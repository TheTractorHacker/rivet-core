/*
 * Shared date-range picker (includes/date_range_picker.php). Bound by event delegation on document so pickers inside
 * AJAX-loaded content work. Writes the hidden canned_date / dtf / dtt inputs the server has always read.
 * Custom calendar = Litepicker in inline mode (plugins/litepicker, loaded by includes/footer.php).
 */
(function () {
    'use strict';

    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    function q(root, sel) { return root.querySelector(sel); }
    function isISO(s) { return /^\d{4}-\d{2}-\d{2}$/.test(s || ''); }
    function parts(s) { var m = s.split('-'); return { y: +m[0], m: +m[1], d: +m[2] }; }
    function fmtDay(s, withYear) {
        var p = parts(s);
        return MONTHS[p.m - 1] + ' ' + p.d + (withYear ? ', ' + p.y : '');
    }
    function fmtRange(from, to) {
        if (!isISO(from) || !isISO(to)) { return ''; }
        var cur = String(new Date().getFullYear());
        var a = parts(from), b = parts(to);
        var withYear = a.y !== b.y || String(a.y) !== cur;
        if (from === to) { return fmtDay(from, withYear); }
        return fmtDay(from, withYear) + ' – ' + fmtDay(to, withYear);
    }

    function state(root) {
        return {
            root: root,
            canned: q(root, '.drp-canned'),
            dtf: q(root, '.drp-dtf'),
            dtt: q(root, '.drp-dtt'),
            btn: q(root, '.drp-btn'),
            clear: q(root, '.drp-clear'),
            panel: q(root, '.drp-panel'),
            custom: q(root, '.drp-custom'),
            from: q(root, '.drp-from'),
            to: q(root, '.drp-to'),
            cal: q(root, '.drp-cal'),
            label: q(root, '.drp-label'),
            dates: q(root, '.drp-dates'),
            def: root.getAttribute('data-drp-default') || 'alltime',
            auto: root.getAttribute('data-drp-auto') !== '0'
        };
    }

    function opts(root) { return Array.prototype.slice.call(root.querySelectorAll('.drp-opt')); }

    function isOpen(root) { return !root.querySelector('.drp-panel').hidden; }

    function open(root) {
        var s = state(root);
        document.querySelectorAll('[data-drp]').forEach(function (o) { if (o !== root && isOpen(o)) { close(o, false); } });
        s.panel.hidden = false;
        s.btn.setAttribute('aria-expanded', 'true');
        root.classList.add('is-open');
        if (!s.custom.hidden) { initCal(s); }
        keepOnScreen(s);
        var sel = root.querySelector('.drp-opt[aria-selected="true"]') || opts(root)[0];
        if (sel) { focusOpt(root, sel); }
    }

    // Wide screens: the popover hangs off the button; slide it left if it would run past the viewport edge (phones use a bottom sheet).
    function keepOnScreen(s) {
        s.panel.style.left = '';
        if (window.matchMedia && window.matchMedia('(max-width: 640px)').matches) { return; }
        var r = s.panel.getBoundingClientRect();
        var shift = Math.min(0, window.innerWidth - 8 - r.right);
        if (shift < 0) { s.panel.style.left = shift + 'px'; }
    }

    function close(root, refocus) {
        var s = state(root);
        s.panel.hidden = true;
        s.btn.setAttribute('aria-expanded', 'false');
        root.classList.remove('is-open');
        if (refocus) { s.btn.focus(); }
    }

    function focusOpt(root, el) {
        opts(root).forEach(function (o) { o.tabIndex = (o === el) ? 0 : -1; });
        el.focus();
    }

    function submit(s) {
        var form = s.root.closest('form');
        if (!form || !s.auto) { return; }
        form.submit();
    }

    function setInputs(s, preset, from, to) {
        s.canned.value = preset;
        var isCustom = preset === 'custom';
        s.dtf.value = isCustom ? from : '';
        s.dtt.value = isCustom ? to : '';
        s.dtf.disabled = !isCustom;
        s.dtt.disabled = !isCustom;
    }

    function refreshButton(s, preset, name, from, to) {
        s.label.textContent = name;
        s.dates.textContent = (preset === 'alltime') ? '' : fmtRange(from, to);
        s.btn.setAttribute('aria-label', 'Date range: ' + name + (s.dates.textContent ? ', ' + s.dates.textContent : ''));
        s.clear.hidden = (preset === s.def);
        opts(s.root).forEach(function (o) {
            var on = o.getAttribute('data-preset') === preset;
            o.setAttribute('aria-selected', on ? 'true' : 'false');
        });
    }

    function pickPreset(s, opt) {
        var id = opt.getAttribute('data-preset');
        if (id === 'custom') {
            s.custom.hidden = false;
            if (!s.from.value && !s.to.value) {
                // Start from the range currently selected (when it has dates) so the calendar opens on something sensible.
                var cur = opts(s.root).filter(function (o) { return o.getAttribute('aria-selected') === 'true'; })[0];
                if (cur && cur.getAttribute('data-from') && cur.getAttribute('data-preset') !== 'alltime') {
                    s.from.value = cur.getAttribute('data-from');
                    s.to.value = cur.getAttribute('data-to');
                }
            }
            opts(s.root).forEach(function (o) { o.setAttribute('aria-selected', o === opt ? 'true' : 'false'); });
            initCal(s);
            keepOnScreen(s);
            s.from.focus();
            return;
        }
        s.custom.hidden = true;
        var name = opt.querySelector('.drp-opt-name').textContent;
        setInputs(s, id, '', '');
        refreshButton(s, id, name, opt.getAttribute('data-from'), opt.getAttribute('data-to'));
        close(s.root, true);
        submit(s);
    }

    function initCal(s) {
        if (s.root._lp || !window.Litepicker) { return; }
        var narrow = window.matchMedia && window.matchMedia('(max-width: 640px)').matches;
        var n = narrow ? 1 : 2;
        var host = document.createElement('input');
        host.type = 'text';
        host.className = 'drp-lp-host';
        host.setAttribute('aria-hidden', 'true');
        host.tabIndex = -1;
        s.cal.appendChild(host);
        var lp = new window.Litepicker({
            element: host,
            parentEl: s.cal,
            inlineMode: true,
            singleMode: false,
            numberOfMonths: n,
            numberOfColumns: n,
            format: 'YYYY-MM-DD',
            firstDay: parseInt(s.cal.getAttribute('data-first-day') || '1', 10),
            autoApply: true,
            minDate: '1970-01-01',
            maxDate: '2099-12-31',
            startDate: isISO(s.from.value) ? s.from.value : null,
            endDate: isISO(s.to.value) ? s.to.value : null,
            setup: function (picker) {
                picker.on('selected', function (d1, d2) {
                    if (d1) { s.from.value = d1.format('YYYY-MM-DD'); }
                    if (d2) { s.to.value = d2.format('YYYY-MM-DD'); }
                });
            }
        });
        s.root._lp = lp;
    }

    function syncCalFromInputs(s) {
        var lp = s.root._lp;
        if (!lp) { return; }
        if (isISO(s.from.value) && isISO(s.to.value)) {
            var a = s.from.value, b = s.to.value;
            if (a > b) { var t = a; a = b; b = t; }
            try { lp.setDateRange(a, b); } catch (err) { /* out of range: leave the calendar alone */ }
        }
    }

    function apply(s) {
        var a = s.from.value, b = s.to.value;
        if (!isISO(a) && !isISO(b)) {
            s.from.focus();
            s.from.setAttribute('aria-invalid', 'true');
            return;
        }
        s.from.removeAttribute('aria-invalid');
        if (isISO(a) && isISO(b) && a > b) { var t = a; a = b; b = t; s.from.value = a; s.to.value = b; }
        setInputs(s, 'custom', isISO(a) ? a : '', isISO(b) ? b : '');
        var text = fmtRange(a || '1970-01-01', b || '2099-12-31');
        if (!isISO(b)) { text = 'From ' + fmtDay(a, true); } else if (!isISO(a)) { text = 'Up to ' + fmtDay(b, true); }
        refreshButton(s, 'custom', 'Custom range', a, b);
        s.dates.textContent = text;
        s.clear.hidden = false;
        close(s.root, true);
        submit(s);
    }

    function cancel(s) {
        // Restore the picker to what the form currently holds.
        var cur = s.canned.value;
        var isCustom = cur === 'custom';
        s.custom.hidden = !isCustom;
        s.from.value = isCustom ? s.dtf.value : '';
        s.to.value = isCustom ? s.dtt.value : '';
        opts(s.root).forEach(function (o) { o.setAttribute('aria-selected', o.getAttribute('data-preset') === cur ? 'true' : 'false'); });
        close(s.root, true);
    }

    function clearRange(s) {
        var def = s.def;
        var opt = s.root.querySelector('.drp-opt[data-preset="' + def + '"]');
        setInputs(s, def, '', '');
        if (opt) {
            refreshButton(s, def, opt.querySelector('.drp-opt-name').textContent, opt.getAttribute('data-from'), opt.getAttribute('data-to'));
        }
        s.clear.hidden = true;
        submit(s);
    }

    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t.closest) { return; }
        // Litepicker re-renders its day cells while handling a click, so the target may already be detached: that click was inside the picker.
        if (!t.isConnected) { return; }
        var root = t.closest('[data-drp]');
        if (!root) {
            document.querySelectorAll('[data-drp]').forEach(function (o) { if (isOpen(o)) { close(o, false); } });
            return;
        }
        var s = state(root);
        if (t.closest('.drp-btn')) {
            if (isOpen(root)) { close(root, false); } else { open(root); }
        } else if (t.closest('.drp-clear')) {
            clearRange(s);
        } else if (t.closest('.drp-opt')) {
            pickPreset(s, t.closest('.drp-opt'));
        } else if (t.closest('.drp-apply')) {
            apply(s);
        } else if (t.closest('.drp-cancel')) {
            cancel(s);
        }
    });

    document.addEventListener('change', function (e) {
        var t = e.target;
        if (!t.classList || (!t.classList.contains('drp-from') && !t.classList.contains('drp-to'))) { return; }
        var root = t.closest('[data-drp]');
        if (root) { syncCalFromInputs(state(root)); }
    });

    document.addEventListener('keydown', function (e) {
        var t = e.target;
        if (!t.closest) { return; }
        var root = t.closest('[data-drp]');
        if (!root && e.key === 'Escape') {
            // Focus can sit on <body> after clicking a calendar day: Esc still closes whichever picker is open.
            root = document.querySelector('[data-drp].is-open');
        }
        if (!root) { return; }
        var s = state(root);

        if (e.key === 'Escape' && isOpen(root)) {
            e.preventDefault();
            e.stopPropagation();
            cancel(s);
            return;
        }
        if (t.classList.contains('drp-btn') && (e.key === 'ArrowDown' || e.key === 'ArrowUp') && !isOpen(root)) {
            e.preventDefault();
            open(root);
            return;
        }
        if (t.classList.contains('drp-opt')) {
            var list = opts(root);
            var i = list.indexOf(t);
            var next = null;
            if (e.key === 'ArrowDown') { next = list[Math.min(i + 1, list.length - 1)]; }
            else if (e.key === 'ArrowUp') { next = list[Math.max(i - 1, 0)]; }
            else if (e.key === 'Home') { next = list[0]; }
            else if (e.key === 'End') { next = list[list.length - 1]; }
            if (next) { e.preventDefault(); focusOpt(root, next); }
            return;
        }
        if ((t.classList.contains('drp-from') || t.classList.contains('drp-to')) && e.key === 'Enter') {
            e.preventDefault();
            apply(s);
        }
    });

    // Tabbing out of the panel closes it.
    document.addEventListener('focusout', function (e) {
        var root = e.target.closest ? e.target.closest('[data-drp]') : null;
        if (!root || !isOpen(root)) { return; }
        var to = e.relatedTarget;
        if (to && root.contains(to)) { return; }
        if (!to) { return; } // click inside non-focusable area / window blur: the click handler decides
        close(root, false);
    });
})();
