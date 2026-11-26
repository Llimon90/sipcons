<?php
// Ejecuta un endpoint real del backend en un proceso aparte, como si llegara
// por HTTP, con la sesión que indique la prueba. Lo usa llamar() de tests/lib.php
// para probar permisos y reglas contra el backend (no contra la interfaz).
//
//   php tests/endpoint_runner.php <peticion.json>
//   {"script":"backend/actualiza.php","metodo":"POST","get":{},"post":{},
//    "json":null,"sesion":{"user_id":1,"rol":"Técnico","empresa_id":7,...}}
//
// Imprime la salida del endpoint seguida de "\n@@HTTP <código>".
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$p = json_decode((string)file_get_contents($argv[1] ?? ''), true);
if (!is_array($p) || empty($p['script'])) { fwrite(STDERR, "Petición inválida\n"); exit(2); }

$raiz = realpath(__DIR__ . '/..');
$script = realpath($raiz . '/' . $p['script']);
if (!$script || strpos($script, $raiz . DIRECTORY_SEPARATOR) !== 0) { fwrite(STDERR, "Script fuera del proyecto\n"); exit(2); }

// Cuerpo JSON en php://input: se sustituye el envoltorio php:// solo para "input".
final class EntradaFalsa {
    public static string $cuerpo = '';
    private int $pos = 0;
    private $real = null;
    public $context;
    public function stream_open($ruta, $modo, $opciones, &$abierta) {
        if (strtolower($ruta) === 'php://input') return true;
        stream_wrapper_restore('php');
        $this->real = fopen($ruta, $modo);
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', self::class);
        return $this->real !== false;
    }
    public function stream_read($n) {
        if ($this->real) return fread($this->real, $n);
        $r = substr(self::$cuerpo, $this->pos, $n); $this->pos += strlen($r); return $r;
    }
    public function stream_write($d) { return $this->real ? fwrite($this->real, $d) : 0; }
    public function stream_eof() { return $this->real ? feof($this->real) : $this->pos >= strlen(self::$cuerpo); }
    public function stream_close() { if ($this->real) fclose($this->real); }
    public function stream_stat() { return []; }
    public function stream_flush() { return $this->real ? fflush($this->real) : true; }
}

$metodo = strtoupper($p['metodo'] ?? 'GET');
$_SERVER['REQUEST_METHOD'] = $metodo;
$_SERVER['SCRIPT_NAME'] = '/' . str_replace('\\', '/', $p['script']);
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET = $p['get'] ?? [];
$_POST = $p['post'] ?? [];
$_REQUEST = $_GET + $_POST;
if (isset($p['json'])) {
    EntradaFalsa::$cuerpo = json_encode($p['json'], JSON_UNESCAPED_UNICODE);
    $_SERVER['CONTENT_TYPE'] = 'application/json';
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', EntradaFalsa::class);
} elseif ($_POST) {
    $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=prueba';
}

// Sesión: se guarda en la misma carpeta que usa config/auth.php y se pasa por cookie.
$rutaSesiones = $raiz . '/storage/sessions';
if (is_dir($rutaSesiones) && is_writable($rutaSesiones)) session_save_path($rutaSesiones);
session_id('prueba' . bin2hex(random_bytes(12)));
session_start();
$_SESSION = $p['sesion'] ?? [];
session_write_close();
$_COOKIE[session_name()] = session_id();
$_SESSION = [];

// Para probar cabeceras: la sesión puede pedir que se vuelquen a un archivo.
$volcarCabeceras = $p['sesion']['__volcar_cabeceras'] ?? null;
// Respuestas binarias (PDF, ZIP): en Windows la tubería corta en el byte 0x1A; se devuelven en base64
$binario = !empty($p['sesion']['__binario']);
if ($binario) ob_start();
$idSesion = session_id();
register_shutdown_function(function () use ($volcarCabeceras, $rutaSesiones, $idSesion, $binario) {
    $codigo = http_response_code() ?: 200;
    if ($binario) { $b = ''; while (ob_get_level()) $b = ob_get_clean() . $b; echo '@@B64 ' . base64_encode($b); }
    if ($volcarCabeceras) file_put_contents($volcarCabeceras, implode("\n", headers_list()));
    echo "\n@@HTTP $codigo";
    @unlink($rutaSesiones . '/sess_' . $idSesion);  // aunque el endpoint haya cerrado la sesión
});

chdir(dirname($script));
require $script;
