// Visual Font Awesome icon picker. Markup: includes/icon_picker.php (iconPickerField()).
// Bound by event delegation on document, so it works for forms injected later (AJAX modals).
// The catalog (RivetCore\Ui\IconCatalog::toJson()) is fetched lazily, once per page, on first open.
(function () {
  'use strict';

  var STYLE_TOKENS = ['fa', 'fas', 'far', 'fab', 'fa-solid', 'fa-regular', 'fa-brands'];
  var PATTERN = /^fa-[a-z0-9]+(-[a-z0-9]+)*$/;
  var catalogPromise = null;
  var catalogCache = {};

  // Same rules as IconCatalog::normalize(); returns '' when invalid.
  function normalize(input) {
    var v = String(input || '').trim().toLowerCase();
    if (!v || v.length > 50) { return ''; }
    var t = v.split(/\s+/);
    while (t.length > 1 && STYLE_TOKENS.indexOf(t[0]) !== -1) { t.shift(); }
    if (t.length !== 1 || STYLE_TOKENS.indexOf(t[0]) !== -1) { return ''; }
    var c = t[0].indexOf('fa-') === 0 ? t[0] : 'fa-' + t[0];
    return (c.length <= 50 && PATTERN.test(c)) ? c : '';
  }

  function loadCatalog(url) {
    if (catalogCache[url]) { return catalogCache[url]; }
    catalogCache[url] = fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
      .then(function (d) {
        d.icons.forEach(function (i) { i._s = (i.l + ' ' + i.c + ' ' + (i.k || []).join(' ')).toLowerCase(); });
        return d;
      })
      .catch(function (e) { delete catalogCache[url]; throw e; });
    return catalogCache[url];
  }

  function q(root, sel) { return root.querySelector(sel); }

  function state(root) {
    if (!root._ip) { root._ip = { cat: '', query: '', data: null, loading: false }; }
    return root._ip;
  }

  function setValue(root, cls) {
    var input = q(root, '[data-icon-value]');
    input.value = cls;
    var shown = cls || root.getAttribute('data-default');
    q(root, '[data-icon-preview]').className = 'fas ' + shown;
    q(root, '[data-icon-name]').textContent = shown;
    q(root, '[data-icon-custom]').value = cls;
    q(root, '[data-icon-error]').hidden = true;
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function isOpen(root) { return !q(root, '.icon-picker__panel').hidden; }

  function open(root) {
    var panel = q(root, '.icon-picker__panel');
    var btn = q(root, '.icon-picker__button');
    panel.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
    root.classList.add('is-open');
    var st = state(root);
    if (!st.data && !st.loading) {
      st.loading = true;
      loadCatalog(root.getAttribute('data-catalog-url')).then(function (d) {
        st.data = d; st.loading = false; buildCats(root); render(root);
      }).catch(function () {
        st.loading = false;
        q(root, '[data-icon-grid]').innerHTML = '<span class="icon-picker__empty">The icon catalog could not be loaded. You can still type a Font Awesome class below.</span>';
      });
    } else if (st.data) { render(root); }
    q(root, '[data-icon-search]').focus();
  }

  function close(root, returnFocus) {
    var panel = q(root, '.icon-picker__panel');
    if (panel.hidden) { return; }
    panel.hidden = true;
    var btn = q(root, '.icon-picker__button');
    btn.setAttribute('aria-expanded', 'false');
    root.classList.remove('is-open');
    if (returnFocus) { btn.focus(); }
  }

  function buildCats(root) {
    var st = state(root), box = q(root, '[data-icon-cats]');
    box.innerHTML = '';
    function chip(key, label) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'icon-picker__chip'; b.setAttribute('data-cat', key);
      b.setAttribute('aria-pressed', key === st.cat ? 'true' : 'false');
      b.textContent = label; box.appendChild(b);
    }
    chip('', 'All');
    Object.keys(st.data.categories).forEach(function (k) { chip(k, st.data.categories[k]); });
  }

  function render(root) {
    var st = state(root), grid = q(root, '[data-icon-grid]');
    var terms = st.query.toLowerCase().split(/\s+/).filter(Boolean);
    var current = q(root, '[data-icon-value]').value;
    var list = st.data.icons.filter(function (i) {
      if (st.cat && i.g !== st.cat) { return false; }
      return terms.every(function (t) { return i._s.indexOf(t) !== -1; });
    });
    var frag = document.createDocumentFragment();
    list.forEach(function (i, idx) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'icon-picker__item'; b.setAttribute('role', 'option');
      b.setAttribute('data-icon', i.c); b.title = i.l + ' (' + i.c + ')'; b.setAttribute('aria-label', i.l);
      b.setAttribute('aria-selected', i.c === current ? 'true' : 'false');
      b.tabIndex = -1;
      var ic = document.createElement('i'); ic.className = 'fas ' + i.c; ic.setAttribute('aria-hidden', 'true');
      b.appendChild(ic); frag.appendChild(b);
    });
    grid.innerHTML = '';
    if (!list.length) {
      grid.innerHTML = '<span class="icon-picker__empty">No icons match. Try another word, or enter a custom class below.</span>';
    } else {
      grid.appendChild(frag);
      var sel = grid.querySelector('[aria-selected="true"]') || grid.firstChild;
      sel.tabIndex = 0;
    }
    q(root, '[data-icon-status]').textContent = list.length + (list.length === 1 ? ' icon' : ' icons');
  }

  function focusItem(root, el) {
    if (!el) { return; }
    var grid = q(root, '[data-icon-grid]');
    var cur = grid.querySelector('[tabindex="0"]'); if (cur) { cur.tabIndex = -1; }
    el.tabIndex = 0; el.focus();
  }

  function columns(grid) {
    var items = grid.querySelectorAll('.icon-picker__item');
    if (!items.length) { return 1; }
    var top = items[0].offsetTop, n = 0;
    while (n < items.length && items[n].offsetTop === top) { n++; }
    return Math.max(1, n);
  }

  function useCustom(root) {
    var raw = q(root, '[data-icon-custom]').value;
    if (!raw.trim()) { setValue(root, ''); close(root, true); return; }
    var cls = normalize(raw);
    if (!cls) { q(root, '[data-icon-error]').hidden = false; return; }
    setValue(root, cls); close(root, true);
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    var root = t.closest ? t.closest('[data-icon-picker]') : null;
    if (!root) {
      document.querySelectorAll('[data-icon-picker].is-open').forEach(function (r) { close(r, false); });
      return;
    }
    document.querySelectorAll('[data-icon-picker].is-open').forEach(function (r) { if (r !== root) { close(r, false); } });
    var btn = t.closest('.icon-picker__button');
    if (btn) { isOpen(root) ? close(root, true) : open(root); return; }
    var item = t.closest('.icon-picker__item');
    if (item) { setValue(root, item.getAttribute('data-icon')); close(root, true); return; }
    var chip = t.closest('.icon-picker__chip');
    if (chip) {
      var st = state(root); st.cat = chip.getAttribute('data-cat');
      root.querySelectorAll('.icon-picker__chip').forEach(function (c) { c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'); });
      render(root); return;
    }
    if (t.closest('[data-icon-use]')) { useCustom(root); return; }
    if (t.closest('[data-icon-reset]')) { setValue(root, ''); close(root, true); }
  });

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (t.matches && t.matches('[data-icon-search]')) {
      var root = t.closest('[data-icon-picker]'), st = state(root);
      st.query = t.value; if (st.data) { render(root); }
    } else if (t.matches && t.matches('[data-icon-custom]')) {
      t.closest('[data-icon-picker]').querySelector('[data-icon-error]').hidden = true;
    }
  });

  document.addEventListener('keydown', function (e) {
    var t = e.target;
    var root = t.closest ? t.closest('[data-icon-picker]') : null;
    if (!root) { return; }
    var key = e.key;
    if (key === 'Escape' && isOpen(root)) {
      e.preventDefault(); e.stopPropagation(); close(root, true); return;   // do not also close the Bootstrap modal
    }
    if (key === 'Enter' && t.matches('[data-icon-search], [data-icon-custom]')) {
      e.preventDefault();   // never submit the surrounding form from inside the picker
      if (t.matches('[data-icon-custom]')) { useCustom(root); }
      else { var first = q(root, '.icon-picker__item'); if (first) { focusItem(root, first); } }
      return;
    }
    if (key === 'ArrowDown' && t.matches('.icon-picker__button') && !isOpen(root)) { e.preventDefault(); open(root); return; }
    var grid = q(root, '[data-icon-grid]');
    if (t.matches('[data-icon-search]') && key === 'ArrowDown') {
      e.preventDefault(); focusItem(root, q(root, '.icon-picker__item[tabindex="0"]') || q(root, '.icon-picker__item')); return;
    }
    if (t.matches('.icon-picker__item')) {
      var items = Array.prototype.slice.call(grid.querySelectorAll('.icon-picker__item'));
      var i = items.indexOf(t), cols = columns(grid), n = null;
      if (key === 'ArrowRight') { n = i + 1; } else if (key === 'ArrowLeft') { n = i - 1; }
      else if (key === 'ArrowDown') { n = i + cols; } else if (key === 'ArrowUp') { n = i - cols; }
      else if (key === 'Home') { n = 0; } else if (key === 'End') { n = items.length - 1; }
      if (n !== null) {
        e.preventDefault();
        if (n < 0) { q(root, '[data-icon-search]').focus(); return; }
        focusItem(root, items[Math.min(n, items.length - 1)]);
      }
    }
  }, true);   // capture phase: Esc must be handled before Bootstrap's own modal keydown handler sees it
})();
