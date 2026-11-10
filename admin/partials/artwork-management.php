<?php
/**
 * Admin: Artwork Management - approve / reject submissions (section only - no <html>, header or footer).
 *
 * Usage, inside <main> of admin/manage.php:
 *     <?php include __DIR__ . '/partials/artwork-management.php'; ?>
 *
 * Shows a compact one-line-per-artwork log. Only the artwork title is a link: it opens the
 * private admin preview (admin/view-artwork.php). Approve / Reject post to
 * admin/artwork-review-action.php, which does the actual change in one transaction.
 *
 * Reuses: db(), e(), csrf_token()            (includes/db.php)
 *         art_session_user_id()               (includes/artwork_helpers.php)
 *         helpers in includes/artwork_review_helpers.php (all prefixed am_)
 *
 * All variables start with $am so they cannot clash with manage.php or the other sections.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/artwork_review_helpers.php';

// ---- Administrators only (checked against the database) --------------------
try {
    $amPdo   = db();
    $amAdmin = am_current_admin($amPdo);
} catch (Throwable $amError) {
    error_log('[artwork-management] ' . $amError->getMessage());
    // The admin check itself failed (for example users.deleted_at is missing), so fail closed.
    // manage.php has already limited this page to administrators; say what is wrong.
    $amAdmin = null;
    if (($_SESSION['role'] ?? '') === 'admin') {
        ?>
        <link rel="stylesheet" href="../css/artwork-management.css">
        <section class="am-section" id="artwork-management" aria-labelledby="am-title">
            <header class="am-header">
                <h2 class="am-title" id="am-title">Artwork Management</h2>
            </header>
            <p class="am-notice am-notice--error" role="alert">
                Artwork management is unavailable because the database needs to be updated.
                Run <code>database/migrations/2026-11-05_artwork_review_support.sql</code> and reload this page.
            </p>
        </section>
        <hr class="am-divider">
        <?php
    }
    return;
}
if ($amAdmin === null) {
    return;   // not an active, non-deleted administrator: output nothing
}

// ---- Request state: filter, search, page ------------------------------------
$amView   = am_view_params($_GET);
$amFlash  = am_flash_pull();                 // message left by artwork-review-action.php
$amToken  = csrf_token();
$amPage   = $amView['page'];

$amRows       = [];
$amCounts     = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
$amTotal      = 0;
$amPages      = 1;
$amLoadFailed = false;
$amMissing    = [];

try {
    $amMissing = am_schema_missing($amPdo);

    if (!$amMissing) {
        // Totals per status for the filter tabs (independent of the search text).
        foreach ($amPdo->query('SELECT status, COUNT(*) AS n FROM artworks GROUP BY status')->fetchAll() as $amCountRow) {
            if (isset($amCounts[(string) $amCountRow['status']])) {
                $amCounts[(string) $amCountRow['status']] = (int) $amCountRow['n'];
            }
        }

        // WHERE clause: fixed SQL text only; every value is a bound parameter.
        $amWhere  = [];
        $amParams = [];   // list of [value, PDO type]

        if ($amView['status'] !== 'all') {
            $amWhere[]  = 'a.status = ?';
            $amParams[] = [$amView['status'], PDO::PARAM_STR];
        }
        if ($amView['q'] !== '') {
            $amLike = '%' . addcslashes($amView['q'], '\\%_') . '%';
            $amSearch = '(a.title LIKE ? OR u.username LIKE ? OR u.email LIKE ?';
            $amParams[] = [$amLike, PDO::PARAM_STR];
            $amParams[] = [$amLike, PDO::PARAM_STR];
            $amParams[] = [$amLike, PDO::PARAM_STR];
            if (ctype_digit($amView['q']) && strlen($amView['q']) <= 9) {
                $amSearch  .= ' OR a.id = ?';
                $amParams[] = [(int) $amView['q'], PDO::PARAM_INT];
            }
            $amWhere[] = $amSearch . ')';
        }
        $amWhereSql = $amWhere ? 'WHERE ' . implode(' AND ', $amWhere) : '';

        $amBind = static function (PDOStatement $amSt, array $amList): void {
            foreach ($amList as $amIndex => [$amValue, $amType]) {
                $amSt->bindValue($amIndex + 1, $amValue, $amType);
            }
        };

        $amSt = $amPdo->prepare(
            'SELECT COUNT(*) FROM artworks a JOIN users u ON u.id = a.user_id ' . $amWhereSql
        );
        $amBind($amSt, $amParams);
        $amSt->execute();
        $amTotal = (int) $amSt->fetchColumn();

        $amPages = max(1, (int) ceil($amTotal / AM_PAGE_SIZE));
        $amPage  = min($amPage, $amPages);
        $amView['page'] = $amPage;

        // Pending first (oldest first, like a queue), then everything else newest first.
        $amSt = $amPdo->prepare(
            "SELECT a.id, a.title, a.status, a.created_at, a.reviewed_at, a.reviewed_by, a.admin_feedback,
                    u.username AS artist_username, u.email AS artist_email,
                    r.username AS reviewer_username,
                    (SELECT h.reviewer_username_snapshot FROM artwork_review_history h
                      WHERE h.artwork_id = a.id ORDER BY h.reviewed_at DESC, h.id DESC LIMIT 1) AS history_reviewer,
                    (SELECT h.reviewed_at FROM artwork_review_history h
                      WHERE h.artwork_id = a.id ORDER BY h.reviewed_at DESC, h.id DESC LIMIT 1) AS history_reviewed_at
               FROM artworks a
               JOIN users u ON u.id = a.user_id
               LEFT JOIN users r ON r.id = a.reviewed_by
               {$amWhereSql}
              ORDER BY (a.status = 'pending') DESC,
                       CASE WHEN a.status = 'pending' THEN a.created_at END ASC,
                       a.created_at DESC,
                       a.id DESC
              LIMIT ? OFFSET ?"
        );
        $amBind($amSt, $amParams);
        $amSt->bindValue(count($amParams) + 1, AM_PAGE_SIZE, PDO::PARAM_INT);
        $amSt->bindValue(count($amParams) + 2, ($amPage - 1) * AM_PAGE_SIZE, PDO::PARAM_INT);
        $amSt->execute();
        $amRows = $amSt->fetchAll();
    }
} catch (Throwable $amError) {
    error_log('[artwork-management] ' . $amError->getMessage());
    $amLoadFailed = true;
}

// ---- Small helpers for this template ---------------------------------------
$amPageBase = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'manage.php'));

/** Link to a list view that keeps any other query parameters of the page (other sections). */
$amLink = static function (array $amNew) use ($amView, $amPageBase): string {
    $amKeep  = array_diff_key($_GET, ['am_status' => 1, 'am_q' => 1, 'am_page' => 1]);
    $amMerge = array_merge($amView, $amNew);
    $amQuery = http_build_query($amKeep, '', '&', PHP_QUERY_RFC3986);
    $amOwn   = am_view_query($amMerge);
    $amAll   = implode('&', array_filter([$amQuery, $amOwn], static fn (string $amPart): bool => $amPart !== ''));
    return $amPageBase . ($amAll !== '' ? '?' . $amAll : '') . '#' . AM_SECTION_ID;
};

