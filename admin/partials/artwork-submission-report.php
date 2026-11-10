<?php
/**
 * Admin: Artwork Submission Report (section only - no <html>, header or footer).
 *
 * Usage, inside <main> of admin/manage.php:
 *     <?php include __DIR__ . '/partials/artwork-submission-report.php'; ?>
 *
 * Lists every artwork submitted today / this week / this month (Asia/Colombo),
 * whatever its current status. The "Download .md" link goes to
 * admin/export-artwork-submissions.php, which runs the same query.
 *
 * Reuses: art_db(), art_e()                       (includes/artwork_helpers.php)
 *         sr_* helpers                            (includes/submission_report_helpers.php)
 *
 * All variables start with $sr so they cannot clash with manage.php or other partials.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/submission_report_helpers.php';

// ---- Administrators only (checked against the database) --------------------
try {
    $srDb     = art_db();
    $srViewer = sr_current_admin($srDb);
} catch (Throwable $srError) {
    error_log('[submission-report] ' . $srError->getMessage());
    return;
}
if (!$srViewer) {
    return;
}

// ---- Period (strict whitelist, default Today) ------------------------------
$srPeriod = sr_period_from($_GET['sr_period'] ?? null);
$srBounds = sr_bounds($srPeriod);

// ---- Load rows ---------------------------------------------------------------
$srRows       = [];
$srLoadFailed = false;

try {
    $srRows = sr_fetch_rows($srDb, $srBounds['start'], $srBounds['end']);
} catch (Throwable $srError) {
    error_log('[submission-report] ' . $srError->getMessage());
    $srLoadFailed = true;
}

// ---- Links (keep other query parameters of the page, change only the period) ----
$srOtherParams = $_GET;
unset($srOtherParams['sr_period']);

$srPeriodUrl = static function (string $period) use ($srOtherParams): string {
    return '?' . http_build_query($srOtherParams + ['sr_period' => $period]) . '#artwork-submission-report';
};

// The export gets the exact end moment shown here, so both always list the same artworks.
$srExportUrl = 'export-artwork-submissions.php?' . http_build_query([
    'sr_period' => $srPeriod,
    'sr_until'  => $srBounds['end']->getTimestamp(),
]);

$srPeriodLabel = SR_PERIODS[$srPeriod];
$srCount       = count($srRows);
?>
<link rel="stylesheet" href="../css/artwork-submission-report.css">

<section class="sr-report" id="artwork-submission-report" aria-labelledby="sr-report-title">

    <header class="sr-report__header">
        <div class="sr-report__heading">
            <h2 class="sr-report__title" id="sr-report-title">Artwork Submission Report</h2>
            <p class="sr-report__range">
                <strong><?= art_e($srPeriodLabel) ?></strong>
                <span aria-hidden="true">·</span>
                <?= art_e(sr_range_text($srBounds['start'], $srBounds['end'])) ?>
            </p>
        </div>

        <div class="sr-report__controls">
            <nav class="sr-report__periods" aria-label="Report period">
                <?php foreach (SR_PERIODS as $srKey => $srLabel): ?>
                    <a
                        class="sr-report__period"
                        href="<?= art_e($srPeriodUrl($srKey)) ?>"
                        <?= $srKey === $srPeriod ? 'aria-current="true"' : '' ?>
                    ><?= art_e($srLabel) ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if (!$srLoadFailed): ?>
                <a class="sr-report__download" href="<?= art_e($srExportUrl) ?>" download>Download .md</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($srLoadFailed): ?>

        <p class="sr-report__notice sr-report__notice--error" role="alert">
            The report could not be loaded. Please refresh the page in a moment.
        </p>

    <?php else: ?>

        <p class="sr-report__total" role="status">
            <span class="sr-report__total-number"><?= $srCount ?></span>
            <?= $srCount === 1 ? 'submission' : 'submissions' ?>
        </p>

        <?php if (!$srRows): ?>

            <p class="sr-report__empty">No artworks were submitted in this period.</p>

        <?php else: ?>

            <div class="sr-report__scroll" tabindex="0" role="region" aria-label="Artwork submissions (scrollable)">
                <table class="sr-report__table">
                    <caption class="sr-report__caption">
                        Artworks submitted <?= art_e(strtolower($srPeriodLabel)) ?>, newest first
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">Submitted</th>
                            <th scope="col">Artwork</th>
                            <th scope="col">Artist</th>
                            <th scope="col">Email</th>
                            <th scope="col">Status</th>
                            <th scope="col">Approved by</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($srRows as $srRow): ?>
                            <?php [$srStatusLabel, $srStatusClass] = sr_status_badge($srRow['status']); ?>
                            <tr>
                                <td class="sr-report__time">
                                    <time datetime="<?= art_e($srRow['submitted_at']->format('c')) ?>">
                                        <?= art_e($srRow['submitted_at']->format('Y-m-d H:i:s')) ?>
                                    </time>
                                </td>
                                <td class="sr-report__artwork">
                                    <a href="<?= art_e(sr_preview_url($srRow['id'])) ?>"><?= art_e($srRow['title']) ?></a>
                                </td>
                                <td><?= art_e($srRow['artist_username']) ?></td>
                                <td class="sr-report__email"><?= art_e($srRow['artist_email']) ?></td>
                                <td>
                                    <span class="sr-report__status sr-report__status--<?= art_e($srStatusClass) ?>">
                                        <?= art_e($srStatusLabel) ?>
                                    </span>
                                </td>
                                <td class="sr-report__approver<?= $srRow['approver_recorded'] ? '' : ' sr-report__approver--none' ?>">
                                    <?= art_e($srRow['approver']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

    <?php endif; ?>

</section>

