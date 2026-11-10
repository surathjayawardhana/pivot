<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

/**
 * event.php
 * Public Events page. Guests can browse; login is only needed to join.
 */

require_once __DIR__ . '/includes/event-bridge.php';

$userId    = ev_user_id();
$events    = [];
$loadError = false;

try {
    $sql = 'SELECT e.id, e.event_name, e.description, e.image_path,
                   (SELECT COUNT(*) FROM event_participants p WHERE p.event_id = e.id) AS participant_count,
                   EXISTS(SELECT 1 FROM event_participants m
                          WHERE m.event_id = e.id AND m.user_id = :uid) AS joined
            FROM events e
            ORDER BY e.created_at DESC, e.id DESC';
    $stmt = ev_db()->prepare($sql);
    $stmt->bindValue(':uid', $userId ?? 0, PDO::PARAM_INT); // 0 matches nobody (guests)
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('event.php: ' . $e->getMessage());
    $loadError = true;
}

$pageTitle = 'Events';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Events | NSBM ArtCraft</title>

    <link rel="icon" type="image/png" href="assets/images/favicon.png">

    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/event.css">
</head>
<body>

<?php require_once __DIR__ . '/partials/header.php'; ?>

<main class="ev-page">
    <div class="ev-container"
         id="ev-root"
         data-csrf="<?= ev_e(ev_csrf_token()) ?>"
         data-logged-in="<?= $userId !== null ? '1' : '0' ?>"
         data-login-url="<?= ev_e(ev_login_url()) ?>">

        <header class="ev-hero">
            <h1 class="ev-title">Events</h1>
            <p class="ev-subtitle">Discover upcoming events and become part of the ArtCraft community.</p>
        </header>

        <?php if ($loadError): ?>
            <div class="ev-state ev-state--error" role="alert">
                <strong>We couldn't load events.</strong>
                <span>Please refresh the page in a moment.</span>
            </div>

        <?php elseif (count($events) === 0): ?>
            <div class="ev-state">
                <strong>No events yet.</strong>
                <span>New events will appear here as soon as they are announced.</span>
            </div>

        <?php else: ?>
            <div class="ev-list">
                <?php foreach ($events as $event): ?>
                    <?php
                    $isJoined = (int) $event['joined'] === 1;
                    $count    = (int) $event['participant_count'];
                    ?>
                    <article class="ev-card">
                        <div class="ev-media">
                            <img src="<?= ev_e($event['image_path']) ?>"
                                 alt="<?= ev_e($event['event_name']) ?>"
                                 loading="lazy"
                                 decoding="async">
                        </div>

                        <div class="ev-body">
                            <h2 class="ev-name"><?= ev_e($event['event_name']) ?></h2>
                            <div class="ev-description"><?= nl2br(ev_e($event['description'])) ?></div>

                            <div class="ev-footer">
                                <button type="button"
                                        class="ev-btn<?= $isJoined ? ' is-joined' : '' ?>"
                                        data-event-id="<?= (int) $event['id'] ?>"
                                        data-joined="<?= $isJoined ? '1' : '0' ?>"
                                        aria-pressed="<?= $isJoined ? 'true' : 'false' ?>">
                                    <span class="ev-btn__label"><?= $isJoined ? 'Joined ✓' : 'Join Event' ?></span>
                                </button>

                                <span class="ev-count">
                                    <strong data-count><?= $count ?></strong>
                                    <span data-count-label><?= $count === 1 ? 'participant' : 'participants' ?></span>
                                </span>
                            </div>

                            <p class="ev-message" role="status" aria-live="polite" hidden></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
(function () {
    'use strict';

    var root = document.getElementById('ev-root');
    if (!root) { return; }

    var csrf     = root.dataset.csrf;
    var loggedIn = root.dataset.loggedIn === '1';
    var loginUrl = root.dataset.loginUrl;

    function showMessage(card, text, isError, linkUrl, linkText) {
        var box = card.querySelector('.ev-message');
        box.textContent = text;
        box.classList.toggle('is-error', !!isError);
        if (linkUrl) {
            box.appendChild(document.createTextNode(' '));
            var a = document.createElement('a');
            a.href = linkUrl;
            a.textContent = linkText;
            box.appendChild(a);
        }
        box.hidden = false;
    }

    function hideMessage(card) {
        var box = card.querySelector('.ev-message');
        box.hidden = true;
        box.textContent = '';
    }

    function setBusy(btn, busy) {
        btn.disabled = busy;
        btn.classList.toggle('is-busy', busy);
        btn.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    function render(card, btn, joined, count) {
        btn.dataset.joined = joined ? '1' : '0';
        btn.classList.toggle('is-joined', joined);
        btn.setAttribute('aria-pressed', joined ? 'true' : 'false');
        btn.querySelector('.ev-btn__label').textContent = joined ? 'Joined ✓' : 'Join Event';
        card.querySelector('[data-count]').textContent = String(count);
        card.querySelector('[data-count-label]').textContent = count === 1 ? 'participant' : 'participants';
    }

    root.addEventListener('click', function (event) {
        var btn = event.target.closest('.ev-btn');
        if (!btn || btn.disabled) { return; }

        var card = btn.closest('.ev-card');
        hideMessage(card);

        // Guests can browse, but must log in to join.
        if (!loggedIn) {
            showMessage(card, 'Please log in to join events.', true, loginUrl, 'Log in');
            return;
        }

        // Send an explicit action so a double click can never flip the state twice.
        var action = btn.dataset.joined === '1' ? 'leave' : 'join';

        var body = new FormData();
        body.append('event_id', btn.dataset.eventId);
        body.append('action', action);
        body.append('csrf_token', csrf);

        setBusy(btn, true);

        fetch('connect-event.php', {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
        .then(function (response) {
            return response.json()
                .catch(function () { return { success: false, message: 'Unexpected server response.' }; })
                .then(function (data) { return { status: response.status, data: data }; });
        })
        .then(function (result) {
            var data = result.data;
            if (result.status === 200 && data.success) {
                render(card, btn, data.joined, data.participant_count);
            } else if (result.status === 401) {
                showMessage(card, data.message || 'Please log in to join events.', true, loginUrl, 'Log in');
            } else {
                showMessage(card, data.message || 'Something went wrong. Please try again.', true);
            }
        })
        .catch(function () {
            showMessage(card, 'Network problem. Check your connection and try again.', true);
        })
        .then(function () {
            setBusy(btn, false);
        });
    });
})();
</script>

<?php
// EDIT: confirm this is your real public footer partial path.
require_once __DIR__ . '/partials/footer.php';
?>
</body>
</html>