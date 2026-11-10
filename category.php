<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/includes/auth.php';
?>

<?php
$categoryPage = 'connect_categoy.php';

$categories = [
    ['name' => 'Digital Art',                    'image' => 'Digital Art.jpg',                    'description' => 'Digitally created paintings, illustrations, and experimental artwork.'],
    ['name' => 'Drawing & Illustration',         'image' => 'Drawing & Illustration.jpg',         'description' => 'Pencil sketches, ink drawings, portraits, comics, and hand-drawn illustrations.'],
    ['name' => 'Painting',                       'image' => 'Painting.jpg',                       'description' => 'Watercolor, acrylic, oil, abstract, and other painted artwork.'],
    ['name' => 'Photography',                    'image' => 'Photography.jpg',                    'description' => 'Portraits, landscapes, street photography, architecture, and creative photography.'],
    ['name' => 'Poetry',                         'image' => 'poem.jpg',                           'description' => 'Poems, verses, and spoken words that celebrate life, challenge convention, and give a voice to the thoughts left unspoken.'],
    ['name' => '3D Art & Sculpture',             'image' => '3D Art & Sculpture.jpg',             'description' => '3D models, character renders, sculptures, and photographs of sculptural work.'],
    ['name' => 'Crafts & Handmade',              'image' => 'Crafts & Handmade.jpg',              'description' => 'Paper crafts, pottery, handmade decorations, and creative physical objects.'],
    ['name' => 'Calligraphy & Typography',       'image' => 'Calligraphy & Typography.jpg',       'description' => 'Decorative lettering, calligraphy, typographic compositions, and hand lettering.'],
    ['name' => 'Fashion & Textile Design',       'image' => 'Fashion & Textile Design.jpg',       'description' => 'Fashion illustrations, clothing concepts, fabric patterns, and textile artwork.'],
    ['name' => 'Architecture & Interior Design', 'image' => 'Architecture & Interior Design.jpg', 'description' => 'Building concepts, architectural sketches, interior layouts, and design visualizations.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="css/style.css">
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <title>Categories | NSBM ArtCraft Platform</title>
</head>
<body>
    <?php include 'partials/header.php'; ?>

    <main>
        <section class="category-page" aria-labelledby="category-title">
            <div class="category-page__container">
                <header class="category-intro">
                    <h1 class="category-intro__title" id="category-title">Explore <span class="category-intro__accent">Categories</span></h1>
                    <p class="category-intro__text">Discover different types of art and find what inspires you.</p>
                </header>

                <ul class="category-grid">
                    <?php foreach ($categories as $category): ?>
                        <?php
                        $slug  = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($category['name'])), '-');
                        $href = $categoryPage . '?category=' . rawurlencode($category['name']);
                        $image = 'assets/images/' . rawurlencode($category['image']);
                        ?>
                        <li class="category-grid__item">
                            <a class="category-card" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
                                <span class="category-card__media">
                                    <img class="category-card__image" src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>" alt="" width="400" height="280" loading="lazy">
                                </span>
                                <span class="category-card__body">
                                    <h2 class="category-card__title"><?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                                    <p class="category-card__description"><?= htmlspecialchars($category['description'], ENT_QUOTES, 'UTF-8') ?></p>
                                    <svg class="category-card__arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                        <path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    </main>

    <?php include 'partials/footer.php'; ?>
</body>
</html>
