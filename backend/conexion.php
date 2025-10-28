<?php
// Configuración para MySQLi (si aún la necesitas para otras partes)
$host = "localhost";
$user = "sipcons1_test";
$password = "sip*SYS2025";
$database = "sipcons1_sipcons_test";

// Conexión MySQLi
$conn = new mysqli($host, $user, $password, $database);
if ($conn->connect_error) {
    die(json_encode(["error" => "Error de conexión MySQLi: " . $conn->connect_error]));
}

// Conexión PDO
try {
    $pdo = new PDO("mysql:host=$host;dbname=$database;charset=utf8", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    die(json_encode(['error' => 'Error de conexión PDO: ' . $e->getMessage()]));
}

// Clase Database para el nuevo sistema
class Database {
    private $host = "localhost";
    private $user = "sipcons1_test";
    private $password = "sip*SYS2025";
    private $database = "sipcons1_sipcons_test";
    private $conn;

    public function __construct() {
        try {
            $this->conn = new PDO(
                "mysql:host={$this->host};dbname={$this->database};charset=utf8mb4",
                $this->user,
                $this->password,
                array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                )
            );
        } catch(PDOException $e) {
            error_log("Error de conexión a la base de datos: " . $e->getMessage());
            throw new Exception("Error de conexión a la base de datos");
        }
    }

    public function getConnection() {
        return $this->conn;
    }
}

// Configuración de cabeceras comunes
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
?>