<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

startSecureSession();
header('Content-Type: application/json');

// Must be logged in.
if (empty($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'You must be logged in to vote.'], 401);
}

// Only accept POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Invalid request method.'], 405);
}

// CSRF check.
if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    jsonResponse(['success' => false, 'message' => 'Your session expired. Please refresh and try again.'], 403);
}

$userId = (int) $_SESSION['user_id'];

// Per-user rate limit. vote.php opens a transaction with SELECT ... FOR
// UPDATE per call, so an unbounded loop of submissions (stuck client,
// scripted abuse, double-click storms) would translate directly into DB
// lock contention. 10 submissions/minute is far above any legitimate
// ballot — a real ballot is one submission per election.
$pdo = getDbConnection();
if (isRateLimitedKey($pdo, 'vote_submit:user:' . $userId, 10, 60)) {
    jsonResponse(['success' => false, 'message' => 'Too many vote attempts. Please wait a minute and try again.'], 429);
}
recordAttemptKey($pdo, 'vote_submit:user:' . $userId);

// Two supported shapes:
//   - single election:  election_id=X, fields position_<pid>[]
//   - combined ballot:  combined=1 + combined_elections[]=X&Y,
//                       fields e_<eid>_p_<pid>[]
// The combined ballot is one continuous page but still writes one
// ballot_submissions row per election, inside the same transaction.
$isCombined = !empty($_POST['combined']);

if ($isCombined) {
    $targetEids = array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['combined_elections'] ?? [])),
        fn($id) => $id > 0
    )));
    sort($targetEids);
    if (!$targetEids) {
        jsonResponse(['success' => false, 'message' => 'No elections were included in this ballot.'], 400);
    }
    if (count($targetEids) > 20) {
        jsonResponse(['success' => false, 'message' => 'Too many elections in one ballot.'], 400);
    }
} else {
    $electionId = (int) ($_POST['election_id'] ?? 0);
    if ($electionId <= 0) {
        jsonResponse(['success' => false, 'message' => 'Missing election.'], 400);
    }
    $targetEids = [$electionId];
}

syncElectionStatuses($pdo);

