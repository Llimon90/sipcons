<?php
function loadEnv(string $path): bool {
    if (!file_exists($path)) return false;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key !== '') {
            // Solo $_ENV (memoria por-petición). NUNCA putenv(): en PHP-FPM
            // putenv() persiste en el proceso worker entre peticiones, y si
            // este mismo worker atiende despues una peticion de OTRO entorno
            // (ej. produccion) que no tenga su propio .env, heredaria estas
            // credenciales por error.
            $_ENV[$key] = $value;
        }
    }
    return true;
}

if (!loadEnv(__DIR__ . '/../.env')) {
    die(json_encode(["error" => "Falta el archivo .env de este entorno. No se puede continuar sin configuracion explicita de base de datos."]));
}

$host     = $_ENV['DB_HOST'] ?? 'localhost';
$user     = $_ENV['DB_USER'] ?? '';
$password = $_ENV['DB_PASS'] ?? '';
$database = $_ENV['DB_NAME'] ?? '';

// Conexión MySQLi (compatibilidad con archivos existentes)
$conn = new mysqli($host, $user, $password, $database);
if ($conn->connect_error) {
    die(json_encode(["error" => "Error de conexión: " . $conn->connect_error]));
}
$conn->set_charset('utf8mb4');

// Conexión PDO (compatibilidad con archivos existentes)
try {
    $pdo = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    die(json_encode(['error' => 'Error de conexión PDO: ' . $e->getMessage()]));
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

        try {
            $this->conn = new PDO(
                "mysql:host=$host;dbname=$name;charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
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
