<?php
// Operaciones de plataforma (el negocio Servidesk): registro autoservicio,
// planes y precios, cobranza, ciclo de vida de las empresas, notas de
// seguimiento, modo soporte, cuentas de administración y exportación.
// Requiere $pdo (config/database.php).
require_once __DIR__ . '/provision.php';
require_once __DIR__ . '/csv.php';

// Estados efectivos (situacionEmpresa) que generan ingreso recurrente
const ESTADOS_CON_INGRESO = ['activa', 'gracia', 'solo_lectura'];

function registroAbierto(): bool {
    $valor = configPlataforma('registro_abierto');
    return filter_var($valor !== '' ? $valor : env('REGISTRO_ABIERTO', 'true'), FILTER_VALIDATE_BOOLEAN);
}

function registroMaxPorIp(): int {
    $valor = configPlataforma('registro_max_por_ip');
    return max(1, (int)($valor !== '' ? $valor : env('REGISTRO_MAX_POR_IP', 3)));
}

function ipCliente(): ?string {
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

/** Bitácora de plataforma (altas, pagos, extensiones, suspensiones...). Nunca interrumpe. */
function registrarEventoPlataforma(PDO $pdo, ?int $empresaId, string $accion, array $detalle = []): void {
    try {
        $pdo->prepare(
            "INSERT INTO plataforma_eventos (empresa_id, accion, detalle, usuario_nombre, ip_address) VALUES (?, ?, ?, ?, ?)"
        )->execute([
            $empresaId, $accion, $detalle ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : null,
            $_SESSION['soporte']['nombre'] ?? ($_SESSION['nombre'] ?? ($_SESSION['usuario'] ?? 'sistema')), ipCliente(),
        ]);
    } catch (Throwable $e) {
        error_log("Evento de plataforma no registrado ($accion): " . $e->getMessage());
    }
}

function empresaPorId(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM empresas WHERE id = ?");
    $stmt->execute([$id]);
    $e = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($e) $e['marca'] = json_decode($e['marca'] ?? '', true) ?: [];
    return $e;
}

/** Cuántos registros tiene una tabla de una empresa (0 si no existe). */
function contarTablaEmpresa(PDO $pdo, int $empresaId, string $tabla): int {
    if (!in_array($tabla, TABLAS_EMPRESA, true)) return 0;
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM `" . prefijoEmpresa($empresaId) . $tabla . "`")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Espacio en disco de los archivos de una empresa (bytes). */
function almacenamientoEmpresa(int $empresaId): int {
    $total = 0;
    foreach (['uploads', 'manuales'] as $base) {
        $dir = realpath(__DIR__ . '/../' . $base . '/e' . $empresaId);
        if (!$dir) continue;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) $total += $f->getSize();
        }
    }
    return $total;
}

// ------------------------------------------------------------------ planes

