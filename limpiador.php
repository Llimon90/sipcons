<?php

$directorio = __DIR__; 

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directorio));
$archivos_limpiados = 0;

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $ruta = $file->getPathname();
        $contenido = file_get_contents($ruta);
        
        // Si detecta el caracter BOM, lo recorta y reescribe el archivo
        if (substr($contenido, 0, 3) === "\xEF\xBB\xBF") {
            file_put_contents($ruta, substr($contenido, 3));
            echo "✅ Limpiado: " . $file->getFilename() . "<br>";
            $archivos_limpiados++;
        }
    }
}

echo "<hr><b>Proceso terminado. Se limpiaron $archivos_limpiados archivos en total.</b>";
?>