
(function () {
    'use strict';

    var form = document.getElementById('pinForm');
    var pinInput = document.getElementById('pin');
    var button = document.getElementById('pinBtn');
    var message = document.getElementById('message');

    function show(text, kind) {
        message.textContent = text;
        message.className = 'message ' + kind;
        message.style.display = 'block';
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        var pin = pinInput.value.trim();
        if (!/^[0-9]{4,6}$/.test(pin)) {
            show('PIN must be 4 to 6 digits.', 'error');
            return;
        }

        button.disabled = true;
        try {
            var res = await fetch('api/verify_pin.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
                body: JSON.stringify({ pin: pin })
            });
            var data = await res.json();

            if (data.ok) {
                window.location.href = data.redirect || 'attendance.php';
                return;
            }

            show(data.error || 'Something went wrong.', 'error');
            pinInput.value = '';
            if (data.redirect) {
      
                setTimeout(function () { window.location.href = data.redirect; }, 2500);
                return;
            }
        } catch (err) {
            show('Could not reach the server. Please try again.', 'error');
        }
        button.disabled = false;
    });
})();