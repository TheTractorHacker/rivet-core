/* Webhook guides hub (admin/settings_webhook_guides.php only): hash routing, live search + category filter, language tabs, copy,
 * line-wrap toggle, per-platform progress (localStorage, always in try/catch), a tiny syntax highlighter. No libraries. */
(function () {
  'use strict';
  var root = document.getElementById('wg-root');
  if (!root) { return; }
  root.classList.add('wg-js');

  function $all(sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); }
  function store(key, val) {
    try { if (val === undefined) { return window.localStorage.getItem(key); } window.localStorage.setItem(key, val); } catch (e) { return null; }
    return null;
  }
  var live = document.createElement('div');
  live.className = 'visually-hidden'; live.setAttribute('role', 'status'); live.setAttribute('aria-live', 'polite');
  root.appendChild(live);

  /* ------------------------------------------------------------------ syntax highlighting */
  function esc(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
  var KW = {
    javascript: 'const let var function return if else for while of in new try catch throw async await require import from export default true false null undefined typeof class this',
    python: 'def return if elif else for while in import from as try except raise with lambda class pass True False None and or not is yield',
    php: 'function return if else elseif foreach for while as new try catch throw true false null echo use namespace class public private static string int bool array isset empty define',
    bash: 'if then else fi for do done while case esac echo export curl read local function in',
    json: 'true false null'
  };
  var SPEC = {
    javascript: { com: '\\/\\/[^\\n]*|\\/\\*[\\s\\S]*?\\*\\/', str: '`(?:\\\\[\\s\\S]|[^`\\\\])*`|"(?:\\\\.|[^"\\\\\\n])*"|\'(?:\\\\.|[^\'\\\\\\n])*\'' },
    python: { com: '#[^\\n]*', str: '"""[\\s\\S]*?"""|\'\'\'[\\s\\S]*?\'\'\'|"(?:\\\\.|[^"\\\\\\n])*"|\'(?:\\\\.|[^\'\\\\\\n])*\'' },
    php: { com: '\\/\\/[^\\n]*|#[^\\n]*|\\/\\*[\\s\\S]*?\\*\\/', str: '"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'', v: '\\$[A-Za-z_]\\w*' },
    bash: { com: '(?:^|\\s)#[^\\n]*', str: '"(?:\\\\.|[^"\\\\])*"|\'[^\']*\'', v: '\\$\\{?[A-Za-z_]\\w*\\}?' },
    json: { com: '(?!)', str: '"(?:\\\\.|[^"\\\\\\n])*"' }
  };
  var RES = {};
  function regexFor(lang) {
    if (RES[lang]) { return RES[lang]; }
    var s = SPEC[lang];
    // groups: 1 comment, 2 string, 3 variable, 4 number, 5 word, 6 cli flag
    RES[lang] = new RegExp('(' + s.com + ')|(' + s.str + ')|(' + (s.v || '(?!)') + ')|(\\b\\d+(?:\\.\\d+)?\\b)|([A-Za-z_][\\w-]*)' + (lang === 'bash' ? '|((?:^|\\s)--?[A-Za-z][\\w-]*)' : ''), 'g');
    return RES[lang];
  }
  function highlight(code, lang) {
    if (!SPEC[lang] || code.length > 20000) { return esc(code); }
    var kw = {}; (KW[lang] || '').split(' ').forEach(function (w) { kw[w] = 1; });
    var re = regexFor(lang), out = '', last = 0, m;
    re.lastIndex = 0;
    while ((m = re.exec(code)) !== null) {
      if (m[0] === '') { re.lastIndex++; continue; }
      out += esc(code.slice(last, m.index));
      var t = m[0], cls = '';
      if (m[1] !== undefined) { cls = 'com'; }
      else if (m[2] !== undefined) {
        cls = 'str';
        if (lang === 'json' && /^\s*:/.test(code.slice(m.index + t.length, m.index + t.length + 8))) { cls = 'key'; }
      }
      else if (m[3] !== undefined) { cls = 'var'; }
      else if (m[4] !== undefined) { cls = 'num'; }
      else if (m[5] !== undefined) {
        if (kw[t]) { cls = 'kw'; }
        else if (code.charAt(m.index + t.length) === '(' && lang !== 'json') { cls = 'fn'; }
      } else if (m[6] !== undefined) {
        var lead = t.match(/^\s*/)[0];
        out += esc(lead) + '<span class="tk-fn">' + esc(t.slice(lead.length)) + '</span>'; last = m.index + t.length; continue;
      }
      if (cls === 'com' && lang === 'bash' && /^\s/.test(t)) {
        var ws = t.match(/^\s*/)[0]; out += esc(ws); t = t.slice(ws.length);
      }
      out += cls ? '<span class="tk-' + cls + '">' + esc(t) + '</span>' : esc(t);
      last = m.index + m[0].length;
    }
    return out + esc(code.slice(last));
  }
  $all('.wg-code').forEach(function (fig) {
    var code = fig.querySelector('pre code'); if (!code) { return; }
    var lang = fig.getAttribute('data-lang');
    if (SPEC[lang]) { code.innerHTML = highlight(code.textContent, lang); }
  });

  /* ------------------------------------------------------------------ routing */
  var sections = $all('[data-wg-guide]');
  var byId = {}; sections.forEach(function (s) { byId[s.getAttribute('data-guide')] = s; });
  var links = $all('[data-wg-link]');
  var jump = root.querySelector('[data-wg-jump]');
  var current = null;

  function topOffset() { var r = root.querySelector('.wg-layout'); return r ? r.getBoundingClientRect().top + window.pageYOffset - 8 : 0; }
  function route(initial) {
    var id = '';
    try { id = decodeURIComponent((location.hash || '').replace(/^#/, '')); } catch (e) { id = ''; }
    var el = id ? document.getElementById(id) : null;
    var guide = el ? el.closest('[data-wg-guide]') : null;
    if (!guide || !root.contains(guide)) { guide = byId.guides; el = null; }
    sections.forEach(function (s) { s.classList.toggle('is-active', s === guide); });
    var gid = guide.getAttribute('data-guide');
    links.forEach(function (a) {
      var on = a.getAttribute('data-wg-link') === gid && (gid !== 'n8n' || (a.getAttribute('href') === '#n8n') === (id !== 'n8n-walkthrough'));
      a.classList.toggle('is-current', on);
      if (on) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); }
    });
    if (jump) { var want = (el && el.id === 'networks') ? 'networks' : gid; jump.value = want; if (jump.value !== want) { jump.value = gid; } }
    if (guide !== current || !initial) {
      if (el && el !== guide) { el.scrollIntoView(); } else if (!initial || current) { window.scrollTo(0, Math.max(0, topOffset())); }
    }
    if (el && el !== guide && initial) { el.scrollIntoView(); }
    current = guide;
    var h = guide.querySelector('h2'); if (h) { document.title = h.textContent + ' | ' + (document.title.split(' | ').pop()); }
    watchToc();
  }
  window.addEventListener('hashchange', function () { route(false); });
  if (jump) { jump.addEventListener('change', function () { location.hash = '#' + jump.value; }); }

  /* ------------------------------------------------------------------ search + category filter */
  var q = '', cat = '';
  var items = $all('[data-wg-item]');
  var search = root.querySelector('[data-wg-search]');
  function applyFilter() {
    var visible = 0, cards = 0;
    items.forEach(function (it) {
      var ok = (!cat || it.getAttribute('data-cat') === cat) && (!q || (it.getAttribute('data-search') || '').indexOf(q) !== -1);
      it.hidden = !ok;
      if (it.tagName === 'OPTION') { it.disabled = !ok; }
      if (it.tagName === 'ARTICLE' && ok) { cards++; }
      if (it.tagName === 'LI' && ok) { visible++; }
    });
    $all('.wg-navgroup').forEach(function (g) { g.hidden = !g.querySelector('[data-wg-item]:not([hidden])'); });
    $all('optgroup[data-cat]').forEach(function (g) { var any = !!g.querySelector('option:not([hidden])'); g.hidden = !any; g.disabled = !any; });
    var e1 = root.querySelector('[data-wg-empty]'), e2 = root.querySelector('[data-wg-empty-grid]'), c = root.querySelector('[data-wg-count]');
    if (e1) { e1.hidden = visible > 0; }
    if (e2) { e2.hidden = cards > 0; }
    if (c) { c.textContent = String(cards); }
    live.textContent = cards + (cards === 1 ? ' platform' : ' platforms') + ' shown';
  }
  if (search) {
    search.addEventListener('input', function () { q = search.value.trim().toLowerCase(); applyFilter(); });
    search.addEventListener('keydown', function (e) { if (e.key === 'Escape') { search.value = ''; q = ''; applyFilter(); } });
  }
  $all('[data-wg-cat]').forEach(function (b) {
    b.addEventListener('click', function () {
      cat = b.getAttribute('data-wg-cat');
      $all('[data-wg-cat]').forEach(function (o) { var on = o === b; o.classList.toggle('is-active', on); o.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      applyFilter();
    });
  });

  /* ------------------------------------------------------------------ tabs (arrow keys, shared language) */
  function selectTab(tab, focus) {
    var group = tab.closest('[data-wg-tabs]');
    $all('[role="tab"]', group).forEach(function (t) {
      var on = t === tab;
      t.setAttribute('aria-selected', on ? 'true' : 'false'); t.tabIndex = on ? 0 : -1;
      var p = document.getElementById(t.getAttribute('aria-controls')); if (p) { p.hidden = !on; }
    });
    if (focus) { tab.focus(); }
  }
  root.addEventListener('click', function (e) {
    var tab = e.target.closest && e.target.closest('[role="tab"][data-wg-lang]');
    if (!tab) { return; }
    var lang = tab.getAttribute('data-wg-lang');
    selectTab(tab, false); store('wg-lang', lang);
    $all('[role="tab"][data-wg-lang="' + lang + '"]').forEach(function (t) { if (t !== tab) { selectTab(t, false); } });
  });
  root.addEventListener('keydown', function (e) {
    var tab = e.target.closest && e.target.closest('[role="tab"]');
    if (!tab) { return; }
    var tabs = $all('[role="tab"]', tab.closest('[data-wg-tabs]')), i = tabs.indexOf(tab), n = -1;
    if (e.key === 'ArrowRight') { n = (i + 1) % tabs.length; } else if (e.key === 'ArrowLeft') { n = (i - 1 + tabs.length) % tabs.length; }
    else if (e.key === 'Home') { n = 0; } else if (e.key === 'End') { n = tabs.length - 1; }
    if (n < 0) { return; }
    e.preventDefault(); selectTab(tabs[n], true); tabs[n].click();
  });
  var savedLang = store('wg-lang');
  if (savedLang) { $all('[role="tab"][data-wg-lang="' + savedLang + '"]').forEach(function (t) { selectTab(t, false); }); }

  /* ------------------------------------------------------------------ copy + wrap */
  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) { return navigator.clipboard.writeText(text); }
    return new Promise(function (ok, fail) {
      var ta = document.createElement('textarea'); ta.value = text; ta.setAttribute('readonly', ''); ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy') ? ok() : fail(); } catch (err) { fail(err); } finally { document.body.removeChild(ta); }
    });
  }
  root.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-wg-copy]');
    if (btn) {
      var src = document.querySelector(btn.getAttribute('data-wg-copy')); if (!src) { return; }
      var label = btn.querySelector('[data-wg-copy-label]');
      copyText(src.textContent).then(function () {
        if (label) { label.textContent = 'Copied'; } btn.classList.add('is-copied'); live.textContent = 'Copied to clipboard';
        setTimeout(function () { if (label) { label.textContent = 'Copy'; } btn.classList.remove('is-copied'); }, 1600);
      }, function () { if (label) { label.textContent = 'Press Ctrl+C'; } window.getSelection().selectAllChildren(src); });
      return;
    }
    var w = e.target.closest && e.target.closest('[data-wg-wrap]');
    if (w) { var fig = w.closest('.wg-code'), on = !fig.classList.contains('wg-wrap'); fig.classList.toggle('wg-wrap', on); w.setAttribute('aria-pressed', on ? 'true' : 'false'); }
  });

  /* ------------------------------------------------------------------ ticks remembered per platform */
  $all('[data-wg-checks]').forEach(function (list) {
    var key = 'wg:' + list.getAttribute('data-wg-checks');
    var boxes = $all('input[type="checkbox"]', list), saved = [];
    try { saved = JSON.parse(store(key) || '[]') || []; } catch (e) { saved = []; }
    var section = list.closest('section'), prog = section ? section.querySelector('[data-wg-progress]') : null;
    function paint() {
      var n = 0;
      boxes.forEach(function (b) { var li = b.closest('li'); if (b.checked) { n++; } if (li) { li.classList.toggle('is-done', b.checked); } });
      if (prog) { prog.textContent = n ? n + ' of ' + boxes.length + ' done' : ''; }
    }
    boxes.forEach(function (b, i) {
      b.checked = saved.indexOf(i) !== -1;
      b.addEventListener('change', function () {
        var on = []; boxes.forEach(function (x, j) { if (x.checked) { on.push(j); } });
        store(key, JSON.stringify(on)); paint();
      });
    });
    paint();
  });

  /* ------------------------------------------------------------------ "On this page" highlight */
  var io = null;
  function watchToc() {
    if (io) { io.disconnect(); io = null; }
    if (!('IntersectionObserver' in window) || !current) { return; }
    var blocks = $all('[data-wg-toc]', current), tocLinks = $all('.wg-toc a', current);
    if (!blocks.length) { return; }
    io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) { return; }
        tocLinks.forEach(function (a) { a.classList.toggle('is-current', a.getAttribute('href') === '#' + en.target.id); });
      });
    }, { rootMargin: '0px 0px -65% 0px' });
    blocks.forEach(function (b) { io.observe(b); });
  }

  /* ------------------------------------------------------------------ print: expand everything */
  var opened = [];
  window.addEventListener('beforeprint', function () { opened = []; $all('details').forEach(function (d) { if (!d.open) { d.open = true; opened.push(d); } }); });
  window.addEventListener('afterprint', function () { opened.forEach(function (d) { d.open = false; }); opened = []; });

  applyFilter();
  route(true);
})();
