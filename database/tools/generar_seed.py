"""
Genera database/seed.sql: datos de demostración de Servidesk para una empresa
de servicios de mantenimiento industrial (HVAC, refrigeración, bombeo, aire
comprimido y tableros eléctricos).

Todas las fechas se escriben relativas a CURDATE(), así que cada vez que se
carga el seed (o se restablece la demo) los datos aparecen "al día".

Uso:  python database/tools/generar_seed.py
Requiere: pip install bcrypt
"""
import json
import random
from pathlib import Path

import bcrypt

random.seed(2026)
SALIDA = Path(__file__).resolve().parent.parent / "seed.sql"


# --------------------------------------------------------------------- utilidades
def q(v):
    """Literal SQL."""
    if v is None:
        return "NULL"
    if isinstance(v, bool):
        return "1" if v else "0"
    if isinstance(v, (int, float)):
        return repr(round(v, 2)) if isinstance(v, float) else str(v)
    if isinstance(v, Raw):
        return v.sql
    s = str(v).replace("\\", "\\\\").replace("'", "''").replace("\n", "\\n")
    return f"'{s}'"


class Raw:
    def __init__(self, sql):
        self.sql = sql


def fecha(dias):
    """DATE relativa: hoy - dias."""
    return Raw(f"DATE_SUB(CURDATE(), INTERVAL {dias} DAY)") if dias >= 0 else Raw(f"DATE_ADD(CURDATE(), INTERVAL {-dias} DAY)")


def momento(minutos):
    """DATETIME relativo: minutos respecto a hoy 00:00 (negativo = pasado)."""
    return Raw(f"TIMESTAMPADD(MINUTE, {int(minutos)}, CURDATE())")


# La demo es la empresa 1: sus tablas llevan el prefijo e1_ (ver config/empresa.php).
# usuarios y analytics_eventos son centrales y se acotan con empresa_id.
EMPRESA_DEMO = 1
PREFIJO = f"e{EMPRESA_DEMO}_"
CENTRALES = {"usuarios", "analytics_eventos"}


def tabla_sql(nombre):
    return nombre if nombre in CENTRALES else PREFIJO + nombre


def insert(tabla, filas):
    if not filas:
        return ""
    tabla = tabla_sql(tabla)
    cols = list(filas[0].keys())
    valores = ",\n".join("(" + ", ".join(q(f[c]) for c in cols) + ")" for f in filas)
    return f"INSERT INTO `{tabla}` ({', '.join(f'`{c}`' for c in cols)}) VALUES\n{valores};\n\n"


def hash_pw(p):
    # PHP password_verify acepta $2y$; bcrypt de Python genera $2b$ (mismo algoritmo)
    return bcrypt.hashpw(p.encode(), bcrypt.gensalt(10)).decode().replace("$2b$", "$2y$", 1)


DIA = 1440

# --------------------------------------------------------------------- usuarios
usuarios = [
    # id, nombre, usuario, rol, correo, telefono, password
    (1, "Usuario Demo", "demo@sistema.com", "Administrador", "demo@sistema.com", "55 5555 0100", "demo123"),
    (2, "Miguel Ángel Herrera", "mherrera", "Supervisor", "mherrera@servidesk.example", "55 5555 0101", "tecnico123"),
    (3, "Carlos Mendoza Ruiz", "cmendoza", "Técnico", "cmendoza@servidesk.example", "55 5555 0102", "tecnico123"),
    (4, "Ana Sofía Treviño", "atrevino", "Técnico", "atrevino@servidesk.example", "55 5555 0103", "tecnico123"),
    (5, "Jorge Iván Castillo", "jcastillo", "Técnico", "jcastillo@servidesk.example", "55 5555 0104", "tecnico123"),
    (6, "Ricardo Ibarra Ortega", "ribarra", "Técnico", "ribarra@servidesk.example", "55 5555 0105", "tecnico123"),
    (7, "Daniela Fuentes Lara", "dfuentes", "Técnico", "dfuentes@servidesk.example", "55 5555 0106", "tecnico123"),
]
TECNICOS = [u[1] for u in usuarios if u[3] == "Técnico"]
PESO_TECNICO = [30, 24, 20, 16, 10]  # carga de trabajo desigual: se nota en las gráficas
SUPERVISOR = usuarios[1][1]

filas_usuarios = [
    {"id": u[0], "empresa_id": EMPRESA_DEMO, "nombre": u[1], "alias_tecnico": None, "correo": u[4], "telefono": u[5],
     "usuario": u[2], "password": hash_pw(u[6]), "rol": u[3],
     "fecha_creacion": momento(-200 * DIA + 9 * 60), "tema": "claro"}
    for u in usuarios
]

