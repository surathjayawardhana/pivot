<?php
declare(strict_types=1);

// Load the database connection and shared helpers.
require_once __DIR__ . '/../../includes/db.php';

// Only authenticated administrators may access this page.
if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'admin'
) {
    header('Location: ../login.php');
    exit;
}
?>
<?php
/**
 * NSBM ArtCraft - Admin site header.
 *
 * Used by pages inside the admin folder.
 * Navigation links point to admin pages unless marked "View Site".
 */

// Load the shared database, session, and helper functions.
require_once __DIR__ . '/../../includes/db.php';

// Admin navigation items.
$navItems = [
 ///   [
    #    'label' => 'Dashboard',
    #    'href' => 'index.php',
    #    'active_page' => 'index.php',
 ///   ],
    [
        'label' => 'Manage',
        'href' => 'manage.php',
        'active_page' => 'manage.php',
    ],
    [
        'label' => 'Events',
        'href' => 'add-event.php',
        'active_page' => 'add-event.php',
    ],
    [
        'label' => 'Generate',
        'href' => 'generate.php',
        'active_page' => 'generate.php',
    ],

];

// Identify the currently open page.
$navCurrentPage = basename($_SERVER['SCRIPT_NAME']);

// Find an existing avatar image, if available.
$navAvatarSrc = null;

$navAvatarFiles = [
    'profile.png',
    'profile.jpg',
    'profile.jpeg',
    'profile.webp',
    'avatar.png',
    'avatar.jpg',
    'avatar.jpeg',
    'avatar.webp',
];

foreach ($navAvatarFiles as $navAvatarFile) {
    $navAvatarPath = __DIR__ . '/../../assets/images/' . $navAvatarFile;

    if (is_file($navAvatarPath)) {
        // URL path relative to pages inside /admin/.
        $navAvatarSrc = '../assets/images/' . rawurlencode($navAvatarFile);
        break;
    }
}

?>

<?php

if (!empty($_SESSION['user_id'])) {

    try {

        $projectRoot = dirname(__DIR__, 2);

        require_once $projectRoot . '/includes/artwork_helpers.php';

        $navUser = art_db()->one(
            'SELECT profile_image_path FROM users WHERE id = ?',
            [(int) $_SESSION['user_id']]
        );

        $storedImage = trim(
            (string) ($navUser['profile_image_path'] ?? '')
        );

        if ($storedImage !== '') {

            $filename = basename(
                str_replace('\\', '/', $storedImage)
            );

            $absolutePath = $projectRoot
                . '/uploads/profiles/'
                . $filename;

            if (is_file($absolutePath)) {

                // Relative to pages inside pivot/admin/.
                $navAvatarSrc = '../uploads/profiles/'
                    . rawurlencode($filename)
                    . '?v=' . filemtime($absolutePath);

            } else {

                error_log(
                    '[admin/header] Avatar file does not exist: '
                    . $absolutePath
                );
            }
        }

    } catch (Throwable $navError) {

        error_log(
            '[admin/header] Avatar error: '
            . $navError->getMessage()
        );
    }
}

?>
<link rel="stylesheet" href="../css/header.css">

<header class="site-header">

    <!-- Announcement bar -->
    <div class="site-header__announcement">
        <p class="site-header__announcement-text">
            NSBM ARTCRAFT PLATFORM
        </p>
    </div>

    <!-- Main admin navigation -->
    <div class="site-header__bar">
        <nav class="site-nav" aria-label="Admin navigation">

            <!-- Admin dashboard logo -->
            <a
                class="site-nav__logo"
                href="index.php"
                aria-label="Admin dashboard"
            >
                <img
                    class="site-nav__logo-img"
                    src="../assets/images/Nart_Logo.png"
                    alt="NSBM ArtCraft logo"
                >
            </a>

            <!-- Mobile navigation toggle -->
            <button
                class="site-nav__toggle"
                type="button"
                aria-label="Toggle navigation menu"
                aria-expanded="false"
                aria-controls="site-nav-menu"
            >
                <span class="site-nav__toggle-bar"></span>
                <span class="site-nav__toggle-bar"></span>
                <span class="site-nav__toggle-bar"></span>
            </button>

            <!-- Admin navigation links -->
            <ul class="site-nav__menu" id="site-nav-menu">
                <?php foreach ($navItems as $navItem): ?>
                    <li>
                        <a
                            class="site-nav__link"
                            href="<?= e($navItem['href']) ?>"
                            <?php if (
                                $navItem['active_page'] !== null &&
                                $navCurrentPage === $navItem['active_page']
                            ): ?>
                                aria-current="page"
                            <?php endif; ?>
                        >
                            <?= e($navItem['label']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <!-- Profile link -->
            <a
                class="site-nav__profile"
                href="../admin-profile.php"
                aria-label="My Profile"
                <?= $navCurrentPage === 'profile.php'
                    ? 'aria-current="page"'
                    : '' ?>
            >
                <?php if ($navAvatarSrc !== null): ?>

                    <img
                        class="site-nav__profile-img"
                        src="<?= e($navAvatarSrc) ?>"
                        alt=""
                    >

                <?php else: ?>

                    <!-- Default profile icon -->
                    <svg
                        class="site-nav__profile-icon"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                        focusable="false"
                    >
                        <circle
                            cx="12"
                            cy="8"
                            r="4"
                            fill="currentColor"
                        />
                        <path
                            d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7z"
                            fill="currentColor"
                        />
                    </svg>

                <?php endif; ?>
            </a>

            <!-- Logout button -->
            <?php if (!empty($_SESSION['user_id'])): ?>
                <form action="../logout.php" method="POST">

                    <!-- CSRF protection -->
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(csrf_token()) ?>"
                    >

                    <button
                        type="submit"
                        class="site-nav__logout"
                    >
                        Logout
                    </button>

                </form>
            <?php endif; ?>

        </nav>
    </div>
</header>

<!-- Admin pages use the shared navigation JavaScript. -->
<script src="../js/header.js"></script>