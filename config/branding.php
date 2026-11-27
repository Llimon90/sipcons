<?php
// Identidad visual (marca blanca): la de la plataforma sale del .env y la de
// cada empresa de empresas.marca (ver brandConfig). La consumen branding/tema.css.php (colores), branding/marca.js.php
// (nombre, logo, favicon en el frontend) y el backend (textos generados).
require_once __DIR__ . '/env.php';

function brandHex(string $valor, string $defecto): string {
    $valor = trim($valor);
    if (preg_match('/^#?([0-9a-f]{6})$/i', $valor, $m)) return '#' . strtolower($m[1]);
    if (preg_match('/^#?([0-9a-f])([0-9a-f])([0-9a-f])$/i', $valor, $m)) {
        return '#' . strtolower($m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3]);
    }
    return $defecto;
}

// Mezcla un color con blanco (factor > 0) o con negro (factor < 0).
function brandMezclar(string $hex, float $factor): string {
    $rgb = sscanf($hex, '#%02x%02x%02x');
    $destino = $factor >= 0 ? 255 : 0;
    $f = abs($factor);
    $out = array_map(fn($c) => (int)round($c + ($destino - $c) * $f), $rgb);
    return vsprintf('#%02x%02x%02x', $out);
}

function brandRgb(string $hex): string {
    return implode(', ', sscanf($hex, '#%02x%02x%02x'));
}

function brandColores(string $primario, string $secundario, string $sidebar, string $hover = ''): array {
    return [
        'primario'          => $primario,
        'primario_hover'    => brandHex($hover, brandMezclar($primario, -0.2)),
        'primario_activo'   => brandMezclar($primario, -0.4),
        'secundario'        => $secundario,
        'secundario_oscuro' => brandMezclar($secundario, -0.12),
        'sidebar'           => $sidebar,
        'sidebar_2'         => brandMezclar($sidebar, 0.08),
        // Variantes para el tema oscuro (más claras para contrastar sobre fondo oscuro)
        'oscuro_acento'     => brandMezclar($primario, 0.2),
        'oscuro_acento_2'   => brandMezclar($primario, 0.05),
        'oscuro_enlace'     => brandMezclar($primario, 0.35),
        'oscuro_relleno'    => brandMezclar($primario, -0.15),
    ];
}

/**
 * Marca vigente. Sin empresa en sesión (login, páginas públicas) es la marca de
 * la plataforma, tomada del .env. Con empresa en sesión, sus valores de
 * empresas.marca sustituyen a los de la plataforma (nombre, logo, colores,
 * categorías, prefijo de folio...).
 */
function brandConfig(): array {
    static $cache = [];
    $empresa = (function_exists('empresaActual') && class_exists('EmpresaContexto', false) && EmpresaContexto::$id)
        ? empresaActual() : null;
    $clave = $empresa ? (string)$empresa['id'] : 'plataforma';
    if (isset($cache[$clave])) return $cache[$clave];

    $cfg = [
        'nombre'      => env('APP_NAME', 'Sistema'),
        'eslogan'     => env('APP_TAGLINE', 'Gestión de servicios y mantenimiento'),
        'empresa'     => env('APP_COMPANY', env('APP_NAME', 'Sistema')),
        'logo'        => env('APP_LOGO', 'img/logo.svg'),
        'favicon'     => env('APP_FAVICON', ''),
        'pie_login'   => env('APP_LOGIN_FOOTER', 'Uso exclusivo de personal autorizado.'),
        // URL pública del aviso de privacidad (vacío = sin enlace en el login)
        'aviso_privacidad' => env('APP_AVISO_PRIVACIDAD_URL', ''),
        // Términos de uso del registro en línea (legal: revisión de abogado pendiente) y su versión
        'terminos'    => env('APP_TERMINOS_URL', ''),
        'terminos_version' => env('APP_TERMINOS_VERSION', ''),
        // Categorías de incidencia (formulario, detalle y filtros de búsqueda)
        'categorias'  => array_values(array_filter(array_map('trim', explode(',', (string)env(
            'INCIDENCIA_CATEGORIAS',
            'Mantenimiento correctivo,Mantenimiento preventivo,Instalación,Garantía,Diagnóstico,Otros'
        ))), 'strlen')),
        // Prefijo de los folios de incidencia (SD-0001)
        'prefijo_folio' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)env('FOLIO_PREFIX', 'SD'))) ?: 'SD',
        'colores'     => brandColores(
            brandHex((string)env('BRAND_PRIMARY', ''), '#3498db'),
            brandHex((string)env('BRAND_SECONDARY', ''), '#1a4de4'),
            brandHex((string)env('BRAND_SIDEBAR', ''), '#2c3e50'),
            (string)env('BRAND_PRIMARY_HOVER', '')
        ),
    ];

    if ($empresa) {
        $m = $empresa['marca'] ?? [];
        // 'nombre' es siempre el del sistema (p. ej. "Sistema"); el cliente es 'empresa'
        $cfg['empresa'] = $empresa['nombre'];
        foreach (['eslogan', 'logo'] as $k) {
            if (!empty($m[$k])) $cfg[$k] = (string)$m[$k];
        }
        if (!empty($m['categorias']) && is_array($m['categorias'])) {
            $cfg['categorias'] = array_values(array_filter(array_map('trim', $m['categorias']), 'strlen'));
        }
        if (!empty($m['prefijo_folio'])) {
            $cfg['prefijo_folio'] = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$m['prefijo_folio'])) ?: $cfg['prefijo_folio'];
        }
        $c = $m['colores'] ?? [];
        $cfg['colores'] = brandColores(
            brandHex((string)($c['primario'] ?? ''), $cfg['colores']['primario']),
            brandHex((string)($c['secundario'] ?? ''), $cfg['colores']['secundario']),
            brandHex((string)($c['sidebar'] ?? ''), $cfg['colores']['sidebar'])
        );
    }
    return $cache[$clave] = $cfg;
}

