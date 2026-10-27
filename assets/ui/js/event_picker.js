// Searchable event picker. Markup: includes/event_picker.php (eventPickerField()). Vanilla; each picker is set up as it appears in the
// page (a MutationObserver also catches AJAX-loaded modals). The catalog is fetched once per page from /modals/event_catalog.php.
//
// Selection model: `ids` (events ticked one by one) and `pats` (wildcard patterns such as "ticket.*" or "*"). An event is selected when it
// is in `ids` or matched by a pattern. After every change `normalize()` folds a fully selected group into its pattern when the group
// is a clean prefix ("ticket.*" covers exactly the ticket events), so what is stored stays short and also covers future events of that
// family. Patterns this script did not create (e.g. "auth.login_*") are kept as they are until the user changes them.
(function () {
  'use strict';

  var catalogCache = {};

  function loadCatalog(url) {
    if (catalogCache[url]) { return catalogCache[url]; }
    catalogCache[url] = fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
      .catch(function (e) { delete catalogCache[url]; throw e; });
    return catalogCache[url];
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined && text !== null) { n.textContent = text; }
    return n;
  }

  // "*" matches any run of characters, dots included (same rule as RivetCore EventCatalog::matchPattern()).
  function patternRegex(p) {
    return new RegExp('^' + p.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$');
  }

  // Same idea as EventCatalog::search(): every term must match; id exact > id prefix > label prefix > id/label contains > tag > description.
  function scoreEvent(e, terms) {
    var total = 0;
    for (var i = 0; i < terms.length; i++) {
      var t = terms[i], id = e.id.toLowerCase(), label = e.label.toLowerCase(), s = 0;
      if (id === t) { s = 100; }
      else if (id.indexOf(t) === 0) { s = 80; }
      else if (label.indexOf(t) === 0) { s = 70; }
      else if (id.indexOf(t) !== -1 || label.indexOf(t) !== -1) { s = 50; }
      else if ((e.tags || []).some(function (g) { return g.toLowerCase().indexOf(t) !== -1; })) { s = 30; }
      else if ((e.description || '').toLowerCase().indexOf(t) !== -1) { s = 10; }
      if (s === 0) { return 0; }
      total += s;
    }
    return total;
  }

  function Picker(root) {
    this.root = root;
    this.name = root.getAttribute('data-name');
    this.single = root.getAttribute('data-mode') === 'single';
    this.ids = new Set();
    this.pats = new Set();
    this.events = [];       // [{id, group, groupLabel, label, description, severity, since, tags}]
    this.byId = {};
    this.groups = [];       // [{key, label, ids:[], pattern:string|'' , open:bool, nodes}]
    this.rows = {};         // id -> {row, box}
    this.query = '';
    this.valuesBox = root.querySelector('[data-ep-values]');
    var self = this;
    Array.prototype.forEach.call(this.valuesBox.querySelectorAll('input'), function (i) {
      var v = (i.value || '').trim();
      if (!v) { return; }
      if (v.indexOf('*') !== -1) { self.pats.add(v); } else { self.ids.add(v); }
    });
    this.bind();
    var urlAttr = root.getAttribute('data-catalog-url');
    loadCatalog(urlAttr).then(function (cat) { self.build(cat); }).catch(function () {
      self.q('[data-ep-groups]').textContent = '';
      self.q('[data-ep-groups]').appendChild(el('div', 'ep-loading text-danger', 'The event list could not be loaded. Reload the page and try again.'));
    });
    this.renderSummary();
  }

  Picker.prototype.q = function (sel) { return this.root.querySelector(sel); };

  Picker.prototype.allIds = function () { return this.events.map(function (e) { return e.id; }); };

  Picker.prototype.matches = function (pat, id) {
    if (pat === '*') { return true; }
    if (!this._re) { this._re = {}; }
    return (this._re[pat] || (this._re[pat] = patternRegex(pat))).test(id);
  };

  Picker.prototype.expand = function (pat) {
    var self = this;
    return this.allIds().filter(function (id) { return self.matches(pat, id); });
  };

  Picker.prototype.covered = function (id) {
    if (this.ids.has(id)) { return true; }
    var hit = false;
    this.pats.forEach(function (p) { if (!hit && this.matches(p, id)) { hit = true; } }, this);
    return hit;
  };

  Picker.prototype.build = function (cat) {
    var self = this;
    this.events = cat.events.slice();
    var other = [];
    try { other = JSON.parse(this.root.getAttribute('data-other') || '[]'); } catch (e) { other = []; }
    var known = {};
    this.events.forEach(function (e) { known[e.id] = true; });
    other.forEach(function (id) {
      if (known[id]) { return; }
      self.events.push({ id: id, group: '_other', groupLabel: 'Other events seen on this server', label: id, description: 'Recorded in the audit trail on this server; not in the catalog.', severity: 'info', since: null, tags: [] });
    });
    this.events.forEach(function (e) { self.byId[e.id] = e; });

    var order = Object.keys(cat.groups);
    order.push('_other');
    order.forEach(function (key) {
      var list = self.events.filter(function (e) { return e.group === key; });
      if (!list.length) { return; }
      self.groups.push({ key: key, label: list[0].groupLabel, ids: list.map(function (e) { return e.id; }), pattern: self.groupPattern(key, list), open: false });
    });

    // A saved selection that names events this install no longer lists keeps them (they show as chips and are submitted unchanged).
    this.ids.forEach(function (id) {
      if (!self.byId[id]) {
        self.byId[id] = { id: id, group: '_unknown', groupLabel: 'Unknown', label: id, description: '', severity: 'info', since: null, tags: [] };
      }
    });
    this.normalize();
    this.renderGroups();
    this.refresh();
    // Open the groups that already hold a selection, so an edit form shows what is chosen.
    this.groups.forEach(function (g) { if (g.count > 0 && !self.single) { self.setOpen(g, true); } });
    if (this.single) {
      var sel = Array.from(this.ids)[0];
      this.groups.forEach(function (g) { if (sel && g.ids.indexOf(sel) !== -1) { self.setOpen(g, true); } });
    }
  };

  // The wildcard that stands for exactly this group, or '' (e.g. "ticket.*" when every ticket.* event is in the group).
  Picker.prototype.groupPattern = function (key, list) {
    if (key === '_other' || list.length < 2) { return ''; }
    var first = list[0].id, prefix = first;
    list.forEach(function (e) { while (prefix && e.id.indexOf(prefix) !== 0) { prefix = prefix.slice(0, -1); } });
    var cut = Math.max(prefix.lastIndexOf('.'), prefix.lastIndexOf('_'));
    if (cut < 2) { return ''; }
    var pat = prefix.slice(0, cut + 1) + '*';
    var re = patternRegex(pat), inGroup = {};
    list.forEach(function (e) { inGroup[e.id] = true; });
    var all = this.events.filter(function (e) { return re.test(e.id); });
    return all.length === list.length && all.every(function (e) { return inGroup[e.id]; }) ? pat : '';
  };

  Picker.prototype.normalize = function () {
    if (this.single) { return; }
    var self = this;
    if (this.pats.has('*')) { this.pats.clear(); this.pats.add('*'); this.ids.clear(); return; }
    var cands = ['*'];
    this.groups.forEach(function (g) { if (g.pattern) { cands.push(g.pattern); } });
    cands.forEach(function (c) {
      if (self.pats.has(c)) { return; }
      var exp = self.expand(c);
      if (exp.length && exp.every(function (id) { return self.covered(id); })) {
        if (c === '*') { self.pats.clear(); self.ids.clear(); self.pats.add('*'); return; }
        self.pats.add(c);
        exp.forEach(function (id) { self.ids.delete(id); });
        // A narrower custom pattern is now redundant.
        Array.from(self.pats).forEach(function (p) {
          if (p !== c && p !== '*' && self.expand(p).every(function (id) { return self.matches(c, id); })) { self.pats.delete(p); }
        });
      }
    });
  };

  Picker.prototype.setEvent = function (id, on) {
    var self = this;
    if (this.single) { this.ids.clear(); if (on) { this.ids.add(id); } return; }
    if (on) { this.ids.add(id); return; }
    this.ids.delete(id);
    Array.from(this.pats).forEach(function (p) {
      if (self.matches(p, id)) {
        self.pats.delete(p);
        self.expand(p).forEach(function (x) { if (x !== id) { self.ids.add(x); } });
      }
    });
  };

  Picker.prototype.visibleIds = function (g) {
    var self = this;
    return g.ids.filter(function (id) { return !self.query || self.rows[id].visible; });
  };

  Picker.prototype.bind = function () {
    var self = this, root = this.root;
    this.q('[data-ep-search]').addEventListener('input', function (e) { self.query = e.target.value; self.applySearch(); });
    this.q('[data-ep-search]').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); }
      if (e.key === 'Escape' && e.target.value) { e.target.value = ''; self.query = ''; self.applySearch(); e.stopPropagation(); }
    });
    var clear = this.q('[data-ep-clear]');
    if (clear) { clear.addEventListener('click', function () { self.ids.clear(); self.pats.clear(); self.refresh(); }); }
    var all = this.q('[data-ep-all]');
    if (all) {
      all.addEventListener('change', function () {
        self.ids.clear(); self.pats.clear();
        if (all.checked) { self.pats.add('*'); }
        self.refresh();
      });
    }
    root.addEventListener('click', function (e) {
      var rm = e.target.closest('[data-ep-remove]');
      if (rm) {
        var v = rm.getAttribute('data-ep-remove');
        if (self.pats.has(v)) { self.pats.delete(v); } else { self.setEvent(v, false); }
        self.refresh();
        return;
      }
      var head = e.target.closest('[data-ep-toggle]');
      if (head) {
        var g = self.groups[parseInt(head.getAttribute('data-ep-toggle'), 10)];
        self.setOpen(g, !g.open);
      }
    });
    root.addEventListener('change', function (e) {
      var t = e.target;
      if (t.matches('[data-ep-event]')) {
        self.setEvent(t.value, t.checked);
        self.normalize();
        self.refresh();
      } else if (t.matches('[data-ep-group]')) {
        var g = self.groups[parseInt(t.getAttribute('data-ep-group'), 10)];
        var ids = self.visibleIds(g);
        var allOn = ids.every(function (id) { return self.covered(id); });
        ids.forEach(function (id) { self.setEvent(id, !allOn); });
        self.normalize();
        self.refresh();
      }
    });
  };

  Picker.prototype.setOpen = function (g, open) {
    g.open = open;
    g.nodes.body.hidden = !open;
    g.nodes.head.setAttribute('aria-expanded', open ? 'true' : 'false');
    g.nodes.caret.className = 'fas ' + (open ? 'fa-chevron-down' : 'fa-chevron-right') + ' ep-caret';
  };

  Picker.prototype.renderGroups = function () {
    var self = this, host = this.q('[data-ep-groups]');
    host.textContent = '';
    this.groups.forEach(function (g, gi) {
      var sec = el('section', 'ep-group');
      var head = el('div', 'ep-group-head');
      var bodyId = self.root.id + '-g' + gi;
      var toggle = el('button', 'ep-group-toggle');
      toggle.type = 'button';
      toggle.setAttribute('data-ep-toggle', String(gi));
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-controls', bodyId);
      var caret = el('i', 'fas fa-chevron-right ep-caret');
      caret.setAttribute('aria-hidden', 'true');
      var title = el('span', 'ep-group-title', g.label);
      var count = el('span', 'ep-group-count');
      toggle.appendChild(caret); toggle.appendChild(title); toggle.appendChild(count);
      head.appendChild(toggle);
      var box = null;
      if (!self.single) {
        var lab = el('label', 'ep-group-all');
        box = el('input', 'form-check-input');
        box.type = 'checkbox';
        box.setAttribute('data-ep-group', String(gi));
        box.setAttribute('aria-label', 'Select all ' + g.label + ' events');
        var txt = el('span', 'ep-group-all-text', 'Select all');
        lab.appendChild(box); lab.appendChild(txt);
        head.appendChild(lab);
        if (g.pattern) { head.appendChild(el('code', 'ep-group-pattern', g.pattern)); }
        g.nodes = { sec: sec, head: toggle, caret: caret, count: count, box: box, txt: txt };
      } else {
        g.nodes = { sec: sec, head: toggle, caret: caret, count: count, box: null, txt: null };
      }
      var body = el('div', 'ep-group-body');
      body.id = bodyId;
      body.hidden = true;
      g.ids.forEach(function (id) {
        var e = self.byId[id];
        var row = el('label', 'ep-row');
        var inp = el('input', 'form-check-input');
        inp.type = self.single ? 'radio' : 'checkbox';
        if (self.single) { inp.name = self.root.id + '-choice'; }
        inp.value = id;
        inp.setAttribute('data-ep-event', '');
        var main = el('span', 'ep-row-main');
        var line = el('span', 'ep-row-line');
        line.appendChild(el('span', 'ep-row-label', e.label !== id ? e.label : ''));
        line.appendChild(el('code', 'ep-row-id', id));
        line.appendChild(el('span', 'ep-sev ep-sev-' + (e.severity || 'info'), e.severity || 'info'));
        if (e.since === 'planned') {
          var pl = el('span', 'ep-planned', 'planned');
          pl.title = 'Reserved name: not emitted everywhere yet';
          line.appendChild(pl);
        }
        main.appendChild(line);
        if (e.description) { main.appendChild(el('span', 'ep-row-desc', e.description)); }
        row.appendChild(inp); row.appendChild(main);
        body.appendChild(row);
        self.rows[id] = { row: row, box: inp, visible: true, score: 0 };
      });
      g.nodes.body = body;
      sec.appendChild(head); sec.appendChild(body);
      host.appendChild(sec);
    });
    host.appendChild(el('div', 'ep-none text-secondary', 'No events match your search.')).hidden = true;
    this.noneNode = host.lastChild;
  };

  Picker.prototype.applySearch = function () {
    var self = this;
    var terms = this.query.toLowerCase().split(/\s+/).filter(Boolean).slice(0, 8);
    var anyGroup = false;
    this.groups.forEach(function (g, gi) {
      var best = 0, shown = 0;
      g.ids.forEach(function (id) {
        var r = self.rows[id], s = terms.length ? scoreEvent(self.byId[id], terms) : 1;
        r.visible = s > 0;
        r.score = s;
        r.row.hidden = !r.visible;
        r.row.style.order = String(1000 - s);
        if (r.visible) { shown++; best = Math.max(best, s); }
      });
      g.nodes.sec.hidden = shown === 0;
      g.nodes.sec.style.order = String(1000 - best);
      if (shown) { anyGroup = true; }
      if (terms.length) { self.setOpen(g, shown > 0); }
    });
    if (!terms.length) { this.groups.forEach(function (g) { self.setOpen(g, g.count > 0 && !self.single); }); }
    this.noneNode.hidden = anyGroup || !this.events.length;
    this.refresh();
  };

  Picker.prototype.label = function (id) {
    var e = this.byId[id];
    return e && e.label && e.label !== id ? e.label : id;
  };

  Picker.prototype.renderSummary = function () {
    var self = this;
    var total = 0;
    if (this.events.length) {
      total = this.events.filter(function (e) { return self.covered(e.id); }).length;
      this.ids.forEach(function (id) { if (!self.byId[id] || self.byId[id].group === '_unknown') { total++; } });
    } else { total = this.ids.size + this.pats.size; }
    var n = this.single ? (this.ids.size ? 1 : 0) : total;
    this.q('[data-ep-count]').textContent = this.single ? (n ? '1 event chosen' : 'No event chosen') : (n === 1 ? '1 event selected' : n + ' events selected');
    var clear = this.q('[data-ep-clear]');
    if (clear) { clear.hidden = n === 0; }
  };

  Picker.prototype.refresh = function () {
    var self = this;
    // hidden inputs: what the form submits
    this.valuesBox.textContent = '';
    var vals = this.single ? Array.from(this.ids) : Array.from(this.pats).concat(Array.from(this.ids));
    if (this.single && !vals.length) { vals = ['']; }
    vals.forEach(function (v) {
      var i = document.createElement('input');
      i.type = 'hidden'; i.name = self.name; i.value = v;
      self.valuesBox.appendChild(i);
    });
    // required-ness for single mode forms: tell the browser nothing is chosen (the server re-checks)
    var all = this.q('[data-ep-all]');
    if (all) { all.checked = this.pats.has('*'); }

    // rows and groups
    this.groups.forEach(function (g) {
      var n = 0;
      g.ids.forEach(function (id) {
        var on = self.covered(id);
        if (on) { n++; }
        var r = self.rows[id];
        r.box.checked = on;
        r.row.classList.toggle('is-selected', on);
      });
      g.count = n;
      g.nodes.count.textContent = n > 0 ? n + ' of ' + g.ids.length + ' selected' : g.ids.length + (g.ids.length === 1 ? ' event' : ' events');
      g.nodes.count.classList.toggle('has-selection', n > 0);
      if (g.nodes.box) {
        var vis = self.visibleIds(g), onVis = vis.filter(function (id) { return self.covered(id); }).length;
        g.nodes.box.checked = vis.length > 0 && onVis === vis.length;
        g.nodes.box.indeterminate = onVis > 0 && onVis < vis.length;
        g.nodes.txt.textContent = self.query ? (g.nodes.box.checked ? 'Clear matches' : 'Select matches') : (g.nodes.box.checked ? 'Clear all' : 'Select all');
      }
    });

    // chips
    var chips = this.q('[data-ep-chips]');
    chips.textContent = '';
    function chip(text, title, value, aria) {
      var c = el('span', 'ep-chip');
      c.setAttribute('role', 'listitem');
      c.title = title || '';
      c.appendChild(el('span', 'ep-chip-text', text));
      var b = el('button', 'ep-chip-x');
      b.type = 'button';
      b.setAttribute('data-ep-remove', value);
      b.setAttribute('aria-label', 'Remove ' + aria);
      b.textContent = '\u00d7';
      c.appendChild(b);
      chips.appendChild(c);
    }
    this.pats.forEach(function (p) {
      var n = self.events.length ? self.expand(p).length : 0;
      chip(p === '*' ? 'All events' : p, p === '*' ? 'Every event, including ones added later' : 'Every event matching ' + p + ' (' + n + ' now)', p, p === '*' ? 'all events' : p);
      chips.lastChild.classList.add('ep-chip-pattern');
      if (n) { chips.lastChild.querySelector('.ep-chip-text').textContent = (p === '*' ? 'All events' : p) + ' · ' + n; }
    });
    var shown = 0;
    this.ids.forEach(function (id) {
      if (shown++ < 60) { chip(self.label(id), id, id, self.label(id)); }
    });
    if (this.ids.size > 60) { chips.appendChild(el('span', 'ep-chip-more text-secondary', '+' + (this.ids.size - 60) + ' more')); }
    chips.hidden = !chips.children.length;
    this.renderSummary();
    this.root.dispatchEvent(new Event('change', { bubbles: true }));
  };

  function init(root) {
    if (root._eventPicker) { return; }
    root._eventPicker = new Picker(root);
  }

  function scan(scope) {
    var list = (scope || document).querySelectorAll ? (scope || document).querySelectorAll('[data-event-picker]') : [];
    Array.prototype.forEach.call(list, init);
    if (scope && scope.matches && scope.matches('[data-event-picker]')) { init(scope); }
  }

  function start() {
    scan(document);
    new MutationObserver(function (muts) {
      muts.forEach(function (m) {
        Array.prototype.forEach.call(m.addedNodes, function (n) { if (n.nodeType === 1) { scan(n); } });
      });
    }).observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
  window.RivetEventPicker = { scan: scan };
})();
