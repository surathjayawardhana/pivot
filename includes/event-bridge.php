
<?php

/**
 * Event bridge
 *
 * Connects the Events system to the existing ArtCraft project.
 *
 * Provides:
 * - Database connection
 * - Logged-in user identification
 * - Administrator verification
 * - Login URL generation
 * - CSRF token generation and validation
 * - Output escaping
 */

/* ================================================================
 * 1. DATABASE AND SESSION
 * ================================================================ */

require_once __DIR__ . '/db.php';

// Start the session only if it has not already been started.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Returns the existing project's PDO database connection.
 */
function ev_db(): PDO
{
    $connection = db();

    if (!($connection instanceof PDO)) {
        throw new RuntimeException(
            'Event bridge: The existing db() function did not return PDO.'
        );
    }

    return $connection;
}

/* ================================================================
 * 2. AUTHENTICATION
 * ================================================================ */

/**
 * Returns the logged-in user's ID.
 *
 * Returns null when the user is not logged in.
 */
function ev_user_id(): ?int
{
    $id = $_SESSION['user_id'] ?? null;

    if (is_int($id) || (is_string($id) && ctype_digit($id))) {
        $id = (int) $id;

        return $id > 0 ? $id : null;
    }

    return null;
}

/**
 * Checks whether the currently logged-in user is an active administrator.
 *
 * The role is retrieved directly from the database.
 * No browser-submitted role is trusted.
 */
function ev_is_admin(): bool
{
    $userId = ev_user_id();

    if ($userId === null) {
        return false;
    }

    $stmt = ev_db()->prepare(
        "SELECT role, account_status, deleted_at
         FROM users
         WHERE id = :id
         LIMIT 1"
    );

    $stmt->execute([
        ':id' => $userId
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return false;
    }

    $role = strtolower(trim((string) ($user['role'] ?? '')));
    $status = strtolower(trim((string) ($user['account_status'] ?? '')));

    return $role === 'admin' && $status === 'active' && $user['deleted_at'] === null;
}

/**
 * Returns the login page URL.
 *
 * Examples:
 * ev_login_url()     -> login.php
 * ev_login_url('../') -> ../login.php
 */
function ev_login_url(string $prefix = ''): string
{
    return $prefix . 'login.php';
}

/* ================================================================
 * 3. CSRF PROTECTION
 * ================================================================ */

/**
 * Generates or retrieves the Events system CSRF token.
 */
function ev_csrf_token(): string
{
    if (
        empty($_SESSION['event_csrf_token']) ||
        !is_string($_SESSION['event_csrf_token'])
    ) {
        $_SESSION['event_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['event_csrf_token'];
}

/**
 * Validates a submitted Events system CSRF token.
 */
function ev_csrf_valid(?string $submitted): bool
{
    if (
        !is_string($submitted) ||
        $submitted === '' ||
        empty($_SESSION['event_csrf_token']) ||
        !is_string($_SESSION['event_csrf_token'])
    ) {
        return false;
    }

    return hash_equals(
        $_SESSION['event_csrf_token'],
        $submitted
    );
}

/* ================================================================
 * 4. OUTPUT ESCAPING
 * ================================================================ */

/**
 * Safely escapes values before displaying them in HTML.
 */
function ev_e(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}
