<?php
    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/../config/db.php';

    startSecureSession();

    // Ballot preview (Batch C2): an admin can look at the EXACT ballot a
    // student would see - dashboard.php?page=vote&election_id=X&preview=1
    // - without needing a student account. Preview requires the ADMIN
    // session and only unlocks the vote branch; every other page still
    // demands a student login. The submit buttons render disabled here,
    // and vote.php rejects the preview session anyway (it checks
    // user_id, which an admin-only session does not have).
    $isPreview = isset($_GET['preview']) && (int) $_GET['preview'] === 1
        && ($_GET['page'] ?? '') === 'vote'
        && !empty($_SESSION['admin_id']);

    if (!$isPreview) {
        requireLogin();
    }

    $pdo = getDbConnection();
    syncElectionStatuses($pdo);

    // Get current user - including the password_reset_pending flag the
    // forced-change gate below reads (Batch B2). One query, no extra hit.
    if ($isPreview && empty($_SESSION['user_id'])) {
        // Previewing admin with no student account of their own: a
        // synthetic, department/year-less viewer. The visibility filters
        // below treat null as "no limit", so every election and every
        // position is on display.
        $user = ['id' => -1, 'name' => 'Preview', 'department' => null, 'year_level' => null, 'password_reset_pending' => 0];
    } else {
        $stmt = $pdo->prepare('SELECT id, name, department, year_level, password_reset_pending FROM users WHERE id = :id');
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user) {
            session_destroy();
            header('Location: index.php');
            exit;
        }
    }

    // Forced password change gate: an admin-issued temporary password
    // (admin/api/students.php resetPassword) sets the flag, and the
    // dashboard renders NOTHING until the student picks a real password.
    // change-password.php is the only way out - it validates the policy,
    // updates the hash, and clears the flag.
    if (!empty($user['password_reset_pending'])) {
        header('Location: change-password.php');
        exit;
    }

    // Which individual positions has this student already voted on, across
    // any election? (votes has UNIQUE(user_id, candidate_id), so a voter
    // gets at most one row per picked candidate — capped at the position's
    // winner_count by vote.php.) Used by the dashboard home to show a
    // real per-position checklist instead of just one all-or-nothing badge.

    /**
     * Whether this student has already cast a ballot in a SPECIFIC election.
     * (users.has_voted is only a legacy "voted at least once, anywhere" flag —
     * with multiple elections we need a per-election answer, and it must be
     * ballot_submissions rather than votes: a submitted ballot can be empty,
     * in which case votes holds nothing to count.)
     */
    function userHasVotedInElection(PDO $pdo, int $userId, int $electionId): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM ballot_submissions WHERE user_id = :uid AND election_id = :eid'
        );
        $stmt->execute(['uid' => $userId, 'eid' => $electionId]);
        return (bool) $stmt->fetch();
    }

    /**
     * Turns an election's raw status + schedule into what the UI shows.
     * "ongoing" from the admin panel is authoritative; the date range is a
     * safety net in case the admin forgets to close something on time.
     */
    function electionUiStatus(array $config, DateTime $now): array
    {
        $start = new DateTime($config['start_date']);
        $end = new DateTime($config['end_date']);
        $isOpen = ($config['status'] === 'ongoing' && $now >= $start && $now <= $end);

        if ($isOpen) {
            $statusText = 'Ongoing';
            $statusClass = '';
        } elseif ($config['status'] === 'paused') {
            $statusText = 'Paused';
            $statusClass = 'paused';
        } elseif (in_array($config['status'], ['draft', 'scheduled'], true) || $now < $start) {
            $statusText = 'Not started';
            $statusClass = 'scheduled';
        } else {
            // Covers 'closed' and 'archived' — archiving just tidies up the
            // admin's Elections list, it doesn't change how a finished
            // election looks to students.
            $statusText = 'Ended';
            $statusClass = 'closed';
        }

        return [$isOpen, $statusText, $statusClass, $start, $end];
    }

    /**
     * Turns a countdown into something a human wants to read. A raw
     * HH:MM:SS clock looks fine for "3 hours left" but turns into an
     * illegible "486:04:11" once an election runs for multiple weeks — so
     * this steps down to whichever unit is actually meaningful:
     *   >= 1 day   -> "20d 6h left"
     *   >= 1 hour  -> "4h 32m left"
     *   otherwise  -> "8m 15s left" (fine-grained since it's closing soon)
     */
    function formatCountdown(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'Closing now';
        }
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($days > 0) {
            return "{$days}d {$hours}h left";
        }
        if ($hours > 0) {
            return "{$hours}h {$minutes}m left";
        }
        return "{$minutes}m {$secs}s left";
    }

    /**
     * The status key used both for the card's top-border color and for
     * grouping/sorting the election list — Ongoing first, then Paused,
     * then Upcoming, then Ended, instead of raw chronological order (which
     * interleaves ongoing/paused/ended elections in a confusing mix).
     */
    function electionStatusKey(bool $isOpen, string $statusClass): string
    {
        return $isOpen ? 'ongoing' : ($statusClass ?: 'closed');
    }

    /**
     * Returns election IDs from $electionData ordered Ongoing -> Paused ->
     * Upcoming -> Ended, preserving each group's original (chronological)
     * relative order. Display-only — doesn't touch $electionData itself.
     */
    function sortedElectionIds(array $electionData, DateTime $now): array
    {
        $priority = ['ongoing' => 0, 'paused' => 1, 'scheduled' => 2, 'closed' => 3];
        $withKey = [];
        foreach ($electionData as $eid => $data) {
            [$isOpen, , $statusClass] = electionUiStatus($data['config'], $now);
            $withKey[] = ['eid' => $eid, 'rank' => $priority[electionStatusKey($isOpen, $statusClass)] ?? 99];
        }
        usort($withKey, fn($a, $b) => $a['rank'] <=> $b['rank']);
        return array_column($withKey, 'eid');
    }

    // --------------------------------
    // REAL ELECTION DATA — pulled from elections / election_positions /
    // positions / candidates. Only elections relevant to this student are
    // included:
    //   - SSG elections are visible to everyone.
    //   - DSG elections are visible only to students in that department.
    // Within a visible election, positions are further filtered by their
    // "limit to see" (year_restriction): a position limited to a specific
    // year level is only shown to students in that year level.
    // --------------------------------
    // Determine page first: the heavy per-election build below is only
    // ever consumed by Branch A (the ballot), so Branch B's SPA shell
    // must not pay for it on every load.
    $page = $_GET['page'] ?? 'home';
    $selectedElectionId = isset($_GET['election_id']) ? (int)$_GET['election_id'] : null;
    $isCombined = $page === 'vote' && !empty($_GET['combined']) && !$isPreview;

    if ($page === 'vote' && ($selectedElectionId || $isCombined)) {
    $electionData = [];
    $ssgLogo = getSiteSetting($pdo, 'ssg_logo');
    $departmentLogos = getDepartmentLogos($pdo);
    // 'draft' elections aren't visible to students yet; 'archived'
    // elections are past cycles the admin has explicitly tucked away —
    // neither belongs on a student's dashboard or ballot picker.
    // PREVIEW is the exception for drafts: letting the admin open a
    // draft's ballot is the whole point of checking it before scheduling.
    $rawElections = $pdo->query("
        SELECT e.*, sy.label AS target_school_year_label
        FROM elections e
        LEFT JOIN school_years sy ON sy.id = e.target_school_year_id
        WHERE e.status NOT IN (" . ($isPreview ? "'archived'" : "'draft', 'archived'") . ")
        ORDER BY e.start_date ASC
    ")->fetchAll();

    foreach ($rawElections as $e) {
        if (!$isPreview && $e['type'] === 'DSG' && $e['department'] !== $user['department']) {
            continue; // not this student's department (preview shows all)
        }

        $posStmt = $pdo->prepare('
            SELECT ep.id AS ep_id, ep.position_id, p.title, ep.winner_count, ep.candidate_limit, ep.year_restriction
            FROM election_positions ep
            JOIN positions p ON p.id = ep.position_id
            WHERE ep.election_id = :eid
            ORDER BY ep.id ASC
        ');
        $posStmt->execute(['eid' => $e['id']]);
        $allPositions = $posStmt->fetchAll();

        // "Limit to see" — hide positions restricted by a year level this
        // student isn't in. Preview shows every position regardless: the
        // admin needs the full roster on one screen.
        $positions = $isPreview ? $allPositions : array_values(array_filter($allPositions, function ($p) use ($user) {
            return empty($p['year_restriction']) || $p['year_restriction'] === $user['year_level'];
        }));

        $candidatesByPosition = [];
        if ($positions) {
            // NOTE: candidates don't carry their own election_id column — a
            // candidate's election is derived through position_id, because
            // the admin panel always creates a brand-new positions row for
            // every position added to an election (titles are never reused
            // across elections), so position_id alone is enough to scope
            // this query correctly.
            $candStmt = $pdo->prepare('
                SELECT c.id, c.position_id, c.name, c.photo, c.party, c.course, c.department,
                       c.candidate_year AS year_level, c.platform,
                       COUNT(v.id) AS vote_count
                FROM candidates c
                LEFT JOIN votes v ON v.candidate_id = c.id
                WHERE c.position_id = :pid
                GROUP BY c.id, c.position_id, c.name, c.photo, c.party, c.course, c.department, c.candidate_year, c.platform
                ORDER BY c.name ASC
            ');
            foreach ($positions as $p) {
                $candStmt->execute(['pid' => $p['position_id']]);
                $candidatesByPosition[$p['position_id']] = $candStmt->fetchAll();
            }
        }

        $electionData[$e['id']] = [
            'config' => [
                'id' => (int) $e['id'],
                'name' => $e['name'],
                'type' => $e['type'],
                'department' => $e['department'],
                'status' => $e['status'],
                'start_date' => $e['start_date'],
                'end_date' => $e['end_date'],
                'results_visibility' => $e['results_visibility'],
                'target_school_year_label' => $e['target_school_year_label'],
                'accent' => $e['type'] === 'SSG' ? '#3b82f6' : '#f97316',
                'icon' => electionLogoHtml($e['type'], $e['department'], $ssgLogo, $departmentLogos),
                'subtitle' => $e['type'] === 'SSG' ? 'Supreme Student Government' : 'Department Student Government' . ($e['department'] ? ' — ' . $e['department'] : ''),
            ],
            'positions' => $positions,
            'candidatesByPosition' => $candidatesByPosition,
            'totalPositions' => count($positions),
            // Preview always shows the OPEN ballot, not the "you already
            // voted" state - the admin needs to see the roster itself.
            'hasVoted' => $isPreview ? false : userHasVotedInElection($pdo, (int) $user['id'], (int) $e['id']),
        ];
    }
    } // end heavy-block gate (page=vote && election_id) — Branch B never runs it

    $csrfToken = csrfToken();

    // ====================================================================
    // BRANCH A — the actual ballot for one specific election. Deliberately
    // kept as its own full server-rendered page (not part of the SPA
    // below): this is the single highest-stakes code path in the app, it
    // already has its own smooth in-page step animations, and clicking
    // into it is a deliberate action rather than casual browsing.
    // Everything else (Home / Vote-picker / Candidates / Results) below
    // was converted to client-rendered panels specifically so switching
    // between THOSE no longer does a full page reload — this branch
    // intentionally still does.
    // ====================================================================
    if ($page === 'vote' && ($selectedElectionId || $isCombined)):
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Vote | School Elections</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= assetV('assets/style.css') ?>">
    <link rel="stylesheet" href="<?= assetV('assets/dashboard-app.css') ?>">
    <script>
    (function () {
        var saved = localStorage.getItem('studentTheme');
        var theme = saved === 'dark' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', theme);
    })();
    </script>
    </head>
    <body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="sidebar" id="sidebar">
        <div class="logo-area">
            <img src="assets/logo.png" alt="FICT Logo">
            <h2>School Elections</h2>
            <span class="logo-tagline">Student Portal</span>
        </div>
        <div class="student-profile-top">
            <div class="user-info">
                <div class="name"><?= h($user['name']) ?></div>
                <div class="role"><?= h($user['department'] ?: 'Student') ?><?= $user['year_level'] ? ' · ' . h($user['year_level']) : '' ?></div>
            </div>
        </div>
        <nav>
            <a href="dashboard.php?page=home"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5 12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5Z"/></svg></span><span>Dashboard</span></a>
            <a href="dashboard.php?page=vote" class="active"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2.5"/><path d="M7.5 12l3 3 6-6.5"/></svg></span><span>Vote Now</span></a>
            <a href="dashboard.php?page=candidates"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span><span>Candidates</span></a>
            <a href="dashboard.php?page=results"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></span><span>Results</span></a>
        </nav>
        <div class="theme-switcher">
            <span class="ts-label" id="themeLabel"><span class="nav-icon" id="themeIcon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg></span> Light Mode</span>
            <button type="button" class="theme-toggle" id="themeToggleBtn" role="switch" aria-checked="false" aria-label="Toggle dark mode"></button>
        </div>
        <div class="sidebar-bottom">
            <div class="update-year-link">
                <a href="update-account.php"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></span><span>Update Year Level</span></a>
            </div>
            <div class="logout-link">
                <a href="logout.php"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></span><span>Log Out</span></a>
            </div>
        </div>
    </div>

    <div class="main-content">
        <div class="page-header ballot-page-header">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">☰</button>
                <h1>Cast Your Vote</h1>
            </div>
            <span class="date"><?= date('l, F j, Y') ?></span>
        </div>

        <?php if ($isPreview): ?>
        <div class="preview-banner" style="background:#101828;color:#d9f99d;border:1px dashed #84cc16;border-radius:12px;text-align:center;padding:.65rem 1rem;margin:0 0 1.25rem;font-family:'IBM Plex Mono',monospace;font-size:.76rem;letter-spacing:.1em;text-transform:uppercase;">PREVIEW &mdash; ballot view only, voting is disabled</div>
        <?php endif; ?>

        <?php
            $now = new DateTime();
            $ballotGroups = [];
            $ballotError = '';
            $singleData = (!$isCombined && $selectedElectionId && isset($electionData[$selectedElectionId]))
                ? $electionData[$selectedElectionId]
                : null;

            if ($isCombined) {
                // One continuous ballot: every open election this student
                // can still vote in, SSG first then their DSG. Closed,
                // paused, draft, archived, and already-voted elections are
                // excluded, as are elections with no visible positions.
                foreach ($electionData as $data) {
                    [$isOpen] = electionUiStatus($data['config'], $now);
                    if (!$data['hasVoted'] && $isOpen && !empty($data['positions'])) {
                        $ballotGroups[] = $data;
                    }
                }
                usort($ballotGroups, function ($a, $b) {
                    $rank = ['SSG' => 0, 'DSG' => 1];
                    $ra = $rank[$a['config']['type']] ?? 2;
                    $rb = $rank[$b['config']['type']] ?? 2;
                    return $ra === $rb
                        ? strcmp((string) $a['config']['start_date'], (string) $b['config']['start_date'])
                        : $ra <=> $rb;
                });
                if (!$ballotGroups) {
                    $ballotError = 'You have no open elections left to vote in.';
                }
            } elseif (!$singleData) {
                $ballotError = 'Invalid election.';
            } elseif ($singleData['hasVoted']) {
                $ballotError = 'You have already voted in this election.';
            } else {
                [$isOpen] = electionUiStatus($singleData['config'], $now);
                if (!$isOpen && !$isPreview) {
                    $ballotError = 'This election is not currently open.';
                } elseif (empty($singleData['positions'])) {
                    $ballotError = 'No positions are available to you in this election yet.';
                } else {
                    $ballotGroups[] = $singleData;
                }
            }

            if ($ballotError !== '') {
                echo '<div class="alert alert-error">' . h($ballotError) . '</div>';
            } else {
                $totalPositions = 0;
                foreach ($ballotGroups as $group) {
                    $totalPositions += count($group['positions']);
                }
                $groupCount = count($ballotGroups);
                $singleConfig = $ballotGroups[0]['config'];
                $pageTitle = $isCombined ? 'Your Combined Ballot' : $singleConfig['name'];
        ?>
                    <div class="ballot-header">
                        <div class="ballot-shell">
                            <div class="ballot-header-top">
                                <div>
                                    <h2 class="ballot-title"><?= h($pageTitle) ?></h2>
                                    <?php if ($isCombined): ?>
                                        <p class="position-note" style="margin:.2rem 0 0;"><?= $groupCount ?> election<?= $groupCount === 1 ? '' : 's' ?> · one continuous ballot · one submission</p>
                                    <?php elseif (!empty($singleConfig['target_school_year_label'])): ?>
                                        <p class="position-note" style="margin:.2rem 0 0;">🎓 Election for school year <?= h($singleConfig['target_school_year_label']) ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="ballot-header-actions">
                                </div>
                            </div>
                            <div class="ballot-progress-track">
                                <div class="ballot-progress-fill" id="ballotProgressFill"></div>
                            </div>
                            <div class="ballot-progress-label" id="ballotProgressLabel">0 of <?= $totalPositions ?> positions answered</div>
                        </div>
                        </div>

                        <div class="ballot-shell">
                        <div id="statusMessage" class="alert" style="display:none;"></div>

                        <form id="voteForm" data-total-positions="<?= $totalPositions ?>"<?= $isCombined ? ' data-combined="1"' : ' data-election="' . (int) $selectedElectionId . '"' ?>>
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <?php if ($isCombined): ?>
                                <input type="hidden" name="combined" value="1">
                                <?php foreach ($ballotGroups as $group): ?>
                                    <input type="hidden" name="combined_elections[]" value="<?= (int) $group['config']['id'] ?>">
                                <?php endforeach; ?>
                            <?php else: ?>
                                <input type="hidden" name="election_id" value="<?= (int) $selectedElectionId ?>">
                            <?php endif; ?>

                            <?php
                                $positionNumber = 0;
                                foreach ($ballotGroups as $groupId => $group):
                                    $groupConfig = $group['config'];
                                    $groupEid = (int) $groupConfig['id'];
                                    $groupTotal = count($group['positions']);
                            ?>
                            <section class="ballot-election-block" data-election-id="<?= $groupEid ?>" data-election-type="<?= h($groupConfig['type']) ?>" data-election-name="<?= h($groupConfig['name']) ?>" data-group-total="<?= $groupTotal ?>">
                                <?php if ($isCombined): ?>
                                <div class="ballot-election-heading">
                                    <div class="ballot-election-icon"><?= $groupConfig['icon'] ?></div>
                                    <div class="ballot-election-info">
                                        <h3><?= h($groupConfig['name']) ?></h3>
                                        <p><?= h($groupConfig['subtitle']) ?><?= !empty($groupConfig['target_school_year_label']) ? ' · SY ' . h($groupConfig['target_school_year_label']) : '' ?></p>
                                    </div>
                                    <div class="ballot-group-progress" aria-hidden="true">
                                        <div class="ballot-group-track"><div class="ballot-group-fill" data-group-fill="<?= $groupEid ?>"></div></div>
                                        <span data-group-count="<?= $groupEid ?>">0/<?= $groupTotal ?></span>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php foreach ($group['positions'] as $position): $positionNumber++; ?>
                                    <?php
                                        $candidates = $group['candidatesByPosition'][$position['position_id']] ?? [];
                                        $winnerCount = max(1, (int) ($position['winner_count'] ?? 1));
                                        $isMulti = $winnerCount > 1;
                                        $inputType = $isMulti ? 'checkbox' : 'radio';
                                        $inputName = $isCombined
                                            ? 'e_' . $groupEid . '_p_' . (int) $position['position_id'] . ($isMulti ? '[]' : '')
                                            : 'position_' . (int) $position['position_id'] . ($isMulti ? '[]' : '');
                                    ?>
                                    <div class="ballot-step" data-step="<?= $positionNumber - 1 ?>" data-kind="position" data-election-id="<?= $groupEid ?>" data-position-title="<?= h($position['title']) ?>" data-max-select="<?= $winnerCount ?>">
                                        <div class="ballot-step-heading">
                                            <span class="ballot-step-eyebrow">
                                                <?php if ($isCombined): ?><?= h($groupConfig['type']) ?> · <?php endif; ?>Position <?= $positionNumber ?> of <?= $totalPositions ?>
                                            </span>
                                            <h3 class="ballot-position-title"><?= h($position['title']) ?></h3>
                                            <?php if (!empty($position['year_restriction'])): ?>
                                                <p class="position-note">Only visible to <?= h($position['year_restriction']) ?> students.</p>
                                            <?php endif; ?>
                                            <p class="ballot-instruction">
                                                <?php if ($isMulti): ?>
                                                    Select up to <?= $winnerCount ?> candidates, then continue.
                                                    <span class="ballot-select-count" data-count-for="<?= $positionNumber - 1 ?>">0 of <?= $winnerCount ?> selected</span>
                                                <?php else: ?>
                                                    Select one candidate, then continue.
                                                <?php endif; ?>
                                            </p>
                                        </div>

                                        <?php if (empty($candidates)): ?>
                                            <p class="muted">No candidates registered for this position.</p>
                                        <?php else: ?>
                                            <fieldset class="ballot-candidates" data-max-select="<?= $winnerCount ?>">
                                                <?php foreach ($candidates as $candidate):
                                                    $partyLabel = (!empty($candidate['party']) && $candidate['party'] !== 'No Party / Independent') ? $candidate['party'] : 'Independent';
                                                ?>
                                                    <label class="ballot-candidate">
                                                        <input type="<?= $inputType ?>" class="ballot-radio-input" name="<?= $inputName ?>" value="<?= (int) $candidate['id'] ?>" data-candidate-name="<?= h($candidate['name']) ?>">
                                                        <span class="ballot-bubble<?= $isMulti ? ' multi' : '' ?>" aria-hidden="true"><span class="ballot-bubble-fill"></span></span>
                                                        <span class="ballot-candidate-body">
                                                            <span class="ballot-candidate-name"><?= h($candidate['name']) ?></span>
                                                            <span class="ballot-candidate-party"><?= h($partyLabel) ?></span>
                                                            <span class="ballot-candidate-meta">
                                                                <?php if (!empty($candidate['department'])): ?><span><?= h($candidate['department']) ?></span><?php endif; ?>
                                                                <?php if (!empty($candidate['course'])): ?><span><?= h($candidate['course']) ?></span><?php endif; ?>
                                                                <?php if (!empty($candidate['year_level'])): ?><span><?= h($candidate['year_level']) ?></span><?php endif; ?>
                                                            </span>
                                                            <?php if (!empty($candidate['platform'])): ?>
                                                                <span class="ballot-candidate-platform"><strong>Platform:</strong> <?= h($candidate['platform']) ?></span>
                                                            <?php endif; ?>
                                                        </span>
                                                        <img class="candidate-avatar" loading="lazy" src="<?= !empty($candidate['photo']) ? h('photo.php?c=' . (int) $candidate['id']) : 'assets/default-avatar.svg' ?>" alt="">
                                                    </label>
                                                <?php endforeach; ?>
                                            </fieldset>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </section>
                            <?php endforeach; ?>

                            <div class="ballot-submit-block">
                                <p class="ballot-instruction">
                                    <?php if ($isCombined): ?>
                                        You're voting in <?= $groupCount ?> election<?= $groupCount === 1 ? '' : 's' ?> at once. Skipping a position is allowed; your whole ballot is submitted together and can't be changed afterward.
                                    <?php else: ?>
                                        Skipping any position is allowed — leave it blank if you'd rather not choose. Your selections can't be changed after you submit.
                                    <?php endif; ?>
                                </p>
                                <button type="submit" class="btn btn-primary" id="submitBtn"<?= $isPreview ? ' disabled' : '' ?>><?= $isCombined ? 'Submit My Ballot' : 'Submit My Vote' ?></button>
                            </div>
                        </form>
                    </div>

                    <div class="ballot-modal-overlay" id="ballotConfirmModal">
                        <div class="ballot-modal-card">
                            <h2>Submit your ballot?</h2>
                            <p>Your selections cannot be changed after submission.</p>
                            <div class="modal-actions">
                                <button type="button" class="btn btn-secondary" onclick="closeBallotConfirm()">Go Back</button>
                                <button type="button" class="btn btn-primary" id="confirmSubmitBtn"<?= $isPreview ? ' disabled' : '' ?> onclick="confirmBallotSubmit()">Cast My Vote</button>
                            </div>
                        </div>
                    </div>

                    <div class="ballot-modal-overlay" id="ballotSuccessModal">
                        <div class="ballot-modal-card">
                            <div class="ballot-modal-check">✔</div>
                            <h2>Vote Submitted!</h2>
                            <p id="ballotModalMessage">Thank you for participating in the election. Your voice has been counted.</p>
                            <div class="modal-actions">
                                <button type="button" class="btn btn-secondary" onclick="window.location.href='dashboard.php?page=results';">View Live Results</button>
                                <button type="button" class="btn btn-primary" onclick="closeBallotModal()">Return to Dashboard</button>
                            </div>
                        </div>
                    </div>
        <?php
            }
        ?>
    </div>

    <script>
    const hamburger = document.getElementById('hamburgerBtn');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    function toggleSidebar(open) {
        if (open === undefined) open = !sidebar.classList.contains('open');
        sidebar.classList.toggle('open', open);
        overlay.classList.toggle('active', open);
        document.body.style.overflow = open ? 'hidden' : '';
    }
    hamburger.addEventListener('click', () => toggleSidebar());
    overlay.addEventListener('click', () => toggleSidebar(false));
    window.addEventListener('resize', () => { if (window.innerWidth > 768) toggleSidebar(false); });

    const THEME_ICON_MOON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z"/></svg>';
    const THEME_ICON_SUN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>';
    const themeToggleBtn = document.getElementById('themeToggleBtn');
    const themeIcon = document.getElementById('themeIcon');
    const themeLabel = document.getElementById('themeLabel');
    function applyThemeUI(theme) {
        const isDark = theme === 'dark';
        if (themeToggleBtn) themeToggleBtn.setAttribute('aria-checked', String(isDark));
        if (themeIcon) themeIcon.innerHTML = isDark ? THEME_ICON_MOON : THEME_ICON_SUN;
        if (themeLabel && themeLabel.childNodes[1]) themeLabel.childNodes[1].textContent = isDark ? ' Dark Mode' : ' Light Mode';
    }
    function setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        try { localStorage.setItem('studentTheme', theme); } catch (e) {}
        applyThemeUI(theme);
    }
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            setTheme(current === 'dark' ? 'light' : 'dark');
        });
    }
    applyThemeUI(document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');

    const toastStack = document.createElement('div');
    toastStack.className = 'toast-stack';
    document.body.appendChild(toastStack);
    const TOAST_ICONS = {
        success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
        error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="16" x2="12" y2="11"/><circle cx="12" cy="7.5" r=".5" fill="currentColor" stroke="none"/></svg>',
        warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><circle cx="12" cy="16.5" r=".5" fill="currentColor" stroke="none"/><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>',
    };
    function showToast(message, type, opts) {
        type = type || 'info'; opts = opts || {};
        const duration = opts.duration !== undefined ? opts.duration : 4500;
        const el = document.createElement('div');
        el.className = 'toast ' + type;
        const msgSpan = document.createElement('span');
        msgSpan.className = 'toast-icon';
        msgSpan.innerHTML = TOAST_ICONS[type] || TOAST_ICONS.info;
        const textSpan = document.createElement('span');
        textSpan.className = 'toast-msg';
        textSpan.textContent = message;
        const closeBtn = document.createElement('button');
        closeBtn.type = 'button'; closeBtn.className = 'toast-close';
        closeBtn.setAttribute('aria-label', 'Dismiss'); closeBtn.textContent = '\u2715';
        el.appendChild(msgSpan); el.appendChild(textSpan); el.appendChild(closeBtn);
        const remove = () => { el.classList.add('leaving'); setTimeout(() => el.remove(), 200); };
        closeBtn.addEventListener('click', remove);
        toastStack.appendChild(el);
        if (duration) setTimeout(remove, duration);
        return el;
    }
    function showConfirmModal(opts) {
        opts = opts || {};
        const title = opts.title || 'Are you sure?';
        const message = opts.message || '';
        const confirmLabel = opts.confirmLabel || 'Confirm';
        const cancelLabel = opts.cancelLabel || 'Cancel';
        const danger = opts.danger !== undefined ? opts.danger : true;
        return new Promise(resolve => {
            const overlay2 = document.createElement('div');
            overlay2.className = 'modal-overlay';
            overlay2.innerHTML = `
                <div class="modal-box" role="alertdialog" aria-modal="true">
                    <div class="modal-icon ${danger ? 'danger' : 'neutral'}">
                        ${danger
                            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
                            : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'}
                    </div>
                    <h3></h3>
                    <p></p>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" data-choice="cancel"></button>
                        <button type="button" class="btn ${danger ? 'btn-danger' : 'btn-primary'}" data-choice="confirm"></button>
                    </div>
                </div>
            `;
            overlay2.querySelector('h3').textContent = title;
            overlay2.querySelector('p').textContent = message;
            overlay2.querySelector('[data-choice="cancel"]').textContent = cancelLabel;
            overlay2.querySelector('[data-choice="confirm"]').textContent = confirmLabel;
            function finish(result) { overlay2.remove(); document.removeEventListener('keydown', onKey); resolve(result); }
            function onKey(e) { if (e.key === 'Escape') finish(false); }
            overlay2.addEventListener('click', e => { if (e.target === overlay2) finish(false); });
            overlay2.querySelector('[data-choice="cancel"]').addEventListener('click', () => finish(false));
            overlay2.querySelector('[data-choice="confirm"]').addEventListener('click', () => finish(true));
            document.addEventListener('keydown', onKey);
            document.body.appendChild(overlay2);
            overlay2.querySelector('[data-choice="confirm"]').focus();
        });
    }

    (function () {
        const voteForm = document.getElementById('voteForm');
        if (!voteForm) return;

        function escHtml(str) {
            const d = document.createElement('div');
            d.textContent = str ?? '';
            return d.innerHTML;
        }

        // One continuous scroll: every position section lives in the DOM
        // at once; only the sticky progress header reports how far along
        // the student is. No per-step show/hide, no wizard navigation.
        const positionSteps = Array.from(voteForm.querySelectorAll('.ballot-step[data-kind="position"]'));

        const progressFill = document.getElementById('ballotProgressFill');
        const progressLabel = document.getElementById('ballotProgressLabel');
        const statusMessage = document.getElementById('statusMessage');
        const submitBtn = document.getElementById('submitBtn');
        const modal = document.getElementById('ballotSuccessModal');

        function updateSelectCount(step, fieldset) {
            const countEl = step.querySelector('.ballot-select-count');
            if (!countEl) return;
            const max = parseInt(fieldset.dataset.maxSelect || '1', 10);
            const checked = fieldset.querySelectorAll('.ballot-radio-input:checked').length;
            countEl.textContent = `${checked} of ${max} selected`;
        }

        function updateDimming(fieldset, step) {
            if (!fieldset) return;
            const labels = Array.from(fieldset.querySelectorAll('.ballot-candidate'));
            const inputs = labels.map(l => l.querySelector('.ballot-radio-input'));
            const isMulti = inputs.length > 0 && inputs[0].type === 'checkbox';
            const maxSelect = parseInt(fieldset.dataset.maxSelect || '1', 10);
            const checkedCount = inputs.filter(i => i.checked).length;
            labels.forEach((label, i) => {
                const input = inputs[i];
                label.classList.toggle('selected', input.checked);
                if (isMulti) {
                    const atLimit = checkedCount >= maxSelect;
                    label.classList.toggle('dimmed', atLimit && !input.checked);
                    input.disabled = atLimit && !input.checked;
                } else {
                    label.classList.toggle('dimmed', checkedCount > 0 && !input.checked);
                }
            });
            if (step) updateSelectCount(step, fieldset);
        }

        positionSteps.forEach(step => {
            const fieldset = step.querySelector('.ballot-candidates');
            if (!fieldset) return;
            fieldset.addEventListener('change', () => { updateDimming(fieldset, step); updateProgress(); });
            updateDimming(fieldset, step);
        });

        // Re-click to unvote: radios can't be unchecked natively, so on a
        // single-choice position, clicking the already-selected candidate
        // clears the selection (abstain) instead of doing nothing.
        // Multi-select positions use checkboxes, which uncheck natively.
        // The last-checked radio per group is remembered from the change
        // event; a click on that same radio unchecks it (with a synthetic
        // change event so dimming + progress stay in sync).
        const prevCheckedByGroup = {};
        voteForm.addEventListener('change', e => {
            if (e.target.type === 'radio') {
                prevCheckedByGroup[e.target.name] = e.target.checked ? e.target : null;
            }
        });
        voteForm.addEventListener('click', e => {
            const input = e.target.closest('.ballot-radio-input');
            if (!input || input.type !== 'radio') return;
            if (prevCheckedByGroup[input.name] === input && input.checked) {
                input.checked = false;
                prevCheckedByGroup[input.name] = null;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        // Progress = answered positions / total. A position counts as
        // answered once any candidate inside it is picked; an empty
        // position is a legitimate skip, never a blocker. In combined mode
        // the label follows the section in view (SSG → DSG) and each
        // election heading gets its own mini completion bar.
        let activeElectionBlock = voteForm.querySelector('.ballot-election-block');
        function updateProgress() {
            let answered = 0;
            positionSteps.forEach(step => {
                if (step.querySelector('.ballot-radio-input:checked')) answered++;
            });
            const total = positionSteps.length;
            if (progressFill) progressFill.style.width = (total ? (answered / total) * 100 : 0) + '%';

            voteForm.querySelectorAll('.ballot-election-block').forEach(block => {
                const blockSteps = Array.from(block.querySelectorAll('.ballot-step'));
                const blockAnswered = blockSteps.filter(step => step.querySelector('.ballot-radio-input:checked')).length;
                const blockTotal = blockSteps.length;
                const blockPct = blockTotal ? (blockAnswered / blockTotal) * 100 : 0;
                block.querySelectorAll('[data-group-fill]').forEach(fill => { fill.style.width = blockPct + '%'; });
                block.querySelectorAll('[data-group-count]').forEach(el => { el.textContent = blockAnswered + '/' + blockTotal; });
            });

            if (!progressLabel) return;
            if (voteForm.dataset.combined === '1' && activeElectionBlock) {
                const blockSteps = Array.from(activeElectionBlock.querySelectorAll('.ballot-step'));
                const blockAnswered = blockSteps.filter(step => step.querySelector('.ballot-radio-input:checked')).length;
                progressLabel.textContent = `${activeElectionBlock.dataset.electionType} · ${blockAnswered} of ${blockSteps.length} answered · ${answered} of ${total} overall`;
            } else {
                progressLabel.textContent = answered + ' of ' + total + ' positions answered';
            }
        }
        updateProgress();

        // Pin the sticky ballot header just BELOW the page header (which
        // also sticks at top:0) — measure rather than guess: the page
        // header wraps on mobile and its height differs.
        const pageHeaderEl = document.querySelector('.page-header');
        const ballotHeaderEl = document.querySelector('.ballot-header');
        function syncBallotStickyTop() {
            if (!ballotHeaderEl) return;
            const top = pageHeaderEl ? pageHeaderEl.offsetHeight : 0;
            ballotHeaderEl.style.setProperty('--ballot-sticky-top', top + 'px');
        }
        syncBallotStickyTop();
        window.addEventListener('resize', syncBallotStickyTop);

        // Combined ballot: the sticky label follows whichever election
        // section the student is currently reading (SSG → DSG), so the
        // "x of y" always refers to the part of the ballot on screen.
        if (voteForm.dataset.combined === '1') {
            const combinedBlocks = Array.from(voteForm.querySelectorAll('.ballot-election-block'));
            window.addEventListener('scroll', () => {
                const headerBottom = (ballotHeaderEl ? ballotHeaderEl.getBoundingClientRect().bottom : 0) + 12;
                // Scrollspy: the active section is the LAST one whose top
                // has passed the header line — i.e. the block containing
                // that line. ("Closest top" was wrong: it flipped the label
                // to the next section as soon as its top edge peeked into
                // view from below, while the current section still filled
                // the whole screen.)
                let best = combinedBlocks[0];
                combinedBlocks.forEach(block => {
                    if (block.getBoundingClientRect().top <= headerBottom) best = block;
                });
                if (best && best !== activeElectionBlock) {
                    activeElectionBlock = best;
                    updateProgress();
                }
            }, { passive: true });
        }

        window.closeBallotModal = function () { window.location.href = 'dashboard.php?page=home'; };

        const confirmModal = document.getElementById('ballotConfirmModal');
        window.openBallotConfirm = function () { if (confirmModal) confirmModal.classList.add('active'); };
        window.closeBallotConfirm = function () { if (confirmModal) confirmModal.classList.remove('active'); };

        async function performBallotSubmit() {
            if (!submitBtn) return;
            const isCombined = voteForm.dataset.combined === '1';
            const submitLabel = isCombined ? 'Submit My Ballot' : 'Submit My Vote';
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="btn-spinner"></span> Submitting...';
            const confirmBtn = document.getElementById('confirmSubmitBtn');
            if (confirmBtn) { confirmBtn.disabled = true; confirmBtn.innerHTML = '<span class="btn-spinner"></span> Submitting...'; }
            if (statusMessage) statusMessage.style.display = 'none';
            const formData = new FormData(voteForm);
            try {
                const response = await fetch('vote.php', { method: 'POST', body: formData });
                const data = await response.json();
                if (data.success) {
                    voteForm.querySelectorAll('input, button').forEach(el => el.disabled = true);
                    if (confirmModal) confirmModal.classList.remove('active');
                    if (modal) {
                        const msgEl = document.getElementById('ballotModalMessage');
                        if (msgEl && data.message) msgEl.textContent = data.message;
                        modal.classList.add('active');
                    }
                } else {
                    if (confirmModal) confirmModal.classList.remove('active');
                    showToast(data.message || 'Something went wrong. Please try again.', 'error');
                    submitBtn.disabled = false; submitBtn.textContent = submitLabel;
                    if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.textContent = 'Cast My Vote'; }
                }
            } catch (err) {
                if (confirmModal) confirmModal.classList.remove('active');
                showToast('Network error. Please try again.', 'error');
                submitBtn.disabled = false; submitBtn.textContent = submitLabel;
                if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.textContent = 'Cast My Vote'; }
            }
        }
        window.confirmBallotSubmit = function () { performBallotSubmit(); };

        voteForm.addEventListener('submit', function (e) {
            e.preventDefault();
            window.openBallotConfirm();
        });
    })();
    </script>
    </body>
    </html>
    <?php
    exit;
    endif;
    // ====================================================================
    // BRANCH B — the SPA shell. Loaded once; Home / Vote-picker /
    // Candidates / Results are all client-rendered panels from here on,
    // fetching data from dashboard-data.php instead of doing a full page
    // reload per section.
    // ====================================================================
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Dashboard | School Elections</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= assetV('assets/style.css') ?>">
    <link rel="stylesheet" href="<?= assetV('assets/dashboard-app.css') ?>">
    <script>
    // Applied synchronously, before the body renders, so the page never
    // flashes light-then-dark on load. Default is light unless the
    // student explicitly flipped the switch before (saved in
    // localStorage under its own key, separate from the admin panel's).
    (function () {
        var saved = localStorage.getItem('studentTheme');
        var theme = saved === 'dark' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', theme);
    })();
    </script>
    </head>
    <body>
    <!-- Sidebar overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="logo-area">
            <img src="assets/logo.png" alt="FICT Logo">
            <h2>School Elections</h2>
            <span class="logo-tagline">Student Portal</span>
        </div>
        <div class="student-profile-top">
            <div class="user-info">
                <div class="name"><?= h($user['name']) ?></div>
                <div class="role"><?= h($user['department'] ?: 'Student') ?><?= $user['year_level'] ? ' · ' . h($user['year_level']) : '' ?></div>
            </div>
        </div>
        <nav>
            <a data-panel="home" class="nav-link active" onclick="showPanel('home')"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5 12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5Z"/></svg></span><span>Dashboard</span></a>
            <a data-panel="vote" class="nav-link" onclick="showPanel('vote')"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2.5"/><path d="M7.5 12l3 3 6-6.5"/></svg></span><span>Vote Now</span></a>
            <a data-panel="candidates" class="nav-link" onclick="showPanel('candidates')"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span><span>Candidates</span></a>
            <a data-panel="results" class="nav-link" onclick="showPanel('results')"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></span><span>Results</span></a>
        </nav>
        <div class="theme-switcher">
            <span class="ts-label" id="themeLabel"><span class="nav-icon" id="themeIcon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg></span> Light Mode</span>
            <button type="button" class="theme-toggle" id="themeToggleBtn" role="switch" aria-checked="false" aria-label="Toggle dark mode"></button>
        </div>
        <div class="sidebar-bottom">
            <div class="update-year-link">
                <a href="update-account.php"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></span><span>Update Year Level</span></a>
            </div>
            <div class="logout-link">
                <a href="logout.php"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></span><span>Log Out</span></a>
            </div>
        </div>
    </div>

    <!-- Main content -->
    <div class="main-content">
        <div class="page-header">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">☰</button>
                <h1 id="pageHeaderTitle">Welcome, <?= h($user['name']) ?></h1>
            </div>
            <span class="date"><?= date('l, F j, Y') ?></span>
        </div>

        <div class="panel active" id="panel-home"></div>
        <div class="panel" id="panel-vote"></div>
        <div class="panel" id="panel-candidates"></div>
        <div class="panel" id="panel-results"></div>
    </div>

    <script>
    <?php echo "const CSRF_TOKEN = " . json_encode($csrfToken) . ";\n"; ?>
    const STUDENT_NAME = <?= json_encode($user['name']) ?>;
    const DASHBOARD_DATA_API = 'dashboard-data.php';

    // Mirror of defaultElectionLogoSvg() in includes/functions.php — the
    // generic institution glyph shown when an election has no uploaded
    // logo (or its icon_url 404s).
    const ELECTION_LOGO_FALLBACK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="elogo-placeholder"><path d="M3 21h18"/><path d="M5 21V9.5L12 4l7 5.5V21"/><path d="M9 21v-6h6v6"/></svg>';

    // config.icon_url (URL or null) -> ready-to-insert header markup.
    // The URL form keeps base64 logos out of the dashboard-data payload;
    // if the endpoint 404s at runtime the onerror swaps in the glyph.
    function electionIconHtml(config) {
        if (!config.icon_url) return ELECTION_LOGO_FALLBACK;
        return '<img src="' + escHtml(config.icon_url) + '" alt="" class="elogo-img" onerror="this.outerHTML=ELECTION_LOGO_FALLBACK">';
    }

    const hamburger = document.getElementById('hamburgerBtn');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    function toggleSidebar(open) {
        if (open === undefined) open = !sidebar.classList.contains('open');
        sidebar.classList.toggle('open', open);
        overlay.classList.toggle('active', open);
        document.body.style.overflow = open ? 'hidden' : '';
    }
    hamburger.addEventListener('click', () => toggleSidebar());
    overlay.addEventListener('click', () => toggleSidebar(false));
    window.addEventListener('resize', () => { if (window.innerWidth > 768) toggleSidebar(false); });

    // ------------------------------------------------------------------
    // THEME SWITCHER (dark / light)
    // ------------------------------------------------------------------
    const THEME_ICON_MOON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z"/></svg>';
    const THEME_ICON_SUN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>';
    const themeToggleBtn = document.getElementById('themeToggleBtn');
    const themeIcon = document.getElementById('themeIcon');
    const themeLabel = document.getElementById('themeLabel');

    function applyThemeUI(theme) {
        const isDark = theme === 'dark';
        if (themeToggleBtn) themeToggleBtn.setAttribute('aria-checked', String(isDark));
        if (themeIcon) themeIcon.innerHTML = isDark ? THEME_ICON_MOON : THEME_ICON_SUN;
        if (themeLabel && themeLabel.childNodes[1]) themeLabel.childNodes[1].textContent = isDark ? ' Dark Mode' : ' Light Mode';
    }
    function setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        try { localStorage.setItem('studentTheme', theme); } catch (e) {}
        applyThemeUI(theme);
    }
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            setTheme(current === 'dark' ? 'light' : 'dark');
        });
    }
    applyThemeUI(document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');

    // ------------------------------------------------------------------
    // TOASTS + CONFIRM MODAL (same pattern as the admin panel)
    // ------------------------------------------------------------------
    const toastStack = document.createElement('div');
    toastStack.className = 'toast-stack';
    document.body.appendChild(toastStack);

    const TOAST_ICONS = {
        success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
        error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="16" x2="12" y2="11"/><circle cx="12" cy="7.5" r=".5" fill="currentColor" stroke="none"/></svg>',
        warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><circle cx="12" cy="16.5" r=".5" fill="currentColor" stroke="none"/><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>',
    };

    function showToast(message, type, opts) {
        type = type || 'info';
        opts = opts || {};
        const duration = opts.duration !== undefined ? opts.duration : 4500;
        const el = document.createElement('div');
        el.className = 'toast ' + type;
        const msgSpan = document.createElement('span');
        msgSpan.className = 'toast-icon';
        msgSpan.innerHTML = TOAST_ICONS[type] || TOAST_ICONS.info;
        const textSpan = document.createElement('span');
        textSpan.className = 'toast-msg';
        textSpan.textContent = message;
        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'toast-close';
        closeBtn.setAttribute('aria-label', 'Dismiss');
        closeBtn.textContent = '\u2715';
        el.appendChild(msgSpan);
        el.appendChild(textSpan);
        el.appendChild(closeBtn);
        const remove = () => { el.classList.add('leaving'); setTimeout(() => el.remove(), 200); };
        closeBtn.addEventListener('click', remove);
        toastStack.appendChild(el);
        if (duration) setTimeout(remove, duration);
        return el;
    }

    function showConfirmModal(opts) {
        opts = opts || {};
        const title = opts.title || 'Are you sure?';
        const message = opts.message || '';
        const confirmLabel = opts.confirmLabel || 'Confirm';
        const cancelLabel = opts.cancelLabel || 'Cancel';
        const danger = opts.danger !== undefined ? opts.danger : true;
        return new Promise(resolve => {
            const overlay2 = document.createElement('div');
            overlay2.className = 'modal-overlay';
            overlay2.innerHTML = `
                <div class="modal-box" role="alertdialog" aria-modal="true">
                    <div class="modal-icon ${danger ? 'danger' : 'neutral'}">
                        ${danger
                            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
                            : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'}
                    </div>
                    <h3></h3>
                    <p></p>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" data-choice="cancel"></button>
                        <button type="button" class="btn ${danger ? 'btn-danger' : 'btn-primary'}" data-choice="confirm"></button>
                    </div>
                </div>
            `;
            overlay2.querySelector('h3').textContent = title;
            overlay2.querySelector('p').textContent = message;
            overlay2.querySelector('[data-choice="cancel"]').textContent = cancelLabel;
            overlay2.querySelector('[data-choice="confirm"]').textContent = confirmLabel;
            function finish(result) {
                overlay2.remove();
                document.removeEventListener('keydown', onKey);
                resolve(result);
            }
            function onKey(e) { if (e.key === 'Escape') finish(false); }
            overlay2.addEventListener('click', e => { if (e.target === overlay2) finish(false); });
            overlay2.querySelector('[data-choice="cancel"]').addEventListener('click', () => finish(false));
            overlay2.querySelector('[data-choice="confirm"]').addEventListener('click', () => finish(true));
            document.addEventListener('keydown', onKey);
            document.body.appendChild(overlay2);
            overlay2.querySelector('[data-choice="confirm"]').focus();
        });
    }

    function escHtml(str) {
        const d = document.createElement('div');
        d.textContent = str ?? '';
        return d.innerHTML;
    }

    // ------------------------------------------------------------------
    // JS PORTS of the PHP helper functions that used to run server-side
    // per page load — now run client-side against the one JSON payload
    // fetched from dashboard-data.php, so switching panels never needs a
    // full page reload.
    // ------------------------------------------------------------------
    function formatCountdown(seconds) {
        if (seconds <= 0) return 'Closing now';
        const days = Math.floor(seconds / 86400);
        const hours = Math.floor((seconds % 86400) / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = Math.floor(seconds % 60);
        if (days > 0) return `${days}d ${hours}h left`;
        if (hours > 0) return `${hours}h ${minutes}m left`;
        return `${minutes}m ${secs}s left`;
    }

    // Mirrors electionUiStatus() in PHP: turns raw status + schedule into
    // {isOpen, statusText, statusClass, start, end}.
    function electionUiStatus(config, now) {
        const start = new Date(config.start_date.replace(' ', 'T'));
        const end = new Date(config.end_date.replace(' ', 'T'));
        const isOpen = config.status === 'ongoing' && now >= start && now <= end;
        let statusText, statusClass;
        if (isOpen) { statusText = 'Ongoing'; statusClass = ''; }
        else if (config.status === 'paused') { statusText = 'Paused'; statusClass = 'paused'; }
        else if (['draft', 'scheduled'].includes(config.status) || now < start) { statusText = 'Not started'; statusClass = 'scheduled'; }
        else { statusText = 'Ended'; statusClass = 'closed'; }
        return { isOpen, statusText, statusClass, start, end };
    }

    function electionStatusKey(isOpen, statusClass) {
        return isOpen ? 'ongoing' : (statusClass || 'closed');
    }

    // Mirrors sortedElectionIds()/groups building in PHP: returns an
    // ordered array of {key, elections} groups — Ongoing -> Paused ->
    // Upcoming -> Ended — each preserving the original chronological order
    // within the group.
    function groupElections(elections, now) {
        const order = ['ongoing', 'paused', 'scheduled', 'closed'];
        const buckets = { ongoing: [], paused: [], scheduled: [], closed: [] };
        elections.forEach(e => {
            const { isOpen, statusClass } = electionUiStatus(e.config, now);
            const key = electionStatusKey(isOpen, statusClass);
            (buckets[key] || buckets.closed).push(e);
        });
        return order.filter(k => buckets[k].length).map(k => ({ key: k, elections: buckets[k] }));
    }

    const GROUP_LABELS = { ongoing: 'Ongoing', paused: 'Paused', scheduled: 'Upcoming', closed: 'Ended' };
    const GROUP_DOT_COLOR = { ongoing: '#22c55e', paused: '#f59e0b', scheduled: '#6366f1', closed: '#ef4444' };

    // ------------------------------------------------------------------
    // SKELETON LOADERS — shown immediately on panel switch, before the
    // (usually near-instant, since it's one cached fetch) data is ready.
    // ------------------------------------------------------------------
    function skeletonHome() {
        return `
            <div class="skeleton skel-card" style="height:220px;margin-bottom:1.75rem;"></div>
            <div class="skeleton skel-line w40" style="height:22px;margin:1.5rem 0 1rem;"></div>
            <div class="skeleton skel-card" style="height:200px;margin-bottom:1.5rem;"></div>
            <div class="skeleton skel-card" style="height:200px;"></div>
        `;
    }
    function skeletonCards() {
        return `
            <div class="skeleton skel-line w40" style="height:22px;margin-bottom:1rem;"></div>
            <div class="skeleton skel-card" style="height:220px;margin-bottom:1.5rem;"></div>
            <div class="skeleton skel-card" style="height:220px;"></div>
        `;
    }
    function skeletonCandidates() {
        return `
            <div class="skeleton skel-line w40" style="height:28px;margin-bottom:1.5rem;"></div>
            <div class="cand-grid">${'<div class="skeleton skel-card" style="height:280px;"></div>'.repeat(4)}</div>
        `;
    }

    // ------------------------------------------------------------------
    // DATA LAYER — fetched once, cached, reused by every panel. Cheap to
    // refetch (force=true) after something changes the underlying data,
    // e.g. returning from casting a vote.
    // ------------------------------------------------------------------
    let dashboardData = null;
    async function loadDashboardData(force) {
        if (!force && dashboardData) return dashboardData;
        const res = await fetch(DASHBOARD_DATA_API);
        if (!res.ok) throw new Error('Failed to load dashboard data');
        dashboardData = await res.json();
        return dashboardData;
    }

    // ------------------------------------------------------------------
    // NAV / PANEL SWITCHING
    // ------------------------------------------------------------------
    const panelTitles = { home: 'Welcome, ' + STUDENT_NAME, vote: 'Vote', candidates: 'Candidates', results: 'Live Results' };
    let currentPanel = 'home';

    function showPanel(name) {
        document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
        const panelEl = document.getElementById('panel-' + name);
        if (panelEl) panelEl.classList.add('active');
        document.querySelectorAll('.nav-link').forEach(a => a.classList.remove('active'));
        const navEl = document.querySelector('.nav-link[data-panel="' + name + '"]');
        if (navEl) navEl.classList.add('active');
        document.getElementById('pageHeaderTitle').textContent = panelTitles[name] || ('Welcome, ' + STUDENT_NAME);
        currentPanel = name;
        toggleSidebar(false);
        window.scrollTo(0, 0);

        if (name === 'home') renderHomePanel();
        if (name === 'vote') renderVotePanel();
        if (name === 'candidates') renderCandidatesPanel();
        if (name === 'results') renderResultsPanel(null); // plain nav click always shows all results, not a leftover filter from a "View Results" deep link
    }

    // ------------------------------------------------------------------
    // ELECTION CARD (shared by Home and the Vote picker — identical
    // markup/logic to what used to be duplicated twice in PHP)
    // ------------------------------------------------------------------
    function renderElectionCard(election, now, countdownIdPrefix) {
        const config = election.config;
        const { isOpen, statusText, statusClass, start, end } = electionUiStatus(config, now);
        const diff = isOpen ? (end - now) / 1000 : 0;
        const canVote = isOpen && !election.hasVoted && election.totalPositions > 0;
        const hasOpened = now >= start;

        let metaHtml = `<span class="ec-meta-item">📅 ${start.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} &ndash; ${end.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</span>`;
        if (isOpen) {
            metaHtml += `<span class="ec-meta-item ec-meta-countdown">⏱ <span id="${countdownIdPrefix}_${election.id}">${formatCountdown(diff)}</span></span>`;
        } else if (now < start) {
            metaHtml += `<span class="ec-meta-item">🚀 Starts ${start.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}</span>`;
        }
        if (config.target_school_year_label) {
            metaHtml += `<span class="ec-meta-item">🎓 SY ${escHtml(config.target_school_year_label)}</span>`;
        }

        let bodyHtml = '';
        if (election.totalPositions === 0) {
            bodyHtml = `<div class="ec-status-neutral">No positions have been added to this election yet.</div>`;
        } else if (hasOpened) {
            if (election.hasVoted) {
                bodyHtml = `<div class="ec-voted-banner">✅ <span>Thank you &mdash; your vote has been recorded.</span></div>`;
            } else if (config.status === 'paused') {
                bodyHtml += `<div class="ec-status-neutral">Voting is temporarily paused. Check back soon.</div>`;
            } else if (!isOpen && now >= end) {
                bodyHtml += `<div class="ec-status-neutral">You didn't cast a vote before this election closed.</div>`;
            }
        }

        let primaryLabel = null, primaryHref = null;
        if (canVote) {
            primaryLabel = '🗳 Vote Now';
            primaryHref = `dashboard.php?page=vote&election_id=${election.id}`;
        }

        return `
        <div class="election-card status-${electionStatusKey(isOpen, statusClass)}" style="--ec-accent: ${config.accent};">
            <div class="ec-header">
                <div class="ec-icon">${electionIconHtml(config)}</div>
                <div class="ec-heading">
                    <h2 class="election-title">${escHtml(config.name)}</h2>
                    <p class="ec-subtitle">${escHtml(config.subtitle)}</p>
                </div>
                <div class="status-large ${statusClass}"><span class="status-dot"></span>${statusText}</div>
            </div>
            <div class="ec-meta">${metaHtml}</div>
            <div class="ec-divider"></div>
            ${bodyHtml}
            <div class="card-actions">
                ${primaryLabel && primaryHref ? `<a href="${primaryHref}" class="btn btn-primary">${primaryLabel}</a>` : ''}
            </div>
        </div>`;
    }

    function startCountdowns(elections, now0, idPrefix) {
        elections.forEach(election => {
            const { isOpen, end } = electionUiStatus(election.config, now0);
            if (!isOpen) return;
            const el = document.getElementById(`${idPrefix}_${election.id}`);
            if (!el) return;
            const endTime = end.getTime();
            function update() {
                const diff = (endTime - Date.now()) / 1000;
                el.textContent = formatCountdown(diff);
            }
            update();
            setInterval(update, 1000);
        });
    }

    function renderElectionFilterBarAndGroups(elections, now, scope, countdownPrefix) {
        const groups = groupElections(elections, now);
        const showHeaders = groups.length > 1;

        let filterBar = '';
        if (showHeaders) {
            const total = elections.length;
            filterBar = `<div class="election-filter-bar" id="${scope}ElectionFilterBar">
                <button type="button" class="selected" onclick="filterElectionGroups('${scope}', 'all', this)">All <span class="fbadge">${total}</span></button>
                ${groups.map(g => `
                    <button type="button" onclick="filterElectionGroups('${scope}', '${g.key}', this)">
                        <span class="dot" style="background: ${GROUP_DOT_COLOR[g.key]};"></span>
                        ${GROUP_LABELS[g.key]} <span class="fbadge">${g.elections.length}</span>
                    </button>`).join('')}
            </div>`;
        }

        const groupsHtml = groups.map(g => `
            ${showHeaders ? `
            <div class="election-group-header" data-group="${g.key}">
                <span class="dot ${g.key}"></span>
                <span class="label">${GROUP_LABELS[g.key]}</span>
                <span class="count">${g.elections.length}</span>
            </div>` : ''}
            <div class="election-cards" data-group="${g.key}">
                ${g.elections.map(e => renderElectionCard(e, now, countdownPrefix)).join('')}
            </div>
        `).join('');

        return { filterBar, groupsHtml, groups };
    }

    function filterElectionGroups(scope, filterKey, btnEl) {
        const bar = document.getElementById(scope + 'ElectionFilterBar');
        if (bar) bar.querySelectorAll('button').forEach(b => b.classList.remove('selected'));
        if (btnEl) btnEl.classList.add('selected');
        const container = document.getElementById(scope + 'ElectionGroups');
        if (!container) return;
        container.querySelectorAll('[data-group]').forEach(el => {
            el.style.display = (filterKey === 'all' || el.dataset.group === filterKey) ? '' : 'none';
        });
    }

    function showCandidatesFor(electionId) {
        showPanel('candidates');
        setTimeout(() => {
            const target = document.getElementById('cand-sec-' + electionId);
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 50);
    }
    function showResultsFor(electionId) {
        document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
        document.getElementById('panel-results').classList.add('active');
        document.querySelectorAll('.nav-link').forEach(a => a.classList.remove('active'));
        const navEl = document.querySelector('.nav-link[data-panel="results"]');
        if (navEl) navEl.classList.add('active');
        document.getElementById('pageHeaderTitle').textContent = panelTitles.results;
        currentPanel = 'results';
        toggleSidebar(false);
        window.scrollTo(0, 0);
        renderResultsPanel(electionId); // scoped to just this election, with a "Back to all results" link — matches the old per-page ?election_id= deep link
    }

    // ------------------------------------------------------------------
    // HOME PANEL
    // ------------------------------------------------------------------
    async function renderHomePanel() {
        const el = document.getElementById('panel-home');
        el.innerHTML = skeletonHome();
        let data;
        try {
            data = await loadDashboardData();
        } catch (e) {
            el.innerHTML = '<div class="alert alert-error">Could not load your dashboard. Please refresh.</div>';
            return;
        }

        const now = new Date();
        const elections = data.elections;

        // Progress at the top of the dashboard — overall plus one bar each
        // for SSG and DSG so a student can see which half still needs them.
        const totalEligible = elections.length;
        const votedCount = elections.filter(e => e.hasVoted).length;
        const pct = totalEligible ? Math.round((votedCount / totalEligible) * 100) : 0;
        function typeProgress(type){
            const list = elections.filter(e => e.config.type === type);
            return { total: list.length, voted: list.filter(e => e.hasVoted).length };
        }
        const ssgProgress = typeProgress('SSG');
        const dsgProgress = typeProgress('DSG');
        function typeBar(label, progress){
            if (!progress.total) return '';
            const p = Math.round((progress.voted / progress.total) * 100);
            return `
            <div class="progress-split-item">
                <span class="progress-split-label">${label}</span>
                <div class="progress-split-track"><div class="progress-split-fill" style="width:${p}%;"></div></div>
                <span class="progress-split-count">${progress.voted}/${progress.total}</span>
            </div>`;
        }
        const progressHtml = totalEligible ? `
        <div class="progress-strip">
            <div class="progress-strip-label">
                <span>Voting progress</span>
                <span class="progress-strip-count">${votedCount} of ${totalEligible} voted</span>
            </div>
            <div class="progress-strip-track"><div class="progress-strip-fill" style="width:${pct}%;"></div></div>
            <div class="progress-split">
                ${typeBar('SSG', ssgProgress)}
                ${typeBar('DSG', dsgProgress)}
            </div>
        </div>` : '';
        const remaining = elections.filter(e => {
            if (e.hasVoted) return false;
            return electionUiStatus(e.config, now).isOpen;
        });

        let voteAllHtml;
        if (remaining.length > 0) {
            voteAllHtml = `
            <div class="vote-hero">
                <div class="vote-hero-eyebrow"><span class="status-large"><span class="status-dot"></span>Voting Open</span></div>
                <h2 class="vote-hero-title">Vote All Elections</h2>
                <p class="vote-hero-dates">${remaining.length} election${remaining.length === 1 ? '' : 's'} left to vote in — one continuous ballot, submitted once.</p>
                <div class="vote-hero-cta">
                    <a href="dashboard.php?page=vote&combined=1" id="startVoteAllBtn" class="btn btn-primary">🗳 Start Voting</a>
                </div>
            </div>`;
        } else if (elections.length > 0) {
            voteAllHtml = `
            <div class="vote-hero is-voted">
                <div class="vote-hero-eyebrow"><span class="status-large"><span class="status-dot"></span>All Done</span></div>
                <h2 class="vote-hero-title">You're all caught up ✓</h2>
                <p class="vote-hero-dates">There's nothing left to vote in right now. Results appear here as soon as they're released.</p>
            </div>`;
        } else {
            voteAllHtml = `
            <div class="vote-hero is-empty">
                <div class="vote-hero-empty-icon">🗳️</div>
                <div class="vote-hero-empty-title">No elections yet</div>
                <p class="vote-hero-empty-text">Check back soon — you'll see it here the moment voting opens.</p>
            </div>`;
        }

        let electionsSectionHtml = '<div class="alert alert-info">No elections have been set up for you yet. Check back soon.</div>';
        if (elections.length > 0) {
            const { filterBar, groupsHtml } = renderElectionFilterBarAndGroups(elections, now, 'home', 'countdown');
            electionsSectionHtml = `${filterBar}<div id="homeElectionGroups">${groupsHtml}</div>`;
        }

        el.innerHTML = `
            <div class="home-greeting"><p>Here's your election dashboard.</p></div>
            ${progressHtml}
            ${voteAllHtml}
            <h2 class="section-title">Your Elections</h2>
            ${electionsSectionHtml}
        `;
        startCountdowns(elections, now, 'countdown');
    }

    // ------------------------------------------------------------------
    // VOTE PICKER PANEL (the actual per-election ballot itself stays a
    // full page load at dashboard.php?page=vote&election_id=X — see the
    // "Vote Now" links above; this panel is just the "choose which
    // election" screen, same content as before, just client-rendered)
    // ------------------------------------------------------------------
    async function renderVotePanel() {
        const el = document.getElementById('panel-vote');
        el.innerHTML = skeletonCards();
        let data;
        try {
            data = await loadDashboardData();
        } catch (e) {
            el.innerHTML = '<div class="alert alert-error">Could not load elections. Please refresh.</div>';
            return;
        }

        const now = new Date();
        const elections = data.elections;

        if (!elections.length) {
            el.innerHTML = `<h2>Choose an Election to Vote In</h2><div class="alert alert-info">No elections are available to you right now.</div>`;
            return;
        }

        const { filterBar, groupsHtml } = renderElectionFilterBarAndGroups(elections, now, 'vote', 'countdown_vote');
        el.innerHTML = `
            <h2>Choose an Election to Vote In</h2>
            ${filterBar}
            <div id="voteElectionGroups">${groupsHtml}</div>
        `;
        startCountdowns(elections, now, 'countdown_vote');
    }

    // ------------------------------------------------------------------
    // CANDIDATES PANEL (magazine-style, grouped by election -> position)
    // ------------------------------------------------------------------
    async function renderCandidatesPanel() {
        const el = document.getElementById('panel-candidates');
        el.innerHTML = skeletonCandidates();
        window.__candMap = {};
        // Two-page candidate directory: SSG first, then DSG. A controller at
        // the top switches between them; each page is grouped by election
        // and position so a student only sees the election(s) they can vote.
        if (!window.__candTab) window.__candTab = 'ssg';
        const candTab = window.__candTab;
        let data;
        try {
            data = await loadDashboardData();
        } catch (e) {
            el.innerHTML = '<div class="alert alert-error">Could not load candidates. Please refresh.</div>';
            return;
        }

        const sections = [];
        data.elections.forEach(election => {
            if ((candTab === 'ssg' && election.config.type !== 'SSG') || (candTab === 'dsg' && election.config.type !== 'DSG')) return;
            const positions = [];
            election.positions.forEach(pos => {
                const cands = pos.candidates || [];
                if (!cands.length) return;
                positions.push({ position: pos, candidates: cands });
            });
            if (positions.length) sections.push({ election, positions });
        });

        if (!sections.length) {
            const emptyLabel = candTab === 'ssg' ? 'SSG (school-wide)' : 'DSG (your faculty)';
            el.innerHTML = `
                <div class="cand-page">
                    <div class="cand-header"><h2 class="cand-title">Meet the Candidates</h2><p class="muted">Browse everyone on the ballot, grouped by election and position.</p></div>
                    ${candTabControllerHtml()}
                    <div class="alert alert-info">No ${emptyLabel} candidates to show yet.</div>
                </div>`;
            return;
        }

        const toolbarHtml = `
            <div class="cand-toolbar">
                <div class="cand-search-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="candSearch" placeholder="Search candidates, platforms, courses…" oninput="filterCandidates()">
                </div>
            </div>`;

        const sectionsHtml = sections.map(sec => `
            <section class="cand-election-section" id="cand-sec-${sec.election.id}">
                <div class="cand-election-head">
                    <span class="cand-election-icon">${electionIconHtml(sec.election.config)}</span>
                    <div><h3>${escHtml(sec.election.config.name)}</h3><p>${escHtml(sec.election.config.subtitle)}${sec.election.config.target_school_year_label ? ' · 🎓 SY ' + escHtml(sec.election.config.target_school_year_label) : ''}</p></div>
                </div>
                ${sec.positions.map(posBlock => {
                    const position = posBlock.position;
                    return `
                    <div class="cand-position-block">
                        <h4 class="cand-position-title">
                            ${escHtml(position.title)}
                            ${position.year_restriction ? `<span class="cand-position-note">Visible to ${escHtml(position.year_restriction)}</span>` : ''}
                        </h4>
                        <div class="cand-grid">
                            ${posBlock.candidates.map(c => {
                                const party = (c.party && c.party !== 'No Party / Independent') ? c.party : '';
                                const photo = c.photo_url || 'assets/default-avatar.svg';
                                const platform = (c.platform || '').trim();
                                const searchMeta = `${c.department || ''} ${c.course || ''} ${c.year_level || ''} ${platform}`.toLowerCase().trim();
                                window.__candMap[c.id] = {
                                    ...c,
                                    party,
                                    photo,
                                    platform,
                                    positionTitle: position.title,
                                    electionName: sec.election.config.name,
                                };
                                return `
                                <article class="cand-card" data-name="${escHtml((c.name||'').toLowerCase())}" data-party="${escHtml(party)}" data-meta="${escHtml(searchMeta)}">
                                    <div class="cand-card-photo" role="button" tabindex="0" aria-label="View ${escHtml(c.name)}'s full profile" onclick="openCandidateModal(${c.id})" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openCandidateModal(${c.id});}">
                                        <img src="${escHtml(photo)}" alt="${escHtml(c.name)}" loading="lazy">
                                        <div class="cand-card-scrim">
                                            ${party ? `<span class="cand-card-kicker">${escHtml(party)}</span>` : ''}
                                            <span class="cand-card-name">${escHtml(c.name)}</span>
                                        </div>
                                    </div>
                                    <div class="cand-card-body">
                                        <div class="cand-card-meta">
                                            ${c.department ? `<span>${escHtml(c.department)}</span>` : ''}
                                            ${c.course ? `<span>${escHtml(c.course)}</span>` : ''}
                                            ${c.year_level ? `<span>${escHtml(c.year_level)}</span>` : ''}
                                        </div>
                                        ${platform ? `<p class="cand-card-platform">${escHtml(platform)}</p>` : `<p class="cand-card-platform muted" style="-webkit-line-clamp:unset;">No platform statement provided.</p>`}
                                        <button type="button" class="cand-card-toggle" onclick="openCandidateModal(${c.id})">View Full Profile</button>
                                    </div>
                                </article>`;
                            }).join('')}
                        </div>
                    </div>`;
                }).join('')}
            </section>
        `).join('');

        el.innerHTML = `
            <div class="cand-page">
                <div class="cand-header"><h2 class="cand-title">Meet the Candidates</h2><p class="muted">Browse everyone on the ballot, grouped by election and position.</p></div>
                ${candTabControllerHtml()}
                ${toolbarHtml}
                <div id="candNoResults" class="cand-no-results" style="display:none;">No candidates match your search.</div>
                ${sectionsHtml}
            </div>
        `;
    }

    function candTabControllerHtml(){
        const isSsg = window.__candTab === 'ssg';
        return `
        <div class="cand-tab-controller" role="tablist" aria-label="Candidate directory">
            <button type="button" class="cand-tab-btn ${isSsg ? 'active' : ''}" role="tab" ${isSsg ? 'aria-selected="true"' : ''} onclick="switchCandTab('ssg')">
                <span class="cand-tab-icon">🎓</span><span>SSG</span><span class="cand-tab-sub">General Elections</span>
            </button>
            <button type="button" class="cand-tab-btn ${!isSsg ? 'active' : ''}" role="tab" ${!isSsg ? 'aria-selected="true"' : ''} onclick="switchCandTab('dsg')">
                <span class="cand-tab-icon">🏛</span><span>DSG</span><span class="cand-tab-sub">Department Council</span>
            </button>
        </div>`;
    }
    function switchCandTab(tab){
        window.__candTab = tab;
        renderCandidatesPanel();
    }

    function filterCandidates() {
        const q = (document.getElementById('candSearch')?.value || '').trim().toLowerCase();
        let anyVisible = false;
        document.querySelectorAll('.cand-card').forEach(card => {
            const name = card.dataset.name || '';
            const meta = card.dataset.meta || '';
            const party = card.dataset.party || '';
            const matchesQuery = !q || name.includes(q) || meta.includes(q) || party.toLowerCase().includes(q);
            const show = matchesQuery;
            card.style.display = show ? '' : 'none';
            if (show) anyVisible = true;
        });
        document.querySelectorAll('.cand-position-block').forEach(block => {
            block.style.display = block.querySelector('.cand-card:not([style*="display: none"])') ? '' : 'none';
        });
        document.querySelectorAll('.cand-election-section').forEach(sec => {
            sec.style.display = sec.querySelector('.cand-position-block:not([style*="display: none"])') ? '' : 'none';
        });
        const noResults = document.getElementById('candNoResults');
        if (noResults) noResults.style.display = anyVisible ? 'none' : 'block';
    }

    // Full-profile modal: photo + identifying info on the left, platform
    // statement filling the center-to-right. Triggered by clicking either
    // the candidate's photo or the "View Full Profile" button.
    function openCandidateModal(candidateId) {
        const c = (window.__candMap || {})[candidateId];
        if (!c) return;

        closeCandidateModal(); // guard against a stray leftover instance

        const overlay = document.createElement('div');
        overlay.className = 'cand-modal-overlay';
        overlay.id = 'candModalOverlay';
        overlay.innerHTML = `
            <div class="cand-modal" role="dialog" aria-modal="true" aria-label="${escHtml(c.name)}'s profile">
                <button type="button" class="cand-modal-close" aria-label="Close" onclick="closeCandidateModal()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
                <div class="cand-modal-left">
                    <div class="cand-modal-photo"><img src="${escHtml(c.photo_url || 'assets/default-avatar.svg')}" alt="${escHtml(c.name)}"></div>
                    <div class="cand-modal-info">
                        <h2 class="cand-modal-name">${escHtml(c.name)}</h2>
                        ${c.party ? `<span class="cand-modal-party">${escHtml(c.party)}</span>` : ''}
                        <div class="cand-modal-meta-list">
                            <div class="cand-modal-meta-row"><span class="cand-modal-meta-label">Running for</span><span>${escHtml(c.positionTitle)}</span></div>
                            <div class="cand-modal-meta-row"><span class="cand-modal-meta-label">Election</span><span>${escHtml(c.electionName)}</span></div>
                            ${c.department ? `<div class="cand-modal-meta-row"><span class="cand-modal-meta-label">Department</span><span>${escHtml(c.department)}</span></div>` : ''}
                            ${c.course ? `<div class="cand-modal-meta-row"><span class="cand-modal-meta-label">Course</span><span>${escHtml(c.course)}</span></div>` : ''}
                            ${c.year_level ? `<div class="cand-modal-meta-row"><span class="cand-modal-meta-label">Year Level</span><span>${escHtml(c.year_level)}</span></div>` : ''}
                        </div>
                    </div>
                </div>
                <div class="cand-modal-right">
                    <h3 class="cand-modal-platform-label">Platform</h3>
                    <div class="cand-modal-platform-text">${c.platform ? escHtml(c.platform) : '<span class="muted">No platform statement provided.</span>'}</div>
                </div>
            </div>`;

        function onKey(e) { if (e.key === 'Escape') closeCandidateModal(); }
        overlay.addEventListener('click', e => { if (e.target === overlay) closeCandidateModal(); });
        overlay._onKey = onKey;
        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';
        overlay.querySelector('.cand-modal-close').focus();
    }

    function closeCandidateModal() {
        const overlay = document.getElementById('candModalOverlay');
        if (!overlay) return;
        if (overlay._onKey) document.removeEventListener('keydown', overlay._onKey);
        overlay.remove();
        document.body.style.overflow = '';
    }

    // ------------------------------------------------------------------
    // RESULTS PANEL
    // ------------------------------------------------------------------
    let resultsFilterElectionId = null;

    // SSG/DSG switcher for Live Results — the same controller the
    // Candidates directory uses, so both panels read as one system. The
    // pick survives the 15-second auto-refresh (window.__resultsTab).
    function resultsTabControllerHtml(){
        const isSsg = window.__resultsTab !== 'dsg';
        return `
        <div class="cand-tab-controller" role="tablist" aria-label="Live results">
            <button type="button" class="cand-tab-btn ${isSsg ? 'active' : ''}" role="tab" ${isSsg ? 'aria-selected="true"' : ''} onclick="switchResultsTab('ssg')">
                <span class="cand-tab-icon">🎓</span><span>SSG</span><span class="cand-tab-sub">General Elections</span>
            </button>
            <button type="button" class="cand-tab-btn ${!isSsg ? 'active' : ''}" role="tab" ${!isSsg ? 'aria-selected="true"' : ''} onclick="switchResultsTab('dsg')">
                <span class="cand-tab-icon">🏛</span><span>DSG</span><span class="cand-tab-sub">Department Council</span>
            </button>
        </div>`;
    }
    function switchResultsTab(tab){
        window.__resultsTab = tab;
        resultsFilterElectionId = null; // a tab switch always shows that council's full list
        renderResultsPanel();
    }

    async function renderResultsPanel(filterElectionId) {
        if (filterElectionId !== undefined) resultsFilterElectionId = filterElectionId;
        const el = document.getElementById('panel-results');
        el.innerHTML = skeletonCards();
        let data;
        try {
            data = await loadDashboardData();
        } catch (e) {
            el.innerHTML = '<div class="alert alert-error">Could not load results. Please refresh.</div>';
            return;
        }

        let scopedElections = data.elections;
        let notFound = false;
        if (resultsFilterElectionId) {
            scopedElections = data.elections.filter(e => e.id === resultsFilterElectionId);
            if (!scopedElections.length) notFound = true;
            // Deep-linked from a ballot "View Results" — line the tab up with
            // that election so "Back to all results" lands on the right one.
            else window.__resultsTab = scopedElections[0].config.type === 'DSG' ? 'dsg' : 'ssg';
        } else {
            // Same SSG/DSG panels as the Candidates directory.
            const wantedType = window.__resultsTab === 'dsg' ? 'DSG' : 'SSG';
            scopedElections = scopedElections.filter(e => e.config.type === wantedType);
        }

        // Split what the admin lets students see from what stays hidden.
        // Hidden elections are NOT silently dropped — each one says why it
        // can't be shown: "never shown" or "only after the election ends".
        const visibleElections = [];
        const hiddenElections = [];
        scopedElections.forEach(election => {
            const config = election.config;
            const visible = config.results_visibility === 'always'
                || (config.results_visibility === 'after' && ['closed', 'archived'].includes(config.status));
            if (visible && election.positions.length > 0) {
                visibleElections.push(election);
            } else if (config.results_visibility === 'never') {
                hiddenElections.push({ name: config.name, reason: 'never' });
            } else if (config.results_visibility === 'after') {
                hiddenElections.push({ name: config.name, reason: 'after' });
            }
        });

        const tabControllerHtml = resultsFilterElectionId ? '' : resultsTabControllerHtml();
        const backLink = resultsFilterElectionId
            ? `<button type="button" class="btn btn-secondary" style="margin-bottom:1.25rem;display:inline-flex;" onclick="renderResultsPanel(null)">&larr; Back to all results</button>`
            : '';

        const hiddenHtml = hiddenElections.map(h => `
            <div class="alert alert-info"><strong>${escHtml(h.name)}</strong> — ${
                h.reason === 'never'
                    ? 'the admin has hidden these results; they will <strong>never</strong> be shown.'
                    : 'results are hidden for now and will only appear <strong>after the election ends</strong>.'
            }</div>`).join('');

        if (notFound) {
            el.innerHTML = `<h2>Live Results</h2><p class="muted">Results update automatically every 15 seconds.</p>${backLink}<div class="alert alert-error">That election could not be found.</div>`;
            return;
        }
        if (!visibleElections.length && !hiddenHtml) {
            const council = window.__resultsTab === 'dsg' ? 'DSG' : 'SSG';
            el.innerHTML = `<h2>Live Results</h2><p class="muted">Results update automatically.</p>${tabControllerHtml}${backLink}<div class="alert alert-info">No ${council} election${window.__resultsTab === 'dsg' ? ' for your department ' : ' '}has results yet.</div>`;
            return;
        }

        const html = visibleElections.map(election => {
            const positionsHtml = election.positions.map(position => {
                const candidates = position.candidates || [];
                const totalVotes = candidates.reduce((sum, c) => sum + (c.vote_count || 0), 0);
                const rowsHtml = candidates.length ? candidates.map(c => {
                    const pct = totalVotes > 0 ? Math.round((c.vote_count / totalVotes) * 1000) / 10 : 0;
                    return `
                    <div class="result-row">
                        <div class="result-header">
                            <span class="candidate-name">${escHtml(c.name)}</span>
                            <span class="vote-count">${c.vote_count} vote${c.vote_count === 1 ? '' : 's'} (${pct}%)</span>
                        </div>
                        <div class="progress-bar-track"><div class="progress-bar-fill" style="width: ${pct}%;"></div></div>
                    </div>`;
                }).join('') : '<p class="muted">No candidates.</p>';
                return `
                <div class="results-block">
                    <h3>${escHtml(position.title)}</h3>
                    ${rowsHtml}
                    <p class="muted">Total votes: ${totalVotes}</p>
                </div>`;
            }).join('');
            return `<h2>${escHtml(election.config.name)}${election.config.target_school_year_label ? ` <span style="font-size:.7rem;font-weight:700;color:#92400e;background:#fef3c7;padding:.2rem .6rem;border-radius:999px;vertical-align:middle;">🎓 SY ${escHtml(election.config.target_school_year_label)}</span>` : ''}</h2>${positionsHtml}<hr>`;
        }).join('');

        el.innerHTML = `<h2>Live Results</h2><p class="muted">Results update automatically every 15 seconds.</p>${tabControllerHtml}${backLink}${hiddenHtml}${html}`;

        // Refresh vote counts on an interval, matching the old page's
        // meta-refresh behavior — but now it's just a data refetch + partial
        // re-render instead of a full page reload.
        clearInterval(window.__resultsRefreshTimer);
        window.__resultsRefreshTimer = setInterval(() => {
            if (currentPanel === 'results') renderResultsPanel();
        }, 15000);
    }

    // ------------------------------------------------------------------
    // INIT — figure out which panel to open based on ?page= in the URL
    // (so a bookmarked/shared link like dashboard.php?page=results still
    // lands on the right panel), then render it.
    // ------------------------------------------------------------------
    (function init() {
        const params = new URLSearchParams(window.location.search);
        const requestedPage = params.get('page');
        const validPanels = ['home', 'vote', 'candidates', 'results'];
        const initial = validPanels.includes(requestedPage) ? requestedPage : 'home';
        showPanel(initial);
    })();
    </script>
    </body>
    </html>
