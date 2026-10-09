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
& $mysql -u root voting_app -e "UPDATE candidates SET photo='$pngDataUrl' WHERE id=1; UPDATE candidates SET photo=NULL WHERE id=2; INSERT INTO site_settings (setting_key, setting_value) VALUES ('ssg_logo', '$pngDataUrl') ON DUPLICATE KEY UPDATE setting_value='$pngDataUrl';" 2>&1 | Out-Null

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

# ----------------------------------------------------------------
# Section: continuous scroll ballot (Task 6)
# ----------------------------------------------------------------
# Reset this student's ballot state so the target election renders a
# fresh form. Photo fixture: candidate 1 photoed (the target ballot
# includes it — proves the photo.php path), everyone else NULL so
# Task 3's "no photo -> 404" invariant holds on reruns.
$uidSql2 = "(SELECT id FROM users WHERE student_id='987654321')"
& $mysql -u root voting_app -e @"
DELETE FROM ballot_submissions WHERE user_id=$uidSql2;
DELETE FROM votes WHERE user_id=$uidSql2;
UPDATE candidates SET photo='$pngDataUrl' WHERE id = 1;
UPDATE candidates SET photo=NULL WHERE id <> 1;
DELETE FROM login_attempts;
"@ 2>&1 | Out-Null

$ballotTarget = $votable[0]
$ballotHtml = ((curl.exe -s -b $jar "$base/dashboard.php?page=vote&election_id=$($ballotTarget.id)") -join "`n")
Assert-True "ballot page renders voteForm" ($ballotHtml -match 'id="voteForm"') ""
Assert-True "stays votable (no already-voted alert)" ($ballotHtml -notmatch 'already voted in this election') ""
Assert-True "no step-machine JS (ballotNext)" ($ballotHtml -notmatch 'ballotNext') ""
Assert-True "no review/summary (ballotSummaryList)" ($ballotHtml -notmatch 'ballotSummaryList') ""
Assert-True "no Save & Exit" ($ballotHtml -notmatch 'ballotSaveExitBtn|Save &amp; Exit') ""
Assert-True "no Back/Continue nav" ($ballotHtml -notmatch 'ballotPrev\(|ballotGoTo\(|Continue &rarr;') ""
Assert-True "no exit-guard popup" ($ballotHtml -notmatch 'handleBallotExitClick') ""
Assert-True "no draft endpoint refs" ($ballotHtml -notmatch 'draft\.php|saveDraftAndExit') ""
Assert-True "no receipt sheet markup" ($ballotHtml -notmatch 'ballot-summary-sheet|Review Your Ballot') ""
$posSections = ([regex]::Matches($ballotHtml, '<div class="ballot-step" data-step=')).Count
Assert-True "all position sections rendered in one page" ($posSections -eq $ballotTarget.totalPositions) "got $posSections, want $($ballotTarget.totalPositions)"
Assert-True "candidate images use photo.php" ($ballotHtml -match 'src="photo\.php\?c=') ""
Assert-True "ballot HTML has no inline data:image" ($ballotHtml -notmatch 'data:image') ""
Assert-True "no per-input base64 data-candidate-photo" ($ballotHtml -notmatch 'data-candidate-photo') ""
Assert-True "submit button exists at end" ($ballotHtml -match 'id="submitBtn"') ""
Assert-True "confirm modal: Cast My Vote + Go Back" (($ballotHtml -match 'Cast My Vote') -and ($ballotHtml -match 'Go Back')) ""
Assert-True "answered-positions label present" ($ballotHtml -match 'ballotProgressLabel') ""

$contHtml = ((curl.exe -s -b $jar "$base/dashboard.php?page=vote&election_id=$($ballotTarget.id)&continuous=1") -join "`n")
Assert-True "continuous=1 sets data-continuous on form" ($contHtml -match 'data-continuous="1"') ""
Assert-True "no continuous param -> no data-continuous" ($ballotHtml -notmatch 'data-continuous') ""

