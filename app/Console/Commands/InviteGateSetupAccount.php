<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Services\Biometrics\Gate\AccountInvitations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class InviteGateSetupAccount extends Command
{
    protected $signature = 'gate:invite-setup {admin_id} {--hours=24} {--send : Send through configured production mail} {--private-link : Export once to private application storage, never stdout}';

    protected $description = 'Issue first-password activation for an existing pending, scoped gate account';

    public function handle(): int
    {
        $send = (bool) $this->option('send');
        $private = (bool) $this->option('private-link');
        $hours = (string) $this->option('hours');
        $id = (string) $this->argument('admin_id');
        if ($send === $private || ! preg_match('/^[1-9]\d{0,9}$/D', $id) || ! preg_match('/^[1-9]\d?$/D', $hours) || (int) $hours > 72) {
            $this->error('Choose one delivery method, an explicit pending account ID and 1–72 hours.');

            return self::INVALID;
        }
        if ($send && app()->environment('production') && in_array(config('mail.default'), ['log', 'array', 'null'], true)) {
            $this->error('Production mail delivery is not configured. No invitation was issued.');

            return self::FAILURE;
        }
        $invitation = null;
        try {
            $invitation = app(AccountInvitations::class)->issue((int) $id, (int) $hours, $send ? 'mail' : 'private_link');
            $file = null;
            if ($send) {
                $email = AdminUser::findOrFail((int) $id)->email;
                Mail::raw("Choose your own password for your restricted EduConnect school-setup account:\n\n".$invitation['url']
                    ."\n\nThis private link is valid once and expires ".gmdate('Y-m-d H:i', $invitation['expires_at']).' UTC. Do not share it. Activating the account does not enable live attendance or enrollment.',
                    static fn ($message) => $message->to($email)->subject('Activate your EduConnect school-setup account'));
            } else {
                $directory = storage_path('app/private/gate-invitations');
                if ((! is_dir($directory) && ! mkdir($directory, 0700, true)) || is_link($directory)
                    || is_link(storage_path('app/private')) || is_link(storage_path('app'))) {
                    throw new \RuntimeException('Private export unavailable.');
                }
                chmod($directory, 0700);
                $file = $directory.'/'.$invitation['id'].'.link';
                $handle = fopen($file, 'x');
                if (! $handle) {
                    throw new \RuntimeException('Private export unavailable.');
                }
                try {
                    chmod($file, 0600);
                    $content = $invitation['url']."\n";
                    if (fwrite($handle, $content) !== strlen($content)) {
                        throw new \RuntimeException('Private export unavailable.');
                    }
                } finally {
                    fclose($handle);
                }
            }
            $this->line(json_encode(['ok' => true, 'admin_id' => (int) $id, 'expires_at' => $invitation['expires_at'],
                'delivery' => $send ? 'mail_transport_accepted' : 'private_file', 'private_file' => $file], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            if ($invitation !== null) {
                DB::table('ec_gate_account_invitations')->where('id', $invitation['id'])->whereNull('used_at')
                    ->update(['revoked_at' => now()->utc(), 'token_hash' => null]);
            }
            $this->error('Gate invitation could not be delivered. No activation link was printed; review private application configuration.');

            return self::FAILURE;
        }
    }
}
