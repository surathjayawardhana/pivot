<?php

/**
 * admin/edit-event.php
 * Administrator-only event manager.
 * Allows administrators to view, edit, and delete events.
 */

require_once __DIR__ . '/../includes/event-bridge.php';

// -----------------------------------------------------------------------------
// Access control: verify the authenticated user and their current database role.
// -----------------------------------------------------------------------------
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
        <link rel="icon" type="image/png" href="../assets/images/favicon.png">
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Access denied | NSBM ArtCraft</title>
    </head>
    <body style="font-family: Arial, sans-serif; padding: 2rem;">
        <h1>Access denied</h1>
        <p>Only active administrators can manage events.</p>
        <p><a href="../event.php">Back to Events</a></p>
    </body>
    </html>
    <?php
    exit;
}

// The database has confirmed this user is an active administrator.
// Keep the shared admin header's session-based check in sync with that result.
$_SESSION['role'] = 'admin';

// -----------------------------------------------------------------------------
// Settings (must match the events table and add-event.php).
// -----------------------------------------------------------------------------
const EV_EDIT_NAME_MAX = 150;
const EV_EDIT_DESCRIPTION_MAX = 5000;
const EV_EDIT_IMAGE_MAX_BYTES = 5 * 1024 * 1024;
const EV_EDIT_ALLOWED_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

$uploadDir = dirname(__DIR__) . '/uploads/events';
$uploadRelative = 'uploads/events/';
$db = ev_db();

$errors = [];
$formError = '';
$openEditId = 0;
$formValues = [];

/**
 * Return a safe filename only for images created by the event upload system.
 * This prevents a database path from being used for arbitrary file deletion.
 */
function ev_edit_managed_filename(?string $relativePath): ?string
{
    if (!is_string($relativePath)) {
        return null;
    }

    $relativePath = str_replace('\\', '/', trim($relativePath));

    if (!preg_match(
        '~^uploads/events/(event_[a-f0-9]{16})\.(jpg|png|webp)$~i',
        $relativePath,
        $matches
    )) {
        return null;
    }

    return $matches[1] . '.' . strtolower($matches[2]);
}

/**
 * Remove an event image only when no remaining event record uses it.
 */
function ev_edit_remove_unused_image(PDO $db, ?string $relativePath): void
{
    $filename = ev_edit_managed_filename($relativePath);

    if ($filename === null) {
        return;
    }

    try {
        $managedPath = 'uploads/events/' . $filename;
        $stmt = $db->prepare('SELECT COUNT(*) FROM events WHERE image_path = :image_path');
        $stmt->execute([':image_path' => $managedPath]);

        // Never remove a file that is still referenced by another event.
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $absolutePath = __DIR__ . '/../uploads/events/' . $filename;

        if (is_file($absolutePath) && !@unlink($absolutePath)) {
            error_log('edit-event.php: Could not remove old event image: ' . $absolutePath);
        }
    } catch (Throwable $e) {
        // File cleanup must not turn a successful database update/delete into a failure.
        error_log('edit-event.php image cleanup: ' . $e->getMessage());
    }
}

/**
 * Validate an optional uploaded event image.
 * Returns [extension, error]. A null extension means no new image was supplied.
 */
