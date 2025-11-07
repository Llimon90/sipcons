// Variables globales para los charts
let charts = {};
let currentTab = 'overview';

// Cargar estadísticas al iniciar la página
document.addEventListener('DOMContentLoaded', function() {
    console.log('Iniciando carga de estadísticas...');
    inicializarInterfaz();
    cargarEstadisticas();
});

function inicializarInterfaz() {
    // Configurar pestañas
    const tabs = document.querySelectorAll('.tab');
    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            const tabId = this.getAttribute('data-tab');
            cambiarPestaña(tabId);
        });
    });

    // Configurar filtro de fechas personalizadas
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
                // Aplicar filtros inmediatamente al cambiar rango predefinido
                aplicarFiltros();
            }
        });
    }

    // Configurar inputs de fecha personalizada
    const fechaInicio = document.getElementById('fechaInicio');
    const fechaFin = document.getElementById('fechaFin');
    if (fechaInicio && fechaFin) {
        fechaInicio.addEventListener('change', aplicarFiltros);
        fechaFin.addEventListener('change', aplicarFiltros);
    }

    // Configurar otros filtros
    const tecnico = document.getElementById('tecnico');
    const sucursal = document.getElementById('sucursal');
    const estatus = document.getElementById('estatus');
    
    if (tecnico) tecnico.addEventListener('change', aplicarFiltros);
    if (sucursal) sucursal.addEventListener('change', aplicarFiltros);
    if (estatus) estatus.addEventListener('change', aplicarFiltros);

    // Inicializar fecha actual para filtros personalizados
    const hoy = new Date();
    const hace30Dias = new Date();
    hace30Dias.setDate(hoy.getDate() - 30);
    
    if (fechaInicio) fechaInicio.value = hace30Dias.toISOString().split('T')[0];
    if (fechaFin) fechaFin.value = hoy.toISOString().split('T')[0];
}

function cambiarPestaña(tabId) {
    // Actualizar pestañas activas
    document.querySelectorAll('.tab').forEach(tab => {
        tab.classList.remove('active');
    });
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });

    // Activar pestaña seleccionada
    const tabElement = document.querySelector(`[data-tab="${tabId}"]`);
    const tabContent = document.getElementById(`${tabId}-tab`);
    
    if (tabElement) tabElement.classList.add('active');
    if (tabContent) tabContent.classList.add('active');
    
    currentTab = tabId;
    
    // Recargar datos específicos de la pestaña
    cargarEstadisticas();
}

async function cargarEstadisticas() {
    try {
        mostrarLoading(true);
        
        // Construir parámetros de filtro
        const params = new URLSearchParams();
        
        // Agregar filtros actuales
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
        
        // Cargar estadísticas generales
        const urlGeneral = `../backend/estadisticas.php?action=estadisticas_generales&${params.toString()}`;
        console.log('Cargando:', urlGeneral);
        
        const responseGeneral = await fetch(urlGeneral);
        if (!responseGeneral.ok) {
            throw new Error('Error en la respuesta del servidor: ' + responseGeneral.status);
        }
        const dataGeneral = await responseGeneral.json();
        
        console.log('Datos generales recibidos:', dataGeneral);
        
        if (dataGeneral.success) {
            actualizarEstadisticasGenerales(dataGeneral.data);
        } else {
            throw new Error(dataGeneral.error || 'Error en los datos generales');
        }
        
        // Cargar estadísticas detalladas de incidencias
        const urlIncidencias = `../backend/estadisticas.php?action=estadisticas_incidencias&${params.toString()}`;
        const responseIncidencias = await fetch(urlIncidencias);
        
        if (!responseIncidencias.ok) {
            throw new Error('Error en la respuesta del servidor: ' + responseIncidencias.status);
        }
        const dataIncidencias = await responseIncidencias.json();
        
        console.log('Datos de incidencias recibidos:', dataIncidencias);
        
        if (dataIncidencias.success) {
            crearGraficos(dataIncidencias.data);
        } else {
            throw new Error(dataIncidencias.error || 'Error en los datos de incidencias');
        }
        
        // Cargar datos de técnicos si estamos en esa pestaña
        if (currentTab === 'tecnicos') {
            const urlTecnicos = `../backend/estadisticas.php?action=estadisticas_tecnicos&${params.toString()}`;
            const responseTecnicos = await fetch(urlTecnicos);
            
            if (responseTecnicos.ok) {
                const dataTecnicos = await responseTecnicos.json();
                if (dataTecnicos.success) {
                    actualizarEstadisticasTecnicos(dataTecnicos.data);
                }
            }
        }
        
    } catch (error) {
        console.error('Error cargando estadísticas:', error);
        mostrarError('Error al cargar las estadísticas: ' + error.message);
    } finally {
        mostrarLoading(false);
    }
}

