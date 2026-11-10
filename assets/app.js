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
 * OTP countdown. Shows "Expires in 0:45" and ticks down once a second.
 * When it reaches zero the code is treated as dead: the `lock` elements are
 * disabled and the `resend` button is revealed.
 * Uses a wall-clock deadline so it stays accurate in background tabs.
 */
function createOtpCountdown(opts) {
    let deadline = 0, tick = null;
    const api = { active: false };
    const fmt = (s) => Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');

    function render() {
        const left = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        opts.timer.innerHTML = 'Expires in <b>' + fmt(left) + '</b>';
        opts.timer.classList.toggle('is-low', left <= 10);
        if (left <= 0) api.expire();
    }
    function lock(on) { (opts.lock || []).forEach(el => { if (el) el.disabled = on; }); }

    api.start = function (seconds) {
        clearInterval(tick);
        deadline = Date.now() + (seconds || 45) * 1000;
        api.active = true;
        opts.timer.hidden = false;
        opts.timer.classList.remove('is-expired');
        opts.resend.hidden = true;
        opts.resend.disabled = false;
        lock(false);
        render();
        tick = setInterval(render, 250);
    };
    api.expire = function () {
        clearInterval(tick);
        api.active = false;
        opts.timer.innerHTML = 'Code expired';
        opts.timer.classList.remove('is-low');
        opts.timer.classList.add('is-expired');
        opts.resend.hidden = false;
        lock(true);
        if (opts.onExpire) opts.onExpire();
    };
    api.stop = function (keepVisible) {
        clearInterval(tick);
        api.active = false;
        opts.resend.hidden = true;
        if (!keepVisible) opts.timer.classList.remove('is-low', 'is-expired');
    };
    return api;
}

/**
 * Login page: "Forgot Password" flow.
 * Looks up the username already typed in the login form, gets back a
 * masked email for the matching account, and opens the OTP modal.
 * Safe to call even on pages without a #otpModal / username field.
 */
let otpCountdown = null;
function getOtpCountdown() {
    if (!otpCountdown) {
        otpCountdown = createOtpCountdown({
            timer: document.getElementById('otpTimer'),
            resend: document.getElementById('otpResendBtn'),
            lock: [document.getElementById('otpCode'), document.getElementById('verifyOtpBtn')],
        });
    }
    return otpCountdown;
}
function otpExpireNow() { getOtpCountdown().expire(); }

function otpUsername() {
    const forgotField = document.getElementById('forgotUsername');
    const loginField = document.querySelector('form [name="username"]');
    return (forgotField && forgotField.value.trim())
        ? forgotField.value.trim()
        : (loginField ? loginField.value.trim() : '');
}

/** Asks the server for a fresh code and (re)starts the countdown. `onDone` runs on success. */
function sendOtpRequest(onDone) {
    const username = otpUsername();
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
                document.getElementById('otpCode').value = '';
                getOtpCountdown().start(data.expires_in);
                onDone && onDone();
            } else {
                alert(data.message || 'Something went wrong. Please try again.');
            }
        })
        .catch(() => alert('Something went wrong. Please try again.'));
}

function requestOtp() {
    // A still-valid code was already sent: just reopen the modal.
    if (otpCountdown && otpCountdown.active) {
        openModal('otpModal');
        return;
    }
    const btn = document.getElementById('sendOtpBtn');
    sendOtpRequest(function () {
        if (btn) { btn.textContent = 'OTP sent'; btn.disabled = true; }
        setTimeout(function () {
            closeModal('forgotPasswordModal');
            openModal('otpModal');
            const c = document.getElementById('otpCode'); if (c) c.focus();
        }, 800);
    });
}

function resendOtp() {
    const btn = document.getElementById('otpResendBtn');
    btn.disabled = true;
    sendOtpRequest(function () {
        const c = document.getElementById('otpCode'); if (c) c.focus();
    });
    setTimeout(function () { btn.disabled = false; }, 1500);
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


/**
 * Theme toggle (next to Logout). Dark is the default; "light" is stored only
 * when the user picks it. The initial theme is applied by an inline script in
 * <head> before first paint, so this only wires the button and persistence.
 */
function currentTheme() {
    return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
}
function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    try { localStorage.setItem('theme', theme); } catch (e) {}
    var btn = document.getElementById('themeToggle');
    if (btn) {
        btn.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
        btn.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
    }
    document.dispatchEvent(new CustomEvent('themechange', { detail: { theme: theme } }));
}
document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('themeToggle');
    if (!btn) return;
    var t = currentTheme();
    btn.setAttribute('aria-label', t === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
    btn.setAttribute('aria-pressed', t === 'dark' ? 'true' : 'false');
    btn.addEventListener('click', function () {
        applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
    });
});

