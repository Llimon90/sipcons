<?php
// /robots.txt (ver .htaccess): solo el sitio de ventas se indexa; la app no.
require_once __DIR__ . '/sitemap.php';

header('Content-Type: text/plain; charset=utf-8');
echo "User-agent: *\n";
echo "Allow: /soluciones/\n";
foreach (['/public/', '/backend/', '/auth/', '/plataforma/', '/branding/', '/uploads/', '/manuales/', '/demo/'] as $ruta) {
    echo "Disallow: $ruta\n";
}
echo "\nSitemap: " . sitioBase() . "/sitemap.xml\n";
