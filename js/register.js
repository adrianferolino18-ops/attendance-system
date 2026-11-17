
(function () {
    'use strict';

    var form = document.getElementById('regForm');
    var video = document.getElementById('video');
    var overlay = document.getElementById('overlay');
    var statusText = document.getElementById('status');
    var message = document.getElementById('message');
    var submitBtn = document.getElementById('submitBtn');
    var stopLoop = null;

    var PHOTO_COUNT = 25;
    var MIN_PHOTOS = 15;    

    function setStatus(text) {
        statusText.textContent = text;
    }

    function showError(text) {
        message.textContent = text;
        message.className = 'message error';
        message.style.display = 'block';
    }

    function clearError() {
        message.style.display = 'none';
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function stopEverything() {
        if (stopLoop) { stopLoop(); stopLoop = null; }
        Face.stopCamera(video);
    }

    function finishWithError(text) {
        showError(text);
        setStatus('Registration was not completed.');
        stopEverything();
        submitBtn.disabled = false;
        submitBtn.textContent = 'Try again';
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        clearError();

        var name = document.getElementById('name').value.trim();
        var id = document.getElementById('employee_id').value.trim().toUpperCase();   // one ID format everywhere
        var pin = document.getElementById('pin').value;
        var pin2 = document.getElementById('pin2').value;

        if (!/^[A-Za-z][A-Za-z .'-]{1,49}$/.test(name)) {
            return showError('Name must be 2-50 letters.');
        }
        if (!/^[A-Z0-9][A-Z0-9\-]{2,19}$/.test(id)) {
            return showError('Employee ID must be 3-20 letters, numbers or -.');
        }
        if (!/^[0-9]{4,6}$/.test(pin)) {
            return showError('PIN must be 4 to 6 digits.');
        }
        if (pin !== pin2) {
            return showError('The two PINs do not match.');
        }

        submitBtn.disabled = true;

        try {
            setStatus('Starting the camera...');
            await Face.startCamera(video);
            stopLoop = Face.startLoop(video, overlay, null, { mirrored: true });   // live box + status text

            setStatus('Look at the camera and turn your head slightly...');
            var photos = await Face.captureFrames(video, PHOTO_COUNT, 300, function (taken, total, status) {
                if (status && status.ok) {
                    setStatus('Taking photos ' + taken + '/' + total + ' - turn your head slightly');
                } else {
                    setStatus('Photos ' + taken + '/' + total + ' - ' + ((status && status.message) || 'waiting for your face'));
                }
            });

            if (photos.length < MIN_PHOTOS) {
                return finishWithError('Could not take enough clear photos. Stay in the camera, in good light, and try again.');
            }

            setStatus('Saving...');
            var res = await fetch('api/register.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
                body: JSON.stringify({ name: name, employee_id: id, pin: pin, photos: photos })
            });

            var data;
            try { data = await res.json(); } catch (e) { data = null; }

            if (!data || !data.ok) {
                return finishWithError((data && (data.message || data.error)) || 'Registration failed. Please try again.');
            }

            stopEverything();
            setStatus('Registered! Going to the login page...');
            setTimeout(function () { window.location.href = data.redirect || 'login.php'; }, 1500);

        } catch (err) {
            finishWithError('Something went wrong: ' + err.message);
        }
    });
})();