$amTabs = [
    'all'      => ['All',      $amCounts['pending'] + $amCounts['approved'] + $amCounts['rejected']],
    'pending'  => ['Pending',  $amCounts['pending']],
    'approved' => ['Approved', $amCounts['approved']],
    'rejected' => ['Rejected', $amCounts['rejected']],
];
?>
<link rel="stylesheet" href="../css/artwork-management.css">
<?php if (empty($GLOBALS['ss_assets_loaded'])): $GLOBALS['ss_assets_loaded'] = true; ?>
<link rel="stylesheet" href="../assets/css/search-suggestions.css">
<script src="../js/search-suggestions.js" defer></script>
<?php endif; ?>

<section class="am-section" id="artwork-management" aria-labelledby="am-title">

    <header class="am-header">
        <h2 class="am-title" id="am-title">Artwork Management</h2>
        <p class="am-intro">Review artwork submissions. Pending items are listed first, oldest first.</p>
    </header>

    <?php if ($amFlash): ?>
        <p
            class="am-notice am-notice--<?= e($amFlash['type']) ?>"
            role="<?= $amFlash['type'] === 'error' ? 'alert' : 'status' ?>"
        >
            <?= e($amFlash['message']) ?>
        </p>
    <?php endif; ?>

    <?php if ($amLoadFailed): ?>
        <p class="am-notice am-notice--error" role="alert">
            The artwork list could not be loaded. Please refresh the page in a moment.
        </p>
    <?php elseif ($amMissing): ?>
        <p class="am-notice am-notice--error" role="alert">
            The database needs to be updated before artworks can be reviewed
            (missing: <?= e(implode(', ', $amMissing)) ?>).
            Run <code>database/migrations/2026-11-05_artwork_review_support.sql</code> and reload this page.
        </p>
    <?php else: ?>

        <div class="am-toolbar">
            <nav class="am-tabs" aria-label="Filter artwork by status">
                <?php foreach ($amTabs as $amKey => [$amLabel, $amCount]): ?>
                    <a
                        class="am-tab<?= $amView['status'] === $amKey ? ' am-tab--active' : '' ?>"
                        href="<?= e($amLink(['status' => $amKey, 'page' => 1])) ?>"
                        <?= $amView['status'] === $amKey ? 'aria-current="true"' : '' ?>
                    >
                        <?= e($amLabel) ?> <span class="am-tab__count"><?= (int) $amCount ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <form class="am-search" method="get" action="<?= e($amPageBase) ?>#artwork-management" role="search">
                <?php foreach (array_diff_key($_GET, ['am_status' => 1, 'am_q' => 1, 'am_page' => 1]) as $amOtherKey => $amOtherValue): ?>
                    <?php if (is_string($amOtherKey) && is_scalar($amOtherValue)): ?>
                        <input type="hidden" name="<?= e($amOtherKey) ?>" value="<?= e((string) $amOtherValue) ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <input type="hidden" name="am_status" value="<?= e($amView['status']) ?>">
                <label class="am-sr-only" for="am-q">Search by artwork title, artist username or email</label>
                <input
                    class="am-input"
                    id="am-q"
                    type="search"
                    name="am_q"
                    value="<?= e($amView['q']) ?>"
                    maxlength="<?= AM_SEARCH_MAX ?>"
                    placeholder="Search title, artist, email or ID"
                    autocomplete="off"
                    data-ss-endpoint="search_suggestions.php"
                    data-ss-context="am"
                    data-ss-variant="admin"
                    data-ss-min="2"
                    data-ss-min-numeric="1"
                    data-ss-max="6"
                    data-ss-filters="am_status"
                    data-ss-reset="am_page"
                >
                <button type="submit" class="am-btn">Search</button>
                <?php if ($amView['q'] !== ''): ?>
                    <a class="am-clear" href="<?= e($amLink(['q' => '', 'page' => 1])) ?>">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (!$amRows): ?>
            <p class="am-empty">
                <?= $amView['q'] !== '' || $amView['status'] !== 'all'
                    ? 'No artwork matches these filters.'
                    : 'No artwork has been submitted yet.' ?>
            </p>
        <?php else: ?>
            <div class="am-log" tabindex="0" role="region" aria-label="Artwork submissions log">
                <table class="am-table">
                    <caption class="am-sr-only">
                        Artwork submissions, <?= (int) $amTotal ?> in this view, page <?= (int) $amPage ?> of <?= (int) $amPages ?>
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Artwork</th>
                            <th scope="col">Artist</th>
                            <th scope="col">Submitted</th>
                            <th scope="col">Status</th>
                            <th scope="col">Reviewed</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($amRows as $amRow): ?>
                            <?php
                            $amId     = (int) $amRow['id'];
                            $amStatus = (string) $amRow['status'];
                            [$amStatusText, $amStatusClass] = am_status_label($amStatus);

                            // Latest recorded review: the artwork row first, then the history table.
                            $amReviewedAt = $amRow['reviewed_at'] ?? $amRow['history_reviewed_at'] ?? null;
                            $amReviewer   = $amRow['reviewer_username'] ?? $amRow['history_reviewer'] ?? null;
                            $amNote       = trim((string) ($amRow['admin_feedback'] ?? ''));
                            ?>
                            <tr class="am-row am-row--<?= e($amStatusClass) ?>">
                                <td class="am-col-id">#<?= $amId ?></td>

                                <td class="am-col-title">
                                    <a
                                        class="am-title-link"
                                        href="view-artwork.php?id=<?= $amId ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        title="<?= e((string) $amRow['title']) ?>"
                                    ><?= e((string) $amRow['title']) ?><span class="am-sr-only"> (opens a private preview in a new tab)</span></a>
                                </td>

                                <td class="am-col-artist">
                                    <span class="am-artist"><?= e((string) $amRow['artist_username']) ?></span>
                                    <span class="am-email"><?= e((string) $amRow['artist_email']) ?></span>
                                </td>

                                <td class="am-col-submitted">
                                    <?php if (am_fmt_dt($amRow['created_at'] ?? null) !== ''): ?>
                                        <time datetime="<?= e(am_dt_attr($amRow['created_at'])) ?>"><?= e(am_fmt_dt($amRow['created_at'])) ?></time>
                                    <?php else: ?>
                                        <span class="am-muted">Not recorded</span>
                                    <?php endif; ?>
                                </td>

                                <td class="am-col-status">
                                    <span class="am-status am-status--<?= e($amStatusClass) ?>"><?= e($amStatusText) ?></span>
                                </td>

                                <td class="am-col-review">
                                    <?php if ($amStatus === 'pending' && $amReviewedAt === null): ?>
                                        <span class="am-muted">Awaiting review</span>
                                    <?php elseif ($amReviewedAt === null && $amReviewer === null): ?>
                                        <span class="am-muted">Not recorded</span>
                                    <?php else: ?>
                                        <?php if (am_fmt_dt($amReviewedAt) !== ''): ?>
                                            <time datetime="<?= e(am_dt_attr($amReviewedAt)) ?>"><?= e(am_fmt_dt($amReviewedAt)) ?></time>
                                        <?php else: ?>
                                            <span class="am-muted">Not recorded</span>
                                        <?php endif; ?>
                                        <span class="am-by">by <?= $amReviewer !== null && $amReviewer !== ''
                                            ? e((string) $amReviewer)
                                            : '<span class="am-muted">Not recorded</span>' ?></span>
                                    <?php endif; ?>
                                    <?php if ($amNote !== ''): ?>
                                        <span class="am-note" title="<?= e($amNote) ?>">Note: <?= e($amNote) ?></span>
                                    <?php endif; ?>
                                </td>

                                <td class="am-col-actions">
                                    <form class="am-form" method="post" action="artwork-review-action.php">
                                        <input type="hidden" name="csrf_token" value="<?= e($amToken) ?>">
                                        <input type="hidden" name="artwork_id" value="<?= $amId ?>">
                                        <input type="hidden" name="expected_status" value="<?= e($amStatus) ?>">
                                        <input type="hidden" name="am_status" value="<?= e($amView['status']) ?>">
                                        <input type="hidden" name="am_q" value="<?= e($amView['q']) ?>">
                                        <input type="hidden" name="am_page" value="<?= (int) $amPage ?>">

                                        <label class="am-sr-only" for="am-note-<?= $amId ?>">Optional note for artwork #<?= $amId ?></label>
                                        <input
                                            class="am-input am-input--note"
                                            id="am-note-<?= $amId ?>"
                                            type="text"
                                            name="feedback"
                                            maxlength="<?= AM_FEEDBACK_MAX ?>"
                                            placeholder="Note (optional)"
                                            autocomplete="off"
                                        >

                                        <?php if ($amStatus !== 'approved'): ?>
                                            <button type="submit" name="action" value="approve" class="am-btn am-btn--approve">
                                                <?= $amStatus === 'rejected' ? 'Approve instead' : 'Approve' ?>
                                                <span class="am-sr-only"> artwork #<?= $amId ?></span>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($amStatus !== 'rejected'): ?>
                                            <button type="submit" name="action" value="reject" class="am-btn am-btn--reject">
                                                <?= $amStatus === 'approved' ? 'Reject instead' : 'Reject' ?>
                                                <span class="am-sr-only"> artwork #<?= $amId ?></span>
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($amPages > 1): ?>
                <nav class="am-pager" aria-label="Artwork log pages">
                    <?php if ($amPage > 1): ?>
                        <a href="<?= e($amLink(['page' => $amPage - 1])) ?>">Previous</a>
                    <?php endif; ?>
                    <span>Page <?= (int) $amPage ?> of <?= (int) $amPages ?> (<?= (int) $amTotal ?> artworks)</span>
                    <?php if ($amPage < $amPages): ?>
                        <a href="<?= e($amLink(['page' => $amPage + 1])) ?>">Next</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>

    <?php endif; ?>

</section>

