<?php
/**
 * dashboard-data.php
 *
 * JSON endpoint for the student dashboard's SPA panels (Home, Vote-picker,
 * Candidates, Results — see dashboard.php's "Branch B"). Returns the exact
 * same election data that used to be computed once per full page load in
 * dashboard.php, just as JSON instead of being used to render HTML
 * server-side.
 *
 * Deliberately NOT used by the actual ballot-casting page (dashboard.php's
 * "Branch A", ?page=vote&election_id=X) — that stays a full server-rendered
 * page and posts straight to vote.php/draft.php as it always has.
 *
 * Query shape: a fixed batch set (user, submissions, settings, departments,
 * elections, positions-IN, candidates-IN) — never
 * one query per election or per position. Images travel as URLs
 * (`photo_url` / `icon_url`, served cacheably by photo.php / icon.php),
 * never as base64, so the payload stays a few KB regardless of how many
 * candidates have photos.
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

startSecureSession();
requireLogin();

header('Content-Type: application/json');

$pdo = getDbConnection();
syncElectionStatuses($pdo);

$stmt = $pdo->prepare('SELECT id, name, department, year_level FROM users WHERE id = :id');
$stmt->execute(['id' => $_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in.']);
    exit;
}

// Submission locks — ONE query answers hasVoted for every election below.
// ballot_submissions, not votes: a submitted ballot may be empty
// (abstention), so "voted" must mean "a submission exists", which is
// exactly the lock vote.php enforces.
$subStmt = $pdo->prepare('SELECT election_id FROM ballot_submissions WHERE user_id = :uid');
$subStmt->execute(['uid' => $user['id']]);
$votedElectionIds = array_map('intval', $subStmt->fetchAll(PDO::FETCH_COLUMN));

// 'draft' elections aren't visible to students yet; 'archived' elections
// are past cycles the admin has explicitly tucked away (same as they
// disappear from the admin's own Elections list) — neither belongs on a
// student's dashboard or vote picker.
$rawElections = $pdo->query("
    SELECT e.*, sy.label AS target_school_year_label
    FROM elections e
    LEFT JOIN school_years sy ON sy.id = e.target_school_year_id
    WHERE e.status NOT IN ('draft', 'archived')
    ORDER BY e.start_date ASC
")->fetchAll();

// Department filter first, then batch every per-election query across the
// whole eligible set.
$eligible = [];
foreach ($rawElections as $e) {
    if ($e['type'] === 'DSG' && $e['department'] !== $user['department']) {
        continue; // not this student's department
    }
    $eligible[] = $e;
}

// Logo EXISTENCE only — the bytes are served by icon.php, so the base64
// never travels through this payload (nor is it loaded from the DB just to
// be thrown away).
$ssgLogoExists = (bool) (int) $pdo->query("
    SELECT COUNT(*) FROM site_settings
    WHERE setting_key = 'ssg_logo' AND setting_value IS NOT NULL AND setting_value <> ''
")->fetchColumn();
$deptsWithLogo = $pdo->query("
    SELECT code FROM departments WHERE logo IS NOT NULL AND logo <> ''
")->fetchAll(PDO::FETCH_COLUMN);
$deptsWithLogo = array_flip($deptsWithLogo);

// ONE positions query for every eligible election (was: one per election).
$positionsByElection = [];
if ($eligible) {
    $ePlaceholders = [];
    $eParams = [];
    foreach ($eligible as $i => $e) {
        $ePlaceholders[] = ':eid' . $i;
        $eParams[':eid' . $i] = (int) $e['id'];
    }
    $posStmt = $pdo->prepare('
        SELECT ep.election_id, ep.position_id, p.title, ep.winner_count,
               ep.candidate_limit, ep.year_restriction
        FROM election_positions ep
        JOIN positions p ON p.id = ep.position_id
        WHERE ep.election_id IN (' . implode(',', $ePlaceholders) . ')
        ORDER BY ep.election_id ASC, ep.id ASC
    ');
    $posStmt->execute($eParams);
    foreach ($posStmt->fetchAll() as $row) {
        $positionsByElection[(int) $row['election_id']][] = $row;
    }
}

// "Limit to see" — hide positions restricted to a year level this student
// isn't in, and remember which position ids survive for the candidates
// batch below.
$allowedPositionsByElection = [];
$allowedPositionIds = [];
foreach ($positionsByElection as $eid => $rows) {
    $kept = array_values(array_filter($rows, function ($p) use ($user) {
        return empty($p['year_restriction']) || $p['year_restriction'] === $user['year_level'];
    }));
    $allowedPositionsByElection[$eid] = $kept;
    foreach ($kept as $p) {
        $allowedPositionIds[] = (int) $p['position_id'];
    }
}

// ONE candidates query for all allowed positions (was: one per position).
// has_photo replaces the base64 photo column itself: only whether a photo
// EXISTS is needed to build photo.php?c=<id>.
$candidatesByPosition = [];
if ($allowedPositionIds) {
    $pPlaceholders = [];
    $pParams = [];
    foreach ($allowedPositionIds as $i => $pid) {
        $pPlaceholders[] = ':pid' . $i;
        $pParams[':pid' . $i] = $pid;
    }
    $candStmt = $pdo->prepare('
        SELECT c.position_id, c.id, c.name, c.party, c.department,
               c.candidate_year AS year_level, c.platform,
               MAX(CASE WHEN c.photo IS NOT NULL THEN 1 ELSE 0 END) AS has_photo,
               COUNT(v.id) AS vote_count
        FROM candidates c
        LEFT JOIN votes v ON v.candidate_id = c.id
        WHERE c.position_id IN (' . implode(',', $pPlaceholders) . ')
        GROUP BY c.id, c.position_id, c.name, c.party, c.department, c.candidate_year, c.platform
        ORDER BY c.name ASC
    ');
    $candStmt->execute($pParams);
    foreach ($candStmt->fetchAll() as $row) {
        $pid = (int) $row['position_id'];
        $candidatesByPosition[$pid][] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'photo_url' => (bool) $row['has_photo'] ? 'photo.php?c=' . (int) $row['id'] : null,
            'party' => $row['party'],
            'department' => $row['department'],
            'year_level' => $row['year_level'],
            'platform' => $row['platform'],
            'vote_count' => (int) $row['vote_count'],
        ];
    }
}

$elections = [];
foreach ($eligible as $e) {
    $electionId = (int) $e['id'];
    $positions = $allowedPositionsByElection[$electionId] ?? [];

    $positionsOut = [];
    foreach ($positions as $p) {
        $pid = (int) $p['position_id'];

        $positionsOut[] = [
            'position_id' => $pid,
            'title' => $p['title'],
            'winner_count' => (int) ($p['winner_count'] ?? 1),
            'year_restriction' => $p['year_restriction'] ?: null,
            'candidates' => $candidatesByPosition[$pid] ?? [],
        ];
    }

    $hasLogo = $e['type'] === 'SSG'
        ? $ssgLogoExists
        : isset($deptsWithLogo[(string) $e['department']]);

    $elections[] = [
        'id' => $electionId,
        'config' => [
            'id' => $electionId,
            'name' => $e['name'],
            'type' => $e['type'],
            'department' => $e['department'],
            'status' => $e['status'],
            'start_date' => $e['start_date'],
            'end_date' => $e['end_date'],
            'results_visibility' => $e['results_visibility'],
            'target_school_year_label' => $e['target_school_year_label'],
            'accent' => $e['type'] === 'SSG' ? '#3b82f6' : '#f97316',
            'icon_url' => $hasLogo ? 'icon.php?e=' . $electionId : null,
            'subtitle' => $e['type'] === 'SSG' ? 'Supreme Student Government' : 'Department Student Government' . ($e['department'] ? ' — ' . $e['department'] : ''),
        ],
        'positions' => $positionsOut,
        'totalPositions' => count($positionsOut),
        'hasVoted' => in_array($electionId, $votedElectionIds, true),
    ];
}

echo json_encode([
    'user' => [
        'name' => $user['name'],
        'department' => $user['department'],
        'year_level' => $user['year_level'],
    ],
    'elections' => $elections,
]);
