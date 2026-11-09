<?php
/**
 * Edit the logged-in user's own profile: picture, first name, surname, biography.
 * The username is generated from the email address by the database, so it is shown but not editable.
 */
declare(strict_types=1);
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/profile_helpers.php';

/* ---------- Bootstrap: only a logged-in, active user can edit - and only themselves ---------- */
try {
    $db = art_db();
} catch (Throwable $e) {
    error_log('[edit_profile] ' . $e->getMessage());
    http_response_code(500);
    exit('The service is temporarily unavailable. Please try again later.');
}
$user = art_require_user($db);            // redirects to login.php otherwise

try {
    $profile = profile_find_by_id($db, $user['id']);
} catch (Throwable $e) {
    error_log('[edit_profile] load: ' . $e->getMessage());
    $profile = null;
}
if (!$profile) {
    http_response_code(500);
    exit('Your profile could not be loaded. Please try again later.');
}

$errors = [];
$values = [
    'first_name' => (string) $profile['first_name'],
    'surname'    => (string) $profile['surname'],
    'bio'        => (string) ($profile['bio'] ?? ''),
];
$oldImage = $profile['profile_image_path'] ?? null;

/* ---------- Handle submissions (the target is always the session user) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors['form'] = 'The upload was too large for the server. Please choose an image under 5 MB.';
    } elseif (!art_csrf_check($_POST['csrf_token'] ?? '')) {
        $errors['form'] = 'Your session expired or this form was already submitted. Please try again.';
    } else {
        $action = post_string('action');

        if ($action === 'remove_image') {
            /* ----- Remove picture: back to the shared default avatar ----- */
            try {
                if ($oldImage !== null) {
                    $db->run('UPDATE users SET profile_image_path = NULL WHERE id = ?', [$user['id']]);
                    profile_delete_image($oldImage, $user['id']);     // file removed only after the DB change
                }
                art_csrf_rotate();
                art_flash_set('success', 'Profile picture removed.');
                header('Location: edit_profile.php');
                exit;
            } catch (Throwable $e) {
                error_log('[edit_profile] remove: ' . $e->getMessage());
                $errors['form'] = 'We could not remove your picture. Please try again.';
            }

        } elseif ($action === 'save') {
            /* ----- Save names, biography and (optionally) a new picture ----- */
            $values = profile_post_values();
            $errors += profile_validate($values);

            $file    = $_FILES['profile_image'] ?? null;
            $hasFile = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            $check   = null;
            if ($hasFile) {
                $check = art_validate_upload($file);     // size, real MIME via finfo, getimagesize
                if ($check['error']) {
                    $errors['image'] = $check['error'];
                }
            }

            if (!$errors) {
                $newImage = null;
                try {
                    $bio = $values['bio'] === '' ? null : $values['bio'];

                    if ($hasFile) {
                        $newImage = profile_move_upload($user['id'], $file['tmp_name'], $check['ext']);
                        if ($newImage === null) {
                            throw new RuntimeException('Could not store the uploaded image.');
                        }
                        $db->run(
                            'UPDATE users SET first_name = ?, surname = ?, bio = ?, profile_image_path = ? WHERE id = ?',
                            [$values['first_name'], $values['surname'], $bio, $newImage, $user['id']]
                        );
                        profile_delete_image($oldImage, $user['id']);   // old file only after the new one is saved
                    } else {
                        $db->run(
                            'UPDATE users SET first_name = ?, surname = ?, bio = ? WHERE id = ?',
                            [$values['first_name'], $values['surname'], $bio, $user['id']]
                        );
                    }

                    $_SESSION['first_name'] = $values['first_name'];     // login.php keeps this in the session
                    art_csrf_rotate();
                    art_flash_set('success', 'Your profile has been updated.');
                    header('Location: profile.php');
                    exit;
                } catch (Throwable $e) {
                    if ($newImage !== null) {
                        profile_delete_image($newImage, $user['id']);    // don't leave an orphan file behind
                    }
                    error_log('[edit_profile] save: ' . $e->getMessage());
                    $errors['form'] = 'We could not save your profile. Please try again.';
                }
            }
        } else {
            $errors['form'] = 'That request was not recognised.';
        }
    }
}

$flash      = art_flash_pull();
$avatarUrl  = profile_avatar_url($oldImage);
$hasCustom  = profile_custom_avatar_url($oldImage) !== null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/profile.css">
    <title>Edit Profile | NSBM ArtCraft Platform</title>
