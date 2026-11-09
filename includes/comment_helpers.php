<?php
/**
 * Comments and one-level replies for artworks (table: artwork_comments).
 *
 *   artwork_comments  id, artwork_id, user_id, parent_comment_id, body,
 *                     created_at, updated_at, deleted_at, deleted_by_user_id
 *
 *   parent_comment_id IS NULL  -> top-level comment on the artwork
 *   parent_comment_id = <id>   -> reply to that top-level comment (never deeper)
 *   deleted_at IS NOT NULL     -> soft-deleted: the body is cleared to '' in the table
 *
 * Builds on the existing project code instead of replacing it:
 *   - includes/db.php              db()
 *   - includes/artwork_helpers.php ArtDb, art_db(), art_e(), art_csrf_*()
 *   - includes/profile_helpers.php profile_viewer(), profile_full_name(), profile_url()
 *
 * Used by: comment_action.php, partials/comments.php, partials/posts.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/artwork_helpers.php';
require_once __DIR__ . '/profile_helpers.php';

/* ======================================================================
 * Settings (override any of these with define() before this file loads)
 * ==================================================================== */

defined('COMMENT_MAX_LENGTH')           || define('COMMENT_MAX_LENGTH', 1000);   // characters, not bytes
defined('COMMENT_PAGE_SIZE')            || define('COMMENT_PAGE_SIZE', 10);      // top-level comments per page
defined('COMMENT_REPLIES_SHOWN')        || define('COMMENT_REPLIES_SHOWN', 20);  // replies shown per thread by default
defined('COMMENT_REPLIES_MAX')          || define('COMMENT_REPLIES_MAX', 200);   // replies shown when a thread is expanded
defined('COMMENT_RATE_LIMIT_MAX')       || define('COMMENT_RATE_LIMIT_MAX', 5);  // new comments + replies ...
defined('COMMENT_RATE_LIMIT_WINDOW')    || define('COMMENT_RATE_LIMIT_WINDOW', 60); // ... per this many seconds, per user
defined('COMMENT_DUPLICATE_WINDOW')     || define('COMMENT_DUPLICATE_WINDOW', 30);  // identical repeat within N seconds = double submit
// false: a deleted top-level comment that has no remaining replies is hidden.
// true:  it stays visible as "This comment was deleted."
defined('COMMENT_SHOW_EMPTY_TOMBSTONES') || define('COMMENT_SHOW_EMPTY_TOMBSTONES', false);

/* ======================================================================
 * Who may use comments?
 * ==================================================================== */

/** True when the users table has the newer deleted_at column (checked once per request). */
function comment_users_have_deleted_at(ArtDb $db): bool
{
    static $has = null;
    if ($has === null) {
        $has = $db->one(
            "SELECT 1 AS x FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'deleted_at'
              LIMIT 1"
        ) !== null;
    }
    return $has;
}

/**
 * The logged-in viewer who is allowed to write comments: active, with a valid
 * role (profile_viewer() reads both from the database on every request) and,
 * when the column exists, not soft-deleted. Null for guests and everyone else.
 *
 * @return ?array{id:int, first_name:string, surname:string, username:string, role:string}
 */
function comment_viewer(ArtDb $db): ?array
{
    $viewer = profile_viewer($db);
    if (!$viewer) {
        return null;
    }
    if (comment_users_have_deleted_at($db)) {
        $row = $db->one('SELECT deleted_at FROM users WHERE id = ?', [$viewer['id']]);
        if (!$row || $row['deleted_at'] !== null) {
            return null;
        }
    }
    return $viewer;
}

/** Administrator check on the database-backed role that comment_viewer() loaded. */
function comment_is_admin(?array $viewer): bool
{
    return $viewer !== null && ($viewer['role'] ?? '') === 'admin';
}

/** SQL condition: the comment author's account is still publicly available. Built from constants only. */
function comment_author_available_sql(ArtDb $db, string $alias = 'u'): string
{
    $sql = "$alias.id IS NOT NULL AND $alias.account_status = 'active'";
    if (comment_users_have_deleted_at($db)) {
        $sql .= " AND $alias.deleted_at IS NULL";
    }
    return '(' . $sql . ')';
}

/* ======================================================================
 * Artwork and comment lookups
 * ==================================================================== */

