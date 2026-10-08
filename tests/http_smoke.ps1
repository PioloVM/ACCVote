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

# ----------------------------------------------------------------
# Section: skippable ballot + abstention lock (Task 4)
# ----------------------------------------------------------------
$jar = [System.IO.Path]::GetTempFileName()

# Fixtures: both eligible elections (SSG #1, own-dept DSG #2) open with a
# fresh window; clear this student's previous ballot/votes so the section
# is re-runnable; clear login_attempts (the shared rate-limit bucket for
# login + vote keys) so rapid test reruns never mask a real failure.
$uidSql = "(SELECT id FROM users WHERE student_id='987654321')"
& $mysql -u root voting_app -e @"
UPDATE elections SET status='ongoing', start_date=DATE_SUB(NOW(), INTERVAL 1 DAY), end_date=DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id IN (1,2);
DELETE FROM ballot_submissions WHERE user_id=$uidSql;
DELETE FROM votes WHERE user_id=$uidSql;
UPDATE users SET has_voted=0 WHERE id=$uidSql;
DELETE FROM login_attempts;
"@ 2>&1 | Out-Null

$loginHtml = ((curl.exe -s -c $jar "$base/login.php") -join "`n")
$token = [regex]::Match($loginHtml, 'name="csrf_token"\s+value="([^"]+)"').Groups[1].Value
Assert-True "login form yields csrf token" ($token -ne "") "not found in login page"
$loginCode = curl.exe -s -b $jar -c $jar -o NUL -w "%{http_code}" -X POST "$base/login.php" -d "csrf_token=$token&student_id=987654321&password=password123"
Assert-True "login succeeds (302)" ($loginCode -eq "302") "got $loginCode"

$data = ((curl.exe -s -b $jar "$base/dashboard-data.php") -join "") | ConvertFrom-Json
$votable = @($data.elections | Where-Object { $_.config.status -eq 'ongoing' -and -not $_.hasVoted -and $_.totalPositions -gt 0 })
Assert-True "fixture yields at least 2 votable elections" ($votable.Count -ge 2) "got $($votable.Count)"

$first = $votable[0]

# 1. Empty ballot (no position_* fields at all) must be accepted as an
#    abstention and report the next election in the chain.
$respText = ((curl.exe -s -b $jar -X POST "$base/vote.php" -d "csrf_token=$token&election_id=$($first.id)") -join "")
$resp = $respText | ConvertFrom-Json
Assert-True "empty ballot accepted" ($resp.success -eq $true) $respText
Assert-True "response carries next_election key" (($resp.PSObject.Properties.Name -contains 'next_election')) $respText
Assert-True "response carries remaining count" (($resp.PSObject.Properties.Name -contains 'remaining')) $respText
if (($resp.PSObject.Properties.Name -contains 'next_election')) {
    Assert-True "next_election is the next votable id in dashboard-data order" ($resp.next_election.id -eq $votable[1].id) "got $($resp.next_election.id), want $($votable[1].id)"
    Assert-True "remaining equals votable minus submitted" ([int]$resp.remaining -eq ($votable.Count - 1)) "got $($resp.remaining), want $($votable.Count - 1)"
}

# 2. Re-submitting the same election must 409 (the lock).
$code2 = curl.exe -s -b $jar -o NUL -w "%{http_code}" -X POST "$base/vote.php" -d "csrf_token=$token&election_id=$($first.id)"
Assert-True "re-submit returns 409" ($code2 -eq "409") "got $code2"

# 3. The dashboard must now report the election as voted.
$data2 = ((curl.exe -s -b $jar "$base/dashboard-data.php") -join "") | ConvertFrom-Json
$eAfter = $data2.elections | Where-Object { $_.id -eq $first.id }
Assert-True "hasVoted flips true after empty submit" ($eAfter.hasVoted -eq $true) "hasVoted=$($eAfter.hasVoted)"

# ----------------------------------------------------------------
# Section: payload contract — URLs instead of base64 (Task 5)
# ----------------------------------------------------------------
$respRaw = ((curl.exe -s -b $jar "$base/dashboard-data.php") -join "")
$respBytes = [System.Text.Encoding]::UTF8.GetByteCount($respRaw)
Assert-True "payload has photo_url" ($respRaw -match '"photo_url"') ""
Assert-True "payload has icon_url" ($respRaw -match '"icon_url"') ""
Assert-True "payload contains no inline data:image" ($respRaw -notmatch 'data:image') ($respRaw.Substring([Math]::Max(0, $respRaw.IndexOf('data:image')), [Math]::Min(120, $respRaw.Length)))
Assert-True "payload under 50KB" ($respBytes -lt 50000) "got $respBytes bytes"

if ($failures -gt 0) { Write-Output "`n$failures assertion(s) failed"; exit 1 }
Write-Output "`nAll assertions passed"
exit 0
