<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/lib/sla_helpers.php';

if ($conn->connect_error) {
    die(json_encode(["error" => "Error de conexión: " . $conn->connect_error]));
}

// Antes el criterio era fijo (fecha < NOW()-7 días). Ahora cada incidencia
// abierta se compara contra su propio SLA de cierre (categoria+prioridad,
// ver sla_reglas / obtenerReglaSla), así que una incidencia Crítica puede
// alertar antes de 7 días y una Baja después, según cómo se configure.
$sql = "SELECT id, numero_incidente, cliente, sucursal, estatus, fecha, categoria, prioridad
        FROM incidencias
        WHERE estatus <> 'Cerrado con factura'
        ORDER BY fecha ASC";

$result = $conn->query($sql);

$alertas = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $regla = obtenerReglaSla($pdo, $row['categoria'] ?? null, $row['prioridad'] ?? null);
        $ts = strtotime($row['fecha']);
        if ($ts === false) continue;
        $horasAbierta = (time() - $ts) / 3600;
        if ($horasAbierta >= $regla['horas_cierre']) {
            $row['horas_sla_cierre'] = $regla['horas_cierre'];
            $row['horas_transcurridas'] = round($horasAbierta, 1);
            $alertas[] = $row;
        }
    }
}

echo json_encode(["alertas" => $alertas]);

$conn->close();
