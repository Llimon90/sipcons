<?php
// Diagnóstico de solo lectura: para cada usuario con rol de técnico,
// muestra cuántas incidencias le encontraría el sistema por nombre y si
// tiene alias_tecnico configurado. Sirve para detectar, antes de que un
// técnico se tope con un dashboard vacío, si su nombre de usuario no
// coincide con lo que aparece en incidencias.tecnico.
//
// Uso: abrir esta URL logueado como Administrador/Programador. No hace
// ningún cambio en la base de datos.
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('usuarios');

header('Content-Type: application/json');

require_once __DIR__ . '/lib/estadisticas_helpers.php';

$ROLES_TECNICO = ['Técnico', 'Supervisor', 'Administrador', 'Técnico/Administrador', 'Programador'];

$placeholders = implode(',', array_fill(0, count($ROLES_TECNICO), '?'));
$stmt = $pdo->prepare("SELECT id, nombre, alias_tecnico, usuario, rol FROM usuarios WHERE rol IN ($placeholders) ORDER BY nombre");
$stmt->execute($ROLES_TECNICO);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sqlTecnico = $conn->query("SELECT tecnico FROM incidencias WHERE tecnico IS NOT NULL AND tecnico <> ''");
$valoresTecnico = [];
$nombresIndividuales = [];
while ($fila = $sqlTecnico->fetch_assoc()) {
    $valoresTecnico[] = $fila['tecnico'];
    foreach (separarTecnicos($fila['tecnico']) as $t) {
        $nombresIndividuales[$t] = ($nombresIndividuales[$t] ?? 0) + 1;
    }
}
arsort($nombresIndividuales);

$resultado = [];
foreach ($usuarios as $u) {
    $identidad = !empty($u['alias_tecnico']) ? $u['alias_tecnico'] : $u['nombre'];
    $coincidencias = 0;
    foreach ($valoresTecnico as $valor) {
        if (tecnicoCoincide($identidad, $valor)) {
            $coincidencias++;
        }
    }

    $resultado[] = [
        'usuario' => $u['usuario'],
        'nombre' => $u['nombre'],
        'rol' => $u['rol'],
        'alias_tecnico' => $u['alias_tecnico'],
        'identidad_usada' => $identidad,
        'incidencias_encontradas' => $coincidencias,
        'advertencia' => $coincidencias === 0 ? 'Sin ninguna incidencia encontrada con este nombre: revisar si necesita alias_tecnico.' : null,
    ];
}

echo json_encode([
    'success' => true,
    'data' => $resultado,
    'valores_en_incidencias' => $nombresIndividuales,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

$conn->close();
