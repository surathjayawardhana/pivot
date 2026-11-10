<?php
/**
 * Helpers for the admin "Artwork Management" feature (approve / reject submissions).
 *
 * Used by:
 *   admin/partials/artwork-management.php   the visible log section
 *   admin/artwork-review-action.php         the POST endpoint that reviews an artwork
 *   admin/view-artwork.php                  the private admin preview page
 *
 * Builds on the existing project code instead of replacing it:
 *   includes/db.php              db(), e(), csrf_token(), csrf_valid(), post_string(), clean_text(), text_length()
 *   includes/artwork_helpers.php art_session_user_id()
 *   includes/profile_helpers.php profile_artwork_image_url()
 *
 * Every function here starts with "am_" (artwork management) so it cannot clash with
 * the helpers of the other admin sections.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/profile_helpers.php';   // also loads artwork_helpers.php

const AM_STATUSES      = ['pending', 'approved', 'rejected'];
const AM_FILTERS       = ['all', 'pending', 'approved', 'rejected'];
const AM_ACTIONS       = ['approve' => 'approved', 'reject' => 'rejected'];   // action => new status
const AM_PAGE_SIZE     = 25;
const AM_FEEDBACK_MAX  = 500;
const AM_SEARCH_MAX    = 100;
const AM_SECTION_ID    = 'artwork-management';

/* ======================================================================
 * Who is asking? (always read from the database, never from $_SESSION['role'])
 * ==================================================================== */

/**
 * The logged-in user if - and only if - that user currently exists, is active,
 * is not soft-deleted and has the role "admin". Otherwise null.
 *
 * @return ?array{id:int, first_name:string, surname:string, email:string, username:string}
 */
function am_current_admin(PDO $pdo): ?array
{
    $uid = art_session_user_id();
    if ($uid === null) {
        return null;
    }

    $st = $pdo->prepare(
        'SELECT id, first_name, surname, email, username, role, account_status, deleted_at
           FROM users
          WHERE id = ?'
    );
    $st->bindValue(1, $uid, PDO::PARAM_INT);
    $st->execute();
    $user = $st->fetch();

    if (
        !$user
        || $user['role'] !== 'admin'
        || $user['account_status'] !== 'active'
        || $user['deleted_at'] !== null
    ) {
        return null;
    }

    return [
        'id'         => (int) $user['id'],
        'first_name' => (string) $user['first_name'],
        'surname'    => (string) $user['surname'],
        'email'      => (string) $user['email'],
        'username'   => (string) $user['username'],
    ];
}

/* ======================================================================
 * Schema check (so a database without the new tables gives a clear message)
 * ==================================================================== */

/**
 * Names of the database objects this feature needs but the connected database lacks.
 * An empty array means everything is in place.
 *
 * @return string[]
 */
function am_schema_missing(PDO $pdo): array
{
    $missing = [];

    $st = $pdo->prepare(
        'SELECT TABLE_NAME FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?)'
    );
    $st->execute(['artwork_review_history', 'activity_logs']);
    $present = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN, 0));

    foreach (['artwork_review_history', 'activity_logs'] as $table) {
        if (!in_array($table, $present, true)) {
            $missing[] = 'table ' . $table;
        }
    }

    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute(['users', 'deleted_at']);
    if ((int) $st->fetchColumn() === 0) {
        $missing[] = 'column users.deleted_at';
    }

    return $missing;
}

/* ======================================================================
 * Flash message (its own session key, so it is never shown by - or stolen
 * from - the Homepage Showcase section, which uses art_flash_*)
 * ==================================================================== */

/** @param 'success'|'error'|'info' $type */
function am_flash_set(string $type, string $message): void
{
    $_SESSION['am_flash'] = ['type' => $type, 'message' => $message];
}

/** @return ?array{type:string, message:string} */
function am_flash_pull(): ?array
{
    $flash = $_SESSION['am_flash'] ?? null;
    unset($_SESSION['am_flash']);

    if (!is_array($flash) || !is_string($flash['message'] ?? null)) {
        return null;
    }
    $type = in_array($flash['type'] ?? '', ['success', 'error', 'info'], true) ? $flash['type'] : 'info';
    return ['type' => $type, 'message' => $flash['message']];
}

