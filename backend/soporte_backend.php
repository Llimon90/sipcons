<?php
// Incluir conexión - usar tu archivo existente
require_once 'conexion.php';

try {
    // Usar la clase Database que ahora está en conexion.php
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
        case 'add_marca':
            addMarca($conn);
            break;
        case 'add_modelo':
            addModelo($conn);
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
        $stmt = $conn->prepare("SELECT id, nombre FROM marcas WHERE activo = 1 ORDER BY nombre");
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

        $stmt = $conn->prepare("
            SELECT m.id, m.nombre, m.tipo_equipo, ma.nombre as marca_nombre 
            FROM modelos m 
            INNER JOIN marcas ma ON m.marca_id = ma.id 
            WHERE m.marca_id = ? AND m.activo = 1 
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
            ORDER BY d.tipo_documento
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

        // Verificar si la marca ya existe
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
        $marca_id = $_POST['marca_id'] ?? '';
        $nombre = trim($_POST['nombre'] ?? '');
        $tipo_equipo = trim($_POST['tipo_equipo'] ?? '');

        if (empty($marca_id) || empty($nombre) || empty($tipo_equipo)) {
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

        // Verificar si el modelo ya existe para esta marca
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

// Función para sanitizar nombres de carpetas
function sanitizeFolderName($name) {
    // Reemplazar caracteres no válidos con guiones bajos
    $clean = preg_replace('/[^a-zA-Z0-9áéíóúÁÉÍÓÚñÑ_\- ]/', '_', $name);
    // Reemplazar múltiples espacios o guiones bajos con uno solo
    $clean = preg_replace('/[\s_]+/', '_', $clean);
    // Eliminar espacios/guiones al inicio y final
    $clean = trim($clean, ' _-');
    // Limitar longitud
    return substr($clean, 0, 100);
}

// Función para crear estructura de directorios
function crearEstructuraDirectorios($ruta_completa) {
    if (!is_dir($ruta_completa)) {
        // Crear directorios recursivamente con permisos 0755
        if (!mkdir($ruta_completa, 0755, true)) {
            error_log("Error: No se pudo crear el directorio: " . $ruta_completa);
            return false;
        }
        
        // Crear archivo .htaccess para proteger el directorio
        $htaccess_content = "Options -Indexes\n";
        file_put_contents(dirname($ruta_completa) . '/.htaccess', $htaccess_content);
        
        // Crear archivo index.html vacío en cada directorio para evitar listado
        file_put_contents($ruta_completa . 'index.html', '<!-- Directorio protegido -->');
    }
    
    return true;
}

// Función para guardar documentos
function guardarDocumento($conn, $modelo_id, $tipo, $file, $upload_dir) {
    // Validar que sea PDF
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if ($mime_type !== 'application/pdf') {
        error_log("Tipo de archivo no permitido: " . $mime_type);
        return false;
    }

    // Validar tamaño del archivo (máximo 10MB)
    if ($file['size'] > 10 * 1024 * 1024) {
        error_log("Archivo demasiado grande: " . $file['size']);
        return false;
    }

    // Generar nombre seguro para el archivo
    $extension = 'pdf';
    $nombre_base = sanitizeFolderName(pathinfo($file['name'], PATHINFO_FILENAME));
    $nombre_archivo = $nombre_base . '_' . uniqid() . '.' . $extension;
    $ruta_completa = $upload_dir . $nombre_archivo;

    if (move_uploaded_file($file['tmp_name'], $ruta_completa)) {
        // Guardar en base de datos con ruta relativa
        $ruta_relativa = str_replace('../', '', $ruta_completa);
        
        $stmt = $conn->prepare("
            INSERT INTO documentos (modelo_id, tipo_documento, nombre_archivo, ruta_archivo) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$modelo_id, $tipo, $file['name'], $ruta_relativa]);
        
        return [
            'tipo' => $tipo, 
            'nombre' => $file['name'],
            'ruta' => $ruta_relativa,
            'ruta_completa' => $ruta_completa
        ];
    }

    error_log("Error al mover archivo subido: " . $file['name']);
    return false;
}
?>