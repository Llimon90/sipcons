<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermisoAlguno('clientes');
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/lib/periodicidad_helpers.php';
header('Content-Type: application/json');

try {
    $id = $_POST['id'] ?? throw new Exception("ID de equipo no especificado");

    // Estado anterior para el historial
    $stmtAnterior = $pdo->prepare(
        "SELECT cliente, sucursal, equipo, marca, modelo, numero_serie, calibracion, servicio, frecuencia_servicio, garantia, origen, fecha_registro
         FROM padron_equipos WHERE id = ?"
    );
    $stmtAnterior->execute([$id]);
    $anterior = $stmtAnterior->fetch(PDO::FETCH_ASSOC) ?: null;
    $sucursal = $_POST['sucursal'] ?? '';
    $equipo = $_POST['equipo'] ?? '';
    $marca = $_POST['marca'] ?? '';
    $modelo = $_POST['modelo'] ?? '';
    $numero_serie = $_POST['numero_serie'] ?? 'S/N';
    // H-05 (fase 4): sin fecha, se conserva la que tenía; si viene, debe ser una fecha real
    $fecha_registro = !empty($_POST['fecha_registro']) ? trim((string)$_POST['fecha_registro']) : (substr((string)($anterior['fecha_registro'] ?? ''), 0, 10) ?: date('Y-m-d'));
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha_registro, $mf) || !checkdate((int)$mf[2], (int)$mf[3], (int)$mf[1])) {
        http_response_code(400);
        die(json_encode(['exito' => false, 'success' => false, 'mensaje' => 'La fecha de registro no es válida.'], JSON_UNESCAPED_UNICODE));
    }
    
    [$mesesCalibracion, $tieneServicio, $mesesServicio] = normalizarPeriodicidad($_POST);
    $mesesGarantia = (int)($_POST['garantia'] ?? 0);

    // H-02 · D-57: los datos de un equipo de venta propia (equipo, marca, modelo, serie y garantía)
    // se corrigen desde la venta; aquí solo se ajustan la sucursal y el mantenimiento
    if ($anterior && $anterior['origen'] === 'Venta propia') {
        $stVenta = $pdo->prepare('SELECT v.folio FROM padron_equipos p JOIN ventas v ON v.id = p.venta_id WHERE p.id = ?');
        $stVenta->execute([$id]);
        $folioVenta = $stVenta->fetchColumn();
        $cambiaVenta = $folioVenta !== false && (trim($equipo) !== trim((string)$anterior['equipo']) || trim($marca) !== trim((string)$anterior['marca'])
            || trim($modelo) !== trim((string)$anterior['modelo']) || trim($numero_serie) !== trim((string)$anterior['numero_serie'])
            || $mesesGarantia !== (int)$anterior['garantia']
            || substr((string)$fecha_registro, 0, 10) !== substr((string)$anterior['fecha_registro'], 0, 10));  // H-05: la fecha de la venta también
        if ($cambiaVenta) {
            http_response_code(409);
            die(json_encode(['exito' => false, 'success' => false, 'codigo' => 'equipo_de_venta',
                'mensaje' => "Este equipo es de la venta $folioVenta: su equipo, marca, modelo, serie, garantía y fecha se corrigen desde la venta. Aquí puedes cambiar la sucursal y el mantenimiento."], JSON_UNESCAPED_UNICODE));
        }
    }
    // H-05: una serie no se repite en el padrón (como al darlo de alta)
    $serieNorm = strtoupper(trim((string)$numero_serie));
    // (solo si la serie cambió: un duplicado heredado no impide editar la sucursal o el mantenimiento)
    $serieCambio = !$anterior || mb_strtoupper(trim((string)$anterior['numero_serie'])) !== mb_strtoupper($serieNorm);
    if ($serieCambio && $serieNorm !== '' && !in_array($serieNorm, ['S/N', 'N/A', 'SN'], true)) {
        $stSerie = $pdo->prepare('SELECT cliente FROM padron_equipos WHERE UPPER(TRIM(numero_serie)) = ? AND id <> ? LIMIT 1');
        $stSerie->execute([$serieNorm, $id]);
        if (($otroCliente = $stSerie->fetchColumn()) !== false) {
            http_response_code(409);
            die(json_encode(['exito' => false, 'success' => false, 'mensaje' => "La serie $numero_serie ya está registrada en el padrón (cliente $otroCliente)."], JSON_UNESCAPED_UNICODE));
        }
    }

    // Calculamos los vencimientos base actualizados
    // H-04 (fase 4 b): las próximas fechas solo se recalculan si cambió la periodicidad o la fecha base, con
    // meses de calendario y al siguiente que toca (editar la sucursal no reprograma ni genera atrasados)
    require_once __DIR__ . '/lib/importar_helpers.php';
    $stProx = $pdo->prepare('SELECT proxima_calibracion, proximo_servicio FROM padron_equipos WHERE id = ?');
    $stProx->execute([$id]);
    $prox = $stProx->fetch(PDO::FETCH_ASSOC) ?: ['proxima_calibracion' => null, 'proximo_servicio' => null];
    $cambiaBase = !$anterior || substr((string)$anterior['fecha_registro'], 0, 10) !== $fecha_registro;
    $fechaProximaCalibracion = $mesesCalibracion > 0
        ? (($cambiaBase || (int)$anterior['calibracion'] !== $mesesCalibracion || !$prox['proxima_calibracion']) ? impProximaFecha($fecha_registro, $mesesCalibracion) : $prox['proxima_calibracion'])
        : null;
    $fechaProximoServicio = ($tieneServicio && $mesesServicio > 0)
        ? (($cambiaBase || (int)$anterior['frecuencia_servicio'] !== $mesesServicio || !(int)$anterior['servicio'] || !$prox['proximo_servicio']) ? impProximaFecha($fecha_registro, $mesesServicio) : $prox['proximo_servicio'])
        : null;

    $sql = "UPDATE padron_equipos SET 
            sucursal = ?, equipo = ?, marca = ?, modelo = ?, numero_serie = ?, 
            calibracion = ?, servicio = ?, frecuencia_servicio = ?, garantia = ?, 
            proxima_calibracion = ?, proximo_servicio = ?, fecha_registro = ? 
            WHERE id = ?";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $sucursal, $equipo, $marca, $modelo, $numero_serie,
        $mesesCalibracion, $tieneServicio, $mesesServicio, $mesesGarantia,
        $fechaProximaCalibracion, $fechaProximoServicio, $fecha_registro, $id
    ]);
    require_once __DIR__ . '/lib/sucursales_helpers.php';
    sucLigarRegistro($pdo, 'padron_equipos', (int)$id);  // D-44

    registrarAuditoria('padron_equipos', $id, 'UPDATE', $anterior, [
        'sucursal'            => $sucursal,
        'equipo'              => $equipo,
        'marca'               => $marca,
        'modelo'              => $modelo,
        'numero_serie'        => $numero_serie,
        'calibracion'         => $mesesCalibracion,
        'servicio'            => $tieneServicio,
        'frecuencia_servicio' => $mesesServicio,
        'garantia'            => $mesesGarantia,
        'fecha_registro'      => $fecha_registro,
    ]);

    solicitarRevisionTickets();
    echo json_encode(['exito' => true, 'mensaje' => 'Equipo actualizado con éxito.']);

} catch (Exception $e) {
    echo json_encode(['exito' => false, 'mensaje' => mensajeErrorPublico($e)]);
}
?>