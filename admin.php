<?php
// admin.php - admin panel: see the registered users, delete a user, and see the attendance (login) records.
// Only the IDs in $adminIds can open it (log in the normal way: face + PIN). Register an account with the ID ADMIN.

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/users.php';
require_once __DIR__ . '/includes/attendance_data.php';
require_once __DIR__ . '/includes/python_client.php';
secureSessionStart();

date_default_timezone_set('Asia/Manila');

// must be fully logged in (face + PIN)
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// only admins may stay. KEEP THIS LIST THE SAME AS IN attendance.php. IDs are always saved in CAPITAL letters.
$adminIds = ['ADMIN'];
$myId = (string)($_SESSION['user_id'] ?? '');
if (!in_array(strtoupper($myId), $adminIds, true)) {
    header('Location: attendance.php');
    exit;
}

// ---------------------------------------------------------------- delete a user (form POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['error', 'Invalid form. Please try again.'];

    if (csrfValid((string)($_POST['csrf_token'] ?? ''))) {
        $target = trim((string)($_POST['user_id'] ?? ''));

        if (($_POST['action'] ?? '') !== 'delete_user') {
            $flash = ['error', 'Unknown action.'];
        } elseif (strtoupper($target) === strtoupper($myId)) {
            $flash = ['error', 'You cannot delete the account you are logged in with.'];
        } elseif (!preg_match('/^[A-Za-z0-9_-]{3,20}$/', $target) || !userExists($target)) {
            $flash = ['error', 'User not found.'];
        } else {
            deleteUser($target);                                       // 1. the row in users.csv
            if (!empty($_POST['delete_attendance'])) {
                deleteCSV(ATTENDANCE_CSV, 'user_id', $target);          // 2. (optional) their attendance rows
            }
            $py = (new PythonClient())->deleteUser($target);            // 3. the face photos (Member 3's Python service)
            if ($py['ok'] ?? false) {
                $flash = ['success', 'Deleted ' . $target . ' and the face photos.'];
            } else {
                $flash = ['error', 'Deleted ' . $target . ' from users.csv, but the face photos were NOT removed: '
                    . (string)($py['message'] ?? 'unknown error') . ' Start "py app.py" and delete the user again later,'
                    . ' or delete the folder data/faces/' . $target . ' by hand.'];
            }
        }
    }

    // redirect, so refreshing the page does not send the form again
    $_SESSION['admin_flash'] = $flash;
    header('Location: admin.php');
    exit;
}

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

// ---------------------------------------------------------------- data for the page
$users = getAllUsersPublic();

/** How many face photos Python saved for this user (0 = none, so the face part is out of sync). */
function countFacePhotos(string $userId): int
{
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{2,19}$/', $userId)) {
        return 0;
    }
    $files = glob(DATA_DIR . 'faces/' . $userId . '/*.png');
    return $files === false ? 0 : count($files);
}

// attendance records: one day (default today) or all days
$date = (string)($_GET['date'] ?? date('Y-m-d'));
$showAll = ($date === 'all');
if (!$showAll && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}
$records = array_values(array_filter(
    getAllAttendance(),
    fn($r) => $showAll || $r['date'] === $date
));
usort($records, fn($a, $b) => strcmp($b['date'] . $b['time_in'], $a['date'] . $a['time_in']));   // newest first

$pageTitle = 'Admin';
require_once __DIR__ . '/includes/header.php';
?>

<h1>Admin</h1>
<p><a class="btn btn-secondary" href="attendance.php">Back to attendance</a></p>

<?php if ($flash): ?>
    <div class="message <?php echo e($flash[0]); ?>"><?php echo e($flash[1]); ?></div>
<?php endif; ?>

<h2>Registered users (<?php echo count($users); ?>)</h2>
<table>
    <tr>
        <th>User ID</th>
        <th>Name</th>
        <th>Registered</th>
        <th>Face photos</th>
        <th></th>
    </tr>
    <?php foreach ($users as $u): ?>
        <?php $photos = countFacePhotos($u['user_id']); ?>
        <tr>
            <td><?php echo e($u['user_id']); ?></td>
            <td><?php echo e($u['name']); ?></td>
            <td><?php echo e($u['created_at']); ?></td>
            <td><?php echo $photos > 0 ? e((string)$photos) : '<strong>none</strong>'; ?></td>
            <td>
                <?php if (strtoupper($u['user_id']) === strtoupper($myId)): ?>
                    (you)
                <?php else: ?>
                    <form method="post" action="admin.php" data-name="<?php echo e($u['name']); ?>"
                          onsubmit="return confirm('Delete ' + this.dataset.name + ' and their face photos? This cannot be undone.');">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="user_id" value="<?php echo e($u['user_id']); ?>">
                        <label style="margin:0 0 6px;"><input type="checkbox" name="delete_attendance" value="1"> also delete attendance</label>
                        <button class="btn btn-secondary" type="submit" style="margin:0;">Delete</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (count($users) === 0): ?>
        <tr><td colspan="5">No users registered yet.</td></tr>
    <?php endif; ?>
</table>

<h2>Attendance records (<?php echo $showAll ? 'all days' : e($date); ?>)</h2>
<form method="get" action="admin.php">
    <input type="date" name="date" value="<?php echo $showAll ? '' : e($date); ?>" style="padding:8px;">
    <button class="btn" type="submit">Show this day</button>
    <a class="btn btn-secondary" href="admin.php?date=all">All days</a>
</form>
<table>
    <tr>
        <th>User ID</th>
        <th>Name</th>
        <th>Date</th>
        <th>Time In</th>
        <th>Time Out</th>
    </tr>
    <?php foreach ($records as $r): ?>
        <tr>
            <td><?php echo e($r['user_id']); ?></td>
            <td><?php echo e($r['name']); ?></td>
            <td><?php echo e($r['date']); ?></td>
            <td><?php echo e($r['time_in']); ?></td>
            <td><?php echo e($r['time_out'] !== '' ? $r['time_out'] : '-'); ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (count($records) === 0): ?>
        <tr><td colspan="5">No records.</td></tr>
    <?php endif; ?>
</table>

<?php require_once __DIR__ . '/includes/footer.php'; ?>