/**
 * The artwork when it accepts comments (approved, owner account active - the
 * same rule artwork.php uses to show it publicly), otherwise null.
 */
function comment_open_artwork(ArtDb $db, int $artworkId): ?array
{
    if ($artworkId < 1) {
        return null;
    }
    return $db->one(
        "SELECT a.id, a.user_id, a.status
           FROM artworks a
           JOIN users u ON u.id = a.user_id AND u.account_status = 'active'
          WHERE a.id = ? AND a.status = 'approved'",
        [$artworkId]
    );
}

/** One comment row for permission checks (no author details). */
function comment_find(ArtDb $db, int $commentId): ?array
{
    if ($commentId < 1) {
        return null;
    }
    $row = $db->one(
        'SELECT id, artwork_id, user_id, parent_comment_id, body, deleted_at
           FROM artwork_comments WHERE id = ?',
        [$commentId]
    );
    if (!$row) {
        return null;
    }
    foreach (['id', 'artwork_id', 'user_id'] as $k) {
        $row[$k] = (int) $row[$k];
    }
    $row['parent_comment_id'] = $row['parent_comment_id'] === null ? null : (int) $row['parent_comment_id'];
    return $row;
}

function comment_belongs_to_artwork(array $comment, int $artworkId): bool
{
    return (int) $comment['artwork_id'] === $artworkId;
}

function comment_is_top_level(array $comment): bool
{
    return $comment['parent_comment_id'] === null;
}

function comment_is_deleted(array $comment): bool
{
    return $comment['deleted_at'] !== null;
}

/* ---- Permissions (viewer comes from comment_viewer(), never from the form) ---- */

function comment_is_owner(array $comment, ?array $viewer): bool
{
    return $viewer !== null && (int) $comment['user_id'] === (int) $viewer['id'];
}

/** Only the original author edits - administrators cannot edit other people's words. */
function comment_can_edit(array $comment, ?array $viewer): bool
{
    return !comment_is_deleted($comment) && comment_is_owner($comment, $viewer);
}

/** The author, or an administrator, may delete. */
function comment_can_delete(array $comment, ?array $viewer): bool
{
    return !comment_is_deleted($comment)
        && $viewer !== null
        && (comment_is_owner($comment, $viewer) || comment_is_admin($viewer));
}

/** Only live top-level comments take replies (no replying to replies). */
function comment_can_reply(array $comment, ?array $viewer): bool
{
    return $viewer !== null && !comment_is_deleted($comment) && comment_is_top_level($comment);
}

/* ======================================================================
 * Text handling
 * ==================================================================== */

/**
 * Normalises submitted text but keeps line breaks and paragraphs:
 * CRLF/CR -> LF, control characters removed, 3+ blank lines -> 1 blank line,
 * leading/trailing whitespace (including Unicode spaces) trimmed.
 * (clean_text() from db.php is not used: it collapses all whitespace.)
 */
function comment_clean_body(string $raw): string
{
    $s = str_replace(["\r\n", "\r"], "\n", $raw);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s) ?? $s;
    $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? $s;
    $s = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $s) ?? $s;
    return $s;
}

/**
 * Validates a submitted comment body.
 *
 * @return array{0: ?string, 1: ?string} [clean text or null, error message or null]
 */
function comment_validate_body(string $raw): array
{
    if (!mb_check_encoding($raw, 'UTF-8')) {
        return [null, 'The comment contains characters that are not allowed.'];
    }
    $clean = comment_clean_body($raw);

    // Blank = nothing except whitespace and invisible formatting characters.
    if (preg_replace('/[\s\p{Z}\p{Cf}]+/u', '', $clean) === '') {
        return [null, 'Please write something before posting.'];
    }
    $length = mb_strlen($clean, 'UTF-8');           // characters, so emoji count as one
    if ($length > COMMENT_MAX_LENGTH) {
        return [null, 'Comments can be at most ' . number_format(COMMENT_MAX_LENGTH)
            . ' characters (yours is ' . number_format($length) . ').'];
    }
    return [$clean, null];
}

/** Safe HTML for a comment body: escape first, then turn line breaks into <br>. */
function comment_body_html(string $body): string
{
    return nl2br(art_e($body), false);
}

