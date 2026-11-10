<?php
/**
 * NSBM ArtCraft - Artwork Participation Report: query helpers.
 *
 * Used by admin/partials/artwork-participation-report.php. Nothing here prints HTML.
 *
 * Rules the report follows:
 *   - `users` is the base table; artworks are counted in ONE grouped subquery
 *     (one row per user_id) that is LEFT JOINed, so users with no artworks stay
 *     in the report and artwork rows are never multiplied.
 *   - Counts describe each artwork's CURRENT `artworks.status`.
 *     `artwork_review_history` is deliberately never touched.
 *   - Only whitelisted sort keys / directions ever reach the SQL text.
 *   - Every value from the request is bound as a prepared-statement parameter
 *     (integers with PDO::PARAM_INT). Placeholder names are all unique because
 *     the connection uses real (non-emulated) prepared statements.
 *   - `users.username` is a generated column: it is only ever selected,
 *     searched and sorted - never written.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';              // db() -> PDO
require_once __DIR__ . '/artwork_helpers.php'; // art_session_user_id(), art_e()

const APR_PAGE_SIZE      = 25;
const APR_SEARCH_MAX_LEN = 100;

/** Sort key => fixed SQL expression. Request values are only ever used as array keys. */
const APR_SORT_SQL = [
    'username' => 'u.username',
    'total'    => 'COALESCE(a.total_count, 0)',
    'approved' => 'COALESCE(a.approved_count, 0)',
    'rejected' => 'COALESCE(a.rejected_count, 0)',
];

/** Account filter keys accepted from the request. */
const APR_STATUS_FILTERS = ['all', 'active', 'suspended', 'removed'];

/* ======================================================================
 * Access control
 * ==================================================================== */

/**
 * True only when the logged-in user is, in the DATABASE right now, an active,
 * non-removed administrator. The session role is never consulted.
 */
function apr_viewer_is_admin(PDO $pdo): bool
{
    $uid = art_session_user_id();
    if ($uid === null) {
        return false;
    }

    $st = $pdo->prepare(
        'SELECT role, account_status, deleted_at
           FROM users
          WHERE id = :uid
          LIMIT 1'
    );
    $st->bindValue(':uid', $uid, PDO::PARAM_INT);
    $st->execute();
    $row = $st->fetch();

    return is_array($row)
        && $row['role'] === 'admin'
        && $row['account_status'] === 'active'
        && $row['deleted_at'] === null;
}

/* ======================================================================
 * Request handling
 * ==================================================================== */

/**
 * Turns $_GET into a clean, bounded state array. Unknown or malformed values
 * fall back to defaults, so the result is always safe to use.
 *
 * @return array{q:string, status:string, sort:string, dir:string, page:int}
 */
function apr_read_request(array $get): array
{
    $q = $get['pr_q'] ?? '';
    $q = is_string($q) ? $q : '';
    $q = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $q) ?? '';   // no control characters
    $q = trim((string) preg_replace('/\s+/u', ' ', $q));
    if (function_exists('mb_substr')) {
        $q = mb_substr($q, 0, APR_SEARCH_MAX_LEN, 'UTF-8');
    } else {
        $q = substr($q, 0, APR_SEARCH_MAX_LEN);
    }

    $status = $get['pr_status'] ?? 'all';
    $status = (is_string($status) && in_array($status, APR_STATUS_FILTERS, true)) ? $status : 'all';

    $sort = $get['pr_sort'] ?? 'username';
    $sort = (is_string($sort) && array_key_exists($sort, APR_SORT_SQL)) ? $sort : 'username';

    $dir = $get['pr_dir'] ?? '';
    if (!is_string($dir) || !in_array($dir, ['asc', 'desc'], true)) {
        $dir = apr_default_dir($sort);
    }

    $page = filter_var($get['pr_page'] ?? 1, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 1000000, 'default' => 1],
    ]);

    return [
        'q'      => $q,
        'status' => $status,
        'sort'   => $sort,
        'dir'    => $dir,
        'page'   => (int) $page,
    ];
}

/** Names sort A-Z first; counts sort biggest first. */
function apr_default_dir(string $sort): string
{
    return $sort === 'username' ? 'asc' : 'desc';
}

/* ======================================================================
 * SQL building
 * ==================================================================== */

/**
 * FROM + WHERE for the current filters.
 *
 * `a` is the pre-aggregated artwork subquery: exactly one row per user_id.
 * COUNT(*) counts artwork records regardless of status; the other counts use
 * the artwork's current status. Rows are only counted, never joined further.
 *
 * @return array{0:string, 1:list<array{0:string, 1:int|string, 2:int}>}
 *         [sql fragment, parameters as [name, value, PDO type]]
 */
