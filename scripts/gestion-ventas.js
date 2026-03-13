document.addEventListener('DOMContentLoaded', () => {
    let todasLasVentas = []; // Aquí guardaremos la "base de datos" local en memoria

    // Referencias a los elementos del DOM
    const tbody = document.getElementById('tabla-gestion-body'); // Asegúrate de que el <tbody> de tu tabla tenga este ID
    const kpiVentas = document.getElementById('stat-total-ventas'); // ID del KPI "Ventas"
    const kpiEquipos = document.getElementById('stat-total-equipos'); // ID del KPI "Equipos"
    const kpiServicio = document.getElementById('stat-con-servicio'); // ID del KPI "Con Servicio"

    // Inputs de Filtros
    const txtFiltro = document.getElementById('filtro-texto');
    const fechaInicio = document.getElementById('filtro-fecha-inicio');
    const fechaFin = document.getElementById('filtro-fecha-fin');
    const selectServicio = document.getElementById('filtro-servicio');
    const btnLimpiar = document.getElementById('btn-limpiar-filtros');

    // 1. CARGAR DATOS INICIALES
    const cargarGestion = async () => {
        try {
            const resp = await fetch('../backend/obtener_gestion_ventas.php');
            const data = await resp.json();

            if (data.exito) {
                todasLasVentas = data.ventas; // Guardamos en memoria
                procesarYRenderizar(todasLasVentas); // Mostramos todo al inicio
            }
        } catch (e) {
            console.error("Error cargando el dashboard:", e);
        }
    };

    // 2. FUNCIÓN DE RENDERIZADO Y KPIs
    const procesarYRenderizar = (datos) => {
        // --- Actualizar KPIs Dinámicamente ---
        kpiVentas.textContent = datos.length;
        
        let totalEq = 0;
        let totalServ = 0;
        datos.forEach(v => {
            totalEq += parseInt(v.cantidad_equipos) || 0;
            totalServ += parseInt(v.equipos_con_servicio) || 0;
        });
        
        kpiEquipos.textContent = totalEq;
        kpiServicio.textContent = totalServ;

        // --- Renderizar Tabla ---
        if (datos.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding: 20px; color: #7f8c8d;"><i class="fas fa-search"></i> No se encontraron resultados con estos filtros.</td></tr>`;
            return;
        }

        tbody.innerHTML = datos.map(v => `
            <tr>
                <td><span style="background:#e8f4f8; color:#2980b9; padding:4px 8px; border-radius:4px; font-weight:bold; font-size: 0.9em;">${v.folio}</span></td>
                <td>${v.fecha_registro.split(' ')[0]}</td> <td><strong>${v.cliente}</strong></td>
                <td><small>${v.equipos || '-'}</small><br><small style="color:#7f8c8d;">${v.marcas || ''}</small></td>
                <td style="text-align: center;">${v.cantidad_equipos}</td>
                <td style="text-align: center;">
                    ${v.equipos_con_servicio > 0 ? `<span style="color:#27ae60;" title="${v.equipos_con_servicio} equipos con cláusula"><i class="fas fa-check-circle"></i> Sí</span>` : '<span style="color:#e74c3c;"><i class="fas fa-times-circle"></i> No</span>'}
                </td>
                <td style="text-align: center;">
                    <div style="display: flex; gap: 5px; justify-content: center;">
                        <button onclick="verDetalle(${v.id})" style="background:#3498db; color:white; border:none; padding:6px 10px; border-radius:4px; cursor:pointer;" title="Editar / Ver Detalle">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button onclick="imprimirTicket('${v.folio}')" style="background:#2ecc71; color:white; border:none; padding:6px 10px; border-radius:4px; cursor:pointer;" title="Imprimir Comprobante">
                            <i class="fas fa-print"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');
    };

    // 3. MOTOR DE FILTRADO (Se ejecuta al escribir o cambiar algo)
    const aplicarFiltros = () => {
        const texto = txtFiltro.value.toLowerCase();
        const fIni = fechaInicio.value;
        const fFin = fechaFin.value;
        const serv = selectServicio.value;

        const ventasFiltradas = todasLasVentas.filter(v => {
            // A. Filtro de Texto (Busca en Folio, Cliente, Sucursal, Equipo, Marca y Modelo)
            const matchTexto = 
                (v.folio && v.folio.toLowerCase().includes(texto)) ||
                (v.cliente && v.cliente.toLowerCase().includes(texto)) ||
                (v.equipos && v.equipos.toLowerCase().includes(texto)) ||
                (v.marcas && v.marcas.toLowerCase().includes(texto)) ||
                (v.modelos && v.modelos.toLowerCase().includes(texto));

            // B. Filtro de Fechas
            let matchFecha = true;
            const fechaVenta = v.fecha_registro.split(' ')[0]; // "2026-03-12"
            if (fIni && fechaVenta < fIni) matchFecha = false;
            if (fFin && fechaVenta > fFin) matchFecha = false;

            // C. Filtro de Servicio
            let matchServicio = true;
            if (serv === 'si' && parseInt(v.equipos_con_servicio) === 0) matchServicio = false;
            if (serv === 'no' && parseInt(v.equipos_con_servicio) > 0) matchServicio = false;

            // Retorna true solo si pasa todas las pruebas
            return matchTexto && matchFecha && matchServicio;
        });

        procesarYRenderizar(ventasFiltradas);
    };

    // 4. LISTENERS (Escuchar cambios en tiempo real)
    txtFiltro.addEventListener('input', aplicarFiltros);
    fechaInicio.addEventListener('change', aplicarFiltros);
    fechaFin.addEventListener('change', aplicarFiltros);
    selectServicio.addEventListener('change', aplicarFiltros);

    // Botón de Limpiar
    btnLimpiar.addEventListener('click', () => {
        txtFiltro.value = '';
        fechaInicio.value = '';
        fechaFin.value = '';
        selectServicio.value = 'todos';
        procesarYRenderizar(todasLasVentas); // Restaura todo
    });

    // Arrancar el motor
    cargarGestion();
});

// Funciones Globales para los botones de la tabla
window.verDetalle = (ventaId) => {
    window.location.href = `detalles-venta.html?id=${ventaId}`;
};

window.imprimirTicket = (folio) => {
    alert(`Preparando impresión para el folio: ${folio}`);
    // Aquí puedes enlazar tu generador de PDF
};