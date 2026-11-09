<?php
/**
 * NSBM ArtCraft - helpers shared by the two search-suggestion endpoints.
 *
 *   search_suggestions.php         public homepage endpoint (approved artwork + categories)
 *   admin/search_suggestions.php   administrator-only endpoint (am / um / pr search boxes)
 *
 * Nothing here prints HTML. Every function starts with "ss_" so it cannot clash with the
 * existing am_ / apr_ / art_ / profile_ helpers it builds on.
 *
 * Rules every endpoint follows:
 *   - GET only, JSON only, "Cache-Control: no-store".
 *   - The query is validated (type, encoding, length) before any SQL is built.
 *   - SQL text is fixed; every value is a bound parameter; LIKE wildcards are escaped.
 *   - Hard result limit (SS_LIMIT_MAX). Database errors are logged, never sent to the browser.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/profile_helpers.php';   // also loads artwork_helpers.php

const SS_QUERY_MAX     = 100;   // same limit as the existing search inputs
const SS_LIMIT_DEFAULT = 6;
const SS_LIMIT_MAX     = 8;

/* ======================================================================
 * Response helpers
 * ==================================================================== */

/** @param array<string,mixed> $payload */
function ss_send(int $status, array $payload): never
{
    // The browser must never cache or reuse a suggestion response.
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        http_response_code(500);
        $json = '{"error":"server_error"}';
    }
    echo $json;
    exit;
}

function ss_fail(int $status, string $code): never
{
    ss_send($status, ['error' => $code]);
}

/** Only GET is accepted. */
function ss_require_get(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        ss_fail(405, 'method_not_allowed');
    }
}

/* ======================================================================
 * Request parsing
 * ==================================================================== */

/**
 * The cleaned search text ('' when absent). Anything that is not a plain, valid UTF-8
 * string of at most SS_QUERY_MAX characters is answered with 400.
 */
function ss_read_query(): string
{
    $raw = $_GET['q'] ?? '';
    if (!is_string($raw)) {
        ss_fail(400, 'invalid_query');
    }
    // Cheap byte check first so a huge value never reaches the regular expressions.
    if (strlen($raw) > SS_QUERY_MAX * 4 || !mb_check_encoding($raw, 'UTF-8')) {
        ss_fail(400, 'invalid_query');
    }

    $q = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw) ?? '';
    $q = trim((string) preg_replace('/\s+/u', ' ', $q));   // same clean-up as the real search forms

    if (mb_strlen($q, 'UTF-8') > SS_QUERY_MAX) {
        ss_fail(400, 'query_too_long');
    }
    return $q;
}

/** Number of suggestions the browser asked for, clamped to 1..SS_LIMIT_MAX. */
function ss_read_limit(): int
{
    $limit = filter_var($_GET['limit'] ?? SS_LIMIT_DEFAULT, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => SS_LIMIT_MAX, 'default' => SS_LIMIT_DEFAULT],
    ]);
    return (int) $limit;
}

/** True for a digits-only query (a possible numeric ID). */
function ss_is_digits(string $q): bool
{
    return $q !== '' && ctype_digit($q);
}

/**
 * Is the query long enough to search?
 *   - digits only  -> one digit is enough when $allowIdSearch (an ID lookup is cheap)
 *   - anything else -> at least two characters
 */
function ss_long_enough(string $q, bool $allowIdSearch): bool
{
    if ($allowIdSearch && ss_is_digits($q)) {
        return true;
    }
    return mb_strlen($q, 'UTF-8') >= 2;
}

/* ======================================================================
 * SQL helpers
 * ==================================================================== */

/** Escapes LIKE wildcards with '|' (every LIKE below says ESCAPE '|'). */
function ss_escape_like(string $value): string
{
    return str_replace(['|', '%', '_'], ['||', '|%', '|_'], $value);
}

function ss_like_contains(string $q): string
{
    return '%' . ss_escape_like($q) . '%';
}

function ss_like_prefix(string $q): string
{
    return ss_escape_like($q) . '%';
}

/**
 * Runs a prepared statement. Integers are bound as integers (needed for LIMIT with real
 * prepared statements), everything else as strings.
 *
 * @param list<int|string> $params positional values, in the order of the ? marks
 * @return list<array<string,mixed>>
 */
