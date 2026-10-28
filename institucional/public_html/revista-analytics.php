<?php
// ============================================================
// Revista – Analytics (100% local, sem Google Analytics)
// Autor: você ;)
// Basta colocar este arquivo em /public_html/revista-analytics.php
// e manter o log gerado pelo revista.php em revista_clicks.csv
// ============================================================

/*
Formato esperado do CSV (separador ;):
  [0]=data/hora (Y-m-d H:i:s, America/Sao_Paulo)
  [1]=uid (opcional)
  [2]=ip
  [3]=user-agent
  [4]=referrer (URL completa ou vazio)
  [5]=caminho do PDF
Linhas com mais colunas serão ignoradas além da [5].
*/

date_default_timezone_set('America/Sao_Paulo');

// ======== CONFIG ========
define('CSV_FILE', dirname(__DIR__) . '/var_runtime/revista_clicks.csv');

// (Opcional) chave simples via querystring, ex.: /revista-analytics.php?key=MINHACHAVE
// deixe em branco ('') para desativar a exigência de chave
define('DASHBOARD_KEY', getenv('REVISTA_DASHBOARD_KEY') ?: ''); // ex.: 'MINHACHAVE'
// ====== FIM CONFIG ======

// --- Gate por chave (opcional) ---
if (DASHBOARD_KEY === '' || !isset($_GET['key']) || !is_string($_GET['key']) || !hash_equals(DASHBOARD_KEY, $_GET['key'])) {
  http_response_code(403);
  echo "Acesso negado. Forneça a chave correta em ?key=...";
  exit;
}

// --- util ---
function parse_dt($s) {
  $t = strtotime($s);
  return $t ? $t : null;
}
function detect_device($ua) {
  $uaL = strtolower($ua);
  if (preg_match('/mobile|iphone|android(?!.*tablet)|windows phone|opera mini|opera mobi/i', $uaL)) return 'Mobile';
  if (preg_match('/ipad|tablet|kindle|silk|playbook/i', $uaL)) return 'Tablet';
  return 'Desktop';
}
function detect_os($ua) {
  $uaL = strtolower($ua);
  $map = [
    'Windows' => '/windows nt|win64|win32/',
    'macOS'   => '/mac os x|macintosh/',
    'iOS'     => '/iphone|ipad|ipod/',
    'Android' => '/android/',
    'Linux'   => '/linux/',
    'ChromeOS'=> '/cros/',
  ];
  foreach ($map as $name => $rx) if (preg_match($rx, $uaL)) return $name;
  return 'Outro';
}
function detect_browser($ua) {
  $uaL = strtolower($ua);
  if (preg_match('/edg\//', $uaL)) return 'Edge';
  if (preg_match('/chrome\//', $uaL) && !preg_match('/edg\//', $uaL)) return 'Chrome';
  if (preg_match('/safari\//', $uaL) && !preg_match('/chrome\//', $uaL)) return 'Safari';
  if (preg_match('/firefox\//', $uaL)) return 'Firefox';
  if (preg_match('/opera|opr\//', $uaL)) return 'Opera';
  return 'Outro';
}

// --- Lê CSV ---
$rows = [];
if (is_file(CSV_FILE) && is_readable(CSV_FILE)) {
  if (($h = fopen(CSV_FILE, 'r')) !== false) {
    while (($data = fgetcsv($h, 0, ';', '"', '')) !== false) {
      if (!isset($data[0])) continue;
      $rows[] = [
        'ts'   => $data[0] ?? '',
        'uid'  => $data[1] ?? '',
        'ip'   => $data[2] ?? '',
        'ua'   => $data[3] ?? '',
        'ref'  => $data[4] ?? '',
        'pdf'  => $data[5] ?? '',
      ];
    }
    fclose($h);
  }
}

