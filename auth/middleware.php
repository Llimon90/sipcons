<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

$allowedOrigin = '';
$appUrlParts = parse_url($_ENV['APP_URL'] ?? '');
if (!empty($appUrlParts['scheme']) && !empty($appUrlParts['host'])) {
    $allowedOrigin = $appUrlParts['scheme'] . '://' . $appUrlParts['host'];
    if (!empty($appUrlParts['port'])) {
        $allowedOrigin .= ':' . $appUrlParts['port'];
    }
}
if ($allowedOrigin !== '') {
    header("Access-Control-Allow-Origin: $allowedOrigin");
    header('Access-Control-Allow-Credentials: true');
}
header("Content-Type: application/json");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type");

requireAuth();
