<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermisoAlguno('incidencias');

if ($conn->connect_error) {
    die(json_encode(["error" => "Error de conexión: " . $conn->connect_error]));
}

// Criterio: más días abierta que la meta de cumplimiento de la empresa (Mi empresa) y sin cerrar
// D-9: el Técnico solo ve las alertas de sus órdenes
require_once __DIR__ . '/lib/incidencias_helpers.php';
[$soloMias, $paramsMias] = filtroOrdenesSesion($pdo);
$st = $pdo->prepare("SELECT id, numero_incidente, cliente, sucursal, estatus, fecha, tecnico
        FROM incidencias
        WHERE " . sqlOrdenAtrasada() . "  -- D-18 (y D-4, D-33: ni cerradas, ni canceladas, ni completadas)
          AND $soloMias
        ORDER BY fecha ASC");
$st->execute($paramsMias);
$alertas = array_map(function ($f) { unset($f['tecnico']); return $f; }, filtrarOrdenesVisibles($pdo, $st->fetchAll(PDO::FETCH_ASSOC)));

echo json_encode(["alertas" => $alertas]);

$conn->close();
?>
