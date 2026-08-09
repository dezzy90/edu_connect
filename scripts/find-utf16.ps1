param(
    [string[]] $Roots = @("app", "routes", "config", "database", "bootstrap")
)

Write-Host "Scanning for UTF-16/NUL-encoded PHP files in: $($Roots -join ', ')"

$foundNul = @()
$foundBom = @()

foreach ($root in $Roots) {
    if (-not (Test-Path $root)) { continue }
    Get-ChildItem -Recurse -Path $root -Filter "*.php" | ForEach-Object {
        try {
            $bytes = [System.IO.File]::ReadAllBytes($_.FullName)

            # Heuristic 1: NUL byte presence anywhere in file (common for UTF-16 content)
            if ($bytes -contains 0) {
                $foundNul += $_.FullName
                Write-Host "Detected NUL bytes (likely UTF-16): $($_.FullName)"
            }

            # Heuristic 2: UTF-16 BOM check at start of file
            if ($bytes.Length -ge 2) {
                $b0 = $bytes[0]
                $b1 = $bytes[1]
                if (($b0 -eq 0xFF -and $b1 -eq 0xFE) -or ($b0 -eq 0xFE -and $b1 -eq 0xFF)) {
                    $foundBom += $_.FullName
                    Write-Host "Detected UTF-16 BOM: $($_.FullName)"
                }
            }
        } catch {
            Write-Host "Error reading file: $($_.FullName) -> $($_.Exception.Message)"
        }
    }
}

Write-Host ""
Write-Host "=== Summary ==="
if ($foundNul.Count -eq 0 -and $foundBom.Count -eq 0) {
    Write-Host "No UTF-16 indicators detected (no NUL bytes and no UTF-16 BOM)."
} else {
    if ($foundNul.Count -gt 0) {
        Write-Host "Files with NUL bytes:"
        $foundNul | Sort-Object -Unique | ForEach-Object { Write-Host " - $_" }
    }
    if ($foundBom.Count -gt 0) {
        Write-Host ""
        Write-Host "Files with UTF-16 BOM:"
        $foundBom | Sort-Object -Unique | ForEach-Object { Write-Host " - $_" }
    }
}
