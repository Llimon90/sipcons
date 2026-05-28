<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Configuración de errores para desarrollo
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Incluir conexión
require_once 'conexion.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Obtener la acción desde GET o POST
    $action = $_GET['action'] ?? ($_POST['action'] ?? '');
    
    if (empty($action)) {
        echo json_encode(['success' => false, 'message' => 'No se especificó acción']);
        exit;
    }
    
    switch($action) {
        case 'get_marcas':
            getMarcas($conn);
            break;
        case 'get_modelos':
            getModelos($conn);
            break;
        case 'get_documentos':
            getDocumentos($conn);
            break;
        case 'get_modelo_info':
            getModeloInfo($conn);
            break;
        case 'add_marca':
            addMarca($conn);
            break;
        case 'add_modelo':
            addModelo($conn);
            break;
        case 'update_modelo':
            updateModelo($conn);
            break;
        case 'delete_modelo':
            deleteModelo($conn);
            break;
        case 'delete_marca':
            deleteMarca($conn);
            break;
        case 'delete_documento':
            deleteDocumento($conn);
            break;
        case 'upload_documentos':
            uploadDocumentos($conn);
            break;
        case 'buscar':
            buscarContenido($conn);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Acción no válida: ' . $action]);
    }
} catch(Exception $e) {
    error_log("Error en soporte_backend.php: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Error del servidor: ' . $e->getMessage()
    ]);
}

function getMarcas($conn) {
    try {
        // QUITADO: WHERE activo = 1
        $stmt = $conn->prepare("SELECT id, nombre FROM marcas ORDER BY nombre");
        $stmt->execute();
        $marcas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true, 
            'marcas' => $marcas,
            'count' => count($marcas)
        ]);
    } catch(PDOException $e) {
        throw new Exception("Error al obtener marcas: " . $e->getMessage());
    }
}

function getModelos($conn) {
    try {
        $marca_id = $_GET['marca_id'] ?? null;
        
        if (!$marca_id) {
            echo json_encode(['success' => false, 'message' => 'ID de marca no proporcionado']);
            return;
        }

        // QUITADO: AND m.activo = 1
        $stmt = $conn->prepare("
            SELECT m.id, m.nombre, m.tipo_equipo, ma.nombre as marca_nombre 
            FROM modelos m 
            INNER JOIN marcas ma ON m.marca_id = ma.id 
            WHERE m.marca_id = ? 
            ORDER BY m.nombre
        ");
        $stmt->execute([$marca_id]);
        $modelos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true, 
            'modelos' => $modelos,
            'count' => count($modelos)
        ]);
    } catch(PDOException $e) {
        throw new Exception("Error al obtener modelos: " . $e->getMessage());
    }
}function getModeloInfo($conn) {
    try {
        $modelo_id = $_GET['modelo_id'] ?? null;
        
        if (!$modelo_id) {
            echo json_encode(['success' => false, 'message' => 'ID de modelo no proporcionado']);
            return;
        }

        $stmt = $conn->prepare("
            SELECT m.*, ma.nombre as marca_nombre, ma.id as marca_id
            FROM modelos m 
            INNER JOIN marcas ma ON m.marca_id = ma.id 
            WHERE m.id = ? AND m.activo = 1
        ");
        $stmt->execute([$modelo_id]);
        $modelo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$modelo) {
            echo json_encode(['success' => false, 'message' => 'Modelo no encontrado']);
            return;
        }
        
        echo json_encode([
            'success' => true, 
            'modelo' => $modelo
        ]);
    } catch(PDOException $e) {
        throw new Exception("Error al obtener información del modelo: " . $e->getMessage());
    }
}

