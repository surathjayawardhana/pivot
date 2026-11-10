<?php

/**
 * admin/add-event.php
 * Administrator-only page for creating events.
 * Access is checked on the server (session + database role) before anything
 * is shown or saved. Hiding the link is NOT the protection.
 */

require_once __DIR__ . '/../includes/event-bridge.php';

// ---- Access control (runs before any output) -------------------------------
if (ev_user_id() === null) {
    header('Location: ' . ev_login_url('../'));
    exit;
}

if (!ev_is_admin()) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Access denied</title>
        <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    </head>
    <body style="font-family: Helvetica, Roboto, Arial, sans-serif; padding: 2rem;">
        <h1>Access denied</h1>
        <p>Only administrators can add events.</p>
        <p><a href="../event.php">Back to Events</a></p>
    </body>
    </html>
    <?php
    exit;
}

// ---- Settings --------------------------------------------------------------
const EV_NAME_MAX         = 150;     // must match events.event_name varchar(150)
const EV_DESCRIPTION_MAX  = 5000;    // characters (column is TEXT, this keeps it sensible)
const EV_IMAGE_MAX_BYTES  = 5 * 1024 * 1024; // 5 MB (php.ini must allow at least this)
const EV_ALLOWED_TYPES    = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

$uploadDir      = dirname(__DIR__) . '/uploads/events';  // folder on disk
$uploadRelative = 'uploads/events/';                     // what is stored in MySQL

$errors = [];
$name   = '';
$desc   = '';

// ---- Handle form submission ------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    // If the upload is bigger than post_max_size, PHP empties $_POST and $_FILES.
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors['form'] = 'The upload is larger than the server allows. Choose a smaller image.';
    } elseif (!ev_csrf_valid(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $errors['form'] = 'Your session has expired. Please refresh the page and try again.';
    } else {
        // Text fields: trim, then validate.
        $name = trim((string) ($_POST['event_name'] ?? ''));
        $desc = trim((string) ($_POST['description'] ?? ''));

        if ($name === '') {
            $errors['event_name'] = 'Enter an event name.';
        } elseif (mb_strlen($name) > EV_NAME_MAX) {
            $errors['event_name'] = 'The event name can be at most ' . EV_NAME_MAX . ' characters.';
        }

        if ($desc === '') {
            $errors['description'] = 'Enter a description.';
        } elseif (mb_strlen($desc) > EV_DESCRIPTION_MAX) {
            $errors['description'] = 'The description can be at most ' . EV_DESCRIPTION_MAX . ' characters.';
        }

        // Image: check the upload status, size, real MIME type and that it opens as an image.
        $extension = '';
        $file      = $_FILES['event_image'] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors['event_image'] = 'Choose an event image.';
        } elseif ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            $errors['event_image'] = 'That image is too large.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $errors['event_image'] = 'The image could not be uploaded. Please try again.';
        } elseif ((int) $file['size'] > EV_IMAGE_MAX_BYTES) {
            $errors['event_image'] = 'The image must be 5 MB or smaller.';
        } elseif (!is_uploaded_file($file['tmp_name'])) {
            $errors['event_image'] = 'Invalid upload.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = (string) $finfo->file($file['tmp_name']);

            if (!isset(EV_ALLOWED_TYPES[$mime])) {
                $errors['event_image'] = 'Use a JPEG, PNG or WEBP image.';
            } elseif (@getimagesize($file['tmp_name']) === false) {
                $errors['event_image'] = 'That file is not a valid image.';
            } else {
                $extension = EV_ALLOWED_TYPES[$mime];
            }
        }

        // Everything valid: save the file, then the database row.
        if (!$errors) {
            $savedPath = null;
            try {
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                    throw new RuntimeException('Could not create ' . $uploadDir);
                }

                // Random server-made name: the original file name is never used.
                $fileName  = 'event_' . bin2hex(random_bytes(8)) . '.' . $extension;
                $savedPath = $uploadDir . '/' . $fileName;

                if (!move_uploaded_file($file['tmp_name'], $savedPath)) {
                    $savedPath = null;
                    throw new RuntimeException('move_uploaded_file failed');
                }

                $stmt = ev_db()->prepare(
                    'INSERT INTO events (event_name, description, image_path, created_by)
                     VALUES (:name, :description, :image_path, :created_by)'
                );
                $stmt->execute([
                    ':name'        => $name,
                    ':description' => $desc,
                    ':image_path'  => $uploadRelative . $fileName,
                    ':created_by'  => ev_user_id(),
                ]);

                // Post/Redirect/Get: refreshing the page will not create a duplicate.
                header('Location: add-event.php?created=1');
                exit;
            } catch (Throwable $e) {
                error_log('add-event.php: ' . $e->getMessage());
                // Clean up the uploaded file if the database insert failed.
                if ($savedPath !== null && is_file($savedPath)) {
                    unlink($savedPath);
                }
                $errors['form'] = 'The event could not be saved. Please try again.';
            }
        }
    }
}

