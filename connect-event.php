<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
/**
 * connect-event.php
 * POST-only JSON endpoint. Lets a logged-in user join or leave an event.
 *
 * Expects POST fields: event_id, action ("join" or "leave"), csrf_token
 * (the token may also be sent in an X-CSRF-Token header).
 * The user id ALWAYS comes from the server session, never from the browser.
 */

require_once __DIR__ . '/includes/event-bridge.php';

/** Send a JSON response and stop. */
function ev_respond(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Only POST may change data.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    ev_respond(405, ['success' => false, 'message' => 'Method not allowed.']);
}

// 2. Must be logged in.
$userId = ev_user_id();
if ($userId === null) {
    ev_respond(401, [
        'success'        => false,
        'login_required' => true,
        'message'        => 'Please log in to join events.',
    ]);
}

// 3. CSRF token.
$token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!ev_csrf_valid(is_string($token) ? $token : null)) {
    ev_respond(403, ['success' => false, 'message' => 'Your session has expired. Please refresh the page and try again.']);
}

// 4. Validate input.
$eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($eventId === false || $eventId === null) {
    ev_respond(422, ['success' => false, 'message' => 'Invalid event.']);
}

$action = $_POST['action'] ?? '';
if ($action !== 'join' && $action !== 'leave') {
    ev_respond(422, ['success' => false, 'message' => 'Invalid action.']);
}

try {
    $pdo = ev_db();

    // 5. The event must exist.
    $stmt = $pdo->prepare('SELECT id FROM events WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $eventId]);
    if ($stmt->fetchColumn() === false) {
        ev_respond(404, ['success' => false, 'message' => 'This event no longer exists.']);
    }

    // 6. Join or leave. Both are safe to repeat.
    if ($action === 'join') {
        try {
            $stmt = $pdo->prepare('INSERT INTO event_participants (event_id, user_id) VALUES (:event_id, :user_id)');
            $stmt->execute([':event_id' => $eventId, ':user_id' => $userId]);
        } catch (PDOException $e) {
            // 1062 = duplicate entry: the user had already joined (for example a
            // double click). That is fine, so we treat it as success.
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
        }
        $joined = true;
    } else {
        $stmt = $pdo->prepare('DELETE FROM event_participants WHERE event_id = :event_id AND user_id = :user_id');
        $stmt->execute([':event_id' => $eventId, ':user_id' => $userId]);
        $joined = false;
    }

    // 7. Fresh participant count.
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM event_participants WHERE event_id = :event_id');
    $stmt->execute([':event_id' => $eventId]);
    $count = (int) $stmt->fetchColumn();

    ev_respond(200, [
        'success'           => true,
        'joined'            => $joined,
        'participant_count' => $count,
        'message'           => $joined ? 'You joined this event.' : 'You left this event.',
    ]);
} catch (Throwable $e) {
    // Log the real error for developers, show a safe message to users.
    error_log('connect-event.php: ' . $e->getMessage());
    ev_respond(500, ['success' => false, 'message' => 'Something went wrong. Please try again.']);
}
