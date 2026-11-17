<?php
// login.php - Step 1 of login: camera -> liveness -> face match -> show name

session_start();
require_once __DIR__ . '/includes/csv_helper.php';
require_once __DIR__ . '/includes/challenge.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = trim($_POST['user_id'] ?? '');

    if (!csrfCheck($_POST['csrf'] ?? '')) {
        $error = 'Invalid form. Please try again.';
    } elseif (!verifyChallenge($_POST['challenge_token'] ?? '')) {
        $error = 'Liveness check expired or invalid. Please try again.';
    } else {
        $found = false;
        foreach (readCSV(__DIR__ . '/data/users.csv') as $row) {
            if ($row['user_id'] === $userId) {
                $found = true;
                break;
            }
        }

        if ($found) {
            $_SESSION['pending_user_id'] = $userId;
            header('Location: pin.php');
            exit;
        }
        $error = 'User not found.';
    }
}

$challenge = generateChallenge();

$savedUsers = [];
foreach (readCSV(__DIR__ . '/data/users.csv') as $row) {
    $savedUsers[] = [
        'user_id'    => $row['user_id'],
        'name'       => $row['name'],
        'descriptor' => json_decode($row['face_descriptor'], true),
    ];
}

$pageTitle = 'Login';
require_once __DIR__ . '/includes/header.php';
?>

<h1>Login</h1>

<?php if ($error !== ''): ?>
    <div class="message error"><?php echo e($error); ?></div>
<?php endif; ?>

<div class="camera-box">
    <video id="video" autoplay muted playsinline></video>
    <canvas id="overlay"></canvas>
</div>
<p id="status">Click Start to begin.</p>
<button class="btn" id="startBtn" type="button">Start</button>
<a class="btn btn-secondary" href="index.php">Back</a>

<form id="matchForm" method="post" action="login.php">
    <input type="hidden" name="csrf" value="<?php echo e(csrfToken()); ?>">
    <input type="hidden" name="challenge_token" value="<?php echo e($challenge['token']); ?>">
    <input type="hidden" name="user_id" id="matchedUserId" value="">
</form>

<script src="js/face.js"></script>
<script src="js/liveness.js"></script>
<script>

var savedUsers = <?php echo json_encode($savedUsers); ?>;
var challengeType = <?php echo json_encode($challenge['type']); ?>;

var video = document.getElementById('video');
var overlay = document.getElementById('overlay');
var statusText = document.getElementById('status');
var startBtn = document.getElementById('startBtn');

function setStatus(text) {
    statusText.textContent = text;
}

async function startLogin() {
    startBtn.disabled = true;

    try {
        setStatus('Loading face models...');
        await loadModels();
        await startCamera(video);

        setStatus('Liveness check: please ' + (challengeType === 'blink' ? 'blink' : 'turn your head'));
        var isLive = await runLiveness(video, challengeType);
        if (!isLive) {
            setStatus('Liveness check failed. Please try again.');
            startBtn.disabled = false;
            return;
        }

        setStatus('Looking at your face...');
        var result = await getDescriptor(video, overlay);
        if (!result.ok) {
            setStatus(result.error);
            startBtn.disabled = false;
            return;
        }

        var match = matchFace(result.descriptor, savedUsers);
        if (!match) {
            setStatus('Face not recognized. Please try again.');
            startBtn.disabled = false;
            return;
        }

        setStatus('Hello, ' + match.name + '! Going to PIN...');
        document.getElementById('matchedUserId').value = match.user_id;
        setTimeout(function () {
            document.getElementById('matchForm').submit();
        }, 1500);

    } catch (err) {
        setStatus('Something went wrong: ' + err.message);
        startBtn.disabled = false;
    }
}

startBtn.addEventListener('click', startLogin);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>