<?php
// Importación masiva desde CSV (backend/importar.php): clientes, padrón de
// equipos y catálogo de inventario. Para quien llega con sus datos en Excel:
// lo guarda como CSV, lo sube, revisa la vista previa y confirma.
//
// Reglas:
//   - Todo o nada: si una fila tiene error no se guarda ninguna.
//   - Lo que ya existe (cliente con el mismo nombre, artículo con el mismo
//     SKU) se omite con aviso; nunca se sobrescribe.
//   - El servidor vuelve a leer y validar el archivo al confirmar: la vista
//     previa del navegador no se toma como buena.
// Requiere $pdo de la empresa en sesión (las tablas se reescriben a e<ID>_).
require_once __DIR__ . '/inventario_helpers.php';
require_once __DIR__ . '/periodicidad_helpers.php';

class ImportarException extends RuntimeException {}

const IMP_MAX_BYTES = 2 * 1024 * 1024;
const IMP_MAX_FILAS = 2000;

/**
 * Columnas de cada tipo: campo => [encabezado de la plantilla, obligatorio,
 * largo máximo, otros encabezados aceptados, ejemplo].
 */
function impDefiniciones(): array {
    return [
        'clientes' => [
            'titulo' => 'Clientes',
            'modulo' => 'clientes',
            'columnas' => [
                'nombre'             => ['Nombre', true, 100, ['cliente', 'razon social', 'nombre del cliente', 'empresa'], 'Hotel Ejemplo del Valle'],
                'rfc'                => ['RFC', false, 20, [], 'XAXX010101000'],
                'direccion'          => ['Dirección', false, 150, ['domicilio'], 'Av. Reforma 123, Col. Centro'],
                'telefono'           => ['Teléfono', false, 20, ['tel', 'telefono cliente'], '6861234567'],
                'email'              => ['Correo', false, 100, ['email', 'correo electronico', 'e-mail'], 'compras@ejemplo.test'],
                'contacto'           => ['Contacto', false, 100, ['nombre del contacto', 'contacto principal'], 'Ana López'],
                'contacto_telefono'  => ['Teléfono del contacto', false, 50, ['tel contacto', 'celular'], '6869876543'],
                'contacto_email'     => ['Correo del contacto', false, 150, ['email contacto'], 'ana@ejemplo.test'],
                'dias_credito'       => ['Días de crédito', false, 0, ['credito', 'dias credito'], '30'],
            ],
        ],
        'equipos' => [
            'titulo' => 'Padrón de equipos',
            'modulo' => 'clientes',
            'columnas' => [
                'cliente'       => ['Cliente', true, 255, ['nombre del cliente'], 'Hotel Ejemplo del Valle'],
                'sucursal'      => ['Sucursal', false, 255, ['ubicacion', 'sitio', 'planta'], 'Torre norte'],
                'equipo'        => ['Equipo', true, 255, ['tipo de equipo', 'descripcion'], 'Minisplit 2 toneladas'],
                'marca'         => ['Marca', false, 255, [], 'Marca Ejemplo'],
                'modelo'        => ['Modelo', false, 255, [], 'MS-24'],
                'numero_serie'  => ['Número de serie', false, 100, ['serie', 'no de serie', 'num serie', 'n serie'], 'SN-000123'],
                'meses'         => ['Mantenimiento cada (meses)', false, 0, ['periodicidad', 'frecuencia', 'cada cuantos meses', 'meses'], '6'],
                'poliza'        => ['Con póliza (sí/no)', false, 0, ['poliza', 'contrato'], 'no'],
                'garantia'      => ['Garantía (meses)', false, 0, ['garantia'], '12'],
                'fecha_registro'=> ['Fecha de alta', false, 0, ['fecha', 'fecha de instalacion', 'instalacion'], '2026-01-15'],
            ],
        ],
        'articulos' => [
            'titulo' => 'Catálogo de inventario',
            'modulo' => 'inventario',
            'columnas' => [
                'sku'            => ['SKU', true, 40, ['codigo', 'clave'], 'REF-001'],
                'nombre'         => ['Nombre', true, 150, ['articulo', 'producto', 'descripcion corta'], 'Capacitor 35/5 MFD'],
                'categoria'      => ['Categoría', true, 0, ['tipo'], 'Refacción'],
                'marca'          => ['Marca', false, 100, [], 'Marca Ejemplo'],
                'modelo'         => ['Modelo', false, 100, [], 'C-355'],
                'unidad'         => ['Unidad', false, 20, ['unidad de medida'], 'pza'],
                'existencia'     => ['Existencia inicial', false, 0, ['existencia', 'cantidad', 'stock'], '10'],
                'stock_minimo'   => ['Stock mínimo', false, 0, ['minimo'], '2'],
                'costo_unitario' => ['Costo unitario', false, 0, ['costo'], '85.50'],
                'precio_venta'   => ['Precio de venta', false, 0, ['precio'], '180'],
                'ubicacion'      => ['Ubicación', false, 100, ['almacen', 'anaquel'], 'Anaquel A-3'],
                'descripcion'    => ['Descripción', false, 0, ['notas'], ''],
            ],
        ],
    ];
}