function listarPlanes(PDO $pdo, bool $soloActivos = false): array {
    $sql = "SELECT p.*, (SELECT COUNT(*) FROM empresas e WHERE e.plan_id = p.id AND e.estado <> 'demo') AS empresas
            FROM planes p" . ($soloActivos ? " WHERE p.activo = 1" : '') . " ORDER BY p.orden, p.precio_mensual";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function planPorId(PDO $pdo, ?int $id): ?array {
    static $cache = [];
    if (!$id) return null;
    if (!array_key_exists($id, $cache)) {
        $stmt = $pdo->prepare("SELECT * FROM planes WHERE id = ?");
        $stmt->execute([$id]);
        $cache[$id] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    return $cache[$id];
}

/**
 * Precio vigente de una empresa: el pactado (empresas.precio) o el del plan
 * según su ciclo. mensual = equivalente mensual (para MRR).
 */
function precioEmpresa(PDO $pdo, array $e): array {
    $plan = planPorId($pdo, $e['plan_id'] ? (int)$e['plan_id'] : null);
    $anual = ($e['ciclo'] ?? 'mensual') === 'anual';
    $deLista = $plan ? (float)($anual ? $plan['precio_anual'] : $plan['precio_mensual']) : 0.0;
    $ciclo = $e['precio'] !== null && $e['precio'] !== '' ? (float)$e['precio'] : $deLista;
    return [
        'plan'      => $plan,
        'ciclo'     => $anual ? 'anual' : 'mensual',
        'por_ciclo' => round($ciclo, 2),
        'mensual'   => round($anual ? $ciclo / 12 : $ciclo, 2),
        'pactado'   => $e['precio'] !== null && $e['precio'] !== '',
        'de_lista'  => round($deLista, 2),
    ];
}

/**
 * Límite de usuarios del plan de la empresa (null = sin límite). La prueba
 * gratuita no tiene límite: el sistema completo, solo con los permisos por rol.
 * Al contratar, los usuarios ya creados se conservan; el límite solo frena altas nuevas.
 */
function limiteUsuariosEmpresa(PDO $pdo, int $empresaId): ?int {
    $e = empresaPorId($pdo, $empresaId);
    // D-50: en modo satélite el límite lo decide Solid Admin (también durante la prueba)
    // (H-04: null = sin límite; no cae al plan local)
    if ($e && solidSatelite()) return isset($e['limite_usuarios']) && $e['limite_usuarios'] !== null ? (int)$e['limite_usuarios'] : null;
    if (!$e || in_array($e['estado'], ['demo', 'prueba'], true)) return null;
    $plan = planPorId($pdo, $e['plan_id'] ? (int)$e['plan_id'] : null);
    return $plan && $plan['max_usuarios'] !== null ? (int)$plan['max_usuarios'] : null;
}

function guardarPlan(PDO $pdo, array $d): int {
    $id = (int)($d['id'] ?? 0);
    $nombre = trim((string)($d['nombre'] ?? ''));
    $clave = strtolower(trim(preg_replace('/[^a-z0-9_-]+/i', '-', (string)($d['clave'] ?? $nombre)), '-'));
    if ($nombre === '' || $clave === '') throw new InvalidArgumentException('El plan necesita nombre.');
    $mensual = round((float)($d['precio_mensual'] ?? 0), 2);
    $anualPrecio = round((float)($d['precio_anual'] ?? 0), 2);
    if ($mensual < 0 || $anualPrecio < 0) throw new InvalidArgumentException('Los precios no pueden ser negativos.');
    $max = ($d['max_usuarios'] ?? '') === '' ? null : max(1, (int)$d['max_usuarios']);
    $valores = [$clave, $nombre, trim((string)($d['descripcion'] ?? '')) ?: null, $mensual, $anualPrecio, $max,
                !empty($d['activo']) ? 1 : 0, (int)($d['orden'] ?? 0)];
    if ($id) {
        $pdo->prepare("UPDATE planes SET clave=?, nombre=?, descripcion=?, precio_mensual=?, precio_anual=?, max_usuarios=?, activo=?, orden=? WHERE id=?")
            ->execute([...$valores, $id]);
    } else {
        $pdo->prepare("INSERT INTO planes (clave, nombre, descripcion, precio_mensual, precio_anual, max_usuarios, activo, orden) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute($valores);
        $id = (int)$pdo->lastInsertId();
    }
    // Personalización de marca incluida en el plan (migración 005)
    if (array_key_exists('permite_marca', $d)) {
        try {
            $pdo->prepare("UPDATE planes SET permite_marca = ? WHERE id = ?")->execute([!empty($d['permite_marca']) ? 1 : 0, $id]);
        } catch (Throwable $e) {
            // Sin la migración 005 todos los planes permiten personalizar
        }
    }
    registrarEventoPlataforma($pdo, null, 'plan_guardado', ['plan_id' => $id, 'nombre' => $nombre,
        'precio_mensual' => $mensual, 'precio_anual' => $anualPrecio, 'max_usuarios' => $max]);
    return $id;
}

/** Cambia plan, ciclo y precio pactado de una empresa. */
function cambiarPlanEmpresa(PDO $pdo, int $empresaId, int $planId, string $ciclo, $precio): void {
    $e = empresaPorId($pdo, $empresaId);
    if (!$e) throw new InvalidArgumentException('Empresa no encontrada.');
    if (!planPorId($pdo, $planId)) throw new InvalidArgumentException('Plan no válido.');
    $ciclo = $ciclo === 'anual' ? 'anual' : 'mensual';
    $precio = ($precio === null || $precio === '') ? null : round(max(0, (float)$precio), 2);
    $pdo->prepare("UPDATE empresas SET plan_id = ?, ciclo = ?, precio = ?, actualizado_en = NOW() WHERE id = ?")
        ->execute([$planId, $ciclo, $precio, $empresaId]);
    registrarEventoPlataforma($pdo, $empresaId, 'plan', [
        'de_plan' => $e['plan_id'], 'a_plan' => $planId, 'ciclo' => $ciclo, 'precio_pactado' => $precio,
    ]);
}

// ------------------------------------------------------------------ cobranza

// Tipos de movimiento de cobranza (pagos.tipo, migración 008):
//   pago          cubre un periodo: extiende la vigencia y activa la cuenta
//   abono         pago parcial: suma al cobrado pero no extiende la vigencia
//                 (el pago que completa el periodo se registra como "pago")
//   nota_credito  descuento o devolución (CFDI de egreso): se guarda en
//                 negativo para que los totales cobrados ya la resten
const TIPOS_PAGO = ['pago', 'abono', 'nota_credito'];

function pagosTieneTipo(PDO $pdo): bool {
    static $existe = null;
    if ($existe === null) {
        try {
            $existe = (bool)$pdo->query("SHOW COLUMNS FROM pagos LIKE 'tipo'")->fetch();
        } catch (Throwable $e) {
            $existe = false;
        }
    }
    return $existe;
}

/**
 * Registra un movimiento de cobranza. Un "pago" deja la empresa activa y al
 * corriente hasta $cubreHasta: sin monto usa su precio por ciclo; sin
 * cubre_hasta suma un ciclo (mes o año) al vencimiento vigente (o a hoy, si ya
 * venció o nunca había pagado). Abonos y notas de crédito no mueven la vigencia.
 */
function registrarPago(PDO $pdo, int $empresaId, array $datos): array {
    $empresa = empresaPorId($pdo, $empresaId);
    if (!$empresa) throw new InvalidArgumentException('Empresa no encontrada.');
    if ($empresa['estado'] === 'demo') throw new InvalidArgumentException('La empresa demo no lleva pagos.');

    $tipo = (string)($datos['tipo'] ?? 'pago');
    if (!in_array($tipo, TIPOS_PAGO, true)) throw new InvalidArgumentException('Tipo de movimiento no válido.');
    if ($tipo !== 'pago' && !pagosTieneTipo($pdo)) throw new InvalidArgumentException('Para registrar abonos y notas de crédito aplica la migración 008.');

    $precio = precioEmpresa($pdo, $empresa);
    if ($tipo !== 'pago' && ($datos['monto'] ?? '') === '') throw new InvalidArgumentException('Indica el monto.');
    $monto = ($datos['monto'] ?? '') === '' ? $precio['por_ciclo'] : round((float)$datos['monto'], 2);
    if ($monto < 0) throw new InvalidArgumentException('El monto no puede ser negativo.');
    if ($tipo !== 'pago' && $monto <= 0) throw new InvalidArgumentException('El monto debe ser mayor a cero.');
    $fechaPago = !empty($datos['fecha_pago']) ? $datos['fecha_pago'] : date('Y-m-d');
    $cubreHasta = trim((string)($datos['cubre_hasta'] ?? ''));
    if ($tipo !== 'pago') {
        // Sin efecto en la vigencia: se anota la vigente para que anular siga funcionando
        $cubreHasta = $empresa['pagado_hasta'] ?: $fechaPago;
    } elseif ($cubreHasta === '') {
        $base = ($empresa['pagado_hasta'] && $empresa['pagado_hasta'] >= date('Y-m-d')) ? $empresa['pagado_hasta'] : date('Y-m-d');
        $cubreHasta = date('Y-m-d', strtotime($base . ($precio['ciclo'] === 'anual' ? ' +1 year' : ' +1 month')));
    }
    foreach ([$fechaPago, $cubreHasta] as $f) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) || !strtotime($f)) throw new InvalidArgumentException('Fecha no válida.');
    }
    $montoGuardado = $tipo === 'nota_credito' ? -$monto : $monto;

    $columnas = 'empresa_id, monto, fecha_pago, cubre_hasta, metodo, referencia, notas, factura, registrado_por';
    $valores = [
        $empresaId, $montoGuardado, $fechaPago, $cubreHasta,
        trim((string)($datos['metodo'] ?? '')) ?: null, trim((string)($datos['referencia'] ?? '')) ?: null,
        trim((string)($datos['notas'] ?? '')) ?: null, trim((string)($datos['factura'] ?? '')) ?: null,
        $_SESSION['nombre'] ?? null,
    ];
    if (pagosTieneTipo($pdo)) {
        $columnas .= ', tipo';
        $valores[] = $tipo;
    }

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO pagos ($columnas) VALUES (" . implode(', ', array_fill(0, count($valores), '?')) . ")")->execute($valores);
    $pagoId = (int)$pdo->lastInsertId();
    if ($tipo === 'pago') {
        $pdo->prepare("UPDATE empresas SET estado = 'activa', pagado_hasta = ?, actualizado_en = NOW() WHERE id = ?")
            ->execute([$cubreHasta, $empresaId]);
    }
    $pdo->commit();

    registrarEventoPlataforma($pdo, $empresaId, $tipo === 'pago' ? 'pago' : $tipo, [
        'pago_id' => $pagoId, 'monto' => $montoGuardado, 'fecha_pago' => $fechaPago, 'cubre_hasta' => $cubreHasta,
        'estado_anterior' => $empresa['estado'], 'metodo' => $datos['metodo'] ?? null,
    ]);
    return ['pago_id' => $pagoId, 'cubre_hasta' => $cubreHasta, 'monto' => $montoGuardado, 'tipo' => $tipo];
}