// --- Filtros da UI ---
$start = isset($_GET['start']) ? strtotime($_GET['start'].' 00:00:00') : null;
$end   = isset($_GET['end'])   ? strtotime($_GET['end'].' 23:59:59')   : null;
$filterUid = isset($_GET['uid']) ? trim($_GET['uid']) : '';
$filterRef = isset($_GET['ref']) ? trim($_GET['ref']) : '';
$filterDev = isset($_GET['dev']) ? trim($_GET['dev']) : ''; // Mobile/Tablet/Desktop

$filtered = [];
foreach ($rows as $r) {
  $ts = parse_dt($r['ts']);
  if (!$ts) continue;

  if ($start && $ts < $start) continue;
  if ($end   && $ts > $end)   continue;
  if ($filterUid !== '' && strcasecmp($r['uid'], $filterUid) !== 0) continue;
  if ($filterRef !== '' && stripos($r['ref'], $filterRef) === false) continue;

  $dev = detect_device($r['ua']);
  if ($filterDev !== '' && $filterDev !== $dev) continue;

  // enriquecer
  $r['ts_unix'] = $ts;
  $r['date']    = date('Y-m-d', $ts);
  $r['hour']    = (int)date('G', $ts);
  $r['device']  = $dev;
  $r['os']      = detect_os($r['ua']);
  $r['browser'] = detect_browser($r['ua']);
  $filtered[]   = $r;
}

// --- Agregações ---
$total = count($filtered);
$firstDate = $total ? min(array_map(fn($r)=>$r['date'],$filtered)) : '';
$lastDate  = $total ? max(array_map(fn($r)=>$r['date'],$filtered)) : '';

$byDate = [];
$byHour = array_fill(0, 24, 0);
$byDevice = [];
$byOS = [];
$byBrowser = [];
$byRef = [];
$byUid = [];
$heat = array_fill(0, 7, array_fill(0, 24, 0)); // 0=Dom..6=Sáb

foreach ($filtered as $r) {
  $byDate[$r['date']] = ($byDate[$r['date']] ?? 0) + 1;
  $byHour[$r['hour']]++;
  $byDevice[$r['device']] = ($byDevice[$r['device']] ?? 0) + 1;
  $byOS[$r['os']] = ($byOS[$r['os']] ?? 0) + 1;
  $byBrowser[$r['browser']] = ($byBrowser[$r['browser']] ?? 0) + 1;

  $ref = $r['ref'] ?: '—';
  if ($ref !== '—') { $p = parse_url($ref); if (!empty($p['host'])) $ref = $p['host']; }
  $byRef[$ref] = ($byRef[$ref] ?? 0) + 1;

  $uid = $r['uid'] ?: '—';
  $byUid[$uid] = ($byUid[$uid] ?? 0) + 1;

  $w = (int)date('w', $r['ts_unix']);
  $heat[$w][$r['hour']]++;
}

// Visitantes únicos (estimativa, IP+UA)
$uniq = [];
foreach ($filtered as $r) {
  $key = hash('sha256', $r['ip'].'|'.$r['ua']);
  $uniq[$key] = true;
}
$uniqueVisitors = count($uniq);

// Ordenações
ksort($byDate);
arsort($byRef);
arsort($byUid);

// Dados p/ JS
$chartDateLabels = array_keys($byDate);
$chartDateValues = array_values($byDate);
$chartHourLabels = range(0,23);
$chartHourValues = array_values($byHour);

// Semana
$weekDays = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];

