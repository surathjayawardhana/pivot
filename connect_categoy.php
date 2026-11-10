
<?php
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}


require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/artwork_helpers.php';
require_once __DIR__ . '/includes/profile_helpers.php';
require_once __DIR__ . '/includes/like_helpers.php';

$artworks = [];
$selectedCategory = null;
$failed = false;

// Convert category names into URL-friendly slugs.
$makeSlug = static function (string $name): string {
    return trim(
        (string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)),
        '-'
    );
};

$requestedSlug = $makeSlug(trim((string) ($_GET['category'] ?? '')));

// Logged-in user's id (this page requires login), used to show which artworks
// they have already liked, and where the no-JavaScript like fallback returns to.
$viewerId   = art_session_user_id() ?? 0;
$likeReturn = 'connect_categoy.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');

try {
    $db = art_db();

    // Find the selected category using its URL slug.
    $categories = $db->all(
        "SELECT id, name FROM categories"
    );

    foreach ($categories as $category) {
        if ($makeSlug((string) $category['name']) === $requestedSlug) {
            $selectedCategory = $category;
            break;
        }
    }

    // Retrieve approved artworks belonging to that category.
    if ($selectedCategory !== null) {
        $artworks = $db->all(
            "SELECT
                a.id,
                a.title,
                a.description,
                a.image_path,
                a.created_at,
                c.name AS category_name,
                u.first_name,
                u.surname,
                u.username,
                u.profile_image_path,
                (SELECT COUNT(*) FROM artwork_likes l
                  WHERE l.artwork_id = a.id) AS like_count,
                EXISTS(SELECT 1 FROM artwork_likes lm
                        WHERE lm.artwork_id = a.id AND lm.user_id = ?) AS liked_by_me
             FROM artworks a
             INNER JOIN categories c
                ON c.id = a.category_id
             INNER JOIN users u
                ON u.id = a.user_id
             WHERE a.category_id = ?
               AND a.status = 'approved'
               AND u.account_status = 'active'
             ORDER BY a.created_at DESC, a.id DESC",
            [$viewerId, (int) $selectedCategory['id']]   // first ? is liked_by_me in the SELECT list
        );
    }

} catch (Throwable $e) {
    error_log('[connect_category] ' . $e->getMessage());
    $failed = true;
    http_response_code(500);
}

if (!$failed && $selectedCategory === null) {
    http_response_code(404);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/cate.css">
    <link rel="stylesheet" href="css/artists.css">
    <link rel="stylesheet" href="assets/css/likes4cate.css">
    <link rel="stylesheet" href="assets/css/comments.css">
    <title>
        <?= $selectedCategory ? art_e($selectedCategory['name']) : 'Category' ?>
        | NSBM ArtCraft
    </title>
</head>

<body>

    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="category-artworks-page">
        <div class="category-artworks-container">

            <?php if ($failed): ?>

                <h1>Something went wrong</h1>
                <p>We couldn't retrieve the artworks. Please try again.</p>

            <?php elseif (!$selectedCategory): ?>

                <h1>Category not found</h1>
                <p>The selected category does not exist.</p>
                <a href="category.php">Browse categories</a>

            <?php else: ?>

                <header class="category-artworks-intro">
                    <h1><?= art_e($selectedCategory['name']) ?></h1>
                    <p>Explore artworks shared in this category.</p>
                </header>

                <?php if (empty($artworks)): ?>

                    <p>No approved artworks are available in this category yet.</p>

                <?php else: ?>

                    <div class="category-artworks-grid">

                        <?php foreach ($artworks as $artwork):
                            $image = profile_artwork_image_url($artwork['image_path']);
                            $artist = profile_full_name($artwork);
                        ?>

                            <article class="category-artwork-card" id="art-<?= (int) $artwork['id'] ?>">

                                <a href="artwork.php?id=<?= (int) $artwork['id'] ?>">

                                    <?php if ($image !== ''): ?>
                                        <img
                                            src="<?= art_e($image) ?>"
                                            alt="<?= art_e($artwork['title']) ?>"
                                            loading="lazy"
                                            decoding="async"
                                        >
                                    <?php endif; ?>

                                    <h2><?= art_e($artwork['title']) ?></h2>

                                </a>

                                <p>
                                    By <?= art_e($artist) ?>
                                    (@<?= art_e($artwork['username']) ?>)
                                </p>

                                <?php if (!empty($artwork['description'])): ?>
                                    <p><?= art_e($artwork['description']) ?></p>
                                <?php endif; ?>

                                <div class="category-artwork-card__likes">
                                    <div class="art-card__actions">
                                        <?php /* Opens the artwork page at the comment box (partials/comments.php: id "comment-form-main-body"). */ ?>
                                        <a
                                            class="comment-link"
                                            href="artwork.php?id=<?= (int) $artwork['id'] ?>#comment-form-main-body"
                                            aria-label="Write a comment on <?= art_e($artwork['title']) ?>"
                                        >
                                            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" stroke-width="2"
                                                 stroke-linejoin="round" stroke-linecap="round">
                                                <path d="M21 11.5a8.4 8.4 0 0 1-8.9 8.4 9 9 0 0 1-3.5-.7L3 21l1.9-5.1A8.4 8.4 0 1 1 21 11.5z"></path>
                                            </svg>
                                            <span>Comment</span>
                                        </a>
                                        <?php art_like_button(
                                            (int) $artwork['id'],
                                            (string) $artwork['title'],
                                            (int) $artwork['like_count'],
                                            !empty($artwork['liked_by_me']),
                                            $likeReturn
                                        ); ?>
                                    </div>
                                </div>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            <?php endif; ?>

        </div>
    </main>

    <?php include __DIR__ . '/partials/footer.php'; ?>

    <script src="js/likes.js" defer></script>

</body>
</html>