/* ======================================================================
 * Request parameters (whitelisted / validated - never concatenated into SQL)
 * ==================================================================== */

/**
 * The list view the admin was on: status filter, search text and page.
 * Works on $_GET (the section) or $_POST (the action endpoint, which sends the view back).
 *
 * @param array<string,mixed> $source
 * @return array{status:string, q:string, page:int}
 */
function am_view_params(array $source): array
{
    $status = $source['am_status'] ?? 'all';
    if (!is_string($status) || !in_array($status, AM_FILTERS, true)) {
        $status = 'all';
    }

    $q = $source['am_q'] ?? '';
    $q = is_string($q) ? $q : '';
    if (!mb_check_encoding($q, 'UTF-8')) {
        $q = '';
    }
    $q = clean_text(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $q) ?? '');
    $q = mb_substr($q, 0, AM_SEARCH_MAX, 'UTF-8');

    $page = filter_var($source['am_page'] ?? 1, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 100000, 'default' => 1],
    ]);

    return ['status' => $status, 'q' => $q, 'page' => (int) $page];
}

/** Query string (without the leading "?") that restores a list view. Defaults are left out. */
function am_view_query(array $view): string
{
    $query = [];
    if (($view['status'] ?? 'all') !== 'all') {
        $query['am_status'] = $view['status'];
    }
    if (($view['q'] ?? '') !== '') {
        $query['am_q'] = $view['q'];
    }
    if ((int) ($view['page'] ?? 1) > 1) {
        $query['am_page'] = (int) $view['page'];
    }
    return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

/** Cleaned optional review note, or an error message. @return array{0:?string, 1:?string} [note, error] */
function am_clean_feedback(mixed $raw): array
{
    $raw = is_string($raw) ? $raw : '';
    if (!mb_check_encoding($raw, 'UTF-8')) {
        return [null, 'The note contains characters that are not allowed.'];
    }
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $raw)) {
        return [null, 'The note contains characters that are not allowed.'];
    }
    $note = clean_text($raw);
    if (text_length($note) > AM_FEEDBACK_MAX) {
        return [null, 'The note can be at most ' . AM_FEEDBACK_MAX . ' characters.'];
    }
    return [$note === '' ? null : $note, null];
}

/* ======================================================================
 * Display helpers
 * ==================================================================== */

/** "2026-11-05 07:27:25" -> "2026-11-05 07:27" (no timezone conversion: shown as stored). */
function am_fmt_dt(?string $value): string
{
    if ($value === null || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $value)) {
        return '';
    }
    return substr($value, 0, 16);
}

/** Value for a <time datetime=""> attribute. */
function am_dt_attr(?string $value): string
{
    $text = am_fmt_dt($value);
    return $text === '' ? '' : str_replace(' ', 'T', $text);
}

/** @return array{0:string,1:string} [label, css modifier] */
function am_status_label(string $status): array
{
    return match ($status) {
        'approved' => ['Approved', 'approved'],
        'rejected' => ['Rejected', 'rejected'],
        default    => ['Pending', 'pending'],
    };
}

/* ======================================================================
 * Audit helpers
 * ==================================================================== */

/** The client address as seen by the web server (forwarding headers are not trusted). */
function am_client_ip(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? substr($ip, 0, 45) : null;
}

function am_user_agent(): ?string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    if (!is_string($ua) || $ua === '') {
        return null;
    }
    $ua = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $ua);
    return $ua === '' ? null : mb_substr($ua, 0, 512, 'UTF-8');
}

/* ======================================================================
 * The review itself
 * ==================================================================== */

