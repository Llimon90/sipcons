<?php
// TEST TEMPORAL — eliminar despues
require_once __DIR__ . '/../auth/middleware.php';

$filtro = '%' . ($_GET['q'] ?? '') . '%';

$stmt = $conn->prepare("SELECT id, nombre FROM clientes WHERE nombre LIKE ? LIMIT 5");
$stmt->bind_param('s', $filtro);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}

echo json_encode([
    'session_user' => $_SESSION['usuario'] ?? 'NO SESSION',
    'total'        => count($rows),
    'muestra'      => $rows,
]);
