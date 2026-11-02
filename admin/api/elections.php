<?php
/**
 * admin/api/elections.php
 *
 * Real DB-backed CRUD for the admin "Election Management" panel.
 *
 * Data model recap (see schema.sql + migration.sql):
 *   elections            — one row per election (name, type, dept, schedule, status,
 *                           results_visibility, parties_enabled, parties JSONB array)
 *   positions            — a NEW row is created for every position added to an election
 *                           (titles are not shared/reused across elections on purpose,
 *                           so one election's candidates never leak into another's)
 *   election_positions   — links a position to an election, plus winner_count /
 *                           candidate_limit / year_restriction ("limit to see")
 *   candidates           — belongs to exactly one position_id (and therefore, transitively,
 *                           to exactly one election)
 *
 * IMPORTANT: positions.id -> election_positions.position_id and
 * positions.id -> candidates.position_id must both be ON DELETE CASCADE
 * (see migration.sql). That is what lets us clean up an election's old
 * positions/candidates/votes in one DELETE FROM positions WHERE id IN (...)
 * whenever the admin edits or deletes an election, instead of leaving
 * orphaned rows behind (which is what the first draft of this file did).
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

startSecureSession();
requireAdminLogin();
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$pdo = getDbConnection();
syncElectionStatuses($pdo);

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

// Candidate photos are stored directly in the database as the same
// base64 data: URL the browser already sends — not written to local disk.
// Free-tier hosts like Render wipe local disk on every redeploy, which
// would silently blank out every candidate's photo; storing it in
// Postgres survives deploys/restarts with no extra service (S3,
// Cloudinary, etc.) to set up, and works identically in local dev and
// production. The tradeoff is DB size, which is why validatePayload()
// below caps how large a single photo can be.
//
// Only two shapes are accepted: a data:image/ URL (a real upload from the
// wizard) or a legacy assets/... path (candidates saved before photos
// moved into the database). Anything else is rejected rather than passed
// through — this field renders straight into an <img src="..."> in the
// admin wizard's own preview, so accepting arbitrary strings here would
// be a stored-XSS hole for anyone who could reach this endpoint directly
// (bypassing the file picker) rather than through the normal upload flow.
function saveCandidatePhoto(?string $base64): ?string
{
    if (!$base64) return null;
    if (strpos($base64, 'data:image/') === 0) {
        // Byte-level validation: the decoded payload must actually parse as
        // a raster image (see isValidImageDataUrl() in functions.php), not
        // merely carry the right string prefix. Invalid data is dropped
        // here as a final guard; validatePayload() already rejects it with
        // a proper error message before this is ever reached.
        return isValidImageDataUrl($base64) ? $base64 : null;
    }
    if (strpos($base64, 'assets/') === 0) return $base64; // legacy pre-migration path
    return null;
}

// Rough base64 -> decoded-byte-size estimate, good enough for a size cap.
function base64ApproxBytes(string $base64): int
{
    $comma = strpos($base64, ',');
    $encoded = $comma !== false ? substr($base64, $comma + 1) : $base64;
    return (int) (strlen($encoded) * 3 / 4);
}

function splitElectionType(array $input): array
{
    $type = $input['type'] ?? '';
    $department = $type === 'DSG' ? trim($input['department'] ?? '') : null;
    if ($department === '') $department = null;
    return [$type, $department];
}

function validDepartmentCodes(PDO $pdo): array
{
    return $pdo->query('SELECT code FROM departments')->fetchAll(PDO::FETCH_COLUMN);
}

// Elections are run FOR the upcoming school year (see
// nextSchoolYearStartYear() in includes/functions.php). The target is NOT
// admin-editable: a new election always targets the upcoming school year,
// and an existing election keeps the year it was created with — nothing
// ever re-points it, because that year is who its officers serve. Any
// target_school_year_id the client sends is ignored.
function newElectionTargetSchoolYearId(PDO $pdo): int
{
    return (int) getOrCreateSchoolYear($pdo, nextSchoolYearStartYear())['id'];
}

// One SSG election per school year; one DSG election per faculty per school
// year — including archived elections. Shared by create, full edit, AND
// status activation: without the activation check a duplicated draft could
// bypass the detector by just being started (create/edit used to be the
// only paths that checked).
function assertNotDuplicateElection(PDO $pdo, string $type, ?string $department, int $targetSchoolYearId, int $excludeId): void
{
    if ($type === 'SSG') {
        $dupCheck = $pdo->prepare('SELECT id FROM elections WHERE type = :type AND target_school_year_id = :tsy AND id != :id');
        $dupCheck->execute(['type' => 'SSG', 'tsy' => $targetSchoolYearId, 'id' => $excludeId]);
        if ($dupCheck->fetch()) {
            respond(['error' => 'An SSG election already exists for this school year. Only one SSG election is allowed per school year.'], 400);
        }
    } elseif ($type === 'DSG') {
        $dupCheck = $pdo->prepare('SELECT id FROM elections WHERE type = :type AND department = :dept AND target_school_year_id = :tsy AND id != :id');
        $dupCheck->execute(['type' => 'DSG', 'dept' => $department, 'tsy' => $targetSchoolYearId, 'id' => $excludeId]);
        if ($dupCheck->fetch()) {
            respond(['error' => 'A DSG election already exists for this faculty in this school year. Only one DSG election per faculty per school year is allowed.'], 400);
        }
    }
}

function validatePayload(array $input, bool $requirePositions, array $validDepartmentCodes = []): array
{
    $errors = [];
    $type = $input['type'] ?? '';
    if (!in_array($type, ['SSG', 'DSG'], true)) $errors[] = 'Choose a valid election type.';
    if ($type === 'DSG' && empty(trim($input['department'] ?? ''))) $errors[] = 'Select a faculty.';
    if (empty(trim($input['name'] ?? ''))) {
        $errors[] = 'Enter an election name.';
    } elseif (mb_strlen(trim($input['name'])) > 100) {
        $errors[] = 'Election name is too long (max 100 characters).';
    }
    if (empty($input['start']) || empty($input['end'])) $errors[] = 'Set a start and end date/time.';
    if (!empty($input['start']) && !empty($input['end']) && strtotime($input['end']) <= strtotime($input['start'])) {
        $errors[] = 'End time must be after the start time.';
    }
    if (!empty($input['parties_enabled'])) {
        foreach (($input['parties'] ?? []) as $p) {
            if (trim((string)$p) === '') { $errors[] = 'Fill in all party names, or disable parties.'; break; }
            if (mb_strlen(trim((string)$p)) > 100) { $errors[] = 'Party name "' . mb_substr(trim((string)$p), 0, 30) . '…" is too long (max 100 characters).'; }
        }
    }

    if ($requirePositions) {
        $positions = $input['positions'] ?? [];
        if (!is_array($positions) || empty($positions)) {
            $errors[] = 'Add at least one position.';
        }
        foreach ($positions as $i => $p) {
            $rawTitle = trim($p['title'] ?? '');
            $label = $rawTitle !== '' ? mb_substr($rawTitle, 0, 50) : ('#' . ($i + 1));
            if ($rawTitle === '') {
                $errors[] = 'Position #' . ($i + 1) . ' needs a name.';
            } elseif (mb_strlen($rawTitle) > 50) {
                $errors[] = 'Position name "' . mb_substr($rawTitle, 0, 30) . '…" is too long (max 50 characters).';
            }
            $candidates = $p['candidates'] ?? [];
            $candCount = is_array($candidates) ? count($candidates) : 0;
            $winners = (int)($p['winner_count'] ?? 1);
            if ($candCount < 1) $errors[] = 'Position "' . $label . '" needs at least 1 candidate.';
            if ($winners < 1) $errors[] = 'Position "' . $label . '" needs at least 1 winner.';
            if ($winners > $candCount) $errors[] = 'Position "' . $label . '" can\'t have more winners than candidates.';
            foreach ($candidates as $j => $c) {
                $name = trim($c['name'] ?? '');
                if ($name === '') {
                    $errors[] = 'Candidate ' . ($j + 1) . ' in position "' . $label . '" needs a name.';
                } elseif (mb_strlen($name) > 100) {
                    // Matches the actual candidates.name column width —
                    // without this check, an over-length name reaches
                    // Postgres and fails as a raw, unhelpful 500 error
                    // instead of a clear validation message here.
                    $errors[] = 'Name for candidate ' . ($j + 1) . ' in position "' . $label . '" is too long (max 100 characters).';
                }
                $candDept = trim((string) ($c['department'] ?? ''));
                if ($candDept === '') {
                    $errors[] = 'Candidate ' . ($j + 1) . ' in position "' . $label . '" needs a faculty.';
                } elseif (mb_strlen($candDept) > 150) {
                    $errors[] = 'Faculty for candidate ' . ($j + 1) . ' in position "' . $label . '" is too long (max 150 characters).';
                } elseif (!in_array($candDept, $validDepartmentCodes, true)) {
                    $errors[] = 'Faculty for candidate ' . ($j + 1) . ' in position "' . $label . '" is not a recognized faculty.';
                }
                if (!empty($c['party']) && mb_strlen($c['party']) > 100) {
                    $errors[] = 'Party for candidate ' . ($j + 1) . ' in position "' . $label . '" is too long (max 100 characters).';
                }
                $photo = $c['photo'] ?? '';
                if ($photo && strpos($photo, 'data:image/') === 0) {
                    if (base64ApproxBytes($photo) > 2 * 1024 * 1024) {
                        $errors[] = 'Photo for candidate ' . ($j + 1) . ' in position "' . $label . '" is too large (max 2MB) — please use a smaller image.';
                    } elseif (!isValidImageDataUrl($photo)) {
                        $errors[] = 'Photo for candidate ' . ($j + 1) . ' in position "' . $label . '" is not a valid image file (use PNG, JPEG, GIF, or WebP).';
                    }
                }
            }
        }
    }

    return $errors;
}

// Delete a set of position rows. Thanks to ON DELETE CASCADE on
// election_positions.position_id and candidates.position_id (see
// migration.sql), this also removes their election_positions link rows,
// their candidates, and (via candidates -> votes cascade) any votes cast
// for those candidates.
function deletePositions(PDO $pdo, array $positionIds): void
{
    $positionIds = array_values(array_unique(array_map('intval', $positionIds)));
    if (!$positionIds) return;
    $placeholders = implode(',', array_fill(0, count($positionIds), '?'));
    $pdo->prepare("DELETE FROM positions WHERE id IN ($placeholders)")->execute($positionIds);
}

function electionVoteCount(PDO $pdo, int $electionId): int
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM votes v
        JOIN election_positions ep ON ep.position_id = v.position_id
        WHERE ep.election_id = :eid
    ');
    $stmt->execute(['eid' => $electionId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Distinct voters who have cast at least one ballot in this election, and
 * the number of students actually eligible to do so.
 *
 * "Ballots cast" counts DISTINCT voters, not vote rows: one voter fills a
 * whole ballot, so a multi-position election records several rows per
 * person. Counting rows would report turnout far above 100%.
 *
 * "Eligible" is the set who could genuinely vote right now:
 *   - registration_status = 'active'  (an 'unregistered' row has no
 *     password yet, so it can never reach a ballot)
 *   - account_status NOT IN ('dropped','graduated')  (login.php refuses
 *     these outright)
 * Scoped to the election's own faculty for a DSG, since a DSG ballot is
 * only open to that faculty. SSG is open to every active student.
 */
