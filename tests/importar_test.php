<?php
// Pruebas de la importación desde CSV (backend/lib/importar_helpers.php).
require_once __DIR__ . '/../backend/lib/importar_helpers.php';

[$empI, $adminI] = empresaTemporal('Importar A');
[$empJ, $adminJ] = empresaTemporal('Importar B');

function cuenta(string $tabla): int {
    global $pdo;
    return (int)$pdo->query("SELECT COUNT(*) FROM $tabla")->fetchColumn();
}

grupo('Importar: lectura del archivo');

prueba('Lee CSV de Excel: punto y coma, Windows-1252, BOM y renglones vacíos', function () {
    $latin = mb_convert_encoding("Nombre;Teléfono\nPanadería Ñandú;686\n;\n", 'Windows-1252', 'UTF-8');
    $r = impLeerCsv($latin);
    igual(['Nombre', 'Teléfono'], $r['encabezados']);
    igual('Panadería Ñandú', $r['filas'][0][1][0]);
    igual(1, count($r['filas']), 'el renglón vacío se ignora');
    $bom = impLeerCsv("\xEF\xBB\xBFNombre,RFC\n\"Hotel, S.A.\",XAXX\n");
    igual('Nombre', $bom['encabezados'][0], 'sin BOM pegado al encabezado');
    igual('Hotel, S.A.', $bom['filas'][0][1][0], 'comas dentro de comillas');
});

prueba('Rechaza archivos vacíos, sin renglones o de Excel binario', function () {
    lanza(fn() => impLeerCsv(''), ImportarException::class, 'vacío');
    lanza(fn() => impLeerCsv("Nombre,RFC\n"), ImportarException::class, 'solo trae encabezados');
    lanza(fn() => impLeerCsv("PK\x03\x04\0\0binario"), ImportarException::class, 'no es un CSV');
    lanza(fn() => impLeerCsv(str_repeat('a', IMP_MAX_BYTES + 1)), ImportarException::class, '2 MB');
});

prueba('Reconoce encabezados escritos a mano y avisa la columna obligatoria que falta', function () {
    $m = impMapearColumnas('equipos', ['CLIENTE', 'Tipo de equipo', 'No. de serie', 'Garantia (meses)']);
    igual(['cliente' => 0, 'equipo' => 1, 'numero_serie' => 2, 'garantia' => 3], $m);
    lanza(fn() => impMapearColumnas('articulos', ['SKU', 'Nombre']), ImportarException::class, 'Categoría');
});

prueba('Números y fechas como los escribe la gente', function () {
    igual(1234.5, impNumero('$1,234.50'));
    igual(12.5, impNumero('12,5'));
    igual(0.0, impNumero(''));
    igual(null, impNumero('diez'));
    igual('2026-01-15', impFecha('15/01/2026'));
    igual('2026-01-15', impFecha('2026-1-15'));
    igual(false, impFecha('31/02/2026'));
    igual(null, impFecha(''));
    igual(1, impSiNo('Sí'));
    igual(0, impSiNo(''));
    igual(null, impSiNo('quizá'));
});

prueba('La plantilla se lee de vuelta sin errores en los tres tipos', function () use ($empI, $adminI) {
    global $pdo;
    como($empI, $adminI, 'Administrador');
    foreach (['clientes', 'equipos', 'articulos'] as $tipo) {
        $a = impAnalizar($pdo, $tipo, impPlantilla($tipo));
        if ($tipo === 'equipos') {
            igual(1, $a['resumen']['error'], 'el cliente de ejemplo no existe todavía');
        } else {
            igual(['crear' => 1, 'omitir' => 0, 'error' => 0], $a['resumen'], $tipo);
        }
    }
});

grupo('Importar: clientes, equipos y catálogo');

