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
#     (2026-10-10 redesign: the Overview/Election Detail pair moved from
#     pill-toggle sort-style pills to a segmented .view-switch.)
Assert-True "results tab bar is a split header with the view switch left" ($adminSrc -match '(?s)results-tab-row[\s\S]{0,300}class="view-switch">\s*<button[^>]*>Overview</button>\s*<button[^>]*>Election Detail</button>\s*</div>') "view switch not isolated"
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

# ----------------------------------------------------------------
# Section: admin quick actions removed + students panel boundary fix
# ----------------------------------------------------------------
# The two-button strip (+ Create Election / + Register Student) is gone;
# the Getting Started card keeps its own buttons and BOTH helper
# functions (they are that card's only callers).
Assert-True "quick actions strip no longer rendered" ($adminSrc -notmatch 'class="quick-actions"' -and $adminSrc -notmatch 'quickActions = !gettingStarted') "quick actions strip still present"
Assert-True "quick-actions css removed" ($adminSrc -notmatch '\.quick-actions\{') "dead .quick-actions css remains"
Assert-True "getting-started card keeps both helper buttons" ($adminSrc -match 'onclick="quickRegisterStudent\(\)"' -and $adminSrc -match 'onclick="quickCreateElection\(\)"') "onboarding buttons lost"
Assert-True "both quick helpers still defined" ($adminSrc -match 'function quickCreateElection\(' -and $adminSrc -match 'function quickRegisterStudent\(') "helper deleted that is still referenced"
# Boundary: .main-content is a flex item (flex:1 1 0%) whose auto
# min-width lets its min-content (the 9-column students table, ~1280px)
# push the whole panel past the viewport right edge where
# html/body overflow-x:hidden clips it. min-width:0 restores the bound.
Assert-True "main-content is bounded against min-content push" ($adminSrc -match '\.main-content\{[^}]*min-width:0') "min-width:0 missing from .main-content"

# ----------------------------------------------------------------
# Section: students table fits its width (no sideways scroll)
# ----------------------------------------------------------------
# Partner approved vertical scrolling but wants every column visible
# left-to-right: fixed layout + colgroup widths + ellipsis + tooltips
# replace the min-width:920px horizontal-scroll design.
Assert-True "students table uses fixed layout, no forced min-width" ($adminSrc -match 'table\.students-table\{[^}]*table-layout:fixed' -and $adminSrc -notmatch 'min-width:920px') "table still forced wide"
Assert-True "students table declares colgroup widths" ($adminSrc -match '<colgroup>' -and $adminSrc -match '<col style="width:22%">') "no colgroup widths"
Assert-True "student cells ellipsize instead of overflowing" ($adminSrc -match '(?s)table\.students-table td\{[^}]*text-overflow:ellipsis') "cells not ellipsized"
Assert-True "faculty cell carries a hover title" ($adminSrc -match 'td title="\$\{escapeHtml\(s\.department\)\}"') "no tooltip on faculty"
Assert-True "name cell carries a hover title" ($adminSrc -match 'td class="name-cell" title=') "no tooltip on name"

# ----------------------------------------------------------------
# Section: students pagination page numbers + search focus retention
# ----------------------------------------------------------------
Assert-True "pagination renders clickable page numbers" ($adminSrc -match 'class="page-btn' -and $adminSrc -match 'goToStudentPage\(\$\{n\}\)') "no clickable page numbers"
Assert-True "pagination keeps left/right arrows" ($adminSrc -match 'title="Previous page"' -and $adminSrc -match 'title="Next page"') "arrows lost"
Assert-True "current page is marked" ($adminSrc -match 'aria-current="page"') "no current-page marker"
Assert-True "search focus survives the re-render" ($adminSrc -match 'searchFocused' -and $adminSrc -match 'setSelectionRange\(searchCaret, searchCaret\)') "focus not restored after render"
Assert-True "students page size is 15" ($adminSrc -match 'STUDENTS_PER_PAGE = 15') "page size not 15"
Assert-True "pagination bar shows whenever there are rows" ($adminSrc -match 'const paginationBar = filtered\.length > 0 \?') "bar still hidden on a single page"

# ----------------------------------------------------------------
# Section: results view switch (Overview / Election Detail)
# ----------------------------------------------------------------
# These two switch the RESULTS VIEW, unlike the Elections status pills
# (sorters) - they get a segmented-control look: one joined track, the
# active view a filled segment.
Assert-True "results tabs use the segmented view switch" ($adminSrc -match 'function resultsTabBar\(\)\{[\s\S]{0,1600}?<div class="view-switch">') "resultsTabBar still on pill-toggle"
Assert-True "view-switch css exists" ($adminSrc -match '\.view-switch\{display:inline-flex') "no .view-switch rule"
Assert-True "view switch active state is a filled segment" ($adminSrc -match '(?s)\.view-switch button\.selected\{[^}]*background:var\(--navy\)') "no segment active style"
Assert-True "view-switch button keyboard focus styled" ($adminSrc -match '\.view-switch button:focus-visible') "focus missing"
Assert-True "view switch selected segment visible in dark mode" ($adminSrc -match '(?s)\[data-theme="dark"\] \.view-switch button\.selected\{[^}]*background:var\(--navy-2\)') "dark-mode selected state missing"

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

# ----------------------------------------------------------------
# Section: Batch A1+A2 - security headers + CI schema parity wiring
# ----------------------------------------------------------------
# php -S (Render production) never reads .htaccess, so the header block
# must exist in BOTH .htaccess (Apache) and router.php (production). The
# CSP must allow cdnjs (SheetJS + Chart.js on the student dashboard,
# SRI-pinned) and Google Fonts, or those break silently.
$r = Invoke-Get "$base/login.php"
Assert-True "Content-Security-Policy served on pages" ($r.Headers -match "Content-Security-Policy:") "CSP header missing"
Assert-True "CSP restricts framing to same origin" ($r.Headers -match "frame-ancestors 'self'") "frame-ancestors missing"
Assert-True "CSP allows the cdnjs chart/export libraries" ($r.Headers -match "cdnjs\.cloudflare\.com") "cdnjs not whitelisted"
Assert-True "X-Content-Type-Options nosniff served" ($r.Headers -match "X-Content-Type-Options:\s*nosniff") "nosniff missing"
Assert-True "X-Frame-Options SAMEORIGIN served" ($r.Headers -match "X-Frame-Options:\s*SAMEORIGIN") "XFO missing"
$routerSrc = Get-Content .\router.php -Raw
Assert-True "router.php sets the security headers (production php -S)" (($routerSrc -match 'X-Content-Type-Options') -and ($routerSrc -match 'Content-Security-Policy')) "router header block missing"
Assert-True "router.php gates HSTS behind HTTPS" (($routerSrc -match 'Strict-Transport-Security') -and ($routerSrc -match "HTTPS")) "router HSTS missing or ungated"
$htaccessSrc = Get-Content .\.htaccess -Raw
Assert-True ".htaccess sends HSTS only over HTTPS" ($htaccessSrc -match "expr=%\{HTTPS\}") "HSTS not HTTPS-gated"
Assert-True ".htaccess sets a Content-Security-Policy" ($htaccessSrc -match 'Header always set Content-Security-Policy') "CSP line missing"
$ciSrc = Get-Content .\.github\workflows\ci.yml -Raw
Assert-True "CI runs the schema parity check" ($ciSrc -match 'schema_parity\.php') "not wired into CI"

# ----------------------------------------------------------------
# Section: Batch A3 - admin throttle unlock (logs view)
# ----------------------------------------------------------------
# Fixture: one HOT student bucket (inside the 300s window), one STALE
# bucket (outside it - must NOT be listed), and a vote bucket, all for
# a documentation-only IP so the real login buckets stay untouched.
& $mysql -u root voting_app -e "DELETE FROM login_attempts; INSERT INTO login_attempts (identifier, attempted_at) VALUES ('student_login:203.0.113.9', NOW()), ('admin_login:203.0.113.9', NOW() - INTERVAL 2 HOUR), ('vote_submit:user:3', NOW());" 2>&1 | Out-Null

