@extends('layouts.default')

<!-- Heading -->
@section('content')
<div class="headingWrapper">
  <div class="container adminHeading" role="banner">
    <span class="text-center">
      <h1><a href="{{ url('dashboard') }}">Data Dashboard</a></h1>
      <p>Counts of everything that has been imported: records per table, distinct people and IDs, the most common values (gender, city, state, types, statuses...) and trends over time. Groups of fewer than <span id="db-min">10</span> records are hidden.</p>
    </span>
  </div>
</div>

<hr/>

<style>
  .db-tiles { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 18px; }
  .db-tile { flex: 1 1 180px; border: 1px solid #ddd; border-radius: 4px; background: #fff; padding: 12px 14px; }
  .db-tile .n { font-size: 28px; font-weight: 700; line-height: 1.1; }
  .db-tile .l { color: #666; font-size: 13px; }
  .db-tile .s { color: #888; font-size: 11px; }
  .db-bar { display: flex; align-items: center; gap: 8px; margin: 3px 0; font-size: 13px; }
  .db-bar .name { flex: 0 0 34%; max-width: 34%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .db-bar .track { flex: 1 1 auto; background: #f1f0ea; border-radius: 3px; height: 14px; }
  .db-bar .fill { background: #2a78d6; height: 14px; border-radius: 3px; min-width: 2px; }
  .db-bar .val { flex: 0 0 auto; min-width: 120px; text-align: right; color: #333; font-variant-numeric: tabular-nums; }
  .db-bar .val small { color: #888; }
  .db-card { border: 1px solid #ddd; border-radius: 4px; background: #fff; margin-bottom: 10px; }
  .db-card-head { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 10px 14px; }
  .db-card-head .t { font-weight: 700; font-size: 15px; }
  .db-card-head .c { color: #777; font-size: 12px; }
  .db-card-head .r { margin-left: auto; font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; }
  .db-card-head .r small { font-size: 11px; font-weight: 400; color: #777; }
  .db-chip { display: inline-block; background: #eef3fb; border-radius: 10px; padding: 1px 9px; font-size: 12px; margin: 2px 4px 2px 0; }
  .db-badge { font-size: 11px; border-radius: 3px; padding: 1px 6px; }
  .db-badge.ready { background: #e3f4ec; color: #146c43; }
  .db-badge.stale { background: #fff3cd; color: #7a5b00; }
  .db-badge.missing { background: #eee; color: #555; }
  .db-badge.partial { background: #fde8e8; color: #8a1f1f; }
  .db-detail { border-top: 1px solid #eee; padding: 12px 14px; }
  .db-detail h4 { margin: 14px 0 6px; font-size: 14px; }
  .db-detail h4:first-child { margin-top: 0; }
  .db-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 14px 24px; }
  .db-block .bt { font-weight: 600; margin-bottom: 2px; }
  .db-note { color: #777; font-size: 12px; }
  .db-controls { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; align-items: center; }
  .db-controls input, .db-controls select { max-width: 260px; }
  .db-msg { margin-left: 8px; color: #555; font-size: 13px; }
</style>

<div class="container" role="main">
  <div id="db-error" class="alert alert-danger" style="display:none"></div>
  <div class="db-tiles" id="db-tiles" aria-live="polite"></div>

  <h3 style="margin-top:0">Records per table</h3>
  <div id="db-records" class="well" style="background:#fff"></div>

  <h3>Tables</h3>
  <div class="db-controls">
    <select id="db-collection" class="form-control" aria-label="Collection"><option value="">All collections</option></select>
    <input id="db-search" type="search" class="form-control" placeholder="Find a table" aria-label="Find a table">
    <span id="db-admin" style="display:none">
      <button type="button" class="btn btn-default btn-sm" id="db-refresh-all">Refresh all counts</button>
      <span class="db-msg" id="db-refresh-msg"></span>
    </span>
  </div>
  <div id="db-tables"></div>
</div>

<script src="{{ asset('js/chart-interactive.js') }}"></script>
<script>
(function () {
  var CSRF = {!! json_encode(csrf_token()) !!};
  var URLS = { data: {!! json_encode(url('dashboard/data')) !!}, table: {!! json_encode(url('dashboard/table')) !!}, refresh: {!! json_encode(url('dashboard/refresh')) !!} };
  var STATE = { tables: [], overview: null, canRefresh: false };
  var DETAIL = {};          // table id -> payload (loaded when a card is opened)

  function $(id) { return document.getElementById(id); }
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text !== undefined) { e.textContent = text; } return e; }
  function num(v) { return v === null || v === undefined ? '–' : Number(v).toLocaleString('en-US'); }
  function fail(msg) { var b = $('db-error'); b.textContent = msg; b.style.display = ''; }
  function getJSON(url) {
    return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) {
      return r.json().then(function (j) { if (!r.ok) { throw new Error(j.error || j.message || ('Request failed (' + r.status + ')')); } return j; });
    });
  }
  function postJSON(url, body) {
    return fetch(url, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(body) }).then(function (r) {
      return r.json().then(function (j) { if (!r.ok) { throw new Error(j.error || j.message || ('Request failed (' + r.status + ')')); } return j; });
    });
  }

  // ---- headline tiles and records per table
  function tile(n, l, s) {
    var t = el('div', 'db-tile'); t.appendChild(el('div', 'n', n)); t.appendChild(el('div', 'l', l));
    if (s) { t.appendChild(el('div', 's', s)); }
    return t;
  }
  function renderOverview() {
    var o = STATE.overview, box = $('db-tiles'); box.innerHTML = '';
    box.appendChild(tile(num(o.records), 'Records', o.with_counts < o.tables ? 'in ' + o.with_counts + ' of ' + o.tables + ' tables counted so far' : 'in all ' + o.tables + ' tables'));
    box.appendChild(tile(num(o.collections), 'Collections'));
    box.appendChild(tile(num(o.tables), 'Tables'));
    box.appendChild(tile(num(o.people), 'Distinct people', o.people === null ? 'no person or constituent ID column counted yet' : 'from ' + o.people_from));
  }
  function renderRecords() {
    var box = $('db-records'); box.innerHTML = '';
    var rows = STATE.tables.filter(function (t) { return t.rows !== null; }).sort(function (a, b) { return b.rows - a.rows; });
    if (!rows.length) { box.appendChild(el('div', 'db-note', 'No table has been counted yet. Counts are made after each import; an administrator can refresh them below.')); return; }
    var max = rows[0].rows || 1, limit = box.getAttribute('data-all') ? rows.length : 15;
    rows.slice(0, limit).forEach(function (t) {
      var b = el('div', 'db-bar'); b.appendChild(el('div', 'name', t.name)); b.lastChild.title = t.name + ' (' + t.collection + ')';
      var tr = el('div', 'track'), f = el('div', 'fill'); f.style.width = Math.max(0.5, 100 * t.rows / max) + '%'; tr.appendChild(f); b.appendChild(tr);
      b.appendChild(el('div', 'val', num(t.rows)));
      box.appendChild(b);
    });
    if (rows.length > limit) {
      var more = el('button', 'btn btn-link btn-sm', 'Show all ' + rows.length + ' tables'); more.type = 'button';
      more.onclick = function () { box.setAttribute('data-all', '1'); renderRecords(); };
      box.appendChild(more);
    }
  }

  // ---- table cards
  function badge(t) {
    var span = el('span', 'db-badge ' + t.status);
    span.textContent = t.status === 'ready' ? 'Up to date' : t.status === 'stale' ? 'Imported since counted' : 'Not counted yet';
    if (t.computed_at) { span.title = 'Counted ' + t.computed_at; }
    return span;
  }
  function renderTables() {
    var box = $('db-tables'), col = $('db-collection').value, q = $('db-search').value.trim().toLowerCase();
    box.innerHTML = '';
    var list = STATE.tables.filter(function (t) { return (!col || String(t.collection_id) === col) && (!q || t.name.toLowerCase().indexOf(q) >= 0); });
    if (!list.length) { box.appendChild(el('div', 'db-note', STATE.tables.length ? 'No table matches.' : 'There are no tables you can see yet.')); return; }
    list.forEach(function (t) { box.appendChild(card(t)); });
  }
  function card(t) {
    var c = el('div', 'db-card'), head = el('div', 'db-card-head');
    var left = el('div'); left.appendChild(el('div', 't', t.name)); left.appendChild(el('div', 'c', t.collection)); head.appendChild(left);
    head.appendChild(badge(t));
    if (t.partial) { var p = el('span', 'db-badge partial', 'Partly counted'); p.title = 'Counting this table hit the time limit; some breakdowns are missing.'; head.appendChild(p); }
    var r = el('div', 'r', t.rows === null ? '' : num(t.rows)); r.appendChild(el('small', '', t.rows === null ? 'not counted yet' : ' records')); head.appendChild(r);
    c.appendChild(head);

    if (t.ids && t.ids.length) {
      var chips = el('div'); chips.style.padding = '0 14px 6px';
      t.ids.forEach(function (i) { chips.appendChild(el('span', 'db-chip', num(i.count) + ' distinct ' + i.label)); });
      c.appendChild(chips);
    }

    var actions = el('div'); actions.style.cssText = 'padding:0 14px 10px';
    var detail = el('div', 'db-detail'); detail.style.display = 'none';
    if (t.rows !== null && (t.breakdowns || t.trends)) {
      var open = el('button', 'btn btn-default btn-sm', 'Show ' + [t.breakdowns ? t.breakdowns + ' breakdown' + (t.breakdowns > 1 ? 's' : '') : '', t.trends ? t.trends + ' trend' + (t.trends > 1 ? 's' : '') : ''].filter(Boolean).join(' and '));
      open.type = 'button'; open.setAttribute('aria-expanded', 'false');
      open.onclick = function () {
        var shown = detail.style.display !== 'none';
        if (shown) { detail.style.display = 'none'; open.setAttribute('aria-expanded', 'false'); return; }
        detail.style.display = ''; open.setAttribute('aria-expanded', 'true');
        if (DETAIL[t.table_id]) { return; }
        detail.textContent = 'Loading...';
        getJSON(URLS.table + '/' + t.table_id).then(function (j) { DETAIL[t.table_id] = j; renderDetail(detail, j); })
          .catch(function (e) { detail.textContent = e.message; });
      };
      actions.appendChild(open);
    }
    if (STATE.canRefresh) {
      var rf = el('button', 'btn btn-link btn-sm', 'Refresh counts'); rf.type = 'button';
      rf.onclick = function () { queueRefresh({ table_id: t.table_id }, rf); };
      actions.appendChild(rf);
    }
    c.appendChild(actions); c.appendChild(detail);
    return c;
  }

  var GROUPS = [['distribution', 'Who and where'], ['code', 'Types, statuses and codes'], ['other', 'Other fields with few values']];
  function renderDetail(box, j) {
    box.innerHTML = '';
    var p = j.payload, rows = p.rows || 1;
    if (j.status === 'stale') { box.appendChild(el('div', 'alert alert-warning', 'Records were imported after these counts were made. They will update when the next count finishes.')); }
    GROUPS.forEach(function (g) {
      var items = p.breakdowns.filter(function (b) { return b.group === g[0]; });
      if (!items.length) { return; }
      box.appendChild(el('h4', '', g[1]));
      var grid = el('div', 'db-grid');
      items.forEach(function (b) { grid.appendChild(breakdown(b, rows)); });
      box.appendChild(grid);
    });
    if (p.trends.length) {
      box.appendChild(el('h4', '', 'Over time'));
      p.trends.forEach(function (t) { var d = el('div'); d.style.marginBottom = '14px'; box.appendChild(d); window.CCDTChart ? window.CCDTChart.render(d, t.shaped) : d.appendChild(el('div', 'db-note', t.label)); });
    }
    if (!p.breakdowns.length && !p.trends.length) { box.appendChild(el('div', 'db-note', 'Nothing to break down in this table.')); }
    var foot = 'Counted ' + (j.computed_at || '') + (p.seconds ? ' in ' + p.seconds + ' s' : '') + '.';
    if (p.skipped && p.skipped.length) { foot += ' Skipped: ' + p.skipped.join(', ') + '.'; }
    box.appendChild(el('div', 'db-note', foot));
  }
  function breakdown(b, rows) {
    var d = el('div', 'db-block'), s = b.shaped, vals = s.series.length ? s.series[0].values : [];
    d.appendChild(el('div', 'bt', b.label));
    var max = 0; vals.forEach(function (v) { if (v !== null && v > max) { max = v; } });
    s.x.forEach(function (name, i) {
      var v = vals[i]; if (v === null || v === undefined) { return; }
      var r = el('div', 'db-bar'); r.appendChild(el('div', 'name', name)); r.lastChild.title = name;
      var tr = el('div', 'track'), f = el('div', 'fill'); f.style.width = Math.max(0.5, 100 * v / (max || 1)) + '%'; tr.appendChild(f); r.appendChild(tr);
      var val = el('div', 'val', num(v)); val.appendChild(el('small', '', '  ' + (100 * v / rows).toFixed(v / rows < 0.1 ? 1 : 0) + '%')); r.appendChild(val);
      d.appendChild(r);
    });
    var note = 'Top ' + s.x.length + (s.suppressed ? '; ' + s.suppressed + ' group' + (s.suppressed > 1 ? 's' : '') + ' under ' + s.min_cell_size + ' hidden' : '');
    d.appendChild(el('div', 'db-note', note + '. Percent of all records in the table.'));
    return d;
  }

  function queueRefresh(body, btn) {
    btn.disabled = true;
    postJSON(URLS.refresh, body).then(function (j) {
      var msg = $('db-refresh-msg'); msg.textContent = j.queued + ' table' + (j.queued === 1 ? '' : 's') + ' queued. Reload this page in a few minutes.';
      if (btn.id !== 'db-refresh-all') { btn.textContent = 'Queued'; }
    }).catch(function (e) { fail(e.message); btn.disabled = false; });
  }

  // ---- start
  getJSON(URLS.data).then(function (j) {
    STATE.tables = j.tables; STATE.overview = j.overview; STATE.canRefresh = j.can_refresh;
    $('db-min').textContent = j.min_cell_size;
    var sel = $('db-collection');
    j.overview.by_collection.forEach(function (c) { var o = el('option', '', c.name + ' (' + num(c.records) + ')'); o.value = String(c.id); sel.appendChild(o); });
    if (j.can_refresh) { $('db-admin').style.display = ''; $('db-refresh-all').onclick = function () { queueRefresh({ all: 1 }, this); }; }
    sel.onchange = renderTables; $('db-search').oninput = renderTables;
    renderOverview(); renderRecords(); renderTables();
  }).catch(function (e) { fail(e.message); });
})();
</script>
@endsection