function electionTurnout(PDO $pdo, int $electionId, string $type, ?string $department): array
{
    $ballotsStmt = $pdo->prepare('
        SELECT COUNT(DISTINCT user_id) FROM ballot_submissions
        WHERE election_id = :eid
    ');
    $ballotsStmt->execute(['eid' => $electionId]);
    $ballotsCast = (int) $ballotsStmt->fetchColumn();

    if ($type === 'DSG' && $department !== null && $department !== '') {
        $eligStmt = $pdo->prepare("
            SELECT COUNT(*) FROM users
            WHERE registration_status = 'active'
              AND account_status NOT IN ('dropped','graduated')
              AND department = :dept
        ");
        $eligStmt->execute(['dept' => $department]);
    } else {
        $eligStmt = $pdo->prepare("
            SELECT COUNT(*) FROM users
            WHERE registration_status = 'active'
              AND account_status NOT IN ('dropped','graduated')
        ");
        $eligStmt->execute();
    }
    $eligible = (int) $eligStmt->fetchColumn();

    return [
        'ballots_cast'   => $ballotsCast,
        'eligible_voters' => $eligible,
        'turnout_pct'    => $eligible > 0 ? round($ballotsCast / $eligible * 100, 1) : 0.0,
    ];
}

function insertPositionsAndCandidates(PDO $pdo, int $electionId, array $positions, bool $partiesEnabled): void
{
    // Postgres returns the new id via RETURNING; MySQL/MariaDB exposes it
    // through lastInsertId(). Both are safe inside this loop because each
    // insert is its own statement on the same connection.
    if (isMysql()) {
        $posInsert = $pdo->prepare('INSERT INTO positions (title) VALUES (:title)');
    } else {
        $posInsert = $pdo->prepare('INSERT INTO positions (title) VALUES (:title) RETURNING id');
    }
    $epInsert = $pdo->prepare('
        INSERT INTO election_positions (election_id, position_id, winner_count, candidate_limit, year_restriction)
        VALUES (:eid, :pid, :winners, :limit_c, :year)
    ');
    $candInsert = $pdo->prepare('
        INSERT INTO candidates (position_id, name, photo, candidate_year, department, platform, party)
        VALUES (:pid, :name, :photo, :year, :department, :platform, :party)
    ');

    foreach ($positions as $posData) {
        $title = trim($posData['title']);
        $posInsert->execute(['title' => $title]);
        $posId = isMysql()
            ? (int) $pdo->lastInsertId()
            : (int) $posInsert->fetchColumn();

        $candidates = $posData['candidates'] ?? [];
        $yearRestriction = trim($posData['year_restriction'] ?? '');

        $epInsert->execute([
            'eid' => $electionId,
            'pid' => $posId,
            'winners' => (int) ($posData['winner_count'] ?? 1),
            'limit_c' => count($candidates) ?: null,
            'year' => $yearRestriction !== '' ? $yearRestriction : null,
        ]);

        foreach ($candidates as $cand) {
            $photoPath = saveCandidatePhoto($cand['photo'] ?? null);
            $candInsert->execute([
                'pid' => $posId,
                'name' => trim($cand['name']),
                'photo' => $photoPath,
                'year' => $cand['candidate_year'] ?? '',
                'department' => trim($cand['department'] ?? ''),
                'platform' => $cand['platform'] ?? '',
                'party' => $partiesEnabled ? ($cand['party'] ?: 'No Party / Independent') : 'No Party / Independent',
            ]);
        }
    }
}

// ------------------------------------------------------------------
// GET: list all elections, or fetch a single election with full detail
// ------------------------------------------------------------------
if ($method === 'GET') {
    $id = $_GET['id'] ?? null;

    if ($id) {
        $stmt = $pdo->prepare('
            SELECT e.*, sy.label AS target_school_year_label, sy.start_year AS target_school_year_start
            FROM elections e
            LEFT JOIN school_years sy ON sy.id = e.target_school_year_id
            WHERE e.id = :id
        ');
        $stmt->execute(['id' => (int) $id]);
        $election = $stmt->fetch();

        if (!$election) {
            respond(['error' => 'Election not found'], 404);
        }

        $posStmt = $pdo->prepare('
            SELECT ep.position_id, p.title, ep.winner_count, ep.candidate_limit, ep.year_restriction
            FROM election_positions ep
            JOIN positions p ON p.id = ep.position_id
            WHERE ep.election_id = :eid
            ORDER BY ep.id
        ');
        $posStmt->execute(['eid' => $election['id']]);
        $positions = $posStmt->fetchAll();

        $candStmt = $pdo->prepare('
            SELECT c.id, c.name, c.photo, c.candidate_year, c.department, c.platform, c.party,
                   COUNT(v.id) AS vote_count
            FROM candidates c
            LEFT JOIN votes v ON v.candidate_id = c.id
            WHERE c.position_id = :pid
            GROUP BY c.id, c.name, c.photo, c.candidate_year, c.department, c.platform, c.party
            ORDER BY c.name ASC
        ');
        foreach ($positions as &$pos) {
            $candStmt->execute(['pid' => $pos['position_id']]);
            $pos['winner_count'] = (int) $pos['winner_count'];
            $pos['candidate_limit'] = $pos['candidate_limit'] !== null ? (int) $pos['candidate_limit'] : null;
            $pos['candidates'] = array_map(function ($c) {
                $c['vote_count'] = (int) $c['vote_count'];
                return $c;
            }, $candStmt->fetchAll());
        }
        unset($pos);

        $election['positions'] = $positions;
        $election['parties'] = json_decode($election['parties'] ?? '[]', true) ?: [];
        $election['parties_enabled'] = (bool) $election['parties_enabled'];
        $election['vote_count'] = electionVoteCount($pdo, (int) $election['id']);

        // Turnout figures for the admin charts. The "eligible" definition
        // here is the SAME one results.php uses (active registration,
        // excluding dropped/graduated students), so admin and public
        // turnout percentages always agree.
        $election = array_merge(
            $election,
            electionTurnout($pdo, (int) $election['id'], (string) $election['type'], $election['department'])
        );

        respond($election);
    }

    $stmt = $pdo->query('
        SELECT e.id, e.name, e.type, e.department, e.status, e.start_date, e.end_date,
               e.results_visibility, e.parties_enabled, e.parties,
               e.target_school_year_id, sy.label AS target_school_year_label
        FROM elections e
        LEFT JOIN school_years sy ON sy.id = e.target_school_year_id
        ORDER BY e.start_date DESC
    ');
    $list = $stmt->fetchAll();
    foreach ($list as &$e) {
        $e['parties'] = json_decode($e['parties'] ?? '[]', true) ?: [];
        $e['parties_enabled'] = (bool) $e['parties_enabled'];
    }
    unset($e);
    respond($list);
}

// Every other method is a write — require CSRF from the JSON body.
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(['error' => 'Invalid request body.'], 400);
}
if (!verifyCsrfToken($input['csrf_token'] ?? null)) {
    respond(['error' => 'Your session expired. Please refresh and try again.'], 403);
}

// Per-admin rate limit on every write. The cap is deliberately generous —
// a busy admin wizard fires several requests in quick succession — but it
// stops a runaway client or compromised session from hammering the
// database with unbounded writes.
$adminWriteKey = 'admin_write:admin:' . (int) ($_SESSION['admin_id'] ?? 0);
if (isRateLimitedKey($pdo, $adminWriteKey, 120, 60)) {
    respond(['error' => 'Too many requests. Please slow down.'], 429);
}
recordAttemptKey($pdo, $adminWriteKey);

// ------------------------------------------------------------------
// POST: create a brand-new election (always a "full" payload)
// ------------------------------------------------------------------
if ($method === 'POST') {
    // ------------------------------------------------------------------
    // Special action: duplicate an existing election (Batch C1).
    // Copies the election row plus its whole position/candidate roster as
    // FRESH rows - candidates are never shared between elections (a
    // candidate's election is derived through position_id), and votes are
    // never carried over: the copy is a brand-new, empty ballot. It lands
    // as a draft named "<source> (Copy)" with the same schedule and
    // settings, ready to rename/reschedule before it goes live.
    // ------------------------------------------------------------------
    if (!empty($input['duplicateOf'])) {
        $sourceId = (int) $input['duplicateOf'];

        $srcStmt = $pdo->prepare('SELECT * FROM elections WHERE id = :id');
        $srcStmt->execute(['id' => $sourceId]);
        $src = $srcStmt->fetch();
        if (!$src) {
            respond(['error' => 'Source election not found.'], 404);
        }

        // Each election owns its own positions rows, so position_id alone
        // scopes the candidate query correctly (same note as in the GET
        // detail branch below).
        $posStmt = $pdo->prepare('
            SELECT ep.position_id, ep.winner_count, ep.year_restriction, p.title
            FROM election_positions ep
            JOIN positions p ON p.id = ep.position_id
            WHERE ep.election_id = :eid
            ORDER BY ep.id ASC
        ');
        $posStmt->execute(['eid' => $sourceId]);
        $srcPositions = $posStmt->fetchAll();

        $candStmt = $pdo->prepare('
            SELECT name, photo, candidate_year, department, platform, party
            FROM candidates WHERE position_id = :pid
            ORDER BY name ASC
        ');
        $positions = [];
        foreach ($srcPositions as $sp) {
            $candStmt->execute(['pid' => $sp['position_id']]);
            $positions[] = [
                'title' => $sp['title'],
                'winner_count' => (int) $sp['winner_count'],
                'year_restriction' => (string) ($sp['year_restriction'] ?? ''),
                // candidate_limit is recomputed by insertPositionsAndCandidates
                // from the candidate count - same rule the wizard applies.
                'candidates' => $candStmt->fetchAll(),
            ];
        }

        try {
            $pdo->beginTransaction();

            $copyName = mb_substr(trim($src['name']) . ' (Copy)', 0, 100);
            if (isMysql()) {
                $stmt = $pdo->prepare('
                    INSERT INTO elections (name, type, department, status, start_date, end_date,
                                           results_visibility, parties_enabled, parties, target_school_year_id)
                    VALUES (:name, :type, :department, :status, :start, :end, :visibility, :parties_enabled, :parties, :target_sy)
                ');
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO elections (name, type, department, status, start_date, end_date,
                                           results_visibility, parties_enabled, parties, target_school_year_id)
                    VALUES (:name, :type, :department, :status, :start, :end, :visibility, :parties_enabled, :parties::jsonb, :target_sy)
                    RETURNING id
                ');
            }
            $stmt->execute([
                'name' => $copyName,
                'type' => $src['type'],
                'department' => $src['department'],
                // Draft, exactly like a wizard-created election: the copy
                // never inherits a live/closed status, so nothing can open
                // or close on its own until the admin schedules it.
                'status' => 'draft',
                'start' => $src['start_date'],
                'end' => $src['end_date'],
                'visibility' => $src['results_visibility'],
                // 1/0, not a raw bool - PDO stringifies false to '' which
                // Postgres rejects as boolean input (same as the create
                // flow above).
                'parties_enabled' => !empty($src['parties_enabled']) ? 1 : 0,
                'parties' => (string) ($src['parties'] ?? '[]'),
                // Retarget to the automatic upcoming school year rather than
                // inheriting the source's: duplicating is how you template
                // last cycle's election for the new cycle, and the target is
                // never admin-editable anywhere else either.
                'target_sy' => newElectionTargetSchoolYearId($pdo),
            ]);
            $electionId = isMysql() ? (int) $pdo->lastInsertId() : (int) $stmt->fetchColumn();

            insertPositionsAndCandidates($pdo, $electionId, $positions, !empty($src['parties_enabled']));

            $pdo->prepare('INSERT INTO activity_logs (admin_username, action) VALUES (:u, :a)')
                ->execute([
                    'u' => 'admin:' . ($_SESSION['admin_username'] ?? 'unknown'),
                    'a' => sprintf('Duplicated election #%d as #%d "%s" (draft copy)', $sourceId, $electionId, $copyName),
                ]);

            $pdo->commit();
            respond(['success' => true, 'id' => $electionId]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Duplicate election failed: ' . $e->getMessage());
            respond(['error' => 'Could not duplicate the election.'], 500);
        }
    }

    $errors = validatePayload($input, true, validDepartmentCodes($pdo));
    if ($errors) respond(['error' => implode(' ', $errors)], 400);

    [$type, $department] = splitElectionType($input);

    // Automatic target: new elections always run for the upcoming school
    // year (the wizard no longer offers a choice — see
    // newElectionTargetSchoolYearId above). Duplicate detector runs against
    // that resolved year, including archived elections.
    $targetSchoolYearId = newElectionTargetSchoolYearId($pdo);
    assertNotDuplicateElection($pdo, $type, $department, $targetSchoolYearId, 0);

    try {

        $pdo->beginTransaction();

        $partiesJson = json_encode($input['parties'] ?? []);
        if (isMysql()) {
            $stmt = $pdo->prepare('
                INSERT INTO elections (name, type, department, status, start_date, end_date,
                                        results_visibility, parties_enabled, parties, target_school_year_id)
                VALUES (:name, :type, :department, :status, :start, :end, :visibility, :parties_enabled, :parties, :target_sy)
            ');
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO elections (name, type, department, status, start_date, end_date,
                                        results_visibility, parties_enabled, parties, target_school_year_id)
                VALUES (:name, :type, :department, :status, :start, :end, :visibility, :parties_enabled, :parties::jsonb, :target_sy)
                RETURNING id
            ');
        }
        $stmt->execute([
            'name' => trim($input['name']),
            'type' => $type,
            'department' => $department,
            // New elections start as "Not Started". They move to "Upcoming"
            // the moment the admin saves a schedule (see the PUT handler
            // below), and from there start/end automatically on their own
            // — see syncElectionStatuses() in includes/functions.php.
            'status' => 'draft',
            'start' => str_replace('T', ' ', $input['start']),
            'end' => str_replace('T', ' ', $input['end']),
            'visibility' => $input['results_visibility'] ?? 'after',
            // IMPORTANT: cast bool to 1/0, not a raw PHP bool. PDO stringifies
            // `false` to an empty string when bound as a plain parameter,
            // and Postgres rejects '' as invalid boolean input — 1/0 (which
            // PHP stringifies to '1'/'0') are valid boolean literals instead.
            'parties_enabled' => !empty($input['parties_enabled']) ? 1 : 0,
            'parties' => $partiesJson,
            'target_sy' => $targetSchoolYearId,
        ]);
        $electionId = isMysql() ? (int) $pdo->lastInsertId() : (int) $stmt->fetchColumn();

        insertPositionsAndCandidates($pdo, $electionId, $input['positions'], !empty($input['parties_enabled']));

        $pdo->commit();
        respond(['success' => true, 'id' => $electionId]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Create election failed: ' . $e->getMessage());
        respond(['error' => 'Could not create the election.'], 500);
    }
}

// ------------------------------------------------------------------
// PUT: update an election.
//   - If the payload includes "positions", it's a full save: name/type/
//     schedule/parties AND the whole position/candidate roster are
//     replaced. Blocked once the election has recorded votes.
//   - If it doesn't, it's a lightweight patch (used for the Start/Pause/
//     End buttons, the schedule box, and the results-visibility toggle) —
//     only the fields actually present in the payload are touched.
// ------------------------------------------------------------------
if ($method === 'PUT') {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) respond(['error' => 'Invalid election ID.'], 400);

    $existingStmt = $pdo->prepare('SELECT * FROM elections WHERE id = :id');
    $existingStmt->execute(['id' => $id]);
    $current = $existingStmt->fetch();
    if (!$current) respond(['error' => 'Election not found.'], 404);

    $hasPositions = array_key_exists('positions', $input);

    try {
        if (!$hasPositions) {
            // Lightweight patch: only touch fields that were actually sent.
            $fields = [];
            $values = ['id' => $id];

            if (array_key_exists('status', $input)) {
                if (!in_array($input['status'], ['draft', 'scheduled', 'ongoing', 'paused', 'closed', 'archived'], true)) {
                    respond(['error' => 'Invalid status.'], 400);
                }
                if ($input['status'] === 'archived' && $current['status'] !== 'closed') {
                    respond(['error' => 'Only ended elections can be archived.'], 409);
                }
                if ($input['status'] === 'ongoing' && $current['status'] === 'draft') {
                    respond(['error' => 'Save a schedule for this election before starting it.'], 409);
                }
                // Activation must respect the one-per-school-year rule too:
                // a duplicated draft could otherwise be started straight past
                // the duplicate detector (create/full-edit were the only
                // paths that checked). Pausing/closing/archiving stays free.
                if (in_array($input['status'], ['scheduled', 'ongoing'], true)) {
                    assertNotDuplicateElection($pdo, $current['type'], $current['department'], (int) $current['target_school_year_id'], $id);
                }
                $fields[] = 'status = :status';
                $values['status'] = $input['status'];
            }
            if (array_key_exists('start', $input) || array_key_exists('end', $input)) {
                $start = $input['start'] ?? str_replace(' ', 'T', substr($current['start_date'], 0, 16));
                $end = $input['end'] ?? str_replace(' ', 'T', substr($current['end_date'], 0, 16));
                if (strtotime($end) <= strtotime($start)) {
                    respond(['error' => 'End time must be after the start time.'], 400);
                }
                if ($current['status'] === 'ongoing') {
                    respond(['error' => 'Pause this election before changing its schedule.'], 409);
                }
                $fields[] = 'start_date = :start_date';
                $fields[] = 'end_date = :end_date';
                $values['start_date'] = str_replace('T', ' ', $start);
                $values['end_date'] = str_replace('T', ' ', $end);
                // Saving a schedule is what moves a brand-new election from
                // "Not Started" to "Upcoming" — and it's automatic from here
                // on out (see syncElectionStatuses()), unless it's already
                // further along in its lifecycle (ongoing/paused/closed/archived).
                // Same duplicate wall as the status buttons above: scheduling
                // is activation too.
                if ($current['status'] === 'draft') {
                    assertNotDuplicateElection($pdo, $current['type'], $current['department'], (int) $current['target_school_year_id'], $id);
                    $fields[] = "status = 'scheduled'";
                }
            }
            if (array_key_exists('results_visibility', $input)) {
                if (!in_array($input['results_visibility'], ['always', 'after', 'never'], true)) {
                    respond(['error' => 'Invalid results visibility.'], 400);
                }
                $fields[] = 'results_visibility = :vis';
                $values['vis'] = $input['results_visibility'];
            }
            // target_school_year_id is intentionally NOT editable here (or
            // anywhere): it's fixed at creation to the upcoming school year.

            if (!$fields) respond(['error' => 'Nothing to update.'], 400);

            $pdo->prepare('UPDATE elections SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($values);
            respond(['success' => true]);
        }

        // Full save (name/type/schedule/parties + positions/candidates).
        if ($current['status'] === 'ongoing') {
            respond(['error' => 'This election is ongoing. Pause it before editing positions or candidates.'], 409);
        }

        // "Save as Draft" must keep the election a draft — the client
        // sends is_draft_save and the server previously ignored it,
        // so saving an unfinished election silently scheduled it (and
        // the wizard's default start of "now" meant the next status
        // sync would open it for voting).
        $isDraftSave = !empty($input['is_draft_save']);

        // A draft save may legitimately be unfinished: drop blank
        // position rows instead of rejecting them (the client has
        // always documented this behaviour; the server never did it).
        if ($isDraftSave) {
            $input['positions'] = array_values(array_filter($input['positions'] ?? [], function ($p) {
                return trim($p['title'] ?? '') !== '';
            }));
        }

        $errors = validatePayload($input, !$isDraftSave, validDepartmentCodes($pdo));
        if ($errors) respond(['error' => implode(' ', $errors)], 400);

        [$type, $department] = splitElectionType($input);
        // Target school year is fixed at creation (automatic upcoming year)
        // — a full edit never re-points it, no matter what the client sends.
        $targetSchoolYearId = (int) $current['target_school_year_id'];
        assertNotDuplicateElection($pdo, $type, $department, $targetSchoolYearId, $id);

        $pdo->beginTransaction();

        // Lock the election row and re-check the vote count INSIDE the
        // transaction: a ballot cast between an unlocked check and the
        // position delete below would be cascade-deleted, and the
        // concurrent voter's INSERT would then fail on the FK — a lost
        // vote with no admin-visible trace.
        $lockStmt = $pdo->prepare('SELECT id FROM elections WHERE id = :id FOR UPDATE');
        $lockStmt->execute(['id' => $id]);
        if (electionVoteCount($pdo, $id) > 0) {
            $pdo->rollBack();
            respond(['error' => 'This election already has votes recorded, so its positions and candidates can\'t be edited anymore. You can still change its schedule, status, or results visibility.'], 409);
        }

        $statusClause = ($current['status'] === 'draft' && !$isDraftSave) ? ", status = 'scheduled'" : '';
        $partiesSql = isMysql() ? 'parties = :parties' : 'parties = :parties::jsonb';
        $pdo->prepare('
            UPDATE elections SET
                name = :name, type = :type, department = :department,
                start_date = :start, end_date = :end,
                results_visibility = :visibility, parties_enabled = :parties_enabled, ' . $partiesSql . ',
                target_school_year_id = :target_sy
                ' . $statusClause . '
            WHERE id = :id
        ')->execute([
            'id' => $id,
            'name' => trim($input['name']),
            'type' => $type,
            'department' => $department,
            'start' => str_replace('T', ' ', $input['start']),
            'end' => str_replace('T', ' ', $input['end']),
            'visibility' => $input['results_visibility'] ?? 'after',
            // Cast to 1/0 — see the note in the POST handler above about why
            // binding a raw PHP bool breaks for `false`.
            'parties_enabled' => !empty($input['parties_enabled']) ? 1 : 0,
            'parties' => json_encode($input['parties'] ?? []),
            'target_sy' => $targetSchoolYearId ?: null,
        ]);

        // Wipe the old positions (cascades election_positions + candidates + votes
        // for those candidates — see deletePositions() docblock above) and
        // recreate them fresh. Safe here because we already confirmed above
        // that this election has zero votes recorded.
        $oldPosStmt = $pdo->prepare('SELECT position_id FROM election_positions WHERE election_id = :id');
        $oldPosStmt->execute(['id' => $id]);
        deletePositions($pdo, $oldPosStmt->fetchAll(PDO::FETCH_COLUMN));

        insertPositionsAndCandidates($pdo, $id, $input['positions'], !empty($input['parties_enabled']));

        $pdo->commit();
        respond(['success' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Update election failed: ' . $e->getMessage());
        respond(['error' => 'Could not save changes.'], 500);
    }
}

// ------------------------------------------------------------------
// DELETE: remove an election and everything that belongs only to it
// ------------------------------------------------------------------
if ($method === 'DELETE') {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) respond(['error' => 'Invalid election ID.'], 400);

    // Deleting an election cascade-deletes every vote ever cast in
    // it. The browser confirm is not a guard — require an explicit
    // server-side acknowledgement, and record what is being
    // destroyed in the audit log inside the same transaction.
    if (empty($input['confirm_delete'])) {
        respond(['error' => 'Refusing to delete: missing explicit confirmation. This election and all its votes will be permanently removed.'], 409);
    }

    try {
        $pdo->beginTransaction();

        $lockStmt = $pdo->prepare('SELECT id FROM elections WHERE id = :id FOR UPDATE');
        $lockStmt->execute(['id' => $id]);
        if (!$lockStmt->fetch()) {
            $pdo->rollBack();
            respond(['error' => 'Election not found.'], 404);
        }

        $voteCount = electionVoteCount($pdo, $id);
        if ($voteCount > 0) {
            $pdo->prepare('INSERT INTO activity_logs (admin_username, action) VALUES (:u, :a)')
                ->execute([
                    'u' => 'admin:' . ($_SESSION['admin_username'] ?? 'unknown'),
                    'a' => sprintf('Deleted election #%d with %d recorded vote(s) — votes destroyed with the election', $id, $voteCount),
                ]);
        }

        $posStmt = $pdo->prepare('SELECT position_id FROM election_positions WHERE election_id = :id');
        $posStmt->execute(['id' => $id]);
        $positionIds = $posStmt->fetchAll(PDO::FETCH_COLUMN);

        $del = $pdo->prepare('DELETE FROM elections WHERE id = :id');
        $del->execute(['id' => $id]);
        if ($del->rowCount() === 0) {
            $pdo->rollBack();
            respond(['error' => 'Election not found.'], 404);
        }

        // Positions created for this election aren't shared with any other
        // election, so it's safe to remove them (and, via cascade, their
        // candidates and votes) once the election row itself is gone.
        deletePositions($pdo, $positionIds);

        $pdo->commit();
        respond(['success' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Delete election failed: ' . $e->getMessage());
        respond(['error' => 'Could not delete the election.'], 500);
    }
}

respond(['error' => 'Method not allowed.'], 405);