$ajar = [System.IO.Path]::GetTempFileName()
$adminLoginHtml = ((curl.exe -s -c $ajar "$base/admin/login.php") -join "`n")
$adminToken = [regex]::Match($adminLoginHtml, 'name="csrf_token"\s+value="([^"]+)"').Groups[1].Value
$adminCode = curl.exe -s -b $ajar -c $ajar -o NUL -w "%{http_code}" -X POST "$base/admin/login.php" -d "csrf_token=$adminToken&username=admin&password=admin123"
Assert-True "admin login succeeds (302)" ($adminCode -eq "302") "got $adminCode"

$adminDash = ((curl.exe -s -b $ajar "$base/admin/dashboard.php") -join "`n")
$adminCsrf = [regex]::Match($adminDash, 'const CSRF_TOKEN = "([^"]+)"').Groups[1].Value
Assert-True "admin dashboard yields the API csrf token" ($adminCsrf -ne "") "not found"

$thJson = ((curl.exe -s -b $ajar "$base/admin/api/logs.php?throttled=1") -join "")
$th = $thJson | ConvertFrom-Json
$thIds = @($th | ForEach-Object { $_.identifier })
Assert-True "throttled list shows the hot student bucket" ($thIds -contains 'student_login:203.0.113.9') "missing hot id in: $thJson"
Assert-True "throttled list shows the vote bucket" ($thIds -contains 'vote_submit:user:3') "missing vote id"
Assert-True "throttled list ignores stale attempts" ($thIds -notcontains 'admin_login:203.0.113.9') "stale id leaked"

# JSON bodies via temp file: PowerShell strips embedded double quotes from
# native-command arguments (curl received `{ a: 1 }`), so -d '...' can never
# carry JSON on Windows. Writing the body to a file sidesteps the quoting.
$delFile = [System.IO.Path]::GetTempFileName()
[System.IO.File]::WriteAllText($delFile, '{ "csrf_token": "' + $adminCsrf + '", "identifier": "student_login:203.0.113.9" }')
$delJson = ((curl.exe -s -b $ajar -X DELETE "$base/admin/api/logs.php" --data-binary "@$delFile") -join "")
$delObj = $delJson | ConvertFrom-Json
Assert-True "unblock succeeds" ($delObj.success -eq $true) $delJson
$left = (& $mysql -u root voting_app -e "SELECT COUNT(*) FROM login_attempts WHERE identifier='student_login:203.0.113.9';" | Out-String)
Assert-True "throttle rows deleted" ($left -match '\b0\b') "still present: $left"
$logHit = (& $mysql -u root voting_app -e "SELECT COUNT(*) FROM activity_logs WHERE action LIKE 'Unblocked rate-limit bucket%';" | Out-String)
Assert-True "unblock writes an audit log line" ($logHit -match '[1-9]') "no audit row"

[System.IO.File]::WriteAllText($delFile, '{ "csrf_token": "bogus", "identifier": "student_login:203.0.113.9" }')
$badJson = ((curl.exe -s -b $ajar -X DELETE "$base/admin/api/logs.php" --data-binary "@$delFile") -join "")
Assert-True "unblock refuses a bogus csrf token" ($badJson -match 'expired') "accepted: $badJson"

[System.IO.File]::WriteAllText($delFile, '{ "csrf_token": "' + $token + '", "identifier": "vote_submit:user:3" }')
$sDelCode = curl.exe -s -b $jar -o NUL -w "%{http_code}" -X DELETE "$base/admin/api/logs.php" --data-binary "@$delFile"
Remove-Item $delFile -Force -ErrorAction SilentlyContinue
Assert-True "students cannot unblock (401 from api guard)" ($sDelCode -eq '401') "got $sDelCode"
$voteLeft = (& $mysql -u root voting_app -e "SELECT COUNT(*) FROM login_attempts WHERE identifier='vote_submit:user:3';" | Out-String)
Assert-True "student attempt left the vote bucket intact" ($voteLeft -match '[1-9]') "bucket was cleared"

# UI contract: the Logs view carries the throttle card + unblock handler.
Assert-True "logs view renders the throttle card" ($adminSrc -match 'throttle-card') "CSS/markup missing"
Assert-True "logs view fetches the throttled list" ($adminSrc -match 'throttled=1') "fetch missing"
Assert-True "unblock handler exists in the logs view" ($adminSrc -match 'function unblockThrottle') "handler missing"

& $mysql -u root voting_app -e "DELETE FROM login_attempts WHERE identifier LIKE '%203.0.113.9' OR identifier='vote_submit:user:3';" 2>&1 | Out-Null
Remove-Item $ajar -Force -ErrorAction SilentlyContinue

# ----------------------------------------------------------------
# Section: Batch A4 - maintenance prune (Settings view)
# ----------------------------------------------------------------
# Fixture: one stale + one live row of every prunable kind. A CLOSED and
# an ONGOING fixture election each carry a draft - only the closed one's
# draft may be pruned. Fresh admin session (mjar): A3 deleted its own jar.
& $mysql -u root voting_app -e "DELETE FROM vote_drafts WHERE election_id IN (SELECT id FROM elections WHERE name LIKE 'PRUNE FIXTURE%'); DELETE FROM elections WHERE name LIKE 'PRUNE FIXTURE%'; DELETE FROM activity_logs WHERE admin_username='fixture'; DELETE FROM login_attempts; INSERT INTO login_attempts (identifier, attempted_at) VALUES ('old_prune:1', NOW() - INTERVAL 10 DAY), ('hot_prune:1', NOW()); INSERT INTO activity_logs (admin_username, action, created_at) VALUES ('fixture', 'old log line', NOW() - INTERVAL 400 DAY); INSERT INTO elections (name, type, status, start_date, end_date) VALUES ('PRUNE FIXTURE closed', 'SSG', 'closed', NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 5 DAY), ('PRUNE FIXTURE ongoing', 'SSG', 'ongoing', NOW() - INTERVAL 1 DAY, NOW() + INTERVAL 5 DAY); INSERT INTO vote_drafts (user_id, election_id, selections) SELECT 3, id, '{}' FROM elections WHERE name LIKE 'PRUNE FIXTURE%';" 2>&1 | Out-Null

$mjar = [System.IO.Path]::GetTempFileName()
$mLoginHtml = ((curl.exe -s -c $mjar "$base/admin/login.php") -join "`n")
$mToken = [regex]::Match($mLoginHtml, 'name="csrf_token"\s+value="([^"]+)"').Groups[1].Value
$mCode = curl.exe -s -b $mjar -c $mjar -o NUL -w "%{http_code}" -X POST "$base/admin/login.php" -d "csrf_token=$mToken&username=admin&password=admin123"
Assert-True "maintenance section: admin login succeeds (302)" ($mCode -eq "302") "got $mCode"
$mDash = ((curl.exe -s -b $mjar "$base/admin/dashboard.php") -join "`n")
$mCsrf = [regex]::Match($mDash, 'const CSRF_TOKEN = "([^"]+)"').Groups[1].Value

$mc = ((curl.exe -s -b $mjar "$base/admin/api/settings.php?type=maintenance") -join "") | ConvertFrom-Json
Assert-True "maintenance counts include closed-election drafts" ($mc.drafts_closed_elections -ge 1) "got $($mc.drafts_closed_elections)"
Assert-True "maintenance counts include stale attempts" ($mc.attempts_older_than_7d -ge 1) "got $($mc.attempts_older_than_7d)"
Assert-True "maintenance counts include year-old logs" ($mc.logs_older_than_365d -ge 1) "got $($mc.logs_older_than_365d)"

