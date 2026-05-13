<?php
// backend/generador_tickets.php
require_once 'conexion.php';

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
        (numero, numero_incidente, cliente, contacto, sucursal, fecha, tecnico, falla, equipo, estatus, accion, notas) 
        VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?)";
    $stmtInsertIncidencia = $pdo->prepare($sqlInsert);

    // ========================================================
    // 1. TICKETS DE CALIBRACIÓN LEYENDO DESDE PADRÓN
    // ========================================================
    $sqlCal = "SELECT id, cliente, sucursal, equipo, marca, modelo, numero_serie, calibracion, proxima_calibracion, origen, venta_id 
               FROM padron_equipos 
               WHERE calibracion > 0 AND proxima_calibracion <= DATE_ADD(CURDATE(), INTERVAL 10 DAY)";
    
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
        
        $falla = "PREVENTIVO: Calibración Próxima a Vencer ($cantidad equipos)";
        $notas = "Equipos a calibrar:\n";
        
        foreach ($equipos as $eq) {
            $serie = !empty($eq['numero_serie']) ? $eq['numero_serie'] : 'S/N';
            $ref = $eq['origen'] === 'Venta SIPCONS' ? "(Venta #{$eq['venta_id']})" : "(Equipo Externo)";
            $notas .= "- {$eq['marca']} {$eq['modelo']} (Serie: $serie) $ref | Vence: {$eq['proxima_calibracion']}\n";
        }
        $notas .= "\nTicket generado automáticamente con 10 días de anticipación.";
        $nombreEquipo = $cantidad > 1 ? "Múltiples Equipos" : $primerEq['equipo'];

        $stmtInsertIncidencia->execute([
            "AUTO-CAL", $folioNuevo, $primerEq['cliente'], "Sistema SIPCONS", $primerEq['sucursal'], 
            "", $falla, $nombreEquipo, "Abierto", "", $notas
        ]);
        
        foreach ($equipos as $eq) {
            $stmtUpdateCal->execute([$eq['calibracion'], $eq['id']]);
        }
    }

    // ========================================================
    // 2. TICKETS DE SERVICIO LEYENDO DESDE PADRÓN
    // ========================================================
    $sqlServ = "SELECT id, cliente, sucursal, equipo, marca, modelo, numero_serie, frecuencia_servicio, proximo_servicio, origen, venta_id 
                FROM padron_equipos 
                WHERE servicio = 1 AND frecuencia_servicio > 0 AND proximo_servicio <= DATE_ADD(CURDATE(), INTERVAL 10 DAY)";
    
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
        
        $falla = "PREVENTIVO: Mantenimiento Próximo a Vencer ($cantidad equipos)";
        $notas = "Equipos para mantenimiento:\n";
        
        foreach ($equipos as $eq) {
            $serie = !empty($eq['numero_serie']) ? $eq['numero_serie'] : 'S/N';
            $ref = $eq['origen'] === 'Venta Lumina' ? "(Venta #{$eq['venta_id']})" : "(Equipo Externo)";
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
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error generando tickets automáticos: " . $e->getMessage());
}
?>