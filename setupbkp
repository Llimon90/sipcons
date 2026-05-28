<?php
/**
 * SETUP INICIAL — Crear usuario administrador
 * IMPORTANTE: Elimina este archivo después de usarlo.
 */
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/config/database.php';

$mensaje = '';
$tipo    = '';
$listo   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre   = trim($_POST['nombre']   ?? '');
    $correo   = trim($_POST['correo']   ?? '');
    $telefono = trim($_POST['telefono'] ?? '0000000000');
    $usuario  = trim($_POST['usuario']  ?? '');
    $password = $_POST['password']      ?? '';
    $confirma = $_POST['confirma']      ?? '';
    $rol      = 'admin';

    if (empty($nombre) || empty($correo) || empty($usuario) || empty($password)) {
        $mensaje = 'Todos los campos son obligatorios.';
        $tipo    = 'error';
    } elseif (strlen($password) < 8) {
        $mensaje = 'La contraseña debe tener al menos 8 caracteres.';
        $tipo    = 'error';
    } elseif ($password !== $confirma) {
        $mensaje = 'Las contraseñas no coinciden.';
        $tipo    = 'error';
    } else {
        // Verificar si el usuario ya existe
        $check = $conn->prepare("SELECT id FROM usuarios WHERE usuario = ? OR correo = ? LIMIT 1");
        $check->bind_param('ss', $usuario, $correo);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $mensaje = 'Ese usuario o correo ya existe en la base de datos.';
            $tipo    = 'error';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                "INSERT INTO usuarios (nombre, correo, telefono, usuario, password, rol) VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('ssssss', $nombre, $correo, $telefono, $usuario, $hash, $rol);

            if ($stmt->execute()) {
                $mensaje = "Usuario <strong>$usuario</strong> creado correctamente. Ya puedes iniciar sesión.";
                $tipo    = 'exito';
                $listo   = true;
            } else {
                $mensaje = "Error al crear el usuario: {$stmt->error}";
                $tipo    = 'error';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Setup — SIPCONS</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: Arial, sans-serif;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #1a252f 0%, #2c3e50 50%, #34495e 100%);
    }
    .card {
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.4);
      width: 100%;
      max-width: 460px;
      overflow: hidden;
    }
    .card-header {
      background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
      padding: 28px 32px;
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .card-header i { font-size: 1.6rem; color: #e67e22; }
    .card-header div h1 { color: #ecf0f1; font-size: 1.2rem; font-weight: 600; }
    .card-header div p  { color: rgba(236,240,241,0.6); font-size: 0.8rem; margin-top: 3px; }
    .warning-banner {
      background: #fef9e7;
      border-left: 4px solid #e67e22;
      padding: 10px 16px;
      font-size: 0.82rem;
      color: #7d6608;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .card-body { padding: 28px 32px; }
    .alert {
      border-radius: 8px;
      padding: 12px 16px;
      font-size: 0.88rem;
      margin-bottom: 20px;
      display: flex;
      align-items: flex-start;
      gap: 10px;
    }
    .alert.error  { background:#fdf2f2; border:1px solid #f5c6cb; color:#c0392b; }
    .alert.exito  { background:#eafaf1; border:1px solid #a9dfbf; color:#1e8449; }
    .row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .form-group { margin-bottom: 18px; }
    .form-group label {
      display: block;
      font-size: 0.78rem;
      font-weight: 700;
      color: #555;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 7px;
    }
    .form-group input {
      width: 100%;
      padding: 11px 14px;
      border: 1.5px solid #ddd;
      border-radius: 8px;
      font-size: 0.92rem;
      color: #333;
      outline: none;
      transition: border-color 0.2s;
    }
    .form-group input:focus { border-color: #3498db; box-shadow: 0 0 0 3px rgba(52,152,219,0.12); }
    .btn {
      width: 100%;
      padding: 13px;
      border: none;
      border-radius: 8px;
      font-size: 0.95rem;
      font-weight: 600;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      text-decoration: none;
    }
    .btn-primary { background: linear-gradient(135deg, #2980b9, #3498db); color: #fff; }
    .btn-primary:hover { opacity: 0.9; }
    .btn-success { background: linear-gradient(135deg, #1e8449, #27ae60); color: #fff; margin-top: 10px; }
    .btn-success:hover { opacity: 0.9; }
  </style>
</head>
<body>
<div class="card">
  <div class="card-header">
    <i class="fas fa-triangle-exclamation"></i>
    <div>
      <h1>Setup inicial — SIPCONS</h1>
      <p>Crear primer usuario administrador</p>
    </div>
  </div>

  <div class="warning-banner">
    <i class="fas fa-lock"></i>
    Elimina este archivo del servidor después de usarlo.
  </div>

  <div class="card-body">
    <?php if ($mensaje): ?>
    <div class="alert <?= $tipo ?>">
      <i class="fas fa-<?= $tipo === 'exito' ? 'circle-check' : 'circle-exclamation' ?>"></i>
      <span><?= $mensaje ?></span>
    </div>
    <?php endif; ?>

    <?php if ($listo): ?>
      <a href="auth/login.html" class="btn btn-success">
        <i class="fas fa-right-to-bracket"></i> Ir al login
      </a>
    <?php else: ?>
    <form method="POST">
      <div class="row">
        <div class="form-group">
          <label>Nombre completo</label>
          <input type="text" name="nombre" placeholder="Luis Limón" required />
        </div>
        <div class="form-group">
          <label>Usuario</label>
          <input type="text" name="usuario" placeholder="luis.limon" required />
        </div>
      </div>
      <div class="form-group">
        <label>Correo</label>
        <input type="email" name="correo" placeholder="correo@empresa.com" required />
      </div>
      <div class="row">
        <div class="form-group">
          <label>Contraseña</label>
          <input type="password" name="password" placeholder="Min. 8 caracteres" required />
        </div>
        <div class="form-group">
          <label>Confirmar</label>
          <input type="password" name="confirma" placeholder="Repite la contraseña" required />
        </div>
      </div>
      <button type="submit" class="btn btn-primary">
        <i class="fas fa-user-plus"></i> Crear administrador
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
