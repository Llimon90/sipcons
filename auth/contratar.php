<?php
// API de la página Contratar plan (auth/contratar.html). Solo para el
// administrador de una empresa en prueba o activa, aunque su cuenta esté
// bloqueada por prueba o pago vencido: por eso no pasa por requireAuth(),
// que rechaza todo lo demás mientras la cuenta esté bloqueada.
//
// GET  ?accion=estado            situación, planes, datos de pago y solicitudes
// GET  ?accion=comprobante&id=   ver el comprobante subido
// POST ?accion=solicitar         plan_id, ciclo, datos fiscales, comprobante (opcional)
// POST ?accion=comprobante       id, comprobante (archivo)
// POST ?accion=cancelar          id
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/contratacion.php';

header('Content-Type: application/json');

function responder(int $codigo, array $datos): void {
    http_response_code($codigo);
    echo json_encode(['exito' => $codigo < 400] + $datos, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn() || empty($_SESSION['empresa_id'])) {
    responder(401, ['mensaje' => 'Inicia sesión para contratar.', 'login_url' => 'login.html']);
}
// S-2: usuario eliminado, con contraseña o rol cambiados: la sesión ya no vale (igual que requireAuth)
if (!revalidarUsuarioSesion()) rechazarSesionInvalida();
// S2-3 · L-13: con la contraseña temporal del panel pendiente, primero se cambia
require_once __DIR__ . '/../config/usuarios.php';
if (empty($_SESSION['soporte']) && usuarioDebeCambiarPassword($pdo, (int)$_SESSION['user_id'])) {
    responder(403, ['mensaje' => 'Cambia tu contraseña temporal para continuar.', 'codigo' => 'cambio_password']);
}
if (!contratacionDisponible($pdo)) {
    responder(503, ['mensaje' => 'La contratación en línea aún no está disponible.' . contactoComercial()]);
}
$empresa = empresaActual();
if (!rolPuedeContratar($_SESSION['rol'] ?? null)) {
    responder(403, ['mensaje' => 'Solo el administrador de la cuenta puede contratar o renovar el plan.']);
}
if (!empresaPuedeContratar($empresa)) {
    responder(403, ['mensaje' => $empresa && $empresa['estado'] === 'demo'
        ? 'La cuenta de demostración no se contrata. Crea tu cuenta de prueba para empezar.'
        : 'Esta cuenta no puede contratar en línea.' . contactoComercial()]);
}
$empresaId = (int)$empresa['id'];

/** Solicitud sin rutas internas de archivos. */
function solicitudPublica(?array $s): ?array {
    if (!$s) return null;
    $s['tiene_comprobante'] = !empty($s['comprobante']);
    unset($s['comprobante'], $s['usuario_id']);
    return $s;
}

function solicitudDeEstaEmpresa(PDO $pdo, int $empresaId, int $id): array {
    $sol = solicitudPorId($pdo, $id);
    if (!$sol || (int)$sol['empresa_id'] !== $empresaId) responder(404, ['mensaje' => 'Solicitud no encontrada.']);
    return $sol;
}

$accion = $_GET['accion'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ("$metodo:$accion") {
    case 'GET:estado':
        $situacion = situacionEmpresa($empresa);
        // El aviso general habla del administrador en tercera persona; aquí ya es él
        if ($situacion['acceso'] === 'bloqueado') {
            $situacion['mensaje'] = ($situacion['estado'] === 'prueba_vencida'
                ? 'Tu periodo de prueba terminó.' : 'Tu cuenta está suspendida por falta de pago.')
                . ' Elige un plan y paga para reactivarla: tu información se conserva completa.';
        }
        $historial = solicitudesDeEmpresa($pdo, $empresaId, 10);
        // Datos fiscales para prellenar: la última solicitud con factura o los de la cuenta
        $fiscal = ['rfc' => $empresa['rfc'], 'razon_social' => $empresa['razon_social'], 'regimen_fiscal' => '',
                   'codigo_postal' => '', 'uso_cfdi' => 'G03', 'correo_factura' => $empresa['contacto_correo']];
        foreach ($historial as $h) {
            if ((int)$h['requiere_factura']) {
                $fiscal = array_intersect_key($h, $fiscal);
                break;
            }
        }
        $pendiente = solicitudPendiente($pdo, $empresaId);
        responder(200, [
            'empresa' => [
                'nombre' => $empresa['nombre'], 'estado' => $situacion['estado'], 'acceso' => $situacion['acceso'],
                'mensaje' => $situacion['mensaje'], 'dias' => $situacion['dias'],
                'plan_id' => $empresa['plan_id'] ? (int)$empresa['plan_id'] : null, 'ciclo' => $empresa['ciclo'] ?? 'mensual',
                'prueba_hasta' => $empresa['prueba_hasta'], 'pagado_hasta' => $empresa['pagado_hasta'],
                'verificacion_hasta' => $empresa['verificacion_hasta'] ?? null,
            ],
            'planes' => planesContratables($pdo, $empresa),
            'pago' => datosPagoPlataforma(),
            'pendiente' => solicitudPublica($pendiente),
            'historial' => array_map('solicitudPublica', $historial),
            'fiscal' => $fiscal,
            'regimenes' => REGIMENES_FISCALES,
            'usos_cfdi' => USOS_CFDI,
            'dias_verificacion' => min(15, diasConfig('dias_verificacion')),
        ]);

    case 'GET:comprobante':
        enviarComprobante(solicitudDeEstaEmpresa($pdo, $empresaId, (int)($_GET['id'] ?? 0)));

    case 'POST:solicitar':
        $sol = crearSolicitudPago($pdo, $empresa, $_POST);
        $aviso = '';
        if (!empty($_FILES['comprobante']) && ($_FILES['comprobante']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                guardarComprobante($pdo, $sol, $_FILES['comprobante']);
            } catch (InvalidArgumentException $ex) {
                $aviso = 'Tu solicitud quedó registrada, pero el comprobante no se pudo subir: ' . $ex->getMessage();
            }
        }
        responder(200, ['solicitud' => solicitudPublica(solicitudPorId($pdo, (int)$sol['id'])), 'aviso' => $aviso]);

    case 'POST:comprobante':
        $sol = solicitudDeEstaEmpresa($pdo, $empresaId, (int)($_POST['id'] ?? 0));
        guardarComprobante($pdo, $sol, $_FILES['comprobante'] ?? []);
        responder(200, ['mensaje' => 'Comprobante recibido.']);

    case 'POST:cancelar':
        solicitudDeEstaEmpresa($pdo, $empresaId, (int)($_POST['id'] ?? 0));
        cancelarSolicitud($pdo, $empresaId, (int)$_POST['id']);
        responder(200, ['mensaje' => 'Solicitud cancelada.']);

    default:
        responder(400, ['mensaje' => 'Acción no válida']);
    }
} catch (InvalidArgumentException $e) {
    responder(422, ['mensaje' => mensajeErrorPublico($e)]);
} catch (Throwable $e) {
    error_log('Contratación: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    responder(500, ['mensaje' => 'No se pudo completar la operación. Inténtalo de nuevo en unos minutos.']);
}
