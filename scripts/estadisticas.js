// Variables globales para los charts
let charts = {};
let currentTab = 'direccion';
let filtrosPoblados = false;

// Paleta de gráficos alineada con la identidad visual del panel (css informes.html)
const COLOR_ACCENT = '#3498db';
const COLOR_ACCENT_DARK = '#2c3e50';
const PALETA_GRAFICOS = [
    '#3498db', '#2c3e50', '#16a34a', '#d97706', '#8e44ad',
    '#0891b2', '#dc2626', '#64748b', '#0d9488', '#c026d3',
    '#ea580c', '#2563eb', '#65a30d', '#e11d48', '#0369a1'
];

// Umbrales de SLA (deben coincidir con backend/estadisticas.php)
const SLA_RESPUESTA_HORAS = 4;
const SLA_CIERRE_DIAS = 7;

document.addEventListener('DOMContentLoaded', function() {
    inicializarInterfaz();
    inicializarModalDrilldown();
    poblarFiltros();
    cargarEstadisticas();
});

function inicializarInterfaz() {
    const tabs = document.querySelectorAll('.tab');
    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            const tabId = this.getAttribute('data-tab');
            cambiarPestaña(tabId);
        });
    });

    const rangoFecha = document.getElementById('rangoFecha');
    if (rangoFecha) {
        rangoFecha.addEventListener('change', function() {
            const customDateRange = document.getElementById('customDateRange');
            const customDateRangeEnd = document.getElementById('customDateRangeEnd');

            if (this.value === 'custom') {
                if (customDateRange) customDateRange.style.display = 'flex';
                if (customDateRangeEnd) customDateRangeEnd.style.display = 'flex';
            } else {
                if (customDateRange) customDateRange.style.display = 'none';
                if (customDateRangeEnd) customDateRangeEnd.style.display = 'none';
                aplicarFiltros();
            }
        });
    }

    const fechaInicio = document.getElementById('fechaInicio');
    const fechaFin = document.getElementById('fechaFin');
    if (fechaInicio && fechaFin) {
        fechaInicio.addEventListener('change', aplicarFiltros);
        fechaFin.addEventListener('change', aplicarFiltros);
    }

    const tecnico = document.getElementById('tecnico');
    const sucursal = document.getElementById('sucursal');
    const estatus = document.getElementById('estatus');

    if (tecnico) tecnico.addEventListener('change', aplicarFiltros);
    if (sucursal) sucursal.addEventListener('change', aplicarFiltros);
    if (estatus) estatus.addEventListener('change', aplicarFiltros);

    const hoy = new Date();
    const hace30Dias = new Date();
    hace30Dias.setDate(hoy.getDate() - 30);

    if (fechaInicio) fechaInicio.value = hace30Dias.toISOString().split('T')[0];
    if (fechaFin) fechaFin.value = hoy.toISOString().split('T')[0];
}

async function poblarFiltros() {
    if (filtrosPoblados) return;
    try {
        const response = await fetch('../backend/estadisticas.php?action=filtros_opciones');
        if (!response.ok) return;
        const resultado = await response.json();
        if (!resultado.success) return;

        const selectTecnico = document.getElementById('tecnico');
        const selectSucursal = document.getElementById('sucursal');

        if (selectTecnico && Array.isArray(resultado.data.tecnicos)) {
            resultado.data.tecnicos.forEach(nombre => {
                const opt = document.createElement('option');
                opt.value = nombre;
                opt.textContent = nombre;
                selectTecnico.appendChild(opt);
            });
        }

        if (selectSucursal && Array.isArray(resultado.data.sucursales)) {
            resultado.data.sucursales.forEach(nombre => {
                const opt = document.createElement('option');
                opt.value = nombre;
                opt.textContent = nombre;
                selectSucursal.appendChild(opt);
            });
        }

        filtrosPoblados = true;
    } catch (error) {
        console.error('Error cargando opciones de filtro:', error);
    }
}

