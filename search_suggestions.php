<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/search_suggestions_helpers.php';

ss_require_get();

try {
    $pdo = db();

    // Same convention as the rest of the site: active, not removed, allowed role - read from the database.
    $viewer = profile_viewer(art_db());
    if ($viewer === null) {
        ss_fail(401, 'not_signed_in');
    }
    // The session is only needed for the check above. Release its lock so several
    // suggestion requests (and the page itself) never queue behind each other.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $q     = ss_read_query();
    $limit = ss_read_limit();

    // The homepage search has no ID field, so digits are treated like any other text here.
    if (!ss_long_enough($q, false)) {
        ss_send(200, ['suggestions' => []]);
    }

    $contains = ss_like_contains($q);
    $prefix   = ss_like_prefix($q);
    $out      = [];

    // ---- Categories (max 2, fewer when the caller wants very few) -----------------
    $categoryLimit = $limit >= 5 ? 2 : 1;
    $categories = ss_fetch(
        $pdo,
        "SELECT c.id, c.name, COUNT(a.id) AS artwork_count
           FROM categories c
           INNER JOIN artworks a ON a.category_id = c.id AND a.status = 'approved'
          WHERE c.name LIKE ? ESCAPE '|'
          GROUP BY c.id, c.name
          ORDER BY (c.name LIKE ? ESCAPE '|') DESC, c.name ASC
          LIMIT ?",
        [$contains, $prefix, $categoryLimit]
    );
    foreach ($categories as $c) {
        $n = (int) $c['artwork_count'];
        $out[] = [
            'type'  => 'category',
            'id'    => (int) $c['id'],
            'label' => (string) $c['name'],
            'meta'  => 'Category · ' . $n . ($n === 1 ? ' artwork' : ' artworks'),
            'value' => (string) $c['name'],
        ];
    }

    // ---- Artworks (one row per artwork, so no duplicates are possible) ------------
    $artLimit = $limit - count($out);
    if ($artLimit > 0) {
        $rows = ss_fetch(
            $pdo,
            "SELECT a.id, a.title, a.image_path,
                    c.name AS category_name,
                    u.first_name, u.surname, u.username
               FROM artworks a
               INNER JOIN users u ON u.id = a.user_id
               LEFT JOIN categories c ON c.id = a.category_id
              WHERE a.status = 'approved'
                AND (a.title LIKE ? ESCAPE '|'
                     OR a.description LIKE ? ESCAPE '|'
                     OR c.name LIKE ? ESCAPE '|')
              ORDER BY CASE WHEN a.title LIKE ? ESCAPE '|' THEN 0
                            WHEN a.title LIKE ? ESCAPE '|' THEN 1
                            ELSE 2 END,
                       a.created_at DESC, a.id DESC
              LIMIT ?",
            [$contains, $contains, $contains, $prefix, $contains, $artLimit]
        );

        $root = __DIR__;
        foreach ($rows as $r) {
            $title  = (string) $r['title'];
            $artist = trim((string) $r['first_name'] . ' ' . (string) $r['surname']);
            if ($artist === '') {
                $artist = (string) $r['username'];
            }
            $metaParts = array_filter([(string) ($r['category_name'] ?? ''), $artist], static fn (string $p): bool => $p !== '');

            // Thumbnail: only a plain file inside uploads/artworks that really exists.
            $thumb = null;
            $path  = (string) $r['image_path'];
            if (
                preg_match('#^uploads/artworks/[A-Za-z0-9._-]+$#', $path) === 1
                && !str_contains($path, '..')
                && is_file($root . '/' . $path)
            ) {
                $thumb = art_asset_url($path);
            }

            $item = [
                'type'  => 'artwork',
                'id'    => (int) $r['id'],
                'label' => $title,
                'meta'  => implode(' · ', $metaParts),
                'value' => $title,
            ];
            if ($thumb !== null) {
                $item['thumb'] = $thumb;
            }
            $out[] = $item;
        }
    }

    ss_send(200, ['suggestions' => $out]);
} catch (Throwable $e) {
    ss_internal_error($e);
}
