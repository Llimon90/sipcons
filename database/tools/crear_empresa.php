<?php
// Alta de una empresa desde la línea de comandos (mientras no existan el
// registro web y el panel de plataforma, y para pruebas).
//
//   php database/tools/crear_empresa.php --nombre="Mantenimientos Rivera" \
//       --correo=admin@rivera.com --password="Secreta123" [--estado=prueba|activa] \
//       [--admin="Juan Rivera"] [--telefono="55 1234 5678"] [--color=#e67e22]
//
// Crea la empresa, sus tablas (e<ID>_*), sus carpetas y su administrador.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/plataforma.php';

$o = getopt('', ['nombre:', 'correo:', 'password:', 'estado::', 'admin::', 'telefono::', 'color::']);
if (empty($o['nombre']) || empty($o['correo']) || empty($o['password'])) {
    fwrite(STDERR, "Uso: php database/tools/crear_empresa.php --nombre=\"Empresa\" --correo=admin@empresa.com --password=\"Secreta123\" [--estado=prueba|activa] [--admin=\"Nombre\"] [--telefono=...] [--color=#hex]\n");
    exit(1);
}

try {
    $id = crearEmpresa($pdo, [
        'nombre'          => $o['nombre'],
        'estado'          => $o['estado'] ?? 'prueba',
        'admin_nombre'    => $o['admin'] ?? 'Administrador',
        'admin_usuario'   => $o['correo'],
        'admin_password'  => $o['password'],
        'admin_telefono'  => $o['telefono'] ?? '',
        'contacto_correo' => $o['correo'],
        'contacto_telefono' => $o['telefono'] ?? null,
        'marca'           => !empty($o['color']) ? ['colores' => ['primario' => $o['color']]] : [],
    ]);
    registrarEventoPlataforma($pdo, $id, 'alta_manual', ['nombre' => $o['nombre'], 'origen' => 'consola']);
    $e = $pdo->query("SELECT slug, estado, prueba_hasta FROM empresas WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    echo "Empresa creada: id $id ({$e['slug']}), estado {$e['estado']}"
        . ($e['prueba_hasta'] ? ", prueba hasta {$e['prueba_hasta']}" : '') . "\n"
        . "Acceso: {$o['correo']}\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
