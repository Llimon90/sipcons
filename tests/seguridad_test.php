<?php
// Pruebas de la auditoría de lanzamiento: sesiones, cuentas de Administrador,
// integridad al eliminar ventas, cobranza y meta de cumplimiento.
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/usuarios.php';
require_once __DIR__ . '/../config/login_log.php';
require_once __DIR__ . '/../backend/lib/cotizaciones_helpers.php';

[$empS, $adminS] = empresaTemporal('Seguridad');
[$empOtra, $adminOtra] = empresaTemporal('Otra');

/** Simula una sesión abierta en login (con la huella de la contraseña). */
function sesionDe(int $empresaId, int $usuarioId, string $rol): void {
    global $pdo;
    como($empresaId, $usuarioId, $rol);
    $hash = $pdo->query('SELECT password FROM usuarios WHERE id = ' . $usuarioId)->fetchColumn();
    actualizarHuellaSesion((string)$hash);
}

/** revalidarUsuarioSesion() memoriza el resultado por petición: aquí cada llamada es una petición nueva. */
function revalidar(): bool {
    global $pdo;
    $id = (int)($_SESSION['user_id'] ?? 0);
    $st = $pdo->prepare('SELECT empresa_id, rol, nombre, password FROM usuarios WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return false;
    if (isset($_SESSION['huella']) && !hash_equals($_SESSION['huella'], huellaPassword($u['password']))) return false;
    if ((int)$u['empresa_id'] !== (int)$_SESSION['empresa_id']) return false;
    $_SESSION['rol'] = normalizarRol($u['rol']);
    return true;
}

grupo('Sesiones');

prueba('La sesión se cierra si el usuario se elimina', function () use ($empS) {
    global $pdo;
    $u = usuarioTemporal($empS, 'Técnico');
    sesionDe($empS, $u, 'Técnico');
    verdadero(revalidar(), 'la sesión recién abierta es válida');
    $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$u]);
    verdadero(!revalidar(), 'un usuario eliminado ya no tiene sesión');
});

prueba('La sesión se cierra si le cambian la contraseña', function () use ($empS) {
    global $pdo;
    $u = usuarioTemporal($empS, 'Supervisor');
    sesionDe($empS, $u, 'Supervisor');
    $pdo->prepare('UPDATE usuarios SET password = ? WHERE id = ?')->execute([password_hash('otra-clave', PASSWORD_DEFAULT), $u]);
    verdadero(!revalidar(), 'otra contraseña invalida la sesión');
});

prueba('Un cambio de rol aplica sin cerrar sesión', function () use ($empS) {
    global $pdo;
    $u = usuarioTemporal($empS, 'Administrador');
    sesionDe($empS, $u, 'Administrador');
    $pdo->prepare("UPDATE usuarios SET rol = 'Técnico' WHERE id = ?")->execute([$u]);
    verdadero(revalidar(), 'sigue con sesión');
    igual('Técnico', $_SESSION['rol']);
});

prueba('La función real revalida y sincroniza el rol', function () use ($empS) {
    global $pdo;
    $u = usuarioTemporal($empS, 'Supervisor');
    sesionDe($empS, $u, 'Administrador'); // rol viejo en sesión
    verdadero(revalidarUsuarioSesion(), 'válida');
    igual('Supervisor', $_SESSION['rol'], 'el rol sale de la base');
});

grupo('Cuentas de Administrador');

prueba('Quien no es Administrador no crea, edita ni elimina Administradores', function () use ($empS) {
    $u = usuarioTemporal($empS, 'Supervisor');
    como($empS, $u, 'Supervisor');
    verdadero(errorGestionCuenta(null, 'Administrador') !== null, 'crear Administrador');
    verdadero(errorGestionCuenta('Administrador', 'Administrador') !== null, 'editar Administrador');
    verdadero(errorGestionCuenta('Técnico', 'Administrador') !== null, 'ascender a Administrador');
    igual(null, errorGestionCuenta('Técnico', 'Supervisor'), 'otros roles sí');
});

prueba('El Administrador sí gestiona Administradores', function () use ($empS, $adminS) {
    como($empS, $adminS, 'Administrador');
    igual(null, errorGestionCuenta(null, 'Administrador'));
    igual(null, errorGestionCuenta('Administrador', 'Técnico'));
});

prueba('Se cuentan los Administradores de la empresa (sin mezclar empresas)', function () use ($empS, $empOtra) {
    global $pdo;
    $antes = contarAdministradores($pdo, $empS);
    usuarioTemporal($empS, 'Administrador');
    usuarioTemporal($empOtra, 'Administrador');
    igual($antes + 1, contarAdministradores($pdo, $empS));
});

grupo('Inicio de sesión');

prueba('5 intentos fallidos bloquean ese correo 15 minutos', function () {
    global $pdo;
    $correo = 'bloqueo-' . bin2hex(random_bytes(3)) . '@pruebas.test';
    for ($i = 0; $i < LOGIN_MAX_FALLOS_USUARIO - 1; $i++) loginRegistrarFallo($pdo, $correo);
    igual(0, loginMinutosBloqueo($pdo, $correo), 'antes del límite');
    loginRegistrarFallo($pdo, $correo);
    verdadero(loginMinutosBloqueo($pdo, $correo) >= 14, 'bloqueado');
    loginLimpiarFallos($pdo, $correo);
    igual(0, loginMinutosBloqueo($pdo, $correo), 'un acceso correcto limpia los fallos');
});