function impDefinicion(string $tipo): array {
    $defs = impDefiniciones();
    if (!isset($defs[$tipo])) {
        throw new ImportarException('Tipo de importación no válido.');
    }
    return $defs[$tipo];
}

/** "Número de serie" → "numero de serie": para comparar encabezados escritos a mano. */
function impClave(string $texto): string {
    $t = mb_strtolower(trim($texto));
    $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $t = preg_replace('/\(.*?\)/u', ' ', $t);           // "Garantía (meses)" → "garantia"
    $t = preg_replace('/[^a-z0-9]+/', ' ', $t);
    return trim(preg_replace('/\s+/', ' ', $t));
}

/** Plantilla CSV (con BOM para que Excel respete los acentos) con una fila de ejemplo. */
function impPlantilla(string $tipo): string {
    $cols = impDefinicion($tipo)['columnas'];
    $f = fopen('php://temp', 'r+');
    fputcsv($f, array_column($cols, 0));
    fputcsv($f, array_column($cols, 4));
    rewind($f);
    $csv = stream_get_contents($f);
    fclose($f);
    return "\xEF\xBB\xBF" . $csv;
}

/**
 * Lee el CSV tal como lo guarda Excel en México: UTF-8 (con o sin BOM) o
 * Windows-1252, separado por comas o por punto y coma.
 * Devuelve ['encabezados' => [...], 'filas' => [[n, [celdas]], ...]].
 */
function impLeerCsv(string $contenido): array {
    if (strlen($contenido) > IMP_MAX_BYTES) {
        throw new ImportarException('El archivo pasa de 2 MB. Divídelo en partes.');
    }
    if (str_starts_with($contenido, "\xEF\xBB\xBF")) {
        $contenido = substr($contenido, 3);
    } elseif (!mb_check_encoding($contenido, 'UTF-8')) {
        $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
    }
    if (str_contains(substr($contenido, 0, 4096), "\0")) {
        throw new ImportarException('El archivo no es un CSV. En Excel usa Archivo > Guardar como > "CSV UTF-8 (delimitado por comas)".');
    }
    $contenido = str_replace(["\r\n", "\r"], "\n", $contenido);
    $primera = strtok($contenido, "\n") ?: '';
    $separador = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
    if (substr_count($primera, "\t") > max(substr_count($primera, ';'), substr_count($primera, ','))) {
        $separador = "\t";
    }

    $f = fopen('php://temp', 'r+');
    fwrite($f, $contenido);
    rewind($f);
    $encabezados = null;
    $filas = [];
    $n = 0;
    while (($celdas = fgetcsv($f, 0, $separador, '"', '')) !== false) {
        $n++;
        $celdas = array_map(fn($c) => trim((string)$c), $celdas);
        if (implode('', $celdas) === '') continue;          // renglones vacíos al final
        if ($encabezados === null) {
            $encabezados = $celdas;
            continue;
        }
        $filas[] = [$n, $celdas];
        if (count($filas) > IMP_MAX_FILAS) {
            fclose($f);
            throw new ImportarException('El archivo tiene más de ' . IMP_MAX_FILAS . ' renglones. Divídelo en partes.');
        }
    }
    fclose($f);
    if ($encabezados === null) {
        throw new ImportarException('El archivo está vacío.');
    }
    if (!$filas) {
        throw new ImportarException('El archivo solo trae encabezados: no hay renglones que importar.');
    }
    return ['encabezados' => $encabezados, 'filas' => $filas];
}

