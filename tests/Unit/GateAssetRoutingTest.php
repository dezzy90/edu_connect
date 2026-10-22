<?php

function gateAssetRewrite(): array
{
    $root = dirname(__DIR__, 2);
    $lines = file($root.'/deployment/cpanel/backend-direct.htaccess', FILE_IGNORE_NEW_LINES);
    $rule = array_values(array_filter($lines, fn ($line) => str_starts_with(trim($line), 'RewriteRule ^gate-operator-assets/')));
    expect($rule)->toHaveCount(1);

    return preg_split('/\s+/', trim($rule[0]));
}

it('serves both manifest-referenced gate assets through the narrow repository-root rewrite', function (): void {
    [, $pattern, $replacement] = gateAssetRewrite();
    $root = dirname(__DIR__, 2);
    $directory = 'gate-operator-assets/20261022-r7';
    $entry = json_decode(file_get_contents($root.'/public/'.$directory.'/manifest.json'), true, 8, JSON_THROW_ON_ERROR)['resources/js/gate-operator.tsx'];
    expect($replacement)->toBe('public/gate-operator-assets/$1/assets/$2');
    foreach ([$entry['file'], ...$entry['css']] as $asset) {
        $path = $directory.'/'.$asset;
        expect(preg_match('~'.$pattern.'~D', $path))->toBe(1);
        expect(preg_replace('~'.$pattern.'~D', $replacement, $path))->toBe('public/'.$path);
        expect(is_file($root.'/public/'.$path))->toBeTrue();
    }
});

it('never exposes arbitrary files or traversal through the gate asset rewrite', function (string $path): void {
    [, $pattern] = gateAssetRewrite();
    expect(preg_match('~'.$pattern.'~D', $path))->toBe(0);
})->with(['gate-operator-assets/20261022-r7/manifest.json', 'gate-operator-assets/20261022-r7/assets/../../.env',
    'gate-operator-assets/20261022-r7/assets/file.php', 'gate-operator-assets/20261022-r7/assets/nested/file.js',
    'gate-operator-assets/20261022-r7/assets/%2e%2e%2fsecret.js', 'gate-operator-assets/not-a-release/assets/file.js',
    'storage/private/file.js', '.env']);
