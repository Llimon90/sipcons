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
            }
        });
    }

    // Configurar botón de exportación
    const exportBtn = document.querySelector('.btn-outline');
    if (exportBtn) {
        exportBtn.addEventListener('click', function() {
            const exportOptions = document.getElementById('exportOptions');
            if (exportOptions) {
                exportOptions.style.display = exportOptions.style.display === 'none' ? 'flex' : 'none';
            }
        });
    }

    // Inicializar fecha actual para filtros personalizados
    const hoy = new Date();
    const hace30Dias = new Date();
    hace30Dias.setDate(hoy.getDate() - 30);
    
    const fechaInicio = document.getElementById('fechaInicio');
    const fechaFin = document.getElementById('fechaFin');
    
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
    
    // Cargar datos específicos de la pestaña si es necesario
    if (tabId === 'tecnicos') {
        cargarDatosTecnicos();
    }
}

async function cargarEstadisticas() {
    try {
        mostrarLoading(true);
        
        // Cargar estadísticas generales
        const responseGeneral = await fetch('../backend/estadisticas.php?action=estadisticas_generales');
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
        const responseIncidencias = await fetch('../backend/estadisticas.php?action=estadisticas_incidencias');
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
        
    } catch (error) {
        console.error('Error cargando estadísticas:', error);
        mostrarError('Error al cargar las estadísticas: ' + error.message);
    } finally {
        mostrarLoading(false);
    }
}

function actualizarEstadisticasGenerales(data) {
    console.log('Actualizando estadísticas generales:', data);
    
    // Actualizar tarjetas principales con verificación de elementos
    actualizarElementoSiExiste('totalIncidencias', data.total_incidencias || '0');
    actualizarElementoSiExiste('totalClientes', data.total_clientes || '0');
    actualizarElementoSiExiste('resueltasMes', data.incidencias_resueltas_mes || '0');
    actualizarElementoSiExiste('incidenciasPendientes', data.incidencias_pendientes || '0');
    
    // Actualizar tiempo promedio
    actualizarElementoSiExiste('tiempoPromedio', data.tiempo_promedio || '0d');
    
    // Calcular y mostrar tendencias
    const tendencia = data.tendencia_incidencias || 0;
    const elemento = document.getElementById('tendenciaIncidencias');
    if (elemento) {
        elemento.textContent = `${tendencia >= 0 ? '+' : ''}${tendencia}% vs mes anterior`;
        elemento.className = `stat-change ${tendencia >= 0 ? '' : 'negative'}`;
    }
    
    // Calcular eficiencia
    const total = data.total_incidencias || 1;
    const resueltas = data.incidencias_resueltas_mes || 0;
    const eficiencia = Math.round((resueltas / total) * 100);
    actualizarElementoSiExiste('eficienciaMensual', `${eficiencia}% de eficiencia`);
    
    // Actualizar estadísticas de la pestaña de incidencias
    actualizarElementoSiExiste('incidenciasAbiertas', data.incidencias_activas || '0');
    actualizarElementoSiExiste('incidenciasAsignadas', data.incidencias_activas || '0');
    actualizarElementoSiExiste('incidenciasCompletadas', data.incidencias_completadas || '0');
    actualizarElementoSiExiste('incidenciasFacturadas', data.incidencias_facturadas || '0');
    
    // Actualizar última actualización
    actualizarElementoSiExiste('lastUpdated', `Actualizado: ${new Date().toLocaleTimeString()}`);
    
    // Mostrar detalles de estatus en consola para debugging
    if (data.detalle_estatus) {
        console.log('Detalle de estatus:', data.detalle_estatus);
    }
}

function actualizarElementoSiExiste(id, valor) {
    const elemento = document.getElementById(id);
    if (elemento) {
        elemento.textContent = valor;
    } else {
        console.warn(`Elemento con ID '${id}' no encontrado`);
    }
}

