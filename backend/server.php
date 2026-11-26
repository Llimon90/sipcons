<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermisoAlguno('incidencias');
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/../config/branding.php';

// Encabezados generales
header("Content-Type: application/json");

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
    // DISPARADOR AUTOMÁTICO: revisa si hay mantenimientos antes de cargar la tabla.
    // Solo al consultar la lista (no al registrar) y como máximo cada pocos minutos.
    require_once __DIR__ . '/generador_tickets.php';

    // **INICIO - FUNCIÓN PARA MOSTRAR LA BASE DE DATOS EN EL DOM**
    
    // Consulta para obtener todas las incidencias sin filtrar
    // D-9: el Técnico solo recibe las órdenes donde está asignado
    require_once __DIR__ . '/lib/incidencias_helpers.php';
    [$soloMias, $paramsMias] = filtroOrdenesSesion($pdo);
    // D-42: ?programadas=1 trae las de fecha futura (la lista normal no las incluye hasta su día)
    $programadas = ($_GET['programadas'] ?? '') === '1';
    $st = $pdo->prepare("SELECT * FROM incidencias WHERE " . ($programadas ? sqlOrdenProgramada() : sqlOrdenActiva()) . " AND $soloMias ORDER BY "
        . ($programadas ? 'fecha ASC, id ASC' : 'id DESC'));  // D-4
    $st->execute($paramsMias);
    $incidencias = filtrarOrdenesVisibles($pdo, $st->fetchAll(PDO::FETCH_ASSOC));

    if ($incidencias) {
        echo json_encode($incidencias);
    } else {
        echo json_encode(["message" => $programadas ? "No hay órdenes de servicio programadas" : "No hay órdenes de servicio abiertas"]);
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
                '. No se registró la orden de servicio. Quita o reduce los archivos e intenta de nuevo.');
        }
        $data = $_POST;
    } else {
        $data = json_decode(file_get_contents("php://input"), true) ?: [];
    }

    // Validar los datos
    if (!isset($data["reporte_cliente"], $data["cliente"], $data["contacto"], $data["sucursal"], $data["fecha"], $data["tecnico"], $data["status"], $data["falla"], $data["notas"])) {
        $responderError(400, 'faltan_datos', 'Faltan datos de la orden de servicio. Recarga la página e intenta de nuevo.');
    }
    if (trim($data['cliente']) === '' || trim($data['contacto']) === '' || trim($data['fecha']) === '' || trim((string)$data['falla']) === '') {
        $responderError(400, 'faltan_datos', 'Faltan datos obligatorios: cliente, quién reporta, fecha y motivo del servicio.');
    }
    if ($errorFecha = errorFechaOrden((string)$data['fecha'])) $responderError(400, 'fecha_invalida', $errorFecha);  // L-18
    // D-1: lista cerrada de estatus (la validación completa, con los técnicos, va abajo)
    if (estatusOficial((string)$data['status']) === null) {
        $responderError(400, 'estatus_invalido', 'Elige un estatus de la lista: ' . implode(', ', ESTATUS_ORDEN) . '.');
    }

    // Archivos: se revisan antes de registrar nada
    $revisionArchivos = revisarArchivosSubidos('archivos');
    if ($rechazo = rechazoDeArchivos($revisionArchivos, $limites)) {
        $responderError($rechazo[0], $rechazo[1], str_replace('No se guardó ningún cambio.', 'No se registró la orden de servicio.', $rechazo[2]));
    }

    // D-7: clave única del formulario. Si el mismo envío llega dos veces (doble clic,
    // red lenta, reintento), se responde la orden que ya se creó en vez de crear otra.
    $claveEnvio = trim((string)($data['clave_envio'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $claveEnvio)) $claveEnvio = '';
    $tieneClaveEnvio = (bool)$pdo->query("SHOW COLUMNS FROM incidencias LIKE 'clave_envio'")->fetch();

    /** Responde con la orden que ya registró este mismo envío. */
    $responderYaRegistrada = function (array $fila) use ($pdo) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // S-4 · D-9: con la clave de una orden que no es suya, el Técnico no recibe sus datos
        if (!ordenVisibleParaSesion($pdo, $fila['tecnico'] ?? '')) {
            http_response_code(409);
            echo json_encode(['success' => false, 'motivo' => 'clave_repetida',
                'message' => 'Este envío ya se registró. Recarga la página para capturar una orden nueva.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM archivos_incidencias WHERE incidencia_id = ?');
        $st->execute([$fila['id']]);
        echo json_encode([
            'success' => true, 'ya_registrada' => true,
            'message' => 'Esta orden de servicio ya se había registrado con este mismo envío.',
            'numero_incidente' => $fila['numero_incidente'], 'id' => (int)$fila['id'],
            'guardado_en' => date('Y-m-d H:i:s'), 'archivos_agregados' => [],
            'incidencia' => [
                'cliente' => $fila['cliente'], 'contacto' => $fila['contacto'], 'sucursal' => $fila['sucursal'],
                'fecha' => substr((string)$fila['fecha'], 0, 10), 'falla' => $fila['falla'], 'tecnico' => $fila['tecnico'],
                'estatus' => $fila['estatus'], 'archivos' => (int)$st->fetchColumn(),
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit();
    };

    try {
        $pdo->beginTransaction();

        // Folio del contador de la empresa: bloquea hasta terminar, así que dos altas
        // simultáneas no toman el mismo folio y uno eliminado no se reutiliza (CV-12)
        $nuevoNumeroIncidente = tomarFolioIncidencia($pdo);

        // D-7: lectura con bloqueo (ve lo último confirmado por otro envío)
        if ($claveEnvio !== '' && $tieneClaveEnvio) {
            $st = $pdo->prepare('SELECT * FROM incidencias WHERE clave_envio = ? FOR UPDATE');
            $st->execute([$claveEnvio]);
            if ($previa = $st->fetch(PDO::FETCH_ASSOC)) $responderYaRegistrada($previa);
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
            'accion'           => (string)($data['accion'] ?? ''),  // D-26: trabajo realizado (capturas posteriores)
            'notas'            => (string)$data['notas'],
            'numero_incidente' => $nuevoNumeroIncidente,
        ];
        if ($tieneSerie) $valores['numero_serie'] = $numeroSerie !== '' ? $numeroSerie : null;
        if ($tieneClaveEnvio && $claveEnvio !== '') $valores['clave_envio'] = $claveEnvio;

        // D-26: el cliente debe estar en el catálogo (se guarda como está en el catálogo)
        $clienteCatalogo = clienteDelCatalogo($pdo, $valores['cliente']);
        if ($clienteCatalogo === null) {
            $responderError(400, 'cliente_no_registrado', 'El cliente "' . $valores['cliente'] . '" no está en el catálogo. Elígelo de la lista o dalo de alta con «Crear cliente» y vuelve a registrar.');
        }
        $valores['cliente'] = $clienteCatalogo;

        // D-44: la sucursal se liga al catálogo del cliente (si es nueva, se da de alta)
        require_once __DIR__ . '/lib/sucursales_helpers.php';
        if (sucTablaDisponible($pdo)) {
            [$sucursalId, $sucursalNombre] = sucResolverPorCliente($pdo, $clienteCatalogo, $valores['sucursal']);
            $valores['sucursal'] = $sucursalNombre;
            $valores['sucursal_id'] = $sucursalId;
        }

        // D-10: solo técnicos asignables de la empresa (normalizados, sin repetir)
        require_once __DIR__ . '/lib/tecnicos_helpers.php';
        [$tecnicosOk, $errorTecnicos] = normalizarTecnicosOrden($pdo, $valores['tecnico']);
        if ($errorTecnicos !== null) $responderError(400, 'tecnico_invalido', $errorTecnicos);
        $valores['tecnico'] = $tecnicosOk;

        // Estatus (0.1): lista cerrada, D-5, permiso para cerrar o cancelar, motivo
        $reglas = reglasEstatusOrden(null, $valores['estatus'], $tecnicosOk,
            isset($data['motivo_estatus']) ? (string)$data['motivo_estatus'] : null, '', puedeCerrarIncidencias());
        if (isset($reglas['error'])) $responderError($reglas['http'], $reglas['http'] === 403 ? 'sin_permiso' : 'estatus_invalido', $reglas['error']);
        $valores['estatus'] = $reglas['estatus'];
        if (incidenciasTieneMotivoEstatus($pdo)) {
            $valores['motivo_estatus'] = $reglas['motivo_estatus'];
        } elseif ($reglas['motivo_estatus'] !== null) {
            $responderError(503, 'migracion_pendiente', 'Falta aplicar la actualización 010 de la base de datos para guardar motivos de estatus. Avisa al administrador del sistema.');
        }

        $columnas = implode(', ', array_map(fn($c) => "`$c`", array_keys($valores)));
        $marcas = implode(', ', array_fill(0, count($valores), '?'));
        $pdo->prepare("INSERT INTO incidencias ($columnas) VALUES ($marcas)")->execute(array_values($valores));
        $nuevoId = (int)$pdo->lastInsertId();
        if (!empty($valores['numero_serie'])) {  // D-41: la serie capturada es el primer equipo de la orden
            require_once __DIR__ . '/lib/equipos_orden_helpers.php';
            eqoAgregar($pdo, $nuevoId, (string)$valores['numero_serie'], [], true);
        }

        try {
            $archivosAgregados = guardarArchivosIncidencia($pdo, $nuevoId, $revisionArchivos['validos'], $archivosMovidos);
        } catch (RuntimeException $e) {
            $responderError(500, 'archivo_no_guardado',
                "No se pudo guardar el archivo \"{$e->getMessage()}\" en el servidor. No se registró la orden de servicio. Intenta de nuevo.");
        }

        // Comprobación: se vuelve a leer lo registrado y se compara con lo enviado
        $st = $pdo->prepare('SELECT * FROM incidencias WHERE id = ?');
        $st->execute([$nuevoId]);
        $registrada = $st->fetch(PDO::FETCH_ASSOC);
        if (!$registrada) {
            $responderError(500, 'verificacion', 'No se pudo confirmar el registro en la base de datos. No se registró la orden de servicio. Intenta de nuevo.');
        }
        $distintos = camposQueNoCoinciden($valores, $registrada);
        if ($distintos) {
            $responderError(500, 'verificacion',
                'No se pudo registrar correctamente: ' . nombresCamposIncidencia($distintos) .
                '. Es posible que el texto sea demasiado largo o tenga caracteres especiales no admitidos (por ejemplo, emojis). No se registró la orden de servicio.',
                'Campos que no coinciden tras registrar: ' . implode(',', $distintos));
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM archivos_incidencias WHERE incidencia_id = ?');
        $st->execute([$nuevoId]);
        $archivosEnBase = (int)$st->fetchColumn();
        if ($archivosEnBase !== count($archivosAgregados)) {
            $responderError(500, 'verificacion', 'No se pudieron confirmar los archivos adjuntos. No se registró la orden de servicio. Intenta de nuevo.',
                "Archivos esperados " . count($archivosAgregados) . ", en base $archivosEnBase");
        }

        $datosAuditoria = ['id' => $nuevoId] + $valores;
        if ($archivosAgregados) $datosAuditoria['archivos_agregados'] = $archivosAgregados;
        registrarAuditoria('incidencias', $nuevoId, 'CREATE', null, $datosAuditoria, $nuevoNumeroIncidente);

        // La respuesta se arma antes de confirmar y muestra lo que quedó en la
        // base (no lo que se envió), para que quien registra lo pueda revisar.
        $respuesta = [
            "success" => true,
            "message" => "Orden de servicio registrada correctamente",
            "numero_incidente" => $registrada['numero_incidente'],
            "id" => $nuevoId,
            "guardado_en" => date('Y-m-d H:i:s'),
            "archivos_agregados" => $archivosAgregados,
            "incidencia" => [
                'cliente'   => $registrada['cliente'],
                'contacto'  => $registrada['contacto'],
                'sucursal'  => $registrada['sucursal'],
                'fecha'     => substr((string)$registrada['fecha'], 0, 10),
                'falla'     => $registrada['falla'],
                'tecnico'   => $registrada['tecnico'],
                'estatus'   => $registrada['estatus'],
                'archivos'  => $archivosEnBase,
            ],
        ];

        $pdo->commit();
        echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        // D-7: otro envío con la misma clave ganó la carrera (índice único)
        if ($claveEnvio !== '' && $tieneClaveEnvio && $e instanceof PDOException && str_contains($e->getMessage(), 'uniq_clave_envio')) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $st = $pdo->prepare('SELECT * FROM incidencias WHERE clave_envio = ?');
            $st->execute([$claveEnvio]);
            if ($previa = $st->fetch(PDO::FETCH_ASSOC)) $responderYaRegistrada($previa);
        }
        error_log('server.php (alta de orden de servicio): ' . $e->getMessage());
        $responderError(500, 'error_servidor',
            'Ocurrió un problema en el servidor y no se registró la orden de servicio. Intenta de nuevo en un momento.',
            get_class($e) . ': ' . $e->getMessage());
    }
}

$conn->close();
?>