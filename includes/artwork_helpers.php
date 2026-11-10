<?php
/**
 * Shared helpers for add_post.php and editpost.php (NSBM ArtCraft Platform).
 *
 * Everything that has to be adapted to the project's existing includes/db.php
 * and includes/auth.php lives in the "ADAPTER" section at the top:
 *   - art_db()               finds the connection created by includes/db.php
 *   - art_session_user_id()  reads the logged-in user's id from the session
 */

const ART_MAX_UPLOAD_BYTES = 5 * 1024 * 1024;   // 5 MB
const ART_TITLE_MAX        = 150;               // artworks.title is varchar(150)
const ART_DESCRIPTION_MAX  = 5000; // UI and server-side limit
const ART_ALLOWED_ROLES    = ['student', 'admin'];
const ART_ALLOWED_TYPES    = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const ART_UPLOAD_REL       = 'uploads/artworks';
const ART_STAGE_REL        = 'uploads/tmp';
const ART_SESSION_USER_KEYS = ['user_id', 'id', 'uid'];

/* ======================================================================
 * ADAPTER
 * ==================================================================== */

/**
 * Thin wrapper so the pages work whether includes/db.php exposes PDO or mysqli.
 */
final class ArtDb
{
    public function __construct(private PDO|mysqli $h)
    {
        if ($h instanceof PDO) {
            $h->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $h->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } else {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $h->set_charset('utf8mb4');
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function all(string $sql, array $params = []): array
    {
        $params = array_values($params);
        if ($this->h instanceof PDO) {
            $st = $this->h->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        }
        $st = $this->h->prepare($sql);
        if ($params) {
            $st->bind_param(str_repeat('s', count($params)), ...$params);
        }
        $st->execute();
        $res  = $st->get_result();
        $rows = $res instanceof mysqli_result ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $st->close();
        return $rows;
    }

    public function one(string $sql, array $params = []): ?array
    {
        $rows = $this->all($sql, $params);
        return $rows[0] ?? null;
    }

    /** Runs INSERT/UPDATE/DELETE and returns the affected row count. */
    public function run(string $sql, array $params = []): int
    {
        $params = array_values($params);
        if ($this->h instanceof PDO) {
            $st = $this->h->prepare($sql);
            $st->execute($params);
            return $st->rowCount();
        }
        $st = $this->h->prepare($sql);
        if ($params) {
            $st->bind_param(str_repeat('s', count($params)), ...$params);
        }
        $st->execute();
        $n = $st->affected_rows;
        $st->close();
        return (int) $n;
    }
}

/** Locates the connection that includes/db.php created. */
function art_db(): ArtDb
{
    static $instance = null;

    if ($instance instanceof ArtDb) {
        return $instance;
    }

    if (!function_exists('db')) {
        throw new RuntimeException(
            'Database connection function db() is unavailable. Check includes/db.php.'
        );
    }

    // Get the existing PDO connection.
    $handle = db();

    if (!($handle instanceof PDO) && !($handle instanceof mysqli)) {
        throw new RuntimeException(
            'The db() function must return a PDO or mysqli connection.'
        );
    }

    return $instance = new ArtDb($handle);
}

/** Reads the authenticated user's id from the session (set by the existing login). */
function art_session_user_id(): ?int
{
    foreach (ART_SESSION_USER_KEYS as $k) {
        if (isset($_SESSION[$k]) && is_scalar($_SESSION[$k]) && ctype_digit((string) $_SESSION[$k]) && (int) $_SESSION[$k] > 0) {
            return (int) $_SESSION[$k];
        }
    }
    foreach (['user', 'auth', 'current_user'] as $k) {
        if (isset($_SESSION[$k]) && is_array($_SESSION[$k])) {
            foreach (['id', 'user_id'] as $sub) {
                $v = $_SESSION[$k][$sub] ?? null;
                if (is_scalar($v) && ctype_digit((string) $v) && (int) $v > 0) {
                    return (int) $v;
                }
            }
        }
    }
    return null;
}

/**
 * Forgets the login data in the PHP session. Used when the account behind a session is
 * no longer allowed to sign in (suspended, removed), so login.php shows the form
 * instead of bouncing the visitor back to a page that rejects them again.
 */
function art_forget_session_user(): void
{
    unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['first_name']);
}

/**
 * Returns the authenticated user (id, names, email, username, role) loaded from
 * the database, or redirects to the login page.
 *
 * Only an active account that has not been removed (users.deleted_at IS NULL) is accepted.
 * $loginUrl is relative to the calling page (admin pages pass '../login.php').
 */
function art_require_user(ArtDb $db, string $loginUrl = 'login.php'): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $uid  = art_session_user_id();
    $user = $uid
        ? $db->one('SELECT id, first_name, surname, email, username, role, account_status, deleted_at FROM users WHERE id = ?', [$uid])
        : null;

