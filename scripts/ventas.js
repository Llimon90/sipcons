/**
 * scripts/ventas.js
 * Sistema de Control de Incidencias - Módulo de Ventas
 */

document.addEventListener('DOMContentLoaded', () => {
    const formVenta = document.getElementById('form-venta');
    const qtyInput = document.getElementById('qty');
    const seriesContainer = document.getElementById('series-container');
    const btnRegistrar = document.getElementById('btn-registrar-venta');
    const cuerpoTabla = document.getElementById('cuerpo-tabla-ventas');
    const clienteSelect = document.getElementById('cliente');
    const mensajeDiv = document.getElementById('mensaje');

    // --- 1. GENERACIÓN DINÁMICA DE CAMPOS DE SERIE ---
    const actualizarCamposSerie = () => {
        const cantidad = parseInt(qtyInput.value) || 0;
        seriesContainer.innerHTML = ''; // Limpiar anteriores

        if (cantidad > 0) {
            const titulo = document.createElement('h3');
            titulo.innerHTML = `<i class="fas fa-barcode"></i> Números de Serie (${cantidad})`;
            seriesContainer.appendChild(titulo);

            const grid = document.createElement('div');
            grid.className = 'series-grid'; // Asegúrate de añadir este estilo a tu CSS

            for (let i = 1; i <= cantidad; i++) {
                const div = document.createElement('div');
                div.className = 'filtro-item';
                div.innerHTML = `
                    <label>Serie ${i}:</label>
                    <input type="text" name="serie[]" class="serie-input" placeholder="Ingrese serie..." required>
                `;
                grid.appendChild(div);
            }
            seriesContainer.appendChild(grid);
        }
    };

    qtyInput.addEventListener('input', actualizarCamposSerie);
    // Ejecutar al inicio por si hay un valor por defecto
    actualizarCamposSerie();

   
    // --- 3. CARGAR VENTAS (Para la tabla) ---
    const cargarVentas = async () => {
        try {
            const resp = await fetch('../backend/obtener-ventas.php');
            const data = await resp.json();
            renderizarTabla(data.ventas);
        } catch (error) {
            console.error("Error cargando ventas:", error);
        }
    };

    const renderizarTabla = (ventas) => {
        if (!ventas || ventas.length === 0) {
            cuerpoTabla.innerHTML = '<tr><td colspan="9" class="no-data">No hay ventas registradas</td></tr>';
            return;
        }

        cuerpoTabla.innerHTML = ventas.map(v => `
            <tr>
                <td>${v.fecha_registro}</td>
                <td><strong>${v.cliente}</strong></td>
                <td>${v.sucursal || '-'}</td>
                <td>${v.equipo}</td>
                <td>${v.marca}</td>
                <td>${v.modelo}</td>
                <td>${v.numero_serie}</td>
                <td>${v.garantia} meses</td>
                <td>
                    <div class="acciones-tabla">
                        <button onclick="editarVenta(${v.id})" class="btn-edit" title="Editar"><i class="fas fa-edit"></i></button>
                        <button onclick="borrarVenta(${v.id})" class="btn-delete" title="Borrar"><i class="fas fa-trash"></i></button>
                    </div>
                </td>
            </tr>
        `).join('');
        
        document.getElementById('total-registros').textContent = ventas.length;
        document.getElementById('registros-mostrados').textContent = ventas.length;
    };

    // --- 4. REGISTRAR VENTA (Envío al servidor) ---
    btnRegistrar.addEventListener('click', async () => {
        // Validación básica
        if (!formVenta.checkValidity()) {
            formVenta.reportValidity();
            return;
        }

        const seriesInputs = document.querySelectorAll('.serie-input');
        const series = Array.from(seriesInputs).map(input => input.value.trim());

        const payload = {
            folio: "", // Puedes agregar un campo folio en el HTML si lo necesitas
            cliente: clienteSelect.value,
            sucursal: document.getElementById('sucursal').value,
            equipo: document.getElementById('equipo').value,
            marca: document.getElementById('marca').value,
            modelo: document.getElementById('modelo').value,
            garantia: document.getElementById('garantia').value,
            servicio: document.getElementById('servicio').checked,
            notas: document.getElementById('notas').value,
            numero_series: series
        };

        try {
            btnRegistrar.disabled = true;
            btnRegistrar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';

            const resp = await fetch('../backend/registro_ventas.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const resultado = await resp.json();

            if (resultado.exito) {
                mostrarMensaje('¡Venta registrada con éxito!', 'success');
                formVenta.reset();
                actualizarCamposSerie();
                cargarVentas();
            } else {
                mostrarMensaje('Error: ' + resultado.mensaje, 'error');
            }
        } catch (error) {
            mostrarMensaje('Error de conexión con el servidor', 'error');
        } finally {
            btnRegistrar.disabled = false;
            btnRegistrar.innerHTML = '<i class="fas fa-save"></i> Registrar Venta';
        }
    });

    const mostrarMensaje = (texto, tipo) => {
        mensajeDiv.textContent = texto;
        mensajeDiv.className = `mensaje ${tipo}`;
        setTimeout(() => { mensajeDiv.className = 'mensaje'; }, 5000);
    };

    // Inicializar datos
   
    cargarVentas();
});

// Funciones globales para acciones (Placeholder para tu lógica de edición/borrado)
window.editarVenta = (id) => console.log("Editando:", id);
window.borrarVenta = (id) => {
    if(confirm('¿Seguro que deseas eliminar este registro?')) {
        console.log("Borrando:", id);
    }
};