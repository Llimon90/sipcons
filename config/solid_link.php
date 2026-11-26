<?php
// Servidesk como sistema satélite de Solid Suite (D-50). Contrato: docs/solid-link.md.
//
// Con SOLID_LINK_TOKEN en el .env, Solid Admin (panel central de la suite) administra las empresas de esta
// instancia: alta, estado, vencimiento, plan y límite de usuarios. La cobranza vive allá; aquí solo se aplica.
// En modo satélite:
//   - La gracia y la suspensión por atraso las decide el panel (ya vienen dentro de `vence` y `estado`):
//     al pasar `vence` la cuenta queda en solo lectura hasta que el panel la reactive o la suspenda.
//   - La contratación en línea y las escrituras de cobranza del panel /plataforma se desactivan (una sola fuente).
// Requiere $pdo (config/database.php) y config/plataforma.php.

require_once __DIR__ . '/usuarios.php';

/** Estados de Servidesk ↔ Solid Link (iguales salvo demo, que no se expone). */
const SOLID_ESTADOS = ['prueba', 'activa', 'suspendida', 'cancelada'];

function solidTokenValido(string $cabecera): bool {
    $esperado = (string)env('SOLID_LINK_TOKEN', '');
    if (strlen($esperado) < 24 || stripos($cabecera, 'Bearer ') !== 0) return false;
    return hash_equals(hash('sha256', $esperado), hash('sha256', substr($cabecera, 7)));
}

