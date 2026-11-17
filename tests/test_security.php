<?php
/** Run from the project folder:  php tests/test_security.php
 *  Uses a temporary folder, so your real data files are never touched. */
$tmp = sys_get_temp_dir() . '/att_sec_test_' . uniqid();
putenv("ATT_DATA_DIR=$tmp");
require_once __DIR__ . '/../includes/challenge.php';   // also loads security.php

$pass = 0; $fail = 0;
function check(string $label, bool $cond) {
    global $pass, $fail;
    echo ($cond ? "PASS" : "FAIL") . "  $label\n";
    $cond ? $pass++ : $fail++;
}

$_SESSION = [];   // pretend session (no real cookies in a CLI test)

// ---- Challenge ----------------------------------------------------------------
$c = createChallenge();
check('challenge has token, 2 steps, expiry', strlen($c['token']) === 64 && count($c['steps']) === 2 && $c['expires_in'] > 0);
check('challenge has one blink and one head turn',
    count(array_filter($c['steps'], fn($s) => $s['type'] === 'blink')) === 1
    && count(array_filter($c['steps'], fn($s) => strpos($s['type'], 'turn_') === 0)) === 1);
check('raw token is not stored in the session', strpos(json_encode($_SESSION), $c['token']) === false);

$types = array_map(fn($s) => $s['type'], $c['steps']);
$_SESSION['liveness_challenge']['issued_at'] -= 5;            // pretend 5 seconds passed
$r = verifyChallenge($c['token'], ['steps_done' => $types]);
check('correct token + steps accepted', $r['ok'] === true);
check('liveness active after pass', livenessIsActive());
consumeLiveness();
check('liveness pass is single use', !livenessIsActive());

$r = verifyChallenge($c['token'], ['steps_done' => $types]);
check('replaying the same token is rejected', $r['ok'] === false);

$c = createChallenge(); $types = array_map(fn($s) => $s['type'], $c['steps']);
$_SESSION['liveness_challenge']['issued_at'] -= 5;
check('wrong token rejected', verifyChallenge(str_repeat('a', 64), ['steps_done' => $types])['ok'] === false);
check('failed attempt uses the challenge up (no second guess)', verifyChallenge($c['token'], ['steps_done' => $types])['ok'] === false);

$c = createChallenge();
$_SESSION['liveness_challenge']['issued_at'] -= 5;
check('missing steps rejected', verifyChallenge($c['token'], ['steps_done' => []])['ok'] === false);

$c = createChallenge(); $types = array_map(fn($s) => $s['type'], $c['steps']);
$_SESSION['liveness_challenge']['issued_at'] -= 5;
check('steps in wrong order rejected', verifyChallenge($c['token'], ['steps_done' => array_reverse($types)])['ok'] === false);

$c = createChallenge(); $types = array_map(fn($s) => $s['type'], $c['steps']);
check('instant answer (script, not a person) rejected', verifyChallenge($c['token'], ['steps_done' => $types])['ok'] === false);

$c = createChallenge(); $types = array_map(fn($s) => $s['type'], $c['steps']);
$_SESSION['liveness_challenge']['issued_at']  -= 120;
$_SESSION['liveness_challenge']['expires_at'] -= 120;
check('expired challenge rejected', verifyChallenge($c['token'], ['steps_done' => $types])['ok'] === false);

check('no challenge at all rejected', verifyChallenge('x', ['steps_done' => []])['ok'] === false);

$_SESSION['liveness_ok_until'] = time() - 1;
check('old liveness pass has expired', !livenessIsActive());

// ---- CSRF ---------------------------------------------------------------------
$_SESSION = [];
$t = csrfToken();
check('csrf token is 64 hex chars', (bool)preg_match('/^[a-f0-9]{64}$/', $t));
check('csrf token is stable within a session', csrfToken() === $t);
check('correct csrf token accepted', csrfValid($t));
check('wrong csrf token rejected', !csrfValid(str_repeat('0', 64)));
check('empty csrf token rejected', !csrfValid(''));
check('meta tag contains token', strpos(csrfMetaTag(), $t) !== false);

// ---- Sanitizing -----------------------------------------------------------------
check('escapeHtml blocks script tags', escapeHtml('<script>alert("x")</script>') === '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;');
check('escapeHtml escapes single quotes', strpos(escapeHtml("a'b"), "'") === false);
check('cleanString strips control chars and trims', cleanString("  Juan\x00\x07 Cruz  ") === 'Juan Cruz');
check('cleanString limits length', mb_strlen(cleanString(str_repeat('a', 500), 50)) === 50);

// ---- PIN lockout ------------------------------------------------------------------
$t0 = 1_000_000;
$s = pinLockStatus('EMP001', $t0);
check('fresh user is not locked, 3 attempts left', !$s['locked'] && $s['attempts_left'] === 3);
$s = pinRecordFailure('EMP001', $t0);
check('1st wrong PIN: 2 attempts left', !$s['locked'] && $s['attempts_left'] === 2);
$s = pinRecordFailure('EMP001', $t0 + 1);
check('2nd wrong PIN: 1 attempt left', !$s['locked'] && $s['attempts_left'] === 1);
$s = pinRecordFailure('EMP001', $t0 + 2);
check('3rd wrong PIN locks the account', $s['locked'] && $s['seconds_left'] === PIN_LOCK_SECONDS);
check('still locked 30 seconds later', pinLockStatus('EMP001', $t0 + 32)['locked']);
check('another user is NOT locked', !pinLockStatus('EMP002', $t0 + 5)['locked']);
$s = pinRecordFailure('EMP001', $t0 + 10);
check('failing while locked does not extend the lock', $s['seconds_left'] === PIN_LOCK_SECONDS - 8);
check('unlocked after the lock time', !pinLockStatus('EMP001', $t0 + 2 + PIN_LOCK_SECONDS + 1)['locked']);
check('attempts reset after lock ends', pinLockStatus('EMP001', $t0 + 2 + PIN_LOCK_SECONDS + 1)['attempts_left'] === 3);

pinRecordFailure('EMP003', $t0); pinRecordFailure('EMP003', $t0 + 1);
pinRecordSuccess('EMP003');
check('correct PIN clears earlier misses', pinLockStatus('EMP003', $t0 + 2)['attempts_left'] === 3);

pinRecordFailure('EMP004', $t0);
check('old typo is forgiven after the window', pinLockStatus('EMP004', $t0 + PIN_FAIL_WINDOW + 5)['attempts_left'] === 3);
check('lockout file exists in the data folder', file_exists(LOCKOUT_FILE));

// ---- Session helpers ------------------------------------------------------------------
$_SESSION = [];
setPendingUser('EMP001');
check('pending user is readable', getPendingUser() === 'EMP001');
$_SESSION['pending_at'] = time() - PENDING_TTL_SECONDS - 5;
check('pending user expires', getPendingUser() === null);
setPendingUser('EMP001');
clearPending();
check('clearPending works', getPendingUser() === null);
$_SESSION['csrf_token'] = 'old';
completeLogin('EMP001', 'Juan Dela Cruz');
check('completeLogin sets the user', isLoggedIn() && $_SESSION['user_name'] === 'Juan Dela Cruz');
check('completeLogin clears pending and rotates the CSRF token', getPendingUser() === null && !isset($_SESSION['csrf_token']));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
