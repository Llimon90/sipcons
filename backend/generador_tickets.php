<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../config/guardados_log.php';
require_once __DIR__ . '/lib/periodicidad_helpers.php';
// backend/generador_tickets.php
//
// Genera tickets automáticos de mantenimiento/calibración desde el padrón:
//   - Equipos SIN póliza (servicio = 0, meses en "calibracion"): ticket para
//     contactar al cliente y recomendar el mantenimiento/calibración.
//   - Equipos CON póliza (servicio = 1, meses en "frecuencia_servicio"):
//     ticket de servicio programado.
// Se incluye al consultar la lista de incidencias. Corre como máximo cada
// GENERADOR_CADA_SEGUNDOS y un solo proceso a la vez (dos usuarios simultáneos
// podían duplicar tickets); cualquier cambio en el padrón (venta o equipo
// externo) pide una revisión inmediata con solicitarRevisionTickets().
const GENERADOR_CADA_SEGUNDOS = 600;
const GENERADOR_DIAS_ANTICIPACION = 10;

$candadoGenerador = null;
$puedeGenerar = true;
if (is_dir(dirname(RUTA_CANDADO_GENERADOR)) && ($candadoGenerador = @fopen(RUTA_CANDADO_GENERADOR, 'c+'))) {
    if (!flock($candadoGenerador, LOCK_EX | LOCK_NB)) {
        $puedeGenerar = false; // otro usuario lo está ejecutando en este momento
    } else {
        $ultimaVez = (int)trim((string)stream_get_contents($candadoGenerador));
        if ($ultimaVez > 0 && time() - $ultimaVez < GENERADOR_CADA_SEGUNDOS) {
            $puedeGenerar = false;
        }
    }
}

// Suma meses como DATE_ADD de MySQL: el 31 de enero + 1 mes es el 28/29 de febrero.
function sumarMesesFecha(string $fecha, int $meses): string {
    $d = new DateTime($fecha);
    $dia = (int)$d->format('d');
    $d->modify('first day of this month')->modify("+$meses months");
    $d->setDate((int)$d->format('Y'), (int)$d->format('m'), min($dia, (int)$d->format('t')));
    return $d->format('Y-m-d');
}

