<?php
require_once __DIR__ . '/../auth/middleware.php';
// backend/generador_tickets.php
//
// Genera tickets automáticos de mantenimiento/calibración desde el padrón:
//   - Equipos SIN póliza (servicio = 0, meses en "calibracion"): ticket para
//     contactar al cliente y recomendar el mantenimiento/calibración.
//   - Equipos CON póliza (servicio = 1, meses en "frecuencia_servicio"):
//     ticket de servicio programado.
// Antes
// corría en cada consulta y en cada alta de incidencia, haciendo lentas
// ambas. Ahora corre como máximo cada GENERADOR_CADA_SEGUNDOS y un solo
// proceso a la vez (dos usuarios simultáneos podían duplicar tickets).
const GENERADOR_CADA_SEGUNDOS = 600;

$candadoGenerador = null;
$rutaCandado = __DIR__ . '/../storage/logs/.generador_tickets';
$puedeGenerar = true;
if (is_dir(dirname($rutaCandado)) && ($candadoGenerador = @fopen($rutaCandado, 'c+'))) {
    if (!flock($candadoGenerador, LOCK_EX | LOCK_NB)) {
        $puedeGenerar = false; // otro usuario lo está ejecutando en este momento
    } else {
        $ultimaVez = (int)trim((string)stream_get_contents($candadoGenerador));
        if ($ultimaVez > 0 && time() - $ultimaVez < GENERADOR_CADA_SEGUNDOS) {
            $puedeGenerar = false;
        }
    }
}

