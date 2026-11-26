<?php
// «Exportar mis datos» (auditoría integral 2026-11-25, P0 legal/contrato): el Administrador
// descarga un ZIP con un CSV por cada tabla de su empresa (UTF-8 con BOM, para Excel) y sus
// usuarios sin contraseñas. Los archivos adjuntos no van en el ZIP (pueden pesar mucho): se
// descargan desde cada registro. Se permite también en solo lectura (es una consulta).
// Requiere $pdo con la empresa activa (EmpresaContexto).
require_once __DIR__ . '/../../config/csv.php';

/** Ruta de un ZIP temporal con los datos de la empresa activa. Quien llama lo borra. */
function expZipEmpresa(PDO $pdo): string {
    if (!class_exists('ZipArchive')) throw new RuntimeException('El servidor no tiene la extensión zip de PHP.');
    $empresaId = (int)(EmpresaContexto::$id ?? 0);
    if ($empresaId <= 0) throw new RuntimeException('Sin empresa activa.');
    $ruta = tempnam(sys_get_temp_dir(), 'sdx');
    $zip = new ZipArchive();
    if ($zip->open($ruta, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('No se pudo preparar el archivo.');

    $csv = function (array $encabezados, iterable $filas): string {
        $h = fopen('php://temp', 'w+');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, $encabezados);
        foreach ($filas as $f) fputcsv($h, csvFilaSegura(array_values($f)));  // S-5
        rewind($h);
        $s = stream_get_contents($h);
        fclose($h);
        return $s;
    };
    $resumen = [];
    $temporales = [];
    $listo = false;
    try {  // H-06 (fase 4): los temporales se borran siempre, también si algo falla a la mitad
    foreach (TABLAS_EMPRESA as $tabla) {
        try {
            $encabezados = array_map(fn($c) => $c['Field'], $pdo->query("SHOW COLUMNS FROM `$tabla`")->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            continue;  // tabla de una migración que esta empresa aún no tiene
        }
        // Fila por fila a un archivo temporal: el historial y el kárdex pueden ser grandes y el hosting
        // tiene poca memoria (consulta sin búfer, sin cargar la tabla completa)
        $tmp = tempnam(sys_get_temp_dir(), 'sdc');
        $temporales[] = $tmp;
        $h = fopen($tmp, 'w');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, $encabezados);
        $n = 0;
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $st = $pdo->query("SELECT * FROM `$tabla`");  // PdoEmpresa la lleva a e<ID>_<tabla>
            while ($f = $st->fetch(PDO::FETCH_NUM)) { fputcsv($h, csvFilaSegura($f)); $n++; }  // S-5
            $st->closeCursor();
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
        fclose($h);
        $zip->addFile($tmp, "$tabla.csv");
        $resumen[] = "$tabla.csv: $n registros";
    }
    // Usuarios de la empresa, sin contraseñas ni datos de sesión
    $st = $pdo->prepare('SELECT id, nombre, correo, telefono, usuario, rol FROM usuarios WHERE empresa_id = ? ORDER BY id');
    $st->execute([$empresaId]);
    $usuarios = $st->fetchAll(PDO::FETCH_ASSOC);
    $zip->addFromString('usuarios.csv', $csv(['id', 'nombre', 'correo', 'telefono', 'usuario', 'rol'], $usuarios));
    $resumen[] = 'usuarios.csv: ' . count($usuarios) . ' registros (sin contraseñas)';

    $zip->addFromString('LEEME.txt', "Exportación de datos de " . (EmpresaContexto::$fila['nombre'] ?? 'la empresa') . ' - ' . date('Y-m-d H:i') . "\r\n\r\n"
        . "Un archivo CSV por tabla (se abre en Excel). Los archivos adjuntos (fotos, PDF, comprobantes) no se incluyen:\r\n"
        . "se descargan desde cada orden, venta o cuenta. Las contraseñas no se exportan.\r\n\r\n" . implode("\r\n", $resumen) . "\r\n");
    $zip->close();
    $listo = true;
    } finally {
        // H-05 (fase 4 b): cerrar antes de borrar (en Windows un archivo abierto no se borra)
        if (isset($h) && is_resource($h)) @fclose($h);
        if (!$listo) { try { @$zip->close(); } catch (Throwable $e) { /* nada */ } }
        foreach ($temporales as $t) @unlink($t);
        if (!$listo) @unlink($ruta);
    }
    return $ruta;
}
