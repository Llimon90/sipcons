document.addEventListener('DOMContentLoaded', () => {
    let todasLasVentas = []; // Aquí guardaremos la "base de datos" local en memoria

    // Referencias a los elementos del DOM
    const tbody = document.getElementById('tabla-gestion-body'); 
    const kpiVentas = document.getElementById('stat-total-ventas'); 
    const kpiEquipos = document.getElementById('stat-total-equipos'); 
    const kpiServicio = document.getElementById('stat-con-servicio'); 

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
                todasLasVentas = data.ventas; 
                procesarYRenderizar(todasLasVentas); 
            }
        } catch (e) {
            console.error("Error cargando el dashboard:", e);
        }
    };

    // 2. FUNCIÓN DE RENDERIZADO Y KPIs
    const procesarYRenderizar = (datos) => {
        // --- Actualizar KPIs ---
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
        tbody.innerHTML = ''; // Limpiamos la tabla

        if (datos.length === 0) {
            tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding: 20px; color: #7f8c8d;"><i class="fas fa-search"></i> No se encontraron resultados con estos filtros.</td></tr>`;
            return;
        }

        datos.forEach(v => {
            const tr = document.createElement('tr');
            
            // LA MAGIA DE LA FILA CLICKEABLE
            tr.style.cursor = 'pointer';
            tr.style.transition = 'background-color 0.2s ease';
            tr.addEventListener('mouseenter', () => tr.style.backgroundColor = '#f1f5f9');
            tr.addEventListener('mouseleave', () => tr.style.backgroundColor = 'transparent');
            
            // Redirección directa al detalle de la venta (Asegúrate de que este archivo exista)
            tr.onclick = () => {
                window.location.href = `detalles-venta.html?id=${v.id}`;
            };

            tr.innerHTML = `
                <td><span style="background:#e8f4f8; color:#2980b9; padding:4px 8px; border-radius:4px; font-weight:bold; font-size: 0.9em;">${v.folio}</span></td>
                <td>${v.fecha_registro.split(' ')[0]}</td> 
                <td><strong>${v.cliente}</strong></td>
                <td><small>${v.equipos || '-'}</small><br><small style="color:#7f8c8d;">${v.marcas || ''}</small></td>
                <td style="text-align: center; font-weight: bold;">${v.cantidad_equipos}</td>
                <td style="text-align: center;">
                    ${v.equipos_con_servicio > 0 ? `<span style="color:#27ae60;" title="${v.equipos_con_servicio} equipos con cláusula"><i class="fas fa-check-circle"></i> Sí</span>` : '<span style="color:#e74c3c;"><i class="fas fa-times-circle"></i> No</span>'}
                </td>
            `;
            tbody.appendChild(tr);
        });
    };

    // 3. MOTOR DE FILTRADO
    const aplicarFiltros = () => {
        const texto = txtFiltro.value.toLowerCase();
        const fIni = fechaInicio.value;
        const fFin = fechaFin.value;
        const serv = selectServicio.value;

        const ventasFiltradas = todasLasVentas.filter(v => {
            const matchTexto = 
                (v.folio && v.folio.toLowerCase().includes(texto)) ||
                (v.cliente && v.cliente.toLowerCase().includes(texto)) ||
                (v.equipos && v.equipos.toLowerCase().includes(texto)) ||
                (v.marcas && v.marcas.toLowerCase().includes(texto)) ||
                (v.modelos && v.modelos.toLowerCase().includes(texto));

            let matchFecha = true;
            const fechaVenta = v.fecha_registro.split(' ')[0]; 
            if (fIni && fechaVenta < fIni) matchFecha = false;
            if (fFin && fechaVenta > fFin) matchFecha = false;

            let matchServicio = true;
            if (serv === 'si' && parseInt(v.equipos_con_servicio) === 0) matchServicio = false;
            if (serv === 'no' && parseInt(v.equipos_con_servicio) > 0) matchServicio = false;

            return matchTexto && matchFecha && matchServicio;
        });

        procesarYRenderizar(ventasFiltradas);
    };

    // 4. LISTENERS
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
        procesarYRenderizar(todasLasVentas); 
    });

    // Arrancar el motor
    cargarGestion();
});