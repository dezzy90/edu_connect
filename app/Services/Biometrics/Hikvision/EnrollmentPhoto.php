<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Hikvision;

use App\Models\V2\IntegrationConnection;
use App\Models\V2\Student;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class EnrollmentPhoto
{
    public function stage(Student $student, IntegrationConnection $connection, ?array $descriptor): void
    {
        if (! config('hikvision_push.enrollment_enabled')) {
            return;
        }
        $connections = config('hikvision_push.enrollment_connection_ids', []);
        if ($connections !== [] && ! in_array((int) $connection->id, $connections, true)) {
            return;
        }
        $revision = $descriptor['revision'] ?? null;
        if ($descriptor !== null && (! is_string($revision) || ! preg_match('/^[a-f0-9]{64}$/D', $revision))) {
            throw new RuntimeException('invalid_photo_revision');
        }
        if ((int) $student->tenant_id !== (int) $connection->tenant_id || $student->source_system !== 'edu_admin'
            || ! DB::table('ec_integration_mappings')->where('connection_id', $connection->id)->where('local_type', 'student')
                ->where('local_id', $student->id)->where('external_type', 'student')->where('external_id', (string) $student->source_id)->exists()) {
            throw new RuntimeException('photo_scope_mismatch');
        }
        $student->forceFill(['enrollment_photo' => $revision ? ['revision' => $revision] : null])->saveQuietly();
        $existing = DB::table('ec_hikvision_enrollment_photos')->where('student_id', $student->id)->first();
        $attributes = ['connection_id' => $connection->id, 'source_revision' => $revision, 'updated_at' => now()];
        if (! $existing || $existing->source_revision !== $revision || (int) $existing->connection_id !== (int) $connection->id
            || $existing->state === 'not_required') {
            $attributes += ['state' => $revision ? 'photo_pending' : 'missing_photo', 'error_code' => null,
                'attempts' => 0, 'retry_at' => null, 'lease_token' => null, 'lease_until' => 0,
                'prepared_revision' => null, 'photo_hash' => null, 'photo_encrypted' => null];
        }
        if ($existing) {
            DB::table('ec_hikvision_enrollment_photos')->where('id', $existing->id)->update($attributes);
        } else {
            DB::table('ec_hikvision_enrollment_photos')->insert($attributes + ['student_id' => $student->id, 'created_at' => now()]);
        }
        app(EnrollmentRoster::class)->reconcile($student->id);
        if (array_key_exists('state', $attributes)) {
            EnrollmentWork::request((int) $student->id);
        }
    }

    public function prepare(int $studentId): void
    {
        if (! config('hikvision_push.enrollment_enabled')) {
            return;
        }
        $photo = DB::transaction(static function () use ($studentId): ?object {
            $student = DB::table('ec_students')->where('id', $studentId)->lockForUpdate()->first();
            $row = DB::table('ec_hikvision_enrollment_photos')->where('student_id', $studentId)->lockForUpdate()->sole();
            $connections = config('hikvision_push.enrollment_connection_ids', []);
            if ($connections !== [] && ! in_array((int) $row->connection_id, $connections, true)) {
                return null;
            }
            if (! $student || ! EnrollmentRoster::eligible($student)) {
                DB::table('ec_hikvision_enrollment_photos')->where('id', $row->id)->update([
                    'state' => 'not_required', 'prepared_revision' => null, 'photo_hash' => null, 'photo_encrypted' => null,
                    'lease_token' => null, 'lease_until' => 0, 'updated_at' => now(),
                ]);

                return null;
            }
            if (! in_array($row->state, ['photo_pending', 'photo_unavailable'], true) || $row->attempts >= 5
                || $row->lease_until > now()->getTimestamp() || ($row->retry_at && strtotime($row->retry_at) > now()->getTimestamp())) {
                return null;
            }
            $row->lease_token = (string) Str::uuid();
            DB::table('ec_hikvision_enrollment_photos')->where('id', $row->id)->update([
                'lease_token' => $row->lease_token, 'lease_until' => now()->getTimestamp() + 120,
                'attempts' => $row->attempts + 1, 'updated_at' => now(),
            ]);

            return $row;
        });
        if (! $photo) {
            return;
        }
        try {
            $student = Student::withoutGlobalScopes()->findOrFail($studentId);
            $connection = IntegrationConnection::withoutGlobalScopes()->findOrFail($photo->connection_id);
            if (! EnrollmentRoster::eligible(DB::table('ec_students')->where('id', $studentId)->sole())) {
                DB::table('ec_hikvision_enrollment_photos')->where('id', $photo->id)->where('lease_token', $photo->lease_token)
                    ->update(['state' => 'not_required', 'lease_token' => null, 'lease_until' => 0, 'updated_at' => now()]);

                return;
            }
            if ($connection->status !== 'active' || $connection->provider !== 'edu_admin'
                || (int) $connection->tenant_id !== (int) $student->tenant_id || $student->source_system !== 'edu_admin'
                || ! preg_match('/^\d+$/D', (string) $student->source_id)
                || ! DB::table('ec_integration_mappings')->where('connection_id', $connection->id)->where('local_type', 'student')
                    ->where('local_id', $studentId)->where('external_type', 'student')->where('external_id', (string) $student->source_id)->exists()
                || ($connection->enabled_school_ids && ! in_array((int) $student->school_id, array_map('intval', $connection->enabled_school_ids), true))) {
                throw new RuntimeException('photo_source_not_authorized');
            }
            $base = rtrim((string) $connection->base_url, '/');
            if (parse_url($base, PHP_URL_SCHEME) !== 'https' || parse_url($base, PHP_URL_USER) || parse_url($base, PHP_URL_QUERY)) {
                throw new RuntimeException('photo_source_requires_https');
            }
            // Construct this path ourselves. Never fetch photo_url or an upstream-supplied URL.
            $path = '/api/v1/integrations/edu-connect/students/'.rawurlencode((string) $student->source_id)
                .'/enrollment-photo?revision='.$photo->source_revision;
            $response = Http::withToken(Crypt::decryptString($connection->encrypted_access_token))
                ->withOptions(['allow_redirects' => false, 'stream' => true])->timeout(15)->get($base.$path);
            if ($response->status() !== 200) {
                throw new RuntimeException($response->status() === 409 ? 'photo_revision_changed' : 'photo_download_failed');
            }
            $stream = $response->toPsrResponse()->getBody();
            try {
                $max = (int) config('hikvision_push.enrollment_max_source_bytes', 4194304);
                $bytes = '';
                while (! $stream->eof() && strlen($bytes) <= $max) {
                    $chunk = $stream->read(min(65536, $max + 1 - strlen($bytes)));
                    if ($chunk === '' && ! $stream->eof()) {
                        throw new RuntimeException('photo_download_failed');
                    }
                    $bytes .= $chunk;
                }
            } finally {
                $stream->close();
            }
            if (strlen($bytes) > $max || $response->header('X-Enrollment-Photo-Revision') !== $photo->source_revision
                || ! hash_equals(hash('sha256', $bytes), (string) $response->header('X-Content-SHA256'))) {
                throw new RuntimeException('photo_integrity_failed');
            }
            $jpeg = self::jpeg($bytes);
            DB::transaction(static function () use ($photo, $jpeg): void {
                $current = DB::table('ec_hikvision_enrollment_photos')->where('id', $photo->id)->lockForUpdate()->sole();
                if ($current->source_revision !== $photo->source_revision || $current->connection_id !== $photo->connection_id
                    || $current->lease_token !== $photo->lease_token) {
                    return; // A newer upload won while download/processing was in progress.
                }
                DB::table('ec_hikvision_enrollment_photos')->where('id', $photo->id)->update([
                    'prepared_revision' => $photo->source_revision, 'photo_hash' => hash('sha256', $jpeg),
                    'photo_encrypted' => Crypt::encryptString(base64_encode($jpeg)), 'state' => 'photo_ready',
                    'error_code' => null, 'updated_at' => now(),
                    'lease_token' => null, 'lease_until' => 0, 'retry_at' => null,
                ]);
            });
        } catch (\Throwable $error) {
            $allowed = ['photo_source_not_authorized', 'photo_source_requires_https', 'photo_revision_changed', 'photo_download_failed',
                'photo_integrity_failed', 'photo_needs_replacement', 'image_processing_unavailable'];
            $code = in_array($error->getMessage(), $allowed, true) ? $error->getMessage() : 'photo_processing_failed';
            DB::table('ec_hikvision_enrollment_photos')->where('id', $photo->id)->where('source_revision', $photo->source_revision)
                ->where('lease_token', $photo->lease_token)
                ->update(['state' => $code === 'photo_needs_replacement' ? 'photo_needs_replacement' : 'photo_unavailable',
                    'error_code' => $code, 'updated_at' => now(), 'lease_token' => null, 'lease_until' => 0,
                    'retry_at' => now()->addMinutes(5)]);
        }
        app(EnrollmentRoster::class)->reconcile($studentId);
    }

    public static function jpeg(string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
            || min($info[0], $info[1]) < 160 || max($info[0], $info[1]) > 4096 || $info[0] * $info[1] > 12000000) {
            throw new RuntimeException('photo_needs_replacement');
        }
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('image_processing_unavailable');
        }
        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            throw new RuntimeException('photo_needs_replacement');
        }
        if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
            $stream = fopen('php://temp', 'w+b');
            try {
                fwrite($stream, $bytes);
                rewind($stream);
                $exif = @exif_read_data($stream, 'IFD0', true);
                $orientation = (int) ($exif['IFD0']['Orientation'] ?? 1);
                if (in_array($orientation, [3, 5, 6, 7, 8], true)) {
                    $rotated = imagerotate($source, match ($orientation) {
                        3 => 180, 5, 6 => -90, default => 90
                    }, 0);
                    imagedestroy($source);
                    $source = $rotated;
                }
                if (in_array($orientation, [2, 5, 7], true)) {
                    imageflip($source, IMG_FLIP_HORIZONTAL);
                }
                if ($orientation === 4) {
                    imageflip($source, IMG_FLIP_VERTICAL);
                }
            } finally {
                fclose($stream);
            }
        }
        $info[0] = imagesx($source);
        $info[1] = imagesy($source);
        $scale = min(1, 800 / max($info[0], $info[1]));
        $image = imagecreatetruecolor(max(1, (int) round($info[0] * $scale)), max(1, (int) round($info[1] * $scale)));
        try {
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
            imagecopyresampled($image, $source, 0, 0, 0, 0, imagesx($image), imagesy($image), $info[0], $info[1]);
            foreach ([90, 80, 70, 60] as $quality) {
                ob_start();
                try {
                    imagejpeg($image, null, $quality);
                    $jpeg = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                if (strlen($jpeg) <= (int) config('hikvision_push.enrollment_max_photo_bytes', 120000)) {
                    return $jpeg;
                }
            }
            throw new RuntimeException('photo_needs_replacement');
        } finally {
            imagedestroy($source);
            imagedestroy($image);
        }
    }
}
