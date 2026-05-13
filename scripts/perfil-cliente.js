// scripts/perfil-cliente.js
let equiposPadron = []; 

document.addEventListener("DOMContentLoaded", async () => {
    const params = new URLSearchParams(window.location.search);
    const clientId = params.get('id');

    if (!clientId) {
        alert("No se especificó un cliente.");
        window.location.href = 'clientes.html';
        return;
    }

    // 1. Cargar Datos del Cliente
    try {
        const resp = await fetch(`../backend/detalle-cliente.php?id=${clientId}`);
        const cliente = await resp.json();

        if (cliente.error) {
            alert("Cliente no encontrado.");
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
        document.getElementById('edit-contactos').value = cliente.contactos || '';
        document.getElementById('edit-email').value = cliente.email || '';

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
                alert('Datos del cliente actualizados correctamente.');
                document.getElementById('titulo-nombre-cliente').textContent = document.getElementById('edit-nombre').value;
                document.getElementById('ext-cliente').value = document.getElementById('edit-nombre').value;
            } else {
                alert(`Error: ${result.error}`);
            }
        } catch (error) {
            console.error("Error al actualizar:", error);
        }
    });

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
                alert(idEquipo ? 'Equipo actualizado con éxito.' : 'Equipo registrado con éxito.');
                cerrarModalEquipo();
                cargarEquipos(document.getElementById('edit-nombre').value);
                document.getElementById('buscador-padron').value = '';
            } else {
                alert(`Error: ${result.mensaje}`);
            }
        } catch (error) {
            console.error("Error al guardar equipo:", error);
            alert("Hubo un error de conexión.");
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
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">Consultando inventario...</td></tr>';

    try {
        const resp = await fetch(`../backend/obtener_equipos_cliente.php?cliente=${encodeURIComponent(nombreCliente)}`);
        const data = await resp.json();

        if (data.error) throw new Error(data.error);

        equiposPadron = data; 
        renderizarTablaEquipos(equiposPadron);

    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#e74c3c;">Error al cargar el padrón de equipos.</td></tr>';
    }
}

function renderizarTablaEquipos(equipos) {
    const tbody = document.getElementById('tabla-padron-cliente');
    tbody.innerHTML = '';

    if (!equipos || equipos.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#7f8c8d; padding:20px;">No se encontraron equipos para este cliente.</td></tr>';
        return;
    }

    equipos.forEach(eq => {
        // 1. Textos de Periodicidad
        let periodos = [];
        if (eq.calibracion > 0) periodos.push(`Cal: ${eq.calibracion}m`);
        if (eq.servicio > 0 && eq.frecuencia_servicio > 0) periodos.push(`Serv: ${eq.frecuencia_servicio}m`);
        let txtPeriodo = periodos.length > 0 ? periodos.join(' | ') : 'Sin programa';

        // 2. Badge de Origen
        let badgeOrigen = eq.origen === 'Venta SIPCONS' 
            ? `<span style="background:#e8f4f8; color:#2980b9; padding:3px 6px; border-radius:4px; font-size:0.8rem; font-weight:bold;">Venta #${eq.venta_id}</span>`
            : `<span style="background:#fef5e7; color:#d35400; padding:3px 6px; border-radius:4px; font-size:0.8rem; font-weight:bold;">Externo</span>`;

        // 3. CALCULADORA DE GARANTÍA
        let badgeGarantia = `<span style="background:#bdc3c7; color:white; padding:4px 8px; border-radius:4px; font-size:0.75rem; white-space:nowrap;"><i class="fas fa-shield-alt"></i> Sin Garantía</span>`;
        
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
                
                badgeGarantia = `<span style="background:#27ae60; color:white; padding:4px 8px; border-radius:4px; font-size:0.75rem; white-space:nowrap;" title="Quedan ${tiempoRestante}"><i class="fas fa-check-circle"></i> Activa hasta: ${fechaVencimientoTexto}</span>`;
            } else {
                badgeGarantia = `<span style="background:#e74c3c; color:white; padding:4px 8px; border-radius:4px; font-size:0.75rem; white-space:nowrap;"><i class="fas fa-times-circle"></i> Venció: ${fechaVencimientoTexto}</span>`;
            }
        }

        // 4. Dibujar la Fila
        const tr = document.createElement('tr');
        tr.style.cursor = 'pointer';
        tr.style.borderBottom = '1px solid #eee';
        tr.addEventListener('mouseenter', () => tr.style.backgroundColor = '#f1f5f9');
        tr.addEventListener('mouseleave', () => tr.style.backgroundColor = 'transparent');
        
        tr.onclick = () => abrirModalEdicionEquipo(eq);

        tr.innerHTML = `
            <td><strong>${eq.marca || ''} ${eq.modelo || ''}</strong><br><small style="color:#7f8c8d;">${eq.equipo}</small></td>
            <td>${eq.numero_serie || 'S/N'}</td>
            <td>${eq.sucursal || '-'}</td>
            <td>${badgeOrigen}</td>
            <td style="font-size:0.85rem; font-weight:bold; color:#2c3e50;">${txtPeriodo}</td>
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
    
    document.getElementById('ext-calibracion').value = eq.calibracion || '';
    document.getElementById('ext-servicio').checked = eq.servicio == 1;
    document.getElementById('ext-frecuencia').value = eq.frecuencia_servicio || '';
    document.getElementById('ext-garantia').value = eq.garantia || '';

    document.getElementById('titulo-modal-equipo').innerHTML = `<i class="fas fa-edit"></i> Editar Equipo: ${eq.equipo}`;
    document.getElementById('btn-eliminar-equipo').style.display = 'inline-block';
    
    document.getElementById('modalEquipoExterno').style.display = 'flex';
};

window.cerrarModalEquipo = function() {
    document.getElementById('modalEquipoExterno').style.display = 'none';
};

window.eliminarEquipoPadron = async function() {
    const idEquipo = document.getElementById('ext-id').value;
    if (!idEquipo) return;

    if(confirm("¿Estás seguro de que deseas eliminar este equipo del padrón? Perderá su programación automática.")) {
        try {
            const resp = await fetch(`../backend/elimina_equipo_padron.php?id=${idEquipo}`, { method: 'DELETE' });
            const result = await resp.json();
            
            if (result.exito) {
                alert("Equipo eliminado del padrón.");
                cerrarModalEquipo();
                cargarEquipos(document.getElementById('edit-nombre').value);
            } else {
                alert(`Error: ${result.mensaje}`);
            }
        } catch (e) {
            console.error(e);
            alert("Error al intentar eliminar el equipo.");
        }
    }
};