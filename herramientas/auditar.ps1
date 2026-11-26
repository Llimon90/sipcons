<#
.SYNOPSIS
  Auditoría independiente de lógica de negocio con Gemini (Antigravity CLI, solo lectura).

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File herramientas\auditar.ps1 -Objetivo ciclo-vida
  powershell -ExecutionPolicy Bypass -File herramientas\auditar.ps1 -Objetivo backend/finanzas.php
  powershell -ExecutionPolicy Bypass -File herramientas\auditar.ps1 -Diff
  powershell -ExecutionPolicy Bypass -File herramientas\auditar.ps1 -Diff -Base origin/master

.NOTES
  - Usa siempre --mode plan (el auditor no puede editar) y nunca --dangerously-skip-permissions.
  - NO usar --disable-slash-commands: agy avisa que con ese flag "--mode plan has no effect"
    (el auditor quedaría con permisos de edición). Si aparece cualquier aviso así, se descarta.
  - Fija el modelo Gemini: Antigravity también ofrece modelos Claude, y el auditor debe ser otro modelo.
  - Compara el estado del repositorio antes y después: si cambió algo, el reporte se descarta.
  - Guarda el reporte en auditorias\AAAA-MM-DD-<objetivo>.md (si ya existe, agrega -2, -3...).
#>
[CmdletBinding()]
param(
    [string]$Objetivo,
    [switch]$Diff,
    [string]$Base = 'master',
    [string]$Modelo = 'gemini-4-argon-high',
    [int]$TiempoMaximoMin = 25,
    [string]$Enfoque = ''
)

# 'Continue': en PowerShell 5.1 el stderr de git/agy se vuelve error con 'Stop'.
# Los errores propios se lanzan con throw.
$ErrorActionPreference = 'Continue'
[Console]::OutputEncoding = [Text.Encoding]::UTF8
$OutputEncoding = [Text.Encoding]::UTF8
$utf8 = New-Object System.Text.UTF8Encoding($false)

$raiz = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $raiz

if (-not $Diff -and -not $Objetivo) {
    throw 'Indica -Objetivo <modulo|ruta> o -Diff. Módulos: ciclo-vida, permisos, sla, asignacion, notificaciones, concurrencia, cotizaciones, finanzas, inventario'
}
if ($Modelo -notlike 'gemini-*') { throw "El auditor debe ser un modelo Gemini (recibido: $Modelo)." }

$agy = (Get-Command agy -ErrorAction SilentlyContinue).Source
if (-not $agy) { $agy = Join-Path $env:LOCALAPPDATA 'agy\bin\agy.exe' }
if (-not (Test-Path $agy)) { throw 'No se encontró Antigravity CLI (agy).' }