    if (
        !$user
        || $user['deleted_at'] !== null
        || $user['account_status'] !== 'active'
        || !in_array($user['role'], ART_ALLOWED_ROLES, true)
    ) {
        art_forget_session_user();
        header('Location: ' . $loginUrl);
        exit;
    }
    $user['id'] = (int) $user['id'];
    return $user;
}

/**
 * Like art_require_user() but the account must currently be an administrator IN THE
 * DATABASE. The role kept in $_SESSION is never trusted; it is refreshed here so a
 * demoted administrator's stale session role stops granting access.
 */
function art_require_admin(ArtDb $db, string $loginUrl = 'login.php'): array
{
    $user = art_require_user($db, $loginUrl);
    $_SESSION['role'] = (string) $user['role'];
    if ($user['role'] !== 'admin') {
        header('Location: ' . $loginUrl);
        exit;
    }
    return $user;
}

/* ======================================================================
 * Small utilities
 * ==================================================================== */

function art_e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function art_root(): string
{
    return dirname(__DIR__);
}

function art_upload_dir(): string
{
    return art_root() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'artworks';
}

function art_stage_dir(): string
{
    return art_root() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'tmp';
}

/** URL-safe relative path (spaces etc. encoded per segment). */
function art_asset_url(?string $rel): string
{
    if (!$rel) {
        return '';
    }
    return implode('/', array_map('rawurlencode', explode('/', $rel)));
}

function art_is_duplicate(Throwable $e): bool
{
    if ($e instanceof PDOException) {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
    return (int) $e->getCode() === 1062;
}

function art_ensure_dir(string $dir, bool $deny_web = false): bool
{
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    if ($deny_web) {
        $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\n");
        }
    }
    return true;
}

function art_flash_set(string $type, string $message): void
{
    $_SESSION['art_flash'] = ['type' => $type, 'message' => $message];
}

function art_flash_pull(): ?array
{
    $f = $_SESSION['art_flash'] ?? null;
    unset($_SESSION['art_flash']);
    return is_array($f) ? $f : null;
}

/* ======================================================================
 * CSRF
 * ==================================================================== */

