// scripts/perfil-cliente.js
let equiposPadron = []; 
let refrescarPeriodicidadExt = () => {};

document.addEventListener("DOMContentLoaded", async () => {
    const editorContactos = AppContactos.init(
        document.getElementById('edit-contactos-editor'),
        document.getElementById('edit-contactos')
    );
    const params = new URLSearchParams(window.location.search);
    const clientId = params.get('id');

    if (!clientId) {
        AppAviso.alerta("No se especificó un cliente.");
        window.location.href = 'clientes.html';
        return;
    }

    // 1. Cargar Datos del Cliente
    try {
        const resp = await fetch(`../backend/detalle-cliente.php?id=${clientId}`);
        const cliente = await resp.json();

        if (cliente.error) {
            AppAviso.alerta("Cliente no encontrado.");
            window.location.href = 'clientes.html';
            return;
        }

        document.getElementById('titulo-nombre-cliente').textContent = cliente.nombre;
        document.getElementById('ext-cliente').value = cliente.nombre;
        
        document.getElementById('edit-id').value = cliente.id;
        document.getElementById('edit-nombre').value = cliente.nombre;
        document.getElementById('edit-rfc').value = cliente.rfc || '';
        document.getElementById('edit-direccion').value = cliente.direccion || '';
        document.getElementById('edit-telefono').value = cliente.telefono || '';
        // Si el cliente aún no tiene filas en cliente_contactos, se muestran
        // los nombres del texto viejo y al guardar se crean las filas.
        editorContactos.set(
            (cliente.contactos_detalle && cliente.contactos_detalle.length)
                ? cliente.contactos_detalle
                : cliente.contactos
        );
        document.getElementById('edit-email').value = cliente.email || '';
        // D-52: días y límite de crédito, visibles con el permiso de clientes (el saldo sigue en Finanzas, D-48)
        const infoCredito = document.getElementById('info-credito');
        if (infoCredito) {
            const dias = parseInt(cliente.dias_credito, 10) || 0;
            const limite = cliente.limite_credito != null && cliente.limite_credito !== '' ? Number(cliente.limite_credito) : null;
            infoCredito.textContent = (dias ? dias + ' días' : 'De contado') + ' · ' +
                (limite !== null ? 'límite ' + limite.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) : 'sin límite');
        }

        cargarEquipos(cliente.nombre);

    } catch (error) {
        console.error("Error cargando perfil:", error);
    }

    // 2. Guardar Cambios del Cliente
    document.getElementById('form-editar-cliente').addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        try {
            const response = await fetch('../backend/actualiza-cliente.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (result.success) {
                // L-4: al renombrar, sus órdenes, equipos y ventas siguen al cliente
                const arr = result.actualizados || {};
                const partes = Object.keys(arr).map(k => arr[k] + ' ' + k);
                AppAviso.alerta('Datos del cliente actualizados correctamente.' + (partes.length ? ' También se actualizaron ' + partes.join(', ') + ' con el nombre nuevo.' : '')
                    + (result.aviso ? ' ' + result.aviso : ''));
                document.getElementById('titulo-nombre-cliente').textContent = document.getElementById('edit-nombre').value;
                document.getElementById('ext-cliente').value = document.getElementById('edit-nombre').value;
            } else {
                AppAviso.alerta(result.error || result.message || 'No se pudo actualizar el cliente.');
            }
        } catch (error) {
            console.error("Error al actualizar:", error);
            AppAviso.alerta('No se pudo actualizar el cliente. Revisa tu conexión e inténtalo de nuevo.');
        }
    });

    // Periodicidad de mantenimiento + check de póliza
    refrescarPeriodicidadExt = AppPeriodicidad.enlazar(
        document.getElementById('ext-calibracion'),
        document.getElementById('ext-servicio'),
        document.getElementById('ext-aviso-periodicidad')
    );

    // 3. Guardar / Actualizar Equipo (Ruta Inteligente)
    document.getElementById('form-equipo-externo').addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        
        const idEquipo = document.getElementById('ext-id').value;
        const endpoint = idEquipo ? '../backend/actualiza_equipo_padron.php' : '../backend/registro_externo.php';

        try {
            const response = await fetch(endpoint, { method: 'POST', body: formData });
            const result = await response.json();

            if (result.exito) {
                AppAviso.alerta(idEquipo ? 'Equipo actualizado con éxito.' : 'Equipo registrado con éxito.');
                cerrarModalEquipo();
                cargarEquipos(document.getElementById('edit-nombre').value);
                document.getElementById('buscador-padron').value = '';
            } else {
                AppAviso.alerta(result.mensaje || 'No se pudo eliminar el equipo.');
            }
        } catch (error) {
            console.error("Error al guardar equipo:", error);
            AppAviso.alerta("Hubo un error de conexión.");
        }
    });

    // 4. Buscador en tiempo real
    const buscador = document.getElementById('buscador-padron');
    if(buscador) {
        buscador.addEventListener('input', (e) => {
            const texto = e.target.value.toLowerCase();
            const equiposFiltrados = equiposPadron.filter(eq => {
                return (
                    (eq.equipo && eq.equipo.toLowerCase().includes(texto)) ||
                    (eq.marca && eq.marca.toLowerCase().includes(texto)) ||
                    (eq.modelo && eq.modelo.toLowerCase().includes(texto)) ||
                    (eq.numero_serie && eq.numero_serie.toLowerCase().includes(texto)) ||
                    (eq.sucursal && eq.sucursal.toLowerCase().includes(texto))
                );
            });
            renderizarTablaEquipos(equiposFiltrados);
        });
    }
});

