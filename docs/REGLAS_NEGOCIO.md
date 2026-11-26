# Reglas de negocio de Servidesk

Fuente de verdad para el auditor (ver `GEMINI.md`) y para las pruebas (`tests/`).
Extraídas del código el 2026-11-09 (rama `auditoria-prelanzamiento`, versión 2.12.x).

**Marcas de cada regla**

- **[CONFIRMADA EN CÓDIGO]**: el código la implementa así, con la ubicación citada.
- **[INFERIDA]**: se deduce de nombres, comentarios o de la interfaz, pero el backend no la hace cumplir.
- **[AMBIGUA/CONTRADICTORIA]**: distintas partes del código no coinciden, o falta la decisión del dueño.
- **[DECIDIDA AAAA-MM-DD]**: el dueño ya decidió cómo debe ser. **Manda sobre el código**:
  si el código hace otra cosa, es un hallazgo. Las decisiones del 2026-11-10 todavía
  no están implementadas; mientras no lo estén, el auditor las reporta como
  "regla decidida sin implementar" (severidad según su impacto), no como falso positivo.

Las respuestas del dueño están en la sección 10, y la sección 0 resume las reglas que deciden.

Terminología: en la interfaz el ticket se llama **orden de servicio**; en el código y
en la base de datos, **incidencia** (tabla `incidencias`).

---

## 0. Reglas decididas por el dueño (2026-11-10) — mandan sobre el código

### 0.1 Estatus (lista cerrada, revisada contra el estándar de gestión de servicio en campo)

El estándar de la industria (FSM / mesa de servicio, ITIL) separa: registrada → asignada →
en ejecución → en espera (con motivo) → trabajo terminado → cerrada administrativamente,
más una salida de cancelación. Contra eso, a la lista actual le faltaban "en ejecución" y
"cancelada", y "Pendiente" no pedía motivo. "Resuelto" aparecía en el código sin existir
en la interfaz, así que se quita.

| Estatus | Significado | Condiciones |
|---|---|---|
| **Abierto** | Registrada, sin técnico asignado. | — |
| **Asignado** | Tiene al menos un técnico, pero el trabajo no ha empezado. | Exige ≥ 1 técnico. |
| **En proceso** *(nuevo)* | El técnico está trabajando en el servicio (en sitio o en taller). | Exige ≥ 1 técnico. |
| **Pendiente** | En espera. **No pausa el SLA.** | Exige **motivo de pendiente**: refacción, aprobación de cotización, cliente o acceso, u otro con texto. |
| **Completado** | El técnico terminó el trabajo en sitio. Falta el cierre administrativo. | — |
| **Cerrado sin factura** / **Cerrado con factura** | Cierre administrativo. | Exige permiso de cierre (RP-12). |
| **Cancelado** *(nuevo)* | No se realizará (duplicada, el cliente desistió…). Sustituye a eliminar en la operación diaria. | Exige permiso de cierre y **motivo**. Queda fuera del SLA. |

- D-1 El backend **rechaza cualquier estatus que no esté en esta lista** (alta, edición, tickets automáticos, cotizaciones).
- D-2 **No hay transiciones prohibidas por orden**: una orden puede darse de alta o pasar directamente a Completado o Cerrado (servicios que se capturan después de hacerse). Solo aplican las condiciones de la tabla.
- D-3 **Reabrir** = salir de Cerrado o Cancelado hacia cualquier otro estatus. Solo lo hace quien puede cerrar (RP-12), y **exige motivo**, que queda en el historial.
- D-4 **Activa** = cualquier estatus salvo Cerrado sin factura, Cerrado con factura y Cancelado. Completado **es activa** (falta el cierre administrativo). Una sola definición para la lista, Inicio, alertas, búsqueda y el panel del técnico.
- D-5 **Asignar técnico**: si una orden en Abierto recibe técnico, pasa sola a Asignado. Si una orden en Asignado o En proceso se queda sin técnicos, regresa a Abierto.

### 0.2 Alta y edición

- D-6 El campo "Falla" pasa a llamarse **"Motivo del servicio"** en toda la interfaz (no todo servicio es atender una falla), y es **obligatorio en el alta y en la edición**. La columna `falla` se conserva en la base de datos.
- D-7 **Doble envío**: el backend debe impedir que un mismo envío del alta cree dos órdenes (con una clave de idempotencia por formulario).
- D-8 La **fecha de reporte** la puede editar cualquier usuario que pueda editar la orden (D-9).
- D-9 **Técnico: ve y edita solo las órdenes donde está asignado.** Aplica a la lista, la búsqueda, el detalle, la edición, los adjuntos, las refacciones, Inicio, el buscador global y las alertas; el backend debe responder 403 o 404 para las ajenas. Si un Técnico da de alta una orden **no** queda asignado automáticamente (decidido el 2026-11-10, P22): si no se asigna, deja de verla.
- D-10 Los técnicos asignados **deben existir en la empresa y tener un rol asignable** (Técnico, Supervisor, Administrador); el backend rechaza cualquier otro valor.
- D-11 Al **eliminar un usuario** asignado a órdenes activas, su nombre se quita de esas órdenes; las que se quedan sin técnicos regresan a Abierto (D-5), sin asignación. Las órdenes cerradas conservan el nombre (es historial).
- D-12 Borrar adjuntos de una orden exige el permiso `incidencias` (y, para el Técnico, que la orden sea suya). El permiso `ventas` solo sirve para adjuntos de ventas.
- D-13 **Sí** se pueden registrar o devolver refacciones en una orden cerrada (corrección posterior).

### 0.3 SLA

- D-14 Días **naturales**.
- D-15 *(Confirmada el 2026-11-10.)* La atención termina con **Completado** (o con un cierre directo, si no pasó por Completado). Si se reabre, el SLA se calcula **hasta la última** vez que se completó o cerró. Las canceladas no cuentan.
- D-16 Pendiente no pausa el reloj (D-1, tabla), pero exige motivo, para poder explicar el atraso.
- D-17 En el SLA por técnico, la orden se atribuye a **quien la completó o cerró** (el o los técnicos asignados en ese momento, según el historial).
- D-18 **Atrasada**: una orden activa está atrasada cuando han pasado **más** días completos que la meta: `días_transcurridos(fecha de reporte, hoy) > sla_dias`. Ejemplo: reporte el 1 de noviembre con meta de 7 días → el 8 de noviembre aún no está atrasada; el 9 sí. Un solo cálculo para Inicio, alertas y el panel del técnico.

### 0.3b Finanzas y conceptos de la orden

- D-22 **Corregir un cobro** = cancelarlo con motivo y registrar uno nuevo con los datos ya llenos. Lo pueden hacer el **Administrador y el Administrativo**. El cobro original queda cancelado en el historial; un cobro nunca se edita.
- D-23 La orden registra **conceptos aplicados** de tres tipos: refacción o consumible (descuenta existencias), servicio o mano de obra (no descuenta; viene del catálogo de servicios, sin existencias) y equipo de reemplazo (descuenta existencias y da de alta el equipo, con su serie, en el padrón del cliente). Cobrar: "Cotizar lo aplicado" (trabajo hecho, no vuelve a descontar) o "Cotizar revisión técnica" (la orden pasa a Pendiente con motivo "aprobación de cotización").