function getDocumentos($conn) {
    try {
        $modelo_id = $_GET['modelo_id'] ?? null;
        
        if (!$modelo_id) {
            echo json_encode(['success' => false, 'message' => 'ID de modelo no proporcionado']);
            return;
        }

        $stmt = $conn->prepare("
            SELECT d.*, m.nombre as modelo_nombre, ma.nombre as marca_nombre 
            FROM documentos d
            INNER JOIN modelos m ON d.modelo_id = m.id
            INNER JOIN marcas ma ON m.marca_id = ma.id
            WHERE d.modelo_id = ? 
            ORDER BY d.tipo_documento, d.nombre_archivo
        ");
        $stmt->execute([$modelo_id]);
        $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Asegurar que las rutas sean accesibles desde el frontend
        foreach ($documentos as &$doc) {
            $doc['ruta_publica'] = $doc['ruta_archivo'];
        }
        
        echo json_encode([
            'success' => true, 
            'documentos' => $documentos,
            'count' => count($documentos)
        ]);
    } catch(PDOException $e) {
        throw new Exception("Error al obtener documentos: " . $e->getMessage());
    }
}

function addMarca($conn) {
    try {
        // Leer datos JSON
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON inválido: ' . json_last_error_msg());
        }
        
        $nombre = trim($data['nombre'] ?? '');

        if (empty($nombre)) {
            echo json_encode(['success' => false, 'message' => 'El nombre de la marca es requerido']);
            return;
        }

        // Verificar si la marca ya existe (QUITADO: AND activo = 1)
        $stmt = $conn->prepare("SELECT id FROM marcas WHERE nombre = ?");
        $stmt->execute([$nombre]);
        
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'La marca ya existe']);
            return;
        }

        // Insertar marca
        $stmt = $conn->prepare("INSERT INTO marcas (nombre) VALUES (?)");
        $stmt->execute([$nombre]);
        $marca_id = $conn->lastInsertId();
        
        // Crear carpeta para la marca
        $marca_dir = '../manuales/' . sanitizeFolderName($nombre) . '/';
        if (crearEstructuraDirectorios($marca_dir)) {
            echo json_encode([
                'success' => true, 
                'message' => 'Marca agregada correctamente', 
                'id' => $marca_id,
                'ruta_marca' => $marca_dir
            ]);
        } else {
            // Si falla crear la carpeta, eliminar la marca
            $stmt = $conn->prepare("DELETE FROM marcas WHERE id = ?");
            $stmt->execute([$marca_id]);
            throw new Exception("Error al crear directorio para la marca");
        }
        
    } catch(PDOException $e) {
        throw new Exception("Error al agregar marca: " . $e->getMessage());
    }
}
function addModelo($conn) {
    try {
        // Debug: mostrar datos recibidos
        error_log("Datos POST recibidos: " . print_r($_POST, true));
        error_log("Archivos recibidos: " . print_r($_FILES, true));
        
        $marca_id = $_POST['marca_id'] ?? '';
        $nombre = trim($_POST['nombre'] ?? '');
        $tipo_equipo = trim($_POST['tipo_equipo'] ?? '');

        if (empty($marca_id) || empty($nombre) || empty($tipo_equipo)) {
            error_log("Campos faltantes - marca_id: $marca_id, nombre: $nombre, tipo_equipo: $tipo_equipo");
            echo json_encode(['success' => false, 'message' => 'Todos los campos son requeridos']);
            return;
        }

        // Obtener información de la marca
        $stmt = $conn->prepare("SELECT nombre FROM marcas WHERE id = ?");
        $stmt->execute([$marca_id]);
        $marca = $stmt->fetch();
        
        if (!$marca) {
            echo json_encode(['success' => false, 'message' => 'Marca no encontrada']);
            return;
        }

        // Verificar si el modelo ya existe para esta marca (QUITADO: AND activo = 1)
        $stmt = $conn->prepare("SELECT id FROM modelos WHERE marca_id = ? AND nombre = ?");
        $stmt->execute([$marca_id, $nombre]);
        
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'El modelo ya existe para esta marca']);
            return;
        }

        // Insertar modelo
        $stmt = $conn->prepare("INSERT INTO modelos (marca_id, nombre, tipo_equipo) VALUES (?, ?, ?)");
        $stmt->execute([$marca_id, $nombre, $tipo_equipo]);
        $modelo_id = $conn->lastInsertId();

        // Crear estructura de carpetas
        $base_dir = '../manuales/';
        
        // Sanitizar nombres para usar en carpetas
        $marca_folder = sanitizeFolderName($marca['nombre']);
        $modelo_folder = sanitizeFolderName($nombre);
        
        // Ruta completa para el modelo
        $modelo_dir = $base_dir . $marca_folder . '/' . $modelo_folder . '/';
        
        // Crear directorios si no existen
        if (!crearEstructuraDirectorios($modelo_dir)) {
            throw new Exception("No se pudo crear la estructura de directorios");
        }

        // Procesar archivos subidos
        $documentos_subidos = [];

        // Procesar manual
        if (isset($_FILES['manual_file']) && $_FILES['manual_file']['error'] === UPLOAD_ERR_OK) {
            $manual_file = guardarDocumento($conn, $modelo_id, 'manual', $_FILES['manual_file'], $modelo_dir);
            if ($manual_file) $documentos_subidos[] = $manual_file;
        }

        // Procesar lista de partes
        if (isset($_FILES['partes_file']) && $_FILES['partes_file']['error'] === UPLOAD_ERR_OK) {
            $partes_file = guardarDocumento($conn, $modelo_id, 'lista_partes', $_FILES['partes_file'], $modelo_dir);
            if ($partes_file) $documentos_subidos[] = $partes_file;
        }

        echo json_encode([
            'success' => true, 
            'message' => 'Modelo agregado correctamente', 
            'id' => $modelo_id,
            'documentos' => $documentos_subidos,
            'ruta_modelo' => $modelo_dir
        ]);
    } catch(PDOException $e) {
        throw new Exception("Error al agregar modelo: " . $e->getMessage());
    }
}
function updateModelo($conn) {
    try {
        $modelo_id = $_POST['modelo_id'] ?? '';
        $marca_id = $_POST['marca_id'] ?? '';
        $nombre = trim($_POST['nombre'] ?? '');
        $tipo_equipo = trim($_POST['tipo_equipo'] ?? '');

        if (empty($modelo_id) || empty($marca_id) || empty($nombre) || empty($tipo_equipo)) {
            echo json_encode(['success' => false, 'message' => 'Todos los campos son requeridos']);
            return;
        }

        // Verificar que el modelo existe
        $stmt = $conn->prepare("SELECT marca_id FROM modelos WHERE id = ? AND activo = 1");
        $stmt->execute([$modelo_id]);
        $modelo_actual = $stmt->fetch();
        
        if (!$modelo_actual) {
            echo json_encode(['success' => false, 'message' => 'Modelo no encontrado']);
            return;
        }

        // Verificar si el nombre ya existe en otra marca
        $stmt = $conn->prepare("SELECT id FROM modelos WHERE marca_id = ? AND nombre = ? AND id != ? AND activo = 1");
        $stmt->execute([$marca_id, $nombre, $modelo_id]);
        
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'El modelo ya existe para esta marca']);
            return;
        }

        // Actualizar modelo
        $stmt = $conn->prepare("UPDATE modelos SET marca_id = ?, nombre = ?, tipo_equipo = ? WHERE id = ?");
        $stmt->execute([$marca_id, $nombre, $tipo_equipo, $modelo_id]);

        // Si cambió la marca o el nombre, mover los archivos
        if ($modelo_actual['marca_id'] != $marca_id) {
            // Aquí iría la lógica para mover archivos entre carpetas
            // Por simplicidad, no implementado en este ejemplo
        }

        echo json_encode([
            'success' => true, 
            'message' => 'Modelo actualizado correctamente'
        ]);
    } catch(PDOException $e) {
        throw new Exception("Error al actualizar modelo: " . $e->getMessage());
    }
}

