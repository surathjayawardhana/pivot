<?php
/**
 * Homepage "Featured artwork" showcase.
 *
 * Usage (index.php, between the search toolbar and the feed):
 *     <?php include __DIR__ . '/partials/showcase.php'; ?>
 *
 * It shows the ONE approved artwork whose featured_slot is '1'. If there is none
 * (or its image file is missing) the file returns without printing anything.
 *
 * Reuses: art_db(), art_e()                        (includes/artwork_helpers.php)
 *         profile_url(), profile_avatar_url(),
 *         profile_artwork_image_url()              (includes/profile_helpers.php)
 *
 * Tables used: artworks (id, title, description, image_path, status, featured_slot),
 *              users (first_name, surname, username, profile_image_path, account_status),
 *              categories (name)
 *
 * All variables start with $showcase so they cannot clash with index.php or posts.php.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/profile_helpers.php';   // also loads artwork_helpers.php

// featured_slot is ENUM('1','2','3','4'). The homepage showcase owns slot '1' only.
if (!defined('SHOWCASE_SLOT')) {
    define('SHOWCASE_SLOT', '1');
}

// ---- Load the featured artwork (approved only, active artist only) -------
try {
    $showcaseArt = art_db()->one(
        "SELECT a.id, a.title, a.description, a.image_path,
                u.first_name, u.surname, u.username, u.profile_image_path,
                c.name AS category_name
           FROM artworks a
           INNER JOIN users u ON u.id = a.user_id AND u.account_status = 'active'
           LEFT JOIN categories c ON c.id = a.category_id
          WHERE a.featured_slot = ? AND a.status = 'approved'
          LIMIT 1",
        [SHOWCASE_SLOT]
    );
} catch (Throwable $showcaseError) {
    error_log('[showcase] ' . $showcaseError->getMessage());
    return;
}

// Nothing is featured: print nothing at all.
if ($showcaseArt === null) {
    return;
}

// Only stored paths inside uploads/artworks that exist on disk give a URL ('' otherwise).
$showcaseImage = profile_artwork_image_url((string) $showcaseArt['image_path']);
if ($showcaseImage === '') {
    error_log('[showcase] Featured artwork #' . (int) $showcaseArt['id'] . ' has no usable image file.');
    return;
}

// ---- Prepare display values ------------------------------------------------
$showcaseId     = (int) $showcaseArt['id'];
$showcaseTitle  = (string) $showcaseArt['title'];
$showcaseArtist = trim((string) $showcaseArt['first_name'] . ' ' . (string) $showcaseArt['surname']);
if ($showcaseArtist === '') {
    $showcaseArtist = (string) $showcaseArt['username'];
}
$showcaseCategory = trim((string) ($showcaseArt['category_name'] ?? ''));
$showcaseAvatar   = profile_avatar_url($showcaseArt['profile_image_path'] ?? null);

// Short description: one line of text, cut near 180 characters.
$showcaseDescription = trim((string) preg_replace('/\s+/u', ' ', (string) $showcaseArt['description']));
if (mb_strlen($showcaseDescription, 'UTF-8') > 180) {
    $showcaseCut   = mb_substr($showcaseDescription, 0, 180, 'UTF-8');
    $showcaseSpace = mb_strrpos($showcaseCut, ' ', 0, 'UTF-8');
    if ($showcaseSpace !== false && $showcaseSpace > 120) {
        $showcaseCut = mb_substr($showcaseCut, 0, $showcaseSpace, 'UTF-8');
    }
    $showcaseDescription = rtrim($showcaseCut, " ,.;:-") . '…';
}

$showcaseArtworkUrl = 'artwork.php?id=' . $showcaseId;
?>
<link rel="stylesheet" href="css/showcase.css">

<section class="pivot-showcase" aria-labelledby="pivot-showcase-title">
    <div class="pivot-showcase__inner">

        <!-- The whole image opens the artwork page -->
        <a
            class="pivot-showcase__media"
            href="<?= art_e($showcaseArtworkUrl) ?>"
            aria-label="View artwork: <?= art_e($showcaseTitle) ?>"
        >
            <img
                class="pivot-showcase__image"
                src="<?= art_e($showcaseImage) ?>"
                alt="<?= art_e($showcaseTitle) ?>"
                decoding="async"
                fetchpriority="high"
            >
        </a>

        <div class="pivot-showcase__content">
            <p class="pivot-showcase__label">Featured artwork</p>

            <h2 class="pivot-showcase__title" id="pivot-showcase-title">
                <a href="<?= art_e($showcaseArtworkUrl) ?>"><?= art_e($showcaseTitle) ?></a>
            </h2>

            <?php if ($showcaseDescription !== ''): ?>
                <p class="pivot-showcase__description"><?= art_e($showcaseDescription) ?></p>
            <?php endif; ?>

            <div class="pivot-showcase__meta">
                <a
                    class="pivot-showcase__artist"
                    href="<?= art_e(profile_url((string) $showcaseArt['username'])) ?>"
                    aria-label="View <?= art_e($showcaseArtist) ?>'s profile"
                >
                    <img
                        class="pivot-showcase__avatar"
                        src="<?= art_e($showcaseAvatar) ?>"
                        alt=""
                        width="40"
                        height="40"
                        loading="lazy"
                    >
                    <span class="pivot-showcase__artist-name"><?= art_e($showcaseArtist) ?></span>
                </a>

                <?php if ($showcaseCategory !== ''): ?>
                    <a
                        class="pivot-showcase__category"
                        href="connect_categoy.php?category=<?= art_e(rawurlencode($showcaseCategory)) ?>"
                    >
                        <?= art_e($showcaseCategory) ?>
                    </a>
                <?php endif; ?>
            </div>

            
        </div>

    </div>
</section>
