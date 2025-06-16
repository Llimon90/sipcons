document.addEventListener('DOMContentLoaded', () => {
  // Elementos del formulario de registro
  const form = document.getElementById('form-venta');
  const btn = document.getElementById('btn-registrar-venta');
  const msg = document.getElementById('mensaje');
  const qty = document.getElementById('qty');
  const container = document.getElementById('series-container');

  // Elementos de filtrado
  const filtroFecha = document.getElementById('filtro-fecha');
  const filtroCliente = document.getElementById('filtro-cliente');
  const filtroSucursal = document.getElementById('filtro-sucursal');
  const filtroEquipo = document.getElementById('filtro-equipo');
  const filtroMarca = document.getElementById('filtro-marca');
  const filtroModelo = document.getElementById('filtro-modelo');
  const filtroSerie = document.getElementById('filtro-serie');
  const btnFiltrar = document.getElementById('btn-filtrar');
  const btnLimpiar = document.getElementById('btn-limpiar-filtros');
  const btnRefrescar = document.getElementById('btn-refrescar');

  // Función para mostrar mensajes
  const showMessage = (text, type='info') => {
    msg.textContent = text;
    msg.className = type;
    if (type === 'success') setTimeout(() => { msg.textContent=''; msg.className=''; }, 5000);
  };

  // Generar campos de números de serie según la cantidad
  const generateSeries = () => {
    const n = Math.max(1, parseInt(qty.value) || 1);
    container.innerHTML = '<label>Números de Serie:</label>';
    for (let i = 1; i <= n; i++) {
      const inp = document.createElement('input');
      inp.type = 'text';
      inp.name = `numero_serie_${i}`;
      inp.placeholder = `Serie #${i}`;
      inp.required = true;
      container.appendChild(inp);
    }
  };

  // Validar formulario antes de enviar
  const validate = () => {
    const missing = ['cliente','equipo','garantia'].filter(id => !form[id].value.trim());
    if (missing.length) {
      showMessage(`Faltan: ${missing.join(', ')}`, 'error');
      return false;
    }
    return true;
  };

  // Obtener datos del formulario
  const getData = () => {
    const data = {
      cliente: form.cliente.value.trim(),
      sucursal: form.sucursal.value.trim(),
      equipo: form.equipo.value.trim(),
      marca: form.marca.value.trim(),
      modelo: form.modelo.value.trim(),
      garantia: form.garantia.value.trim(),
      servicio: form.servicio.checked,
      notas: form.notas.value.trim(),
      numero_series: []
    };
    const count = parseInt(qty.value) || 1;
    for (let i = 1; i <= count; i++) {
      data.numero_series.push(form[`numero_serie_${i}`].value.trim());
    }
    return data;
  };

  // Enviar formulario
  const submitForm = async () => {
    if (!validate()) return;
    const data = getData();
    showMessage('Enviando...', 'info');

    try {
      const res = await fetch('../backend/registro_ventas.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
      });

      const j = await res.json().catch(() => { throw new Error('Respuesta no JSON'); });
      if (!res.ok) throw new Error(j.mensaje || `Error ${res.status}`);

      showMessage(j.mensaje, 'success');
      form.reset();
      generateSeries();
      cargarVentas(); // Recargar la lista después de registrar
    } catch (e) {
      console.error(e);
      showMessage(e.message, 'error');
    }
  };

  // Cargar clientes en el select
  const cargarClientes = async () => {
    try {
      const response = await fetch('../backend/obtener-clientes.php');
      const clientes = await response.json();

      const selectClientes = document.getElementById('cliente');
      selectClientes.innerHTML = '<option value="">Seleccionar Cliente</option>';

      clientes.forEach(cliente => {
        const option = document.createElement('option');
        option.value = cliente.nombre;
        option.textContent = cliente.nombre;
        selectClientes.appendChild(option);
      });
    } catch (error) {
      console.error('Error al cargar clientes:', error);
      showMessage('Error al cargar clientes', 'error');
    }
  };

  // Función principal para cargar ventas con filtros
  const cargarVentas = async () => {
    try {
      showMessage('Cargando ventas...', 'info');
      const response = await fetch(`../backend/obtener-ventas.php`);
      const data = await response.json();
      
      if (!data.exito) throw new Error(data.mensaje || 'Error al cargar ventas');
      
      const tbody = document.getElementById('ventas-body');
      tbody.innerHTML = '';
      
      // Obtener valores de los filtros
      const filtroClienteVal = filtroCliente.value.trim();
      const filtroEquipoVal = filtroEquipo.value.trim();
      const filtroFechaVal = filtroFecha.value;
      const filtroModeloVal = filtroModelo.value.trim();
      const filtroMarcaVal = filtroMarca.value.trim();
      const filtroSerieVal = filtroSerie.value.trim();
      const filtroSucursalVal = filtroSucursal.value.trim();

      // Filtrar ventas
      const ventasFiltradas = data.ventas.filter(venta => {
        const clienteMatch = venta.cliente.toLowerCase().includes(filtroClienteVal.toLowerCase());
        const equipoMatch = venta.equipo.toLowerCase().includes(filtroEquipoVal.toLowerCase());
        const fechaMatch = !filtroFechaVal || new Date(venta.fecha_registro).toLocaleDateString().includes(filtroFechaVal);
        const modeloMatch = !filtroModeloVal || (venta.modelo && venta.modelo.toLowerCase().includes(filtroModeloVal.toLowerCase()));
        const marcaMatch = !filtroMarcaVal || (venta.marca && venta.marca.toLowerCase().includes(filtroMarcaVal.toLowerCase()));
        const serieMatch = !filtroSerieVal || (venta.numero_serie && venta.numero_serie.toLowerCase().includes(filtroSerieVal.toLowerCase()));
        const sucursalMatch = !filtroSucursalVal || (venta.sucursal && venta.sucursal.toLowerCase().includes(filtroSucursalVal.toLowerCase()));
        
        return clienteMatch && equipoMatch && fechaMatch && modeloMatch && marcaMatch && serieMatch && sucursalMatch;
      });

      if (ventasFiltradas.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8">No se encontraron ventas con los filtros aplicados</td></tr>';
        showMessage('', ''); // Limpiar mensaje de carga
        return;
      }

      // Ordenar por fecha más reciente primero
      ventasFiltradas.sort((a, b) => new Date(b.fecha_registro) - new Date(a.fecha_registro));

      // Mostrar ventas filtradas
      ventasFiltradas.forEach(venta => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${new Date(venta.fecha_registro).toLocaleDateString()}</td>
          <td>${venta.cliente}</td>
          <td>${venta.sucursal || '-'}</td>
          <td>${venta.equipo}</td>
          <td>${venta.marca || ''} ${venta.modelo || ''}</td>
          <td>${venta.numero_serie || venta.numero_serie || '-'}</td>
          <td>${venta.garantia} meses</td>
          <td>${venta.notas || '-'}</td>
        `;
        tbody.appendChild(tr);
      });
      
      showMessage('', ''); // Limpiar mensaje de carga
    } catch (error) {
      console.error('Error al cargar ventas:', error);
      showMessage(error.message, 'error');
    }
  };

  // Limpiar todos los filtros
  const limpiarFiltros = () => {
    filtroFecha.value = '';
    filtroCliente.value = '';
    filtroSucursal.value = '';
    filtroEquipo.value = '';
    filtroMarca.value = '';
    filtroModelo.value = '';
    filtroSerie.value = '';
    cargarVentas();
  };

  // Event Listeners
  qty.addEventListener('change', generateSeries);
  btn.addEventListener('click', submitForm);
  form.addEventListener('submit', e => { e.preventDefault(); submitForm(); });
  
  // Filtros
  [filtroFecha, filtroCliente, filtroSucursal, filtroEquipo, filtroMarca, filtroModelo, filtroSerie].forEach(
    filtro => filtro.addEventListener('input', cargarVentas)
  );
  
  btnFiltrar.addEventListener('click', cargarVentas);
  btnLimpiar.addEventListener('click', limpiarFiltros);
  btnRefrescar.addEventListener('click', cargarVentas);

  // Inicialización
  generateSeries();
  cargarClientes();
  cargarVentas();
});