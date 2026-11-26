<?php
// Conexión a la base de datos bajo carga (hosting compartido): cuando MySQL rechaza por
// límite de conexiones (1040 / 1203), se reintenta en vez de responder "servicio no
// disponible" a la primera (bug del 2026-11-13: perfil del cliente y orden intermitentes).

grupo('Conexión con reintentos');

prueba('Bug 2026-11-13: un rechazo por límite de conexiones se reintenta y la conexión se logra', function () {
    $intentos = 0;
    $r = conectarConReintentos(function () use (&$intentos) {
        if (++$intentos < 3) throw new mysqli_sql_exception("User already has more than 'max_user_connections' active connections", 1203);
        return 'conectado';
    }, 4, 0);
    igual('conectado', $r, 'resultado:');
    igual(3, $intentos, 'intentos:');
});

prueba('Un error que no es de límite (contraseña) no se reintenta', function () {
    $intentos = 0;
    lanza(function () use (&$intentos) {
        conectarConReintentos(function () use (&$intentos) {
            $intentos++;
            throw new mysqli_sql_exception('Access denied for user', 1045);
        }, 4, 0);
    }, mysqli_sql_exception::class, 'Access denied');
    igual(1, $intentos, 'intentos:');
});

prueba('Con PDO también se reconoce el límite de conexiones', function () {
    $intentos = 0;
    $r = conectarConReintentos(function () use (&$intentos) {
        if (++$intentos < 2) throw new PDOException('SQLSTATE[HY000] [1040] Too many connections');
        return 'ok';
    }, 4, 0);
    igual('ok', $r, 'resultado:');
});

// Bug 2026-11-25: en Hostinger, con 10 peticiones simultáneas la 11.ª recibía 503 sin reintentar
// (abrir una cotización enviada: sucursales). El rechazo llega con un código que no es 1040/1203.
prueba('Bug 2026-11-25: un rechazo con otro código (1226, 2002) también se reintenta', function () {
    foreach ([[1226, "User 'u' has exceeded the 'max_user_connections' resource (current value: 10)"],
              [2002, 'Resource temporarily unavailable']] as [$codigo, $mensaje]) {
        $intentos = 0;
        $r = conectarConReintentos(function () use (&$intentos, $codigo, $mensaje) {
            if (++$intentos < 3) throw new mysqli_sql_exception($mensaje, $codigo);
            return 'conectado';
        }, 4, 0);
        igual('conectado', $r, "resultado con $codigo:");
    }
});

prueba('Bug 2026-11-25: un error permanente (base o usuario inexistente) no se reintenta', function () {
    foreach ([[1049, "Unknown database 'x'"], [1044, 'Access denied for user to database']] as [$codigo, $mensaje]) {
        $intentos = 0;
        try {
            conectarConReintentos(function () use (&$intentos, $codigo, $mensaje) { $intentos++; throw new mysqli_sql_exception($mensaje, $codigo); }, 4, 0);
        } catch (mysqli_sql_exception $e) {}
        igual(1, $intentos, "intentos con $codigo:");
    }
    $intentos = 0;
    try {
        conectarConReintentos(function () use (&$intentos) { $intentos++; throw new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'u'@'h'"); }, 4, 0);
    } catch (PDOException $e) {}
    igual(1, $intentos, 'intentos con PDO 1045:');
});