// Función para eliminar modelo - ELIMINACIÓN FÍSICA
function deleteModelo($conn) {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        $modelo_id = $data['modelo_id'] ?? '';
        
        if (empty($modelo_id)) {
            echo json_encode(['success' => false, 'message' => 'ID de modelo no proporcionado']);
            return;
        }

        // Obtener información del modelo para eliminar archivos y carpeta
        $stmt = $conn->prepare("
            SELECT m.nombre as modelo_nombre, ma.nombre as marca_nombre, d.ruta_archivo 
            FROM modelos m 
            INNER JOIN marcas ma ON m.marca_id = ma.id 
            LEFT JOIN documentos d ON m.id = d.modelo_id 
            WHERE m.id = ?
        ");
        $stmt->execute([$modelo_id]);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($resultados)) {
            echo json_encode(['success' => false, 'message' => 'Modelo no encontrado']);
            return;
        }

        $marca_nombre = $resultados[0]['marca_nombre'];
        $modelo_nombre = $resultados[0]['modelo_nombre'];

        // Eliminar archivos físicos
        foreach ($resultados as $doc) {
            if (!empty($doc['ruta_archivo'])) {
                $ruta_completa = '../' . $doc['ruta_archivo'];
                if (file_exists($ruta_completa)) {
                    unlink($ruta_completa);
                    error_log("Archivo eliminado: " . $ruta_completa);
                }
            }
        }

        // Eliminar carpeta del modelo
        $carpeta_modelo = '../manuales/' . sanitizeFolderName($marca_nombre) . '/' . sanitizeFolderName($modelo_nombre) . '/';
        if (is_dir($carpeta_modelo)) {
            eliminarCarpetaRecursivamente($carpeta_modelo);
            error_log("Carpeta eliminada: " . $carpeta_modelo);
        }

        // Eliminar documentos de la BD
        $stmt = $conn->prepare("DELETE FROM documentos WHERE modelo_id = ?");
        $stmt->execute([$modelo_id]);

        // Eliminar modelo de la BD (ELIMINACIÓN FÍSICA)
        $stmt = $conn->prepare("DELETE FROM modelos WHERE id = ?");
        $stmt->execute([$modelo_id]);

        echo json_encode([
            'success' => true, 
            'message' => 'Modelo y todos sus documentos eliminados correctamente'
        ]);
    } catch(PDOException $e) {
        throw new Exception("Error al eliminar modelo: " . $e->getMessage());
    }
}

