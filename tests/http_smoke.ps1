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
# Backup-imported Results detail view must carry the spec's uncontested
# label (plan item 6): grey pill in the position header + the explanatory
# note under the candidate rows.
Assert-True "detail view flags uncontested positions" ($adminSrc -match 'status-pill normal">UNCONTESTED') ""
Assert-True "detail view explains uncontested label" ($adminSrc -match 'only one candidate stood') ""
# Admin reset-vote must clear the ballot_submissions lock too — votes alone
# being gone does not let the student vote again (vote.php checks the lock).
$studentsSrc = Get-Content .\admin\api\students.php -Raw
Assert-True "admin reset-vote clears ballot_submissions lock" ($studentsSrc -match 'DELETE FROM ballot_submissions WHERE user_id = :uid AND election_id = :eid') ""
# Local MySQL (emulated PDO prepares) returns string ids from elections.php
# while voted_election_ids are ints — a strict .includes(e.id) never matched,
# so the student-detail Reset button could never render. The compare must
# coerce types.
Assert-True "voted-status compares coerce election id types" ($adminSrc -match 'String\(vid\) === String\(e\.id\)') ""

# ----------------------------------------------------------------
# Section: admin reports & export rework + wizard drag reorder
# ----------------------------------------------------------------
# (a) The "Reports & Export" entry leaves the 3-pill tab group and becomes
#     a standalone primary button on the right side of the results header
#     row (the same zone as the students panel's "+ Register Student").
Assert-True "results tab bar is a split header with pills left" ($adminSrc -match '(?s)results-tab-row[\s\S]{0,300}class="pill-toggle">\s*<button[^>]*>Overview</button>\s*<button[^>]*>Election Detail</button>\s*</div>') "pills not isolated"
Assert-True "Reports & Export is a standalone primary button on the right" ($adminSrc -match '(?s)results-tab-row[\s\S]{0,700}<button class="btn btn-primary[^"]*"[^>]*resultsView=.reports.; renderResults\(\);">Reports &amp; Export</button>') "reports button not primary/right-side"
Assert-True "reports button shows an active style on the reports view" ($adminSrc -match 'reports-btn-active') "no active style"
Assert-True "reports active style is defined in CSS" ($adminSrc -match '\.reports-btn-active\{') "CSS rule missing"
# (b) Generated reports get per-section selection (select all / pick some)
#     and reordering (arrows + drag), and every export output reads the
#     same filtered+ordered section list.
Assert-True "exports read one shared selected+ordered section helper" ($adminSrc -match '(?s)function reportSectionsForExport') "no shared helper"
foreach ($fn in @('reportPrintableHTML','exportReportXLSX','exportReportDoc')) {
    Assert-True "$fn uses the shared section helper" ([regex]::IsMatch($adminSrc, "(?s)function $fn\(\)\{[\s\S]{0,600}reportSectionsForExport\(\)")) "$fn still reads all sections"
}
Assert-True "report preview has a select-all control" ($adminSrc -match 'toggleAllReportSections') "no select-all handler"
Assert-True "report sections have per-section checkboxes" ($adminSrc -match 'toggleReportSection\(') "no per-section handler"
Assert-True "report sections reorderable by arrows" ($adminSrc -match 'moveReportSection\(') "no arrow handler"
Assert-True "empty selection blocks export" ($adminSrc -match 'Select at least one section') "no empty-selection guard"
# (c) Shared press-hold-drag reorder (pointer events, mouse + touch), used by
#     both the report section list and the wizard position rows.
Assert-True "shared press-hold drag helper exists" ($adminSrc -match '(?s)function attachRowDrag') "no drag helper"
Assert-True "drag helper is pointer-based for touch support" ($adminSrc -match '(?s)function attachRowDrag[\s\S]{0,900}pointerdown') "drag helper not pointer-based"
Assert-True "report section rows carry drag handles" ($adminSrc -match 'report-section-row[\s\S]{0,300}drag-handle') "no handle on report rows"
Assert-True "report arrows sit beside the drag handle like the wizard rows" ($adminSrc -match '(?s)class="report-section-row" data-idx="\$\{i\}">\s*<span class="drag-handle"[^>]*>⠿</span>\s*<button[^>]*title="Move up"') "arrows not beside the handle"
Assert-True "wizard position rows carry drag handles" ($adminSrc -match '(?s)wizardDraft\.positions\.map\(\(p,i\) =>[\s\S]{0,900}drag-handle') "no handle on wizard rows"
Assert-True "drag handle blocks page scroll while dragging" ($adminSrc -match 'touch-action:\s*none') "touch-action missing"
# Drag placement must be DIRECT: a row dragged from the bottom lands at the
# top in the same gesture, instead of lagging one row behind the pointer.
Assert-True "drag computes the target slot from the pointer position" ($adminSrc -match '(?s)function attachRowDrag[\s\S]*?listEl\.insertBefore\(row, others\[target\]\)') "placement still one-row-per-event"
Assert-True "drag can drop a row at the very bottom" ($adminSrc -match '(?s)function attachRowDrag[\s\S]*?listEl\.appendChild\(row\)') "no bottom-edge drop"
# The whole row should read as grabbed: it lifts and follows the pointer.
Assert-True "dragged row follows the pointer" ($adminSrc -match '(?s)function attachRowDrag[\s\S]*?followPointer') "row does not follow pointer"
Assert-True "dragged row lifts while grabbed" ($adminSrc -match '(?s)\.row-dragging\{[\s\S]{0,300}box-shadow') "no lifted styling"
Assert-True "displaced rows glide via FLIP instead of snapping" ($adminSrc -match '(?s)function attachRowDrag[\s\S]*?beforeTops') "no FLIP animation"
# And it should land with a visible put-down before the re-render commits.
Assert-True "row lands with a settle animation" ($adminSrc -match 'rowSettle') "no settle animation"
Assert-True "commit waits for the settle animation" ($adminSrc -match '(?s)function attachRowDrag[\s\S]*?setTimeout[\s\S]{0,200}onCommit\(\)') "commit still immediate"
# Robustness: the drop signal must not depend on pointer capture reaching the
# handle. A silently-lost capture left the row stuck in grab mode AND glued
# to the mouse (the "flies up infinitely" report) — so releases must also be
# caught at window level, and a lost capture itself must end the drag.
Assert-True "drag ends on a release anywhere (window fallback)" ($adminSrc -match '(?s)function attachRowDrag[\s\S]*?window\.addEventListener\(.pointerup., onUp') "no window-level drop listener"
# Chrome fires lostpointercapture the moment the captured row is re-slotted
# (insertBefore momentarily detaches it) WHILE THE BUTTON IS STILL HELD —
# treating that as a drop ended every drag after the first reorder. A held
# drag must survive capture loss; a genuinely stale drag is ended instead
# by any pointermove with no button pressed.
Assert-True "capture loss does NOT end a held drag" ($adminSrc -notmatch "addEventListener\('lostpointercapture'") "lostpointercapture still treated as a drop"
Assert-True "a stale drag ends when no button is held" ($adminSrc -match '(?s)function attachRowDrag[\s\S]*?ev\.buttons === 0') "no buttons-check drop guard"
Assert-True "drag ends when the window loses focus" ($adminSrc -match '(?s)function attachRowDrag[\s\S]*?addEventListener\(.blur., onUp') "no blur safety net"
Assert-True "a drop is processed exactly once" ($adminSrc -match '(?s)function attachRowDrag[\s\S]*?let ended = false') "no once-guard"
Assert-True "capture failure cannot break the drag" ($adminSrc -match '(?s)function attachRowDrag[\s\S]*?try \{[\s\S]*?setPointerCapture') "setPointerCapture unguarded"
# Tracking must not depend on the handle receiving events: if pointer capture
# dies mid-gesture the row stopped following, so a long drag only advanced
# one slot per grab. Window-level move tracking survives any capture loss.
Assert-True "drag tracks the pointer at window level" ($adminSrc -match '(?s)function attachRowDrag[\s\S]*?window\.addEventListener\(.pointermove., onMove') "tracking still handle-only"
# Target-slot math must read LAYOUT positions (offsetTop), not animated
# rects — mid-FLIP rect reads made the target index oscillate mid-gesture.
Assert-True "drag target math ignores in-flight animations" ($adminSrc -match '(?s)function attachRowDrag[\s\S]*?offsetTop') "target math still uses animated rects"
# Reports school-year default: must coerce types AND default to the year the
# elections serve (next school year), not 2023-2024 via the [0] fallback.
Assert-True "report school year default coerces option types" ($adminSrc -match 'String\(sy\.start_year\) === String\(nextSchoolYearStartYearClient\(\)\)') "find() still type-mismatches"
Assert-True "report school year defaults to the serving year" ($adminSrc -match 'reportTargetSchoolYearId = \(schoolYearOptions\.find\(sy => String\(sy\.start_year\) === String\(nextSchoolYearStartYearClient\(\)\)\)') "default not the serving year"

