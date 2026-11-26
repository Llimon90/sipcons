# Backlog de prelanzamiento

Fuentes: observaciones del dueño en uso real (2026-11-10) y decisiones de `docs/REGLAS_NEGOCIO.md`
(sección 0). Cada cambio de lógica se implementa con el test primero y pasa `/auditar --diff`
(ver `CLAUDE.md`).

**Tipos:** BUG (no hace lo que debe) · REGLA (implementar una decisión D-n) ·
UX (funciona, pero confunde o estorba) · FUNCIÓN (algo nuevo) · CONSULTA (pregunta de
diseño, con recomendación).

**Prioridad:** P0 = bloqueante para el lanzamiento · P1 = antes del lanzamiento si cabe ·
P2 = después del lanzamiento.

## Resumen

| # | Observación | Tipo | Prioridad |
|---|---|---|---|
| O-16 | Los botones de IVA alteran el total de una cuenta que viene de una cotización | BUG | P0 |
| O-1 | No se puede adjuntar el comprobante de un cobro ya registrado | BUG/FUNCIÓN | P0 |
| O-4 | IVA de los abonos parciales | REGLA (fiscal) | P0 |
| O-2 | Corregir un cobro registrado por error | UX | P1 |
| O-3 | Botón "Cobrar" encimado en la tabla | BUG (visual) | P1 |
| O-5, O-18 | "Falla" → "Motivo del servicio"; estatus según el estándar | REGLA (D-1 a D-6) | P0 |
| O-12 | Botones de guardar confusos en cotizaciones | UX | P1 |
| O-14 | Textos internos que no debería ver el usuario | UX | P1 |
| O-15 | Confirmación repetitiva al enviar cotizaciones | UX | P1 |
| O-13 | Moneda bloqueada en cotizaciones con partidas | CONSULTA/FUNCIÓN | P1 |
| O-10 | Atajos en Inicio: nueva cotización y nuevo artículo | FUNCIÓN (chica) | P1 |
| O-6 | Dar de alta técnico o contacto desde la orden | FUNCIÓN | P1 |
| O-7 | Dar de alta una refacción desde la orden | FUNCIÓN | P1 |
| O-8, O-9 | Dónde va la mano de obra; qué se carga a una orden | CONSULTA/FUNCIÓN | P1 (decisión) / P2 (función completa) |
| O-17 | Gastos ligados a órdenes y gastos generales | CONSULTA/FUNCIÓN | P2 |
| O-11 | Padrón de marcas y modelos | CONSULTA | P2 |
| D-20 | Asistente guiado por rol | FUNCIÓN | P1 |

---

## Finanzas

### O-16 · Los botones de IVA alteran el total de una cuenta que viene de una cotización (BUG, P0)
**Causa probable** (`scripts/finanzas.js:276-285`, `public/finanzas.html:195`): al abrir una
cuenta desde una cotización, el subtotal y el IVA llegan ya calculados con la proporción
aprobada (que puede mezclar 16 %, 8 % y exento, y redondea por partida). Los atajos 16 %/8 %/0
**recalculan el IVA sobre todo el subtotal** sin considerar eso, así que el total cambia, deja
de coincidir con lo aprobado y puede rebasar el límite (y el servidor rechaza la cuenta).
**Corrección propuesta:** si la cuenta viene de una cotización, el IVA queda fijo (sin
atajos) con la nota "IVA según la cotización COT-xxxx". Solo se edita en cuentas manuales.
Test: el total de una cuenta creada desde una cotización con tasas mixtas = lo aprobado
pendiente, al centavo. Hay que confirmarlo en el navegador antes de corregir.

### O-1 · Comprobante de un cobro ya registrado (BUG/FUNCIÓN, P0)
Hoy el comprobante solo se sube en el momento de registrar el cobro (`finRegistrarCobro`);
después no hay forma de agregarlo ni de reemplazarlo.
**Propuesta:** en la lista de cobros, "Adjuntar comprobante" (o "Reemplazar") por cobro,
con registro en el historial. El reemplazo conserva el archivo anterior en el historial. Lo
puede hacer quien tiene el permiso `finanzas`.