try {
    $pdo->beginTransaction();

    // Lock the user row so two rapid submissions (double-click, two tabs)
    // can't both pass validation before either has inserted a vote.
    $stmt = $pdo->prepare('SELECT id, department, year_level, password_reset_pending FROM users WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $userId]);
    $voter = $stmt->fetch();

    if (!$voter) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'User not found.'], 404);
    }

    // Forced password change: an admin-issued temporary password sets
    // users.password_reset_pending - no ballot may be cast until the
    // student picks a real one. The dashboard gate (change-password.php)
    // handles the UI side; this closes the hand-crafted POST path too.
    if (!empty($voter['password_reset_pending'])) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'You must set a new password before voting.'], 403);
    }

    $now = new DateTime();
    $electionStmt = $pdo->prepare('SELECT * FROM elections WHERE id = :id');
    $votedStmt = $pdo->prepare(
        'SELECT 1 FROM ballot_submissions WHERE user_id = :uid AND election_id = :eid'
    );
    $posStmt = $pdo->prepare("
        SELECT ep.position_id, ep.year_restriction, ep.winner_count
        FROM election_positions ep
        WHERE ep.election_id = :eid
          AND (ep.year_restriction IS NULL OR ep.year_restriction = '' OR ep.year_restriction = :year)
    ");
    $candidateStmt = $pdo->prepare('SELECT id FROM candidates WHERE id = :id AND position_id = :position_id');
    $insertVoteStmt = $pdo->prepare(
        'INSERT INTO votes (user_id, candidate_id, position_id) VALUES (:user_id, :candidate_id, :position_id)'
    );
    $submitStmt = $pdo->prepare(
        'INSERT INTO ballot_submissions (user_id, election_id, submitted_at) VALUES (:uid, :eid, CURRENT_TIMESTAMP)'
    );

    $votesToCast = [];

    foreach ($targetEids as $eid) {
        // Load the election and confirm it's actually open right now.
        $electionStmt->execute(['id' => $eid]);
        $election = $electionStmt->fetch();

        if (!$election) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Election not found.'], 404);
        }

        $start = new DateTime($election['start_date']);
        $end = new DateTime($election['end_date']);
        $isOpen = ($election['status'] === 'ongoing' && $now >= $start && $now <= $end);

        if (!$isOpen) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'One of these elections is no longer open.'], 400);
        }

        // Department scoping: a DSG election is only votable by students in that department.
        if ($election['type'] === 'DSG' && $election['department'] !== $voter['department']) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'You are not eligible to vote in this election.'], 403);
        }

        // Already voted in this specific election? Checked against
        // ballot_submissions — the per-election submission lock — because a
        // ballot may be submitted with zero picks (abstention), which leaves
        // no rows in `votes` for the old join-based check to find.
        $votedStmt->execute(['uid' => $userId, 'eid' => $eid]);
        if ($votedStmt->fetch()) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'You have already voted in one of these elections.'], 409);
        }

        // The exact positions this voter is allowed to see/vote for in this
        // election — same "limit to see" year-level rule as the dashboard.
        // winner_count is fetched here too, since it caps how many candidates
        // the student is allowed to pick for a multi-winner position below.
        $posStmt->execute(['eid' => $eid, 'year' => $voter['year_level']]);
        $allowedPositions = $posStmt->fetchAll();

        if (empty($allowedPositions)) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'No positions are available to you in one of these elections.'], 400);
        }

        foreach ($allowedPositions as $posRow) {
            $positionId = (int) $posRow['position_id'];
            $winnerCount = max(1, (int) $posRow['winner_count']);
            $fieldName = $isCombined
                ? 'e_' . $eid . '_p_' . $positionId
                : 'position_' . $positionId;

            // Single-winner positions submit one value (a radio group).
            // Multi-winner positions submit an array (checkbox group); PHP
            // collapses the "[]" suffix automatically, so $_POST[$fieldName]
            // already comes through as an array.
            $rawValue = $_POST[$fieldName] ?? null;
            if (is_array($rawValue)) {
                $selectedIds = array_map('intval', $rawValue);
            } elseif ($rawValue !== null && $rawValue !== '') {
                $selectedIds = [(int) $rawValue];
            } else {
                $selectedIds = [];
            }
            $selectedIds = array_values(array_unique(array_filter($selectedIds, function ($id) {
                return $id > 0;
            })));

            // Every position is skippable: no pick here is a legitimate
            // abstention for this position, not an error. An entirely empty
            // ballot still submits and locks the election (see the
            // ballot_submissions insert below).
            if (empty($selectedIds)) {
                continue;
            }

            if (count($selectedIds) > $winnerCount) {
                $pdo->rollBack();
                $label = $winnerCount === 1 ? '1 candidate' : $winnerCount . ' candidates';
                jsonResponse(['success' => false, 'message' => "You can select up to $label for this position."], 400);
            }

            foreach ($selectedIds as $candidateId) {
                // Confirm the candidate actually belongs to this position.
                $candidateStmt->execute(['id' => $candidateId, 'position_id' => $positionId]);
                if (!$candidateStmt->fetch()) {
                    $pdo->rollBack();
                    jsonResponse(['success' => false, 'message' => 'Invalid candidate selection.'], 400);
                }

                $votesToCast[] = ['candidate_id' => $candidateId, 'position_id' => $positionId];
            }
        }
    }

    // All selections validated — insert them all together.
    foreach ($votesToCast as $vote) {
        $insertVoteStmt->execute([
            'user_id' => $userId,
            'candidate_id' => $vote['candidate_id'],
            'position_id' => $vote['position_id'],
        ]);
    }

    // Legacy "has voted somewhere at least once" convenience flag — the ONLY
    // remaining reason it is written. No page redirects on it: login.php
    // always lands students on dashboard.php, and every DECISION in the app
    // reads the authoritative per-election lock in ballot_submissions
    // instead (userHasVotedInElection()). Kept for backward compatibility
    // only; safe to remove the write and the column together someday.
    $pdo->prepare('UPDATE users SET has_voted = 1 WHERE id = :id')->execute(['id' => $userId]);

    // Each election gets its own submission lock, written on EVERY
    // successful submit, empty ballot or not. This is what makes "already
    // voted" answerable when zero rows landed in `votes`, and the PK makes
    // a re-submit 409.
    $draftStmt = $pdo->prepare('DELETE FROM vote_drafts WHERE user_id = :uid AND election_id = :eid');
    foreach ($targetEids as $eid) {
        // The ballot is now finalized — any "save for later" draft for this
        // election is stale scratch data at this point.
        $draftStmt->execute(['uid' => $userId, 'eid' => $eid]);
        $submitStmt->execute(['uid' => $userId, 'eid' => $eid]);
    }

    $pdo->commit();

    $count = count($targetEids);
    jsonResponse([
        'success' => true,
        'message' => $isCombined
            ? 'Your combined ballot for ' . $count . ' election' . ($count === 1 ? '' : 's') . ' has been recorded. Thank you for voting!'
            : 'Your vote has been recorded. Thank you for voting!',
        'submitted_elections' => array_map('intval', $targetEids),
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Unique constraint violation on (user_id, candidate_id) — belt-and-braces
    // in case of a race condition despite the row lock above.
    error_log('Vote insertion failed: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Could not record your vote. You may have already voted.'], 409);
}
