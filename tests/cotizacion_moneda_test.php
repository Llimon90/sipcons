<?php
// Conversión de moneda en cotizaciones: cotConvertirPrecio() pasa un precio de la
// moneda del artículo (inventario) a la moneda del documento, usando el tipo de
// cambio (MXN por USD). Es el núcleo de "cargar una pieza en dlls a una cotización
// en pesos (o viceversa)". Estas pruebas blindan la aritmética y los bordes.

require_once __DIR__ . '/../backend/lib/cotizaciones_helpers.php';

grupo('Cotización · conversión de moneda (cotConvertirPrecio)');

prueba('Misma moneda (MXN->MXN) no cambia el precio', function () {
    igual(100.00, cotConvertirPrecio(100.00, 'MXN', 'MXN', 17.0));
});

prueba('Misma moneda (USD->USD) no cambia el precio', function () {
    igual(100.00, cotConvertirPrecio(100.00, 'USD', 'USD', 17.0));
});

prueba('Artículo en USD a cotización en MXN: multiplica por el tipo de cambio', function () {
    igual(170.00, cotConvertirPrecio(10.00, 'USD', 'MXN', 17.0));
});

prueba('Artículo en MXN a cotización en USD: divide entre el tipo de cambio', function () {
    igual(10.00, cotConvertirPrecio(170.00, 'MXN', 'USD', 17.0));
});

prueba('Tipo de cambio inválido (0) deja el precio sin tocar', function () {
    igual(10.00, cotConvertirPrecio(10.00, 'USD', 'MXN', 0.0));
});

prueba('Tipo de cambio negativo deja el precio sin tocar', function () {
    igual(10.00, cotConvertirPrecio(10.00, 'USD', 'MXN', -5.0));
});

prueba('Redondea a 2 decimales al convertir USD->MXN', function () {
    // 10.00 USD * 17.555 = 175.55 (2 decimales)
    igual(175.55, cotConvertirPrecio(10.00, 'USD', 'MXN', 17.555));
});

prueba('Precio cero convierte a cero', function () {
    igual(0.00, cotConvertirPrecio(0.00, 'USD', 'MXN', 17.0));
});

prueba('Ida y vuelta USD->MXN->USD recupera (aprox) el original', function () {
    $tc = 17.0;
    $enMXN = cotConvertirPrecio(100.00, 'USD', 'MXN', $tc);   // 1700.00
    $deVuelta = cotConvertirPrecio($enMXN, 'MXN', 'USD', $tc); // 100.00
    igual(100.00, $deVuelta);
});

prueba('Monedas soportadas: solo MXN y USD', function () {
    igual(['MXN', 'USD'], COT_MONEDAS);
});