if ($puedeGenerar) {
try {
    $pdo->beginTransaction();

    function obtenerNuevoFolioIncidencia($pdo) {
        $stmt = $pdo->query("SELECT numero_incidente FROM incidencias ORDER BY id DESC LIMIT 1");
        $ultimo = $stmt->fetchColumn();
        if ($ultimo && preg_match('/SIP-(\d+)/', $ultimo, $matches)) {
            $num = (int)$matches[1];
        } else {
            $num = 0;
        }
        return "SIP-" . str_pad($num + 1, 4, "0", STR_PAD_LEFT);
    }

    $sqlInsert = "INSERT INTO incidencias 
        (reporte_cliente, numero_incidente, cliente, contacto, sucursal, fecha, tecnico, falla, categoria, estatus, accion, notas) 
        VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?)";
    $stmtInsertIncidencia = $pdo->prepare($sqlInsert);

    // ========================================================
    // 1. SIN PÓLIZA: CONTACTAR AL CLIENTE PARA RECOMENDAR EL SERVICIO
    // ========================================================
    $sqlCal = "SELECT p.id, p.cliente, p.sucursal, p.equipo, p.marca, p.modelo, p.numero_serie, p.calibracion, p.proxima_calibracion, p.origen, p.venta_id, v.folio AS venta_folio
               FROM padron_equipos p
               LEFT JOIN ventas v ON p.venta_id = v.id
               WHERE COALESCE(p.servicio, 0) = 0
                 AND p.calibracion > 0 AND p.proxima_calibracion <= DATE_ADD(CURDATE(), INTERVAL 10 DAY)";
    
    $equiposCalibracion = $pdo->query($sqlCal)->fetchAll(PDO::FETCH_ASSOC);
    $stmtUpdateCal = $pdo->prepare("UPDATE padron_equipos SET proxima_calibracion = DATE_ADD(proxima_calibracion, INTERVAL ? MONTH) WHERE id = ?");

    // Agrupamos por cliente y sucursal para no separar los equipos externos de los comprados
    $gruposCalibracion = [];
    foreach ($equiposCalibracion as $eq) {
        $llaveGrupo = $eq['cliente'] . '|' . $eq['sucursal'];
        $gruposCalibracion[$llaveGrupo][] = $eq;
    }

    foreach ($gruposCalibracion as $llave => $equipos) {
        $folioNuevo = obtenerNuevoFolioIncidencia($pdo);
        $primerEq = $equipos[0]; 
        $cantidad = count($equipos);
        
        $falla = "CONTACTAR CLIENTE (SIN PÓLIZA): Recomendar Mantenimiento/Calibración ($cantidad equipos)";
        $notas = "Cliente sin póliza de mantenimiento. Contactar para recomendar mantenimiento/calibración de:\n";
        
        foreach ($equipos as $eq) {
            $serie = !empty($eq['numero_serie']) ? $eq['numero_serie'] : 'S/N';
            $ref = $eq['origen'] === 'Venta SIPCONS' ? "(Venta #{$eq['venta_folio']})" : "(Equipo Externo)";
            $notas .= "- {$eq['marca']} {$eq['modelo']} (Serie: $serie) $ref | Vence: {$eq['proxima_calibracion']}\n";
        }
        $notas .= "\nTicket generado automáticamente con 10 días de anticipación.";
        $nombreEquipo = $cantidad > 1 ? "Múltiples Equipos" : $primerEq['equipo'];

        $stmtInsertIncidencia->execute([
            "ALERTA-CONTACTO", $folioNuevo, $primerEq['cliente'], "Sistema SIPCONS", $primerEq['sucursal'],
            "", $falla, $nombreEquipo, "Abierto", "", $notas
        ]);
        
        foreach ($equipos as $eq) {
            $stmtUpdateCal->execute([$eq['calibracion'], $eq['id']]);
        }
    }

    // ========================================================
    // 2. CON PÓLIZA: SERVICIO PROGRAMADO
    // ========================================================
    $sqlServ = "SELECT p.id, p.cliente, p.sucursal, p.equipo, p.marca, p.modelo, p.numero_serie, p.frecuencia_servicio, p.proximo_servicio, p.origen, p.venta_id, v.folio AS venta_folio
                FROM padron_equipos p
                LEFT JOIN ventas v ON p.venta_id = v.id
                WHERE p.servicio = 1 AND p.frecuencia_servicio > 0 AND p.proximo_servicio <= DATE_ADD(CURDATE(), INTERVAL 10 DAY)";
    
    $equiposServicio = $pdo->query($sqlServ)->fetchAll(PDO::FETCH_ASSOC);
    $stmtUpdateServ = $pdo->prepare("UPDATE padron_equipos SET proximo_servicio = DATE_ADD(proximo_servicio, INTERVAL ? MONTH) WHERE id = ?");

    $gruposServicio = [];
    foreach ($equiposServicio as $eq) {
        $llaveGrupo = $eq['cliente'] . '|' . $eq['sucursal'];
        $gruposServicio[$llaveGrupo][] = $eq;
    }

    foreach ($gruposServicio as $llave => $equipos) {
        $folioNuevo = obtenerNuevoFolioIncidencia($pdo);
        $primerEq = $equipos[0];
        $cantidad = count($equipos);
        
        $falla = "PÓLIZA: Mantenimiento/Calibración Programado ($cantidad equipos)";
        $notas = "Cliente con póliza de mantenimiento. Equipos a atender:\n";
        
        foreach ($equipos as $eq) {
            $serie = !empty($eq['numero_serie']) ? $eq['numero_serie'] : 'S/N';
            $ref = $eq['origen'] === 'Venta SIPCONS' ? "(Venta #{$eq['venta_folio']})" : "(Equipo Externo)";
            $notas .= "- {$eq['marca']} {$eq['modelo']} (Serie: $serie) $ref | Vence: {$eq['proximo_servicio']}\n";
        }
        $notas .= "\nTicket generado automáticamente con 10 días de anticipación.";
        $nombreEquipo = $cantidad > 1 ? "Múltiples Equipos" : $primerEq['equipo'];

        $stmtInsertIncidencia->execute([
            "AUTO-SERV", $folioNuevo, $primerEq['cliente'], "Sistema SIPCONS", $primerEq['sucursal'], 
            "", $falla, $nombreEquipo, "Abierto", "", $notas
        ]);
        
        foreach ($equipos as $eq) {
            $stmtUpdateServ->execute([$eq['frecuencia_servicio'], $eq['id']]);
        }
    }

    $pdo->commit();

    if ($candadoGenerador) {
        ftruncate($candadoGenerador, 0);
        rewind($candadoGenerador);
        fwrite($candadoGenerador, (string)time());
        fflush($candadoGenerador);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error generando tickets automáticos: " . $e->getMessage());
}
}

if ($candadoGenerador) {
    flock($candadoGenerador, LOCK_UN);
    fclose($candadoGenerador);
}
?>