grupo('Integridad');

prueba('Eliminar una venta de cotización la devuelve a "por registrar"', function () use ($empS, $adminS) {
    global $pdo;
    como($empS, $adminS, 'Administrador');
    $pdo->exec("INSERT INTO clientes (nombre, contactos) VALUES ('Cliente Venta', 'Ana')");
    $cliente = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, unidad, existencia, costo_unitario, precio_venta) VALUES ('EQ-1', 'Equipo 1', 'Equipo', 'pza', 3, 100, 200)")->execute();
    $art = (int)$pdo->lastInsertId();
    $c = cotGuardar($pdo, 0, ['cliente_id' => $cliente, 'fecha' => date('Y-m-d'), 'vigencia_hasta' => date('Y-m-d', strtotime('+10 days')), 'moneda' => 'MXN'],
        [['articulo_id' => $art, 'cantidad' => 1]]);
    cotCambiarEstatus($pdo, (int)$c['id'], 'enviada');
    [$ap] = cotCambiarEstatus($pdo, (int)$c['id'], 'aprobada', ['aprobada_por' => 'Ana']);
    $cv = $ap['ventas'][0];
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO ventas (folio, cliente, fecha_registro) VALUES ('VT-7001', 'Cliente Venta', CURDATE())");
    $venta = (int)$pdo->lastInsertId();
    cotMarcarVentaRegistrada($pdo, (int)$cv['id'], $venta, 'VT-7001');
    $pdo->commit();
    igual('convertida', cotFila($pdo, (int)$c['id'])['estatus']);

    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM ventas WHERE id = ?')->execute([$venta]);
    igual(1, cotLiberarVentaEliminada($pdo, $venta, 'VT-7001'));
    $pdo->commit();
    $cot = cotFila($pdo, (int)$c['id']);
    igual('aprobada', $cot['estatus']);
    igual(null, $cot['venta_id']);
    $fila = $pdo->query('SELECT estado, venta_id FROM cotizacion_ventas WHERE id = ' . (int)$cv['id'])->fetch(PDO::FETCH_ASSOC);
    igual('por_registrar', $fila['estado']);
    igual(null, $fila['venta_id']);
});

prueba('Los errores de base de datos no llegan al usuario', function () {
    $msg = mensajeErrorPublico(new PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'x'"));
    verdadero(strpos($msg, 'SQLSTATE') === false && strpos($msg, 'column') === false, 'mensaje genérico');
    igual('Falta el cliente.', mensajeErrorPublico(new InvalidArgumentException('Falta el cliente.')), 'las validaciones se conservan');
});

grupo('Cobranza');

prueba('Abono y nota de crédito no mueven la vigencia; el pago sí', function () use ($empS) {
    global $pdo;
    establecerEmpresa(null);
    if (!pagosTieneTipo($pdo)) throw new FallaPrueba('falta la migración 008 en la base local');
    $antes = $pdo->query('SELECT pagado_hasta FROM empresas WHERE id = ' . $empS)->fetchColumn();
    $a = registrarPago($pdo, $empS, ['tipo' => 'abono', 'monto' => '500']);
    igual(500.0, (float)$a['monto']);
    $n = registrarPago($pdo, $empS, ['tipo' => 'nota_credito', 'monto' => '200']);
    igual(-200.0, (float)$n['monto'], 'la nota se guarda en negativo');
    igual($antes, $pdo->query('SELECT pagado_hasta FROM empresas WHERE id = ' . $empS)->fetchColumn(), 'vigencia intacta');
    lanza(fn() => registrarPago($pdo, $empS, ['tipo' => 'abono', 'monto' => '']), InvalidArgumentException::class, 'monto');
    $p = registrarPago($pdo, $empS, ['tipo' => 'pago', 'monto' => '690']);
    verdadero($p['cubre_hasta'] > $antes, 'el pago extiende la vigencia');
    $total = (float)$pdo->query('SELECT SUM(monto) FROM pagos WHERE anulado = 0 AND empresa_id = ' . $empS)->fetchColumn();
    igual(990.0, $total, 'cobrado = abono + pago − nota');
});

prueba('La prueba gratuita no tiene límite de usuarios', function () use ($empS) {
    global $pdo;
    establecerEmpresa(null);
    $pdo->prepare("UPDATE empresas SET estado = 'prueba', prueba_hasta = CURDATE() + INTERVAL 5 DAY, plan_id = 1 WHERE id = ?")->execute([$empS]);
    igual(null, limiteUsuariosEmpresa($pdo, $empS));
    $pdo->prepare("UPDATE empresas SET estado = 'activa' WHERE id = ?")->execute([$empS]);
    igual(5, limiteUsuariosEmpresa($pdo, $empS), 'al contratar Básico aplica su límite');
});

grupo('Meta de cumplimiento');

prueba('La meta de días sale de la empresa (7 por defecto, 1 a 90)', function () {
    igual(7, slaDiasEmpresa(['marca' => []]));
    igual(3, slaDiasEmpresa(['marca' => ['sla_dias' => 3]]));
    igual(7, slaDiasEmpresa(['marca' => ['sla_dias' => 500]]), 'fuera de rango usa el valor por defecto');
});
