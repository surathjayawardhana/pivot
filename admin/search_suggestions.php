<?php
/**
 * Administrator search suggestions (JSON).
 *
 *   GET admin/search_suggestions.php?context=am|um|pr&q=...&limit=6[&filter params]
 *
 *   context  box it serves      page search it mirrors            filter params it understands
 *   -------  -----------------  --------------------------------  ----------------------------
 *   am       Artwork Management am_q  (title, artist, email, ID)  am_status
 *   um       User Management    um_q  (name, user, email, ID)     um_role, um_state
 *   pr       Participation Rpt  pr_q  (name, user, email, ID)     pr_status
 *
 * Security:
 *   - Every request is checked against the DATABASE: the account must exist, be active, not
 *     removed and currently have role "admin". $_SESSION['role'] is never consulted, and being
 *     inside an admin page proves nothing: this file can be called directly.
 *   - The context comes from a fixed whitelist. The browser can only pick one of the three
 *     contexts and pass whitelisted filter values; it never chooses tables, columns or SQL.
 *   - Read-only. Choosing a suggestion in the browser only fills the search box.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/search_suggestions_helpers.php';
require_once __DIR__ . '/../includes/artwork_review_helpers.php';    // am_current_admin(), AM_FILTERS
require_once __DIR__ . '/../includes/user_management_rules.php';     // UM_ROLES

ss_require_get();

try {
    $pdo = db();

    // 401 = not signed in at all; 403 = signed in but not a current, active administrator.
    if (art_session_user_id() === null) {
        ss_fail(401, 'not_signed_in');
    }
    if (am_current_admin($pdo) === null) {
        ss_fail(403, 'forbidden');
    }
    // Authorisation is done: release the session lock for the (slow) searching below.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // ---- Context: fixed whitelist --------------------------------------------------
    $context = $_GET['context'] ?? '';
    if (!is_string($context) || !in_array($context, ['am', 'um', 'pr'], true)) {
        ss_fail(400, 'invalid_context');
    }

    $q     = ss_read_query();
    $limit = ss_read_limit();

    // Admin searches also find an exact numeric ID, so one digit is enough for those.
    if (!ss_long_enough($q, true)) {
        ss_send(200, ['suggestions' => []]);
    }

    /** A filter value from the whitelist, or '' (what each page does with an unknown value). */
    $filter = static function (string $name, array $allowed): string {
        $value = $_GET[$name] ?? '';
        return (is_string($value) && in_array($value, $allowed, true)) ? $value : '';
    };

    if ($context === 'am') {
        // Same filter as artwork-management.php: status tab (all / pending / approved / rejected).
        $status = $filter('am_status', AM_FILTERS);
        if ($status === 'all') {
            $status = '';
        }

        $contains = ss_like_contains($q);
        $prefix   = ss_like_prefix($q);
        $idValue  = (ss_is_digits($q) && strlen($q) <= 9) ? (int) $q : null;

        $or = [
            "a.title LIKE ? ESCAPE '|'",
            "u.username LIKE ? ESCAPE '|'",
            "u.email LIKE ? ESCAPE '|'",
        ];
        $params = [];
        $where  = [];
        if ($status !== '') {
            $where[]  = 'a.status = ?';
            $params[] = $status;
        }
        array_push($params, $contains, $contains, $contains);
        if ($idValue !== null) {
            $or[]     = 'a.id = ?';
            $params[] = $idValue;
        }
        $where[] = '(' . implode(' OR ', $or) . ')';

        $params[] = $idValue ?? -1;
        $params[] = $prefix;
        $params[] = $prefix;
        $params[] = $limit;

        $rows = ss_fetch(
            $pdo,
            "SELECT a.id, a.title, a.status, u.username AS artist_username
               FROM artworks a
               JOIN users u ON u.id = a.user_id
              WHERE " . implode(' AND ', $where) . "
              ORDER BY CASE WHEN a.id = ? THEN 0
                            WHEN a.title LIKE ? ESCAPE '|' THEN 1
                            WHEN u.username LIKE ? ESCAPE '|' THEN 2
                            ELSE 3 END,
                       a.created_at DESC, a.id DESC
              LIMIT ?",
            $params
        );

        $tones = ['approved' => 'ok', 'rejected' => 'bad', 'pending' => 'neutral'];
        $out   = [];
        foreach ($rows as $r) {
            $st = (string) $r['status'];
            $out[] = [
                'type'  => 'artwork',
                'id'    => (int) $r['id'],
                'label' => (string) $r['title'],
                'meta'  => '#' . (int) $r['id'] . ' · ' . (string) $r['artist_username'],
                'value' => (string) $r['title'],
                'tag'   => ucfirst($st),
                'tone'  => $tones[$st] ?? 'neutral',
            ];
        }
        ss_send(200, ['suggestions' => $out]);
    }

    if ($context === 'um') {
        // Same filters as user-management.php: role and account state.
        $out = ss_user_suggestions(
            $pdo,
            $q,
            $filter('um_role', UM_ROLES),
            $filter('um_state', array_keys(SS_USER_STATE_SQL)),
            $limit,
            true
        );
        ss_send(200, ['suggestions' => $out]);
    }

    // $context === 'pr': same account filter as the participation report ('all' = no filter).
    $out = ss_user_suggestions(
        $pdo,
        $q,
        '',
        $filter('pr_status', array_keys(SS_USER_STATE_SQL)),
        $limit,
        false
    );
    ss_send(200, ['suggestions' => $out]);
} catch (Throwable $e) {
    ss_internal_error($e);
}
