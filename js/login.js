// js/login.js - Step 1 of login (Member 5's page logic).
// camera -> liveness (js/liveness.js, Member 4) -> one photo -> api/recognize.php -> pin.php

(function () {
    'use strict';

    var video = document.getElementById('video');
    var overlay = document.getElementById('overlay');
    var statusText = document.getElementById('status');
    var startBtn = document.getElementById('startBtn');
    var stopLoop = null;

    // problems we can simply retry a few times (the person is still getting ready)
    var retryCodes = ['no_face', 'too_small', 'too_dark', 'multiple_faces'];

    function setStatus(text) {
        statusText.textContent = text;
    }

    function sleep(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    async function recognize(photo) {
        var res = await fetch('api/recognize.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
            body: JSON.stringify({ image: photo })
        });
        try {
            return await res.json();
        } catch (e) {
            return { ok: false, code: 'error', message: 'The server sent an unreadable reply.' };
        }
    }

    function stopEverything() {
        if (stopLoop) { stopLoop(); stopLoop = null; }
        Face.stopCamera(video);
    }

    function fail(text) {
        setStatus(text);
        stopEverything();
        startBtn.disabled = false;
        startBtn.textContent = 'Try again';
    }

    async function startLogin() {
        startBtn.disabled = true;

        try {
            setStatus('Starting the camera...');
            await Face.startCamera(video);

            // The live face box (Member 3) is NOT started yet: during the blink / head-turn check the
            // browser is already busy finding your eyes, and the extra requests to Python made blinks get missed.
            setStatus('Loading the liveness check...');
            var live = await Liveness.runServerChallenge(video, {
                // a little more forgiving than the default (0.72 / 0.88), so a normal quick blink is counted
                config: { blink: { closedRatio: 0.8, openRatio: 0.85 } },
                onStatus: function (text) { setStatus(text); }
            });
            if (!live.passed) {
                return fail(live.error || 'Liveness check failed. Please try again.');
            }

            stopLoop = Face.startLoop(video, overlay, null, { mirrored: true });   // live face box (Member 3)

            // the liveness pass lasts about 30 seconds, so a few retries are fine
            var result = null;
            for (var attempt = 0; attempt < 5; attempt++) {
                setStatus('Look at the camera...');
                result = await recognize(Face.captureFrame(video));
                if (result.ok || retryCodes.indexOf(result.code) === -1) break;
                setStatus(result.message);
                await sleep(1000);
            }

            if (!result.ok) {
                return fail(result.message || 'Face check failed. Please try again.');
            }
            if (!result.matched) {
                return fail('Face not recognized. Please try again.');
            }

            setStatus('Hello, ' + result.name + '! Going to the PIN page...');
            stopEverything();
            setTimeout(function () { window.location.href = result.redirect || 'pin.php'; }, 1000);

        } catch (err) {
            fail('Something went wrong: ' + err.message);
        }
    }

    startBtn.addEventListener('click', startLogin);
})();