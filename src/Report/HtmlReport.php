<?php

declare(strict_types=1);

namespace Cryjkd\HyperfXhprof\Report;

use Cryjkd\HyperfXhprof\Trace;

/**
 * Renders a self-contained HTML report (flat table + call tree), no assets.
 */
final class HtmlReport
{
    public function render(Trace $trace): string
    {
        $data = $trace->toArray();

        $totalWallUs = max(1, (int) $data['wall_us']);

        $flatRows = $this->renderFlatRows($data['nodes'] ?? [], $totalWallUs);
        $treeHtml = $this->renderTreeHtml($data['tree'] ?? [], $totalWallUs);

        return str_replace(
            ['__TITLE__', '__META__', '__FLAT__', '__TREE__', '__FLAME_DATA__'],
            [
                'Profile ' . $this->esc((string) $data['id']),
                $this->renderMeta($data),
                $flatRows,
                $treeHtml,
                json_encode($data['tree'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG),
            ],
            self::SHELL
        );
    }

    private function renderMeta(array $data): string
    {
        $cells = [
            ['请求 Request', $this->esc((string) ($data['method'] ?? '')) . ' ' . $this->esc((string) ($data['uri'] ?? ''))],
            ['总耗时 Wall', $this->ms((int) ($data['wall_us'] ?? 0))],
            ['CPU 时间', $this->ms((int) ($data['cpu_us'] ?? 0))],
            ['峰值内存', $this->bytes((int) ($data['peak_mem'] ?? 0))],
            ['函数数', (string) count($data['nodes'] ?? [])],
            ['最大深度', (string) ($data['max_depth'] ?? 0)],
            ['开始时间', $this->esc(date('Y-m-d H:i:s', (int) ($data['started_at'] ?? 0)))],
        ];

        $html = '';
        foreach ($cells as [$label, $value]) {
            $html .= '<div class="card"><div class="card-label">' . $this->esc($label)
                . '</div><div class="card-value">' . $value . '</div></div>';
        }

        return $html;
    }

    private function renderFlatRows(array $nodes, int $totalWallUs): string
    {
        $html = '';
        foreach ($nodes as $node) {
            $wallUs = (int) $node['wall_us'];
            $exclUs = (int) $node['excl_wall_us'];
            $pct = $totalWallUs > 0 ? $wallUs / $totalWallUs * 100 : 0.0;

            $html .= '<tr>'
                . '<td class="name"><code>' . $this->esc((string) $node['name']) . '</code></td>'
                . '<td class="num" data-value="' . (int) $node['calls'] . '">' . (int) $node['calls'] . '</td>'
                . '<td class="bar-cell" data-value="' . $this->num($pct, 4) . '"><div class="bar" style="width:' . $this->num($pct) . '%"></div><span class="pct">' . $this->num($pct, 1) . '%</span></td>'
                . '<td class="num" data-value="' . $wallUs . '">' . $this->ms($wallUs) . '</td>'
                . '<td class="num" data-value="' . $exclUs . '">' . $this->ms($exclUs) . '</td>'
                . '<td class="num" data-value="' . (int) $node['cpu_us'] . '">' . $this->ms((int) $node['cpu_us']) . '</td>'
                . '<td class="num" data-value="' . (int) $node['excl_cpu_us'] . '">' . $this->ms((int) $node['excl_cpu_us']) . '</td>'
                . '<td class="num" data-value="' . (int) ($node['mem_end'] - $node['mem_start']) . '">' . $this->bytes((int) ($node['mem_end'] - $node['mem_start'])) . '</td>'
                . '<td class="num" data-value="' . (int) $node['mem_peak'] . '">' . $this->bytes((int) $node['mem_peak']) . '</td>'
                . '</tr>';
        }

        return $html;
    }

    private function renderTreeHtml(array $tree, int $totalWallUs): string
    {
        $html = '<ul class="tree">';
        foreach ($tree as $node) {
            $html .= $this->renderTreeNode($node, $totalWallUs);
        }
        return $html . '</ul>';
    }

    private function renderTreeNode(array $node, int $totalWallUs): string
    {
        $wallUs = (int) $node['wall_us'];
        $exclUs = (int) $node['excl_wall_us'];
        $pct = $totalWallUs > 0 ? $wallUs / $totalWallUs * 100 : 0.0;
        $children = $node['children'] ?? [];

        $summary = '<span class="tn-bar" style="width:' . $this->num($pct) . '%"></span>'
            . '<code>' . $this->esc((string) $node['name']) . '</code>'
            . '<span class="tn-meta">' . $this->ms($wallUs) . ' / excl ' . $this->ms($exclUs) . ' / ' . $this->num($pct, 1) . '%</span>';

        if ($children === []) {
            return '<li><div class="tn">' . $summary . '</div></li>';
        }

        $inner = '<ul>';
        foreach ($children as $child) {
            $inner .= $this->renderTreeNode($child, $totalWallUs);
        }
        $inner .= '</ul>';

        return '<li><details open><summary class="tn">' . $summary . '</summary>' . $inner . '</details></li>';
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function num(float $value, int $decimals = 2): string
    {
        return number_format($value, $decimals, '.', '');
    }

    private function ms(int $us): string
    {
        return $this->num($us / 1000, 3) . ' ms';
    }

    private function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return $this->num($value, 2) . ' ' . $units[$unit];
    }

    private const SHELL = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>__TITLE__</title>
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
    --bar: linear-gradient(90deg, #5b8cff, #38d39f);
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    font: 14px/1.5 -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Microsoft YaHei", sans-serif;
    background: var(--bg);
    color: var(--text);
  }
  header {
    padding: 22px 28px;
    border-bottom: 1px solid var(--border);
    background: linear-gradient(180deg, #12182a, #0f1420);
  }
  header h1 { margin: 0 0 6px; font-size: 20px; font-weight: 650; }
  header .sub { color: var(--muted); font-size: 13px; }
  .cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 10px;
    padding: 18px 28px 0;
  }
  .card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 14px;
  }
  .card-label { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
  .card-value { font-size: 15px; font-weight: 600; margin-top: 4px; word-break: break-all; }
  .card:first-child { grid-column: 1 / -1; }
  section { padding: 18px 28px 30px; }
  h2 { font-size: 15px; font-weight: 650; margin: 10px 0 8px; color: var(--accent-2); }
  table { width: 100%; border-collapse: collapse; background: var(--panel); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
  th, td { padding: 7px 10px; text-align: left; border-bottom: 1px solid var(--border); white-space: nowrap; }
  th { background: var(--panel-2); color: var(--muted); font-size: 12px; cursor: pointer; user-select: none; position: sticky; top: 0; }
  th:hover { color: var(--text); }
  td.name { max-width: 560px; overflow: hidden; text-overflow: ellipsis; }
  code { font-family: ui-monospace, "SFMono-Regular", Consolas, "Liberation Mono", monospace; font-size: 12.5px; }
  .num { text-align: right; font-variant-numeric: tabular-nums; }
  .bar-cell { width: 170px; }
  .bar { height: 8px; border-radius: 4px; background: var(--bar); display: inline-block; vertical-align: middle; }
  .bar-cell .pct { margin-left: 7px; color: var(--muted); font-size: 11px; font-variant-numeric: tabular-nums; }
  .tree { list-style: none; padding-left: 0; margin: 0; }
  .tree ul { list-style: none; padding-left: 20px; border-left: 1px dashed var(--border); margin: 2px 0 2px 8px; }
  .tn { position: relative; padding: 4px 8px; border-radius: 6px; }
  .tn:hover { background: var(--panel); }
  summary.tn { cursor: pointer; list-style: none; }
  summary.tn::-webkit-details-marker { display: none; }
  .tn-bar { position: absolute; left: 0; top: 0; bottom: 0; border-radius: 6px; background: var(--bar); opacity: .22; z-index: 0; }
  .tn code, .tn-meta { position: relative; z-index: 1; }
  .tn-meta { color: var(--muted); font-size: 12px; margin-left: 8px; font-variant-numeric: tabular-nums; }
  .flame { position: relative; background: var(--panel); border: 1px solid var(--border); border-radius: 10px; padding: 6px; overflow: hidden; }
  .flame svg { display: block; }
  .flame-bar { display: flex; align-items: center; gap: 10px; margin: 8px 0; }
  .flame-bar button { background: var(--panel-2); color: var(--text); border: 1px solid var(--border); border-radius: 6px; padding: 4px 12px; cursor: pointer; font-size: 12px; }
  .flame-bar button:hover { border-color: var(--accent); }
  #flame-bread { color: var(--muted); font-size: 12px; }
  .flame-tip { display: none; position: absolute; z-index: 10; background: #0b0e17; border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px; font-size: 12px; line-height: 1.6; pointer-events: none; box-shadow: 0 6px 24px rgba(0,0,0,.4); white-space: nowrap; }
  /* Legend */
  .legend { margin: 6px 0 14px; background: var(--panel); border: 1px solid var(--border); border-radius: 10px; padding: 10px 14px; }
  .legend summary { cursor: pointer; color: var(--accent-2); font-size: 12.5px; font-weight: 600; user-select: none; list-style: none; }
  .legend summary::-webkit-details-marker { display: none; }
  .legend-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(270px, 1fr)); gap: 7px 22px; margin-top: 11px; }
  .legend-item { display: flex; gap: 9px; font-size: 12px; line-height: 1.5; }
  .lg-key { color: var(--accent-2); font-weight: 600; white-space: nowrap; }
  .lg-val { color: var(--muted); }
  /* Table polish */
  tbody tr:nth-child(even) { background: rgba(255, 255, 255, .02); }
  tbody tr:hover { background: rgba(91, 140, 255, .06); }
  th.num { text-align: right; }
</style>
</head>
<body>
<header>
  <h1>HyperfXhprof 性能分析报告</h1>
  <div class="sub">墙钟 / CPU / 内存逐方法分解 · 默认按含子调用墙钟时间降序 · 列头可点击排序</div>
</header>
<div class="cards">__META__</div>
<section>
  <h2>Flat profile</h2>
  <details class="legend" open>
    <summary>字段说明（每个数据的中文解释）</summary>
    <div class="legend-grid">
      <div class="legend-item"><span class="lg-key">函数 Function</span><span class="lg-val">被采样的方法，格式为「类名::方法名」</span></div>
      <div class="legend-item"><span class="lg-key">调用次数 Calls</span><span class="lg-val">该方法在本次请求中被调用的次数</span></div>
      <div class="legend-item"><span class="lg-key">含子调用占比 Incl %</span><span class="lg-val">该方法含子调用的总耗时 ÷ 本次请求总耗时</span></div>
      <div class="legend-item"><span class="lg-key">含子调用墙钟 Incl wall</span><span class="lg-val">方法自身 + 其内部调用所有方法的总耗时（毫秒）</span></div>
      <div class="legend-item"><span class="lg-key">自身墙钟 Excl wall</span><span class="lg-val">仅方法自身执行的耗时，不含子调用（毫秒）</span></div>
      <div class="legend-item"><span class="lg-key">含子调用 CPU Incl cpu</span><span class="lg-val">方法自身 + 其内部调用的 CPU 时间（毫秒）</span></div>
      <div class="legend-item"><span class="lg-key">自身 CPU Excl cpu</span><span class="lg-val">仅方法自身消耗的 CPU 时间（毫秒）</span></div>
      <div class="legend-item"><span class="lg-key">内存增量 Mem Δ</span><span class="lg-val">方法结束时相对进入时的内存变化</span></div>
      <div class="legend-item"><span class="lg-key">峰值内存 Peak mem</span><span class="lg-val">方法执行期间观测到的最高内存</span></div>
    </div>
  </details>
  <table id="flat">
    <thead>
      <tr>
        <th onclick="sortTable('flat',0)">Function</th>
        <th class="num" onclick="sortTable('flat',1)">Calls</th>
        <th onclick="sortTable('flat',2)">Incl %</th>
        <th class="num" onclick="sortTable('flat',3)">Incl wall</th>
        <th class="num" onclick="sortTable('flat',4)">Excl wall</th>
        <th class="num" onclick="sortTable('flat',5)">Incl cpu</th>
        <th class="num" onclick="sortTable('flat',6)">Excl cpu</th>
        <th class="num" onclick="sortTable('flat',7)">Mem Δ</th>
        <th class="num" onclick="sortTable('flat',8)">Peak mem</th>
      </tr>
    </thead>
    <tbody>
__FLAT__
    </tbody>
  </table>
</section>
<section>
  <h2>Call tree</h2>
__TREE__
</section>
<section>
  <h2>Flame graph</h2>
  <div class="flame-bar"><button id="flame-reset" type="button">Reset zoom</button><span id="flame-bread"></span></div>
  <div id="flame" class="flame"></div>
  <div id="flame-tip" class="flame-tip"></div>
</section>
<script>
function sortTable(id, col) {
  var table = document.getElementById(id);
  var tbody = table.tBodies[0];
  var rows = Array.prototype.slice.call(tbody.rows);
  var prevCol = table.getAttribute('data-col');
  var prevDir = table.getAttribute('data-dir');
  var dir = (prevCol === String(col) && prevDir === 'desc') ? 1 : -1;
  rows.sort(function (a, b) {
    var av = parseFloat(a.cells[col].getAttribute('data-value'));
    var bv = parseFloat(b.cells[col].getAttribute('data-value'));
    if (isNaN(av)) { av = 0; }
    if (isNaN(bv)) { bv = 0; }
    return (av - bv) * dir;
  });
  rows.forEach(function (r) { tbody.appendChild(r); });
  table.setAttribute('data-col', String(col));
  table.setAttribute('data-dir', dir === 1 ? 'asc' : 'desc');
}
</script>
<script type="application/json" id="flame-data">__FLAME_DATA__</script>
<script>
(function () {
  var wrap = document.getElementById('flame');
  var dataEl = document.getElementById('flame-data');
  if (!wrap || !dataEl) { return; }
  var rootNodes = JSON.parse(dataEl.textContent);
  var tip = document.getElementById('flame-tip');
  var bread = document.getElementById('flame-bread');
  var NS = 'http://www.w3.org/2000/svg';
  var W = 1200, ROW = 22, FONT = 12;

  function sumWall(nodes) {
    var s = 0;
    for (var i = 0; i < nodes.length; i++) { s += nodes[i].wall_us; }
    return s > 0 ? s : 1;
  }

  function hashStr(s) {
    var h = 0;
    for (var i = 0; i < s.length; i++) { h = ((h << 5) - h + s.charCodeAt(i)) | 0; }
    return Math.abs(h);
  }

  function color(name, depth) {
    var hue = hashStr(name) % 360;
    var light = 42 + Math.min(depth, 9) * 4;
    return 'hsl(' + hue + ', 60%, ' + light + '%)';
  }

  function shortName(name) {
    var i = name.lastIndexOf('::');
    if (i >= 0) { name = name.slice(i + 1) + '()'; }
    i = name.lastIndexOf('\\');
    if (i >= 0) { name = name.slice(i + 1); }
    return name;
  }

  function escHtml(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
  function ms(us) { return (us / 1000).toFixed(3) + ' ms'; }

  function layout(nodes, total) {
    var rects = [];
    function walk(list, x, depth) {
      for (var i = 0; i < list.length; i++) {
        var n = list[i];
        var w = n.wall_us / total * W;
        if (w < 0.25) { w = 0.25; }
        rects.push({ node: n, x: x, y: depth * ROW, w: w, wall: n.wall_us, excl: n.excl_wall_us, depth: depth });
        var cx = x;
        var ch = n.children || [];
        for (var j = 0; j < ch.length; j++) {
          walk([ch[j]], cx, depth + 1);
          cx += ch[j].wall_us / total * W;
        }
      }
    }
    walk(nodes, 0, 0);
    return rects;
  }

  function render(nodes, total) {
    wrap.innerHTML = '';
    var rects = layout(nodes, total);
    var maxDepth = 0;
    for (var i = 0; i < rects.length; i++) { if (rects[i].depth > maxDepth) { maxDepth = rects[i].depth; } }
    var H = (maxDepth + 1) * ROW + 2;
    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('width', '100%');
    svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
    svg.setAttribute('preserveAspectRatio', 'xMinYMin meet');
    for (var i = 0; i < rects.length; i++) {
      (function (r) {
        var g = document.createElementNS(NS, 'g');
        g.style.cursor = 'pointer';
        var rect = document.createElementNS(NS, 'rect');
        rect.setAttribute('x', r.x.toFixed(2));
        rect.setAttribute('y', (r.y + 1).toFixed(2));
        rect.setAttribute('width', Math.max(0.5, r.w - 0.5).toFixed(2));
        rect.setAttribute('height', (ROW - 2).toFixed(2));
        rect.setAttribute('rx', '2');
        rect.setAttribute('fill', color(r.node.name, r.depth));
        rect.setAttribute('stroke', '#0f1420');
        rect.setAttribute('stroke-width', '0.5');
        g.appendChild(rect);
        if (r.w > 26) {
          var label = shortName(r.node.name);
          var maxChars = Math.floor((r.w - 8) / 6.6);
          if (maxChars > 2 && label.length > maxChars) { label = label.slice(0, maxChars - 1) + '…'; }
          if (label.length > 0) {
            var text = document.createElementNS(NS, 'text');
            text.setAttribute('x', (r.x + 4).toFixed(2));
            text.setAttribute('y', (r.y + ROW - 7).toFixed(2));
            text.setAttribute('font-size', FONT);
            text.setAttribute('font-family', 'monospace');
            text.setAttribute('fill', '#08101f');
            text.textContent = label;
            g.appendChild(text);
          }
        }
        g.addEventListener('mousemove', function (e) {
          tip.style.display = 'block';
          var pct = (r.wall / total * 100);
          tip.innerHTML = '<b>' + escHtml(r.node.name) + '</b>'
            + '<br>wall ' + ms(r.wall) + ' · excl ' + ms(r.excl)
            + '<br>' + pct.toFixed(2) + '% of view · depth ' + r.depth;
          var box = wrap.getBoundingClientRect();
          tip.style.left = (e.clientX - box.left + 14) + 'px';
          tip.style.top = (e.clientY - box.top + 14) + 'px';
        });
        g.addEventListener('mouseleave', function () { tip.style.display = 'none'; });
        g.addEventListener('click', function () {
          path.push(r.node.name);
          bread.textContent = 'zoom: ' + path.join(' › ');
          bread.style.display = '';
          render([r.node], r.node.wall_us > 0 ? r.node.wall_us : 1);
        });
        svg.appendChild(g);
      })(rects[i]);
    }
    wrap.appendChild(svg);
  }

  var path = [];
  document.getElementById('flame-reset').addEventListener('click', function () {
    path = [];
    bread.textContent = '';
    bread.style.display = 'none';
    render(rootNodes, sumWall(rootNodes));
  });

  render(rootNodes, sumWall(rootNodes));
})();
</script>
</body>
</html>
HTML;
}