function ev_edit_validate_image(mixed $file): array
{
    // The image is optional when editing; no file means keep the current image.
    if (!is_array($file)) {
        return [null, null];
    }

    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return [null, null]; // Keeping the current image is allowed.
    }

    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        return ['', 'The image is too large. Choose an image of 5 MB or smaller.'];
    }

    if ($uploadError !== UPLOAD_ERR_OK) {
        return ['', 'The image could not be uploaded. Please try again.'];
    }

    $tmpName = $file['tmp_name'] ?? '';
    $fileSize = (int) ($file['size'] ?? 0);

    if (!is_string($tmpName) || $tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['', 'Invalid image upload.'];
    }

    if ($fileSize <= 0 || $fileSize > EV_EDIT_IMAGE_MAX_BYTES) {
        return ['', 'The image must be greater than 0 bytes and no larger than 5 MB.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpName);

    if (!isset(EV_EDIT_ALLOWED_TYPES[$mime])) {
        return ['', 'Use a JPEG, PNG or WEBP image.'];
    }

    if (@getimagesize($tmpName) === false) {
        return ['', 'The uploaded file is not a valid image.'];
    }

    return [EV_EDIT_ALLOWED_TYPES[$mime], null];
}

/**
 * Build a page-relative URL only for a recognised event image path.
 */
function ev_edit_image_url(?string $relativePath): ?string
{
    $filename = ev_edit_managed_filename($relativePath);

    if ($filename === null) {
        return null;
    }

    $absolutePath = __DIR__ . '/../uploads/events/' . $filename;

    if (!is_file($absolutePath)) {
        return null;
    }

    return '../uploads/events/' . rawurlencode($filename) . '?v=' . (string) filemtime($absolutePath);
}

// -----------------------------------------------------------------------------
// Handle edit and delete requests. All state-changing requests use POST + CSRF.
// -----------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
        ? $_POST['csrf_token']
        : null;

    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $formError = 'The request is larger than the server allows. Check the image size and try again.';
    } elseif (!ev_csrf_valid($submittedToken)) {
        $formError = 'Your session has expired. Refresh the page and try again.';
    } else {
        $action = isset($_POST['action']) && is_string($_POST['action'])
            ? $_POST['action']
            : '';

        $eventId = filter_var(
            $_POST['event_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($eventId === false || $eventId === null) {
            $formError = 'Invalid event selected.';
        } else {
            $eventId = (int) $eventId;

            $findEvent = $db->prepare(
                'SELECT id, event_name, description, image_path
                   FROM events
                  WHERE id = :id
                  LIMIT 1'
            );
            $findEvent->execute([':id' => $eventId]);
            $currentEvent = $findEvent->fetch(PDO::FETCH_ASSOC);

            if (!$currentEvent) {
                $formError = 'The selected event could not be found. It may have already been deleted.';
            } elseif ($action === 'delete') {
                try {
                    $db->beginTransaction();

                    $delete = $db->prepare('DELETE FROM events WHERE id = :id');
                    $delete->execute([':id' => $eventId]);

                    if ($delete->rowCount() !== 1) {
                        throw new RuntimeException('The event record was not deleted.');
                    }

                    $db->commit();

                    // event_participants are automatically deleted by the FK cascade.
                    // Remove the image only after the database deletion succeeds.
                    ev_edit_remove_unused_image($db, (string) $currentEvent['image_path']);

                    header('Location: edit-event.php?deleted=1');
                    exit;
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }

                    error_log('edit-event.php delete: ' . $e->getMessage());
                    $formError = 'The event could not be deleted. Please try again.';
                }
            } elseif ($action === 'update') {
                $openEditId = $eventId;
                $name = isset($_POST['event_name']) && is_string($_POST['event_name'])
                    ? trim($_POST['event_name'])
                    : '';
                $description = isset($_POST['description']) && is_string($_POST['description'])
                    ? trim($_POST['description'])
                    : '';

                $formValues = [
                    'event_name' => $name,
                    'description' => $description,
                ];

                if ($name === '') {
                    $errors['event_name'] = 'Enter an event name.';
                } elseif (function_exists('mb_strlen')
                    ? mb_strlen($name, 'UTF-8') > EV_EDIT_NAME_MAX
                    : strlen($name) > EV_EDIT_NAME_MAX) {
                    $errors['event_name'] = 'The event name can be at most ' . EV_EDIT_NAME_MAX . ' characters.';
                }

                if ($description === '') {
                    $errors['description'] = 'Enter an event description.';
                } elseif (function_exists('mb_strlen')
                    ? mb_strlen($description, 'UTF-8') > EV_EDIT_DESCRIPTION_MAX
                    : strlen($description) > EV_EDIT_DESCRIPTION_MAX) {
                    $errors['description'] = 'The description can be at most ' . EV_EDIT_DESCRIPTION_MAX . ' characters.';
                }

                [$newExtension, $imageError] = ev_edit_validate_image($_FILES['event_image'] ?? null);
                if ($imageError !== null) {
                    $errors['event_image'] = $imageError;
                }

                if (!$errors) {
                    $newAbsolutePath = null;
                    $oldRelativePath = (string) $currentEvent['image_path'];
                    $newRelativePath = $oldRelativePath;

                    try {
                        if ($newExtension !== null) {
                            if (!is_dir($uploadDir)
                                && !mkdir($uploadDir, 0755, true)
                                && !is_dir($uploadDir)) {
                                throw new RuntimeException('Could not create the event upload directory.');
                            }

                            $newFileName = 'event_' . bin2hex(random_bytes(8)) . '.' . $newExtension;
                            $newAbsolutePath = $uploadDir . '/' . $newFileName;

                            $tmpName = (string) ($_FILES['event_image']['tmp_name'] ?? '');
                            if (!move_uploaded_file($tmpName, $newAbsolutePath)) {
                                $newAbsolutePath = null;
                                throw new RuntimeException('move_uploaded_file failed.');
                            }

                            $newRelativePath = $uploadRelative . $newFileName;
                        }

                        $update = $db->prepare(
                            'UPDATE events
                                SET event_name = :event_name,
                                    description = :description,
                                    image_path = :image_path
                              WHERE id = :id'
                        );

                        $update->execute([
                            ':event_name' => $name,
                            ':description' => $description,
                            ':image_path' => $newRelativePath,
                            ':id' => $eventId,
                        ]);

                        // The new image is now referenced by the updated row.
                        // Remove the previous image only if no other event uses it.
                        if ($newRelativePath !== $oldRelativePath) {
                            ev_edit_remove_unused_image($db, $oldRelativePath);
                        }

                        header('Location: edit-event.php?updated=1');
                        exit;
                    } catch (Throwable $e) {
                        error_log('edit-event.php update: ' . $e->getMessage());

                        // Do not leave an uploaded file behind when the DB update fails.
                        if ($newAbsolutePath !== null && is_file($newAbsolutePath)) {
                            @unlink($newAbsolutePath);
                        }

                        $formError = 'The event could not be updated. Please try again.';
                    }
                }
            } else {
                $formError = 'Unknown event action.';
            }
        }
    }
}

