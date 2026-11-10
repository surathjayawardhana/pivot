<?php
/**
 * Artwork review action (POST only), used by admin/partials/artwork-management.php.
 *
 *   action=approve|reject   artwork_id=N   expected_status=pending|approved|rejected   feedback=optional note
 *
 * Same pattern as showcase_action.php: check who is asking (from the database), check the
 * CSRF token, validate the input, change the database inside a transaction, store a flash
 * message and redirect back to the section on admin/manage.php.
 *
 * The new status is derived here from the action - it is never read from the request.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/artwork_review_helpers.php';

/** Stores a message and goes back to the artwork-management section of the page the admin was on. */
function am_action_finish(string $type, string $message, array $view = []): never
{
    am_flash_set($type, $message);
    $query = am_view_query($view + ['status' => 'all', 'q' => '', 'page' => 1]);
    header('Location: manage.php' . ($query !== '' ? '?' . $query : '') . '#' . AM_SECTION_ID);
    exit;
}

// ---- 1. POST only ---------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    header('Location: manage.php#' . AM_SECTION_ID);
    exit;
}

// ---- 2. Administrators only (checked against the database on every request) ----
try {
    $pdo = db();

    if (art_session_user_id() === null) {
        header('Location: ../login.php');
        exit;
    }

    $admin = am_current_admin($pdo);
} catch (Throwable $e) {
    error_log('[artwork-review-action] ' . $e->getMessage());
    http_response_code(500);
    exit('The service is temporarily unavailable.');
}

if ($admin === null) {
    http_response_code(403);
    exit('You do not have permission to perform this action.');
}

// The list view (filter / search / page) the admin was on, so they land back on it.
$view = am_view_params($_POST);

// ---- 3. CSRF --------------------------------------------------------------
if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    am_action_finish('error', 'Your session expired. Please refresh the page and try again.', $view);
}

// ---- 4. Validate input ----------------------------------------------------
$action = post_string('action');
if (!isset(AM_ACTIONS[$action])) {
    am_action_finish('error', 'Invalid action.', $view);
}

$artworkId = filter_var($_POST['artwork_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($artworkId === false || $artworkId === null) {
    am_action_finish('error', 'Invalid artwork.', $view);
}

$expected = post_string('expected_status');
if (!in_array($expected, AM_STATUSES, true)) {
    am_action_finish('error', 'Invalid request. Please refresh the page and try again.', $view);
}

[$feedback, $feedbackError] = am_clean_feedback($_POST['feedback'] ?? '');
if ($feedbackError !== null) {
    am_action_finish('error', $feedbackError, $view);
}

// ---- 5. Review (one transaction: artwork + review history + activity log) ----
$result = am_review_artwork($pdo, $admin['id'], (int) $artworkId, $action, $expected, $feedback);

am_action_finish($result['type'], $result['message'], $view);
