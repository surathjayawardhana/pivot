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
        'label' => 'Showcase Edit',
        'href'  => '#homepage-showcase',
        'file'  => __DIR__ . '/../manage.php',
        'page'  => null,
        'end'   => false
    ],
    [
        'label' => 'Artwork Management',
        'href'  => '#artwork-management',
        'file'  => __DIR__ . '/../manage.php',
        'page'  => null,
        'end'   => false
    ],
    [
        'label' => 'User Management',
        'href'  => '#user-management',
        'file'  => __DIR__ . '/../manage.php',
        'page'  => null,
        'end'   => false
    ],
];

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
.lil-nav__link[aria-current="location"] {
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
                >
                    <?= e($lilNavItem['label']) ?>
                </a>

            </li>

        <?php endforeach; ?>

    </ul>
</nav>

<script>
(() => {

    function initializeLilNav() {

        document.querySelectorAll('.lil-nav').forEach(nav => {

            const links = [
                ...nav.querySelectorAll('.lil-nav__link[href^="#"]')
            ];

            // Connect each navigation link to its actual section.
            const items = links.map(link => {

                const id = decodeURIComponent(
                    link.getAttribute('href').slice(1)
                );

                return {
                    link: link,
                    section: document.getElementById(id)
                };

            }).filter(item => item.section);

            if (!items.length) {
                console.warn('Lil-nav: No matching sections found.');
                return;
            }

            // Highlight one navigation link.
            function setActive(activeItem) {

                items.forEach(item => {

                    if (item === activeItem) {

                        item.link.setAttribute(
                            'aria-current',
                            'location'
                        );

                    } else {

                        item.link.removeAttribute('aria-current');

                    }

                });

            }

            // Highlight the section in the URL on initial load.
            function activateFromHash() {

                const id = decodeURIComponent(
                    window.location.hash.slice(1)
                );

                const matchingItem = items.find(
                    item => item.section.id === id
                );

                if (matchingItem) {
                    setActive(matchingItem);
                }

            }

            activateFromHash();

            // Update immediately when clicked.
            items.forEach(item => {

                item.link.addEventListener('click', () => {
                    setActive(item);
                });

            });

            // Handle browser back/forward navigation.
            window.addEventListener('hashchange', activateFromHash);

            // Automatically highlight the currently visible section.
            if ('IntersectionObserver' in window) {

                const observer = new IntersectionObserver(entries => {

                    const visible = entries
                        .filter(entry => entry.isIntersecting)
                        .sort((a, b) =>
                            a.boundingClientRect.top -
                            b.boundingClientRect.top
                        );

                    if (!visible.length) return;

                    const activeItem = items.find(
                        item => item.section === visible[0].target
                    );

                    if (activeItem) {
                        setActive(activeItem);
                    }

                }, {
                    root: null,
                    rootMargin: '-50px 0px -65% 0px',
                    threshold: 0
                });

                items.forEach(item => {
                    observer.observe(item.section);
                });

            }

        });

    }

    // Works whether this script loads before or after DOMContentLoaded.
    if (document.readyState === 'loading') {

        document.addEventListener(
            'DOMContentLoaded',
            initializeLilNav
        );

    } else {

        initializeLilNav();

    }

})();
</script>