### O-2 · Cobro registrado por error (UX, P1)
**Ya existe:** "Cancelar cobro" con motivo obligatorio (solo el Administrador), que recalcula
el saldo y el estatus. Falta hacerlo práctico: una acción **"Corregir"** que cancele el cobro
(con motivo) y abra el formulario de cobro ya lleno con los datos anteriores para ajustarlos.
El cobro original queda cancelado en el historial (no se edita un cobro: es trazabilidad).
**Decidido (2026-11-10, D-22):** pueden corregir el Administrador y el Administrativo.

### O-4 · IVA de los abonos parciales (REGLA fiscal, P0)
En México el IVA se causa cuando se **cobra** (flujo de efectivo, LIVA art. 1-B), y cada pago
parcial lleva su parte proporcional de IVA (es lo que reporta el complemento de pago del CFDI).
Hoy un cobro solo guarda el `monto`: no se sabe cuánto IVA se ha cobrado.
**Propuesta (nueva regla FN-IVA):** `IVA del cobro = monto × (IVA de la cuenta ÷ total de la
cuenta)`, redondeado a centavos; el último cobro ajusta la diferencia para que la suma cuadre
exacto. Se muestra en el detalle de la cuenta, en el estado de cuenta y en un reporte de
"IVA cobrado del periodo". Las retenciones se prorratean igual.
**Validar con el contador** antes de implementarla.

### O-3 · Botón "Cobrar" encimado (BUG visual, P1)
Pendiente de reproducir en el navegador (ancho de la columna de acciones). Se corrige con CSS.

### O-17 · Gastos (CONSULTA → P2)
Ligar un gasto a una orden **sí vale la pena** (ya existe: `incidencia_folio`), porque
alimenta la rentabilidad por orden. Para el control general, la práctica recomendada es:
- **Categorías de dos niveles:**
  - *Costo directo de servicio*, que se liga a una orden: viáticos, combustible del
    servicio, refacciones compradas para la orden, subcontratos, fletes.
  - *Gasto operativo*, sin orden: vehículos (combustible, mantenimiento, seguro, tenencia,
    verificación), herramienta y equipo, renta, servicios, nómina, impuestos, software,
    publicidad, papelería y comisiones bancarias.
- **Liga opcional a un "activo"** (vehículo o herramienta) para saber cuánto cuesta cada unidad.
- **Tablero:** utilidad bruta por orden (ya existe) más gastos operativos del mes, para la
  utilidad neta.

## Órdenes de servicio

### O-5 / O-18 · "Falla" y estatus (REGLA, P0)
Decididas en `REGLAS_NEGOCIO.md` 0.1 y D-1 a D-6: lista cerrada de 8 estatus (se agregan
**En proceso** y **Cancelado**, Pendiente exige motivo) y "Falla" → **"Motivo del servicio"**
en toda la interfaz.

### O-6 · Alta de técnico o contacto sin salir de la orden (FUNCIÓN, P1)
Un modal como el de cliente. Restricciones: solo quien tiene permiso `usuarios` puede crear
un técnico (crea una cuenta con acceso al sistema); los demás ven "Pídele al administrador
que lo dé de alta". El contacto sí lo puede crear quien edita la orden.

### O-7 · Alta de refacción sin salir de la orden (FUNCIÓN, P1)
Un modal sencillo (nombre, tipo, unidad, costo, precio, existencia inicial), solo para quien
tiene permiso `inventario`. Al Técnico, que no lo tiene, se le muestra "Solicitar
alta" (queda como nota en la orden).

### O-8 / O-9 · Mano de obra y conceptos aplicados en la orden (CONSULTA)
**Recomendación:**
1. La mano de obra y los servicios **no son inventario** (no tienen existencias). Su lugar es
   un **Catálogo de productos y servicios**, con dos clases:
   - *Artículos*, con existencias: refacciones, equipos y consumibles.
   - *Servicios*, sin existencias: mano de obra, instalación, diagnóstico y visita.

   El código ya permite artículos de tipo servicio sin existencias (está cubierto por un
   test). Falta separarlos en la interfaz y darles su propia pestaña.
