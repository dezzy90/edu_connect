<?php

namespace App\Services\Auth;

use App\Models\V2\ParentAccount;
use App\Services\Sms\ScmcOtpSender;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class MobileOtpAuth
{
    public const PURPOSE = 'educonnect.mobile.parent.login';

    public const TTL = 300;

    public const COOLDOWN = 60;

    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private MobileOtpPhone $phones,
        private MobileOtpEligibility $eligibility,
        private ScmcOtpSender $sender,
        private ReviewerDemoAccess $reviewer,
    ) {}

    public function ensureAvailable(): void
    {
        if (! config('mobile_otp.enabled') || ! config('services.scmc.enabled')) {
            $this->unavailable();
        }

        $store = (string) config('mobile_otp.cache_store');
        $driver = config("cache.stores.$store.driver");
        if (! app()->environment(['local', 'testing']) && ! in_array($driver, ['database', 'redis'], true)) {
            $this->unavailable();
        }
        if (! Cache::store($store)->getStore() instanceof LockProvider || strlen($this->key()) < 32) {
            $this->unavailable();
        }
    }

    public function request(#[\SensitiveParameter] string $input, string $ip): array
    {
        $this->ensureAvailable();
        $this->throttleIp('request', $ip, (int) config('mobile_otp.requests_per_ip_window', 20));
        $phone = $this->phones->normalize($input);
        if ($phone === null) {
            throw new HttpResponseException(response()->json([
                'status' => 'error', 'message' => 'Enter a valid phone number.',
                'errors' => ['phone' => ['Enter a valid phone number.']],
            ], 422));
        }

        $phoneHash = $this->digest('phone', $phone);

        return $this->withPhoneLock($phoneHash, function () use ($phone, $phoneHash): array {
            $id = (string) Str::uuid();
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            $proof = DB::transaction(function () use ($phone, $phoneHash, $id, $code): ?array {
                DB::table('ec_mobile_otp_phones')->insertOrIgnore(['phone_hash' => $phoneHash]);
                $state = DB::table('ec_mobile_otp_phones')->where('phone_hash', $phoneHash)->lockForUpdate()->first();
                if ($state->requested_at && now()->lt(Carbon::parse($state->requested_at)->addSeconds(self::COOLDOWN))) {
                    $this->limited((int) ceil(now()->diffInSeconds(Carbon::parse($state->requested_at)->addSeconds(self::COOLDOWN))));
                }
                $newWindow = ! $state->window_ends_at || now()->gte(Carbon::parse($state->window_ends_at));
                if (! $newWindow && $state->request_count >= max(1, (int) config('mobile_otp.requests_per_phone_hour', 5))) {
                    $this->limited((int) ceil(now()->diffInSeconds(Carbon::parse($state->window_ends_at))));
                }

                DB::table('ec_mobile_otp_phones')->where('phone_hash', $phoneHash)->update([
                    'requested_at' => now(),
                    'window_ends_at' => $newWindow ? now()->addHour() : $state->window_ends_at,
                    'request_count' => $newWindow ? 1 : $state->request_count + 1,
                ]);

                DB::table('ec_mobile_otp_challenges')->where('phone_hash', $phoneHash)
                    ->where('purpose', self::PURPOSE)->whereIn('status', ['pending', 'sent'])
                    ->update(['status' => 'superseded', 'updated_at' => now()]);

                $reviewer = $this->reviewer->reservedPhone($phone);
                $proof = $reviewer ? $this->reviewer->resolve($phone, lock: true)
                    : $this->eligibility->resolve($phone, lock: true);
                DB::table('ec_mobile_otp_challenges')->insert([
                    'id' => $id,
                    'purpose' => self::PURPOSE,
                    'phone_hash' => $phoneHash,
                    'phone_ciphertext' => Crypt::encryptString($phone),
                    'code_hash' => $reviewer ? $this->reviewerBinding($id, $phoneHash) : $this->codeHash($id, $phoneHash, $code),
                    'subject_type' => $proof['type'] ?? null,
                    'subject_id' => $proof['id'] ?? null,
                    'status' => $proof ? ($reviewer ? 'sent' : 'pending') : 'decoy',
                    'sent_at' => $proof && $reviewer ? now() : null,
                    'attempts' => 0,
                    'expires_at' => now()->addSeconds(self::TTL),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $proof;
            });

            if ($proof && $proof['type'] !== ReviewerDemoAccess::SUBJECT) {
                try {
                    $validity = strtr((string) config('mobile_otp.validity_format', '{minutes}'), [
                        '{minutes}' => (string) (self::TTL / 60),
                    ]);
                    $this->sender->send($phone, $code, $validity, $id);
                    // A newer request may have superseded this delivery if a worker stalled.
                    DB::table('ec_mobile_otp_challenges')->where('id', $id)->where('status', 'pending')
                        ->where('expires_at', '>', now())->update([
                            'status' => 'sent', 'sent_at' => now(),
                            'updated_at' => now(),
                        ]);
                } catch (Throwable) {
                    // Provider bodies/exceptions may contain secrets. Never log or return them.
                    DB::table('ec_mobile_otp_challenges')->where('id', $id)->where('status', 'pending')
                        ->update(['status' => 'failed', 'updated_at' => now()]);
                }
            }

            $challenge = DB::table('ec_mobile_otp_challenges')->where('id', $id)->first();
            if (! $challenge || $challenge->status !== 'sent' || ! $challenge->sent_at
                || now()->gte(Carbon::parse($challenge->expires_at))) {
                $this->unavailable();
            }

            return [
                'challenge_id' => $id,
                'phone_masked' => $this->phones->masked($phone),
                'expires_in_seconds' => max(0, (int) floor(now()->diffInSeconds(Carbon::parse($challenge->expires_at)))),
                'resend_after_seconds' => self::COOLDOWN,
            ];
        });
    }

    /** Issue the token inside the same transaction as the one-time consume. */
    public function verify(string $id, #[\SensitiveParameter] string $code, string $ip, callable $issueToken): array
    {
        $this->ensureAvailable();
        $this->throttleIp('verify', $ip, (int) config('mobile_otp.verifications_per_ip_window', 60));
        $phoneHash = DB::table('ec_mobile_otp_challenges')->where('id', $id)->value('phone_hash');
        if (! $phoneHash) {
            $this->invalid();
        }

        $result = $this->withPhoneLock($phoneHash, fn () => DB::transaction(function () use ($id, $phoneHash, $code, $issueToken): ?array {
            DB::table('ec_mobile_otp_phones')->where('phone_hash', $phoneHash)->lockForUpdate()->first();
            $challenge = DB::table('ec_mobile_otp_challenges')->where('id', $id)->lockForUpdate()->first();
            if (! $challenge || $challenge->purpose !== self::PURPOSE || $challenge->status !== 'sent'
                || ! $challenge->sent_at || $challenge->consumed_at || $challenge->attempts >= self::MAX_ATTEMPTS
                || now()->gte(Carbon::parse($challenge->expires_at))) {
                return null;
            }

            $attempts = $challenge->attempts + 1;
            $phone = Crypt::decryptString($challenge->phone_ciphertext);
            $reviewer = $challenge->subject_type === ReviewerDemoAccess::SUBJECT;
            $proof = null;
            if ($reviewer && hash_equals($phoneHash, $this->digest('phone', $phone))) {
                $proof = $this->reviewer->resolve($phone, lock: true);
                if ($proof && (int) $proof['id'] !== (int) $challenge->subject_id) {
                    $proof = null;
                }
            }
            $valid = $reviewer
                ? $proof && preg_match('/^[0-9]{6}$/D', $code) === 1
                    && hash_equals($challenge->code_hash, $this->reviewerBinding($id, $phoneHash))
                    && password_verify($code, (string) config('reviewer_demo.pin_hash'))
                : hash_equals($challenge->code_hash, $this->codeHash($id, $phoneHash, $code));
            DB::table('ec_mobile_otp_challenges')->where('id', $id)->update([
                'attempts' => $attempts,
                'status' => $reviewer && ! $proof ? 'ineligible' : (! $valid && $attempts >= self::MAX_ATTEMPTS ? 'exhausted' : 'sent'),
                'updated_at' => now(),
            ]);
            if (! $valid) {
                return null;
            }

            if (! $reviewer) {
                $proof = hash_equals($phoneHash, $this->digest('phone', $phone)) && ! $this->reviewer->reservedPhone($phone)
                    ? $this->eligibility->resolve($phone, ['type' => $challenge->subject_type, 'id' => $challenge->subject_id], true)
                    : null;
            }
            if (! $proof) {
                DB::table('ec_mobile_otp_challenges')->where('id', $id)->update(['status' => 'ineligible']);

                return null;
            }

            $consumed = DB::table('ec_mobile_otp_challenges')->where('id', $id)
                ->where('purpose', self::PURPOSE)->where('status', 'sent')->whereNull('consumed_at')
                ->where('expires_at', '>', now())->where('attempts', '<=', self::MAX_ATTEMPTS)
                ->update(['status' => 'consumed', 'consumed_at' => now(), 'updated_at' => now()]);
            if ($consumed !== 1) {
                return null;
            }

            $parent = $proof['parent'] ?? ParentAccount::query()->create([
                'phone' => $phone,
                'first_name' => '',
                'last_name' => '',
                'status' => 'active',
                'preferred_language' => config('educonnect.mobile.default_locale', 'en'),
                'settings' => [],
            ]);
            $parent->forceFill(['phone_verified_at' => now(), 'last_login_at' => now()])->save();

            return $issueToken($parent);
        }));

        // Invalid attempts must commit; throwing inside the transaction would undo their count.
        if ($result === null) {
            $this->invalid();
        }

        return $result;
    }

    private function throttleIp(string $action, string $ip, int $limit): void
    {
        $cache = Cache::store(config('mobile_otp.cache_store'));
        $key = 'mobile-otp:'.$action.':'.$this->digest('ip', $ip);
        $lock = $cache->lock($key.':lock', 10);
        if (! $lock->get()) {
            $this->limited(1);
        }
        try {
            $limiter = new RateLimiter($cache);
            if ($limiter->tooManyAttempts($key, max(1, $limit))) {
                $this->limited($limiter->availableIn($key));
            }
            $limiter->hit($key, max(1, (int) config('mobile_otp.ip_window_seconds', 600)));
        } finally {
            $lock->release();
        }
    }

    private function withPhoneLock(string $hash, callable $work): mixed
    {
        $lock = Cache::store(config('mobile_otp.cache_store'))->lock('mobile-otp:phone:'.$hash, 60);
        if (! $lock->get()) {
            $this->limited(1);
        }
        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    private function codeHash(string $id, string $phoneHash, #[\SensitiveParameter] string $code): string
    {
        return $this->digest('code', $id.'|'.$phoneHash.'|'.$code);
    }

    private function reviewerBinding(string $id, string $phoneHash): string
    {
        // Bind the reusable password hash and exact demo identity to this one-time challenge.
        return $this->digest('reviewer', implode('|', [
            $id, $phoneHash, config('reviewer_demo.parent_id'), config('reviewer_demo.tenant_id'),
            config('reviewer_demo.marker'), config('reviewer_demo.pin_hash'),
        ]));
    }

    private function digest(string $domain, #[\SensitiveParameter] string $value): string
    {
        return hash_hmac('sha256', self::PURPOSE.'|'.$domain.'|'.$value, $this->key());
    }

    private function key(): string
    {
        $key = (string) config('mobile_otp.hmac_key');

        return str_starts_with($key, 'base64:') ? (base64_decode(substr($key, 7), true) ?: '') : $key;
    }

    private function limited(int $seconds): never
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error', 'message' => 'Please wait before trying again.',
        ], 429, ['Retry-After' => (string) max(1, $seconds)]));
    }

    private function invalid(): never
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error', 'message' => 'This code is invalid or expired. Request a new code.',
        ], 422));
    }

    private function unavailable(): never
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error', 'message' => 'Phone sign-in is temporarily unavailable.',
        ], 503));
    }
}
