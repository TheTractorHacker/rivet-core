/*
 * Command palette: Ctrl/Cmd+K (or "/" outside a field) opens one box that jumps to any page in the navigation the signed-in user can
 * already see, to recent destinations, and - while typing - to records (departments, contacts, tickets, assets, KB ...) through the same
 * live-search endpoint the header search uses. Nothing is stored on the server: "recent" lives in this browser's localStorage.
 *
 * Every string that came from the page or the server is placed with textContent; hrefs are only taken from same-origin links.
 */
(function () {
    'use strict';
    if (window.__rivetPalette) { return; }
    window.__rivetPalette = true;

    var RECENT_KEY = 'rivet.palette.recent.v1';
    var MAX_RECENT = 6;
    var MAX_PAGES = 8;
    var overlay, box, input, list, hint;
    var items = [];        // what the list currently shows (selectable rows)
    var active = 0;
    var seq = 0, timer = null;
    var navCache = null;
    var lastFocus = null;
    var moved = false;   // the user has arrowed/hovered: a late result must not steal the selection

    var GROUP_META = {
        clients: ['Departments', 'fa-users'], contacts: ['Contacts', 'fa-address-book'], tickets: ['Tickets', 'fa-life-ring'],
        assets: ['Assets', 'fa-desktop'], kb_articles: ['Knowledge Base', 'fa-book'], credentials: ['Credentials', 'fa-key'],
        vendors: ['Vendors', 'fa-building'], domains: ['Domains', 'fa-globe'], software: ['Software', 'fa-cube'],
        networks: ['Networks', 'fa-network-wired'], services: ['Services', 'fa-concierge-bell'], documents: ['Documents', 'fa-file-alt'],
        recurring_tickets: ['Recurring tickets', 'fa-sync'], quotes: ['Quotes', 'fa-file-invoice'], invoices: ['Invoices', 'fa-file-invoice-dollar'],
        products: ['Products', 'fa-box'], linked: ['Linked records', 'fa-project-diagram'], settings: ['Settings', 'fa-cog']
    };
    var GROUP_ORDER = ['tickets', 'clients', 'contacts', 'assets', 'kb_articles', 'documents', 'credentials', 'vendors', 'domains', 'software',
        'networks', 'services', 'recurring_tickets', 'quotes', 'invoices', 'products', 'linked', 'settings'];

    var isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '');

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text != null) { e.textContent = text; }
        return e;
    }

    function sameOrigin(href) {
        try { var u = new URL(href, location.href); return u.origin === location.origin ? u.pathname + u.search + u.hash : null; } catch (e) { return null; }
    }

    /* ---------- pages: scraped from the sidebar, so they honour the user's permissions and modules for free ---------- */
    function collectPages() {
        if (navCache) { return navCache; }
        var out = [], seen = {};
        var links = document.querySelectorAll('aside.navbar-vertical a[href], .navbar-vertical a[href]');
        Array.prototype.forEach.call(links, function (a) {
            var raw = a.getAttribute('href') || '';
            if (!raw || raw.charAt(0) === '#' || /^javascript:/i.test(raw) || /logout/i.test(raw)) { return; }
            var url = sameOrigin(raw);
            if (!url || seen[url]) { return; }
            if (a.closest('.navbar-brand') || a.classList.contains('navbar-brand')) { return; }
            var tEl = a.querySelector('.nav-link-title') || a;
            var tClone = tEl.cloneNode(true);   // the count badge ("Tickets 66") is not part of the page name
            Array.prototype.forEach.call(tClone.querySelectorAll('.badge, .nav-link-badge'), function (b) { b.remove(); });
            var title = (tClone.textContent || '').replace(/\s+/g, ' ').trim();
            if (!title) { return; }
            var group = '';
            var menu = a.closest('.dropdown-menu');
            if (menu && menu.id) {
                var tog = document.querySelector('[aria-controls="' + menu.id + '"] .nav-link-title');
                if (tog) { group = tog.textContent.replace(/\s+/g, ' ').trim(); }
            }
            var ic = a.querySelector('i[class*="fa-"]');
            seen[url] = 1;
            out.push({ type: 'page', title: title, sub: group, icon: ic ? ic.className.replace(/\bme-\d\b/g, '').trim() : 'fas fa-arrow-right', url: url });
        });
        navCache = out;
        return out;
    }

    /* ---------- matching ---------- */
    function score(text, q) {
        text = text.toLowerCase();
        var i = text.indexOf(q);
        if (i === 0) { return 100 - Math.min(text.length, 40) / 4; }
        if (i > 0) { return (text.charAt(i - 1) === ' ' ? 80 : 60) - i / 10; }
        var p = 0, gaps = 0, last = -1;                    // in-order subsequence, e.g. "rcl" -> "Recurring"
        for (var c = 0; c < q.length; c++) {
            var f = text.indexOf(q.charAt(c), p);
            if (f < 0) { return 0; }
            if (last >= 0 && f !== last + 1) { gaps++; }
            last = f; p = f + 1;
        }
        return Math.max(5, 40 - gaps * 6);
    }

    function rankPages(q) {
        var terms = q.toLowerCase().split(/\s+/).filter(Boolean);
        var res = [];
        collectPages().forEach(function (p) {
            var s = 0, ok = true;
            terms.forEach(function (t) {
                var a = Math.max(score(p.title, t), score(p.sub, t) * .6);
                if (!a) { ok = false; } else { s += a; }
            });
            if (ok) { res.push({ s: s, p: p }); }
        });
        res.sort(function (a, b) { return b.s - a.s; });
        return res.slice(0, MAX_PAGES).map(function (r) { return r.p; });
    }

    /* ---------- recent ---------- */
    function getRecent() {
        try { var v = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; }
    }
    function remember(it) {
        try {
            var url = sameOrigin(it.url);
            if (!url) { return; }
            var r = getRecent().filter(function (x) { return x.url !== url; });
            r.unshift({ title: it.title, sub: it.sub || '', icon: it.icon, url: url });
            localStorage.setItem(RECENT_KEY, JSON.stringify(r.slice(0, MAX_RECENT)));
        } catch (e) { /* private mode: no recents, the palette still works */ }
    }

    /* ---------- rendering ---------- */
    function markMatch(node, text, q) {
        var terms = q.toLowerCase().split(/\s+/).filter(Boolean), low = text.toLowerCase(), hit = -1, len = 0;
        for (var i = 0; i < terms.length; i++) { var k = low.indexOf(terms[i]); if (k >= 0) { hit = k; len = terms[i].length; break; } }
        if (hit < 0) { node.textContent = text; return; }
        node.appendChild(document.createTextNode(text.slice(0, hit)));
        node.appendChild(el('mark', null, text.slice(hit, hit + len)));
        node.appendChild(document.createTextNode(text.slice(hit + len)));
    }

    function section(label) { list.appendChild(el('div', 'rvp-section', label)); }

    function row(it, q) {
        var a = el('a', 'rvp-row');
        a.setAttribute('role', 'option');
        a.href = it.url;
        var ic = el('span', 'rvp-ic'); ic.appendChild(el('i', /fa-/.test(it.icon || '') && / /.test(it.icon) ? it.icon : 'fas ' + (it.icon || 'fa-arrow-right'))); a.appendChild(ic);
        var tx = el('span', 'rvp-tx'), t = el('span', 'rvp-title');
        markMatch(t, it.title, q || ''); tx.appendChild(t);
        if (it.sub) { tx.appendChild(el('span', 'rvp-sub', it.sub)); }
        a.appendChild(tx);
        a.appendChild(el('span', 'rvp-go', it.type === 'record' ? 'Open' : 'Go to'));
        a.addEventListener('mousemove', function () { if (items.indexOf(it) !== active) { moved = true; setActive(items.indexOf(it), true); } });
        a.addEventListener('click', function () { remember(it); });
        it.node = a;
        items.push(it);
        list.appendChild(a);
    }

    function setActive(i, noScroll) {
        if (!items.length) { return; }
        if (!noScroll) { moved = true; }
        if (items[active] && items[active].node) { items[active].node.classList.remove('is-active'); items[active].node.removeAttribute('aria-selected'); }
        active = (i + items.length) % items.length;
        var n = items[active].node;
        n.classList.add('is-active'); n.setAttribute('aria-selected', 'true');
        if (!noScroll) { n.scrollIntoView({ block: 'nearest' }); }
    }

    function render(q, records, loading, keepMoved) {
        list.textContent = ''; items = []; active = 0;
        if (!keepMoved) { moved = false; }
        if (!q) {
            var rec = getRecent();
            if (rec.length) { section('Recent'); rec.forEach(function (r) { row({ type: 'page', title: r.title, sub: r.sub, icon: r.icon, url: r.url }, ''); }); }
            section('Go to');
            collectPages().slice(0, 10).forEach(function (p) { row(p, ''); });
            list.appendChild(el('div', 'rvp-tip', 'Type to jump to any page, or to find a ticket, department, contact, asset or article.'));
        } else {
            var pages = rankPages(q);
            if (pages.length) { section('Pages'); pages.forEach(function (p) { row(p, q); }); }
            if (records) {
                GROUP_ORDER.forEach(function (k) {
                    var g = records[k]; if (!g || !g.length) { return; }
                    var m = GROUP_META[k] || [k, 'fa-circle'];
                    section(m[0]);
                    g.slice(0, 5).forEach(function (r) {
                        var u = sameOrigin(r.url || ''); if (!u) { return; }
                        row({ type: 'record', title: String(r.title || ''), sub: String(r.subtitle || ''), icon: m[1], url: u }, q);
                    });
                });
            }
            if (loading) { list.appendChild(el('div', 'rvp-tip', 'Searching records…')); }
            row({ type: 'page', title: 'Search everything for “' + q + '”', sub: 'Full results page', icon: 'fa-search', url: '/agent/global_search.php?query=' + encodeURIComponent(q) }, '');
            if (!pages.length && !loading && records && !Object.keys(records).length) { list.insertBefore(el('div', 'rvp-tip', 'Nothing matches yet.'), list.firstChild); }
        }
        setActive(0, true);
    }

    function search(q) {
        var mine = ++seq;
        render(q, null, q.length >= 2);
        if (q.length < 2) { return; }
        fetch('/agent/ajax.php?global_search_live=1&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (mine !== seq || !overlay || overlay.hidden) { return; }
                var keep = moved && items[active] ? items[active].url : null;
                render(q, d && d.ok ? (d.groups || {}) : {}, false, true);
                if (keep) { for (var i = 0; i < items.length; i++) { if (items[i].url === keep) { setActive(i, true); break; } } }
            })
            .catch(function () { if (mine === seq) { render(q, {}, false); } });
    }

    /* ---------- open / close ---------- */
    function build() {
        overlay = el('div', 'rvp-overlay'); overlay.hidden = true;
        box = el('div', 'rvp-box'); box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true'); box.setAttribute('aria-label', 'Command palette');
        var top = el('div', 'rvp-top');
        top.appendChild(el('i', 'fas fa-search rvp-search-ic'));
        input = el('input', 'rvp-input'); input.type = 'text'; input.setAttribute('autocomplete', 'off'); input.setAttribute('spellcheck', 'false');
        input.setAttribute('placeholder', 'Jump to a page, ticket, department, contact…'); input.setAttribute('aria-label', 'Search pages and records');
        input.setAttribute('role', 'combobox'); input.setAttribute('aria-expanded', 'true');
        top.appendChild(input); top.appendChild(el('kbd', 'rvp-esc', 'Esc'));
        list = el('div', 'rvp-list'); list.setAttribute('role', 'listbox');
        hint = el('div', 'rvp-foot');
        [['↑↓', 'move'], ['↵', 'open'], ['Esc', 'close']].forEach(function (k) { var s = el('span'); s.appendChild(el('kbd', null, k[0])); s.appendChild(document.createTextNode(' ' + k[1])); hint.appendChild(s); });
        box.appendChild(top); box.appendChild(list); box.appendChild(hint); overlay.appendChild(box); document.body.appendChild(overlay);

        overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) { close(); } });
        input.addEventListener('input', function () {
            var q = input.value.trim();
            if (timer) { clearTimeout(timer); }
            if (q.length < 2) { search(q); } else { render(q, null, true); timer = setTimeout(function () { search(q); }, 180); }
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
            else if (e.key === 'Enter') {
                e.preventDefault();
                var it = items[active]; if (!it) { return; }
                remember(it);
                if (e.ctrlKey || e.metaKey) { window.open(it.url, '_blank', 'noopener'); } else { location.href = it.url; }
            } else if (e.key === 'Tab') { e.preventDefault(); }
        });
    }

    function open() {
        if (!overlay) { build(); }
        if (!overlay.hidden) { input.focus(); return; }
        lastFocus = document.activeElement;
        navCache = null;
        overlay.hidden = false;
        document.documentElement.classList.add('rvp-open');
        input.value = '';
        render('', null, false);
        input.focus();
    }
    function close() {
        if (!overlay || overlay.hidden) { return; }
        overlay.hidden = true; seq++;
        document.documentElement.classList.remove('rvp-open');
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) { /* element gone */ } }
    }

    document.addEventListener('keydown', function (e) {
        var k = (e.key || '').toLowerCase();
        if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && k === 'k') { e.preventDefault(); overlay && !overlay.hidden ? close() : open(); return; }
        if (e.key === 'Escape' && overlay && !overlay.hidden) { e.preventDefault(); close(); return; }
        if (k === '/' && !e.ctrlKey && !e.metaKey && !e.altKey) {
            var t = e.target, tag = t && t.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (t && t.isContentEditable)) { return; }
            e.preventDefault(); open();
        }
    });

    /* A visible entry point: the header search box gets a "Ctrl K" chip, and clicking the chip (or the box with Ctrl held) opens the palette. */
    function chip() {
        var inp = document.getElementById('globalSearchInput');
        if (!inp || document.querySelector('.rvp-chip')) { return; }
        var form = inp.closest('form'); if (!form) { return; }
        var c = el('button', 'rvp-chip'); c.type = 'button'; c.setAttribute('aria-label', 'Open the command palette');
        c.appendChild(el('kbd', null, isMac ? '⌘K' : 'Ctrl K'));
        c.addEventListener('click', open);
        form.classList.add('rvp-has-chip'); form.appendChild(c);
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', chip); } else { chip(); }
})();
