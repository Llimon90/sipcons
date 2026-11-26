# Memoria del proyecto: SIPCONS

Memoria compartida entre agentes. Solo lo no obvio: el porqué, lo descartado y lo pendiente. Máximo 150 líneas; la mantiene el subagente `context-keeper`. Sin secretos ni datos de clientes.

## Estado actual / siguiente paso
- Rama de trabajo `pruebas`; producción en `master` (cPanel, `public_html/apptest`).
- Siguiente: validar en apptest (al amanecer del 2026-11-26) las dos rondas de upgrades de hoy
  (ServiceDesk + Finanzas/Inventario, ver hitos de hoy). Aplicar las migraciones 015-018 y
  030-034 en phpMyAdmin de apptest ANTES de probar (no se aplican solas; faltan 019-029 a
  propósito, dos agentes trabajaron en paralelo y se numeraron para no chocar). Confirmar en
  cPanel que el push a `pruebas` (commit `59ac639`) se desplegó a apptest o disparar el deploy
  manual si no es automático. Si algo no convence, revertir el commit correspondiente en
  `pruebas` — no se tocó `master`.

## Decisiones de arquitectura
| ID | Fecha | Decisión | Por qué |
|---|---|---|---|
| — | — | — | — |

## Deuda técnica
- 2026-11-22 · El repositorio es **público** y `.github/workflows/static.yml` publica el repo completo en GitHub Pages en cada push a `master` (incluye el código PHP como archivos estáticos). Decidir: volver privado el repo y/o quitar el workflow. Pendiente revisar que ningún secreto haya quedado en el historial de git (p. ej. con `gitleaks`).
- 2026-11-22 · Archivos de `backend/` que usan `->query(`/`mysqli_query(` con datos del usuario: los del módulo de clientes (`guardar-cliente.php`, `obtener-clientes.php`) ya se parametrizaron (2026-11-26). Quedan por revisar los demás con `security-auditor`.
- 2026-11-26 · Portal de clientes: el reset de contraseña NO envía correo real (no hay SMTP en apptest); el enlace queda en `storage/logs/portal_reset.log` y el admin lo copia a mano. Pendiente: configurar SMTP y mandar el correo real.
- 2026-11-26 · `cliente_id` en `incidencias` (migración 017) se rellena por coincidencia exacta de nombre con `clientes.nombre`; lo que no calce queda NULL (no aparece en el portal) y se vincula a mano con `backend/vincular-cliente-incidencia.php` — ese endpoint existe pero todavía no tiene pantalla en el admin.
- 2026-11-26 · Filtro de prioridad y gráfica por prioridad en `informes.html` no se hicieron esta ronda (el campo y el SLA configurable sí, el panel de estadísticas visual por prioridad queda pendiente).
- 2026-11-26 · Cuentas por cobrar quedó **desacoplada** de ventas (alta manual, `venta_id`/`cotizacion_id` solo de referencia) porque `ventas`/`venta_detalles` no manejaban ningún importe en dinero; no se generan CxC automáticas al vender. "Convertir cotización a venta" solo crea el encabezado en `ventas` (folio/cliente/sucursal/fecha), sin tocar `venta_detalles`/`padron_equipos` (eso es específico de equipos con número de serie).
- 2026-11-26 · `cliente_id` en `ventas` (migración 030) también se rellena por coincidencia exacta de nombre; igual que en incidencias, lo que no calce queda NULL y no aparece en el desglose de Finanzas hasta vincularse a mano.
- 2026-11-26 · Los 4 módulos nuevos (Inventario, Cotizaciones, Cuentas por cobrar, Finanzas) solo tienen enlace de navegación desde Inicio, Ventas y Clientes — falta agregar el enlace de sidebar en el resto de páginas (`informes.html`, `ajustes.html`, `usuarios.html`, etc.).
- Sin pruebas automáticas.

## Historial de hitos
- 2026-11-26 · Upgrades tipo ServiceDesk (una sola empresa, sin nada multiempresa): prioridad/severidad
  en incidencias con SLA configurable por categoría+prioridad (antes fijo en código, 4h/168h;
  `sla_reglas`, Ajustes → Reglas de SLA); portal de autoservicio de clientes con login propio y
  sesión 100% separada del personal (`public/portal/`, `auth/portal_middleware.php`) para ver/abrir
  sus tickets; se parametrizó el SQL de `guardar-cliente.php`/`obtener-clientes.php` (`b9f4bd2`).
- 2026-11-26 · Upgrades de Finanzas: Inventario de refacciones/productos con movimientos de stock,
  Cotizaciones (folio COT-#####, conversión a venta), Cuentas por cobrar con pagos parciales y
  vencimiento automático, y panel de Finanzas con desglose por cliente→sucursal (`finanzas-resumen.php`).
  Construido en un clon aparte del repo en paralelo al upgrade de ServiceDesk y fusionado después
  a `pruebas` sin conflictos (`59ac639`). Ambas rondas solo en `pruebas`, pendiente validar en apptest.
- 2026-11-19 · Rendimiento: sesión sin bloqueo, sin falsos logouts y menos peticiones PHP (`f38872e`).
- 2026-10 · Tickets automáticos de mantenimiento: una sola periodicidad y verificación de póliza.