// Función para eliminar marca - ELIMINACIÓN FÍSICA
function deleteMarca($conn) {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        $marca_id = $data['marca_id'] ?? '';
        
        if (empty($marca_id)) {
            echo json_encode(['success' => false, 'message' => 'ID de marca no proporcionado']);
            return;
        }

        // Verificar si hay modelos activos en esta marca
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM modelos WHERE marca_id = ?");
        $stmt->execute([$marca_id]);
        $result = $stmt->fetch();
        
        if ($result['count'] > 0) {
            echo json_encode(['success' => false, 'message' => 'No se puede eliminar la marca porque tiene modelos activos. Elimine primero los modelos.']);
            return;
        }

        // Obtener nombre de la marca para eliminar carpeta
        $stmt = $conn->prepare("SELECT nombre FROM marcas WHERE id = ?");
        $stmt->execute([$marca_id]);
        $marca = $stmt->fetch();
        
        if (!$marca) {
            echo json_encode(['success' => false, 'message' => 'Marca no encontrada']);
            return;
        }

        // Eliminar carpeta de la marca (si está vacía)
        $carpeta_marca = '../manuales/' . sanitizeFolderName($marca['nombre']) . '/';
        if (is_dir($carpeta_marca)) {
            // Verificar si la carpeta está vacía
            if (count(glob($carpeta_marca . "/*")) === 0) {
                rmdir($carpeta_marca);
                error_log("Carpeta de marca eliminada: " . $carpeta_marca);
            }
        }

        // Eliminar marca de la BD (ELIMINACIÓN FÍSICA)
        $stmt = $conn->prepare("DELETE FROM marcas WHERE id = ?");
        $stmt->execute([$marca_id]);

        echo json_encode([
            'success' => true, 
            'message' => 'Marca eliminada correctamente'
        ]);
    } catch(PDOException $e) {
        throw new Exception("Error al eliminar marca: " . $e->getMessage());
    }
}

// Función para eliminar documento - ELIMINACIÓN FÍSICA
function deleteDocumento($conn) {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        $documento_id = $data['documento_id'] ?? '';
        
        if (empty($documento_id)) {
            echo json_encode(['success' => false, 'message' => 'ID de documento no proporcionado']);
            return;
        }

        // Obtener información del documento
        $stmt = $conn->prepare("SELECT ruta_archivo FROM documentos WHERE id = ?");
        $stmt->execute([$documento_id]);
        $documento = $stmt->fetch();
        
        if (!$documento) {
            echo json_encode(['success' => false, 'message' => 'Documento no encontrado']);
            return;
        }

        // Eliminar archivo físico
        $ruta_completa = '../' . $documento['ruta_archivo'];
        if (file_exists($ruta_completa)) {
            if (unlink($ruta_completa)) {
                error_log("Archivo eliminado: " . $ruta_completa);
            } else {
                error_log("Error al eliminar archivo: " . $ruta_completa);
            }
        } else {
            error_log("Archivo no encontrado: " . $ruta_completa);
        }

        // Eliminar documento de la BD (ELIMINACIÓN FÍSICA)
        $stmt = $conn->prepare("DELETE FROM documentos WHERE id = ?");
        $stmt->execute([$documento_id]);

        echo json_encode([
            'success' => true, 
            'message' => 'Documento eliminado correctamente'
        ]);
    } catch(PDOException $e) {
        throw new Exception("Error al eliminar documento: " . $e->getMessage());
    }
}

