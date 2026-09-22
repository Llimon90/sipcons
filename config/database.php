<?php
function loadEnv(string $path): void {
    if (!file_exists($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key !== '') {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

loadEnv(__DIR__ . '/../.env');

// La operación es en Tijuana (Baja California), no en el huso horario por
// defecto del hosting (normalmente CDMX). Se fija aquí, en el punto central
// por el que pasa casi toda la app, para que date()/time() en PHP y
// NOW()/CURRENT_TIMESTAMP() en MySQL (incluida la columna auditoria.creado_en)
// coincidan con la hora local. Se usa el offset numérico (-08:00/-07:00 según
// horario de verano) en vez del nombre de la zona porque no todo hosting
// compartido tiene cargadas las tablas de huso horario con nombre de MySQL.
date_default_timezone_set('America/Tijuana');
$offsetTijuana = (new DateTime('now', new DateTimeZone('America/Tijuana')))->format('P');

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
@$conn->query("SET time_zone = '$offsetTijuana'");

// Conexión PDO (compatibilidad con archivos existentes)
try {
    $pdo = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    try { $pdo->exec("SET time_zone = '$offsetTijuana'"); } catch (PDOException $e) { error_log('No se pudo fijar time_zone en PDO: ' . $e->getMessage()); }
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
            $offset = (new DateTime('now', new DateTimeZone('America/Tijuana')))->format('P');
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
