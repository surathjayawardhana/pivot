<?php
/**
 * Follow / unfollow handler (POST only).
 *
 * The follower is always the logged-in user from the session. The form only
 * sends the target user's id and the wanted action; both are validated here.
 * Works with a normal form post (redirects back) and, when profile.js sends
 * "X-Requested-With: fetch", answers with JSON so the page updates in place.
 */
declare(strict_types=1);
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/profile_helpers.php';

$wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

/** Sends the result as JSON or as flash message + redirect, then stops. */
function follow_finish(bool $wantsJson, int $status, array $payload, string $redirect): never
{
    if ($wantsJson) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
    art_flash_set(!empty($payload['ok']) ? 'success' : 'error', (string) ($payload['message'] ?? ''));
    header('Location: ' . $redirect);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    header('Location: index.php');
    exit;
}

try {
    $db     = art_db();
    $viewer = profile_viewer($db);

    // 1. Must be logged in with an active account.
    if (!$viewer) {
        follow_finish($wantsJson, 401, ['ok' => false, 'message' => 'Please log in to follow artists.', 'login' => 'login.php'], 'login.php');
    }

    // 2. CSRF.
    if (!art_csrf_check($_POST['csrf_token'] ?? '')) {
        follow_finish($wantsJson, 403, ['ok' => false, 'message' => 'Your session expired. Please refresh the page and try again.'], 'index.php');
    }

    // 3. Validate the submitted action and target.
    $action   = post_string('action');
    $targetId = filter_var($_POST['target_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $target   = ($targetId !== false && $targetId !== null) ? profile_find_by_id($db, (int) $targetId) : null;

    if (!in_array($action, ['follow', 'unfollow'], true) || !$target) {
        follow_finish($wantsJson, 404, ['ok' => false, 'message' => 'That artist could not be found.'], 'index.php');
    }

    $back = profile_url($target['username']);

    // 4. Do it. $viewer['id'] comes from the session, never from the form.
    if ($action === 'follow') {
        $result = profile_follow($db, $viewer['id'], $target['id']);
        if ($result === 'self') {
            follow_finish($wantsJson, 422, ['ok' => false, 'message' => 'You cannot follow yourself.'], $back);
        }
        if ($result === 'missing') {
            follow_finish($wantsJson, 404, ['ok' => false, 'message' => 'That artist could not be found.'], 'index.php');
        }
        $message = $result === 'followed'
            ? 'You are now following @' . $target['username'] . '.'
            : 'You already follow @' . $target['username'] . '.';
    } else {
        $removed = profile_unfollow($db, $viewer['id'], $target['id']);
        $message = $removed
            ? 'You unfollowed @' . $target['username'] . '.'
            : 'You were not following @' . $target['username'] . '.';
    }

    $counts = profile_follow_counts($db, $target['id']);
    follow_finish($wantsJson, 200, [
        'ok'        => true,
        'following' => $action === 'follow',
        'followers' => $counts['followers'],
        'message'   => $message,
    ], $back);

} catch (Throwable $e) {
    error_log('[follow_action] ' . $e->getMessage());
    follow_finish($wantsJson, 500, ['ok' => false, 'message' => 'Something went wrong. Please try again.'], 'index.php');
}
