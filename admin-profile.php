<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'admin'
) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/profile_helpers.php';

require_once __DIR__ . '/includes/artwork_helpers.php';
art_require_admin(art_db(), 'login.php');

/* ---------- Bootstrap ---------- */
$viewer  = null;
$profile = null;
$failed  = false;

try {
    $db     = art_db();
    $viewer = profile_viewer($db);

    $requested = isset($_GET['u']) && is_string($_GET['u']) ? trim($_GET['u']) : '';
    if ($requested === '') {
        if (!$viewer) {                       // "my profile" needs a login
            header('Location: login.php');
            exit;
        }
        $profile = profile_find_by_id($db, $viewer['id']);
    } else {
        $profile = profile_find_by_username($db, $requested);
    }

    if ($profile) {
        $isOwner    = $viewer !== null && $viewer['id'] === $profile['id'];
        $counts     = profile_follow_counts($db, $profile['id']);
        $counts['artworks'] = profile_artwork_count($db, $profile['id'], $isOwner);
        $artworks   = profile_artworks($db, $profile['id'], $isOwner);
        $isFollowing = $viewer && !$isOwner ? profile_is_following($db, $viewer['id'], $profile['id']) : false;
    }
} catch (Throwable $e) {
    error_log('[profile] ' . $e->getMessage());
    $failed = true;
    http_response_code(500);
}

if (!$failed && !$profile) {
    http_response_code(404);
}

$flash = art_flash_pull();
$title = $profile
    ? profile_full_name($profile) . ' (@' . $profile['username'] . ')'
    : 'Profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/profile.css">
    <title><?= art_e($title) ?> | NSBM ArtCraft Platform</title>
</head>
<body>
    <?php include __DIR__ . '/partials/admin-header.php'; ?>

    <main class="profile-page">
        <div class="profile-container">

        <?php if ($failed): ?>
            <section class="profile-card profile-message" role="alert">
                <h1>Profile unavailable</h1>
                <p>We couldn't load this profile right now. Please try again later.</p>
            </section>

        <?php elseif (!$profile): ?>
            <section class="profile-card profile-message">
                <h1>Artist not found</h1>
                <p>This profile doesn't exist or is no longer available.</p>
                <a class="profile-btn" href="index.php">Back to home</a>
            </section>

        <?php else: ?>
            <?php if ($flash): ?>
                <div class="profile-notice profile-notice--<?= art_e($flash['type']) ?>" role="status">
                    <span><?= art_e($flash['message']) ?></span>
                </div>
            <?php endif; ?>

            <?php $listBase = 'followers.php?u=' . rawurlencode($profile['username']); ?>
            <section class="profile-card profile-header" aria-labelledby="profile-name">
                <img class="profile-avatar"
                     src="<?= art_e(profile_avatar_url($profile['profile_image_path'])) ?>"
                     alt="Profile picture of <?= art_e(profile_full_name($profile)) ?>"
                     width="128" height="128">

                <div class="profile-info">
                    <h1 id="profile-name" class="profile-name"><?= art_e(profile_full_name($profile)) ?></h1>
                    <p class="profile-username">@<?= art_e($profile['username']) ?></p>

                    <?php if (!empty($profile['bio'])): ?>
                        <p class="profile-bio"><?= art_e($profile['bio']) ?></p>
                    <?php elseif ($isOwner): ?>
                        <p class="profile-bio profile-bio--empty">Add a short biography so people know who you are.</p>
                    <?php endif; ?>

                    <ul class="profile-stats">
                        <li><strong><?= (int) $counts['artworks'] ?></strong> Artworks</li>
                        <li><a href="<?= art_e($listBase) ?>&amp;list=followers"><strong data-followers-count><?= (int) $counts['followers'] ?></strong> Followers</a></li>
                        <li><a href="<?= art_e($listBase) ?>&amp;list=following"><strong><?= (int) $counts['following'] ?></strong> Following</a></li>
                    </ul>
                </div>

                <div class="profile-actions">
                    <?php if ($isOwner): ?>
                        <a class="profile-btn" href="edit_profile.php">Edit Profile</a>
                        <a class="profile-btn profile-btn--secondary" href="add_post.php">Add Artwork</a>
                        <a class="profile-btn" href="index.php" style="background-color: #3BA73C ; border:#3BA73C;">Visit site</a>

                    <?php elseif ($viewer): ?>
                        <form method="post" action="follow_action.php" data-follow-form>
                            <input type="hidden" name="csrf_token" value="<?= art_e(art_csrf_token()) ?>">
                            <input type="hidden" name="target_id" value="<?= (int) $profile['id'] ?>">
                            <input type="hidden" name="action" value="<?= $isFollowing ? 'unfollow' : 'follow' ?>" data-follow-action>
                            <button type="submit"
                                    class="profile-btn<?= $isFollowing ? ' profile-btn--secondary' : '' ?>"
                                    data-follow-button><?= $isFollowing ? 'Unfollow' : 'Follow' ?></button>
                        </form>
                        <p class="profile-sr" role="status" aria-live="polite" data-follow-status></p>
                    <?php else: ?>
                        <a class="profile-btn" href="login.php">Log in to follow</a>
                    <?php endif; ?>
                </div>
                
            </section>


            <section class="profile-gallery" aria-labelledby="gallery-title">
                <h2 id="gallery-title" class="profile-section-title">Artworks</h2>

                <?php if (!$artworks): ?>
                    <div class="profile-card profile-empty">
                        <?php if ($isOwner): ?>
                            <h3>You haven't uploaded any artwork yet</h3>
                            <p>Share your first piece with the ArtCraft community.</p>
                            <a class="profile-btn" href="add_post.php">Add Artwork</a>
                        <?php else: ?>
                            <h3>No artwork to show yet</h3>
                            <p><?= art_e($profile['first_name']) ?> hasn't shared any approved artwork yet.</p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <ul class="art-grid">
                        <?php foreach ($artworks as $art):
                            $imgUrl = profile_artwork_image_url($art['image_path'] ?? null);
                            [$badgeText, $badgeClass] = profile_status_badge((string) $art['status']);
                        ?>
                            <li class="art-card">
                                <a class="art-card__link" href="artwork.php?id=<?= (int) $art['id'] ?>">
                                    <span class="art-card__media">
                                        <?php if ($imgUrl !== ''): ?>
                                            <img src="<?= art_e($imgUrl) ?>" alt="<?= art_e((string) $art['title']) ?>" loading="lazy">
                                        <?php endif; ?>
                                    </span>
                                    <span class="art-card__body">
                                        <span class="art-card__title"><?= art_e((string) $art['title']) ?></span>
                                        <span class="art-card__meta">
                                            <?php if (!empty($art['category'])): ?>
                                                <span><?= art_e((string) $art['category']) ?></span>
                                            <?php endif; ?>
                                            <?php if ($isOwner): ?>
                                                <span class="status-badge status-badge--<?= art_e($badgeClass) ?>"><?= art_e($badgeText) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        </div>
    </main>

    <?php include __DIR__ . '/partials/footer.php'; ?>
    <script src="js/profile.js" defer></script>
</body>
</html>
