<?php
// Dirección corta para prospectos: /demo abre el login con el acceso de demostración.
header('Cache-Control: no-store');
header('Location: ../auth/login.html?demo', true, 302);
