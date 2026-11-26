<?php
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/empresa.php';

// Conexiones con aislamiento por empresa: PdoEmpresa y MysqliEmpresa pasan
// cada consulta por sqlConEmpresa() (config/empresa.php), que antepone el
// prefijo de la empresa activa a las tablas de empresa. Todo el código existente
// usa $pdo / $conn / Database sin cambios y queda aislado automáticamente.

class PdoEmpresa extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(sqlConEmpresa($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        return $fetchMode === null
            ? parent::query(sqlConEmpresa($query))
            : parent::query(sqlConEmpresa($query), $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false {
        return parent::exec(sqlConEmpresa($statement));
    }
}

class MysqliEmpresa extends mysqli {
    public function prepare(string $query): mysqli_stmt|false {
        return parent::prepare(sqlConEmpresa($query));
    }

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool {
        return parent::query(sqlConEmpresa($query), $result_mode);
    }

    public function real_query(string $query): bool {
        return parent::real_query(sqlConEmpresa($query));
    }

    public function multi_query(string $query): bool {
        return parent::multi_query(sqlConEmpresa($query));
    }
}

// Zona horaria: config/env.php fija la de la instancia (APP_TIMEZONE); cuando se
// activa una empresa, establecerEmpresa() aplica la suya. Se replica en MySQL
// con el offset numérico porque no todo hosting compartido tiene cargadas las
// tablas de husos horarios con nombre.
$offsetLocal = (new DateTime('now'))->format('P');

$host     = $_ENV['DB_HOST'] ?? 'localhost';
$user     = $_ENV['DB_USER'] ?? '';
$password = $_ENV['DB_PASS'] ?? '';
$database = $_ENV['DB_NAME'] ?? '';
$port     = (int)($_ENV['DB_PORT'] ?? 3306);

/**
 * Mensaje de una excepción que se puede mostrar al usuario. Los errores de la
 * base de datos (estructura, SQL, conexión) solo van al log del servidor: al
 * usuario le llega un texto genérico. Las validaciones de negocio
 * (InvalidArgumentException, RuntimeException...) conservan su mensaje.
 */
function mensajeErrorPublico(Throwable $e, string $generico = 'Ocurrió un error al procesar la solicitud. Inténtalo de nuevo; si persiste, avísanos.'): string {
    if ($e instanceof PDOException || $e instanceof mysqli_sql_exception) {
        error_log(get_class($e) . ' en ' . basename($e->getFile()) . ':' . $e->getLine() . ' - ' . $e->getMessage());
        return $generico;
    }
    return $e->getMessage();
}

function errorConexionBd(string $detalle): void {
    error_log('Error de conexión a la base de datos: ' . $detalle);
    http_response_code(503);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'El servicio no está disponible en este momento. Inténtalo en unos minutos.',
        'mensaje' => 'El servicio no está disponible en este momento. Inténtalo en unos minutos.', 'success' => false, 'exito' => false], JSON_UNESCAPED_UNICODE));
}

/**
 * Abre una conexión reintentando cuando MySQL la rechaza por límite de conexiones
 * (1040 Too many connections, 1203 max_user_connections): en hosting compartido el
 * límite es bajo y una página que hace varias peticiones a la vez lo rebasa por
 * instantes. Otros errores (contraseña, servidor caído) se lanzan sin reintentar.
 * $esperaMs crece en cada intento (150, 300, 450 ms...).
 */
function conectarConReintentos(callable $conectar, int $intentos = 6, int $esperaMs = 150) {
    for ($i = 1; ; $i++) {
        try {
            return $conectar();
        } catch (mysqli_sql_exception | PDOException $e) {
            // Bug 2026-11-25: en Hostinger el rechazo por carga no siempre llega como 1040/1203,
            // así que se reintenta todo salvo los errores permanentes (acceso, base o servidor inexistente)
            $codigo = (int)$e->getCode();
            if (preg_match('/\[(\d{4})\]/', $e->getMessage(), $m)) $codigo = (int)$m[1];  // PDO: SQLSTATE[HY000] [1045]
            $permanente = in_array($codigo, [1044, 1045, 1049, 1698, 2005], true)
                || preg_match('/access denied|unknown database/i', $e->getMessage());
            if ($permanente || $i >= $intentos) throw $e;
            if ($esperaMs > 0) usleep(($esperaMs * $i + random_int(0, $esperaMs)) * 1000);
        }
    }
}

// Conexión MySQLi (compatibilidad con archivos existentes)
try {
    $conn = conectarConReintentos(function () use ($host, $user, $password, $database, $port) {
        $c = @new MysqliEmpresa($host, $user, $password, $database, $port);
        // Sin reporte de excepciones, mysqli solo deja connect_error: también se reintenta
        if ($c->connect_error) throw new mysqli_sql_exception($c->connect_error, (int)$c->connect_errno);
        return $c;
    });
} catch (mysqli_sql_exception $e) {
    errorConexionBd($e->getMessage());
}
if ($conn->connect_error) {
    errorConexionBd($conn->connect_error);
}
$conn->set_charset('utf8mb4');
@$conn->query("SET time_zone = '$offsetLocal'");

// Conexión PDO (compatibilidad con archivos existentes)
try {
    $pdo = conectarConReintentos(fn() => new PdoEmpresa("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4", $user, $password));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    try { $pdo->exec("SET time_zone = '$offsetLocal'"); } catch (PDOException $e) { error_log('No se pudo fijar time_zone en PDO: ' . $e->getMessage()); }
} catch (PDOException $e) {
    errorConexionBd($e->getMessage());
}

// Clase Database (compatibilidad con archivos existentes)
if (!class_exists('Database')):
class Database {
    private PDO $conn;

    public function __construct() {
        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $user = $_ENV['DB_USER'] ?? '';
        $pass = $_ENV['DB_PASS'] ?? '';
        $name = $_ENV['DB_NAME'] ?? '';
        $port = (int)($_ENV['DB_PORT'] ?? 3306);

        try {
            $this->conn = new PdoEmpresa(
                "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            $offset = (new DateTime('now'))->format('P');
            $this->conn->exec("SET time_zone = '$offset'");
        } catch (PDOException $e) {
            error_log("Error de conexión: " . $e->getMessage());
            throw new Exception("Error de conexión a la base de datos");
        }
    }

    public function getConnection(): PDO {
        return $this->conn;
    }
}
endif;

// Headers de API definidos en auth/middleware.php
