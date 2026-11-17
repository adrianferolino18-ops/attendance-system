<?php
declare(strict_types=1);
/**
 * security.php - Member 4: session safety, CSRF, input cleaning, PIN lockout.
 *
 * Every page or API file that touches the session should do:
 *     require_once __DIR__ . '/includes/security.php';
 *     secureSessionStart();
 *
 * Session keys used by the login flow (Member 5: read these, don't invent new ones):
 *     pending_user_id  - face matched, PIN still needed   (set by api/recognize.php)
 *     user_id/user_name - fully logged in                  (set by completeLogin())
 */
require_once __DIR__ . '/config.php';

// ---- Settings (change here, or define() them in config.php first) -----------
if (!defined('PIN_MAX_ATTEMPTS'))     define('PIN_MAX_ATTEMPTS', 3);     // wrong PINs before lock
if (!defined('PIN_LOCK_SECONDS'))     define('PIN_LOCK_SECONDS', 90);    // lock length (1-2 min)
if (!defined('PIN_FAIL_WINDOW'))      define('PIN_FAIL_WINDOW', 900);    // old typos are forgiven after 15 min
if (!defined('SESSION_IDLE_SECONDS')) define('SESSION_IDLE_SECONDS', 900);
if (!defined('PENDING_TTL_SECONDS'))  define('PENDING_TTL_SECONDS', 120); // time allowed to type the PIN
if (!defined('LOCKOUT_FILE'))         define('LOCKOUT_FILE', DATA_DIR . 'lockouts.json');

// ---- Session ----------------------------------------------------------------

function secureSessionStart(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');   // refuse session IDs the server did not create
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,       // JavaScript cannot read the cookie
        'samesite' => 'Strict',   // cookie is not sent on cross-site requests
    ]);
    session_start();

    $now = time();
    if (isset($_SESSION['last_active']) && $now - (int)$_SESSION['last_active'] > SESSION_IDLE_SECONDS) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_active'] = $now;
}

/** New session ID, same data. Call whenever privilege changes (face matched, PIN ok). */
function regenerateSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
}

function setPendingUser(string $userId): void {
    regenerateSession();
    $_SESSION['pending_user_id'] = $userId;
    $_SESSION['pending_at']      = time();
}

/** The user whose face matched and who still has to enter a PIN, or null. */
function getPendingUser(): ?string {
    $id = $_SESSION['pending_user_id'] ?? null;
    $at = (int)($_SESSION['pending_at'] ?? 0);
    if (!is_string($id) || time() - $at > PENDING_TTL_SECONDS) {
        clearPending();
        return null;
    }
    return $id;
}

function clearPending(): void {
    unset($_SESSION['pending_user_id'], $_SESSION['pending_at']);
}

function completeLogin(string $userId, string $name): void {
    regenerateSession();
    clearPending();
    unset($_SESSION['csrf_token'], $_SESSION['liveness_ok_until'], $_SESSION['liveness_challenge']);
    $_SESSION['user_id']      = $userId;
    $_SESSION['user_name']    = $name;
    $_SESSION['logged_in_at'] = time();
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && is_string($_SESSION['user_id']);
}

function logoutUser(): void {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}

// ---- CSRF -------------------------------------------------------------------

function csrfToken(): string {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Put this inside <head>. pin.php's JavaScript reads it from here. */
function csrfMetaTag(): string {
    return '<meta name="csrf-token" content="' . escapeHtml(csrfToken()) . '">';
}

/** Put this inside any normal <form>. */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . escapeHtml(csrfToken()) . '">';
}

function csrfValid(string $sent): bool {
    $real = $_SESSION['csrf_token'] ?? '';
    return is_string($real) && $real !== '' && $sent !== '' && hash_equals($real, $sent);
}

/** For API files: stops with 403 unless the request carries the right token. */
function requireCsrf(): void {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (!is_string($sent) || !csrfValid($sent)) {
        jsonReply(403, ['ok' => false, 'error' => 'Security token invalid. Please reload the page.']);
    }
}