$mBody = [System.IO.Path]::GetTempFileName()
[System.IO.File]::WriteAllText($mBody, '{ "csrf_token": "' + $mCsrf + '", "type": "maintenance", "action": "prune_drafts" }')
$pr1 = ((curl.exe -s -b $mjar -X POST "$base/admin/api/settings.php" --data-binary "@$mBody") -join "")
$pr1o = $pr1 | ConvertFrom-Json
Assert-True "prune_drafts succeeds" ($pr1o.success -eq $true) $pr1
$closedLeft = (& $mysql -u root voting_app -e "SELECT COUNT(*) FROM vote_drafts vd JOIN elections e ON e.id=vd.election_id WHERE e.name='PRUNE FIXTURE closed';" | Out-String)
Assert-True "closed election's draft pruned" ($closedLeft -match '\b0\b') "left: $closedLeft"
$openLeft = (& $mysql -u root voting_app -e "SELECT COUNT(*) FROM vote_drafts vd JOIN elections e ON e.id=vd.election_id WHERE e.name='PRUNE FIXTURE ongoing';" | Out-String)
Assert-True "ongoing election's draft survives" ($openLeft -match '\b1\b') "left: $openLeft"

[System.IO.File]::WriteAllText($mBody, '{ "csrf_token": "' + $mCsrf + '", "type": "maintenance", "action": "prune_attempts" }')
$pr2 = ((curl.exe -s -b $mjar -X POST "$base/admin/api/settings.php" --data-binary "@$mBody") -join "")
$pr2o = $pr2 | ConvertFrom-Json
Assert-True "prune_attempts succeeds" ($pr2o.success -eq $true) $pr2
$attLeft = (& $mysql -u root voting_app -e "SELECT COUNT(*) FROM login_attempts WHERE identifier='hot_prune:1';" | Out-String)
Assert-True "hot attempt row survives prune_attempts" ($attLeft -match '\b1\b') "left: $attLeft"

[System.IO.File]::WriteAllText($mBody, '{ "csrf_token": "' + $mCsrf + '", "type": "maintenance", "action": "prune_logs" }')
$pr3 = ((curl.exe -s -b $mjar -X POST "$base/admin/api/settings.php" --data-binary "@$mBody") -join "")
$pr3o = $pr3 | ConvertFrom-Json
Assert-True "prune_logs succeeds" ($pr3o.success -eq $true) $pr3
$logsLeft = (& $mysql -u root voting_app -e "SELECT COUNT(*) FROM activity_logs WHERE admin_username='fixture';" | Out-String)
Assert-True "year-old log line pruned" ($logsLeft -match '\b0\b') "left: $logsLeft"

[System.IO.File]::WriteAllText($mBody, '{ "csrf_token": "' + $mCsrf + '", "type": "maintenance", "action": "drop_everything" }')
$badAct = ((curl.exe -s -b $mjar -X POST "$base/admin/api/settings.php" --data-binary "@$mBody") -join "")
Assert-True "unknown maintenance action refused" ($badAct -match 'Unknown maintenance action') "got: $badAct"
Remove-Item $mBody -Force -ErrorAction SilentlyContinue

# UI contract: Settings carries the maintenance card + prune handler.
Assert-True "settings renders maintenance rows" ($adminSrc -match 'maintenance-row') "markup missing"
Assert-True "settings loads maintenance counts" ($adminSrc -match 'type=maintenance') "counts fetch missing"
Assert-True "prune handler exists in settings" ($adminSrc -match 'function pruneMaintenance') "handler missing"

# UI contract: every settings card except the last carries the 1.5rem gap;
# the .card class has no margin of its own, so a card missing the inline
# style sits flush against the one above it.
$spacedSettingsCards = ([regex]::Matches($adminSrc, 'class="card" style="margin-bottom:1\.5rem;"')).Count
Assert-True "settings cards carry inter-card spacing" ($spacedSettingsCards -ge 5) "only $spacedSettingsCards spaced cards; the rest render flush"

# cleanup fixture
& $mysql -u root voting_app -e "DELETE FROM vote_drafts WHERE election_id IN (SELECT id FROM elections WHERE name LIKE 'PRUNE FIXTURE%'); DELETE FROM elections WHERE name LIKE 'PRUNE FIXTURE%'; DELETE FROM activity_logs WHERE admin_username='fixture'; DELETE FROM login_attempts WHERE identifier IN ('old_prune:1','hot_prune:1');" 2>&1 | Out-Null
Remove-Item $mjar -Force -ErrorAction SilentlyContinue

# ----------------------------------------------------------------
# Section: Batch B1 - admin account management (admins API + card)
# ----------------------------------------------------------------
# Fixture: a throwaway admin account (suitexadmin) created and removed
# through the API itself; the real admin/admin123 account is NEVER
# mutated (the wrong-current self-change is asserted as a refusal only,
# so a green run can never brick the fixture credentials).
& $mysql -u root voting_app -e "DELETE FROM login_attempts; DELETE FROM admins WHERE username='suitexadmin';" 2>&1 | Out-Null

$bjar = [System.IO.Path]::GetTempFileName()
$bLoginHtml = ((curl.exe -s -c $bjar "$base/admin/login.php") -join "`n")
$bToken = [regex]::Match($bLoginHtml, 'name="csrf_token"\s+value="([^"]+)"').Groups[1].Value
$bCode = curl.exe -s -b $bjar -c $bjar -o NUL -w "%{http_code}" -X POST "$base/admin/login.php" -d "csrf_token=$bToken&username=admin&password=admin123"
Assert-True "B1 admin session login (302)" ($bCode -eq "302") "got $bCode"
$bDash = ((curl.exe -s -b $bjar "$base/admin/dashboard.php") -join "`n")
$bCsrf = [regex]::Match($bDash, 'const CSRF_TOKEN = "([^"]+)"').Groups[1].Value

# GET list
$listJson = ((curl.exe -s -b $bjar "$base/admin/api/admins.php") -join "")
Assert-True "admins list returns the real admin" ($listJson -match '"username":"admin"') "missing: $listJson"

$bBody = [System.IO.Path]::GetTempFileName()
$mkBody = { param($csrf, $json) [System.IO.File]::WriteAllText($bBody, $json.Replace('@@CSRF@@', $csrf)) }

# create
& $mkBody $bCsrf '{ "csrf_token": "@@CSRF@@", "username": "suitexadmin", "email": "suitex@acc.test", "password": "SuitePass1" }'
$mk1 = ((curl.exe -s -b $bjar -X POST "$base/admin/api/admins.php" --data-binary "@$bBody") -join "")
Assert-True "create admin succeeds" ($mk1 -match '"success":true') "got: $mk1"

# duplicate username refused
& $mkBody $bCsrf '{ "csrf_token": "@@CSRF@@", "username": "suitexadmin", "password": "SuitePass1" }'
$mk2 = ((curl.exe -s -b $bjar -X POST "$base/admin/api/admins.php" --data-binary "@$bBody") -join "")
Assert-True "duplicate username refused (409)" ($mk2 -match 'already exists') "got: $mk2"

# password policy
& $mkBody $bCsrf '{ "csrf_token": "@@CSRF@@", "username": "suitexadmin2", "password": "short" }'
$mk3 = ((curl.exe -s -b $bjar -X POST "$base/admin/api/admins.php" --data-binary "@$bBody") -join "")
Assert-True "short password refused by policy" ($mk3 -match 'at least 8') "got: $mk3"
& $mkBody $bCsrf '{ "csrf_token": "@@CSRF@@", "username": "suitexadmin2", "password": "onlyletters" }'
$mk4 = ((curl.exe -s -b $bjar -X POST "$base/admin/api/admins.php" --data-binary "@$bBody") -join "")
Assert-True "letters-only password refused by policy" ($mk4 -match 'letters and numbers') "got: $mk4"

