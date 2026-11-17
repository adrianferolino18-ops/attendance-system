<?php
declare(strict_types=1);
/**
 * challenge.php - Member 4: server side of the liveness check.
 *
 * Flow:
 *   1. createChallenge()  -> random steps (blink + head turn, random order/direction)
 *                            and a one-time token, saved in the session with an expiry.
 *   2. The browser (js/liveness.js) performs the steps and sends the token back.
 *   3. verifyChallenge()  -> checks token, expiry, step list, and minimum time.
 *                            On success the session is "liveness OK" for a short time.
 *   4. api/recognize.php  -> only runs face recognition while livenessIsActive().
 *
 * Needs secureSessionStart() to have been called (uses $_SESSION).
 */
require_once __DIR__ . '/security.php';

if (!defined('CHALLENGE_TTL_SECONDS'))  define('CHALLENGE_TTL_SECONDS', 60);   // time to do the steps
if (!defined('CHALLENGE_MIN_SECONDS'))  define('CHALLENGE_MIN_SECONDS', 2.0);  // faster than this = a script, not a person
if (!defined('LIVENESS_VALID_SECONDS')) define('LIVENESS_VALID_SECONDS', 30);  // how long a pass can be used

/** @return array ['token' => string, 'steps' => array, 'expires_in' => int] (safe to send to the browser) */
function createChallenge(): array {
    $turn  = random_int(0, 1) === 0 ? 'turn_left' : 'turn_right';
    $steps = [['type' => 'blink', 'count' => 2], ['type' => $turn]];
    if (random_int(0, 1) === 1) $steps = array_reverse($steps);

    $token = bin2hex(random_bytes(32));
    $now   = microtime(true);
    $_SESSION['liveness_challenge'] = [
        'token_hash' => hash('sha256', $token),   // the raw token is never stored
        'steps'      => $steps,
        'issued_at'  => $now,
        'expires_at' => $now + CHALLENGE_TTL_SECONDS,
    ];
    unset($_SESSION['liveness_ok_until']);        // a new challenge cancels any old pass

    return ['token' => $token, 'steps' => $steps, 'expires_in' => CHALLENGE_TTL_SECONDS];
}

/**
 * @param string $token  token the browser received from createChallenge()
 * @param array  $result what the browser reports: ['steps_done' => ['blink', 'turn_left']]
 * @return array ['ok' => bool, 'error' => string]
 */
function verifyChallenge(string $token, array $result): array {
    $c = $_SESSION['liveness_challenge'] ?? null;
    unset($_SESSION['liveness_challenge']);       // ONE-TIME: every attempt uses the challenge up

    if (!is_array($c)) {
        return ['ok' => false, 'error' => 'No active liveness check. Please start again.'];
    }
    $now = microtime(true);
    if ($now > $c['expires_at']) {
        return ['ok' => false, 'error' => 'The liveness check expired. Please try again.'];
    }
    if (!hash_equals($c['token_hash'], hash('sha256', $token))) {
        return ['ok' => false, 'error' => 'Invalid liveness token.'];
    }
    $expected = array_map(fn($s) => $s['type'], $c['steps']);
    $done     = $result['steps_done'] ?? null;
    if (!is_array($done) || array_values($done) !== $expected) {
        return ['ok' => false, 'error' => 'Liveness steps were not completed.'];
    }
    if ($now - $c['issued_at'] < CHALLENGE_MIN_SECONDS) {
        return ['ok' => false, 'error' => 'Liveness check finished too quickly.'];
    }

    $_SESSION['liveness_ok_until'] = time() + LIVENESS_VALID_SECONDS;
    return ['ok' => true, 'error' => ''];
}

/** True while this session has a valid, unused liveness pass. Does not use it up. */
function livenessIsActive(): bool {
    return isset($_SESSION['liveness_ok_until']) && (int)$_SESSION['liveness_ok_until'] >= time();
}

/** Uses up the pass (call once recognition has given a real yes/no answer). */
function consumeLiveness(): void {
    unset($_SESSION['liveness_ok_until']);
}
