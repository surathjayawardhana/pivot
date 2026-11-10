<?php

declare(strict_types=1);
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/artwork_helpers.php';

/* ---------- Bootstrap: connection, authenticated user ---------- */
try {
    $db = art_db();
} catch (Throwable $e) {
    error_log('[editpost] ' . $e->getMessage());
    http_response_code(500);
    exit('The service is temporarily unavailable. Please try again later.');
}
$user    = art_require_user($db);
$ownerId = $user['id'];
art_stage_cleanup();

/* ---------- Load the artwork (id AND owner must match) ---------- */
$id      = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$artwork = null;
if ($id) {
    try {
        $artwork = $db->one(
            'SELECT id, user_id, category_id, title, description, image_path, status
               FROM artworks WHERE id = ? AND user_id = ?',
            [$id, $ownerId]
        );
    } catch (Throwable $e) {
        error_log('[editpost] load: ' . $e->getMessage());
    }
}

$flash = art_flash_pull();
$ctx   = 'edit:' . (int) $id;

/* Preview of a staged replacement image after a failed save */
if (isset($_GET['stage_preview'])) {
    if (!$artwork) {
        http_response_code(404);
        exit;
    }
    art_stage_stream($ctx, $ownerId, (string) $_GET['stage_preview']);
}

$errors      = [];
$notice      = null;
$stage       = null;
$categories  = [];
$values      = ['title' => '', 'description' => '', 'category_id' => 0];
$notFound    = !$artwork;

if ($artwork) {
    $values = [
        'title'       => (string) $artwork['title'],
        'description' => (string) $artwork['description'],
        'category_id' => (int) $artwork['category_id'],
    ];
    try {
        $categories = art_categories($db);
    } catch (Throwable $e) {
        error_log('[editpost] categories: ' . $e->getMessage());
        $errors['form'] = 'Categories could not be loaded. Please refresh the page.';
    }

    /* ---------- Handle POST ---------- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $categories) {
        $action = $_POST['action'] ?? '';

        if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $errors['form'] = 'The upload was too large for the server. Please choose an image under 5 MB.';
        } elseif (!art_csrf_check($_POST['csrf_token'] ?? '')) {
            $errors['form'] = 'Your session expired or this form was already submitted. Please reload the page and try again.';
            if ($action === 'update') {
                $values = art_post_values();
                $stage  = art_stage_get($ctx, (string) ($_POST['stage_token'] ?? ''), $ownerId);
            }
        } elseif ($action === 'delete') {
            /* ----- Delete (POST + CSRF + ownership in the WHERE clause) ----- */
            try {
                $deleted = $db->run('DELETE FROM artworks WHERE id = ? AND user_id = ?', [$artwork['id'], $ownerId]);
                if ($deleted === 1) {
                    art_delete_image_file($db, $artwork['image_path']);   // only after the row is gone
                    art_stage_clear($ctx);
                    art_csrf_rotate();
                    art_flash_set('success', 'Your artwork was deleted.');
                    header('Location: editpost.php?deleted=1');
                    exit;
                }
                $errors['form'] = 'The artwork could not be deleted. It may already have been removed.';
            } catch (Throwable $e) {
                error_log('[editpost] delete failed: ' . $e->getMessage());
                $errors['form'] = 'The artwork could not be deleted. Please try again.';
            }
        } elseif ($action === 'update') {
            /* ----- Update ----- */
            $values = art_post_values();
            $up     = art_process_upload($ctx, $ownerId, (string) ($_POST['stage_token'] ?? ''));
            $stage  = $up['stage'];
            if ($up['error']) {
                $errors['image'] = $up['error'];
            }

            [$fieldErrors, $clean] = art_validate_fields($values, $categories);
            $errors += $fieldErrors;

            if (!$errors) {
                // Only fields that actually changed. status and ownership are never touched.
                $sets = [];
                if ($clean['title'] !== (string) $artwork['title']) {
                    $sets['title'] = $clean['title'];
                }
                if ($clean['category_id'] !== (int) $artwork['category_id']) {
                    $sets['category_id'] = $clean['category_id'];
                }
                if ($clean['description'] !== (string) $artwork['description']) {
                    $sets['description'] = $clean['description'];
                }

                if (!$sets && !$stage) {
                    $notice = ['type' => 'info', 'message' => 'There are no changes to save.'];
                } else {
                    $runUpdate = function (array $changes) use ($db, $artwork, $ownerId): void {
                        $allowed = ['title', 'category_id', 'description', 'image_path'];
                        $cols = [];
                        $args = [];
                        foreach ($changes as $col => $val) {
                            if (in_array($col, $allowed, true)) {
                                $cols[] = "`$col` = ?";
                                $args[] = $val;
                            }
                        }
                        if (!$cols) {
                            return;
                        }
                        $args[] = $artwork['id'];
                        $args[] = $ownerId;
                        $n = $db->run('UPDATE artworks SET ' . implode(', ', $cols) . ' WHERE id = ? AND user_id = ?', $args);
                        if ($n < 1 && !$db->one('SELECT id FROM artworks WHERE id = ? AND user_id = ?', [$artwork['id'], $ownerId])) {
                            throw new RuntimeException('Artwork no longer exists.');
                        }
                    };

                    try {
                        if ($stage) {
                            art_store_with_db($db, $user['username'], $stage['path'], $stage['ext'],
                                function (string $rel) use ($sets, $runUpdate) {
                                    $runUpdate($sets + ['image_path' => $rel]);
                                });
                            art_delete_image_file($db, $artwork['image_path']);   // old file, only after the update succeeded
                        } else {
                            $runUpdate($sets);
                        }

                        art_stage_clear($ctx);
                        art_csrf_rotate();
                        art_flash_set('success', 'Changes saved successfully.');
                        header('Location: editpost.php?id=' . (int) $artwork['id']);
                        exit;
                    } catch (Throwable $e) {
                        error_log('[editpost] update failed: ' . $e->getMessage());
                        $errors['form'] = 'We could not save your changes. Your entries have been kept, so please try again.';
                    }
                }
            }
        }
    }
}

