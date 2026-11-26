<?php
// Colores de marca de la instancia, generados desde el .env (config/branding.php).
// css/styles.css y css/dark.css traen estos mismos tokens con los valores por
// defecto, así que si esta hoja falla la interfaz sigue viéndose bien.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';   // sesión: marca de la empresa del usuario
require_once __DIR__ . '/../config/branding.php';

header('Content-Type: text/css; charset=utf-8');
// Contenido distinto por empresa: nunca en cachés compartidas (CDN de Hostinger)
header('Cache-Control: private, no-store');
header('Vary: Cookie');

$c = brandConfig()['colores'];
?>
:root {
  --brand-primary: <?= $c['primario'] ?>;
  --brand-primary-hover: <?= $c['primario_hover'] ?>;
  --brand-primary-active: <?= $c['primario_activo'] ?>;
  --brand-primary-rgb: <?= brandRgb($c['primario']) ?>;
  --brand-secondary: <?= $c['secundario'] ?>;
  --brand-secondary-dark: <?= $c['secundario_oscuro'] ?>;
  --brand-sidebar: <?= $c['sidebar'] ?>;
  --brand-sidebar-2: <?= $c['sidebar_2'] ?>;
  --brand-dm-accent: <?= $c['oscuro_acento'] ?>;
  --brand-dm-accent-2: <?= $c['oscuro_acento_2'] ?>;
  --brand-dm-accent-rgb: <?= brandRgb($c['oscuro_acento']) ?>;
  --brand-dm-link: <?= $c['oscuro_enlace'] ?>;
  --brand-dm-fill: <?= $c['oscuro_relleno'] ?>;
}
