<?php
// register.php - new user: name, employee ID, PIN, then 25 face photos.
// js/register.js takes the photos (Face.captureFrames, Member 3) and sends everything to api/register.php.

require_once __DIR__ . '/includes/security.php';
secureSessionStart();

$pageTitle = 'Register';
require_once __DIR__ . '/includes/header.php';
?>

<h1>Register</h1>

<div id="message" class="message error" style="display:none;"></div>

<form id="regForm" autocomplete="off">
    <label for="name">Full name</label>
    <input type="text" id="name" required maxlength="50">

    <label for="employee_id">Employee ID (3-20 letters, numbers or -)</label>
    <input type="text" id="employee_id" required pattern="[A-Za-z0-9][A-Za-z0-9\-]{2,19}" maxlength="20">

    <label for="pin">PIN (4 to 6 digits)</label>
    <input type="password" id="pin" required inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6">

    <label for="pin2">Confirm PIN</label>
    <input type="password" id="pin2" required inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6">

    <div class="camera-box" style="margin-top:15px;">
        <video id="video" autoplay muted playsinline></video>
        <canvas id="overlay"></canvas>
    </div>
    <p id="status">Fill in the form, then click the button.</p>

    <button class="btn" id="submitBtn" type="submit">Scan face &amp; register</button>
    <a class="btn btn-secondary" href="index.php">Back</a>
</form>

<script src="js/face.js"></script>
<script src="js/register.js"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>