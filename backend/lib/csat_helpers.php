<?php
// Encuesta de satisfacción (CSAT): se genera sola al cerrar una incidencia
// (ver backend/actualiza.php y backend/server.php). Mientras no haya SMTP
// en apptest, el enlace se escribe en storage/logs/csat_pendientes.log para
// que el administrador lo mande a mano, igual que ya hace
// backend/portal/solicitar-reset.php con el reset de contraseña.
//
// Nunca debe interrumpir el guardado de la incidencia: cualquier falla aquí
// solo se registra en el error_log del servidor.
function generarEncuestaCsatSiNecesaria(PDO $pdo, int $incidenciaId, ?string $numeroIncidente): void {
    try {
        $existe = $pdo->prepare('SELECT id FROM encuestas_csat WHERE incidencia_id = ? LIMIT 1');
        $existe->execute([$incidenciaId]);
        if ($existe->fetchColumn()) {
            return; // ya se generó antes (p. ej. se reabrió y se cerró otra vez)
        }

        $token = bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO encuestas_csat (incidencia_id, token) VALUES (?, ?)')
            ->execute([$incidenciaId, $token]);

        $logDir = __DIR__ . '/../../storage/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0700, true);
        $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
        $enlace = $appUrl . '/public/portal/encuesta.html?token=' . $token;
        @file_put_contents(
            $logDir . '/csat_pendientes.log',
            date('Y-m-d H:i:s') . ' incidencia=' . ($numeroIncidente ?? $incidenciaId) . " enlace=$enlace\n",
            FILE_APPEND
        );
    } catch (\Throwable $e) {
        error_log('generarEncuestaCsatSiNecesaria: ' . $e->getMessage());
    }
}