function crearGraficos(data) {
    console.log('Creando gráficos con datos:', data);
    
    // Destruir charts existentes
    Object.values(charts).forEach(chart => {
        if (chart) chart.destroy();
    });
    
    // Colores para los gráficos
    const colores = [
        '#4361ee', '#3a0ca3', '#4cc9f0', '#f72585', '#7209b7',
        '#4895ef', '#560bad', '#b5179e', '#f15bb5', '#00bbf9'
    ];
    
    // Gráfico de estatus - CON VALIDACIÓN
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
    } else {
        console.warn('No hay datos para el gráfico de estatus');
    }
    
    // Gráfico por técnico - CON VALIDACIÓN
    if (data.por_tecnico && data.por_tecnico.length > 0) {
        const ctxTecnico = document.getElementById('chartTecnico');
        if (ctxTecnico) {
            charts.tecnico = new Chart(ctxTecnico, {
                type: 'bar',
                data: {
                    labels: data.por_tecnico.map(item => item.tecnico || 'Sin técnico'),
                    datasets: [{
                        label: 'Incidencias',
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
    } else {
        console.warn('No hay datos para el gráfico de técnicos');
    }
    
    // Gráfico por sucursal - CON VALIDACIÓN
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
    } else {
        console.warn('No hay datos para el gráfico de sucursales');
    }
    
    // Gráfico mensual - CON VALIDACIÓN
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
    } else {
        console.warn('No hay datos para el gráfico mensual');
    }
    
    // Gráfico top clientes - CON VALIDACIÓN
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
    } else {
        console.warn('No hay datos para el gráfico de clientes');
    }
}

function cargarDatosTecnicos() {
    // Esta función cargaría datos específicos para la pestaña de técnicos
    console.log('Cargando datos específicos de técnicos...');
    
    // Simular datos de técnicos (en una implementación real, harías una llamada API)
    setTimeout(() => {
        actualizarElementoSiExiste('tecnicoEficiente', 'Juan Pérez');
        actualizarElementoSiExiste('tecnicoRapido', 'María García');
        actualizarElementoSiExiste('tecnicoMes', 'Carlos López');
        actualizarElementoSiExiste('totalTecnicos', '8');
        
        // Simular gráficos de técnicos
        crearGraficosTecnicos();
    }, 500);
}

function crearGraficosTecnicos() {
    // Datos de ejemplo para gráficos de técnicos
    const datosTecnicos = {
        labels: ['Juan Pérez', 'María García', 'Carlos López', 'Ana Martínez', 'Pedro Rodríguez'],
        datos: [45, 38, 32, 28, 25]
    };
    
    // Gráfico de rendimiento de técnicos
    const ctxRendimiento = document.getElementById('chartRendimiento');
    if (ctxRendimiento) {
        charts.rendimiento = new Chart(ctxRendimiento, {
            type: 'bar',
            data: {
                labels: datosTecnicos.labels,
                datasets: [{
                    label: 'Incidencias Resueltas',
                    data: datosTecnicos.datos,
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
    
    // Gráfico de tiempos de respuesta
    const ctxTiempos = document.getElementById('chartTiempos');
    if (ctxTiempos) {
        charts.tiempos = new Chart(ctxTiempos, {
            type: 'bar',
            data: {
                labels: datosTecnicos.labels,
                datasets: [{
                    label: 'Tiempo Promedio (días)',
                    data: [2.5, 3.1, 1.8, 4.2, 2.9],
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
    
    // Gráfico de satisfacción
    const ctxSatisfaccion = document.getElementById('chartSatisfaccion');
    if (ctxSatisfaccion) {
        charts.satisfaccion = new Chart(ctxSatisfaccion, {
            type: 'doughnut',
            data: {
                labels: ['Excelente', 'Bueno', 'Regular', 'Malo'],
                datasets: [{
                    data: [45, 35, 15, 5],
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

function formatearMes(mesString) {
    if (!mesString) return 'Sin fecha';
    const [year, month] = mesString.split('-');
    const meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    return `${meses[parseInt(month) - 1]} ${year}`;
}

function mostrarLoading(mostrar) {
    const elementos = document.querySelectorAll('.stat-value, .chart-container');
    elementos.forEach(elemento => {
        if (mostrar) {
            elemento.style.opacity = '0.5';
        } else {
            elemento.style.opacity = '1';
        }
    });
}

function mostrarError(mensaje) {
    // Crear o mostrar un mensaje de error
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
    
    // Ocultar después de 5 segundos
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
    
    // Ciclo entre tipos de gráficos
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

function exportarDatos() {
    // Mostrar/ocultar opciones de exportación
    const exportOptions = document.getElementById('exportOptions');
    if (exportOptions) {
        exportOptions.style.display = exportOptions.style.display === 'none' ? 'flex' : 'none';
    }
}

function exportarPDF() {
    alert('Funcionalidad de exportación PDF - En desarrollo');
    // En una implementación real, usarías una librería como jsPDF
}

function exportarExcel() {
    alert('Funcionalidad de exportación Excel - En desarrollo');
    // En una implementación real, usarías una librería como SheetJS
}

function exportarImagen() {
    // Verificar si html2canvas está disponible
    if (typeof html2canvas === 'undefined') {
        alert('Para exportar como imagen, necesita incluir la librería html2canvas');
        return;
    }
    
    // Crear una imagen del dashboard completo
    html2canvas(document.getElementById('mainContent')).then(canvas => {
        const link = document.createElement('a');
        link.download = `dashboard-${new Date().toISOString().split('T')[0]}.png`;
        link.href = canvas.toDataURL();
        link.click();
    });
}

// Función para aplicar filtros
function aplicarFiltros() {
    const rangoFecha = document.getElementById('rangoFecha').value;
    const tecnico = document.getElementById('tecnico').value;
    const sucursal = document.getElementById('sucursal').value;
    const estatus = document.getElementById('estatus').value;
    
    console.log('Aplicando filtros:', { rangoFecha, tecnico, sucursal, estatus });
    
    // Aquí podrías recargar los datos con los filtros aplicados
    cargarEstadisticas();
}

// Actualizar cada 5 minutos
setInterval(cargarEstadisticas, 300000);

// Hacer funciones globales para los botones HTML
window.cargarEstadisticas = cargarEstadisticas;
window.exportarDatos = exportarDatos;
window.exportarPDF = exportarPDF;
window.exportarExcel = exportarExcel;
window.exportarImagen = exportarImagen;
window.toggleChartType = toggleChartType;
window.downloadChart = downloadChart;
window.aplicarFiltros = aplicarFiltros;