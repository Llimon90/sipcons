<?php
// Solid Link v1 (docs/solid-link.md): Solid Admin administra las empresas de esta instancia.
// URL pública: /api/solid/v1/<ruta>  (el .htaccess la dirige aquí con ?ruta=)
// Autenticación: Authorization: Bearer <SOLID_LINK_TOKEN del .env>. Sin token configurado, responde 404.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/plataforma.php';
require_once __DIR__ . '/../config/solid_link.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function solidResponder(array $d, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}
function solidError(string $msg, int $codigo, string $code): void { solidResponder(['error' => $msg, 'code' => $code], $codigo); }

if (!solidSatelite()) solidError('No encontrado', 404, 'NOT_FOUND');
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? '') : '');
if (!solidTokenValido((string)$auth)) solidError('Token inválido', 401, 'UNAUTHORIZED');

$ruta = trim((string)($_GET['ruta'] ?? ''), '/');
$metodo = $_SERVER['REQUEST_METHOD'];
$cuerpo = in_array($metodo, ['POST', 'PUT'], true) ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];
$GLOBALS['_SESSION'] = ['nombre' => 'Solid Admin'];

try {
    if ($metodo === 'GET' && $ruta === 'info') {
        solidResponder(['producto' => 'servidesk', 'nombre' => 'Servidesk', 'version' => trim(explode(' ', (string)@file_get_contents(__DIR__ . '/../version.txt'))[1] ?? ''),
            'contrato' => 1, 'modo' => 'cloud', 'limites' => [['clave' => 'usuarios', 'nombre' => 'Usuarios']], 'cuentas' => true, 'licencias_locales' => false]);
    }
    if ($metodo === 'GET' && $ruta === 'cuentas') {
        $filas = $pdo->query("SELECT * FROM empresas WHERE estado <> 'demo' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        solidResponder(['cuentas' => array_map(fn($e) => solidCuenta($pdo, $e), $filas)]);
    }
    if ($metodo === 'POST' && $ruta === 'cuentas') {
        $r = solidCrearCuenta($pdo, $cuerpo);
        solidResponder($r, 201);
    }
    if (preg_match('#^cuentas/([a-z0-9-]+)(/restablecer-acceso)?$#', $ruta, $m)) {
        $e = solidEmpresaPorSlug($pdo, $m[1]);
        if (!$e) solidError('La cuenta no existe', 404, 'NOT_FOUND');
        if (!empty($m[2]) && $metodo === 'POST') solidResponder(['acceso' => solidRestablecerAcceso($pdo, $e)]);
        if (empty($m[2]) && $metodo === 'GET') solidResponder(solidCuenta($pdo, $e));
        if (empty($m[2]) && $metodo === 'PUT') {
            solidAplicar($pdo, $e, $cuerpo);
            solidResponder(solidCuenta($pdo, solidEmpresaPorSlug($pdo, $m[1])));
        }
    }
    if (str_starts_with($ruta, 'licencias')) solidError('Servidesk no tiene licencias locales', 404, 'NOT_FOUND');
    solidError('Ruta no válida', 404, 'NOT_FOUND');
} catch (SolidConflicto $e) {
    solidError($e->getMessage(), 409, 'CONFLICT');  // Q-3: el alta ya existe
} catch (InvalidArgumentException $e) {
    solidError($e->getMessage(), 400, 'INVALID');
} catch (Throwable $e) {
    error_log('Solid Link: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    solidError('Error interno', 500, 'ERROR');
}
