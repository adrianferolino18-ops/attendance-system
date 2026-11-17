<?php

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/users.php';
secureSessionStart();

$userId = getPendingUser();
if ($userId === null) {
    header('Location: login.php');
    exit;
}

$user = getUserPublic($userId);   
if ($user === null) {
    clearPending();
    header('Location: login.php');
    exit;
}

$pageTitle = 'Enter PIN';
require_once __DIR__ . '/includes/header.php';
?>

<h1>Enter your PIN</h1>
<p>Hello, <?php echo e($user['name']); ?>.</p>

<div id="message" class="message error" style="display:none;"></div>

<form id="pinForm" autocomplete="off">
    <label for="pin">PIN (4 to 6 digits)</label>
    <input type="password" id="pin" maxlength="6" inputmode="numeric" autocomplete="off" required>

    <button class="btn" id="pinBtn" type="submit">Submit</button>
    <a class="btn btn-secondary" href="login.php">Cancel</a>
</form>

<script src="js/pin.js"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>