/** Índice de columna del archivo para cada campo, según su encabezado. */
function impMapearColumnas(string $tipo, array $encabezados): array {
    $cols = impDefinicion($tipo)['columnas'];
    $porClave = [];
    foreach ($encabezados as $i => $e) {
        $k = impClave($e);
        if ($k !== '' && !isset($porClave[$k])) $porClave[$k] = $i;
    }
    $mapa = [];
    $faltan = [];
    foreach ($cols as $campo => [$etiqueta, $obligatorio, , $alias]) {
        foreach (array_merge([$etiqueta, $campo], $alias) as $nombre) {
            $k = impClave(str_replace('_', ' ', $nombre));
            if (isset($porClave[$k])) {
                $mapa[$campo] = $porClave[$k];
                break;
            }
        }
        if ($obligatorio && !isset($mapa[$campo])) $faltan[] = $etiqueta;
    }
    if ($faltan) {
        throw new ImportarException('Al archivo le falta la columna: ' . implode(', ', $faltan) . '. Usa la plantilla para no equivocarte.');
    }
    return $mapa;
}

/** "$1,234.50" → 1234.5; vacío → 0; texto → null (error). */
function impNumero(string $valor): ?float {
    $v = str_replace(['$', ' ', 'MXN', 'mxn'], '', $valor);
    if ($v === '') return 0.0;
    if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $v)) $v = str_replace(',', '', $v);   // 1,234.50
    elseif (preg_match('/^-?\d+,\d{1,2}$/', $v)) $v = str_replace(',', '.', $v);            // 12,5
    return is_numeric($v) ? round((float)$v, 2) : null;
}

/** Acepta 2026-01-15, 15/01/2026 y 15-01-2026. Vacío → null; inválida → false. */
function impFecha(string $valor) {
    if ($valor === '') return null;
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $valor, $m)) [$a, $me, $d] = [$m[1], $m[2], $m[3]];
    elseif (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $valor, $m)) [$d, $me, $a] = [$m[1], $m[2], $m[3]];
    else return false;
    return checkdate((int)$me, (int)$d, (int)$a) ? sprintf('%04d-%02d-%02d', $a, $me, $d) : false;
}

function impSiNo(string $valor): ?int {
    $k = impClave($valor);
    if (in_array($k, ['', 'no', 'n', '0', 'falso', 'false'], true)) return 0;
    if (in_array($k, ['si', 's', '1', 'x', 'verdadero', 'true', 'yes'], true)) return 1;
    return null;
}

/**
 * Lee y valida el archivo. Cada fila queda con su renglón del archivo, los
 * datos limpios y su resultado: crear, omitir (ya existe) o error.
 */
