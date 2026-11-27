<?php
/**
 * Reescritura de rutas de archivos subidos al migrar SIPCONS (una empresa, sin
 * prefijo) -> Servidesk (multiempresa, carpeta por empresa).
 *
 * El esquema viejo guardaba las rutas SIN empresa:
 *     ../uploads/foto.jpg
 *     ../uploads/ventas/CLIENTE/VT-0001_x.png
 *     manuales/marca/modelo/manual.pdf
 * Pero Servidesk aísla cada empresa en su carpeta y backend/archivo.php SOLO
 * sirve uploads/e<ID>/... y manuales/e<ID>/..., devolviendo 404 a cualquier otra
 * forma. Si no se reescriben, al migrar producción TODOS los adjuntos quedan
 * rotos (404). Hay que (1) reescribir estas rutas en la base y (2) mover los
 * archivos físicos de uploads/ -> uploads/e<ID>/ (y manuales/ -> manuales/e<ID>/).
 *
 * La reescritura inserta el segmento e<ID> justo después de `uploads/` o
 * `manuales/`, conservando el prefijo (../), la subcarpeta (ventas/<cliente>,
 * marca/modelo) y el nombre. Es idempotente: una ruta que ya trae e<ID> no se
 * vuelve a tocar (por si se corre con --force).
 *
 * Este archivo NO tiene efectos secundarios (solo define funciones), así que se
 * puede incluir desde la herramienta de migración y desde los tests.
 */

/** Versión PHP (para pruebas y uso en código): reescribe UNA ruta. */
function rutaConEmpresa(string $ruta, int $empresa): string {
    $e = (int)$empresa;
    // (?!e[0-9]+/) evita volver a prefijar una ruta ya migrada.
    $ruta = preg_replace('#uploads/(?!e[0-9]+/)#', "uploads/e{$e}/", $ruta);
    $ruta = preg_replace('#manuales/(?!e[0-9]+/)#', "manuales/e{$e}/", $ruta);
    return $ruta;
}

/**
 * Versión SQL equivalente, para usar dentro del INSERT..SELECT de la migración.
 * $colExpr es la expresión de la columna origen (p. ej. 'ruta_archivo' o '?').
 * Debe dar EXACTAMENTE el mismo resultado que rutaConEmpresa() (lo verifica
 * tests/migracion_rutas_test.php). REGEXP_REPLACE con lookahead funciona en
 * MariaDB 10.0.5+ y MySQL 8.0+.
 */
function sqlRutaConEmpresa(string $colExpr, int $empresa): string {
    $e = (int)$empresa;
    return "REGEXP_REPLACE(REGEXP_REPLACE($colExpr, 'uploads/(?!e[0-9]+/)', 'uploads/e{$e}/'), 'manuales/(?!e[0-9]+/)', 'manuales/e{$e}/')";
}
