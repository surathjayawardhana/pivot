<?php
/**
 * Admin: Artwork Participation Report (section only - no <html>, header or footer).
 *
 * Usage, inside <main> of an admin page (e.g. admin/manage.php):
 *     <?php include __DIR__ . '/partials/artwork-participation-report.php'; ?>
 *
 * One compact row per user account: total artworks uploaded, and how many of
 * them are currently approved / rejected (plus pending). It is a report, not a
 * gallery: no profile pictures, no artwork images.
 *
 * Reuses: db()                                   (includes/db.php)
 *         art_e()                                (includes/artwork_helpers.php)
 *         apr_* query helpers                    (includes/artwork_participation_report.php)
 *
 * GET parameters (all prefixed so they cannot clash with the host page):
 *     pr_q, pr_status, pr_sort, pr_dir, pr_page
 *
 * All variables start with $pr so they cannot clash with the host page.
 * Prints nothing at all unless the database says the viewer is an active admin.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/artwork_participation_report.php';

// ---- Administrators only: checked against the database, not the session role ----
try {
    $prPdo = db();
    if (!apr_viewer_is_admin($prPdo)) {
        return;
    }
} catch (Throwable $prError) {
    error_log('[artwork-participation-report] ' . $prError->getMessage());
    return;
}

// ---- Request state (sanitised and whitelisted) -----------------------------------
$prState = apr_read_request($_GET);

// ---- Load data --------------------------------------------------------------------
$prSummary    = ['users' => 0, 'total' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0];
$prRows       = [];
$prPages      = 1;
$prLoadFailed = false;

try {
    $prSummary = apr_fetch_summary($prPdo, $prState);
    $prPages   = max(1, (int) ceil($prSummary['users'] / APR_PAGE_SIZE));
    $prState['page'] = min($prState['page'], $prPages);   // out-of-range page -> last page
    $prRows = apr_fetch_rows($prPdo, $prState, APR_PAGE_SIZE, ($prState['page'] - 1) * APR_PAGE_SIZE);
} catch (Throwable $prError) {
    error_log('[artwork-participation-report] ' . $prError->getMessage());
    $prLoadFailed = true;
}

// ---- Small helpers for this template ----------------------------------------------
$prAnchor   = '#artwork-participation-report';
$prFiltered = ($prState['q'] !== '' || $prState['status'] !== 'all');

/** Link to this page with some report settings changed; other (non-report) GET values are kept. */
$prUrl = static function (array $prChange) use ($prState): string {
    $prQuery = [];
    foreach ($_GET as $prKey => $prValue) {
        if (is_string($prKey) && is_string($prValue) && strncmp($prKey, 'pr_', 3) !== 0) {
            $prQuery[$prKey] = $prValue;
        }
    }
    $prNew = $prChange + [
        'pr_q' => $prState['q'], 'pr_status' => $prState['status'],
        'pr_sort' => $prState['sort'], 'pr_dir' => $prState['dir'], 'pr_page' => $prState['page'],
    ];
    // Keep links short: only write values that differ from the defaults.
    if ((string) $prNew['pr_q'] !== '')              { $prQuery['pr_q'] = (string) $prNew['pr_q']; }
    if ($prNew['pr_status'] !== 'all')               { $prQuery['pr_status'] = $prNew['pr_status']; }
    if ($prNew['pr_sort'] !== 'username' || $prNew['pr_dir'] !== 'asc') {
        $prQuery['pr_sort'] = $prNew['pr_sort'];
        $prQuery['pr_dir']  = $prNew['pr_dir'];
    }
    if ((int) $prNew['pr_page'] > 1)                 { $prQuery['pr_page'] = (int) $prNew['pr_page']; }

    return '?' . http_build_query($prQuery) . '#artwork-participation-report';
};

/** Clicking the active column flips the direction; a new column starts at its natural direction. */
$prSortLink = static function (string $prKey) use ($prState, $prUrl): string {
    $prDir = ($prState['sort'] === $prKey)
        ? ($prState['dir'] === 'asc' ? 'desc' : 'asc')
        : apr_default_dir($prKey);
    return $prUrl(['pr_sort' => $prKey, 'pr_dir' => $prDir, 'pr_page' => 1]);
};

$prAriaSort = static function (string $prKey) use ($prState): string {
    if ($prState['sort'] !== $prKey) {
        return 'none';
    }
    return $prState['dir'] === 'asc' ? 'ascending' : 'descending';
};

$prArrow = static function (string $prKey) use ($prState): string {
    if ($prState['sort'] !== $prKey) {
        return '';
    }
    return $prState['dir'] === 'asc' ? '↑' : '↓';
};

$prAccountLabels = ['active' => 'Active', 'suspended' => 'Suspended', 'removed' => 'Removed'];