function impAnalizar(PDO $pdo, string $tipo, string $contenido): array {
    $def = impDefinicion($tipo);
    $csv = impLeerCsv($contenido);
    $mapa = impMapearColumnas($tipo, $csv['encabezados']);

    $filas = [];
    $vistos = [];
    $ctx = impContexto($pdo, $tipo);
    foreach ($csv['filas'] as [$n, $celdas]) {
        $datos = [];
        $errores = [];
        foreach ($def['columnas'] as $campo => [$etiqueta, $obligatorio, $max]) {
            $valor = isset($mapa[$campo]) ? (string)($celdas[$mapa[$campo]] ?? '') : '';
            $valor = trim(preg_replace('/\s+/u', ' ', $valor));
            if ($obligatorio && $valor === '') $errores[] = "Falta «{$etiqueta}».";
            if ($max && mb_strlen($valor) > $max) $errores[] = "«{$etiqueta}» pasa de {$max} caracteres.";
            $datos[$campo] = $valor;
        }
        $fila = ['renglon' => $n, 'datos' => $datos, 'errores' => $errores, 'avisos' => [], 'accion' => 'crear'];
        $fila = ('impValidar_' . $tipo)($fila, $ctx, $vistos);
        if ($fila['errores']) $fila['accion'] = 'error';
        $filas[] = $fila;
    }

    $resumen = ['crear' => 0, 'omitir' => 0, 'error' => 0];
    foreach ($filas as $f) $resumen[$f['accion']]++;
    return [
        'tipo'     => $tipo,
        'titulo'   => $def['titulo'],
        'columnas' => array_map(fn($c) => $c[0], $def['columnas']),
        'ignoradas'=> array_values(array_filter(array_diff_key($csv['encabezados'], array_flip($mapa)), 'strlen')),
        'resumen'  => $resumen,
        'filas'    => $filas,
    ];
}

/** Lo que hay en la base para detectar duplicados sin una consulta por fila. */
function impContexto(PDO $pdo, string $tipo): array {
    if ($tipo === 'clientes') {
        $filas = $pdo->query("SELECT nombre, email FROM clientes")->fetchAll(PDO::FETCH_ASSOC);
        $correos = [];  // D-53: el correo de un cliente tampoco se repite en la empresa
        foreach ($filas as $f) if (trim((string)$f['email']) !== '') $correos[mb_strtolower(trim($f['email']))] = $f['nombre'];
        return ['clientes' => array_fill_keys(array_map(fn($f) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $f['nombre']))), $filas), true), 'correos' => $correos];
    }
    if ($tipo === 'equipos') {
        $clientes = [];
        foreach ($pdo->query("SELECT nombre FROM clientes")->fetchAll(PDO::FETCH_COLUMN) as $nom) {
            $clientes[mb_strtolower(trim($nom))] = $nom;
        }
        $series = [];
        foreach ($pdo->query("SELECT numero_serie, cliente FROM padron_equipos WHERE numero_serie IS NOT NULL AND numero_serie <> ''") as $r) {
            $series[mb_strtoupper(trim($r['numero_serie']))] = $r['cliente'];
        }
        return ['clientes' => $clientes, 'series' => $series];
    }
    $skus = $pdo->query("SELECT sku FROM inventario_articulos")->fetchAll(PDO::FETCH_COLUMN);
    return ['skus' => array_fill_keys(array_map(fn($x) => mb_strtoupper(trim($x)), $skus), true)];
}