/**
 * Anula un pago (error de captura, devolución...). No se borra: queda en el
 * historial marcado como anulado. El vencimiento de la empresa se recalcula con
 * los pagos vigentes; si no queda ninguno, su pago se considera vencido desde
 * ayer (entra a periodo de gracia y tú decides qué sigue).
 */
function anularPago(PDO $pdo, int $pagoId, string $motivo): void {
    $motivo = trim($motivo);
    if ($motivo === '') throw new InvalidArgumentException('Indica el motivo de la anulación.');
    $stmt = $pdo->prepare("SELECT * FROM pagos WHERE id = ?");
    $stmt->execute([$pagoId]);
    $pago = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pago) throw new InvalidArgumentException('Pago no encontrado.');
    if ((int)$pago['anulado']) throw new InvalidArgumentException('El pago ya estaba anulado.');

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE pagos SET anulado = 1, anulado_motivo = ?, anulado_en = NOW() WHERE id = ?")->execute([$motivo, $pagoId]);
    $stmt = $pdo->prepare("SELECT MAX(cubre_hasta) FROM pagos WHERE empresa_id = ? AND anulado = 0");
    $stmt->execute([$pago['empresa_id']]);
    $nuevoVencimiento = $stmt->fetchColumn() ?: date('Y-m-d', strtotime('-1 day'));
    $pdo->prepare("UPDATE empresas SET pagado_hasta = ?, actualizado_en = NOW() WHERE id = ? AND estado = 'activa'")
        ->execute([$nuevoVencimiento, $pago['empresa_id']]);
    $pdo->commit();

    registrarEventoPlataforma($pdo, (int)$pago['empresa_id'], 'pago_anulado', [
        'pago_id' => $pagoId, 'monto' => (float)$pago['monto'], 'motivo' => $motivo, 'nuevo_vencimiento' => $nuevoVencimiento,
    ]);
}

