<?php
/**
 * Admin: Homepage Showcase manager (section only - no <html>, header or footer).
 *
 * Usage, inside <main> of admin/manage.php:
 *     <?php include __DIR__ . '/partials/edit-showcase.php'; ?>
 *
 * Shows the current showcase artwork and a grid of approved artworks to feature.
 * The buttons post to admin/showcase_action.php, which does the actual changes.
 *
 * Reuses: art_db(), art_e(), art_flash_pull()          (includes/artwork_helpers.php)
 *         profile_viewer(), profile_artwork_image_url() (includes/profile_helpers.php)
 *         csrf_token()                                  (includes/db.php)
 *
 * All variables start with $sa so they cannot clash with manage.php.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/profile_helpers.php';   // also loads artwork_helpers.php

// featured_slot is ENUM('1','2','3','4'). The homepage showcase owns slot '1' only.
if (!defined('SHOWCASE_SLOT')) {
    define('SHOWCASE_SLOT', '1');
}

const SHOWCASE_ADMIN_PAGE_SIZE = 24;

// ---- Administrators only (checked against the database) --------------------
try {
    $saViewer = profile_viewer(art_db());
} catch (Throwable $saError) {
    error_log('[edit-showcase] ' . $saError->getMessage());
    return;
}
if (!$saViewer || $saViewer['role'] !== 'admin') {
    return;
}

$saFlash = art_flash_pull();   // message left by showcase_action.php

// ---- Load data --------------------------------------------------------------
$saCurrent    = null;
$saArtworks   = [];
$saTotal      = 0;
$saLoadFailed = false;

$saPage = (int) filter_var($_GET['showcase_page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 100000, 'default' => 1],
]);

try {
    $saDb = art_db();

    // The current showcase artwork, whatever its status (so a rejected one can still be removed).
    $saCurrent = $saDb->one(
        'SELECT a.id, a.title, a.image_path, a.status,
                u.first_name, u.surname, u.account_status,
                c.name AS category_name
           FROM artworks a
           INNER JOIN users u ON u.id = a.user_id
           LEFT JOIN categories c ON c.id = a.category_id
          WHERE a.featured_slot = ?
          LIMIT 1',
        [SHOWCASE_SLOT]
    );

    // Approved artworks of active artists: the ones that can appear on the homepage.
    $saCount = $saDb->one(
        "SELECT COUNT(*) AS n
           FROM artworks a
           INNER JOIN users u ON u.id = a.user_id AND u.account_status = 'active'
          WHERE a.status = 'approved'"
    );
    $saTotal = (int) ($saCount['n'] ?? 0);

    $saPages = max(1, (int) ceil($saTotal / SHOWCASE_ADMIN_PAGE_SIZE));
    $saPage  = min($saPage, $saPages);

    $saArtworks = $saDb->all(
        "SELECT a.id, a.title, a.image_path, a.featured_slot,
                u.first_name, u.surname,
                c.name AS category_name
           FROM artworks a
           INNER JOIN users u ON u.id = a.user_id AND u.account_status = 'active'
           LEFT JOIN categories c ON c.id = a.category_id
          WHERE a.status = 'approved'
          ORDER BY a.created_at DESC, a.id DESC
          LIMIT ? OFFSET ?",
        [SHOWCASE_ADMIN_PAGE_SIZE, ($saPage - 1) * SHOWCASE_ADMIN_PAGE_SIZE]
    );
} catch (Throwable $saError) {
    error_log('[edit-showcase] ' . $saError->getMessage());
    $saLoadFailed = true;
}
$saPages = $saPages ?? 1;

// ---- Small helpers for this template ---------------------------------------
/** Thumbnail URL relative to admin/ ('' when the image is missing or the path is unsafe). */
$saThumb = static function (?string $rel): string {
    $url = profile_artwork_image_url($rel);
    return $url === '' ? '' : '../' . $url;
};
$saName = static function (array $row): string {
    $name = trim((string) $row['first_name'] . ' ' . (string) $row['surname']);
    return $name !== '' ? $name : 'Unknown artist';
};

// Why the current showcase artwork may not be visible on the homepage right now.
$saHiddenReason = null;
if ($saCurrent) {
    if ($saCurrent['status'] !== 'approved') {
        $saHiddenReason = 'This artwork is no longer approved, so it is hidden from the homepage.';
    } elseif ($saCurrent['account_status'] !== 'active') {
        $saHiddenReason = 'This artist’s account is not active, so the artwork is hidden from the homepage.';
    } elseif ($saThumb($saCurrent['image_path']) === '') {
        $saHiddenReason = 'The image file for this artwork is missing, so it is hidden from the homepage.';
    }
}

