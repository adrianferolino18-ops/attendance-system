<?php
// logout.php - ends the session and goes back to the home page

require_once __DIR__ . '/includes/security.php';
secureSessionStart();

// only accept a POST request with a valid CSRF token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfValid((string)($_POST['csrf_token'] ?? ''))) {
    logoutUser();   // clears the session data, the cookie and the session itself
}

header('Location: index.php');
exit;