# ----------------------------------------------------------------
# Section: Vote All continuous election chain (Task 7)
# ----------------------------------------------------------------
# Self-contained fixture: both eligible elections open, student's ballot
# state cleared, rate-limit bucket empty — the section is re-runnable.
$uidSql3 = "(SELECT id FROM users WHERE student_id='987654321')"
& $mysql -u root voting_app -e @"
UPDATE elections SET status='ongoing', start_date=DATE_SUB(NOW(), INTERVAL 1 DAY), end_date=DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id IN (1,2);
DELETE FROM ballot_submissions WHERE user_id=$uidSql3;
DELETE FROM votes WHERE user_id=$uidSql3;
UPDATE users SET has_voted=0 WHERE id=$uidSql3;
DELETE FROM login_attempts;
"@ 2>&1 | Out-Null

# (a) votable list — the client's canVote predicate, evaluated server-side
$chainData = ((curl.exe -s -b $jar "$base/dashboard-data.php") -join "") | ConvertFrom-Json
$chainIds = @($chainData.elections | Where-Object { $_.config.status -eq 'ongoing' -and -not $_.hasVoted -and $_.totalPositions -gt 0 } | ForEach-Object { [int]$_.id })
Assert-True "chain fixture: at least 2 votable elections" ($chainIds.Count -ge 2) "got $($chainIds.Count)"

# Vote All UI sources (home card + chain modals) — fetched BEFORE the loop
# while the elections are still unvoted.
$homeHtml = ((curl.exe -s -b $jar "$base/dashboard.php?page=home") -join "")
Assert-True "home source has Vote All button id" ($homeHtml -match 'startVoteAllBtn') ""
Assert-True "home source has Start Voting label" ($homeHtml -match 'Start Voting') ""
Assert-True "home card links continuous=1" ($homeHtml -match 'continuous=1') ""
Assert-True "home source opens remaining branch" ($homeHtml -match 'if \(remaining\.length > 0\)') ""
Assert-True "featured hero selection removed" ($homeHtml -notmatch 'heroActionable') ""

$chainBallotHtml = ((curl.exe -s -b $jar "$base/dashboard.php?page=vote&election_id=$($chainIds[0])&continuous=1") -join "")
Assert-True "continuous ballot sets data-continuous" ($chainBallotHtml -match 'data-continuous="1"') ""
Assert-True "ballot has chain (done/error) modal" ($chainBallotHtml -match 'id="ballotChainModal"') ""
Assert-True "ballot has final modal" ($chainBallotHtml -match 'id="ballotFinalModal"') ""
Assert-True "final modal exact copy" ($chainBallotHtml -match "You&#039;ve completed all your elections!|You've completed all your elections!") ""

# (b) walk the chain: each submit must report the next id in list order
for ($i = 0; $i -lt $chainIds.Count; $i++) {
    $linkResp = ((curl.exe -s -b $jar -X POST "$base/vote.php" -d "csrf_token=$token&election_id=$($chainIds[$i])") -join "") | ConvertFrom-Json
    Assert-True "chain link $($i+1): submit succeeds" ($linkResp.success -eq $true) ($linkResp | ConvertTo-Json -Compress)
    $wantNext = if ($i + 1 -lt $chainIds.Count) { $chainIds[$i + 1] } else { $null }
    $gotNext = if ($null -ne $linkResp.next_election) { [int]$linkResp.next_election.id } else { $null }
    Assert-True "chain link $($i+1): next_election equals next id in dashboard-data order" ($gotNext -eq $wantNext) "got $gotNext, want $wantNext"
    $wantRemaining = $chainIds.Count - 1 - $i
    Assert-True "chain link $($i+1): remaining equals votes left" ([int]$linkResp.remaining -eq $wantRemaining) "got $($linkResp.remaining), want $wantRemaining"
}

