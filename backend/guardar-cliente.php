<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermisoAlguno('clientes');
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/../config/contactos_cliente.php';
// Conexión a la base de datos

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Error de conexión: ' . $conn->connect_error]));
}


// Permitir solicitudes desde el frontend
header("Content-Type: application/json");

// Asegurar que el contenido devuelto sea JSON
header('Content-Type: application/json');

// Evitar que se muestren errores o advertencias PHP
error_reporting(0);
ini_set('display_errors', 0);




// Recibe datos del formulario
$nombre = trim((string)($_POST['nombre'] ?? ''));
$rfc = strtoupper(trim((string)($_POST['rfc'] ?? '')));
$direccion = trim((string)($_POST['direccion'] ?? ''));
$telefono = trim((string)($_POST['telefono'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));

// Contactos: lista JSON [{nombre, telefono, email}] (o, por compatibilidad,
// el texto "Juan; Pedro"). clientes.contactos guarda solo los nombres.
$listaContactos = isset($_POST['contactos_json'])
    ? normalizarContactos($_POST['contactos_json'])
    : normalizarContactos(array_map(fn($n) => ['nombre' => $n], array_filter(array_map('trim', explode(';', $_POST['contactos'] ?? '')))));
$contactos = contactosComoTexto($listaContactos);

if ($nombre === '' || empty($contactos)) {
    echo json_encode(['success' => false, 'message' => 'Nombre y contacto son obligatorios'], JSON_UNESCAPED_UNICODE);
    exit;
}
// D-53 · L-33: nombre (hasta 100) y correo únicos en la empresa; el candado evita dos altas iguales a la vez
require_once __DIR__ . '/lib/clientes_helpers.php';
$nombre = cliNormalizarNombre($nombre);
$candado = cliCandado($pdo);
if ($error = cliErrorDatos($pdo, $nombre, $email)) {
    cliSoltarCandado($pdo, $candado);
    // Un nombre repetido devuelve el id del cliente que ya existe (las altas rápidas lo eligen)
    $dup = $pdo->prepare('SELECT id FROM clientes WHERE LOWER(TRIM(nombre)) = LOWER(?) LIMIT 1');
    $dup->execute([$nombre]);
    $existente = (int)$dup->fetchColumn();
    echo json_encode(['success' => false, 'message' => $error] + ($existente ? ['id' => $existente] : []), JSON_UNESCAPED_UNICODE);
    exit;
}

// Inserta en la base de datos
$stmt = $pdo->prepare('INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email) VALUES (?, ?, ?, ?, ?, ?)');

try {
    $guardado = $stmt->execute([$nombre, $rfc, $direccion, $telefono, $contactos, $email]);
} catch (PDOException $e) {
    cliSoltarCandado($pdo, $candado);
    echo json_encode(['success' => false, 'message' => mensajeErrorPublico($e, 'No se pudo guardar el cliente. Inténtalo de nuevo.')], JSON_UNESCAPED_UNICODE);
    exit;
}
cliSoltarCandado($pdo, $candado);
if ($guardado) {
    $nuevoId = (int)$pdo->lastInsertId();
    sincronizarContactosCliente($nuevoId, $listaContactos);
    registrarAuditoria('clientes', $nuevoId, 'CREATE', null, [
        'nombre'    => $nombre,
        'rfc'       => $rfc,
        'direccion' => $direccion,
        'telefono'  => $telefono,
        'contactos' => $contactos,
        'contactos_detalle' => $listaContactos,
        'email'     => $email,
    ]);
    echo json_encode(['success' => true, 'id' => $nuevoId, 'nombre' => $nombre], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['success' => false, 'message' => 'No se pudo completar la operación. Inténtalo de nuevo.']);
}

$conn->close();
