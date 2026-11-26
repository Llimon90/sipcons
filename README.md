# Servidesk

Sistema de gestión para empresas de **mantenimiento industrial y servicios
técnicos**: incidencias (tickets), clientes y su padrón de equipos,
inventario de refacciones, ventas de equipo, estadísticas de servicio e
historial de cambios. Es **marca blanca**: el nombre, el logo, los colores y
las categorías de cada instancia se configuran en `.env`, sin tocar código.

## Módulos

| Módulo | Qué hace |
|---|---|
| Incidencias | Alta y seguimiento de tickets con folio, técnicos asignados, estatus, adjuntos y refacciones utilizadas |
| Buscar incidencias | Filtros avanzados, accesos rápidos por categoría y mantenimientos programados |
| Clientes | Directorio, contactos y padrón de equipos instalados (pólizas de servicio y garantías) |
| Inventario | Refacciones, equipos, consumibles y herramientas; kárdex, stock mínimo y consumo mensual |
| Ventas | Venta de equipos con series; alimenta el padrón y descuenta inventario |
| Estadísticas | KPIs de atención (SLA de respuesta y cierre), carga por técnico, reincidencias, tendencias |
| Métricas | Panel personal de cada técnico |
| Historial | Auditoría de quién cambió qué y cuándo |
| Usuarios y Ajustes | Roles, privilegios por módulo, tema claro/oscuro |
| Soporte | Manuales y documentación técnica por marca y modelo |

Los tickets de mantenimiento preventivo se generan solos 10 días antes de
que venza la póliza de un equipo del padrón.

## Tecnología

- PHP 8.1+ sin framework, MySQL 5.7+ / MariaDB 10.3+.
- Frontend en HTML, CSS y JavaScript sin compilación (Chart.js y Font
  Awesome por CDN).
- Pensado para hosting compartido (Hostinger) con despliegue por Git.

## Estructura

```
index.html            Inicio (tarjetas de módulos)
auth/                 Login, sesión, permisos y auditoría
backend/              API (un endpoint PHP por acción) y backend/lib/
branding/             tema.css.php y marca.js.php: identidad desde .env
config/               env.php, database.php, branding.php, demo.php
public/               Páginas de cada módulo
scripts/ css/ img/    Frontend
database/             schema.sql (plataforma + demo), plantilla_empresa.sql,
                      seed.sql (datos de la demo), migrations/
database/demo_docs/   PDF de ejemplo de Soporte (respaldo para el reinicio demo)
database/tools/       generadores (schema, seed, PDF demo) y altas por consola
                      (crear_empresa.php, crear_superadmin.php)
cron/                 reset_demo.php (reinicio diario de la demo) y
                      respaldo_db.php (respaldo diario de la base)
docs/legal/           Borradores de aviso de privacidad y contrato de servicio
uploads/ manuales/    Archivos por empresa (uploads/e7/...), servidos por
                      backend/archivo.php solo a usuarios de esa empresa
storage/              Sesiones y logs (no versionados)
```

## Arquitectura multiempresa

Una sola instalación atiende a todas las empresas:

- **Tablas centrales:** `empresas` (estado, fechas de prueba y pago, marca),
  `usuarios` (con `empresa_id`), `pagos`, `analytics_eventos`.
- **Tablas por empresa:** `e7_incidencias`, `e7_clientes`… creadas desde
  `database/plantilla_empresa.sql`.
- Al iniciar sesión se activa la empresa del usuario y las conexiones
  (`config/database.php`) reescriben `FROM incidencias` → `FROM e7_incidencias`
  (`config/empresa.php`). Sin empresa activa no se reescribe nada y las tablas
  sin prefijo no existen: cualquier fallo es un error, nunca datos de otra empresa.
- El estado de la empresa (prueba, gracia, solo lectura, suspendida…) se
  calcula en cada petición a partir de sus fechas (`situacionEmpresa()`).

## Inicio y navegación

- `index.html`: centro de operaciones. Indicadores que filtran la bandeja de
  trabajo (abiertas, asignadas, pendientes, más de 7 días, por cerrar), avisos,
  carga por técnico y últimos cambios. El rol Técnico ve "Mi día" con sus
  incidencias. Datos: `backend/inicio.php`.
- `scripts/menu-lateral.js`: el menú lateral de todas las páginas, agrupado por
  áreas (Operación, Comercial, Análisis, Administración), el buscador global
  (Ctrl + K: folio, cliente, sucursal, número de serie o módulo) y la barra
  inferior en el celular. Cada página trae solo el esqueleto del `<aside>`;
  para agregar un módulo se edita `MENU` en ese archivo.

## Manual de usuario

- `public/manual.html` (menú → Ayuda → Manual de usuario): centro de ayuda con
  índice, buscador de temas y botón "Descargar PDF" que imprime el manual con
  la marca de cada empresa. Al cambiar una función, actualiza su sección.