?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Revista – Analytics</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Chart.js (somente para desenhar os gráficos; nenhum dado sai do seu servidor) -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    :root{--gap:16px;--card:12px}
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial,sans-serif;margin:0;background:#0b0e14;color:#eef1f7}
    header{padding:24px}
    h1{margin:0 0 8px}
    .muted{opacity:.75}
    .wrap{padding:0 24px 40px}
    .grid{display:grid;gap:var(--gap)}
    @media(min-width:1000px){.grid{grid-template-columns:2fr 1fr}}
    .cards{display:grid;gap:var(--gap);grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}
    .card{background:#131823;border:1px solid #1e2533;border-radius:12px;padding:var(--card);box-shadow:0 1px 0 #0007}
    .card h3{margin:0 0 10px;font-size:16px}
    .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:var(--gap)}
    .kpi{background:#101521;border:1px solid #1a2130;border-radius:12px;padding:16px}
    .kpi b{font-size:28px;display:block;margin-top:6px}
    .toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin:12px 0 18px}
    label{font-size:12px;display:block;margin-bottom:6px}
    input,select,button{background:#0f1420;color:#eef1f7;border:1px solid #242b3a;border-radius:8px;padding:10px 12px}
    button{cursor:pointer}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #1c2433}
    th{text-align:left;color:#a8b3c7}
    .pill{display:inline-block;padding:2px 8px;border-radius:999px;background:#192033;border:1px solid #24304a}
    footer{padding:24px;color:#a8b3c7}
    a{color:#7fb3ff}
    .small{font-size:12px}
    .heatwrap{overflow:auto}
    .heat th,.heat td{padding:6px 8px;white-space:nowrap}
    .heat td{min-width:28px;text-align:center}
    .warn{background:#221616;border:1px solid #3a1f1f;color:#f2cccc;border-radius:8px;padding:12px;margin:12px 0}
  </style>
</head>
<body>
<header>
  <h1>Revista – Analytics</h1>
  <div class="muted">Painel de acessos a <strong>/revista</strong> (dados locais de <code><?= htmlspecialchars(basename(CSV_FILE)) ?></code>)</div>
</header>

<div class="wrap">

  <?php if (!is_file(CSV_FILE)) : ?>
    <div class="warn">Arquivo <code><?= htmlspecialchars(CSV_FILE) ?></code> não encontrado. Abra <code>/revista</code> ao menos uma vez para o CSV ser criado pelo <code>revista.php</code>.</div>
  <?php endif; ?>

  <form class="toolbar" method="get">
    <div>
      <label>Início</label>
      <input type="date" name="start" value="<?= htmlspecialchars($_GET['start'] ?? '') ?>">
    </div>
    <div>
      <label>Fim</label>
      <input type="date" name="end" value="<?= htmlspecialchars($_GET['end'] ?? '') ?>">
    </div>
    <div>
      <label>UID (opcional)</label>
      <input type="text" name="uid" placeholder="ex.: CLIENTE_42" value="<?= htmlspecialchars($filterUid) ?>">
    </div>
    <div>
      <label>Referrer contém</label>
      <input type="text" name="ref" placeholder="ex.: instagram.com" value="<?= htmlspecialchars($filterRef) ?>">
    </div>
    <div>
      <label>Dispositivo</label>
      <select name="dev">
        <?php $opt = [''=>'(todos)','Mobile'=>'Mobile','Tablet'=>'Tablet','Desktop'=>'Desktop'];
        foreach($opt as $k=>$v){$sel=($filterDev===$k)?'selected':''; echo "<option value=\"$k\" $sel>$v</option>";} ?>
      </select>
    </div>
    <?php if (DASHBOARD_KEY!==''): ?>
      <input type="hidden" name="key" value="<?= htmlspecialchars($_GET['key'] ?? '') ?>">
    <?php endif; ?>
    <div>
      <button type="submit">Aplicar filtros</button>
    </div>
  </form>

  <section class="kpis">
    <div class="kpi">
      Total de acessos <b><?= number_format($total,0,',','.') ?></b>
      <div class="small muted">Período: <?= $firstDate ?: '—' ?> &rarr; <?= $lastDate ?: '—' ?></div>
    </div>
    <div class="kpi">
      Visitantes únicos (estimativa) <b><?= number_format($uniqueVisitors,0,',','.') ?></b>
      <div class="small muted">Baseado em IP+User-Agent (local, sem cookies).</div>
    </div>
    <div class="kpi">
      Dispositivos
      <div class="small muted">
        <?php if ($byDevice) foreach($byDevice as $k=>$v) echo "<span class='pill'>$k: $v</span> "; else echo "—"; ?>
      </div>
    </div>
    <div class="kpi">
      Sistemas/OS
      <div class="small muted">
        <?php if ($byOS) foreach($byOS as $k=>$v) echo "<span class='pill'>$k: $v</span> "; else echo "—"; ?>
      </div>
    </div>
  </section>

  <div class="grid" style="margin-top:16px">
    <div class="card">
      <h3>Acessos por dia</h3>
      <canvas id="chartDaily"></canvas>
    </div>
    <div class="card">
      <h3>Distribuição por hora</h3>
      <canvas id="chartHour"></canvas>
    </div>
  </div>

  <div class="cards" style="margin-top:16px">
    <div class="card">
      <h3>Por dispositivo</h3>
      <canvas id="chartDevice"></canvas>
    </div>
    <div class="card">
      <h3>Por sistema operacional</h3>
      <canvas id="chartOS"></canvas>
    </div>
    <div class="card">
      <h3>Por navegador</h3>
      <canvas id="chartBrowser"></canvas>
    </div>
    <div class="card">
      <h3>Principais origens (referrer)</h3>
      <canvas id="chartRef"></canvas>
    </div>
  </div>

  <div class="card" style="margin-top:16px">
    <h3>Heatmap por dia × hora</h3>
    <div class="small muted">Mais escuro = mais acessos.</div>
    <div class="heatwrap">
      <table class="heat">
        <thead>
          <tr>
            <th>Dia/Hora</th>
            <?php for($h=0;$h<24;$h++) echo "<th>$h</th>"; ?>
          </tr>
        </thead>
        <tbody>
          <?php
            // acha máximo para normalizar cor
            $maxV = 0;
            for($d=0;$d<7;$d++) for($h=0;$h<24;$h++) $maxV = max($maxV, $heat[$d][$h]);
            for($d=0;$d<7;$d++){
              echo "<tr><th>{$weekDays[$d]}</th>";
              for($h=0;$h<24;$h++){
                $v = $heat[$d][$h];
                $pct = $maxV ? ($v/$maxV) : 0;
                $bg = "rgba(127,179,255,".round($pct,2).")";
                echo "<td title=\"$v\" style=\"background:$bg\">".($v?:'')."</td>";
              }
              echo "</tr>";
            }
          ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="cards" style="margin-top:16px">
    <div class="card">
      <h3>Top UIDs</h3>
      <table>
        <thead><tr><th>UID</th><th>Acessos</th></tr></thead>
        <tbody>
          <?php 
          $i=0; foreach($byUid as $u=>$c){ if(++$i>20) break;
            echo "<tr><td>".htmlspecialchars($u)."</td><td>$c</td></tr>";
          }
          if ($i===0) echo "<tr><td colspan='2'>Sem dados/UIDs.</td></tr>";
          ?>
        </tbody>
      </table>
      <div class="small muted" style="margin-top:8px">Dica: use links com <code>?uid=NOME_DO_CLIENTE</code> ao enviar a revista.</div>
    </div>

    <div class="card">
      <h3>Últimos acessos</h3>
      <table>
        <thead><tr><th>Data/Hora</th><th>UID</th><th>IP</th><th>Dispositivo</th><th>OS</th><th>Navegador</th><th>Referrer</th></tr></thead>
        <tbody>
        <?php
          $last = array_slice(array_reverse($filtered), 0, 50);
          if (!$last) { echo "<tr><td colspan='7'>Sem dados no período.</td></tr>"; }
          else {
            foreach ($last as $r) {
              $refCell = $r['ref'] ? '<a href="'.htmlspecialchars($r['ref']).'" target="_blank" rel="noreferrer">'.htmlspecialchars($r['ref']).'</a>' : '—';
              echo "<tr>
                <td>".htmlspecialchars($r['ts'])."</td>
                <td>".htmlspecialchars($r['uid'] ?: '—')."</td>
                <td>".htmlspecialchars($r['ip'])."</td>
                <td>".htmlspecialchars($r['device'])."</td>
                <td>".htmlspecialchars($r['os'])."</td>
                <td>".htmlspecialchars($r['browser'])."</td>
                <td>$refCell</td>
              </tr>";
            }
          }
        ?>
        </tbody>
      </table>
    </div>
  </div>

  <footer>
    <span class="muted small">Exportar: baixe o <a href="<?= htmlspecialchars(basename(CSV_FILE)) ?>"><?= htmlspecialchars(basename(CSV_FILE)) ?></a> e abra no Excel/Sheets.</span>
  </footer>
</div>

<script>
  // ===== Dados vindos do PHP =====
  const dailyLabels = <?= json_encode($chartDateLabels, JSON_UNESCAPED_UNICODE) ?>;
  const dailyValues = <?= json_encode($chartDateValues, JSON_UNESCAPED_UNICODE) ?>;
  const hourLabels  = <?= json_encode($chartHourLabels, JSON_UNESCAPED_UNICODE) ?>;
  const hourValues  = <?= json_encode($chartHourValues, JSON_UNESCAPED_UNICODE) ?>;
  const devLabels   = <?= json_encode(array_keys($byDevice), JSON_UNESCAPED_UNICODE) ?>;
  const devValues   = <?= json_encode(array_values($byDevice), JSON_UNESCAPED_UNICODE) ?>;
  const osLabels    = <?= json_encode(array_keys($byOS), JSON_UNESCAPED_UNICODE) ?>;
  const osValues    = <?= json_encode(array_values($byOS), JSON_UNESCAPED_UNICODE) ?>;
  const brLabels    = <?= json_encode(array_keys($byBrowser), JSON_UNESCAPED_UNICODE) ?>;
  const brValues    = <?= json_encode(array_values($byBrowser), JSON_UNESCAPED_UNICODE) ?>;
  const refTop      = <?= json_encode(array_slice($byRef,0,10,true), JSON_UNESCAPED_UNICODE) ?>;

  // ===== Helpers Chart.js =====
  function mkLine(ctx, labels, data, label){
    return new Chart(ctx, {
      type: 'line',
      data: { labels, datasets: [{ label, data, tension: .3, fill:false }] },
      options: { responsive:true, plugins:{ legend:{ display:false } },
        scales:{ x:{ grid:{display:false} }, y:{ beginAtZero:true } } }
    });
  }
  function mkBar(ctx, labels, data, label){
    return new Chart(ctx, {
      type: 'bar',
      data: { labels, datasets: [{ label, data }] },
      options: { responsive:true, plugins:{ legend:{ display:false } },
        scales:{ x:{ grid:{display:false} }, y:{ beginAtZero:true } } }
    });
  }
  function mkPie(ctx, labels, data, label){
    return new Chart(ctx, {
      type: 'pie',
      data: { labels, datasets: [{ label, data }] },
      options: { responsive:true, plugins:{ legend:{ position:'bottom' } } }
    });
  }

  // ===== Render =====
  mkLine(document.getElementById('chartDaily'), dailyLabels, dailyValues, 'Acessos/dia');
  mkBar(document.getElementById('chartHour'),  hourLabels,  hourValues,  'Acessos por hora');
  mkPie(document.getElementById('chartDevice'), devLabels, devValues, 'Dispositivos');
  mkPie(document.getElementById('chartOS'),     osLabels,  osValues,  'Sistemas');
  mkPie(document.getElementById('chartBrowser'), brLabels, brValues, 'Navegadores');

  const refLabels = Object.keys(refTop);
  const refValues = Object.values(refTop);
  mkBar(document.getElementById('chartRef'), refLabels, refValues, 'Top Origem');
</script>
</body>
</html>