// ------------------------------------------------------------------ ciclo de vida

/** Cambia el estado guardado de una empresa (activa, suspendida, cancelada, prueba). */
function cambiarEstadoEmpresa(PDO $pdo, int $empresaId, string $estado, array $extra = []): void {
    $empresa = empresaPorId($pdo, $empresaId);
    if (!$empresa) throw new InvalidArgumentException('Empresa no encontrada.');
    if ($empresa['estado'] === 'demo' || $estado === 'demo') {
        throw new InvalidArgumentException('El estado de la empresa demo no se cambia desde el panel.');
    }
    if (!in_array($estado, ['prueba', 'activa', 'suspendida', 'cancelada'], true)) {
        throw new InvalidArgumentException('Estado no válido.');
    }
    $sets = ['estado = ?'];
    $params = [$estado];
    if (!empty($extra['prueba_hasta'])) { $sets[] = 'prueba_hasta = ?'; $params[] = $extra['prueba_hasta']; }
    $params[] = $empresaId;
    $pdo->prepare("UPDATE empresas SET " . implode(', ', $sets) . ", actualizado_en = NOW() WHERE id = ?")->execute($params);
    registrarEventoPlataforma($pdo, $empresaId, 'estado', ['de' => $empresa['estado'], 'a' => $estado] + $extra);
}