// Hidden inputs so the search form keeps the sort and any unrelated GET values.
$prHidden = [];
foreach ($_GET as $prKey => $prValue) {
    if (is_string($prKey) && is_string($prValue) && strncmp($prKey, 'pr_', 3) !== 0) {
        $prHidden[$prKey] = $prValue;
    }
}
$prHidden['pr_sort'] = $prState['sort'];
$prHidden['pr_dir']  = $prState['dir'];

$prFirstShown = $prSummary['users'] === 0 ? 0 : (($prState['page'] - 1) * APR_PAGE_SIZE) + 1;
$prLastShown  = $prFirstShown === 0 ? 0 : $prFirstShown + count($prRows) - 1;

// Page-number window: first, last, and two either side of the current page.
$prPageNumbers = [];
for ($prN = 1; $prN <= $prPages; $prN++) {
    if ($prN === 1 || $prN === $prPages || abs($prN - $prState['page']) <= 2) {
        $prPageNumbers[] = $prN;
    }
}
?>
<link rel="stylesheet" href="../css/artwork-participation-report.css">
<?php if (empty($GLOBALS['ss_assets_loaded'])): $GLOBALS['ss_assets_loaded'] = true; ?>
<link rel="stylesheet" href="../assets/css/search-suggestions.css">
<script src="../js/search-suggestions.js" defer></script>
<?php endif; ?>

