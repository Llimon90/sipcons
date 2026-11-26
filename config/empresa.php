<?php
// Contexto multiempresa.
//
// Cada empresa tiene sus propias tablas en la misma base de datos, con el
// prefijo e<ID>_ (e7_incidencias, e7_clientes...). Las conexiones de
// config/database.php reescriben los nombres de esas tablas con el prefijo de
// la empresa activa, así que el resto del código escribe "FROM incidencias"
// sin saber de prefijos. Sin empresa activa no se reescribe nada y, como las
// tablas sin prefijo no existen, cualquier consulta de empresa falla en vez de
// mostrar datos de otra: el aislamiento falla cerrado.
//
// Tablas centrales (sin prefijo, nunca se reescriben): empresas, usuarios
// (con empresa_id), pagos, analytics_eventos.

// Tablas que pertenecen a cada empresa (se crean desde database/plantilla_empresa.sql)
const TABLAS_EMPRESA = [
    'incidencias', 'archivos_incidencias',
    'clientes', 'cliente_contactos', 'padron_equipos',
    'marcas', 'modelos', 'documentos',
    'inventario_articulos', 'inventario_movimientos',
    'ventas', 'venta_detalles', 'venta_archivos', 'archivos_ventas',
    'permisos_rol', 'auditoria',
    'cotizaciones', 'cotizacion_partidas', 'cotizacion_ventas',
    'cuentas_cobrar', 'cobros', 'gastos',
    'folios', 'incidencia_servicios',
    'cliente_sucursales', 'incidencia_equipos',  // D-44, D-41 (migración 017)
];

// Estados guardados en empresas.estado
const ESTADOS_EMPRESA = ['demo', 'prueba', 'activa', 'suspendida', 'cancelada'];

// Ajustes de negocio: valores por defecto, sustituibles desde el panel de
// plataforma (tabla plataforma_config). Ver docs/legal/contrato-de-servicio.md.
const CONFIG_PLATAFORMA_DEFECTO = [
    'dias_prueba'        => '15',   // duración de la prueba gratuita
    'dias_gracia'        => '7',    // días de atraso con acceso total y aviso
    'dias_solo_lectura'  => '15',   // hasta este atraso: solo lectura; después, suspendida
    'plan_prueba'        => '2',    // plan con el que arrancan las pruebas (sus límites aplican)
    'registro_abierto'   => '',     // '' = usar REGISTRO_ABIERTO del .env
    'registro_max_por_ip'=> '',     // '' = usar REGISTRO_MAX_POR_IP del .env
    'moneda'             => 'MXN',
    'contacto_correo'    => '',     // aparecen en los mensajes de cuenta vencida o suspendida
    'contacto_whatsapp'  => '',
    // Contratación por transferencia (auth/contratar.html, migración 004)
    'pago_banco'         => '',
    'pago_beneficiario'  => '',
    'pago_clabe'         => '',
    'pago_instrucciones' => '',     // texto libre: otra forma de pago, enlace de cobro...
    'dias_verificacion'  => '3',    // acceso provisional al solicitar, mientras se verifica el pago
];

/** Ajuste de la plataforma (tabla plataforma_config, con valores por defecto). */
function configPlataforma(string $clave): string {
    static $valores = null;
    if ($valores === null) {
        $valores = CONFIG_PLATAFORMA_DEFECTO;
        global $pdo;
        try {
            if (isset($pdo)) {
                foreach ($pdo->query("SELECT clave, valor FROM plataforma_config")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                    if ($v !== null && $v !== '') $valores[$k] = (string)$v;
                }
            }
        } catch (\Throwable $e) {
            // Sin la migración 002 se usan los valores por defecto
        }
    }
    return $valores[$clave] ?? '';
}

function diasConfig(string $clave): int {
    // D-50: en modo satélite la gracia ya viene incluida en el vencimiento que envía Solid Admin, y la
    // suspensión por atraso también la decide el panel: aquí, vencido = solo lectura hasta nuevo aviso.
    if (solidSatelite()) {
        if ($clave === 'dias_gracia') return 0;
        if ($clave === 'dias_solo_lectura') return 36500;
    }
    return max(0, (int)configPlataforma($clave));
}

/** D-50: con SOLID_LINK_TOKEN en el .env esta instancia la administra Solid Admin (config/solid_link.php). */
function solidSatelite(): bool {
    return strlen((string)env('SOLID_LINK_TOKEN', '')) >= 24;
}

/** "Escríbenos a ventas@... o por WhatsApp al ..." según lo configurado. */
function contactoComercial(): string {
    $correo = configPlataforma('contacto_correo');
    $whats = configPlataforma('contacto_whatsapp');
    if ($correo && $whats) return " Escríbenos a $correo o por WhatsApp al $whats.";
    if ($correo) return " Escríbenos a $correo.";
    if ($whats) return " Escríbenos por WhatsApp al $whats.";
    return ' Contáctanos.';
}

final class EmpresaContexto {
    public static ?int $id = null;
    public static ?string $prefijo = null;
    public static ?array $fila = null;
}

function prefijoEmpresa(int $empresaId): string {
    return 'e' . $empresaId . '_';
}

