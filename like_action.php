<?php
/**
 * Like / unlike handler (POST only).
 *
 * The user is always the logged-in user from the session; the form only sends
 * the artwork id and the wanted action ("like" or "unlike"). Both actions are
 * safe to repeat, so a double click or a second tab cannot create duplicates.
 *
 * Works with a normal form post (redirects back) and, when js/likes.js sends
 * "X-Requested-With: fetch", answers with JSON so the button updates in place.
 */
declare(strict_types=1);
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/profile_helpers.php';
require_once __DIR__ . '/includes/like_helpers.php';

$wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

/** Sends the result as JSON or as flash message + redirect, then stops. */
function like_finish(bool $wantsJson, int $status, array $payload, string $redirect): never
{
    if ($wantsJson) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!empty($payload['message']) && empty($payload['ok'])) {
        art_flash_set('error', (string) $payload['message']);
    }
    header('Location: ' . $redirect);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    header('Location: index.php');
    exit;
}

$artworkId = filter_var($_POST['artwork_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$artworkId = ($artworkId === false || $artworkId === null) ? 0 : (int) $artworkId;
$back      = art_like_return_url(post_string('return'), $artworkId);

try {
    $db     = art_db();
    $viewer = profile_viewer($db);

    // 1. Must be logged in with an active account.
    if (!$viewer) {
        like_finish($wantsJson, 401, ['ok' => false, 'message' => 'Please log in to like artworks.', 'login' => 'login.php'], 'login.php');
    }

    // 2. CSRF. (The token is not rotated here: other like buttons on the same page share it.)
    if (!art_csrf_check($_POST['csrf_token'] ?? '')) {
        like_finish($wantsJson, 403, ['ok' => false, 'message' => 'Your session expired. Please refresh the page and try again.'], $back);
    }

    // 3. Validate the action and the artwork.
    $action = post_string('action');
    if (!in_array($action, ['like', 'unlike'], true)) {
        like_finish($wantsJson, 422, ['ok' => false, 'message' => 'Invalid action.'], $back);
    }

    $art = $artworkId > 0
        ? $db->one('SELECT id, status FROM artworks WHERE id = ?', [$artworkId])
        : null;

    if (!$art) {
        like_finish($wantsJson, 404, ['ok' => false, 'message' => 'That artwork could not be found.'], 'index.php');
    }

    // 4. Do it. $viewer['id'] comes from the session, never from the form.
    if ($action === 'like') {
        // New likes only on approved artwork (the only artwork shown publicly).
        if ($art['status'] !== 'approved') {
            like_finish($wantsJson, 422, ['ok' => false, 'message' => 'This artwork cannot be liked right now.'], $back);
        }
        art_like_add($db, $artworkId, $viewer['id']);
    } else {
        // Unliking is always allowed so a user can remove an old like.
        art_like_remove($db, $artworkId, $viewer['id']);
    }

    $liked = $action === 'like';
    like_finish($wantsJson, 200, [
        'ok'      => true,
        'liked'   => $liked,
        'likes'   => art_like_count($db, $artworkId),
        'message' => $liked ? 'You liked this artwork.' : 'You removed your like.',
    ], $back);

} catch (Throwable $e) {
    error_log('[like_action] ' . $e->getMessage());
    like_finish($wantsJson, 500, ['ok' => false, 'message' => 'Something went wrong. Please try again.'], $back);
}
