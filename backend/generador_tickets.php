<?php
// backend/generador_tickets.php
require_once 'conexion.php';

try {
    $pdo->beginTransaction();

    // Función adaptada a tu formato SIP-XXXX
    function obtenerNuevoFolioIncidencia($pdo) {
        $stmt = $pdo->query("SELECT numero_incidente FROM incidencias ORDER BY id DESC LIMIT 1");
        $ultimo = $stmt->fetchColumn();
        
        // Extraer el número del SIP-0076
        if ($ultimo && preg_match('/SIP-(\d+)/', $ultimo, $matches)) {
            $num = (int)$matches[1];
        } else {
            $num = 0;
        }
        return "SIP-" . str_pad($num + 1, 4, "0", STR_PAD_LEFT);
    }

    // Consulta de INSERT adaptada a las 12 columnas exactas de tu tabla
    $sqlInsert = "INSERT INTO incidencias 
        (numero, numero_incidente, cliente, contacto, sucursal, fecha, tecnico, falla, equipo, estatus, accion, notas) 
        VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?)";
    $stmtInsertIncidencia = $pdo->prepare($sqlInsert);

    // ========================================================
    // 1. TICKETS DE CALIBRACIÓN RECOMENDADA
    // ========================================================
    $sqlCal = "SELECT d.id, v.cliente, v.sucursal, d.equipo, d.marca, d.modelo, d.numero_serie, d.calibracion, d.proxima_calibracion 
               FROM venta_detalles d JOIN ventas v ON d.venta_id = v.id 
               WHERE d.calibracion > 0 AND d.proxima_calibracion <= CURDATE()";
    
    $equiposCalibracion = $pdo->query($sqlCal)->fetchAll(PDO::FETCH_ASSOC);
    $stmtUpdateCal = $pdo->prepare("UPDATE venta_detalles SET proxima_calibracion = DATE_ADD(proxima_calibracion, INTERVAL ? MONTH) WHERE id = ?");

    foreach ($equiposCalibracion as $eq) {
        $folioNuevo = obtenerNuevoFolioIncidencia($pdo);
        $falla = "ALERTA AUTOMÁTICA: Calibración Recomendada";
        $notas = "MARCA: {$eq['marca']} | MODELO: {$eq['modelo']} | SERIE: {$eq['numero_serie']}\nEl sistema detectó que han pasado {$eq['calibracion']} meses desde su registro. Se recomienda programar calibración.";

        $stmtInsertIncidencia->execute([
            "AUTO-CAL",         // numero (folio cliente)
            $folioNuevo,        // numero_incidente (SIP-XXXX)
            $eq['cliente'],     // cliente
            "Sistema SIPCONS",  // contacto
            $eq['sucursal'],    // sucursal
            "",                 // tecnico (Vacio para asignar)
            $falla,             // falla
            $eq['equipo'],      // equipo
            "Abierto",          // estatus
            "",                 // accion
            $notas              // notas
        ]);
        
        // Empujar la fecha para la SIGUIENTE calibración
        $stmtUpdateCal->execute([$eq['calibracion'], $eq['id']]);
    }

    // ========================================================
    // 2. TICKETS DE CLÁUSULA DE SERVICIO
    // ========================================================
    $sqlServ = "SELECT d.id, v.cliente, v.sucursal, d.equipo, d.marca, d.modelo, d.numero_serie, d.frecuencia_servicio, d.proximo_servicio 
                FROM venta_detalles d JOIN ventas v ON d.venta_id = v.id 
                WHERE d.servicio = 1 AND d.frecuencia_servicio > 0 AND d.proximo_servicio <= CURDATE()";
    
    $equiposServicio = $pdo->query($sqlServ)->fetchAll(PDO::FETCH_ASSOC);
    $stmtUpdateServ = $pdo->prepare("UPDATE venta_detalles SET proximo_servicio = DATE_ADD(proximo_servicio, INTERVAL ? MONTH) WHERE id = ?");

    foreach ($equiposServicio as $eq) {
        $folioNuevo = obtenerNuevoFolioIncidencia($pdo);
        $falla = "MANTENIMIENTO PREVENTIVO (Cláusula de Servicio)";
        $notas = "MARCA: {$eq['marca']} | MODELO: {$eq['modelo']} | SERIE: {$eq['numero_serie']}\nMantenimiento agendado correspondiente a la cláusula de {$eq['frecuencia_servicio']} meses.";

        $stmtInsertIncidencia->execute([
            "AUTO-SERV",        // numero
            $folioNuevo,        // numero_incidente (SIP-XXXX)
            $eq['cliente'],     // cliente
            "Sistema SIPCONS",  // contacto
            $eq['sucursal'],    // sucursal
            "",                 // tecnico (Vacio para asignar)
            $falla,             // falla
            $eq['equipo'],      // equipo
            "Abierto",          // estatus
            "",                 // accion
            $notas              // notas
        ]);
        
        // Empujar la fecha para el SIGUIENTE servicio
        $stmtUpdateServ->execute([$eq['frecuencia_servicio'], $eq['id']]);
    }

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error generando tickets automáticos: " . $e->getMessage());
}
?>