# CSRF guard
& $mkBody 'bogus-token' '{ "csrf_token": "@@CSRF@@", "username": "suitexadmin3", "password": "SuitePass1" }'
$mk5 = ((curl.exe -s -b $bjar -X POST "$base/admin/api/admins.php" --data-binary "@$bBody") -join "")
Assert-True "create refuses bogus csrf" ($mk5 -match 'session expired') "got: $mk5"

# the new admin can log in
$sjar = [System.IO.Path]::GetTempFileName()
$sLoginHtml = ((curl.exe -s -c $sjar "$base/admin/login.php") -join "`n")
$sToken = [regex]::Match($sLoginHtml, 'name="csrf_token"\s+value="([^"]+)"').Groups[1].Value
$sCode = curl.exe -s -b $sjar -c $sjar -o NUL -w "%{http_code}" -X POST "$base/admin/login.php" -d "csrf_token=$sToken&username=suitexadmin&password=SuitePass1"
Assert-True "new admin logs in (302)" ($sCode -eq "302") "got $sCode"
$sDash = ((curl.exe -s -b $sjar "$base/admin/dashboard.php") -join "`n")
$sCsrf = [regex]::Match($sDash, 'const CSRF_TOKEN = "([^"]+)"').Groups[1].Value
$sId = [regex]::Match($listJson + ((curl.exe -s -b $bjar "$base/admin/api/admins.php") -join ""), '"id":"?(\d+)"?,"username":"suitexadmin"').Groups[1].Value
Assert-True "new admin appears in list with an id" ($sId -ne "") "not found in: $listJson"

# admin resets the new admin's password (no current password needed for OTHERS)
& $mkBody $bCsrf ('{ "csrf_token": "@@CSRF@@", "id": ' + $sId + ', "password": "SuitePass2" }')
$pw1 = ((curl.exe -s -b $bjar -X PUT "$base/admin/api/admins.php" --data-binary "@$bBody") -join "")
Assert-True "admin resets another admin's password" ($pw1 -match '"success":true') "got: $pw1"

# old password no longer works, new one does. Each attempt uses a FRESH
# jar + token scraped from a fresh GET: a successful login unsets the
# pre-auth CSRF token (admin/login.php), and an already-authenticated
# jar would just 302 to the dashboard - masking the real check.
function New-AdminLoginProbe([string]$user, [string]$pass) {
    $j = [System.IO.Path]::GetTempFileName()
    $h = ((curl.exe -s -c $j "$base/admin/login.php") -join "`n")
    $t = [regex]::Match($h, 'name="csrf_token"\s+value="([^"]+)"').Groups[1].Value
    $code = curl.exe -s -b $j -c $j -o NUL -w "%{http_code}" -X POST "$base/admin/login.php" -d "csrf_token=$t&username=$user&password=$pass"
    Remove-Item $j -Force -ErrorAction SilentlyContinue
    return $code
}
$oCode = New-AdminLoginProbe 'suitexadmin' 'SuitePass1'
Assert-True "old password rejected after reset (not 302)" ($oCode -ne "302") "got $oCode"
$nCode = New-AdminLoginProbe 'suitexadmin' 'SuitePass2'
Assert-True "new password accepted after reset (302)" ($nCode -eq "302") "got $nCode"

# self-change requires the CORRECT current password - refused with wrong one
& $mkBody $sCsrf ('{ "csrf_token": "@@CSRF@@", "id": ' + $sId + ', "password": "SuitePass9", "current_password": "wrongpass" }')
$selfBad = ((curl.exe -s -b $sjar -X PUT "$base/admin/api/admins.php" --data-binary "@$bBody") -join "")
Assert-True "self-change with wrong current password refused" ($selfBad -match 'Current password is incorrect') "got: $selfBad"

# self-change with the correct current password succeeds (safe: suitexadmin only)
& $mkBody $sCsrf ('{ "csrf_token": "@@CSRF@@", "id": ' + $sId + ', "password": "SuitePass3", "current_password": "SuitePass2" }')
$selfOk = ((curl.exe -s -b $sjar -X PUT "$base/admin/api/admins.php" --data-binary "@$bBody") -join "")
Assert-True "self-change with correct current password succeeds" ($selfOk -match '"success":true') "got: $selfOk"
$cCode = New-AdminLoginProbe 'suitexadmin' 'SuitePass3'
Assert-True "self-changed password works (302)" ($cCode -eq "302") "got $cCode"

# deleting your OWN account is refused
$adminId = [regex]::Match(((curl.exe -s -b $bjar "$base/admin/api/admins.php") -join ""), '"id":"?(\d+)"?,"username":"admin"').Groups[1].Value
Assert-True "real admin id extracted from list" ($adminId -ne "") "regex missed (PDO stringifies ids as JSON strings)"
& $mkBody $bCsrf ('{ "csrf_token": "@@CSRF@@", "id": ' + $adminId + ' }')
$selfDel = ((curl.exe -s -b $bjar -X DELETE "$base/admin/api/admins.php" --data-binary "@$bBody") -join "")
Assert-True "cannot delete your own account" ($selfDel -match 'your own account') "got: $selfDel"

# deleting another admin works, and their login stops working
& $mkBody $bCsrf ('{ "csrf_token": "@@CSRF@@", "id": ' + $sId + ' }')
$del1 = ((curl.exe -s -b $bjar -X DELETE "$base/admin/api/admins.php" --data-binary "@$bBody") -join "")
Assert-True "delete admin succeeds" ($del1 -match '"success":true') "got: $del1"
$gCode = New-AdminLoginProbe 'suitexadmin' 'SuitePass3'
Assert-True "deleted admin cannot log in (not 302)" ($gCode -ne "302") "got $gCode"
$cntLeft = (& $mysql -u root voting_app -e "SELECT COUNT(*) FROM admins;" | Out-String)
Assert-True "at least the real admin remains" ($cntLeft -match '[1-9]') "admins table empty!"

# students are locked out of the admins API
$stuCode = curl.exe -s -b $jar -o NUL -w "%{http_code}" "$base/admin/api/admins.php"
Assert-True "students get 401 from admins API" ($stuCode -eq "401") "got $stuCode"

# audit lines
$audit = (& $mysql -u root voting_app -e "SELECT COUNT(*) FROM activity_logs WHERE action LIKE 'Created admin account%' OR action LIKE 'Removed admin account%' OR action LIKE 'Changed%password%' OR action LIKE 'Reset password%';" | Out-String)
Assert-True "admin account actions are audited" ($audit -match '[1-9]') "no audit rows"

# UI contract: Settings carries the Admin accounts card
Assert-True "settings renders the Admin accounts card" ($adminSrc -match 'Admin accounts') "card missing"
Assert-True "create admin handler exists" ($adminSrc -match 'function createAdmin') "handler missing"
Assert-True "delete admin handler exists" ($adminSrc -match 'function deleteAdmin') "handler missing"
Assert-True "password change handler exists" ($adminSrc -match 'function changeAdminPassword') "handler missing"

# UI contract: the add-admin row's email/password inputs must be covered by
# the shared .settings-add-row rules — a type=text-only selector leaves them
# at the browser default (white boxes that break the dark theme).
Assert-True "settings add-row styles type=email inputs" ($adminSrc -match '\.settings-add-row input\[type=email\]') "email input falls back to browser default"
Assert-True "settings add-row styles type=password inputs" ($adminSrc -match '\.settings-add-row input\[type=password\]') "password input falls back to browser default"
Assert-True "add-admin inputs use shared row sizing" ($adminSrc -notmatch 'id="newAdmin(User|Email|Pass)"[^>]*style=') "inline width caps bypass the shared flex sizing"

