# HTTP smoke tests — voting-app2-updated (XAMPP Apache + local MariaDB).
# Created by Task 3 (image endpoints); Tasks 4, 5, 7, 8, 10 append sections.
# Run: powershell -NoProfile -File tests/http_smoke.ps1
# Exit 0 = all PASS. Any FAIL prints and exits 1 at the end.

$ErrorActionPreference = 'Stop'
$base = "http://localhost/voting-app2-updated"
$failures = 0

function Assert-True([string]$name, [bool]$ok, [string]$detail = '') {
    if ($ok) { Write-Output "PASS: $name" }
    else { $script:failures++; Write-Output "FAIL: $name$(if ($detail) { " - $detail" })" }
}

# One-pixel transparent PNG — deterministic image fixture for 200-branch tests.
$pngDataUrl = "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="

function Invoke-Get([string]$url) {
    $bodyFile = [System.IO.Path]::GetTempFileName()
    $headerFile = [System.IO.Path]::GetTempFileName()
    $code = curl.exe -s -D $headerFile -o $bodyFile -w "%{http_code}" $url
    $headers = Get-Content $headerFile -Raw
    $body = [System.IO.File]::ReadAllBytes($bodyFile)
    $bodyText = [System.Text.Encoding]::UTF8.GetString($body)
    Remove-Item $bodyFile, $headerFile -Force
    return [pscustomobject]@{ Code = ($code -join '').Trim(); Headers = $headers; Body = $body; Text = $bodyText }
}

# ----------------------------------------------------------------
# Section: image endpoints (Task 3)
# ----------------------------------------------------------------
# Fixture: the seed DB ships with no candidate photos and no logos, so
# seed exactly one of each to exercise the 200 branches; id=2 / DSG
# election 2 stay imageless to exercise the 404 branches.
$mysql = "C:\xampp\mysql\bin\mysql.exe"
& $mysql -u root voting_app -e "UPDATE candidates SET photo='$pngDataUrl' WHERE id=1; INSERT INTO site_settings (setting_key, setting_value) VALUES ('ssg_logo', '$pngDataUrl') ON DUPLICATE KEY UPDATE setting_value='$pngDataUrl';" 2>&1 | Out-Null

$r = Invoke-Get "$base/photo.php?c=1"
Assert-True "photo.php c=1 returns 200" ($r.Code -eq "200") "got $($r.Code)"
Assert-True "photo.php c=1 Cache-Control public" ($r.Headers -match "Cache-Control:\s*public") ($r.Headers -split "`n" | Select-String "Cache" | Out-String)
Assert-True "photo.php c=1 Content-Type image" ($r.Headers -match "Content-Type:\s*image/") ""
$fixtureBytes = [Convert]::FromBase64String($pngDataUrl.Split(',')[1]).Length
Assert-True "photo.php c=1 bytes match fixture" ($r.Body.Length -eq $fixtureBytes) "got $($r.Body.Length), want $fixtureBytes"

$r = Invoke-Get "$base/photo.php?c=2"
Assert-True "photo.php c=2 (no photo) returns 404" ($r.Code -eq "404") "got $($r.Code)"

$r = Invoke-Get "$base/photo.php?c=999999"
Assert-True "photo.php unknown id returns 404" ($r.Code -eq "404") "got $($r.Code)"
Assert-True "photo.php 404 body leaks nothing" ($r.Text -notmatch "SQLSTATE|PDO|neon|password") $r.Text.Substring(0, [Math]::Min(120, $r.Text.Length))

$r = Invoke-Get "$base/icon.php?e=1"
Assert-True "icon.php e=1 (ssg_logo set) returns 200" ($r.Code -eq "200") "got $($r.Code)"
Assert-True "icon.php e=1 Cache-Control public" ($r.Headers -match "Cache-Control:\s*public") ""
Assert-True "icon.php e=1 Content-Type image" ($r.Headers -match "Content-Type:\s*image/") ""

$r = Invoke-Get "$base/icon.php?e=2"
Assert-True "icon.php e=2 (DSG, no dept logo) returns 404" ($r.Code -eq "404") "got $($r.Code)"

$r = Invoke-Get "$base/icon.php?e=999999"
Assert-True "icon.php unknown election returns 404" ($r.Code -eq "404") "got $($r.Code)"
Assert-True "icon.php 404 body leaks nothing" ($r.Text -notmatch "SQLSTATE|PDO|neon|password") $r.Text.Substring(0, [Math]::Min(120, $r.Text.Length))

if ($failures -gt 0) { Write-Output "`n$failures assertion(s) failed"; exit 1 }
Write-Output "`nAll assertions passed"
exit 0
