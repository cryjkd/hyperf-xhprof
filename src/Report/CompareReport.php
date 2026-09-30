<?php

declare(strict_types=1);

namespace Cryjkd\HyperfXhprof\Report;

/**
 * Renders the self-contained comparison page:
 *   - a profile list with checkboxes (select several),
 *   - a "Compare" button that renders a side-by-side breakdown,
 *   - "open" links to each profile's individual report (with flame graph).
 *
 * Data is embedded as JSON so the page works when opened directly from disk.
 */
final class CompareReport
{
    /** Keep index.html bounded: only the top-N functions of each profile are embedded. */
    private const MAX_NODES_PER_PROFILE = 200;

    /**
     * @param array<int, array<string, mixed>> $summaries  summary arrays (meta + flat nodes)
     * @param array<int, string> $preselect  ids to auto-check and compare (empty for the index page)
     */
    public function render(array $summaries, array $preselect = []): string
    {
        foreach ($summaries as &$summary) {
            if (isset($summary['nodes']) && is_array($summary['nodes'])) {
                $summary['nodes'] = array_slice($summary['nodes'], 0, self::MAX_NODES_PER_PROFILE);
            }
        }
        unset($summary);

        return str_replace(
            ['__DATA__', '__PRESELECT__'],
            [
                json_encode(array_values($summaries), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG),
                json_encode(array_values($preselect), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG),
            ],
            self::SHELL
        );
    }

