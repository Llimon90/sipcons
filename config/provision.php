<?php
// Alta de empresas: fila en `empresas`, sus tablas (database/plantilla_empresa.sql
// con su prefijo), sus carpetas de archivos y su administrador inicial.
// Requiere $pdo (config/database.php).
require_once __DIR__ . '/empresa.php';

// Duración de la prueba: configPlataforma("dias_prueba") (panel > Configuración)

function marcaPorDefecto(string $nombreEmpresa): array {
    return [
        'nombre'        => $nombreEmpresa,
        'eslogan'       => 'Gestión de servicios y mantenimiento',
        'logo'          => 'img/logo.svg',
        'colores'       => ['primario' => '#3498db', 'secundario' => '#1a4de4', 'sidebar' => '#2c3e50'],
        'categorias'    => ['Mantenimiento correctivo', 'Mantenimiento preventivo', 'Instalación',
                            'Garantía', 'Diagnóstico', 'Otros'],
        'prefijo_folio' => 'SD',
        'zona_horaria'  => (string)env('APP_TIMEZONE', 'America/Mexico_City'),
    ];
}

/** D-29: zona horaria elegida al registrarse; si no es válida, la de la instancia. */
function zonaHorariaRegistro(?string $zona): string {
    $zona = trim((string)$zona);
    return $zona !== '' && in_array($zona, DateTimeZone::listIdentifiers(), true)
        ? $zona : (string)env('APP_TIMEZONE', 'America/Mexico_City');
}

/** "Mantenimientos Rivera, S.A." -> "mantenimientos-rivera" (único en empresas.slug). */
function slugDisponible(PDO $pdo, string $nombre): string {
    // Tabla propia: iconv//TRANSLIT da resultados distintos según el sistema
    // (en Windows "á" se vuelve "'a")
    $sinAcentos = strtr(mb_strtolower($nombre), [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ç' => 'c', '&' => 'y',
    ]);
    $base = trim(preg_replace('/[^a-z0-9]+/', '-', $sinAcentos), '-') ?: 'empresa';
    $base = substr($base, 0, 50);
    $slug = $base;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM empresas WHERE slug = ?");
    for ($i = 2; ; $i++) {
        $stmt->execute([$slug]);
        if (!(int)$stmt->fetchColumn()) return $slug;
        $slug = $base . '-' . $i;
    }
}

/** Crea las tablas de una empresa a partir de la plantilla. */
function crearTablasEmpresa(PDO $pdo, int $empresaId): void {
    $sql = file_get_contents(__DIR__ . '/../database/plantilla_empresa.sql');
    $sql = str_replace('{{p}}', prefijoEmpresa($empresaId), $sql);
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $sentencia) {
        if (trim($sentencia) !== '') $pdo->exec($sentencia);
    }
}

/**
 * Da de alta una empresa completa y devuelve su id.
 * $datos: nombre, estado (prueba|activa|demo), admin_nombre, admin_usuario,
 *         admin_password, admin_telefono, contacto_correo, contacto_telefono,
 *         marca (opcional), registro_ip (opcional, registro autoservicio)
 * Las tablas se crean fuera de transacción (CREATE TABLE confirma la transacción
 * en MySQL); si algo falla, eliminarEmpresa() deja todo limpio.
 */
/** El correo del administrador ya tiene cuenta (registro, panel o reintento). */
class CuentaExistente extends InvalidArgumentException {}

function crearEmpresa(PDO $pdo, array $datos): int {
    // Q-3 · MT-5: dos altas simultáneas con el mismo correo (registro, panel o un reintento) no deben
    // crear dos empresas. El índice único de usuarios es por empresa (D-36): se serializa con un candado
    // con nombre (las tablas se crean fuera de transacción, así que FOR UPDATE no alcanza).
    // (S2-1: con la base de datos en el nombre, otra instancia del mismo MySQL no choca)
    $candado = 'sd-alta-' . md5((string)env('DB_NAME', '') . '|' . strtolower(trim((string)($datos['admin_usuario'] ?? ''))));
    $st = $pdo->prepare('SELECT GET_LOCK(?, 15)');
    $st->execute([$candado]);
    if ((int)$st->fetchColumn() !== 1) throw new RuntimeException('No se pudo reservar el alta: inténtalo de nuevo.');
    try {
        return crearEmpresaSinCandado($pdo, $datos);
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$candado]);
    }
}

