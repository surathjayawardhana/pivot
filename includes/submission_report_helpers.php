<?php
/**
 * Artwork Submission Report - shared code.
 *
 * Used by:
 *   admin/partials/artwork-submission-report.php   (on-screen section)
 *   admin/export-artwork-submissions.php           (Markdown download)
 *   admin/artwork-preview.php                      (admin-only artwork preview)
 *
 * Both the screen and the export call sr_fetch_rows() with the same boundaries, so
 * they always show the same result set.
 *
 * Reuses: art_db() / ArtDb, art_session_user_id()  (includes/artwork_helpers.php)
 * All functions start with sr_ so they cannot clash with other admin partials.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/artwork_helpers.php';

/** Presentation time zone for period boundaries and every displayed date. */
const SR_TIMEZONE = 'Asia/Colombo';

/** Period whitelist: key => label. Default is the first key. */
const SR_PERIODS = [
    'today' => 'Today',
    'week'  => 'This week',
    'month' => 'This month',
];

const SR_NOT_APPROVED = 'Not approved';
const SR_NOT_RECORDED = 'Not recorded';

/* ======================================================================
 * Schema awareness (the bundled artcraft.sql is older than Db.sql)
 * ==================================================================== */

/**
 * Which newer schema parts exist in the connected database. Lets the report keep
 * working (with less approver detail) on a database that has not been migrated yet.
 *
 * @return array{review_history: bool, users_deleted_at: bool}
 */
function sr_schema_flags(ArtDb $db): array
{
    static $flags = null;
    if ($flags !== null) {
        return $flags;
    }

    $row = $db->one(
        "SELECT
            (SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'artwork_review_history') AS has_history,
            (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'deleted_at') AS has_deleted"
    );

    return $flags = [
        'review_history'   => (int) ($row['has_history'] ?? 0) > 0,
        'users_deleted_at' => (int) ($row['has_deleted'] ?? 0) > 0,
    ];
}

/* ======================================================================
 * Authorization (always from the database, never from $_SESSION['role'])
 * ==================================================================== */

/**
 * The logged-in user if - right now - they exist, are active, are not soft-deleted
 * and have role 'admin'. Otherwise null.
 *
 * @return ?array{id:int, username:string, role:string}
 */
function sr_current_admin(ArtDb $db): ?array
{
    $uid = art_session_user_id();
    if (!$uid) {
        return null;
    }

    $sql = "SELECT id, username, role FROM users
             WHERE id = ? AND role = 'admin' AND account_status = 'active'";
    if (sr_schema_flags($db)['users_deleted_at']) {
        $sql .= ' AND deleted_at IS NULL';
    }

    $row = $db->one($sql, [$uid]);
    if (!$row) {
        return null;
    }
    $row['id'] = (int) $row['id'];
    return $row;
}

/* ======================================================================
 * Period handling
 * ==================================================================== */

function sr_timezone(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone(SR_TIMEZONE);
}

/** Strict whitelist; anything unknown (or an array) becomes the default 'today'. */
function sr_period_from(mixed $raw): string
{
    return is_string($raw) && array_key_exists($raw, SR_PERIODS) ? $raw : 'today';
}

/**
 * Start and end of a period in SR_TIMEZONE.
 *   today : 00:00 today            -> now
 *   week  : Monday 00:00 this week -> now
 *   month : 00:00 on the 1st       -> now
 *
 * $until (unix time) lets the export reuse the exact end moment the page showed.
 * It is clamped into [start, now], so it can never reach into the future.
 *
 * @return array{start: DateTimeImmutable, end: DateTimeImmutable}
 */
function sr_bounds(string $period, ?DateTimeImmutable $now = null, ?int $until = null): array
{
    $tz  = sr_timezone();
    $now = ($now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);
    $now = $now->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));   // drop microseconds

    $midnight = $now->setTime(0, 0, 0);
    $start = match ($period) {
        'week'  => $midnight->modify('-' . ((int) $now->format('N') - 1) . ' days'),   // N: Monday = 1
        'month' => $midnight->setDate((int) $now->format('Y'), (int) $now->format('n'), 1),
        default => $midnight,
    };

    $end = $now;
    if ($until !== null && $until >= $start->getTimestamp() && $until < $now->getTimestamp()) {
        $end = (new DateTimeImmutable('@' . $until))->setTimezone($tz);
    }

    return ['start' => $start, 'end' => $end];
}

/** "2026-11-05 00:00:00 to 2026-11-05 14:32:10 (Asia/Colombo, UTC+05:30)" */
function sr_range_text(DateTimeImmutable $start, DateTimeImmutable $end): string
{
    return $start->format('Y-m-d H:i:s') . ' to ' . $end->format('Y-m-d H:i:s')
         . ' (' . SR_TIMEZONE . ', UTC' . $end->format('P') . ')';
}

/* ======================================================================
 * The one query behind both the screen and the export
 * ==================================================================== */

/**
 * Every artwork submitted in [$start, $end], whatever its current status,
 * newest first. Exactly one row per artwork.
 *
 * created_at is a TIMESTAMP: MySQL stores it as UTC and shows it in the connection's
 * time zone. The boundaries are therefore passed as unix timestamps and turned into
 * dates by FROM_UNIXTIME(), which uses that same connection time zone, so the
 * comparison is correct whatever the server/session time zone is. The submission time
 * is read back with UNIX_TIMESTAMP() and formatted in PHP, again with no dependency on
 * the connection time zone.
 *
 * Approving admin (only when the artwork is currently approved):
 *   1. username snapshot of the latest 'approved' row in artwork_review_history
 *      (survives the reviewer being renamed/soft-deleted/removed),
 *   2. else the current username of artworks.reviewed_by,
 *   3. else 'Not recorded' (nothing is guessed).
 * The history row is picked with a correlated subquery on its primary key, so the
 * join can never duplicate an artwork.
 *
 * @return list<array{
 *   id:int, title:string, artist_username:string, artist_email:string, status:string,
 *   submitted_at:DateTimeImmutable, approver:string, approver_recorded:bool
 * }>
 */
