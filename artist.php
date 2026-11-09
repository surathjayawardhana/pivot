<?php
/**
 * Artist directory (Artist tab in the main navigation).
 *   artist.php            -> first page of artists
 *   artist.php?page=2     -> further pages ("Load more" fetches these without a reload)
 *
 * Public page: guests can browse, only following requires a login.
 * It reuses the existing project code and adds no tables or columns:
 *   - includes/db.php, includes/artwork_helpers.php  ArtDb, art_e(), art_csrf_token()
 *   - includes/profile_helpers.php                    profile_viewer(), avatar/artwork URLs, profile_url()
 *   - follow_action.php                               the follow / unfollow endpoint (CSRF + validation)
 */
declare(strict_types=1);
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/profile_helpers.php';

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/artwork_helpers.php';
require_once __DIR__ . '/includes/profile_helpers.php';


const ARTISTS_PAGE_SIZE      = 10;   // artist cards per page
const ARTISTS_PREVIEW_LIMIT  = 12;   // artwork previews loaded per artist


function artists_total(ArtDb $db): int
{
    $row = $db->one(
        "SELECT COUNT(*) AS c FROM users"
    );

    return (int) ($row['c'] ?? 0);
}

/**

 * @return array<int, array<string,mixed>>
 */
function artists_page(ArtDb $db, int $page): array
{
    $limit  = ARTISTS_PAGE_SIZE;
    $offset = max(0, $page - 1) * $limit;

    $rows = $db->all(
        "SELECT u.id, u.first_name, u.surname, u.username, u.profile_image_path,
                COALESCE(s.art_count, 0) AS art_count,
                (SELECT COUNT(*)
                   FROM user_follows f
                   JOIN users fu ON fu.id = f.follower_id
                  WHERE f.followed_id = u.id) AS follower_count
           FROM users u
           LEFT JOIN (
                SELECT user_id, COUNT(*) AS art_count, MAX(id) AS last_art
                FROM artworks
                WHERE status = 'approved'
                GROUP BY user_id
           ) s ON s.user_id = u.id
          ORDER BY (s.art_count IS NULL), s.last_art DESC,
                   u.first_name, u.surname, u.id
          LIMIT $limit OFFSET $offset"
    );

    foreach ($rows as &$row) {
        $row['id']             = (int) $row['id'];
        $row['art_count']      = (int) $row['art_count'];
        $row['follower_count'] = (int) $row['follower_count'];
    }

    unset($row);

    return $rows;
}

/**
 * The newest approved artworks (at most ARTISTS_PREVIEW_LIMIT each) for all
 * artists on the page, in a single query. Pending and rejected work is never
 * selected.
 *
 * @param  int[] $artistIds
 * @return array<int, array<int, array<string,mixed>>>  artist id => artworks
 */
function artists_previews(ArtDb $db, array $artistIds): array
{
    if (!$artistIds) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($artistIds), '?'));
    $rows  = $db->all(
        "SELECT t.id, t.user_id, t.title, t.image_path, t.category
           FROM (SELECT a.id, a.user_id, a.title, a.image_path, c.name AS category,
                        ROW_NUMBER() OVER (PARTITION BY a.user_id ORDER BY a.id DESC) AS rn
                   FROM artworks a
                   LEFT JOIN categories c ON c.id = a.category_id
                  WHERE a.status = 'approved' AND a.user_id IN ($marks)) t
          WHERE t.rn <= " . ARTISTS_PREVIEW_LIMIT . '
          ORDER BY t.user_id, t.rn',
        $artistIds
    );

    $byArtist = [];
    foreach ($rows as $row) {
        $byArtist[(int) $row['user_id']][] = $row;
    }
    return $byArtist;
}

/**
 * Which of these artists the viewer follows, read from user_follows.
 *
 * @param  int[] $artistIds
 * @return array<int,true>  set of followed artist ids
 */
function artists_followed_set(ArtDb $db, int $viewerId, array $artistIds): array
{
    if (!$artistIds) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($artistIds), '?'));
    $rows  = $db->all(
        "SELECT followed_id FROM user_follows WHERE follower_id = ? AND followed_id IN ($marks)",
        array_merge([$viewerId], $artistIds)
    );

    $set = [];
    foreach ($rows as $row) {
        $set[(int) $row['followed_id']] = true;
    }
    return $set;
}

/** "1 artwork" / "3 artworks". */
function artists_plural(int $n, string $singular, string $plural): string
{
    return number_format($n) . ' ' . ($n === 1 ? $singular : $plural);
}

/* ======================================================================
 * Bootstrap
 * ==================================================================== */
