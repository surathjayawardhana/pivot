<?php
/**
 * Shared helpers for the artist profile and follow system (NSBM ArtCraft).
 *
 * Used by: profile.php, edit_profile.php, followers.php, follow_action.php,
 *          artwork.php and partials/header.php.
 *
 * It builds on the existing project code instead of replacing it:
 *   - includes/db.php              db(), clean_text(), text_length(), post_string(), NAME_MAX_LENGTH
 *   - includes/artwork_helpers.php ArtDb, art_require_user(), art_csrf_*(), art_validate_upload(), ...
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/artwork_helpers.php';

const PROFILE_BIO_MAX        = 250;
const PROFILE_PAGE_SIZE      = 24;                                   // rows per page on follower lists
const PROFILE_UPLOAD_REL     = 'uploads/profiles';
const PROFILE_DEFAULT_AVATAR = 'uploads/profiles/default-avatar.png'; // shared file: never deleted

/** Only files this feature created are ever served as an avatar or deleted. profile_<userId>_<16 hex>.<ext> */
const PROFILE_IMAGE_REGEX    = '#^uploads/profiles/profile_(\d+)_[a-f0-9]{16}\.(?:jpg|png|webp)$#';

/* ======================================================================
 * Who is looking at the page?
 * ==================================================================== */

/**
 * The logged-in, active user - or null for a visitor. Unlike art_require_user()
 * this never redirects, so public pages can still be shown to guests.
 *
 * @return ?array{id:int, first_name:string, surname:string, username:string, role:string}
 */
function profile_viewer(ArtDb $db): ?array
{
    $uid = art_session_user_id();
    if (!$uid) {
        return null;
    }
    $user = $db->one(
        'SELECT id, first_name, surname, username, role, account_status, deleted_at FROM users WHERE id = ?',
        [$uid]
    );
    if (
        !$user
        || $user['deleted_at'] !== null
        || $user['account_status'] !== 'active'
        || !in_array($user['role'], ART_ALLOWED_ROLES, true)
    ) {
        return null;
    }
    $user['id'] = (int) $user['id'];
    return $user;
}

/* ======================================================================
 * Loading profiles (public fields only - never email or password hash)
 * ==================================================================== */

const PROFILE_FIELDS = 'id, first_name, surname, username, role, profile_image_path, bio, created_at';

/** Active accounts only: suspended users have no public profile. */
function profile_find_by_username(ArtDb $db, string $username): ?array
{
    $username = trim($username);
    if ($username === '' || strlen($username) > 254 || !mb_check_encoding($username, 'UTF-8')) {
        return null;
    }
    $row = $db->one(
        'SELECT ' . PROFILE_FIELDS . " FROM users WHERE username = ? AND account_status = 'active'",
        [$username]
    );
    return $row ? profile_normalize($row) : null;
}

function profile_find_by_id(ArtDb $db, int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    $row = $db->one(
        'SELECT ' . PROFILE_FIELDS . " FROM users WHERE id = ? AND account_status = 'active'",
        [$id]
    );
    return $row ? profile_normalize($row) : null;
}

function profile_normalize(array $row): array
{
    $row['id'] = (int) $row['id'];
    return $row;
}

function profile_full_name(array $row): string
{
    return trim((string) $row['first_name'] . ' ' . (string) $row['surname']);
}

/** profile.php / followers.php link for a username. */
function profile_url(string $username): string
{
    return 'profile.php?u=' . rawurlencode($username);
}

/* ======================================================================
 * Avatars
 * ==================================================================== */

function profile_upload_dir(): string
{
    return art_root() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profiles';
}

/** URL of the user's own picture, or null if they have none (or the file is gone). */
function profile_custom_avatar_url(?string $rel): ?string
{
    if ($rel === null || !preg_match(PROFILE_IMAGE_REGEX, $rel)) {
        return null;
    }
    if (!is_file(art_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel))) {
        return null;
    }
    return art_asset_url($rel);
}

/** The user's picture, or the shared default avatar. */
function profile_avatar_url(?string $rel): string
{
    return profile_custom_avatar_url($rel) ?? art_asset_url(PROFILE_DEFAULT_AVATAR);
}