# --- Alcance -------------------------------------------------------------
$modulos = @{
    'ciclo-vida'     = @{ archivos = 'backend/server.php backend/actualiza.php backend/detalle.php backend/eliminar-incidencia.php backend/generador_tickets.php backend/lib/incidencias_helpers.php backend/buscar_reportes.php config/branding.php backend/lib/cotizaciones_helpers.php scripts/detalle.js scripts/script.js'
                          enfoque  = 'Ciclo de vida completo de la orden de servicio: crear, asignar, pendiente, completar, cerrar, cancelar, reabrir y eliminar. Estatus permitidos y condiciones de la sección 0.1, D-1 a D-8, D-11 y D-13. Escalamiento: confirmar que no existe y que nada lo simula a medias.' }
    'permisos'       = @{ archivos = 'auth/ config/auth.php config/empresa.php config/database.php config/usuarios.php backend/ plataforma/api.php scripts/auth-guard.js'
                          enfoque  = 'Permisos y aislamiento de datos, probados contra el backend y no contra la interfaz: entre empresas (MT-1 a MT-6) y, dentro de la empresa, el rol Técnico (D-9, D-12) y RP-1 a RP-20. Revisa TODOS los endpoints de backend/ que leen o escriben incidencias, archivos o usuarios.' }
    'sla'            = @{ archivos = 'backend/lib/estadisticas_helpers.php backend/estadisticas.php backend/estadisticas_tecnico.php backend/inicio.php backend/alertas_incidentes.php backend/detalle-usuario.php config/empresa.php config/env.php config/database.php auth/audit.php'
                          enfoque  = 'Cálculo de SLA y tiempos: arranque, respuesta, cierre, reaperturas, cancelaciones, atraso (D-14 a D-18, SL-1 a SL-9) y zonas horarias (ZH-1 a ZH-6), incluidos el cambio de horario de verano y los límites de día.' }
    'asignacion'     = @{ archivos = 'backend/obtener_tecnicos.php backend/server.php backend/actualiza.php backend/eliminar_usuario.php backend/actualiza-usuario.php backend/alta-user.php backend/estadisticas_tecnico.php backend/inicio.php backend/buscar_reportes.php backend/generador_tickets.php scripts/detalle.js'
                          enfoque  = 'Asignación y reasignación: técnicos inexistentes, de otra empresa, eliminados, renombrados o sin rol asignable (AS-1 a AS-8, D-5, D-10, D-11, D-17).' }
    'notificaciones' = @{ archivos = 'backend/inicio.php backend/alertas_incidentes.php backend/estadisticas_tecnico.php scripts/inicio.js scripts/menu-lateral.js'
                          enfoque  = 'Avisos dentro de la app (no hay correo, EN-1 a EN-3, D-19): que cada aviso llegue a quien debe, sin duplicados y sin filtrar datos a quien no debe verlos (por ejemplo, un Técnico viendo órdenes ajenas, D-9).' }
    'concurrencia'   = @{ archivos = 'backend/server.php backend/actualiza.php backend/generador_tickets.php backend/lib/incidencias_helpers.php backend/inventario.php backend/lib/inventario_helpers.php backend/lib/cotizaciones_helpers.php backend/lib/finanzas_helpers.php scripts/detalle.js scripts/script.js'
                          enfoque  = 'Concurrencia: dos agentes editando la misma orden, doble clic en enviar, folios duplicados, existencias de inventario y cobros simultáneos (CV-12, CV-14, CV-16, CV-17, D-7).' }
    'cotizaciones'   = @{ archivos = 'backend/cotizaciones.php backend/lib/cotizaciones_helpers.php backend/lib/cotizacion_pdf.php scripts/cotizacion.js scripts/cotizaciones-comun.js scripts/cotizaciones-incidencia.js'
                          enfoque  = 'Cotizaciones: totales, IVA, monedas, estatus y permisos (sección 8).' }
    'finanzas'       = @{ archivos = 'backend/finanzas.php backend/lib/finanzas_helpers.php scripts/finanzas.js scripts/estado-cuenta.js'
                          enfoque  = 'Finanzas: cuentas por cobrar, cobros parciales, IVA de los abonos, cancelaciones, gastos y rentabilidad (sección 8).' }
    'inventario'     = @{ archivos = 'backend/inventario.php backend/lib/inventario_helpers.php scripts/inventario.js scripts/refacciones-incidencia.js'
                          enfoque  = 'Inventario: existencias, kárdex, consumos y devoluciones en órdenes (RP-16, D-13).' }
}

$tmp = Join-Path $raiz 'auditorias\.tmp'
New-Item -ItemType Directory -Force $tmp | Out-Null