$failed   = false;
$viewer   = null;
$artists  = [];
$previews = [];
$followed = [];
$total    = 0;
$pages    = 1;
$page     = 1;

try {
    $db     = art_db();
    $viewer = profile_viewer($db);            // null for guests, never redirects

    $total = artists_total($db);
    $pages = max(1, (int) ceil($total / ARTISTS_PAGE_SIZE));
    $page  = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 1]]);
    $page  = max(1, min((int) $page, $pages));   // filter_var() gives false for ?page[]=2: never go below 1

    $artists = artists_page($db, $page);
    $ids     = array_column($artists, 'id');

    $previews = artists_previews($db, $ids);
    if ($viewer) {
        $followed = artists_followed_set($db, $viewer['id'], $ids);
    }
} catch (Throwable $e) {
    error_log('[artist] ' . $e->getMessage());
    $failed   = true;
    $artists  = [];
    http_response_code(500);
}

$firstShown = $artists ? ($page - 1) * ARTISTS_PAGE_SIZE + 1 : 0;
$lastShown  = $artists ? $firstShown + count($artists) - 1 : 0;
$pageUrl    = static fn (int $p): string => 'artist.php' . ($p > 1 ? '?page=' . $p : '');
$csrf       = art_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&amp;display=swap">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/artists.css">
    <link rel="stylesheet" href="css/footer.css">
    <title>Artists | NSBM ArtCraft Platform</title>
