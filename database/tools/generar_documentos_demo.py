"""
Genera los PDF de ejemplo del módulo Soporte para la demo.

Son guías genéricas de mantenimiento (no reproducen manuales de fabricantes)
marcadas como material de demostración. Se escriben en dos lugares:
  - manuales/e1/          lo que sirve la app a la empresa demo (rutas en seed.sql)
  - database/demo_docs/   copia de respaldo: cron/reset_demo.php la usa para
                          restaurar manuales/e1/ cada noche
Uso:  python database/tools/generar_documentos_demo.py   (pip install reportlab)
"""
from pathlib import Path


from reportlab.lib import colors
from reportlab.lib.pagesizes import letter
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import cm
from reportlab.platypus import Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle

RAIZ = Path(__file__).resolve().parents[2]
DESTINOS = [RAIZ / "manuales" / "e1", RAIZ / "database" / "demo_docs"]
AZUL = colors.HexColor("#1a4de4")

estilos = getSampleStyleSheet()
TITULO = ParagraphStyle("t", parent=estilos["Title"], textColor=AZUL, fontSize=18, spaceAfter=6)
SUB = ParagraphStyle("s", parent=estilos["Normal"], textColor=colors.HexColor("#5f6f82"), fontSize=10, spaceAfter=12)
H2 = ParagraphStyle("h2", parent=estilos["Heading2"], textColor=colors.HexColor("#2c3e50"), fontSize=13, spaceBefore=10)
TXT = ParagraphStyle("p", parent=estilos["Normal"], fontSize=10, leading=14)
AVISO = ParagraphStyle("a", parent=TXT, fontSize=8, textColor=colors.HexColor("#a35a00"))


def tabla(filas, anchos):
    t = Table(filas, colWidths=anchos, repeatRows=1)
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), AZUL), ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
        ("FONTNAME", (0, 0), (-1, 0), "Helvetica-Bold"), ("FONTSIZE", (0, 0), (-1, -1), 9),
        ("GRID", (0, 0), (-1, -1), 0.4, colors.HexColor("#d5dce5")),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, colors.HexColor("#f7f9fc")]),
        ("VALIGN", (0, 0), (-1, -1), "TOP"), ("LEFTPADDING", (0, 0), (-1, -1), 6),
    ]))
    return t


def documento(archivo, titulo, subtitulo, secciones):
    historia = [Paragraph(titulo, TITULO), Paragraph(subtitulo, SUB),
                Paragraph("Documento de ejemplo para la demostración de Servidesk. Contenido genérico "
                          "de referencia; no sustituye la documentación oficial del fabricante.", AVISO),
                Spacer(1, 10)]
    for encabezado, contenido in secciones:
        historia.append(Paragraph(encabezado, H2))
        for bloque in contenido:
            if isinstance(bloque, tuple):
                historia.append(tabla(*bloque))
            else:
                historia.append(Paragraph(bloque, TXT))
            historia.append(Spacer(1, 6))
    for destino in DESTINOS:
        destino.mkdir(parents=True, exist_ok=True)
        SimpleDocTemplate(str(destino / archivo), pagesize=letter, title=titulo, author="Servidesk (demo)",
                          leftMargin=2 * cm, rightMargin=2 * cm, topMargin=2 * cm, bottomMargin=2 * cm).build(list(historia))