if ($puedeGenerar) {
    $limite = date('Y-m-d', strtotime('+' . GENERADOR_DIAS_ANTICIPACION . ' days'));
    $huboErrores = false;

    $tipos = [
        // 1. SIN PÓLIZA: CONTACTAR AL CLIENTE PARA RECOMENDAR EL SERVICIO
        [
            'reporte'  => 'ALERTA-CONTACTO',
            'meses'    => 'calibracion',
            'proxima'  => 'proxima_calibracion',
            'filtro'   => 'COALESCE(p.servicio, 0) = 0 AND p.calibracion > 0',
            'falla'    => 'CONTACTAR CLIENTE (SIN PÓLIZA): Recomendar Mantenimiento/Calibración (%d equipos)',
            'notas'    => "Cliente sin póliza de mantenimiento. Contactar para recomendar mantenimiento/calibración de:\n",
        ],
        // 2. CON PÓLIZA: SERVICIO PROGRAMADO
        [
            'reporte'  => 'AUTO-SERV',
            'meses'    => 'frecuencia_servicio',
            'proxima'  => 'proximo_servicio',
            'filtro'   => 'p.servicio = 1 AND p.frecuencia_servicio > 0',
            'falla'    => 'PÓLIZA: Mantenimiento/Calibración Programado (%d equipos)',
            'notas'    => "Cliente con póliza de mantenimiento. Equipos a atender:\n",
        ],
    ];

    $stmtInsertIncidencia = $pdo->prepare("INSERT INTO incidencias
        (reporte_cliente, numero_incidente, cliente, contacto, sucursal, fecha, tecnico, falla, categoria, estatus, accion, notas)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($tipos as $tipo) {
        $colMeses = $tipo['meses'];
        $colProxima = $tipo['proxima'];
        $stmtUpdate = $pdo->prepare("UPDATE padron_equipos SET $colProxima = ? WHERE id = ?");

        try {
            $stmtEquipos = $pdo->prepare(
                "SELECT p.id, p.cliente, p.sucursal, p.equipo, p.marca, p.modelo, p.numero_serie,
                        p.$colMeses AS meses, p.$colProxima AS proxima, p.origen, v.folio AS venta_folio
                 FROM padron_equipos p
                 LEFT JOIN ventas v ON p.venta_id = v.id
                 WHERE {$tipo['filtro']} AND p.$colProxima IS NOT NULL AND p.$colProxima <= ?"
            );
            $stmtEquipos->execute([$limite]);
            $equipos = $stmtEquipos->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $huboErrores = true;
            registrarFalloGuardado('ticket_automatico', $tipo['reporte'], 'Consulta del padrón: ' . $e->getMessage());
            continue;
        }

        // Agrupamos por cliente y sucursal para no separar los equipos externos de los comprados
        $grupos = [];
        foreach ($equipos as $eq) {
            $grupos[$eq['cliente'] . '|' . $eq['sucursal']][] = $eq;
        }

        // Un ticket por grupo, cada uno en su propia transacción: si uno falla
        // los demás se generan igual y el fallo queda en la bitácora.
        foreach ($grupos as $grupo) {
            try {
                $pdo->beginTransaction();

                $ultimo = $pdo->query("SELECT numero_incidente FROM incidencias ORDER BY id DESC LIMIT 1 FOR UPDATE")->fetchColumn();
                $num = ($ultimo && preg_match('/SIP-(\d+)/', $ultimo, $m)) ? (int)$m[1] : 0;
                $folioNuevo = "SIP-" . str_pad($num + 1, 4, "0", STR_PAD_LEFT);

                $primerEq = $grupo[0];
                $cantidad = count($grupo);
                $notas = $tipo['notas'];
                foreach ($grupo as $eq) {
                    $serie = !empty($eq['numero_serie']) ? $eq['numero_serie'] : 'S/N';
                    $ref = $eq['origen'] === 'Venta SIPCONS' ? "(Venta #{$eq['venta_folio']})" : "(Equipo Externo)";
                    $notas .= "- {$eq['marca']} {$eq['modelo']} (Serie: $serie) $ref | Vence: {$eq['proxima']}\n";
                }
                $notas .= "\nTicket generado automáticamente con " . GENERADOR_DIAS_ANTICIPACION . " días de anticipación.";
                $nombreEquipo = $cantidad > 1 ? "Múltiples Equipos" : $primerEq['equipo'];

                $stmtInsertIncidencia->execute([
                    $tipo['reporte'], $folioNuevo, $primerEq['cliente'], "Sistema SIPCONS", $primerEq['sucursal'],
                    date('Y-m-d'), "", sprintf($tipo['falla'], $cantidad), $nombreEquipo, "Abierto", "", $notas
                ]);

                // La siguiente fecha queda después del límite: un equipo con
                // fechas atrasadas genera un solo ticket, no uno por periodo vencido.
                foreach ($grupo as $eq) {
                    $meses = max(1, (int)$eq['meses']);
                    $periodos = 1;
                    while (($siguiente = sumarMesesFecha($eq['proxima'], $meses * $periodos)) <= $limite) {
                        $periodos++;
                    }
                    $stmtUpdate->execute([$siguiente, $eq['id']]);
                }

                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $huboErrores = true;
                registrarFalloGuardado('ticket_automatico', $grupo[0]['cliente'], $tipo['reporte'] . ': ' . $e->getMessage());
                error_log("Error generando ticket automático ({$tipo['reporte']}, {$grupo[0]['cliente']}): " . $e->getMessage());
            }
        }
    }

    // Si algo falló no se marca la hora: se reintenta en la siguiente consulta.
    if ($candadoGenerador && !$huboErrores) {
        ftruncate($candadoGenerador, 0);
        rewind($candadoGenerador);
        fwrite($candadoGenerador, (string)time());
        fflush($candadoGenerador);
    }
}

if ($candadoGenerador) {
    flock($candadoGenerador, LOCK_UN);
    fclose($candadoGenerador);
}
?>