<?php
/**
 * admin/api/settings.php
 *
 * CRUD for the reference lists that power dropdowns elsewhere in the admin
 * panel: Departments (Faculties), School Years, and Year Levels.
 * These used to be hardcoded JS arrays inside admin/dashboard.php — this
 * makes them admin-editable instead. See migration_settings.sql for the
 * schema and why users.department/users.year_level/users.school_year_id/
 * elections.department deliberately stay plain text with no foreign key.
 *
 * Also serves election LOGOS:
 *   - Each department can have its own logo (departments.logo), shown on
 *     that department's DSG elections everywhere in the app.
 *   - A single site-wide SSG logo lives in the generic site_settings
 *     key/value table (SSG isn't a department, so it has nowhere else to
 *     live). See migration_logos.sql.
 * Both are stored the same way candidate photos already are — as the
 * browser's own base64 data: URL, directly in Postgres, capped at 2MB.
 *
 * A single "type" field ('department' | 'school_year' | 'year_level' |
 * 'department_logo' | 'site_setting') routes POST/PUT/DELETE to the right
 * sub-resource, since these are all small and closely related — keeps
 * this to one file instead of several near-identical ones.
 *
 * "Majors" have been retired (see migration_schoolyear.sql) — the
 * `majors` table still exists but nothing here manages it anymore.
 *
 * "school_year" rows are label/start_year/sort_order editable, but
 * is_active is fully calendar-derived (see getActiveSchoolYear() in
 * includes/functions.php) — there's no manual "set active" control.
 * GET auto-ensures the current AND next school year both exist.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

startSecureSession();
requireAdminLogin();
header('Content-Type: application/json');

$pdo = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function nextSortOrder(PDO $pdo, string $table, ?array $where = null): int
{
    $sql = "SELECT COALESCE(MAX(sort_order), -1) FROM $table";
    if ($where) {
        $sql .= ' WHERE ' . $where['clause'];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($where['params'] ?? []);
    return ((int) $stmt->fetchColumn()) + 1;
}

// Rough base64 -> decoded-byte-size estimate, good enough for a size cap.
// Same helper as admin/api/elections.php uses for candidate photos.
function base64ApproxBytes(string $base64): int
{
    $comma = strpos($base64, ',');
    $encoded = $comma !== false ? substr($base64, $comma + 1) : $base64;
    return (int) (strlen($encoded) * 3 / 4);
}

// Validates an uploaded logo image: must be a real data:image/ URL whose
// DECODED BYTES actually parse as a raster image (PNG/JPEG/GIF/WebP — see
// isValidImageDataUrl() in functions.php; SVG and anything else is
// rejected), capped at 2MB (matches the candidate-photo cap in
// admin/api/elections.php).
// Returns an error string, or null if the image is fine (or empty/absent,
// which is valid — it means "clear the logo").
function validateLogoUpload(?string $value): ?string
{
    if (empty($value)) {
        return null; // clearing the logo — always allowed
    }
    if (strpos($value, 'data:image/') !== 0) {
        return 'Invalid image data.';
    }
    if (base64ApproxBytes($value) > 2 * 1024 * 1024) {
        return 'Logo is too large (max 2MB) — please use a smaller image.';
    }
    if (!isValidImageDataUrl($value)) {
        return 'That file is not a valid image (use PNG, JPEG, GIF, or WebP).';
    }
    return null;
}

// ------------------------------------------------------------------
// GET ?type=maintenance: prune candidates (Settings > Maintenance card)
// ------------------------------------------------------------------
// Counts only - the actual pruning is a POST below. Cutoffs are computed
// in PHP so the SQL stays valid on BOTH engines (MySQL and PostgreSQL
// quote INTERVAL literals differently).
if ($method === 'GET' && ($_GET['type'] ?? '') === 'maintenance') {
    $attemptsCutoff = date('Y-m-d H:i:s', time() - 7 * 86400);
    $logsCutoff = date('Y-m-d H:i:s', time() - 365 * 86400);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM vote_drafts vd JOIN elections e ON e.id = vd.election_id WHERE e.status IN (\'closed\', \'archived\')');
    $stmt->execute();
    $draftsClosed = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE attempted_at < :cutoff');
    $stmt->execute(['cutoff' => $attemptsCutoff]);
    $attemptsOld = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM activity_logs WHERE created_at < :cutoff');
    $stmt->execute(['cutoff' => $logsCutoff]);
    $logsOld = (int) $stmt->fetchColumn();

    respond([
        'drafts_closed_elections' => $draftsClosed,
        'attempts_older_than_7d' => $attemptsOld,
        'logs_older_than_365d' => $logsOld,
    ]);
}

// ------------------------------------------------------------------
// GET: everything needed to populate dropdowns + the Settings panel
// ------------------------------------------------------------------
if ($method === 'GET') {
    $departments = $pdo->query('SELECT code, name, sort_order, logo FROM departments ORDER BY sort_order, code')->fetchAll();
    $yearLevels = $pdo->query('SELECT id, name, sort_order FROM year_levels ORDER BY sort_order, name')->fetchAll();

    // Auto-derived from today's date (see includes/functions.php) — this
    // guarantees the current AND next school year (elections are run FOR
    // the next one — see migration_election_target_schoolyear.sql) always
    // exist and appear in the list, without an admin ever needing to add
    // them manually first.
    getActiveSchoolYear($pdo);
    getOrCreateSchoolYear($pdo, nextSchoolYearStartYear());

    $schoolYears = $pdo->query('SELECT id, label, start_year, is_active, sort_order FROM school_years ORDER BY sort_order, start_year')->fetchAll();
    $ssgLogo = getSiteSetting($pdo, 'ssg_logo');

    respond([
        'departments' => $departments,
        'year_levels' => $yearLevels,
        'school_years' => array_map(function ($sy) {
            $sy['is_active'] = (bool) $sy['is_active'];
            return $sy;
        }, $schoolYears),
        'site_settings' => ['ssg_logo' => $ssgLogo],
    ]);
}

// Every other method is a write — require CSRF from the JSON body.
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    respond(['error' => 'Invalid request body.'], 400);
}
if (!verifyCsrfToken($body['csrf_token'] ?? null)) {
    respond(['error' => 'Your session expired. Please refresh and try again.'], 403);
}

// Per-admin rate limit on every write (same policy as the other admin APIs).
$adminWriteKey = 'admin_write:admin:' . (int) ($_SESSION['admin_id'] ?? 0);
if (isRateLimitedKey($pdo, $adminWriteKey, 120, 60)) {
    respond(['error' => 'Too many requests. Please slow down.'], 429);
}
recordAttemptKey($pdo, $adminWriteKey);

$type = $body['type'] ?? '';
if (!in_array($type, ['department', 'school_year', 'year_level', 'department_logo', 'site_setting', 'maintenance'], true)) {
    respond(['error' => 'Invalid type.'], 400);
}

// ------------------------------------------------------------------
// POST: create
// ------------------------------------------------------------------
if ($method === 'POST') {
    // Maintenance prunes (Settings > Maintenance card). Every action is
    // idempotent, deletes only clearly-dead rows, and is audit-logged
    // with its count. SELECT-then-DELETE keeps the draft prune portable
    // (MySQL has no DELETE ... USING, PostgreSQL has no multi-table
    // DELETE ... FROM syntax).
    if ($type === 'maintenance') {
        $action = (string) ($body['action'] ?? '');
        $removed = 0;
        if ($action === 'prune_drafts') {
            $stmt = $pdo->prepare('SELECT vd.id FROM vote_drafts vd JOIN elections e ON e.id = vd.election_id WHERE e.status IN (\'closed\', \'archived\')');
            $stmt->execute();
            $ids = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $del = $pdo->prepare("DELETE FROM vote_drafts WHERE id IN ($ph)");
                $del->execute($ids);
                $removed = (int) $del->rowCount();
            }
            $label = 'Pruned ' . $removed . ' vote draft(s) from closed/archived elections';
        } elseif ($action === 'prune_attempts') {
            $cutoff = date('Y-m-d H:i:s', time() - 7 * 86400);
            $del = $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < :cutoff');
            $del->execute(['cutoff' => $cutoff]);
            $removed = (int) $del->rowCount();
            $label = 'Pruned ' . $removed . ' login attempt row(s) older than 7 days';
        } elseif ($action === 'prune_logs') {
            $cutoff = date('Y-m-d H:i:s', time() - 365 * 86400);
            $del = $pdo->prepare('DELETE FROM activity_logs WHERE created_at < :cutoff');
            $del->execute(['cutoff' => $cutoff]);
            $removed = (int) $del->rowCount();
            $label = 'Pruned ' . $removed . ' activity log line(s) older than one year';
        } else {
            respond(['error' => 'Unknown maintenance action.'], 400);
        }

        $log = $pdo->prepare('INSERT INTO activity_logs (admin_username, action) VALUES (:username, :action)');
        $log->execute([
            'username' => $_SESSION['admin_username'] ?? 'admin',
            'action' => $label,
        ]);

        respond(['success' => true, 'removed' => $removed]);
    }

    if ($type === 'department') {
        // The admin only enters a Faculty NAME now — "Code" was removed
        // from the UI as redundant (adviser wants everything labeled with
        // the complete faculty name). Internally, code and name are kept
        // IDENTICAL — code remains the actual value stored on
        // users.department / elections.department / candidates.department,
        // so nothing else in the app needs to change; it just always
        // holds the full name now instead of a short abbreviation. See
        // migration_faculty_full_name.sql for the column-width changes
        // that make this safe.
        $name = trim($body['name'] ?? '');
        if ($name === '') respond(['error' => 'Faculty name is required.'], 400);
        if (mb_strlen($name) > 150) respond(['error' => 'Faculty name is too long (max 150 characters).'], 400);

        $check = $pdo->prepare('SELECT id FROM departments WHERE code = :code');
        $check->execute(['code' => $name]);
        if ($check->fetch()) respond(['error' => 'A faculty with that name already exists.'], 409);

        $sort = nextSortOrder($pdo, 'departments');
        $pdo->prepare('INSERT INTO departments (code, name, sort_order) VALUES (:code, :name, :sort)')
            ->execute(['code' => $name, 'name' => $name, 'sort' => $sort]);
        respond(['success' => true]);
    }

    if ($type === 'school_year') {
        $label = trim($body['label'] ?? '');
        $startYear = (int) ($body['start_year'] ?? 0);
        if ($label === '' || $startYear <= 0) respond(['error' => 'A label and a valid start year are required.'], 400);
        if (mb_strlen($label) > 20) respond(['error' => 'School year label is too long (max 20 characters).'], 400);
        if ($startYear < 2000 || $startYear > 2100) respond(['error' => 'Start year looks invalid.'], 400);

        $dupLabel = $pdo->prepare('SELECT id FROM school_years WHERE label = :label');
        $dupLabel->execute(['label' => $label]);
        if ($dupLabel->fetch()) respond(['error' => 'That school year label already exists.'], 409);

        $dupYear = $pdo->prepare('SELECT id FROM school_years WHERE start_year = :sy');
        $dupYear->execute(['sy' => $startYear]);
        if ($dupYear->fetch()) respond(['error' => 'A school year with that start year already exists.'], 409);

        $sort = nextSortOrder($pdo, 'school_years');
        $pdo->prepare('INSERT INTO school_years (label, start_year, sort_order) VALUES (:label, :sy, :sort)')
            ->execute(['label' => $label, 'sy' => $startYear, 'sort' => $sort]);
        respond(['success' => true]);
    }

    if ($type === 'year_level') {
        $name = trim($body['name'] ?? '');
        if ($name === '') respond(['error' => 'Year level name is required.'], 400);
        if (mb_strlen($name) > 20) respond(['error' => 'Year level name is too long (max 20 characters).'], 400);

        $check = $pdo->prepare('SELECT id FROM year_levels WHERE name = :name');
        $check->execute(['name' => $name]);
        if ($check->fetch()) respond(['error' => 'That year level already exists.'], 409);

        $sort = nextSortOrder($pdo, 'year_levels');
        $pdo->prepare('INSERT INTO year_levels (name, sort_order) VALUES (:name, :sort)')
            ->execute(['name' => $name, 'sort' => $sort]);
        respond(['success' => true]);
    }

    // 'department_logo' and 'site_setting' are only ever updates to an
    // existing row (a department, or a known settings key) — there's
    // nothing meaningful to "create" for them, so POST falls through to
    // "Method not allowed" for those types. Use PUT to set/clear a logo.
    respond(['error' => 'Method not allowed for this type.'], 405);
}

// ------------------------------------------------------------------
// PUT: update (including reorder + logo upload), with an explicit bulk-
// update when a rename actually changes a value that's stored elsewhere
// (department code, major name, year level name) — never silent, always
// in the same transaction as the rename itself.
// ------------------------------------------------------------------
if ($method === 'PUT') {
    if ($type === 'department') {
        // Single-field rename now — the admin only edits a Faculty NAME,
        // and code is kept identical to it internally (see the POST
        // handler above and migration_faculty_full_name.sql). old_code
        // is how the existing row is found; the new name becomes both
        // the new code and the new name.
        $oldCode = trim($body['old_code'] ?? '');
        $newName = trim($body['name'] ?? '');
        $sortOrder = array_key_exists('sort_order', $body) ? (int) $body['sort_order'] : null;

        if ($oldCode === '') respond(['error' => 'Missing faculty identifier.'], 400);
        $existing = $pdo->prepare('SELECT code FROM departments WHERE code = :code');
        $existing->execute(['code' => $oldCode]);
        if (!$existing->fetch()) respond(['error' => 'Faculty not found.'], 404);

        if ($newName === '') respond(['error' => 'Faculty name cannot be empty.'], 400);
        if (mb_strlen($newName) > 150) respond(['error' => 'Faculty name is too long (max 150 characters).'], 400);

        $newCode = $newName; // code always mirrors name
        if ($newCode !== $oldCode) {
            $dupCheck = $pdo->prepare('SELECT id FROM departments WHERE code = :code AND code != :old');
            $dupCheck->execute(['code' => $newCode, 'old' => $oldCode]);
            if ($dupCheck->fetch()) respond(['error' => 'A faculty with that name already exists.'], 409);
        }

        try {
            $pdo->beginTransaction();

            $fields = ['name = :name'];
            $values = ['old' => $oldCode, 'name' => $newName];
            if ($newCode !== $oldCode) {
                $fields[] = 'code = :new_code';
                $values['new_code'] = $newCode;
            }
            if ($sortOrder !== null) {
                $fields[] = 'sort_order = :sort';
                $values['sort'] = $sortOrder;
            }
            $pdo->prepare('UPDATE departments SET ' . implode(', ', $fields) . ' WHERE code = :old')->execute($values);

            $affected = ['users' => 0, 'elections' => 0];
            if ($newCode !== $oldCode) {
                // users.department / elections.department are plain text
                // with no FK, so those need an explicit bulk-update here,
                // in the same transaction, so nothing is left pointing at
                // a faculty name that no longer exists. candidates.department
                // DOES have a real FK (ON UPDATE CASCADE), so it follows
                // automatically.
                $u = $pdo->prepare('UPDATE users SET department = :new WHERE department = :old');
                $u->execute(['new' => $newCode, 'old' => $oldCode]);
                $affected['users'] = $u->rowCount();

                $e = $pdo->prepare('UPDATE elections SET department = :new WHERE department = :old');
                $e->execute(['new' => $newCode, 'old' => $oldCode]);
                $affected['elections'] = $e->rowCount();
            }

            $pdo->commit();
            respond(['success' => true, 'updated_students' => $affected['users'], 'updated_elections' => $affected['elections']]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Update department failed: ' . $e->getMessage());
            respond(['error' => 'Could not save changes.'], 500);
        }
    }

    if ($type === 'department_logo') {
        $code = trim($body['code'] ?? '');
        if ($code === '') respond(['error' => 'Missing department code.'], 400);

        $existing = $pdo->prepare('SELECT code FROM departments WHERE code = :code');
        $existing->execute(['code' => $code]);
        if (!$existing->fetch()) respond(['error' => 'Department not found.'], 404);

        // An absent/empty 'logo' means "clear it" — that's intentional
        // (see the Remove Logo button in the admin panel), not an error.
        $logoInput = $body['logo'] ?? null;
        if ($error = validateLogoUpload($logoInput)) {
            respond(['error' => $error], 400);
        }

        $pdo->prepare('UPDATE departments SET logo = :logo WHERE code = :code')
            ->execute(['logo' => $logoInput ?: null, 'code' => $code]);
        respond(['success' => true]);
    }

    if ($type === 'site_setting') {
        // Only known keys are writable here — this endpoint is deliberately
        // not a general-purpose key/value store the client can write
        // anything to; it's just where the SSG logo (and future site-wide
        // assets like it) live.
        $key = trim($body['key'] ?? '');
        if (!in_array($key, ['ssg_logo'], true)) {
            respond(['error' => 'Unknown setting.'], 400);
        }

        $valueInput = $body['value'] ?? null;
        if ($error = validateLogoUpload($valueInput)) {
            respond(['error' => $error], 400);
        }
        $value = $valueInput ?: null;

        if (isMysql()) {
            $pdo->prepare('
                INSERT INTO site_settings (setting_key, setting_value, updated_at)
                VALUES (:k, :v, NOW())
                ON DUPLICATE KEY UPDATE setting_value = :v2, updated_at = NOW()
            ')->execute(['k' => $key, 'v' => $value, 'v2' => $value]);
        } else {
            $pdo->prepare('
                INSERT INTO site_settings (setting_key, setting_value, updated_at)
                VALUES (:k, :v, NOW())
                ON CONFLICT (setting_key) DO UPDATE SET setting_value = :v2, updated_at = NOW()
            ')->execute(['k' => $key, 'v' => $value, 'v2' => $value]);
        }
        respond(['success' => true]);
    }

    if ($type === 'school_year') {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) respond(['error' => 'Invalid school year.'], 400);

        $existing = $pdo->prepare('SELECT id, label, start_year, is_active FROM school_years WHERE id = :id');
        $existing->execute(['id' => $id]);
        $current = $existing->fetch();
        if (!$current) respond(['error' => 'School year not found.'], 404);

        // is_active is fully calendar-derived now (see getActiveSchoolYear()
        // in includes/functions.php) — there's no manual "set active"
        // control anymore. Only the label/start_year/sort_order are
        // editable here (e.g. to fix a typo in a label).

        $label = trim($body['label'] ?? $current['label']);
        $startYear = array_key_exists('start_year', $body) ? (int) $body['start_year'] : (int) $current['start_year'];
        $sortOrder = array_key_exists('sort_order', $body) ? (int) $body['sort_order'] : null;
        if ($label === '') respond(['error' => 'School year label cannot be empty.'], 400);
        if (mb_strlen($label) > 20) respond(['error' => 'School year label is too long (max 20 characters).'], 400);
        if ($startYear < 2000 || $startYear > 2100) respond(['error' => 'Start year looks invalid.'], 400);

        if ($label !== $current['label']) {
            $dupCheck = $pdo->prepare('SELECT id FROM school_years WHERE label = :label AND id != :id');
            $dupCheck->execute(['label' => $label, 'id' => $id]);
            if ($dupCheck->fetch()) respond(['error' => 'That school year label already exists.'], 409);
        }
        if ($startYear !== (int) $current['start_year']) {
            $dupYear = $pdo->prepare('SELECT id FROM school_years WHERE start_year = :sy AND id != :id');
            $dupYear->execute(['sy' => $startYear, 'id' => $id]);
            if ($dupYear->fetch()) respond(['error' => 'A school year with that start year already exists.'], 409);
        }

        $fields = ['label = :label', 'start_year = :sy'];
        $values = ['id' => $id, 'label' => $label, 'sy' => $startYear];
        if ($sortOrder !== null) {
            $fields[] = 'sort_order = :sort';
            $values['sort'] = $sortOrder;
        }
        $pdo->prepare('UPDATE school_years SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($values);
        respond(['success' => true]);
    }

    if ($type === 'year_level') {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) respond(['error' => 'Invalid year level.'], 400);

        $existing = $pdo->prepare('SELECT id, name FROM year_levels WHERE id = :id');
        $existing->execute(['id' => $id]);
        $current = $existing->fetch();
        if (!$current) respond(['error' => 'Year level not found.'], 404);

        $newName = trim($body['name'] ?? $current['name']);
        $sortOrder = array_key_exists('sort_order', $body) ? (int) $body['sort_order'] : null;
        if ($newName === '') respond(['error' => 'Year level name cannot be empty.'], 400);
        if (mb_strlen($newName) > 20) respond(['error' => 'Year level name is too long (max 20 characters).'], 400);

        if ($newName !== $current['name']) {
            $dupCheck = $pdo->prepare('SELECT id FROM year_levels WHERE name = :name AND id != :id');
            $dupCheck->execute(['name' => $newName, 'id' => $id]);
            if ($dupCheck->fetch()) respond(['error' => 'That year level already exists.'], 409);
        }

        try {
            $pdo->beginTransaction();

            $fields = ['name = :name'];
            $values = ['id' => $id, 'name' => $newName];
            if ($sortOrder !== null) {
                $fields[] = 'sort_order = :sort';
                $values['sort'] = $sortOrder;
            }
            $pdo->prepare('UPDATE year_levels SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($values);

            $affected = ['users' => 0, 'positions' => 0];
            if ($newName !== $current['name']) {
                $u = $pdo->prepare('UPDATE users SET year_level = :new WHERE year_level = :old');
                $u->execute(['new' => $newName, 'old' => $current['name']]);
                $affected['users'] = $u->rowCount();

                // A position's "limit to see" (year_restriction) also
                // stores this same text value — keep it in sync too.
                $p = $pdo->prepare('UPDATE election_positions SET year_restriction = :new WHERE year_restriction = :old');
                $p->execute(['new' => $newName, 'old' => $current['name']]);
                $affected['positions'] = $p->rowCount();
            }

            $pdo->commit();
            respond(['success' => true, 'updated_students' => $affected['users'], 'updated_positions' => $affected['positions']]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Update year level failed: ' . $e->getMessage());
            respond(['error' => 'Could not save changes.'], 500);
        }
    }
}

// ------------------------------------------------------------------
// DELETE: blocked if anything currently uses the value, same pattern as
// "can't delete an election with votes already cast" elsewhere in the app.
// ------------------------------------------------------------------
if ($method === 'DELETE') {
    if ($type === 'department') {
        $code = trim($body['code'] ?? '');
        if ($code === '') respond(['error' => 'Missing faculty identifier.'], 400);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE department = :code');
        $stmt->execute(['code' => $code]);
        $uCount = (int) $stmt->fetchColumn();

        $stmt2 = $pdo->prepare('SELECT COUNT(*) FROM elections WHERE department = :code');
        $stmt2->execute(['code' => $code]);
        $eCount = (int) $stmt2->fetchColumn();

        // candidates.department references departments(code) with
        // ON DELETE RESTRICT — a faculty used only by candidates
        // (e.g. SSG candidates, where elections.department is NULL)
        // passes the users/elections counts above and then blows up
        // as a raw FK violation. Check it too.
        $candStmt = $pdo->prepare('SELECT COUNT(*) FROM candidates WHERE department = :code');
        $candStmt->execute(['code' => $code]);
        $cCount = (int) $candStmt->fetchColumn();

        if ($uCount > 0 || $eCount > 0 || $cCount > 0) {
            $parts = [];
            if ($uCount > 0) $parts[] = "$uCount student" . ($uCount === 1 ? '' : 's');
            if ($eCount > 0) $parts[] = "$eCount election" . ($eCount === 1 ? '' : 's');
            if ($cCount > 0) $parts[] = "$cCount candidate" . ($cCount === 1 ? '' : 's');
            respond(['error' => 'Cannot delete — ' . implode(' and ', $parts) . ' still use this faculty. Reassign them first.'], 409);
        }

        // Its logo (if any) goes with the row — nothing else references it.
        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM departments WHERE code = :code')->execute(['code' => $code]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Delete department failed: ' . $e->getMessage());
            respond(['error' => 'Could not delete the faculty. Please try again.'], 500);
        }
        respond(['success' => true]);
    }

    if ($type === 'school_year') {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) respond(['error' => 'Invalid school year.'], 400);

        $stmt = $pdo->prepare('SELECT id, is_active FROM school_years WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $sy = $stmt->fetch();
        if (!$sy) respond(['error' => 'School year not found.'], 404);

        if ($sy['is_active']) {
            respond(['error' => 'Cannot delete the active school year. Set another one active first.'], 409);
        }

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE school_year_id = :id');
        $countStmt->execute(['id' => $id]);
        $count = (int) $countStmt->fetchColumn();
        // elections.target_school_year_id has no ON DELETE action —
        // deleting a targeted school year throws an FK violation
        // instead of the intended 409. Check it too.
        $eStmt = $pdo->prepare('SELECT COUNT(*) FROM elections WHERE target_school_year_id = :id');
        $eStmt->execute(['id' => $id]);
        $eCount = (int) $eStmt->fetchColumn();
        if ($count > 0 || $eCount > 0) {
            $parts = [];
            if ($count > 0) $parts[] = "$count student" . ($count === 1 ? '' : 's') . ' started in this school year';
            if ($eCount > 0) $parts[] = "$eCount election" . ($eCount === 1 ? '' : 's') . ' targeted at it';
            respond(['error' => 'Cannot delete — ' . implode(' and ', $parts) . '. Reassign them first.'], 409);
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM school_years WHERE id = :id')->execute(['id' => $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Delete school year failed: ' . $e->getMessage());
            respond(['error' => 'Could not delete the school year. Please try again.'], 500);
        }
        respond(['success' => true]);
    }

    if ($type === 'year_level') {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) respond(['error' => 'Invalid year level.'], 400);

        $stmt = $pdo->prepare('SELECT name FROM year_levels WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $yl = $stmt->fetch();
        if (!$yl) respond(['error' => 'Year level not found.'], 404);

        $uStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE year_level = :name');
        $uStmt->execute(['name' => $yl['name']]);
        $uCount = (int) $uStmt->fetchColumn();

        $pStmt = $pdo->prepare('SELECT COUNT(*) FROM election_positions WHERE year_restriction = :name');
        $pStmt->execute(['name' => $yl['name']]);
        $pCount = (int) $pStmt->fetchColumn();

        if ($uCount > 0 || $pCount > 0) {
            $parts = [];
            if ($uCount > 0) $parts[] = "$uCount student" . ($uCount === 1 ? '' : 's');
            if ($pCount > 0) $parts[] = "$pCount position" . ($pCount === 1 ? '' : 's') . ' restricted to it';
            respond(['error' => 'Cannot delete — ' . implode(' and ', $parts) . ' still use this year level.'], 409);
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM year_levels WHERE id = :id')->execute(['id' => $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Delete year level failed: ' . $e->getMessage());
            respond(['error' => 'Could not delete the year level. Please try again.'], 500);
        }
        respond(['success' => true]);
    }

    // 'department_logo' and 'site_setting' are cleared via PUT with an
    // empty value (see above) rather than DELETE — a logo isn't its own
    // row, it's a field on one, so there's nothing distinct to DELETE.
    respond(['error' => 'Method not allowed for this type.'], 405);
}

respond(['error' => 'Method not allowed.'], 405);