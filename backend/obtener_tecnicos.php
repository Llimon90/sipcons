<?php
// Lista de técnicos asignables para los selects de incidencias y filtros.
// Sale de la tabla usuarios (no de una lista fija en el HTML), así que un
// técnico nuevo aparece en cuanto se da de alta con uno de estos roles.
//
// `valor` es el texto que se guarda en incidencias.tecnico: alias_tecnico si
// existe, si no el nombre. Es el mismo criterio con el que el dashboard del
// técnico reconoce sus incidencias (migraciones 008/009), así que las
// incidencias históricas siguen coincidiendo.
require_once __DIR__ . '/../auth/middleware.php';

const ROLES_TECNICO_ASIGNABLE = ['Técnico', 'Técnico/Administrador', 'Supervisor', 'Administrador'];

try {
    // alias_tecnico (migración 008) es opcional; sin la columna se usa el nombre.
    $colAlias = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'alias_tecnico'")->fetch();
    $exprValor = $colAlias ? "COALESCE(NULLIF(TRIM(alias_tecnico), ''), TRIM(nombre))" : "TRIM(nombre)";

    $placeholders = implode(',', array_fill(0, count(ROLES_TECNICO_ASIGNABLE), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, TRIM(nombre) AS nombre, {$exprValor} AS valor
         FROM usuarios
         WHERE rol IN ($placeholders) AND TRIM(COALESCE(nombre, '')) <> ''
         ORDER BY nombre"
    );
    $stmt->execute(ROLES_TECNICO_ASIGNABLE);

    // El valor se guarda unido con "/" en incidencias.tecnico: no puede repetirse.
    $tecnicos = [];
    $vistos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $clave = mb_strtolower($fila['valor']);
        if (isset($vistos[$clave])) continue;
        $vistos[$clave] = true;
        $tecnicos[] = ['id' => (int)$fila['id'], 'nombre' => $fila['nombre'], 'valor' => $fila['valor']];
    }

    echo json_encode(['success' => true, 'data' => $tecnicos]);
} catch (Throwable $e) {
    error_log('obtener_tecnicos: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudo cargar la lista de técnicos']);
}