// Date limiter: no date field may be set later than today (Philippine time).
// Fields that already declare a max, or opt out with data-allow-future (e.g. vehicle bookings), are left alone.
document.addEventListener('DOMContentLoaded', function () {
    var today;
    try { today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(new Date()); } catch (e) { today = new Date().toISOString().slice(0, 10); }
    document.querySelectorAll('input[type="date"]').forEach(function (el) {
        if (el.hasAttribute('data-allow-future') || el.hasAttribute('max')) return;
        el.setAttribute('max', today);
    });
});


/* ── Household forms: integer-only fields, age-linked date of birth, count limiters ── */
(function () {
    function pad(n) { return String(n).padStart(2, '0'); }
    function fmt(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

    // Valid birth dates for someone who is exactly `age` years old today: [earliest, latest].
    function dobRange(age) {
        var t = new Date();
        var latest   = new Date(t.getFullYear() - age,     t.getMonth(), t.getDate());
        var earliest = new Date(t.getFullYear() - age - 1, t.getMonth(), t.getDate() + 1);
        return [fmt(earliest), fmt(latest)];
    }
    function num(el) { var n = parseInt(el.value, 10); return isNaN(n) ? null : n; }

    // Whole numbers only: no decimals, negatives, exponent, and never above the field's max.
    function bindInteger(el) {
        function clean() {
            var digits = String(el.value).replace(/\D/g, '');
            if (el.value !== '' && digits === '') { el.value = ''; return; }
            if (digits === '') return;
            var n = parseInt(digits, 10), max = el.getAttribute('max');
            if (max !== null && max !== '' && n > parseInt(max, 10)) n = parseInt(max, 10);
            if (String(n) !== el.value) el.value = String(n);
        }
        el.addEventListener('keydown', function (e) {
            if (['e', 'E', '+', '-', '.', ','].indexOf(e.key) !== -1) e.preventDefault();
        });
        el.addEventListener('paste', function (e) {
            e.preventDefault();
            var t = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
            el.value = t; clean(); el.dispatchEvent(new Event('input', { bubbles: true }));
        });
        el.addEventListener('wheel', function () { if (document.activeElement === el) el.blur(); }, { passive: true });
        el.addEventListener('input', clean);
    }

    // Date of birth can only be picked within the range that matches the age typed.
    function bindDob(form, initial) {
        var age = form.querySelector('[name="age"]'), dob = form.querySelector('[name="date_of_birth"]');
        if (!age || !dob) return;
        function sync(clear) {
            var a = num(age);
            if (a === null) { dob.disabled = true; dob.value = ''; dob.removeAttribute('min'); return; }
            var r = dobRange(a);
            dob.disabled = false; dob.min = r[0]; dob.max = r[1];
            if (clear && dob.value && (dob.value < r[0] || dob.value > r[1])) dob.value = '';
        }
        age.addEventListener('input', function () { sync(true); });
        sync(false);
    }

    // Step 1: PWD / senior counts can never exceed the total family members.
    function bindHead(form) {
        var age = form.elements['age'], total = form.elements['total_family_members'],
            pwd = form.elements['pwd_count'], sen = form.elements['senior_citizens'];
        var maxM = parseInt(form.dataset.maxMembers, 10) || 15, seniorAge = parseInt(form.dataset.seniorAge, 10) || 60;
        function sync() {
            var t = num(total), cap = (t === null || t < 1) ? maxM : Math.min(t, maxM);
            [pwd, sen].forEach(function (el) {
                el.max = cap;
                var v = num(el); if (v !== null && v > cap) el.value = cap;
            });
            var a = num(age), headSenior = a !== null && a >= seniorAge;
            sen.min = headSenior ? 1 : 0;
            sen.setCustomValidity(headSenior && (num(sen) || 0) < 1
                ? 'The household head is ' + seniorAge + '+, so at least 1 senior citizen is required.' : '');
            total.setCustomValidity(t === null || t < 1 ? 'Total family members must be at least 1.' : '');
        }
        ['age', 'total_family_members', 'pwd_count', 'senior_citizens'].forEach(function (n) {
            form.elements[n].addEventListener('input', sync);
        });
        sync();
    }

    // Step 2: Save stays disabled until every member field is filled and the numbers are consistent.
    function bindMembers(form) {
        var btn = document.getElementById('hhSaveBtn'), msg = document.getElementById('hhMemberMsg');
        var headAge = parseInt(form.dataset.headAge, 10) || 0, seniors = parseInt(form.dataset.seniors, 10) || 0,
            seniorAge = parseInt(form.dataset.seniorAge, 10) || 60;
        var fields = form.querySelectorAll('input:not([type=hidden]), select');
        function check() {
            var empty = 0;
            fields.forEach(function (el) {
                if (el.required && (el.value === '' || (el.pattern && !el.checkValidity()))) empty++;
            });
            var invalid = !form.checkValidity();
            var found = headAge >= seniorAge ? 1 : 0;
            form.querySelectorAll('[name$="_age"]').forEach(function (a) { var v = num(a); if (v !== null && v >= seniorAge) found++; });
            var text = '', isErr = false;
            if (found > seniors) {
                text = found + ' people in this household are ' + seniorAge + '+ but Senior Citizens in Step 1 is ' + seniors + '. Go back to Step 1 to correct it.';
                isErr = true;
            } else if (empty > 0 || invalid) {
                text = 'Fill in all the information for every member to enable Save.';
            }
            msg.textContent = text; msg.classList.toggle('is-error', isErr);
            btn.disabled = invalid || found > seniors;
        }
        form.addEventListener('input', check);
        form.addEventListener('change', check);
        form.addEventListener('submit', function (e) { check(); if (btn.disabled) e.preventDefault(); });
        check();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('input[data-int]').forEach(bindInteger);
        document.querySelectorAll('form[data-hh-head]').forEach(function (f) { bindDob(f); bindHead(f); });
        document.querySelectorAll('form[data-hh-dob]').forEach(function (f) { bindDob(f); });
        document.querySelectorAll('form[data-hh-members]').forEach(bindMembers);
    });
})();

