<?php
// /sitemap.xml (ver .htaccess): las páginas públicas del sitio de ventas.
// La dirección base sale de APP_URL o, si no está, del dominio que se visita,
// para que funcione igual con el dominio temporal que con el definitivo.
require_once __DIR__ . '/../config/env.php';

function sitioBase(): string {
    $app = rtrim((string)env('APP_URL', ''), '/');
    if ($app !== '') return $app;
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = preg_replace('/[^a-z0-9.:-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $ruta = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/soluciones/sitemap.php')), '/\\');
    return ($https ? 'https' : 'http') . '://' . $host . $ruta;
}

if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'sitemap.php') {
    header('Content-Type: application/xml; charset=utf-8');
    $base = sitioBase() . '/soluciones/';
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach (glob(__DIR__ . '/*.html') as $archivo) {
        $pagina = basename($archivo);
        $loc = $base . ($pagina === 'index.html' ? '' : $pagina);
        echo '  <url><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc><lastmod>' . date('Y-m-d', filemtime($archivo)) . '</lastmod>'
           . '<priority>' . ($pagina === 'index.html' ? '1.0' : '0.8') . '</priority></url>' . "\n";
    }
    echo "</urlset>\n";
}
