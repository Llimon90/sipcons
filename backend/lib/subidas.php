<?php
// Extensiones aceptadas en los adjuntos de incidencias y ventas. Nada que el
// servidor pueda ejecutar (php, phtml, phar, cgi, js, html, svg...). La carpeta
// uploads/ además bloquea la ejecución de scripts con su propio .htaccess.
const EXTENSIONES_ADJUNTOS = [
    'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'bmp',
    'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'ppt', 'pptx',
    'heif', 'mp4', 'mov', 'webm', 'ogg', 'm4v', '3gp',
    'mp3', 'wav', 'm4a', 'aac', 'oga',
    'zip', 'rar', '7z', 'xml',
];

function extensionAdjuntoPermitida(string $nombreArchivo): bool {
    $ext = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));
    return $ext !== '' && in_array($ext, EXTENSIONES_ADJUNTOS, true);
}

/**
 * ¿Se puede abrir en el navegador (inline)? Se decide por la EXTENSIÓN (lo que el
 * visor sabe mostrar: imagen, PDF, audio, video, texto) y nunca si el tipo detectado
 * es ejecutable (HTML, XHTML, SVG, XML, JavaScript): abierto en el mismo origen podría
 * correr scripts. Todo lo demás se entrega como descarga.
 */
const EXTENSIONES_EN_NAVEGADOR = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif',
    'mp4', 'mov', 'webm', 'ogg', 'm4v', '3gp', 'mp3', 'wav', 'm4a', 'aac', 'oga', 'txt', 'csv', 'log'];

function tipoEjecutable(string $mime): bool {
    return (bool)preg_match('#(html|svg|javascript|ecmascript|xml)#i', $mime);
}

function seMuestraEnNavegador(string $nombreArchivo, string $mime): bool {
    $ext = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));
    return in_array($ext, EXTENSIONES_EN_NAVEGADOR, true) && !tipoEjecutable($mime);
}
