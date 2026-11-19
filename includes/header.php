<?php
// includes/header.php - top part of every page.
// Starts the (secure) session and puts the CSRF token in <head>, so every page's JavaScript can read it.

require_once __DIR__ . '/security.php';
secureSessionStart();

// helper to safely print text inside HTML (stops script injection)
if (!function_exists('e')) {
    function e($text)
    {
        return escapeHtml((string)$text);
    }
}

if (!isset($pageTitle)) {
    $pageTitle = 'Face Attendance';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php echo csrfMetaTag(); ?>
    <title><?php echo e($pageTitle); ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="site-header">
    <a href="index.php">Face Attendance</a>
</div>
<div class="container">