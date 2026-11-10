<?php
/**
 * Markdown download for the Artwork Submission Report.
 *
 *   GET export-artwork-submissions.php?sr_period=today|week|month[&sr_until=<unix time>]
 *
 * Read-only, so a GET is safe here (nothing is changed, and a cross-site page cannot
 * read the response). It must run before any HTML is printed, which is why it is its
 * own small endpoint instead of living inside the section that manage.php includes
 * after the page header.
 *
 * It re-checks, from the database, that the caller is an existing, active,
 * not-soft-deleted admin, validates the period against a whitelist and runs the same
 * query (sr_fetch_rows) as the on-screen section.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/submission_report_helpers.php';

/** Plain-text error response (no HTML, no technical details). */
function sr_export_fail(int $code, string $message): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    exit($message);
}

$srMethod = $_SERVER['REQUEST_METHOD'] ?? '';
if ($srMethod !== 'GET' && $srMethod !== 'HEAD') {
    header('Allow: GET, HEAD');
    sr_export_fail(405, 'Method not allowed.');
}

// ---- Who is asking? (database, not $_SESSION['role']) ------------------------
try {
    $srDb    = art_db();
    $srAdmin = sr_current_admin($srDb);
    $srAnyId = art_session_user_id();
} catch (Throwable $srError) {
    error_log('[submission-report export] ' . $srError->getMessage());
    sr_export_fail(500, 'The report is temporarily unavailable.');
}

if (!$srAdmin) {
    if (!$srAnyId) {
        header('Location: ../login.php');   // not logged in
        exit;
    }
    sr_export_fail(403, 'You do not have permission to download this report.');
}

// ---- Validate input ---------------------------------------------------------
$srPeriod = sr_period_from($_GET['sr_period'] ?? null);

$srUntil = filter_var($_GET['sr_until'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$srUntil = $srUntil === false ? null : (int) $srUntil;

$srBounds = sr_bounds($srPeriod, null, $srUntil);

// ---- Same query as the screen -----------------------------------------------
try {
    $srRows = sr_fetch_rows($srDb, $srBounds['start'], $srBounds['end']);
} catch (Throwable $srError) {
    error_log('[submission-report export] ' . $srError->getMessage());
    sr_export_fail(500, 'The report could not be generated. Please try again.');
}

$srBody     = sr_markdown($srPeriod, $srBounds['start'], $srBounds['end'], $srRows);
$srFilename = 'artwork-submissions-' . $srPeriod . '-' . $srBounds['end']->format('Y-m-d') . '.md';

// ---- Send the file ------------------------------------------------------------
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/markdown; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $srFilename . '"');
header('Content-Length: ' . strlen($srBody));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

if ($srMethod !== 'HEAD') {
    echo $srBody;
}
exit;