### 0.3c Decisiones del 2026-11-10 (recomendaciones de la ronda piloto aceptadas por el dueño)

- D-24 Los tickets automáticos quedan en el historial como creados por "Sistema" (H-15).
- D-25 Editar una orden exige la `version` que se leyó; si falta, 400 (H-18). El conflicto (409) y "guardar de todos modos" siguen igual.
- D-26 El cliente de una orden debe existir en el catálogo (con alta rápida desde el formulario). El alta acepta "Trabajo realizado" (`accion`), visible al elegir Completado o Cerrado (H-19).
- D-27 El Técnico solo ve de los clientes nombre, sucursales y contactos, y solo de los clientes de sus órdenes; no ve RFC, crédito ni facturación (P-H04).
- D-28 Marcas, modelos y manuales: el Técnico solo consulta; crean, editan y borran el Administrador y el Supervisor, con historial (P-H10).
- D-29 Zona horaria: el registro de empresa la pregunta (prellenada con la del navegador); `APP_TIMEZONE` de esta instancia = `America/Tijuana`. No hay empresas reales todavía, todas son simuladas (ZH-X).
- D-30 Las órdenes que nacen en Completado o Cerrado (capturas posteriores) no entran a las muestras de SLA. Las de fecha futura miden desde su fecha, sin negativos (S-H08).
- D-31 La hora UTC en el historial se agrega después del lanzamiento (S-H09).
- D-32 Corregir el nombre de un técnico lo actualiza en sus órdenes activas (las cerradas conservan el nombre). Pasar a un rol no asignable equivale a una baja (D-11) (A-H04).
- D-33 Una orden Completado no cuenta como atrasada (D-15). Aviso aparte en Inicio: "N completadas con más de 5 días sin cerrar" (N-H02).
- D-34 El IVA de los abonos (O-4) se implementa proporcional por cobro **solo después de validarlo con el contador**.

### 0.3d Decisiones del 2026-11-10 (mañana)

- D-35 **D-27 queda sin efecto.** El Técnico sigue viendo el catálogo de clientes como hoy. P-H04 se cierra como aceptado por el dueño.
- D-36 **Unicidad por empresa.** Folios, nombres de usuario, clientes, correos de clientes y demás datos de captura solo deben ser únicos **dentro de cada empresa**: otra empresa puede repetirlos, porque cada registro está ligado a su empresa. Todo índice único nuevo va en las tablas de la empresa (`e<ID>_`) o incluye `empresa_id`. **Excepción, confirmada por el dueño el 2026-11-10:** el correo de *inicio de sesión* sigue siendo único en toda la plataforma (MT-5), porque el login identifica a la empresa por el correo.
- D-37 **Implementación del paso 4 (0.1).**
  - **Motivo del estatus.** Un solo campo, `motivo_estatus`, guarda el motivo del estatus actual (Pendiente o Cancelado) y se borra al salir de él. El motivo de una reapertura queda en el historial.
  - **Estatus heredados.** La migración 010 los convierte así:
    - Vacío o "Programado" → Abierto, o Asignado si la orden tiene técnico.
    - "Resuelto" → Completado.
    - Las órdenes en Pendiente sin motivo reciben "Otro: sin motivo registrado (antes de la versión 2.13)".
  - **Técnicos y estatus.** Si al editar se quitan todos los técnicos de una orden en Asignado o En proceso, pasa sola a Abierto (D-5). Elegir Asignado o En proceso sin técnico se rechaza.
- D-38 **Auditorías en pausa mientras no haya Gemini 4 Argon.** El dueño no confía en Gemini 3.8 Flash como auditor. Se pausan las auditorías hasta que se renueve la cuota de Argon (hacia el 2026-11-17).
  - Mientras tanto, cada cambio se despliega con tests primero y la suite completa en verde.
  - Lo desplegado sin auditar se lista en `auditorias/PENDIENTE_ARGON.md`.
  - Con Argon se hace una auditoría de repaso de todo lo que esté en esa lista antes del lanzamiento comercial.
- D-39 **Implementación de "Conceptos aplicados" (D-23, punto 2; 2026-11-11).**
  - **Servicio o mano de obra:** se guarda en `incidencia_servicios` (migración 015) con el precio del catálogo. No toca existencias y se puede quitar.
  - **Refacción o consumible:** sale del inventario, igual que antes.
  - **Equipo de reemplazo:** sale del inventario y se da de alta en el padrón del cliente con origen "Reemplazo", ligado a la orden y al artículo.
    - Exige una serie que no esté ya en el padrón y se registra de una pieza en una pieza.
    - No se puede devolver al almacén mientras siga en el padrón.
  - **Eliminar la orden:** devuelve sus refacciones y equipos (RP-14), y además quita sus servicios y los equipos de reemplazo que dio de alta en el padrón.
  - **"Cotizar lo aplicado":** incluye refacciones, equipos y servicios.

### 0.3e Decisiones del 2026-11-12 (análisis de arquitectura y UX)

- D-40 **Baja de técnico y órdenes Completado** (ajusta D-11). Al dar de baja a un técnico, las órdenes en **Completado** conservan su nombre y su estatus, igual que las cerradas y canceladas: el trabajo sí lo hizo él. Solo las órdenes en Abierto, Asignado, En proceso y Pendiente lo pierden.
- D-41 **Equipos del cliente desde la orden.**
  - Una orden puede llevar **varios equipos** (números de serie).
  - Si la serie no está en el padrón del cliente, se da de alta desde la misma orden con un modal: equipo, marca, modelo y sucursal de la orden.
  - Lo puede hacer cualquier usuario que pueda editar la orden, **incluido el Técnico**, pero solo desde la orden. En el padrón el Técnico sigue sin editar ni borrar.
- D-42 **Órdenes programadas.**
  - Una orden con **fecha futura** está programada para ese día (no hace falta otro campo). Mientras no llegue la fecha, no cuenta como activa: no aparece en la bandeja ni en los contadores, las alertas, el atraso o el SLA (`sqlOrdenActiva`).
  - El día indicado se vuelve activa sola. El SLA mide desde la fecha programada.
  - Programa o pospone (cambia la fecha) quien pueda dar de alta órdenes; el cambio de fecha queda en el historial.
  - Las ven todos los usuarios en el filtro "Programadas", con D-9: el Técnico ve solo las suyas.
- D-43 **Servicio y Mano de obra quedan separados.**
  - **Mano de obra** es tiempo: se cobra por hora, admite horas decimales (1.5 h) y su precio es la **tarifa por hora** que fija cada empresa en Mi empresa.
  - **Servicio** es un precio cerrado (mantenimiento, visita, calibración). Su precio y los **conceptos que incluye** se definen en Mi empresa y se muestran en la cotización.
- D-44 **Multisucursal.**
  - Cada cliente tiene un catálogo de sucursales: nombre, dirección, contacto y teléfono.
  - Las órdenes, el padrón, las ventas y las cotizaciones guardan la sucursal elegida (`sucursal_id`). El texto se conserva como historial.
  - La migración crea las sucursales a partir de los textos que ya existen.
- D-45 **Serie de refacciones.** Un artículo puede marcar "controla serie". Al aplicarlo en una orden se pide el N/S de cada pieza, que se guarda en el movimiento. El catálogo no exige serie.
- D-46 **Perfil del técnico.**
  - Es una página con datos, foto, métricas y órdenes activas.
  - Cada usuario sube **solo su propia** foto: imagen de hasta 1 MB, recodificada en el servidor para quitarle los metadatos.
  - El Administrador ve el perfil de cualquier técnico.
