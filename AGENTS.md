# Servidesk: guía para agentes

Sistema marca blanca para empresas de servicio técnico: órdenes de servicio, clientes, inventario, cotizaciones, ventas y finanzas. Desde la 2.20.0 es satélite de Solid Suite: Solid Admin administra clientes, planes, pagos y vigencias por Solid Link (`docs/solid-link.md`).

## Roles (no se mezclan)
- **Claude Code:** arquitecto y codificador. Instrucciones completas en `CLAUDE.md`.
- **Antigravity / Gemini (`agy`):** **auditor de solo lectura**. Tu rol, formato y límites están en `GEMINI.md`; síguelo por encima de este archivo. Nunca modificas archivos y no lees `auditorias/`.

## Antes de empezar
- Fuente de verdad del negocio: `docs/REGLAS_NEGOCIO.md`. La sección 0 (decisiones del dueño) manda sobre el código.
- Tareas complejas (Claude): lee `docs/MEMORY.md` y actualízalo al cerrar un hito (subagente `context-keeper`).

## Stack
- PHP 8.2 + MySQL, sin Composer, hosting compartido de Hostinger. El repo completo se despliega en `public_html`; lo no público se bloquea en `.htaccess`.
- Frontend: HTML + JS sin framework (`scripts/*.js`, `css/`).

## Mapa
| Ruta | Qué es |
|---|---|
| `backend/*.php` · `backend/lib/*_helpers.php` | Endpoints · lógica testeable (los endpoints con `die()` se prueban por su helper) |
| `auth/`, `config/` | Sesión, permisos, conexión, Solid Link (`config/solid_link.php`, `api/solid.php`) |
| `scripts/`, `css/`, `*.html` | Frontend |
| `database/schema.sql` · `database/migrations/` | Esquema · migraciones (con su `_revertir.sql`) |
| `plataforma/` | Panel de plataforma (en modo satélite, sin cobranza propia) |
| `tests/` | Framework propio `tests/lib.php`, un archivo por área |
| `herramientas/auditar.ps1` · `auditorias/` | Auditoría con Gemini · reportes y triage |
| `marketing-video/`, `soluciones/` | **No tocar sin indicación del dueño** |

## Comandos (PowerShell; PHP y MariaDB de XAMPP)
```powershell
Start-Process C:\xampp\mysql\bin\mysqld.exe -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini','--standalone' -WindowStyle Hidden
C:\xampp\php\php.exe tests\ejecutar.php [filtro]     # pruebas
C:\xampp\php\php.exe -l <archivo.php>                # lint
powershell -ExecutionPolicy Bypass -File herramientas\auditar.ps1 -Diff   # auditoría (/auditar)
```

## Reglas no negociables
1. Test primero; todo bug se convierte primero en un test que falla.
2. Ningún cambio de lógica de negocio se cierra sin `/auditar` con Gemini (ver la excepción D-38 en `CLAUDE.md`). El subagente `security-auditor` de Claude es una revisión previa de seguridad: **no sustituye** a `/auditar`.
3. Decisiones nuevas del dueño → `docs/REGLAS_NEGOCIO.md` (secciones 0 y 10, con fecha) antes de implementarlas.
4. Nunca copiar secretos (`.env`, contraseñas, llaves) a reportes, tests ni commits.
