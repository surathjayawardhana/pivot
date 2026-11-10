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
    error_log('[add_post] ' . $e->getMessage());
    http_response_code(500);
    exit('The service is temporarily unavailable. Please try again later.');
}
$user = art_require_user($db);
$ctx  = 'add';
art_stage_cleanup();

/* Preview of a staged upload after a failed submit */
if (isset($_GET['stage_preview'])) {
    art_stage_stream($ctx, $user['id'], (string) $_GET['stage_preview']);
}

$errors     = [];
$stage      = null;
$values     = ['title' => '', 'description' => '', 'category_id' => 0];
$flash      = art_flash_pull();
$categories = [];

try {
    $categories = art_categories($db);
} catch (Throwable $e) {
    error_log('[add_post] categories: ' . $e->getMessage());
    $errors['form'] = 'Categories could not be loaded. Please refresh the page.';
}

/* ---------- Handle submission ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $categories) {
    $values = art_post_values();

    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors['form'] = 'The upload was too large for the server. Please choose an image under 5 MB.';
    } elseif (!art_csrf_check($_POST['csrf_token'] ?? '')) {
        $errors['form'] = 'Your session expired or this form was already submitted. Please check your entries and try again.';
        $stage = art_stage_get($ctx, (string) ($_POST['stage_token'] ?? ''), $user['id']);
    } else {
        $up    = art_process_upload($ctx, $user['id'], (string) ($_POST['stage_token'] ?? ''));
        $stage = $up['stage'];
        if ($up['error']) {
            $errors['image'] = $up['error'];
        }

        [$fieldErrors, $clean] = art_validate_fields($values, $categories);
        $errors += $fieldErrors;

        if (!$stage && !isset($errors['image'])) {
            $errors['image'] = 'Please upload an image of your artwork.';
        }

        if (!$errors) {
            try {
                art_store_with_db($db, $user['username'], $stage['path'], $stage['ext'], function (string $rel) use ($db, $user, $clean) {
                    // status is always set here; it is never read from the form.
                    $db->run(
                        "INSERT INTO artworks (user_id, category_id, title, description, image_path, status)
                         VALUES (?, ?, ?, ?, ?, 'pending')",
                        [$user['id'], $clean['category_id'], $clean['title'], $clean['description'], $rel]
                    );
                });

                art_stage_clear($ctx);
                art_csrf_rotate();
                art_flash_set('success', 'Post successfully submitted');
                header('Location: add_post.php');   // Post/Redirect/Get
                exit;
            } catch (Throwable $e) {
                error_log('[add_post] insert failed: ' . $e->getMessage());
                $errors['form'] = 'We could not save your artwork. Your entries have been kept, so please try again.';
            }
        }
    }
}

$previewUrl = $stage ? 'add_post.php?stage_preview=' . rawurlencode($stage['token']) : '';
$hasStage   = (bool) $stage;
$originalUrl = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/post.css">
    <title>Post Your Artwork | NSBM ArtCraft Platform</title>
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
</head>
<body>

    <?php include 'partials/header.php'; ?>

    <main class="post-page">
        <section class="post-card" aria-labelledby="post-heading">
            <header class="post-card__head">
                <h1 id="post-heading">Post Your Artwork</h1>
                <p>Share your creativity with the ArtCraft community.</p>
            </header>

            <?php if ($flash): ?>
                <div class="post-notice post-notice--<?= art_e($flash['type']) ?>" role="status" tabindex="-1" data-notice>
                    <span><?= art_e($flash['message']) ?></span>
                    <button type="button" class="post-notice__close" data-dismiss aria-label="Dismiss message">&times;</button>
                </div>
            <?php endif; ?>

            <?php if (isset($errors['form'])): ?>
                <div class="post-notice post-notice--error" role="alert" tabindex="-1" data-notice>
                    <span><?= art_e($errors['form']) ?></span>
                    <button type="button" class="post-notice__close" data-dismiss aria-label="Dismiss message">&times;</button>
                </div>
            <?php endif; ?>

            <form method="post" action="add_post.php" enctype="multipart/form-data" novalidate
                  class="post-form" data-post-form data-require-image="1">
                <input type="hidden" name="csrf_token" value="<?= art_e(art_csrf_token()) ?>">

                <?php include __DIR__ . '/partials/artwork_form_fields.php'; ?>

                <div class="post-actions">
                    <a class="post-btn post-btn--secondary" href="index.php">Cancel</a>
                    <button type="submit" class="post-btn" data-submit>
                        <span class="post-btn__label">Post Artwork</span>
                    </button>
                </div>
            </form>
        </section>
    </main>

    <?php include 'partials/footer.php'; ?>

    <script src="js/post.js" defer></script>
</body>
</html>
