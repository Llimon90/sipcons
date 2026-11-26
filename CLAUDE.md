# Servidesk: instrucciones para Claude Code

Sistema marca blanca para empresas de servicio técnico (órdenes de servicio, clientes,
inventario, cotizaciones, ventas y finanzas). PHP 8.2 + MySQL, frontend en JS sin framework,
hosting compartido de Hostinger (sin Composer). El repositorio completo se despliega en
`public_html`: todo lo que no deba ser público se bloquea en `.htaccess`.

## Flujo de dos agentes

- **Claude Code (tú):** arquitecto y codificador. Escribes código y tests, y corriges.
- **Auditor (Gemini 4 Argon vía Antigravity CLI `agy`):** solo lee y reporta. Su rol está en
  `GEMINI.md`, y su fuente de verdad es `docs/REGLAS_NEGOCIO.md`.
- **Memoria compartida:** en tareas complejas lee `docs/MEMORY.md` y actualízalo al cerrar un hito
  (subagente `context-keeper`). `AGENTS.md` es el mapa del proyecto para otros agentes: si cambias
  comandos o estructura aquí, cámbialos también ahí. El subagente `security-auditor` no sustituye a `/auditar`.

## Reglas obligatorias

1. **Test primero.** Antes de implementar o cambiar cualquier regla de negocio, escribe el
   test que la verifica y comprueba que falla. Después implementa.
2. **Todo bug se convierte primero en un test que falla** (lo reporte el dueño o la
   auditoría). Después se corrige, y el test queda en la suite.
3. **No marques un hallazgo del auditor como falso positivo sin evidencia:** cita el código
   (archivo:línea) o el test que lo demuestra. Si hay duda, se escala al dueño como
   "REQUIERE DECISIÓN DEL DUEÑO".
4. **Ningún cambio de lógica de negocio se da por terminado sin pasar `/auditar`**
   (`/auditar --diff` sobre la rama). No se corrige ningún hallazgo sin que el dueño apruebe
   la lista del triage.
   *Excepción temporal (D-38, 2026-11-10):* mientras no haya cuota de Gemini 4 Argon, no
   se audita con modelos menores. Se despliega con tests en verde, y el cambio se anota en
   `auditorias/PENDIENTE_ARGON.md` para la auditoría de repaso con Argon.
5. **`docs/REGLAS_NEGOCIO.md` es la fuente de verdad.** Si el dueño toma una decisión nueva,
   se anota ahí (sección 0 y sección 10, con fecha) antes de implementarla.
6. **No modificar `marketing-video/` ni el sitio de ventas `soluciones/`** (incluido
   `soluciones/video/`) sin indicación explícita del dueño. Si un hallazgo los toca, se reporta
   y se pregunta.
7. Nunca usar modos de auto-aprobación con el auditor (`--dangerously-skip-permissions`) ni
   otro modelo que no sea Gemini para auditar (la independencia es el objetivo).

## Comandos

```powershell
# MySQL local (XAMPP), si no está corriendo
Start-Process C:\xampp\mysql\bin\mysqld.exe -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini','--standalone' -WindowStyle Hidden

# Tests (base local; crean y borran empresas temporales)
C:\xampp\php\php.exe tests\ejecutar.php            # todos
C:\xampp\php\php.exe tests\ejecutar.php incid      # archivos que contengan "incid"

# Auditoría (o el comando /auditar)
powershell -ExecutionPolicy Bypass -File herramientas\auditar.ps1 -Objetivo ciclo-vida
powershell -ExecutionPolicy Bypass -File herramientas\auditar.ps1 -Diff
```

## Convenciones de tests

- Framework propio sin dependencias: `tests/lib.php` (`prueba`, `grupo`, `igual`,
  `verdadero`, `lanza`, `empresaTemporal`, `usuarioTemporal`, `como`).
- Un archivo por área: `tests/<area>_test.php`.
- Los endpoints que terminan con `die()`/`exit` se prueban a través de su lógica en
  `backend/lib/*_helpers.php`. Si la regla vive dentro del endpoint, primero se extrae a un
  helper (test primero) y el endpoint lo llama.
- El nombre del test describe la regla y cita su ID: `D-9: un Técnico no abre una orden ajena`.

## Auditorías

- Reportes en `auditorias/AAAA-MM-DD-<objetivo>.md` y triage en `...-triage.md`. Se
  versionan en git (trazabilidad de calidad) y están bloqueados por web.
- Nunca copiar secretos (`.env`, contraseñas, llaves) a reportes, tests ni commits.