async function cargarEquipos(nombreCliente) {
    const tbody = document.getElementById('tabla-padron-cliente');
    tbody.innerHTML = '<tr><td colspan="6">Consultando inventario...</td></tr>';

    try {
        const resp = await fetch(`../backend/obtener_equipos_cliente.php?cliente=${encodeURIComponent(nombreCliente)}`);
        const data = await resp.json();

        if (data.error) throw new Error(data.error);

        equiposPadron = data; 
        renderizarTablaEquipos(equiposPadron);

    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="6" class="celda-error">Error al cargar el padrón de equipos.</td></tr>';
    }
}

function renderizarTablaEquipos(equipos) {
    const tbody = document.getElementById('tabla-padron-cliente');
    tbody.innerHTML = '';

    if (!equipos || equipos.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6">No se encontraron equipos para este cliente.</td></tr>';
        return;
    }

    equipos.forEach(eq => {
        // 1. Textos de Periodicidad
        let txtPeriodo = AppPeriodicidad.etiqueta(eq);

        // 2. Badge de Origen
        let badgeOrigen = eq.origen === 'Venta propia'
            ? `<span class="etiqueta etiqueta-info">Venta #${escHTML(eq.venta_folio || eq.venta_id)}</span>`
            : `<span class="etiqueta etiqueta-alerta">Externo</span>`;

        // 3. CALCULADORA DE GARANTÍA
        let badgeGarantia = `<span class="etiqueta etiqueta-neutral"><i class="fas fa-shield-alt"></i> Sin Garantía</span>`;
        
        if (eq.garantia > 0 && eq.fecha_registro) {
            const fRegistro = new Date(eq.fecha_registro + 'T12:00:00'); 
            const fFinGarantia = new Date(fRegistro.getTime());
            fFinGarantia.setMonth(fFinGarantia.getMonth() + parseInt(eq.garantia));
            
            const hoy = new Date();
            const fechaVencimientoTexto = fFinGarantia.toISOString().split('T')[0];

            if (fFinGarantia > hoy) {
                const diffTime = fFinGarantia - hoy;
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                let tiempoRestante = diffDays > 30 ? Math.floor(diffDays / 30) + " meses" : diffDays + " días";
                
                badgeGarantia = `<span class="etiqueta etiqueta-ok" title="Quedan ${tiempoRestante}"><i class="fas fa-check-circle"></i> Activa hasta: ${fechaVencimientoTexto}</span>`;
            } else {
                badgeGarantia = `<span class="etiqueta etiqueta-error"><i class="fas fa-times-circle"></i> Venció: ${fechaVencimientoTexto}</span>`;
            }
        }

        // 4. Dibujar la Fila
        const tr = document.createElement('tr');
        tr.className = 'fila-clic';
        
        tr.onclick = () => abrirModalEdicionEquipo(eq);

        tr.innerHTML = `
            <td><strong>${escHTML(eq.marca || '')} ${escHTML(eq.modelo || '')}</strong><br><small class="texto-suave">${escHTML(eq.equipo)}</small></td>
            <td>${escHTML(eq.numero_serie || 'S/N')}</td>
            <td>${escHTML(eq.sucursal || '-')}</td>
            <td>${badgeOrigen}</td>
            <td><strong>${escHTML(txtPeriodo)}</strong></td>
            <td>${badgeGarantia}</td>
        `;
        tbody.appendChild(tr);
    });
}

