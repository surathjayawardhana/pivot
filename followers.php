<?php
/**
 * Follower / following lists for one artist, handled by this single page.
 *   followers.php                         -> my followers
 *   followers.php?list=following          -> people I follow
 *   followers.php?u=username&list=...     -> someone else's lists (guests allowed)
 */
declare(strict_types=1);
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/profile_helpers.php';

$profile = null;
$failed  = false;
$list    = (isset($_GET['list']) && $_GET['list'] === 'following') ? 'following' : 'followers';

try {
    $db     = art_db();
    $viewer = profile_viewer($db);

    $requested = isset($_GET['u']) && is_string($_GET['u']) ? trim($_GET['u']) : '';
    if ($requested === '') {
        if (!$viewer) {
            header('Location: login.php');
            exit;
        }
        $profile = profile_find_by_id($db, $viewer['id']);
    } else {
        $profile = profile_find_by_username($db, $requested);
    }

    if ($profile) {
        $counts = profile_follow_counts($db, $profile['id']);
        $total  = $counts[$list];
        $pages  = max(1, (int) ceil($total / PROFILE_PAGE_SIZE));
        $page   = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 1]]);
        $page   = min((int) $page, $pages);
        $people = profile_follow_list($db, $profile['id'], $list, $page);
    }
} catch (Throwable $e) {
    error_log('[followers] ' . $e->getMessage());
    $failed = true;
    http_response_code(500);
}

if (!$failed && !$profile) {
    http_response_code(404);
}

/** URL of this page for a given list/page, keeping the artist in the query string. */
function followers_url(string $username, string $list, int $page = 1): string
{
    return 'followers.php?u=' . rawurlencode($username) . '&list=' . $list . ($page > 1 ? '&page=' . $page : '');
}

$isOwn = $profile && isset($viewer) && $viewer && $viewer['id'] === $profile['id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/profile.css">
    <title><?= $profile ? art_e(($list === 'following' ? 'Following' : 'Followers') . ' · @' . $profile['username']) : 'Followers' ?> | NSBM ArtCraft Platform</title>
</head>
<body>
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="profile-page">
        <div class="profile-container profile-container--narrow">
        <?php if ($failed): ?>
            <section class="profile-card profile-message" role="alert">
                <h1>List unavailable</h1>
                <p>We couldn't load this list right now. Please try again later.</p>
            </section>

        <?php elseif (!$profile): ?>
            <section class="profile-card profile-message">
                <h1>Artist not found</h1>
                <p>This profile doesn't exist or is no longer available.</p>
                <a class="profile-btn" href="index.php">Back to home</a>
            </section>

        <?php else: ?>
            <section class="profile-card people" aria-labelledby="people-title">
                <header class="people__head">
                    <a class="people__back" href="<?= art_e(profile_url($profile['username'])) ?>">&larr; <?= $isOwn ? 'My profile' : art_e(profile_full_name($profile)) ?></a>
                    <h1 id="people-title"><?= $isOwn ? 'My network' : art_e(profile_full_name($profile)) ?></h1>
                    <p class="profile-username">@<?= art_e($profile['username']) ?></p>
                </header>

                <nav class="people__tabs" aria-label="Follower lists">
                    <a href="<?= art_e(followers_url($profile['username'], 'followers')) ?>"<?= $list === 'followers' ? ' aria-current="page"' : '' ?>>
                        Followers <span><?= (int) $counts['followers'] ?></span>
                    </a>
                    <a href="<?= art_e(followers_url($profile['username'], 'following')) ?>"<?= $list === 'following' ? ' aria-current="page"' : '' ?>>
                        Following <span><?= (int) $counts['following'] ?></span>
                    </a>
                </nav>

                <?php if (!$people): ?>
                    <div class="profile-empty">
                        <h3><?= $list === 'followers' ? 'No followers yet' : 'Not following anyone yet' ?></h3>
                        <p>
                            <?php if ($list === 'followers'): ?>
                                <?= $isOwn ? 'When other artists follow you, they will appear here.' : 'Be the first to follow ' . art_e($profile['first_name']) . '.' ?>
                            <?php else: ?>
                                <?= $isOwn ? 'Visit an artist\'s profile and press Follow to see them here.' : art_e($profile['first_name']) . ' isn\'t following anyone yet.' ?>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <ul class="people__list">
                        <?php foreach ($people as $p): ?>
                            <li>
                                <a class="person" href="<?= art_e(profile_url((string) $p['username'])) ?>">
                                    <img class="person__avatar" src="<?= art_e(profile_avatar_url($p['profile_image_path'])) ?>"
                                         alt="" width="48" height="48" loading="lazy">
                                    <span class="person__text">
                                        <span class="person__name"><?= art_e(profile_full_name($p)) ?></span>
                                        <span class="person__username">@<?= art_e((string) $p['username']) ?></span>
                                    </span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if ($pages > 1): ?>
                        <nav class="people__pager" aria-label="Pages">
                            <?php if ($page > 1): ?>
                                <a class="profile-btn profile-btn--secondary profile-btn--small" href="<?= art_e(followers_url($profile['username'], $list, $page - 1)) ?>">&larr; Newer</a>
                            <?php endif; ?>
                            <span>Page <?= $page ?> of <?= $pages ?></span>
                            <?php if ($page < $pages): ?>
                                <a class="profile-btn profile-btn--secondary profile-btn--small" href="<?= art_e(followers_url($profile['username'], $list, $page + 1)) ?>">Older &rarr;</a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
        </div>
    </main>

    <?php include __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
