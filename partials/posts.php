<?php
/**
 * Artwork feed.
 *
 * Expects $search and $sort from index.php (already validated).
 * Uses art_db(), art_e() and art_asset_url() from includes/artwork_helpers.php.
 *
 * Tables used (all from the existing schema):
 *   artworks       id, user_id, category_id, title, description, image_path, status, created_at
 *   users          id, first_name, surname, username, profile_image_path
 *   categories     id, name
 *   artwork_likes  artwork_id, user_id   (one row per like)
 *   artwork_comments  artwork_id, deleted_at   (counted only; no comment text is loaded here)
 *
 * The like button comes from art_like_button() in includes/like_helpers.php and is
 * handled by like_action.php + js/likes.js (index.php loads the css and js).
 */
require_once dirname(__DIR__) . '/includes/like_helpers.php';

// The logged-in user's id (0 for guests, who never match a row), used to show
// which cards they have already liked.
$viewerId = art_session_user_id() ?? 0;

$projectRoot = dirname(__DIR__);   // folder that contains index.php and uploads/

// ---- Sorting: fixed ORDER BY clauses; user input only picks a key -------
$orderByMap = [
    'newest'     => 'a.created_at DESC, a.id DESC',
    'oldest'     => 'a.created_at ASC, a.id ASC',
    'most_liked' => 'like_count DESC, a.created_at DESC, a.id DESC',
];
$orderBy = $orderByMap[$sort] ?? $orderByMap['newest'];

// ---- Query ---------------------------------------------------------------
// like_count and comment_count are subqueries, so there is exactly one row per
// artwork (zero likes / zero comments are still returned) and the whole feed
// stays one query. comment_count = comments + replies that are not deleted.
$commentCountSql = '(SELECT COUNT(*) FROM artwork_comments cm WHERE cm.artwork_id = a.id AND cm.deleted_at IS NULL)';

$sql = "SELECT a.id, a.user_id, a.title, a.description, a.image_path, a.created_at,
               u.first_name, u.surname, u.username, u.profile_image_path,
               c.name AS category_name,
               (SELECT COUNT(*) FROM artwork_likes l WHERE l.artwork_id = a.id) AS like_count,
               $commentCountSql AS comment_count,
               EXISTS(SELECT 1 FROM artwork_likes lm
                       WHERE lm.artwork_id = a.id AND lm.user_id = ?) AS liked_by_me
        FROM artworks a
        INNER JOIN users u ON u.id = a.user_id
        LEFT JOIN categories c ON c.id = a.category_id
        WHERE a.status = 'approved'";
$params = [$viewerId];   // first ? is the liked_by_me check in the SELECT list

if ($search !== '') {
    // Escape LIKE wildcards so a typed % or _ is searched literally.
    $like = '%' . addcslashes($search, '\\%_') . '%';

    $sql .= " AND (
        a.title LIKE ?
        OR a.description LIKE ?
        OR c.name LIKE ?
    )";

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= ' ORDER BY ' . $orderBy;

$artworks          = [];
$loadFailed        = false;
$commentsAvailable = true;
foreach ([true, false] as $withComments) {
    try {
        $artworks          = art_db()->all($withComments ? $sql : str_replace($commentCountSql, '0', $sql), $params);
        $commentsAvailable = $withComments;
        $loadFailed        = false;
        break;
    } catch (Throwable $e) {
        error_log('PIVOT feed query failed: ' . $e->getMessage());
        $loadFailed = true;
        // 42S02 = table not found: the comments migration has not been run yet.
        // Retry once without the counts so the feed itself keeps working.
        if (!($e instanceof PDOException) || ($e->errorInfo[0] ?? '') !== '42S02') {
            break;
        }
    }
}

// ---- Small helpers for this template -------------------------------------
$fileExists = static function (?string $rel) use ($projectRoot): bool {
    if ($rel === null || $rel === '' || str_contains($rel, '..')) {
        return false;
    }
    return is_file($projectRoot . '/' . $rel);
};

$total = count($artworks);