/** Extiende (o reabre) la prueba: nueva fecha límite = hoy o la vigente + $dias. */
function extenderPrueba(PDO $pdo, int $empresaId, int $dias): string {
    $empresa = empresaPorId($pdo, $empresaId);
    if (!$empresa) throw new InvalidArgumentException('Empresa no encontrada.');
    if ($dias < 1 || $dias > 90) throw new InvalidArgumentException('Entre 1 y 90 días.');
    $base = ($empresa['prueba_hasta'] && $empresa['prueba_hasta'] >= date('Y-m-d')) ? $empresa['prueba_hasta'] : date('Y-m-d');
    $nueva = date('Y-m-d', strtotime("$base +$dias days"));
    cambiarEstadoEmpresa($pdo, $empresaId, 'prueba', ['prueba_hasta' => $nueva, 'dias_agregados' => $dias]);
    return $nueva;
}

/** Datos de la cuenta: nombre, contacto, datos fiscales. */
function actualizarDatosEmpresa(PDO $pdo, int $empresaId, array $d): void {
    $e = empresaPorId($pdo, $empresaId);
    if (!$e) throw new InvalidArgumentException('Empresa no encontrada.');
    $nombre = trim((string)($d['nombre'] ?? $e['nombre']));
    if ($nombre === '') throw new InvalidArgumentException('El nombre no puede quedar vacío.');
    $correo = trim((string)($d['contacto_correo'] ?? ''));
    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Correo de contacto no válido.');
    $campos = [
        'nombre' => $nombre,
        'contacto_nombre' => trim((string)($d['contacto_nombre'] ?? '')) ?: null,
        'contacto_correo' => $correo ?: null,
        'contacto_telefono' => trim((string)($d['contacto_telefono'] ?? '')) ?: null,
        'rfc' => strtoupper(trim((string)($d['rfc'] ?? ''))) ?: null,
        'razon_social' => trim((string)($d['razon_social'] ?? '')) ?: null,
    ];
    $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($campos)));
    $pdo->prepare("UPDATE empresas SET $sets, actualizado_en = NOW() WHERE id = ?")->execute([...array_values($campos), $empresaId]);
    $cambios = array_filter($campos, fn($v, $k) => (string)$v !== (string)($e[$k] ?? ''), ARRAY_FILTER_USE_BOTH);
    if ($cambios) registrarEventoPlataforma($pdo, $empresaId, 'datos', $cambios);
}