# After the last link: every chained election reports hasVoted, final remaining was 0
$chainData2 = ((curl.exe -s -b $jar "$base/dashboard-data.php") -join "") | ConvertFrom-Json
$stillOpen = @($chainData2.elections | Where-Object { $_.id -in $chainIds -and -not $_.hasVoted })
Assert-True "all chained elections report hasVoted" ($stillOpen.Count -eq 0) ("still unvoted: " + (($stillOpen | ForEach-Object { $_.id }) -join ','))

# Reset so the suite stays re-runnable
& $mysql -u root voting_app -e @"
DELETE FROM ballot_submissions WHERE user_id=$uidSql3;
DELETE FROM votes WHERE user_id=$uidSql3;
UPDATE users SET has_voted=0 WHERE id=$uidSql3;
"@ 2>&1 | Out-Null

# ----------------------------------------------------------------
# Section: nav cleanup + draft remnants (Task 8)
# ----------------------------------------------------------------
$payloadRaw = (curl.exe -s -b $jar "$base/dashboard-data.php") -join ""
Assert-True "payload has no answeredPositionIds" ($payloadRaw -notmatch 'answeredPositionIds') ""

$shellHtml = ((curl.exe -s -b $jar "$base/dashboard.php?page=home") -join "")
Assert-True "shell has no quick-action markup" ($shellHtml -notmatch 'quick-action') ""
Assert-True "shell has no ballot progress stepper" ($shellHtml -notmatch 'renderBallotProgressStepper') ""
Assert-True "shell has no answeredPositionIds use" ($shellHtml -notmatch 'answeredPositionIds') ""
Assert-True "shell has no draftsByElection" ($shellHtml -notmatch 'draftsByElection') ""
Assert-True "sidebar label is Vote Now" ($shellHtml -match 'Vote Now') ""
Assert-True "sidebar has no bare Vote label" ($shellHtml -notmatch '>Vote<') ""

# ----------------------------------------------------------------
# Section: admin single-candidate wizard (Task 9)
# ----------------------------------------------------------------
# Source-level checks on the wizard JS inside admin/dashboard.php —
# no admin session needed, and they pin the exact three edits.
$adminSrc = Get-Content .\admin\dashboard.php -Raw
$seedBlock = [regex]::Match($adminSrc, 'function setPositionCount[\s\S]*?function updateCandidateCount').Value
$seedRows = ([regex]::Matches($seedBlock, "\{name:'', photo:''")).Count
Assert-True "wizard seeds exactly 1 candidate row" ($seedRows -eq 1) "got $seedRows seed rows"
$blankBlock = [regex]::Match($adminSrc, 'function blankDraft[\s\S]*?function openCreateElection').Value
$blankRows = ([regex]::Matches($blankBlock, "\{name:'', photo:''")).Count
Assert-True "blankDraft seeds exactly 1 candidate row" ($blankRows -eq 1) "got $blankRows seed rows"
Assert-True "candidates count input defaults to 1" ($adminSrc -match 'p\.candidates\.length \|\| 1') ""
Assert-True "candidates count input has highlight id" ($adminSrc -match 'id="pos_cands_\$\{i\}"') ""
Assert-True "candCount<1 error highlights its field" ($adminSrc -match "wizardInvalidFields\.add\('pos_cands_'\+i\)") ""
# sizeBarChartBox must be top-level (declared before drawPositionShareChart),
# not nested inside it — drawMarginChart() calls it too, and a nested
# declaration throws ReferenceError which kills the margin chart AND the
# "uncontested" legend note before it renders.
$szIdx = $adminSrc.IndexOf('function sizeBarChartBox')
$dsIdx = $adminSrc.IndexOf('function drawPositionShareChart')
Assert-True "sizeBarChartBox declared above drawPositionShareChart (top-level)" ($szIdx -gt 0 -and $dsIdx -gt 0 -and $szIdx -lt $dsIdx) "sz=$szIdx ds=$dsIdx"

if ($failures -gt 0) { Write-Output "`n$failures assertion(s) failed"; exit 1 }
Write-Output "`nAll assertions passed"
exit 0
