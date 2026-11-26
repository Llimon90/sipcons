# Despliegue en Hostinger

Servidesk es una plataforma **multiempresa**: una sola instalación (un sitio,
una base de datos) atiende a todas las empresas. Cada empresa tiene sus
propias tablas (`e7_incidencias`, `e7_clientes`…) y sus propias carpetas de
archivos (`uploads/e7`, `manuales/e7`); al iniciar sesión, el sistema solo usa
las de la empresa del usuario.

- **Demo pública:** es la empresa 1 (estado `demo`), se reinicia cada noche.
  Los prospectos entran por `https://SITIO/demo`.
- **Prueba de 15 días:** una empresa en estado `prueba` con su fecha límite.
- **Cliente:** la misma empresa pasa a `activa`; nada se migra. Tus clientes
  entran por `https://SITIO/` (login limpio, sin datos de la demo).

---

## 1. Sitio y PHP

1. hPanel → **Sitios web**: crea el sitio (o usa el dominio temporal) y
   verifica en **Seguridad → SSL** que tenga certificado. El `.htaccess` fuerza HTTPS.
2. **Avanzado → Configuración de PHP:** PHP **8.1 o superior** (probado con 8.2 y 8.3),
   con las extensiones `dom`, `mbstring` y `gd` (el PDF de cotizaciones las usa;
   en Hostinger vienen activas).

## 2. Base de datos

hPanel → **Bases de datos → Bases de datos MySQL**: crea una base y un usuario
y anota los tres datos. Luego en **phpMyAdmin → Importar**:

**Instalación nueva**
1. `database/schema.sql` → plataforma + empresa 1 (demo) vacía.
2. `database/seed.sql` → datos de la demo (opcional, recomendado).

**Instalación existente de una sola empresa** (anterior al modelo multiempresa)
1. Respalda la base (phpMyAdmin → Exportar).
2. Despliega el código nuevo (paso 3) e importa **una sola vez**
   `database/migrations/001_multiempresa.sql`: las tablas actuales pasan a ser
   de la empresa 1 sin perder datos.
3. Importa `database/seed.sql` si esa empresa es la demo.

**Actualizaciones de la plataforma** (respalda antes; cada una se importa una
vez, en orden):
- `database/migrations/002_modelo_negocio.sql`: planes y precios, ciclo
  mensual/anual, datos fiscales, notas internas, configuración del negocio y
  anulación de pagos. Necesaria para el panel de plataforma 2.1.
- `database/migrations/003_permiso_cierre.sql`: permiso por usuario para
  cerrar incidencias (Usuarios → Editar → "Puede cerrar incidencias").
  Sin ella todo funciona, pero la casilla no se guarda.
- `database/migrations/004_solicitudes_pago.sql`: contratación por
  transferencia (sección 6). Sin ella el botón "Contratar plan" no aparece.
- `database/migrations/005_marca_por_plan.sql`: qué planes incluyen la
  personalización de marca (Básico no; Profesional y Empresa sí). Sin ella
  todos los planes pueden personalizar.

- `database/migrations/006_roles.sql`: quedan cuatro roles (Administrador,
  Supervisor, Administrativo y Técnico). Los usuarios Técnico/Administrador y
  Programador pasan a Administrador (con constancia en el historial de su
  empresa). El código ya los trata como Administrador aunque no se importe.
- `database/migrations/007_cotizaciones.sql`: módulo de cotizaciones y
  catálogo único (servicios y mano de obra en Inventario, sin existencias).
  Crea las tablas en **todas las empresas existentes** con un procedimiento
  temporal, agrega el módulo a Privilegios por rol y la casilla "Puede
  autorizar descuentos". Sin ella el menú muestra Cotizaciones pero el módulo
  responde que no está instalado.
- `database/migrations/008_lanzamiento.sql`: bloqueo temporal tras intentos
  fallidos de inicio de sesión (tabla `login_intentos`), tipos de movimiento
  de cobranza (pago, abono y nota de crédito en `pagos.tipo`), marca incluida
  en todos los planes y foto de producto en Inventario (en todas las
  empresas). Sin ella el login no bloquea, abonos y notas de crédito se
  rechazan y no se pueden subir fotos de producto; lo demás funciona.
