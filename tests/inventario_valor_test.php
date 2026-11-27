<?php
// Valuación del inventario a costo con moneda: por defecto en MXN (los artículos
// guardados en USD se convierten con el tipo de cambio de la empresa) y, aparte,
// el valor nativo en USD de los artículos en dólares. Se prueba la expresión SQL
// real contra una tabla en línea (no depende del esquema cargado).

require_once __DIR__ . '/../backend/lib/inventario_helpers.php';

grupo('Inventario · valuación a costo con moneda');

// Dos artículos: 10 pzas a 5 USD (costo) y 20 pzas a 30 MXN (costo).
function invMuestra(): string {
    return "(SELECT 10 AS existencia, 5.00 AS costo_unitario, 'USD' AS moneda
             UNION ALL SELECT 20, 30.00, 'MXN') t";
}
function invCalc(string $expr): float {
    global $pdo;
    return (float)$pdo->query("SELECT $expr FROM " . invMuestra())->fetchColumn();
}

prueba('Valor en MXN convierte los USD con el tipo de cambio (tc=17)', function () {
    // 10*5*17 (USD->MXN) + 20*30 (MXN) = 850 + 600 = 1450
    igual(1450.00, invCalc(invSqlValorMXN(17.0, true)));
});

prueba('Valor en MXN con otro tipo de cambio (tc=20)', function () {
    // 10*5*20 + 20*30 = 1000 + 600 = 1600
    igual(1600.00, invCalc(invSqlValorMXN(20.0, true)));
});

prueba('Sin tipo de cambio (0): los artículos en USD no se convierten (cuentan 0 en el total MXN)', function () {
    // 10*5*0 + 20*30 = 0 + 600 = 600
    igual(600.00, invCalc(invSqlValorMXN(0.0, true)));
});

prueba('Valor nativo en USD suma solo los artículos en USD', function () {
    // 10*5 = 50 (solo el USD)
    igual(50.00, invCalc(invSqlValorUSDNativo(true)));
});

prueba('Sin migración 024 (sin columna moneda): suma directa, todo MXN', function () {
    // 10*5 + 20*30 = 650
    igual(650.00, invCalc(invSqlValorMXN(17.0, false)));
    igual(0.00, invCalc(invSqlValorUSDNativo(false)));
});