- D-47 **Cotizaciones.**
  - "Nueva versión" y "Duplicar" quedan como están, salvo que "Duplicar" ya no copia el vínculo con la orden de servicio, porque es un trabajo nuevo.
  - Hay vista previa del PDF dentro del sistema, con la marca "BORRADOR" mientras no se envíe.
  - Ni "Cotizar lo aplicado" ni la aprobación vuelven a descontar o a registrar lo que ya se aplicó en la orden. Esto vale también para los **equipos de reemplazo**: no generan una "venta por registrar".
- D-48 **Finanzas en el perfil del cliente.**
  - La pestaña Finanzas del cliente (saldo, cuentas, cobros y notas de crédito) solo la ve quien tiene el permiso `finanzas`, y el servidor lo valida.
  - Límite de crédito opcional, con aviso al crear una cuenta o cotización que lo rebase.
  - **Nota de crédito:** reduce el saldo de una cuenta, exige motivo y admite folio o UUID del CFDI de egreso. No se borra, se cancela, igual que un cobro (D-22). No hay timbrado; el tratamiento fiscal lo valida cada empresa con su contador.

### 0.3f Decisión del 2026-11-13 (cobro de la orden)

- D-49 **Una orden de servicio se cobra por un solo camino.** *(Recomendación aplicada mientras el dueño la confirma; ver P34.)*
  - **Con cotización aprobada:** la cuenta por cobrar sale de la cotización. «Crear cuenta» desde la orden usa la cotización, y la orden no aparece aparte en «Pendientes de registrar».
  - **Sin cotización:** la cuenta sale de la orden con el importe de sus **conceptos aplicados** ya cargado: refacciones y equipos al precio de venta del catálogo, y servicios al precio con que se aplicaron, más el IVA por defecto de la empresa. Se puede ajustar antes de guardar.
  - **Un camino bloquea al otro:**
    - Si la orden ya tiene su cuenta propia, su cotización no se cobra aparte ni queda pendiente.
    - Si la cotización ya tiene cuenta, la orden no se cobra otra vez por sus conceptos.
  - **Por qué no solo desde cotización:** en trabajos chicos o de garantía, obligar a cotizar agrega un paso sin valor. El riesgo real era el doble cobro, y lo resuelve el bloqueo.

### 0.3g Decisión del 2026-11-16 (Solid Suite)

- D-50 **Servidesk es un sistema satélite de Solid Suite.** Los clientes, planes, pagos y vigencias de todos los sistemas (Servidesk, Caja+, Jornada…) se administran en **Solid Admin**, una app independiente (`admin.<dominio de la suite>`). Cada sistema obedece por Solid Link (`docs/solid-link.md`). La decisión es del dueño (2026-11-16) y se tomó pensando en escalar el negocio: una sola fuente de cobranza, cada producto en su propio dominio y fallas aisladas.
  - **Modo satélite:** se activa con `SOLID_LINK_TOKEN` en el `.env`. Sin token, Servidesk funciona como antes con su panel `/plataforma`.
  - **Qué aplica Servidesk:** el panel envía `estado`, `vence` (último día de operación normal, con la gracia ya incluida), `plan` y `limites.usuarios`.
    - Al pasar `vence`, la cuenta queda en **solo lectura hasta que el panel decida**: Servidesk no suma gracia propia ni se suspende sola.
    - El límite de usuarios del panel aplica también durante la prueba.
  - **Alta desde el panel:** crea la empresa, sus tablas y su administrador, cuyo usuario es el correo del contacto, con una contraseña temporal que el panel entrega una sola vez.
  - **Lo que se desactiva en modo satélite (una sola fuente de verdad):**
    - La contratación en línea (Contratar plan).
    - En `/plataforma`: altas, datos, plan, pagos, solicitudes, extender prueba, estado, eliminar y planes.
    - Siguen activos: soporte, notas, marca, configuración y cuentas de plataforma.
  - **Registro autoservicio:** sigue creando empresas. Solid Admin las adopta con "Importar cuentas".

### 0.3h Decisiones del 2026-11-24 (auditoría integral)

- D-51 **Precio congelado de los conceptos aplicados** (L-10). Las refacciones y los equipos aplicados a una orden se valúan, para cobrarla (D-49) y para "Cotizar lo aplicado", al precio de venta **del día en que se aplicaron**, igual que los servicios. Cambiar el precio del catálogo no cambia lo que ya se aplicó. Una devolución regresa al precio con que salió la pieza.
- D-52 **El Supervisor sí puede ver el límite y los días de crédito del cliente** (S-7). D-48 (saldo, cuentas, cobros y notas de crédito solo con el permiso `finanzas`) no cambia.
- D-53 **Clientes únicos dentro de cada empresa** (L-22, ajusta D-36): el **nombre** y el **correo** de un cliente no se repiten dentro de la empresa (sin distinguir mayúsculas ni espacios de sobra). El correo puede quedar vacío. Otra empresa sí puede repetirlos.
- D-54 **Compartir por WhatsApp** (cabe dentro de D-19 y D-21). Cotización y estado de cuenta se pueden compartir con un botón que abre WhatsApp (`wa.me`) con un mensaje y un **enlace de solo lectura que caduca**. No es una notificación automática ni un portal: lo envía la persona, a mano, y el enlace no da acceso a nada más.
- D-56 **Modo satélite, aclaraciones de D-50** (L-11, L-12, L-13, Q-3):
  - Una **prueba** vencida también queda en **solo lectura** (no bloqueada), y una prueba sin `vence` no vence.
  - El alta desde el panel valida todo antes de crear nada; si el correo ya tiene cuenta responde 409 y el panel usa "restablecer acceso".
  - La contraseña que entrega el panel es **temporal**: el usuario la cambia antes de usar el sistema (migración 021). Cambiar la contraseña se permite también en solo lectura.
- D-57 **Integridad del catálogo de clientes** (L-4 a L-7, L-29, L-32; aplica D-53):
  - Renombrar un cliente actualiza el nombre en **todas** sus órdenes, su padrón y sus ventas (se ligan por nombre). Las cotizaciones y cuentas por cobrar conservan el nombre con que se emitieron (se ligan por id). El cambio queda en el historial.
  - Un cliente con órdenes, ventas, equipos, cotizaciones o **cuentas por cobrar** no se elimina. Uno sin historial se elimina con sus sucursales.
  - Un equipo de una **venta propia** se corrige desde la venta, no desde el padrón. Eliminar una venta quita sus equipos del padrón; las órdenes que los atendieron conservan la serie.
  - Usar en una orden una sucursal desactivada la vuelve a activar.
- D-58 **Cotizaciones como documento** (L-9, L-14, L-15, L-19, L-27):
  - Al **enviar**, lo que incluye cada servicio queda congelado: editar el catálogo no cambia una cotización enviada o aprobada (migración 022). El borrador sigue el catálogo.
  - Una cotización **vencida** se puede marcar como aprobada si quien la captura confirma que el cliente la aprobó dentro de la vigencia; si no, se saca una nueva versión.
  - "Cargar refacciones en la orden" no descuenta las que controlan serie (D-45): esas se registran desde la orden con su N/S.
  - El aviso de límite de crédito convierte a pesos las cotizaciones en dólares. El PDF de una versión sustituida lleva la marca SUSTITUIDA.