# --------------------------------------------------------------------- clientes
clientes = [
    ("Alimentos del Valle Industrial, S.A. de C.V.", "AVI980112KJ3", "Av. de las Industrias 1450, Parque Industrial Norte", "55 5555 1101",
     "mantenimiento@alimentosdelvalle.example", [("Ing. Roberto Salgado", "55 5555 1111"), ("Lucía Paredes", "55 5555 1112")], ["Planta Norte", "Planta Sur"]),
    ("Logística Fría del Pacífico", "LFP150603TT1", "Carretera Federal 15 km 8.5", "55 5555 1201",
     "operaciones@logisticafria.example", [("Marco Antonio Ruelas", "55 5555 1211"), ("Patricia Lomelí", "55 5555 1212")], ["CEDIS Centro", "CEDIS Puerto"]),
    ("Hotel Real Costa Azul", "HRC091020M45", "Blvd. Costero 300, Zona Hotelera", "55 5555 1301",
     "ingenieria@realcostaazul.example", [("Fernando Quiroz", "55 5555 1311")], ["Torre A", "Torre B"]),
    ("Hospital San Gabriel", "HSG030715PL8", "Calle Galeana 220, Col. Centro", "55 5555 1401",
     "biomedica@hsangabriel.example", [("Dra. Elena Cifuentes", "55 5555 1411"), ("Óscar Villalobos", "55 5555 1412")], ["Edificio principal"]),
    ("Embotelladora Sierra Clara", "ESC110228AB6", "Libramiento Oriente 77", "55 5555 1501",
     "planta@sierraclara.example", [("Héctor Bautista", "55 5555 1511")], ["Línea 1", "Línea 2"]),
    ("Plásticos y Moldes Herrera", "PMH070910C21", "Calle 4 Norte 118, Zona Industrial", "55 5555 1601",
     "compras@plasticosherrera.example", [("Gabriela Montes", "55 5555 1611")], ["Planta 1"]),
    ("Corporativo Torres Delta", "CTD180501HJ9", "Av. Reforma 1200, Piso 3", "55 5555 1701",
     "facilities@torresdelta.example", [("Luis Enrique Robles", "55 5555 1711")], ["Torre Delta"]),
    ("Panificadora La Espiga Dorada", "PED990314Q77", "Av. Juárez 845", "55 5555 1801",
     "gerencia@espigadorada.example", [("Rosa María Aguilar", "55 5555 1811")], ["Obrador central"]),
]
filas_clientes, filas_contactos = [], []
cid = 0
for i, (nombre, rfc, dir_, tel, email, contactos, _) in enumerate(clientes, start=1):
    filas_clientes.append({"id": i, "nombre": nombre, "rfc": rfc, "direccion": dir_, "telefono": tel,
                           "contactos": "; ".join(c[0] for c in contactos), "email": email,
                           "fecha_registro": momento(-190 * DIA + (i * 37) % 600)})
    for c in contactos:
        cid += 1
        filas_contactos.append({"id": cid, "cliente_id": i, "nombre": c[0], "telefono": c[1], "email": None})

# --------------------------------------------------------------------- catálogo (Soporte)
marcas = ["Carrier", "Grundfos", "Ingersoll Rand", "Schneider Electric", "Bohn", "Daikin"]
modelos = [  # marca, modelo, tipo
    ("Carrier", "42KHQ024", "Minisplit inverter"), ("Daikin", "FTKF24", "Minisplit inverter"),
    ("Bohn", "BHT050", "Unidad condensadora"), ("Bohn", "ADT104", "Evaporador de cámara"),
    ("Grundfos", "CM5-5", "Bomba centrífuga"), ("Ingersoll Rand", "SS5L5", "Compresor de aire"),
    ("Schneider Electric", "TeSys D", "Tablero de control"),
]
filas_marcas = [{"id": i, "nombre": m, "fecha_creacion": momento(-200 * DIA), "fecha_eliminacion": None, "activo": 1}
                for i, m in enumerate(marcas, start=1)]
filas_modelos = [{"id": i, "marca_id": marcas.index(m) + 1, "nombre": mo, "tipo_equipo": t,
                  "fecha_creacion": momento(-200 * DIA), "fecha_eliminacion": None, "activo": 1}
                 for i, (m, mo, t) in enumerate(modelos, start=1)]

# Documentos de Soporte (PDF generados por database/tools/generar_documentos_demo.py)
documentos = [  # modelo, tipo, nombre, descripción, archivo
    ("42KHQ024", "manual", "Mantenimiento preventivo minisplit inverter", "Lista de verificación semestral y fallas frecuentes", "minisplit-mantenimiento.pdf"),
    ("FTKF24", "manual", "Mantenimiento preventivo minisplit inverter", "Aplica a equipos inverter de 1 a 3 TR", "minisplit-mantenimiento.pdf"),
    ("CM5-5", "lista_partes", "Partes de servicio bomba centrífuga 3 HP", "Refacciones de desgaste con su SKU de inventario", "bomba-centrifuga-partes.pdf"),
    ("SS5L5", "manual", "Programa de servicio compresor 5 HP", "Intervalos diario a anual y valores de operación", "compresor-programa-servicio.pdf"),
    ("BHT050", "diagrama", "Diagrama de control unidad condensadora", "Secuencia de arranque, protecciones y puntos de medición", "condensadora-diagrama.pdf"),
    ("TeSys D", "tutorial", "Selección de contactor y relevador térmico", "Guía rápida para arranque directo a 220 V", "tablero-guia-contactores.pdf"),
]
filas_docs = [{"id": i, "modelo_id": [m[1] for m in modelos].index(mo) + 1, "tipo_documento": t, "nombre_archivo": n,
               "descripcion": d, "ruta_archivo": f"manuales/e{EMPRESA_DEMO}/{a}", "fecha_subida": momento(-150 * DIA + i * 90)}
              for i, (mo, t, n, d, a) in enumerate(documentos, start=1)]