function cambiarPestaña(tabId) {
    document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

    const tabElement = document.querySelector(`[data-tab="${tabId}"]`);
    const tabContent = document.getElementById(`${tabId}-tab`);

    if (tabElement) tabElement.classList.add('active');
    if (tabContent) tabContent.classList.add('active');

    currentTab = tabId;
    cargarEstadisticas();
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

    const tecnico = document.getElementById('tecnico')?.value;
    const sucursal = document.getElementById('sucursal')?.value;
    const estatus = document.getElementById('estatus')?.value;

    if (tecnico) params.append('tecnico', tecnico);
    if (sucursal) params.append('sucursal', sucursal);
    if (estatus) params.append('estatus', estatus);

    return params;
}

async function cargarEstadisticas() {
    try {
        mostrarLoading(true);
        const params = construirParametros();

        const urlGeneral = `../backend/estadisticas.php?action=estadisticas_generales&${params.toString()}`;
        const responseGeneral = await fetch(urlGeneral);
        if (!responseGeneral.ok) throw new Error('Error en la respuesta del servidor: ' + responseGeneral.status);
        const dataGeneral = await responseGeneral.json();
        if (!dataGeneral.success) throw new Error(dataGeneral.error || 'Error en los datos generales');
        actualizarEstadisticasGenerales(dataGeneral.data);

        const urlIncidencias = `../backend/estadisticas.php?action=estadisticas_incidencias&${params.toString()}`;
        const responseIncidencias = await fetch(urlIncidencias);
        if (!responseIncidencias.ok) throw new Error('Error en la respuesta del servidor: ' + responseIncidencias.status);
        const dataIncidencias = await responseIncidencias.json();
        if (!dataIncidencias.success) throw new Error(dataIncidencias.error || 'Error en los datos de incidencias');
        crearGraficos(dataIncidencias.data);

        if (currentTab === 'tecnicos') {
            const urlTecnicos = `../backend/estadisticas.php?action=estadisticas_tecnicos&${params.toString()}`;
            const responseTecnicos = await fetch(urlTecnicos);
            if (responseTecnicos.ok) {
                const dataTecnicos = await responseTecnicos.json();
                if (dataTecnicos.success) actualizarEstadisticasTecnicos(dataTecnicos.data);
            }
        }

        if (currentTab === 'direccion') {
            const urlDireccion = `../backend/estadisticas.php?action=estadisticas_direccion&${params.toString()}`;
            const responseDireccion = await fetch(urlDireccion);
            if (responseDireccion.ok) {
                const dataDireccion = await responseDireccion.json();
                if (dataDireccion.success) actualizarVistaDireccion(dataDireccion.data);
            }
        }
    } catch (error) {
        console.error('Error cargando estadísticas:', error);
        mostrarError('Error al cargar las estadísticas: ' + error.message);
    } finally {
        mostrarLoading(false);
    }
}

function badgeSla(porcentaje) {
    if (porcentaje === null || porcentaje === undefined) {
        return { texto: 'Sin datos suficientes', clase: 'badge-sla--neutral' };
    }
    if (porcentaje >= 80) return { texto: `${porcentaje}% dentro de SLA`, clase: 'badge-sla--good' };
    if (porcentaje >= 50) return { texto: `${porcentaje}% dentro de SLA`, clase: 'badge-sla--warn' };
    return { texto: `${porcentaje}% dentro de SLA`, clase: 'badge-sla--bad' };
}

function aplicarBadge(elementoId, badge) {
    const el = document.getElementById(elementoId);
    if (!el) return;
    el.textContent = badge.texto;
    el.className = `badge-sla ${badge.clase}`;
}

