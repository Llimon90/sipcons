<?php
// Configurar conexión con la base de datos
require_once 'conexion.php';

// Verificar conexión
if ($conn->connect_error) {
    die(json_encode(["error" => "Error de conexión: " . $conn->connect_error]));
}

// Recibir los parámetros de búsqueda y sanitizarlos
$cliente = isset($_GET['cliente']) ? trim($_GET['cliente']) : '';
$fecha_inicio = isset($_GET['fecha_inicio']) ? trim($_GET['fecha_inicio']) : '';
$fecha_fin = isset($_GET['fecha_fin']) ? trim($_GET['fecha_fin']) : '';
$estatus = isset($_GET['estatus']) ? trim($_GET['estatus']) : '';
$sucursal = isset($_GET['sucursal']) ? trim($_GET['sucursal']) : '';
$tecnico = isset($_GET['tecnico']) ? trim($_GET['tecnico']) : '';
$tipo_equipo = isset($_GET['tipo_equipo']) ? trim($_GET['tipo_equipo']) : '';
$solo_activas = isset($_GET['solo_activas']) ? trim($_GET['solo_activas']) : '';

// Construir la consulta SQL con `prepared statements`
$sql = "SELECT * FROM incidencias WHERE 1";

$params = [];
$types = "";

if (!empty($cliente) && $cliente !== 'todos') {
    $sql .= " AND cliente = ?";
    $params[] = $cliente;
    $types .= "s";
}

if (!empty($fecha_inicio)) {
    $sql .= " AND fecha >= ?";
    $params[] = $fecha_inicio;
    $types .= "s";
}

if (!empty($fecha_fin)) {
    $sql .= " AND fecha <= ?";
    $params[] = $fecha_fin;
    $types .= "s";
}

if (!empty($estatus)) {
    $sql .= " AND estatus = ?";
    $params[] = $estatus;
    $types .= "s";
}

if (!empty($sucursal)) {
    $sql .= " AND sucursal LIKE ?";
    $params[] = "%$sucursal%";
    $types .= "s";
}

if (!empty($tecnico)) {
    $sql .= " AND tecnico LIKE ?";
    $params[] = "%$tecnico%";
    $types .= "s";
}

// Filtrar por incidencias activas (Abierto, Asignado, Pendiente, Completado)
if (!empty($solo_activas) && $solo_activas === '1') {
    $sql .= " AND estatus IN ('Abierto', 'Asignado', 'Pendiente', 'Completado')";
}

// Búsqueda por palabras clave según el tipo de equipo
if (!empty($tipo_equipo)) {
    $palabras_clave = [];
    
    switch($tipo_equipo) {
        case 'mr-tienda':
            $palabras_clave = [
                "cajon de dinero", "gaveta", "mr tienda", "mr chef", "nube", 
                "back office", "capacitacion", "escaner", "pos", "punto de venta", 
                "jose lopez"
            ];
            break;
        case 'plataforma':
            $palabras_clave = [
                "iqy", "ipes", "celda", "baccula", "plaba", "cas", "plaba-12", 
                "indicador", "calibracion", "celda de carga", "florido"
            ];
            break;
        case 'bascula':
            $palabras_clave = [
                "bpro", "bplus", "bcom s", "bcoms", "etiquetadora", "impresora", 
                "cabeza termica", "cabezal", "mecanismo", "sensor", "plato", 
                "display", "florido", "rodillo", "etiquetas", "etiqueta", 
                "interfaz", "head", "muelle", "teclado", "membrana", "nodo"
            ];
            break;
        case 'pos':
            $palabras_clave = [
                "pos", "punto de venta", "terminal", "tarjeta", "pinpad", 
                "lector", "codigo de barras", "facturacion", "ticket", "caja registradora"
            ];
            break;
        case 'mr-chef':
            $palabras_clave = [
                "mr chef", "cocina", "restaurante", "comanda", "menu", 
                "inventario", "receta", "mesero", "chef"
            ];
            break;
        case 'otros':
            // Para "otros", no aplicamos filtro por palabras clave
            break;
    }
    
    // Si hay palabras clave para el tipo seleccionado, agregar condición a la consulta
    if (!empty($palabras_clave)) {
        $condiciones_keywords = [];
        foreach ($palabras_clave as $keyword) {
            $condiciones_keywords[] = "(falla LIKE ? OR accion LIKE ? OR notas LIKE ?)";
            for ($i = 0; $i < 3; $i++) {
                $params[] = "%$keyword%";
                $types .= "s";
            }
        }
        
        if (!empty($condiciones_keywords)) {
            $sql .= " AND (" . implode(" OR ", $condiciones_keywords) . ")";
        }
    }
}

// Agregar orden de más reciente a más antiguo
$sql .= " ORDER BY id DESC";

// Preparar y ejecutar la consulta
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$incidencias = [];
while ($row = $result->fetch_assoc()) {
    $incidencias[] = $row;
}

echo json_encode($incidencias ?: ["message" => "No se encontraron datos"]);

// Cerrar la conexión
$stmt->close();
$conn->close();
?>