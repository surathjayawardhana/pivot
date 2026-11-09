<?php

declare(strict_types=1);
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/comment_helpers.php';

/** Redirects (303, so the browser follows with GET) and stops. */
function comment_finish(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

/** Stores a message (and the typed text, so it is not lost) and returns to the discussion. */
function comment_fail(int $artworkId, string $message, array $urlOptions, ?array $draft = null): never
{
    comment_flash_set($artworkId, 'error', $message, $draft);
    comment_finish(comment_url($artworkId, $urlOptions));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    comment_finish('index.php');
}

// ---- Read and sanity-check the identifiers (they are only ever used as integers) ----
$artworkId = comment_request_int($_POST, 'artwork_id') ?? 0;
$page      = min(comment_request_int($_POST, 'comments_page') ?? 1, 100000);
$commentId = comment_request_int($_POST, 'comment_id');          // target of reply / edit / delete
$action    = post_string('action');
$rawBody   = post_string('body');

if ($artworkId < 1) {
    comment_finish('index.php');
}

// Where to send the person when something goes wrong, and what to keep in the form.
$draftMode = ['create' => 'create', 'reply' => 'reply', 'edit' => 'edit'][$action] ?? null;
$draft     = $draftMode ? ['mode' => $draftMode, 'comment_id' => $commentId, 'body' => $rawBody] : null;
$errorUrl  = match ($action) {
    'create' => ['page' => $page, 'anchor' => 'comment-form-main'],
    'reply'  => ['page' => $page, 'panel' => 'reply', 'panel_id' => (int) $commentId, 'anchor' => 'reply-form-' . (int) $commentId],
    'edit'   => ['page' => $page, 'panel' => 'edit',  'panel_id' => (int) $commentId, 'anchor' => 'edit-form-' . (int) $commentId],
    default  => ['page' => $page, 'anchor' => 'comments'],
};

try {
    $db = art_db();

    // 1. Logged in with an active, not-deleted account (database-backed, not the session alone).
    $viewer = comment_viewer($db);
    if (!$viewer) {
        comment_finish('login.php');
    }

    // 2. CSRF. (Not rotated: other forms on the same page share the token.)
    if (!art_csrf_check($_POST['csrf_token'] ?? '')) {
        comment_fail($artworkId, 'Your session expired. Please try again.', $errorUrl, $draft);
    }

    // 3. Known action.
    if (!in_array($action, ['create', 'reply', 'edit', 'delete'], true)) {
        comment_fail($artworkId, 'That action is not available.', ['page' => $page]);
    }

    // 4. The artwork must exist and be approved (pending / rejected artwork takes no comments).
    if (!comment_open_artwork($db, $artworkId)) {
        comment_finish('index.php');
    }

    $viewerId = (int) $viewer['id'];

    switch ($action) {

        /* ------------------------------------------------------------ create */
        case 'create':
            [$body, $error] = comment_validate_body($rawBody);
            if ($error !== null) {
                comment_fail($artworkId, $error, $errorUrl, $draft);
            }
            $existing = comment_find_recent_duplicate($db, $artworkId, $viewerId, null, $body);
            if ($existing === null) {
                if (comment_rate_limited($db, $viewerId)) {
                    comment_fail($artworkId, 'You are posting too quickly. Please wait a moment and try again.', $errorUrl, $draft);
                }
                $existing = comment_create($db, $artworkId, $viewerId, $body);
                comment_flash_set($artworkId, 'success', 'Your comment was posted.');
            }
            comment_finish(comment_url($artworkId, ['anchor' => 'comment-' . $existing]));

        /* ------------------------------------------------------------- reply */
        case 'reply':
            $parent = $commentId ? comment_find($db, $commentId) : null;
            if (!$parent || !comment_belongs_to_artwork($parent, $artworkId)) {
                comment_fail($artworkId, 'The comment you replied to could not be found.', ['page' => $page]);
            }
            if (!comment_is_top_level($parent)) {
                comment_fail($artworkId, 'You can only reply to a main comment, not to another reply.', ['page' => $page]);
            }
            if (comment_is_deleted($parent)) {
                // The text is not lost: it comes back in the main comment box.
                comment_fail(
                    $artworkId,
                    'That comment was deleted, so it cannot take replies. You can post your text as a new comment instead.',
                    ['page' => $page, 'anchor' => 'comment-form-main'],
                    ['mode' => 'create', 'comment_id' => null, 'body' => $rawBody]
                );
            }
            [$body, $error] = comment_validate_body($rawBody);
            if ($error !== null) {
                comment_fail($artworkId, $error, $errorUrl, $draft);
            }
            $replyId = comment_find_recent_duplicate($db, $artworkId, $viewerId, (int) $parent['id'], $body);
            if ($replyId === null) {
                if (comment_rate_limited($db, $viewerId)) {
                    comment_fail($artworkId, 'You are posting too quickly. Please wait a moment and try again.', $errorUrl, $draft);
                }
                $replyId = comment_create_reply($db, $artworkId, $viewerId, (int) $parent['id'], $body);
                if ($replyId === null) {   // the parent was deleted while the reply was being written
                    comment_fail($artworkId, 'That comment is no longer available for replies.', ['page' => $page]);
                }
                comment_flash_set($artworkId, 'success', 'Your reply was posted.');
            }
            // thread = open the whole conversation so the new reply is on screen.
            comment_finish(comment_url($artworkId, [
                'page' => $page, 'thread' => (int) $parent['id'], 'anchor' => 'comment-' . $replyId,
            ]));

        /* -------------------------------------------------------------- edit */
        case 'edit':
            $target = $commentId ? comment_find($db, $commentId) : null;
            if (!$target || !comment_belongs_to_artwork($target, $artworkId)) {
                comment_fail($artworkId, 'That comment could not be found.', ['page' => $page]);
            }
            if (comment_is_deleted($target)) {
                comment_fail($artworkId, 'This comment was deleted and can no longer be edited.', ['page' => $page]);
            }
            // Own comments only. Administrators can delete, but never edit, someone else's words.
            if (!comment_can_edit($target, $viewer)) {
                comment_fail($artworkId, 'You can only edit your own comments.', ['page' => $page]);
            }
            [$body, $error] = comment_validate_body($rawBody);
            if ($error !== null) {
                comment_fail($artworkId, $error, $errorUrl, $draft);
            }
            $thread = $target['parent_comment_id'];     // null for a top-level comment
            $after  = ['page' => $page, 'thread' => $thread, 'anchor' => 'comment-' . $target['id']];

            if ($body === (string) $target['body']) {
                comment_flash_set($artworkId, 'info', 'No changes to save.');
                comment_finish(comment_url($artworkId, $after));
            }
            if (!comment_update_body($db, $target['id'], $viewerId, $body)) {
                comment_fail($artworkId, 'This comment can no longer be edited.', ['page' => $page]);
            }
            comment_flash_set($artworkId, 'success', 'Your changes were saved.');
            comment_finish(comment_url($artworkId, $after));

        /* ------------------------------------------------------------ delete */
        case 'delete':
            $target = $commentId ? comment_find($db, $commentId) : null;
            if (!$target || !comment_belongs_to_artwork($target, $artworkId)) {
                comment_fail($artworkId, 'That comment could not be found.', ['page' => $page]);
            }
            $thread = $target['parent_comment_id'];
            // Where to land afterwards: a reply's thread, or the section top for a main comment.
            $after = $thread === null
                ? ['page' => $page, 'anchor' => 'comments']
                : ['page' => $page, 'thread' => $thread, 'anchor' => 'comment-' . $thread];

            if (comment_is_deleted($target)) {            // already gone (double click, second tab)
                comment_flash_set($artworkId, 'info', 'That comment was already deleted.');
                comment_finish(comment_url($artworkId, $after));
            }
            // Author, or an administrator (role read from the database above).
            if (!comment_can_delete($target, $viewer)) {
                comment_fail($artworkId, 'You do not have permission to delete this comment.', ['page' => $page]);
            }
            comment_soft_delete($db, $target['id'], $viewerId);
            comment_flash_set($artworkId, 'success', $thread === null ? 'The comment was deleted.' : 'The reply was deleted.');
            comment_finish(comment_url($artworkId, $after));
    }

} catch (Throwable $e) {
    // Details go to the server log only - never to the browser.
    error_log('[comment_action] ' . $e->getMessage());
    comment_fail($artworkId, 'Something went wrong. Please try again.', $errorUrl, $draft);
}

comment_finish(comment_url($artworkId, ['page' => $page]));