// Siguiente folio de incidencia a partir del último registrado. Toma solo los
// dígitos finales, así que sigue la numeración aunque cambie FOLIO_PREFIX.
function siguienteFolioIncidencia(?string $ultimo): string {
    $n = ($ultimo && preg_match('/(\d+)\s*$/', $ultimo, $m)) ? (int)$m[1] : 0;
    return brandConfig()['prefijo_folio'] . '-' . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
}

/**
 * Toma el siguiente folio de un tipo de documento con el contador de la empresa
 * (tabla folios, migración 011). Bloquea el contador hasta el fin de la transacción:
 * dos altas simultáneas no repiten folio, y el folio de un registro eliminado no se
 * vuelve a usar (CV-12, C-H03, C-H05). Llamar DENTRO de una transacción.
 *  $clave: 'incidencias' | 'ventas'; $tabla/$columna: dónde vive el folio;
 *  $formato: fn(int $n): string.
 * Sin la tabla folios (migración pendiente) sigue el método anterior.
 */
function tomarFolio(PDO $pdo, string $clave, string $tabla, string $columna, callable $formato): string {
    $sufijo = fn($folio) => ($folio && preg_match('/(\d+)\s*$/', (string)$folio, $m)) ? (int)$m[1] : 0;
    $ultimoPorId = fn() => $sufijo($pdo->query("SELECT `$columna` FROM `$tabla` ORDER BY id DESC LIMIT 1 FOR UPDATE")->fetchColumn());

    static $hayContador = [];
    $empresa = class_exists('EmpresaContexto') ? (string)EmpresaContexto::$id : '';
    if (!array_key_exists($empresa, $hayContador)) {
        try {
            $hayContador[$empresa] = (bool)$pdo->query("SHOW TABLES LIKE 'folios'")->fetch();
        } catch (Throwable $e) {
            $hayContador[$empresa] = false;
        }
    }
    if (!$hayContador[$empresa]) return $formato($ultimoPorId() + 1);

    $lee = $pdo->prepare('SELECT ultimo FROM folios WHERE clave = ? FOR UPDATE');
    $lee->execute([$clave]);
    $ultimo = $lee->fetchColumn();
    if ($ultimo === false) {
        // Empresa sin contador para este tipo: empieza donde va la numeración. ON DUPLICATE
        // KEY toma el bloqueo exclusivo de una vez (INSERT IGNORE toma uno compartido y dos
        // altas simultáneas se bloquearían mutuamente al querer subirlo).
        $pdo->prepare('INSERT INTO folios (clave, ultimo) VALUES (?, ?) ON DUPLICATE KEY UPDATE ultimo = ultimo')
            ->execute([$clave, $ultimoPorId()]);
        $lee->execute([$clave]);
        $ultimo = $lee->fetchColumn();
    }
    $n = (int)$ultimo;
    $existe = $pdo->prepare("SELECT COUNT(*) FROM `$tabla` WHERE `$columna` = ?");
    do {  // por si el prefijo cambió y volvió, o hay folios capturados a mano
        $n++;
        $folio = $formato($n);
        $existe->execute([$folio]);
    } while ((int)$existe->fetchColumn() > 0);
    $pdo->prepare('UPDATE folios SET ultimo = ? WHERE clave = ?')->execute([$n, $clave]);
    return $folio;
}

/** Siguiente folio de orden de servicio (<prefijo>-NNNN). Llamar dentro de una transacción. */
function tomarFolioIncidencia(PDO $pdo): string {
    $prefijo = brandConfig()['prefijo_folio'];
    return tomarFolio($pdo, 'incidencias', 'incidencias', 'numero_incidente',
        fn(int $n) => $prefijo . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT));
}