/* ---------- View state ---------- */
$deletedState = $notFound && $flash && ($flash['type'] ?? '') === 'success' && isset($_GET['deleted']);
if ($notFound && !$deletedState) {
    http_response_code(404);
}

$originalUrl = $artwork ? art_asset_url($artwork['image_path']) : '';
$hasStage    = (bool) $stage;
$previewUrl  = $stage
    ? 'editpost.php?id=' . (int) $id . '&stage_preview=' . rawurlencode($stage['token'])
    : $originalUrl;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/post.css">
    <title>Edit Artwork | NSBM ArtCraft Platform</title>
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
</head>
<body>

    <?php include 'partials/header.php'; ?>

    <main class="post-page">
        <?php if ($deletedState): ?>
            <section class="post-card post-card--narrow" aria-labelledby="post-heading">
                <header class="post-card__head">
                    <h1 id="post-heading">Artwork deleted</h1>
                    <p><?= art_e($flash['message']) ?></p>
                </header>
                <div class="post-actions">
                    <a class="post-btn post-btn--secondary" href="index.php">Back to home</a>
                    <a class="post-btn" href="add_post.php">Post new artwork</a>
                </div>
            </section>

        <?php elseif ($notFound): ?>
            <section class="post-card post-card--narrow" aria-labelledby="post-heading">
                <header class="post-card__head">
                    <h1 id="post-heading">Artwork not found</h1>
                    <p>This artwork doesn't exist, or you don't have permission to edit it.</p>
                </header>
                <div class="post-actions">
                    <a class="post-btn post-btn--secondary" href="index.php">Back to home</a>
                    <a class="post-btn" href="add_post.php">Post new artwork</a>
                </div>
            </section>

        <?php else: ?>
            <section class="post-card" aria-labelledby="post-heading">
                <header class="post-card__head">
                    <h1 id="post-heading">Edit Your Artwork</h1>
                    <p>Update the details below. Editing doesn't change your artwork's review status.</p>
                </header>

                <?php if ($flash): ?>
                    <div class="post-notice post-notice--<?= art_e($flash['type']) ?>" role="status" tabindex="-1" data-notice>
                        <span><?= art_e($flash['message']) ?></span>
                        <button type="button" class="post-notice__close" data-dismiss aria-label="Dismiss message">&times;</button>
                    </div>
                <?php endif; ?>

                <?php if ($notice): ?>
                    <div class="post-notice post-notice--<?= art_e($notice['type']) ?>" role="status" tabindex="-1" data-notice>
                        <span><?= art_e($notice['message']) ?></span>
                        <button type="button" class="post-notice__close" data-dismiss aria-label="Dismiss message">&times;</button>
                    </div>
                <?php endif; ?>

                <?php if (isset($errors['form'])): ?>
                    <div class="post-notice post-notice--error" role="alert" tabindex="-1" data-notice>
                        <span><?= art_e($errors['form']) ?></span>
                        <button type="button" class="post-notice__close" data-dismiss aria-label="Dismiss message">&times;</button>
                    </div>
                <?php endif; ?>

                <form method="post" action="editpost.php?id=<?= (int) $artwork['id'] ?>" enctype="multipart/form-data"
                      novalidate class="post-form" data-post-form>
                    <input type="hidden" name="csrf_token" value="<?= art_e(art_csrf_token()) ?>">
                    <input type="hidden" name="action" value="update">

                    <?php include __DIR__ . '/partials/artwork_form_fields.php'; ?>

                    <div class="post-actions post-actions--split">
                        <button type="button" class="post-btn post-btn--danger" data-delete-open>Delete Artwork</button>
                        <button type="submit" class="post-btn" data-submit>
                            <span class="post-btn__label">Save Changes</span>
                        </button>
                    </div>
                </form>

                <form id="delete-form" method="post" action="editpost.php?id=<?= (int) $artwork['id'] ?>" hidden>
                    <input type="hidden" name="csrf_token" value="<?= art_e(art_csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                </form>
            </section>

            <dialog class="post-dialog" id="delete-dialog" aria-labelledby="delete-title">
                <h2 id="delete-title">Delete this artwork?</h2>
                <p>&ldquo;<?= art_e($artwork['title']) ?>&rdquo; and its image will be permanently removed. This can't be undone.</p>
                <div class="post-dialog__actions">
                    <button type="button" class="post-btn post-btn--secondary" data-dialog-cancel>Keep Artwork</button>
                    <button type="submit" form="delete-form" class="post-btn post-btn--danger" data-delete-confirm>Delete Artwork</button>
                </div>
            </dialog>
        <?php endif; ?>
    </main>

    <?php include 'partials/footer.php'; ?>

    <script src="js/post.js" defer></script>
</body>
</html>