# --------------------------------------------------------------------- inventario
# sku, nombre, categoria, marca, modelo, unidad, minimo, costo, precio, ubicacion, existencia_final
articulos = [
    ("REF-0001", "Rodamiento 6205-2RS", "Refacción", "SKF", "6205-2RS", "pza", 10, 85, 160, "Anaquel A-1", 24),
    ("REF-0002", "Banda en V A-48", "Refacción", "Gates", "A-48", "pza", 8, 120, 230, "Anaquel A-2", 15),
    ("REF-0003", "Contactor tripolar 32 A 220 V", "Refacción", "Schneider Electric", "LC1D32", "pza", 4, 1350, 2290, "Anaquel B-1", 3),
    ("REF-0004", "Capacitor de marcha 45/5 µF 440 V", "Refacción", "Genteq", "97F9854", "pza", 6, 310, 590, "Anaquel B-2", 11),
    ("REF-0005", "Filtro plisado 20x25x2 MERV 8", "Refacción", "Filtrete", "MERV 8", "pza", 24, 95, 185, "Rack C", 48),
    ("REF-0006", "Sello mecánico 1\" para bomba centrífuga", "Refacción", "John Crane", "Tipo 21", "pza", 4, 780, 1450, "Anaquel A-3", 2),
    ("REF-0007", "Relevador térmico 23-32 A", "Refacción", "Schneider Electric", "LRD32", "pza", 3, 890, 1590, "Anaquel B-1", 6),
    ("REF-0008", "Termostato digital programable", "Refacción", "Honeywell", "T6 Pro", "pza", 3, 1150, 2100, "Anaquel B-3", 5),
    ("REF-0009", "Válvula de expansión termostática 3 TR", "Refacción", "Danfoss", "TGEX 3", "pza", 2, 2450, 4300, "Anaquel A-4", 4),
    ("REF-0010", "Motor ventilador de condensador 1/3 HP", "Refacción", "WEG", "W22 1/3HP", "pza", 2, 2100, 3650, "Piso, zona motores", 1),
    ("CON-0001", "Refrigerante R-410A", "Consumible", "Chemours", "Freon 410A", "kg", 20, 260, 480, "Jaula de gases", 38.5),
    ("CON-0002", "Aceite POE 32 para compresor", "Consumible", "Emkarate", "RL32-3MAF", "lt", 8, 340, 610, "Anaquel D-1", 14),
    ("CON-0003", "Grasa multipropósito EP2 (cartucho 400 g)", "Consumible", "Mobil", "Mobilux EP2", "pza", 12, 110, 210, "Anaquel D-2", 30),
    ("CON-0004", "Cinta aislante vinil Super 33+", "Consumible", "3M", "Super 33+", "rollo", 15, 75, 140, "Anaquel D-3", 42),
    ("CON-0005", "Limpiador espumoso para serpentines", "Consumible", "Nu-Calgon", "Evap Foam", "lt", 10, 150, 290, "Anaquel D-1", 7),
    ("EQP-0001", "Minisplit inverter 2 TR 220 V", "Equipo", "Carrier", "42KHQ024", "pza", 2, 13800, 21900, "Bodega equipos", 5),
    ("EQP-0002", "Bomba centrífuga 3 HP", "Equipo", "Grundfos", "CM5-5", "pza", 1, 18500, 27900, "Bodega equipos", 2),
    ("EQP-0003", "Compresor de aire 5 HP 60 gal", "Equipo", "Ingersoll Rand", "SS5L5", "pza", 1, 31500, 46900, "Bodega equipos", 1),
    ("HER-0001", "Multímetro de gancho True-RMS", "Herramienta", "Fluke", "376 FC", "pza", 1, 9800, 0, "Gabinete herramientas", 3),
    ("HER-0002", "Juego de manómetros digitales", "Herramienta", "Fieldpiece", "SMAN460", "jgo", 1, 11200, 0, "Gabinete herramientas", 2),
]
ART = {a[0]: i for i, a in enumerate(articulos, start=1)}
COSTO = {a[0]: a[7] for a in articulos}
movimientos = []  # (minuto, sku, cantidad, tipo, motivo, ref_tipo, ref_id, ref_folio, usuario_id)