if ($Diff) {
    $nombre = 'diff-' + ((git rev-parse --abbrev-ref HEAD) -replace '[^A-Za-z0-9._-]', '-')
    $mergeBase = (git merge-base $Base HEAD).Trim()
    $rutaDiff = Join-Path $tmp "$nombre.patch"
    $parche = (git diff $mergeBase -- . ':!marketing-video' ':!soluciones' ':!vendor' ':!auditorias') -join "`n"
    $nuevos = git ls-files --others --exclude-standard -- . ':!marketing-video' ':!soluciones' ':!auditorias'
    if (-not $parche -and -not $nuevos) { throw "No hay cambios contra $Base." }
    [IO.File]::WriteAllText($rutaDiff, $parche, $utf8)
    $listaNuevos = if ($nuevos) { ($nuevos -join ', ') } else { 'ninguno' }
    $alcance = "Los cambios de la rama actual contra '$Base'. El parche está en auditorias/.tmp/$nombre.patch (léelo completo). Archivos nuevos sin versionar: $listaNuevos. Lee también el contexto que necesites de los archivos tocados."
    $enfoqueModulo = 'Todo lo que el cambio pueda romper o dejar sin cumplir respecto a REGLAS_NEGOCIO.md, incluidas las reglas que el cambio debió implementar y no implementó, y si trae los tests que exige CLAUDE.md.'
} elseif ($modulos.ContainsKey($Objetivo)) {
    $nombre = $Objetivo
    $alcance = "Módulo '$Objetivo'. Archivos principales: $($modulos[$Objetivo].archivos). Sigue las llamadas a otros archivos cuando haga falta."
    $enfoqueModulo = $modulos[$Objetivo].enfoque
} else {
    if (-not (Test-Path (Join-Path $raiz $Objetivo))) { throw "No existe el módulo ni la ruta: $Objetivo" }
    $nombre = ($Objetivo.Trim('/\') -replace '[\\/]', '-' -replace '[^A-Za-z0-9._-]', '-')
    $alcance = "La ruta '$Objetivo' (y lo que llame)."
    $enfoqueModulo = 'Todas las reglas de REGLAS_NEGOCIO.md que apliquen a esa ruta.'
}
if ($Enfoque) { $enfoqueModulo += " Además: $Enfoque" }

# --- Huella del repositorio (para detectar cualquier escritura del auditor) ---
function Get-HuellaRepo {
    $partes = @()
    $partes += (git status --porcelain=v1 --untracked-files=all) -join "`n"
    $partes += (git diff --binary) -join "`n"
    foreach ($f in (git ls-files --others --exclude-standard)) {
        if ($f -like 'auditorias/*') { continue }
        $i = Get-Item -LiteralPath $f -ErrorAction SilentlyContinue
        if ($i) { $partes += "$f|$($i.Length)|$($i.LastWriteTimeUtc.Ticks)" }
    }
    $sha = [Security.Cryptography.SHA256]::Create()
    [BitConverter]::ToString($sha.ComputeHash($utf8.GetBytes(($partes -join "`n")))) -replace '-', ''
}

$prompt = @"
Lee primero GEMINI.md (tu rol y el formato obligatorio) y docs/REGLAS_NEGOCIO.md completo (la fuente de verdad; la sección 0 manda sobre el código).
ALCANCE: $alcance
ENFOQUE: $enfoqueModulo
Eres de solo lectura: no crees, edites ni borres archivos; no abras .env ni leas la carpeta auditorias/ (reportes anteriores: tu revisión debe ser independiente). Cita solo funciones y líneas que existan en lo que leíste. NO uses la terminal ni ejecutes comandos (están bloqueados y cortan tu trabajo): usa solo tus herramientas de leer archivos, listar carpetas y buscar texto.
Sé exhaustivo dentro del alcance: es preferible un hallazgo de confianza media bien explicado que callarlo.
Entrega el reporte completo en tu respuesta de texto, en español, con el formato de GEMINI.md, y termina con la tabla resumen y la línea FIN-DEL-REPORTE.
"@

$fecha = Get-Date -Format 'yyyy-MM-dd'
$destino = Join-Path $raiz "auditorias\$fecha-$nombre.md"
$n = 2
while (Test-Path $destino) { $destino = Join-Path $raiz "auditorias\$fecha-$nombre-$n.md"; $n++ }

Write-Host "Auditando '$nombre' con $Modelo (solo lectura). Puede tardar varios minutos..."
$antes = Get-HuellaRepo
$inicio = Get-Date
# Sin terminal: las herramientas de comandos quedan denegadas por regla (además de --mode plan).
$ajustes = Join-Path $tmp 'agy-ajustes.json'
[IO.File]::WriteAllText($ajustes, '{"permissions":{"deny":["command"]}}', $utf8)
$salida = & $agy --model $Modelo --mode plan --settings $ajustes --print-timeout "$($TiempoMaximoMin)m" -p $prompt 2>&1
$codigo = $LASTEXITCODE
$duracion = (Get-Date) - $inicio
$despues = Get-HuellaRepo

$texto = ($salida | ForEach-Object { "$_" }) -join "`n"
# Enlaces file:///C:/.../SERVIDESK/ruta -> ruta relativa (el reporte se versiona)
$raizUrl = 'file:///' + ($raiz -replace '\\', '/') + '/'
$texto = $texto.Replace($raizUrl, '')

# Nunca guardar secretos del .env aunque el auditor los haya visto
$redactados = 0
if (Test-Path (Join-Path $raiz '.env')) {
    foreach ($l in Get-Content (Join-Path $raiz '.env')) {
        if ($l -match '^\s*([A-Z0-9_]*(PASS|KEY|SECRET|TOKEN|CLABE)[A-Z0-9_]*)\s*=\s*"?([^"]{4,})"?\s*$') {
            $valor = $Matches[3].Trim()
            if ($texto.Contains($valor)) { $texto = $texto.Replace($valor, '[REDACTADO]'); $redactados++ }
        }
    }
}

$estado = 'COMPLETO'
$avisos = @()
if ($texto -match 'no output produced') { $estado = 'INCOMPLETO'; $avisos += 'agy no produjo reporte (probablemente intentó una herramienta bloqueada).' }
if ($texto -match 'mode plan has no effect|plan mode (is )?(disabled|ignored)') { $estado = 'DESCARTADO'; $avisos += 'agy avisó que el modo plan (solo lectura) no estaba activo.' }
if ($antes -ne $despues) { $estado = 'DESCARTADO'; $avisos += 'El repositorio cambió durante la auditoría (el auditor o alguien más escribió archivos). Revisa git status antes de usar este reporte.' }
if ($codigo -ne 0) { $estado = 'INCOMPLETO'; $avisos += "agy terminó con código $codigo." }
if ($texto -notmatch 'FIN-DEL-REPORTE') { if ($estado -eq 'COMPLETO') { $estado = 'INCOMPLETO' }; $avisos += 'El reporte no termina con FIN-DEL-REPORTE (posible corte por tiempo).' }
if ($redactados) { $avisos += "Se redactaron $redactados valor(es) secretos del .env que aparecían en la respuesta." }

$commit = (git rev-parse --short HEAD).Trim()
$rama = (git rev-parse --abbrev-ref HEAD).Trim()
$encabezado = @"
# Auditoría: $nombre

| Campo | Valor |
|---|---|
| Fecha | $(Get-Date -Format 'yyyy-MM-dd HH:mm') |
| Rama / commit | $rama / $commit$(if ((git status --porcelain --untracked-files=no)) { ' (con cambios sin commit)' }) |
| Auditor | Antigravity CLI, modelo ``$Modelo``, ``--mode plan`` (solo lectura) |
| Duración | $([int]$duracion.TotalMinutes) min $($duracion.Seconds) s |
| Estado | **$estado** |
$(if ($avisos) { "`n> " + ($avisos -join "`n> ") + "`n" })

---

"@

New-Item -ItemType Directory -Force (Split-Path $destino) | Out-Null
[IO.File]::WriteAllText($destino, $encabezado + $texto + "`n", $utf8)
$relativo = $destino.Substring($raiz.Length + 1) -replace '\\', '/'
Write-Host "Reporte: $relativo ($estado)"
if ($estado -ne 'COMPLETO') { foreach ($a in $avisos) { Write-Warning $a }; exit 2 }
