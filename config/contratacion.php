<?php
// Contratación por transferencia, sin pasarela de pago.
//
// El administrador de una empresa elige plan y ciclo (auth/contratar.html),
// recibe una referencia y los datos bancarios de la plataforma, y puede subir
// su comprobante. Nada se activa por lo que diga el cliente: el pago solo se
// registra cuando la plataforma lo confirma en su panel (Solicitudes), que es
// cuando la empresa pasa a activa con su nuevo vencimiento.
//
// Mientras se verifica, la empresa recibe unos días de acceso provisional
// (dias_verificacion, empresas.verificacion_hasta) para no quedar bloqueada
// por un depósito que tarda; se otorgan como máximo una vez cada 30 días y se
// retiran al rechazar o cancelar la solicitud.
//
// Requiere la migración 004; sin ella contratacionDisponible() es false.
require_once __DIR__ . '/plataforma.php';
require_once __DIR__ . '/../auth/permisos.php';

// Roles de la empresa que pueden contratar y pagar
const ROLES_CONTRATAN = ['Administrador', ROL_ACCESO_TOTAL];

// Catálogos del SAT para CFDI 4.0 (los más comunes en pymes)
const REGIMENES_FISCALES = [
    '601' => 'General de Ley Personas Morales',
    '603' => 'Personas Morales con Fines no Lucrativos',
    '605' => 'Sueldos y Salarios',
    '606' => 'Arrendamiento',
    '612' => 'Personas Físicas con Actividades Empresariales y Profesionales',
    '616' => 'Sin obligaciones fiscales',
    '621' => 'Incorporación Fiscal',
    '625' => 'Plataformas Tecnológicas',
    '626' => 'Régimen Simplificado de Confianza',
];
const USOS_CFDI = [
    'G03' => 'Gastos en general',
    'S01' => 'Sin efectos fiscales',
];

const EXTENSIONES_COMPROBANTE = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic'];
const MAX_BYTES_COMPROBANTE = 8 * 1024 * 1024;

function contratacionDisponible(PDO $pdo): bool {
    static $existe = null;
    if ($existe === null) {
        try {
            $existe = (bool)$pdo->query("SHOW TABLES LIKE 'solicitudes_pago'")->fetch();
        } catch (Throwable $e) {
            $existe = false;
        }
    }
    return $existe;
}

/** ¿La empresa puede contratar o renovar? (la demo, suspendidas y canceladas no) */
function empresaPuedeContratar(?array $e): bool {
    // D-50: en modo satélite se contrata y se paga con Solid Admin (una sola fuente de cobranza)
    if (solidSatelite()) return false;
    return $e !== null && in_array($e['estado'], ['prueba', 'activa'], true);
}

function rolPuedeContratar(?string $rol): bool {
    return in_array((string)$rol, ROLES_CONTRATAN, true);
}

/** Usuario en sesión: administrador de una empresa que puede contratar. */
function puedeContratar(PDO $pdo): bool {
    return contratacionDisponible($pdo) && rolPuedeContratar($_SESSION['rol'] ?? null)
        && empresaPuedeContratar(empresaActual());
}

/** Monto por ciclo de un plan para esta empresa (el precio pactado aplica si no cambia de plan ni de ciclo). */
function montoContratacion(PDO $pdo, array $e, int $planId, string $ciclo): float {
    $mismo = (int)$e['plan_id'] === $planId && ($e['ciclo'] ?? 'mensual') === $ciclo;
    return precioEmpresa($pdo, ['plan_id' => $planId, 'ciclo' => $ciclo, 'precio' => $mismo ? $e['precio'] : null])['por_ciclo'];
}

