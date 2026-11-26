<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

// La API solo la consume el propio frontend (mismo origen): sin cabeceras CORS.
header("Content-Type: application/json");

requireAuth();
