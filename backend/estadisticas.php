<?php
// Configuración de conexión
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Incluir el archivo de conexión
require_once 'conexion.php';

try {
    // Obtener estadísticas de incidencias
    if ($_GET['action'] == 'estadisticas_incidencias') {
        
        // Total de incidencias
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM incidencias");
        $total_incidencias = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Incidencias por estatus
        $stmt = $pdo->query("
            SELECT 
                CASE 
                    WHEN estatus IS NULL OR estatus = '' THEN 'Sin estatus'
                    ELSE estatus
                END as estatus, 
                COUNT(*) as cantidad 
            FROM incidencias 
            GROUP BY estatus
            ORDER BY cantidad DESC
        ");
        $incidencias_estatus = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Incidencias por técnico
        $stmt = $pdo->query("
            SELECT 
                CASE 
                    WHEN tecnico IS NULL OR tecnico = '' THEN 'Sin técnico'
                    ELSE tecnico
                END as tecnico, 
                COUNT(*) as cantidad 
            FROM incidencias 
            GROUP BY tecnico
            ORDER BY cantidad DESC
        ");
        $incidencias_tecnico = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Incidencias por sucursal
        $stmt = $pdo->query("
            SELECT 
                CASE 
                    WHEN sucursal IS NULL OR sucursal = '' THEN 'Sin sucursal'
                    ELSE sucursal
                END as sucursal, 
                COUNT(*) as cantidad 
            FROM incidencias 
            GROUP BY sucursal
            ORDER BY cantidad DESC
        ");
        $incidencias_sucursal = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Incidencias por mes (últimos 6 meses)
        $stmt = $pdo->query("
            SELECT 
                DATE_FORMAT(fecha, '%Y-%m') as mes,
                COUNT(*) as cantidad 
            FROM incidencias 
            WHERE fecha >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(fecha, '%Y-%m')
            ORDER BY mes
        ");
        $incidencias_mensuales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Top 5 clientes con más incidencias
        $stmt = $pdo->query("
            SELECT 
                CASE 
                    WHEN cliente IS NULL OR cliente = '' THEN 'Sin cliente'
                    ELSE cliente
                END as cliente, 
                COUNT(*) as cantidad 
            FROM incidencias 
            GROUP BY cliente 
            ORDER BY cantidad DESC 
            LIMIT 5
        ");
        $top_clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'total_incidencias' => $total_incidencias['total'],
                'por_estatus' => $incidencias_estatus,
                'por_tecnico' => $incidencias_tecnico,
                'por_sucursal' => $incidencias_sucursal,
                'mensuales' => $incidencias_mensuales,
                'top_clientes' => $top_clientes
            ]
        ]);
        
    } elseif ($_GET['action'] == 'estadisticas_generales') {
        
        // Estadísticas generales del sistema
        $stats = [];
        
        // Total de clientes
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM clientes");
        $stats['total_clientes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Total de usuarios
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios");
        $stats['total_usuarios'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Total de incidencias
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM incidencias");
        $stats['total_incidencias'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Incidencias completadas este mes
        $stmt = $pdo->query("
            SELECT COUNT(*) as total 
            FROM incidencias 
            WHERE estatus = 'completado' 
            AND MONTH(fecha) = MONTH(CURDATE()) 
            AND YEAR(fecha) = YEAR(CURDATE())
        ");
        $stats['incidencias_resueltas_mes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Incidencias pendientes
        $stmt = $pdo->query("
            SELECT COUNT(*) as total 
            FROM incidencias 
            WHERE estatus NOT IN ('completado', 'cerrado con factura', 'cerrado sin factura')
            OR estatus IS NULL
        ");
        $stats['incidencias_pendientes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Estadísticas adicionales por estatus
        $stmt = $pdo->query("
            SELECT estatus, COUNT(*) as cantidad 
            FROM incidencias 
            GROUP BY estatus
        ");
        $stats['detalle_estatus'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Tiempo promedio de resolución (en días)
        $stmt = $pdo->query("
            SELECT AVG(DATEDIFF(
                COALESCE(fecha_cierre, CURDATE()), 
                fecha
            )) as tiempo_promedio 
            FROM incidencias 
            WHERE fecha IS NOT NULL
        ");
        $tiempo_promedio = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['tiempo_promedio'] = round($tiempo_promedio['tiempo_promedio'] ?? 0) . 'd';
        
        // Tendencia vs mes anterior
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as actual,
                (SELECT COUNT(*) FROM incidencias 
                 WHERE MONTH(fecha) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) 
                 AND YEAR(fecha) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)))
                as anterior
            FROM incidencias 
            WHERE MONTH(fecha) = MONTH(CURDATE()) 
            AND YEAR(fecha) = YEAR(CURDATE())
        ");
        $tendencia = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $actual = $tendencia['actual'] ?? 0;
        $anterior = $tendencia['anterior'] ?? 0;
        
        if ($anterior > 0) {
            $stats['tendencia_incidencias'] = round((($actual - $anterior) / $anterior) * 100);
        } else {
            $stats['tendencia_incidencias'] = $actual > 0 ? 100 : 0;
        }
        
        echo json_encode([
            'success' => true,
            'data' => $stats
        ]);
        
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error de base de datos: ' . $e->getMessage()
    ]);
}
?>