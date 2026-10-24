// Administration > Webhooks > Add (admin/webhook_new.php) and Edit (admin/webhook_edit.php).
//
//   Add:  a four-step flow (Platform > Connect > Events > Review & test) with a progress bar, one focus per step, validation before each Continue,
//         ?dest=<platform>&step=<step> deep links, a live URL check, quick event presets, an inline Send test and a success screen.
//   Edit: tabs (Connection | Events | Payload & advanced | Deliveries), unsaved-changes warning, an enable switch that saves at once.
//
// The server stays the only authority: every check here is a convenience that mirrors the rules the save runs (modals/webhook/webhook_action.php
// answers the live URL check and step validation with DestinationConfig, the same code Save uses). Vanilla JS, no libraries; data-wz-* hooks.
(function () {
  'use strict';

  var root = document.querySelector('[data-wz]');
  if (!root) { return; }
  var form = root.querySelector('[data-wz-form]');
  var mode = root.getAttribute('data-wz-mode');
  var actionUrl = root.getAttribute('data-wz-action');
  var destId = root.getAttribute('data-wz-dest') || '';
  var destName = root.getAttribute('data-wz-dest-name') || '';
  var webhookId = root.getAttribute('data-wz-id') || '';
  var networksUrl = root.getAttribute('data-wz-networks') || 'settings_webhooks.php#internal-networks';
  var editUrl = root.getAttribute('data-wz-edit') || 'webhook_edit.php';

  // ---- small helpers ---------------------------------------------------------------------------------------------
  function $(sel, ctx) { return (ctx || root).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined && text !== null) { n.textContent = text; }
    return n;
  }
  function icon(cls) { var i = el('i', cls); i.setAttribute('aria-hidden', 'true'); return i; }
  function store(kind, key, val) {
    try {
      var s = window[kind];
      if (val === undefined) { return s.getItem(key); }
      s.setItem(key, val);
    } catch (e) { /* storage can be blocked: the page works without it */ }
    return null;
  }
  function debounce(fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; }
  function field(input) { return input.closest('[data-wz-field]'); }
  function shown(node) { return !!node && !node.closest('[hidden]') && node.getClientRects().length > 0; }

  function setFieldError(input, msg) {
    var f = field(input);
    if (!f) { return; }
    var box = f.querySelector('[data-wz-err]');
    f.classList.toggle('is-invalid', !!msg);
    if (box) { box.textContent = msg || ''; box.hidden = !msg; }
    if (msg) { input.setAttribute('aria-invalid', 'true'); } else { input.removeAttribute('aria-invalid'); }
  }
  function clearErrors(scope) {
    $$('[data-wz-field].is-invalid', scope).forEach(function (f) {
      f.classList.remove('is-invalid');
      var b = f.querySelector('[data-wz-err]'); if (b) { b.textContent = ''; b.hidden = true; }
    });
    $$('[aria-invalid]', scope).forEach(function (i) { i.removeAttribute('aria-invalid'); });
    $$('[data-wz-steperrors]', scope).forEach(function (b) { b.hidden = true; b.textContent = ''; });
  }
  function stepErrors(panel, messages, links) {
    var box = $('[data-wz-steperrors]', panel);
    if (!box) { return; }
    box.textContent = '';
    if (!messages.length) { box.hidden = true; return; }
    box.appendChild(el('strong', '', messages.length === 1 ? 'Fix this first:' : 'Fix these first:'));
    var ul = el('ul', 'mb-0');
    messages.forEach(function (m, i) {
      var li = el('li', '', m);
      if (links && links[i]) {
        li.appendChild(document.createTextNode(' '));
        var a = el('a', '', links[i].label); a.href = '#'; a.addEventListener('click', function (e) { e.preventDefault(); links[i].go(); });
        li.appendChild(a);
      }
      ul.appendChild(li);
    });
    box.appendChild(ul);
    box.hidden = false;
    box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  function formData(action, extra) {
    var fd = new FormData(form);
    fd.delete('add_webhook'); fd.delete('edit_webhook');
    fd.set('wh_action', action);
    Object.keys(extra || {}).forEach(function (k) { fd.set(k, extra[k]); });
    return fd;
  }
  function post(action, extra) {
    return fetch(actionUrl, { method: 'POST', body: formData(action, extra), credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { throw new Error('The server answered HTTP ' + r.status + '.'); }); });
  }
  function csrf() { var i = form && form.querySelector('[name=csrf_token]'); return i ? i.value : ''; }
  function copyText(text, done) {
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done, function () {}); return; }
    var ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); done(); } catch (e) { /* nothing more to try */ }
    ta.remove();
  }

  // ---- secrets: show / hide, copy, generate ------------------------------------------------------------------------
  root.addEventListener('click', function (e) {
    var t = e.target;
    var rev = t.closest('[data-wz-reveal]');
    if (rev) {
      var inp = rev.parentNode.querySelector('input');
      var on = inp.type === 'password';
      inp.type = on ? 'text' : 'password';
      rev.setAttribute('aria-pressed', on ? 'true' : 'false');
      rev.setAttribute('aria-label', on ? 'Hide' : 'Show');
      rev.firstChild.className = on ? 'fas fa-eye-slash' : 'fas fa-eye';
      return;
    }
    var cp = t.closest('[data-wz-copyval]');
    if (cp) {
      var src = cp.parentNode.querySelector('input');
      if (!src.value) { cp.title = 'Nothing to copy: a saved secret is never shown again'; return; }
      copyText(src.value, function () { var old = cp.firstChild.className; cp.firstChild.className = 'fas fa-check'; setTimeout(function () { cp.firstChild.className = old; }, 1400); });
      return;
    }
    var gen = t.closest('[data-wz-gen]');
    if (gen) {
      var target = gen.parentNode.querySelector('input');
      var bytes = new Uint8Array(20); crypto.getRandomValues(bytes);
      target.value = Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
      target.type = 'text';
      var rv = gen.parentNode.querySelector('[data-wz-reveal]');
      if (rv) { rv.setAttribute('aria-pressed', 'true'); rv.firstChild.className = 'fas fa-eye-slash'; }
      target.focus();
      target.dispatchEvent(new Event('input', { bubbles: true }));
      return;
    }
    var ins = t.closest('[data-wz-insert]');
    if (ins) {
      var ta = $('[data-wz-tpl-input]'), v = ins.getAttribute('data-wz-insert');
      var s = ta.selectionStart || 0, en = ta.selectionEnd || 0;
      ta.value = ta.value.slice(0, s) + v + ta.value.slice(en);
      ta.focus(); ta.selectionStart = ta.selectionEnd = s + v.length;
      ta.dispatchEvent(new Event('input', { bubbles: true }));
    }
  });

  // ---- authentication mode ---------------------------------------------------------------------------------------
  function applyAuthMode() {
    var sel = $('[data-wz-auth-mode]');
    if (!sel) { return; }
    var m = sel.value;
    $$('[data-wz-auth-fields]').forEach(function (box) { box.hidden = box.getAttribute('data-wz-auth-fields') !== m; });
    var none = $('[data-wz-auth-none]');
    if (none) { none.hidden = m === 'bearer' || m === 'basic' || m === 'header'; }
  }

  // ---- custom template: live check ---------------------------------------------------------------------------------
  var checkTemplate = debounce(function () {
    var input = $('[data-wz-tpl-input]'), state = $('[data-wz-tpl-state]');
    if (!input || !state) { return; }
    var fd = new FormData();
    fd.append('csrf_token', csrf()); fd.append('wh_action', 'validate_template');
    fd.append('webhook_template', input.value);
    fd.append('webhook_template_encoding', ($('[data-wz-tpl-enc]') || {}).value || 'json');
    state.textContent = 'Checking...'; state.className = 'small text-secondary';
    fetch(actionUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.ok) { state.textContent = 'Valid. Sample output: ' + String(d.sample).slice(0, 120); state.className = 'small text-success'; }
      else { state.textContent = (d.errors || ['Invalid template']).join(' '); state.className = 'small text-danger'; }
    }).catch(function () { state.textContent = ''; });
  }, 500);

  // ---- address: paste handling, name suggestion and the live check -----------------------------------------------------
  var urlInput = $('[data-wz-url]');
  var nameInput = $('[data-wz-name]');
  var urlState = $('[data-wz-urlstate]');
  var urlResult = null;     // the last answer of the live check
  var urlSeq = 0;

  function hostOfInput() {
    var v = (urlInput && urlInput.value || '').trim();
    var hint = urlInput ? urlInput.getAttribute('data-wz-hint') : '';
    if (!v || (v.indexOf('{') !== -1 && !hint)) { return ''; }
    try { return new URL(v.replace(/\{[a-z_]+\}/g, 'x')).hostname; } catch (e) { return ''; }
  }
  function suggestName() {
    if (!nameInput || nameInput.getAttribute('data-wz-touched') === '1') { return; }
    var host = (urlResult && urlResult.host) || hostOfInput();
    if (!host) { return; }
    var cur = nameInput.value.trim();
    if (cur === '' || nameInput.getAttribute('data-wz-auto') === '1') {
      nameInput.value = (destName || 'Webhook') + ' \u2013 ' + host;
      nameInput.setAttribute('data-wz-auto', '1');
      setFieldError(nameInput, '');
    }
  }
  if (nameInput) { nameInput.addEventListener('input', function () { nameInput.setAttribute('data-wz-touched', '1'); nameInput.removeAttribute('data-wz-auto'); }); }

  function renderUrlState(r) {
    if (!urlState) { return; }
    urlState.textContent = '';
    if (!r || r.state === 'empty') { urlState.hidden = true; urlState.className = 'wz-urlstate'; return; }
    var cls = r.ok ? 'is-ok' : (r.state === 'private' || r.state === 'incomplete' ? 'is-warn' : 'is-bad');
    if (r.state === 'keep') { cls = 'is-info'; }
    urlState.className = 'wz-urlstate ' + cls;
    urlState.appendChild(icon(r.ok && r.state !== 'keep' ? 'fas fa-check-circle mt-1' : (r.state === 'keep' ? 'fas fa-lock mt-1' : 'fas fa-exclamation-circle mt-1')));
    var span = el('span', '', r.message);
    urlState.appendChild(span);
    if (r.state === 'private') {
      var a = el('a', 'ms-1', 'Open Internal network access'); a.href = networksUrl; a.target = '_blank'; a.rel = 'noopener';
      span.appendChild(document.createTextNode(' ')); span.appendChild(a);
    }
    urlState.hidden = false;
  }
  var runUrlCheck = function () {
    if (!urlInput && !$('input[name^="extra["]')) { return Promise.resolve(null); }
    var my = ++urlSeq;
    var fd = new FormData();
    fd.set('csrf_token', csrf()); fd.set('wh_action', 'check_url');
    fd.set('webhook_destination', destId);
    if (webhookId) { fd.set('webhook_id', webhookId); }
    fd.set('webhook_url', urlInput ? urlInput.value : '');
    $$('input[name^="extra["]').forEach(function (i) { fd.set(i.name, i.value); });
    return fetch(actionUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (my !== urlSeq) { return urlResult; }
      urlResult = d;
      renderUrlState(d);
      suggestName();
      return d;
    }).catch(function () { return null; });
  };
  var scheduleUrlCheck = debounce(runUrlCheck, 450);

  // "Plain" paste and blur clean-up: trims, understands a pasted curl command and, when the platform's address has {parts} (ntfy topic ...),
  // splits a full address into them. Only done when the result is unambiguous; anything else is left exactly as typed.
  function tidyAddress(raw) {
    var text = String(raw || '').trim();
    var out = { value: text, notice: '' };
    if (/^curl\s/i.test(text)) {
      var m = text.match(/https?:\/\/[^\s'"\\]+/);
      if (m) {
        out.value = m[0];
        out.notice = 'Used the address from the pasted curl command.';
        var b = text.match(/-H\s+['"]Authorization:\s*Bearer\s+([^'"]+)['"]/i);
        var sel = $('[data-wz-auth-mode]');
        var tok = $('input[name=auth_token]');
        if (b && sel && tok && (sel.tagName === 'SELECT' ? $$('option', sel).some(function (o) { return o.value === 'bearer'; }) : sel.value === 'bearer')) {
          sel.value = 'bearer'; applyAuthMode(); tok.value = b[1].trim();
          out.notice += ' The bearer token was filled in too.';
        }
      }
    } else if (/\s/.test(text)) {
      out.value = text.split(/\s+/)[0];
    }
    var hint = urlInput ? urlInput.getAttribute('data-wz-hint') : '';
    if (hint && hint.indexOf('{') !== -1 && /^https?:\/\//i.test(out.value) && out.value.indexOf('{') === -1) {
      var names = [];
      var rx = '^' + hint.replace(/[.+?^$()|[\]\\*]/g, '\\$&').replace(/\{([a-z_]+)\}/g, function (all, n) { if (n === 'txn') { return '[^/?#]+'; } names.push(n); return '([^/?#]+)'; }) + '/?$';
      var mm = out.value.match(new RegExp(rx, 'i'));
      if (mm && names.length) {
        var ok = names.every(function (n) { return $('input[name="extra[' + n + ']"]'); });
        if (ok) {
          names.forEach(function (n, i) { var inp = $('input[name="extra[' + n + ']"]'); try { inp.value = decodeURIComponent(mm[i + 1]); } catch (e) { inp.value = mm[i + 1]; } });
          out.value = hint;
          out.notice = 'Split the address into its parts: ' + names.join(', ') + '.';
        }
      }
    }
    return out;
  }
  function applyTidy(res) {
    if (urlInput) { urlInput.value = res.value; }
    scheduleUrlCheck();
    if (res.notice && urlState) {
      urlState.className = 'wz-urlstate is-info'; urlState.textContent = ''; urlState.appendChild(icon('fas fa-magic mt-1')); urlState.appendChild(el('span', '', res.notice)); urlState.hidden = false;
      setTimeout(scheduleUrlCheck, 1200);
    }
  }
  if (urlInput) {
    urlInput.addEventListener('paste', function (e) {
      var cd = e.clipboardData || window.clipboardData;
      var text = cd ? cd.getData('text') : '';
      if (!text) { return; }
      var res = tidyAddress(text);
      if (res.value !== text || res.notice) {
        e.preventDefault();
        setFieldError(urlInput, '');
        applyTidy(res);
        urlInput.dispatchEvent(new Event('input', { bubbles: true }));
      }
    });
    urlInput.addEventListener('change', function () { var res = tidyAddress(urlInput.value); if (res.value !== urlInput.value || res.notice) { applyTidy(res); } });
    urlInput.addEventListener('input', function () { setFieldError(urlInput, ''); scheduleUrlCheck(); });
  }
  $$('input[name^="extra["]').forEach(function (i) { i.addEventListener('input', function () { setFieldError(i, ''); scheduleUrlCheck(); }); });

  // ---- advanced options disclosure (open / closed is remembered for the browser session) ----------------------------------
  var adv = $('[data-wz-advanced]');
  if (adv) {
    if (store('sessionStorage', 'wh_adv_open') === '1') { adv.open = true; }
    adv.addEventListener('toggle', function () { store('sessionStorage', 'wh_adv_open', adv.open ? '1' : '0'); });
  }
  function openAdvanced() { if (adv && !adv.open) { adv.open = true; } }

  // ---- events: quick presets -----------------------------------------------------------------------------------------
  function picker() { var r = $('[data-event-picker]'); return r && r._eventPicker && r._eventPicker.events && r._eventPicker.events.length ? r._eventPicker : null; }
  function presetCovered(p, vals) {
    return vals.every(function (v) {
      if (v === '*') { return p.pats.has('*'); }
      if (v.indexOf('*') !== -1) {
        if (p.pats.has(v) || p.pats.has('*')) { return true; }
        var ex = p.expand(v); return ex.length > 0 && ex.every(function (id) { return p.covered(id); });
      }
      return p.covered(v);
    });
  }
  function refreshPresetState() {
    var p = picker();
    $$('[data-wz-preset]').forEach(function (b) {
      var on = false;
      try { on = !!p && presetCovered(p, JSON.parse(b.getAttribute('data-values'))); } catch (e) { on = false; }
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    var field_ = $('[data-wz-events]'); if (field_ && picker() && selectedEventCount() > 0) { setFieldError($('[data-ep-search]', field_), ''); }
    var badge = $('[data-wz-evcount]'); if (badge) { badge.textContent = String(selectedEventCount()); }
    if (picker() && selectedValues().length) { $$('[data-wz-events]').forEach(function (f) { var pane = f.closest('[data-wz-step-panel],[data-wz-pane]'); var b = pane && $('[data-wz-steperrors]', pane); if (b) { b.hidden = true; b.textContent = ''; } }); }
  }
  function selectedValues() { return $$('[data-ep-values] input', $('[data-event-picker]') || root).map(function (i) { return i.value; }).filter(Boolean); }
  function selectedEventCount() {
    var cnt = $('[data-ep-count]'); var m = cnt && cnt.textContent.match(/^(\d+)/);
    return m ? parseInt(m[1], 10) : selectedValues().length;
  }
  root.addEventListener('click', function (e) {
    var chip = e.target.closest('[data-wz-preset]');
    if (!chip) { return; }
    var p = picker();
    if (!p) { return; }
    var vals = []; try { vals = JSON.parse(chip.getAttribute('data-values')); } catch (err) { return; }
    var pressed = chip.getAttribute('aria-pressed') === 'true';
    if (vals.length === 1 && vals[0] === '*') {
      p.ids.clear(); p.pats.clear();
      if (!pressed) { p.pats.add('*'); }
    } else if (pressed) {
      vals.forEach(function (v) { if (v.indexOf('*') !== -1) { p.pats.delete(v); } else { p.setEvent(v, false); } });
    } else {
      p.pats.delete('*');
      vals.forEach(function (v) { if (v.indexOf('*') !== -1) { p.pats.add(v); } else { p.ids.add(v); } });
    }
    p.normalize(); p.refresh();
  });
  root.addEventListener('change', function (e) { if (e.target.matches && e.target.matches('[data-event-picker]')) { refreshPresetState(); } });
  // The picker loads its catalog after the page: presets show their state once it has built.
  var presetTries = 0;
  (function waitForPicker() { if (picker()) { refreshPresetState(); } else if (presetTries++ < 40) { setTimeout(waitForPicker, 150); } })();

  // ---- send test / preview ---------------------------------------------------------------------------------------------
  function renderResult(box, d, opts) {
    opts = opts || {};
    box.hidden = false; box.className = 'wz-result'; box.textContent = '';
    if (!d) { return; }
    if (d.busy) {
      box.classList.add('is-busy');
      box.appendChild(el('span', 'text-secondary', d.busy));
      return;
    }
    if (d.errors) {
      box.classList.add('is-bad');
      box.appendChild(el('div', 'wz-result-head', 'The test could not be sent'));
      var ul = el('ul', 'mb-0 mt-1'); d.errors.forEach(function (m) { ul.appendChild(el('li', '', m)); }); box.appendChild(ul);
      return;
    }
    var r = d.result;
    box.classList.add(r.ok ? 'is-ok' : 'is-bad');
    var head = el('div', 'wz-result-head');
    head.appendChild(icon(r.ok ? 'fas fa-check-circle text-success' : 'fas fa-times-circle text-danger'));
    head.appendChild(el('span', '', r.ok ? 'Delivered' : 'Failed'));
    var stats = el('span', 'wz-result-stats');
    stats.appendChild(el('span', '', 'Status: ' + (r.http_status ? 'HTTP ' + r.http_status : 'no response')));
    stats.appendChild(el('span', '', 'Duration: ' + r.duration_ms + ' ms'));
    head.appendChild(stats);
    box.appendChild(head);
    if (r.error && !r.ok) { box.appendChild(el('div', 'text-danger small mt-1', r.error)); }
    if (r.hint) { box.appendChild(el('div', 'wz-result-hint small', r.hint)); }
    if (r.response) { box.appendChild(el('div', 'small text-secondary mt-2', 'Response from the receiver (cut):')); box.appendChild(el('pre', '', r.response)); }
    box.appendChild(el('div', 'small text-secondary mt-2', opts.saved ? 'A test event was sent through the real delivery path and logged once as a "test." entry.' : 'A test event was sent through the real delivery path. Nothing was saved or logged.'));
    box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }
  function runTest(btn, box, sampleSel) {
    btn.disabled = true;
    renderResult(box, { busy: 'Sending a test event...' });
    var extra = {};
    var sample = sampleSel || $('[data-wz-sample]');
    if (sample && sample.value) { extra.sample_event = sample.value; }
    post('test', extra).then(function (d) { btn.disabled = false; renderResult(box, d.ok ? d : { errors: d.errors || ['The test could not be sent.'] }); })
      .catch(function (e) { btn.disabled = false; renderResult(box, { errors: [e.message] }); });
  }
  function postSaved(extra) {
    var fd = new FormData(); fd.set('csrf_token', csrf()); fd.set('wh_action', 'test_saved'); fd.set('webhook_id', createdId || webhookId);
    Object.keys(extra || {}).forEach(function (k) { fd.set(k, extra[k]); });
    return fetch(actionUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }

  function renderPreview(box, d) {
    box.textContent = '';
    if (!d.ok) {
      box.appendChild(el('div', 'text-danger small', 'The preview needs these fixed first:'));
      var ul = el('ul', 'small text-danger mb-0'); (d.errors || []).forEach(function (m) { ul.appendChild(el('li', '', m)); }); box.appendChild(ul);
      return;
    }
    var p = d.preview;
    box.appendChild(el('div', 'wz-previewhead', p.method + ' ' + p.url + '   (body format: ' + p.format + ')'));
    box.appendChild(el('div', 'small text-secondary', 'Headers (secrets masked)'));
    box.appendChild(el('pre', 'wz-pre', p.headers.join('\n')));
    box.appendChild(el('div', 'small text-secondary mt-2', 'Body'));
    box.appendChild(el('pre', 'wz-pre wz-pre-body', p.body));
    box.appendChild(el('div', 'small text-secondary mt-1', 'Sample data only; nothing was sent. JSON is shown indented; the signature covers the compact bytes that are actually sent.'));
  }
  var previewSeq = 0;
  function loadPreview() {
    var box = $('[data-wz-preview]'), sel = $('[data-wz-sample]');
    if (!box) { return Promise.resolve(null); }
    var my = ++previewSeq;
    box.textContent = ''; box.appendChild(el('span', 'text-secondary small', 'Building the preview...'));
    return post('preview', sel && sel.value ? { sample_event: sel.value } : {}).then(function (d) {
      if (my !== previewSeq) { return d; }
      if (sel && d.events && !sel.options.length) {
        d.events.forEach(function (ev) { var o = el('option', '', ev); o.value = ev; if (ev === d.event) { o.selected = true; } sel.appendChild(o); });
      }
      renderPreview(box, d);
      return d;
    }).catch(function (e) { renderPreview(box, { ok: false, errors: [e.message] }); return null; });
  }
  root.addEventListener('change', function (e) { if (e.target.matches && e.target.matches('[data-wz-sample]')) { loadPreview(); } });
  root.addEventListener('click', function (e) {
    if (e.target.closest('[data-wz-refresh-preview]')) { loadPreview(); return; }
    var tb = e.target.closest('[data-wz-test]');
    if (tb) { runTest(tb, $('[data-wz-result]')); }
  });

  // ---- guide slide-over ----------------------------------------------------------------------------------------------------
  var slide = $('[data-wz-slideover]'), backdrop = $('[data-wz-backdrop]'), guideLoaded = '';
  var lastHelpTrigger = null;
  var dockMq = window.matchMedia ? window.matchMedia('(min-width: 1500px)') : { matches: false };
  // ---- guide sanitiser (rc.38, security review L8) --------------------------------------------------------------------
  // The guide fragment is edition HTML. It is rebuilt from an allow-list instead of imported: only formatting elements survive, an attribute is kept only if it is on
  // the short list below, a link keeps only an http(s) or mailto target (or a page-relative one), and an element that is not allowed loses its tag but keeps its text.
  var GUIDE_TAGS = ' a b blockquote br code dd details div dl dt em h1 h2 h3 h4 h5 h6 hr i img kbd li ol p pre samp small span strong sub summary sup table tbody td tfoot th thead tr u ul ';
  var GUIDE_DROP = ' script noscript style template iframe frame frameset object embed applet form input button select textarea option link meta base svg math audio video source track canvas title head ';
  function guideUrl(v) {
    try {
      var u = new URL(v, window.location.href);
      return (u.protocol === 'http:' || u.protocol === 'https:' || u.protocol === 'mailto:') ? v : null;
    } catch (e) { return null; }
  }
  function guideCopy(from, into) {
    Array.prototype.forEach.call(from.childNodes, function (n) {
      if (n.nodeType === 3) { into.appendChild(document.createTextNode(n.nodeValue)); return; }
      if (n.nodeType !== 1) { return; }
      var tag = n.nodeName.toLowerCase();
      if (GUIDE_DROP.indexOf(' ' + tag + ' ') !== -1) { return; }
      if (GUIDE_TAGS.indexOf(' ' + tag + ' ') === -1) { guideCopy(n, into); return; }
      var c = document.createElement(tag);
      var cls = n.getAttribute('class');
      if (cls && /^[A-Za-z0-9 _-]+$/.test(cls)) { c.setAttribute('class', cls); }
      var title = n.getAttribute('title');
      if (title) { c.setAttribute('title', title); }
      if (tag === 'a') {
        var href = n.getAttribute('href'), ok = href ? guideUrl(href) : null;
        if (ok !== null) {
          c.setAttribute('href', ok);
          if (/^https?:/i.test(ok)) { c.setAttribute('target', '_blank'); }
          c.setAttribute('rel', 'noopener noreferrer');
        }
      } else if (tag === 'img') {
        var src = n.getAttribute('src'), good = src ? guideUrl(src) : null;
        if (good === null || /^mailto:/i.test(good)) { return; }
        c.setAttribute('src', good); c.setAttribute('alt', n.getAttribute('alt') || ''); c.setAttribute('loading', 'lazy');
      } else if (tag === 'td' || tag === 'th') {
        ['colspan', 'rowspan'].forEach(function (a) { var v = n.getAttribute(a); if (v && /^[0-9]{1,2}$/.test(v)) { c.setAttribute(a, v); } });
      }
      guideCopy(n, c);
      into.appendChild(c);
    });
  }
  function appendGuide(target, html) {
    var doc = new DOMParser().parseFromString('<div>' + html + '</div>', 'text/html');
    guideCopy(doc.body.firstChild, target);
  }
  function loadGuide() {
    var id = destId; if (!id || guideLoaded === id) { return; }
    guideLoaded = id;
    var body = $('[data-wz-guide-body]');
    fetch(root.getAttribute('data-wz-guide') + '?dest=' + encodeURIComponent(id), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
      .then(function (d) {
        body.textContent = '';
        appendGuide(body, String(d.content));
      }).catch(function (err) { guideLoaded = ''; body.textContent = 'The guide could not be loaded (' + err.message + '). Open the full guide from the Guides page.'; });
  }
  function openGuide(trigger) {
    if (!slide) { return; }
    lastHelpTrigger = trigger || null;
    slide.hidden = false;
    var dock = dockMq.matches;
    root.classList.toggle('has-dock', dock);
    if (backdrop) { backdrop.hidden = dock; }
    loadGuide();
    var close = $('[data-wz-guide-close]'); if (close) { close.focus(); }
  }
  function closeGuide() {
    if (!slide || slide.hidden) { return false; }
    slide.hidden = true; if (backdrop) { backdrop.hidden = true; }
    root.classList.remove('has-dock');
    if (lastHelpTrigger && document.contains(lastHelpTrigger)) { lastHelpTrigger.focus(); }
    return true;
  }
  root.addEventListener('click', function (e) {
    var h = e.target.closest('[data-wz-help]');
    if (h) { slide && !slide.hidden ? closeGuide() : openGuide(h); return; }
    if (e.target.closest('[data-wz-guide-close]')) { closeGuide(); }
  });
  if (backdrop) { backdrop.addEventListener('click', closeGuide); }

  // ---- shared field wiring -----------------------------------------------------------------------------------------------
  var authSel = $('[data-wz-auth-mode]');
  if (authSel) { authSel.addEventListener('change', applyAuthMode); applyAuthMode(); }
  var tplInput = $('[data-wz-tpl-input]');
  if (tplInput) { tplInput.addEventListener('input', checkTemplate); var te = $('[data-wz-tpl-enc]'); if (te) { te.addEventListener('change', checkTemplate); } checkTemplate(); }
  form.addEventListener('input', function (e) { var t = e.target; if (t.matches && t.matches('input,textarea,select')) { var f = field(t); if (f && f.classList.contains('is-invalid') && t.value) { setFieldError(t, ''); } } });

  // required fields of one panel; returns [{input, message}]
  function requiredProblems(panel) {
    var out = [];
    $$('[data-wz-required]', panel).forEach(function (i) {
      if (!shown(i) || i.disabled) { return; }
      if (String(i.value).trim() === '') { out.push({ input: i, message: 'This is required.' }); }
    });
    var mode_ = $('[data-wz-auth-mode]', panel);
    if (mode_ && mode_.value === 'basic') { var u = $('[data-wz-required-if-basic]', panel); if (u && u.value.trim() === '') { out.push({ input: u, message: 'This is required.' }); } }
    var hn = $('input[name=auth_header_name]', panel);
    if (mode_ && mode_.value === 'header' && hn && hn.value.trim() === '') { out.push({ input: hn, message: 'This is required.' }); }
    return out;
  }
  function showProblems(list) {
    list.forEach(function (p) { setFieldError(p.input, p.message); });
    if (list.length) {
      var first = list[0].input;
      var d = first.closest('details'); if (d && !d.open) { d.open = true; }
      first.focus();
    }
  }
  function classify(msg) { return /event/i.test(msg) && !/webhook event rules?/i.test(msg) ? 'events' : 'connect'; }
  function eventsProblem() {
    var p = $('[data-event-picker]');
    if (!p) { return null; }
    return selectedValues().length ? null : 'Choose at least one event, or use a quick pick above.';
  }

  // ===================================================================================================================
  // ADD: the stepper
  // ===================================================================================================================
  var createdId = '';
  if (mode === 'add') {
    var ORDER = ['platform', 'connect', 'events', 'review'];
    var LABELS = { platform: 'Platform', connect: 'Connect', events: 'Events', review: 'Review & test' };
    var avail = destId ? ORDER : ['platform'];
    var panels = {};
    $$('[data-wz-step-panel]').forEach(function (p) { panels[p.getAttribute('data-wz-step-panel')] = p; });
    var current = avail.indexOf(root.getAttribute('data-wz-step')) >= 0 ? root.getAttribute('data-wz-step') : avail[0];
    var maxReached = 0;
    var bar = $('[data-wz-bar]'), backBtn = $('[data-wz-back]'), nextBtn = $('[data-wz-next]'), ctBtn = $('[data-wz-create-test]'), keyHint = $('[data-wz-keyhint]');
    var creating = false;

    var stepUrl = function (name) {
      var q = []; if (destId) { q.push('dest=' + encodeURIComponent(destId)); }
      q.push('step=' + name);
      return location.pathname + '?' + q.join('&');
    };

    function render(name, opts) {
      opts = opts || {};
      var idx = name === 'done' ? avail.length : avail.indexOf(name);
      if (idx < 0) { return; }
      current = name;
      maxReached = Math.max(maxReached, idx);
      Object.keys(panels).forEach(function (k) {
        var on = k === name;
        panels[k].hidden = !on;
        panels[k].classList.toggle('is-active', on);
      });
      $$('[data-wz-stepnav]').forEach(function (li) {
        var k = li.getAttribute('data-wz-stepnav'), i = ORDER.indexOf(k);
        li.classList.toggle('is-current', k === name);
        li.classList.toggle('is-done', i < idx);
        var b = li.querySelector('button');
        b.disabled = !(i < idx) || name === 'done';
        if (k === name) { b.setAttribute('aria-current', 'step'); } else { b.removeAttribute('aria-current'); }
      });
      var pct = name === 'done' ? 100 : Math.round(((idx + 1) / ORDER.length) * 100);
      if (bar) { bar.style.width = pct + '%'; }
      var sc = $('[data-wz-stepcount]'); if (sc) { sc.textContent = name === 'done' ? 'All done' : 'Step ' + (idx + 1) + ' of ' + ORDER.length + ': ' + LABELS[name]; }
      var isDone = name === 'done';
      $('[data-wz-footer]').hidden = isDone || name === 'platform';
      if (backBtn) { backBtn.hidden = idx === 0; }
      if (nextBtn) { nextBtn.textContent = ''; var review = name === 'review'; nextBtn.appendChild(document.createTextNode(review ? 'Create webhook' : 'Continue')); nextBtn.appendChild(icon(review ? 'fas fa-check ms-2' : 'fas fa-arrow-right ms-2')); }
      if (ctBtn) { ctBtn.hidden = name !== 'review'; }
      if (keyHint) { keyHint.textContent = name === 'review' ? 'Enter to create, Esc to go back' : 'Enter to continue, Esc to go back'; }
      if (!opts.noHistory) {
        var st = { step: name };
        if (opts.push) { history.pushState(st, '', stepUrl(name)); } else { history.replaceState(st, '', stepUrl(name)); }
      }
      if (name === 'review') { buildReview(); }
      if (!opts.noFocus) {
        var h = panels[name] && panels[name].querySelector('.wz-h');
        if (h) { h.focus({ preventScroll: true }); }
        var top = root.getBoundingClientRect().top;
        if (top < 0) { root.scrollIntoView({ block: 'start' }); }
      }
    }
    function go(name, push) { render(name, { push: push !== false }); }

    function validateStep(name) {
      var panel = panels[name];
      clearErrors(panel);
      if (name === 'connect') {
        if (nameInput && nameInput.value.trim() === '') { suggestName(); }
        var probs = requiredProblems(panel);
        if (probs.length) { showProblems(probs); return Promise.resolve(false); }
        return runUrlCheck().then(function (r) {
          // the address problems are shown on the field itself; stop here when the check already says no
          if (r && !r.ok && r.state !== 'empty' && r.state !== 'incomplete' && r.state !== 'private') { if (urlInput) { urlInput.focus(); } return false; }
          return post('validate', { scope: 'connect' }).then(function (d) {
            if (d.ok) { return true; }
            var msgs = (d.steps || []).map(function (s) { return s.message; });
            if ((d.steps || []).some(function (s) { return s.step === 'advanced'; })) { openAdvanced(); }
            stepErrors(panel, msgs.length ? msgs : (d.errors || ['Check the fields above.']));
            return false;
          });
        }).catch(function (e) { stepErrors(panel, ['Could not check this step: ' + e.message]); return false; });
      }
      if (name === 'events') {
        var ep = eventsProblem();
        if (ep) { var s = $('[data-ep-search]', panel); if (s) { setFieldError(s, ep); } stepErrors(panel, [ep]); return Promise.resolve(false); }
      }
      return Promise.resolve(true);
    }

    function next() {
      var i = avail.indexOf(current);
      if (current === 'review') { create(false); return; }
      if (i < 0 || i >= avail.length - 1) { return; }
      nextBtn && (nextBtn.disabled = true);
      validateStep(current).then(function (ok) { nextBtn && (nextBtn.disabled = false); if (ok) { go(avail[i + 1]); } });
    }
    function back() { var i = avail.indexOf(current); if (i > 0) { go(avail[i - 1]); } }

    if (nextBtn) { nextBtn.addEventListener('click', next); }
    if (backBtn) { backBtn.addEventListener('click', back); }
    if (ctBtn) { ctBtn.addEventListener('click', function () { create(true); }); }
    $$('[data-wz-stepnav] button').forEach(function (b) {
      b.addEventListener('click', function () { var k = b.parentNode.getAttribute('data-wz-stepnav'); if (avail.indexOf(k) >= 0 && avail.indexOf(k) < avail.indexOf(current)) { go(k); } });
    });
    window.addEventListener('popstate', function (e) {
      var s = (e.state && e.state.step) || new URLSearchParams(location.search).get('step') || avail[0];
      if (avail.indexOf(s) >= 0) { render(s, { noHistory: true }); }
    });

    // keyboard: Enter continues (except in multi-line fields, buttons, links and the picker), Esc goes back / closes the guide
    root.addEventListener('keydown', function (e) {
      var t = e.target;
      if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey) {
        if (t.tagName === 'TEXTAREA' || t.tagName === 'BUTTON' || t.tagName === 'A' || t.tagName === 'SUMMARY' || t.tagName === 'SELECT' || t.type === 'checkbox' || t.hasAttribute('data-ep-search') || t.hasAttribute('data-wz-psearch')) { return; }
        if (current === 'platform' || current === 'done') { return; }
        e.preventDefault(); next();
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape' || e.defaultPrevented) { return; }
      if (document.querySelector('.modal.show')) { return; }
      if (closeGuide()) { e.preventDefault(); return; }
      if (current !== 'platform' && current !== 'done' && avail.indexOf(current) > 0) { e.preventDefault(); back(); }
    });
    form.addEventListener('submit', function (e) { e.preventDefault(); if (current !== 'platform' && current !== 'done') { next(); } });

    // ---- platform step ---------------------------------------------------------------------------------------------
    var chooser = $('[data-wz-chooser]');
    if (chooser) {
      var psearch = $('[data-wz-psearch]'), recentBox = $('[data-wz-recent]'), popular = $('[data-wz-popular]'), pnone = $('[data-wz-pnone]');
      var cat = '';
      var applyFilter = function () {
        var q = psearch.value.toLowerCase().trim(), any = false, filtering = q !== '' || cat !== '';
        $$('[data-wz-cat-block] .wz-pcard', chooser).forEach(function (c) {
          var show = (!q || c.getAttribute('data-wz-search').indexOf(q) !== -1) && (!cat || c.getAttribute('data-wz-cat') === cat);
          c.hidden = !show; if (show) { any = true; }
        });
        $$('[data-wz-cat-block]', chooser).forEach(function (b) { b.hidden = !b.querySelector('.wz-pcard:not([hidden])'); });
        if (popular) { popular.hidden = filtering; }
        if (recentBox) { recentBox.hidden = filtering || !$('[data-wz-recent-grid]').children.length; }
        pnone.hidden = any;
      };
      psearch.addEventListener('input', applyFilter);
      psearch.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); var first = $('.wz-pcard:not([hidden])', $('[data-wz-cat-block]:not([hidden])', chooser) || chooser); if (first) { location.href = first.href; } }
      });
      $$('[data-wz-catchip]', chooser).forEach(function (b) {
        b.addEventListener('click', function () {
          cat = b.getAttribute('data-wz-catchip');
          $$('[data-wz-catchip]', chooser).forEach(function (o) { var on = o === b; o.classList.toggle('is-on', on); o.setAttribute('aria-pressed', on ? 'true' : 'false'); });
          applyFilter();
        });
      });
      // Recently used: cards cloned from the grid for the platforms this browser created webhooks for last
      var recent = []; try { recent = JSON.parse(store('localStorage', 'wh_recent_dest') || '[]'); } catch (e) { recent = []; }
      var grid = $('[data-wz-recent-grid]');
      (Array.isArray(recent) ? recent : []).slice(0, 4).forEach(function (id) {
        var src = chooser.querySelector('[data-wz-cat-block] [data-wz-dest="' + String(id).replace(/[^a-z0-9-]/g, '') + '"]');
        if (src) { var c = src.cloneNode(true); c.removeAttribute('data-wz-search'); c.classList.add('is-recent'); grid.appendChild(c); }
      });
      if (grid.children.length) { recentBox.hidden = false; }
      if (current === 'platform' && psearch && !location.hash) { setTimeout(function () { psearch.focus({ preventScroll: true }); }, 0); }
    }

    // ---- review ----------------------------------------------------------------------------------------------------
    var AUTH_LABEL = { none: 'None', hmac: 'Our signature only', bearer: 'Bearer token', basic: 'Basic (username and password)', header: 'Custom header' };
    function addRow(dl, label, valueNode) { dl.appendChild(el('dt', '', label)); var dd = el('dd'); if (typeof valueNode === 'string') { dd.textContent = valueNode; } else { dd.appendChild(valueNode); } dl.appendChild(dd); return dd; }
    function buildReview() {
      var dl = $('[data-wz-summary]'); dl.textContent = '';
      var panel = panels.review;
      clearErrors(panel);
      addRow(dl, 'Platform', destName);
      addRow(dl, 'Name', nameInput ? nameInput.value.trim() || '(not set)' : '');
      var addr = addRow(dl, 'Address', 'Checking...');
      var vals = selectedValues(), count = selectedEventCount();
      var ev = el('span');
      ev.appendChild(el('strong', '', count + (count === 1 ? ' event' : ' events')));
      var chips = el('span', 'ms-2');
      vals.slice(0, 6).forEach(function (v) { chips.appendChild(el('span', 'badge ' + (v.indexOf('*') !== -1 ? 'text-bg-primary' : 'text-bg-secondary') + ' me-1', v === '*' ? 'All events' : v)); });
      if (vals.length > 6) { chips.appendChild(el('span', 'badge text-bg-light', '+' + (vals.length - 6) + ' more')); }
      ev.appendChild(chips);
      addRow(dl, 'Events', ev);
      var fmtRow = addRow(dl, 'Payload format', '...');
      var authMode = $('[data-wz-auth-mode]');
      if (authMode) {
        var am = authMode.value, secretSet = {
          bearer: ($('input[name=auth_token]') || {}).value, basic: ($('input[name=auth_password]') || {}).value, header: ($('input[name=auth_header_value]') || {}).value
        }[am];
        addRow(dl, 'Authentication', (AUTH_LABEL[am] || am) + (am === 'bearer' || am === 'basic' || am === 'header' ? (secretSet ? ': credential set (hidden)' : ': no credential entered') : ''));
      }
      var sec = $('input[name=webhook_secret]');
      if (sec) {
        var srow = el('span');
        srow.appendChild(document.createTextNode(sec.value ? '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022 ' : (destId === 'slack' ? 'None (Slack buttons off)' : 'None ')));
        if (sec.value) {
          var cb = el('button', 'btn btn-sm btn-outline-secondary py-0', 'Copy'); cb.type = 'button';
          cb.addEventListener('click', function () { copyText(sec.value, function () { cb.textContent = 'Copied'; setTimeout(function () { cb.textContent = 'Copy'; }, 1400); }); });
          srow.appendChild(cb);
        }
        addRow(dl, destId === 'slack' ? 'Signing secret (Slack)' : 'Signing secret', srow);
      }
      var prio = $('select[name=webhook_min_priority]'), cl = $('select[name="webhook_client_ids[]"]');
      var filt = [];
      if (prio && prio.value) { filt.push(prio.value + ' priority and above'); }
      if (cl) { var n = $$('option:checked', cl).length; if (n) { filt.push(n + (n === 1 ? ' client' : ' clients')); } }
      if (filt.length) { addRow(dl, 'Filters', filt.join(', ')); }
      var res = $('[data-wz-result]', panel); if (res) { res.hidden = true; }
      loadPreview().then(function (d) {
        if (!d) { addr.textContent = '(unknown)'; fmtRow.textContent = '(unknown)'; return; }
        if (!d.ok) {
          addr.textContent = 'Needs attention'; fmtRow.textContent = '-';
          var msgs = d.errors || [];
          stepErrors(panel, msgs, msgs.map(function (m) { var s = classify(m); return { label: s === 'events' ? 'Go to Events' : 'Go to Connect', go: function () { go(s); } }; }));
          return;
        }
        var p = d.preview;
        var host = '';
        try { host = new URL(p.url.replace('/\u2026hidden', '/')).host; } catch (e) { host = p.url; }
        addr.textContent = host; addr.title = 'Only the host is shown: the rest of the address is a secret.';
        fmtRow.textContent = p.format + ' over ' + p.method;
      });
    }

    // ---- create ------------------------------------------------------------------------------------------------------
    function create(andTest) {
      if (creating) { return; }
      creating = true;
      [nextBtn, ctBtn].forEach(function (b) { if (b) { b.disabled = true; } });
      var panel = panels.review; clearErrors(panel);
      var fd = new FormData(form);
      fd.set('add_webhook', '1'); fd.set('wh_action', ''); fd.delete('wh_action'); fd.set('wh_ajax', '1');
      fetch(form.getAttribute('action'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json().catch(function () { throw new Error('The server answered HTTP ' + r.status + '. Your changes were not saved.'); }); })
        .then(function (d) {
          creating = false; [nextBtn, ctBtn].forEach(function (b) { if (b) { b.disabled = false; } });
          if (!d.ok) {
            var msgs = d.errors || ['The webhook could not be created.'];
            stepErrors(panel, msgs, msgs.map(function (m) { var s = classify(m); return { label: s === 'events' ? 'Go to Events' : 'Go to Connect', go: function () { go(s); } }; }));
            return;
          }
          createdId = String(d.id);
          var rec = []; try { rec = JSON.parse(store('localStorage', 'wh_recent_dest') || '[]'); } catch (e) { rec = []; }
          rec = [destId].concat((Array.isArray(rec) ? rec : []).filter(function (x) { return x !== destId; })).slice(0, 4);
          store('localStorage', 'wh_recent_dest', JSON.stringify(rec));
          showDone(d, andTest);
        })
        .catch(function (e) { creating = false; [nextBtn, ctBtn].forEach(function (b) { if (b) { b.disabled = false; } }); stepErrors(panel, [e.message]); });
    }
    function showDone(d, andTest) {
      render('done', { noHistory: true });
      $('[data-wz-donetitle]').textContent = d.name + ' is ready';
      var enabled = $('#wz-enabled') && $('#wz-enabled').checked;
      var n = selectedEventCount();
      $('[data-wz-donesub]').textContent = destName + ' webhook created with ' + n + (n === 1 ? ' event' : ' events') + (enabled ? ' and switched on.' : '. It is switched off until you enable it.');
      var dl = $('[data-wz-deliveries]'); if (dl) { dl.href = editUrl + '?id=' + encodeURIComponent(d.id) + '&tab=deliveries'; }
      history.replaceState({ step: 'done' }, '', editUrl + '?id=' + encodeURIComponent(d.id));
      if (andTest) { doneTest(); }
    }
    function doneTest() {
      var box = $('[data-wz-doneresult]'), btn = $('[data-wz-testagain]');
      btn.disabled = true; renderResult(box, { busy: 'Sending a test event...' });
      postSaved().then(function (d) { btn.disabled = false; renderResult(box, d.ok ? d : { errors: d.errors || ['The test could not be sent.'] }, { saved: true }); })
        .catch(function (e) { btn.disabled = false; renderResult(box, { errors: [e.message] }); });
    }
    var again = $('[data-wz-testagain]'); if (again) { again.addEventListener('click', doneTest); }

    render(current, { noFocus: false });
    if (current === 'platform') { /* focus goes to the search box */ var ps = $('[data-wz-psearch]'); if (ps) { ps.focus({ preventScroll: true }); } }
  }

  // ===================================================================================================================
  // EDIT: tabs, unsaved-changes warning, live enable switch, save
  // ===================================================================================================================
  if (mode === 'edit') {
    var tabs = $$('[data-wz-tab]'), panes = {};
    $$('[data-wz-pane]').forEach(function (p) { panes[p.getAttribute('data-wz-pane')] = p; });
    var savebar = $('[data-wz-savebar]');
    var activeTab = root.getAttribute('data-wz-initial-tab') || 'connection';
    function activate(name, opts) {
      if (!panes[name]) { name = 'connection'; }
      activeTab = name;
      Object.keys(panes).forEach(function (k) { var on = k === name; panes[k].hidden = !on; panes[k].classList.toggle('is-active', on); });
      tabs.forEach(function (b) { var on = b.getAttribute('data-wz-tab') === name; b.setAttribute('aria-selected', on ? 'true' : 'false'); b.tabIndex = on ? 0 : -1; });
      if (savebar) { savebar.hidden = name === 'deliveries'; }
      if (!(opts && opts.noHistory)) {
        var u = new URL(location.href); u.searchParams.set('tab', name); u.searchParams.delete('saved'); history.replaceState(null, '', u.toString());
      }
      if (name === 'advanced') { loadPreview(); }
      if (opts && opts.focus) { var pt = panes[name].querySelector('input:not([type=hidden]),select,textarea'); if (pt && name !== 'deliveries') { pt.focus({ preventScroll: true }); } }
    }
    tabs.forEach(function (b, i) {
      b.addEventListener('click', function () { activate(b.getAttribute('data-wz-tab')); });
      b.addEventListener('keydown', function (e) {
        var j = e.key === 'ArrowRight' ? i + 1 : (e.key === 'ArrowLeft' ? i - 1 : -1);
        if (j >= 0) { j = (j + tabs.length) % tabs.length; tabs[j].focus(); activate(tabs[j].getAttribute('data-wz-tab')); e.preventDefault(); }
      });
    });
    activate(activeTab, { noHistory: true });

    // dirty tracking: what the form would post, with the picker's selection normalised, compared with the first snapshot
    var baseline = null, dirty = false, saving = false;
    function signature() {
      var parts = [];
      $$('input, select, textarea', form).concat($$('input[form="wz-form"]')).forEach(function (i) {
        if (!i.name || i.name === 'csrf_token' || i.hasAttribute('data-wz-toggle-live') || i.closest('[data-ep-values]') || i.type === 'search') { return; }
        if (i.type === 'checkbox' || i.type === 'radio') { parts.push(i.name + '=' + (i.checked ? i.value : '')); }
        else if (i.tagName === 'SELECT' && i.multiple) { parts.push(i.name + '=' + $$('option:checked', i).map(function (o) { return o.value; }).join(',')); }
        else { parts.push(i.name + '=' + i.value); }
      });
      parts.push('events=' + selectedValues().slice().sort().join(','));
      return parts.join('\u0001');
    }
    function setDirty(on) {
      dirty = on;
      var d = $('[data-wz-dirty]'); if (d) { d.hidden = !on; }
    }
    function snap() { if (baseline === null) { baseline = signature(); } }
    form.addEventListener('change', function (e) { if (e.target.matches && e.target.matches('[data-event-picker]')) { snap(); } });
    setTimeout(snap, 1500);
    var recheck = function () { if (baseline !== null) { setDirty(signature() !== baseline); } };
    form.addEventListener('input', recheck); form.addEventListener('change', recheck);
    window.addEventListener('beforeunload', function (e) { if (dirty && !saving) { e.preventDefault(); e.returnValue = ''; } });

    var sw = $('[data-wz-switch]');
    if (sw) {
      sw.addEventListener('change', function () {
        // only follow a relative path: never a scheme (javascript:, data:) or a protocol-relative URL
        var base = (sw.getAttribute('data-wz-switch') || '').trim();
        if (/^\s*([a-z][a-z0-9+.-]*:|\/\/|\\)/i.test(base)) { return; }
        // resolve against this origin and navigate only to a same-origin URL, so the value can never change origin or scheme
        var target;
        try { target = new URL(base + encodeURIComponent(sw.value), location.href); } catch (e) { return; }
        if (target.origin !== location.origin) { return; }
        location.assign(target.pathname + target.search + target.hash);
      });
    }

    // the enable switch saves at once
    var tog = $('[data-wz-toggle-live]');
    if (tog) {
      tog.addEventListener('change', function () {
        var on = tog.checked;
        var fd = new FormData(); fd.set('csrf_token', csrf()); fd.set('toggle_webhook', '1'); fd.set('wh_ajax', '1'); fd.set('webhook_id', webhookId); fd.set('enabled', on ? '1' : '0');
        tog.disabled = true;
        fetch(form.getAttribute('action'), { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
          tog.disabled = false;
          if (!d.ok) { throw new Error((d.errors || ['Could not change that.'])[0]); }
          var lab = $('[data-wz-switch-label]'); if (lab) { lab.textContent = on ? 'Enabled' : 'Disabled'; }
          var badge = $('[data-wz-statusbadge]'); if (badge && !on) { badge.className = 'badge text-bg-secondary'; badge.textContent = 'Disabled'; }
          renderResult($('[data-wz-result]'), { busy: on ? 'Enabled. Events are sent again.' : 'Disabled. No events are sent until you turn it back on.' });
          var rb = $('[data-wz-result]'); rb.classList.remove('is-busy'); rb.classList.add('is-ok');
        }).catch(function (err) { tog.disabled = false; tog.checked = !on; renderResult($('[data-wz-result]'), { errors: [err.message] }); });
      });
    }

    // save
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (saving) { return; }
      Object.keys(panes).forEach(function (k) { clearErrors(panes[k]); });
      var probs = requiredProblems(panes.connection).concat(requiredProblems(panes.advanced || panes.connection));
      if (probs.length) { activate('connection'); showProblems(probs); return; }
      var ep = eventsProblem();
      if (ep) { activate('events'); stepErrors(panes.events, [ep]); return; }
      saving = true;
      var btn = $('[data-wz-save]'); btn.disabled = true;
      var fd = new FormData(form); fd.set('edit_webhook', '1'); fd.set('wh_ajax', '1');
      fetch(form.getAttribute('action'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json().catch(function () { throw new Error('The server answered HTTP ' + r.status + '. Nothing was saved.'); }); })
        .then(function (d) {
          if (!d.ok) {
            saving = false; btn.disabled = false;
            var msgs = d.errors || ['Not saved.'];
            var tabFor = function (m) { return /event/i.test(m) ? 'events' : (/template|secret|method|header|bearer|token|password|authentication|username|body/i.test(m) ? 'connection' : 'connection'); };
            var first = tabFor(msgs[0]);
            activate(first);
            stepErrors(panes[first], msgs);
            return;
          }
          saving = true; setDirty(false);
          var u = new URL(location.href); u.searchParams.set('tab', activeTab); u.searchParams.set('saved', '1');
          location.href = u.toString();
        })
        .catch(function (err) { saving = false; btn.disabled = false; stepErrors(panes[activeTab] || panes.connection, [err.message]); });
    });
    // Enter in a text field must not save by accident
    form.addEventListener('keydown', function (e) {
      var t = e.target;
      if (e.key === 'Enter' && t.tagName === 'INPUT' && t.type !== 'submit' && t.type !== 'button' && t.type !== 'checkbox') { e.preventDefault(); }
    });
    var sp = new URLSearchParams(location.search);
    if (sp.get('saved') === '1') {
      var rb = $('[data-wz-result]'); renderResult(rb, { busy: 'Saved.' }); rb.classList.remove('is-busy'); rb.classList.add('is-ok');
      var u2 = new URL(location.href); u2.searchParams.delete('saved'); history.replaceState(null, '', u2.toString());
    }
  }
})();