function impValidar_clientes(array $fila, array $ctx, array &$vistos): array {
    $d = &$fila['datos'];
    $d['rfc'] = mb_strtoupper($d['rfc']);
    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $fila['errores'][] = 'El correo no es válido.';
    if ($d['contacto_email'] !== '' && !filter_var($d['contacto_email'], FILTER_VALIDATE_EMAIL)) $fila['errores'][] = 'El correo del contacto no es válido.';
    if (str_contains($d['contacto'], ';')) $d['contacto'] = str_replace(';', ',', $d['contacto']);
    $dias = impNumero($d['dias_credito']);
    if ($dias === null || $dias < 0 || $dias > 365 || floor($dias) != $dias) $fila['errores'][] = 'Los días de crédito deben ser un número entero de 0 a 365.';
    else $d['dias_credito'] = (int)$dias;

    if (mb_strlen($d['nombre']) > 100) $fila['errores'][] = 'El nombre admite hasta 100 caracteres.';  // L-33
    $k = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $d['nombre'])));  // H-10
    $correo = mb_strtolower(trim($d['email']));
    if ($correo !== '' && !isset($ctx['clientes'][$k])) {  // D-53
        if (isset($ctx['correos'][$correo])) $fila['errores'][] = "El correo ya es del cliente {$ctx['correos'][$correo]}.";
        elseif (isset($vistos['@' . $correo])) $fila['errores'][] = "El correo se repite en el renglón {$vistos['@' . $correo]}.";
        else $vistos['@' . $correo] = $fila['renglon'];
    }
    if ($k !== '' && isset($ctx['clientes'][$k])) {
        $fila['accion'] = 'omitir';
        $fila['avisos'][] = 'Ya existe un cliente con ese nombre: se deja como está.';
    } elseif ($k !== '' && isset($vistos[$k])) {
        $fila['errores'][] = "El nombre se repite en el renglón {$vistos[$k]}.";
    } elseif ($k !== '') {
        $vistos[$k] = $fila['renglon'];
    }
    return $fila;
}

function impValidar_equipos(array $fila, array $ctx, array &$vistos): array {
    $d = &$fila['datos'];
    $k = mb_strtolower($d['cliente']);
    if ($k !== '') {
        if (isset($ctx['clientes'][$k])) $d['cliente'] = $ctx['clientes'][$k];   // con el nombre exacto del catálogo
        else $fila['errores'][] = 'El cliente no existe. Impórtalo o dalo de alta primero en Clientes.';
    }
    $serie = $d['numero_serie'];
    $serieMayus = mb_strtoupper($serie);
    if ($serie === '' || in_array($serieMayus, ['S/N', 'N/A', 'SN', 'NA'], true)) {
        $d['numero_serie'] = 'S/N';
    } elseif (isset($ctx['series'][$serieMayus])) {
        $fila['errores'][] = "La serie ya pertenece a un equipo de {$ctx['series'][$serieMayus]}.";
    } elseif (isset($vistos[$serieMayus])) {
        $fila['errores'][] = "La serie se repite en el renglón {$vistos[$serieMayus]}.";
    } else {
        $vistos[$serieMayus] = $fila['renglon'];
    }
    foreach (['meses' => 'Mantenimiento cada (meses)', 'garantia' => 'Garantía (meses)'] as $campo => $etiqueta) {
        $v = impNumero($d[$campo]);
        if ($v === null || $v < 0 || $v > 120 || floor($v) != $v) $fila['errores'][] = "«{$etiqueta}» debe ser un número entero de 0 a 120.";
        else $d[$campo] = (int)$v;
    }
    $poliza = impSiNo($d['poliza']);
    if ($poliza === null) $fila['errores'][] = '«Con póliza» debe ser sí o no.';
    else $d['poliza'] = $poliza;
    $fecha = impFecha($d['fecha_registro']);
    if ($fecha === false) $fila['errores'][] = 'La fecha de alta no es válida (usa 2026-01-15 o 15/01/2026).';
    else $d['fecha_registro'] = $fecha ?? date('Y-m-d');
    return $fila;
}

