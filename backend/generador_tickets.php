<?php
// backend/generador_tickets.php
require_once 'conexion.php';

try {
    $pdo->beginTransaction();

    // Función para generar folio consecutivo
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
    // 1. TICKETS DE CALIBRACIÓN (10 DÍAS ANTES DEL VENCIMIENTO)
    // ========================================================
    // CAMBIO AQUÍ: <= DATE_ADD(CURDATE(), INTERVAL 10 DAY)
    $sqlCal = "SELECT d.id, v.id as venta_id, v.cliente, v.sucursal, d.equipo, d.marca, d.modelo, d.numero_serie, d.calibracion, d.proxima_calibracion 
               FROM venta_detalles d JOIN ventas v ON d.venta_id = v.id 
               WHERE d.calibracion > 0 AND d.proxima_calibracion <= DATE_ADD(CURDATE(), INTERVAL 10 DAY)";
    
    $equiposCalibracion = $pdo->query($sqlCal)->fetchAll(PDO::FETCH_ASSOC);
    $stmtUpdateCal = $pdo->prepare("UPDATE venta_detalles SET proxima_calibracion = DATE_ADD(proxima_calibracion, INTERVAL ? MONTH) WHERE id = ?");

    // Agrupar los equipos por venta_id
    $gruposCalibracion = [];
    foreach ($equiposCalibracion as $eq) {
        $gruposCalibracion[$eq['venta_id']][] = $eq;
    }

    // Procesar cada grupo como un solo ticket
    foreach ($gruposCalibracion as $venta_id => $equipos) {
        $folioNuevo = obtenerNuevoFolioIncidencia($pdo);
        $primerEq = $equipos[0]; // Tomamos cliente y sucursal del primer equipo
        $cantidad = count($equipos);
        
        $falla = "PREVENTIVO: Calibración Próxima a Vencer ($cantidad equipos)";
        
        // Construimos el detalle de todos los equipos para las notas
        $notas = "Venta asociada: #$venta_id\nEquipos a calibrar:\n";
        foreach ($equipos as $eq) {
            $serie = !empty($eq['numero_serie']) ? $eq['numero_serie'] : 'S/N';
            // Mostramos la fecha exacta en la que vence para que el técnico sepa su límite
            $notas .= "- {$eq['marca']} {$eq['modelo']} (Serie: $serie) | Vence: {$eq['proxima_calibracion']}\n";
        }
        $notas .= "\nTicket generado automáticamente con 10 días de anticipación al vencimiento.";

        // Determinamos el nombre del equipo (Si es más de uno, ponemos Múltiples)
        $nombreEquipo = $cantidad > 1 ? "Múltiples Equipos" : $primerEq['equipo'];

        // Generar 1 solo ticket por este grupo
        $stmtInsertIncidencia->execute([
            "AUTO-CAL",         // numero (folio cliente)
            $folioNuevo,        // numero_incidente (SIP-XXXX)
            $primerEq['cliente'],// cliente
            "Sistema SIPCONS",  // contacto
            $primerEq['sucursal'],// sucursal
            "",                 // tecnico (Vacío para asignar)
            $falla,             // falla
            $nombreEquipo,      // equipo
            "Abierto",          // estatus
            "",                 // accion
            $notas              // notas condensadas
        ]);
        
        // Actualizar la fecha para la SIGUIENTE calibración de cada equipo en este grupo
        // (Se suma a partir de la fecha que tenía programada, conservando su ciclo original)
        foreach ($equipos as $eq) {
            $stmtUpdateCal->execute([$eq['calibracion'], $eq['id']]);
        }
    }

    // ========================================================
    // 2. TICKETS DE SERVICIO (10 DÍAS ANTES DEL VENCIMIENTO)
    // ========================================================
    // CAMBIO AQUÍ: <= DATE_ADD(CURDATE(), INTERVAL 10 DAY)
    $sqlServ = "SELECT d.id, v.id as venta_id, v.cliente, v.sucursal, d.equipo, d.marca, d.modelo, d.numero_serie, d.frecuencia_servicio, d.proximo_servicio 
                FROM venta_detalles d JOIN ventas v ON d.venta_id = v.id 
                WHERE d.servicio = 1 AND d.frecuencia_servicio > 0 AND d.proximo_servicio <= DATE_ADD(CURDATE(), INTERVAL 10 DAY)";
    
    $equiposServicio = $pdo->query($sqlServ)->fetchAll(PDO::FETCH_ASSOC);
    $stmtUpdateServ = $pdo->prepare("UPDATE venta_detalles SET proximo_servicio = DATE_ADD(proximo_servicio, INTERVAL ? MONTH) WHERE id = ?");

    // Agrupar los equipos por venta_id
    $gruposServicio = [];
    foreach ($equiposServicio as $eq) {
        $gruposServicio[$eq['venta_id']][] = $eq;
    }

    // Procesar cada grupo como un solo ticket
    foreach ($gruposServicio as $venta_id => $equipos) {
        $folioNuevo = obtenerNuevoFolioIncidencia($pdo);
        $primerEq = $equipos[0];
        $cantidad = count($equipos);
        
        $falla = "PREVENTIVO: Cláusula de Servicio Próxima a Vencer ($cantidad equipos)";
        
        // Construimos el detalle de todos los equipos para las notas
        $notas = "Venta asociada: #$venta_id\nEquipos para mantenimiento:\n";
        foreach ($equipos as $eq) {
            $serie = !empty($eq['numero_serie']) ? $eq['numero_serie'] : 'S/N';
            // Mostramos la fecha exacta en la que vence
            $notas .= "- {$eq['marca']} {$eq['modelo']} (Serie: $serie) | Vence: {$eq['proximo_servicio']}\n";
        }
        $notas .= "\nTicket generado automáticamente con 10 días de anticipación al vencimiento.";

        $nombreEquipo = $cantidad > 1 ? "Múltiples Equipos" : $primerEq['equipo'];

        // Generar 1 solo ticket por este grupo
        $stmtInsertIncidencia->execute([
            "AUTO-SERV",        // numero
            $folioNuevo,        // numero_incidente (SIP-XXXX)
            $primerEq['cliente'],// cliente
            "Sistema SIPCONS",  // contacto
            $primerEq['sucursal'],// sucursal
            "",                 // tecnico (Vacío para asignar)
            $falla,             // falla
            $nombreEquipo,      // equipo
            "Abierto",          // estatus
            "",                 // accion
            $notas              // notas condensadas
        ]);
        
        // Actualizar la fecha para el SIGUIENTE servicio de cada equipo en este grupo
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