prueba('Clientes: crea, omite los que ya existen y marca repetidos y correos malos', function () use ($empI, $adminI) {
    global $pdo;
    como($empI, $adminI, 'Administrador');
    $pdo->exec("INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email) VALUES ('Hotel Existente', '', '', '', '', '')");
    $csv = "Nombre,RFC,Correo,Contacto,Teléfono del contacto,Días de crédito\n"
         . "Clínica Norte,xaxx010101000,compras@clinica.test,Ana López,686111,30\n"
         . "hotel existente,,,,,\n"
         . "Clínica Norte,,,,,\n"
         . "Taller Sur,,no-es-correo,,,\n"
         . "Escuela Oeste,,,,,quince\n";
    $a = impAnalizar($pdo, 'clientes', $csv);
    igual(['crear' => 1, 'omitir' => 1, 'error' => 3], $a['resumen']);
    verdadero(str_contains($a['filas'][2]['errores'][0], 'renglón 2'), 'el repetido dice en qué renglón está el primero');
    igual('XAXX010101000', $a['filas'][0]['datos']['rfc'], 'RFC en mayúsculas');
    lanza(fn() => impImportar($pdo, 'clientes', $csv), ImportarException::class, 'no se guardó nada');
    igual(1, cuenta('clientes'), 'con errores no se guarda nada');

    $ok = "Nombre,RFC,Correo,Contacto,Teléfono del contacto,Días de crédito\n"
        . "Clínica Norte,xaxx010101000,compras@clinica.test,Ana López,686111,30\n"
        . "Hotel Existente,,,,,\n"
        . "Taller Sur,,,,,\n";
    $r = impImportar($pdo, 'clientes', $ok);
    igual(2, $r['importados']);
    igual(3, cuenta('clientes'));
    $c = $pdo->query("SELECT * FROM clientes WHERE nombre = 'Clínica Norte'")->fetch(PDO::FETCH_ASSOC);
    igual(30, (int)$c['dias_credito']);
    igual('Ana López', $c['contactos']);
    igual('686111', $pdo->query("SELECT telefono FROM cliente_contactos WHERE cliente_id = {$c['id']}")->fetchColumn());
    igual(2, (int)$pdo->query("SELECT COUNT(*) FROM auditoria WHERE tabla = 'clientes' AND accion = 'CREATE'")->fetchColumn(), 'cada alta queda en el Historial');
    lanza(fn() => impImportar($pdo, 'clientes', $ok), ImportarException::class, 'ya existe');
});

prueba('Equipos: exige cliente existente, evita series repetidas y calcula el próximo mantenimiento', function () use ($empI, $adminI) {
    global $pdo;
    como($empI, $adminI, 'Administrador');
    $pdo->exec("INSERT INTO padron_equipos (cliente, equipo, numero_serie) VALUES ('Taller Sur', 'Compresor', 'SER-1')");
    $csv = "Cliente,Equipo,Número de serie,Mantenimiento cada (meses),Con póliza (sí/no),Fecha de alta\n"
         . "clínica norte,Minisplit,A-1,6,no,15/01/2026\n"
         . "Clínica Norte,Chiller,B-2,3,sí,2026-02-01\n"
         . "Clínica Norte,Ventilador,,,,\n"
         . "Clínica Norte,Bomba,S/N,,,\n"
         . "Cliente Fantasma,Minisplit,C-3,,,\n"
         . "Clínica Norte,Minisplit,ser-1,,,\n"
         . "Clínica Norte,Minisplit,A-1,,,\n"
         . "Clínica Norte,Minisplit,D-4,seis,talvez,31/02/2026\n";
    $a = impAnalizar($pdo, 'equipos', $csv);
    igual(['crear' => 4, 'omitir' => 0, 'error' => 4], $a['resumen']);
    igual(3, count($a['filas'][7]['errores']), 'meses, póliza y fecha inválidos en el mismo renglón');
    igual('Clínica Norte', $a['filas'][0]['datos']['cliente'], 'usa el nombre exacto del catálogo');

    $r = impImportar($pdo, 'equipos', implode("\n", array_slice(explode("\n", $csv), 0, 5)));
    igual(4, $r['importados']);
    $e = $pdo->query("SELECT * FROM padron_equipos WHERE numero_serie = 'A-1'")->fetch(PDO::FETCH_ASSOC);
    igual(impProximaFecha('2026-01-15', 6), $e['proxima_calibracion'], 'sin póliza: recordatorio para contactar (L-21: el siguiente que toca)');
    igual(null, $e['proximo_servicio']);
    $p = $pdo->query("SELECT * FROM padron_equipos WHERE numero_serie = 'B-2'")->fetch(PDO::FETCH_ASSOC);
    igual([1, 3, impProximaFecha('2026-02-01', 3)], [(int)$p['servicio'], (int)$p['frecuencia_servicio'], $p['proximo_servicio']], 'con póliza: servicio programado (L-21)');
    igual(2, (int)$pdo->query("SELECT COUNT(*) FROM padron_equipos WHERE numero_serie = 'S/N'")->fetchColumn(), 'sin serie quedan como S/N y no chocan');
});