# ----------------------------------------------------------------
# Section: 9.5 batch — brand port (landing fonts carried inward)
# ----------------------------------------------------------------
$studentCss = Get-Content .\assets\dashboard-app.css -Raw
$dashSrc = Get-Content .\dashboard.php -Raw
Assert-True "student css defines the landing fonts as tokens" ($studentCss -match "--font-display: 'Sora'" -and $studentCss -match "--font-mono: 'IBM Plex Mono'") "font tokens missing"
Assert-True "student page header uses the display font" ($studentCss -match '(?s)\.page-header h1 \{[^}]*font-family: var\(--font-display\)') "page-header h1 still system-ui"
Assert-True "student section titles use the display font" ($studentCss -match '(?s)\.section-title \{[^}]*font-family: var\(--font-display\)') "section-title still system-ui"
Assert-True "sticky ballot title uses the display font" ($studentCss -match '(?s)\.ballot-title \{[^}]*font-family: var\(--font-display\)') "ballot-title still system-ui"
Assert-True "ballot position titles carry the lime accent" ($studentCss -match '(?s)\.ballot-position-title \{[^}]*border-left: 3px solid var\(--accent\)') "no lime accent on position titles"
Assert-True "ballot branch head loads the brand fonts" (([regex]::Matches($dashSrc, 'family=Sora')).Count -ge 2) "font link missing from a dashboard head"
Assert-True "admin head loads the brand fonts" ($adminSrc -match 'family=Sora') "no font link in admin head"
Assert-True "admin section titles use the display font" ($adminSrc -match '(?s)\.section-title\{[^}]*font-family:var\(--font-display\)') "admin titles still system fonts"
Assert-True "admin stat tile labels use the mono eyebrow" ($adminSrc -match '(?s)\.stat-tile \.k\{[^}]*font-family:var\(--font-mono\)') "stat labels not mono"
Assert-True "no informational label below .75rem in student css" (-not ($studentCss -match 'font-size:\s*0?\.6[0-9]rem|font-size:\s*0?\.7[0-4]rem')) "tiny label sizes remain"

