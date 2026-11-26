async function buscarVentas() {
    const params = new URLSearchParams();
    if (filtroCliente.value) params.append('cliente_id', filtroCliente.value);
    if (filtroFecha.value) params.append('fecha', filtroFecha.value);

    try {
        const response = await fetch(`../backend/consultar_ventas.php?${params.toString()}`);
        const resultado = await response.json();
        
        if (!resultado.success) {
            throw new Error(resultado.error || 'Error en la respuesta del servidor');
        }
        
        // Asegurarnos que data es un array
        const ventas = Array.isArray(resultado.data) ? resultado.data : [];
        mostrarVentas(ventas);
        
    } catch (error) {
        console.error('Error al buscar ventas:', error);
        mostrarVentas([]); // Mostrar tabla vacía
        AppAviso.alerta('Error al buscar ventas: ' + error.message);
    }
}

function mostrarVentas(ventas) {
    tablaVentas.innerHTML = '';
    
    // Verificar que ventas es un array
    if (!Array.isArray(ventas)) {
        console.error('Los datos de ventas no son un array:', ventas);
        ventas = [];
    }
    
    if (ventas.length === 0) {
        const fila = document.createElement('tr');
        fila.innerHTML = '<td colspan="11" style="text-align: center;">No se encontraron ventas</td>';
        tablaVentas.appendChild(fila);
        return;
    }
    
    ventas.forEach(venta => {
        const fila = document.createElement('tr');
        fila.dataset.id = venta.vid;
        
        fila.innerHTML = `
            <td>${escHTML(venta.vid)}</td>
            <td>${escHTML(venta.cliente || 'N/A')}</td>
            <td>${escHTML(venta.sucursal || 'N/A')}</td>
            <td>${escHTML(venta.equipo || 'N/A')}</td>
            <td>${escHTML(venta.marca || 'N/A')}</td>
            <td>${escHTML(venta.modelo || 'N/A')}</td>
            <td>${venta.numero_series ? escHTML(venta.numero_series.join(', ')) : 'N/A'}</td>
            <td>${escHTML(venta.garantia || '0')} meses</td>
            <td>${venta.servicio ? 'Sí' : 'No'}</td>
            <td>${venta.fecha_registro ? new Date(venta.fecha_registro).toLocaleDateString() : 'N/A'}</td>
            <td>
                <button class="btn-editar" data-id="${escHTML(venta.vid)}">Editar</button>
                <button class="btn-eliminar" data-id="${escHTML(venta.vid)}">Eliminar</button>
            </td>
        `;
        
        tablaVentas.appendChild(fila);
    });

    // Agregar eventos a los botones
    agregarEventosBotones();
}