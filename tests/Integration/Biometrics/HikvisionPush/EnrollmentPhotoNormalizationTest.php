<?php

declare(strict_types=1);

use App\Services\Biometrics\Hikvision\EnrollmentPhoto;

uses(Tests\TestCase::class);

it('prepares a bounded camera original without changing its bytes or enlarging its content', function (): void {
    $input = normalizationSyntheticImage(4160, 3120);
    $before = hash('sha256', $input);
    $output = EnrollmentPhoto::jpeg($input);
    $info = getimagesizefromstring($output);

    expect([$info[0], $info[1], $info['mime']])->toBe([800, 600, 'image/jpeg']);
    expect(strlen($output))->toBeLessThanOrEqual(120000);
    expect(hash('sha256', $input))->toBe($before);
    expect($output)->not->toContain('Exif');
});

it('keeps small but valid images at their original resolution', function (): void {
    $info = getimagesizefromstring(EnrollmentPhoto::jpeg(normalizationSyntheticImage(160, 200)));
    expect([$info[0], $info[1]])->toBe([160, 200]);
});

it('rejects oversized or undersized originals before decoding', function (int $width, int $height): void {
    // A valid PNG header is enough to inspect dimensions; no huge image is allocated in this test.
    $header = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
    $info = getimagesizefromstring($header);
    expect([$info[0], $info[1]])->toBe([$width, $height]);
    expect(fn () => EnrollmentPhoto::jpeg($header))->toThrow(RuntimeException::class, 'photo_needs_replacement');
})->with([[6001, 200], [4001, 4000], [159, 200], [200, 159]]);

it('continues to reject unsupported image formats and undecodable input', function (string $bytes): void {
    expect(fn () => EnrollmentPhoto::jpeg($bytes))->toThrow(RuntimeException::class, 'photo_needs_replacement');
})->with(['not-an-image', 'GIF89a'.pack('vv', 320, 400)."\x80\0\0".str_repeat("\0", 6)]);

function normalizationSyntheticImage(int $width, int $height): string
{
    // Generated solid colour only; never a real student portrait.
    $image = imagecreatetruecolor($width, $height);
    try {
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 100, 140));
        ob_start();
        try {
            imagejpeg($image, null, 90);

            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    } finally {
        imagedestroy($image);
    }
}