# ----------------------------------------------------------------
# Section: 9.5 batch — has_voted retirement (reads)
# ----------------------------------------------------------------
# decisions must read ballot_submissions, not the legacy flag; the flag's
# WRITE stays only because the partner's uncommitted login.php redirects on
# it — flagged in the ledger, not edited.
Assert-True "student dashboard no longer selects the legacy has_voted flag" ($dashSrc -notmatch "SELECT id, name, has_voted, department, year_level FROM users") "flag still selected in dashboard.php"
$ddSrc = Get-Content .\dashboard-data.php -Raw
Assert-True "dashboard-data no longer selects the legacy has_voted flag" ($ddSrc -notmatch 'has_voted') "flag still selected in dashboard-data.php"
$studentsApiSrc = Get-Content .\admin\api\students.php -Raw
Assert-True "admin student list derives voted from ballot_submissions" ($studentsApiSrc -match 'EXISTS\(SELECT 1 FROM ballot_submissions bs WHERE bs\.user_id = u\.id\) AS has_voted') "chip still reads the legacy column"

# ----------------------------------------------------------------
# Section: 9.5 batch — login throttling (characterized, pre-existing)
# ----------------------------------------------------------------
# Both login flows already throttle per-IP (8 attempts / 5 min) via
# includes/functions.php. Pinned here so it cannot silently regress.
$studentLoginSrc = Get-Content .\login.php -Raw
Assert-True "student login throttles before verifying credentials" ($studentLoginSrc -match 'isRateLimited\(\$pdo, ''student_login''\)' -and $studentLoginSrc -match 'recordAttempt\(\$pdo, ''student_login''\)') "student login unthrottled"
$adminLoginSrc = Get-Content .\admin\login.php -Raw
Assert-True "admin login throttles before verifying credentials" ($adminLoginSrc -match 'isRateLimited\(\$pdo, ''admin_login''\)' -and $adminLoginSrc -match 'recordAttempt\(\$pdo, ''admin_login''\)') "admin login unthrottled"
$functionsSrc = Get-Content .\includes\functions.php -Raw
Assert-True "throttle implementation exists in functions.php" ($functionsSrc -match 'function isRateLimitedKey\(' -and $functionsSrc -match 'function recordAttemptKey\(') "throttle functions missing"
# Dead code: the admin's drawPartyChart renders a chart into a card the
# markup never emits — verify it is gone or, if it must stay, that its
# container actually exists somewhere.
Assert-True "dead party-chart code removed" ($adminSrc -notmatch 'function drawPartyChart') "drawPartyChart still present"