function impValidar_articulos(array $fila, array $ctx, array &$vistos): array {
    $d = &$fila['datos'];
    $d['sku'] = mb_strtoupper($d['sku']);
    $categoria = null;
    foreach (INV_CATEGORIAS as $c) {
        if (impClave($c) === impClave($d['categoria'])) $categoria = $c;
    }
    if ($d['categoria'] !== '' && !$categoria) $fila['errores'][] = 'Categoría no válida. Usa: ' . implode(', ', INV_CATEGORIAS) . '.';
    $d['categoria'] = $categoria ?? $d['categoria'];
    $d['unidad'] = $d['unidad'] !== '' ? mb_strtolower($d['unidad']) : ($categoria && !invCategoriaControlaExistencia($categoria) ? 'servicio' : 'pza');
    foreach (['existencia' => 'Existencia inicial', 'stock_minimo' => 'Stock mínimo', 'costo_unitario' => 'Costo unitario', 'precio_venta' => 'Precio de venta'] as $campo => $etiqueta) {
        $v = impNumero($d[$campo]);
        if ($v === null || $v < 0 || $v >= 1e10) $fila['errores'][] = "«{$etiqueta}» debe ser un número de 0 o más.";
        else $d[$campo] = $v;
    }
    if ($categoria && !invCategoriaControlaExistencia($categoria)) {
        if (is_float($d['existencia']) && $d['existencia'] > 0) $fila['avisos'][] = 'Es un servicio: la existencia no se toma en cuenta.';
        $d['existencia'] = 0.0;
        $d['stock_minimo'] = 0.0;
        $d['ubicacion'] = '';
    }

    $k = $d['sku'];
    if ($k !== '' && isset($ctx['skus'][$k])) {
        $fila['accion'] = 'omitir';
        $fila['avisos'][] = 'Ya existe un artículo con ese SKU: se deja como está.';
    } elseif ($k !== '' && isset($vistos[$k])) {
        $fila['errores'][] = "El SKU se repite en el renglón {$vistos[$k]}.";
    } elseif ($k !== '') {
        $vistos[$k] = $fila['renglon'];
    }
    return $fila;
}

/**
 * Valida de nuevo y guarda las filas nuevas en una sola transacción.
 * Devuelve el análisis con 'importados' (cuántas se crearon).
 */
function impImportar(PDO $pdo, string $tipo, string $contenido): array {
    // H-04 · D-53: los clientes se analizan y guardan con el catálogo bloqueado (igual que el alta)
    if ($tipo === 'clientes') {
        require_once __DIR__ . '/clientes_helpers.php';
        $candado = cliCandado($pdo);
        try { return impImportarSinCandado($pdo, $tipo, $contenido); } finally { cliSoltarCandado($pdo, $candado); }
    }
    return impImportarSinCandado($pdo, $tipo, $contenido);
}

