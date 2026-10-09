<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/audit.php';
// DISPARADOR AUTOMÁTICO: Revisa si hay mantenimientos antes de cargar la tabla
require_once 'generador_tickets.php';

// Encabezados generales
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// 🔒 Evitar caché del lado del cliente y del servidor
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

if ($conn->connect_error) {
    die(json_encode(["error" => "Error de conexión: " . $conn->connect_error]));
}

$method = $_SERVER["REQUEST_METHOD"];


if ($method === "GET") {
    // **INICIO - FUNCIÓN PARA MOSTRAR LA BASE DE DATOS EN EL DOM**
    
    // Consulta para obtener todas las incidencias sin filtrar
    $sql = "SELECT * FROM incidencias WHERE estatus IN ('Abierto','Asignado', 'Pendiente', 'Completado') ORDER BY numero_incidente DESC;";

    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $incidencias = [];
        while($row = $result->fetch_assoc()) {
            $incidencias[] = $row;
        }
        echo json_encode($incidencias);
    } else {
        echo json_encode(["message" => "No hay incidencias abiertas"]);
    }
    
    // **FIN - FUNCIÓN PARA MOSTRAR LA BASE DE DATOS EN EL DOM**
    
} elseif ($method === "POST") {
    // **INICIO - FUNCIÓN PARA CREAR NUEVAS INCIDENCIAS**
    // Acepta el formulario con archivos adjuntos (multipart) o JSON sin archivos.
    // Siempre responde JSON: { success, message|error, motivo, numero_incidente, id }.
    // La incidencia y sus archivos se guardan juntos o no se guarda nada.
    require_once __DIR__ . '/../config/guardados_log.php';
    require_once __DIR__ . '/lib/incidencias_helpers.php';
    ini_set('display_errors', '0');

    $archivosMovidos = [];
    $responderError = function (int $http, string $motivo, string $mensaje, string $detalleLog = '') use (&$archivosMovidos, $pdo) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($archivosMovidos as $ruta) @unlink($ruta);
        registrarFalloGuardado('alta_' . $motivo, null, $detalleLog ?: $mensaje);
        http_response_code($http);
        echo json_encode(['success' => false, 'motivo' => $motivo, 'error' => $mensaje], JSON_UNESCAPED_UNICODE);
        exit();
    };

    $limites = limitesSubida();
    $esMultipart = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false;

    if ($esMultipart) {
        // Si el envío supera post_max_size, PHP descarta todo el formulario
        if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $responderError(413, 'envio_muy_grande',
                'Los archivos que adjuntaste pesan demasiado' .
                ($limites['total_mb'] ? " (el máximo es {$limites['total_mb']} MB en total)" : '') .
                '. No se registró la incidencia. Quita o reduce los archivos e intenta de nuevo.');
        }
        $data = $_POST;
    } else {
        $data = json_decode(file_get_contents("php://input"), true) ?: [];
    }

    // Validar los datos
    if (!isset($data["reporte_cliente"], $data["cliente"], $data["contacto"], $data["sucursal"], $data["fecha"], $data["tecnico"], $data["status"], $data["falla"], $data["notas"])) {
        $responderError(400, 'faltan_datos', 'Faltan datos de la incidencia. Recarga la página e intenta de nuevo.');
    }
    if (trim($data['cliente']) === '' || trim($data['contacto']) === '' || trim($data['fecha']) === '') {
        $responderError(400, 'faltan_datos', 'Faltan datos obligatorios: cliente, quién reporta y fecha.');
    }

    // El rol Técnico no puede cerrar incidencias con/sin factura
    $estatusRestringidosTecnico = ['Cerrado con factura', 'Cerrado sin factura'];
    if (($_SESSION['rol'] ?? '') === 'Técnico' && in_array($data["status"], $estatusRestringidosTecnico, true)) {
        $responderError(403, 'sin_permiso', 'Tu usuario no tiene permiso para cerrar incidencias con o sin factura.');
    }

    // Archivos: se revisan antes de registrar nada
    $revisionArchivos = revisarArchivosSubidos('archivos');
    if ($rechazo = rechazoDeArchivos($revisionArchivos, $limites)) {
        $responderError($rechazo[0], $rechazo[1], str_replace('No se guardó ningún cambio.', 'No se registró la incidencia.', $rechazo[2]));
    }

    try {
        $pdo->beginTransaction();

        // Obtener el último número de incidencia (bloqueado hasta terminar,
        // para que dos registros simultáneos no tomen el mismo folio)
        $ultimo = $pdo->query("SELECT numero_incidente FROM incidencias ORDER BY id DESC LIMIT 1 FOR UPDATE")->fetchColumn();
        $nuevoNumeroIncidente = "SIP-0001"; // Valor inicial si no hay registros
        if ($ultimo) {
            $numeroIncremental = intval(explode("-", $ultimo)[1] ?? 0) + 1;
            $nuevoNumeroIncidente = "SIP-" . str_pad($numeroIncremental, 4, "0", STR_PAD_LEFT);
        }

        // numero_serie (migración 010) es opcional; si la columna aún no existe se ignora.
        $numeroSerie = trim($data["numero_serie"] ?? '');
        $tieneSerie = (bool)$pdo->query("SHOW COLUMNS FROM incidencias LIKE 'numero_serie'")->fetch();

        $valores = [
            'reporte_cliente'  => (string)$data['reporte_cliente'],
            'cliente'          => trim((string)$data['cliente']),
            'contacto'         => trim((string)$data['contacto']),
            'sucursal'         => (string)$data['sucursal'],
            'categoria'        => (string)($data['categoria'] ?? ''),
            'fecha'            => (string)$data['fecha'],
            'tecnico'          => (string)$data['tecnico'],
            'estatus'          => (string)$data['status'],
            'falla'            => (string)$data['falla'],
            'notas'            => (string)$data['notas'],
            'numero_incidente' => $nuevoNumeroIncidente,
        ];
        if ($tieneSerie) $valores['numero_serie'] = $numeroSerie !== '' ? $numeroSerie : null;

        $columnas = implode(', ', array_map(fn($c) => "`$c`", array_keys($valores)));
        $marcas = implode(', ', array_fill(0, count($valores), '?'));
        $pdo->prepare("INSERT INTO incidencias ($columnas) VALUES ($marcas)")->execute(array_values($valores));
        $nuevoId = (int)$pdo->lastInsertId();

        try {
            $archivosAgregados = guardarArchivosIncidencia($pdo, $nuevoId, $revisionArchivos['validos'], $archivosMovidos);
        } catch (RuntimeException $e) {
            $responderError(500, 'archivo_no_guardado',
                "No se pudo guardar el archivo \"{$e->getMessage()}\" en el servidor. No se registró la incidencia. Intenta de nuevo.");
        }

        $datosAuditoria = ['id' => $nuevoId] + $valores;
        if ($archivosAgregados) $datosAuditoria['archivos_agregados'] = $archivosAgregados;
        registrarAuditoria('incidencias', $nuevoId, 'CREATE', null, $datosAuditoria, $nuevoNumeroIncidente);

        $pdo->commit();

        echo json_encode([
            "success" => true,
            "message" => "Incidencia registrada correctamente",
            "numero_incidente" => $nuevoNumeroIncidente,
            "id" => $nuevoId,
            "archivos_agregados" => $archivosAgregados,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('server.php (alta de incidencia): ' . $e->getMessage());
        $responderError(500, 'error_servidor',
            'Ocurrió un problema en el servidor y no se registró la incidencia. Intenta de nuevo en un momento.',
            get_class($e) . ': ' . $e->getMessage());
    }
}

$conn->close();
?>