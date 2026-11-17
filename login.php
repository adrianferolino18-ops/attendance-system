<?php
// login.php - Step 1 of login: camera -> liveness check -> face recognition -> go to PIN.
// All the work is done by js/login.js, which calls:
//   api/challenge.php (Member 4)  liveness
//   api/recognize.php (Member 4)  Python face recognition (Member 3), then sets the pending user

require_once __DIR__ . '/includes/security.php';
secureSessionStart();

// already logged in? go straight to attendance
if (isLoggedIn()) {
    header('Location: attendance.php');
    exit;
}

$pageTitle = 'Login';
require_once __DIR__ . '/includes/header.php';
?>

<h1>Login</h1>

<div class="camera-box">
    <video id="video" autoplay muted playsinline></video>
    <canvas id="overlay"></canvas>
</div>
<p id="status">Click Start to begin.</p>
<button class="btn" id="startBtn" type="button">Start</button>
<a class="btn btn-secondary" href="index.php">Back</a>

<script src="js/face.js"></script>
<script src="js/liveness.js"></script>
<script src="js/login.js"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>