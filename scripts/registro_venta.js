document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('form-venta');
  const btn = document.getElementById('btn-registrar-venta');
  const msg = document.getElementById('mensaje');
  const qty = document.getElementById('qty');

  const container = document.getElementById('series-container');

  const showMessage = (text, type='info') => {
    msg.textContent = text;
    msg.className = type;
    if (type === 'success') setTimeout(() => { msg.textContent=''; msg.className=''; }, 5000);
  };

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

  const validate = () => {
    const missing = ['cliente','equipo','garantia'].filter(id => !form[id].value.trim());
    if (missing.length) {
      showMessage(`Faltan: ${missing.join(', ')}`, 'error');
      return false;
    }
    return true;
  };

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
    } catch (e) {
      console.error(e);
      showMessage(e.message, 'error');
    }
  };

  qty.addEventListener('change', generateSeries);
  btn.addEventListener('click', submitForm);
  form.addEventListener('submit', e => { e.preventDefault(); submitForm(); });

  generateSeries();
});

// Cargar clientes al iniciar
document.addEventListener('DOMContentLoaded', async () => {
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
    alert('Error al cargar clientes en el select');
  }
});

// Función para cargar y mostrar las ventas
const cargarVentas = async (filtroCliente = '', filtroEquipo = '') => {
  try {
    const response = await fetch(`../backend/obtener-ventas.php`);
    const data = await response.json();
    
    if (!data.exito) throw new Error(data.mensaje || 'Error al cargar ventas');
    
    const tbody = document.getElementById('ventas-body');
    tbody.innerHTML = '';
    
    // Filtrar ventas si hay filtros aplicados
    const ventasFiltradas = data.ventas.filter(venta => {
      const clienteMatch = venta.cliente.toLowerCase().includes(filtroCliente.toLowerCase());
      const equipoMatch = venta.equipo.toLowerCase().includes(filtroEquipo.toLowerCase());
      return clienteMatch && equipoMatch;
    });
    
    if (ventasFiltradas.length === 0) {
      tbody.innerHTML = '<tr><td colspan="8">No se encontraron ventas</td></tr>';
      return;
    }
    
    ventasFiltradas.forEach(venta => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${new Date(venta.fecha_registro).toLocaleDateString()}</td>
        <td>${venta.cliente}</td>
        <td>${venta.sucursal || '-'}</td>
        <td>${venta.equipo}</td>
        <td>${venta.marca || ''} ${venta.modelo || ''}</td>
        <td>${venta.numero_serie}</td>
        <td>${venta.garantia} meses</td>
        <td>${venta.notas || '-'}</td>
      `;
      tbody.appendChild(tr);
    });
    
  } catch (error) {
    console.error('Error al cargar ventas:', error);
    showMessage(error.message, 'error');
  }
};

// Event listeners para filtros y botón refrescar
document.addEventListener('DOMContentLoaded', () => {
  // Cargar ventas al iniciar
  cargarVentas();
  
  // Configurar filtros
  const filtroCliente = document.getElementById('filtro-cliente');
  const filtroEquipo = document.getElementById('filtro-equipo');
  const btnRefrescar = document.getElementById('btn-refrescar');
  
  const aplicarFiltros = () => {
    cargarVentas(filtroCliente.value, filtroEquipo.value);
  };
  
  filtroCliente.addEventListener('input', aplicarFiltros);
  filtroEquipo.addEventListener('input', aplicarFiltros);
  btnRefrescar.addEventListener('click', aplicarFiltros);
  
  // También puedes recargar las ventas después de registrar una nueva
  const btnRegistrar = document.getElementById('btn-registrar-venta');
  btnRegistrar.addEventListener('click', async () => {
    await submitForm();
    cargarVentas(); // Recargar la lista después de registrar
  });
});