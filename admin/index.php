<?php
declare(strict_types=1);

// Load the session and shared helpers.
require_once __DIR__ . '/../includes/db.php';

// Only authenticated administrators may access this page.
if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'admin'
) {
    header('Location: ../login.php');
    exit;
}
?>

<?php

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/includes/db.php';
require_once $projectRoot . '/includes/auth.php';
require_once $projectRoot . '/includes/artwork_helpers.php';

try {
    $db = art_db();
    $user = art_require_user($db, '../login.php');
} catch (Throwable $e) {
    error_log('[admin/index] ' . $e->getMessage());
    http_response_code(500);
    exit('The service is temporarily unavailable.');
}

if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('You do not have permission to access this page.');
}

$firstName = $user['first_name'] ?: 'Admin';

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <title>Admin Portal | NSBM ArtCraft</title>

    <link rel="stylesheet" href="../css/admin-home.css">
</head>

<body>

    <main class="portal">

        <section class="welcome">
            <p class="eyebrow">NSBM ARTCRAFT PLATFORM</p>

            <h1>
                Welcome back, <?= art_e($firstName) ?>.
            </h1>

            <p>Where would you like to go? Choose how you want to access ArtCraft.</p>
        </section>


        <section class="destinations">

            <a href="manage.php" class="destination destination--admin">
                <span>Administration Board</span>
                <span class="arrow" aria-hidden="true">↗</span>
            </a>

            <a href="../index.php" class="destination destination--site">
                <span>View Site</span>
                <span class="arrow" aria-hidden="true">↗</span>
            </a>

        </section>

        <div class="portal-logout">

        <form action="../logout.php" method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <button type="submit" class="logout-button">
                Log out <span aria-hidden="true">↗</span>
            </button>

            </form>

        </div>

    </main>

</body>
</html>
