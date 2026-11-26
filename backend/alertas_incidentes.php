<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/lib/sla_helpers.php';

if ($conn->connect_error) {
    die(json_encode(["error" => "Error de conexión: " . $conn->connect_error]));
}

const ESTATUS_CERRADOS_ALERTAS = ['Cerrado con factura', 'Cerrado sin factura'];

// Antes el criterio era fijo (fecha < NOW()-7 días). Ahora cada incidencia
// abierta se compara contra su propio SLA de cierre (categoria+prioridad,
// ver sla_reglas / obtenerReglaSla), así que una incidencia Crítica puede
// alertar antes de 7 días y una Baja después, según cómo se configure.
// (Antes solo excluía 'Cerrado con factura'; 'Cerrado sin factura' también
// es un cierre y no debía seguir generando alertas de SLA vencido.)
$sql = "SELECT id, numero_incidente, cliente, sucursal, estatus, fecha, categoria, prioridad, tecnico, escalado
        FROM incidencias
        WHERE estatus NOT IN ('" . implode("','", ESTATUS_CERRADOS_ALERTAS) . "')
        ORDER BY fecha ASC";

$result = $conn->query($sql);

// Columna opcional (migración 063); si el entorno todavía no la tiene, el
// escalamiento simplemente no se marca (no debe tronar la alerta de SLA).
$tieneEscalado = (bool)$conn->query("SHOW COLUMNS FROM incidencias LIKE 'escalado'")->fetch_assoc();
$marcarEscalado = $tieneEscalado ? $pdo->prepare("UPDATE incidencias SET escalado = 1 WHERE id = ?") : null;

$alertas = [];
$escaladasNuevas = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $regla = obtenerReglaSla($pdo, $row['categoria'] ?? null, $row['prioridad'] ?? null);
        $ts = strtotime($row['fecha']);
        if ($ts === false) continue;
        $horasAbierta = (time() - $ts) / 3600;

        // Escalamiento: sin técnico asignado y dentro del último 25% del
        // tiempo de RESPUESTA (no de cierre) del SLA que le toca. Solo se
        // marca una vez; no hay envío de correo real (no hay SMTP todavía),
        // solo queda marcada para que la pantalla la resalte.
        $sinTecnico = trim((string)($row['tecnico'] ?? '')) === '';
        $limiteRespuesta = (float)($regla['horas_respuesta'] ?? 0);
        if ($marcarEscalado && !$row['escalado'] && $sinTecnico && $limiteRespuesta > 0 && $horasAbierta >= $limiteRespuesta * 0.75) {
            $marcarEscalado->execute([$row['id']]);
            $row['escalado'] = 1;
            $escaladasNuevas[] = $row['numero_incidente'];
        }

        if ($horasAbierta >= $regla['horas_cierre']) {
            $row['horas_sla_cierre'] = $regla['horas_cierre'];
            $row['horas_transcurridas'] = round($horasAbierta, 1);
            $alertas[] = $row;
        }
    }
}

if ($escaladasNuevas) {
    require_once __DIR__ . '/../auth/audit.php';
    registrarAuditoria('incidencias', null, 'UPDATE', null, ['escaladas' => $escaladasNuevas]);
}

echo json_encode(["alertas" => $alertas]);

$conn->close();