- `database/migrations/009_finanzas.sql`: módulo de Finanzas en todas las
  empresas (tablas `cuentas_cobrar`, `cobros`, `gastos`; `clientes.dias_credito`;
  permiso "finanzas" para Administrador y Administrativo). Sin ella el menú
  muestra Finanzas pero el módulo responde que no está instalado.
- `database/migrations/010_estatus_motivos.sql` (2.13): lista cerrada de 8
  estatus en todas las empresas (agrega En proceso y Cancelado) y la columna
  `motivo_estatus` (motivo de Pendiente y de Cancelado). Convierte los estatus
  heredados (vacío o "Programado" → Abierto o Asignado según tenga técnico,
  "Resuelto" → Completado) y pone un motivo genérico a las Pendiente que no lo
  tienen. **Importarla antes de desplegar la 2.13**: sin ella no se pueden
  guardar órdenes en Pendiente ni Cancelado.
- `database/migrations/011_folios_unicos.sql` (2.13.2): contador de folios
  por empresa (`folios`: el folio de algo eliminado no se reutiliza y dos
  altas simultáneas no repiten folio), clave de envío del alta (un doble clic
  no crea dos órdenes), folio único en órdenes y ventas, y nombre de usuario
  único dentro de cada empresa. Si ya hay folios o nombres repetidos, renombra
  los más nuevos (`SD-0001-57`, `Ana López (57)`). **Importarla antes de
  desplegar la 2.13.2**: el reinicio nocturno de la demo la necesita.
- `database/migrations/012_indice_adjuntos.sql` (2.13.3, opcional): índice en
  los adjuntos de las órdenes para que se busquen más rápido. Sin ella todo
  funciona igual.
- `database/migrations/013_asistente.sql` (2.14.0): guarda en la cuenta de
  cada usuario si ya vio el recorrido guiado. Sin ella el recorrido no se abre
  solo la primera vez (sí desde Ayuda → Recorrido guiado).
- `database/migrations/014_preferencias.sql` (2.14.1): preferencias personales
  en la cuenta del usuario (por ejemplo, "no volver a mostrar" la explicación
  al enviar una cotización). Sin ella esa explicación se sigue mostrando siempre.
- `database/migrations/015_conceptos_aplicados.sql` (2.15.0): conceptos
  aplicados en la orden de servicio: servicios y mano de obra (sin existencias)
  y equipos de reemplazo que quedan en el padrón del cliente. **Importarla antes
  de usar esas opciones**: sin ella la orden solo registra refacciones y avisa
  que falta la actualización.

El sitio de ventas (`soluciones/`) y la importación desde CSV (2.12.0) no
necesitan migración. Los precios del sitio salen de la tabla `planes`; el
WhatsApp y el correo, de Configuración en el panel de plataforma; el aviso de
privacidad, de `APP_AVISO_PRIVACIDAD_URL` en `.env`. Con el dominio
definitivo, pon `APP_URL` en `.env` para que el sitemap use esa dirección.

- `database/migrations/019_solid_link.sql` (2.20.0, D-50): agrega `empresas.limite_usuarios` y
  `empresas.plan_externo` para el **modo satélite de Solid Suite**. Con `SOLID_LINK_TOKEN` en el `.env`,
  **Solid Admin** (app independiente) administra clientes, planes, pagos y vigencias por `/api/solid/v1`
  (contrato en `docs/solid-link.md`). Captura en Solid Admin → Sistemas la URL del sitio
  (`https://app.tudominio.com`) y el mismo token, y luego usa Probar e Importar cuentas.
  Sin token, nada cambia. Revertir: `019_solid_link_revertir.sql`.
- Auditoría integral (2.21.0 a 2.23.x, 2026-11-25). El código funciona sin ellas, pero cada una activa su mejora; se pueden volver a importar sin error. **Impórtalas con codificación utf8mb4** (phpMyAdmin lo hace así; en consola usa `--default-character-set=utf8mb4`):
  - `020_precio_congelado.sql`: precio de venta congelado de cada pieza aplicada (D-51). Revertir: `020_precio_congelado_revertir.sql`.
  - `021_cambio_password.sql`: contraseña temporal que se cambia al entrar (L-13). Revertir: `021_cambio_password_revertir.sql`.
  - `022_incluye_congelado.sql`: lo que incluye cada servicio se congela al enviar la cotización (L-9); congela las que ya no son borrador. Revertir: `022_incluye_congelado_revertir.sql`.
  - `023_corregir_acentos.sql`: corrige «versi├│n» si la 010 se importó con otra codificación (no toca nada si está bien).
  - Opcional en `.env`: `APP_TERMINOS_URL` y `APP_TERMINOS_VERSION` (términos del registro en línea; los redacta el abogado).