function solidEmpresaPorSlug(PDO $pdo, string $slug): ?array {
    $st = $pdo->prepare("SELECT * FROM empresas WHERE slug = ? AND estado <> 'demo'");
    $st->execute([$slug]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Cuenta en formato Solid Link. */
function solidCuenta(PDO $pdo, array $e): array {
    $id = (int)$e['id'];
    $usuarios = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE empresa_id = ?");
    $usuarios->execute([$id]);
    $ultima = $pdo->prepare("SELECT MAX(creado_en) FROM analytics_eventos WHERE empresa_id = ?");
    $ultima->execute([$id]);
    $admin = $pdo->prepare("SELECT nombre, correo, telefono FROM usuarios WHERE empresa_id = ? AND rol = 'Administrador' ORDER BY id LIMIT 1");
    $admin->execute([$id]);
    $a = $admin->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'id' => $e['slug'], 'nombre' => $e['nombre'], 'estado' => $e['estado'],
        'vence' => $e['estado'] === 'prueba' ? $e['prueba_hasta'] : $e['pagado_hasta'],
        'plan' => $e['plan_externo'] ?? null,
        'limites' => ['usuarios' => isset($e['limite_usuarios']) && $e['limite_usuarios'] !== null ? (int)$e['limite_usuarios'] : limiteUsuariosEmpresa($pdo, $id)],
        'contacto' => ['nombre' => $e['contacto_nombre'] ?: ($a['nombre'] ?? null), 'correo' => $e['contacto_correo'] ?: ($a['correo'] ?? null), 'telefono' => $e['contacto_telefono'] ?: ($a['telefono'] ?? null)],
        'uso' => ['usuarios' => (int)$usuarios->fetchColumn(), 'ordenes' => contarTablaEmpresa($pdo, $id, 'incidencias'), 'ultima_actividad' => $ultima->fetchColumn() ?: null],
        'url' => rtrim((string)env('APP_URL', ''), '/') ?: null,
        'creada' => $e['creado_en'],
    ];
}

/** Q-3: el alta se repitió (la cuenta ya existe). La API responde 409. */
class SolidConflicto extends RuntimeException {}

/** L-12 · Q-4: valida estado, vence y límites ANTES de escribir nada. */
function solidValidar(array $b): void {
    if (array_key_exists('estado', $b) && !in_array($b['estado'], SOLID_ESTADOS, true)) {
        throw new InvalidArgumentException('estado inválido (prueba | activa | suspendida | cancelada)');
    }
    if (array_key_exists('vence', $b) && $b['vence'] !== null) {
        $ok = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$b['vence'], $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
        if (!$ok) throw new InvalidArgumentException('vence debe ser una fecha AAAA-MM-DD que exista, o null');
    }
    if (array_key_exists('limites', $b) && $b['limites'] !== null && !is_array($b['limites'])) {
        throw new InvalidArgumentException('limites debe ser un objeto');
    }
    $usuarios = $b['limites']['usuarios'] ?? null;
    if ($usuarios !== null && $usuarios !== '' && filter_var($usuarios, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
        throw new InvalidArgumentException('limites.usuarios debe ser un entero mayor a 0, o null');
    }
    // H-06: longitudes de las columnas (empresas y su administrador), antes de escribir nada
    if (isset($b['nombre']) && mb_strlen(trim((string)$b['nombre'])) > 150) throw new InvalidArgumentException('El nombre admite hasta 150 caracteres');
    if (isset($b['plan']) && mb_strlen((string)$b['plan']) > 80) throw new InvalidArgumentException('plan admite hasta 80 caracteres');
    if (array_key_exists('contacto', $b) && $b['contacto'] !== null && !is_array($b['contacto'])) throw new InvalidArgumentException('contacto debe ser un objeto');
    foreach (['nombre' => $b['nombre'] ?? null, 'plan' => $b['plan'] ?? null, 'estado' => $b['estado'] ?? null, 'vence' => $b['vence'] ?? null] as $k => $v) {
        if ($v !== null && !is_scalar($v)) throw new InvalidArgumentException("$k debe ser texto");
    }
    foreach (['nombre', 'correo', 'telefono'] as $k) {
        if (isset($b['contacto'][$k]) && !is_scalar($b['contacto'][$k])) throw new InvalidArgumentException("contacto.$k debe ser texto");
    }
    // H-06 · AS-7: el nombre del administrador es su identidad de técnico (el "/" separa técnicos)
    if (isset($b['contacto']['nombre']) && str_contains((string)$b['contacto']['nombre'], '/')) throw new InvalidArgumentException('contacto.nombre no puede llevar "/"');
    foreach (['nombre' => [100, 'contacto.nombre'], 'correo' => [100, 'contacto.correo'], 'telefono' => [20, 'El teléfono del contacto']] as $k => [$max, $etiqueta]) {
        if (isset($b['contacto'][$k]) && mb_strlen(trim((string)$b['contacto'][$k])) > $max) throw new InvalidArgumentException("$etiqueta admite hasta $max caracteres");
    }
}

/** Aplica lo que decide el panel. */
function solidAplicar(PDO $pdo, array $e, array $b): void {
    solidValidar($b);
    $sets = [];
    $v = [];
    $estado = $e['estado'];
    if (array_key_exists('estado', $b)) {
        $estado = $b['estado'];
        $sets[] = 'estado = ?'; $v[] = $estado;
    }
    // H-07: si cambia el estado sin mandar vence, la fecha vigente pasa a la columna del estado nuevo
    // (de la prueba al periodo pagado o al revés), para que no quede una fecha vieja aplicándose
    $hayVence = array_key_exists('vence', $b);
    $vence = $hayVence ? $b['vence'] : null;
    // (H-04 y H-01 de la fase 4: solo si el estado CAMBIA; repetir el estado actual en un PUT parcial no
    // toca las fechas, así una cuenta vencida sigue en solo lectura hasta que el panel mande vence. Al
    // reactivar desde suspendida o cancelada se toma la fecha más reciente que tuviera.)
    if (!$hayVence && array_key_exists('estado', $b) && $estado !== $e['estado'] && in_array($estado, ['prueba', 'activa'], true)) {
        $hayVence = true;
        if (in_array($e['estado'], ['prueba', 'activa'], true)) {
            $vence = $e['estado'] === 'prueba' ? ($e['prueba_hasta'] ?? null) : ($e['pagado_hasta'] ?? null);
        } else {
            // (H-05 fase 4 c) la fecha del estado al que vuelve; si no la tiene, la del otro
            $vence = $estado === 'prueba' ? ($e['prueba_hasta'] ?? $e['pagado_hasta'] ?? null) : ($e['pagado_hasta'] ?? $e['prueba_hasta'] ?? null);
        }
        // L2-1: una fecha ya pasada no se arrastra (dejaría suspendido a quien acaba de pagar): sin vence
        if ($vence !== null && $vence < date('Y-m-d')) $vence = null;
    }
    if ($hayVence) {
        // En prueba la fecha es el fin de la prueba; si no, el último día pagado (con la gracia ya incluida)
        if ($estado === 'prueba') { $sets[] = 'prueba_hasta = ?'; $v[] = $vence; }
        else { $sets[] = 'pagado_hasta = ?'; $v[] = $vence; }
    }
    if (array_key_exists('plan', $b)) { $sets[] = 'plan_externo = ?'; $v[] = $b['plan'] !== null ? mb_substr((string)$b['plan'], 0, 80) : null; }
    if (isset($b['limites']) && is_array($b['limites']) && array_key_exists('usuarios', $b['limites'])) {
        $sets[] = 'limite_usuarios = ?';
        $v[] = $b['limites']['usuarios'] === null || $b['limites']['usuarios'] === '' ? null : max(1, (int)$b['limites']['usuarios']);
    }
    if (isset($b['nombre']) && trim((string)$b['nombre']) !== '') { $sets[] = 'nombre = ?'; $v[] = mb_substr(trim((string)$b['nombre']), 0, 150); }
    if (isset($b['contacto']) && is_array($b['contacto'])) {
        foreach (['nombre' => 'contacto_nombre', 'correo' => 'contacto_correo', 'telefono' => 'contacto_telefono'] as $k => $col) {
            if (array_key_exists($k, $b['contacto'])) { $sets[] = "$col = ?"; $v[] = $b['contacto'][$k] !== null ? mb_substr(trim((string)$b['contacto'][$k]), 0, 150) ?: null : null; }
        }
    }
    if (!$sets) return;
    $v[] = (int)$e['id'];
    $pdo->prepare("UPDATE empresas SET " . implode(', ', $sets) . ", actualizado_en = NOW() WHERE id = ?")->execute($v);
    registrarEventoPlataforma($pdo, (int)$e['id'], 'solid_link', array_intersect_key($b, array_flip(['estado', 'vence', 'plan', 'limites'])));
}

/** L-13: la contraseña que entrega el panel es temporal; el usuario la cambia al entrar (migración 021). */
function solidExigirCambioPassword(PDO $pdo, int $empresaId, string $usuario): void {
    if (!usuariosTienenCambioPassword($pdo)) return;
    $pdo->prepare('UPDATE usuarios SET debe_cambiar_password = 1 WHERE empresa_id = ? AND usuario = ?')->execute([$empresaId, $usuario]);
}

function solidPasswordTemporal(): string {
    $c = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $p = '';
    for ($i = 0; $i < 12; $i++) $p .= $c[random_int(0, strlen($c) - 1)];
    return $p . '-' . random_int(10, 99);
}

/** Alta desde el panel: empresa, tablas y administrador (su usuario es el correo del contacto). */
function solidCrearCuenta(PDO $pdo, array $b): array {
    solidValidar($b);  // H-06: tipos y largos antes de leer nada (un contacto que no es objeto daba 500)
    $nombre = trim((string)($b['nombre'] ?? ''));
    $correo = strtolower(trim((string)($b['contacto']['correo'] ?? '')));
    if ($nombre === '') throw new InvalidArgumentException('Falta el nombre');
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Servidesk necesita el correo del contacto: será el usuario del administrador');
    solidValidar($b);  // L-12: nada se crea si el resto viene mal
    if (isset($b['estado']) && !in_array($b['estado'], ['prueba', 'activa'], true)) throw new InvalidArgumentException('estado inválido para un alta (prueba | activa)');
    // Q-3: un reintento (p. ej. tras un timeout) no crea otra cuenta ni pierde la contraseña: el panel
    // debe usar restablecer-acceso para entregar una nueva
    // (H-05: también si el correo es de una cuenta de plataforma o es el correo de otro usuario;
    // la comprobación definitiva se repite dentro del candado de crearEmpresa)
    $st = $pdo->prepare("SELECT COALESCE(e.slug, 'de plataforma') FROM usuarios u LEFT JOIN empresas e ON e.id = u.empresa_id
                         WHERE LOWER(u.usuario) = ? OR LOWER(u.correo) = ? LIMIT 1");
    $st->execute([$correo, $correo]);
    $conflicto = fn($slug) => new SolidConflicto("Ya existe la cuenta $slug con ese correo. Si el alta anterior no llegó al panel, concíliala y usa restablecer-acceso para entregar una contraseña nueva.");
    if ($slug = $st->fetchColumn()) throw $conflicto($slug);
    $estado = $b['estado'] ?? 'prueba';
    $password = solidPasswordTemporal();
    try {
        $id = crearEmpresa($pdo, [
            'nombre' => $nombre, 'estado' => $estado, 'admin_nombre' => trim((string)($b['contacto']['nombre'] ?? '')) ?: 'Administrador',
            'admin_usuario' => $correo, 'admin_password' => $password, 'admin_telefono' => (string)($b['contacto']['telefono'] ?? ''),
            'contacto_correo' => $correo, 'contacto_telefono' => $b['contacto']['telefono'] ?? null,
        ]);
    } catch (CuentaExistente $ex) {
        throw $conflicto('existente');  // H-05: otra alta con el mismo correo ganó el candado
    }
    $e = empresaPorId($pdo, $id);
    $aplicar = array_intersect_key($b, array_flip(['estado', 'vence', 'plan', 'limites']));
    // H-05: sin vence, la prueba no hereda los días de prueba locales (el panel decide)
    if (!array_key_exists('vence', $aplicar)) $aplicar['vence'] = null;
    solidAplicar($pdo, $e, $aplicar);
    solidExigirCambioPassword($pdo, $id, $correo);  // L-13
    registrarEventoPlataforma($pdo, $id, 'alta_manual', ['nombre' => $nombre, 'origen' => 'Solid Admin']);
    return solidCuenta($pdo, empresaPorId($pdo, $id)) + ['acceso' => [
        'url' => rtrim((string)env('APP_URL', ''), '/') ?: null, 'usuario' => $correo, 'password' => $password,
        'nota' => 'Contraseña temporal: el sistema pide cambiarla (en Ajustes) al entrar.',
    ]];
}

/** Nueva contraseña temporal para el administrador principal; sus sesiones abiertas dejan de valer. */
function solidRestablecerAcceso(PDO $pdo, array $e): array {
    $st = $pdo->prepare("SELECT id, usuario FROM usuarios WHERE empresa_id = ? AND rol = 'Administrador' ORDER BY id LIMIT 1");
    $st->execute([(int)$e['id']]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) throw new InvalidArgumentException('La empresa no tiene administrador');
    $password = solidPasswordTemporal();
    $pdo->prepare("UPDATE usuarios SET password = ? WHERE id = ?")->execute([password_hash($password, PASSWORD_DEFAULT), (int)$u['id']]);
    solidExigirCambioPassword($pdo, (int)$e['id'], (string)$u['usuario']);  // L-13
    registrarEventoPlataforma($pdo, (int)$e['id'], 'solid_link', ['restablecer_acceso' => $u['usuario']]);
    return ['url' => rtrim((string)env('APP_URL', ''), '/') ?: null, 'usuario' => $u['usuario'], 'password' => $password];
}