# cleanup
& $mysql -u root voting_app -e "DELETE FROM login_attempts; DELETE FROM activity_logs WHERE action LIKE '%suitexadmin%' OR action LIKE 'Created admin account%' OR action LIKE 'Removed admin account%' OR action LIKE 'Changed%password%' OR action LIKE 'Reset password for admin%';" 2>&1 | Out-Null
Remove-Item $bBody, $sjar -Force -ErrorAction SilentlyContinue

# ----------------------------------------------------------------
# Section: Batch B2 - student password recovery (temp password flow)
# ----------------------------------------------------------------
# Source pins: BOTH schema files must carry the flag column so fresh
# installs on either engine get it (schema_parity keeps them equal),
# and the gate page must exist.
Assert-True "mysql schema has password_reset_pending" ((Get-Content .\schema.mysql.sql -Raw) -match 'password_reset_pending') "missing"
Assert-True "pg schema has password_reset_pending" ((Get-Content .\schema.sql -Raw) -match 'password_reset_pending') "missing"
$ccSrc = Get-Content .\change-password.php -Raw -ErrorAction SilentlyContinue
Assert-True "change-password.php gate page exists" ($ccSrc -and $ccSrc -match 'pw-reset-gate') "missing"

# Save the fixture student's exact credentials for a byte-exact restore
# AFTER the flow - this account (987654321/password123) is the human's
# login, so a green run must never leave it changed.
# NOTE: the suite runs with $ErrorActionPreference='Stop', so a mysql
# stderr line through 2>&1 would be a TERMINATING error record. The
# helper drops to Continue around the call - RED runs (missing column)
# must fail assertions, not kill the script.
function Invoke-MysqlOut([string]$sql) {
    $old = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { return (& $mysql -N -B -u root voting_app -e $sql 2>&1 | Out-String).Trim() }
    finally { $ErrorActionPreference = $old }
}
$fixTsv = Invoke-MysqlOut "SELECT id, password, IFNULL(password_reset_pending,0) FROM users WHERE student_id='987654321';"
$fixParts = $fixTsv -split "`t"
$fixId = $fixParts[0]; $fixHash = $fixParts[1]; $fixFlag = $fixParts[2]
Assert-True "fixture student + hash saved" (($fixId -ne '') -and ($fixHash -ne '') -and ($fixId -match '^\d+$')) "got: $fixTsv"

# Admin session for the reset call.
$ajar2 = [System.IO.Path]::GetTempFileName()
$a2h = ((curl.exe -s -c $ajar2 "$base/admin/login.php") -join "`n")
$a2t = [regex]::Match($a2h, 'name="csrf_token"\s+value="([^"]+)"').Groups[1].Value
$a2code = curl.exe -s -b $ajar2 -c $ajar2 -o NUL -w "%{http_code}" -X POST "$base/admin/login.php" -d "csrf_token=$a2t&username=admin&password=admin123"
Assert-True "B2 admin session login (302)" ($a2code -eq "302") "got $a2code"
$a2dash = ((curl.exe -s -b $ajar2 "$base/admin/dashboard.php") -join "`n")
$a2csrf = [regex]::Match($a2dash, 'const CSRF_TOKEN = "([^"]+)"').Groups[1].Value

# Admin issues a temporary password through students.php.
$rBody = [System.IO.Path]::GetTempFileName()
[System.IO.File]::WriteAllText($rBody, '{ "csrf_token": "' + $a2csrf + '", "id": ' + $fixId + ', "resetPassword": true }')
$r1 = ((curl.exe -s -b $ajar2 -X PUT "$base/admin/api/students.php" --data-binary "@$rBody") -join "")
Assert-True "admin temp-password reset succeeds" ($r1 -match '"success":true') "got: $r1"
$tempPw = [regex]::Match($r1, '"temp_password":"([^"]+)"').Groups[1].Value
Assert-True "temp password meets policy (8+, letters+numbers)" (($tempPw.Length -ge 8) -and ($tempPw -match '[A-Za-z]') -and ($tempPw -match '\d')) "got: $tempPw"

# Fresh-jar student login probe (same reasoning as the admin probes:
# a success rotates the session and drops the pre-auth token).
function New-StudentLoginProbe([string]$sid, [string]$pw) {
    $j = [System.IO.Path]::GetTempFileName()
    $h = ((curl.exe -s -c $j "$base/login.php") -join "`n")
    $t = [regex]::Match($h, 'name="csrf_token"\s+value="([^"]+)"').Groups[1].Value
    $code = curl.exe -s -b $j -c $j -o NUL -w "%{http_code}" -X POST "$base/login.php" -d "csrf_token=$t&student_id=$sid&password=$pw"
    Remove-Item $j -Force -ErrorAction SilentlyContinue
    return $code
}

# Sticky session logged in WITH the temp password - used for the gate.
$pjar = [System.IO.Path]::GetTempFileName()
$ph = ((curl.exe -s -c $pjar "$base/login.php") -join "`n")
$pt = [regex]::Match($ph, 'name="csrf_token"\s+value="([^"]+)"').Groups[1].Value
$pcode = curl.exe -s -b $pjar -c $pjar -o NUL -w "%{http_code}" -X POST "$base/login.php" -d "csrf_token=$pt&student_id=987654321&password=$tempPw"
Assert-True "student session with temp password (302)" ($pcode -eq "302") "got $pcode"

# Dashboard must bounce the pending student to the gate.
$gHdr = [System.IO.Path]::GetTempFileName()
$gCode = curl.exe -s -b $pjar -c $pjar -o NUL -D $gHdr -w "%{http_code}" "$base/dashboard.php"
$gLoc = ((Get-Content $gHdr) | Select-String '^Location:').ToString()
Remove-Item $gHdr -Force -ErrorAction SilentlyContinue
Assert-True "dashboard redirects pending student to the gate" (($gCode -eq "302") -and ($gLoc -match 'change-password\.php')) "got $gCode / $gLoc"

$gateHtml = ((curl.exe -s -b $pjar "$base/change-password.php") -join "`n")
Assert-True "gate page renders (pw-reset-gate)" ($gateHtml -match 'pw-reset-gate') "missing"
$gTok = [regex]::Match($gateHtml, 'name="csrf_token"\s+value="([^"]+)"').Groups[1].Value
Assert-True "gate form carries a csrf token" ($gTok -ne "") "not found"

# Weak password refused, flag untouched.
$wCode = curl.exe -s -b $pjar -c $pjar -o NUL -w "%{http_code}" -X POST "$base/change-password.php" -d "csrf_token=$gTok&password=short&confirm=short"
Assert-True "gate refuses weak password (200)" ($wCode -eq "200") "got $wCode"
$flagA = Invoke-MysqlOut "SELECT password_reset_pending FROM users WHERE student_id='987654321';"
Assert-True "flag still set after weak attempt" ($flagA -eq "1") "got '$flagA'"

# Bogus CSRF refused.
$bCode = curl.exe -s -b $pjar -o NUL -w "%{http_code}" -X POST "$base/change-password.php" -d "csrf_token=bogus&password=StudentNew1&confirm=StudentNew1"
Assert-True "gate refuses bad csrf (200)" ($bCode -eq "200") "got $bCode"

# Valid change succeeds and clears the flag.
$chHdr = [System.IO.Path]::GetTempFileName()
$cCode = curl.exe -s -b $pjar -c $pjar -o NUL -D $chHdr -w "%{http_code}" -X POST "$base/change-password.php" -d "csrf_token=$gTok&password=StudentNew1&confirm=StudentNew1"
Remove-Item $chHdr -Force -ErrorAction SilentlyContinue
Assert-True "password change succeeds (302)" ($cCode -eq "302") "got $cCode"
$flagB = Invoke-MysqlOut "SELECT password_reset_pending FROM users WHERE student_id='987654321';"
Assert-True "flag cleared after change" ($flagB -eq "0") "got '$flagB'"

