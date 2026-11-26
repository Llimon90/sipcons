<?php
// S-5 (auditoría integral 2026-11-24): inyección de fórmulas en los CSV que se abren en Excel.
// Un texto que empieza con = + - @ (o tabulador/retorno) se ejecuta como fórmula al abrir el
// archivo; se le antepone un apóstrofo para que Excel lo muestre como texto. Los números no
// se tocan (un importe negativo sigue siendo número).

function csvCeldaSegura($v) {
    if (!is_string($v) || $v === '' || is_numeric($v)) return $v;  // L2-5: "-2.00" sigue siendo número
    return strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
}

function csvFilaSegura(array $fila): array {
    return array_map('csvCeldaSegura', $fila);
}