- D-60 **Datos y constancias del cliente de Servidesk** (auditoría integral, P0 legal):
  - «Exportar mis datos»: el Administrador descarga un ZIP con un CSV por módulo (también en solo lectura). Sin contraseñas; los adjuntos se descargan desde cada registro.
  - Al registrarse se guarda la constancia de la aceptación: correo, fecha, IP, navegador y las direcciones y la versión del aviso de privacidad y de los términos (`APP_TERMINOS_URL`, `APP_TERMINOS_VERSION`). El texto de los términos lo redacta y aprueba el abogado.
  - D-54 se implementa con enlaces firmados (HMAC, llave por instancia en `plataforma_config`) que vencen a los 7 días; una cuenta suspendida o cancelada ya no los sirve.
- D-55 **Una segunda cuenta por cobrar de la misma orden o venta se confirma** (L-1). Si la orden o la venta ya tiene una cuenta vigente, registrar otra pide confirmación ("¿un cargo adicional?") y queda en el historial. Una venta registrada desde una cotización **comparte el monto aprobado** de esa cotización: lo cobrado por la cotización y por sus ventas no pasa de lo aprobado, así nunca se cobra dos veces y sí se puede cobrar el resto (L-2, H-03 de la tercera auditoría). No se elimina una orden ni una venta que tenga una cuenta por cobrar vigente: primero se cancela la cuenta.
- D-59 **Confirmaciones y fechas de la orden** (bloque 6 de la auditoría integral):
  - Cancelar una orden que tiene cuenta por cobrar, cotización enviada o aprobada, equipos de reemplazo o conceptos aplicados pide confirmación; cambiarle el cliente con esos documentos, también. Queda en el historial (L-16, L-17).
  - La fecha de la orden debe existir y no estar a más de un año (L-18).
  - Al importar el padrón, el próximo mantenimiento es el siguiente que toca a partir de hoy, con meses de calendario (L-21).
  - Una orden Completado con fecha futura no es «programada» (L-34). En solo lectura, consultar no escribe nada (L-20).
  - Notas de crédito y gastos no llevan fecha futura; un cobro no es anterior a la fecha de su cuenta (L-26).

### 0.4 Notificaciones y adopción

- D-19 No se requieren notificaciones por correo o WhatsApp para el lanzamiento.
- D-20 En su lugar: **asistente guiado** para los primeros accesos, distinto por rol, que el usuario puede cerrar y volver a abrir cuando quiera (ver `docs/BACKLOG_PRELANZAMIENTO.md`).
- D-21 No habrá portal de clientes antes del lanzamiento: el aislamiento por auditar es entre empresas y entre usuarios internos (D-9).

---

## 1. Multiempresa y aislamiento de datos

| ID | Regla | Estado |
|---|---|---|
| MT-1 | Cada empresa tiene sus propias tablas con prefijo `e<ID>_`. Las conexiones `$pdo`/`$conn` reescriben los nombres de tablas de empresa (`TABLAS_EMPRESA`) con el prefijo de la empresa en sesión. Sin empresa activa no se reescribe nada y la consulta falla (falla cerrada). `config/empresa.php` (`sqlConEmpresa`), `config/database.php`. | [CONFIRMADA EN CÓDIGO] |
| MT-2 | Las tablas centrales `empresas`, `usuarios`, `pagos`, `analytics_eventos` y `plataforma_config` no llevan prefijo. Toda consulta a `usuarios` desde una empresa debe filtrar por `empresa_id = $_SESSION['empresa_id']`. | [CONFIRMADA EN CÓDIGO] en los endpoints de usuarios revisados; el auditor debe verificar los demás |
| MT-3 | La reescritura solo reconoce tablas después de `FROM`, `JOIN`, `INTO`, `UPDATE`, `TABLE` y `EXISTS`. Una tabla de empresa escrita de otra forma (por ejemplo `FROM a, incidencias`) no se reescribe y la consulta falla en vez de filtrar datos. | [CONFIRMADA EN CÓDIGO] |
| MT-4 | Los archivos subidos viven en `uploads/e<ID>/` y `manuales/e<ID>/`. `backend/archivo.php` solo los entrega a usuarios con sesión de esa misma empresa, y nunca como HTML/SVG/JS. | [CONFIRMADA EN CÓDIGO] |
| MT-5 | Los correos de acceso son únicos en toda la plataforma, y el mensaje de "correo ocupado" no revela de qué empresa es. `config/usuarios.php`. | [CONFIRMADA EN CÓDIGO] |
| MT-6 | Los folios (incidencias, cotizaciones, ventas) son consecutivos **por empresa**. | [CONFIRMADA EN CÓDIGO] (test `cotizaciones_test.php` para cotizaciones) |

## 2. Roles y permisos

### 2.1 Roles

| ID | Regla | Estado |
|---|---|---|
| RP-1 | Roles asignables: **Administrador, Supervisor, Administrativo, Técnico** (`ROLES_ASIGNABLES`, `auth/permisos.php`). Los roles antiguos `Técnico/Administrador` y `Programador` se normalizan a Administrador. | [CONFIRMADA EN CÓDIGO] |
| RP-2 | **Soporte de plataforma**: el superadministrador entra a una empresa en modo soporte con acceso total, fijo en el código. Entra aunque la cuenta esté vencida o suspendida. | [CONFIRMADA EN CÓDIGO] |
| RP-3 | **Superadmin** sin empresa solo opera el panel de plataforma (`plataforma/api.php`, `requireSuperadmin`); cualquier endpoint de empresa le responde 403. | [CONFIRMADA EN CÓDIGO] |
| RP-4 | **No existe un rol ni un portal de cliente final.** Los clientes de la empresa no inician sesión; solo los usuarios internos lo hacen. | [CONFIRMADA EN CÓDIGO] |

### 2.2 Permisos por módulo (tabla `permisos_rol`, configurable)

Valores por defecto de `database/plantilla_empresa.sql` (1 = permitido):

| Módulo | Administrador | Supervisor | Administrativo | Técnico |
|---|---|---|---|---|
| incidencias | 1 | 1 | 1 | 1 |
| reportes (búsqueda) | 1 | 1 | 1 | 1 |
| clientes | 1 | 1 | 1 | 0 |
| cotizaciones | 1 | 1 | 1 | 0 |
| ventas | 1 | 1 | 1 | 0 |
| inventario | 1 | 1 | 1 | 0 |
| finanzas | 1 | 0 | 1 | 0 |
| usuarios | 1 | 0 | 0 | 0 |
| soporte (manuales) | 1 | 1 | 0 | 1 |
| informes (estadísticas) | 1 | 1 | 1 | 0 |
| historial | 1 | 0 | 0 | 0 |

