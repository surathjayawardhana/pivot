<?php
/**
 * Admin-only artwork preview: artwork-preview.php?id=N
 *
 * Lets an administrator open ANY artwork (pending, rejected or approved) from the
 * management/report lists without making private artwork public: artwork.php is left
 * untouched, so it still only shows approved artwork and the owner's own work.
 *
 * If the artwork-management feature already provides a preview page with this name,
 * keep that one - the report only links to sr_preview_url().
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/submission_report_helpers.php';
require_once __DIR__ . '/../includes/profile_helpers.php';   // profile_artwork_image_url()

$srFail = static function (int $code, string $message): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    exit($message);
};

try {
    $srDb    = art_db();
    $srAdmin = sr_current_admin($srDb);
    $srAnyId = art_session_user_id();
} catch (Throwable $srError) {
    error_log('[artwork-preview] ' . $srError->getMessage());
    $srFail(500, 'The preview is temporarily unavailable.');
}

if (!$srAdmin) {
    if (!$srAnyId) {
        header('Location: ../login.php');
        exit;
    }
    $srFail(403, 'You do not have permission to view this page.');
}

$srId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($srId === false || $srId === null) {
    $srFail(404, 'Artwork not found.');
}

try {
    $srArt = $srDb->one(
        'SELECT a.id, a.title, a.description, a.image_path, a.status, a.admin_feedback,
                UNIX_TIMESTAMP(a.created_at) AS created_epoch,
                u.username AS artist_username, u.email AS artist_email,
                c.name AS category_name
           FROM artworks a
           INNER JOIN users u ON u.id = a.user_id
           LEFT JOIN categories c ON c.id = a.category_id
          WHERE a.id = ?',
        [(int) $srId]
    );
} catch (Throwable $srError) {
    error_log('[artwork-preview] ' . $srError->getMessage());
    $srFail(500, 'The preview is temporarily unavailable.');
}

if (!$srArt) {
    $srFail(404, 'Artwork not found.');
}

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

[$srStatusLabel, $srStatusClass] = sr_status_badge((string) $srArt['status']);
$srImage = profile_artwork_image_url((string) $srArt['image_path']);
$srWhen  = (new DateTimeImmutable('@' . (int) $srArt['created_epoch']))->setTimezone(sr_timezone());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Preview: <?= art_e((string) $srArt['title']) ?> | NSBM ArtCraft Platform</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../css/artwork-submission-report.css">
</head>
<body>
<main class="sr-report sr-report--preview" id="artwork-admin-preview" aria-labelledby="sr-preview-title">
    <p class="sr-report__range"><a href="manage.php#artwork-submission-report">&larr; Back to admin</a></p>

    <h1 class="sr-report__title" id="sr-preview-title"><?= art_e((string) $srArt['title']) ?></h1>
    <p class="sr-report__range">
        <?= art_e((string) $srArt['artist_username']) ?>
        <span aria-hidden="true">·</span> <?= art_e((string) $srArt['artist_email']) ?>
        <span aria-hidden="true">·</span> <?= art_e((string) ($srArt['category_name'] ?? 'Uncategorised')) ?>
        <span aria-hidden="true">·</span> <?= art_e($srWhen->format('Y-m-d H:i:s')) ?> (<?= art_e(SR_TIMEZONE) ?>)
    </p>
    <p>
        <span class="sr-report__status sr-report__status--<?= art_e($srStatusClass) ?>"><?= art_e($srStatusLabel) ?></span>
    </p>

    <?php if ($srImage !== ''): ?>
        <p><img class="sr-report__preview-image" src="../<?= art_e($srImage) ?>" alt="<?= art_e((string) $srArt['title']) ?>"></p>
    <?php else: ?>
        <p class="sr-report__empty">The image file for this artwork is missing.</p>
    <?php endif; ?>

    <?php if (trim((string) $srArt['description']) !== ''): ?>
        <p class="sr-report__preview-text"><?= nl2br(art_e((string) $srArt['description'])) ?></p>
    <?php endif; ?>

    <?php if (trim((string) ($srArt['admin_feedback'] ?? '')) !== ''): ?>
        <p class="sr-report__preview-text"><strong>Admin feedback:</strong> <?= nl2br(art_e((string) $srArt['admin_feedback'])) ?></p>
    <?php endif; ?>
</main>
</body>
</html>
