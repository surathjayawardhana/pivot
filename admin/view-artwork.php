<?php
/**
 * Private admin preview of one artwork: admin/view-artwork.php?id=ARTWORK_ID
 *
 * Opened from the artwork title in the Artwork Management log. An administrator can see any
 * artwork here whatever its status (pending, approved or rejected). Nobody else can: the
 * administrator is verified against the database on every request, and artwork.php (the
 * public page) is left unchanged, so pending and rejected work stays private.
 *
 * Read only - nothing on this page changes the database.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/artwork_review_helpers.php';

// ---- Administrators only (checked against the database) --------------------
try {
    $pdo = db();

    if (art_session_user_id() === null) {
        header('Location: ../login.php');
        exit;
    }
    $viewer = am_current_admin($pdo);
} catch (Throwable $error) {
    error_log('[view-artwork] ' . $error->getMessage());
    http_response_code(500);
    exit('The service is temporarily unavailable.');
}

if ($viewer === null) {
    http_response_code(403);
    exit('You do not have permission to view this page.');
}

// ---- Load the artwork -----------------------------------------------------
$art    = null;
$failed = false;
$id     = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($id !== false && $id !== null) {
    try {
        $st = $pdo->prepare(
            'SELECT a.id, a.title, a.description, a.image_path, a.status, a.admin_feedback,
                    a.created_at, a.reviewed_at, a.featured_slot,
                    c.name AS category,
                    u.username AS artist_username, u.email AS artist_email,
                    r.username AS reviewer_username
               FROM artworks a
               JOIN users u ON u.id = a.user_id
               LEFT JOIN categories c ON c.id = a.category_id
               LEFT JOIN users r ON r.id = a.reviewed_by
              WHERE a.id = ?'
        );
        $st->bindValue(1, (int) $id, PDO::PARAM_INT);
        $st->execute();
        $art = $st->fetch() ?: null;
    } catch (Throwable $error) {
        error_log('[view-artwork] ' . $error->getMessage());
        $failed = true;
        http_response_code(500);
    }
}
if (!$failed && $art === null) {
    http_response_code(404);
}

// Private page: keep it out of caches and search engines.
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

$imageUrl = $art ? profile_artwork_image_url((string) $art['image_path']) : '';   // '' if missing or unsafe
$pageTitle = $art ? (string) $art['title'] : 'Artwork preview';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/artwork-management.css">
    <title><?= e($pageTitle) ?> | Admin preview | NSBM ArtCraft Platform</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
</head>
<body>

<?php include __DIR__ . '/partials/header.php'; ?>

<main>
    <section class="am-preview" aria-labelledby="am-preview-title">
        <a class="am-back" href="manage.php#artwork-management">&larr; Back to Artwork Management</a>

        <?php if ($failed): ?>
            <h1 class="am-title" id="am-preview-title">Artwork unavailable</h1>
            <p>This artwork could not be loaded right now. Please try again in a moment.</p>

        <?php elseif ($art === null): ?>
            <h1 class="am-title" id="am-preview-title">Artwork not found</h1>
            <p>There is no artwork with that ID.</p>

        <?php else:
            [$statusText, $statusClass] = am_status_label((string) $art['status']);
            $feedback = trim((string) ($art['admin_feedback'] ?? ''));
            ?>
            <p class="am-private">Private admin preview. This page is only available to administrators.</p>

            <h1 class="am-title" id="am-preview-title"><?= e((string) $art['title']) ?></h1>
            <p><span class="am-status am-status--<?= e($statusClass) ?>"><?= e($statusText) ?></span></p>

            <dl class="am-facts">
                <dt>Artwork ID</dt>
                <dd>#<?= (int) $art['id'] ?></dd>

                <dt>Artist</dt>
                <dd><?= e((string) $art['artist_username']) ?> <span class="am-muted">(<?= e((string) $art['artist_email']) ?>)</span></dd>

                <dt>Category</dt>
                <dd><?= ($art['category'] ?? '') !== '' ? e((string) $art['category']) : '<span class="am-muted">None</span>' ?></dd>

                <dt>Submitted</dt>
                <dd><?= am_fmt_dt($art['created_at'] ?? null) !== '' ? e(am_fmt_dt($art['created_at'])) : 'Not recorded' ?></dd>

                <dt>Reviewed</dt>
                <dd>
                    <?php if ((string) $art['status'] === 'pending' && $art['reviewed_at'] === null): ?>
                        Awaiting review
                    <?php else: ?>
                        <?= am_fmt_dt($art['reviewed_at'] ?? null) !== '' ? e(am_fmt_dt($art['reviewed_at'])) : 'Not recorded' ?>
                        by <?= ($art['reviewer_username'] ?? '') !== '' ? e((string) $art['reviewer_username']) : 'Not recorded' ?>
                    <?php endif; ?>
                </dd>

                <?php if ($feedback !== ''): ?>
                    <dt>Review note</dt>
                    <dd><?= e($feedback) ?></dd>
                <?php endif; ?>
            </dl>

            <?php if ($imageUrl !== ''): ?>
                <img class="am-image" src="../<?= e($imageUrl) ?>" alt="<?= e((string) $art['title']) ?>">
            <?php else: ?>
                <p class="am-missing">The image file for this artwork is missing.</p>
            <?php endif; ?>

            <?php if (trim((string) $art['description']) !== ''): ?>
                <p class="am-description"><?= nl2br(e((string) $art['description'])) ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>

<?php include __DIR__ . '/partials/footer.php'; ?>

</body>
</html>
