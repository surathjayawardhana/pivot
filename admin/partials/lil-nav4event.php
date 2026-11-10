<?php
/**
 * NSBM ArtCraft - slim secondary navigation bar for admin pages.
 *
 * Usage: include it on an admin page, straight after the main header:
 *
 *     <?php include __DIR__ . '/partials/header.php'; ?>
 *     <?php include __DIR__ . '/partials/lil-nav.php'; ?>
 *
 * Self-contained: the CSS is in the <style> block below, so no other file is needed.
 * It must stay a direct child of <body>, next to the header, for the sticky effect to work.
 *
 * The current page is found from the URL (like partials/header.php), so the active link
 * updates by itself. A link is only printed if its target file exists.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';   // for e()

// label | href (relative to pages inside /admin/) | file that must exist | page file name for the active state
$lilNavItems = [
    [
        'label' => 'Add Event',
        'href'  => 'add-event.php',
        'file'  => __DIR__ . '/../add-event.php',
        'page'  => 'add-event.php',
        'end'   => false
    ],
    [
        'label' => 'Edit Event',
        'href'  => 'edit-event.php',
        'file'  => __DIR__ . '/../edit-event.php',
        'page'  => 'edit-event.php',
        'end'   => false
    ],
];
   


$lilNavCurrent = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
?>
<style>
/* Slim admin sub-navigation. The main header is not fixed (it scrolls away), so this bar
   is "sticky": it sits right under the header and then stays at the top of the screen,
   whatever the header's height is, without hiding any page content. */
/* Box sizing */
.lil-nav,
.lil-nav *,
.lil-nav *::before,
.lil-nav *::after {
    box-sizing: border-box;
}

/* Main secondary navigation */
.lil-nav {
    --lil-nav-height: 40px;

    position: -webkit-sticky;
    position: sticky;
    top: 0;
    z-index: 90;

    width: 100%;
    background: rgba(220, 220, 224, 0.97);

    font-family: var(--art-font-body, "Roboto", "Segoe UI", system-ui, sans-serif);

    box-shadow: 0 5px 6px -5px rgba(0, 0, 0, 0.35);
}

/* Navigation list */
.lil-nav__list {
    display: flex;
    align-items: center;
    gap: 0.25rem;

    /*width: min(calc(100% - 2rem), 90rem);*/
    width: 100%;
    margin: 0;
    height: var(--lil-nav-height);

    /*margin: 0 auto;*/
    padding: 0;
    list-style: none;
    white-space: nowrap;

    /* Horizontal scrolling on mobile */
    overflow-x: auto;
    overflow-y: hidden;
    overscroll-behavior-x: contain;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
}

/* Hide scrollbar */
.lil-nav__list::-webkit-scrollbar {
    display: none;
}

/* Navigation items */
.lil-nav__item {
    flex: 0 0 auto;
}

/* Optional right-aligned item (e.g. View Site) */
.lil-nav__item--end {
    margin-left: 0;
}

/* Navigation links */
.lil-nav__link {
    display: flex;
    align-items: center;

    height: var(--lil-nav-height);
    padding: 0 0.75rem;

    color: rgba(26, 24, 24, 0.8);
    background: transparent;

    font-family: inherit;
    font-size: 14px;
    font-weight: 500;
    letter-spacing: 0.02em;

    text-decoration: none;
    transition: color 0.2s ease;
}

/* Hover */
.lil-nav__link:hover {
    color: #AAAAAA;
}

/* Active page */
.lil-nav__link[aria-current="page"] {
    color: #3BA73C;
}

/* Keyboard accessibility */
.lil-nav__link:focus-visible {
    outline: 2px solid #3BA73C;
    outline-offset: -2px;
    border-radius: 4px;
}
</style>

<nav class="lil-nav" aria-label="Admin sections">
    <ul class="lil-nav__list">
        <?php foreach ($lilNavItems as $lilNavItem): ?>
            <?php if (!is_file($lilNavItem['file'])) { continue; } ?>
            <li class="lil-nav__item<?= $lilNavItem['end'] ? ' lil-nav__item--end' : '' ?>">
                <a
                    class="lil-nav__link"
                    href="<?= e($lilNavItem['href']) ?>"
                    <?= ($lilNavItem['page'] !== null && $lilNavCurrent === $lilNavItem['page']) ? 'aria-current="page"' : '' ?>
                >
                    <?= e($lilNavItem['label']) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
