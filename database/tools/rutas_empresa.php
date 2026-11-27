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

/**
 * Versión PHP (para pruebas y uso en código): reescribe UNA ruta. Es idempotente
 * (una ruta que ya trae e<ID> no se vuelve a tocar).
 */
function rutaConEmpresa(string $ruta, int $empresa): string {
    $e = (int)$empresa;
    // (?!e[0-9]+/) evita volver a prefijar una ruta ya migrada.
    $ruta = preg_replace('#uploads/(?!e[0-9]+/)#', "uploads/e{$e}/", $ruta);
    $ruta = preg_replace('#manuales/(?!e[0-9]+/)#', "manuales/e{$e}/", $ruta);
    return $ruta;
}

/**
 * Versión SQL para el INSERT..SELECT de la migración. $colExpr es la expresión de
 * la columna origen (p. ej. "COALESCE(ruta_archivo,'')" o '?').
 *
 * PORTÁTIL a propósito: usa REPLACE anidado, NO REGEXP_REPLACE, porque el MySQL
 * de Hostinger (5.7) no tiene REGEXP_REPLACE (es de MySQL 8+). La migración
 * siempre lee del esquema VIEJO (rutas sin e<ID>), así que REPLACE directo es
 * correcto. Para corregir una base YA migrada se usa un UPDATE con guarda
 * `WHERE ... NOT LIKE '%uploads/e<ID>/%'` (ver cabecera de migrar_...php).
 *
 * Sobre rutas de formato viejo da el mismo resultado que rutaConEmpresa() (lo
 * comprueba tests/migracion_rutas_test.php).
 */
function sqlRutaConEmpresa(string $colExpr, int $empresa): string {
    $e = (int)$empresa;
    return "REPLACE(REPLACE($colExpr, 'uploads/', 'uploads/e{$e}/'), 'manuales/', 'manuales/e{$e}/')";
}