prueba('Catálogo: categoría flexible, existencia inicial al kárdex y servicios sin existencia', function () use ($empI, $adminI) {
    global $pdo;
    como($empI, $adminI, 'Administrador');
    $csv = "SKU;Nombre;Categoría;Existencia inicial;Costo unitario;Precio de venta\n"
         . "ref-01;Capacitor;refaccion;10;\$85.50;180\n"
         . "MO-01;Hora técnico;Mano de obra;5;0;450\n"
         . "X-1;Cosa;Juguete;0;0;0\n"
         . "REF-01;Otro;Refacción;1;1;1\n"
         . "Y-1;Algo;Consumible;-3;0;0\n";
    $a = impAnalizar($pdo, 'articulos', $csv);
    igual(['crear' => 2, 'omitir' => 0, 'error' => 3], $a['resumen']);
    igual('Refacción', $a['filas'][0]['datos']['categoria']);
    igual(['Es un servicio: la existencia no se toma en cuenta.'], $a['filas'][1]['avisos']);
    igual('servicio', $a['filas'][1]['datos']['unidad']);

    impImportar($pdo, 'articulos', implode("\n", array_slice(explode("\n", $csv), 0, 3)));
    $art = $pdo->query("SELECT * FROM inventario_articulos WHERE sku = 'REF-01'")->fetch(PDO::FETCH_ASSOC);
    igual([10.0, 85.5, 1], [(float)$art['existencia'], (float)$art['costo_unitario'], (int)$art['controla_existencia']]);
    igual(1, (int)$pdo->query("SELECT COUNT(*) FROM inventario_movimientos WHERE articulo_id = {$art['id']} AND tipo = 'entrada'")->fetchColumn(), 'entrada en el kárdex');
    $mo = $pdo->query("SELECT existencia, controla_existencia FROM inventario_articulos WHERE sku = 'MO-01'")->fetch(PDO::FETCH_ASSOC);
    igual([0.0, 0], [(float)$mo['existencia'], (int)$mo['controla_existencia']]);
    $a2 = impAnalizar($pdo, 'articulos', "SKU,Nombre,Categoría\nref-01,Capacitor,Refacción\n");
    igual('omitir', $a2['filas'][0]['accion'], 'el SKU existente se omite');
});

prueba('Si falla a la mitad, no queda nada guardado', function () use ($empI, $adminI) {
    global $pdo;
    como($empI, $adminI, 'Administrador');
    $antes = cuenta('clientes');
    // Un trigger hace fallar el segundo renglón ya dentro de la transacción
    $tabla = prefijoEmpresa($empI) . 'clientes';
    $pdo->exec("CREATE TRIGGER prueba_importar_falla BEFORE INSERT ON `$tabla` FOR EACH ROW
                BEGIN IF NEW.nombre = 'Dos Mal' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'falla simulada'; END IF; END");
    try {
        lanza(fn() => impImportar($pdo, 'clientes', "Nombre,Contacto\nUno Bien,Luis\nDos Mal,Pepe\n"), PDOException::class, 'falla simulada');
    } finally {
        $pdo->exec("DROP TRIGGER IF EXISTS prueba_importar_falla");
    }
    igual($antes, cuenta('clientes'), 'el primer renglón tampoco quedó');
    igual(0, (int)$pdo->query("SELECT COUNT(*) FROM cliente_contactos c JOIN clientes k ON k.id = c.cliente_id WHERE k.nombre = 'Uno Bien'")->fetchColumn());
    $grande = impAnalizar($pdo, 'articulos', "SKU,Nombre,Categoría,Precio de venta\nG-1,Grande,Equipo,99999999999\n");
    igual('error', $grande['filas'][0]['accion'], 'importes fuera de rango se detectan antes de guardar');
});

prueba('Cada empresa importa solo en sus tablas', function () use ($empI, $adminI, $empJ, $adminJ) {
    global $pdo;
    como($empJ, $adminJ, 'Administrador');
    igual(0, cuenta('clientes'), 'la empresa B no ve los clientes importados en A');
    $a = impAnalizar($pdo, 'equipos', "Cliente,Equipo\nClínica Norte,Minisplit\n");
    igual(1, $a['resumen']['error'], 'tampoco puede colgar equipos a clientes de A');
    impImportar($pdo, 'clientes', "Nombre\nClínica Norte\n");
    igual(1, cuenta('clientes'));
    como($empI, $adminI, 'Administrador');
    igual(3, cuenta('clientes'), 'A sigue con los suyos');
});