/** Planes que se pueden contratar, con su monto mensual y anual para esta empresa. */
function planesContratables(PDO $pdo, array $e): array {
    $planes = [];
    foreach (listarPlanes($pdo, true) as $p) {
        $mensual = montoContratacion($pdo, $e, (int)$p['id'], 'mensual');
        $anual = montoContratacion($pdo, $e, (int)$p['id'], 'anual');
        if ($mensual <= 0 && $anual <= 0) continue;
        $planes[] = [
            'id' => (int)$p['id'], 'nombre' => $p['nombre'], 'descripcion' => $p['descripcion'],
            'max_usuarios' => $p['max_usuarios'] !== null ? (int)$p['max_usuarios'] : null,
            'mensual' => $mensual, 'anual' => $anual,
        ];
    }
    return $planes;
}

/** Lo que el cliente necesita para transferir. */
function datosPagoPlataforma(): array {
    return [
        'banco'         => configPlataforma('pago_banco'),
        'beneficiario'  => configPlataforma('pago_beneficiario'),
        'clabe'         => configPlataforma('pago_clabe'),
        'instrucciones' => configPlataforma('pago_instrucciones'),
        'moneda'        => configPlataforma('moneda'),
        'contacto'      => trim(contactoComercial()),
    ];
}

function solicitudPorId(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM solicitudes_pago WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function solicitudPendiente(PDO $pdo, int $empresaId): ?array {
    $stmt = $pdo->prepare("SELECT s.*, p.nombre AS plan FROM solicitudes_pago s LEFT JOIN planes p ON p.id = s.plan_id
                           WHERE s.empresa_id = ? AND s.estado = 'pendiente' ORDER BY s.id DESC LIMIT 1");
    $stmt->execute([$empresaId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function solicitudesDeEmpresa(PDO $pdo, int $empresaId, int $limite = 10): array {
    $stmt = $pdo->prepare(
        "SELECT s.*, p.nombre AS plan FROM solicitudes_pago s LEFT JOIN planes p ON p.id = s.plan_id
         WHERE s.empresa_id = ? ORDER BY s.id DESC LIMIT " . max(1, $limite)
    );
    $stmt->execute([$empresaId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Crea la solicitud de contratación de la empresa en sesión. Valida plan,
 * ciclo y datos fiscales; el monto lo calcula el servidor. Devuelve la solicitud.
 */
function crearSolicitudPago(PDO $pdo, array $e, array $d): array {
    if (!empresaPuedeContratar($e)) throw new InvalidArgumentException('Esta cuenta no puede contratar en línea.');
    if (solicitudPendiente($pdo, (int)$e['id'])) {
        throw new InvalidArgumentException('Ya tienes una solicitud de pago en verificación.');
    }
    $planId = (int)($d['plan_id'] ?? 0);
    $plan = planPorId($pdo, $planId);
    if (!$plan || !(int)$plan['activo']) throw new InvalidArgumentException('Elige un plan disponible.');
    $ciclo = ($d['ciclo'] ?? '') === 'anual' ? 'anual' : 'mensual';
    $monto = montoContratacion($pdo, $e, $planId, $ciclo);
    if ($monto <= 0) throw new InvalidArgumentException('Ese plan no tiene precio para el ciclo elegido.');

    $fiscal = ['requiere_factura' => 0, 'rfc' => null, 'razon_social' => null, 'regimen_fiscal' => null,
               'codigo_postal' => null, 'uso_cfdi' => null, 'correo_factura' => null];
    if (!empty($d['requiere_factura']) && $d['requiere_factura'] !== '0') {
        $rfc = strtoupper(preg_replace('/\s+/', '', (string)($d['rfc'] ?? '')));
        $razon = trim((string)($d['razon_social'] ?? ''));
        $regimen = (string)($d['regimen_fiscal'] ?? '');
        $cp = trim((string)($d['codigo_postal'] ?? ''));
        $uso = (string)($d['uso_cfdi'] ?? 'G03');
        $correo = strtolower(trim((string)($d['correo_factura'] ?? '')));
        if (!preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u', $rfc)) throw new InvalidArgumentException('RFC no válido.');
        if (mb_strlen($razon) < 2 || mb_strlen($razon) > 200) throw new InvalidArgumentException('Escribe la razón social tal como aparece en tu constancia fiscal.');
        if (!isset(REGIMENES_FISCALES[$regimen])) throw new InvalidArgumentException('Elige tu régimen fiscal.');
        if (!preg_match('/^\d{5}$/', $cp)) throw new InvalidArgumentException('El código postal fiscal debe tener 5 dígitos.');
        if (!isset(USOS_CFDI[$uso])) throw new InvalidArgumentException('Uso de CFDI no válido.');
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Escribe el correo donde recibirás la factura.');
        $fiscal = ['requiere_factura' => 1, 'rfc' => $rfc, 'razon_social' => mb_strtoupper($razon), 'regimen_fiscal' => $regimen,
                   'codigo_postal' => $cp, 'uso_cfdi' => $uso, 'correo_factura' => $correo];
    }

    // Acceso provisional: como máximo una vez cada 30 días
    $dias = min(15, diasConfig('dias_verificacion'));
    $accesoHasta = null;
    if ($dias > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM solicitudes_pago WHERE empresa_id = ? AND acceso_hasta IS NOT NULL AND creado_en >= NOW() - INTERVAL 30 DAY");
        $stmt->execute([(int)$e['id']]);
        if (!(int)$stmt->fetchColumn()) $accesoHasta = date('Y-m-d', strtotime("+$dias days"));
    }

    $pdo->prepare(
        "INSERT INTO solicitudes_pago (empresa_id, plan_id, ciclo, monto, moneda, requiere_factura, rfc, razon_social,
            regimen_fiscal, codigo_postal, uso_cfdi, correo_factura, acceso_hasta, usuario_id, solicitado_por)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        (int)$e['id'], $planId, $ciclo, $monto, configPlataforma('moneda'), ...array_values($fiscal),
        $accesoHasta, (int)($_SESSION['user_id'] ?? 0) ?: null, $_SESSION['nombre'] ?? null,
    ]);
    $id = (int)$pdo->lastInsertId();
    $referencia = 'E' . (int)$e['id'] . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
    $pdo->prepare("UPDATE solicitudes_pago SET referencia = ? WHERE id = ?")->execute([$referencia, $id]);
    if ($accesoHasta) {
        $pdo->prepare("UPDATE empresas SET verificacion_hasta = ? WHERE id = ?")->execute([$accesoHasta, (int)$e['id']]);
    }
    registrarEventoPlataforma($pdo, (int)$e['id'], 'solicitud_pago', [
        'solicitud_id' => $id, 'referencia' => $referencia, 'plan' => $plan['nombre'], 'ciclo' => $ciclo,
        'monto' => $monto, 'acceso_hasta' => $accesoHasta,
    ]);
    return solicitudPorId($pdo, $id);
}

function carpetaComprobantes(int $empresaId): string {
    return __DIR__ . '/../storage/comprobantes/e' . $empresaId;
}

function rutaComprobante(array $sol): ?string {
    if (empty($sol['comprobante']) || basename($sol['comprobante']) !== $sol['comprobante']) return null;
    $ruta = carpetaComprobantes((int)$sol['empresa_id']) . '/' . $sol['comprobante'];
    return is_file($ruta) ? $ruta : null;
}

/** Guarda (o reemplaza) el comprobante de una solicitud pendiente. $archivo = entrada de $_FILES. */
function guardarComprobante(PDO $pdo, array $sol, array $archivo): void {
    if ($sol['estado'] !== 'pendiente') throw new InvalidArgumentException('La solicitud ya fue atendida.');
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
        throw new InvalidArgumentException(($archivo['error'] ?? 0) === UPLOAD_ERR_INI_SIZE
            ? 'El archivo es demasiado grande.' : 'No se recibió el archivo.');
    }
    $ext = strtolower(pathinfo((string)$archivo['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, EXTENSIONES_COMPROBANTE, true)) throw new InvalidArgumentException('Sube el comprobante en PDF o imagen (JPG, PNG, WEBP, HEIC).');
    if ((int)$archivo['size'] > MAX_BYTES_COMPROBANTE) throw new InvalidArgumentException('El comprobante no debe pasar de 8 MB.');

    $dir = carpetaComprobantes((int)$sol['empresa_id']);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('No se pudo crear la carpeta de comprobantes.');
    $nombre = 's' . (int)$sol['id'] . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($archivo['tmp_name'], "$dir/$nombre")) throw new RuntimeException('No se pudo guardar el comprobante.');
    if ($anterior = rutaComprobante($sol)) @unlink($anterior);

    $original = mb_substr(preg_replace('/[^\p{L}\p{N} ._()-]+/u', '_', basename((string)$archivo['name'])), 0, 200);
    $pdo->prepare("UPDATE solicitudes_pago SET comprobante = ?, comprobante_nombre = ? WHERE id = ?")
        ->execute([$nombre, $original, (int)$sol['id']]);
    registrarEventoPlataforma($pdo, (int)$sol['empresa_id'], 'comprobante', ['solicitud_id' => (int)$sol['id'], 'referencia' => $sol['referencia']]);
}

/** Envía el comprobante al navegador (para verlo, nunca se ejecuta). */
function enviarComprobante(array $sol): void {
    $ruta = rutaComprobante($sol);
    if (!$ruta) {
        http_response_code(404);
        exit('Comprobante no encontrado');
    }
    $tipos = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
              'webp' => 'image/webp', 'heic' => 'image/heic'];
    $ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
    header_remove('Content-Type');
    header('Content-Type: ' . ($tipos[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($ruta));
    header('Content-Disposition: inline; filename="comprobante-' . preg_replace('/[^A-Za-z0-9-]/', '', (string)$sol['referencia']) . '.' . $ext . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($ruta);
    exit;
}

/** El cliente retira su solicitud pendiente (eligió mal el plan, pagará después...). */
function cancelarSolicitud(PDO $pdo, int $empresaId, int $id): void {
    $stmt = $pdo->prepare("UPDATE solicitudes_pago SET estado = 'cancelada', resuelto_por = ?, resuelto_en = NOW()
                           WHERE id = ? AND empresa_id = ? AND estado = 'pendiente'");
    $stmt->execute([$_SESSION['nombre'] ?? null, $id, $empresaId]);
    if (!$stmt->rowCount()) throw new InvalidArgumentException('La solicitud ya no está pendiente.');
    $pdo->prepare("UPDATE empresas SET verificacion_hasta = NULL WHERE id = ?")->execute([$empresaId]);
    registrarEventoPlataforma($pdo, $empresaId, 'solicitud_cancelada', ['solicitud_id' => $id]);
}

/**
 * La plataforma confirma que el dinero llegó: aplica plan y ciclo, guarda los
 * datos fiscales, registra el pago (la empresa queda activa) y cierra la
 * solicitud. $d: monto, fecha_pago, cubre_hasta, factura, notas (opcionales).
 * Si la empresa paga durante su prueba, el periodo pagado empieza al terminar la prueba.
 */
function confirmarSolicitud(PDO $pdo, int $id, array $d): array {
    $sol = solicitudPorId($pdo, $id);
    if (!$sol) throw new InvalidArgumentException('Solicitud no encontrada.');
    if ($sol['estado'] !== 'pendiente') throw new InvalidArgumentException('La solicitud ya fue atendida.');
    $e = empresaPorId($pdo, (int)$sol['empresa_id']);
    if (!$e) throw new InvalidArgumentException('La empresa ya no existe.');
    if (in_array($e['estado'], ['demo', 'cancelada'], true)) throw new InvalidArgumentException('La cuenta está cancelada: reactívala antes de registrar el pago.');

    // Se reclama la solicitud primero: dos clics no registran dos pagos
    $stmt = $pdo->prepare("UPDATE solicitudes_pago SET estado = 'confirmada', resuelto_por = ?, resuelto_en = NOW() WHERE id = ? AND estado = 'pendiente'");
    $stmt->execute([$_SESSION['nombre'] ?? null, $id]);
    if (!$stmt->rowCount()) throw new InvalidArgumentException('La solicitud ya fue atendida.');

    try {
        $empresaId = (int)$e['id'];
        if ((int)$e['plan_id'] !== (int)$sol['plan_id'] || ($e['ciclo'] ?? 'mensual') !== $sol['ciclo']) {
            cambiarPlanEmpresa($pdo, $empresaId, (int)$sol['plan_id'], $sol['ciclo'], null);
        }
        if ((int)$sol['requiere_factura']) {
            $pdo->prepare("UPDATE empresas SET rfc = ?, razon_social = ? WHERE id = ?")->execute([$sol['rfc'], $sol['razon_social'], $empresaId]);
        }
        $cubreHasta = trim((string)($d['cubre_hasta'] ?? ''));
        if ($cubreHasta === '' && $e['estado'] === 'prueba' && $e['prueba_hasta'] && $e['prueba_hasta'] > date('Y-m-d')) {
            $cubreHasta = date('Y-m-d', strtotime($e['prueba_hasta'] . ($sol['ciclo'] === 'anual' ? ' +1 year' : ' +1 month')));
        }
        $notas = trim('Solicitud ' . $sol['referencia'] . '. ' . trim((string)($d['notas'] ?? '')));
        $pago = registrarPago($pdo, $empresaId, [
            'monto'       => ($d['monto'] ?? '') === '' ? $sol['monto'] : $d['monto'],
            'fecha_pago'  => $d['fecha_pago'] ?? '',
            'cubre_hasta' => $cubreHasta,
            'metodo'      => 'Transferencia',
            'referencia'  => $sol['referencia'],
            'factura'     => $d['factura'] ?? '',
            'notas'       => mb_substr($notas, 0, 255),
        ]);
    } catch (Throwable $ex) {
        $pdo->prepare("UPDATE solicitudes_pago SET estado = 'pendiente', resuelto_por = NULL, resuelto_en = NULL WHERE id = ?")->execute([$id]);
        throw $ex;
    }
    $pdo->prepare("UPDATE solicitudes_pago SET pago_id = ? WHERE id = ?")->execute([$pago['pago_id'], $id]);
    $pdo->prepare("UPDATE empresas SET verificacion_hasta = NULL WHERE id = ?")->execute([$empresaId]);
    registrarEventoPlataforma($pdo, $empresaId, 'solicitud_confirmada', [
        'solicitud_id' => $id, 'referencia' => $sol['referencia'], 'monto' => $pago['monto'], 'cubre_hasta' => $pago['cubre_hasta'],
    ]);
    return $pago;
}

/** El pago no llegó o no coincide: se retira el acceso provisional y el cliente ve el motivo. */
function rechazarSolicitud(PDO $pdo, int $id, string $motivo): void {
    $motivo = trim($motivo);
    if ($motivo === '') throw new InvalidArgumentException('Indica el motivo para que el cliente sepa qué corregir.');
    $sol = solicitudPorId($pdo, $id);
    if (!$sol) throw new InvalidArgumentException('Solicitud no encontrada.');
    $stmt = $pdo->prepare("UPDATE solicitudes_pago SET estado = 'rechazada', motivo = ?, resuelto_por = ?, resuelto_en = NOW() WHERE id = ? AND estado = 'pendiente'");
    $stmt->execute([mb_substr($motivo, 0, 255), $_SESSION['nombre'] ?? null, $id]);
    if (!$stmt->rowCount()) throw new InvalidArgumentException('La solicitud ya fue atendida.');
    $pdo->prepare("UPDATE empresas SET verificacion_hasta = NULL WHERE id = ?")->execute([(int)$sol['empresa_id']]);
    registrarEventoPlataforma($pdo, (int)$sol['empresa_id'], 'solicitud_rechazada', [
        'solicitud_id' => $id, 'referencia' => $sol['referencia'], 'motivo' => $motivo,
    ]);
}