$created   = ($_GET['created'] ?? '') === '1' && $_SERVER['REQUEST_METHOD'] === 'GET';
$pageTitle = 'Add Event';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <title>Add Event | NSBM ArtCraft</title>

    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/header.css">
</head>
<body>

<?php require_once __DIR__ . '/partials/header.php'; ?>
<?php include __DIR__ . '/partials/lil-nav4event.php'; ?>

<style>

/* =========================================================
   NSBM ArtCraft — Admin Add Event
   Responsive styling
   ========================================================= */
body{
    margin: 0;
}
.ev-admin {
    --ev-blue: #0D6BB6;
    --ev-blue-dark: #0A5A9A;
    --ev-green: #3BA73C;
    --ev-text: #1E2933;
    --ev-muted: #6B7280;
    --ev-border: #DCE1E7;
    --ev-bg: #F5F7F9;
    --ev-white: #FFFFFF;
    --ev-error: #B42318;

    box-sizing: border-box;
    width: min(100% - 32px, 680px);
    margin: clamp(24px, 4vw, 48px) auto;
    padding: clamp(20px, 4vw, 36px);

    background: var(--ev-white);
    border: 1px solid var(--ev-border);
    border-radius: 20px;
    box-shadow: 0 2px 10px rgba(30, 41, 51, .05);

    color: var(--ev-text);
    font-family: Helvetica, Roboto, Arial, sans-serif;
    line-height: 1.6;
}

.ev-admin *,
.ev-admin *::before,
.ev-admin *::after {
    box-sizing: border-box;
}

/* Heading */

.ev-admin h1 {
    margin: 0 0 24px;
    font-size: clamp(1.5rem, 4vw, 2rem);
    line-height: 1.2;
    font-weight: 700;
    overflow-wrap: anywhere;
}

/* Form */

.ev-admin form {
    display: flex;
    flex-direction: column;
    width: 100%;
    min-width: 0;
}

.ev-admin label {
    display: block;
    margin: 20px 0 7px;
    font-size: .95rem;
    font-weight: 600;
}

.ev-admin input[type="text"],
.ev-admin textarea,
.ev-admin input[type="file"] {
    display: block;
    width: 100%;
    max-width: 100%;
    min-width: 0;
    min-height: 48px;
    padding: 12px 14px;

    border: 1px solid var(--ev-border);
    border-radius: 8px;
    background: var(--ev-white);

    color: var(--ev-text);
    font: inherit;
    font-size: 1rem;
}

.ev-admin textarea {
    min-height: 180px;
    resize: vertical;
    line-height: 1.6;
}

.ev-admin input:focus-visible,
.ev-admin textarea:focus-visible {
    outline: 2px solid var(--ev-blue);
    outline-offset: 2px;
    border-color: var(--ev-blue);
}

/* File input */

.ev-admin input[type="file"] {
    padding: 10px;
    cursor: pointer;
    font-size: .9rem;
}

.ev-admin input[type="file"]::file-selector-button {
    margin-right: 12px;
    padding: 9px 13px;
    border: 0;
    border-radius: 8px;
    background: #EAF3FA;
    color: var(--ev-blue);
    font: inherit;
    font-weight: 600;
    cursor: pointer;
}

.ev-admin input[type="file"]::file-selector-button:hover {
    background: #DCECF8;
}

/* Helper text */

.ev-admin .ev-hint {
    margin: 7px 0 0;
    color: var(--ev-muted);
    font-size: .85rem;
    line-height: 1.5;
}

.ev-admin .ev-error {
    margin: 7px 0 0;
    color: var(--ev-error);
    font-size: .875rem;
    overflow-wrap: anywhere;
}

/* Alerts */

.ev-admin .ev-alert {
    margin-bottom: 20px;
    padding: 16px 18px;
    border: 1px solid transparent;
    border-radius: 12px;
    overflow-wrap: anywhere;
}

.ev-admin .ev-alert--error {
    background: #FDECEC;
    border-color: #F2B8B8;
    color: var(--ev-error);
}

.ev-admin .ev-alert--ok {
    background: #EDF7EE;
    border-color: #B7DDB9;
    color: #256B27;
}

.ev-admin .ev-links {
    display: flex;
    flex-wrap: wrap;
    gap: 12px 20px;
}

.ev-admin a {
    color: var(--ev-blue);
    font-weight: 500;
    overflow-wrap: anywhere;
}

/* Submit button */