function actualizarEstadisticasGenerales(data) {
    actualizarElementoSiExiste('totalIncidencias', data.total_incidencias ?? 0);
    actualizarElementoSiExiste('totalClientes', data.total_clientes ?? 0);
    actualizarElementoSiExiste('resueltasMes', data.incidencias_resueltas ?? 0);
    actualizarElementoSiExiste('eficienciaMensual', `${data.eficiencia_total ?? 0}% de eficiencia en el periodo`);

    actualizarElementoSiExiste('incidenciasAbiertas', data.incidencias_abiertas ?? 0);
    actualizarElementoSiExiste('incidenciasPendientes', data.incidencias_pendientes ?? 0);
    actualizarElementoSiExiste('incidenciasAsignadas', data.incidencias_asignadas ?? 0);
    actualizarElementoSiExiste('incidenciasCompletadas', data.incidencias_completadas ?? 0);
    actualizarElementoSiExiste('incidenciasFacturadas', data.incidencias_cerradas_factura ?? 0);

    const tiempos = data.tiempos || {};

    // Tiempo de respuesta (mediana, horas)
    const respuestaHoras = tiempos.respuesta_mediana_horas;
    actualizarElementoSiExiste('tiempoRespuesta', respuestaHoras !== null && respuestaHoras !== undefined ? `${respuestaHoras.toFixed(1)} h` : 'N/D');
    actualizarElementoSiExiste('tiempoRespuestaMuestras', `Basado en ${tiempos.muestras_respuesta ?? 0} de ${tiempos.total_incidencias ?? 0} incidencias con historial`);
    aplicarBadge('tiempoRespuestaBadge', badgeSla(tiempos.sla_respuesta_pct ?? null));

    // Tiempo de cierre (mediana, días)
    const cierreHoras = tiempos.cierre_mediana_horas;
    const cierreDias = cierreHoras !== null && cierreHoras !== undefined ? cierreHoras / 24 : null;
    actualizarElementoSiExiste('tiempoPromedio', cierreDias !== null ? `${cierreDias.toFixed(1)} d` : 'N/D');
    actualizarElementoSiExiste('tiempoPromedioMuestras', `Basado en ${tiempos.muestras_cierre ?? 0} de ${tiempos.total_incidencias ?? 0} incidencias con historial`);
    aplicarBadge('tiempoPromedioBadge', badgeSla(tiempos.sla_cierre_pct ?? null));

    // SLA de cierre (tarjeta dedicada)
    const slaPct = tiempos.sla_cierre_pct;
    actualizarElementoSiExiste('slaCierre', slaPct !== null && slaPct !== undefined ? `${slaPct}%` : 'N/D');
    actualizarElementoSiExiste('slaCierreDetalle', `Meta: cierre en ${SLA_CIERRE_DIAS} días o menos`);

    actualizarElementoSiExiste('incidenciasReabiertas', tiempos.reabiertas ?? 0);

    const tendencia = data.tendencia_incidencias ?? 0;
    const elemento = document.getElementById('tendenciaIncidencias');
    if (elemento) {
        elemento.textContent = `${tendencia >= 0 ? '+' : ''}${tendencia}% vs periodo anterior`;
        elemento.className = `stat-change ${tendencia >= 0 ? '' : 'negative'}`;
    }

    actualizarElementoSiExiste('lastUpdated', `Actualizado: ${data.last_updated || new Date().toLocaleTimeString()}`);
}

function actualizarEstadisticasTecnicos(data) {
    actualizarElementoSiExiste('tecnicoEficiente', data.tecnico_eficiente || 'N/A');
    actualizarElementoSiExiste('tecnicoMes', data.tecnico_mas_completadas || 'N/A');
    actualizarElementoSiExiste('tecnicoRapido', data.tecnico_mas_rapido || 'N/A');
    actualizarElementoSiExiste('totalTecnicos', data.total_tecnicos ?? 0);

    crearGraficosTecnicos(data);
}

function colorSla(pct) {
    if (pct >= 80) return '#16a34a';
    if (pct >= 50) return '#d97706';
    return '#dc2626';
}

