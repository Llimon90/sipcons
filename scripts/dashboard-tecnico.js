let chartEvolucion = null;

document.addEventListener('DOMContentLoaded', function () {
    inicializarFiltros();
    cargarMiDesempeno();
});

function inicializarFiltros() {
    const rangoFecha = document.getElementById('rangoFecha');
    if (rangoFecha) {
        rangoFecha.addEventListener('change', function () {
            const customDateRange = document.getElementById('customDateRange');
            const customDateRangeEnd = document.getElementById('customDateRangeEnd');
            if (this.value === 'custom') {
                if (customDateRange) customDateRange.style.display = 'flex';
                if (customDateRangeEnd) customDateRangeEnd.style.display = 'flex';
            } else {
                if (customDateRange) customDateRange.style.display = 'none';
                if (customDateRangeEnd) customDateRangeEnd.style.display = 'none';
                cargarMiDesempeno();
            }
        });
    }

    const fechaInicio = document.getElementById('fechaInicio');
    const fechaFin = document.getElementById('fechaFin');
    if (fechaInicio && fechaFin) {
        fechaInicio.addEventListener('change', cargarMiDesempeno);
        fechaFin.addEventListener('change', cargarMiDesempeno);
    }

    const hoy = new Date();
    const hace30Dias = new Date();
    hace30Dias.setDate(hoy.getDate() - 30);
    if (fechaInicio) fechaInicio.value = hace30Dias.toISOString().split('T')[0];
    if (fechaFin) fechaFin.value = hoy.toISOString().split('T')[0];
}

function construirParametros() {
    const params = new URLSearchParams();
    const rangoFecha = document.getElementById('rangoFecha')?.value || '30';
    params.append('rangoFecha', rangoFecha);

    if (rangoFecha === 'custom') {
        const fechaInicio = document.getElementById('fechaInicio')?.value;
        const fechaFin = document.getElementById('fechaFin')?.value;
        if (fechaInicio) params.append('fechaInicio', fechaInicio);
        if (fechaFin) params.append('fechaFin', fechaFin);
    }

    return params;
}

function mostrarLoading(mostrar) {
    const main = document.getElementById('mainContent');
    if (main) main.classList.toggle('is-loading', mostrar);
}

