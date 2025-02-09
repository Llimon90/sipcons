<?php
// Habilitar el manejo de errores para debug
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configuración de la conexión a la base de datos
$host = "localhost";
$dbname = "u179371012_sipcons";
$username = "u179371012_root";
$password = "Llimon2025";

try {
    // Crear una conexión PDO
    $conexion = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conexion->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    // Configurar encabezados para permitir CORS
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST");
    header("Content-Type: application/json");

    // Leer el cuerpo de la solicitud
    $input = json_decode(file_get_contents("php://input"), true);

    // Verificar que todos los campos necesarios estén presentes
    if (
        !isset($input["cliente"]) || !isset($input["contacto"]) || 
        !isset($input["sucursal"]) || !isset($input["fecha"]) || 
        !isset($input["tecnico"]) || !isset($input["status"]) || 
        !isset($input["falla"])
    ) {
        echo json_encode(["error" => "Todos los campos son obligatorios"]);
        http_response_code(400);
        exit();
    }

    // Extraer los valores del cuerpo de la solicitud
    $cliente = $input["cliente"];
    $contacto = $input["contacto"];
    $sucursal = $input["sucursal"];
    $fecha = $input["fecha"];
    $tecnico = $input["tecnico"];
    $status = $input["status"];
    $falla = $input["falla"];

    // Obtener el último número de incidencia
    $consultaUltimoNumero = "SELECT numero FROM incidencias ORDER BY id DESC LIMIT 1";
    $stmt = $conexion->prepare($consultaUltimoNumero);
    $stmt->execute();
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

    // Generar el nuevo número de incidencia
    $nuevoNumero = "SIP-0001"; // Número inicial si no hay registros previos
    if ($resultado) {
        $ultimoNumero = $resultado["numero"]; // Ejemplo: "SIP-0001"
        $numeroIncremental = intval(explode("-", $ultimoNumero)[1]) + 1;
        $nuevoNumero = "SIP-" . str_pad($numeroIncremental, 4, "0", STR_PAD_LEFT);
    }

    // Insertar la nueva incidencia
    $insertarIncidencia = "
        INSERT INTO incidencias (numero, cliente, contacto, sucursal, fecha, tecnico, estatus, falla)
        VALUES (:numero, :cliente, :contacto, :sucursal, :fecha, :tecnico, :estatus, :falla)
    ";
    $stmt = $conexion->prepare($insertarIncidencia);
    $stmt->execute([
        ":numero" => $nuevoNumero,
        ":cliente" => $cliente,
        ":contacto" => $contacto,
        ":sucursal" => $sucursal,
        ":fecha" => $fecha,
        ":tecnico" => $tecnico,
        ":estatus" => $status,
        ":falla" => $falla,
    ]);

    // Respuesta de éxito
    echo json_encode([
        "message" => "Incidencia registrada correctamente",
        "numero" => $nuevoNumero,
        "id" => $conexion->lastInsertId()
    ]);
    http_response_code(201);

} catch (PDOException $e) {
    // Manejo de errores de la base de datos
    echo json_encode(["error" => "Error de servidor: " . $e->getMessage()]);
    http_response_code(500);
    exit();
}
?>