</head>
<body>
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="profile-page">
        <div class="profile-container profile-container--narrow">
            <section class="profile-card profile-edit" aria-labelledby="edit-title">
                <header class="profile-edit__head">
                    <h1 id="edit-title">Edit Profile</h1>
                    <p>Update how you appear to other artists on ArtCraft.</p>
                </header>

                <?php if ($flash): ?>
                    <div class="profile-notice profile-notice--<?= art_e($flash['type']) ?>" role="status">
                        <span><?= art_e($flash['message']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if (isset($errors['form'])): ?>
                    <div class="profile-notice profile-notice--error" role="alert">
                        <span><?= art_e($errors['form']) ?></span>
                    </div>
                <?php endif; ?>

                <form method="post" action="edit_profile.php" enctype="multipart/form-data" novalidate class="profile-form" data-profile-form>
                    <input type="hidden" name="csrf_token" value="<?= art_e(art_csrf_token()) ?>">
                    <input type="hidden" name="action" value="save">

                    <div class="profile-field<?= isset($errors['image']) ? ' has-error' : '' ?>">
                        <span class="profile-label" id="avatar-label">Profile picture</span>
                        <div class="avatar-picker">
                            <img class="avatar-picker__preview" src="<?= art_e($avatarUrl) ?>" alt="Profile picture preview"
                                 width="120" height="120" data-avatar-preview>
                            <div class="avatar-picker__controls">
                                <input type="file" id="profile-image" name="profile_image" class="profile-file"
                                       accept="image/jpeg,image/png,image/webp"
                                       aria-labelledby="avatar-label" aria-describedby="avatar-help avatar-error" data-avatar-input>
                                <label class="profile-btn profile-btn--secondary profile-btn--small" for="profile-image">Choose photo</label>
                                <?php if ($hasCustom): ?>
                                    <button type="submit" form="remove-avatar-form" class="profile-btn profile-btn--danger profile-btn--small">Remove photo</button>
                                <?php endif; ?>
                                <p class="profile-help" id="avatar-help">JPG, PNG or WEBP, up to 5 MB. The picture is shown as a circle.</p>
                            </div>
                        </div>
                        <p class="profile-error" id="avatar-error" data-avatar-error><?= art_e($errors['image'] ?? '') ?></p>
                    </div>

                    <div class="profile-row">
                        <div class="profile-field<?= isset($errors['first_name']) ? ' has-error' : '' ?>">
                            <label class="profile-label" for="first-name">First name</label>
                            <input type="text" id="first-name" name="first_name" class="profile-input" required
                                   maxlength="<?= NAME_MAX_LENGTH ?>" autocomplete="given-name"
                                   value="<?= art_e($values['first_name']) ?>">
                            <p class="profile-error"><?= art_e($errors['first_name'] ?? '') ?></p>
                        </div>
                        <div class="profile-field<?= isset($errors['surname']) ? ' has-error' : '' ?>">
                            <label class="profile-label" for="surname">Surname</label>
                            <input type="text" id="surname" name="surname" class="profile-input" required
                                   maxlength="<?= NAME_MAX_LENGTH ?>" autocomplete="family-name"
                                   value="<?= art_e($values['surname']) ?>">
                            <p class="profile-error"><?= art_e($errors['surname'] ?? '') ?></p>
                        </div>
                    </div>

                    <div class="profile-field">
                        <label class="profile-label" for="username">Username</label>
                        <input type="text" id="username" class="profile-input profile-input--readonly" readonly
                               value="@<?= art_e($profile['username']) ?>" aria-describedby="username-help">
                        <p class="profile-help" id="username-help">Your username comes from your NSBM email address and can't be changed.</p>
                    </div>

                    <div class="profile-field<?= isset($errors['bio']) ? ' has-error' : '' ?>">
                        <label class="profile-label" for="bio">Biography</label>
                        <textarea id="bio" name="bio" class="profile-input profile-textarea" rows="4"
                                  maxlength="<?= PROFILE_BIO_MAX ?>" data-bio data-max="<?= PROFILE_BIO_MAX ?>"
                                  placeholder="Tell people about you and your art"
                                  aria-describedby="bio-counter"><?= art_e($values['bio']) ?></textarea>
                        <p class="profile-help profile-help--right" id="bio-counter" data-bio-counter><?= text_length($values['bio']) ?> / <?= PROFILE_BIO_MAX ?></p>
                        <p class="profile-error"><?= art_e($errors['bio'] ?? '') ?></p>
                    </div>

                    <div class="profile-form__actions">
                        <a class="profile-btn profile-btn--secondary" href="profile.php">Cancel</a>
                        <button type="submit" class="profile-btn">Save changes</button>
                    </div>
                </form>

                <?php if ($hasCustom): ?>
                    <!-- Separate form so "Remove photo" can sit next to the picture without nesting forms. -->
                    <form method="post" action="edit_profile.php" id="remove-avatar-form" data-confirm="Remove your profile picture?">
                        <input type="hidden" name="csrf_token" value="<?= art_e(art_csrf_token()) ?>">
                        <input type="hidden" name="action" value="remove_image">
                    </form>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <?php include __DIR__ . '/partials/footer.php'; ?>
    <script src="js/profile.js" defer></script>
</body>
</html>