# --------------------------------------------------------------------- padrón de equipos
# Equipos instalados en cada cliente (algunos vendidos por la empresa, ver ventas)
equipos_tipo = {
    "minisplit": ("Minisplit inverter 2 TR", "Carrier", "42KHQ024"),
    "minisplit_d": ("Minisplit inverter 2 TR", "Daikin", "FTKF24"),
    "condensadora": ("Unidad condensadora 5 TR", "Bohn", "BHT050"),
    "evaporador": ("Evaporador de cámara de refrigeración", "Bohn", "ADT104"),
    "bomba": ("Bomba centrífuga 3 HP", "Grundfos", "CM5-5"),
    "compresor": ("Compresor de aire 5 HP", "Ingersoll Rand", "SS5L5"),
    "tablero": ("Tablero de control de motores", "Schneider Electric", "TeSys D"),
}
padron_def = {  # cliente_idx: [(sucursal_idx, tipo, cantidad)]
    1: [(0, "condensadora", 2), (0, "evaporador", 2), (1, "compresor", 1), (1, "tablero", 1)],
    2: [(0, "condensadora", 2), (0, "evaporador", 3), (1, "evaporador", 2)],
    3: [(0, "minisplit_d", 3), (1, "minisplit_d", 2), (0, "bomba", 1)],
    4: [(0, "minisplit_d", 3), (0, "bomba", 2), (0, "tablero", 1)],
    5: [(0, "compresor", 1), (1, "bomba", 2), (1, "tablero", 1)],
    6: [(0, "compresor", 1), (0, "tablero", 2)],
    7: [(0, "minisplit_d", 2), (0, "bomba", 1)],
    8: [(0, "condensadora", 1), (0, "evaporador", 1), (0, "compresor", 1)],
}
padron = []  # dicts
serie_n = 1000
for ci, defs in padron_def.items():
    sucursales = clientes[ci - 1][6]
    for si, tipo, cant in defs:
        equipo, marca, modelo = equipos_tipo[tipo]
        for _ in range(cant):
            serie_n += random.randint(7, 61)
            padron.append({
                "cliente": clientes[ci - 1][0], "sucursal": sucursales[si], "equipo": equipo, "marca": marca,
                "modelo": modelo, "numero_serie": f"{marca[:2].upper()}{modelo[:3].upper().replace(' ', '')}-{serie_n}",
                "tipo": tipo, "cliente_idx": ci,
            })

# --------------------------------------------------------------------- ventas (descuentan inventario)
# (dias_atras, cliente_idx, sucursal_idx, sku, cantidad, garantia, servicio_meses)
ventas_def = [
    (160, 3, 0, "EQP-0001", 3, 12, 6),
    (128, 7, 0, "EQP-0001", 2, 12, 6),
    (96, 4, 0, "EQP-0002", 1, 12, 6),
    (61, 6, 0, "EQP-0003", 1, 12, 3),
    (33, 1, 1, "EQP-0003", 1, 12, 3),
    (12, 5, 1, "EQP-0002", 2, 12, 6),
]
filas_ventas, filas_vdet = [], []
vdet_id = 0
for vi, (dias, ci, si, sku, cant, gar, serv) in enumerate(ventas_def, start=1):
    a = articulos[ART[sku] - 1]
    folio = f"VT-{vi:05d}"
    cliente, sucursal = clientes[ci - 1][0], clientes[ci - 1][6][si]
    filas_ventas.append({"id": vi, "folio": folio, "cliente": cliente, "fecha_registro": fecha(dias),
                         "fecha_actualizacion": fecha(dias), "sucursal": sucursal})
    minuto = -dias * DIA + 11 * 60 + vi * 7
    for _ in range(cant):
        vdet_id += 1
        serie_n += random.randint(11, 40)
        serie = f"{a[3][:2].upper()}{a[4][:3].upper().replace(' ', '')}-{serie_n}"
        prox = dias - serv * 30
        filas_vdet.append({"id": vdet_id, "venta_id": vi, "articulo_id": ART[sku], "equipo": a[1], "marca": a[3],
                           "modelo": a[4], "numero_serie": serie, "garantia": gar, "calibracion": 0, "servicio": 1,
                           "frecuencia_servicio": serv, "notas": "Instalación incluida",
                           "proxima_calibracion": None, "proximo_servicio": fecha(prox)})
        padron.append({"cliente": cliente, "sucursal": sucursal, "equipo": a[1], "marca": a[3], "modelo": a[4],
                       "numero_serie": serie, "tipo": None, "cliente_idx": ci, "venta": (vi, vdet_id, dias, serv)})
    movimientos.append((minuto, sku, -cant, "salida", f"Venta {folio}", "venta", vi, folio, 1))

filas_padron = []
for pid, p in enumerate(padron, start=1):
    venta = p.get("venta")
    if venta:
        vi, vdet, dias, serv = venta
        fila = {"origen": "Venta propia", "venta_id": vi, "venta_detalle_id": vdet, "fecha_registro": fecha(dias),
                "servicio": 1, "frecuencia_servicio": serv, "garantia": 12, "proximo_servicio": fecha(dias - serv * 30)}
    else:
        # Externos con póliza de servicio: uno vence en 7 días (genera un ticket automático de demo)
        con_poliza = p["tipo"] in ("condensadora", "compresor", "bomba")
        dias_prox = -7 if pid == 1 else -random.randint(25, 80)
        fila = {"origen": "Externo", "venta_id": None, "venta_detalle_id": None, "fecha_registro": fecha(185),
                "servicio": 1 if con_poliza else 0, "frecuencia_servicio": 3 if con_poliza else 0, "garantia": 0,
                "proximo_servicio": fecha(dias_prox) if con_poliza else None}
    filas_padron.append({"id": pid, "cliente": p["cliente"], "sucursal": p["sucursal"], "equipo": p["equipo"],
                         "marca": p["marca"], "modelo": p["modelo"], "numero_serie": p["numero_serie"],
                         "calibracion": 0, "servicio": fila["servicio"], "frecuencia_servicio": fila["frecuencia_servicio"],
                         "garantia": fila["garantia"], "proxima_calibracion": None, "proximo_servicio": fila["proximo_servicio"],
                         "origen": fila["origen"], "venta_id": fila["venta_id"], "venta_detalle_id": fila["venta_detalle_id"],
                         "fecha_registro": fila["fecha_registro"]})

