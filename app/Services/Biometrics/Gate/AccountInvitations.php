<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Gate;

use App\Models\AdminUser;
use App\Services\Biometrics\Devices\RegistryAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/** One-time first-password setup for explicitly scoped, inactive gate accounts only. */
final class AccountInvitations
{
    private function pending(?AdminUser $account): bool
    {
        return $account && $account->role === 'school_admin' && $account->gate_only === true
            && ! $account->test_recovery_only && ! $account->is_active && $account->email_verified_at === null
            && $account->last_login_at === null
            && (app(RegistryAccess::class)->schools($account)->exists() || app(SetupAccess::class)->schools($account)->exists());
    }

    private function enabled(): bool
    {
        return config('gate.account_invites_enabled') === true;
    }

    public function issue(int $adminId, int $hours, string $channel = 'private_link'): array
    {
        $base = rtrim((string) config('app.url'), '/');
        $url = parse_url($base);
        if (! $this->enabled() || ! in_array($channel, ['private_link', 'mail'], true) || $hours < 1 || $hours > 72 || ! is_array($url) || ($url['scheme'] ?? null) !== 'https'
            || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || ! in_array($url['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('Gate invitations require an approved HTTPS application origin and explicit activation.');
        }

        return DB::transaction(function () use ($adminId, $hours, $base, $channel): array {
            $account = AdminUser::whereKey($adminId)->lockForUpdate()->first();
            if (! $this->pending($account)) {
                throw new RuntimeException('Only a pending, explicitly scoped gate account can be invited.');
            }
            DB::table('ec_gate_account_invitations')->where('admin_id', $adminId)->whereNull('used_at')->whereNull('revoked_at')
                ->update(['revoked_at' => now()->utc(), 'token_hash' => null]);
            $id = (string) Str::uuid();
            $token = bin2hex(random_bytes(32));
            $expires = now()->timestamp + $hours * 3600;
            DB::table('ec_gate_account_invitations')->insert(['id' => $id, 'admin_id' => $adminId,
                'email_hash' => hash('sha256', mb_strtolower($account->email)), 'token_hash' => hash('sha256', $token),
                'delivery_channel' => $channel, 'expires_at' => $expires, 'created_at' => now()->utc()]);

            return ['id' => $id, 'url' => $base.'/admin/gate/account/activate/'.$id.'/'.$token, 'expires_at' => $expires];
        });
    }

    private function matches(?object $row, string $token, ?AdminUser $account): bool
    {
        return $this->enabled() && $row && $this->pending($account) && $row->used_at === null && $row->revoked_at === null
            && (int) $row->expires_at > now()->timestamp && is_string($row->token_hash)
            && preg_match('/^[a-f0-9]{64}$/D', $token) && hash_equals($row->token_hash, hash('sha256', $token))
            && hash_equals($row->email_hash, hash('sha256', mb_strtolower($account->email)));
    }

    public function inspect(string $id, string $token): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        $row = DB::table('ec_gate_account_invitations')->where('id', $id)->first();
        $account = $row ? AdminUser::find($row->admin_id) : null;

        return $this->matches($row, $token, $account) ? ['email' => $account->email, 'expires_at' => (int) $row->expires_at] : null;
    }

    public function complete(string $id, string $token, string $password): void
    {
        abort_unless($this->enabled(), 404);
        $head = DB::table('ec_gate_account_invitations')->where('id', $id)->first();
        abort_unless($head, 404);
        DB::transaction(function () use ($head, $id, $token, $password): void {
            // Same lock order as issuance; consumption and account activation are atomic.
            $account = AdminUser::whereKey($head->admin_id)->lockForUpdate()->first();
            $row = DB::table('ec_gate_account_invitations')->where('id', $id)->lockForUpdate()->first();
            abort_unless($this->matches($row, $token, $account), 404);
            $account->forceFill(['password' => Hash::make($password), 'is_active' => true,
                'email_verified_at' => $row->delivery_channel === 'mail' ? now()->utc() : null, 'remember_token' => null])->save();
            DB::table('personal_access_tokens')->where('tokenable_type', AdminUser::class)->where('tokenable_id', $account->id)->delete();
            DB::table('ec_gate_account_invitations')->where('admin_id', $account->id)->whereNull('used_at')->whereNull('revoked_at')
                ->update(['revoked_at' => now()->utc(), 'token_hash' => null]);
            DB::table('ec_gate_account_invitations')->where('id', $id)->update(['used_at' => now()->utc(), 'revoked_at' => null]);
        });
    }
}
