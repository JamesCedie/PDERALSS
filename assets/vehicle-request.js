/* Vehicle Request form: availability calendar + time-slot picker (Social Worker). */
(function () {
    var dataEl = document.getElementById('vrAvail');
    var grid = document.getElementById('vrCalGrid');
    if (!dataEl || !grid) return;

    var A;
    try { A = JSON.parse(dataEl.textContent); } catch (e) { return; }

    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var SHORT  = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    var title = document.getElementById('vrCalTitle');
    var foot = document.getElementById('vrCalFoot');
    var prev = document.getElementById('vrPrev');
    var next = document.getElementById('vrNext');
    var dateInput = document.getElementById('vrDate');
    var dateTag = document.getElementById('vrDateTag');
    var slotRows = document.getElementById('vrSlotRows');
    var form = document.getElementById('vrCreateForm');
    var errBox = document.getElementById('vrFormError');

    var dateKeys = Object.keys(A.dates).sort();
    var first = dateKeys.length ? dateKeys[0] : A.today;
    var last = dateKeys.length ? dateKeys[dateKeys.length - 1] : A.today;
    var view = { y: +first.slice(0, 4), m: +first.slice(5, 7) - 1 };
    var minView = { y: view.y, m: view.m };
    var maxView = { y: +last.slice(0, 4), m: +last.slice(5, 7) - 1 };
    var selected = '';

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function key(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
    function pretty(k) { return SHORT[+k.slice(5, 7) - 1] + ' ' + (+k.slice(8, 10)) + ', ' + k.slice(0, 4); }
    function freeCount(k) {
        var d = A.dates[k];
        if (!d) return 0;
        return Object.keys(d).reduce(function (n, s) { return n + (d[s] > 0 ? 1 : 0); }, 0);
    }
    function clock(h) {            // 0-24 -> "12:00 AM", "01:00 PM", ...
        h = h % 24;
        return pad(h % 12 || 12) + ':00 ' + (h < 12 ? 'AM' : 'PM');
    }
    function slotLabel(s) {
        var h = +s.slice(0, 2);
        return clock(h) + ' – ' + clock(h + 1);
    }
    function showError(msg) {
        if (!errBox) return;
        errBox.textContent = msg || '';
        errBox.hidden = !msg;
    }

    function renderCalendar() {
        title.textContent = MONTHS[view.m] + ' ' + view.y;
        prev.disabled = view.y === minView.y && view.m === minView.m;
        next.disabled = view.y === maxView.y && view.m === maxView.m;
        grid.innerHTML = '';

        var firstDow = (new Date(view.y, view.m, 1).getDay() + 6) % 7;   // Monday = 0
        var daysIn = new Date(view.y, view.m + 1, 0).getDate();
        var daysPrev = new Date(view.y, view.m, 0).getDate();

        for (var i = 0; i < 42; i++) {
            var cell = document.createElement('button');
            cell.type = 'button';
            var num, k = '', inMonth = true;
            if (i < firstDow) { num = daysPrev - firstDow + i + 1; inMonth = false; }
            else if (i >= firstDow + daysIn) { num = i - firstDow - daysIn + 1; inMonth = false; }
            else { num = i - firstDow + 1; k = key(view.y, view.m, num); }

            cell.textContent = num;
            cell.className = 'vr-day';
            if (!inMonth) {
                cell.className += ' out';
                cell.disabled = true;
                cell.tabIndex = -1;
            } else if (!A.dates[k]) {
                cell.className += ' out';          // past dates / beyond the booking window
                cell.disabled = true;
            } else if (freeCount(k) > 0) {
                cell.className += ' ok';
                cell.setAttribute('aria-label', pretty(k) + ', available');
                cell.dataset.date = k;
                if (k === selected) cell.className += ' sel';
            } else {
                cell.className += ' no';
                cell.disabled = true;
                cell.setAttribute('aria-label', pretty(k) + ', unavailable');
            }
            grid.appendChild(cell);
            if (i >= 34 && i % 7 === 6 && firstDow + daysIn <= i + 1) break;   // skip a trailing empty week
        }
    }

    function renderSlots() {
        slotRows.innerHTML = '';
        if (!selected) {
            slotRows.innerHTML = '<div class="vr-slot-empty">Choose a date first.</div>';
            dateTag.textContent = '';
            return;
        }
        dateTag.textContent = pretty(selected);
        var day = A.dates[selected] || {};
        A.slots.forEach(function (s) {
            var open = (day[s] || 0) > 0;
            var row = document.createElement('label');
            row.className = 'vr-slot' + (open ? '' : ' no');
            var radio = document.createElement('input');
            radio.type = 'radio'; radio.name = 'requested_time'; radio.value = s; radio.disabled = !open;
            var text = document.createElement('span'); text.textContent = slotLabel(s);
            var tag = document.createElement('span');
            tag.className = 'vr-slot-tag ' + (open ? 'ok' : 'no');
            tag.textContent = open ? 'Available' : 'Unavailable';
            row.appendChild(radio); row.appendChild(text); row.appendChild(tag);
            slotRows.appendChild(row);
        });
    }

    function select(k) {
        selected = k;
        dateInput.value = k;
        foot.textContent = pretty(k) + ' selected · Available';
        showError('');
        renderCalendar();
        renderSlots();
    }

    grid.addEventListener('click', function (e) {
        var b = e.target.closest('.vr-day.ok');
        if (b && b.dataset.date) select(b.dataset.date);
    });
    prev.addEventListener('click', function () { view.m--; if (view.m < 0) { view.m = 11; view.y--; } renderCalendar(); });
    next.addEventListener('click', function () { view.m++; if (view.m > 11) { view.m = 0; view.y++; } renderCalendar(); });
    slotRows.addEventListener('change', function () { showError(''); });

    form.addEventListener('submit', function (e) {
        var msg = '';
        if (!document.getElementById('vrPurpose').value) msg = 'Please select a purpose.';
        else if (!document.getElementById('vrPassenger').value.trim()) msg = 'Enter the passenger name.';
        else if (!document.getElementById('vrHospital').value) msg = 'Please select a hospital.';
        else if (!dateInput.value) msg = 'Please select an available date.';
        else if (!form.querySelector('input[name="requested_time"]:checked')) msg = 'Please select a time slot.';
        if (msg) { e.preventDefault(); showError(msg); }
    });

    // Start on the first month that has something bookable.
    for (var i = 0; i < dateKeys.length; i++) {
        if (freeCount(dateKeys[i]) > 0) { view = { y: +dateKeys[i].slice(0, 4), m: +dateKeys[i].slice(5, 7) - 1 }; break; }
    }
    renderCalendar();
    renderSlots();
})();