# ----------------------------------------------------------------
# Section: 9.5 batch — branded 404 + credential hygiene
# ----------------------------------------------------------------
# The branded 404 is served two ways: Apache ErrorDocument (local) and a
# router.php wired into the Dockerfile's php -S CMD (Render). The .htaccess
# also stops shipping the commented-out Neon credential so it can be
# committed as the local XAMPP config.
$notFoundSrc = if (Test-Path .\404.php) { Get-Content .\404.php -Raw } else { '' }
Assert-True "branded 404 page exists with the brand headline" ($notFoundSrc -match "isn't on the ballot") "404.php missing or unbranded"
Assert-True "404 page carries the brand fonts" ($notFoundSrc -match 'family=Sora') "404 not on-brand"
$htaccessSrc = Get-Content .\.htaccess -Raw
Assert-True "htaccess ships an ErrorDocument 404" ($htaccessSrc -match 'ErrorDocument 404') "no ErrorDocument"
Assert-True "htaccess carries no database credentials" ($htaccessSrc -notmatch 'npg_|postgresql://[^\s]*:[^\s]*@') "credential still in .htaccess"
$gitignoreSrc = Get-Content .\.gitignore -Raw
Assert-True "htaccess is no longer gitignored" ($gitignoreSrc -notmatch '(?m)^\.htaccess\s*$') ".gitignore still excludes .htaccess"
$routerSrc = if (Test-Path .\router.php) { Get-Content .\router.php -Raw } else { '' }
Assert-True "php -S router exists and serves the branded 404" ($routerSrc -match '404\.php' -and $routerSrc -match 'http_response_code\(404\)') "router missing"
$dockerfileSrc = Get-Content .\Dockerfile -Raw
Assert-True "Dockerfile wires the router" ($dockerfileSrc -match 'router\.php') "Dockerfile not using router"
# Behavioral: an unknown path answers 404 with the branded page (Apache).
$r404 = Invoke-Get "$base/__no_such_page_9_5.php"
Assert-True "unknown path answers 404" ($r404.Code -eq '404') "got $($r404.Code)"
Assert-True "unknown path shows the branded page" ($r404.Text -match "isn't on the ballot") "default error page served"

# ----------------------------------------------------------------
# Section: 9.5 batch — CI workflow (static checks runnable anywhere)
# ----------------------------------------------------------------
# The full HTTP suite needs XAMPP + local MariaDB, so it stays local; CI
# carries the two checks that run cross-platform: php -l over every file
# and the inline-JS parse guard on both dashboards.
$ciSrc = if (Test-Path .\.github\workflows\ci.yml) { Get-Content .\.github\workflows\ci.yml -Raw } else { '' }
Assert-True "CI workflow exists" ($ciSrc -ne '') ".github/workflows/ci.yml missing"
Assert-True "CI lints every PHP file" ($ciSrc -match 'php -l') "no php -l step"
Assert-True "CI runs the JS parse guard" ($ciSrc -match 'extract_admin_js\.php' -and $ciSrc -match 'node --check') "parse guard not wired"
Assert-True "CI checks both dashboards" ($ciSrc -match 'dashboard\.php' -and $ciSrc -match 'admin/dashboard\.php') "a dashboard is unchecked"
Assert-True "CI pins PHP 8.3" ($ciSrc -match "8\.3") "php version not pinned"

# JS PARSE GUARD — a syntax error in a page's main inline script kills every
# function on it (the HTML still renders; nothing responds) and the regex
# checks above cannot see that. Extract the admin page's main script and
# hand it to `node --check`. This caught a real bug on 2026-10-09: a botched
# edit dropped a function body and the whole admin script died silently.
$node = (Get-Command node -ErrorAction SilentlyContinue).Source
if ($node) {
    $jsPath = & "C:\xampp\php\php.exe" tests\extract_admin_js.php admin\dashboard.php
    $nodeOut = & $node --check $jsPath 2>&1
    Assert-True "admin page main script parses as JavaScript" ($LASTEXITCODE -eq 0) ($nodeOut -join ' ')
}