$saToken = csrf_token();
?>
<link rel="stylesheet" href="../css/showcase.css">
<section class="showcase-admin"  id="homepage-showcase" aria-labelledby="showcase-admin-title">

    <header class="showcase-admin__header">
        <h2 class="showcase-admin__title" id="showcase-admin-title">Homepage Showcase</h2>
        <p class="showcase-admin__intro">
            Choose one approved artwork to highlight at the top of the homepage.
            Featuring another artwork replaces the current one automatically.
        </p>
    </header>

    <?php if ($saFlash): ?>
        <p
            class="showcase-admin__notice showcase-admin__notice--<?= ($saFlash['type'] ?? '') === 'success' ? 'success' : 'error' ?>"
            role="<?= ($saFlash['type'] ?? '') === 'success' ? 'status' : 'alert' ?>"
        >
            <?= art_e((string) ($saFlash['message'] ?? '')) ?>
        </p>
    <?php endif; ?>

    <?php if ($saLoadFailed): ?>
        <p class="showcase-admin__notice showcase-admin__notice--error" role="alert">
            The showcase could not be loaded. Please refresh the page in a moment.
        </p>
    <?php else: ?>

        <!-- Current showcase -->
        <div class="showcase-admin__current">
            <h3 class="showcase-admin__subtitle">Currently featured</h3>

            <?php if ($saCurrent): ?>
                <?php $saCurrentThumb = $saThumb($saCurrent['image_path']); ?>
                <div class="showcase-admin__current-card">
                    <?php if ($saCurrentThumb !== ''): ?>
                        <img
                            class="showcase-admin__current-thumb"
                            src="<?= art_e($saCurrentThumb) ?>"
                            alt="<?= art_e((string) $saCurrent['title']) ?>"
                            loading="lazy"
                        >
                    <?php else: ?>
                        <div class="showcase-admin__current-thumb showcase-admin__thumb--missing">No image</div>
                    <?php endif; ?>

                    <div class="showcase-admin__current-info">
                        <p class="showcase-admin__artwork-title"><?= art_e((string) $saCurrent['title']) ?></p>
                        <p class="showcase-admin__artwork-meta">
                            <?= art_e($saName($saCurrent)) ?>
                            <?php if (!empty($saCurrent['category_name'])): ?>
                                <span aria-hidden="true">·</span> <?= art_e((string) $saCurrent['category_name']) ?>
                            <?php endif; ?>
                        </p>

                        <?php if ($saHiddenReason !== null): ?>
                            <p class="showcase-admin__warning"><?= art_e($saHiddenReason) ?></p>
                        <?php endif; ?>

                        <form class="showcase-admin__form" method="post" action="showcase_action.php">
                            <input type="hidden" name="csrf_token" value="<?= art_e($saToken) ?>">
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="artwork_id" value="<?= (int) $saCurrent['id'] ?>">
                            <input type="hidden" name="showcase_page" value="<?= $saPage ?>">
                            <button type="submit" class="showcase-admin__button showcase-admin__button--danger">
                                Remove from showcase
                            </button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <p class="showcase-admin__empty">
                    No artwork is featured, so the homepage showcase is hidden.
                </p>
            <?php endif; ?>
        </div>

        <!-- Artworks that can be featured -->
        <h3 class="showcase-admin__subtitle">Approved artworks</h3>

        <?php if (!$saArtworks): ?>
            <p class="showcase-admin__empty">There are no approved artworks to feature yet.</p>
        <?php else: ?>
            <ul class="showcase-admin__grid">
                <?php foreach ($saArtworks as $saArt): ?>
                    <?php
                    $saSlot       = $saArt['featured_slot'] === null ? '' : (string) $saArt['featured_slot'];
                    $saIsFeatured = $saSlot === SHOWCASE_SLOT;
                    $saOtherSlot  = $saSlot !== '' && !$saIsFeatured;   // used by a different featured slot
                    $saThumbUrl   = $saThumb($saArt['image_path']);
                    ?>
                    <li class="showcase-admin__card<?= $saIsFeatured ? ' showcase-admin__card--featured' : '' ?>">
                        <?php if ($saThumbUrl !== ''): ?>
                            <img
                                class="showcase-admin__thumb"
                                src="<?= art_e($saThumbUrl) ?>"
                                alt="<?= art_e((string) $saArt['title']) ?>"
                                loading="lazy"
                            >
                        <?php else: ?>
                            <div class="showcase-admin__thumb showcase-admin__thumb--missing">No image</div>
                        <?php endif; ?>

                        <div class="showcase-admin__card-body">
                            <p class="showcase-admin__artwork-title"><?= art_e((string) $saArt['title']) ?></p>
                            <p class="showcase-admin__artwork-meta">
                                <?= art_e($saName($saArt)) ?>
                                <?php if (!empty($saArt['category_name'])): ?>
                                    <span aria-hidden="true">·</span> <?= art_e((string) $saArt['category_name']) ?>
                                <?php endif; ?>
                            </p>

                            <?php if ($saIsFeatured): ?>
                                <span class="showcase-admin__badge">Currently Featured</span>
                            <?php elseif ($saOtherSlot): ?>
                                <button type="button" class="showcase-admin__button" disabled>
                                    Uses slot <?= art_e($saSlot) ?>
                                </button>
                            <?php else: ?>
                                <form method="post" action="showcase_action.php">
                                    <input type="hidden" name="csrf_token" value="<?= art_e($saToken) ?>">
                                    <input type="hidden" name="action" value="feature">
                                    <input type="hidden" name="artwork_id" value="<?= (int) $saArt['id'] ?>">
                                    <input type="hidden" name="showcase_page" value="<?= $saPage ?>">
                                    <button type="submit" class="showcase-admin__button showcase-admin__button--primary">
                                        Feature artwork
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($saPages > 1): ?>
                <nav class="showcase-admin__pager" aria-label="Artwork pages">
                    <?php if ($saPage > 1): ?>
                        <a href="?showcase_page=<?= $saPage - 1 ?>#homepage-showcase">Previous</a>
                    <?php endif; ?>
                    <span>Page <?= $saPage ?> of <?= $saPages ?></span>
                    <?php if ($saPage < $saPages): ?>
                        <a href="?showcase_page=<?= $saPage + 1 ?>#homepage-showcase">Next</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>

    <?php endif; ?>

</section>