// ------------------------------------------------------------------ notas

function agregarNotaEmpresa(PDO $pdo, int $empresaId, string $nota): void {
    $nota = trim($nota);
    if ($nota === '') throw new InvalidArgumentException('La nota está vacía.');
    if (!empresaPorId($pdo, $empresaId)) throw new InvalidArgumentException('Empresa no encontrada.');
    $pdo->prepare("INSERT INTO empresa_notas (empresa_id, nota, autor) VALUES (?, ?, ?)")
        ->execute([$empresaId, mb_substr($nota, 0, 5000), $_SESSION['nombre'] ?? null]);
}

// ------------------------------------------------------------------ modo soporte

/**
 * El superadministrador entra al sistema de una empresa para dar soporte, con
 * acceso total (rol "Soporte de plataforma") aunque la cuenta esté vencida. Queda en la
 * bitácora; la interfaz muestra un aviso y el botón para volver al panel.
 */
function iniciarSoporte(PDO $pdo, int $empresaId): void {
    $e = empresaPorId($pdo, $empresaId);
    if (!$e) throw new InvalidArgumentException('Empresa no encontrada.');
    $_SESSION['soporte'] = [
        'user_id' => $_SESSION['user_id'], 'nombre' => $_SESSION['nombre'],
        'usuario' => $_SESSION['usuario'], 'rol' => $_SESSION['rol'], 'desde' => date('Y-m-d H:i:s'),
    ];
    registrarEventoPlataforma($pdo, $empresaId, 'soporte_entrada', []);
    session_regenerate_id(true);
    $_SESSION['empresa_id'] = $empresaId;
    $_SESSION['rol'] = ROL_ACCESO_TOTAL;
    $_SESSION['nombre'] = 'Soporte · ' . $_SESSION['soporte']['nombre'];
}

function terminarSoporte(PDO $pdo): void {
    if (empty($_SESSION['soporte'])) return;
    $empresaId = (int)($_SESSION['empresa_id'] ?? 0);
    $s = $_SESSION['soporte'];
    session_regenerate_id(true);
    $_SESSION['empresa_id'] = null;
    $_SESSION['user_id'] = $s['user_id'];
    $_SESSION['nombre'] = $s['nombre'];
    $_SESSION['usuario'] = $s['usuario'];
    $_SESSION['rol'] = $s['rol'];
    unset($_SESSION['soporte']);
    establecerEmpresa(null);
    registrarEventoPlataforma($pdo, $empresaId ?: null, 'soporte_salida', ['desde' => $s['desde']]);
}

// ------------------------------------------------------------------ administradores

function listarAdministradores(PDO $pdo): array {
    return $pdo->query("SELECT id, nombre, usuario, fecha_creacion FROM usuarios WHERE empresa_id IS NULL AND rol = '" . ROL_SUPERADMIN . "' ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);
}

function crearAdministrador(PDO $pdo, string $nombre, string $correo, string $password): int {
    $correo = strtolower(trim($correo));
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Correo no válido.');
    if (strlen($password) < 10) throw new InvalidArgumentException('La contraseña debe tener al menos 10 caracteres.');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE usuario = ?");
    $stmt->execute([$correo]);
    if ((int)$stmt->fetchColumn()) throw new InvalidArgumentException('Ese correo ya tiene una cuenta.');
    $pdo->prepare("INSERT INTO usuarios (empresa_id, nombre, correo, telefono, usuario, password, rol) VALUES (NULL, ?, ?, '', ?, ?, ?)")
        ->execute([trim($nombre) ?: 'Administrador de plataforma', $correo, $correo, password_hash($password, PASSWORD_DEFAULT), ROL_SUPERADMIN]);
    $id = (int)$pdo->lastInsertId();
    registrarEventoPlataforma($pdo, null, 'admin_alta', ['usuario' => $correo]);
    return $id;
}

function eliminarAdministrador(PDO $pdo, int $id): void {
    if ($id === (int)($_SESSION['user_id'] ?? 0)) throw new InvalidArgumentException('No puedes eliminar tu propia cuenta.');
    if (count(listarAdministradores($pdo)) <= 1) throw new InvalidArgumentException('Debe quedar al menos una cuenta de plataforma.');
    $stmt = $pdo->prepare("SELECT usuario FROM usuarios WHERE id = ? AND empresa_id IS NULL AND rol = ?");
    $stmt->execute([$id, ROL_SUPERADMIN]);
    $usuario = $stmt->fetchColumn();
    if (!$usuario) throw new InvalidArgumentException('Cuenta no encontrada.');
    $pdo->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id]);
    registrarEventoPlataforma($pdo, null, 'admin_baja', ['usuario' => $usuario]);
}