function mostrarError(mensaje) {
    const aviso = document.getElementById('misPendientesAviso');
    if (!aviso) return;
    aviso.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${mensaje}`;
    aviso.style.display = 'flex';
}

function badgeSla(porcentaje) {
    if (porcentaje === null || porcentaje === undefined) {
        return { texto: 'Sin datos suficientes', clase: 'badge-sla--neutral' };
    }
    if (porcentaje >= 80) return { texto: `${porcentaje}% atendidas a tiempo`, clase: 'badge-sla--good' };
    if (porcentaje >= 50) return { texto: `${porcentaje}% atendidas a tiempo`, clase: 'badge-sla--warn' };
    return { texto: `${porcentaje}% atendidas a tiempo`, clase: 'badge-sla--bad' };
}

function actualizarElementoSiExiste(id, valor) {
    const el = document.getElementById(id);
    if (el) el.textContent = valor;
}

function renderInsights(insights) {
    const ul = document.getElementById('misInsights');
    if (!ul) return;
    ul.innerHTML = '';

    if (!insights || insights.length === 0) {
        const li = document.createElement('li');
        li.className = 'insights-empty';
        li.textContent = 'No hay suficientes datos en este periodo para generar lecturas automáticas.';
        ul.appendChild(li);
        return;
    }

    insights.forEach(texto => {
        const li = document.createElement('li');
        const icono = document.createElement('i');
        icono.className = 'fas fa-circle';
        const span = document.createElement('span');
        span.textContent = texto;
        li.appendChild(icono);
        li.appendChild(span);
        ul.appendChild(li);
    });
}

function formatearMes(mesString) {
    if (!mesString) return 'Sin fecha';
    const [year, month] = mesString.split('-');
    const meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    return `${meses[parseInt(month) - 1]} ${year}`;
}

function crearGraficoEvolucion(evolucion) {
    if (chartEvolucion) {
        chartEvolucion.destroy();
        chartEvolucion = null;
    }

    const canvas = document.getElementById('chartMiEvolucion');
    const contenedor = canvas?.closest('.chart-container');
    let vacio = contenedor?.querySelector('.chart-empty-state');

    if (!evolucion || evolucion.length === 0) {
        if (canvas) canvas.style.display = 'none';
        if (contenedor && !vacio) {
            vacio = document.createElement('div');
            vacio.className = 'chart-empty-state';
            vacio.innerHTML = '<i class="fas fa-chart-line"></i><span>Aún no hay incidencias cerradas en los últimos 12 meses</span>';
            contenedor.appendChild(vacio);
        }
        return;
    }

    if (canvas) canvas.style.display = '';
    if (vacio) vacio.remove();

    chartEvolucion = new Chart(canvas, {
        type: 'line',
        data: {
            labels: evolucion.map(item => formatearMes(item.mes)),
            datasets: [{
                label: 'Incidencias cerradas',
                data: evolucion.map(item => item.cantidad),
                backgroundColor: 'rgba(52, 152, 219, 0.12)',
                borderColor: '#3498db',
                borderWidth: 2,
                tension: 0.4,
                fill: true
            }]
        },
        options: { responsive: true, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
}

function construirFilaPendiente(inc) {
    const tr = document.createElement('tr');
    const celda = (texto) => {
        const td = document.createElement('td');
        td.textContent = texto || '-';
        return td;
    };

    tr.appendChild(celda(inc.numero_incidente));
    tr.appendChild(celda(inc.cliente));
    tr.appendChild(celda(inc.sucursal));
    tr.appendChild(celda(inc.estatus));
    tr.appendChild(celda(inc.fecha ? String(inc.fecha).substring(0, 10) : ''));

    const tdAccion = document.createElement('td');
    const enlace = document.createElement('a');
    enlace.href = `detalle.html?id=${encodeURIComponent(inc.id)}`;
    enlace.target = '_blank';
    enlace.rel = 'noopener';
    enlace.className = 'btn btn-primary btn-sm';
    enlace.innerHTML = '<i class="fas fa-up-right-from-square"></i> Abrir';
    tdAccion.appendChild(enlace);
    tr.appendChild(tdAccion);

    return tr;
}

function renderPendientes(pendientes) {
    const tabla = document.getElementById('misPendientesTabla');
    const cuerpo = document.getElementById('misPendientesBody');
    const vacio = document.getElementById('misPendientesVacio');
    if (!tabla || !cuerpo || !vacio) return;

    cuerpo.innerHTML = '';

    if (!pendientes || pendientes.length === 0) {
        tabla.style.display = 'none';
        vacio.style.display = 'flex';
        return;
    }

    tabla.style.display = '';
    vacio.style.display = 'none';
    pendientes.forEach(inc => cuerpo.appendChild(construirFilaPendiente(inc)));
}

async function cargarMiDesempeno() {
    try {
        mostrarLoading(true);
        const aviso = document.getElementById('misPendientesAviso');
        if (aviso) aviso.style.display = 'none';

        const params = construirParametros();
        const resp = await fetch(`../backend/estadisticas_tecnico.php?action=resumen&${params.toString()}`);
        if (!resp.ok) throw new Error('Error en la respuesta del servidor: ' + resp.status);
        const resultado = await resp.json();
        if (!resultado.success) throw new Error(resultado.error || 'No se pudo cargar tu información');

        const data = resultado.data;
        actualizarElementoSiExiste('saludoTecnico', `Hola, ${data.identidad}`);

        const r = data.resumen || {};
        actualizarElementoSiExiste('miAsignadas', r.asignadas ?? 0);
        actualizarElementoSiExiste('miCompletadas', r.completadas ?? 0);
        actualizarElementoSiExiste('miPendientes', r.pendientes_actual ?? 0);
        actualizarElementoSiExiste('miVencidasDetalle', `${r.vencidas ?? 0} con más de 7 días abiertas`);

        const tendencia = r.tendencia_incidencias ?? 0;
        const elTendencia = document.getElementById('miTendencia');
        if (elTendencia) {
            elTendencia.textContent = `${tendencia >= 0 ? '+' : ''}${tendencia}% vs periodo anterior`;
            elTendencia.className = `stat-change ${tendencia >= 0 ? '' : 'negative'}`;
        }

        const respuestaHoras = r.respuesta_mediana_horas;
        actualizarElementoSiExiste('miRespuesta', respuestaHoras !== null && respuestaHoras !== undefined ? `${respuestaHoras.toFixed(1)} h` : 'N/D');
        actualizarElementoSiExiste('miRespuestaMuestras', `Basado en ${r.muestras_respuesta ?? 0} incidencias con historial`);

        const cierreHoras = r.cierre_mediana_horas;
        const cierreDias = cierreHoras !== null && cierreHoras !== undefined ? cierreHoras / 24 : null;
        actualizarElementoSiExiste('miCierre', cierreDias !== null ? `${cierreDias.toFixed(1)} d` : 'N/D');
        actualizarElementoSiExiste('miCierreMuestras', `Basado en ${r.muestras_cierre ?? 0} incidencias con historial`);

        actualizarElementoSiExiste('miSla', r.sla_cierre_pct !== null && r.sla_cierre_pct !== undefined ? `${r.sla_cierre_pct}%` : 'N/D');
        const badge = badgeSla(r.sla_cierre_pct ?? null);
        const elBadge = document.getElementById('miSlaBadge');
        if (elBadge) {
            elBadge.textContent = badge.texto;
            elBadge.className = `badge-sla ${badge.clase}`;
        }

        renderInsights(data.insights);
        crearGraficoEvolucion(data.evolucion_mensual);
        renderPendientes(data.pendientes);
    } catch (error) {
        console.error('Error cargando mi desempeño:', error);
        mostrarError('Error al cargar tu información: ' + error.message);
    } finally {
        mostrarLoading(false);
    }
}

window.cargarMiDesempeno = cargarMiDesempeno;
