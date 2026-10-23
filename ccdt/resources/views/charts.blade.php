@extends('layouts.default')

<!-- Heading -->
@section('content')
<div class="headingWrapper">
  <div class="container adminHeading" role="banner">
    <span class="text-center">
      <h1><a href="{{ url('charts') }}">Chart Builder</a></h1>
      <p>Draw charts from an imported table. Charts are counts and totals only; names, contact details and free text are not available.</p>
    </span>
  </div>
</div>

<hr/>

<style>
  .cb-panel label { font-weight: 600; margin-bottom: 2px; }
  .cb-panel .form-group { margin-bottom: 10px; }
  .cb-filter { display: flex; gap: 4px; margin-bottom: 4px; }
  .cb-filter select, .cb-filter input { min-width: 0; }
  .cb-stage { min-height: 320px; border: 1px solid #ddd; border-radius: 4px; padding: 10px; background: #fff; }
  .cb-stage img { max-width: 100%; height: auto; }
  .cb-viewmode { margin-bottom: 8px; }
  .cb-viewmode .active { background: #e6e6e6; font-weight: 600; }
  .cb-meta { color: #666; font-size: 12px; margin-top: 6px; }
  .cb-placeholder { color: #777; padding: 60px 10px; text-align: center; }
  .cb-busy { opacity: .45; }
  #cb-data-wrap { max-height: 280px; overflow: auto; margin-top: 8px; }
</style>

<div class="container-fluid" role="main">
  <div class="row">

    <!-- Controls -->
    <div class="col-md-4 cb-panel">
      <div class="well">
        <div class="form-group">
          <label for="cb-table">Table</label>
          <select id="cb-table" class="form-control">
            <option value="">Choose a table...</option>
            @foreach($tables as $t)
              <option value="{{ $t->id }}">{{ $t->tblNme }} ({{ $t->collection->clctnName }})</option>
            @endforeach
          </select>
        </div>

        <div class="form-group" id="cb-join-wrap" style="display:none">
          <label for="cb-join">Combine with another table <small>(same collection)</small></label>
          <select id="cb-join" class="form-control"></select>
          <div id="cb-joinkey-wrap" style="display:none; margin-top:6px">
            <label for="cb-joinkey" class="small">Linked by</label>
            <select id="cb-joinkey" class="form-control input-sm"></select>
            <p class="help-block small" id="cb-joinhelp" style="margin-bottom:0"></p>
          </div>
        </div>

        <div class="form-group" id="cb-starter-wrap" style="display:none">
          <label for="cb-starter">Start from a ready-made chart</label>
          <select id="cb-starter" class="form-control"></select>
        </div>

        <div class="form-group">
          <label for="cb-chart">Chart type</label>
          <select id="cb-chart" class="form-control">
            <option value="bar">Bar (vertical)</option>
            <option value="hbar">Bar (horizontal, ranked)</option>
            <option value="line">Line over time</option>
            <option value="stacked">Stacked bar</option>
            <option value="hist">Histogram</option>
            <option value="heatmap">Heatmap</option>
          </select>
        </div>

        <div class="form-group" id="cb-xmode-wrap" style="display:none">
          <label for="cb-xmode">Histogram of</label>
          <select id="cb-xmode" class="form-control">
            <option value="days">Days between two date fields</option>
            <option value="number">A numeric field</option>
          </select>
        </div>

        <div class="form-group" id="cb-x-wrap">
          <label for="cb-x" id="cb-x-label">X axis field</label>
          <select id="cb-x" class="form-control"></select>
        </div>

        <div class="form-group" id="cb-to-wrap" style="display:none">
          <label for="cb-to">To (end date field)</label>
          <select id="cb-to" class="form-control"></select>
        </div>

        <div class="form-group" id="cb-bucket-wrap">
          <label for="cb-bucket">Group dates by</label>
          <select id="cb-bucket" class="form-control">
            <option value="none">Don't group (use values as they are)</option>
            <option value="month">Month (YYYYMMDD date fields)</option>
            <option value="year">Year (YYYYMMDD date fields)</option>
          </select>
        </div>

        <div class="form-group" id="cb-bin-wrap" style="display:none">
          <label for="cb-bin">Bin width</label>
          <select id="cb-bin" class="form-control">
            <option value="1">1</option><option value="7" selected>7 (weeks, for days)</option>
            <option value="10">10</option><option value="30">30 (months, for days)</option>
            <option value="100">100</option><option value="1000">1000</option>
          </select>
        </div>

        <div class="form-group" id="cb-group-wrap">
          <label for="cb-group">Split by (optional)</label>
          <select id="cb-group" class="form-control"></select>
        </div>

        <div class="form-group">
          <label for="cb-measure">Show</label>
          <div style="display:flex; gap:4px">
            <select id="cb-measure" class="form-control">
              <option value="count">Number of records</option>
              <option value="count_distinct">Number of different values of...</option>
              <option value="sum">Total of...</option>
              <option value="avg">Average of...</option>
            </select>
            <select id="cb-measure-col" class="form-control" style="display:none"></select>
          </div>
        </div>

        <div class="form-group">
          <label>Filters</label>
          <div id="cb-filters"></div>
          <button type="button" class="btn btn-default btn-xs" id="cb-add-filter">Add filter</button>
        </div>

        <div class="form-group" id="cb-limit-wrap">
          <label for="cb-limit">Most frequent values to show</label>
          <input type="number" id="cb-limit" class="form-control" min="1" max="50" value="20">
        </div>

        <div class="form-group">
          <label for="cb-title">Title (optional)</label>
          <input type="text" id="cb-title" class="form-control" maxlength="120">
        </div>

        <button type="button" class="btn btn-primary" id="cb-draw">Draw chart</button>
        <button type="button" class="btn btn-default" id="cb-save" disabled>Save chart</button>
        <a class="btn btn-default" id="cb-download" download="chart.png" style="display:none">Download PNG</a>
      </div>

      <div class="well" id="cb-saved-wrap" @if(count($saved) == 0) style="display:none" @endif>
        <label for="cb-saved">Saved charts</label>
        <div style="display:flex; gap:4px">
          <select id="cb-saved" class="form-control"></select>
          <button type="button" class="btn btn-default" id="cb-saved-open">Open</button>
          <button type="button" class="btn btn-danger" id="cb-saved-del" title="Delete saved chart">Delete</button>
        </div>
      </div>
    </div>

    <!-- Chart -->
    <div class="col-md-8">
      <div class="cb-stage" id="cb-stage" aria-live="polite">
        <div class="cb-placeholder" id="cb-placeholder">Choose a table, then pick a ready-made chart or build your own.</div>
        <div class="alert alert-danger" id="cb-error" style="display:none" role="alert"></div>
        <div class="cb-viewmode" id="cb-viewmode" style="display:none">
          <div class="btn-group btn-group-sm" role="group" aria-label="Chart view">
            <button type="button" class="btn btn-default" id="cb-mode-image" aria-pressed="true">Image</button>
            <button type="button" class="btn btn-default" id="cb-mode-live" aria-pressed="false">Hover and zoom</button>
          </div>
        </div>
        <img id="cb-img" alt="" style="display:none">
        <div id="cb-live" style="display:none"></div>
        <div class="cb-meta" id="cb-meta"></div>
      </div>
      <p><a href="#" id="cb-toggle-data">Show the numbers behind the chart</a></p>
      <div id="cb-data-wrap" style="display:none"><table class="table table-condensed" id="cb-data"></table></div>
    </div>
  </div>
</div>

<script src="{{ asset('js/chart-interactive.js') }}"></script>
<script>
(function () {
  var SAVED = {!! json_encode($saved->map(function ($c) { return ['id' => $c->id, 'name' => $c->name, 'table_id' => $c->table_id, 'spec' => $c->specArray()]; })->values(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
  var PRELOAD = {!! json_encode($preload ? $preload->id : null) !!};
  var CSRF = {!! json_encode(csrf_token()) !!};
  var URLS = { describe: {!! json_encode(url('charts/table')) !!}, render: {!! json_encode(url('charts/render')) !!},
               save: {!! json_encode(url('charts/save')) !!}, del: {!! json_encode(url('charts/delete')) !!} };
  var OPS = [['eq', 'is'], ['neq', 'is not'], ['in', 'is one of (comma separated)'], ['gte', '>= (number or YYYYMMDD)'], ['lte', '<= (number or YYYYMMDD)'],
             ['between', 'between (two numbers, comma separated)'], ['prefix', 'starts with'], ['blank', 'is blank'], ['notblank', 'is not blank']];

  function $(id) { return document.getElementById(id); }
  var meta = { columns: {}, starters: [] };
  var timer = null, seq = 0, lastSpec = null, loading = false, lastResult = null;
  var mode = 'image';
  try { if (window.localStorage.getItem('ccdt-chart-mode') === 'live') { mode = 'live'; } } catch (e) { /* storage may be unavailable */ }

  // Image (matplotlib PNG) or Hover and zoom (SVG drawn in the browser from the same numbers)
  function showMode() {
    if (!lastResult) { return; }
    var live = mode === 'live' && window.CCDTChart && lastResult.chart;
    $('cb-viewmode').style.display = window.CCDTChart && lastResult.chart ? '' : 'none';
    $('cb-img').style.display = live ? 'none' : '';
    $('cb-live').style.display = live ? '' : 'none';
    $('cb-mode-image').classList.toggle('active', !live); $('cb-mode-image').setAttribute('aria-pressed', String(!live));
    $('cb-mode-live').classList.toggle('active', !!live); $('cb-mode-live').setAttribute('aria-pressed', String(!!live));
    if (live) { window.CCDTChart.render($('cb-live'), lastResult); }
  }
  function setMode(m) {
    mode = m; showMode();
    try { window.localStorage.setItem('ccdt-chart-mode', m); } catch (e) { /* not essential */ }
  }

  function pretty(s) { return s.replace(/[_-]+/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }); }
  // a field of the main table is its plain name; a field of the combined table is "b:name"
  function enc(column, src) { return src === 'b' ? 'b:' + column : column; }
  function ref(v) { return v.indexOf('b:') === 0 ? { column: v.slice(2), src: 'b' } : { column: v, src: 'a' }; }
  function label(v) { var r = ref(v); return (r.src === 'b' ? 'Other table: ' : '') + pretty(r.column); }
  function fillSelect(sel, names, blank) {
    var keep = sel.value;
    sel.innerHTML = '';
    if (blank) { var o = document.createElement('option'); o.value = ''; o.textContent = blank; sel.appendChild(o); }
    names.forEach(function (n) { var o = document.createElement('option'); o.value = n; o.textContent = label(n); sel.appendChild(o); });
    if (keep && names.indexOf(keep) >= 0) { sel.value = keep; }
  }
  function colNames(numericOnly) {
    var out = Object.keys(meta.columns).filter(function (n) { return !numericOnly || meta.columns[n].numeric; });
    if (meta.other) {
      Object.keys(meta.other.columns).forEach(function (n) {
        if (!numericOnly || meta.other.columns[n].numeric) { out.push('b:' + n); }
      });
    }
    return out;
  }
  function isNumericRef(v) { var r = ref(v); var set = r.src === 'b' && meta.other ? meta.other.columns : meta.columns; return !!(set[r.column] && set[r.column].numeric); }

  // ---- layout rules per chart type
  function layout() {
    var c = $('cb-chart').value, hist = c === 'hist', days = hist && $('cb-xmode').value === 'days';
    $('cb-xmode-wrap').style.display = hist ? '' : 'none';
    $('cb-to-wrap').style.display = days ? '' : 'none';
    $('cb-bucket-wrap').style.display = hist ? 'none' : '';
    $('cb-bin-wrap').style.display = hist ? '' : 'none';
    $('cb-group-wrap').style.display = ['line', 'stacked', 'heatmap'].indexOf(c) >= 0 ? '' : 'none';
    $('cb-limit-wrap').style.display = ['bar', 'hbar', 'stacked', 'heatmap'].indexOf(c) >= 0 ? '' : 'none';
    $('cb-x-label').textContent = days ? 'From (start date field)' : (c === 'line' ? 'Date field' : 'X axis field');
    var bucket = $('cb-bucket');
    bucket.options[0].disabled = (c === 'line');
    if (c === 'line' && bucket.value === 'none') { bucket.value = 'month'; }
    $('cb-measure-col').style.display = $('cb-measure').value === 'count' ? 'none' : '';
    fillSelect($('cb-measure-col'), colNames($('cb-measure').value === 'sum' || $('cb-measure').value === 'avg'));
    fillSelect($('cb-x'), hist && !days ? colNames(true) : colNames(false));
  }

  // ---- spec <-> form
  function readSpec() {
    var c = $('cb-chart').value, spec = { chart: c, measure: { fn: $('cb-measure').value }, filters: [], title: $('cb-title').value };
    if ($('cb-measure').value !== 'count') { var m = ref($('cb-measure-col').value); spec.measure.column = m.column; spec.measure.src = m.src; }
    if (c === 'hist') {
      if ($('cb-xmode').value === 'days') {
        var f = ref($('cb-x').value), t = ref($('cb-to').value);
        spec.x = { kind: 'days_between', from: f.column, from_src: f.src, to: t.column, to_src: t.src, cap: 365, bin: +$('cb-bin').value };
      } else { var xr = ref($('cb-x').value); spec.x = { column: xr.column, src: xr.src, bin: +$('cb-bin').value }; }
    } else { var xc = ref($('cb-x').value); spec.x = { column: xc.column, src: xc.src, bucket: $('cb-bucket').value }; }
    if (['line', 'stacked', 'heatmap'].indexOf(c) >= 0 && $('cb-group').value) { var g = ref($('cb-group').value); spec.group = { column: g.column, src: g.src }; }
    var jk = joinKey();
    if (meta.other && jk) { spec.join = { table_id: meta.other.id, left: jk.left, right: jk.right }; }
    spec.limit = +$('cb-limit').value || 20;
    Array.prototype.forEach.call($('cb-filters').children, function (row) {
      var col = row.querySelector('.f-col').value, op = row.querySelector('.f-op').value, val = row.querySelector('.f-val').value;
      if (!col) { return; }
      var fr = ref(col);
      var f = { column: fr.column, src: fr.src, op: op };
      if (op !== 'blank' && op !== 'notblank') { f.value = val; }
      spec.filters.push(f);
    });
    return spec;
  }

  // load the combined table a spec asks for (if it is not the one already loaded), then apply
  function applySpec(spec, done) {
    var want = spec.join && spec.join.table_id ? +spec.join.table_id : 0;
    if ((meta.other ? meta.other.id : 0) !== want) {
      $('cb-join').value = want ? String(want) : '';
      loadJoin(want, function () { applySpecNow(spec); if (done) { done(); } });
    } else { applySpecNow(spec); if (done) { done(); } }
  }
  function applySpecNow(spec) {
    if (spec.join && meta.other) { selectKey(spec.join.left, spec.join.right); }
    $('cb-chart').value = spec.chart || 'bar';
    $('cb-measure').value = (spec.measure && spec.measure.fn) || 'count';
    var days = spec.x && spec.x.kind === 'days_between';
    if (spec.chart === 'hist') { $('cb-xmode').value = days ? 'days' : 'number'; }
    layout();
    if (spec.measure && spec.measure.column) { $('cb-measure-col').value = enc(spec.measure.column, spec.measure.src); }
    if (days) { $('cb-x').value = enc(spec.x.from, spec.x.from_src); $('cb-to').value = enc(spec.x.to, spec.x.to_src); }
    else if (spec.x) { $('cb-x').value = enc(spec.x.column, spec.x.src); }
    $('cb-bucket').value = (spec.x && spec.x.bucket) || (spec.chart === 'line' ? 'month' : 'none');
    if (spec.x && spec.x.bin) { $('cb-bin').value = String(spec.x.bin); }
    $('cb-group').value = spec.group && spec.group.column ? enc(spec.group.column, spec.group.src) : '';
    $('cb-limit').value = spec.limit || 20;
    $('cb-title').value = spec.title || '';
    $('cb-filters').innerHTML = '';
    (spec.filters || []).forEach(function (f) { addFilter(f); });
    layout();
  }

  function addFilter(f) {
    var row = document.createElement('div'); row.className = 'cb-filter';
    row.innerHTML = '<select class="form-control input-sm f-col" aria-label="Filter field"></select><select class="form-control input-sm f-op" aria-label="Filter operator"></select>' +
      '<input class="form-control input-sm f-val" aria-label="Filter value"><button type="button" class="btn btn-default btn-sm" aria-label="Remove filter">&times;</button>';
    fillSelect(row.querySelector('.f-col'), colNames(false), 'Field...');
    var op = row.querySelector('.f-op');
    OPS.forEach(function (o) { var e = document.createElement('option'); e.value = o[0]; e.textContent = o[1]; op.appendChild(e); });
    if (f) {
      row.querySelector('.f-col').value = enc(f.column, f.src); op.value = f.op;
      row.querySelector('.f-val').value = Array.isArray(f.value) ? f.value.join(',') : (f.value == null ? '' : f.value);
    }
    row.querySelector('button').onclick = function () { row.remove(); schedule(); };
    row.addEventListener('input', schedule); row.addEventListener('change', schedule);
    $('cb-filters').appendChild(row);
  }

  // ---- talking to the server
  function post(url, body) {
    return fetch(url, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(body) }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); });
  }

  function showError(msg) {
    $('cb-error').textContent = msg; $('cb-error').style.display = msg ? '' : 'none';
  }

  function draw() {
    var tid = $('cb-table').value;
    if (!tid) { return; }
    var mine = ++seq, spec = readSpec();
    lastSpec = spec;
    $('cb-stage').classList.add('cb-busy'); $('cb-placeholder').style.display = 'none'; showError('');
    post(URLS.render, { table_id: tid, spec: spec }).then(function (res) {
      if (mine !== seq) { return; }               // a newer request superseded this one
      $('cb-stage').classList.remove('cb-busy');
      if (!res.ok) { showError(res.body.error || 'Could not draw the chart.'); $('cb-save').disabled = true; return; }
      var b = res.body;
      lastResult = b;
      $('cb-img').src = b.image;
      $('cb-img').alt = b.title + '. ' + b.series.length + ' series, ' + b.x.length + ' points. The numbers are listed below the chart.';
      $('cb-download').href = b.image; $('cb-download').style.display = '';
      showMode();
      $('cb-meta').textContent = 'Query ' + b.query_ms + ' ms, drawing ' + b.render_ms + ' ms' + (b.summary ? ' (stored result)' : b.cached ? ' (cached)' : '') +
        (b.suppressed ? ' | ' + b.suppressed + ' small cell(s) hidden' : '');
      $('cb-save').disabled = false;
      var t = '<thead><tr><th>' + esc(b.x_label) + '</th>' + b.series.map(function (s) { return '<th>' + esc(s.name) + '</th>'; }).join('') + '</tr></thead><tbody>';
      b.x.forEach(function (x, i) {
        t += '<tr><td>' + esc(x) + '</td>' + b.series.map(function (s) { var v = s.values[i]; return '<td>' + (v === null ? '&ndash;' : esc(v)) + '</td>'; }).join('') + '</tr>';
      });
      $('cb-data').innerHTML = t + '</tbody>';
    }).catch(function () { $('cb-stage').classList.remove('cb-busy'); showError('Could not reach the server.'); });
  }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function schedule() { clearTimeout(timer); timer = setTimeout(draw, 700); }

  function loadTable(id, then) {
    if (!id) { return; }
    fetch(URLS.describe + '/' + id, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); }).then(function (d) {
        meta = d; meta.other = null;
        var sel = $('cb-join'); sel.innerHTML = '<option value="">Just this table</option>';
        (d.joinable || []).forEach(function (t) { var o = document.createElement('option'); o.value = t.id; o.textContent = t.name; sel.appendChild(o); });
        $('cb-join-wrap').style.display = (d.joinable || []).length ? '' : 'none';
        $('cb-joinkey-wrap').style.display = 'none';
        $('cb-filters').innerHTML = '';
        refreshColumns();
        if (then) { then(); }
      });
  }

  // selects that list fields, and the starter list, after the tables in play change
  function refreshColumns() {
    fillSelect($('cb-group'), colNames(false), 'No split');
    var all = (meta.starters || []).concat(meta.other ? meta.other.starters || [] : []);
    meta.allStarters = all;
    var st = $('cb-starter'); st.innerHTML = '<option value="">Choose...</option>';
    all.forEach(function (s, i) { var o = document.createElement('option'); o.value = i; o.textContent = (s.spec.join ? 'Combined: ' : '') + s.label; st.appendChild(o); });
    $('cb-starter-wrap').style.display = all.length ? '' : 'none';
    Array.prototype.forEach.call($('cb-filters').children, function (row) {
      var sel = row.querySelector('.f-col'), keep = sel.value;
      fillSelect(sel, colNames(false), 'Field...');
      if (keep && sel.value !== keep) { row.remove(); }
    });
    layout();
  }

  function joinKey() {
    var v = $('cb-joinkey').value;
    if (!meta.other || v === '') { return null; }
    var k = meta.other.keys[+v];
    return k && k.indexed ? k : null;
  }
  function selectKey(left, right) {
    meta.other.keys.forEach(function (k, i) { if (k.left === left && k.right === right) { $('cb-joinkey').value = String(i); } });
  }

  function loadJoin(otherId, then) {
    if (!otherId) {
      meta.other = null; $('cb-joinkey-wrap').style.display = 'none'; refreshColumns();
      if (then) { then(); }
      return;
    }
    fetch(URLS.describe + '/' + $('cb-table').value + '/with/' + otherId, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); }).then(function (res) {
        if (!res.ok) { showError(res.body.error || 'Those tables cannot be combined.'); $('cb-join').value = ''; meta.other = null; $('cb-joinkey-wrap').style.display = 'none'; refreshColumns(); return; }
        meta.other = { id: +otherId, columns: res.body.columns, keys: res.body.keys, starters: res.body.starters };
        var ks = $('cb-joinkey'); ks.innerHTML = '';
        var firstOk = -1;
        meta.other.keys.forEach(function (k, i) {
          var o = document.createElement('option'); o.value = i;
          o.textContent = pretty(k.left) + (k.indexed ? '' : ' (not indexed yet)'); o.disabled = !k.indexed;
          if (k.indexed && firstOk < 0) { firstOk = i; }
          ks.appendChild(o);
        });
        if (!meta.other.keys.length) {
          ks.innerHTML = '<option value="">No shared ID field</option>';
          $('cb-joinhelp').textContent = 'These tables have no ID field in common, so they cannot be combined.';
        } else if (firstOk < 0) {
          ks.value = '';
          $('cb-joinhelp').textContent = 'The shared ID fields are not indexed yet. Ask an administrator to run: php artisan table:create:index --all';
        } else {
          ks.value = String(firstOk);
          $('cb-joinhelp').textContent = 'Only records that have a matching ID in both tables are counted.';
        }
        $('cb-joinkey-wrap').style.display = '';
        refreshColumns();
        if (then) { then(); }
      }).catch(function () { showError('Could not reach the server.'); });
  }

  // ---- wiring
  ['cb-chart', 'cb-xmode', 'cb-measure'].forEach(function (id) { $(id).addEventListener('change', function () { layout(); schedule(); }); });
  ['cb-x', 'cb-to', 'cb-bucket', 'cb-bin', 'cb-group', 'cb-measure-col', 'cb-limit', 'cb-title'].forEach(function (id) {
    $(id).addEventListener('change', schedule);
  });
  $('cb-table').addEventListener('change', function () { loadTable(this.value); });
  $('cb-join').addEventListener('change', function () { loadJoin(this.value ? +this.value : 0, schedule); });
  $('cb-joinkey').addEventListener('change', schedule);
  $('cb-starter').addEventListener('change', function () {
    if (this.value === '') { return; }
    applySpec(meta.allStarters[+this.value].spec, draw);
  });
  $('cb-add-filter').onclick = function () { if ($('cb-table').value) { addFilter(); } };
  $('cb-draw').onclick = draw;
  $('cb-mode-image').onclick = function () { setMode('image'); };
  $('cb-mode-live').onclick = function () { setMode('live'); };
  $('cb-toggle-data').onclick = function (e) {
    e.preventDefault(); var w = $('cb-data-wrap'); var open = w.style.display === 'none';
    w.style.display = open ? '' : 'none'; this.textContent = open ? 'Hide the numbers behind the chart' : 'Show the numbers behind the chart';
  };
  $('cb-save').onclick = function () {
    var name = window.prompt('Name for this chart:', lastSpec && lastSpec.title ? lastSpec.title : '');
    if (!name) { return; }
    post(URLS.save, { table_id: $('cb-table').value, name: name, spec: readSpec() }).then(function (res) {
      if (!res.ok) { showError(res.body.error || 'Could not save.'); return; }
      SAVED.push({ id: res.body.id, name: res.body.name, table_id: +$('cb-table').value, spec: readSpec() });
      refreshSaved();
    });
  };
  function refreshSaved() {
    var s = $('cb-saved'); s.innerHTML = '';
    SAVED.forEach(function (c, i) { var o = document.createElement('option'); o.value = i; o.textContent = c.name; s.appendChild(o); });
    $('cb-saved-wrap').style.display = SAVED.length ? '' : 'none';
  }
  function openSaved(c) {
    $('cb-table').value = String(c.table_id);
    loadTable(c.table_id, function () { applySpec(c.spec, draw); });
  }
  $('cb-saved-open').onclick = function () { if (SAVED[+$('cb-saved').value]) { openSaved(SAVED[+$('cb-saved').value]); } };
  $('cb-saved-del').onclick = function () {
    var c = SAVED[+$('cb-saved').value];
    if (!c || !window.confirm('Delete saved chart "' + c.name + '"?')) { return; }
    post(URLS.del, { id: c.id }).then(function () { SAVED.splice(SAVED.indexOf(c), 1); refreshSaved(); });
  };

  refreshSaved();
  if (PRELOAD) { SAVED.forEach(function (c) { if (c.id === PRELOAD) { openSaved(c); } }); }
})();
</script>
@endsection