<section class="apr" id="artwork-participation-report" aria-labelledby="apr-title">

    <header class="apr__header">
        <h2 class="apr__title" id="apr-title">Artwork Participation Report</h2>
        <p class="apr__intro">
            Artworks uploaded by each user and their current review status.
            Counts show where each artwork stands now, not how many times it was reviewed.
        </p>
    </header>

    <?php if ($prLoadFailed): ?>
        <p class="apr__notice" role="alert">The report could not be loaded right now. Please try again shortly.</p>
    <?php else: ?>

        <dl class="apr__summary" aria-label="Report totals<?= $prFiltered ? ' for the current filters' : '' ?>">
            <div class="apr__stat">
                <dt>Users</dt>
                <dd><?= number_format($prSummary['users']) ?></dd>
            </div>
            <div class="apr__stat">
                <dt>Uploaded</dt>
                <dd><?= number_format($prSummary['total']) ?></dd>
            </div>
            <div class="apr__stat apr__stat--approved">
                <dt>Approved</dt>
                <dd><?= number_format($prSummary['approved']) ?></dd>
            </div>
            <div class="apr__stat apr__stat--rejected">
                <dt>Rejected</dt>
                <dd><?= number_format($prSummary['rejected']) ?></dd>
            </div>
            <div class="apr__stat apr__stat--pending">
                <dt>Pending</dt>
                <dd><?= number_format($prSummary['pending']) ?></dd>
            </div>
        </dl>
        <?php if ($prFiltered): ?>
            <p class="apr__note">Totals cover the <?= number_format($prSummary['users']) ?> matching user<?= $prSummary['users'] === 1 ? '' : 's' ?> (all pages).</p>
        <?php endif; ?>

        <form class="apr__filters" method="get" action="<?= art_e($prAnchor) ?>" role="search">
            <?php foreach ($prHidden as $prKey => $prValue): ?>
                <input type="hidden" name="<?= art_e((string) $prKey) ?>" value="<?= art_e($prValue) ?>">
            <?php endforeach; ?>

            <label class="apr__field apr__field--search">
                <span class="apr__label">Search</span>
                <input
                    type="search"
                    name="pr_q"
                    value="<?= art_e($prState['q']) ?>"
                    maxlength="<?= (int) APR_SEARCH_MAX_LEN ?>"
                    placeholder="Username, email, name or ID"
                    autocomplete="off"
                    data-ss-endpoint="search_suggestions.php"
                    data-ss-context="pr"
                    data-ss-variant="admin"
                    data-ss-min="2"
                    data-ss-min-numeric="1"
                    data-ss-max="6"
                    data-ss-filters="pr_status"
                    data-ss-reset="pr_page"
                >
            </label>

            <label class="apr__field">
                <span class="apr__label">Account</span>
                <select name="pr_status">
                    <?php foreach (['all' => 'All', 'active' => 'Active', 'suspended' => 'Suspended', 'removed' => 'Removed'] as $prValue => $prText): ?>
                        <option value="<?= art_e($prValue) ?>"<?= $prState['status'] === $prValue ? ' selected' : '' ?>><?= art_e($prText) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="apr__actions">
                <button type="submit" class="apr__button">Apply</button>
                <?php if ($prFiltered): ?>
                    <a class="apr__reset" href="<?= art_e($prUrl(['pr_q' => '', 'pr_status' => 'all', 'pr_page' => 1])) ?>">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if (!$prRows): ?>
            <p class="apr__empty">
                <?= $prFiltered ? 'No users match these filters.' : 'There are no user accounts yet.' ?>
            </p>
        <?php else: ?>
            <p class="apr__hint">Swipe the table sideways to see every column.</p>
            <div class="apr__scroll" role="region" aria-label="Artwork participation by user" tabindex="0">
                <table class="apr__table">
                    <caption class="apr__sr">
                        Users with their total, approved, rejected and pending artwork counts.
                        Showing <?= (int) $prFirstShown ?> to <?= (int) $prLastShown ?> of <?= (int) $prSummary['users'] ?>.
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col" aria-sort="<?= art_e($prAriaSort('username')) ?>">
                                <a href="<?= art_e($prSortLink('username')) ?>">Username <span aria-hidden="true"><?= $prArrow('username') ?></span></a>
                            </th>
                            <th scope="col">User ID</th>
                            <th scope="col">Email</th>
                            <th scope="col">Account</th>
                            <th scope="col" class="apr__num" aria-sort="<?= art_e($prAriaSort('total')) ?>">
                                <a href="<?= art_e($prSortLink('total')) ?>">Total uploaded <span aria-hidden="true"><?= $prArrow('total') ?></span></a>
                            </th>
                            <th scope="col" class="apr__num apr__num--approved" aria-sort="<?= art_e($prAriaSort('approved')) ?>">
                                <a href="<?= art_e($prSortLink('approved')) ?>">Approved <span aria-hidden="true"><?= $prArrow('approved') ?></span></a>
                            </th>
                            <th scope="col" class="apr__num apr__num--rejected" aria-sort="<?= art_e($prAriaSort('rejected')) ?>">
                                <a href="<?= art_e($prSortLink('rejected')) ?>">Rejected <span aria-hidden="true"><?= $prArrow('rejected') ?></span></a>
                            </th>
                            <th scope="col" class="apr__num">Pending</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($prRows as $prRow): ?>
                            <tr class="apr__row apr__row--<?= art_e($prRow['account']) ?>">
                                <th scope="row" class="apr__user"><?= art_e($prRow['username']) ?></th>
                                <td class="apr__id"><?= (int) $prRow['id'] ?></td>
                                <td class="apr__email"><?= art_e($prRow['email']) ?></td>
                                <td>
                                    <span class="apr__tag apr__tag--<?= art_e($prRow['account']) ?>"><?= art_e($prAccountLabels[$prRow['account']] ?? ucfirst($prRow['account'])) ?></span>
                                </td>
                                <td class="apr__num<?= $prRow['total'] === 0 ? ' is-zero' : '' ?>"><?= number_format($prRow['total']) ?></td>
                                <td class="apr__num apr__num--approved<?= $prRow['approved'] === 0 ? ' is-zero' : '' ?>"><?= number_format($prRow['approved']) ?></td>
                                <td class="apr__num apr__num--rejected<?= $prRow['rejected'] === 0 ? ' is-zero' : '' ?>"><?= number_format($prRow['rejected']) ?></td>
                                <td class="apr__num apr__num--pending"><?= number_format($prRow['pending']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <nav class="apr__pager" aria-label="Report pages">
                <p class="apr__count">
                    Showing <?= number_format($prFirstShown) ?>–<?= number_format($prLastShown) ?> of <?= number_format($prSummary['users']) ?>
                </p>
                <?php if ($prPages > 1): ?>
                    <ul class="apr__pages">
                        <li>
                            <?php if ($prState['page'] > 1): ?>
                                <a href="<?= art_e($prUrl(['pr_page' => $prState['page'] - 1])) ?>" rel="prev">Previous</a>
                            <?php else: ?>
                                <span class="is-disabled">Previous</span>
                            <?php endif; ?>
                        </li>
                        <?php $prPrev = 0; foreach ($prPageNumbers as $prN): ?>
                            <?php if ($prN - $prPrev > 1): ?><li><span class="is-gap" aria-hidden="true">…</span></li><?php endif; ?>
                            <li>
                                <?php if ($prN === $prState['page']): ?>
                                    <span class="is-current" aria-current="page"><?= (int) $prN ?></span>
                                <?php else: ?>
                                    <a href="<?= art_e($prUrl(['pr_page' => $prN])) ?>" aria-label="Page <?= (int) $prN ?>"><?= (int) $prN ?></a>
                                <?php endif; ?>
                            </li>
                            <?php $prPrev = $prN; ?>
                        <?php endforeach; ?>
                        <li>
                            <?php if ($prState['page'] < $prPages): ?>
                                <a href="<?= art_e($prUrl(['pr_page' => $prState['page'] + 1])) ?>" rel="next">Next</a>
                            <?php else: ?>
                                <span class="is-disabled">Next</span>
                            <?php endif; ?>
                        </li>
                    </ul>
                <?php endif; ?>
            </nav>
        <?php endif; ?>

    <?php endif; ?>

</section>
<hr class="apr-divider">
