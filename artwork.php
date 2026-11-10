<?php

declare(strict_types=1);
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/profile_helpers.php';
require_once __DIR__ . '/includes/like_helpers.php';

$art       = null;
$failed    = false;
$likeCount = 0;
$likedByMe = false;

try {
    $db     = art_db();
    $viewer = profile_viewer($db);
    $id     = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($id) {
        $art = $db->one(
            "SELECT a.id, a.user_id, a.title, a.description, a.image_path, a.status, c.name AS category,
                    u.first_name, u.surname, u.username, u.profile_image_path
               FROM artworks a
               JOIN users u ON u.id = a.user_id AND u.account_status = 'active'
               LEFT JOIN categories c ON c.id = a.category_id
              WHERE a.id = ? AND (a.status = 'approved' OR a.user_id = ?)",
            [$id, $viewer['id'] ?? 0]
        );

        if ($art) {
            $likeCount = art_like_count($db, (int) $art['id']);
            $likedByMe = $viewer ? art_like_exists($db, (int) $art['id'], $viewer['id']) : false;
        }
    }
} catch (Throwable $e) {
    error_log('[artwork] ' . $e->getMessage());
    $failed = true;
    http_response_code(500);
}

if (!$failed && !$art) {
    http_response_code(404);
}

$isOwner = $art && isset($viewer) && $viewer && $viewer['id'] === (int) $art['user_id'];

// The discussion (and its CSS/JS) exists for approved artwork only.
$showComments = $art && $art['status'] === 'approved';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/profile.css">
    <link rel="stylesheet" href="assets/css/likes4art.css">
    <?php if ($showComments): ?>
    <link rel="stylesheet" href="assets/css/comments.css">
    <?php endif; ?>
    <title><?= $art ? art_e((string) $art['title']) : 'Artwork' ?> | NSBM ArtCraft Platform</title>
</head>
<body>
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="profile-page">
        <div class="profile-container profile-container--narrow">
        <?php if ($failed): ?>
            <section class="profile-card profile-message" role="alert">
                <h1>Artwork unavailable</h1>
                <p>We couldn't load this artwork right now. Please try again later.</p>
            </section>
        <?php elseif (!$art): ?>
            <section class="profile-card profile-message">
                <h1>Artwork not found</h1>
                <p>This artwork doesn't exist or isn't available.</p>
                <a class="profile-btn" href="index.php">Back to home</a>
            </section>
        <?php else:
            $imgUrl = profile_artwork_image_url($art['image_path'] ?? null);
            [$badgeText, $badgeClass] = profile_status_badge((string) $art['status']);
        ?>
            <article class="profile-card artwork-view">
                <?php if ($imgUrl !== ''): ?>
                    <img class="artwork-view__img" src="<?= art_e($imgUrl) ?>" alt="<?= art_e((string) $art['title']) ?>">
                <?php endif; ?>
                <div class="artwork-view__body">
                    <h1><?= art_e((string) $art['title']) ?></h1>
                    <p class="artwork-view__meta">
                        <?php if (!empty($art['category'])): ?>
                            <span>
                                <a
                                    class="artwork-view__category"
                                    href="connect_categoy.php?category=<?= rawurlencode((string) $art['category']) ?>"
                                >
                                    <?= art_e((string) $art['category']) ?>
                                </a>
                            </span>
                        <?php endif; ?>

                        <?php if ($isOwner): ?>
                            <span class="status-badge status-badge--<?= art_e($badgeClass) ?>">
                                <?= art_e($badgeText) ?>
                            </span>
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($art['description'])): ?>
                        <p class="artwork-view__desc"><?= nl2br(art_e((string) $art['description'])) ?></p>
                    <?php endif; ?>

                    <?php if ($art['status'] === 'approved'): ?>
                        <div class="artwork-view__likes">
                            <?php art_like_button((int) $art['id'], (string) $art['title'], $likeCount, $likedByMe, 'artwork.php?id=' . (int) $art['id']); ?>
                        </div>
                    <?php endif; ?>

                    <a class="person person--inline" href="<?= art_e(profile_url((string) $art['username'])) ?>">
                        <img class="person__avatar" src="<?= art_e(profile_avatar_url($art['profile_image_path'])) ?>" alt="" width="48" height="48">
                        <span class="person__text">
                            <span class="person__name"><?= art_e(profile_full_name($art)) ?></span>
                            <span class="person__username">@<?= art_e((string) $art['username']) ?></span>
                        </span>
                    </a>

                    <?php if ($isOwner): ?>
                        <div class="artwork-view__actions">
                            <a class="profile-btn profile-btn--secondary" href="editpost.php?id=<?= (int) $art['id'] ?>">Edit artwork</a>
                        </div>
                    <?php endif; ?>
                </div>
            </article>

            <?php if ($showComments): ?>
                <?php include __DIR__ . '/partials/comments.php'; ?>
            <?php endif; ?>
        <?php endif; ?>
        </div>
    </main>

    <?php include __DIR__ . '/partials/footer.php'; ?>
    <script src="js/likes.js" defer></script>
    <?php if ($showComments): ?>
    <script src="js/comments.js" defer></script>
    <?php endif; ?>
</body>
</html>
