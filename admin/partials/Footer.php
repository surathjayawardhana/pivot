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
 * Site footer (copyright, About Us, GitHub contributors).
 * Usage, inside <body> of a page in the project root:
 *     <?php include 'partials/footer.php'; ?>
 */

$footerContributors = [
    'https://github.com/surathjayawardhana',
    'https://github.com/Sameethurisma88-cpu',
    'https://github.com/heshanimaleesha-hub',
    'https://github.com/asanib10',
    'https://github.com/kaveeshadulanjali',
    'https://github.com/Senuthmi-Dahamila',
    'https://github.com/nethumini0809',
    'https://github.com/Sandu2578',
];

// GitHub mark (16x16 viewBox).
$footerGithubIcon = 'M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z';
?>
<link rel="stylesheet" href="../css/footer.css">

<footer class="site-footer">
    <div class="site-footer__inner">
        <p class="site-footer__copyright">&copy; <?= date('Y') ?> NSBM ArtCraft Platform. All rights reserved.</p>

        <section class="site-footer__section" aria-labelledby="footer-about-title">
            <h2 class="site-footer__title" id="footer-about-title">About Us</h2>
            <p class="site-footer__text">ArtCraft is a platform for sharing artwork and discovering artists and creative events.</p>
        </section>

        <section class="site-footer__section" aria-labelledby="footer-contributors-title">
            <h2 class="site-footer__title" id="footer-contributors-title">Contributors</h2>
            <ul class="site-footer__contributors">
                <?php foreach ($footerContributors as $footerUrl): ?>
                    <?php $footerUser = htmlspecialchars(basename($footerUrl), ENT_QUOTES, 'UTF-8'); ?>
                    <li>
                        <a class="site-footer__contributor" href="<?= htmlspecialchars($footerUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= $footerUser ?> on GitHub (opens in a new tab)">
                            <svg class="site-footer__github-icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path fill="currentColor" d="<?= $footerGithubIcon ?>"/></svg>
                            <span><?= $footerUser ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>
</footer>