| ID | Regla | Estado |
|---|---|---|
| RP-5 | Cada endpoint del backend exige su módulo con `requirePermiso`/`requirePermisoAlguno`/`tienePermiso`. Sin el permiso responde 403. Ante un error de base de datos al consultar permisos, se niega el acceso. | [CONFIRMADA EN CÓDIGO] |
| RP-6 | Solo un **Administrador** (o Soporte) edita la tabla de privilegios; es un chequeo fijo de rol, no de `permisos_rol` (`requireGestionPrivilegios`). | [CONFIRMADA EN CÓDIGO] |
| RP-7 | Acciones reservadas al Administrador por chequeo fijo (`esAdministracion()`): eliminar incidencias, clientes y ventas; importar CSV; cancelar cuentas por cobrar y gastos; eliminar cotizaciones (solo en borrador). | [CONFIRMADA EN CÓDIGO] (tests de finanzas y cotizaciones cubren parte) |
| RP-8 | Solo un Administrador crea, edita o elimina cuentas de **Administrador**. Nadie puede eliminarse a sí mismo, y no se puede eliminar al último Administrador de la empresa. `config/usuarios.php`, `backend/eliminar_usuario.php`. | [CONFIRMADA EN CÓDIGO] (test `seguridad_test.php`) |
| RP-9 | La sesión se revalida en cada petición: si el usuario se elimina, cambia de empresa o le cambian la contraseña, se cierra; un cambio de rol aplica sin cerrar sesión. `revalidarUsuarioSesion`. | [CONFIRMADA EN CÓDIGO] (test `seguridad_test.php`) |
| RP-10 | El frontend oculta páginas por módulo (`scripts/auth-guard.js`, `PAGINA_A_MODULO`), pero la protección real es la del backend (RP-5). | [CONFIRMADA EN CÓDIGO] |

### 2.3 Permisos sobre incidencias

| ID | Regla | Estado |
|---|---|---|
| RP-11 | Quien tiene el módulo `incidencias` **ve y edita todas las incidencias de su empresa**, sin importar a quién estén asignadas. Esto incluye al Técnico, que puede cambiar cliente, fecha, técnicos asignados, falla, notas y estatus de órdenes ajenas. `backend/detalle.php`, `backend/actualiza.php`, `backend/server.php`. **Decisión del dueño: pendiente (P1).** | [AMBIGUA/CONTRADICTORIA] |
| RP-12 | **Cerrar** = pasar a `Cerrado con factura` o `Cerrado sin factura`. El rol Técnico nunca puede; los demás pueden, salvo que su cuenta tenga `puede_cerrar = 0`. Solo se bloquea *cambiar a* cerrado: una orden que ya estaba cerrada se puede seguir editando. `puedeCerrarIncidencias()` en `config/auth.php`, `actualiza.php`, `server.php`. | [CONFIRMADA EN CÓDIGO] |
| RP-13 | Una orden se puede **dar de alta ya cerrada** si el usuario puede cerrar. `server.php`. | [CONFIRMADA EN CÓDIGO] |
| RP-14 | **Eliminar** una incidencia: solo Administrador o Soporte. El borrado es físico, pero el historial conserva la foto completa. Las refacciones consumidas regresan al inventario, y las cotizaciones, gastos y cuentas por cobrar se conservan sin la liga. `backend/eliminar-incidencia.php`. | [CONFIRMADA EN CÓDIGO] |
| RP-15 | Eliminar archivos adjuntos de una incidencia lo puede hacer cualquiera con `incidencias` **o** `ventas` (`backend/eliminar_archivo.php`). No está claro si alguien que solo tiene `ventas` debería poder borrar adjuntos de una incidencia. | [AMBIGUA/CONTRADICTORIA] |
| RP-16 | Las refacciones se registran o devuelven en una incidencia con el permiso `incidencias`; solo se devuelve lo que se registró en esa misma orden, y la orden debe existir. `backend/inventario.php` (`consumir_incidencia`, `devolver_incidencia`). No se valida el estatus: se pueden registrar refacciones en una orden cerrada. | [CONFIRMADA EN CÓDIGO]; lo de órdenes cerradas es [AMBIGUA] |
| RP-17 | El Técnico puede crear desde su incidencia un borrador de cotización y editarlo, pero no enviarlo. | [CONFIRMADA EN CÓDIGO] (test `cotizaciones_test.php`) |

### 2.4 Estado de la cuenta de la empresa

| ID | Regla | Estado |
|---|---|---|
| RP-18 | La situación de la empresa se calcula a partir de sus fechas (`situacionEmpresa`, `config/empresa.php`). `demo`, `prueba` vigente, `activa` y `gracia` (hasta `dias_gracia` = 7 días de atraso) dan acceso total. `solo_lectura` (hasta `dias_solo_lectura` = 15) solo permite GET/HEAD. Una prueba vencida, `suspendida` o `cancelada` queda bloqueada. | [CONFIRMADA EN CÓDIGO] |
| RP-19 | Mientras se verifica un pago (`verificacion_hasta`), una empresa en prueba o activa conserva el acceso total. | [CONFIRMADA EN CÓDIGO] |
| RP-20 | Una empresa en solo lectura o bloqueada no genera tickets automáticos. | [CONFIRMADA EN CÓDIGO] |
| RP-21 | D-50: en modo satélite (`SOLID_LINK_TOKEN`) `dias_gracia` vale 0 y no hay suspensión por atraso. El vencimiento ya trae la gracia de Solid Admin y la suspensión la envía el panel. `limite_usuarios` del panel manda sobre el plan. Ver `tests/solid_link_test.php`. | [CONFIRMADA EN CÓDIGO] |

## 3. Ciclo de vida de la incidencia

### 3.1 Estatus

| ID | Regla | Estado |
|---|---|---|
| CV-1 | Estatus que ofrece la interfaz (`scripts/detalle.js`): **Abierto, Asignado, Pendiente, Completado, Cerrado sin factura, Cerrado con factura**. | [CONFIRMADA EN CÓDIGO] (frontend) |
| CV-2 | El backend **no valida el estatus**: la columna es `varchar(100)` y `actualiza.php`/`server.php` guardan cualquier texto (incluido vacío). Un estatus fuera de la lista desaparece de la lista principal (CV-6) y del filtro "solo activas". | [CONFIRMADA EN CÓDIGO]; si debe ser lista cerrada es **Decisión del dueño (P2)** |
| CV-3 | El flujo esperado es Abierto → Asignado → (Pendiente ↔ Asignado) → Completado → Cerrado con/sin factura. | [INFERIDA] (orden de la interfaz y significado de los nombres) |
| CV-4 | **Transiciones:** el backend permite pasar de cualquier estatus a cualquier otro; la única restricción es RP-12 (cerrar). | [CONFIRMADA EN CÓDIGO]; si debe haber transiciones prohibidas es **Decisión del dueño (P3)** |
| CV-5 | **Reabrir:** se puede pasar una orden cerrada a cualquier estatus abierto. Lo puede hacer cualquiera con `incidencias`, incluido el Técnico (que no puede cerrar). No se pide motivo y no se registra como "reapertura" aparte del historial. | [CONFIRMADA EN CÓDIGO]; quién puede reabrir y si exige motivo es **Decisión del dueño (P4)** |
| CV-6 | **Qué cuenta como "activa"** no coincide en el código: | [AMBIGUA/CONTRADICTORIA] (P5) |
| | – Lista principal (`server.php` GET) y filtro "solo activas" de la búsqueda (`buscar_reportes.php`): `estatus IN ('Abierto','Asignado','Pendiente','Completado')`. Deja fuera los estatus vacíos o desconocidos. | |
| | – Inicio, bandeja y contador del menú (`inicio.php`): `estatus IS NULL OR NOT LIKE 'Cerrado%'`. Completado cuenta como activa. | |
| | – Alertas de atraso (`alertas_incidentes.php`): `NOT LIKE 'Cerrado%'`. Completado cuenta como activa, pero las de estatus NULL quedan fuera. | |
| | – Estadísticas y SLA (`estadisticas_helpers.php`, `ESTADOS_CERRADOS`): `completado`, `cerrado con factura`, `cerrado sin factura` y `resuelto` cuentan como **cerradas**. "Resuelto" no existe en la interfaz. | |
| | – Pendientes del técnico (`estadisticas_tecnico.php`): Completado **no** es pendiente. | |
| CV-7 | **Cancelar:** no existe un estatus de cancelada. La única forma de retirar una orden es eliminarla (RP-14). | [CONFIRMADA EN CÓDIGO]; si se necesita "Cancelada" es **Decisión del dueño (P6)** |
| CV-8 | **Fusionar** órdenes duplicadas: no existe. | [CONFIRMADA EN CÓDIGO] (no aplica) |
| CV-9 | Cuando se aprueba una cotización ligada y el usuario lo confirma, la orden pasa de `Pendiente` a `Asignado`, solo si sigue en Pendiente (`cotReactivarIncidencia`). | [CONFIRMADA EN CÓDIGO] |