function ss_fetch(PDO $pdo, string $sql, array $params): array
{
    $st = $pdo->prepare($sql);
    foreach (array_values($params) as $i => $value) {
        $st->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Logs the real error, tells the browser nothing specific. */
function ss_internal_error(Throwable $e): never
{
    error_log('[search-suggestions] ' . get_class($e) . ': ' . $e->getMessage());
    ss_fail(500, 'server_error');
}

/* ======================================================================
 * User suggestions (shared by User Management and the Participation Report)
 * ==================================================================== */

/** Fixed SQL for the account-state filter (same rules as user-management.php / the report). */
const SS_USER_STATE_SQL = [
    'active'    => "u.deleted_at IS NULL AND u.account_status = 'active'",
    'suspended' => "u.deleted_at IS NULL AND u.account_status = 'suspended'",
    'removed'   => 'u.deleted_at IS NOT NULL',
];

/**
 * Users that the existing admin search would also find.
 *
 * Search fields (identical to the page queries): username, email, first name, surname,
 * "first surname", and - for digits-only text - the exact user ID.
 *
 * @param string  $role  '' (any) or one of UM_ROLES - already validated by the caller
 * @param string  $state '' (any) or a key of SS_USER_STATE_SQL - already validated by the caller
 * @return list<array<string,mixed>> suggestions
 */
function ss_user_suggestions(PDO $pdo, string $q, string $role, string $state, int $limit, bool $showRole): array
{
    $contains = ss_like_contains($q);
    $prefix   = ss_like_prefix($q);
    $idValue  = (ss_is_digits($q) && strlen($q) <= 10) ? (int) $q : null;

    $or = [
        "u.username LIKE ? ESCAPE '|'",
        "u.email LIKE ? ESCAPE '|'",
        "u.first_name LIKE ? ESCAPE '|'",
        "u.surname LIKE ? ESCAPE '|'",
        "CONCAT(u.first_name, ' ', u.surname) LIKE ? ESCAPE '|'",
    ];
    $params = [$contains, $contains, $contains, $contains, $contains];
    if ($idValue !== null) {
        $or[]     = 'u.id = ?';
        $params[] = $idValue;
    }

    $where = ['(' . implode(' OR ', $or) . ')'];
    if ($role !== '') {
        $where[]  = 'u.role = ?';
        $params[] = $role;
    }
    if ($state !== '' && isset(SS_USER_STATE_SQL[$state])) {
        $where[] = SS_USER_STATE_SQL[$state];   // fixed text from the constant above
    }

    // Best match first: exact ID, then username / full name / email that START with the text.
    $sql = "SELECT u.id, u.username, u.email, u.first_name, u.surname, u.role, u.account_status, u.deleted_at
              FROM users u
             WHERE " . implode(' AND ', $where) . "
             ORDER BY CASE WHEN u.id = ? THEN 0
                           WHEN u.username LIKE ? ESCAPE '|' THEN 1
                           WHEN CONCAT(u.first_name, ' ', u.surname) LIKE ? ESCAPE '|' THEN 2
                           WHEN u.email LIKE ? ESCAPE '|' THEN 3
                           ELSE 4 END,
                      u.username ASC, u.id ASC
             LIMIT ?";
    $params[] = $idValue ?? -1;
    $params[] = $prefix;
    $params[] = $prefix;
    $params[] = $prefix;
    $params[] = $limit;

    $out = [];
    foreach (ss_fetch($pdo, $sql, $params) as $row) {
        $id       = (int) $row['id'];
        $username = (string) $row['username'];
        $full     = trim((string) $row['first_name'] . ' ' . (string) $row['surname']);
        $removed  = $row['deleted_at'] !== null;
        $account  = $removed ? 'removed' : (string) $row['account_status'];

        $meta = [$username, (string) $row['email'], '#' . $id];
        if ($showRole) {
            $meta[] = $row['role'] === 'admin' ? 'Admin' : 'Student';
        }

        $out[] = [
            'type'  => 'user',
            'id'    => $id,
            'label' => $full !== '' ? $full : $username,
            'meta'  => implode(' · ', $meta),
            'value' => $username,   // unique, and matched by the existing search
            'tag'   => ucfirst($account),
            'tone'  => $account === 'active' ? 'ok' : ($account === 'removed' ? 'bad' : 'neutral'),
        ];
    }
    return $out;
}