.ev-admin button[type="submit"] {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    width: fit-content;
    min-width: 160px;
    min-height: 48px;
    margin-top: 26px;
    padding: 12px 24px;

    border: 0;
    border-radius: 999px;
    background: var(--ev-blue);
    color: var(--ev-white);

    font: inherit;
    font-weight: 600;
    cursor: pointer;
    transition: background-color .15s ease;
}

.ev-admin button[type="submit"]:hover {
    background: var(--ev-blue-dark);
}

.ev-admin button[type="submit"]:active {
    background: var(--ev-green);
}

.ev-admin button[type="submit"]:focus-visible {
    outline: 3px solid var(--ev-blue);
    outline-offset: 3px;
}

/* Tablet */

@media (max-width: 768px) {
    .ev-admin {
        width: calc(100% - 32px);
        margin: 24px auto;
    }

    .ev-admin textarea {
        min-height: 160px;
    }
}

/* Action buttons */
.ev-admin .ev-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    margin-top: 26px;
}

.ev-admin .ev-actions button[type="submit"] {
    margin-top: 0;
}

.ev-admin .ev-edit-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    min-width: 160px;
    min-height: 48px;
    padding: 12px 24px;

    border: 0;
    border-radius: 999px;

    background: #3BA73C;
    color: #FFFFFF;

    font: inherit;
    font-weight: 600;
    text-decoration: none;

    cursor: pointer;
    transition: background-color .15s ease;
}

.ev-admin .ev-edit-button:hover {
    background: #2E8B32;
}

.ev-admin .ev-edit-button:focus-visible {
    outline: 3px solid #3BA73C;
    outline-offset: 3px;
}

/* Mobile */

@media (max-width: 480px) {
    .ev-admin {
        width: calc(100% - 24px);
        margin: 16px auto;
        padding: 20px 16px;
        border-radius: 14px;
    }

    .ev-admin h1 {
        margin-bottom: 18px;
    }

    .ev-admin label {
        margin-top: 17px;
    }

    .ev-admin input[type="text"],
    .ev-admin textarea,
    .ev-admin input[type="file"] {
        font-size: 16px;
    }

    .ev-admin button[type="submit"] {
        width: 100%;
        min-height: 52px;
    }

    .ev-admin .ev-links {
        flex-direction: column;
        gap: 10px;
    }
    .ev-admin .ev-actions {
        flex-direction: column;
        align-items: stretch;
    }

    .ev-admin .ev-actions button[type="submit"],
    .ev-admin .ev-edit-button {
        width: 100%;
    }
}

/* Very small screens */

@media (max-width: 350px) {
    .ev-admin {
        width: calc(100% - 16px);
        padding: 16px 12px;
    }
}

/* Reduced motion */

@media (prefers-reduced-motion: reduce) {
    .ev-admin button[type="submit"] {
        transition: none;
    }
}

</style>

<main class="ev-admin">
    <h1>Add event</h1>

    <?php if ($created): ?>
        <div class="ev-alert ev-alert--ok" role="status">
            <p style="margin:0 0 .5rem;"><strong>Event created.</strong></p>
            <div class="ev-links">
                <a href="../event.php">View the Events page</a>
                <a href="add-event.php">Add another event</a>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($errors['form'])): ?>
        <div class="ev-alert ev-alert--error" role="alert"><?= ev_e($errors['form']) ?></div>
    <?php endif; ?>

    <form method="post" action="add-event.php" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= ev_e(ev_csrf_token()) ?>">

        <label for="event_name">Event name</label>
        <input type="text" id="event_name" name="event_name" maxlength="<?= EV_NAME_MAX ?>" required
               value="<?= ev_e($name) ?>">
        <?php if (isset($errors['event_name'])): ?><p class="ev-error"><?= ev_e($errors['event_name']) ?></p><?php endif; ?>

        <label for="description">Description</label>
        <textarea id="description" name="description" maxlength="<?= EV_DESCRIPTION_MAX ?>" required><?= ev_e($desc) ?></textarea>
        <?php if (isset($errors['description'])): ?><p class="ev-error"><?= ev_e($errors['description']) ?></p><?php endif; ?>

        <label for="event_image">Event image</label>
        <input type="file" id="event_image" name="event_image" accept="image/jpeg,image/png,image/webp" required>
        <p class="ev-hint">JPEG, PNG or WEBP, up to 5 MB.</p>
        <?php if (isset($errors['event_image'])): ?><p class="ev-error"><?= ev_e($errors['event_image']) ?></p><?php endif; ?>

        <div class="ev-actions">
            <button type="submit">Create Event</button>

            <a href="edit-event.php" class="ev-edit-button">
                Edit Event
            </a>
        </div>
    </form>
</main>
</body>
</html>