    private const SHELL = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HyperfXhprof Compare</title>
<style>
  :root {
    --bg: #0f1420;
    --panel: #161d2e;
    --panel-2: #1c2538;
    --border: #26314a;
    --text: #dbe4f5;
    --muted: #8b98b5;
    --accent: #5b8cff;
    --accent-2: #38d39f;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    font: 14px/1.5 -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Microsoft YaHei", sans-serif;
    background: var(--bg);
    color: var(--text);
  }
  header { padding: 22px 28px; border-bottom: 1px solid var(--border); background: linear-gradient(180deg, #12182a, #0f1420); }
  header h1 { margin: 0 0 6px; font-size: 20px; font-weight: 650; }
  header .sub { color: var(--muted); font-size: 13px; }
  section { padding: 18px 28px 30px; }
  h2 { font-size: 15px; font-weight: 650; margin: 10px 0 8px; color: var(--accent-2); }
  h3 { font-size: 13px; color: var(--accent-2); margin: 22px 0 8px; }
  .toolbar { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
  .toolbar button { background: var(--panel-2); color: var(--text); border: 1px solid var(--border); border-radius: 6px; padding: 6px 14px; cursor: pointer; }
  .toolbar button:hover { border-color: var(--accent); }
  #count { color: var(--muted); font-size: 12px; }
  #list { display: flex; flex-direction: column; gap: 6px; }
  .pl-row { display: flex; align-items: center; gap: 10px; background: var(--panel); border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px; }
  .pl-row input { accent-color: var(--accent); }
  .pl-row label { flex: 1; display: flex; align-items: baseline; gap: 12px; cursor: pointer; min-width: 0; }
  .pl-id { font-family: ui-monospace, Consolas, monospace; font-size: 12.5px; color: var(--accent); }
  .pl-meta { color: var(--muted); font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .pl-num { margin-left: auto; color: var(--text); font-size: 12px; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .pl-open { color: var(--accent-2); font-size: 12px; text-decoration: none; }
  .pl-open:hover { text-decoration: underline; }
  table { width: 100%; border-collapse: collapse; background: var(--panel); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
  th, td { padding: 7px 10px; text-align: left; border-bottom: 1px solid var(--border); white-space: nowrap; }
  th { background: var(--panel-2); color: var(--muted); font-size: 12px; }
  td.k { color: var(--text); }
  code { font-family: ui-monospace, Consolas, monospace; font-size: 12.5px; }
  .num { text-align: right; font-variant-numeric: tabular-nums; }
  .num.hi { color: #ff8a8a; }
  .num.lo { color: var(--accent-2); }
  .mini-bar { height: 6px; border-radius: 3px; background: #0b0e17; overflow: hidden; margin-bottom: 3px; min-width: 60px; }
  .mini-fill { height: 100%; background: linear-gradient(90deg, #5b8cff, #38d39f); }
  .empty { color: var(--muted); padding: 20px; text-align: center; border: 1px dashed var(--border); border-radius: 10px; }
  th.num { text-align: right; }
  tbody tr:nth-child(even) { background: rgba(255, 255, 255, .02); }
  tbody tr:hover { background: rgba(91, 140, 255, .06); }
  .legend { margin: 6px 0 14px; background: var(--panel); border: 1px solid var(--border); border-radius: 10px; padding: 12px 16px; }
  .legend summary { cursor: pointer; color: var(--accent-2); font-size: 13px; font-weight: 600; user-select: none; list-style: none; }
  .legend summary::-webkit-details-marker { display: none; }
  .legend-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 8px; margin-top: 12px; }
  .legend-item { display: flex; flex-direction: column; gap: 2px; background: var(--panel-2); border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px; }
  .lg-key { color: var(--accent-2); font-weight: 600; font-size: 12px; }
  .lg-val { color: var(--muted); font-size: 12px; line-height: 1.55; }
</style>
</head>
<body>
<header>
  <h1>HyperfXhprof 采样对比</h1>
  <div class="sub">勾选两个及以上采样，对比墙钟 / CPU / 内存与函数级耗时 · 红色 = 最慢，绿色 = 最快</div>
</header>
<section>
  <h2>采样列表 Profiles</h2>
  <div class="toolbar">
    <button id="compare-btn" type="button">对比选中 Compare selected</button>
    <span id="count"></span>
  </div>
  <div id="list"></div>
</section>
<section>
  <details class="legend" open>
    <summary>对比说明（每个数据的中文解释）</summary>
    <div class="legend-grid">
      <div class="legend-item"><span class="lg-key">采样 ID</span><span class="lg-val">每次采样报告的唯一标识</span></div>
      <div class="legend-item"><span class="lg-key">墙钟 Wall</span><span class="lg-val">本次请求/消息的总耗时（毫秒）</span></div>
      <div class="legend-item"><span class="lg-key">CPU</span><span class="lg-val">CPU 时间（毫秒，Swoole 下为进程级近似值）</span></div>
      <div class="legend-item"><span class="lg-key">峰值内存 Peak mem</span><span class="lg-val">本次采样观测到的最高内存</span></div>
      <div class="legend-item"><span class="lg-key">函数数 Functions</span><span class="lg-val">被采样到的不同函数数量</span></div>
      <div class="legend-item"><span class="lg-key">Δ（max−min）</span><span class="lg-val">同一函数在所选采样中最慢减最快</span></div>
      <div class="legend-item"><span class="lg-key">红色 hi</span><span class="lg-val">该函数在所选采样里最慢</span></div>
      <div class="legend-item"><span class="lg-key">绿色 lo</span><span class="lg-val">该函数在所选采样里最快</span></div>
    </div>
  </details>
  <div id="compare-out"></div>
</section>
<script>
var PROFILES = __DATA__;
var PRESELECT = __PRESELECT__;

function escHtml(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function shortId(id) { return id.length > 20 ? '…' + id.slice(-18) : id; }
function ms(us) { return (us / 1000).toFixed(2); }
function fmtMem(b) {
  var units = ['B', 'KB', 'MB', 'GB'];
  var v = b, u = 0;
  while (v >= 1024 && u < units.length - 1) { v /= 1024; u += 1; }
  return v.toFixed(2) + ' ' + units[u];
}

function buildList() {
  var list = document.getElementById('list');
  list.innerHTML = '';
  PROFILES.forEach(function (p) {
    var row = document.createElement('div');
    row.className = 'pl-row';
    var cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.value = p.id;
    cb.id = 'chk-' + p.id;
    row.appendChild(cb);
    var label = document.createElement('label');
    label.htmlFor = 'chk-' + p.id;
    label.innerHTML = '<span class="pl-id">' + escHtml(p.id) + '</span>'
      + '<span class="pl-meta">' + escHtml((p.method || '') + ' ' + (p.uri || '')) + '</span>'
      + '<span class="pl-num">' + ms(p.wall_us || 0) + ' ms · ' + fmtMem(p.peak_mem || 0) + '</span>';
    row.appendChild(label);
    var link = document.createElement('a');
    link.href = p.id + '.html';
    link.target = '_blank';
    link.rel = 'noopener';
    link.className = 'pl-open';
    link.textContent = '查看 ↗';
    row.appendChild(link);
    list.appendChild(row);
  });
}

function selected() {
  return PROFILES.filter(function (p) {
    var cb = document.getElementById('chk-' + p.id);
    return cb && cb.checked;
  });
}

function compare() {
  var sel = selected();
  var count = document.getElementById('count');
  count.textContent = '已选 ' + sel.length + ' 个';
  var out = document.getElementById('compare-out');
  if (sel.length < 2) {
    out.innerHTML = '<div class="empty">请至少勾选两个采样进行对比。</div>';
    return;
  }
  out.innerHTML = renderComparison(sel);
}

function renderComparison(sel) {
  var html = '<h3>总览 Overview</h3><table><thead><tr><th>指标 Metric</th>';
  sel.forEach(function (p) { html += '<th class="num">' + escHtml(shortId(p.id)) + '</th>'; });
  html += '</tr></thead><tbody>';

  var metrics = [
    ['墙钟 Wall (ms)', function (p) { return ms(p.wall_us || 0); }, function (p) { return p.wall_us || 0; }],
    ['CPU (ms)', function (p) { return ms(p.cpu_us || 0); }, function (p) { return p.cpu_us || 0; }],
    ['峰值内存 Peak mem', function (p) { return fmtMem(p.peak_mem || 0); }, function (p) { return p.peak_mem || 0; }],
    ['函数数 Functions', function (p) { return String((p.nodes || []).length); }, function (p) { return (p.nodes || []).length; }]
  ];

  metrics.forEach(function (m) {
    html += '<tr><td class="k">' + m[0] + '</td>';
    var max = 1;
    sel.forEach(function (p) { var v = m[2](p); if (v > max) { max = v; } });
    sel.forEach(function (p) {
      var v = m[2](p);
      var w = (v / max * 100).toFixed(1);
      html += '<td class="num"><div class="mini-bar"><div class="mini-fill" style="width:' + w + '%"></div></div><span>' + m[1](p) + '</span></td>';
    });
    html += '</tr>';
  });
  html += '</tbody></table>';

  var funcs = {};
  sel.forEach(function (p) {
    (p.nodes || []).forEach(function (n) {
      if (!funcs[n.name]) { funcs[n.name] = { max: 0, per: {} }; }
      funcs[n.name].per[p.id] = n.wall_us || 0;
      if ((n.wall_us || 0) > funcs[n.name].max) { funcs[n.name].max = n.wall_us || 0; }
    });
  });

  var names = Object.keys(funcs).sort(function (a, b) { return funcs[b].max - funcs[a].max; });

  html += '<h3>函数对比 Functions</h3><table><thead><tr><th>函数 Function</th>';
  sel.forEach(function (p) { html += '<th class="num">' + escHtml(shortId(p.id)) + '</th>'; });
  html += '<th class="num">Δ (max−min)</th></tr></thead><tbody>';

  names.slice(0, 200).forEach(function (name) {
    var f = funcs[name];
    html += '<tr><td class="k"><code>' + escHtml(name) + '</code></td>';
    var min = Infinity, max = -Infinity;
    sel.forEach(function (p) {
      var v = f.per[p.id] || 0;
      if (v < min) { min = v; }
      if (v > max) { max = v; }
    });
    var span = f.max > 0 ? f.max : 1;
    sel.forEach(function (p) {
      var v = f.per[p.id] || 0;
      var w = (v / span * 100).toFixed(1);
      var cls = '';
      if (max > min) { cls = (v === max) ? 'hi' : (v === min) ? 'lo' : ''; }
      html += '<td class="num ' + cls + '"><div class="mini-bar"><div class="mini-fill" style="width:' + w + '%"></div></div><span>' + (v > 0 ? ms(v) : '—') + '</span></td>';
    });
    var delta = max - min;
    html += '<td class="num">' + (delta > 0 ? '+' + ms(delta) : '—') + '</td>';
    html += '</tr>';
  });
  html += '</tbody></table>';

  return html;
}

document.addEventListener('DOMContentLoaded', function () {
  buildList();
  document.getElementById('compare-btn').addEventListener('click', compare);
  if (PRESELECT && PRESELECT.length) {
    PRESELECT.forEach(function (id) {
      var cb = document.getElementById('chk-' + id);
      if (cb) { cb.checked = true; }
    });
    compare();
  }
});
</script>
</body>
</html>
HTML;
}