Las migraciones 002 a 009 se pueden volver a importar sin error. 006 a 009
tienen su archivo `_revertir.sql` (el de 007 **borra las cotizaciones** y el de
009 **borra cuentas por cobrar, cobros y gastos**).
Si phpMyAdmin rechaza `CREATE PROCEDURE`, importa el archivo marcando
"Delimitador" vacío o usa la pestaña SQL; los procedimientos se borran solos
al terminar.

**Datos de prueba de cotizaciones (solo local):**
`php database/tools/seed_cotizaciones.php --empresa=<id>` crea 6 servicios y 5
cotizaciones en distintos estatus.

**Pruebas automáticas (solo local):** `php tests/ejecutar.php` (crea empresas
temporales en la base local y las borra al terminar).

**Bitácora de guardados fallidos:** `storage/logs/guardados-AAAA-MM.log`
(Administrador de archivos de hPanel). Una línea por cada alta o edición que
no se completó, con la empresa (`e<ID>`), el usuario y el motivo.

## 3. Despliegue automático desde GitHub

hPanel → **Avanzado → GIT** → **Desplegar desde GitHub**:

1. **Continúa con GitHub** → autoriza la aplicación de Hostinger dando acceso
   solo al repositorio `servidesk` (*Only select repositories*).
2. Repositorio `Llimon90/servidesk`, rama **`master`**, sin comando de build,
   raíz del repositorio → **Implementar**.

Cada cambio fusionado a `master` se publica en segundos. El despliegue no toca
`.env`, `uploads/` ni la base de datos. Para comprobar qué versión está
publicada, abre `/version.txt` en el sitio.

## 4. Archivo `.env`

Administrador de archivos → `public_html` → crea `.env` (con el punto, sin
`.txt`) a partir de `.env.example`:

- `DB_NAME`, `DB_USER`, `DB_PASS` del paso 2 (`DB_HOST=localhost`).
- `APP_URL` con la URL pública y `APP_TIMEZONE`.
- Marca **de la plataforma** (`APP_NAME`, colores…): es la que se ve en el
  login. La marca de cada empresa se guarda en la base (tabla `empresas`).
- `DEMO_MODE=true` si la plataforma ofrece la demo pública (página `/demo`
  con las credenciales visibles y reinicio nocturno de la empresa 1). El login
  de clientes nunca muestra las credenciales.

## 5. Tu cuenta de plataforma (superadministrador)

Es el usuario sin empresa que administra empresas, pruebas y pagos. En tu PC,
desde la carpeta del proyecto:

```
php database/tools/crear_superadmin.php --correo=tu@correo.com --password="(10+ caracteres)" --solo-sql
```

Copia el `INSERT` que imprime y ejecútalo en phpMyAdmin → **SQL**. La
contraseña viaja ya cifrada; nunca queda escrita en el repositorio. Al iniciar
sesión con esa cuenta entras al **panel de plataforma** (`/plataforma/`).

## 6. Empresas: registro, panel y ciclo de vida

**Registro autoservicio (pruebas de 15 días).** Con `REGISTRO_ABIERTO=true`, la
página `/demo` muestra "Prueba gratis 15 días" (`/auth/registro.html`). El prospecto
escribe su empresa, nombre, correo y contraseña, y entra directo a su espacio
con su prueba activa. Protecciones: campo trampa para bots, tiempo mínimo de
llenado y máximo `REGISTRO_MAX_POR_IP` registros por IP al día.

**Panel de plataforma** (`/plataforma/`, solo cuentas de plataforma):
- **Resumen**: MRR y ARR, clientes que pagan, ingreso promedio por cuenta,
  cobrado del mes contra el anterior, conversión de pruebas, por cobrar,
  suspendidas, altas y cancelaciones; gráficas de ingresos, cartera, altas
  y MRR por plan; actividad reciente.