// Función auxiliar para eliminar carpetas recursivamente
function eliminarCarpetaRecursivamente($carpeta) {
    if (!is_dir($carpeta)) {
        return false;
    }
    
    $archivos = array_diff(scandir($carpeta), array('.', '..'));
    
    foreach ($archivos as $archivo) {
        $ruta = $carpeta . '/' . $archivo;
        if (is_dir($ruta)) {
            eliminarCarpetaRecursivamente($ruta);
        } else {
            unlink($ruta);
        }
    }
    
    return rmdir($carpeta);
}
function uploadDocumentos($conn) {
    try {
        $modelo_id = $_POST['modelo_id'] ?? '';
        
        if (empty($modelo_id)) {
            echo json_encode(['success' => false, 'message' => 'ID de modelo no proporcionado']);
            return;
        }

        // Obtener información del modelo para la ruta
        $stmt = $conn->prepare("
            SELECT m.nombre as modelo_nombre, ma.nombre as marca_nombre 
            FROM modelos m 
            INNER JOIN marcas ma ON m.marca_id = ma.id 
            WHERE m.id = ?
        ");
        $stmt->execute([$modelo_id]);
        $modelo = $stmt->fetch();
        
        if (!$modelo) {
            echo json_encode(['success' => false, 'message' => 'Modelo no encontrado']);
            return;
        }

        // Crear ruta del modelo
        $base_dir = '../manuales/';
        $marca_folder = sanitizeFolderName($modelo['marca_nombre']);
        $modelo_folder = sanitizeFolderName($modelo['modelo_nombre']);
        $modelo_dir = $base_dir . $marca_folder . '/' . $modelo_folder . '/';
        
        if (!crearEstructuraDirectorios($modelo_dir)) {
            throw new Exception("No se pudo crear la estructura de directorios");
        }

        // Procesar archivos subidos
        $documentos_subidos = procesarArchivosSubidos($conn, $modelo_id, $modelo_dir);

        echo json_encode([
            'success' => true, 
            'message' => 'Documentos subidos correctamente',
            'documentos' => $documentos_subidos
        ]);
    } catch(PDOException $e) {
        throw new Exception("Error al subir documentos: " . $e->getMessage());
    }
}

function procesarArchivosSubidos($conn, $modelo_id, $upload_dir) {
    $documentos_subidos = [];
    $tipos_permitidos = [
        'manual' => ['pdf'],
        'lista_partes' => ['pdf'],
        'tutorial' => ['pdf', 'mp4', 'avi', 'mov'],
        'imagen' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'diagrama' => ['pdf', 'jpg', 'jpeg', 'png'],
        'esquema' => ['pdf', 'jpg', 'jpeg', 'png'],
        'firmware' => ['zip', 'rar', 'bin', 'hex'],
        'software' => ['zip', 'rar', 'exe', 'msi'],
        'otro' => ['pdf', 'zip', 'rar', 'doc', 'docx', 'xls', 'xlsx']
    ];

    foreach ($_FILES as $key => $file) {
        if ($file['error'] === UPLOAD_ERR_OK) {
            // Determinar tipo de documento desde el nombre del campo
            $tipo = 'otro';
            if (strpos($key, 'manual') !== false) $tipo = 'manual';
            elseif (strpos($key, 'partes') !== false) $tipo = 'lista_partes';
            elseif (strpos($key, 'tutorial') !== false) $tipo = 'tutorial';
            elseif (strpos($key, 'imagen') !== false) $tipo = 'imagen';
            elseif (strpos($key, 'diagrama') !== false) $tipo = 'diagrama';
            elseif (strpos($key, 'esquema') !== false) $tipo = 'esquema';
            elseif (strpos($key, 'firmware') !== false) $tipo = 'firmware';
            elseif (strpos($key, 'software') !== false) $tipo = 'software';

            $documento = guardarDocumento($conn, $modelo_id, $tipo, $file, $upload_dir, $tipos_permitidos[$tipo]);
            if ($documento) {
                $documentos_subidos[] = $documento;
            }
        }
    }

    return $documentos_subidos;
}

// ... (las funciones restantes se mantienen igual: buscarContenido, sanitizeFolderName, crearEstructuraDirectorios, guardarDocumento)
// Solo actualiza la función guardarDocumento para aceptar tipos permitidos:

function guardarDocumento($conn, $modelo_id, $tipo, $file, $upload_dir, $extensiones_permitidas = ['pdf']) {
    // Validar tipo de archivo
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($extension, $extensiones_permitidas)) {
        error_log("Tipo de archivo no permitido: " . $extension . " para tipo: " . $tipo);
        return false;
    }

    // Validar tamaño del archivo (máximo 50MB)
    if ($file['size'] > 50 * 1024 * 1024) {
        error_log("Archivo demasiado grande: " . $file['size']);
        return false;
    }

    // Generar nombre seguro para el archivo
    $nombre_base = sanitizeFolderName(pathinfo($file['name'], PATHINFO_FILENAME));
    $nombre_archivo = $nombre_base . '_' . uniqid() . '.' . $extension;
    $ruta_completa = $upload_dir . $nombre_archivo;

    if (move_uploaded_file($file['tmp_name'], $ruta_completa)) {
        // Guardar en base de datos con ruta relativa
        $ruta_relativa = str_replace('../', '', $ruta_completa);
        
        $descripcion = $_POST['descripcion_' . $tipo] ?? '';
        
        $stmt = $conn->prepare("
            INSERT INTO documentos (modelo_id, tipo_documento, nombre_archivo, descripcion, ruta_archivo) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$modelo_id, $tipo, $file['name'], $descripcion, $ruta_relativa]);
        
        return [
            'tipo' => $tipo, 
            'nombre' => $file['name'],
            'descripcion' => $descripcion,
            'ruta' => $ruta_relativa,
            'ruta_completa' => $ruta_completa
        ];
    }

    error_log("Error al mover archivo subido: " . $file['name']);
    return false;
}