/**
 * Stat count-up.
 * Whole-number stat values (e.g. "1,284" or "62%") count up from 0 the first
 * time they scroll into view. Skipped when the user prefers reduced motion,
 * and for the dashboard's live evacuee panel (.dash-live) which refreshes
 * itself every few seconds and must never re-animate.
 */
(function () {
    var SELECTOR = [
        '.stat-value', '.vr-card-value', '.rg-stat-value', '.rg-brgy-value',
        '.hh-folder-count strong', '.sw-breakdown-item strong', '.dash-tile strong'
    ].join(',');
    var NUMBER = /^(\d{1,3}(?:,\d{3})+|\d+)(%?)$/;
    var DURATION = 900;

    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    function easeOutCubic(t) { return 1 - Math.pow(1 - t, 3); }

    function run(el) {
        var text = el.textContent.trim();
        var m = NUMBER.exec(text);
        if (!m) return;
        var target = parseInt(m[1].replace(/,/g, ''), 10);
        if (!(target > 0)) return;

        var withCommas = m[1].indexOf(',') !== -1;
        var suffix = m[2];
        var start = null;

        el.style.fontVariantNumeric = 'tabular-nums';

        function frame(ts) {
            if (start === null) start = ts;
            var p = Math.min((ts - start) / DURATION, 1);
            var value = Math.round(target * easeOutCubic(p));
            el.textContent = (withCommas ? value.toLocaleString('en-US') : String(value)) + suffix;
            if (p < 1) {
                requestAnimationFrame(frame);
            } else {
                el.textContent = text; // always finish on the exact server-rendered value
            }
        }
        requestAnimationFrame(frame);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var els = Array.prototype.filter.call(document.querySelectorAll(SELECTOR), function (el) {
            return !el.closest('.dash-live') && el.children.length === 0 && NUMBER.test(el.textContent.trim());
        });
        if (!els.length) return;

        if (!('IntersectionObserver' in window)) {
            els.forEach(run);
            return;
        }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    io.unobserve(entry.target);
                    run(entry.target);
                }
            });
        }, { threshold: 0.3 });
        els.forEach(function (el) { io.observe(el); });
    });
})();