/* ======================================================================
 * Writing
 * ==================================================================== */

/** id of the row this connection just inserted. */
function comment_last_insert_id(ArtDb $db): int
{
    return (int) ($db->one('SELECT LAST_INSERT_ID() AS id')['id'] ?? 0);
}

/** Creates a top-level comment. $userId must come from comment_viewer(). */
function comment_create(ArtDb $db, int $artworkId, int $userId, string $body): int
{
    $db->run(
        'INSERT INTO artwork_comments (artwork_id, user_id, parent_comment_id, body) VALUES (?, ?, NULL, ?)',
        [$artworkId, $userId, $body]
    );
    return comment_last_insert_id($db);
}

/**
 * Creates a reply. The parent is verified inside the INSERT itself (same
 * artwork, top-level, not deleted), so it cannot be deleted between a check
 * and the insert. Returns the new id, or null when the parent is not valid.
 */
function comment_create_reply(ArtDb $db, int $artworkId, int $userId, int $parentId, string $body): ?int
{
    $inserted = $db->run(
        'INSERT INTO artwork_comments (artwork_id, user_id, parent_comment_id, body)
         SELECT ?, ?, p.id, ?
           FROM artwork_comments p
          WHERE p.id = ? AND p.artwork_id = ? AND p.parent_comment_id IS NULL AND p.deleted_at IS NULL',
        [$artworkId, $userId, $body, $parentId, $artworkId]
    );
    return $inserted > 0 ? comment_last_insert_id($db) : null;
}

/**
 * Changes the text and sets updated_at. Only the author's own live comment is
 * touched; artwork_id and parent_comment_id can never change here.
 */
function comment_update_body(ArtDb $db, int $commentId, int $userId, string $body): bool
{
    return $db->run(
        'UPDATE artwork_comments SET body = ?, updated_at = NOW()
          WHERE id = ? AND user_id = ? AND deleted_at IS NULL',
        [$body, $commentId, $userId]
    ) > 0;
}

/**
 * Soft delete: clears the text, stamps deleted_at and records who did it.
 * Replies stay. Returns false when it was already deleted.
 */
function comment_soft_delete(ArtDb $db, int $commentId, int $deletedByUserId): bool
{
    return $db->run(
        "UPDATE artwork_comments SET body = '', deleted_at = NOW(), deleted_by_user_id = ?
          WHERE id = ? AND deleted_at IS NULL",
        [$deletedByUserId, $commentId]
    ) > 0;
}

/* ---- Spam control ---- */

/** True when this user already posted the maximum number of comments/replies in the time window. */
function comment_rate_limited(ArtDb $db, int $userId): bool
{
    // Deleted rows still count, so delete-and-repost cannot dodge the limit.
    $row = $db->one(
        'SELECT COUNT(*) AS c FROM artwork_comments
          WHERE user_id = ? AND created_at >= (NOW() - INTERVAL ' . (int) COMMENT_RATE_LIMIT_WINDOW . ' SECOND)',
        [$userId]
    );
    return (int) ($row['c'] ?? 0) >= COMMENT_RATE_LIMIT_MAX;
}

/** id of an identical comment this user just posted in the same place (double click, double POST). */
function comment_find_recent_duplicate(ArtDb $db, int $artworkId, int $userId, ?int $parentId, string $body): ?int
{
    $row = $db->one(
        'SELECT id FROM artwork_comments
          WHERE user_id = ? AND artwork_id = ? AND parent_comment_id <=> ? AND deleted_at IS NULL
            AND BINARY body = BINARY ?
            AND created_at >= (NOW() - INTERVAL ' . (int) COMMENT_DUPLICATE_WINDOW . ' SECOND)
          ORDER BY id DESC LIMIT 1',
        [$userId, $artworkId, $parentId, $body]
    );
    return $row ? (int) $row['id'] : null;
}

/* ======================================================================
 * Reading
 * ==================================================================== */

/** Live (not deleted) comments plus replies for one artwork. */
function comment_count(ArtDb $db, int $artworkId): int
{
    $row = $db->one(
        'SELECT COUNT(*) AS c FROM artwork_comments WHERE artwork_id = ? AND deleted_at IS NULL',
        [$artworkId]
    );
    return (int) ($row['c'] ?? 0);
}