// ---- Input cleaning / output ------------------------------------------------

/** Use on EVERYTHING printed into HTML. */
function escapeHtml(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Trims, strips control characters, limits length. (Escaping is separate: escapeHtml.) */
function cleanString(string $s, int $maxLength = 100): string {
    $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s) ?? '';
    return mb_substr(trim($s), 0, $maxLength);
}

function readJsonBody(int $maxBytes = 100000): array {
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes);
    $data = json_decode($raw === false ? '' : $raw, true);
    return is_array($data) ? $data : [];
}

function jsonReply(int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($body);
    exit;
}

/** For API files: POST only. */
function requirePost(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        jsonReply(405, ['ok' => false, 'error' => 'POST only.']);
    }
}

// ---- PIN lockout (stored in data/lockouts.json, flock-protected) -------------
// Stored on the server per employee ID, so clearing cookies does not reset it.

function lockoutMutate(callable $fn) {
    $dir = dirname(LOCKOUT_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $fh = fopen(LOCKOUT_FILE, 'c+');
    if (!$fh) throw new RuntimeException('Cannot open lockout file.');
    flock($fh, LOCK_EX);
    $raw  = stream_get_contents($fh);
    $data = json_decode(($raw === false || $raw === '') ? '[]' : $raw, true);
    if (!is_array($data)) $data = [];
    $result = $fn($data);                 // $fn may change $data (passed by reference)
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $result;
}

function lockoutNormalise(array $rec, int $now): array {
    $rec += ['fails' => 0, 'locked_until' => 0, 'last_fail' => 0];
    if ($rec['locked_until'] > 0 && $rec['locked_until'] <= $now) {   // lock finished
        $rec['fails'] = 0;
        $rec['locked_until'] = 0;
    }
    if ($rec['fails'] > 0 && $now - $rec['last_fail'] > PIN_FAIL_WINDOW) {   // old typos forgiven
        $rec['fails'] = 0;
    }
    return $rec;
}

function lockoutStore(array &$all, string $userId, array $rec): void {
    if ($rec['fails'] === 0 && $rec['locked_until'] === 0) unset($all[$userId]);
    else $all[$userId] = $rec;
}

function lockoutView(array $rec, int $now): array {
    return [
        'locked'        => $rec['locked_until'] > $now,
        'seconds_left'  => max(0, $rec['locked_until'] - $now),
        'attempts_left' => max(0, PIN_MAX_ATTEMPTS - $rec['fails']),
    ];
}

/** @return array ['locked' => bool, 'seconds_left' => int, 'attempts_left' => int] */
function pinLockStatus(string $userId, ?int $now = null): array {
    $now = $now ?? time();
    return lockoutMutate(function (array &$all) use ($userId, $now) {
        $rec = lockoutNormalise($all[$userId] ?? [], $now);
        lockoutStore($all, $userId, $rec);
        return lockoutView($rec, $now);
    });
}

/** Call after a WRONG pin. Returns the new status (locked = true on the 3rd miss). */
function pinRecordFailure(string $userId, ?int $now = null): array {
    $now = $now ?? time();
    return lockoutMutate(function (array &$all) use ($userId, $now) {
        $rec = lockoutNormalise($all[$userId] ?? [], $now);
        if ($rec['locked_until'] <= $now) {
            $rec['fails']++;
            $rec['last_fail'] = $now;
            if ($rec['fails'] >= PIN_MAX_ATTEMPTS) {
                $rec['locked_until'] = $now + PIN_LOCK_SECONDS;
                $rec['fails'] = 0;
            }
        }
        lockoutStore($all, $userId, $rec);
        return lockoutView($rec, $now);
    });
}

/** Call after a CORRECT pin. */
function pinRecordSuccess(string $userId): void {
    lockoutMutate(function (array &$all) use ($userId) {
        unset($all[$userId]);
    });
}