function apr_from_where(array $state): array
{
    $sql = "FROM users u
            LEFT JOIN (
                SELECT user_id,
                       COUNT(*)                                          AS total_count,
                       SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                       SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
                       SUM(CASE WHEN status = 'pending'  THEN 1 ELSE 0 END) AS pending_count
                  FROM artworks
                 GROUP BY user_id
            ) a ON a.user_id = u.id";

    $where  = [];
    $params = [];

    // Account filter: Removed (soft-deleted) wins over the stored account_status.
    switch ($state['status']) {
        case 'active':
            $where[] = "u.deleted_at IS NULL AND u.account_status = 'active'";
            break;
        case 'suspended':
            $where[] = "u.deleted_at IS NULL AND u.account_status = 'suspended'";
            break;
        case 'removed':
            $where[] = 'u.deleted_at IS NOT NULL';
            break;
        default: // 'all' - every account, including removed ones
            break;
    }

    // Search: username, email, first name, surname, "first surname", or exact user ID.
    // Every placeholder has its own name (required with real prepared statements).
    if ($state['q'] !== '') {
        $like = '%' . apr_escape_like($state['q']) . '%';

        $parts = [
            "u.username LIKE :q_username ESCAPE '|'",
            "u.email LIKE :q_email ESCAPE '|'",
            "u.first_name LIKE :q_first ESCAPE '|'",
            "u.surname LIKE :q_surname ESCAPE '|'",
            "CONCAT(u.first_name, ' ', u.surname) LIKE :q_full ESCAPE '|'",
        ];
        foreach ([':q_username', ':q_email', ':q_first', ':q_surname', ':q_full'] as $name) {
            $params[] = [$name, $like, PDO::PARAM_STR];
        }

        if (ctype_digit($state['q']) && strlen($state['q']) <= 10) {
            $parts[]  = 'u.id = :q_id';
            $params[] = [':q_id', (int) $state['q'], PDO::PARAM_INT];
        }

        $where[] = '(' . implode(' OR ', $parts) . ')';
    }

    if ($where) {
        $sql .= "\n WHERE " . implode(' AND ', $where);
    }

    return [$sql, $params];
}

/** Escapes LIKE wildcards using '|' as the escape character (see ESCAPE '|' above). */
function apr_escape_like(string $value): string
{
    return str_replace(['|', '%', '_'], ['||', '|%', '|_'], $value);
}

/** Binds [name, value, type] triples to a prepared statement. */
function apr_bind(PDOStatement $st, array $params): void
{
    foreach ($params as [$name, $value, $type]) {
        $st->bindValue($name, $value, $type);
    }
}

/* ======================================================================
 * Queries
 * ==================================================================== */

/**
 * Totals for everything the current filters match (all pages, not just the
 * visible one). Computed by the database; nothing is hardcoded.
 *
 * @return array{users:int, total:int, approved:int, rejected:int, pending:int}
 */
function apr_fetch_summary(PDO $pdo, array $state): array
{
    [$from, $params] = apr_from_where($state);

    $st = $pdo->prepare(
        "SELECT COUNT(*)                          AS user_count,
                COALESCE(SUM(a.total_count), 0)    AS total_count,
                COALESCE(SUM(a.approved_count), 0) AS approved_count,
                COALESCE(SUM(a.rejected_count), 0) AS rejected_count,
                COALESCE(SUM(a.pending_count), 0)  AS pending_count
           $from"
    );
    apr_bind($st, $params);
    $st->execute();
    $row = $st->fetch() ?: [];

    return [
        'users'    => (int) ($row['user_count'] ?? 0),
        'total'    => (int) ($row['total_count'] ?? 0),
        'approved' => (int) ($row['approved_count'] ?? 0),
        'rejected' => (int) ($row['rejected_count'] ?? 0),
        'pending'  => (int) ($row['pending_count'] ?? 0),
    ];
}

/**
 * One page of report rows. $limit and $offset are integers bound with PARAM_INT.
 *
 * @return list<array{id:int, username:string, email:string, account:string,
 *                    total:int, approved:int, rejected:int, pending:int}>
 */
function apr_fetch_rows(PDO $pdo, array $state, int $limit, int $offset): array
{
    [$from, $params] = apr_from_where($state);

    // Both values come from whitelists: unknown keys cannot reach the SQL text.
    $sortSql = APR_SORT_SQL[$state['sort']] ?? APR_SORT_SQL['username'];
    $dirSql  = $state['dir'] === 'desc' ? 'DESC' : 'ASC';

    $st = $pdo->prepare(
        "SELECT u.id, u.username, u.email, u.account_status, u.deleted_at,
                COALESCE(a.total_count, 0)    AS total_count,
                COALESCE(a.approved_count, 0) AS approved_count,
                COALESCE(a.rejected_count, 0) AS rejected_count,
                COALESCE(a.pending_count, 0)  AS pending_count
           $from
          ORDER BY $sortSql $dirSql, u.username ASC, u.id ASC
          LIMIT :row_limit OFFSET :row_offset"
    );
    apr_bind($st, $params);
    $st->bindValue(':row_limit', $limit, PDO::PARAM_INT);
    $st->bindValue(':row_offset', $offset, PDO::PARAM_INT);
    $st->execute();

    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            'id'       => (int) $r['id'],
            'username' => (string) $r['username'],
            'email'    => (string) $r['email'],
            'account'  => $r['deleted_at'] !== null ? 'removed' : (string) $r['account_status'],
            'total'    => (int) $r['total_count'],
            'approved' => (int) $r['approved_count'],
            'rejected' => (int) $r['rejected_count'],
            'pending'  => (int) $r['pending_count'],
        ];
    }

    return $rows;
}
