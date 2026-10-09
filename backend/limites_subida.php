<?php
// Límites de subida del servidor (MB por archivo, MB por envío y número de
// archivos), para que el formulario avise antes de enviar archivos pesados.
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/lib/incidencias_helpers.php';

header('Cache-Control: private, max-age=3600');
echo json_encode(limitesSubida() + ['extensiones' => EXTENSIONES_ADJUNTOS]);