function renderInsights(insights) {
    const ul = document.getElementById('direccionInsights');
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

function crearGraficoSlaSucursal(items) {
    destruirChart('slaSucursal');
    mostrarVacio('chartSlaSucursal', !items || items.length === 0);
    if (!items || items.length === 0) return;

    charts.slaSucursal = new Chart(document.getElementById('chartSlaSucursal'), {
        type: 'bar',
        data: {
            labels: items.map(s => s.sucursal),
            datasets: [{
                label: '% dentro de SLA',
                data: items.map(s => s.sla_pct),
                backgroundColor: items.map(s => colorSla(s.sla_pct)),
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const item = items[ctx.dataIndex];
                            return `${item.sla_pct}% dentro de SLA · ${item.muestras} incidencias cerradas · mediana ${item.cierre_mediana_dias} días`;
                        }
                    }
                }
            },
            scales: { x: { beginAtZero: true, max: 100 } }
        }
    });
}

function actualizarVistaDireccion(data) {
    const resumen = data.resumen || {};

    actualizarElementoSiExiste('direccionTotal', resumen.total_incidencias ?? 0);

    const tendencia = resumen.tendencia_incidencias ?? 0;
    const elTendencia = document.getElementById('direccionTendencia');
    if (elTendencia) {
        elTendencia.textContent = `${tendencia >= 0 ? '+' : ''}${tendencia}% vs periodo anterior`;
        elTendencia.className = `stat-change ${tendencia >= 0 ? '' : 'negative'}`;
    }

    const slaPct = resumen.sla_cierre_pct;
    actualizarElementoSiExiste('direccionSla', slaPct !== null && slaPct !== undefined ? `${slaPct}%` : 'N/D');

    const cierreDias = resumen.cierre_mediana_dias;
    actualizarElementoSiExiste('direccionCierre', cierreDias !== null && cierreDias !== undefined ? `${cierreDias} d` : 'N/D');

    actualizarElementoSiExiste('direccionFacturadas', resumen.incidencias_cerradas_factura ?? 0);
    actualizarElementoSiExiste('direccionReabiertas', resumen.reabiertas ?? 0);

    renderInsights(data.insights);
    crearGraficoSlaSucursal(data.sla_por_sucursal);
}

function destruirChart(id) {
    if (charts[id] && typeof charts[id].destroy === 'function') {
        charts[id].destroy();
        delete charts[id];
    }
}

function mostrarVacio(canvasId, mostrar) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const contenedor = canvas.closest('.chart-container');
    if (!contenedor) return;
    let vacio = contenedor.querySelector('.chart-empty-state');
    if (mostrar) {
        canvas.style.display = 'none';
        if (!vacio) {
            vacio = document.createElement('div');
            vacio.className = 'chart-empty-state';
            vacio.innerHTML = '<i class="fas fa-chart-bar"></i><span>Sin datos suficientes para este periodo</span>';
            contenedor.appendChild(vacio);
        }
    } else {
        canvas.style.display = '';
        if (vacio) vacio.remove();
    }
}