// -----------------------------------------------------------------------------
// Load all events and participant totals for the management list.
// -----------------------------------------------------------------------------
$events = [];
$loadError = false;

try {
    $stmt = $db->query(
        'SELECT e.id,
                e.event_name,
                e.description,
                e.image_path,
                e.created_at,
                e.created_by,
                u.first_name AS creator_first_name,
                u.surname AS creator_surname,
                (SELECT COUNT(*)
                   FROM event_participants ep
                  WHERE ep.event_id = e.id) AS participant_count
           FROM events e
           LEFT JOIN users u ON u.id = e.created_by
          ORDER BY e.created_at DESC, e.id DESC'
    );

    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('edit-event.php load: ' . $e->getMessage());
    $loadError = true;
}

$updated = ($_GET['updated'] ?? '') === '1' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
$deleted = ($_GET['deleted'] ?? '') === '1' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Events | NSBM ArtCraft</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/header.css">

    <style>
body {
    margin: 0;
    background: #fff;
}

.ev-manager {
    --ev-blue: #0D6BB6;
    --ev-blue-dark: #0A5A9A;
    --ev-green: #3BA73C;
    --ev-text: #1E2933;
    --ev-muted: #6B7280;
    --ev-outline: #8E9AA7;      /* main outline: darker than before */
    --ev-divider: #C9D0D8;
    --ev-surface: #F5F7F9;

    /* G1 = standard circular corners, G2 = smooth (squircle) corners */
    --ev-g1: 14px;
    --ev-g2: 18px;
    --ev-btn-radius: 14px;      /* button corners (B2) */

    width: min(960px, calc(100% - 32px));
    margin: clamp(32px, 6vw, 72px) auto;
    color: var(--ev-text);
    font-family: Helvetica, Roboto, Arial, sans-serif;
    -webkit-font-smoothing: antialiased;
}

.ev-manager,
.ev-manager *,
.ev-manager *::before,
.ev-manager *::after {
    box-sizing: border-box;
}

.ev-manager {
    -webkit-text-size-adjust: 100%;
    text-size-adjust: 100%;
}

/* Smooth corners (G2) where supported; falls back to normal rounding */
@supports (corner-shape: squircle) {
    .ev-event,
    .ev-manager__empty,
    .ev-btn,
    .ev-manager__top-link,
    .ev-manager__alert,
    .ev-event__form input[type="text"],
    .ev-event__form textarea,
    .ev-event__form input[type="file"] {
        corner-shape: squircle;
    }
}

/* Heading */
.ev-manager__heading {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 32px;
}

.ev-manager h1 {
    margin: 0 0 6px;
    font-size: clamp(1.6rem, 4vw, 2rem);
    font-weight: 700;
    letter-spacing: -0.02em;
    line-height: 1.2;
}

.ev-manager__intro {
    margin: 0;
    color: var(--ev-muted);
    line-height: 1.5;
}

