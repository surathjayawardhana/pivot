<?php
require_once __DIR__ . '/../includes/db.php';
?>
<?php
/**
 * Site header (announcement bar + main navigation).
 * Usage, inside <body> of a page in the project root:
 *     <?php include 'partials/header.php'; ?>
 */

// Page filenames: adjust here if your project uses different names.
define('BASE_URL','');
$navHomePage = BASE_URL . 'admin/index.php';
$navmnPage = BASE_URL . 'admin/manage.php';
$navgnPage = BASE_URL . 'admin/generate.php';
$navevPage = BASE_URL . 'admin/add-event.php';

$navProfilePage = 'profile.php';
$navItems = [
    ['label' => 'Manage',      'href' => $navmnPage],
    ['label' => 'Events',      'href' => $navevPage],
    ['label' => 'Generate',      'href' => $navgnPage],
  
];

$navCurrentPage = basename($_SERVER['SCRIPT_NAME']);

// Use the first existing avatar image; otherwise the SVG fallback icon is shown.
$navAvatarSrc = null;
foreach (['profile.png', 'profile.jpg', 'profile.jpeg', 'profile.webp', 'avatar.png', 'avatar.jpg', 'avatar.jpeg', 'avatar.webp'] as $navAvatarFile) {
    if (is_file(__DIR__ . '/../assets/images/' . $navAvatarFile)) {
        $navAvatarSrc = 'assets/images/' . $navAvatarFile;
        break;
    }
}

// A logged-in user's own profile picture takes priority. If anything fails (for example the
// profile migration has not been run yet) the fallback above is kept and the page still loads.
if (!empty($_SESSION['user_id'])) {
    try {
        require_once __DIR__ . '/../includes/profile_helpers.php';
        $navUser   = art_db()->one('SELECT profile_image_path FROM users WHERE id = ?', [(int) $_SESSION['user_id']]);
        $navCustom = profile_custom_avatar_url($navUser['profile_image_path'] ?? null);
        if ($navCustom !== null) {
            $navAvatarSrc = $navCustom;
        }
    } catch (Throwable $navError) {
        error_log('[header] avatar: ' . $navError->getMessage());
    }
}
?>
<link rel="stylesheet" href="css/header.css">


<header class="site-header">
    <div class="site-header__announcement">
        <p class="site-header__announcement-text">NSBM ARTCRAFT PLATFORM</p>
    </div>

    <div class="site-header__bar">
        <nav class="site-nav" aria-label="Main navigation">
            <a class="site-nav__logo" href="<?= $navHomePage ?>" aria-label="NSBM ArtCraft home">
                <img class="site-nav__logo-img" src="assets/images/Nart_Logo.png" alt="NSBM ArtCraft logo">
            </a>

            <button class="site-nav__toggle" type="button" aria-label="Menu" aria-expanded="false" aria-controls="site-nav-menu">
                <span class="site-nav__toggle-bar"></span>
                <span class="site-nav__toggle-bar"></span>
                <span class="site-nav__toggle-bar"></span>
            </button>

            <ul class="site-nav__menu" id="site-nav-menu">
                <?php foreach ($navItems as $navItem): ?>
                    <li>
                        <a class="site-nav__link" href="<?= $navItem['href'] ?>"<?= $navCurrentPage === $navItem['href'] ? ' aria-current="page"' : '' ?>><?= $navItem['label'] ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <a class="site-nav__profile" href="<?= $navProfilePage ?>" aria-label="My Profile"<?= $navCurrentPage === $navProfilePage ? ' aria-current="page"' : '' ?>>
                <?php if ($navAvatarSrc): ?>
                    <img class="site-nav__profile-img" src="<?= $navAvatarSrc ?>" alt="">
                <?php else: ?>
                    <svg class="site-nav__profile-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <circle cx="12" cy="8" r="4" fill="currentColor"/>
                        <path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7z" fill="currentColor"/>
                    </svg>
                <?php endif; ?>
            </a>
    
            <!-- Logout button -->
            <?php if (!empty($_SESSION['user_id'])): ?>
                <form action="/pivot/logout.php" method="POST">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(csrf_token()) ?>"
                    >

                    <button type="submit" class="site-nav__logout">
                        Logout
                    </button>
                </form>
            <?php endif; ?>
        </nav>
    </div>
</header>
<script src="js/header.js"></script>