// Función de búsqueda (se mantiene igual)
function buscarContenido($conn) {
    try {
        $termino = $_GET['q'] ?? '';
        $termino = trim($termino);
        
        if (empty($termino)) {
            echo json_encode(['success' => false, 'message' => 'Término de búsqueda vacío']);
            return;
        }

        $terminoBusqueda = '%' . $termino . '%';
        
        // Buscar en marcas
        $stmtMarcas = $conn->prepare("
            SELECT id, nombre, 'marca' as tipo 
            FROM marcas 
            WHERE nombre LIKE ? AND activo = 1
            ORDER BY nombre
        ");
        $stmtMarcas->execute([$terminoBusqueda]);
        $marcas = $stmtMarcas->fetchAll();

        // Buscar en modelos
        $stmtModelos = $conn->prepare("
            SELECT m.id, m.nombre, m.tipo_equipo, ma.nombre as marca_nombre, ma.id as marca_id, 'modelo' as tipo
            FROM modelos m 
            INNER JOIN marcas ma ON m.marca_id = ma.id 
            WHERE (m.nombre LIKE ? OR m.tipo_equipo LIKE ?) AND m.activo = 1
            ORDER BY ma.nombre, m.nombre
        ");
        $stmtModelos->execute([$terminoBusqueda, $terminoBusqueda]);
        $modelos = $stmtModelos->fetchAll();

        // Combinar resultados
        $resultados = [
            'marcas' => $marcas,
            'modelos' => $modelos,
            'total' => count($marcas) + count($modelos)
        ];

        echo json_encode([
            'success' => true,
            'resultados' => $resultados,
            'termino' => $termino,
            'counts' => [
                'marcas' => count($marcas),
                'modelos' => count($modelos)
            ]
        ]);

    } catch(PDOException $e) {
        throw new Exception("Error en búsqueda: " . $e->getMessage());
    }
}

// Funciones auxiliares (se mantienen igual)
function sanitizeFolderName($name) {
    $clean = preg_replace('/[^a-zA-Z0-9áéíóúÁÉÍÓÚñÑ_\- ]/', '_', $name);
    $clean = preg_replace('/[\s_]+/', '_', $clean);
    $clean = trim($clean, ' _-');
    return substr($clean, 0, 100);
}

function crearEstructuraDirectorios($ruta_completa) {
    if (!is_dir($ruta_completa)) {
        if (!mkdir($ruta_completa, 0755, true)) {
            error_log("Error: No se pudo crear el directorio: " . $ruta_completa);
            return false;
        }
        
        $htaccess_content = "Options -Indexes\n";
        file_put_contents(dirname($ruta_completa) . '/.htaccess', $htaccess_content);
        
        file_put_contents($ruta_completa . 'index.html', '<!-- Directorio protegido -->');
    }
    
    return true;
}
?>