# Gate no longer intercepts this session.
$ccNow = curl.exe -s -b $pjar -o NUL -w "%{http_code}" "$base/change-password.php"
Assert-True "change-password redirects when not pending (302)" ($ccNow -eq "302") "got $ccNow"
$dashNow = curl.exe -s -b $pjar -o NUL -w "%{http_code}" "$base/dashboard.php"
Assert-True "dashboard serves normally after change (200)" ($dashNow -eq "200") "got $dashNow"

# Old temp is dead; the new password works.
$oldCode = New-StudentLoginProbe '987654321' $tempPw
Assert-True "temp password dead after change (not 302)" ($oldCode -ne "302") "got $oldCode"
$newCode = New-StudentLoginProbe '987654321' 'StudentNew1'
Assert-True "new password works (302)" ($newCode -eq "302") "got $newCode"

# Enforcement + audit + UI source pins.
$voteSrc = Get-Content .\vote.php -Raw
Assert-True "vote.php blocks pending-password students" ($voteSrc -match 'password_reset_pending') "missing"
$audB2 = Invoke-MysqlOut "SELECT COUNT(*) FROM activity_logs WHERE action LIKE 'Reset password for student%';"
Assert-True "temp reset is audited" ($audB2 -match '^[1-9][0-9]*$') "got '$audB2'"
Assert-True "student form has Reset password handler" ($adminSrc -match 'function resetStudentPassword') "handler missing"
Assert-True "reset action sends the resetPassword flag" ($adminSrc -match 'resetPassword: true') "payload missing"

# RESTORE the fixture student byte-for-byte (saved hash + flag), then
# prove the human's original password works again.
$sqlFile = [System.IO.Path]::GetTempFileName()
[System.IO.File]::WriteAllText($sqlFile, "UPDATE users SET password='" + $fixHash + "', password_reset_pending=" + $fixFlag + " WHERE id=" + $fixId + ";")
$oldEap = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
try { (Get-Content $sqlFile -Raw) | & $mysql -u root voting_app 2>&1 | Out-Null }
finally { $ErrorActionPreference = $oldEap }
Remove-Item $sqlFile -Force -ErrorAction SilentlyContinue
$restoreCode = New-StudentLoginProbe '987654321' 'password123'
Assert-True "fixture credentials restored (302)" ($restoreCode -eq "302") "got $restoreCode"

& $mysql -u root voting_app -e "DELETE FROM activity_logs WHERE action LIKE 'Reset password for student%'; DELETE FROM login_attempts;" 2>&1 | Out-Null
Remove-Item $rBody, $pjar, $ajar2 -Force -ErrorAction SilentlyContinue

# ----------------------------------------------------------------
# Section: Batch C1 - duplicate election (draft copy)
# ----------------------------------------------------------------
# Admin session (fresh jar - a login success rotates the session and
# drops the pre-auth token, same as every other section here).
$cjar = [System.IO.Path]::GetTempFileName()
$ch = ((curl.exe -s -c $cjar "$base/admin/login.php") -join "`n")
$ct = [regex]::Match($ch, 'name="csrf_token"\s+value="([^"]+)"').Groups[1].Value
$cc = curl.exe -s -b $cjar -c $cjar -o NUL -w "%{http_code}" -X POST "$base/admin/login.php" -d "csrf_token=$ct&username=admin&password=admin123"
Assert-True "C1 admin session login (302)" ($cc -eq "302") "got $cc"
$cdash = ((curl.exe -s -b $cjar "$base/admin/dashboard.php") -join "`n")
$ccsrf = [regex]::Match($cdash, 'const CSRF_TOKEN = "([^"]+)"').Groups[1].Value

# Source fixture: a full election created through the API itself, so
# the duplicate has a real roster (position + candidate) to copy.
$dept = Invoke-MysqlOut "SELECT code FROM departments LIMIT 1;"
Assert-True "department code fixture available" ($dept -ne '' -and $dept -notmatch 'ERROR') "got '$dept'"
$srcBody = [System.IO.Path]::GetTempFileName()
[System.IO.File]::WriteAllText($srcBody, '{ "csrf_token": "' + $ccsrf + '", "type": "SSG", "name": "C1 SOURCE FIXTURE", "start": "2026-11-01 08:00", "end": "2026-11-02 17:00", "results_visibility": "after", "parties_enabled": false, "positions": [ { "title": "President", "winner_count": 1, "year_restriction": "", "candidates": [ { "name": "Alice Cruz", "department": "' + $dept + '", "candidate_year": "", "platform": "", "party": "", "photo": "" } ] } ] }')
$src1 = ((curl.exe -s -b $cjar -X POST "$base/admin/api/elections.php" --data-binary "@$srcBody") -join "")
Assert-True "C1 source election created" ($src1 -match '"success":true') "got: $src1"
$srcId = [regex]::Match($src1, '"id":(\d+)').Groups[1].Value
Assert-True "source id extracted" ($srcId -match '^[1-9]\d*$') "got: $src1"

# Duplicate it through the special action.
$dupBody = [System.IO.Path]::GetTempFileName()
[System.IO.File]::WriteAllText($dupBody, '{ "csrf_token": "' + $ccsrf + '", "duplicateOf": ' + $srcId + ' }')
$dup1 = ((curl.exe -s -b $cjar -X POST "$base/admin/api/elections.php" --data-binary "@$dupBody") -join "")
Assert-True "duplicate succeeds" ($dup1 -match '"success":true') "got: $dup1"
$dupId = [regex]::Match($dup1, '"id":(\d+)').Groups[1].Value
Assert-True "copy id differs from source" (($dupId -match '^[1-9]\d*$') -and ($dupId -ne $srcId)) "got: $dup1"

# The copy is a draft named "<source> (Copy)".
$copyRow = Invoke-MysqlOut "SELECT CONCAT(name, '|', status) FROM elections WHERE id=$dupId;"
Assert-True "copy is a draft named '(Copy)'" ($copyRow -eq 'C1 SOURCE FIXTURE (Copy)|draft') "got '$copyRow'"

# Roster copied as FRESH rows (candidates are never shared).
$copyPos = Invoke-MysqlOut "SELECT COUNT(*) FROM election_positions ep JOIN positions p ON p.id = ep.position_id WHERE ep.election_id=$dupId AND p.title='President' AND ep.winner_count=1;"
Assert-True "copy has the President position" ($copyPos -eq '1') "got '$copyPos'"
$copyCand = Invoke-MysqlOut "SELECT COUNT(*) FROM candidates c JOIN election_positions ep ON ep.position_id = c.position_id WHERE ep.election_id=$dupId AND c.name='Alice Cruz';"
Assert-True "copy has Alice Cruz as candidate" ($copyCand -eq '1') "got '$copyCand'"
$srcCandId = Invoke-MysqlOut "SELECT c.id FROM candidates c JOIN election_positions ep ON ep.position_id = c.position_id WHERE ep.election_id=$srcId LIMIT 1;"
$dupCandId = Invoke-MysqlOut "SELECT c.id FROM candidates c JOIN election_positions ep ON ep.position_id = c.position_id WHERE ep.election_id=$dupId LIMIT 1;"
Assert-True "candidates are fresh rows, not shared" (($srcCandId -match '^\d+$') -and ($dupCandId -match '^\d+$') -and ($srcCandId -ne $dupCandId)) "src=$srcCandId dup=$dupCandId"

# Source untouched.
$srcStill = Invoke-MysqlOut "SELECT CONCAT((SELECT COUNT(*) FROM elections WHERE id=$srcId), '|', (SELECT COUNT(*) FROM election_positions WHERE election_id=$srcId), '|', (SELECT name FROM elections WHERE id=$srcId));"
Assert-True "source election untouched" ($srcStill -eq '1|1|C1 SOURCE FIXTURE') "got '$srcStill'"

