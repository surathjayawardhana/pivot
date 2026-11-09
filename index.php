<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
/**
 * PIVOT homepage.
 *
 * 1. Loads the shared database setup and the artwork helpers (once each).
 * 2. Validates the two GET parameters (search, sort) in ONE place.
 * 3. Includes the search toolbar and the artwork feed.
 */

// db.php creates the only database connection (db()) and starts the session.
// artwork_helpers.php reuses that connection through art_db().


require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/artwork_helpers.php';

// ---- Sorting whitelist: value => label shown in the dropdown ----------
// posts.php maps these same keys to fixed ORDER BY clauses.
$sortOptions = [
    'newest'     => 'Newest first',
    'oldest'     => 'Oldest first',
    'most_liked' => 'Most liked',
];

// ---- Validate GET parameters -------------------------------------------
$search = '';
if (isset($_GET['search']) && is_string($_GET['search'])) {
    $search = preg_replace('/\s+/u', ' ', $_GET['search']) ?? '';   // collapse whitespace
    $search = trim($search);
    $search = mb_substr($search, 0, 100, 'UTF-8');                  // sensible length limit
}

$sort = 'newest';
if (isset($_GET['sort']) && is_string($_GET['sort']) && array_key_exists($_GET['sort'], $sortOptions)) {
    $sort = $_GET['sort'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <link rel="stylesheet" href="css/style.css">
    <title>NSBM ArtCraft Platform</title>
    <link rel="icon" type="image/png" href="assets/images/favicon.png">

    <!-- If your existing header partial already prints <head>, move these two lines into it. -->
    <link rel="stylesheet" href="assets/css/search.css">
    <link rel="stylesheet" href="assets/css/posts.css">
    <link rel="stylesheet" href="assets/css/likes.css">
    <link rel="stylesheet" href="css/welcome-hero.css">
    <link rel="stylesheet" href="assets/css/comments.css">
</head>
<body>

<?php include __DIR__ . '/partials/header.php';?>

<?php include __DIR__ . '/partials/welcome-hero.php'; ?>

<?php include __DIR__ . '/partials/showcase.php';?>

<?php include __DIR__ . '/partials/seacher.php'; ?>

<?php include __DIR__ . '/partials/posts.php'; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>

<script src="js/likes.js" defer></script>


</body>
</html>