# --------------------------------------------------------------------- incidencias
# Por tipo de equipo: (falla, acción, refacciones [(sku, cant)], categoría)
FALLAS = {
    "condensadora": [
        ("Cámara no alcanza temperatura: 9 °C con setpoint de 2 °C.", "Se detectó fuga en conexión de descarga. Se reparó, se hizo vacío y se recargó refrigerante.", [("CON-0001", 4.5)], "Mantenimiento correctivo"),
        ("Alarma de alta presión, el compresor se detiene.", "Serpentín de condensador obstruido. Se lavó con limpiador espumoso y se revisó el motor ventilador.", [("CON-0005", 2)], "Mantenimiento correctivo"),
        ("Motor ventilador de condensador no arranca.", "Motor con devanado abierto. Se sustituyó el motor y el capacitor.", [("REF-0010", 1), ("REF-0004", 1)], "Mantenimiento correctivo"),
        ("Servicio preventivo trimestral de unidad condensadora.", "Limpieza de serpentín, reapriete eléctrico, medición de presiones y amperajes. Equipo operando en parámetros.", [("CON-0005", 1)], "Mantenimiento preventivo"),
    ],
    "evaporador": [
        ("Formación de hielo en el evaporador de la cámara.", "Resistencia de deshielo dañada y válvula de expansión mal calibrada. Se cambió la TXV y se ajustó el ciclo de deshielo.", [("REF-0009", 1)], "Mantenimiento correctivo"),
        ("Goteo de agua sobre producto en cámara.", "Charola de drenado obstruida. Se limpió y se instaló trampa en la línea de drenaje.", [], "Mantenimiento correctivo"),
        ("Revisión preventiva de evaporadores.", "Limpieza de serpentín y ventiladores, verificación de deshielos y termostatos.", [("CON-0005", 1)], "Mantenimiento preventivo"),
    ],
    "minisplit": [
        ("Equipo enciende pero no enfría.", "Capacitor de marcha abierto. Se reemplazó y se verificó la carga de refrigerante.", [("REF-0004", 1), ("CON-0001", 1)], "Mantenimiento correctivo"),
        ("Gotea agua por la unidad evaporadora.", "Drenaje tapado por lodo. Se destapó la línea y se lavó la unidad.", [], "Mantenimiento correctivo"),
        ("Mantenimiento preventivo semestral de minisplits.", "Lavado de evaporador y condensador, cambio de filtro y revisión de conexiones.", [("REF-0005", 1)], "Mantenimiento preventivo"),
        ("El control no responde y la pantalla parpadea.", "Se diagnosticó falla en tarjeta receptora. Se cotizó el reemplazo con el fabricante.", [], "Diagnóstico"),
    ],
    "bomba": [
        ("Fuga de agua por el sello de la bomba.", "Se desmontó la bomba y se cambió el sello mecánico y los rodamientos.", [("REF-0006", 1), ("REF-0001", 2)], "Mantenimiento correctivo"),
        ("Vibración y ruido excesivo en la bomba.", "Rodamientos desgastados y desalineación con el motor. Se cambiaron y se alineó el acoplamiento.", [("REF-0001", 2), ("CON-0003", 1)], "Mantenimiento correctivo"),
        ("Baja presión en la red de agua.", "Impulsor con incrustación. Se limpió y se ajustó el presostato.", [], "Diagnóstico"),
        ("Servicio preventivo de equipo de bombeo.", "Engrase, revisión de sello y medición de consumo eléctrico.", [("CON-0003", 1)], "Mantenimiento preventivo"),
    ],
    "compresor": [
        ("Compresor dispara la protección térmica.", "Relevador térmico dañado y banda floja. Se cambiaron el relevador y la banda.", [("REF-0007", 1), ("REF-0002", 1)], "Mantenimiento correctivo"),
        ("El compresor no alcanza la presión de corte.", "Válvula de admisión con fuga. Se reparó y se cambió el aceite.", [("CON-0002", 2)], "Mantenimiento correctivo"),
        ("Mantenimiento preventivo de compresor de aire.", "Cambio de aceite y filtro, tensión de bandas y purga del tanque.", [("CON-0002", 2), ("REF-0002", 1)], "Mantenimiento preventivo"),
    ],
    "tablero": [
        ("Contactor con contactos carbonizados, el motor no arranca.", "Se sustituyó el contactor y se reapretaron las terminales del tablero.", [("REF-0003", 1), ("CON-0004", 1)], "Mantenimiento correctivo"),
        ("Disparo intermitente del interruptor principal.", "Desbalance de fases por conexión floja. Se corrigió y se termografió el tablero.", [("CON-0004", 1)], "Diagnóstico"),
        ("Instalación de termostato programable para el área de producción.", "Se instaló y programó el termostato con horario de operación.", [("REF-0008", 1), ("CON-0004", 1)], "Instalación"),
    ],
}
FALLAS["minisplit_d"] = FALLAS["minisplit"]
GARANTIA = ("Revisión en garantía: el equipo presenta ruido al arrancar.", "Ajuste de soportes antivibratorios y reapriete. Cubierto por garantía.", [], "Garantía")

