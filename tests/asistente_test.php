<?php
// Asistente guiado por rol (D-20): el progreso se guarda en la cuenta del usuario.
require_once __DIR__ . '/../backend/lib/asistente_helpers.php';

[$empA, $adminA] = empresaTemporal('Asistente');
[$empOtra, $adminOtra] = empresaTemporal('Asistente Otra');
$tecA = usuarioTemporal($empA, 'Técnico');
como($empA, $adminA, 'Administrador');

grupo('Asistente guiado (D-20)');

prueba('D-20: un usuario nuevo tiene el asistente pendiente (se abre solo)', function () use ($empA, $tecA) {
    $r = llamar($empA, $tecA, 'Técnico', 'GET', 'backend/asistente.php');
    igual('nuevo', $r['json']['estado'] ?? null, 'estado (' . mb_substr($r['texto'], 0, 100) . '):');
    igual('Técnico', $r['json']['rol'] ?? null, 'rol para elegir los pasos:');
});

prueba('D-20: al cerrarlo queda guardado en su cuenta y ya no se abre solo', function () use ($empA, $tecA, $adminA) {
    $r = llamar($empA, $tecA, 'Técnico', 'POST', 'backend/asistente.php', ['json' => ['estado' => 'cerrado']]);
    igual(200, $r['http'], 'guardar:');
    igual('cerrado', llamar($empA, $tecA, 'Técnico', 'GET', 'backend/asistente.php')['json']['estado'] ?? null, 'estado después:');
    igual('nuevo', llamar($empA, $adminA, 'Administrador', 'GET', 'backend/asistente.php')['json']['estado'] ?? null, 'otro usuario no se ve afectado:');
    como($empA, $adminA, 'Administrador');
});

prueba('D-20: solo acepta estados conocidos', function () use ($empA, $adminA) {
    $r = llamar($empA, $adminA, 'Administrador', 'POST', 'backend/asistente.php', ['json' => ['estado' => 'hackeado']]);
    igual(400, $r['http'], 'estado desconocido:');
    como($empA, $adminA, 'Administrador');
});

prueba('D-20 · MT-2: guardar el progreso no toca usuarios de otra empresa', function () use ($empA, $adminOtra) {
    global $pdo;
    asistenteGuardar($pdo, $adminOtra, $empA, 'terminado');  // usuario de otra empresa con la empresa A
    igual(null, $pdo->query("SELECT asistente FROM usuarios WHERE id = $adminOtra")->fetchColumn() ?: null, 'asistente del usuario de la otra empresa:');
});