function crearGraficos(data) {
    Object.keys(charts).forEach(destruirChart);

    // Estatus
    mostrarVacio('chartEstatus', !data.por_estatus || data.por_estatus.length === 0);
    if (data.por_estatus && data.por_estatus.length > 0) {
        charts.estatus = new Chart(document.getElementById('chartEstatus'), {
            type: 'doughnut',
            data: {
                labels: data.por_estatus.map(item => item.estatus || 'Sin estatus'),
                datasets: [{
                    data: data.por_estatus.map(item => item.cantidad),
                    backgroundColor: PALETA_GRAFICOS,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                onClick: (evt, elements) => {
                    if (!elements.length) return;
                    const item = data.por_estatus[elements[0].index];
                    if (!item || !item.estatus) return;
                    abrirModalPorEstatus(item.estatus);
                },
                onHover: (evt, elements) => {
                    evt.native.target.style.cursor = elements.length ? 'pointer' : 'default';
                },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    // Por técnico
    mostrarVacio('chartTecnico', !data.por_tecnico || data.por_tecnico.length === 0);
    if (data.por_tecnico && data.por_tecnico.length > 0) {
        charts.tecnico = new Chart(document.getElementById('chartTecnico'), {
            type: 'bar',
            data: {
                labels: data.por_tecnico.map(item => item.tecnico || 'Sin técnico'),
                datasets: [{
                    label: 'Incidencias Asignadas',
                    data: data.por_tecnico.map(item => item.cantidad),
                    backgroundColor: COLOR_ACCENT,
                    borderColor: COLOR_ACCENT_DARK,
                    borderWidth: 1
                }]
            },
            options: { responsive: true, scales: { y: { beginAtZero: true } } }
        });
    }

    // Por sucursal
    mostrarVacio('chartSucursal', !data.por_sucursal || data.por_sucursal.length === 0);
    if (data.por_sucursal && data.por_sucursal.length > 0) {
        charts.sucursal = new Chart(document.getElementById('chartSucursal'), {
            type: 'pie',
            data: {
                labels: data.por_sucursal.map(item => item.sucursal || 'Sin sucursal'),
                datasets: [{
                    data: data.por_sucursal.map(item => item.cantidad),
                    backgroundColor: PALETA_GRAFICOS,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
    }

    // Mensual
    mostrarVacio('chartMensual', !data.mensuales || data.mensuales.length === 0);
    if (data.mensuales && data.mensuales.length > 0) {
        charts.mensual = new Chart(document.getElementById('chartMensual'), {
            type: 'line',
            data: {
                labels: data.mensuales.map(item => formatearMes(item.mes)),
                datasets: [{
                    label: 'Incidencias',
                    data: data.mensuales.map(item => item.cantidad),
                    backgroundColor: 'rgba(52, 152, 219, 0.12)',
                    borderColor: COLOR_ACCENT,
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true
                }]
            },
            options: { responsive: true, scales: { y: { beginAtZero: true } } }
        });
    }

    // Top clientes
    mostrarVacio('chartClientes', !data.top_clientes || data.top_clientes.length === 0);
    if (data.top_clientes && data.top_clientes.length > 0) {
        charts.clientes = new Chart(document.getElementById('chartClientes'), {
            type: 'bar',
            data: {
                labels: data.top_clientes.map(item => item.cliente || 'Sin cliente'),
                datasets: [{
                    label: 'Número de Incidencias',
                    data: data.top_clientes.map(item => item.cantidad),
                    backgroundColor: PALETA_GRAFICOS,
                    borderWidth: 1
                }]
            },
            options: { responsive: true, indexAxis: 'y', scales: { x: { beginAtZero: true } } }
        });
    }

    // Equipos con más incidencias (antes mal etiquetado como "tipos de falla")
    mostrarVacio('chartEquipos', !data.por_equipo || data.por_equipo.length === 0);
    if (data.por_equipo && data.por_equipo.length > 0) {
        charts.equipos = new Chart(document.getElementById('chartEquipos'), {
            type: 'bar',
            data: {
                labels: data.por_equipo.map(item => item.equipo || 'Sin equipo'),
                datasets: [{
                    label: 'Incidencias',
                    data: data.por_equipo.map(item => item.cantidad),
                    backgroundColor: '#0891b2',
                    borderColor: '#0e7490',
                    borderWidth: 1
                }]
            },
            options: { responsive: true, scales: { y: { beginAtZero: true } } }
        });
    }

    // Tipos de falla más comunes (dato real, antes nunca se graficaba)
    mostrarVacio('chartFallasComunes', !data.top_fallas || data.top_fallas.length === 0);
    if (data.top_fallas && data.top_fallas.length > 0) {
        charts.fallas = new Chart(document.getElementById('chartFallasComunes'), {
            type: 'bar',
            data: {
                labels: data.top_fallas.map(item => item.falla || 'Sin especificar'),
                datasets: [{
                    label: 'Incidencias',
                    data: data.top_fallas.map(item => item.cantidad),
                    backgroundColor: '#d97706',
                    borderColor: '#b45309',
                    borderWidth: 1
                }]
            },
            options: { responsive: true, indexAxis: 'y', scales: { x: { beginAtZero: true } } }
        });
    }

    // Distribución real de tiempos de cierre (reemplaza el chart de "prioridad", que no existía en BD)
    const buckets = data.cierre_buckets || [];
    const totalBuckets = buckets.reduce((a, b) => a + (b.cantidad || 0), 0);
    mostrarVacio('chartCierreBuckets', totalBuckets === 0);
    if (totalBuckets > 0) {
        charts.cierreBuckets = new Chart(document.getElementById('chartCierreBuckets'), {
            type: 'bar',
            data: {
                labels: buckets.map(b => b.label),
                datasets: [{
                    label: 'Incidencias cerradas',
                    data: buckets.map(b => b.cantidad),
                    backgroundColor: ['#16a34a', '#3498db', '#d97706', '#dc2626'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }
}

function crearGraficosTecnicos(data) {
    if (!data.graficos) return;

    mostrarVacio('chartRendimiento', !data.graficos.rendimiento || data.graficos.rendimiento.labels.length === 0);
    if (data.graficos.rendimiento && data.graficos.rendimiento.labels.length > 0) {
        charts.rendimiento = new Chart(document.getElementById('chartRendimiento'), {
            type: 'bar',
            data: {
                labels: data.graficos.rendimiento.labels,
                datasets: [
                    { label: 'Incidencias Asignadas', data: data.graficos.rendimiento.datos_asignadas, backgroundColor: COLOR_ACCENT, borderColor: COLOR_ACCENT_DARK, borderWidth: 1 },
                    { label: 'Incidencias Completadas', data: data.graficos.rendimiento.datos_completadas, backgroundColor: '#16a34a', borderColor: '#15803d', borderWidth: 1 }
                ]
            },
            options: { responsive: true, scales: { y: { beginAtZero: true } } }
        });
    }

    mostrarVacio('chartEficienciaTecnico', !data.graficos.eficiencia || data.graficos.eficiencia.labels.length === 0);
    if (data.graficos.eficiencia && data.graficos.eficiencia.labels.length > 0) {
        charts.eficiencia = new Chart(document.getElementById('chartEficienciaTecnico'), {
            type: 'bar',
            data: {
                labels: data.graficos.eficiencia.labels,
                datasets: [{
                    label: 'Eficiencia (%)',
                    data: data.graficos.eficiencia.datos,
                    backgroundColor: '#d97706',
                    borderColor: '#b45309',
                    borderWidth: 1
                }]
            },
            options: { responsive: true, scales: { y: { beginAtZero: true, max: 100, title: { display: true, text: 'Porcentaje (%)' } } } }
        });
    }

    const tiemposCierre = data.graficos.tiempos_cierre;
    mostrarVacio('chartTiempoCierreTecnico', !tiemposCierre || tiemposCierre.labels.length === 0);
    if (tiemposCierre && tiemposCierre.labels.length > 0) {
        charts.tiempoCierreTecnico = new Chart(document.getElementById('chartTiempoCierreTecnico'), {
            type: 'bar',
            data: {
                labels: tiemposCierre.labels,
                datasets: [{
                    label: 'Mediana de cierre (días)',
                    data: tiemposCierre.datos_dias,
                    backgroundColor: '#8e44ad',
                    borderColor: '#6c3483',
                    borderWidth: 1
                }]
            },
            options: { responsive: true, indexAxis: 'y', scales: { x: { beginAtZero: true, title: { display: true, text: 'Días' } } } }
        });
    }
}

function actualizarElementoSiExiste(id, valor) {
    const elemento = document.getElementById(id);
    if (elemento) elemento.textContent = valor;
}

function formatearMes(mesString) {
    if (!mesString) return 'Sin fecha';
    const [year, month] = mesString.split('-');
    const meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    return `${meses[parseInt(month) - 1]} ${year}`;
}

function mostrarLoading(mostrar) {
    const main = document.getElementById('mainContent');
    if (main) main.classList.toggle('is-loading', mostrar);
}

function mostrarError(mensaje) {
    let errorDiv = document.getElementById('errorMessage');
    if (!errorDiv) {
        errorDiv = document.createElement('div');
        errorDiv.id = 'errorMessage';
        errorDiv.className = 'panel-error';
        const mainContent = document.getElementById('mainContent');
        const filters = document.querySelector('.filters');
        if (mainContent && filters) mainContent.insertBefore(errorDiv, filters);
    }
    errorDiv.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${mensaje}`;
    errorDiv.style.display = 'flex';
    setTimeout(() => { errorDiv.style.display = 'none'; }, 5000);
}

function toggleChartType(chartId) {
    const chart = charts[chartId];
    if (!chart) return;
    const currentType = chart.config.type;
    let newType = currentType;
    if (currentType === 'bar') newType = 'line';
    else if (currentType === 'line') newType = 'pie';
    else if (currentType === 'pie') newType = 'doughnut';
    else if (currentType === 'doughnut') newType = 'bar';
    chart.config.type = newType;
    chart.update();
}

function downloadChart(chartId) {
    const chart = charts[chartId];
    if (!chart) return;
    const link = document.createElement('a');
    link.download = `grafico-${chartId}-${new Date().toISOString().split('T')[0]}.png`;
    link.href = chart.toBase64Image();
    link.click();
}

function aplicarFiltros() {
    cargarEstadisticas();
}

function exportarDatos() {
    const params = construirParametros();
    window.location.href = `../backend/estadisticas.php?action=exportar_csv&${params.toString()}`;
}

// ===== Drill-down: revisar y corregir el estatus de las incidencias detrás
// de una porción del gráfico o de una tarjeta de KPI =====

function inicializarModalDrilldown() {
    const overlay = document.getElementById('modalDrilldown');
    if (!overlay) return;

    overlay.addEventListener('click', function(evt) {
        if (evt.target === overlay) cerrarModalDrilldown();
    });

    const btnCerrar = document.getElementById('modalDrilldownCerrar');
    if (btnCerrar) btnCerrar.addEventListener('click', cerrarModalDrilldown);

    document.addEventListener('keydown', function(evt) {
        if (evt.key === 'Escape' && overlay.classList.contains('is-open')) cerrarModalDrilldown();
    });
}

function mostrarModalDrilldown(abrir) {
    const overlay = document.getElementById('modalDrilldown');
    if (!overlay) return;
    overlay.classList.toggle('is-open', abrir);
    document.body.style.overflow = abrir ? 'hidden' : '';
}

function cerrarModalDrilldown() {
    mostrarModalDrilldown(false);
}

function abrirModalPorEstatus(estatusClic) {
    const titulos = {
        'Abierto,Pendiente': 'Incidencias abiertas o pendientes',
    };
    const titulo = titulos[estatusClic] || `Incidencias: ${estatusClic}`;
    mostrarModalDrilldown(true);
    cargarModalDrilldown({ estatusClic }, titulo);
}

function abrirModalReabiertas() {
    mostrarModalDrilldown(true);
    cargarModalDrilldown({ modo: 'reabiertas' }, 'Incidencias reabiertas (reincidencias)');
}

async function cargarModalDrilldown(extra, titulo) {
    const tituloEl = document.getElementById('modalDrilldownTitulo');
    const cuerpo = document.getElementById('modalDrilldownBody');
    const vacio = document.getElementById('modalDrilldownVacio');
    const aviso = document.getElementById('modalDrilldownAviso');
    const tabla = document.getElementById('modalDrilldownTabla');

    if (tituloEl) tituloEl.textContent = titulo || 'Incidencias';
    if (cuerpo) cuerpo.innerHTML = '';
    if (vacio) vacio.style.display = 'none';
    if (aviso) aviso.style.display = 'none';
    if (tabla) tabla.style.display = 'none';

    const params = construirParametros();
    params.delete('estatus'); // el filtro global de estatus no debe interferir con el detalle
    Object.entries(extra).forEach(([clave, valor]) => params.set(clave, valor));

    try {
        const resp = await fetch(`../backend/estadisticas.php?action=incidencias_por_estatus&${params.toString()}`);
        const resultado = await resp.json();
        if (!resultado.success) throw new Error(resultado.error || 'No se pudo cargar el detalle');

        const incidencias = resultado.data.incidencias || [];
        if (incidencias.length === 0) {
            if (vacio) vacio.style.display = 'flex';
            return;
        }

        if (tabla) tabla.style.display = '';
        incidencias.forEach(inc => cuerpo.appendChild(construirFilaDrilldown(inc)));

        if (resultado.data.limitado && aviso) {
            aviso.textContent = `Mostrando los primeros ${incidencias.length} resultados. Ajusta los filtros para acotar la búsqueda.`;
            aviso.className = 'modal-aviso';
            aviso.style.display = 'block';
        }
    } catch (error) {
        if (aviso) {
            aviso.textContent = 'Error al cargar el detalle: ' + error.message;
            aviso.className = 'modal-aviso modal-aviso--error';
            aviso.style.display = 'block';
        }
    }
}

function construirFilaDrilldown(inc) {
    const tr = document.createElement('tr');
    tr.dataset.id = inc.id;

    const celda = (texto) => {
        const td = document.createElement('td');
        td.textContent = texto || '-';
        return td;
    };

    const urlDetalle = `detalle.html?id=${encodeURIComponent(inc.id)}`;

    const tdFolio = document.createElement('td');
    const enlaceFolio = document.createElement('a');
    enlaceFolio.href = urlDetalle;
    enlaceFolio.target = '_blank';
    enlaceFolio.rel = 'noopener';
    enlaceFolio.className = 'drilldown-link';
    enlaceFolio.textContent = inc.numero_incidente || '-';
    tdFolio.appendChild(enlaceFolio);

    tr.appendChild(tdFolio);
    tr.appendChild(celda(inc.cliente));
    tr.appendChild(celda(inc.sucursal));
    tr.appendChild(celda(inc.tecnico));
    tr.appendChild(celda(inc.fecha ? String(inc.fecha).substring(0, 10) : ''));
    tr.appendChild(celda(inc.estatus));

    const tdAccion = document.createElement('td');
    const btnAbrir = document.createElement('a');
    btnAbrir.href = urlDetalle;
    btnAbrir.target = '_blank';
    btnAbrir.rel = 'noopener';
    btnAbrir.className = 'btn btn-outline btn-sm';
    btnAbrir.innerHTML = '<i class="fas fa-up-right-from-square"></i> Abrir';
    tdAccion.appendChild(btnAbrir);
    tr.appendChild(tdAccion);

    return tr;
}

window.cargarEstadisticas = cargarEstadisticas;
window.aplicarFiltros = aplicarFiltros;
window.toggleChartType = toggleChartType;
window.downloadChart = downloadChart;
window.exportarDatos = exportarDatos;
window.abrirModalPorEstatus = abrirModalPorEstatus;
window.abrirModalReabiertas = abrirModalReabiertas;
window.cerrarModalDrilldown = cerrarModalDrilldown;

// Actualizar cada 5 minutos
setInterval(cargarEstadisticas, 300000);