padron_con_tipo = []
for i, p in enumerate(padron):
    tipo = p["tipo"]
    if tipo is None:  # equipos vendidos
        tipo = {"Minisplit inverter 2 TR 220 V": "minisplit", "Bomba centrífuga 3 HP": "bomba",
                "Compresor de aire 5 HP 60 gal": "compresor"}[p["equipo"]]
    padron_con_tipo.append((p, tipo))


def elegir_tecnico():
    t = random.choices(TECNICOS, weights=PESO_TECNICO)[0]
    if random.random() < 0.12:  # algunas visitas en pareja
        otro = random.choice([x for x in TECNICOS if x != t])
        return f"{t}/{otro}"
    return t


filas_inc, filas_aud = [], []
aud_id = 0


def auditar(minuto, accion, reg_id, folio, antes, despues, usuario, tabla="incidencias"):
    global aud_id
    aud_id += 1
    uid, nombre, rol = usuario
    filas_aud.append({"id": aud_id, "usuario_id": uid, "usuario_nombre": nombre, "usuario_rol": rol,
                      "accion": accion, "tabla": tabla, "registro_id": str(reg_id), "registro_folio": folio,
                      "datos_anteriores": json.dumps(antes, ensure_ascii=False) if antes else None,
                      "datos_nuevos": json.dumps(despues, ensure_ascii=False) if despues else None,
                      "ip_address": "10.0.0." + str(10 + uid), "creado_en": momento(minuto)})


USR = {u[1]: (u[0], u[1], u[3]) for u in usuarios}
ADMIN = USR["Usuario Demo"]

# Plan: 40 históricas cerradas (últimos ~6 meses) + 10 recientes con estatus mixto
plan = []
for _ in range(40):
    plan.append(("cerrada", random.randint(14, 178)))
plan.sort(key=lambda x: -x[1])
plan += [
    ("Cerrado con factura", 11), ("Cerrado sin factura", 9), ("Completado", 7),
    ("Pendiente", 6), ("Pendiente", 5), ("Asignado", 4), ("Asignado", 3),
    ("Abierto", 2), ("Abierto", 1), ("Abierto", 1),
]

# Equipo con reincidencia (mismo número de serie varias veces): se ve en estadísticas
reincidente = padron_con_tipo[5]

for n, (estado, dias) in enumerate(plan, start=1):
    if n == 45:  # pendiente con sello mecánico ya apartado (ver movimiento abajo)
        p, tipo = next(x for x in padron_con_tipo if x[1] == "bomba")
        falla, accion, refacciones, categoria = FALLAS["bomba"][0]
    else:
        p, tipo = reincidente if n in (8, 23, 37, 46) else random.choice(padron_con_tipo)
        falla, accion, refacciones, categoria = random.choice(FALLAS[tipo])
        if p.get("venta") and random.random() < 0.35:
            falla, accion, refacciones, categoria = GARANTIA
    folio = f"SD-{n:04d}"
    tecnico = elegir_tecnico()
    tec_principal = USR[tecnico.split("/")[0]]
    ci = p["cliente_idx"]
    contacto = clientes[ci - 1][5][0][0]
    alta = -dias * DIA + random.randint(8 * 60, 17 * 60)
    reporte = random.choice(["", "", f"OT-{random.randint(2100, 9800)}", f"TK-{random.randint(300, 990)}"])

    cerrada = estado not in ("Abierto", "Asignado", "Pendiente")
    final = estado if estado != "cerrada" else random.choices(
        ["Cerrado con factura", "Cerrado sin factura", "Completado"], weights=[60, 25, 15])[0]
    accion_txt = accion if (cerrada or estado == "Pendiente") else ""
    notas = ""
    if estado == "Pendiente":
        notas = "En espera de refacción / autorización de cotización por parte del cliente."
        accion_txt = "Diagnóstico realizado: se requiere refacción. Se cotizó al cliente y se apartó el material en almacén."

    filas_inc.append({"id": n, "reporte_cliente": reporte, "numero_incidente": folio, "cliente": p["cliente"],
                      "contacto": contacto, "sucursal": p["sucursal"], "fecha": fecha(dias), "tecnico": tecnico,
                      "falla": falla, "categoria": categoria, "numero_serie": p["numero_serie"], "estatus": final,
                      "accion": accion_txt, "notas": notas})

    auditar(alta, "CREATE", n, folio, None,
            {"numero_incidente": folio, "cliente": p["cliente"], "sucursal": p["sucursal"], "tecnico": tecnico,
             "falla": falla, "categoria": categoria, "estatus": "Abierto"}, ADMIN)
    if estado == "Abierto":
        continue

    # Primera respuesta: ~80 % dentro del SLA de 4 h
    resp = alta + int(60 * (random.uniform(0.3, 3.8) if random.random() < 0.8 else random.uniform(4.5, 9)))
    auditar(resp, "UPDATE", n, folio, {"estatus": "Abierto"}, {"estatus": "Asignado"}, USR[SUPERVISOR])
    if estado == "Asignado":
        continue

    previo = "Asignado"
    if estado == "Pendiente" or (cerrada and random.random() < 0.25):
        pend = resp + int(60 * random.uniform(2, 20))
        auditar(pend, "UPDATE", n, folio, {"estatus": "Asignado"},
                {"estatus": "Pendiente", "notas": "En espera de refacción"}, tec_principal)
        previo = "Pendiente"
        resp = pend
    if estado == "Pendiente":
        continue

    # Cierre: la mayoría en menos de 3 días, algunos se alargan
    horas = random.choices([random.uniform(3, 22), random.uniform(24, 70), random.uniform(72, 160), random.uniform(170, 220)],
                           weights=[40, 35, 18, 7])[0]
    cierre = min(resp + int(60 * horas), -60 * 6)
    auditar(cierre, "UPDATE", n, folio, {"estatus": previo, "accion": ""}, {"estatus": final, "accion": accion}, tec_principal)
    for sku, cant in refacciones:
        movimientos.append((cierre - 5, sku, -cant, "salida", f"Utilizado en incidencia {folio}", "incidencia", n, folio,
                            tec_principal[0]))