2. La sección del detalle pasa de "Refacciones utilizadas" a **"Conceptos aplicados"**:
   - refacción o consumible: descuenta existencias;
   - servicio o mano de obra: no descuenta nada;
   - equipo de reemplazo: descuenta existencias y da de alta el equipo, con su serie, en el
     padrón del cliente.
3. Para cobrar hay dos caminos, que ya existen y solo hay que nombrar bien:
   - **Cotizar lo aplicado:** el trabajo ya se hizo; la cotización no vuelve a descontar.
   - **Cotizar revisión técnica:** la cotización va para aprobación del cliente. La orden pasa
     a Pendiente con motivo "aprobación de cotización", y al aprobarse regresa a Asignado (ya
     ocurre así).

**Aprobado (2026-11-10, D-23).** El punto 2 se hace antes del lanzamiento y la pestaña de
servicios, después.

### O-11 · Padrón de marcas y modelos (CONSULTA, P2)
Ya existen las tablas `marcas` y `modelos`, usadas por el padrón de equipos. Vale la pena
**ligar también los artículos de inventario** a una marca y modelo, más una lista de
"modelos compatibles" por refacción: así se sabe qué refacciones le sirven a un equipo y
qué se ha cambiado a cada serie. Es útil, pero no bloquea el lanzamiento.

## Cotizaciones

### O-12 · Botones de guardar (UX, P1)
Hay que reproducirlo en el navegador. Propuesta: **un solo botón "Guardar"**, siempre en el
mismo lugar, con un indicador de estado al lado ("Cambios sin guardar" / "Guardado 10:42").

### O-13 · Moneda con partidas (CONSULTA, P1)
Una cotización en una sola moneda es la práctica correcta: el CFDI se emite en una moneda,
y mezclar monedas confunde al cliente y la cobranza. Hoy ya se pueden **agregar artículos del
catálogo en USD a una cotización en MXN** (se convierten con el tipo de cambio).
**Propuesta:** permitir **cambiar la moneda con partidas**, convirtiendo todas al tipo de
cambio indicado (con confirmación y en el historial), en lugar de obligar a borrar las
partidas. Si el cliente necesita la mano de obra en pesos y el equipo en dólares, lo correcto
son dos cotizaciones (o dos cuentas por cobrar).

### O-14 · Textos internos visibles al usuario (UX, P1)
Ejemplo: `notas_internas` se llena con "Cotización posterior al servicio… se descontaron del
inventario…" (`backend/lib/cotizaciones_helpers.php:624`).
**Propuesta:** las notas quedan vacías para el usuario, y esa información pasa a una etiqueta
del sistema que no se edita ("Desde OS SD-0002 · refacciones ya instaladas"). Además, hay que
revisar todos los mensajes, confirmaciones y textos de ejemplo con este criterio: el usuario
necesita saber *qué pasa y qué puede hacer*, no *cómo funciona por dentro*.

### O-15 · Confirmación al enviar (UX, P1)
**Propuesta:** la explicación se muestra **solo la primera vez**, con la casilla "Entendido,
no volver a mostrar" (guardada en la cuenta del usuario). Después, enviar es un solo clic, y
la cotización muestra la etiqueta "Enviada · para cambiarla crea una nueva versión". Seguirán
pidiendo confirmación solo las acciones que no se pueden deshacer (eliminar, cancelar).

## Inicio y adopción

### O-10 · Atajos en Inicio (FUNCIÓN chica, P1)
"Nueva cotización" y "Nuevo artículo", visibles según el permiso de cada módulo.

### D-20 · Asistente guiado por rol (FUNCIÓN, P1)
Un recorrido en pasos sobre la pantalla real, distinto por rol:
- Técnico: sus órdenes, cambiar el estatus, adjuntar fotos, registrar refacciones.
- Administrativo: órdenes, cotizaciones y cobranza.
- Supervisor: bandeja, asignación e informes.
- Administrador: lo anterior más la configuración de la empresa.

Se abre solo en el primer acceso, se puede cerrar en cualquier paso y se vuelve a abrir desde
el menú (Ayuda → Recorrido guiado). El progreso se guarda en la cuenta del usuario, no en el
navegador. Se hace sin librerías externas (no hay Composer ni bundler).
