<?php
/**
 * Likes for artworks (table: artwork_likes).
 *
 *   artwork_likes  artwork_id, user_id, created_at
 *                  PRIMARY KEY (artwork_id, user_id)  -> one like per user per artwork
 *
 * Builds on includes/artwork_helpers.php (ArtDb, art_e(), art_csrf_token()).
 * Used by: like_action.php, partials/posts.php and artwork.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/artwork_helpers.php';

/** Number of likes an artwork has. */
function art_like_count(ArtDb $db, int $artworkId): int
{
    $row = $db->one('SELECT COUNT(*) AS c FROM artwork_likes WHERE artwork_id = ?', [$artworkId]);
    return (int) ($row['c'] ?? 0);
}

/** True when this user has already liked this artwork. */
function art_like_exists(ArtDb $db, int $artworkId, int $userId): bool
{
    return $db->one(
        'SELECT 1 AS x FROM artwork_likes WHERE artwork_id = ? AND user_id = ?',
        [$artworkId, $userId]
    ) !== null;
}

/**
 * Adds a like. Safe to repeat: a second like from the same user (double click,
 * two tabs) changes nothing instead of raising a duplicate-key error.
 */
function art_like_add(ArtDb $db, int $artworkId, int $userId): void
{
    $db->run(
        'INSERT INTO artwork_likes (artwork_id, user_id) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE artwork_id = artwork_id',
        [$artworkId, $userId]
    );
}

/** Removes a like. Returns false when there was nothing to remove. */
function art_like_remove(ArtDb $db, int $artworkId, int $userId): bool
{
    return $db->run(
        'DELETE FROM artwork_likes WHERE artwork_id = ? AND user_id = ?',
        [$artworkId, $userId]
    ) > 0;
}

/**
 * Where the no-JavaScript fallback may redirect to. Only the two pages that show
 * the like button are accepted, so the "return" field can never become an open
 * redirect. Anything else goes to the homepage.
 */
function art_like_return_url(string $raw, int $artworkId): string
{
    $raw = trim($raw);

    if (preg_match('~^index\.php(\?[A-Za-z0-9_=&%.+\-]*)?$~', $raw)) {
        return $raw . '#art-' . $artworkId;   // land back on the same card
    }
    if (preg_match('~^artwork\.php\?id=[0-9]{1,10}$~', $raw)) {
        return $raw;
    }
    return 'index.php#art-' . $artworkId;
}

/**
 * Prints the like button (a small form so it also works without JavaScript).
 * js/likes.js upgrades it to update in place.
 *
 * @param string $returnTo  index.php[?query] or artwork.php?id=N (see art_like_return_url)
 */
function art_like_button(int $artworkId, string $title, int $count, bool $liked, string $returnTo): void
{
    $label = ($liked ? 'Unlike artwork: ' : 'Like artwork: ') . $title;
    ?>
    <form class="like-form" method="post" action="like_action.php" data-like-form>
        <input type="hidden" name="csrf_token" value="<?= art_e(art_csrf_token()) ?>">
        <input type="hidden" name="artwork_id" value="<?= $artworkId ?>">
        <input type="hidden" name="action" value="<?= $liked ? 'unlike' : 'like' ?>" data-like-action>
        <input type="hidden" name="return" value="<?= art_e($returnTo) ?>">
        <button
            type="submit"
            class="like-btn<?= $liked ? ' is-liked' : '' ?>"
            data-like-button
            data-title="<?= art_e($title) ?>"
            aria-pressed="<?= $liked ? 'true' : 'false' ?>"
            aria-label="<?= art_e($label) ?>"
        >
            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" stroke="currentColor"
                 stroke-width="2" stroke-linejoin="round">
                <path d="M12 20.5s-7.5-4.6-9.2-9.3C1.7 8 3.6 4.8 6.9 4.8c1.9 0 3.5 1 5.1 3 1.6-2 3.2-3 5.1-3 3.3 0 5.2 3.2 4.1 6.4-1.7 4.7-9.2 9.3-9.2 9.3z"></path>
            </svg>
            <span class="like-btn__count" data-like-count><?= $count ?></span>
        </button>
    </form>
    <?php
}
