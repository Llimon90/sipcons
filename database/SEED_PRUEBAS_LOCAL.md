# Datos de prueba locales (sipcons_pruebas)

Ficticios, solo para desarrollo en esta máquina. Contraseña para los 4 usuarios: `Prueba123!`

| usuario | rol |
|---|---|
| programador | Programador |
| admin | Administrador |
| tecnico | Técnico |
| supervisor | Supervisor |

Clientes: "Mr. Tienda Zona Rio" (id 1) y "Calimax Otay" (id 2), cada uno con 1-2 contactos, 1 venta con 1 equipo en el padrón, y clientes.cliente_id ya vinculado en sus incidencias/ventas.

Incidencias: `SIP-0001` (Alta, Abierto), `SIP-0002` (Media, Asignado), `SIP-0003` (Baja, Cerrado con factura).

Base de datos: `sipcons_pruebas` en MariaDB local (XAMPP, `root` sin password). `.env` en la raíz del repo ya apunta aquí.