# ----------------------------------------------------------------
# Section: perf core — gated prelude + guarded status sync (Task 10)
# ----------------------------------------------------------------
# (a) Guarded sync end-to-end: a scheduled election whose start has
#     already passed must flip to ongoing when a page running
#     syncElectionStatuses() loads. Capture/restore election 1's row so
#     the fixture is invisible to anything after this section.
$e1row = & $mysql -u root voting_app -N -e "SELECT status, start_date, end_date FROM elections WHERE id=1"
$e1 = @((($e1row -join "`n") -split "`t") | ForEach-Object { $_.Trim() })
& $mysql -u root voting_app -e "UPDATE elections SET status='scheduled', start_date=DATE_SUB(NOW(), INTERVAL 1 DAY), end_date=DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id=1" 2>&1 | Out-Null
$syncLoad = Invoke-Get "$base/landingpage.php"
$flipped = ((& $mysql -u root voting_app -N -e "SELECT status FROM elections WHERE id=1") -join '').Trim()
& $mysql -u root voting_app -e "UPDATE elections SET status='$($e1[0])', start_date='$($e1[1])', end_date='$($e1[2])' WHERE id=1" 2>&1 | Out-Null
Assert-True "landing load returns 200 during sync test" ($syncLoad.Code -eq '200') "got $($syncLoad.Code)"
Assert-True "past-scheduled election flips to ongoing on page load" ($flipped -eq 'ongoing') "got '$flipped' after landing load (code $($syncLoad.Code))"

# (b) Repeat load: no error, and the second load is not slower than the
#     first — guards against per-load work creeping back into the prelude.
$sw1 = [Diagnostics.Stopwatch]::StartNew(); $lb1 = Invoke-Get "$base/landingpage.php"; $sw1.Stop()
$sw2 = [Diagnostics.Stopwatch]::StartNew(); $lb2 = Invoke-Get "$base/landingpage.php"; $sw2.Stop()
Assert-True "repeat landing loads both 200" ($lb1.Code -eq '200' -and $lb2.Code -eq '200') "got $($lb1.Code)/$($lb2.Code)"
Assert-True "second landing load not slower than the first" ($sw2.ElapsedMilliseconds -le ($sw1.ElapsedMilliseconds * 2 + 500)) "t1=$($sw1.ElapsedMilliseconds)ms t2=$($sw2.ElapsedMilliseconds)ms"

# (c) SPA mode must serve the Branch B shell and never Branch A ballot
#     markup (data-panel marker keeps this from passing vacuously on a
#     redirect to the login page).
$spaFile = [System.IO.Path]::GetTempFileName()
$spaCode = (curl.exe -s -b $jar -o $spaFile -w "%{http_code}" "$base/dashboard.php") -join ''
$spaText = [System.Text.Encoding]::UTF8.GetString([System.IO.File]::ReadAllBytes($spaFile))
Remove-Item $spaFile -Force
Assert-True "student dashboard (no ?page) loads 200" ($spaCode -eq '200') "got $spaCode"
Assert-True "dashboard no-?page renders the SPA shell" ($spaText -match 'data-panel="home"') "SPA marker missing"
Assert-True "dashboard no ?page has no ballot-step markup" ($spaText -notmatch 'ballot-step') "ballot markup leaked into SPA shell"

# (d) Source: the heavy per-election data block must run AFTER page
#     detection and only inside the page=vote gate, so Branch B loads
#     keep only session/user/light queries.
$dashSrc = Get-Content .\dashboard.php -Raw
$pageDetectIdx = $dashSrc.IndexOf('$page = $_GET[')
$heavyGateIdx = $dashSrc.IndexOf("if (`$page === 'vote' && `$selectedElectionId) {")
$heavyStartIdx = $dashSrc.IndexOf('$electionData = [];')
Assert-True "page detection precedes the heavy data block" ($pageDetectIdx -ge 0 -and $heavyStartIdx -gt $pageDetectIdx) "pageIdx=$pageDetectIdx heavyIdx=$heavyStartIdx"
Assert-True "heavy election-data block gated behind page=vote" ($heavyGateIdx -ge 0 -and $heavyGateIdx -lt $heavyStartIdx) "gateIdx=$heavyGateIdx heavyIdx=$heavyStartIdx"

# Results behavior smoke — pins that batched queries still render the
# closed CASED election's card (its tallies are fixture-stable: no test
# casts votes there).
$r = Invoke-Get "$base/results.php"
Assert-True "results page loads 200" ($r.Code -eq '200') "got $($r.Code)"
Assert-True "results renders closed election card" ($r.Text -match 'CASED Student Council Election 2026') "CASED card missing"
Assert-True "results renders turnout line" ($r.Text -match 'ballots cast') "turnout line missing"