// Where the no-JavaScript fallback returns to after liking (same search/sort).
$likeReturn = 'index.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
?>
<section class="pivot-feed" id="artwork-feed" aria-label="Artworks">
    <div class="pivot-feed__inner">

        <?php if ($loadFailed): ?>
            <div class="pivot-feed__empty">
                <h2 class="pivot-feed__empty-title">Artworks could not be loaded</h2>
                <p class="pivot-feed__empty-text">Please refresh the page in a moment.</p>
            </div>

        <?php elseif ($total === 0 && $search !== ''): ?>
            <div class="pivot-feed__empty">
                <h2 class="pivot-feed__empty-title">No artworks match “<?= art_e($search) ?>”</h2>
                <p class="pivot-feed__empty-text">Check the spelling or try a different word.</p>
                <a class="pivot-feed__empty-link" href="index.php?sort=<?= art_e(rawurlencode($sort)) ?>">Clear search</a>
            </div>

        <?php elseif ($total === 0): ?>
            <div class="pivot-feed__empty">
                <h2 class="pivot-feed__empty-title">No artworks yet</h2>
                <p class="pivot-feed__empty-text">Approved artworks will appear here.</p>
            </div>

        <?php else: ?>
            <p class="pivot-feed__summary">
                <?php if ($search !== ''): ?>
                    <?= $total ?> <?= $total === 1 ? 'result' : 'results' ?> for “<?= art_e($search) ?>”
                    <a class="pivot-feed__clear" href="index.php?sort=<?= art_e(rawurlencode($sort)) ?>">Clear</a>
                <?php else: ?>
                    <?= $total ?> <?= $total === 1 ? 'artwork' : 'artworks' ?>
                <?php endif; ?>
            </p>

            <div class="pivot-feed__grid">
                <?php foreach ($artworks as $art): ?>
                    <?php
                    $title       = (string) $art['title'];
                    $description = trim((string) $art['description']);
                    $artistName  = trim($art['first_name'] . ' ' . $art['surname']);
                    $initial     = mb_strtoupper(mb_substr($artistName !== '' ? $artistName : '?', 0, 1, 'UTF-8'), 'UTF-8');
                    $likes       = (int) $art['like_count'];
                    $likedByMe   = !empty($art['liked_by_me']);
                    $comments    = (int) ($art['comment_count'] ?? 0);
                    $hasImage    = $fileExists($art['image_path']);
                    $hasAvatar   = $fileExists($art['profile_image_path']);
                    $uploaded    = $art['created_at'] ? strtotime((string) $art['created_at']) : false;
                    ?>
                    <article class="art-card" id="art-<?= (int) $art['id'] ?>">
                        <a
                            class="art-card__media-link"
                            href="artwork.php?id=<?= (int) $art['id'] ?>"
                            aria-label="View artwork: <?= art_e($title) ?>"
                        >
                            <div class="art-card__media">
                                <?php if ($hasImage): ?>
                                    <img
                                        class="art-card__image"
                                        src="<?= art_e(art_asset_url($art['image_path'])) ?>"
                                        alt="<?= art_e($title) ?>"
                                        loading="lazy"
                                    >
                                <?php else: ?>
                                    <div class="art-card__placeholder">
                                        Image unavailable
                                    </div>
                                <?php endif; ?>
                            </div>
                        </a>

                        <div class="art-card__body">
                                <?php if (!empty($art['category_name'])): ?>
                                    <a
                                        class="art-card__category"
                                        href="connect_categoy.php?category=<?= rawurlencode((string) $art['category_name']) ?>"
                                    >
                                        <?= art_e($art['category_name']) ?>
                                    </a>
                                <?php endif; ?>

                                <h2 class="art-card__title">
                                    <a href="artwork.php?id=<?= (int) $art['id'] ?>">
                                        <?= art_e($title) ?>
                                    </a>
                                </h2>
                            <?php if ($description !== ''): ?>
                                <p class="art-card__description"><?= art_e($description) ?></p>
                            <?php endif; ?>

                            <div class="art-card__footer">
                                <div class="art-card__artist">
                                <a
                                    class="art-card__avatar-link"
                                    href="profile.php?u=<?= rawurlencode((string) $art['username']) ?>"
                                    aria-label="View <?= art_e($artistName) ?>'s profile"
                                >
                                    <?php if ($hasAvatar): ?>
                                        <img
                                            class="art-card__avatar"
                                            src="<?= art_e(art_asset_url($art['profile_image_path'])) ?>"
                                            alt=""
                                            loading="lazy"
                                        >
                                    <?php else: ?>
                                        <span class="art-card__avatar art-card__avatar--initial" aria-hidden="true">
                                            <?= art_e($initial) ?>
                                        </span>
                                    <?php endif; ?>
                                </a>
                                    <div class="art-card__artist-text">
                                        <a
                                            class="art-card__artist-name"
                                            href="profile.php?u=<?= rawurlencode((string) $art['username']) ?>"
                                        >
                                            <?= art_e($artistName) ?>
                                        </a>
                                        <?php if ($uploaded): ?>
                                            <time class="art-card__date" datetime="<?= art_e(date('Y-m-d', $uploaded)) ?>">
                                                <?= art_e(date('M j, Y', $uploaded)) ?>
                                            </time>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="art-card__actions">
                                    <?php if ($commentsAvailable): ?>
                                        <a
                                            class="comment-link"
                                            href="artwork.php?id=<?= (int) $art['id'] ?>#comments"
                                            aria-label="<?= $comments ?> <?= $comments === 1 ? 'comment' : 'comments' ?> on <?= art_e($title) ?>. View discussion"
                                        >
                                            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" stroke-width="2"
                                                 stroke-linejoin="round" stroke-linecap="round">
                                                <path d="M21 11.5a8.4 8.4 0 0 1-8.9 8.4 9 9 0 0 1-3.5-.7L3 21l1.9-5.1A8.4 8.4 0 1 1 21 11.5z"></path>
                                            </svg>
                                            <span class="comment-link__count"><?= $comments ?></span>
                                        </a>
                                    <?php endif; ?>
                                    <?php art_like_button((int) $art['id'], $title, $likes, $likedByMe, $likeReturn); ?>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</section>
