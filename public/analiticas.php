<?php
// Panel de analíticas de uso. Exclusivo del Programador: cualquier otro
// usuario recibe un 404 (ni siquiera se entrega el HTML del panel).
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../auth/analytics_acceso.php';

if (!isLoggedIn()) {
    header('Location: ../auth/login.html');
    exit;
}
if (!puedeVerAnaliticas()) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>404</title></head><body><h1>404 Not Found</h1></body></html>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Analíticas de uso - SIPCONS</title>
<link rel="icon" href="../img/favicon.ico" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{--ink:#1f2d3a;--ink2:#5b6b7a;--line:#dde3ea;--bg:#f3f5f8;--panel:#fff;--accent:#2563eb;--accent2:#0f766e;--good:#16a34a;--warn:#d97706;--bad:#dc2626;--info:#64748b}
*{box-sizing:border-box}
body{margin:0;font-family:"Segoe UI",system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--ink);font-size:14px;line-height:1.5}
a{color:var(--accent)}
.top{background:#1f2d3a;color:#fff;padding:14px 20px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between}
.top h1{margin:0;font-size:20px;font-weight:600}
.top small{color:#b6c3d0}
.top a{color:#cfe0ff;text-decoration:none;font-size:13px}
.wrap{max-width:1300px;margin:0 auto;padding:16px 20px 60px}
.filters{background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:12px 14px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:14px}
.filters label{display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--ink2);font-weight:600;text-transform:uppercase;letter-spacing:.03em}
.filters select,.filters input[type=date]{padding:7px 8px;border:1px solid var(--line);border-radius:6px;font-size:14px;background:#fff;color:var(--ink)}
.filters .chk{flex-direction:row;align-items:center;gap:6px;text-transform:none;font-weight:500;font-size:13px}
button.btn{background:var(--accent);color:#fff;border:0;border-radius:6px;padding:8px 14px;font-size:14px;cursor:pointer}
button.btn.sec{background:#e8edf3;color:var(--ink)}
.tabs{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:14px;border-bottom:2px solid var(--line)}
.tab{background:none;border:0;padding:10px 14px;font-size:14px;cursor:pointer;color:var(--ink2);border-bottom:3px solid transparent;margin-bottom:-2px;font-weight:600}
.tab.on{color:var(--accent);border-bottom-color:var(--accent)}
.panel{display:none}.panel.on{display:block}
.grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));margin-bottom:14px}
.kpi{background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:12px 14px}
.kpi .l{font-size:12px;color:var(--ink2);text-transform:uppercase;letter-spacing:.03em;font-weight:600}
.kpi .v{font-size:26px;font-weight:700;margin-top:2px}
.kpi .s{font-size:12px;color:var(--ink2)}
.up{color:var(--good)}.down{color:var(--bad)}
.box{background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:14px;margin-bottom:14px}
.box h3{margin:0 0 10px;font-size:15px}
.box .hint{color:var(--ink2);font-size:12.5px;margin:-4px 0 10px}
.cols{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(340px,1fr))}
.chartbox{position:relative;height:260px}
.tablewrap{overflow-x:auto}
table{border-collapse:collapse;width:100%;font-size:13.5px}
th,td{text-align:left;padding:7px 9px;border-bottom:1px solid var(--line);vertical-align:top}
th{font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--ink2);background:#f8fafc;white-space:nowrap;cursor:default}
th.sortable{cursor:pointer}
td.n,th.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
tr.click{cursor:pointer}tr.click:hover{background:#f1f6ff}
.bar{display:inline-block;height:8px;background:var(--accent);border-radius:4px;vertical-align:middle;margin-left:6px;min-width:2px}
.pill{display:inline-block;padding:1px 9px;border-radius:999px;font-size:12px;font-weight:600;color:#fff}
.pill.alto{background:var(--bad)}.pill.medio{background:var(--warn)}.pill.bajo{background:var(--info)}.pill.info{background:var(--accent2)}
.hall{border:1px solid var(--line);border-left-width:5px;border-radius:8px;background:var(--panel);padding:11px 14px;margin-bottom:10px}
.hall.alto{border-left-color:var(--bad)}.hall.medio{border-left-color:var(--warn)}.hall.bajo{border-left-color:var(--info)}.hall.info{border-left-color:var(--accent2)}
.hall .t{font-weight:700}.hall .a{font-size:12px;color:var(--ink2);margin-left:8px}
.hall .sug{margin-top:4px;color:var(--ink2)}
.hall .sug b{color:var(--ink)}
.empty{color:var(--ink2);padding:20px;text-align:center}
.err{background:#fde8e8;color:#991b1b;border-radius:8px;padding:12px 14px;margin-bottom:12px}
.loading{opacity:.5;pointer-events:none}
.heat{border-collapse:separate;border-spacing:2px;font-size:11px;width:auto}
.heat td,.heat th{padding:0;text-align:center;border:0;background:none}
.heat td.c{width:26px;height:22px;border-radius:3px;font-size:10px;color:#0b2540}
.heat th{font-size:10px;color:var(--ink2);text-transform:none;background:none;padding:0 4px}
.path{color:var(--ink2);font-size:12.5px}
.overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;z-index:50;overflow-y:auto}
.overlay.on{display:block}
.drawer{background:var(--bg);max-width:1100px;margin:30px auto;border-radius:10px;padding:18px 20px 30px;min-height:200px}
.drawer .hd{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px}
.drawer h2{margin:0;font-size:19px}
.tl{border-left:2px solid var(--line);margin-left:6px;padding-left:14px}
.tl .ev{position:relative;padding:3px 0;font-size:13px}
.tl .ev:before{content:"";position:absolute;left:-20px;top:9px;width:9px;height:9px;border-radius:50%;background:var(--accent)}
.tl .ev.error:before{background:var(--bad)}.tl .ev.pageview:before{background:var(--accent2);width:11px;height:11px;left:-21px}
.tl .ev.perm:before{background:#cbd5e1}
.tl .t{color:var(--ink2);font-variant-numeric:tabular-nums;display:inline-block;min-width:64px}
@media (max-width:640px){.wrap{padding:12px 10px 50px}.kpi .v{font-size:22px}}
</style>
</head>
<body>
<div class="top">
  <div>
    <h1><i class="fas fa-binoculars"></i> Analíticas de uso</h1>
    <small id="estado">Cargando…</small>
  </div>
  <a href="../index.html"><i class="fas fa-arrow-left"></i> Volver al inicio</a>
</div>

<div class="wrap">
  <div class="filters" id="filtros">
    <label>Periodo
      <select id="fRango">
        <option value="7">Últimos 7 días</option>
        <option value="14">Últimos 14 días</option>
        <option value="30" selected>Últimos 30 días</option>
        <option value="90">Últimos 90 días</option>
        <option value="180">Últimos 6 meses</option>
        <option value="365">Último año</option>
        <option value="custom">Personalizado</option>
      </select>
    </label>
    <label id="fDesdeW" style="display:none">Desde <input type="date" id="fDesde"></label>
    <label id="fHastaW" style="display:none">Hasta <input type="date" id="fHasta"></label>
    <label>Rol
      <select id="fRol"><option value="">Todos</option></select>
    </label>
    <label>Usuario
      <select id="fUsuario"><option value="">Todos</option></select>
    </label>
    <label class="chk"><input type="checkbox" id="fProg"> Incluir mi actividad (Programador)</label>
    <button class="btn" id="btnAplicar"><i class="fas fa-rotate"></i> Actualizar</button>
  </div>

  <div class="tabs" role="tablist">
    <button class="tab on" data-tab="hallazgos">Hallazgos</button>
    <button class="tab" data-tab="resumen">Resumen</button>
    <button class="tab" data-tab="modulos">Módulos y secciones</button>
    <button class="tab" data-tab="usuarios">Usuarios</button>
    <button class="tab" data-tab="flujos">Flujos y embudos</button>
    <button class="tab" data-tab="interacciones">Interacciones</button>
    <button class="tab" data-tab="friccion">Fricción y errores</button>
    <button class="tab" data-tab="adopcion">Adopción</button>
  </div>

  <div id="errorGlobal"></div>
  <div class="panel on" id="p-hallazgos"></div>
  <div class="panel" id="p-resumen"></div>
  <div class="panel" id="p-modulos"></div>
  <div class="panel" id="p-usuarios"></div>
  <div class="panel" id="p-flujos"></div>
  <div class="panel" id="p-interacciones"></div>
  <div class="panel" id="p-friccion"></div>
  <div class="panel" id="p-adopcion"></div>
</div>

<div class="overlay" id="overlay"><div class="drawer" id="drawer"></div></div>

<script src="../scripts/analiticas-panel.js?v=1"></script>
</body>
</html>