/* Buttons: normal / hover / active */
.ev-btn,
.ev-manager__top-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 42px;
    padding: 0 20px;
    border: 0;
    border-radius: var(--ev-btn-radius);
    background: var(--ev-blue);
    color: #fff;
    font: inherit;
    font-size: .92rem;
    font-weight: 600;
    text-decoration: none;
    line-height: 1.2;
    text-align: center;
    white-space: nowrap;
    cursor: pointer;
    -webkit-appearance: none;
    appearance: none;
    -webkit-tap-highlight-color: transparent;
    touch-action: manipulation;
    transition: background-color .15s ease;
}

/* Hover only on devices that really hover (prevents sticky hover on touch) */
@media (hover: hover) {
    .ev-btn:hover,
    .ev-manager__top-link:hover {
        background: var(--ev-blue-dark);
    }
}

.ev-btn:active,
.ev-manager__top-link:active {
    background: var(--ev-green);
}

/* Forms wrapped around buttons (e.g. delete) must not break the button row */
.ev-event__actions form,
.ev-event__editor-actions form {
    display: flex;
    margin: 0;
}

/* Alerts */
.ev-manager__alert {
    margin: 0 0 20px;
    padding: 12px 16px;
    border: 1.5px solid var(--ev-outline);
    border-radius: var(--ev-g1);
    background: #fff;
    font-size: .92rem;
    line-height: 1.5;
}

.ev-manager__alert--success {
    color: var(--ev-green);
    border-color: var(--ev-green);
}

.ev-manager__alert--error {
    color: #B42318;
    border-color: #B42318;
}

/* Event list */
.ev-manager__list {
    display: grid;
    gap: 16px;
}

.ev-event {
    display: grid;
    grid-template-columns: minmax(200px, 32%) minmax(0, 1fr);
    overflow: hidden;
    background: #fff;
    border: 1.5px solid var(--ev-outline);
    border-radius: var(--ev-g2);
}

.ev-event__media {
    min-height: 220px;
    background: var(--ev-surface);
}

.ev-event__image {
    display: block;
    width: 100%;
    height: 100%;
    min-height: 220px;
    object-fit: cover;
}

.ev-event__no-image {
    display: grid;
    min-height: 220px;
    place-items: center;
    padding: 20px;
    color: var(--ev-muted);
    font-size: .9rem;
    text-align: center;
}

.ev-event__content {
    min-width: 0;
    padding: 24px;
}

.ev-event__title {
    margin: 0 0 8px;
    font-size: 1.2rem;
    font-weight: 700;
    letter-spacing: -0.01em;
    line-height: 1.35;
    overflow-wrap: anywhere;
}

.ev-event__description {
    margin: 0;
    color: #4B5563;
    line-height: 1.65;
    overflow-wrap: anywhere;
    white-space: normal;
}

.ev-event__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 16px;
    margin-top: 14px;
    color: var(--ev-muted);
    font-size: .85rem;
}

.ev-event__actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-top: 20px;
}

/* Inline editor */
.ev-event__editor {
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1.5px solid var(--ev-divider);
}

.ev-event__editor summary {
    width: fit-content;
    color: var(--ev-blue);
    font-weight: 600;
    cursor: pointer;
    user-select: none;
}

.ev-event__editor summary:hover {
    color: var(--ev-blue-dark);
}

.ev-event__form {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 16px;
}

.ev-event__form label {
    margin-top: 8px;
    font-size: .9rem;
    font-weight: 600;
}

.ev-event__form input[type="text"],
.ev-event__form textarea,
.ev-event__form input[type="file"] {
    display: block;
    width: 100%;
    min-width: 0;
    min-height: 44px;
    padding: 10px 12px;
    border: 1.5px solid var(--ev-outline);
    border-radius: var(--ev-g1);
    background: #fff;
    color: var(--ev-text);
    font: inherit;
    transition: border-color .15s ease;
}

.ev-event__form textarea {
    min-height: 140px;
    resize: vertical;
    line-height: 1.6;
}

.ev-event__form input:focus-visible,
.ev-event__form textarea:focus-visible {
    outline: none;
    border-color: var(--ev-blue);
}

.ev-event__hint {
    margin: 0;
    color: var(--ev-muted);
    font-size: .82rem;
    line-height: 1.5;
}

.ev-event__error {
    margin: 0;
    color: #B42318;
    font-size: .85rem;
}

.ev-event__editor-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}

/* Empty state */
.ev-manager__empty {
    padding: 56px 24px;
    border: 1.5px solid var(--ev-outline);
    border-radius: var(--ev-g2);
    color: var(--ev-muted);
    text-align: center;
}

