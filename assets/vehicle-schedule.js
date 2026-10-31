/* MDRRMO dashboard: vehicle schedule calendar. */
(function () {
    var dataEl = document.getElementById('vsData');
    var grid = document.getElementById('vsGrid');
    if (!dataEl || !grid) return;
    var D;
    try { D = JSON.parse(dataEl.textContent); } catch (e) { return; }

    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var CLS = { Pending: 'b-yellow', Approved: 'b-green', Scheduled: 'b-blue', Completed: 'b-purple' };

    var title = document.getElementById('vsTitle');
    var prev = document.getElementById('vsPrev');
    var next = document.getElementById('vsNext');
    var todayBtn = document.getElementById('vsToday');
    var dayTitle = document.getElementById('vsDayTitle');
    var dayList = document.getElementById('vsDayList');

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function key(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
    function ym(k) { return { y: +k.slice(0, 4), m: +k.slice(5, 7) - 1 }; }
    function pretty(k) { return SHORT[+k.slice(5, 7) - 1] + ' ' + (+k.slice(8, 10)) + ', ' + k.slice(0, 4); }
    function clock12(t) {          // "14:00" -> "02:00 PM"
        if (!t) return '';
        var h = +t.slice(0, 2);
        return pad(h % 12 || 12) + t.slice(2, 5) + ' ' + (h < 12 ? 'AM' : 'PM');
    }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined) n.textContent = text;
        return n;
    }

    var view = ym(D.today), lo = ym(D.from), hi = ym(D.to), selected = D.today;
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
            var trips = D.days[k] || [];
            var cell = el('button', 'vs-cell' + (k === D.today ? ' today' : '') + (k === selected ? ' sel' : '') + (trips.length ? ' has' : ''));
            cell.type = 'button';
            cell.dataset.date = k;
            cell.appendChild(el('span', 'vs-num', n));
            if (trips.length) {
                var dots = el('span', 'vs-dots');
                var seen = {};
                trips.forEach(function (t) {
                    if (!seen[t.status]) { seen[t.status] = 1; dots.appendChild(el('i', 'vs-dot s-' + t.status.toLowerCase())); }
                });
                cell.appendChild(dots);
                cell.appendChild(el('span', 'vs-count', trips.length + (trips.length === 1 ? ' trip' : ' trips')));
                cell.setAttribute('aria-label', pretty(k) + ', ' + trips.length + ' trips');
            }
            grid.appendChild(cell);
        }
    }

    function renderDay() {
        var trips = D.days[selected] || [];
        dayTitle.textContent = pretty(selected) + (selected === D.today ? ' · Today' : '');
        dayList.innerHTML = '';
        if (!trips.length) { dayList.appendChild(el('div', 'vs-empty', 'No vehicle trips on this day.')); return; }
        trips.forEach(function (t) {
            var row = el('div', 'vs-trip');
            var top = el('div', 'vs-trip-top');
            top.appendChild(el('strong', '', clock12(t.time) || 'No time set'));
            top.appendChild(el('span', 'vs-veh', t.vehicle || 'No vehicle yet'));
            top.appendChild(el('span', 'badge ' + (CLS[t.status] || 'b-gray'), t.status));
            row.appendChild(top);
            row.appendChild(el('div', 'vs-trip-main', (t.passenger || 'Passenger not specified') + ' · ' + t.purpose));
            var meta = [t.hospital, 'Brgy. ' + t.barangay, t.code].filter(Boolean).join(' · ');
            row.appendChild(el('div', 'vs-trip-meta', meta));
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