# ----------------------------------------------------------------
# Section: ballot exit confirm + re-click unvote (partner follow-ups)
# ----------------------------------------------------------------
# Exit confirm: leaving the ballot with unsubmitted selections must ask
# first. Re-click unvote: clicking an already-selected radio clears it
# (the no-vote option for single-choice positions).
$ballotSrc = Get-Content .\dashboard.php -Raw
Assert-True "ballot exit links intercepted with confirm dialog" ($ballotSrc -match 'ballotExitLinks') "no exit interception found"
Assert-True "exit dialog warns unsubmitted selections are lost" ($ballotSrc -match 'will not be saved') "warning text missing"
Assert-True "selected radio can be unvoted by re-clicking it" ($ballotSrc -match 'prevCheckedByGroup') "no re-click unvote logic found"

# ----------------------------------------------------------------
# Section: ballot sticky header layout + centered submit (follow-up 2)
# ----------------------------------------------------------------
# The sticky election/progress bar must sit below the (also sticky) page
# header, span the full content width, and the submit button must center.
$ballotCss = Get-Content .\assets\dashboard-app.css -Raw
Assert-True "ballot sticky bar offsets below the page header dynamically" ($ballotSrc -match 'syncBallotStickyTop') "no sticky-top sync JS found"
Assert-True "ballot sticky bar spans the full content width" ($ballotCss -match '(?s)\.ballot-header \{[^}]*margin-left: -2rem') "no full-bleed margins on .ballot-header"
Assert-True "mobile keeps the ballot bar full-bleed" ($ballotCss -match '\.ballot-header \{ top: 0; margin-left: -1rem') "no mobile full-bleed rule"
Assert-True "submit vote button centers on all screen sizes" ($ballotCss -match '(?s)\.ballot-submit-block \.btn \{[^}]*inline-flex') "button is still block-level flex"

# Mobile ballot follow-up: page header full-bleed + scrolls away on the
# voting page; ballot bar pins at the very top. The mobile overrides MUST
# come after the base .ballot-header rule or the -2rem desktop bleed wins
# the cascade and overflows the phone screen (that bug shipped in d191f65).
Assert-True "ballot page header tagged for mobile opt-out" ($ballotSrc -match 'ballot-page-header') "class missing"

# The ballot screen's top header must speak the ballot's language, not the
# generic dashboard greeting.
Assert-True "ballot page header uses a ballot-appropriate title" ($ballotSrc -match '(?s)page-header ballot-page-header.{0,300}<h1>Cast Your Vote</h1>') "header title not updated"
Assert-True "ballot page header drops the dashboard greeting" ($ballotSrc -notmatch '(?s)page-header ballot-page-header.{0,300}Welcome') "header still greets the user"
Assert-True "mobile ballot overrides come after the base rule" ($ballotCss.IndexOf('margin-left: -2rem') -lt $ballotCss.IndexOf('.ballot-header { top: 0')) "mobile rule loses the cascade"
Assert-True "mobile page header spans full width" ($ballotCss -match 'margin-left: -1rem; margin-right: -1rem; border-radius: 0') "page header still inset"
Assert-True "dead pre-base mobile bleed rule removed" ($ballotCss -notmatch '\.ballot-header \{ margin-left: -1rem') "old rule still present"
Assert-True "no gap above the mobile page header" ($ballotCss -match '\.main-content \{ padding-top: 0; \}') "container top padding still shows above the header"

# Candidate modal on mobile: the whole profile must scroll as one page
# (photo + info + platform), not just the platform pane.
Assert-True "candidate modal scrolls as one page on mobile" ($ballotCss -match '(?s)\.cand-modal \{ flex-direction: column;[^}]*overflow-y: auto') "modal still overflow:hidden on mobile"
Assert-True "candidate modal panes don't scroll independently on mobile" ($ballotCss -match '(?s)\.cand-modal-left \{ flex: 0 0 auto;[^}]*overflow: visible') "left pane still self-scrolls"
Assert-True "candidate modal close button stays reachable when scrolled" ($ballotCss -match '(?s)@media \(max-width: 768px\) \{[^@]*?\.cand-modal-close \{ position: fixed') "close button scrolls away"

if ($failures -gt 0) { Write-Output "`n$failures assertion(s) failed"; exit 1 }
Write-Output "`nAll assertions passed"
exit 0
