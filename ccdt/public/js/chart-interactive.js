/*
 * Interactive view of a CCDT chart: hover tooltips and drag-to-zoom, drawn as
 * SVG in the browser from the same aggregated numbers the matplotlib image is
 * made from. No libraries, no extra requests.
 *
 *   CCDTChart.render(container, payload)
 *
 * payload: {chart: line|bar|hbar|stacked|hist|heatmap, title, x_label, y_label,
 *           x_kind: time|category|number, bucket, x: [...], series: [{name, values}]}
 *
 * Hover (or touch) shows the values at that position. Drag across the plot to
 * zoom to that range, double-click or "Reset zoom" to go back. Horizontal bar
 * charts have no zoom (they are already limited to the top categories).
 * Colours match charts-service/render.py.
 */
(function (global) {
  'use strict';

  var CAT = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
  var OTHER = '#898781', INK = '#0b0b0b', INK2 = '#52514e', MUTED = '#898781', GRID = '#e1e0d9', BASE = '#c3c2b7', SURFACE = '#fcfcfb';
  var SEQ = ['#cde2fb', '#9ec5f4', '#6da7ec', '#3987e5', '#256abf', '#184f95', '#0d366b'];
  var NS = 'http://www.w3.org/2000/svg';

  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function color(i, name) { return name === 'Other' ? OTHER : CAT[i % CAT.length]; }
  function isInt(series) {
    return series.every(function (s) { return s.values.every(function (v) { return v === null || Math.abs(v - Math.round(v)) < 1e-9; }); });
  }
  function fmt(v, integer) {
    if (v === null || v === undefined) { return '–'; }
    var o = integer ? { maximumFractionDigits: 0 } : { minimumFractionDigits: 1, maximumFractionDigits: 1 };
    return Number(v).toLocaleString('en-US', o);
  }
  function label(k, p) {
    k = String(k);
    if (p.x_kind === 'time') { return p.bucket === 'year' ? k : k.slice(0, 4) + '-' + k.slice(4, 6); }
    return k;
  }
  // "nice" tick values from 0 to max, about n of them
  function ticks(max, n) {
    if (!(max > 0)) { return [0, 1]; }
    var raw = max / n, mag = Math.pow(10, Math.floor(Math.log(raw) / Math.LN10)), f = raw / mag;
    var step = (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * mag, out = [];
    for (var v = 0; v <= max + step * 0.999; v += step) { out.push(Math.round(v / step) * step); if (v >= max) { break; } }
    return out;
  }
  function ramp(t) {   // t in [0,1] along the blue ramp
    var pos = Math.max(0, Math.min(1, t)) * (SEQ.length - 1), i = Math.min(SEQ.length - 2, Math.floor(pos)), f = pos - i;
    function h(c) { return [1, 3, 5].map(function (j) { return parseInt(c.substr(j, 2), 16); }); }
    var a = h(SEQ[i]), b = h(SEQ[i + 1]);
    return 'rgb(' + a.map(function (x, j) { return Math.round(x + (b[j] - x) * f); }).join(',') + ')';
  }

  function render(container, p) {
    container.innerHTML = '';
    container.style.position = 'relative';
    var chart = p.chart, n = p.x.length, S = p.series, integer = isInt(S);
    var labels = p.x.map(function (k) { return label(k, p); });
    var view = { lo: 0, hi: n - 1 };
    var canZoom = chart !== 'hbar';

    var bar = document.createElement('div');
    bar.style.cssText = 'font-size:12px;color:#666;margin-bottom:4px;min-height:22px';
    var hint = document.createElement('span');
    hint.textContent = canZoom ? 'Hover for values. Drag across the chart to zoom; double-click to reset.' : 'Hover for values.';
    var reset = document.createElement('button');
    reset.type = 'button'; reset.className = 'btn btn-default btn-xs'; reset.textContent = 'Reset zoom'; reset.style.cssText = 'margin-left:8px;display:none';
    bar.appendChild(hint); bar.appendChild(reset);
    var host = document.createElement('div'); host.style.cssText = 'position:relative;touch-action:pan-y;user-select:none;-webkit-user-select:none';
    var tip = document.createElement('div');
    tip.setAttribute('role', 'status');
    tip.style.cssText = 'position:absolute;pointer-events:none;display:none;background:#fff;border:1px solid ' + BASE + ';border-radius:4px;padding:6px 8px;font-size:12px;line-height:1.4;box-shadow:0 2px 6px rgba(0,0,0,.15);z-index:5;max-width:260px;white-space:nowrap';
    container.appendChild(bar); container.appendChild(host); host.appendChild(tip);

    var geom = null;       // set by draw(): what the pointer handlers need

    function draw() {
      var W = Math.max(480, Math.floor(host.clientWidth || container.clientWidth || 760)), H = 440;
      var lo = view.lo, hi = view.hi, cnt = hi - lo + 1, multi = S.length > 1 && chart !== 'heatmap';
      var longest = 0; for (var i = lo; i <= hi; i++) { longest = Math.max(longest, labels[i].length); }
      var rowLabel = 0; if (chart === 'heatmap') { S.forEach(function (s) { rowLabel = Math.max(rowLabel, s.name.length); }); }
      var ml = chart === 'hbar' ? Math.min(220, Math.max(80, longest * 6.6 + 14)) : chart === 'heatmap' ? Math.min(180, Math.max(60, rowLabel * 6.6 + 14)) : 64;
      var mr = 30, mt = 38 + (multi ? 22 : 0), mb = chart === 'hbar' ? 44 : 58;
      var pw = W - ml - mr, ph = H - mt - mb;
      var g = '<rect width="' + W + '" height="' + H + '" fill="' + SURFACE + '"/>';
      g += '<text x="' + ml + '" y="22" font-size="15" font-weight="700" fill="' + INK + '">' + esc(p.title) + '</text>';
      if (multi) {
        var lx = ml;
        S.forEach(function (s, k) {
          g += '<rect x="' + lx + '" y="34" width="10" height="10" rx="2" fill="' + color(k, s.name) + '"/><text x="' + (lx + 14) + '" y="43" font-size="11" fill="' + INK2 + '">' + esc(s.name) + '</text>';
          lx += 14 + s.name.length * 6.2 + 14;
        });
      }
      var band = '';
      var info = { W: W, H: H, ml: ml, mt: mt, pw: pw, ph: ph, lo: lo, hi: hi, cnt: cnt };

      if (chart === 'heatmap') {
        var cw = pw / cnt, rh = ph / S.length, max = 0;
        S.forEach(function (s) { for (var c = lo; c <= hi; c++) { if (s.values[c] !== null) { max = Math.max(max, s.values[c]); } } });
        S.forEach(function (s, r) {
          g += '<text x="' + (ml - 6) + '" y="' + (mt + r * rh + rh / 2 + 4) + '" font-size="11" text-anchor="end" fill="' + INK2 + '">' + esc(s.name) + '</text>';
          for (var c = lo; c <= hi; c++) {
            var v = s.values[c], x0 = ml + (c - lo) * cw, y0 = mt + r * rh;
            g += '<rect x="' + x0 + '" y="' + y0 + '" width="' + Math.max(0, cw - 1) + '" height="' + Math.max(0, rh - 1) + '" fill="' + (v === null ? '#f0efec' : ramp(max ? v / max : 0)) + '"/>';
            if (v !== null && cw >= 44 && rh >= 20) { g += '<text x="' + (x0 + cw / 2) + '" y="' + (y0 + rh / 2 + 4) + '" font-size="11" text-anchor="middle" fill="' + (max && v / max > 0.55 ? '#fff' : INK) + '">' + fmt(v, integer) + '</text>'; }
          }
        });
        g += xAxisLabels(function (c) { return ml + (c - lo + 0.5) * cw; }, lo, hi, ml, pw, ph + mt, H);
        info.cw = cw; info.rh = rh;
      } else if (chart === 'hbar') {
        var vals = S[0].values, vmax = 0; for (var q = 0; q < n; q++) { if (vals[q] !== null) { vmax = Math.max(vmax, vals[q]); } }
        var tk = ticks(vmax, 5), top = tk[tk.length - 1] || 1, rowH = ph / n;
        tk.forEach(function (t) {
          var x = ml + t / top * pw;
          g += '<line x1="' + x + '" x2="' + x + '" y1="' + mt + '" y2="' + (mt + ph) + '" stroke="' + GRID + '"/><text x="' + x + '" y="' + (mt + ph + 16) + '" font-size="11" text-anchor="middle" fill="' + MUTED + '">' + fmt(t, integer) + '</text>';
        });
        for (var r2 = 0; r2 < n; r2++) {
          var w = vals[r2] === null ? 0 : vals[r2] / top * pw;
          g += '<rect x="' + ml + '" y="' + (mt + r2 * rowH + rowH * 0.12) + '" width="' + w + '" height="' + (rowH * 0.76) + '" fill="' + color(0, S[0].name) + '"/>';
          g += '<text x="' + (ml - 6) + '" y="' + (mt + r2 * rowH + rowH / 2 + 4) + '" font-size="11" text-anchor="end" fill="' + INK2 + '">' + esc(labels[r2]) + '</text>';
        }
        g += '<text x="' + (ml + pw / 2) + '" y="' + (H - 6) + '" font-size="12" text-anchor="middle" fill="' + INK2 + '">' + esc(p.y_label) + '</text>';
        info.rowH = rowH;
      } else {
        // vertical value axis: line, bar, hist, stacked
        var ymax = 0;
        for (var c2 = lo; c2 <= hi; c2++) {
          if (chart === 'stacked') { var tot = 0; S.forEach(function (s) { tot += s.values[c2] || 0; }); ymax = Math.max(ymax, tot); }
          else { S.forEach(function (s) { if (s.values[c2] !== null) { ymax = Math.max(ymax, s.values[c2]); } }); }
        }
        var yt = ticks(ymax, 5), ytop = yt[yt.length - 1] || 1;
        function Y(v) { return mt + ph - v / ytop * ph; }
        yt.forEach(function (t) {
          g += '<line x1="' + ml + '" x2="' + (ml + pw) + '" y1="' + Y(t) + '" y2="' + Y(t) + '" stroke="' + GRID + '"/><text x="' + (ml - 6) + '" y="' + (Y(t) + 4) + '" font-size="11" text-anchor="end" fill="' + MUTED + '">' + fmt(t, integer) + '</text>';
        });
        g += '<text transform="translate(14,' + (mt + ph / 2) + ') rotate(-90)" font-size="12" text-anchor="middle" fill="' + INK2 + '">' + esc(p.y_label) + '</text>';
        var step = pw / cnt, X;
        if (chart === 'line') {
          X = function (c) { return cnt === 1 ? ml + pw / 2 : ml + (c - lo) / (cnt - 1) * pw; };
          S.forEach(function (s, k) {
            var d = '', pen = false;
            for (var c = lo; c <= hi; c++) {
              if (s.values[c] === null) { pen = false; continue; }
              d += (pen ? 'L' : 'M') + X(c).toFixed(1) + ' ' + Y(s.values[c]).toFixed(1); pen = true;
            }
            g += '<path d="' + d + '" fill="none" stroke="' + color(k, s.name) + '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
            if (cnt <= 40) { for (var c3 = lo; c3 <= hi; c3++) { if (s.values[c3] !== null) { g += '<circle cx="' + X(c3).toFixed(1) + '" cy="' + Y(s.values[c3]).toFixed(1) + '" r="2.5" fill="' + color(k, s.name) + '"/>'; } } }
          });
          info.X = X;
        } else {
          X = function (c) { return ml + (c - lo + 0.5) * step; };
          var bw = step * (chart === 'hist' ? 0.96 : 0.78);
          for (var c4 = lo; c4 <= hi; c4++) {
            var acc = 0;
            S.forEach(function (s, k) {
              var v = s.values[c4]; if (v === null) { return; }
              if (chart === 'stacked') { g += '<rect x="' + (X(c4) - bw / 2) + '" y="' + Y(acc + v) + '" width="' + bw + '" height="' + (Y(acc) - Y(acc + v)) + '" fill="' + color(k, s.name) + '"/>'; acc += v; }
              else { g += '<rect x="' + (X(c4) - bw / 2) + '" y="' + Y(v) + '" width="' + bw + '" height="' + (Y(0) - Y(v)) + '" fill="' + color(k, s.name) + '"/>'; }
            });
          }
          info.X = X;
        }
        g += '<line x1="' + ml + '" x2="' + (ml + pw) + '" y1="' + Y(0) + '" y2="' + Y(0) + '" stroke="' + BASE + '"/>';
        g += xAxisLabels(X, lo, hi, ml, pw, mt + ph, H);
        info.step = step; info.Y = Y;
      }

      g += '<g id="cb-hl"></g><rect id="cb-sel" display="none" fill="#2a78d6" fill-opacity=".12" stroke="#2a78d6"/>';
      host.querySelectorAll('svg').forEach(function (e) { e.remove(); });
      var svg = document.createElementNS(NS, 'svg');
      svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H); svg.setAttribute('width', '100%'); svg.setAttribute('role', 'img');
      svg.setAttribute('aria-label', p.title + '. Interactive chart; the numbers are listed below.');
      svg.style.cssText = 'display:block;max-width:100%;cursor:' + (canZoom ? 'crosshair' : 'default');
      svg.innerHTML = g;
      host.insertBefore(svg, tip);
      geom = info; geom.svg = svg;
      reset.style.display = (view.lo !== 0 || view.hi !== n - 1) ? '' : 'none';
    }

    function xAxisLabels(X, lo, hi, ml, pw, base, H) {
      var cnt = hi - lo + 1, longest = 0, out = '';
      for (var i = lo; i <= hi; i++) { longest = Math.max(longest, labels[i].length); }
      var every = Math.max(1, Math.ceil(cnt * (longest * 6.4 + 10) / pw));
      for (var j = lo; j <= hi; j += every) {
        out += '<text x="' + X(j).toFixed(1) + '" y="' + (base + 16) + '" font-size="11" text-anchor="middle" fill="' + MUTED + '">' + esc(labels[j]) + '</text>';
      }
      return out + '<text x="' + (ml + pw / 2) + '" y="' + (H - 8) + '" font-size="12" text-anchor="middle" fill="' + INK2 + '">' + esc(p.x_label) + '</text>';
    }

    // ---- pointer handling
    function local(ev) {
      var r = geom.svg.getBoundingClientRect(), k = geom.W / r.width;
      return { x: (ev.clientX - r.left) * k, y: (ev.clientY - r.top) * k, px: ev.clientX - r.left, py: ev.clientY - r.top, scale: k };
    }
    function indexAt(pt) {
      if (chart === 'hbar') { var r = Math.floor((pt.y - geom.mt) / geom.rowH); return r >= 0 && r < n ? r : -1; }
      if (pt.x < geom.ml || pt.x > geom.ml + geom.pw || pt.y < geom.mt || pt.y > geom.mt + geom.ph) { return -1; }
      if (chart === 'line') {
        if (geom.cnt === 1) { return geom.lo; }
        return geom.lo + Math.max(0, Math.min(geom.cnt - 1, Math.round((pt.x - geom.ml) / geom.pw * (geom.cnt - 1))));
      }
      var c = geom.lo + Math.floor((pt.x - geom.ml) / (chart === 'heatmap' ? geom.cw : geom.step));
      return Math.max(geom.lo, Math.min(geom.hi, c));
    }
    function showTip(pt, idx) {
      var hl = geom.svg.querySelector('#cb-hl'), html = '<strong>' + esc(labels[idx]) + '</strong>', shape = '';
      if (chart === 'heatmap') {
        var row = Math.max(0, Math.min(S.length - 1, Math.floor((pt.y - geom.mt) / geom.rh)));
        html += '<br>' + esc(S[row].name) + ': <strong>' + fmt(S[row].values[idx], integer) + '</strong>';
        shape = '<rect x="' + (geom.ml + (idx - geom.lo) * geom.cw) + '" y="' + (geom.mt + row * geom.rh) + '" width="' + geom.cw + '" height="' + geom.rh + '" fill="none" stroke="' + INK + '" stroke-width="2"/>';
      } else {
        var total = 0;
        S.forEach(function (s, k) {
          html += '<br><span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:' + color(k, s.name) + ';margin-right:5px"></span>' + (S.length > 1 ? esc(s.name) + ': ' : '') + '<strong>' + fmt(s.values[idx], integer) + '</strong>';
          total += s.values[idx] || 0;
        });
        if (chart === 'stacked' && S.length > 1) { html += '<br>Total: <strong>' + fmt(total, integer) + '</strong>'; }
        if (chart === 'hbar') { shape = '<rect x="' + geom.ml + '" y="' + (geom.mt + idx * geom.rowH) + '" width="' + geom.pw + '" height="' + geom.rowH + '" fill="#000" fill-opacity=".05"/>'; }
        else if (chart === 'line') { var xx = geom.X(idx); shape = '<line x1="' + xx + '" x2="' + xx + '" y1="' + geom.mt + '" y2="' + (geom.mt + geom.ph) + '" stroke="' + BASE + '" stroke-dasharray="3 3"/>'; }
        else { shape = '<rect x="' + (geom.ml + (idx - geom.lo) * geom.step) + '" y="' + geom.mt + '" width="' + geom.step + '" height="' + geom.ph + '" fill="#000" fill-opacity=".05"/>'; }
      }
      hl.innerHTML = shape;
      tip.innerHTML = html; tip.style.display = 'block';
      var hr = host.getBoundingClientRect(), left = pt.px + 14, tw = tip.offsetWidth;
      if (left + tw > hr.width - 4) { left = pt.px - tw - 14; }
      tip.style.left = Math.max(2, left) + 'px'; tip.style.top = Math.max(2, pt.py - tip.offsetHeight - 10) + 'px';
      tip.setAttribute('data-index', idx);
    }
    function hideTip() { tip.style.display = 'none'; if (geom) { var hl = geom.svg.querySelector('#cb-hl'); if (hl) { hl.innerHTML = ''; } } }

    var drag = null;
    host.addEventListener('pointerdown', function (ev) {
      if (!canZoom || !geom || ev.button > 0) { return; }
      var pt = local(ev), i = indexAt(pt);
      if (i < 0) { return; }
      drag = { x0: pt.x, i0: i, moved: false };
      try { host.setPointerCapture(ev.pointerId); } catch (e) { /* not essential */ }
    });
    host.addEventListener('pointermove', function (ev) {
      if (!geom) { return; }
      var pt = local(ev);
      if (drag) {
        if (Math.abs(pt.x - drag.x0) > 6) { drag.moved = true; }
        if (drag.moved) {
          var sel = geom.svg.querySelector('#cb-sel'), a = Math.max(geom.ml, Math.min(pt.x, drag.x0)), b = Math.min(geom.ml + geom.pw, Math.max(pt.x, drag.x0));
          sel.setAttribute('x', a); sel.setAttribute('y', geom.mt); sel.setAttribute('width', Math.max(0, b - a)); sel.setAttribute('height', geom.ph); sel.removeAttribute('display');
          hideTip();
          return;
        }
      }
      var idx = indexAt(pt);
      if (idx < 0) { hideTip(); } else { showTip(pt, idx); }
    });
    host.addEventListener('pointerup', function (ev) {
      if (!drag) { return; }
      var d = drag; drag = null;
      try { host.releasePointerCapture(ev.pointerId); } catch (e) { /* not essential */ }
      var sel = geom.svg.querySelector('#cb-sel'); if (sel) { sel.setAttribute('display', 'none'); }
      if (!d.moved) { return; }
      var i1 = indexAt(local(ev)), a = Math.min(d.i0, i1 < 0 ? d.i0 : i1), b = Math.max(d.i0, i1 < 0 ? d.i0 : i1);
      if (b - a < 1) { return; }            // need at least two points to zoom to
      view = { lo: a, hi: b }; hideTip(); draw();
    });
    host.addEventListener('pointerleave', function () { if (!drag) { hideTip(); } });
    host.addEventListener('dblclick', function () { if (canZoom) { view = { lo: 0, hi: n - 1 }; hideTip(); draw(); } });
    reset.addEventListener('click', function () { view = { lo: 0, hi: n - 1 }; draw(); });
    var resizeTimer = null;
    var onResize = function () { clearTimeout(resizeTimer); resizeTimer = setTimeout(function () { if (document.body.contains(host)) { draw(); } }, 150); };
    global.addEventListener('resize', onResize);

    draw();
    return { view: function () { return { lo: view.lo, hi: view.hi }; } };
  }

  global.CCDTChart = { render: render };
})(window);
