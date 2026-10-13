<?php
// backend/lib/periodicidad_helpers.php
//
// Los formularios capturan un solo dato de periodicidad (campo "calibracion",
// en meses) y un check de póliza (campo "servicio"):
//   - servicio = 0: el cliente NO tiene póliza/acuerdo formal. Se guarda en
//     "calibracion" y el generador crea un ticket para CONTACTAR al cliente y
//     recomendar mantenimiento/calibración.
//   - servicio = 1: el cliente aceptó póliza/acuerdo formal. Se guarda en
//     "frecuencia_servicio" y el generador crea el ticket de SERVICIO programado.
// Así nunca se generan ambos tickets para el mismo equipo.

/**
 * @return array{0:int,1:int,2:int} [calibracion, servicio, frecuencia_servicio]
 */
function normalizarPeriodicidad(array $datos): array {
    $poliza = !empty($datos['servicio']) ? 1 : 0;
    $meses = (int)($datos['calibracion'] ?? 0);
    // Compatibilidad con formularios anteriores que mandaban los meses de la
    // póliza en "frecuencia_servicio".
    if ($meses <= 0 && $poliza) {
        $meses = (int)($datos['frecuencia_servicio'] ?? 0);
    }
    if ($meses <= 0) {
        return [0, $poliza, 0];
    }
    return $poliza ? [0, 1, $meses] : [$meses, 0, 0];
}

// Candado/marca de tiempo del generador de tickets (generador_tickets.php).
const RUTA_CANDADO_GENERADOR = __DIR__ . '/../../storage/logs/.generador_tickets';

/**
 * Tras un cambio en el padrón, hace que el generador de tickets revise en la
 * siguiente consulta en vez de esperar su intervalo normal.
 */
function solicitarRevisionTickets(): void {
    if (is_file(RUTA_CANDADO_GENERADOR)) {
        @file_put_contents(RUTA_CANDADO_GENERADOR, '0', LOCK_EX);
    }
}