- **Empresas**: cartera con plan, MRR, situación, próximo hito, nivel de uso y
  filtros; exportación CSV; alta manual. La **ficha** de cada empresa tiene
  resumen (volumen de datos y almacenamiento), facturación (plan, ciclo,
  precio pactado, registrar y anular pagos con folio de factura), datos
  fiscales y de contacto, notas internas, marca, usuarios y bitácora.
  Acciones: **entrar como soporte** (con aviso y botón para volver), extender
  prueba, suspender o reactivar, cancelar, exportar datos (ZIP) y eliminar
  (solo canceladas, confirmando su identificador).
- **Solicitudes de pago**: contrataciones y renovaciones por transferencia
  por verificar (ver abajo), con su comprobante y datos de factura.
- **Cobranza**: cobrado en el mes, cuentas con pago vencido, renovaciones de
  los próximos 30 días, pagos sin factura y CSV de pagos para contabilidad.
- **Pruebas**: embudo (registros → uso → pago) y seguimiento de pruebas en
  curso y vencidas.
- **Planes y precios**: catálogo con precio mensual/anual, límite de
  usuarios (se aplica al dar de alta usuarios) y si incluye la
  personalización de marca.
- **Bitácora**: toda acción de plataforma, con filtros.
- **Configuración**: días de prueba, de gracia y de solo lectura, plan de las
  pruebas, registro abierto/cerrado, límite por IP, contacto comercial (sale
  en los avisos de pago), datos bancarios para transferencias, días de acceso
  mientras verificas un pago y moneda. Sustituye a los valores del `.env`.
- **Administradores**: otras cuentas de plataforma y cambio de tu contraseña.

Por consola (SSH) también existe:
`php database/tools/crear_empresa.php --nombre="..." --correo=... --password="..." --estado=prueba`

**Ciclo de una empresa** (se calcula solo a partir de sus fechas):

| Situación | Acceso |
|---|---|
| `prueba` vigente | Total, con aviso de días restantes |
| `prueba` vencida | Bloqueado, con mensaje para contratar |
| `activa`, pago al corriente | Total |
| 1–7 días de atraso | Total, con aviso de pago |
| 8–15 días de atraso | Solo lectura (consultar y exportar) |
| 16+ días de atraso | Bloqueado |

(Los días de gracia y de solo lectura se ajustan en Configuración.)
| `suspendida` / `cancelada` | Bloqueado |

Los pagos se registran en el panel. En ningún estado se borran datos.

**Contratación por transferencia (sin pasarela de pago).** Requiere la
migración 004 y tus datos bancarios en Configuración.

1. El administrador de la empresa entra a **Contratar plan**: desde el botón
   del aviso de prueba o de pago, desde Ajustes → Plan y pagos o, si su prueba
   o su pago ya vencieron, directo al iniciar sesión (el resto del sistema
   sigue bloqueado). Los demás roles solo ven el aviso.
2. Elige plan y ciclo (el monto lo calcula el servidor; el precio pactado se
   respeta si no cambia de plan ni de ciclo) y, si quiere factura, captura
   RFC, razón social, régimen, C.P. fiscal, uso del CFDI y correo.
3. Recibe una referencia (`E7-0012`), el monto y tus datos bancarios, y puede
   subir su comprobante (PDF o imagen, 8 MB). Los comprobantes se guardan en
   `storage/comprobantes/e<ID>/`, fuera del alcance de la web.
4. Mientras verificas conserva el acceso los días que indiques en
   Configuración (3 por defecto; como máximo una vez cada 30 días).
5. En el panel, **Solicitudes de pago** → **Confirmar** cuando el depósito
   aparezca en tu banco: se aplica el plan, se registra el pago y la empresa
   queda activa. Si paga durante su prueba, el periodo pagado empieza al
   terminar la prueba. **Rechazar** retira el acceso provisional y el cliente
   ve el motivo. Nada se activa sin tu confirmación.

**Usuarios: el correo es el usuario.** Al dar de alta un usuario, su correo
es su usuario para iniciar sesión y debe ser único en toda la plataforma (así
dos empresas nunca chocan por un nombre como "tecnico1"). Si el administrador
cambia el correo de alguien, ese usuario entra con el nuevo correo. Las
cuentas anteriores con usuario sin correo siguen entrando igual.