/** Activa una empresa para el resto de la petición (o del script CLI). */
function establecerEmpresa(?int $empresaId): void {
    EmpresaContexto::$id = $empresaId ?: null;
    EmpresaContexto::$prefijo = $empresaId ? prefijoEmpresa($empresaId) : null;
    EmpresaContexto::$fila = null;

    // Zona horaria de la empresa (PHP y sesión de MySQL)
    $empresa = $empresaId ? empresaActual() : null;
    $tz = $empresa['marca']['zona_horaria'] ?? null;
    // S-H02: sin zona propia (o sin empresa) se vuelve a la de la instancia; no se hereda la anterior
    if (!$tz || !in_array($tz, DateTimeZone::listIdentifiers(), true)) $tz = (string)env('APP_TIMEZONE', 'America/Mexico_City');
    if (@date_default_timezone_set($tz) || @date_default_timezone_set('America/Mexico_City')) {
        $offset = (new DateTime('now'))->format('P');
        global $pdo, $conn;
        try { if (isset($pdo)) $pdo->exec("SET time_zone = '$offset'"); } catch (\Throwable $e) {}
        try { if (isset($conn)) @$conn->query("SET time_zone = '$offset'"); } catch (\Throwable $e) {}
    }
}

/** Fila de la empresa activa (con 'marca' decodificada), o null. */
function empresaActual(): ?array {
    if (EmpresaContexto::$id === null) return null;
    if (EmpresaContexto::$fila !== null) return EmpresaContexto::$fila;
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM empresas WHERE id = ?");
    $stmt->execute([EmpresaContexto::$id]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($fila) {
        $fila['marca'] = json_decode($fila['marca'] ?? '', true) ?: [];
    }
    return EmpresaContexto::$fila = $fila;
}

/**
 * Situación efectiva de una empresa a la fecha de hoy, calculada desde sus
 * fechas (no hace falta una tarea programada para "moverla" de estado):
 *   estado    demo | prueba | prueba_vencida | activa | gracia | solo_lectura | suspendida | cancelada
 *   acceso    total | lectura | bloqueado
 *   dias      días restantes de prueba, o días de atraso en el pago
 *   mensaje   texto para mostrar al usuario
 *   verificacion  true si el acceso es provisional (pago en verificación)
 *
 * Mientras la plataforma verifica un pago (empresas.verificacion_hasta, ver
 * config/contratacion.php) una cuenta en prueba o activa conserva el acceso
 * total aunque su prueba o su pago hayan vencido; el estado no cambia.
 */
/**
 * L-20 · RP-18: las consultas (GET) que de paso escriben mantenimiento (ligar sucursales, marcar
 * cotizaciones vencidas) no escriben si la cuenta está en solo lectura. El modo soporte sí.
 */
function empresaAdmiteEscrituras(): bool {
    if (!empty($_SESSION['soporte'])) return true;
    $e = empresaActual();
    return !$e || situacionEmpresa($e)['acceso'] === 'total';
}

function situacionEmpresa(?array $e): array {
    $s = situacionPorFechas($e);
    $hasta = $e['verificacion_hasta'] ?? null;
    if ($e && $hasta && $hasta >= date('Y-m-d') && in_array($e['estado'], ['prueba', 'activa'], true)) {
        $s['verificacion'] = true;
        $s['mensaje'] = 'Recibimos tu solicitud de pago y la estamos verificando.';
        if ($s['acceso'] !== 'total') {
            $s['acceso'] = 'total';
            $s['mensaje'] .= ' Mientras tanto tienes acceso completo hasta el ' . date('d/m/Y', strtotime($hasta)) . '.';
        }
    }
    return $s;
}

function situacionPorFechas(?array $e): array {
    if (!$e) {
        return ['estado' => 'inexistente', 'acceso' => 'bloqueado', 'dias' => null,
                'mensaje' => 'La empresa de esta cuenta ya no existe.'];
    }
    $hoy = new DateTimeImmutable('today');
    $dias = fn(?string $fecha) => $fecha ? (int)$hoy->diff(new DateTimeImmutable($fecha))->format('%r%a') : null;

    switch ($e['estado']) {
        case 'demo':
            return ['estado' => 'demo', 'acceso' => 'total', 'dias' => null, 'mensaje' => ''];
        case 'cancelada':
            return ['estado' => 'cancelada', 'acceso' => 'bloqueado', 'dias' => null,
                    'mensaje' => 'Esta cuenta fue cancelada. Si necesitas recuperar tu información,' . lcfirst(trim(contactoComercial()))];
        case 'suspendida':
            return ['estado' => 'suspendida', 'acceso' => 'bloqueado', 'dias' => null,
                    'mensaje' => 'La cuenta está suspendida. Tu información se conserva.' . contactoComercial()];
        case 'prueba':
            $restan = $dias($e['prueba_hasta']);
            if (solidSatelite()) {
                // L-11 · D-50: el panel decide. Sin vence = prueba sin vencimiento; vencida = solo lectura
                // hasta que Solid Admin la active o la suspenda (aquí no se contrata en línea).
                if ($restan === null) return ['estado' => 'prueba', 'acceso' => 'total', 'dias' => null, 'mensaje' => ''];
                if ($restan < 0) {
                    return ['estado' => 'prueba_vencida', 'acceso' => 'lectura', 'dias' => 0,
                            'mensaje' => 'Tu periodo de prueba terminó: puedes consultar tu información, pero no capturar. Para contratar, ponte en contacto con nosotros.' . contactoComercial()];
                }
            }
            if ($restan === null || $restan < 0) {
                return ['estado' => 'prueba_vencida', 'acceso' => 'bloqueado', 'dias' => 0,
                        'mensaje' => 'Tu periodo de prueba terminó y tu información se conserva. El administrador de la cuenta puede contratar un plan al iniciar sesión.' . contactoComercial()];
            }
            return ['estado' => 'prueba', 'acceso' => 'total', 'dias' => $restan,
                    'mensaje' => $restan === 0 ? 'Tu periodo de prueba termina hoy.'
                        : "Te quedan $restan " . ($restan === 1 ? 'día' : 'días') . ' de prueba.'];
        case 'activa':
        default:
            $restan = $dias($e['pagado_hasta']);
            $atraso = $restan === null ? 0 : max(0, -$restan);
            if ($atraso === 0) {
                // Aviso de renovación los últimos días del periodo pagado
                $aviso = $restan !== null && $restan <= 5
                    ? ($restan === 0 ? 'Tu periodo pagado termina hoy.' : 'Tu periodo pagado termina el ' . date('d/m/Y', strtotime($e['pagado_hasta'])) . '.')
                    : '';
                return ['estado' => 'activa', 'acceso' => 'total', 'dias' => 0, 'mensaje' => $aviso];
            }
            if ($atraso <= diasConfig('dias_gracia')) {
                return ['estado' => 'gracia', 'acceso' => 'total', 'dias' => $atraso,
                        'mensaje' => "Tu pago venció hace $atraso " . ($atraso === 1 ? 'día' : 'días') . '. Regulariza tu pago para evitar restricciones.' . contactoComercial()];
            }
            if ($atraso <= max(diasConfig('dias_solo_lectura'), diasConfig('dias_gracia'))) {
                return ['estado' => 'solo_lectura', 'acceso' => 'lectura', 'dias' => $atraso,
                        'mensaje' => 'Cuenta en modo solo lectura por pago pendiente: puedes consultar y exportar, pero no capturar.' . contactoComercial()];
            }
            return ['estado' => 'suspendida', 'acceso' => 'bloqueado', 'dias' => $atraso,
                    'mensaje' => 'La cuenta está suspendida por falta de pago y tu información se conserva. El administrador de la cuenta puede pagar al iniciar sesión.' . contactoComercial()];
    }
}

/**
 * Reescribe los nombres de tablas de empresa en una consulta SQL con el
 * prefijo de la empresa activa. Solo toca identificadores (después de FROM,
 * JOIN, INTO, UPDATE, TABLE, EXISTS) y el patrón de SHOW TABLES LIKE; nunca
 * textos entre comillas como auditoria.tabla = 'incidencias'.
 */
function sqlConEmpresa(string $sql): string {
    $prefijo = EmpresaContexto::$prefijo;
    if ($prefijo === null) return $sql;

    static $patron = null;
    if ($patron === null) {
        $nombres = implode('|', array_map('preg_quote', TABLAS_EMPRESA));
        $patron = '/\b(FROM|JOIN|INTO|UPDATE|TABLE|EXISTS)(\s+)(`?)(' . $nombres . ')\3(?![\w`])/i';
    }
    $sql = preg_replace_callback($patron, fn($m) => $m[1] . $m[2] . '`' . $prefijo . $m[4] . '`', $sql);

    // SHOW TABLES LIKE 'inventario_movimientos'
    return preg_replace_callback(
        "/(SHOW\s+TABLES\s+LIKE\s+')(" . implode('|', TABLAS_EMPRESA) . ")'/i",
        fn($m) => $m[1] . $prefijo . $m[2] . "'",
        $sql
    );
}

// Meta de cumplimiento (SLA): días para cerrar una incidencia. La fija cada
// empresa en Mi empresa (empresas.marca -> "sla_dias"); la usan Inicio,
// Informes, el dashboard del técnico y las alertas de atraso.
const SLA_DIAS_DEFECTO = 7;
const SLA_DIAS_MAX = 90;

function slaDiasEmpresa(?array $empresa = null): int {
    $empresa = $empresa ?? empresaActual();
    $dias = (int)($empresa['marca']['sla_dias'] ?? SLA_DIAS_DEFECTO);
    return $dias >= 1 && $dias <= SLA_DIAS_MAX ? $dias : SLA_DIAS_DEFECTO;
}

/** Carpeta de archivos de la empresa activa: uploads/e7, manuales/e7 (relativa a la raíz). */
function carpetaEmpresa(string $base): string {
    if (EmpresaContexto::$id === null) {
        throw new RuntimeException('Sin empresa activa');
    }
    return $base . '/e' . EmpresaContexto::$id;
}
