# Solid Link v1 — contrato entre Solid Admin (panel de Solid Suite) y cada sistema

**Regla de oro:** Solid Admin, el panel central de Solid Suite, **decide** (clientes, planes, pagos, vigencias, gracia) y cada
sistema (Servidesk, Caja+, Jornada, los que vengan) **obedece**. Ningún sistema cobra ni calcula gracia, y
ninguno lee la base de datos de otro: todo pasa por esta API.

```
Solid Admin ──(HTTPS + token)──▶ Servidesk /api/solid/v1/...
 (clientes, pagos,          ├──▶ Caja+     /api/solid/v1/...
  planes, vigencias)        └──▶ Jornada y los que vengan
```

## Conexión
- Base: `https://<servidor-del-sistema>/api/solid/v1`
- Cabecera: `Authorization: Bearer <token>`; el token lo define cada sistema en su servidor
  (Servidesk: `SOLID_LINK_TOKEN`; Caja+: `CAJAPLUS_PROVIDER_TOKEN`; 24+ caracteres) y se captura en Solid Admin → Sistemas → Conexión.
- JSON en ambos sentidos. Fechas `AAAA-MM-DD`.
- Errores: código HTTP + `{ "error": "mensaje para humanos", "code": "NOT_FOUND|INVALID|EXISTS|LICENSING_DISABLED" }`.

## Conceptos comunes
| Campo | Significado |
|---|---|
| `estado` | `prueba` · `activa` · `suspendida` · `cancelada` |
| `vence` | **Último día en que el sistema opera normalmente.** El panel ya incluye los días de gracia. A partir del día siguiente el sistema entra en modo restringido (consultar y respaldar, no capturar). `null` = sin vencimiento. |
| `suspendida` | Bloquea aunque `vence` siga vigente. |
| `cancelada` | Sin acceso. Los datos se conservan hasta que el proveedor decida borrarlos. |
| `plan` | Clave del plan en el panel (informativa para el sistema). |
| `limites` | Objeto con los límites que el sistema sabe aplicar (`GET /info` dice cuáles). Ej. Caja+: `{ "cajas": 2 }`; licencia local: `{ "cajas": 2, "empresas": 1 }`; Jornada: `{ "empleados": 25 }`. `null` = sin límite. |

## Endpoints

### `GET /info`
Qué sistema es y qué sabe hacer. El panel lo usa para "Probar conexión".
```json
{ "producto": "caja", "nombre": "Caja+", "version": "0.1.0", "contrato": 1, "modo": "cloud",
  "limites": [{ "clave": "cajas", "nombre": "Cajas" }], "cuentas": true, "licencias_locales": true }
```

### Cuentas (el cliente usando el sistema en la nube)
| Método | Ruta | Cuerpo | Respuesta |
|---|---|---|---|
| GET | `/cuentas` | — | `{ cuentas: [Cuenta] }` (para conciliar) |
| GET | `/cuentas/:id` | — | `Cuenta` |
| POST | `/cuentas` | `{ id?, nombre, estado, vence, plan, limites, contacto: { nombre, correo, telefono }, fiscal: { rfc, razon_social, regimen_fiscal, codigo_postal } }` | `201` `Cuenta` + `acceso: { url, empresa, usuario, password }` (**la contraseña solo se entrega aquí**) |
| PUT | `/cuentas/:id` | cualquier subconjunto de `{ nombre, estado, vence, plan, limites, contacto }` | `Cuenta` |
| POST | `/cuentas/:id/restablecer-acceso` | — | `{ acceso: { url, empresa, usuario, password } }` (cierra las sesiones abiertas) |

`Cuenta`:
```json
{ "id": "abarrotes-lupita", "nombre": "Abarrotes Lupita", "estado": "activa", "vence": "2026-12-31",
  "plan": "nube-basico", "limites": { "cajas": 2 }, "contacto": { "correo": "..." },
  "uso": { "usuarios": 3, "cajas_en_uso": 1, "ventas_30d": 812, "ultima_actividad": "2026-11-16 18:02:11" },
  "url": "https://abarrotes-lupita.nube.tudominio.com" }
```
`fiscal` lo envía el panel siempre; cada sistema usa lo que necesite (Jornada exige el RFC; Servidesk, el correo del contacto) y responde 400 con un mensaje claro si falta.
`uso` es libre por sistema (el panel lo muestra tal cual). `ultima_actividad` es común y alimenta la "salud" del cliente.

### Licencias locales (solo sistemas que se instalan en la PC del cliente, como Caja+)
| Método | Ruta | Cuerpo | Respuesta |
|---|---|---|---|
| GET | `/licencias` | — | `{ licencias: [Licencia] }` |
| GET | `/licencias/:id` | — | `Licencia` |
| POST | `/licencias` | `{ cliente, equipo ("*" = cualquiera), plan, vence, limites, cuenta? }` | `201` `Licencia` con `clave` |
| PUT | `/licencias/:id` | `{ estado: activa\|revocada, vence, limites, plan, equipo, cliente }` | `Licencia` (clave nueva si cambió algo) |

La instalación valida en línea cada 12 h. Si el panel cambió la licencia, recibe la clave nueva (firmada por el
servidor) y la adopta sola. Sin internet sigue funcionando hasta su `vence` (máx. 15 días sin validar).

## Cuándo llama el panel
| Evento en el panel | Llamada |
|---|---|
| Contratar un sistema para un cliente | `POST /cuentas` (o `POST /licencias` si es instalación local) |
| Registrar pago / anular pago / extender prueba / cambiar plan / suspender / cancelar | `PUT /cuentas/:id` (o `/licencias/:id`) con `estado`, `vence`, `limites` recalculados |
| Cada noche (cron) | `PUT` de todas las suscripciones (corrige cualquier desfase si un sistema estuvo caído); la respuesta trae el `uso` |
| "Importar cuentas" | `GET /cuentas` y `GET /licencias`: adopta las cuentas creadas directamente en el sistema (autoservicio) |

## Cómo conectar un sistema nuevo
1. Implementa estos endpoints. Referencias: Caja+ `server/src/routes/solid.js` (Node), Servidesk `api/solid.php` + `config/solid_link.php` (PHP sin framework) y Jornada `api/src/Controller/SolidController.php` (PHP con router).
2. En Solid Admin: Sistemas → Nuevo sistema (clave, nombre, URL, token) → Probar.
3. Crea sus planes en Planes y precios (sistema + límites) y, si ya tenía clientes, usa Importar cuentas.