### 3.2 Alta

| ID | Regla | Estado |
|---|---|---|
| CV-10 | Alta: son obligatorios (no vacíos) `cliente`, `contacto` (quién reporta) y `fecha`; los campos `reporte_cliente`, `sucursal`, `tecnico`, `status`, `falla` y `notas` solo tienen que venir en el envío, aunque vengan vacíos. `server.php`. | [CONFIRMADA EN CÓDIGO] |
| CV-11 | En la **edición** además es obligatoria la `falla` (`actualiza.php`). En el alta no lo es. | [AMBIGUA/CONTRADICTORIA] (P7) |
| CV-12 | Folio: `<prefijo de la empresa>-NNNN`, el número del último folio + 1, tomado con `ORDER BY id DESC LIMIT 1 FOR UPDATE` dentro de la transacción (`siguienteFolioIncidencia`, `config/branding.php`). No hay índice único sobre `numero_incidente`. | [CONFIRMADA EN CÓDIGO] |
| CV-13 | El alta y sus adjuntos se guardan juntos o no se guarda nada. Antes de confirmar, se vuelve a leer lo guardado y se compara con lo enviado. | [CONFIRMADA EN CÓDIGO] |
| CV-14 | Doble envío del alta: el frontend deshabilita el botón mientras guarda (`scripts/script.js`), pero el backend no tiene protección contra duplicados (ni clave de idempotencia ni detección), así que dos POST iguales crean dos órdenes. | [CONFIRMADA EN CÓDIGO]; si se requiere protección en backend es **Decisión del dueño (P8)** |
| CV-15 | La `fecha` (fecha de reporte) se puede cambiar después del alta, y moverla cambia los tiempos de SLA (SL-3). | [CONFIRMADA EN CÓDIGO]; si debe quedar fija es **Decisión del dueño (P9)** |

### 3.3 Edición concurrente

| ID | Regla | Estado |
|---|---|---|
| CV-16 | `detalle.php` entrega una `version` (hash del registro). `actualiza.php` bloquea la fila (`FOR UPDATE`) y, si la versión no coincide, responde 409 con quién y cuándo la modificó. El usuario puede forzar (`forzar=1`), y eso queda en la bitácora. | [CONFIRMADA EN CÓDIGO] |
| CV-17 | Si el envío no trae `version`, se guarda sin comprobar conflictos. | [CONFIRMADA EN CÓDIGO] |
| CV-18 | Los adjuntos que superan el límite rechazan todo el guardado (413/415) con un mensaje que nombra el archivo. | [CONFIRMADA EN CÓDIGO] |

### 3.4 Tickets automáticos de mantenimiento

| ID | Regla | Estado |
|---|---|---|
| CV-19 | Al consultar la lista de incidencias (GET de `server.php`) se ejecuta `generador_tickets.php`, como máximo cada 600 s y en un solo proceso por empresa (candado de archivo). | [CONFIRMADA EN CÓDIGO] |
| CV-20 | Equipos **sin póliza** (`servicio = 0`, `calibracion > 0`) generan un `ALERTA-CONTACTO`, y equipos **con póliza** (`servicio = 1`, `frecuencia_servicio > 0`) un `AUTO-SERV`, cuando su próxima fecha cae dentro de los próximos 10 días. Se agrupa un ticket por cliente+sucursal, con estatus `Abierto`, sin técnico, contacto "Sistema <marca>" y la categoría de preventivo de la empresa (o la primera de su lista). | [CONFIRMADA EN CÓDIGO] |
| CV-21 | Después de generar el ticket, la próxima fecha del equipo avanza hasta quedar después del límite: un equipo atrasado genera **un** ticket, no uno por periodo vencido. 31 de enero + 1 mes = 28/29 de febrero. | [CONFIRMADA EN CÓDIGO] |
| CV-22 | Si un grupo falla, los demás se generan y no se marca la hora de la última corrida, así que se reintenta. | [CONFIRMADA EN CÓDIGO] |
| CV-23 | Los tickets automáticos no quedan registrados en el historial (`auditoria`), así que su tiempo de respuesta se mide desde la `fecha` (SL-3). | [CONFIRMADA EN CÓDIGO] |

## 4. Asignación

| ID | Regla | Estado |
|---|---|---|
| AS-1 | `incidencias.tecnico` es **texto libre** con varios técnicos separados por `/`. Guarda el `alias_tecnico` del usuario o, si no tiene, su nombre. No hay llave foránea a `usuarios`. | [CONFIRMADA EN CÓDIGO] |
| AS-2 | La lista de técnicos asignables incluye a los usuarios de la empresa con rol Técnico, Supervisor o Administrador (no a los Administrativos), y el valor no se repite (`obtener_tecnicos.php`). | [CONFIRMADA EN CÓDIGO] |
| AS-3 | El backend **no valida** que los técnicos asignados existan, sean de la empresa o tengan un rol asignable: guarda el texto recibido. | [CONFIRMADA EN CÓDIGO]; si debe validarse es **Decisión del dueño (P10)** |
| AS-4 | No hay relación entre estatus y asignación: puede haber una orden `Asignado` sin técnico o `Abierto` con técnico. | [CONFIRMADA EN CÓDIGO]; **Decisión del dueño (P11)** |
| AS-5 | No hay asignación automática. Los tickets automáticos nacen sin técnico, y la búsqueda de mantenimientos programados los muestra como "Por asignar". | [CONFIRMADA EN CÓDIGO] |
| AS-6 | Al **eliminar** un usuario, sus órdenes conservan su nombre como texto: no se reasignan ni se avisa. Ya no aparece en la lista de asignables. Si se le **cambia el nombre**, deja de reconocer sus órdenes anteriores (en su panel y en las estadísticas), salvo que se le ponga un `alias_tecnico`. | [CONFIRMADA EN CÓDIGO]; qué hacer con las órdenes abiertas de un usuario eliminado es **Decisión del dueño (P12)** |
| AS-7 | Un técnico ve "sus" órdenes en su panel por coincidencia exacta de su identidad (nombre o alias tomado de la **sesión**, nunca de la URL) con alguno de los nombres separados por `/`. | [CONFIRMADA EN CÓDIGO] |
| AS-8 | No existe el concepto de usuario "inactivo" (solo existe o se elimina). | [CONFIRMADA EN CÓDIGO] |