// CONTROL DE MODALES
window.abrirModalEquipo = function() {
    document.getElementById('form-equipo-externo').reset();
    document.getElementById('ext-id').value = ''; 
    document.getElementById('ext-fecha').valueAsDate = new Date();
    document.getElementById('ext-cliente').value = document.getElementById('edit-nombre').value;
    
    document.getElementById('titulo-modal-equipo').innerHTML = '<i class="fas fa-plus-circle"></i> Registrar Nuevo Equipo';
    document.getElementById('btn-eliminar-equipo').style.display = 'none'; 
    refrescarPeriodicidadExt();
    
    document.getElementById('modalEquipoExterno').style.display = 'flex';
};

window.abrirModalEdicionEquipo = function(eq) {
    document.getElementById('form-equipo-externo').reset();
    
    document.getElementById('ext-id').value = eq.id;
    document.getElementById('ext-sucursal').value = eq.sucursal || '';
    if(eq.fecha_registro) document.getElementById('ext-fecha').value = eq.fecha_registro;
    document.getElementById('ext-equipo').value = eq.equipo || '';
    document.getElementById('ext-marca').value = eq.marca || '';
    document.getElementById('ext-modelo').value = eq.modelo || '';
    document.getElementById('ext-serie').value = eq.numero_serie || '';
    
    document.getElementById('ext-calibracion').value = AppPeriodicidad.mesesDe(eq) || '';
    document.getElementById('ext-servicio').checked = eq.servicio == 1;
    refrescarPeriodicidadExt();
    document.getElementById('ext-garantia').value = eq.garantia || '';

    document.getElementById('titulo-modal-equipo').innerHTML = `<i class="fas fa-edit"></i> Editar Equipo: ${escHTML(eq.equipo)}`;
    document.getElementById('btn-eliminar-equipo').style.display = 'inline-block';
    
    document.getElementById('modalEquipoExterno').style.display = 'flex';
};

window.cerrarModalEquipo = function() {
    document.getElementById('modalEquipoExterno').style.display = 'none';
};

window.eliminarEquipoPadron = async function() {
    const idEquipo = document.getElementById('ext-id').value;
    if (!idEquipo) return;

    if (await AppAviso.confirmar('Perderá su programación automática de mantenimientos.', { peligro: true, boton: 'Sí, eliminar', titulo: '¿Eliminar este equipo del padrón?' })) {
        try {
            const resp = await fetch(`../backend/elimina_equipo_padron.php?id=${idEquipo}`, { method: 'DELETE' });
            const result = await resp.json();
            
            if (result.exito) {
                AppAviso.alerta("Equipo eliminado del padrón.");
                cerrarModalEquipo();
                cargarEquipos(document.getElementById('edit-nombre').value);
            } else {
                AppAviso.alerta(result.mensaje || 'No se pudo eliminar el equipo.');
            }
        } catch (e) {
            console.error(e);
            AppAviso.alerta("Error al intentar eliminar el equipo.");
        }
    }
};