function actualizarEstadisticasGenerales(data) {
    console.log('Actualizando estadísticas generales:', data);
    
    // Actualizar tarjetas principales
    actualizarElementoSiExiste('totalIncidencias', data.total_incidencias || '0');
    actualizarElementoSiExiste('totalClientes', data.total_clientes || '0');
    actualizarElementoSiExiste('resueltasMes', data.incidencias_resueltas || '0');
    actualizarElementoSiExiste('eficienciaMensual', `${data.eficiencia_total || 0}% de eficiencia`);
    
    // Actualizar tarjetas de la pestaña de incidencias
    actualizarElementoSiExiste('incidenciasAbiertas', data.incidencias_abiertas || '0');
    actualizarElementoSiExiste('incidenciasAsignadas', data.incidencias_asignadas || '0');
    actualizarElementoSiExiste('incidenciasCompletadas', data.incidencias_completadas || '0');
    actualizarElementoSiExiste('incidenciasFacturadas', data.incidencias_cerradas_factura || '0');
    
    // Nueva estadística: Total de equipos
    actualizarElementoSiExiste('totalEquipos', data.total_equipos || '0');
    
    // Tiempo promedio (placeholder - necesitarías fecha_cierre en tu tabla)
    actualizarElementoSiExiste('tiempoPromedio', 'N/A');
    
    // Tendencia
    const tendencia = data.tendencia_incidencias || 0;
    const elemento = document.getElementById('tendenciaIncidencias');
    if (elemento) {
        elemento.textContent = `${tendencia >= 0 ? '+' : ''}${tendencia}% vs mes anterior`;
        elemento.className = `stat-change ${tendencia >= 0 ? '' : 'negative'}`;
    }
    
    // Última actualización
    actualizarElementoSiExiste('lastUpdated', `Actualizado: ${data.last_updated || new Date().toLocaleTimeString()}`);
}

function actualizarEstadisticasTecnicos(data) {
    console.log('Actualizando estadísticas de técnicos:', data);
    
    // Actualizar tarjetas de técnicos
    actualizarElementoSiExiste('tecnicoEficiente', data.tecnico_eficiente || 'N/A');
    actualizarElementoSiExiste('tecnicoMes', data.tecnico_mas_completadas || 'N/A');
    actualizarElementoSiExiste('tecnicoRapido', data.tecnico_eficiente || 'N/A');
    actualizarElementoSiExiste('totalTecnicos', data.total_tecnicos || '0');
    
    // Crear gráficos de técnicos
    crearGraficosTecnicos(data);
}