# Una incidencia pendiente ya tiene la refacción apartada (sale del almacén)
movimientos.append((-5 * DIA + 15 * 60, "REF-0006", -1, "salida", "Utilizado en incidencia SD-0045", "incidencia", 45, "SD-0045", 4))

# --------------------------------------------------------------------- kárdex consistente
# Existencia inicial (hace 185 días) + compras mensuales; se calcula para que la
# existencia final sea la definida en articulos[] y nunca quede en negativo.
consumo = {a[0]: 0.0 for a in articulos}
for m in movimientos:
    consumo[m[1]] += -m[2]

for idx, a in enumerate(articulos):
    sku, final = a[0], a[10]
    total_salidas = consumo[sku]
    requerido = final + total_salidas
    if total_salidas > 0 and a[2] in ("Refacción", "Consumible"):
        # mitad al inicio, el resto en dos compras a proveedor
        inicial = round(requerido * 0.5, 2)
        compra = round((requerido - inicial) / 2, 2)
        compras = [(-120 * DIA + 10 * 60, compra), (-55 * DIA + 10 * 60, round(requerido - inicial - compra, 2))]
    else:
        inicial, compras = requerido, []
    movimientos.append((-185 * DIA + 9 * 60 + idx, sku, inicial, "entrada", "Existencia inicial", "manual", None, None, 1))
    for k, (minuto, cant) in enumerate(compras, start=1):
        if cant > 0:
            movimientos.append((minuto + idx, sku, cant, "entrada", f"Compra a proveedor OC-{2300 + idx * 3 + k}", "manual", None, None, 2))

movimientos.sort(key=lambda m: m[0])
saldo = {a[0]: 0.0 for a in articulos}
filas_mov = []
for i, (minuto, sku, cant, tipo, motivo, rt, rid, rfolio, uid) in enumerate(movimientos, start=1):
    saldo[sku] = round(saldo[sku] + cant, 2)
    if saldo[sku] < 0:
        # Garantiza un kárdex válido: adelanta una compra justo antes
        raise SystemExit(f"Existencia negativa en {sku} al procesar {motivo}")
    usuario = next(u for u in usuarios if u[0] == uid)
    filas_mov.append({"id": i, "articulo_id": ART[sku], "tipo": tipo, "cantidad": float(cant),
                      "existencia_resultante": saldo[sku], "costo_unitario": float(COSTO[sku]), "motivo": motivo,
                      "referencia_tipo": rt, "referencia_id": rid, "referencia_folio": rfolio,
                      "usuario_id": uid, "usuario_nombre": usuario[1], "creado_en": momento(minuto)})

for a in articulos:
    assert abs(saldo[a[0]] - a[10]) < 0.01, (a[0], saldo[a[0]], a[10])


# --------------------------------------------------------------------- historial de inventario y ventas
# Mismo formato que backend/lib/inventario_helpers.php (invAuditarMovimiento)
def cant(n):
    return str(int(n)) if float(n).is_integer() else f"{n:g}"


USR_ID = {u[0]: (u[0], u[1], u[3]) for u in usuarios}
for i, a in enumerate(articulos, start=1):
    auditar(-185 * DIA + 9 * 60 + i - 1, "CREATE", i, a[0], None,
            {"sku": a[0], "nombre": a[1], "categoria": a[2], "marca": a[3], "modelo": a[4], "unidad": a[5],
             "stock_minimo": a[6], "costo_unitario": a[7], "precio_venta": a[8], "ubicacion": a[9]},
            ADMIN, "inventario_articulos")

for vi, (dias, ci, si, sku, cantv, gar, serv) in enumerate(ventas_def, start=1):
    a = articulos[ART[sku] - 1]
    auditar(-dias * DIA + 11 * 60 + vi * 7 - 1, "CREATE", vi, f"VT-{vi:05d}", None,
            {"folio": f"VT-{vi:05d}", "cliente": clientes[ci - 1][0], "sucursal": clientes[ci - 1][6][si],
             "equipo": a[1], "marca": a[3], "modelo": a[4], "garantia": gar, "servicio": 1,
             "frecuencia_servicio": serv, "articulo_inventario_id": ART[sku],
             "equipos": [{"numero_serie": d["numero_serie"]} for d in filas_vdet if d["venta_id"] == vi]},
            ADMIN, "ventas")

