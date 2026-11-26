<?php
// Reglas de SLA configurables (tabla sla_reglas, migración 016).
// Requiere $pdo (config/database.php).

const PRIORIDADES_INCIDENCIA = ['Baja', 'Media', 'Alta', 'Crítica'];
const PRIORIDAD_DEFAULT = 'Media';

// Usados solo si la tabla sla_reglas no existe todavía o está vacía
// (instalación recién migrada, o entorno sin BD actualizada).
const SLA_RESPUESTA_HORAS_FALLBACK = 4;
const SLA_CIERRE_HORAS_FALLBACK = 24 * 7;

function prioridadValida($valor): bool {
    return in_array($valor, PRIORIDADES_INCIDENCIA, true);
}

/**
 * Todas las reglas de sla_reglas activas, indexadas por "categoria|prioridad"
 * (categoria vacío = regla default de esa prioridad). Se piden todas de una
 * vez y se cachean en el proceso: estadisticas_helpers.php la llama una vez
 * por incidencia del listado filtrado, nunca querremos un SELECT por fila.
 */
function obtenerReglasSla(PDO $pdo): array {
    static $reglas = null;
    if ($reglas !== null) return $reglas;

    $reglas = [];
    try {
        $stmt = $pdo->query("SELECT categoria, prioridad, horas_respuesta, horas_cierre FROM sla_reglas WHERE activo = 1");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $clave = (string)($fila['categoria'] ?? '') . '|' . $fila['prioridad'];
            $reglas[$clave] = ['horas_respuesta' => (int)$fila['horas_respuesta'], 'horas_cierre' => (int)$fila['horas_cierre']];
        }
    } catch (Throwable $e) {
        error_log('sla_reglas no disponible, se usan los umbrales fijos: ' . $e->getMessage());
    }
    return $reglas;
}

/**
 * Horas de respuesta/cierre que aplican a una incidencia según su categoría
 * y prioridad: regla específica (categoría+prioridad) -> regla default de
 * la prioridad (categoria NULL) -> umbrales fijos de respaldo.
 */
function obtenerReglaSla(PDO $pdo, ?string $categoria, ?string $prioridad): array {
    $prioridad = prioridadValida($prioridad) ? $prioridad : PRIORIDAD_DEFAULT;
    $categoria = trim((string)$categoria);
    $reglas = obtenerReglasSla($pdo);

    if ($categoria !== '' && isset($reglas[$categoria . '|' . $prioridad])) {
        return $reglas[$categoria . '|' . $prioridad];
    }
    if (isset($reglas['|' . $prioridad])) {
        return $reglas['|' . $prioridad];
    }
    return ['horas_respuesta' => SLA_RESPUESTA_HORAS_FALLBACK, 'horas_cierre' => SLA_CIERRE_HORAS_FALLBACK];
}
