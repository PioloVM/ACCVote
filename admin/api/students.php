<?php
/**
 * admin/api/students.php
 *
 * Real DB-backed CRUD for the admin "Students" panel.
 * Talks JSON in both directions. Every write requires the admin session
 * (requireAdminLogin) AND a valid CSRF token in the JSON body.
 *
 * Frontend field names <-> DB columns:
 *   studentId  <-> student_id
 *   fullName   <-> name
 *   department <-> department
 *   yearLevel  <-> year_level (computed from schoolYear vs. the active
 *                 school year — see computeYearLevel() in functions.php;
 *                 not directly hand-edited here)
 *   schoolYear <-> school_year_id (FK to school_years — the year the
 *                 student started)
 *   email      <-> email
 *   status     <-> registration_status  ('unregistered' | 'active' | 'suspended')
 *   hasVoted   <-> has_voted            (0 / 1)
 *   password   -> hashed with password_hash() before it ever touches the DB.
 *                 We never send a password back out — editing leaves it
 *                 untouched unless a new one is supplied.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

startSecureSession();
requireAdminLogin();

header('Content-Type: application/json');

$pdo = getDbConnection();

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

// Field limits matching the actual users table column widths. Without
// this, an over-length value (student ID, name, etc.) reaches Postgres
// and fails as a raw "value too long for type character varying(N)"
// error instead of a clear message here.
function checkFieldLengths(array $fields): ?string
{
    $limits = [
        'Student ID' => [$fields['student_id'] ?? null, 20],
        'Full name' => [$fields['fullName'] ?? null, 100],
        'Faculty' => [$fields['department'] ?? null, 150],
        'Year level' => [$fields['yearLevel'] ?? null, 20],
        'Email' => [$fields['email'] ?? null, 100],
    ];
    foreach ($limits as $label => [$value, $max]) {
        if ($value !== null && mb_strlen((string) $value) > $max) {
            return "$label is too long (max $max characters).";
        }
    }
    return null;
}

// Resolves the year level to store for a student. Rule (admins may use all
// six statuses, so resolveYearDeclaration() is deliberately NOT used here):
//   - status 'normal'  -> year level is ALWAYS computed from the school year;
//                         any yearLevel in the payload is ignored.
//   - any other status -> year level is taken from the payload (admin-chosen),
//                         validated against year_levels, never recomputed.
// Returns ['ok'=>bool, 'error'=>?string, 'year_level'=>?string, 'keep'=>bool]
// 'keep' = leave the stored value untouched (nothing valid to write).
function resolveAdminYearLevel(PDO $pdo, string $status, ?int $startYear, ?string $payloadLevel, ?string $existingLevel): array
{
    $yearLevelNames = $pdo->query('SELECT name FROM year_levels ORDER BY sort_order, name')->fetchAll(PDO::FETCH_COLUMN);
    $payloadLevel = trim((string) $payloadLevel);

    if ($status === 'normal') {
        if ($startYear === null) {
            return ['ok' => true, 'error' => null, 'year_level' => null, 'keep' => true];
        }
        $computed = computeYearLevel($startYear, currentSchoolYearStartYear(), $yearLevelNames);
        if ($computed['label'] === null) {
            // Past the configured levels: nothing sensible to compute, so don't clobber what's stored.
            return ['ok' => true, 'error' => null, 'year_level' => null, 'keep' => true];
        }
        return ['ok' => true, 'error' => null, 'year_level' => $computed['label'], 'keep' => false];
    }

    if ($payloadLevel !== '') {
        if (!in_array($payloadLevel, $yearLevelNames, true)) {
            return ['ok' => false, 'error' => 'That year level does not exist.', 'year_level' => null, 'keep' => false];
        }
        return ['ok' => true, 'error' => null, 'year_level' => $payloadLevel, 'keep' => false];
    }

    // No level supplied for a non-normal student: keep what's stored. If nothing
    // is stored yet (new record), a level is required.
    if ($existingLevel === null || $existingLevel === '') {
        return ['ok' => false, 'error' => 'Select a year level for this student.', 'year_level' => null, 'keep' => false];
    }
    return ['ok' => true, 'error' => null, 'year_level' => null, 'keep' => true];
}

// validateStudentIdFormat() now lives in includes/functions.php — shared
// with student/register.php, since students can now self-register
// directly instead of only activating an admin-pre-created row.

$method = $_SERVER['REQUEST_METHOD'];

// ---------- GET: list all students ----------
if ($method === 'GET') {
    $stmt = $pdo->query('
        SELECT u.id, u.student_id, u.name, u.department, u.year_level,
               u.email, u.has_voted, u.registration_status, u.account_status,
               u.school_year_id, sy.label AS school_year_label
        FROM users u
        LEFT JOIN school_years sy ON sy.id = u.school_year_id
        ORDER BY u.name ASC
    ');
    $rows = $stmt->fetchAll();

    // Which (user, election) pairs has each student actually voted in?
    // Computed fresh from votes/election_positions rather than trusting the
    // single legacy has_voted flag, so this stays accurate even when a
    // student is eligible for more than one election at once.
    $voteMap = []; // user_id => [election_id, election_id, ...]
    $voteStmt = $pdo->query('
        SELECT DISTINCT v.user_id, ep.election_id
        FROM votes v
        JOIN election_positions ep ON ep.position_id = v.position_id
    ');
    foreach ($voteStmt->fetchAll() as $row) {
        $voteMap[(int) $row['user_id']][] = (int) $row['election_id'];
    }

    $out = array_map(function ($r) use ($voteMap) {
        return [
            'id' => (int) $r['id'],
            'student_id' => $r['student_id'],
            'name' => $r['name'],
            'department' => $r['department'],
            'year_level' => $r['year_level'],
            'school_year_id' => $r['school_year_id'] !== null ? (int) $r['school_year_id'] : null,
            'school_year_label' => $r['school_year_label'],
            'email' => $r['email'],
            'has_voted' => (int) $r['has_voted'],
            'status' => $r['registration_status'] ?: 'unregistered',
            'account_status' => $r['account_status'] ?: 'normal',
            'voted_election_ids' => $voteMap[(int) $r['id']] ?? [],
        ];
    }, $rows);

    respond($out);
}

// Every other method is a write — require CSRF from the JSON body.
$body = readJsonBody();
if (!verifyCsrfToken($body['csrf_token'] ?? null)) {
    respond(['error' => 'Your session expired. Please refresh and try again.'], 403);
}

// Per-admin rate limit on every write (same policy as the other admin APIs).
$adminWriteKey = 'admin_write:admin:' . (int) ($_SESSION['admin_id'] ?? 0);
if (isRateLimitedKey($pdo, $adminWriteKey, 120, 60)) {
    respond(['error' => 'Too many requests. Please slow down.'], 429);
}
recordAttemptKey($pdo, $adminWriteKey);

// ---------- POST: create a new student ----------
// Bulk CSV import was removed — students self-register directly now
// (see student/register.php), which covers the case this used to exist
// for. This endpoint only ever creates one student at a time.
if ($method === 'POST') {
    $studentId    = trim($body['student_id'] ?? '');
    $name         = trim($body['fullName'] ?? '');
    $department   = trim($body['department'] ?? '');
    $schoolYearId = (int) ($body['schoolYearId'] ?? 0);
    $email        = trim($body['email'] ?? '');
    $password     = (string) ($body['password'] ?? '');

    if ($studentId === '' || $name === '' || $department === '' || $schoolYearId <= 0) {
        respond(['error' => 'Student ID, name, faculty, and school year are all required.'], 400);
    }
    if ($lengthError = checkFieldLengths(['student_id' => $studentId, 'fullName' => $name, 'department' => $department, 'email' => $email])) {
        respond(['error' => $lengthError], 400);
    }

    $syCheck = $pdo->prepare('SELECT start_year FROM school_years WHERE id = :id');
    $syCheck->execute(['id' => $schoolYearId]);
    $syRow = $syCheck->fetch();
    if (!$syRow) {
        respond(['error' => 'That school year does not exist.'], 404);
    }
    $validAccountStatuses = ['normal', 'repeating', 'undergrad_repeating', 'suspended_repeating', 'dropped', 'graduated'];
    $accountStatus = in_array($body['accountStatus'] ?? '', $validAccountStatuses, true) ? $body['accountStatus'] : 'normal';

    $levelResult = resolveAdminYearLevel($pdo, $accountStatus, (int) $syRow['start_year'], $body['yearLevel'] ?? null, null);
    if (!$levelResult['ok']) {
        respond(['error' => $levelResult['error']], 400);
    }
    $yearLevel = $levelResult['year_level']; // null only for a 'normal' student past the configured levels
    if (!validateStudentIdFormat($studentId)) {
        respond(['error' => 'Student ID must be exactly 9 digits.'], 400);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['error' => 'Please enter a valid email address.'], 400);
    }
    if ($password !== '' && ($policyError = passwordPolicyError($password)) !== null) {
        respond(['error' => $policyError], 400);
    }

    $check = $pdo->prepare('SELECT id FROM users WHERE student_id = :id');
    $check->execute(['id' => $studentId]);
    if ($check->fetch()) {
        respond(['error' => 'That Student ID is already registered.'], 409);
    }

    // If the admin sets a password now, the account is active immediately.
    // If left blank, the student activates their own account via the
    // self-registration page (registration_status stays 'unregistered').
    $hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
    $status = $password !== '' ? 'active' : 'unregistered';

    $stmt = $pdo->prepare('
        INSERT INTO users (student_id, name, department, school_year_id, year_level, email, password, registration_status, account_status, has_voted)
        VALUES (:student_id, :name, :department, :school_year_id, :year_level, :email, :password, :status, :account_status, 0)
    ');
    $stmt->execute([
        'student_id' => $studentId,
        'name' => $name,
        'department' => $department,
        'school_year_id' => $schoolYearId,
        'year_level' => $yearLevel,
        'email' => $email !== '' ? $email : null,
        'password' => $hash,
        'status' => $status,
        'account_status' => $accountStatus,
    ]);

    respond(['success' => true, 'id' => (int) $pdo->lastInsertId()]);
}

// ---------- PUT: update an existing student ----------
if ($method === 'PUT') {
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        respond(['error' => 'Invalid student ID.'], 400);
    }

    $existing = $pdo->prepare('SELECT id, student_id, account_status, year_level, school_year_id FROM users WHERE id = :id');
    $existing->execute(['id' => $id]);
    $current = $existing->fetch();
    if (!$current) {
        respond(['error' => 'Student not found.'], 404);
    }

    // Special action: actually deletes the student's cast votes for ONE
    // specific election (not just flipping a display flag), so the
    // Students panel's real per-election status reflects it immediately
    // and the student can genuinely vote again in that election. This is
    // what the admin dashboard's "Reset" button on a student's voting
    // status now calls — previously it only toggled the legacy has_voted
    // column below, which doesn't affect what's actually shown per
    // election or what vote.php checks, so the button did nothing real.
    if (!empty($body['resetVotesForElection'])) {
        $eid = (int) $body['resetVotesForElection'];
        $pdo->prepare('
            DELETE FROM votes
            WHERE user_id = :uid
              AND position_id IN (SELECT position_id FROM election_positions WHERE election_id = :eid)
        ')->execute(['uid' => $id, 'eid' => $eid]);

        // Drop the submission lock too — without this the student would
        // stay locked out of the election even though their votes are gone
        // (the lock, not the votes, is what vote.php checks).
        $pdo->prepare(
            'DELETE FROM ballot_submissions WHERE user_id = :uid AND election_id = :eid'
        )->execute(['uid' => $id, 'eid' => $eid]);

        // Keep the legacy "voted somewhere, ever" flag consistent: only
        // true if the student still has at least one vote left in any
        // election after this reset.
        $remainStmt = $pdo->prepare('SELECT COUNT(*) FROM votes WHERE user_id = :uid');
        $remainStmt->execute(['uid' => $id]);
        $remaining = (int) $remainStmt->fetchColumn();
        $pdo->prepare('UPDATE users SET has_voted = :hv WHERE id = :id')
            ->execute(['hv' => $remaining > 0 ? 1 : 0, 'id' => $id]);

        respond(['success' => true]);
    }

    $fields = [];
    $values = ['id' => $id];

    $map = [
        'student_id'  => 'student_id',
        'fullName'    => 'name',
        'department'  => 'department',
        'email'       => 'email',
        'status'      => 'registration_status',
    ];

    if ($lengthError = checkFieldLengths($body)) {
        respond(['error' => $lengthError], 400);
    }

    $validAccountStatuses = ['normal', 'repeating', 'undergrad_repeating', 'suspended_repeating', 'dropped', 'graduated'];
    if (array_key_exists('accountStatus', $body)) {
        if (!in_array($body['accountStatus'], $validAccountStatuses, true)) {
            respond(['error' => 'Invalid account status.'], 400);
        }
        $fields[] = 'account_status = :account_status';
        $values['account_status'] = $body['accountStatus'];
    }

    $newSchoolYearId = $current['school_year_id'] !== null ? (int) $current['school_year_id'] : null;
    if (array_key_exists('schoolYearId', $body)) {
        $newSchoolYearId = (int) $body['schoolYearId'];
        $syCheck = $pdo->prepare('SELECT id FROM school_years WHERE id = :id');
        $syCheck->execute(['id' => $newSchoolYearId]);
        if (!$syCheck->fetch()) {
            respond(['error' => 'That school year does not exist.'], 404);
        }
        $fields[] = 'school_year_id = :school_year_id';
        $values['school_year_id'] = $newSchoolYearId;
    }

    // Year level: only touched when something that affects it was sent.
    //   normal      -> recomputed from the (new or existing) school year, payload ignored
    //   non-normal  -> admin-chosen level from the payload; NEVER recomputed on a
    //                  school-year change (repeaters would be silently overwritten)
    if (array_key_exists('accountStatus', $body) || array_key_exists('schoolYearId', $body) || array_key_exists('yearLevel', $body)) {
        $effectiveStatus = array_key_exists('accountStatus', $body) ? $body['accountStatus'] : ($current['account_status'] ?: 'normal');
        $startYear = null;
        if ($newSchoolYearId !== null) {
            $syStmt = $pdo->prepare('SELECT start_year FROM school_years WHERE id = :id');
            $syStmt->execute(['id' => $newSchoolYearId]);
            $syYear = $syStmt->fetchColumn();
            $startYear = $syYear !== false ? (int) $syYear : null;
        }
        $levelResult = resolveAdminYearLevel($pdo, $effectiveStatus, $startYear, $body['yearLevel'] ?? null, $current['year_level']);
        if (!$levelResult['ok']) {
            respond(['error' => $levelResult['error']], 400);
        }
        if (!$levelResult['keep']) {
            $fields[] = 'year_level = :year_level';
            $values['year_level'] = $levelResult['year_level'];
        }
    }

    if (isset($body['student_id']) && trim($body['student_id']) !== $current['student_id']) {
        if (!validateStudentIdFormat(trim($body['student_id']))) {
            respond(['error' => 'Student ID must be exactly 9 digits.'], 400);
        }
        $dupCheck = $pdo->prepare('SELECT id FROM users WHERE student_id = :sid AND id != :id');
        $dupCheck->execute(['sid' => trim($body['student_id']), 'id' => $id]);
        if ($dupCheck->fetch()) {
            respond(['error' => 'That Student ID is already registered.'], 409);
        }
    }
    if (array_key_exists('email', $body)) {
        $email = trim((string) $body['email']);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['error' => 'Please enter a valid email address.'], 400);
        }
    }

    foreach ($map as $key => $column) {
        if (array_key_exists($key, $body)) {
            $fields[] = "$column = :$column";
            $values[$column] = is_string($body[$key]) ? trim($body[$key]) : $body[$key];
        }
    }

    if (array_key_exists('hasVoted', $body)) {
        $fields[] = 'has_voted = :has_voted';
        $values['has_voted'] = $body['hasVoted'] ? 1 : 0;
    }

    if (!empty($body['password'])) {
        $password = (string) $body['password'];
        if (($policyError = passwordPolicyError($password)) !== null) {
            respond(['error' => $policyError], 400);
        }
        $fields[] = 'password = :password';
        $values['password'] = password_hash($password, PASSWORD_DEFAULT);
        // Setting a password activates the account, unless the admin is
        // explicitly suspending it in the same request.
        if (!isset($body['status'])) {
            $fields[] = 'registration_status = :auto_status';
            $values['auto_status'] = 'active';
        }
    }

    if (empty($fields)) {
        respond(['error' => 'No fields to update.'], 400);
    }

    $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $pdo->prepare($sql)->execute($values);

    respond(['success' => true]);
}

// ---------- DELETE: remove a student ----------
// DELETE is intentionally disabled — the admin panel no longer offers a
// way to delete a student record (removed from the Students panel UI).
// Deleting a real voter record is destructive (cascades to their votes,
// permanently altering historical tallies) and the lifecycle model added
// alongside school years — suspended / dropped / graduated via
// account_status, or Suspended via registration_status — already covers
// every legitimate reason to retire an account, without losing the
// underlying data. Left as an explicit 405 rather than silently removed,
// so a stray client-side call fails loudly instead of doing nothing.
if ($method === 'DELETE') {
    respond(['error' => 'Deleting students is not supported. Use Suspended status or a Lifecycle flag instead.'], 405);
}

respond(['error' => 'Method not allowed.'], 405);