function crearEmpresaSinCandado(PDO $pdo, array $datos): int {
    $nombre = trim((string)($datos['nombre'] ?? ''));
    $usuario = strtolower(trim((string)($datos['admin_usuario'] ?? '')));
    $password = (string)($datos['admin_password'] ?? '');
    $estado = $datos['estado'] ?? 'prueba';
    if ($nombre === '' || $usuario === '' || strlen($password) < 8) {
        throw new InvalidArgumentException('Nombre de empresa, usuario y contraseña (8+ caracteres) son obligatorios.');
    }
    if (!in_array($estado, ESTADOS_EMPRESA, true)) {
        throw new InvalidArgumentException("Estado no válido: $estado");
    }
    // MT-5: el correo de acceso es único en toda la plataforma (como usuario o como correo de alguien)
    $existe = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE LOWER(usuario) = ? OR LOWER(correo) = ?");
    $existe->execute([$usuario, $usuario]);
    if ((int)$existe->fetchColumn()) {
        throw new CuentaExistente('Ya existe una cuenta con ese correo o usuario.');
    }

    $marca = array_replace_recursive(marcaPorDefecto($nombre), $datos['marca'] ?? []);
    $pdo->prepare(
        "INSERT INTO empresas (slug, nombre, estado, prueba_hasta, marca, contacto_nombre, contacto_correo, contacto_telefono,
                               registro_ip, plan_id, ciclo)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        slugDisponible($pdo, $nombre), $nombre, $estado,
        $estado === 'prueba' ? date('Y-m-d', strtotime('+' . diasConfig('dias_prueba') . ' days')) : null,
        json_encode($marca, JSON_UNESCAPED_UNICODE),
        $datos['admin_nombre'] ?? null, $datos['contacto_correo'] ?? $usuario, $datos['contacto_telefono'] ?? null,
        $datos['registro_ip'] ?? null,
        (int)($datos['plan_id'] ?? 0) ?: ((int)configPlataforma('plan_prueba') ?: null),
        ($datos['ciclo'] ?? 'mensual') === 'anual' ? 'anual' : 'mensual',
    ]);
    $empresaId = (int)$pdo->lastInsertId();

    try {
        crearTablasEmpresa($pdo, $empresaId);
        $pdo->prepare(
            "INSERT INTO usuarios (empresa_id, nombre, correo, telefono, usuario, password, rol)
             VALUES (?, ?, ?, ?, ?, ?, 'Administrador')"
        )->execute([
            $empresaId, $datos['admin_nombre'] ?? 'Administrador',
            $datos['contacto_correo'] ?? $usuario, $datos['admin_telefono'] ?? '',
            $usuario, password_hash($password, PASSWORD_DEFAULT),
        ]);
        foreach (['uploads', 'manuales'] as $base) {
            $dir = __DIR__ . '/../' . $base . '/e' . $empresaId;
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
        }
    } catch (Throwable $e) {
        eliminarEmpresa($pdo, $empresaId);
        throw $e;
    }
    return $empresaId;
}

/** Borra una empresa por completo: tablas, usuarios, pagos, analíticas y archivos. */
function eliminarEmpresa(PDO $pdo, int $empresaId): void {
    if ($empresaId <= 0) return;
    $prefijo = prefijoEmpresa($empresaId);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (TABLAS_EMPRESA as $tabla) {
        $pdo->exec("DROP TABLE IF EXISTS `{$prefijo}{$tabla}`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    foreach (['usuarios', 'pagos', 'analytics_eventos'] as $tabla) {
        $pdo->prepare("DELETE FROM `$tabla` WHERE empresa_id = ?")->execute([$empresaId]);
    }
    $pdo->prepare("DELETE FROM empresas WHERE id = ?")->execute([$empresaId]);
    try {
        $pdo->prepare("DELETE FROM solicitudes_pago WHERE empresa_id = ?")->execute([$empresaId]);
    } catch (Throwable $e) {
        // Sin la migración 004 no hay solicitudes
    }
    foreach (['uploads', 'manuales', 'storage/comprobantes'] as $base) {
        borrarCarpeta(__DIR__ . '/../' . $base . '/e' . $empresaId);
    }
}

function borrarCarpeta(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}