/**
 * Approves or rejects one artwork in a single transaction:
 *   lock artwork -> update artworks -> insert artwork_review_history -> insert activity_logs.
 * If any step fails everything is rolled back, so the status never changes without an audit row.
 *
 * @param string  $action     'approve' | 'reject' (the new status is derived here, never posted)
 * @param string  $expected   the status the admin saw when the page was drawn (stale-page guard)
 * @return array{type:string, message:string}  type is 'success', 'error' or 'info'
 */
function am_review_artwork(PDO $pdo, int $adminId, int $artworkId, string $action, string $expected, ?string $feedback): array
{
    if (!isset(AM_ACTIONS[$action]) || !in_array($expected, AM_STATUSES, true) || $artworkId < 1) {
        return ['type' => 'error', 'message' => 'Invalid request.'];
    }
    $newStatus = AM_ACTIONS[$action];

    try {
        $pdo->beginTransaction();

        // 1. Re-check the acting admin inside the transaction. The shared lock stops the
        //    account from being demoted or removed until this review is committed.
        $st = $pdo->prepare(
            'SELECT id, username, role, account_status, deleted_at FROM users WHERE id = ? FOR SHARE'
        );
        $st->bindValue(1, $adminId, PDO::PARAM_INT);
        $st->execute();
        $admin = $st->fetch();

        if (
            !$admin
            || $admin['role'] !== 'admin'
            || $admin['account_status'] !== 'active'
            || $admin['deleted_at'] !== null
        ) {
            $pdo->rollBack();
            return ['type' => 'error', 'message' => 'You no longer have permission to review artwork.'];
        }

        // 2. Lock the artwork row so two administrators cannot overwrite each other.
        $st = $pdo->prepare(
            'SELECT id, user_id, title, status, featured_slot FROM artworks WHERE id = ? FOR UPDATE'
        );
        $st->bindValue(1, $artworkId, PDO::PARAM_INT);
        $st->execute();
        $art = $st->fetch();

        if (!$art) {
            $pdo->rollBack();
            return ['type' => 'error', 'message' => 'That artwork could not be found.'];
        }

        $previous = (string) $art['status'];
        $title    = (string) $art['title'];

        // 3. Nothing to do? Say so without writing a false history row.
        if ($previous === $newStatus) {
            $pdo->rollBack();
            return [
                'type'    => 'info',
                'message' => '“' . $title . '” is already ' . $newStatus . '. No change was made.',
            ];
        }

        // 4. Stale page: someone else changed it after this list was drawn.
        if ($previous !== $expected) {
            $pdo->rollBack();
            return [
                'type'    => 'error',
                'message' => '“' . $title . '” was changed by someone else (it is now ' . $previous . '). '
                           . 'Nothing was changed - please check the list and try again.',
            ];
        }

        // 5. Snapshots for the audit trail.
        $st = $pdo->prepare('SELECT id, username FROM users WHERE id = ?');
        $st->bindValue(1, (int) $art['user_id'], PDO::PARAM_INT);
        $st->execute();
        $artist = $st->fetch();
        if (!$artist) {
            throw new RuntimeException('Artist #' . (int) $art['user_id'] . ' of artwork #' . $artworkId . ' not found.');
        }

        // One timestamp from the database for every row written below.
        $now = (string) $pdo->query('SELECT NOW()')->fetchColumn();

        $oldSlot  = $art['featured_slot'] === null ? null : (string) $art['featured_slot'];
        $unfeature = $newStatus === 'rejected' && $oldSlot !== null;

        // 6. Update the artwork. A rejected artwork can never stay featured.
        //    updated_at is maintained by the table (ON UPDATE CURRENT_TIMESTAMP).
        $sql = 'UPDATE artworks
                   SET status = ?, admin_feedback = ?, reviewed_by = ?, reviewed_at = ?'
             . ($newStatus === 'rejected' ? ', featured_slot = NULL' : '')
             . ' WHERE id = ? AND status = ?';
        $st = $pdo->prepare($sql);
        $st->bindValue(1, $newStatus, PDO::PARAM_STR);
        $st->bindValue(2, $feedback, $feedback === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue(3, $adminId, PDO::PARAM_INT);
        $st->bindValue(4, $now, PDO::PARAM_STR);
        $st->bindValue(5, $artworkId, PDO::PARAM_INT);
        $st->bindValue(6, $previous, PDO::PARAM_STR);
        $st->execute();
        if ($st->rowCount() !== 1) {
            throw new RuntimeException('Review of artwork #' . $artworkId . ' updated ' . $st->rowCount() . ' rows.');
        }

        // 7. Review history (one row per real status transition).
        $st = $pdo->prepare(
            'INSERT INTO artwork_review_history
                (artwork_id, artwork_title_snapshot, artist_user_id, artist_username_snapshot,
                 reviewer_user_id, reviewer_username_snapshot, previous_status, new_status, feedback, reviewed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->bindValue(1, $artworkId, PDO::PARAM_INT);
        $st->bindValue(2, $title, PDO::PARAM_STR);
        $st->bindValue(3, (int) $artist['id'], PDO::PARAM_INT);
        $st->bindValue(4, (string) $artist['username'], PDO::PARAM_STR);
        $st->bindValue(5, $adminId, PDO::PARAM_INT);
        $st->bindValue(6, (string) $admin['username'], PDO::PARAM_STR);
        $st->bindValue(7, $previous, PDO::PARAM_STR);
        $st->bindValue(8, $newStatus, PDO::PARAM_STR);
        $st->bindValue(9, $feedback, $feedback === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue(10, $now, PDO::PARAM_STR);
        $st->execute();

        // 8. Activity log. Only status / slot values are stored - nothing private.
        $verb        = $newStatus === 'approved' ? 'Approved' : 'Rejected';
        $description = $verb . ' artwork #' . $artworkId . ' “' . $title . '” by ' . (string) $artist['username']
                     . ' (was ' . $previous . ').'
                     . ($unfeature ? ' Removed from featured slot ' . $oldSlot . '.' : '');

        $oldValues = ['status' => $previous, 'featured_slot' => $oldSlot];
        $newValues = [
            'status'        => $newStatus,
            'featured_slot' => $newStatus === 'rejected' ? null : $oldSlot,
            'reviewed_by'   => $adminId,
        ];
        $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

        $st = $pdo->prepare(
            'INSERT INTO activity_logs
                (actor_user_id, actor_username_snapshot, actor_role_snapshot, action,
                 entity_type, entity_id, entity_label, description, old_values, new_values,
                 ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ip = am_client_ip();
        $ua = am_user_agent();
        $st->bindValue(1, $adminId, PDO::PARAM_INT);
        $st->bindValue(2, (string) $admin['username'], PDO::PARAM_STR);
        $st->bindValue(3, 'admin', PDO::PARAM_STR);
        $st->bindValue(4, $newStatus === 'approved' ? 'artwork.approved' : 'artwork.rejected', PDO::PARAM_STR);
        $st->bindValue(5, 'artwork', PDO::PARAM_STR);
        $st->bindValue(6, $artworkId, PDO::PARAM_INT);
        $st->bindValue(7, mb_substr($title, 0, 255, 'UTF-8'), PDO::PARAM_STR);
        $st->bindValue(8, mb_substr($description, 0, 500, 'UTF-8'), PDO::PARAM_STR);
        $st->bindValue(9, json_encode($oldValues, $jsonFlags), PDO::PARAM_STR);
        $st->bindValue(10, json_encode($newValues, $jsonFlags), PDO::PARAM_STR);
        $st->bindValue(11, $ip, $ip === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue(12, $ua, $ua === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue(13, $now, PDO::PARAM_STR);
        $st->execute();

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Technical details go to the server log only.
        error_log('[artwork-review] artwork #' . $artworkId . ' ' . $action . ': ' . $e->getMessage());
        return [
            'type'    => 'error',
            'message' => 'The review could not be saved, so nothing was changed. Please try again.',
        ];
    }

    return [
        'type'    => 'success',
        'message' => $verb . ' “' . $title . '”.'
                   . ($unfeature ? ' It was also removed from its featured slot.' : ''),
    ];
}
