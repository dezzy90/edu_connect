# Fix UTF-16 encoded PHP files by converting to UTF-8 without BOM
# Scans recursively under 'app' for .php files that contain NUL bytes (indicator of UTF-16 encoding)
# and converts them to UTF-8 (no BOM).

$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
$converted = @()
$failed = @()

Get-ChildItem -Recurse -Path "app" -Filter "*.php" | ForEach-Object {
    try {
        $bytes = [System.IO.File]::ReadAllBytes($_.FullName)
        if ($bytes -contains 0) {
            # Likely UTF-16LE; decode using Unicode and write as UTF-8 (no BOM)
            $text = [System.Text.Encoding]::Unicode.GetString($bytes)
            [System.IO.File]::WriteAllText($_.FullName, $text, $utf8NoBom)
            $converted += $_.FullName
            Write-Host "Converted: $($_.FullName)"
        }
    } catch {
        $failed += $_.FullName
        Write-Host "Failed: $($_.FullName): $($_.Exception.Message)"
    }
}

Write-Host ""
Write-Host "=== Summary ==="
Write-Host "Converted files:"
$converted | ForEach-Object { Write-Host " - $_" }

if ($failed.Count -gt 0) {
    Write-Host ""
    Write-Host "Failed files:"
    $failed | ForEach-Object { Write-Host " - $_" }
}