# Guards: unknown source, bogus csrf, student session.
$badIdBody = [System.IO.Path]::GetTempFileName()
[System.IO.File]::WriteAllText($badIdBody, '{ "csrf_token": "' + $ccsrf + '", "duplicateOf": 999999 }')
$badId = ((curl.exe -s -b $cjar -X POST "$base/admin/api/elections.php" --data-binary "@$badIdBody") -join "")
Assert-True "unknown source refused (404)" ($badId -match 'not found') "got: $badId"
$badCsrfBody = [System.IO.Path]::GetTempFileName()
[System.IO.File]::WriteAllText($badCsrfBody, '{ "csrf_token": "bogus", "duplicateOf": ' + $srcId + ' }')
$badCsrf = ((curl.exe -s -b $cjar -X POST "$base/admin/api/elections.php" --data-binary "@$badCsrfBody") -join "")
Assert-True "duplicate refuses bogus csrf" ($badCsrf -match 'session expired') "got: $badCsrf"
$stuDup = curl.exe -s -b $jar -o NUL -w "%{http_code}" -X POST "$base/admin/api/elections.php" --data-binary "@$dupBody"
Assert-True "students get 401 from duplicate" ($stuDup -eq "401") "got $stuDup"

# Audit + UI source pins.
$cAudit = Invoke-MysqlOut "SELECT COUNT(*) FROM activity_logs WHERE action LIKE 'Duplicated election%';"
Assert-True "duplicate is audited" ($cAudit -match '^[1-9][0-9]*$') "got '$cAudit'"
Assert-True "duplicate handler exists" ($adminSrc -match 'function duplicateElection') "handler missing"
Assert-True "election card shows Duplicate button" ($adminSrc -match 'onclick="duplicateElection') "button missing"

# cleanup: both elections removed through the API (cascade), then logs/jars
$delBody1 = [System.IO.Path]::GetTempFileName()
[System.IO.File]::WriteAllText($delBody1, '{ "csrf_token": "' + $ccsrf + '", "id": ' + $dupId + ', "confirm_delete": true }')
$del1 = ((curl.exe -s -b $cjar -X DELETE "$base/admin/api/elections.php" --data-binary "@$delBody1") -join "")
Assert-True "copy deleted in cleanup" ($del1 -match '"success":true') "got: $del1"
$delBody2 = [System.IO.Path]::GetTempFileName()
[System.IO.File]::WriteAllText($delBody2, '{ "csrf_token": "' + $ccsrf + '", "id": ' + $srcId + ', "confirm_delete": true }')
$del2 = ((curl.exe -s -b $cjar -X DELETE "$base/admin/api/elections.php" --data-binary "@$delBody2") -join "")
Assert-True "source deleted in cleanup" ($del2 -match '"success":true') "got: $del2"
& $mysql -u root voting_app -e "DELETE FROM activity_logs WHERE action LIKE 'Duplicated election%' OR action LIKE '%C1 SOURCE FIXTURE%'; DELETE FROM login_attempts;" 2>&1 | Out-Null
Remove-Item $cjar, $srcBody, $dupBody, $badIdBody, $badCsrfBody, $delBody1, $delBody2 -Force -ErrorAction SilentlyContinue

# ----------------------------------------------------------------
# Section: Batch C2 - ballot preview (admin session, submit off)
# ----------------------------------------------------------------
# Fixture: create + promote an election. The ballot branch only builds
# data for non-archived elections; preview mode additionally lets
# drafts through so admins can check a ballot before scheduling it.
$pvjar = [System.IO.Path]::GetTempFileName()
$pvh = ((curl.exe -s -c $pvjar "$base/admin/login.php") -join "`n")
$pvt = [regex]::Match($pvh, 'name="csrf_token"\s+value="([^"]+)"').Groups[1].Value
$pvc = curl.exe -s -b $pvjar -c $pvjar -o NUL -w "%{http_code}" -X POST "$base/admin/login.php" -d "csrf_token=$pvt&username=admin&password=admin123"
Assert-True "C2 admin session login (302)" ($pvc -eq "302") "got $pvc"
$pvdash = ((curl.exe -s -b $pvjar "$base/admin/dashboard.php") -join "`n")
$pvcsrf = [regex]::Match($pvdash, 'const CSRF_TOKEN = "([^"]+)"').Groups[1].Value

$dept2 = Invoke-MysqlOut "SELECT code FROM departments LIMIT 1;"
$pvCreate = [System.IO.Path]::GetTempFileName()
[System.IO.File]::WriteAllText($pvCreate, '{ "csrf_token": "' + $pvcsrf + '", "type": "SSG", "name": "C2 PREVIEW FIXTURE", "start": "2026-11-01 08:00", "end": "2026-11-02 17:00", "results_visibility": "after", "parties_enabled": false, "positions": [ { "title": "President", "winner_count": 1, "year_restriction": "", "candidates": [ { "name": "Bob Santos", "department": "' + $dept2 + '", "candidate_year": "", "platform": "", "party": "", "photo": "" } ] } ] }')
$pv1 = ((curl.exe -s -b $pvjar -X POST "$base/admin/api/elections.php" --data-binary "@$pvCreate") -join "")
$prevId = [regex]::Match($pv1, '"id":(\d+)').Groups[1].Value
Assert-True "C2 fixture election created" (($pv1 -match '"success":true') -and ($prevId -match '^[1-9]\d*$')) "got: $pv1"

# 1a. Draft preview - the whole point of previewing BEFORE scheduling:
# the ballot must render for a draft even though it isn't open yet
# (preview lets drafts through and skips the not-open gate).
$pvDraft = ((curl.exe -s -b $pvjar "$base/dashboard.php?page=vote&election_id=$prevId&preview=1") -join "`n")
Assert-True "draft ballot previews before scheduling" (($pvDraft -match 'id="voteForm"') -and ($pvDraft -match 'preview-banner') -and ($pvDraft -notmatch 'not currently open')) "draft preview broken"
$pvPromote = [System.IO.Path]::GetTempFileName()
[System.IO.File]::WriteAllText($pvPromote, '{ "csrf_token": "' + $pvcsrf + '", "id": ' + $prevId + ', "start": "2026-10-01 08:00", "end": "2026-12-31 17:00" }')
$pv2 = ((curl.exe -s -b $pvjar -X PUT "$base/admin/api/elections.php" --data-binary "@$pvPromote") -join "")
Assert-True "C2 fixture scheduled (draft -> scheduled)" ($pv2 -match '"success":true') "got: $pv2"
# Draft cannot jump straight to ongoing - the wizard's own rule (save a
# schedule first) - so promote in two steps, with a window that spans now.
$pvPromote2 = [System.IO.Path]::GetTempFileName()
[System.IO.File]::WriteAllText($pvPromote2, '{ "csrf_token": "' + $pvcsrf + '", "id": ' + $prevId + ', "status": "ongoing" }')
$pv2b = ((curl.exe -s -b $pvjar -X PUT "$base/admin/api/elections.php" --data-binary "@$pvPromote2") -join "")
Assert-True "C2 fixture promoted to ongoing" ($pv2b -match '"success":true') "got: $pv2b"

# 1. Admin + preview=1 renders the REAL ballot, banner on, submit off.
$pvHtml = ((curl.exe -s -b $pvjar "$base/dashboard.php?page=vote&election_id=$prevId&preview=1") -join "`n")
Assert-True "preview renders for the admin session" ($pvHtml -match 'id="voteForm"') "ballot missing"
Assert-True "preview banner is shown" ($pvHtml -match 'preview-banner') "banner missing"
Assert-True "submit disabled server-side in preview" ($pvHtml -match 'id="submitBtn"[^>]*disabled') "submit not disabled"
Assert-True "confirm-cast disabled too in preview" ($pvHtml -match 'id="confirmSubmitBtn"[^>]*disabled') "confirm not disabled"
Assert-True "preview shows the fixture candidate" ($pvHtml -match 'Bob Santos') "roster missing"