function cambiarPasswordAdministrador(PDO $pdo, string $actual, string $nueva): void {
    if (strlen($nueva) < 10) throw new InvalidArgumentException('La nueva contraseña debe tener al menos 10 caracteres.');
    $stmt = $pdo->prepare("SELECT password FROM usuarios WHERE id = ? AND empresa_id IS NULL");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $hash = $stmt->fetchColumn();
    if (!$hash || !password_verify($actual, $hash)) throw new InvalidArgumentException('La contraseña actual no es correcta.');
    $nuevoHash = password_hash($nueva, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE usuarios SET password = ? WHERE id = ?")->execute([$nuevoHash, (int)$_SESSION['user_id']]);
    actualizarHuellaSesion($nuevoHash);
    registrarEventoPlataforma($pdo, null, 'admin_password', []);
}

// ------------------------------------------------------------------ configuración

function guardarConfigPlataforma(PDO $pdo, array $valores): void {
    $permitidas = array_keys(CONFIG_PLATAFORMA_DEFECTO);
    $stmt = $pdo->prepare("INSERT INTO plataforma_config (clave, valor, actualizado_en) VALUES (?, ?, NOW())
                           ON DUPLICATE KEY UPDATE valor = VALUES(valor), actualizado_en = NOW()");
    $guardados = [];
    foreach ($valores as $clave => $valor) {
        if (!in_array($clave, $permitidas, true)) continue;
        $valor = trim(str_replace("\r\n", "\n", (string)$valor));
        if (in_array($clave, ['dias_prueba', 'dias_gracia', 'dias_solo_lectura', 'registro_max_por_ip', 'plan_prueba'], true)
            && $valor !== '' && (!ctype_digit($valor) || (int)$valor > 365)) {
            throw new InvalidArgumentException("Valor no válido para $clave.");
        }
        if ($clave === 'contacto_correo' && $valor !== '' && !filter_var($valor, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Correo de contacto no válido.');
        }
        if ($clave === 'pago_clabe') {
            $valor = preg_replace('/\s+/', '', $valor);
            if ($valor !== '' && !preg_match('/^\d{18}$/', $valor)) throw new InvalidArgumentException('La CLABE debe tener 18 dígitos.');
        }
        if ($clave === 'dias_verificacion' && $valor !== '' && (!ctype_digit($valor) || (int)$valor > 15)) {
            throw new InvalidArgumentException('Los días de acceso mientras verificas un pago van de 0 a 15.');
        }
        if (in_array($clave, ['pago_banco', 'pago_beneficiario'], true)) $valor = mb_substr($valor, 0, 150);
        if ($clave === 'pago_instrucciones') $valor = mb_substr($valor, 0, 1000);
        $stmt->execute([$clave, $valor]);
        $guardados[$clave] = $valor;
    }
    if ((int)($guardados['dias_solo_lectura'] ?? 0) && (int)($guardados['dias_gracia'] ?? 0)
        && (int)$guardados['dias_solo_lectura'] < (int)$guardados['dias_gracia']) {
        throw new InvalidArgumentException('Los días de solo lectura deben ser mayores o iguales a los de gracia.');
    }
    registrarEventoPlataforma($pdo, null, 'config', $guardados);
}

// ------------------------------------------------------------------ exportación

/**
 * Exporta una empresa completa a un ZIP: datos.sql (sus tablas con sus
 * datos, más sus usuarios y pagos) y sus archivos (uploads/, manuales/).
 * Devuelve la ruta del ZIP temporal (el llamador lo envía y lo borra).
 */
function exportarEmpresa(PDO $pdo, int $empresaId): string {
    if (!class_exists('ZipArchive')) throw new RuntimeException('El servidor no tiene la extensión zip de PHP.');
    $empresa = empresaPorId($pdo, $empresaId);
    if (!$empresa) throw new InvalidArgumentException('Empresa no encontrada.');
    $prefijo = prefijoEmpresa($empresaId);

    $sql = "-- Exportación de la empresa \"{$empresa['nombre']}\" (id $empresaId) - " . date('Y-m-d H:i:s') . "\n"
         . "-- Tablas con prefijo $prefijo, más sus usuarios y pagos.\n"
         . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
    $volcar = function (string $tabla, string $where = '', array $params = []) use ($pdo, &$sql) {
        $stmt = $pdo->prepare("SELECT * FROM `$tabla`" . ($where ? " WHERE $where" : ''));
        $stmt->execute($params);
        while ($fila = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $sql .= "INSERT INTO `$tabla` (`" . implode('`, `', array_keys($fila)) . "`) VALUES ("
                  . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $fila)) . ");\n";
        }
        $sql .= "\n";
    };
    foreach (TABLAS_EMPRESA as $tabla) {
        $nombre = $prefijo . $tabla;
        $crear = $pdo->query("SHOW CREATE TABLE `$nombre`")->fetch(PDO::FETCH_NUM)[1] ?? null;
        if (!$crear) continue;
        $sql .= "DROP TABLE IF EXISTS `$nombre`;\n$crear;\n";
        $volcar($nombre);
    }
    $sql .= "-- Usuarios de la empresa (contraseñas cifradas)\n";
    $volcar('usuarios', 'empresa_id = ?', [$empresaId]);
    $sql .= "-- Pagos registrados\n";
    $volcar('pagos', 'empresa_id = ?', [$empresaId]);
    try {
        $sql .= "-- Solicitudes de contratación\n";
        $volcar('solicitudes_pago', 'empresa_id = ?', [$empresaId]);
    } catch (Throwable $e) {
        // Sin la migración 004 no hay solicitudes
    }
    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

    $zipRuta = tempnam(sys_get_temp_dir(), 'sdexp');
    $zip = new ZipArchive();
    if ($zip->open($zipRuta, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('No se pudo crear el ZIP.');
    $zip->addFromString('datos.sql', $sql);
    $zip->addFromString('empresa.json', json_encode($empresa, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    foreach (['uploads', 'manuales'] as $base) {
        $dir = realpath(__DIR__ . '/../' . $base . '/e' . $empresaId);
        if (!$dir) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $zip->addFile($f->getPathname(), $base . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1)));
            }
        }
    }
    $zip->close();
    registrarEventoPlataforma($pdo, $empresaId, 'exportacion', ['bytes' => filesize($zipRuta)]);
    return $zipRuta;
}

/** CSV (UTF-8 con BOM para Excel) a partir de filas asociativas. */
function enviarCsv(string $nombreArchivo, array $encabezados, array $filas): void {
    header_remove('Content-Type');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
    header('Cache-Control: private, no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_values($encabezados));
    foreach ($filas as $f) {
        fputcsv($out, csvFilaSegura(array_map(fn($k) => $f[$k] ?? '', array_keys($encabezados))));  // S-5
    }
    fclose($out);
}