- `docs/Manual de usuario - Servidesk.pdf`: la misma versión en PDF con la
  marca de la plataforma, para enviar a prospectos o clientes.

## Registro y panel de plataforma

- `auth/registro.html`: alta autoservicio de empresas en prueba de 15 días
  (`REGISTRO_ABIERTO`), con protección anti-bots y límite por IP.
- `auth/contratar.html`: el administrador de la empresa elige plan y ciclo,
  recibe referencia y datos bancarios y sube su comprobante; la plataforma
  confirma el pago en su panel (`config/contratacion.php`, migración 004).
- `public/mi-empresa.html`: el administrador de cada empresa ve su plan,
  vigencia y pagos, el uso del sistema y, si su plan lo incluye, personaliza
  logo, colores y textos (`config/marca_empresa.php`, migración 005).
- `plataforma/`: panel exclusivo del superadministrador (cuenta sin empresa):
  métricas de negocio (MRR, conversión, cobranza), cartera de empresas con su
  ficha, planes y precios, pagos y facturas, pruebas, modo soporte,
  configuración del negocio y cuentas de plataforma. Toda acción queda en
  `plataforma_eventos`. Detalle en `DEPLOY.md`.

## Configuración de marca

La marca de **cada empresa** vive en `empresas.marca` (JSON: nombre, logo,
colores, categorías, prefijo de folio, zona horaria). El `.env` define la marca
de la **plataforma** (la del login) y los valores por defecto:

- `APP_NAME`, `APP_TAGLINE`, `APP_COMPANY`, `APP_LOGIN_FOOTER`: textos.
- `APP_LOGO`, `APP_FAVICON`: imágenes (ruta relativa o URL).
- `BRAND_PRIMARY`, `BRAND_SECONDARY`, `BRAND_SIDEBAR`: colores. Hover y
  modo oscuro se derivan automáticamente.
- `INCIDENCIA_CATEGORIAS`, `FOLIO_PREFIX`: operación.

`config/branding.php` lee esos valores; `branding/tema.css.php` los
convierte en variables CSS (`--brand-*`) y `branding/marca.js.php` los expone
al frontend como `window.APP_BRAND`.

## Modo demostración

Con `DEMO_MODE=true` la página `/demo` (para prospectos) muestra las
credenciales de prueba (`demo@sistema.com / demo123`) y el enlace a la prueba
gratis; el login de clientes (`/auth/login.html`) no muestra nada de la demo.
Dentro de la demo se bloquean contraseñas, usuarios y
privilegios, y `cron/reset_demo.php` restablece los datos cada día a las
03:00. Detalles en [DEPLOY.md](DEPLOY.md#6-reinicio-diario-de-la-demo-solo-demo).

Cuentas del seed: `demo@sistema.com / demo123` (Administrador) y
`mherrera`, `cmendoza`, `atrevino`, `jcastillo`, `ribarra`, `dfuentes`
con contraseña `tecnico123`.

## Desarrollo local (Windows con XAMPP)

1. Instala XAMPP con PHP 8.2 en `C:\xampp` (no en *Program Files*).
2. Crea `C:\xampp\apache\conf\extra\httpd-servidesk.conf`:

   ```apache
   Alias /servidesk "C:/ruta/al/proyecto/SERVIDESK"
   <Directory "C:/ruta/al/proyecto/SERVIDESK">
       Options -Indexes +FollowSymLinks
       AllowOverride All
       Require all granted
   </Directory>
   ```

   y agrega al final de `httpd.conf`: `Include "conf/extra/httpd-servidesk.conf"`.
3. En el XAMPP Control Panel arranca **MySQL** y reinicia **Apache**.
4. En phpMyAdmin crea la base `servidesk_demo` (`utf8mb4_unicode_ci`) e
   importa `database/schema.sql` y `database/seed.sql`.
5. Crea `.env` en la raíz con `DB_USER=root`, `DB_PASS=` (vacío),
   `DB_NAME=servidesk_demo`, `APP_URL=http://localhost/servidesk` y
   `DEMO_MODE=true`.
6. Abre http://localhost/servidesk.

Para volver a los datos iniciales, importa de nuevo `database/seed.sql`.
No ejecutes `cron/reset_demo.php` en tu copia de trabajo: también vacía
`uploads/` y `manuales/` de esa carpeta.

Para regenerar los datos de demostración tras cambiar el generador:

```
pip install bcrypt
python database/tools/generar_seed.py
```

## Documentos legales

`docs/legal/` contiene **borradores** del aviso de privacidad y del contrato
de prestación de servicios (con cláusula de encargado de datos personales).
Deben revisarse con un abogado antes de usarse. Una vez publicado el aviso,
su URL va en `APP_AVISO_PRIVACIDAD_URL` y aparece como enlace en el login.

## Despliegue

Ver **[DEPLOY.md](DEPLOY.md)**: subdominio, base de datos, Git con
webhook, `.env`, cron y verificación.
