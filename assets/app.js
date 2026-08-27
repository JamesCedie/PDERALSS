function toggleSidebar() {
    const s = document.getElementById('sidebar');
    const m = document.getElementById('main');

    if (window.innerWidth <= 800) {
        s.classList.toggle('mobile-open');
    } else {
        s.classList.toggle('collapsed');
        m.classList.toggle('expanded');
    }
}

function openModal(id) {
    document.getElementById(id).classList.add('show');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}

function confirmAction(message) {
    return confirm(message);
}

/**
 * Login page: "Forgot Password" flow.
 * Looks up the username already typed in the login form, gets back a
 * masked email for the matching account, and opens the OTP modal.
 * Safe to call even on pages without a #otpModal / username field.
 */
function requestOtp() {
    const usernameField = document.querySelector('[name="username"]');
    const username = usernameField ? usernameField.value.trim() : '';

    if (!username) {
        alert('Please enter your username first.');
        return;
    }

    fetch('login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'lookup_username=1&username=' + encodeURIComponent(username),
    })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById('otpEmailDisplay').textContent = data.masked_email;
                closeModal('otpModal'); // in case it was already open, resets it
                openModal('otpModal');
                alert('An OTP has been sent to ' + data.masked_email);
            } else {
                alert(data.message || 'Something went wrong. Please try again.');
            }
        })
        .catch(() => alert('Something went wrong. Please try again.'));
}

document.addEventListener('click', function (e) {
    if (e.target.classList.contains('modal')) {
        e.target.classList.remove('show');
    }
});