saldo_prev = {a[0]: 0.0 for a in articulos}
ACCION = {"entrada": "ENTRADA", "salida": "SALIDA", "ajuste": "AJUSTE"}
for (minuto, sku, c, tipo, motivo, rt, rid, rfolio, uid), fm in zip(movimientos, filas_mov):
    a = articulos[ART[sku] - 1]
    unidad, base = a[5], {"sku": sku, "articulo": a[1]}
    antes_ex, despues_ex = saldo_prev[sku], fm["existencia_resultante"]
    saldo_prev[sku] = despues_ex
    ref = None
    if rt in ("incidencia", "venta") and rid:
        ref = ("Venta " if rt == "venta" else "Incidencia ") + rfolio
    usuario = USR_ID[uid]
    auditar(minuto, ACCION[tipo], ART[sku], sku, {**base, "existencia": f"{cant(antes_ex)} {unidad}"},
            {**base, "existencia": f"{cant(despues_ex)} {unidad}", "movimiento": tipo.capitalize(),
             "cantidad": f"{'+' if c > 0 else ''}{cant(c)} {unidad}", "motivo": motivo, **({"referencia": ref} if ref else {})},
            usuario, "inventario_articulos")
    if ref:
        auditar(minuto, "INVENTARIO", rid, rfolio, None,
                {**base, "movimiento": "Salida de almacén" if c < 0 else "Devolución a almacén",
                 "cantidad": f"{cant(abs(c))} {unidad}", "existencia_resultante": f"{cant(despues_ex)} {unidad}",
                 "motivo": motivo},
                usuario, "incidencias" if rt == "incidencia" else "ventas")

filas_art = [{"id": i, "sku": a[0], "nombre": a[1], "categoria": a[2], "marca": a[3], "modelo": a[4],
              "descripcion": None, "unidad": a[5], "existencia": float(a[10]), "stock_minimo": float(a[6]),
              "costo_unitario": float(a[7]), "precio_venta": float(a[8]), "ubicacion": a[9], "activo": 1,
              "creado_en": momento(-185 * DIA + 9 * 60), "actualizado_en": None}
             for i, a in enumerate(articulos, start=1)]

# --------------------------------------------------------------------- salida
# Orden de tablas hijas a padres: se vacían con DELETE (MySQL 8 no permite TRUNCATE
# en tablas referenciadas por llaves foráneas, aunque FOREIGN_KEY_CHECKS=0).
TABLAS = ["cobros", "cuentas_cobrar", "gastos", "inventario_movimientos", "inventario_articulos", "venta_archivos", "archivos_ventas", "venta_detalles",
          "ventas", "padron_equipos", "archivos_incidencias", "incidencias", "cliente_contactos", "clientes",
          "documentos", "modelos", "marcas", "auditoria", "folios"]

sql = [
    "-- =====================================================================\n"
    "--  Servidesk - datos de demostración\n"
    "--  GENERADO por database/tools/generar_seed.py (no editar a mano).\n"
    "--\n"
    "--  BORRA los datos de la EMPRESA DEMO (id 1: tablas e1_* y sus usuarios) y carga\n"
    "--  una empresa ficticia; no toca otras empresas. Conserva e1_permisos_rol. Fechas relativas a\n"
    "--  CURDATE(): la demo siempre aparece al día.\n"
    "--\n"
    "--  Accesos:  demo@sistema.com / demo123  (Administrador)\n"
    "--            mherrera, cmendoza, atrevino, jcastillo, ribarra, dfuentes / tecnico123\n"
    "-- =====================================================================\n\n",
    "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n",
    "".join(f"DELETE FROM `{tabla_sql(t)}`;\n" for t in TABLAS),
    f"DELETE FROM `analytics_eventos` WHERE `empresa_id` = {EMPRESA_DEMO};\n",
    f"DELETE FROM `usuarios` WHERE `empresa_id` = {EMPRESA_DEMO};\n\n",
    insert("usuarios", filas_usuarios),
    insert("clientes", filas_clientes),
    insert("cliente_contactos", filas_contactos),
    insert("marcas", filas_marcas),
    insert("modelos", filas_modelos),
    insert("documentos", filas_docs),
    insert("inventario_articulos", filas_art),
    insert("ventas", filas_ventas),
    insert("venta_detalles", filas_vdet),
    insert("padron_equipos", filas_padron),
    insert("incidencias", filas_inc),
    "-- Estatus 2.13 (D-5, 0.1): las Abiertas no tienen técnico; las Pendientes llevan motivo\nUPDATE `e1_incidencias` SET tecnico = '' WHERE estatus = 'Abierto';\nUPDATE `e1_incidencias` SET motivo_estatus = 'Aprobación de cotización: refacción apartada en almacén' WHERE estatus = 'Pendiente';\n\n",
    insert("inventario_movimientos", filas_mov),
    insert("auditoria", filas_aud),
    "SET FOREIGN_KEY_CHECKS = 1;\n",
]
SALIDA.write_text("".join(sql), encoding="utf-8", newline="\n")
print(f"{SALIDA}: {len(filas_inc)} incidencias, {len(filas_art)} artículos, {len(filas_mov)} movimientos, "
      f"{len(filas_aud)} eventos de auditoría, {len(filas_padron)} equipos en padrón, {len(filas_ventas)} ventas")
