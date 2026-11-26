<?php
// Importación masiva desde CSV (ver backend/lib/importar_helpers.php).
//
//   GET  plantilla&tipo=clientes|equipos|articulos   CSV de ejemplo para llenar
//   POST validar&tipo=      (multipart "archivo")    vista previa, no guarda nada
//   POST importar&tipo=     (multipart "archivo")    valida de nuevo y guarda todo o nada
//
// Solo el Administrador, y además con el módulo del tipo (clientes o
// inventario). El modo solo lectura de la cuenta lo aplica requireAuth().
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
require_once __DIR__ . '/lib/importar_helpers.php';

$accion = $_GET['accion'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];
$tipo = (string)($_GET['tipo'] ?? '');

function responder(array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    header('Content-Type: application/json');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $def = impDefinicion($tipo);
    if (!esAdministracion() || !tienePermiso($def['modulo'])) {
        responder(['exito' => false, 'mensaje' => 'Solo el Administrador puede importar datos.'], 403);
    }

    if ($metodo === 'GET' && $accion === 'plantilla') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="plantilla-' . $tipo . '.csv"');
        echo impPlantilla($tipo);
        exit;
    }

    if ($metodo === 'POST' && in_array($accion, ['validar', 'importar'], true)) {
        $archivo = $_FILES['archivo'] ?? null;
        if (!$archivo || $archivo['error'] === UPLOAD_ERR_NO_FILE) {
            throw new ImportarException('Elige el archivo CSV.');
        }
        if ($archivo['error'] === UPLOAD_ERR_INI_SIZE || $archivo['error'] === UPLOAD_ERR_FORM_SIZE || $archivo['size'] > IMP_MAX_BYTES) {
            throw new ImportarException('El archivo pasa de 2 MB. Divídelo en partes.');
        }
        if ($archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
            throw new ImportarException('No se pudo recibir el archivo. Inténtalo de nuevo.');
        }
        $ext = strtolower(pathinfo((string)$archivo['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['xlsx', 'xls', 'ods'], true)) {
            throw new ImportarException('Ese es un archivo de Excel. Ábrelo y usa Archivo > Guardar como > "CSV UTF-8 (delimitado por comas)".');
        }
        if (!in_array($ext, ['csv', 'txt'], true)) {
            throw new ImportarException('El archivo debe ser .csv.');
        }
        $contenido = (string)file_get_contents($archivo['tmp_name']);
        $resultado = $accion === 'validar' ? impAnalizar($pdo, $tipo, $contenido) : impImportar($pdo, $tipo, $contenido);
        responder(['exito' => true] + $resultado);
    }

    responder(['exito' => false, 'mensaje' => 'Acción no válida.'], 400);
} catch (ImportarException | InventarioException $e) {
    responder(['exito' => false, 'mensaje' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('Importación: ' . $e->getMessage());
    responder(['exito' => false, 'mensaje' => 'No se pudo completar la importación. No se guardó nada.'], 500);
}