/**
 * SELECT list shared by the comment and reply queries. Public author fields
 * only (never email or password hash). Deleted rows never return their text,
 * and authors whose account is unavailable lose their name and picture.
 */
function comment_select_sql(ArtDb $db): string
{
    $ok = comment_author_available_sql($db);
    return "c.id, c.artwork_id, c.user_id, c.parent_comment_id,
            CASE WHEN c.deleted_at IS NULL THEN c.body ELSE '' END AS body,
            c.created_at, c.updated_at, c.deleted_at,
            (c.deleted_by_user_id IS NOT NULL AND c.deleted_by_user_id <> c.user_id) AS removed_by_other,
            CASE WHEN $ok THEN 1 ELSE 0 END AS author_available,
            CASE WHEN $ok THEN u.first_name END AS first_name,
            CASE WHEN $ok THEN u.surname END AS surname,
            CASE WHEN $ok THEN u.username END AS username,
            CASE WHEN $ok THEN u.profile_image_path END AS profile_image_path";
}

/** WHERE condition for top-level comments that are shown (see COMMENT_SHOW_EMPTY_TOMBSTONES). */
function comment_top_level_visible_sql(): string
{
    $sql = 'c.artwork_id = ? AND c.parent_comment_id IS NULL';
    if (!COMMENT_SHOW_EMPTY_TOMBSTONES) {
        $sql .= ' AND (c.deleted_at IS NULL OR EXISTS (
                     SELECT 1 FROM artwork_comments r
                      WHERE r.parent_comment_id = c.id AND r.deleted_at IS NULL))';
    }
    return $sql;
}

/**
 * One page of the discussion in three queries, however many comments there are:
 * the page count, the page of top-level comments (newest first), and every
 * reply for those comments at once (oldest first, capped per thread).
 *
 * @param ?int $expandedThread parent id whose replies should all be loaded
 * @return array{total:int, page:int, pages:int, comments:array<int,array<string,mixed>>}
 */
function comment_load_discussion(ArtDb $db, int $artworkId, int $page, ?int $expandedThread = null): array
{
    $perPage = max(1, (int) COMMENT_PAGE_SIZE);
    $visible = comment_top_level_visible_sql();

    $topCount = (int) ($db->one("SELECT COUNT(*) AS c FROM artwork_comments c WHERE $visible", [$artworkId])['c'] ?? 0);
    $pages    = max(1, (int) ceil($topCount / $perPage));
    $page     = min(max(1, $page), $pages);
    $offset   = ($page - 1) * $perPage;

    $parents = $db->all(
        'SELECT ' . comment_select_sql($db) . "
           FROM artwork_comments c
           LEFT JOIN users u ON u.id = c.user_id
          WHERE $visible
          ORDER BY c.created_at DESC, c.id DESC
          LIMIT $perPage OFFSET $offset",
        [$artworkId]
    );

    $byId = [];
    foreach ($parents as $p) {
        $p['id']          = (int) $p['id'];
        $p['replies']     = [];
        $p['reply_total'] = 0;
        $byId[$p['id']]   = $p;
    }

    if ($byId) {
        $ids      = array_keys($byId);
        $in       = implode(',', array_fill(0, count($ids), '?'));
        $expanded = (int) ($expandedThread ?? 0);
        $shown    = max(1, (int) COMMENT_REPLIES_SHOWN);
        $max      = max($shown, (int) COMMENT_REPLIES_MAX);

        $replies = $db->all(
            'SELECT t.* FROM (
                 SELECT ' . comment_select_sql($db) . ",
                        ROW_NUMBER() OVER (PARTITION BY c.parent_comment_id ORDER BY c.created_at ASC, c.id ASC) AS rn,
                        COUNT(*)     OVER (PARTITION BY c.parent_comment_id) AS thread_total
                   FROM artwork_comments c
                   LEFT JOIN users u ON u.id = c.user_id
                  WHERE c.parent_comment_id IN ($in)
             ) t
              WHERE t.rn <= CASE WHEN t.parent_comment_id = $expanded THEN $max ELSE $shown END
              ORDER BY t.parent_comment_id, t.rn",
            $ids
        );
        foreach ($replies as $r) {
            $pid = (int) $r['parent_comment_id'];
            if (!isset($byId[$pid])) {
                continue;
            }
            $r['id'] = (int) $r['id'];
            $byId[$pid]['reply_total'] = (int) $r['thread_total'];
            $byId[$pid]['replies'][]   = $r;
        }
    }

    return [
        'total'    => comment_count($db, $artworkId),
        'page'     => $page,
        'pages'    => $pages,
        'comments' => array_values($byId),
    ];
}