## 5. SLA (meta de cumplimiento) y tiempos

| ID | Regla | Estado |
|---|---|---|
| SL-1 | **Meta de cierre** = `sla_dias` de la empresa (Mi empresa, de 1 a 90, 7 por defecto) × 24 h. Son días **naturales**: no hay horario laboral ni días festivos. `slaDiasEmpresa`, `slaCierreHoras`. | [CONFIRMADA EN CÓDIGO]; si deben ser días hábiles es **Decisión del dueño (P13)** |
| SL-2 | **Meta de primera respuesta** = 4 h fija (`SLA_RESPUESTA_HORAS`), no configurable. La respuesta es el primer cambio de estatus fuera de `Abierto`/vacío. | [CONFIRMADA EN CÓDIGO] |
| SL-3 | **Arranque:** la `fecha` de reporte a las 00:00. Si el alta registrada en el historial fue el mismo día, se usa la hora real del alta. | [CONFIRMADA EN CÓDIGO] |
| SL-4 | **Cierre** = la **primera** vez que el estatus pasa a uno de `ESTADOS_CERRADOS` (incluye Completado). Una reapertura no reinicia ni extiende el cálculo; los cierres posteriores se ignoran. | [CONFIRMADA EN CÓDIGO]; qué debe pasar al reabrir es **Decisión del dueño (P14)** |
| SL-5 | **Pausa:** no existe; `Pendiente` (por ejemplo, esperando refacción o cotización) no detiene el reloj. | [CONFIRMADA EN CÓDIGO]; **Decisión del dueño (P15)** |
| SL-6 | **Reasignación:** no afecta el reloj. En el SLA por técnico, todo el tiempo de cierre se atribuye a los técnicos asignados *al momento de consultar*, no a quien la tenía cuando cerró. | [CONFIRMADA EN CÓDIGO]; **Decisión del dueño (P16)** |
| SL-7 | Los tiempos salen del historial (`auditoria`). Guardar sin cambiar el estatus no cuenta como cierre. Las órdenes anteriores al historial no tienen respuesta medible. | [CONFIRMADA EN CÓDIGO] |
| SL-8 | Si falla el registro en el historial, la operación sigue (`registrarAuditoria` nunca interrumpe), y ese cambio no cuenta para el SLA. | [CONFIRMADA EN CÓDIGO] |
| SL-9 | **Atraso:** una orden activa está atrasada cuando lleva más días sin cerrarse que `sla_dias`. Cada lugar lo calcula distinto: | [AMBIGUA/CONTRADICTORIA] (P5, P17) |
| | – Alertas: `fecha < NOW() - INTERVAL sla DAY`. | |
| | – Inicio: `DATEDIFF(CURDATE(), fecha)` contra `DIAS_ATRASO`. | |
| | – Panel del técnico: `ahora - fecha > sla*24h`. Además usa su propia definición de activa (CV-6). | |

## 6. Escalamiento y notificaciones

| ID | Regla | Estado |
|---|---|---|
| EN-1 | **No existe escalamiento** (ni automático ni manual) por tiempo, prioridad o atraso. | [CONFIRMADA EN CÓDIGO] |
| EN-2 | **No hay campo de prioridad** en las incidencias. | [CONFIRMADA EN CÓDIGO] |
| EN-3 | **No se envían notificaciones** (correo, WhatsApp, push) por alta, asignación, cambio de estatus o atraso. Solo hay avisos dentro de la app: bandeja de Inicio, alertas de atraso y panel del técnico. | [CONFIRMADA EN CÓDIGO]; si se requieren para el lanzamiento es **Decisión del dueño (P18)** |

## 7. Fechas y zonas horarias

| ID | Regla | Estado |
|---|---|---|
| ZH-1 | La zona de PHP es la de la empresa (`marca.zona_horaria`, en Mi empresa); sin ella, `APP_TIMEZONE`, y por defecto `America/Mexico_City`. `config/env.php`, `establecerEmpresa`. | [CONFIRMADA EN CÓDIGO] |
| ZH-2 | En cada conexión, MySQL usa el **desfase numérico** de ese momento (`SET time_zone = '-07:00'`), porque no todo hosting tiene tablas de husos con nombre. | [CONFIRMADA EN CÓDIGO] |
| ZH-3 | `incidencias.fecha` es `DATE` (sin hora). `auditoria.creado_en` es `DATETIME` y guarda la hora local de la empresa **sin zona**. | [CONFIRMADA EN CÓDIGO] |
| ZH-4 | Si la empresa cambia de zona, o en el cambio de horario de verano (Mexicali y Tijuana sí lo tienen), las horas ya guardadas no se convierten: los tiempos que cruzan ese momento pueden quedar ±1 h (o lo que cambie la zona). | [INFERIDA] de ZH-2 y ZH-3 |
| ZH-5 | Las fechas de cuenta (prueba, pago, gracia) se calculan con `today` en la zona de la empresa activa. Los procesos sin empresa (cron, plataforma) usan `APP_TIMEZONE`. | [CONFIRMADA EN CÓDIGO] |
| ZH-6 | El usuario no tiene una zona propia: todos ven la hora de la empresa. | [CONFIRMADA EN CÓDIGO] |

## 8. Reglas de otros módulos que ya tienen pruebas

Se resumen como referencia; el detalle está en los tests.

- **Cotizaciones** (`tests/cotizaciones_test.php`): IVA 16/8/0/exento, redondeo por partida, transiciones de estatus válidas, enviada = no editable, versiones `-vN`, rechazo con motivo, vencimiento, 409 por edición concurrente, descuento máximo salvo permiso, margen visible solo para Administrador/Administrativo, y aprobar no mueve inventario. [CONFIRMADA EN CÓDIGO]
- **Finanzas** (`tests/finanzas_test.php`): el vencimiento sale de los días de crédito; retenciones; cobros parciales y cancelación recalculan el saldo; solo el Administrador cancela; no se cobra más de lo aprobado; rentabilidad = ingreso sin IVA − refacciones a costo − gastos sin IVA. [CONFIRMADA EN CÓDIGO]
- **Importación CSV** (`tests/importar_test.php`): todo o nada; equipos con cliente existente y series sin repetir; cada empresa importa en sus tablas. [CONFIRMADA EN CÓDIGO]
- **Seguridad** (`tests/seguridad_test.php`): sesiones (RP-9), cuentas de Administrador (RP-8), 5 intentos fallidos bloquean 15 min, errores de base de datos que no llegan al usuario. [CONFIRMADA EN CÓDIGO]

## 9. Reglas sin ninguna prueba hoy

Todas las de las secciones 3 a 7 (ciclo de vida, asignación, SLA, alertas y zonas
horarias), además de RP-11 a RP-16 y MT-1 a MT-4 aplicadas a incidencias.

## 10. Preguntas para el dueño

Respondidas el 2026-11-10; las reglas resultantes están en la sección 0. Las secciones 1 a 9 describen lo que hace el código **hoy**.

