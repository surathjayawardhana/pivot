<?php
/**
 * Homepage showcase actions (POST only), used by admin/partials/edit-showcase.php.
 *
 *   action=feature  artwork_id=N   make artwork N the homepage showcase (replaces the old one)
 *   action=remove   artwork_id=N   take artwork N out of the showcase (artwork is kept)
 *
 * Same pattern as like_action.php / follow_action.php: check who is asking, check the
 * CSRF token, validate the input, change the database, store a flash message and
 * redirect back to admin/manage.php.
 *
 * featured_slot is ENUM('1','2','3','4') with a UNIQUE key. This file only ever touches
 * slot '1' and never moves an artwork out of slots '2'-'4'.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/profile_helpers.php';   // also loads artwork_helpers.php

if (!defined('SHOWCASE_SLOT')) {
    define('SHOWCASE_SLOT', '1');
}

/** Stores a message and goes back to the management page. */
function showcase_finish(string $type, string $message, int $page = 1): never
{
    art_flash_set($type, $message);
    header('Location: manage.php' . ($page > 1 ? '?showcase_page=' . $page : '') . '#homepage-showcase');
    exit;
}

// ---- 1. POST only ---------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    header('Location: manage.php');
    exit;
}

// ---- 2. Administrators only (role is read from the database, not the session) ----
try {
    $db     = art_db();
    $viewer = profile_viewer($db);
} catch (Throwable $e) {
    error_log('[showcase_action] ' . $e->getMessage());
    http_response_code(500);
    exit('The service is temporarily unavailable.');
}

if (!$viewer) {
    header('Location: ../login.php');
    exit;
}
if ($viewer['role'] !== 'admin') {
    http_response_code(403);
    exit('You do not have permission to perform this action.');
}

// Page of the artwork list the admin was on, so they land back on it.
$page = filter_var($_POST['showcase_page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 100000, 'default' => 1],
]);
$page = (int) $page;

// ---- 3. CSRF --------------------------------------------------------------
if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    showcase_finish('error', 'Your session expired. Please refresh the page and try again.', $page);
}

// ---- 4. Validate input ----------------------------------------------------
$action = post_string('action');
if (!in_array($action, ['feature', 'remove'], true)) {
    showcase_finish('error', 'Invalid action.', $page);
}

$artworkId = filter_var($_POST['artwork_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($artworkId === false || $artworkId === null) {
    showcase_finish('error', 'Invalid artwork.', $page);
}
$artworkId = (int) $artworkId;

$pdo = db();   // the same PDO connection that art_db() wraps

// ---- 5a. Remove from showcase ---------------------------------------------
if ($action === 'remove') {
    try {
        // One atomic statement. The artwork row and its status are not otherwise changed
        // ("updated_at = updated_at" stops MySQL from refreshing that timestamp).
        // Matching on featured_slot as well means a stale page can never remove a
        // different artwork that another admin has featured in the meantime.
        $st = $pdo->prepare(
            'UPDATE artworks SET featured_slot = NULL, updated_at = updated_at
              WHERE id = ? AND featured_slot = ?'
        );
        $st->execute([$artworkId, SHOWCASE_SLOT]);
        $removed = $st->rowCount() === 1;
    } catch (Throwable $e) {
        error_log('[showcase_action] remove: ' . $e->getMessage());
        showcase_finish('error', 'The artwork could not be removed. Please try again.', $page);
    }

    if (!$removed) {
        showcase_finish('error', 'That artwork is no longer the featured artwork.', $page);
    }
    showcase_finish('success', 'Removed from the showcase. The homepage showcase is hidden until you feature another artwork.', $page);
}

// ---- 5b. Feature (replaces the current showcase artwork) -------------------
try {
    $pdo->beginTransaction();

    // Lock the chosen artwork row so its status cannot change while we work.
    $st = $pdo->prepare('SELECT user_id, title, status, featured_slot FROM artworks WHERE id = ? FOR UPDATE');
    $st->execute([$artworkId]);
    $art = $st->fetch();

    $problem = null;
    if (!$art) {
        $problem = 'That artwork could not be found.';
    } elseif ($art['status'] !== 'approved') {
        $problem = 'Only approved artworks can be featured.';
    } else {
        $st = $pdo->prepare('SELECT account_status FROM users WHERE id = ?');
        $st->execute([(int) $art['user_id']]);
        $owner = $st->fetch();

        if (!$owner || $owner['account_status'] !== 'active') {
            $problem = 'This artist’s account is not active, so the artwork cannot be featured.';
        } elseif ($art['featured_slot'] !== null && (string) $art['featured_slot'] !== SHOWCASE_SLOT) {
            $problem = 'This artwork already uses featured slot ' . (string) $art['featured_slot']
                     . ', so it cannot be moved to the homepage showcase.';
        } elseif ((string) $art['featured_slot'] === SHOWCASE_SLOT) {
            $problem = 'That artwork is already the featured artwork.';
        }
    }

    if ($problem !== null) {
        $pdo->rollBack();
        showcase_finish('error', $problem, $page);
    }

    // Free slot '1' (the unique key allows only one artwork per slot) ...
    $st = $pdo->prepare('UPDATE artworks SET featured_slot = NULL, updated_at = updated_at WHERE featured_slot = ?');
    $st->execute([SHOWCASE_SLOT]);

    // ... then give it to the chosen artwork. Exactly one row must change.
    $st = $pdo->prepare(
        "UPDATE artworks SET featured_slot = ?, updated_at = updated_at
          WHERE id = ? AND status = 'approved' AND featured_slot IS NULL"
    );
    $st->execute([SHOWCASE_SLOT, $artworkId]);
    if ($st->rowCount() !== 1) {
        throw new RuntimeException('Featuring artwork #' . $artworkId . ' changed ' . $st->rowCount() . ' rows.');
    }

    $pdo->commit();
} catch (Throwable $e) {
    // Rolling back also restores the previous featured artwork.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[showcase_action] feature: ' . $e->getMessage());
    showcase_finish(
        'error',
        art_is_duplicate($e)
            ? 'Another administrator changed the showcase at the same moment. Nothing was changed, please try again.'
            : 'The showcase could not be updated. The previous featured artwork was kept.',
        $page
    );
}

showcase_finish('success', '“' . (string) $art['title'] . '” is now featured on the homepage.', $page);