DOCS = [
    ("minisplit-mantenimiento.pdf", "Mantenimiento preventivo de minisplit inverter",
     "Equipos de 1 a 3 TR · Frecuencia semestral",
     [("1. Seguridad", ["Desenergizar el equipo desde el interruptor dedicado y verificar ausencia de tensión. "
                        "Usar guantes, lentes y escalera dieléctrica."]),
      ("2. Lista de verificación", [([["Paso", "Actividad", "Valor de referencia"],
                                      ["1", "Limpiar o cambiar filtros de aire", "Sin obstrucción visible"],
                                      ["2", "Lavar serpentín evaporador con limpiador espumoso", "Aletas rectas y limpias"],
                                      ["3", "Destapar charola y línea de drenaje", "Flujo libre al verter 1 L de agua"],
                                      ["4", "Lavar serpentín condensador", "Sin polvo ni grasa"],
                                      ["5", "Reapretar terminales eléctricas", "Torque según placa"],
                                      ["6", "Medir corriente de compresor", "± 10 % de la nominal"],
                                      ["7", "Medir presión de succión (R-410A)", "115 – 130 psi en enfriamiento"],
                                      ["8", "Diferencial de temperatura retorno / inyección", "10 – 12 °C"]],
                                     [1.4 * cm, 9 * cm, 6 * cm])]),
      ("3. Fallas frecuentes", ["<b>No enfría:</b> revisar capacitor de marcha, carga de refrigerante y ventilador del condensador.",
                                "<b>Gotea agua:</b> drenaje obstruido o equipo sin pendiente hacia el desagüe.",
                                "<b>Código de error en pantalla:</b> registrar el código en la incidencia y consultar la tabla del fabricante."])]),
    ("bomba-centrifuga-partes.pdf", "Lista de partes de servicio · Bomba centrífuga 3 HP",
     "Refacciones de reemplazo frecuente y su referencia en inventario",
     [("Partes de desgaste", [([["Parte", "Cambio sugerido", "SKU inventario"],
                                ["Sello mecánico 1\"", "Al presentar fuga o cada 2 años", "REF-0006"],
                                ["Rodamiento lado acople 6205-2RS", "Cada 15 000 h o con ruido", "REF-0001"],
                                ["Rodamiento lado ventilador 6205-2RS", "Cada 15 000 h o con ruido", "REF-0001"],
                                ["Grasa EP2", "Cada 3 meses", "CON-0003"],
                                ["Empaque de voluta", "En cada desarme", "—"]],
                               [7 * cm, 5.5 * cm, 4 * cm])]),
      ("Procedimiento de cambio de sello", ["1) Cerrar válvulas de succión y descarga y drenar la carcasa. "
                                             "2) Desacoplar motor y retirar impulsor. 3) Sustituir sello sin tocar las caras de contacto. "
                                             "4) Rearmar, purgar aire y verificar que no haya fuga a presión de operación."])]),
    ("compresor-programa-servicio.pdf", "Programa de servicio · Compresor de aire 5 HP",
     "Compresor reciprocante de dos etapas con tanque de 60 gal",
     [("Intervalos", [([["Intervalo", "Actividad"],
                        ["Diario", "Purgar condensado del tanque; revisar nivel de aceite"],
                        ["Semanal", "Limpiar filtro de admisión; revisar fugas con agua jabonosa"],
                        ["Mensual", "Revisar tensión y estado de bandas"],
                        ["Trimestral", "Cambiar aceite (CON-0002) y filtro de admisión"],
                        ["Anual", "Probar válvula de seguridad y calibrar presostato"]],
                       [3.5 * cm, 13 * cm])]),
      ("Valores de operación", ["Presión de arranque: 140 psi · Presión de paro: 175 psi · "
                                "Temperatura de descarga máxima: 120 °C."])]),
    ("condensadora-diagrama.pdf", "Diagrama de control simplificado · Unidad condensadora 5 TR",
     "Secuencia de arranque y protecciones",
     [("Secuencia", ["1) El termostato de cámara cierra el circuito de la válvula solenoide de líquido. "
                     "2) Al subir la presión de succión, el presostato de baja energiza la bobina del contactor (REF-0003). "
                     "3) Arrancan compresor y ventilador del condensador (REF-0010). "
                     "4) Las protecciones de alta presión y relevador térmico (REF-0007) abren la bobina ante una falla."]),
      ("Puntos de medición", [([["Punto", "Medición", "Esperado"],
                                ["L1 – L2", "Tensión de alimentación", "220 V ± 10 %"],
                                ["Bobina contactor", "Tensión de control", "24 V CA"],
                                ["Compresor", "Corriente de operación", "Según placa ± 10 %"],
                                ["Capacitor ventilador", "Capacitancia", "± 6 % del valor nominal"]],
                               [4.5 * cm, 6 * cm, 6 * cm])])]),
    ("tablero-guia-contactores.pdf", "Guía rápida · Selección de contactor y relevador térmico",
     "Arranque directo de motores trifásicos a 220 V",
     [("Tabla de selección", [([["Motor", "Corriente nominal", "Contactor", "Relevador térmico"],
                                ["3 HP", "9.6 A", "LC1D12", "LRD14 (7 – 10 A)"],
                                ["5 HP", "15.2 A", "LC1D18", "LRD21 (12 – 18 A)"],
                                ["7.5 HP", "22 A", "LC1D25", "LRD22 (16 – 24 A)"],
                                ["10 HP", "28 A", "LC1D32 (REF-0003)", "LRD32 (23 – 32 A, REF-0007)"]],
                               [3 * cm, 4 * cm, 4.5 * cm, 5 * cm])]),
      ("Recomendaciones", ["Ajustar el relevador térmico a la corriente de placa del motor. "
                           "Reapretar terminales en cada servicio preventivo y registrar la termografía en la incidencia."])]),
]

if __name__ == "__main__":
    for d in DESTINOS:
        # Solo los PDF: borrar la carpeta falla en Windows/OneDrive y no hace falta
        for viejo in d.glob("*.pdf") if d.exists() else []:
            viejo.unlink()
    for archivo, titulo, sub, secciones in DOCS:
        documento(archivo, titulo, sub, secciones)
    print("PDF generados:", ", ".join(d[0] for d in DOCS))
