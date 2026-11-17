# Security test log (Member 4)

Tester: ____________  Date: ____________  Browser/camera: ____________  Lighting: ____________

**How to fill this in:** run each test, then write PASS (the system defended itself), FAIL (the attack
got through) or N/A in the Result column, and one line of notes (e.g. "passed after 2nd try", "EAR never
dropped below 0.2"). A FAIL is not a bad grade: reporting an honest weakness is the point of this log.

Run the site on `http://localhost` (the camera is blocked on plain http elsewhere), with a registered user.

## A. Spoofing attacks on the camera (liveness)

| # | Attack | How to try it | Expected | Result | Notes |
|---|---|---|---|---|---|
| A1 | Photo on a phone screen | Open the owner's photo on a phone, hold it to the camera, start login | Fails: no blink, times out | | |
| A2 | Printed photo | Print a clear face photo, hold it up (also try bending it slightly) | Fails: no blink, times out | | |
| A3 | Printed photo with eyes cut out | Cut eye holes, hold it in front of your own face and blink behind it | Probably PASSES the blink; should fail the head-turn or look wrong. Record what happens | | |
| A4 | Screenshot / image shown via a virtual camera (e.g. OBS) | Feed a still image as the webcam | Fails: no blink | | |
| A5 | Recorded video replay | Record the owner blinking and turning, play it on a phone to the camera | **Expected weak spot.** The challenge is random (blink + left OR right, random order), so a pre-recorded video only passes if it happens to match. Try 5 times, record how many pass | | /5 passed |
| A6 | Two faces in view | Stand next to a second person | Rejected with "More than one face" | | |
| A7 | No face / too dark | Cover the camera, then use a dark room | Never passes; shows a clear message | | |
| A8 | Wrong head direction | When asked to turn left, turn right | Does not count; keeps waiting, then times out | | |
| A9 | Real user, normal conditions | Owner does the challenge honestly | Passes within ~10 s. Note how many tries it took (usability matters too) | | tries: |
| A10 | Real user with glasses | Same as A9 | Record whether blinks are still detected | | |

## B. Server-side attacks (run in the browser console on the login page)

Handy snippet (paste once, then use `call(...)`):

```js
const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
const call = (url, body, token = csrf) => fetch(url, {method:'POST', credentials:'same-origin',
  headers:{'Content-Type':'application/json','X-CSRF-Token':token}, body:JSON.stringify(body)})
  .then(async r => ({status:r.status, ...(await r.json())}));
```

| # | Attack | How to try it | Expected | Result | Notes |
|---|---|---|---|---|---|
| B1 | Skip liveness, go straight to recognition | `call('api/recognize.php', {image:'x'})` on a fresh page | 403, `liveness_required` | | |
| B2 | Missing / wrong CSRF token | `call('api/challenge.php', {action:'new'}, 'wrong')` | 403 "Security token invalid" | | |
| B3 | Fake "I passed" immediately | `const c = await call('api/challenge.php',{action:'new'}); call('api/challenge.php',{action:'verify', token:c.token, result:{steps_done:c.steps.map(s=>s.type)}})` | Rejected: "finished too quickly" | | |
| B4 | Token replay | Do a real pass, then send the same verify call again | Second one rejected (token is one-time) | | |
| B5 | Wrong token | verify with `token:'0'.repeat(64)` | Rejected, and the real challenge is now used up | | |
| B6 | Expired challenge | Get a challenge, wait 65 s, then verify | Rejected: "expired" | | |
| B7 | Reuse a liveness pass | After one successful recognition, call `api/recognize.php` again without a new challenge | 403 `liveness_required` | | |
| B8 | Direct access to data files | Open `http://localhost/<project>/data/users.csv` and `/data/lockouts.json` in the browser | 403 Forbidden. **Fails on `php -S`** (it ignores .htaccess): use XAMPP/Apache | | |
| B9 | GET instead of POST | Open `api/verify_pin.php` in the address bar | 405 "POST only" | | |
| B10 | Script injection in a text field | Register with the name `<script>alert(1)</script>` | Rejected by name validation, or shown as plain text, never executed | | |

## C. PIN brute force and sessions

| # | Test | How to try it | Expected | Result | Notes |
|---|---|---|---|---|---|
| C1 | 3 wrong PINs lock the account | Enter 3 wrong PINs | 3rd shows "Locked for 90 seconds"; sent back to home | | |
| C2 | Correct PIN while locked | Pass face + liveness again, enter the CORRECT PIN during the lock | Still refused, with seconds remaining | | |
| C3 | Lock survives clearing cookies | After C1, clear cookies / use a private window, do face + liveness again | Still locked (lock is stored on the server) | | |
| C4 | Lock ends | Wait 90 s, try again | Works; attempts counter back to 3 | | |
| C5 | Correct PIN resets the counter | 2 wrong PINs, then the right one, then 2 wrong again | Not locked after the second pair | | |
| C6 | Session ID changes | DevTools > Application > Cookies: note `PHPSESSID` before face match, after face match, after PIN | Three different values | | |
| C7 | Cookie flags | Same cookie | HttpOnly = yes, SameSite = Strict | | |
| C8 | PIN page without a face match | Open `pin.php` directly in a fresh window | Redirected to login, no PIN form for any user | | |
| C9 | Pending login expires | Face match, then wait 2+ minutes before typing the PIN | "Session expired", back to login | | |
| C10 | PIN stored as hash | Open `data/users.csv` (from the file system) | `pin_hash` starts with `$2y$`; no plain PIN anywhere | | |

## D. Known limits (copy into the report)

- **Liveness runs in the browser.** The server checks the one-time token, expiry, step order and a minimum
  time, but it cannot see the camera. Someone who rewrites the page's JavaScript could claim a pass. The
  random challenge stops simple photo/screenshot attacks and makes video replay harder, not impossible.
- A good **video replay** or a deepfake can defeat blink + head-turn checks. Stronger defences (depth
  sensors, server-side analysis of several frames) are out of scope for this project.
- **PIN lockout is per employee**, so someone who passes the face + liveness step for a victim and fails
  the PIN three times can lock that victim out for 90 s. Reaching the PIN step already requires a matched
  face, which limits this.
- Face matching has **no per-IP rate limit**; each attempt costs a fresh liveness challenge, which slows it.