</head>
<body>
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="artists-page">
        <div class="artists-container">

            <header class="artists-intro">
                <h1 class="artists-title">Artists</h1>
                <p class="artists-lead">Discover the students sharing their work on ArtCraft, browse their artwork and follow the ones you like.</p>
            </header>

        <?php if ($failed): ?>
            <section class="artists-state" role="alert">
                <h2>Artists are unavailable right now</h2>
                <p>We couldn't load the artist directory. Please try again in a moment.</p>
                <a class="artists-btn" href="artist.php">Try again</a>
            </section>

        <?php elseif (!$artists): ?>
            <section class="artists-state">
                <h2>No artists to show yet</h2>
                <p>Nobody has joined the directory so far. Artists will appear here as soon as they register.</p>
            </section>

        <?php else: ?>
            <?php if (!$viewer): ?>
                <p class="artists-notice">
                    <a href="login.php">Log in</a> to follow artists and keep up with their new work.
                </p>
            <?php endif; ?>

            <p class="artists-sr" role="status" aria-live="polite" data-artists-status></p>

            <ul class="artist-list" id="artist-list">
                <?php foreach ($artists as $artist):
                    $artistId  = $artist['id'];
                    $fullName  = profile_full_name($artist);
                    $username  = (string) $artist['username'];
                    $profileUrl = profile_url($username);
                    $isSelf    = $viewer !== null && $viewer['id'] === $artistId;
                    $isFollowing = isset($followed[$artistId]);
                    $works     = $previews[$artistId] ?? [];
                    $moreWorks = $artist['art_count'] - count($works);
                ?>
                <li>
                    <article class="artist-card" aria-labelledby="artist-name-<?= $artistId ?>">

                        <div class="artist-card__profile">
                            <div class="artist-card__head">
                                <a class="artist-card__avatar-link" href="<?= art_e($profileUrl) ?>" tabindex="-1" aria-hidden="true">
                                    <img class="artist-card__avatar"
                                         src="<?= art_e(profile_avatar_url($artist['profile_image_path'])) ?>"
                                         alt="" width="88" height="88" loading="lazy" decoding="async">
                                </a>
                                <div class="artist-card__identity">
                                    <h2 class="artist-card__name" id="artist-name-<?= $artistId ?>">
                                        <a href="<?= art_e($profileUrl) ?>"><?= art_e($fullName) ?></a>
                                    </h2>
                                    <p class="artist-card__username">@<?= art_e($username) ?></p>
                                    <p class="artist-card__stats">
                                        <span><?= art_e(artists_plural($artist['art_count'], 'artwork', 'artworks')) ?></span>
                                        <span><strong data-followers-count><?= number_format($artist['follower_count']) ?></strong> <span data-followers-word><?= $artist['follower_count'] === 1 ? 'follower' : 'followers' ?></span></span>
                                    </p>
                                </div>
                            </div>

                            <div class="artist-card__action">
                                <?php if ($isSelf): ?>
                                    <a class="artists-btn artists-btn--block" href="profile.php">View my profile</a>
                                <?php elseif ($viewer): ?>
                                    <form class="follow-form" method="post" action="follow_action.php" data-follow-form data-artist-name="<?= art_e($fullName) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= art_e($csrf) ?>">
                                        <input type="hidden" name="target_id" value="<?= $artistId ?>">
                                        <input type="hidden" name="action" value="<?= $isFollowing ? 'unfollow' : 'follow' ?>" data-follow-action>
                                        <button type="submit"
                                                class="artists-btn artists-btn--block follow-btn<?= $isFollowing ? ' is-following' : '' ?>"
                                                aria-label="<?= art_e(($isFollowing ? 'Unfollow ' : 'Follow ') . $fullName) ?>"
                                                data-follow-button>
                                            <span class="follow-btn__rest" data-label-rest><?= $isFollowing ? 'Following' : 'Follow' ?></span>
                                            <span class="follow-btn__hover" data-label-hover aria-hidden="true">Unfollow</span>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <a class="artists-btn artists-btn--block" href="login.php"
                                       aria-label="<?= art_e('Log in to follow ' . $fullName) ?>">Follow</a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="artist-card__works">
                            <?php if (!$works): ?>
                                <p class="artist-empty">No approved artworks yet.</p>
                            <?php else: ?>
                                <div class="artist-gallery"
                                     role="region" tabindex="0"
                                     aria-label="<?= art_e('Artworks by ' . $fullName) ?>"
                                     data-gallery>
                                    <ul class="artist-gallery__track">
                                        <?php foreach ($works as $work):
                                            $imgUrl = profile_artwork_image_url($work['image_path'] ?? null);
                                        ?>
                                            <li class="artwork-tile">
                                                <a class="artwork-tile__link" href="artwork.php?id=<?= (int) $work['id'] ?>">
                                                    <span class="artwork-tile__media">
                                                        <?php if ($imgUrl !== ''): ?>
                                                            <img src="<?= art_e($imgUrl) ?>" alt="" loading="lazy" decoding="async">
                                                        <?php else: ?>
                                                            <svg class="artwork-tile__placeholder" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                                <path fill="currentColor" d="M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zm0 2v9.6l3.5-3.5 3 3 4.5-5.1L19 13.2V5H5zm14 14v-3.9l-3.9-3.9-4.6 5.2-3-3L5 18.4V19h14zM8.5 9.2a1.7 1.7 0 1 0 0-3.4 1.7 1.7 0 0 0 0 3.4z"/>
                                                            </svg>
                                                        <?php endif; ?>
                                                    </span>
                                                    <span class="artwork-tile__body">
                                                        <span class="artwork-tile__title"><?= art_e((string) $work['title']) ?></span>
                                                        <?php if (!empty($work['category'])): ?>
                                                            <span class="artwork-tile__meta"><?= art_e((string) $work['category']) ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>

                                        <?php if ($moreWorks > 0): ?>
                                            <li class="artwork-tile artwork-tile--more">
                                                <a class="artwork-tile__link" href="<?= art_e($profileUrl) ?>">
                                                    <span class="artwork-tile__more-text">View all <?= number_format($artist['art_count']) ?> artworks</span>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>

                                <button type="button" class="artists-btn gallery-arrow gallery-arrow--prev" data-gallery-prev hidden
                                        aria-label="<?= art_e('Scroll ' . $fullName . '\'s artworks left') ?>">
                                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d="M15 5l-7 7 7 7"/></svg>
                                </button>
                                <button type="button" class="artists-btn gallery-arrow gallery-arrow--next" data-gallery-next hidden
                                        aria-label="<?= art_e('Scroll ' . $fullName . '\'s artworks right') ?>">
                                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                </button>
                            <?php endif; ?>
                        </div>

                    </article>
                </li>
                <?php endforeach; ?>
            </ul>

            <nav class="artists-pager" aria-label="Artist pages">
                <p class="artists-pager__count" data-range data-first="<?= $firstShown ?>" data-last="<?= $lastShown ?>" data-total="<?= $total ?>">
                    Artists <?= $firstShown ?>&ndash;<?= $lastShown ?> of <?= number_format($total) ?>
                </p>
                <div class="artists-pager__links">
                    <?php if ($page > 1): ?>
                        <a class="artists-btn" href="<?= art_e($pageUrl($page - 1)) ?>" rel="prev">Previous artists</a>
                    <?php endif; ?>
                    <?php if ($page < $pages): ?>
                        <a class="artists-btn" href="<?= art_e($pageUrl($page + 1)) ?>" rel="next" data-load-more>Load more artists</a>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>

        </div>
    </main>

    <?php include __DIR__ . '/partials/footer.php'; ?>
    <script src="js/artists.js" defer></script>
</body>
</html>
