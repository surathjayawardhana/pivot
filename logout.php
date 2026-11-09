<?php
declare(strict_types=1);

// Load the session and security helpers.
require_once __DIR__ . '/includes/db.php';

// Only allow logout through a POST request.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// Validate the CSRF token to protect the logout request.
if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid request. Please refresh the page and try again.');
}

// Remove all session data.
$_SESSION = [];

// Remove the session cookie from the browser.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $params['path'],
        'domain'   => $params['domain'],
        'secure'   => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

// Destroy the server-side session.
session_destroy();

// Redirect the user to the login page.
header('Location: login.php');
exit;