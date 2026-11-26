<?php
// Reglas de automatización simples (tabla reglas_automatizacion, migración 060).
// A propósito sin regex ni condiciones compuestas: solo igualdad de texto
// (sin distinguir mayúsculas/espacios) sobre categoria/sucursal/prioridad.
// Requiere $pdo (config/database.php).

const CAMPOS_CONDICION_AUTOMATIZACION = ['categoria', 'sucursal', 'prioridad'];
const ACCIONES_AUTOMATIZACION = ['asignar_tecnico', 'cambiar_prioridad'];

function campoCondicionValido($valor): bool {
    return in_array($valor, CAMPOS_CONDICION_AUTOMATIZACION, true);
}

function accionAutomatizacionValida($valor): bool {
    return in_array($valor, ACCIONES_AUTOMATIZACION, true);
}

/**
 * Evalúa las reglas activas (en orden) contra los datos de una incidencia
 * nueva y devuelve solo los campos que deben sobreescribirse, por ejemplo
 * ['tecnico' => 'ABC'] o ['prioridad' => 'Alta']. La última regla que
 * coincide para un mismo campo gana (se procesan en orden ascendente).
 * Solo se usa al CREAR (backend/server.php), nunca al editar: no se quiere
 * pelear con un cambio manual que haga el técnico después.
 */
function aplicarReglasAutomatizacion(PDO $pdo, array $datosIncidencia): array {
    $sobreescribir = [];

    try {
        $stmt = $pdo->query(
            "SELECT campo_condicion, valor_condicion, accion, valor_accion
             FROM reglas_automatizacion WHERE activo = 1 ORDER BY orden ASC, id ASC"
        );
        $reglas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('reglas_automatizacion no disponible: ' . $e->getMessage());
        return [];
    }

    foreach ($reglas as $regla) {
        $campo = $regla['campo_condicion'];
        if (!campoCondicionValido($campo)) continue;

        $valorIncidencia = mb_strtolower(trim((string)($datosIncidencia[$campo] ?? '')));
        $valorCondicion = mb_strtolower(trim((string)$regla['valor_condicion']));
        if ($valorCondicion === '' || $valorIncidencia !== $valorCondicion) continue;

        if ($regla['accion'] === 'asignar_tecnico') {
            $sobreescribir['tecnico'] = (string)$regla['valor_accion'];
        } elseif ($regla['accion'] === 'cambiar_prioridad' && prioridadValida($regla['valor_accion'])) {
            $sobreescribir['prioridad'] = (string)$regla['valor_accion'];
        }
    }

    return $sobreescribir;
}