function crearGraficos(data) {
    console.log('Creando gráficos con datos:', data);
    
    // Destruir charts existentes
    Object.values(charts).forEach(chart => {
        if (chart && typeof chart.destroy === 'function') {
            chart.destroy();
        }
    });
    charts = {};
    
    // Colores para los gráficos
    const colores = [
        '#4361ee', '#3a0ca3', '#4cc9f0', '#f72585', '#7209b7',
        '#4895ef', '#560bad', '#b5179e', '#f15bb5', '#00bbf9',
        '#28a745', '#20c997', '#ffc107', '#dc3545', '#6c757d'
    ];
    
    // Gráfico de estatus
    if (data.por_estatus && data.por_estatus.length > 0) {
        const ctxEstatus = document.getElementById('chartEstatus');
        if (ctxEstatus) {
            charts.estatus = new Chart(ctxEstatus, {
                type: 'doughnut',
                data: {
                    labels: data.por_estatus.map(item => item.estatus || 'Sin estatus'),
                    datasets: [{
                        data: data.por_estatus.map(item => item.cantidad),
                        backgroundColor: colores,
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = Math.round((value / total) * 100);
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }
    }
    
    // Gráfico por técnico
    if (data.por_tecnico && data.por_tecnico.length > 0) {
        const ctxTecnico = document.getElementById('chartTecnico');
        if (ctxTecnico) {
            charts.tecnico = new Chart(ctxTecnico, {
                type: 'bar',
                data: {
                    labels: data.por_tecnico.map(item => item.tecnico || 'Sin técnico'),
                    datasets: [{
                        label: 'Incidencias Asignadas',
                        data: data.por_tecnico.map(item => item.cantidad),
                        backgroundColor: '#4361ee',
                        borderColor: '#3a0ca3',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }
    }
    
    // Gráfico por sucursal
    if (data.por_sucursal && data.por_sucursal.length > 0) {
        const ctxSucursal = document.getElementById('chartSucursal');
        if (ctxSucursal) {
            charts.sucursal = new Chart(ctxSucursal, {
                type: 'pie',
                data: {
                    labels: data.por_sucursal.map(item => item.sucursal || 'Sin sucursal'),
                    datasets: [{
                        data: data.por_sucursal.map(item => item.cantidad),
                        backgroundColor: colores,
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }
    }
    
    // Gráfico mensual
    if (data.mensuales && data.mensuales.length > 0) {
        const ctxMensual = document.getElementById('chartMensual');
        if (ctxMensual) {
            charts.mensual = new Chart(ctxMensual, {
                type: 'line',
                data: {
                    labels: data.mensuales.map(item => formatearMes(item.mes)),
                    datasets: [{
                        label: 'Incidencias',
                        data: data.mensuales.map(item => item.cantidad),
                        backgroundColor: 'rgba(67, 97, 238, 0.1)',
                        borderColor: '#4361ee',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }
    }
    
    // Gráfico top clientes
    if (data.top_clientes && data.top_clientes.length > 0) {
        const ctxClientes = document.getElementById('chartClientes');
        if (ctxClientes) {
            charts.clientes = new Chart(ctxClientes, {
                type: 'bar',
                data: {
                    labels: data.top_clientes.map(item => item.cliente || 'Sin cliente'),
                    datasets: [{
                        label: 'Número de Incidencias',
                        data: data.top_clientes.map(item => item.cantidad),
                        backgroundColor: colores,
                        borderColor: colores.map(color => color),
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    indexAxis: 'y',
                    scales: {
                        x: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }
    }
    
    // Gráfico por equipo
    if (data.por_equipo && data.por_equipo.length > 0) {
        const ctxEquipo = document.getElementById('chartFallas');
        if (ctxEquipo) {
            charts.equipo = new Chart(ctxEquipo, {
                type: 'bar',
                data: {
                    labels: data.por_equipo.map(item => item.equipo || 'Sin equipo'),
                    datasets: [{
                        label: 'Incidencias',
                        data: data.por_equipo.map(item => item.cantidad),
                        backgroundColor: '#4cc9f0',
                        borderColor: '#3a0ca3',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }
    }
}

function crearGraficosTecnicos(data) {
    if (!data.graficos) return;
    
    // Gráfico de rendimiento de técnicos
    const ctxRendimiento = document.getElementById('chartRendimiento');
    if (ctxRendimiento && data.graficos.rendimiento) {
        charts.rendimiento = new Chart(ctxRendimiento, {
            type: 'bar',
            data: {
                labels: data.graficos.rendimiento.labels,
                datasets: [
                    {
                        label: 'Incidencias Asignadas',
                        data: data.graficos.rendimiento.datos_asignadas,
                        backgroundColor: '#4361ee',
                        borderColor: '#3a0ca3',
                        borderWidth: 1
                    },
                    {
                        label: 'Incidencias Completadas',
                        data: data.graficos.rendimiento.datos_completadas,
                        backgroundColor: '#28a745',
                        borderColor: '#20a745',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }
    
    // Gráfico de eficiencia
    const ctxEficiencia = document.getElementById('chartTiempos');
    if (ctxEficiencia && data.graficos.eficiencia) {
        charts.eficiencia = new Chart(ctxEficiencia, {
            type: 'bar',
            data: {
                labels: data.graficos.eficiencia.labels,
                datasets: [{
                    label: 'Eficiencia (%)',
                    data: data.graficos.eficiencia.datos,
                    backgroundColor: '#ffc107',
                    borderColor: '#e0a800',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        title: {
                            display: true,
                            text: 'Porcentaje (%)'
                        }
                    }
                }
            }
        });
    }
    
    // Gráfico de satisfacción (placeholder)
    const ctxSatisfaccion = document.getElementById('chartSatisfaccion');
    if (ctxSatisfaccion) {
        charts.satisfaccion = new Chart(ctxSatisfaccion, {
            type: 'doughnut',
            data: {
                labels: ['Excelente', 'Bueno', 'Regular', 'Malo'],
                datasets: [{
                    data: [65, 25, 7, 3],
                    backgroundColor: ['#28a745', '#20c997', '#ffc107', '#dc3545'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }
}

function actualizarElementoSiExiste(id, valor) {
    const elemento = document.getElementById(id);
    if (elemento) {
        elemento.textContent = valor;
    }
}

function formatearMes(mesString) {
    if (!mesString) return 'Sin fecha';
    const [year, month] = mesString.split('-');
    const meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    return `${meses[parseInt(month) - 1]} ${year}`;
}

function mostrarLoading(mostrar) {
    const elementos = document.querySelectorAll('.stat-value, .chart-container');
    elementos.forEach(elemento => {
        elemento.style.opacity = mostrar ? '0.5' : '1';
    });
}

function mostrarError(mensaje) {
    let errorDiv = document.getElementById('errorMessage');
    if (!errorDiv) {
        errorDiv = document.createElement('div');
        errorDiv.id = 'errorMessage';
        errorDiv.style.cssText = `
            background: #dc3545;
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            text-align: center;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
        `;
        const mainContent = document.getElementById('mainContent');
        const filters = document.querySelector('.filters');
        if (mainContent && filters) {
            mainContent.insertBefore(errorDiv, filters);
        }
    }
    errorDiv.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${mensaje}`;
    errorDiv.style.display = 'block';
    
    setTimeout(() => {
        errorDiv.style.display = 'none';
    }, 5000);
}

// Funciones de utilidad para gráficos
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
    console.log('Aplicando filtros...');
    cargarEstadisticas();
}

// Hacer funciones globales para los botones HTML
window.cargarEstadisticas = cargarEstadisticas;
window.aplicarFiltros = aplicarFiltros;
window.toggleChartType = toggleChartType;
window.downloadChart = downloadChart;

// Actualizar cada 5 minutos
setInterval(cargarEstadisticas, 300000);