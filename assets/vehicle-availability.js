/* Social Worker dashboard: read-only vehicle availability calendar (dates + time slots). */
(function () {
    var dataEl = document.getElementById('vaData');
    var grid = document.getElementById('vaGrid');
    if (!dataEl || !grid) return;
    var D;
    try { D = JSON.parse(dataEl.textContent); } catch (e) { return; }

    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var CLS = { Pending: 'b-yellow', Approved: 'b-green', Scheduled: 'b-blue' };

    var title = document.getElementById('vaTitle');
    var prev = document.getElementById('vaPrev');
    var next = document.getElementById('vaNext');
    var todayBtn = document.getElementById('vaToday');
    var dayTitle = document.getElementById('vaDayTitle');
    var dayList = document.getElementById('vaDayList');

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function key(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
    function ym(k) { return { y: +k.slice(0, 4), m: +k.slice(5, 7) - 1 }; }
    function pretty(k) { return SHORT[+k.slice(5, 7) - 1] + ' ' + (+k.slice(8, 10)) + ', ' + k.slice(0, 4); }
    function clock(h) { h = h % 24; return pad(h % 12 || 12) + ':00 ' + (h < 12 ? 'AM' : 'PM'); }
    function slotLabel(s) { var h = +s.slice(0, 2); return clock(h) + ' – ' + clock(h + 1); }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined) n.textContent = text;
        return n;
    }
    function freeSlots(k) {
        var d = D.dates[k];
        if (!d) return 0;
        return Object.keys(d).reduce(function (n, s) { return n + (d[s] > 0 ? 1 : 0); }, 0);
    }

    var keys = Object.keys(D.dates).sort();
    var view = ym(D.today), lo = ym(keys[0] || D.today), hi = ym(keys[keys.length - 1] || D.today);
    var selected = D.today;
    function cmp(a, b) { return (a.y - b.y) * 12 + (a.m - b.m); }

    function renderCalendar() {
        title.textContent = MONTHS[view.m] + ' ' + view.y;
        prev.disabled = cmp(view, lo) <= 0;
        next.disabled = cmp(view, hi) >= 0;
        grid.innerHTML = '';
        var firstDow = (new Date(view.y, view.m, 1).getDay() + 6) % 7;
        var daysIn = new Date(view.y, view.m + 1, 0).getDate();
        var weeks = Math.ceil((firstDow + daysIn) / 7);

        for (var i = 0; i < weeks * 7; i++) {
            var n = i - firstDow + 1;
            if (n < 1 || n > daysIn) { grid.appendChild(el('span', 'vs-cell out')); continue; }
            var k = key(view.y, view.m, n);
            var inRange = !!D.dates[k];
            var free = inRange ? freeSlots(k) : 0;
            var cls = 'vs-cell' + (k === D.today ? ' today' : '') + (k === selected ? ' sel' : '');
            if (!inRange) cls += ' past';
            else cls += free > 0 ? ' va-ok' : ' va-no';
            var cell = el('button', cls);
            cell.type = 'button';
            cell.dataset.date = k;
            cell.appendChild(el('span', 'vs-num', n));
            if (inRange) cell.appendChild(el('span', 'vs-count', free > 0 ? free + ' open' : 'Full'));
            if (D.mine[k]) {
                var dots = el('span', 'vs-dots');
                dots.appendChild(el('i', 'vs-dot s-scheduled'));
                cell.appendChild(dots);
            }
            grid.appendChild(cell);
        }
    }

    function renderDay() {
        dayTitle.textContent = pretty(selected) + (selected === D.today ? ' · Today' : '');
        dayList.innerHTML = '';
        var day = D.dates[selected];
        if (!day) { dayList.appendChild(el('div', 'vs-empty', 'This date is outside the booking window.')); return; }
        var mine = {};
        (D.mine[selected] || []).forEach(function (b) { mine[b.time] = b; });

        D.slots.forEach(function (s) {
            var open = (day[s] || 0) > 0;
            var row = el('div', 'va-slot' + (open ? '' : ' no'));
            row.appendChild(el('span', 'va-time', slotLabel(s)));
            if (mine[s]) {
                row.appendChild(el('span', 'va-mine', (mine[s].passenger || 'Your booking') + ' · ' + mine[s].code));
                row.appendChild(el('span', 'badge ' + (CLS[mine[s].status] || 'b-gray'), mine[s].status));
            } else {
                row.appendChild(el('span', 'va-tag ' + (open ? 'ok' : 'no'), open ? 'Available' : 'Unavailable'));
            }
            dayList.appendChild(row);
        });
    }

    grid.addEventListener('click', function (e) {
        var c = e.target.closest('.vs-cell[data-date]');
        if (!c) return;
        selected = c.dataset.date;
        renderCalendar();
        renderDay();
    });
    prev.addEventListener('click', function () { view.m--; if (view.m < 0) { view.m = 11; view.y--; } renderCalendar(); });
    next.addEventListener('click', function () { view.m++; if (view.m > 11) { view.m = 0; view.y++; } renderCalendar(); });
    todayBtn.addEventListener('click', function () { view = ym(D.today); selected = D.today; renderCalendar(); renderDay(); });

    renderCalendar();
    renderDay();
})();
