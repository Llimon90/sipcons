<?php
// Configurar conexión con la base de datos
$host = "localhost";
$user = "u179371012_root";
$password = "Llimon.2025";
$database = "u179371012_sipcons";

// Crear conexión
$conn = new mysqli($host, $user, $password, $database);

// Verificar conexión
if ($conn->connect_error) {
    die(json_encode(["error" => "Error de conexión: " . $conn->connect_error]));
}

// Recibir los parámetros de búsqueda (evitar valores nulos)
$cliente = isset($_GET['cliente']) && $_GET['cliente'] !== 'todos' ? $_GET['cliente'] : null;
$fecha_inicio = !empty($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : null;
$fecha_fin = !empty($_GET['fecha_fin']) ? $_GET['fecha_fin'] : null;
$estatus = !empty($_GET['estatus']) ? $_GET['estatus'] : null;
$sucursal = !empty($_GET['sucursal']) ? $_GET['sucursal'] : null;

// Construir la consulta SQL
$sql = "SELECT * FROM incidencias WHERE 1";
$params = [];
$types = "";

// Aplicar filtros solo si tienen valores
if ($cliente !== null) {
    $sql .= " AND cliente = ?";
    $params[] = &$cliente;
    $types .= "s";
}

if ($fecha_inicio !== null) {
    $sql .= " AND fecha >= ?";
    $params[] = &$fecha_inicio;
    $types .= "s";
}

if ($fecha_fin !== null) {
    $sql .= " AND fecha <= ?";
    $params[] = &$fecha_fin;
    $types .= "s";
}

if ($estatus !== null) {
    $sql .= " AND estatus = ?";
    $params[] = &$estatus;
    $types .= "s";
}

if ($sucursal !== null) {
    $sql .= " AND sucursal = ?";
    $params[] = &$sucursal;
    $types .= "s";
}

// Preparar la consulta
$stmt = $conn->prepare($sql);

if ($stmt) {
    // Si hay parámetros, enlazarlos
    if (!empty($params)) {
        array_unshift($params, $types);
        call_user_func_array([$stmt, 'bind_param'], $params);
    }

    // Ejecutar la consulta
    $stmt->execute();
    $result = $stmt->get_result();

    // Verificar si hay resultados
    if ($result->num_rows > 0) {
        $incidencias = [];
        while ($row = $result->fetch_assoc()) {
            $incidencias[] = $row;
        }
        echo json_encode($incidencias);
    } else {
        echo json_encode(["message" => "No se encontraron datos"]);
    }

    // Cerrar la consulta
    $stmt->close();
} else {
    echo json_encode(["error" => "Error en la consulta: " . $conn->error]);
}

// Cerrar conexión
$conn->close();
?>
