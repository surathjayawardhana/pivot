<?php
/**
 * NSBM ArtCraft - shared setup.
 *
 * Loaded by login.php and register.php. It provides:
 *   - database settings and one PDO connection (db())
 *   - a secure session
 *   - CSRF token helpers
 *   - small helpers used by both pages (escaping, name/email cleanup)
 *
 * If your project already has a database connection file, keep it and
 * replace the db() function below with a call into that file instead.
 */
declare(strict_types=1);

// ---- Settings: change these to match your MySQL setup -----------------
const DB_HOST = 'localhost';
const DB_NAME = 'artcraft';
const DB_USER = 'root';
const DB_PASS = '';

// Where to send people after a successful login.
const AFTER_LOGIN_URL = 'index.php';

// Registration only accepts emails on this domain suffix.
const NSBM_EMAIL_SUFFIX = '.nsbm.ac.lk';

const NAME_MAX_LENGTH = 50;
const EMAIL_MAX_LENGTH = 254;
const PASSWORD_MIN_LENGTH = 8;
const PASSWORD_MAX_LENGTH = 72; // bcrypt ignores everything after 72 bytes

// ---- Session ----------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---- Database ---------------------------------------------------------
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements
        ]);
    }

    return $pdo;
}

// ---- Output escaping --------------------------------------------------
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---- CSRF protection --------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_valid(mixed $token): bool
{
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

// ---- Input helpers ----------------------------------------------------

/** Read a POST field as a string (arrays and missing fields become ''). */
function post_string(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? $value : '';
}

/** Trim (including Unicode spaces) and collapse inner runs of whitespace. */
function clean_text(string $value): string
{
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

/** Emails are compared in lowercase everywhere (form, check, database). */
function normalize_email(string $email): string
{
    // strtolower is enough: valid email addresses here are plain ASCII.
    return strtolower(trim($email));
}

/** Number of characters (not bytes), so names in any script are counted fairly. */
function text_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    return (int) preg_match_all('/./su', $value);
}

/**
 * Returns an error message, or null when the email is a valid NSBM address.
 * Expects an already-normalized (trimmed, lowercase) email.
 */
function nsbm_email_error(string $email): ?string
{
    if ($email === '') {
        return 'Enter your NSBM email address.';
    }

    if (strlen($email) > EMAIL_MAX_LENGTH || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return 'Enter a valid email address.';
    }

    $domain = substr($email, strrpos($email, '@') + 1);
    $suffixLength = strlen(NSBM_EMAIL_SUFFIX);

    // Must end with ".nsbm.ac.lk" and have something in front of it,
    // e.g. student.nsbm.ac.lk. Gmail, Outlook, etc. are rejected.
    if (strlen($domain) <= $suffixLength || substr($domain, -$suffixLength) !== NSBM_EMAIL_SUFFIX) {
        return 'Use your NSBM email address (for example, name@student.nsbm.ac.lk).';
    }

    return null;
}