function sr_fetch_rows(ArtDb $db, DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $flags = sr_schema_flags($db);

    $historySelect = $flags['review_history']
        ? 'h.reviewer_username_snapshot AS history_reviewer'
        : 'NULL AS history_reviewer';
    $historyJoin = $flags['review_history']
        ? "LEFT JOIN artwork_review_history h
                  ON h.id = (
                       SELECT h2.id
                         FROM artwork_review_history h2
                        WHERE h2.artwork_id = a.id AND h2.new_status = 'approved'
                        ORDER BY h2.reviewed_at DESC, h2.id DESC
                        LIMIT 1
                     )"
        : '';

    $sql = "SELECT a.id, a.title, a.status,
                   UNIX_TIMESTAMP(a.created_at) AS created_epoch,
                   u.username AS artist_username,
                   u.email    AS artist_email,
                   {$historySelect},
                   ru.username AS current_reviewer
              FROM artworks a
              INNER JOIN users u  ON u.id  = a.user_id
              LEFT  JOIN users ru ON ru.id = a.reviewed_by
              {$historyJoin}
             WHERE a.created_at >= FROM_UNIXTIME(CAST(? AS UNSIGNED))
               AND a.created_at <= FROM_UNIXTIME(CAST(? AS UNSIGNED))
             ORDER BY a.created_at DESC, a.id DESC";

    $found = $db->all($sql, [$start->getTimestamp(), $end->getTimestamp()]);
    $tz    = sr_timezone();
    $rows  = [];

    foreach ($found as $r) {
        $status = (string) $r['status'];

        if ($status !== 'approved') {
            $approver = SR_NOT_APPROVED;
            $recorded = false;
        } else {
            $name = trim((string) ($r['history_reviewer'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($r['current_reviewer'] ?? ''));
            }
            $recorded = $name !== '';
            $approver = $recorded ? $name : SR_NOT_RECORDED;
        }

        $rows[] = [
            'id'                => (int) $r['id'],
            'title'             => (string) $r['title'],
            'artist_username'   => (string) $r['artist_username'],
            'artist_email'      => (string) $r['artist_email'],
            'status'            => $status,
            'submitted_at'      => (new DateTimeImmutable('@' . (int) $r['created_epoch']))->setTimezone($tz),
            'approver'          => $approver,
            'approver_recorded' => $recorded,
        ];
    }

    return $rows;
}

/* ======================================================================
 * Small presentation helpers
 * ==================================================================== */

/** @return array{0:string,1:string} [label, css modifier] */
function sr_status_badge(string $status): array
{
    return match ($status) {
        'approved' => ['Approved', 'approved'],
        'rejected' => ['Rejected', 'rejected'],
        default    => ['Pending', 'pending'],
    };
}

/** Link to the secure admin-only preview (never to the public/owner-only artwork.php). */
function sr_preview_url(int $artworkId): string
{
    return 'artwork-preview.php?id=' . $artworkId;
}

/* ======================================================================
 * Markdown export
 * ==================================================================== */

/**
 * Makes a database value safe inside one Markdown table cell: control characters and
 * line breaks become spaces, and every character Markdown could treat as syntax
 * (| ` [ ] \ < > * _ ~ & !) is escaped, so a title or username can not break the table,
 * inject a link/image/HTML, or start emphasis.
 */
function sr_md_escape(string $value): string
{
    if (!mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');   // replaces invalid bytes
    }
    $value = preg_replace('/[\x00-\x1F\x7F\x{0085}\x{2028}\x{2029}]+/u', ' ', $value) ?? '';
    $value = trim(preg_replace('/ {2,}/', ' ', $value) ?? '');

    return preg_replace('/([\\\\`|\[\]<>*_~&!])/', '\\\\$1', $value) ?? '';
}

/** Builds the whole .md document for one period. */
function sr_markdown(string $period, DateTimeImmutable $start, DateTimeImmutable $end, array $rows, ?DateTimeImmutable $generatedAt = null): string
{
    $generatedAt = ($generatedAt ?? new DateTimeImmutable('now', sr_timezone()))->setTimezone(sr_timezone());

    $md  = "# Artwork Submission Report\n\n";
    $md .= '- **Period:** ' . sr_md_escape(SR_PERIODS[$period] ?? SR_PERIODS['today']) . "\n";
    $md .= '- **Date range:** ' . sr_md_escape(sr_range_text($start, $end)) . "\n";
    $md .= '- **Generated:** ' . $generatedAt->format('Y-m-d H:i:s') . ' (' . SR_TIMEZONE . ', UTC' . $generatedAt->format('P') . ")\n";
    $md .= '- **Total submissions:** ' . count($rows) . "\n\n";

    if (!$rows) {
        return $md . "_No artworks were submitted in this period._\n";
    }

    $md .= "| Submitted | Artwork | Artist username | Artist email | Status | Approved by |\n";
    $md .= "| --- | --- | --- | --- | --- | --- |\n";

    foreach ($rows as $r) {
        $md .= '| ' . implode(' | ', [
            $r['submitted_at']->format('Y-m-d H:i:s'),
            sr_md_escape($r['title']),
            sr_md_escape($r['artist_username']),
            sr_md_escape($r['artist_email']),
            sr_status_badge($r['status'])[0],
            sr_md_escape($r['approver']),
        ]) . " |\n";
    }

    return $md;
}
