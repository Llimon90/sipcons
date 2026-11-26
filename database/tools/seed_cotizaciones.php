<?php
// Datos de prueba del módulo de cotizaciones para una empresa existente:
// 6 servicios / mano de obra en el catálogo y 5 cotizaciones en distintos
// estatus (borrador, enviada, aprobada parcial con ventas por registrar,
// rechazada y vencida). Usa las mismas funciones que la interfaz.
//
//   C:\xampp\php\php.exe database\tools\seed_cotizaciones.php --empresa=2
//
// Solo base local. Requiere la migración 007 y al menos un cliente y un
// equipo (categoría Equipo) en el catálogo de la empresa.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/plataforma.php';
require_once __DIR__ . '/../../backend/lib/cotizaciones_helpers.php';

if (!in_array($_ENV['DB_HOST'] ?? '', ['localhost', '127.0.0.1'], true)) {
    fwrite(STDERR, "Abortado: solo contra la base local.\n");
    exit(1);
}
$o = getopt('', ['empresa:']);
$empresaId = (int)($o['empresa'] ?? 0);
if (!$empresaId || !empresaPorId($pdo, $empresaId)) {
    fwrite(STDERR, "Uso: php database/tools/seed_cotizaciones.php --empresa=<id>\n");
    exit(1);
}
$admin = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE empresa_id = ? AND rol = 'Administrador' ORDER BY id LIMIT 1");
$admin->execute([$empresaId]);
$admin = $admin->fetch(PDO::FETCH_ASSOC);
establecerEmpresa($empresaId);
$_SESSION = ['user_id' => (int)$admin['id'], 'rol' => 'Administrador', 'nombre' => $admin['nombre'], 'empresa_id' => $empresaId];