.ev-manager__empty strong {
    display: block;
    margin-bottom: 6px;
    color: var(--ev-text);
    font-size: 1.05rem;
    font-weight: 700;
}

/* Keyboard focus */
.ev-btn:focus-visible,
.ev-manager__top-link:focus-visible,
.ev-event__editor summary:focus-visible {
    outline: 2px solid var(--ev-blue);
    outline-offset: 3px;
}

/* Responsive */
@media (max-width: 700px) {
    .ev-event {
        grid-template-columns: 1fr;
    }

    .ev-event__media {
        min-height: 0;
    }

    .ev-event__image {
        height: auto;
        min-height: 0;
        aspect-ratio: 16 / 9;
    }

    .ev-event__no-image {
        min-height: 140px;
    }

    .ev-event__content {
        padding: 18px;
    }
}

@media (max-width: 480px) {
    .ev-manager {
        width: calc(100% - 24px);
        margin: 20px auto;
    }

    .ev-manager__heading {
        align-items: stretch;
    }

    .ev-manager__top-link {
        width: 100%;
    }

    /* Buttons sit side by side and share the row; they wrap only if too tight */
    .ev-event__actions,
    .ev-event__editor-actions {
        flex-direction: row;
        flex-wrap: wrap;
        align-items: stretch;
    }

    .ev-event__actions > *,
    .ev-event__editor-actions > * {
        flex: 1 1 130px;
        min-width: 0;
    }

    .ev-event__actions .ev-btn,
    .ev-event__editor-actions .ev-btn {
        flex: 1 1 auto;
        width: 100%;
    }

    /* 16px stops iOS Safari from zooming when a field is focused */
    .ev-event__form input[type="text"],
    .ev-event__form textarea,
    .ev-event__form input[type="file"] {
        font-size: 16px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .ev-btn,
    .ev-manager__top-link,
    .ev-event__form input,
    .ev-event__form textarea {
        transition: none;
    }
}
    </style>
</head>
<body>

<?php require_once __DIR__ . '/partials/header.php'; ?>
<?php include __DIR__ . '/partials/lil-nav4event.php'; ?>

<main class="ev-manager">
    <header class="ev-manager__heading">
        <div>
            <h1>Manage Events</h1>
            <p class="ev-manager__intro">
                View all events, update their details and images, or remove events from NARTCRAFT.
            </p>
        </div>
        <a class="ev-manager__top-link" href="add-event.php">+ Create Event</a>
    </header>

    <?php if ($updated): ?>
        <div class="ev-manager__alert ev-manager__alert--success" role="status">
            Event updated successfully.
        </div>
    <?php endif; ?>

    <?php if ($deleted): ?>
        <div class="ev-manager__alert ev-manager__alert--success" role="status">
            Event deleted successfully.
        </div>
    <?php endif; ?>

    <?php if ($formError !== ''): ?>
        <div class="ev-manager__alert ev-manager__alert--error" role="alert">
            <?= ev_e($formError) ?>
        </div>
    <?php endif; ?>

    <?php if ($loadError): ?>
        <div class="ev-manager__alert ev-manager__alert--error" role="alert">
            Events could not be loaded. Please refresh the page and try again.
        </div>
    <?php elseif ($events === []): ?>
        <div class="ev-manager__empty">
            <strong>No events found.</strong>
            Create your first event to display it here.
        </div>
    <?php else: ?>
        <section class="ev-manager__list" aria-label="All events">
            <?php foreach ($events as $event): ?>
                <?php
                $eventId = (int) $event['id'];
                $eventName = (string) $event['event_name'];
                $eventDescription = (string) $event['description'];
                $imageUrl = ev_edit_image_url((string) $event['image_path']);
                $participantCount = (int) $event['participant_count'];
                $creatorName = trim(
                    (string) ($event['creator_first_name'] ?? '') . ' ' .
                    (string) ($event['creator_surname'] ?? '')
                );
                $isEditing = $openEditId === $eventId;
                $editName = $isEditing && array_key_exists('event_name', $formValues)
                    ? (string) $formValues['event_name']
                    : $eventName;
                $editDescription = $isEditing && array_key_exists('description', $formValues)
                    ? (string) $formValues['description']
                    : $eventDescription;
                ?>

                <article class="ev-event" id="event-<?= $eventId ?>">
                    <div class="ev-event__media">
                        <?php if ($imageUrl !== null): ?>
                            <img
                                class="ev-event__image"
                                src="<?= ev_e($imageUrl) ?>"
                                alt="<?= ev_e($eventName) ?>"
                                loading="lazy"
                                decoding="async"
                            >
                        <?php else: ?>
                            <div class="ev-event__no-image">Event image unavailable</div>
                        <?php endif; ?>
                    </div>

                    <div class="ev-event__content">
                        <h2 class="ev-event__title"><?= ev_e($eventName) ?></h2>
                        <p class="ev-event__description"><?= nl2br(ev_e($eventDescription)) ?></p>

                        <div class="ev-event__meta">
                            <span><?= $participantCount ?> <?= $participantCount === 1 ? 'participant' : 'participants' ?></span>
                            <?php if ($creatorName !== ''): ?>
                                <span>Created by <?= ev_e($creatorName) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($event['created_at'])): ?>
                                <span>Created <?= ev_e(date('M j, Y', strtotime((string) $event['created_at']))) ?></span>
                            <?php endif; ?>
                            <span>Event #<?= $eventId ?></span>
                        </div>

                        <div class="ev-event__actions">
                            <a class="ev-btn ev-btn--edit" href="#edit-<?= $eventId ?>"
                               onclick="document.getElementById('edit-details-<?= $eventId ?>').open = true;">
                                Edit Event
                            </a>

                            <form method="post" action="edit-event.php" class="ev-delete-form">
                                <input type="hidden" name="csrf_token" value="<?= ev_e(ev_csrf_token()) ?>">
                                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                <input type="hidden" name="action" value="delete">
                                <button class="ev-btn ev-btn--delete" type="submit">
                                    Delete Event
                                </button>
                            </form>
                        </div>

                        <details class="ev-event__editor" id="edit-details-<?= $eventId ?>" <?= ($isEditing || (isset($_GET['edit']) && (string) $_GET['edit'] === (string) $eventId)) ? 'open' : '' ?>>
                            <summary id="edit-<?= $eventId ?>">Edit event details</summary>

                            <form class="ev-event__form" method="post" action="edit-event.php#event-<?= $eventId ?>"
                                  enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?= ev_e(ev_csrf_token()) ?>">
                                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                <input type="hidden" name="action" value="update">

                                <label for="event-name-<?= $eventId ?>">Event title</label>
                                <input
                                    type="text"
                                    id="event-name-<?= $eventId ?>"
                                    name="event_name"
                                    maxlength="<?= EV_EDIT_NAME_MAX ?>"
                                    required
                                    value="<?= ev_e($editName) ?>"
                                >
                                <?php if ($isEditing && isset($errors['event_name'])): ?>
                                    <p class="ev-event__error"><?= ev_e($errors['event_name']) ?></p>
                                <?php endif; ?>

                                <label for="event-description-<?= $eventId ?>">Description</label>
                                <textarea
                                    id="event-description-<?= $eventId ?>"
                                    name="description"
                                    maxlength="<?= EV_EDIT_DESCRIPTION_MAX ?>"
                                    required
                                ><?= ev_e($editDescription) ?></textarea>
                                <?php if ($isEditing && isset($errors['description'])): ?>
                                    <p class="ev-event__error"><?= ev_e($errors['description']) ?></p>
                                <?php endif; ?>

                                <label for="event-image-<?= $eventId ?>">Replace event image (optional)</label>
                                <input
                                    type="file"
                                    id="event-image-<?= $eventId ?>"
                                    name="event_image"
                                    accept="image/jpeg,image/png,image/webp"
                                >
                                <p class="ev-event__hint">JPEG, PNG or WEBP, up to 5 MB. Leave this empty to keep the current image.</p>
                                <?php if ($isEditing && isset($errors['event_image'])): ?>
                                    <p class="ev-event__error"><?= ev_e($errors['event_image']) ?></p>
                                <?php endif; ?>

                                <div class="ev-event__editor-actions">
                                    <button class="ev-btn ev-btn--edit" type="submit">Save Changes</button>
                                </div>
                            </form>
                        </details>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>

<script>
document.addEventListener('submit', function (event) {
    const form = event.target.closest('.ev-delete-form');
    if (!form) return;

    const confirmed = window.confirm(
        'Are you sure you want to delete this event? This action cannot be undone. Event participant records will also be removed.'
    );

    if (!confirmed) {
        event.preventDefault();
    }
});
</script>

</body>
</html>