function impImportarSinCandado(PDO $pdo, string $tipo, string $contenido): array {
    $analisis = impAnalizar($pdo, $tipo, $contenido);
    if ($analisis['resumen']['error']) {
        throw new ImportarException('El archivo tiene ' . $analisis['resumen']['error'] . ' renglón(es) con error. Corrígelos y vuelve a subirlo: no se guardó nada.');
    }
    $nuevas = array_values(array_filter($analisis['filas'], fn($f) => $f['accion'] === 'crear'));
    if (!$nuevas) {
        throw new ImportarException('No hay nada nuevo que importar: todo lo del archivo ya existe.');
    }

    $pdo->beginTransaction();
    try {
        foreach ($nuevas as $f) ('impGuardar_' . $tipo)($pdo, $f['datos']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    if ($tipo === 'equipos') solicitarRevisionTickets();
    $analisis['importados'] = count($nuevas);
    return $analisis;
}

function impGuardar_clientes(PDO $pdo, array $d): void {
    $pdo->prepare("INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email, dias_credito) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$d['nombre'], $d['rfc'], $d['direccion'], $d['telefono'], $d['contacto'], $d['email'], $d['dias_credito']]);
    $id = (int)$pdo->lastInsertId();
    if ($d['contacto'] !== '') {
        $pdo->prepare("INSERT INTO cliente_contactos (cliente_id, nombre, telefono, email) VALUES (?, ?, ?, ?)")
            ->execute([$id, $d['contacto'], $d['contacto_telefono'] ?: null, $d['contacto_email'] ?: null]);
    }
    registrarAuditoria('clientes', $id, 'CREATE', null, $d + ['origen' => 'Importación CSV']);
}

/** L-21: primera fecha base + k×meses (k ≥ 1) que sea hoy o después. */
function impProximaFecha(string $base, int $meses): string {
    $hoy = date('Y-m-d');
    $k = 1;
    do {
        $d = new DateTime($base);
        $dia = (int)$d->format('d');
        $d->modify('first day of this month')->modify("+" . ($meses * $k) . " months");
        $d->setDate((int)$d->format('Y'), (int)$d->format('m'), min($dia, (int)$d->format('t')));
        $k++;
    } while ($d->format('Y-m-d') < $hoy && $k < 1200);
    return $d->format('Y-m-d');
}

function impGuardar_equipos(PDO $pdo, array $d): void {
    [$calibracion, $servicio, $frecuencia] = normalizarPeriodicidad(['calibracion' => $d['meses'], 'servicio' => $d['poliza']]);
    $f = $d['fecha_registro'];
    // L-21: meses de calendario (31 de enero + 1 = fin de febrero, como el generador de tickets) y, si la
    // fecha de alta es vieja, el siguiente mantenimiento es el próximo que toca (no una fila de atrasados)
    $proxCal = $calibracion > 0 ? impProximaFecha($f, $calibracion) : null;
    $proxServ = ($servicio && $frecuencia > 0) ? impProximaFecha($f, $frecuencia) : null;
    $pdo->prepare(
        "INSERT INTO padron_equipos
            (cliente, sucursal, equipo, marca, modelo, numero_serie, calibracion, servicio, frecuencia_servicio, garantia, proxima_calibracion, proximo_servicio, origen, fecha_registro)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Externo', ?)"
    )->execute([$d['cliente'], $d['sucursal'], $d['equipo'], $d['marca'], $d['modelo'], $d['numero_serie'],
                $calibracion, $servicio, $frecuencia, $d['garantia'], $proxCal, $proxServ, $f]);
    $nuevoEquipo = (int)$pdo->lastInsertId();
    require_once __DIR__ . '/sucursales_helpers.php';
    sucLigarRegistro($pdo, 'padron_equipos', $nuevoEquipo);  // D-44
    registrarAuditoria('padron_equipos', $nuevoEquipo, 'CREATE', null, [
        'cliente' => $d['cliente'], 'sucursal' => $d['sucursal'], 'equipo' => $d['equipo'], 'marca' => $d['marca'],
        'modelo' => $d['modelo'], 'numero_serie' => $d['numero_serie'], 'calibracion' => $calibracion,
        'servicio' => $servicio, 'frecuencia_servicio' => $frecuencia, 'garantia' => $d['garantia'],
        'origen' => 'Importación CSV',
    ]);
}

function impGuardar_articulos(PDO $pdo, array $d): void {
    $campos = [
        'sku' => $d['sku'], 'nombre' => $d['nombre'], 'categoria' => $d['categoria'],
        'marca' => $d['marca'] ?: null, 'modelo' => $d['modelo'] ?: null, 'descripcion' => $d['descripcion'] ?: null,
        'unidad' => $d['unidad'], 'stock_minimo' => $d['stock_minimo'], 'costo_unitario' => $d['costo_unitario'],
        'precio_venta' => $d['precio_venta'], 'ubicacion' => $d['ubicacion'] ?: null,
    ];
    if (invTieneControlExistencia($pdo)) {
        $campos['controla_existencia'] = invCategoriaControlaExistencia($d['categoria']) ? 1 : 0;
    }
    $cols = implode(', ', array_keys($campos));
    $marcas = implode(', ', array_fill(0, count($campos), '?'));
    $pdo->prepare("INSERT INTO inventario_articulos ($cols) VALUES ($marcas)")->execute(array_values($campos));
    $id = (int)$pdo->lastInsertId();
    registrarAuditoria('inventario_articulos', $id, 'CREATE', null, $campos + ['existencia_inicial' => $d['existencia'], 'origen' => 'Importación CSV'], $d['sku']);
    if ($d['existencia'] > 0) {
        invRegistrarMovimiento($pdo, $id, $d['existencia'], 'entrada', 'Existencia inicial (importación)', 'manual');
    }
}