/**
 * Moves a validated upload into uploads/profiles under a server-generated name.
 * The original filename and client MIME type are never used.
 *
 * @return ?string relative path to store in the database, or null on failure
 */
function profile_move_upload(int $userId, string $tmpPath, string $ext): ?string
{
    $dir = profile_upload_dir();
    if (!art_ensure_dir($dir)) {
        return null;
    }
    for ($i = 0; $i < 5; $i++) {
        $name = sprintf('profile_%d_%s.%s', $userId, bin2hex(random_bytes(8)), $ext);
        $abs  = $dir . DIRECTORY_SEPARATOR . $name;
        if (is_file($abs)) {
            continue;
        }
        if (!move_uploaded_file($tmpPath, $abs)) {
            return null;
        }
        @chmod($abs, 0644);
        return PROFILE_UPLOAD_REL . '/' . $name;
    }
    return null;
}

/**
 * Deletes a profile picture file. Refuses anything that is not one of this
 * feature's own files belonging to $ownerId, so the default avatar, artwork
 * images and other users' pictures can never be removed through here.
 */
function profile_delete_image(?string $rel, int $ownerId): bool
{
    if ($rel === null || $rel === PROFILE_DEFAULT_AVATAR || !preg_match(PROFILE_IMAGE_REGEX, $rel, $m)) {
        return false;
    }
    if ((int) $m[1] !== $ownerId) {
        return false;
    }
    $dir  = realpath(profile_upload_dir());
    $file = realpath(art_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
    if (!$dir || !$file || !is_file($file) || strpos($file, $dir . DIRECTORY_SEPARATOR) !== 0) {
        return false;
    }
    return @unlink($file);
}

/* ======================================================================
 * Profile form validation
 * ==================================================================== */

/** Cleaned values from POST (also used to re-fill the form after an error). */
function profile_post_values(): array
{
    $bio = clean_text(post_string('bio'));
    $bio = preg_replace('/[\x00-\x1F\x7F]/', '', $bio) ?? $bio;
    return [
        'first_name' => clean_text(post_string('first_name')),
        'surname'    => clean_text(post_string('surname')),
        'bio'        => $bio,
    ];
}

/** @return array<string,string> field => message */
function profile_validate(array $v): array
{
    $errors = [];

    foreach (['first_name' => 'First name', 'surname' => 'Surname'] as $key => $label) {
        $value = (string) $v[$key];
        if ($value === '') {
            $errors[$key] = 'Enter your ' . strtolower($label) . '.';
        } elseif (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            $errors[$key] = $label . ' contains characters that are not allowed.';
        } elseif (text_length($value) > NAME_MAX_LENGTH) {
            $errors[$key] = $label . ' must be ' . NAME_MAX_LENGTH . ' characters or fewer.';
        }
    }

    $bio = (string) $v['bio'];
    if (!mb_check_encoding($bio, 'UTF-8')) {
        $errors['bio'] = 'Your biography contains characters that are not allowed.';
    } elseif (text_length($bio) > PROFILE_BIO_MAX) {
        $errors['bio'] = 'Your biography can be at most ' . PROFILE_BIO_MAX . ' characters.';
    }

    return $errors;
}

/* ======================================================================
 * Counts (always calculated from the tables - nothing is stored twice)
 * Only active accounts are counted, so numbers match the lists.
 * ==================================================================== */

function profile_artwork_count(ArtDb $db, int $userId, bool $includeUnapproved): int
{
    $sql = 'SELECT COUNT(*) AS c FROM artworks WHERE user_id = ?'
         . ($includeUnapproved ? '' : " AND status = 'approved'");
    return (int) ($db->one($sql, [$userId])['c'] ?? 0);
}

/** @return array{followers:int, following:int} */
function profile_follow_counts(ArtDb $db, int $userId): array
{
    $followers = $db->one(
        "SELECT COUNT(*) AS c
           FROM user_follows f
           JOIN users u ON u.id = f.follower_id AND u.account_status = 'active'
          WHERE f.followed_id = ?",
        [$userId]
    );
    $following = $db->one(
        "SELECT COUNT(*) AS c
           FROM user_follows f
           JOIN users u ON u.id = f.followed_id AND u.account_status = 'active'
          WHERE f.follower_id = ?",
        [$userId]
    );
    return ['followers' => (int) ($followers['c'] ?? 0), 'following' => (int) ($following['c'] ?? 0)];
}

/* ======================================================================
 * Follow / unfollow
 * The follower is ALWAYS the logged-in user, passed in from the session.
 * ==================================================================== */

function profile_is_following(ArtDb $db, int $followerId, int $followedId): bool
{
    return (bool) $db->one(
        'SELECT 1 AS yes FROM user_follows WHERE follower_id = ? AND followed_id = ?',
        [$followerId, $followedId]
    );
}

/** @return string 'followed' | 'already' | 'self' | 'missing' */
function profile_follow(ArtDb $db, int $followerId, int $targetId): string
{
    if ($followerId === $targetId) {
        return 'self';
    }
    if (!profile_find_by_id($db, $targetId)) {
        return 'missing';
    }
    try {
        $db->run('INSERT INTO user_follows (follower_id, followed_id) VALUES (?, ?)', [$followerId, $targetId]);
        return 'followed';
    } catch (Throwable $e) {
        if (art_is_duplicate($e)) {          // primary key stops duplicates, even on a double click
            return 'already';
        }
        throw $e;
    }
}

/** Deletes only the row (this user -> that user). Returns true if a row was removed. */
function profile_unfollow(ArtDb $db, int $followerId, int $targetId): bool
{
    return $db->run(
        'DELETE FROM user_follows WHERE follower_id = ? AND followed_id = ?',
        [$followerId, $targetId]
    ) > 0;
}

/**
 * One page of followers ('followers') or followed users ('following') of $userId.
 * Returns public fields only.
 *
 * @return array<int, array<string,mixed>>
 */
function profile_follow_list(ArtDb $db, int $userId, string $type, int $page, int $perPage = PROFILE_PAGE_SIZE): array
{
    // Both column names come from this fixed choice, never from user input.
    [$otherCol, $ownCol] = $type === 'following'
        ? ['f.followed_id', 'f.follower_id']
        : ['f.follower_id', 'f.followed_id'];

    $limit  = max(1, $perPage);
    $offset = max(0, $page - 1) * $limit;

    return $db->all(
        "SELECT u.id, u.first_name, u.surname, u.username, u.profile_image_path
           FROM user_follows f
           JOIN users u ON u.id = $otherCol AND u.account_status = 'active'
          WHERE $ownCol = ?
          ORDER BY f.created_at DESC, u.id DESC
          LIMIT $limit OFFSET $offset",
        [$userId]
    );
}

/* ======================================================================
 * Artwork gallery
 * ==================================================================== */

/**
 * The artist's artwork. Visitors get approved work only; the owner also sees
 * pending and rejected work (each with a status label).
 *
 * @return array<int, array<string,mixed>>
 */
function profile_artworks(ArtDb $db, int $userId, bool $isOwner): array
{
    return $db->all(
        'SELECT a.id, a.title, a.image_path, a.status, c.name AS category
           FROM artworks a
           LEFT JOIN categories c ON c.id = a.category_id
          WHERE a.user_id = ?' . ($isOwner ? '' : " AND a.status = 'approved'") . '
          ORDER BY a.id DESC',
        [$userId]
    );
}

/** Image URL for an artwork row, or '' when the stored path looks unsafe. */
function profile_artwork_image_url(?string $rel): string
{
    if ($rel === null || !preg_match('#^uploads/artworks/[A-Za-z0-9._-]+$#', $rel) || str_contains($rel, '..')) {
        return '';
    }
    // A missing file gives '' so the card shows its grey placeholder instead of a broken-image icon.
    if (!is_file(art_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel))) {
        return '';
    }
    return art_asset_url($rel);
}

/** @return array{0:string,1:string} [label, css modifier] */
function profile_status_badge(string $status): array
{
    return match ($status) {
        'approved' => ['Approved', 'approved'],
        'pending'  => ['Pending review', 'pending'],
        'rejected' => ['Rejected', 'rejected'],
        default    => [ucfirst($status), 'pending'],
    };
}