// 1. Catálogo de 6 servicios (si el SKU ya existe se respeta)
$servicios = [
    ['SRV-VISITA', 'Visita técnica de diagnóstico', 'Servicio', 'visita', 350, 650],
    ['SRV-MTTO-MS', 'Mantenimiento preventivo de minisplit', 'Servicio', 'servicio', 450, 950],
    ['SRV-MTTO-CAM', 'Mantenimiento preventivo de cámara de refrigeración', 'Servicio', 'servicio', 1400, 3200],
    ['MO-INST-MS', 'Instalación de minisplit hasta 2 TR (3 m de tubería)', 'Mano de obra', 'servicio', 1100, 2500],
    ['MO-HORA', 'Hora de mano de obra técnica', 'Mano de obra', 'hr', 220, 480],
    ['SRV-CARGA-GAS', 'Recuperación y carga de refrigerante (mano de obra)', 'Servicio', 'servicio', 380, 850],
];
$ins = $pdo->prepare("INSERT IGNORE INTO inventario_articulos (sku, nombre, categoria, controla_existencia, unidad, existencia, stock_minimo, costo_unitario, precio_venta)
                      VALUES (?, ?, ?, 0, ?, 0, 0, ?, ?)");
foreach ($servicios as $s) $ins->execute($s);
$id = fn(string $sku) => (int)$pdo->query('SELECT id FROM inventario_articulos WHERE sku = ' . $pdo->quote($sku))->fetchColumn();

$equipos = $pdo->query("SELECT id FROM inventario_articulos WHERE categoria = 'Equipo' AND activo = 1 ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
$refaccion = (int)$pdo->query("SELECT id FROM inventario_articulos WHERE categoria = 'Refacción' AND activo = 1 ORDER BY id LIMIT 1")->fetchColumn();
$clientes = $pdo->query('SELECT id FROM clientes ORDER BY id LIMIT 4')->fetchAll(PDO::FETCH_COLUMN);
if (!$equipos || !$clientes) {
    fwrite(STDERR, "La empresa necesita al menos un cliente y un artículo de categoría Equipo.\n");
    exit(1);
}
$equipo2 = $equipos[1] ?? $equipos[0];

function encabezadoSeed(PDO $pdo, int $cliente, int $diasAtras = 0): array {
    $contacto = $pdo->prepare('SELECT id FROM cliente_contactos WHERE cliente_id = ? LIMIT 1');
    $contacto->execute([$cliente]);
    $cfg = cotConfig();
    return [
        'cliente_id' => $cliente, 'contacto_id' => (int)$contacto->fetchColumn() ?: null, 'sucursal' => 'Matriz',
        'fecha' => date('Y-m-d', strtotime("-$diasAtras days")), 'vigencia_hasta' => date('Y-m-d', strtotime('+' . ($cfg['vigencia_dias'] - $diasAtras) . ' days')),
        'moneda' => 'MXN', 'precios_incluyen_iva' => 0, 'condiciones' => $cfg['terminos'],
        'notas_cliente' => 'Precios en moneda nacional. Incluye garantía por escrito.',
    ];
}
$cli = fn(int $i) => (int)$clientes[$i % count($clientes)];

// 2. Cinco cotizaciones
$borrador = cotGuardar($pdo, 0, encabezadoSeed($pdo, $cli(0)), [
    ['articulo_id' => $equipos[0], 'cantidad' => 2], ['articulo_id' => $id('MO-INST-MS'), 'cantidad' => 2],
]);

$enviada = cotGuardar($pdo, 0, encabezadoSeed($pdo, $cli(1), 2), [
    ['articulo_id' => $id('SRV-MTTO-MS'), 'cantidad' => 6, 'descuento_pct' => 10], ['articulo_id' => $id('SRV-CARGA-GAS'), 'cantidad' => 2],
]);
cotCambiarEstatus($pdo, (int)$enviada['id'], 'enviada');

$aprobada = cotGuardar($pdo, 0, encabezadoSeed($pdo, $cli(2), 5), array_values(array_filter([
    ['articulo_id' => $equipos[0], 'cantidad' => 1], ['articulo_id' => $equipo2, 'cantidad' => 2],
    $refaccion ? ['articulo_id' => $refaccion, 'cantidad' => 2] : null,
    ['articulo_id' => $id('MO-INST-MS'), 'cantidad' => 3],
])));
cotCambiarEstatus($pdo, (int)$aprobada['id'], 'enviada');
$aceptadas = array_column(array_filter($aprobada['partidas'], fn($p) => $p['tipo'] !== 'mano_obra'), 'id');
cotCambiarEstatus($pdo, (int)$aprobada['id'], 'aprobada', ['aprobada_por' => 'Ing. Responsable de compras', 'aceptadas' => $aceptadas]);

$rechazada = cotGuardar($pdo, 0, encabezadoSeed($pdo, $cli(3), 9), [['articulo_id' => $id('SRV-MTTO-CAM'), 'cantidad' => 2]]);
cotCambiarEstatus($pdo, (int)$rechazada['id'], 'enviada');
cotCambiarEstatus($pdo, (int)$rechazada['id'], 'rechazada', ['motivo' => 'El cliente eligió otro proveedor por precio.']);

$vencida = cotGuardar($pdo, 0, encabezadoSeed($pdo, $cli(0), 2), [['articulo_id' => $id('SRV-VISITA'), 'cantidad' => 1], ['articulo_id' => $id('MO-HORA'), 'cantidad' => 4]]);
cotCambiarEstatus($pdo, (int)$vencida['id'], 'enviada');
$pdo->prepare('UPDATE cotizaciones SET fecha = DATE_SUB(CURDATE(), INTERVAL 25 DAY), vigencia_hasta = DATE_SUB(CURDATE(), INTERVAL 10 DAY) WHERE id = ?')->execute([$vencida['id']]);
cotMarcarVencidas($pdo);

foreach ([$borrador, $enviada, $aprobada, $rechazada, $vencida] as $c) {
    $c = cotFila($pdo, (int)$c['id']);
    printf("  %-10s %-11s %-35s $%s\n", $c['folio'], $c['estatus'], mb_substr($c['cliente_nombre'], 0, 35), number_format((float)$c['total'], 2));
}
echo "Catálogo: 6 servicios / mano de obra. Listo.\n";