function art_csrf_token(): string
{
    if (empty($_SESSION['art_csrf']) || !is_string($_SESSION['art_csrf'])) {
        $_SESSION['art_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['art_csrf'];
}

function art_csrf_check($token): bool
{
    return is_string($token) && $token !== ''
        && isset($_SESSION['art_csrf']) && is_string($_SESSION['art_csrf'])
        && hash_equals($_SESSION['art_csrf'], $token);
}

/** Called after a successful state change so the same form can't be replayed. */
function art_csrf_rotate(): void
{
    $_SESSION['art_csrf'] = bin2hex(random_bytes(32));
}

/* ======================================================================
 * Categories and field validation
 * ==================================================================== */

function art_categories(ArtDb $db): array
{
    return $db->all('SELECT id, name, description, image_path FROM categories ORDER BY id');
}

/** Raw (trimmed) values from POST, used both for validation and for re-filling the form. */
function art_post_values(): array
{
    $title = isset($_POST['title']) && is_string($_POST['title']) ? trim($_POST['title']) : '';
    $desc  = isset($_POST['description']) && is_string($_POST['description']) ? trim($_POST['description']) : '';
    $cat   = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
    return ['title' => $title, 'description' => $desc, 'category_id' => $cat === false ? 0 : (int) $cat];
}

/** @return array{0: array<string,string>, 1: array<string,mixed>} [errors, clean values] */
function art_validate_fields(array $v, array $categories): array
{
    $errors = [];

    if ($v['title'] === '') {
        $errors['title'] = 'Please enter a title for your artwork.';
    } elseif (!mb_check_encoding($v['title'], 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $v['title'])) {
        $errors['title'] = 'The title contains characters that are not allowed.';
    } elseif (mb_strlen($v['title'], 'UTF-8') > ART_TITLE_MAX) {
        $errors['title'] = 'The title can be at most ' . ART_TITLE_MAX . ' characters.';
    }

    $validIds = array_map('intval', array_column($categories, 'id'));
    if ($v['category_id'] <= 0) {
        $errors['category'] = 'Please select a category.';
    } elseif (!in_array($v['category_id'], $validIds, true)) {
        $errors['category'] = 'That category does not exist. Please choose another.';
    }

    if (!mb_check_encoding($v['description'], 'UTF-8')) {
        $errors['description'] = 'The description contains characters that are not allowed.';
    } elseif (mb_strlen($v['description'], 'UTF-8') > ART_DESCRIPTION_MAX) {
        $errors['description'] = 'The description can be at most ' . ART_DESCRIPTION_MAX . ' characters.';
    }

    return [$errors, $v];
}

/* ======================================================================
 * Image validation
 * ==================================================================== */

/** Inspects a file on disk; returns ['mime','ext'] when it is a genuine JPEG/PNG/WEBP, else null. */
function art_inspect_image(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($path);
    if (!is_string($mime) || !isset(ART_ALLOWED_TYPES[$mime])) {
        return null;
    }
    $info = @getimagesize($path);
    if ($info === false || ($info['mime'] ?? '') !== $mime || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
        return null;
    }
    return ['mime' => $mime, 'ext' => ART_ALLOWED_TYPES[$mime]];
}

/** @return array{error: ?string, mime?: string, ext?: string} */
function art_validate_upload(array $file): array
{
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if (!is_int($err) || !is_string($file['tmp_name'] ?? null)) {
        return ['error' => 'The uploaded file is not valid.'];
    }
    switch ($err) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['error' => 'The image is too large. The maximum size is 5 MB.'];
        case UPLOAD_ERR_PARTIAL:
            return ['error' => 'The image was only partially uploaded. Please try again.'];
        default:
            return ['error' => 'The image could not be uploaded. Please try again.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['error' => 'The uploaded file is not valid.'];
    }
    if (($file['size'] ?? 0) > ART_MAX_UPLOAD_BYTES || filesize($file['tmp_name']) > ART_MAX_UPLOAD_BYTES) {
        return ['error' => 'The image is too large. The maximum size is 5 MB.'];
    }
    $info = art_inspect_image($file['tmp_name']);
    if (!$info) {
        return ['error' => 'Only JPG, PNG or WEBP images are allowed.'];
    }
    return ['error' => null] + $info;
}

/* ======================================================================
 * Staging: keeps a validated upload between a failed submit and the retry
 * ==================================================================== */

function art_stage_valid_name(string $name): bool
{
    return (bool) preg_match('/^stage_\d+_[a-f0-9]{16}\.(jpg|png|webp)$/', $name);
}

function art_stage_clear(string $ctx): void
{
    $s = $_SESSION['art_stage'][$ctx] ?? null;
    if (is_array($s) && isset($s['file']) && art_stage_valid_name($s['file'])) {
        @unlink(art_stage_dir() . DIRECTORY_SEPARATOR . $s['file']);
    }
    unset($_SESSION['art_stage'][$ctx]);
}

/** @return ?array{token:string,path:string,mime:string,ext:string} */
function art_stage_get(string $ctx, ?string $token, int $uid): ?array
{
    $s = $_SESSION['art_stage'][$ctx] ?? null;
    if (!is_array($s) || !is_string($token) || $token === '' || !isset($s['token'], $s['file'], $s['uid'])) {
        return null;
    }
    if (!hash_equals((string) $s['token'], $token) || (int) $s['uid'] !== $uid || !art_stage_valid_name((string) $s['file'])) {
        return null;
    }
    $path = art_stage_dir() . DIRECTORY_SEPARATOR . $s['file'];
    $info = art_inspect_image($path);
    if (!$info) {
        return null;
    }
    return ['token' => $s['token'], 'path' => $path, 'mime' => $info['mime'], 'ext' => $info['ext']];
}

function art_stage_save(string $ctx, int $uid, string $tmp, string $ext): ?array
{
    if (!art_ensure_dir(art_stage_dir(), true)) {
        return null;
    }
    art_stage_clear($ctx);
    $name = sprintf('stage_%d_%s.%s', $uid, bin2hex(random_bytes(8)), $ext);
    if (!move_uploaded_file($tmp, art_stage_dir() . DIRECTORY_SEPARATOR . $name)) {
        return null;
    }
    $token = bin2hex(random_bytes(16));
    if (!isset($_SESSION['art_stage']) || !is_array($_SESSION['art_stage'])) {
        $_SESSION['art_stage'] = [];
    }
    $_SESSION['art_stage'][$ctx] = ['token' => $token, 'file' => $name, 'uid' => $uid];
    return art_stage_get($ctx, $token, $uid);
}

/** Removes abandoned staged files older than two hours. */
function art_stage_cleanup(): void
{
    $dir = art_stage_dir();
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $f) {
        if (art_stage_valid_name($f)) {
            $p = $dir . DIRECTORY_SEPARATOR . $f;
            if (is_file($p) && filemtime($p) < time() - 7200) {
                @unlink($p);
            }
        }
    }
}

/** Streams the staged image back for the preview after a failed submit. */
function art_stage_stream(string $ctx, int $uid, string $token): void
{
    $s = art_stage_get($ctx, $token, $uid);
    if (!$s) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $s['mime']);
    header('Content-Length: ' . filesize($s['path']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($s['path']);
    exit;
}

/**
 * Handles the file input for a POST: validates a fresh upload and stages it, or
 * falls back to the previously staged file.
 *
 * @return array{stage: ?array, error: ?string}
 */
function art_process_upload(string $ctx, int $uid, ?string $postedToken): array
{
    $file = $_FILES['image'] ?? null;
    if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $v = art_validate_upload($file);
        if ($v['error']) {
            return ['stage' => art_stage_get($ctx, $postedToken, $uid), 'error' => $v['error']];
        }
        $stage = art_stage_save($ctx, $uid, $file['tmp_name'], $v['ext']);
        return $stage
            ? ['stage' => $stage, 'error' => null]
            : ['stage' => null, 'error' => 'We could not process the uploaded image. Please try again.'];
    }
    return ['stage' => art_stage_get($ctx, $postedToken, $uid), 'error' => null];
}

/* ======================================================================
 * Final image storage: the_uploaded_USERNAME_artwork_N.ext
 * ==================================================================== */

function art_safe_username(string $username): string
{
    $safe = trim((string) preg_replace('/[^A-Za-z0-9_-]/', '_', $username), '_');
    $safe = substr($safe, 0, 64);
    return $safe === '' ? 'user' : $safe;
}

/**
 * Picks the next free number for this username (checking BOTH the database and the
 * upload folder) and atomically reserves the file name by creating it with mode "x".
 *
 * @return array{rel:string, abs:string}
 */
function art_reserve_target(ArtDb $db, string $username, string $ext): array
{
    $dir = art_upload_dir();
    if (!art_ensure_dir($dir)) {
        throw new RuntimeException('Upload directory is not available.');
    }
    $prefix = 'the_uploaded_' . art_safe_username($username) . '_artwork_';
    $regex  = '/^' . preg_quote($prefix, '/') . '(\d+)\.[a-z0-9]+$/i';
    $max    = 0;

    $like = ART_UPLOAD_REL . '/' . addcslashes($prefix, '\\%_') . '%';
    foreach ($db->all('SELECT image_path FROM artworks WHERE image_path LIKE ?', [$like]) as $row) {
        if (preg_match($regex, basename((string) $row['image_path']), $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    foreach (scandir($dir) ?: [] as $f) {
        if (preg_match($regex, $f, $m)) {
            $max = max($max, (int) $m[1]);
        }
    }

    for ($n = $max + 1, $tries = 0; $tries < 100; $n++, $tries++) {
        $name = $prefix . $n . '.' . $ext;
        $abs  = $dir . DIRECTORY_SEPARATOR . $name;
        $fh   = @fopen($abs, 'x');           // fails if the file already exists
        if ($fh !== false) {
            fclose($fh);
            return ['rel' => ART_UPLOAD_REL . '/' . $name, 'abs' => $abs];
        }
    }
    throw new RuntimeException('Could not allocate an image file name.');
}

/**
 * Copies the (already validated) image into uploads/artworks under a fresh name and
 * calls $write($relPath) to persist it. If $write fails the new file is removed.
 * A duplicate-key collision on image_path is retried with the next number.
 */
function art_store_with_db(ArtDb $db, string $username, string $srcPath, string $ext, callable $write): string
{
    $last = null;
    for ($i = 0; $i < 3; $i++) {
        $t = art_reserve_target($db, $username, $ext);
        if (!@copy($srcPath, $t['abs']) || filesize($t['abs']) !== filesize($srcPath)) {
            @unlink($t['abs']);
            throw new RuntimeException('Could not save the image file.');
        }
        try {
            $write($t['rel']);
            return $t['rel'];
        } catch (Throwable $e) {
            @unlink($t['abs']);
            if (!art_is_duplicate($e)) {
                throw $e;
            }
            $last = $e;
        }
    }
    throw $last ?? new RuntimeException('Could not store the image.');
}

/**
 * Deletes an artwork image only if the stored path is a plain file name inside
 * uploads/artworks and no other artwork row still references it.
 */
function art_delete_image_file(ArtDb $db, ?string $rel): bool
{
    if (!$rel || !preg_match('#^' . preg_quote(ART_UPLOAD_REL, '#') . '/[A-Za-z0-9._-]+$#', $rel) || str_contains($rel, '..')) {
        return false;
    }
    if ($db->one('SELECT id FROM artworks WHERE image_path = ?', [$rel])) {
        return false;                         // still in use by another record
    }
    $dir  = realpath(art_upload_dir());
    $file = realpath(art_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
    if (!$dir || !$file || !is_file($file) || strpos($file, $dir . DIRECTORY_SEPARATOR) !== 0) {
        return false;
    }
    return @unlink($file);
}
