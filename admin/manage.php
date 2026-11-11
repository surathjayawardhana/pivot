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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../css/style.css">
    <title>NSBM ArtCraft Platform</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
</head>
<body>

<?php include __DIR__ . '/partials/header.php'; ?>

<main>
   
    <?php include __DIR__ . '/partials/lil-nav.php'; ?>
    <?php include __DIR__ . '/partials/edit-showcase.php'; ?>
    <?php include __DIR__ . '/partials/artwork-management.php'; ?>
    <?php include __DIR__ . '/partials/user-management.php'; ?>

   
</main>

<?php include __DIR__ . '/partials/footer.php'; ?>

</body>
</html>