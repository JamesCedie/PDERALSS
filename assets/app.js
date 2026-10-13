function toggleSidebar() {
    const s = document.getElementById('sidebar');
    const m = document.getElementById('main');
    const overlay = document.getElementById('sidebarOverlay');

    if (window.innerWidth <= 1100) {
        const isOpen = s.classList.toggle('sidebar-open');
        if (overlay) overlay.style.display = isOpen ? 'block' : 'none';
    } else {
        s.classList.toggle('collapsed');
        m.classList.toggle('expanded');
    }
}

// Close the sidebar when the overlay is tapped (all small screens)
document.addEventListener('DOMContentLoaded', function () {
    const overlay = document.getElementById('sidebarOverlay');
    if (overlay) {
        overlay.addEventListener('click', function () {
            document.getElementById('sidebar').classList.remove('sidebar-open');
            overlay.style.display = 'none';
        });
    }
});

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
    const forgotField = document.getElementById('forgotUsername');
    const loginField = document.querySelector('form [name="username"]');
    const username = (forgotField && forgotField.value.trim())
        ? forgotField.value.trim()
        : (loginField ? loginField.value.trim() : '');

    if (!username) {
        alert('Please enter your username first.');
        return;
    }

    const otpDisplay = document.getElementById('otpEmailDisplay');
    if (otpDisplay && otpDisplay.textContent.trim()) {
        alert('An OTP was already sent to ' + otpDisplay.textContent.trim());
        openModal('otpModal');
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
                const btn = document.getElementById('sendOtpBtn');
                if (btn) { btn.textContent = 'OTP sent'; btn.disabled = true; }
                setTimeout(function () { closeModal('otpModal'); openModal('otpModal'); }, 2000);
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
document.addEventListener('DOMContentLoaded', function () {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function (el) {
        setTimeout(function () {
            el.style.transition = 'opacity 0.5s ease';
            el.style.opacity = '0';
            setTimeout(function () {
                if (el.parentNode) el.parentNode.removeChild(el);
            }, 500);
        }, 10000);
    });
});
function showToast(message, type = 'info') {
    const container = document.getElementById('swToastContainer');
    if (!container) {
        // Keep non-social-worker pages using their normal browser behavior.
        return;
    }
    const toast = document.createElement('div');
    toast.className = 'sw-toast sw-toast-' + type;
    toast.setAttribute('role', 'status');
    toast.textContent = message;
    container.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('show'));
    window.setTimeout(() => {
        toast.classList.remove('show');
        window.setTimeout(() => toast.remove(), 180);
    }, 2800);
}

/**
 * MD pages: client-side filter for a table. Rows opt in with data-search="lowercase text".
 * An optional "no match" row (hidden by default) is shown when nothing matches.
 */
function mdBindSearch(inputId, tableId, noMatchId) {
    const box = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    if (!box || !table) return;
    const rows = table.querySelectorAll('tbody tr[data-search]');
    const none = noMatchId ? document.getElementById(noMatchId) : null;
    box.addEventListener('input', function () {
        const q = box.value.trim().toLowerCase();
        let shown = 0;
        rows.forEach(function (r) {
            const match = r.dataset.search.indexOf(q) !== -1;
            r.hidden = !match;
            if (match) shown++;
        });
        if (none) none.hidden = shown > 0 || rows.length === 0;
    });
}