**Cotizaciones.** Módulo en Comercial (`public/cotizaciones.html`): partidas
del catálogo con precio y costo congelados, IVA 16/8/0%/exento, MXN o USD,
versiones (COT-0001-v2), aprobación por partida, PDF con Dompdf (librería en
`vendor/dompdf`, bloqueada a la web) y "ventas por registrar" (una por modelo
de equipo aceptado) que se completan en Ventas. Configuración por empresa en
Mi empresa → Personalización → Cotizaciones (todos los planes).

**Mi empresa (para el administrador de cada cliente).** Página
`public/mi-empresa.html`, en el menú y en el inicio solo para el Administrador:

- **Plan y facturación**: plan, precio, vigencia con días restantes, usuarios
  usados contra el límite, historial de pagos y solicitudes, datos fiscales y
  botón para renovar o cambiar de plan.
- **Uso del sistema**: totales (incidencias, clientes, equipos, ventas,
  inventario, usuarios activos, almacenamiento), gráficas de 6 meses,
  actividad diaria de 30 días, actividad por usuario y últimos movimientos.
- **Personalización** (si su plan la incluye; nunca en la demo): logo (PNG,
  JPG o WEBP de 1 MB, en `uploads/e<ID>/marca/`), nombre visible, eslogan,
  colores con vista previa y aviso de contraste, prefijo de folio (solo
  afecta a los folios nuevos) y categorías. Cada cambio queda en el historial
  de la empresa y en tu bitácora; tú sigues pudiendo editar la marca desde la
  ficha de la empresa.

## 7. Tareas programadas

hPanel → **Avanzado → Cron Jobs → Personalizado**, ambas **cada hora** (`0 * * * *`):

```
/usr/bin/php /home/USUARIO/domains/DOMINIO/public_html/cron/reset_demo.php
/usr/bin/php /home/USUARIO/domains/DOMINIO/public_html/cron/respaldo_db.php
```

- **reset_demo.php** (solo con `DEMO_MODE=true`): a las 03:00 (`DEMO_RESET_HORA`)
  restablece **únicamente la empresa 1**; se niega a correr si la empresa 1 no
  está en estado `demo`. Nunca toca a otras empresas.
- **respaldo_db.php**: a las 02:00 (`RESPALDO_HORA`) respalda la base completa
  (todas las empresas) en `.sql.gz`, conservando `RESPALDO_DIAS` (14). Pon
  `RESPALDO_DIR` fuera de `public_html` (ej. `/home/USUARIO/respaldos`).
  Restaurar: phpMyAdmin → Importar el `.sql.gz`.

Los archivos adjuntos (`uploads/`, `manuales/`) los cubre el respaldo de
archivos de Hostinger.

## 8. Verificación rápida

| Prueba | Resultado esperado |
|---|---|
| `https://SITIO/` en un navegador que nunca ha entrado | Sitio de ventas (`/soluciones/`) |
| `https://SITIO/` en un navegador que ya inició sesión | Login con la marca de la plataforma, sin credenciales de la demo |
| `https://SITIO/robots.txt` y `/sitemap.xml` | Texto y XML con las páginas de `/soluciones/` |
| `https://SITIO/demo` | Login con el recuadro de la demo y "Prueba gratis" |
| Entrar con la demo | Datos de la empresa demo |
| `https://SITIO/storage/comprobantes/` | 404 |
| Entrar con una empresa en prueba | Sus datos, su marca y "Te quedan N días de prueba" |
| `https://SITIO/.env`, `/.git/config`, `/database/seed.sql` | 403 / 404 |
| `https://SITIO/uploads/e1/...` sin sesión o desde otra empresa | Redirige al login / 404 |
| `http://SITIO/` | Redirige a `https://` |

## 9. Problemas comunes

- **Error 500 en todo el sitio:** revisa `error_log` en `public_html`. Suele
  ser PHP < 8.1 o el `.env` con datos de base incorrectos.
- **"Tu sesión expiró" justo después de migrar:** normal; las sesiones previas
  a la migración se cierran. Vuelve a iniciar sesión.
- **Una empresa no puede entrar:** revisa en la tabla `empresas` su `estado`,
  `prueba_hasta` y `pagado_hasta`.
- **Import con error #1701 (TRUNCATE):** usa el `seed.sql` actual (borra con
  `DELETE`, compatible con MySQL 8).