/* ======================================================================
 * Display helpers
 * ==================================================================== */

/**
 * Public author details for a comment row.
 *
 * @return array{name:string, username:?string, avatar:?string, initial:string, profile:?string}
 */
function comment_author(array $row): array
{
    if (empty($row['author_available'])) {
        return ['name' => 'Former member', 'username' => null, 'avatar' => null, 'initial' => '?', 'profile' => null];
    }
    $name = profile_full_name($row);
    if ($name === '') {
        $name = (string) $row['username'];
    }
    return [
        'name'     => $name,
        'username' => (string) $row['username'],
        'avatar'   => profile_custom_avatar_url($row['profile_image_path'] ?? null),
        'initial'  => mb_strtoupper(mb_substr($name !== '' ? $name : '?', 0, 1, 'UTF-8'), 'UTF-8'),
        'profile'  => profile_url((string) $row['username']),
    ];
}

/** @return array{0:string,1:string} [machine-readable datetime, label such as "Nov 5, 2026, 2:15 PM"] */
function comment_time(?string $dbDate): array
{
    $ts = $dbDate ? strtotime($dbDate) : false;
    if ($ts === false) {
        return ['', ''];
    }
    return [date('c', $ts), date('M j, Y, g:i A', $ts)];
}

/* ======================================================================
 * Request parsing, URLs and one-time messages
 * ==================================================================== */

/** A positive integer from a GET/POST array, or null when missing or not a clean integer. */
function comment_request_int(array $source, string $key): ?int
{
    $v = filter_var($source[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $v === false || $v === null ? null : (int) $v;
}

/**
 * Local URL of an artwork's discussion. Built only from integers and fixed
 * words - nothing the browser sends is ever placed in it as text - so it can
 * not become an open redirect. Escape with art_e() when printing into HTML.
 *
 * @param array{page?:int, thread?:int, panel?:string, panel_id?:int, anchor?:string} $o
 */
function comment_url(int $artworkId, array $o = []): string
{
    $q = ['id' => $artworkId];
    if (($o['page'] ?? 1) > 1) {
        $q['comments_page'] = (int) $o['page'];
    }
    if (!empty($o['thread'])) {
        $q['thread'] = (int) $o['thread'];
    }
    if (!empty($o['panel']) && in_array($o['panel'], ['reply', 'edit', 'delete'], true) && !empty($o['panel_id'])) {
        $q['comment_panel'] = $o['panel'];
        $q['comment_id']    = (int) $o['panel_id'];
    }
    $anchor = (string) ($o['anchor'] ?? 'comments');
    if (!preg_match('/^[a-z]+(-[a-z]+)*(-\d+)?$/', $anchor)) {
        $anchor = 'comments';
    }
    return 'artwork.php?' . http_build_query($q) . '#' . $anchor;
}

/**
 * Stores a one-time message for the next artwork page view. For a failed
 * submit, $draft keeps what the person typed so nothing is lost.
 *
 * @param ?array{mode:string, comment_id:?int, body:string} $draft
 */
function comment_flash_set(int $artworkId, string $type, string $message, ?array $draft = null): void
{
    if ($draft !== null) {
        $draft['body'] = mb_substr(mb_scrub($draft['body'], 'UTF-8'), 0, 4000, 'UTF-8');
    }
    $_SESSION['comment_flash'] = [
        'artwork_id' => $artworkId,
        'type'       => $type,
        'message'    => $message,
        'draft'      => $draft,
    ];
}

/** Returns and clears the message, but only on the artwork page it was meant for. */
function comment_flash_pull(int $artworkId): ?array
{
    $f = $_SESSION['comment_flash'] ?? null;
    if (!is_array($f) || (int) ($f['artwork_id'] ?? 0) !== $artworkId) {
        return null;
    }
    unset($_SESSION['comment_flash']);
    return $f;
}