# 2. Admin without the flag does NOT get the ballot.
$pvNo = curl.exe -s -b $pvjar -o NUL -w "%{http_code}" "$base/dashboard.php?page=vote&election_id=$prevId"
Assert-True "no preview flag = no ballot for admin" ($pvNo -ne "200") "got $pvNo"

# 3. Anonymous + preview=1 refused.
$pvAnon = curl.exe -s -o NUL -w "%{http_code}" "$base/dashboard.php?page=vote&election_id=$prevId&preview=1"
Assert-True "anonymous preview refused" ($pvAnon -ne "200") "got $pvAnon"

# 4. Student + preview=1 sees their NORMAL ballot (no banner).
$pvStu = ((curl.exe -s -b $jar "$base/dashboard.php?page=vote&election_id=$prevId&preview=1") -join "`n")
Assert-True "student ballot renders without the banner" (($pvStu -match 'id="voteForm"') -and ($pvStu -notmatch 'preview-banner')) "unexpected preview state"

# 5. The preview admin session cannot actually vote.
$pvVote = curl.exe -s -b $pvjar -o NUL -w "%{http_code}" -X POST "$base/vote.php" -d "election_id=$prevId"
Assert-True "preview session cannot submit votes (401)" ($pvVote -eq "401") "got $pvVote"

# 6. Source pins.
$dashSrcC2 = Get-Content .\dashboard.php -Raw
Assert-True "dashboard has preview mode" ($dashSrcC2 -match '\$isPreview') "missing"
Assert-True "preview forces hasVoted off" ($dashSrcC2 -match 'isPreview \? false : userHasVotedInElection') "missing"

# cleanup: fixture removed through the API, logs/jars cleared.
$pvDel = [System.IO.Path]::GetTempFileName()
[System.IO.File]::WriteAllText($pvDel, '{ "csrf_token": "' + $pvcsrf + '", "id": ' + $prevId + ', "confirm_delete": true }')
$pvd = ((curl.exe -s -b $pvjar -X DELETE "$base/admin/api/elections.php" --data-binary "@$pvDel") -join "")
Assert-True "C2 fixture deleted in cleanup" ($pvd -match '"success":true') "got: $pvd"
& $mysql -u root voting_app -e "DELETE FROM activity_logs WHERE action LIKE '%C2 PREVIEW FIXTURE%'; DELETE FROM login_attempts;" 2>&1 | Out-Null
Remove-Item $pvjar, $pvCreate, $pvPromote, $pvPromote2, $pvDel -Force -ErrorAction SilentlyContinue

# ----------------------------------------------------------------
# Section: Batch D1 - database backup tool + weekly workflow
# ----------------------------------------------------------------
# (Subprocess/missing-file paths are guarded so a RED run fails these
# assertions instead of terminating the suite on a native-command error.)
$d1Script = Test-Path .\tools\backup.ps1
Assert-True "D1 backup script exists" ($d1Script) "missing tools\backup.ps1"
Assert-True "D1 backup workflow exists" (Test-Path .\.github\workflows\db-backup.yml) "missing workflow"

# Refusal: no DATABASE_URL -> nonzero exit + a clear message, nothing dumped.
$envBackup = $env:DATABASE_URL
$env:DATABASE_URL = $null
# Child stderr lines become terminating records under the suite-wide
# $ErrorActionPreference=Stop - drop to Continue around the captures.
$oldEAP = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
$refuse = if ($d1Script) { (& powershell -NoProfile -File .\tools\backup.ps1 2>&1 | Out-String) } else { 'missing script' }
$refuseCode = if ($d1Script) { $LASTEXITCODE } else { -1 }
$env:DATABASE_URL = $envBackup
$ErrorActionPreference = $oldEAP
Assert-True "D1 backup refuses without DATABASE_URL" (($refuseCode -ne 0) -and ($refuse -match 'DATABASE_URL')) "code $refuseCode out: $refuse"

# Dry-run: prints the pg_dump invocation WITHOUT the password.
$ErrorActionPreference = 'Continue'
$show = if ($d1Script) { (& powershell -NoProfile -File .\tools\backup.ps1 -ConnectionString 'postgres://backupuser:SECRETPW123@ep-example.neon.tech/neondb?sslmode=require' -ShowCommand 2>&1 | Out-String) } else { 'missing script' }
$ErrorActionPreference = $oldEAP
Assert-True "D1 show-command prints a pg_dump invocation" ($show -match 'pg_dump') "got: $show"
Assert-True "D1 show-command redacts the password" ($show -notmatch 'SECRETPW123') "password leaked!"
Assert-True "D1 show-command keeps host and db visible" ($show -match 'ep-example\.neon\.tech') "got: $show"

# Workflow + gitignore source pins.
$wfSrc = if (Test-Path .\.github\workflows\db-backup.yml) { Get-Content .\.github\workflows\db-backup.yml -Raw } else { '' }
Assert-True "D1 workflow is scheduled" ($wfSrc -match 'schedule:') "missing"
Assert-True "D1 workflow guards on the DATABASE_URL secret" ($wfSrc -match 'secrets\.DATABASE_URL') "missing"
Assert-True "D1 workflow dumps with pg_dump" ($wfSrc -match 'pg_dump') "missing"
Assert-True "D1 backups dir is gitignored" ((Get-Content .gitignore -Raw) -match '/backups/') "missing"

# ----------------------------------------------------------------
# Section: Batch D2 - health endpoint + uptime workflow
# ----------------------------------------------------------------
$d2Health = Test-Path .\health.php
Assert-True "D2 health endpoint exists" ($d2Health) "missing health.php"

# Live: DB up -> 200, ok/db-up JSON, nothing internal, nothing cached.
$healthCode = curl.exe -s -o NUL -w "%{http_code}" "$base/health.php"
$healthBody = ((curl.exe -s "$base/health.php") -join "`n")
Assert-True "D2 health endpoint is 200 when DB is up" ($healthCode -eq "200") "got $healthCode"
Assert-True "D2 health body reports ok" ($healthBody -match '"ok":true') "got: $healthBody"
Assert-True "D2 health body reports db up" ($healthBody -match '"db":"up"') "got: $healthBody"
Assert-True "D2 health body leaks no internals" ($healthBody -notmatch 'password|3306|root@|localhost|PDOException|voting_app') "got: $healthBody"
$healthHdr = ((curl.exe -s -D - -o NUL "$base/health.php") -join "`n")
Assert-True "D2 health response is not cacheable" ($healthHdr -match 'no-store') "got headers: $healthHdr"

# Source pins: 503 failure path, public probe stays stateless.
$healthSrc = if ($d2Health) { Get-Content .\health.php -Raw } else { '' }
Assert-True "D2 health answers 503 on failure" ($healthSrc -match '503') "missing"
Assert-True "D2 health never starts a session" ($healthSrc -notmatch 'session_start|startSecureSession') "sessions in health check"

# Workflow source pins.
$wf2Src = if (Test-Path .\.github\workflows\uptime.yml) { Get-Content .\.github\workflows\uptime.yml -Raw } else { '' }
Assert-True "D2 uptime workflow exists" (Test-Path .\.github\workflows\uptime.yml) "missing"
Assert-True "D2 uptime workflow is scheduled" ($wf2Src -match 'schedule:') "missing"
Assert-True "D2 uptime workflow guards on SITE_URL" ($wf2Src -match 'vars\.SITE_URL') "missing"
Assert-True "D2 uptime workflow probes health.php" ($wf2Src -match 'health\.php') "missing"
Assert-True "D2 uptime workflow files an issue on failure" ($wf2Src -match 'issues\.create') "missing"

if ($failures -gt 0) { Write-Output "`n$failures assertion(s) failed"; exit 1 }
Write-Output "`nAll assertions passed"
exit 0