| # | Pregunta | Reglas | Respuesta |
|---|---|---|---|
| P1 | ¿El Técnico debe ver y editar **todas** las órdenes de la empresa, o solo las asignadas a él? ¿Puede cambiar la asignación, el cliente o la fecha de una orden? | RP-11 || 2026-11-10: solo las suyas → D-9 |
| P2 | ¿El estatus debe ser una **lista cerrada** (los 6 de la interfaz) y el backend rechazar cualquier otro valor? | CV-2 || 2026-11-10: sí, lista cerrada revisada contra el estándar → 0.1, D-1 |
| P3 | ¿Hay transiciones **prohibidas**? Por ejemplo: Abierto → Cerrado sin pasar por Completado, o Completado sin técnico asignado. | CV-4 || 2026-11-10: no; puede llegar ya completada o cerrada → D-2 |
| P4 | **Reabrir** una orden cerrada: ¿quién puede (¿también el Técnico?), y debe pedir motivo? | CV-5 || 2026-11-10: sí (solo quien puede cerrar, con motivo) → D-3 |
| P5 | ¿**Completado** es cerrada o activa? Hoy cuenta como cerrada para el SLA y el panel del técnico, y como activa para Inicio, alertas y la lista principal. ¿Qué significa Completado frente a Cerrado? | CV-6, SL-9 || 2026-11-10: Completado = el técnico terminó en sitio → D-4, D-15 |
| P6 | ¿Se necesita un estatus **Cancelada** (por ejemplo, duplicada o el cliente desistió) en lugar de eliminar? | CV-7 || 2026-11-10: sí → 0.1 (Cancelado) |
| P7 | ¿La **falla** debe ser obligatoria también en el alta? | CV-11 || 2026-11-10: sí, y renombrar a "Motivo del servicio" → D-6 |
| P8 | ¿Quieres protección en el servidor contra **doble envío** del alta, o basta con el botón deshabilitado? | CV-14 || 2026-11-10: sí → D-7 |
| P9 | ¿La **fecha de reporte** se puede editar después del alta? ¿Quién puede? | CV-15 || 2026-11-10: sí, cualquier usuario → D-8 |
| P10 | ¿El backend debe rechazar técnicos asignados que no existan o no sean asignables? | AS-3 || 2026-11-10: sí → D-10 |
| P11 | ¿`Asignado` exige al menos un técnico? Al asignar un técnico a una orden `Abierto`, ¿debe pasar sola a `Asignado`? | AS-4 || 2026-11-10: sí y sí → 0.1, D-5 |
| P12 | Al **eliminar** a un técnico con órdenes abiertas: ¿bloquear la eliminación, avisar, reasignar o dejarlas así? | AS-6 || 2026-11-10: quedan abiertas y sin asignación → D-11 |
| P13 | ¿La meta de SLA se mide en días **naturales** o **hábiles** (con horario laboral y festivos)? | SL-1 || 2026-11-10: naturales → D-14 |
| P14 | Al **reabrir**: ¿el SLA de cierre se calcula hasta el último cierre, se reinicia o se suma el tiempo reabierto? | SL-4 || 2026-11-10: hasta el último cierre → D-15 |
| P15 | ¿`Pendiente` (en espera de cliente o refacción) debe **pausar** el SLA? | SL-5 || 2026-11-10: no pausa, pero exige motivo → D-16 |
| P16 | En el SLA por técnico, ¿a quién se atribuye una orden **reasignada**: a quien la cerró o a todos los que la tuvieron? | SL-6 || 2026-11-10: a quien cierra → D-17 |
| P17 | Atraso: ¿cuenta desde la fecha de reporte en días completos (con "7 días" = atrasada al día 8)? | SL-9 || 2026-11-10: atrasada después de un día con el SLA vencido → D-18 |
| P18 | ¿Se requieren **notificaciones** (correo o WhatsApp) para el lanzamiento? ¿A quién y en qué eventos? | EN-3 || 2026-11-10: no; en su lugar, un asistente guiado por rol → D-19, D-20 |
| P19 | ¿Alguien con solo el permiso de `ventas` debe poder borrar adjuntos de una orden de servicio? | RP-15 || 2026-11-10: no → D-12 |
| P20 | ¿Se pueden registrar refacciones en una orden ya **cerrada**? | RP-16 || 2026-11-10: sí → D-13 |
| P21 | Tu lista menciona que "un usuario o **cliente** no pueda ver tickets ajenos". Hoy los clientes no tienen acceso al sistema. ¿Está planeado un portal de clientes antes del lanzamiento? | RP-4 || 2026-11-10: no → D-21 |
| P22 | Si un Técnico da de alta una orden, ¿queda asignado automáticamente? | D-9 | 2026-11-10: no → D-9 |
| P23 | ¿El SLA de atención termina en Completado? | D-15 | 2026-11-10: sí → D-15 |
| P26 | ¿El Técnico debe dejar de ver RFC, crédito y facturación de los clientes (D-27)? | D-27 | 2026-11-10 (mañana): no importa, se queda como está → D-35 |
| P27 | ¿Qué datos pueden repetirse entre empresas? | MT-6, AS-7 | 2026-11-10 (mañana): todos, cada uno ligado a su empresa → D-36 |
| P28 | ¿El correo de inicio de sesión sigue siendo único en toda la plataforma? | MT-5, D-36 | 2026-11-10: sí, se queda único → D-36 |
| P24 | ¿Quién corrige un cobro registrado por error? | D-22 | 2026-11-10: Administrador y Administrativo → D-22 |
| P25 | Modelo de "conceptos aplicados" en la orden (refacción, servicio, equipo de reemplazo) | D-23 | 2026-11-10: aprobado → D-23 |
| P29 | Al dar de baja a un técnico, ¿las órdenes Completado conservan su nombre? | D-11 | 2026-11-12: sí, conservan nombre y estatus → D-40 |
| P30 | ¿El Técnico puede dar de alta equipos en el padrón? | D-28 | 2026-11-12: sí, solo desde una orden, con su modal → D-41 |
| P31 | Órdenes programadas: ¿quién las programa y quién las ve? | D-1, D-30 | 2026-11-12: las programa quien puede dar de alta órdenes y las ven todos → D-42 |
| P32 | ¿Servicio y Mano de obra se separan? | D-23 | 2026-11-12: sí; la empresa fija la tarifa por hora y los servicios con lo que incluyen → D-43 |
| P33 | ¿Multisucursal antes del lanzamiento? | — | 2026-11-12: sí → D-44 |
| P34 | ¿Las cuentas por cobrar se crean solo desde una cotización, o también desde la orden de servicio? | D-23, D-47 | 2026-11-13: recomendación aplicada (un solo camino por orden) pendiente de confirmar → D-49 |
| P35 | ¿El cobro de una orden se congela al precio del día en que se aplicó el concepto? | D-49 | 2026-11-24: sí → D-51 |
| P36 | ¿El Supervisor puede ver el límite de crédito del cliente? | D-48 | 2026-11-24: sí → D-52 |
| P37 | ¿El correo de un cliente debe ser único dentro de la empresa? | D-36 | 2026-11-24: sí → D-53 |
| P38 | ¿"Compartir por WhatsApp" (manual, con enlace de solo lectura) cabe dentro de D-19 y D-21? | D-19, D-21 | 2026-11-24: sí → D-54 |
