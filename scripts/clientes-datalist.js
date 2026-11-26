// Convierte un <input type="text"> de cliente en un desplegable escribible
// con el catálogo de clientes (mismo comportamiento que en el alta de incidencia).
// Un cliente distinto al que ya tenía el campo debe existir en el catálogo; el
// valor original se respeta aunque ya no exista (registros antiguos).
window.AppClientes = {
  async enlazar(input) {
    if (!input) return;

    const datalist = document.createElement('datalist');
    datalist.id = 'lista-clientes-' + (input.id || 'campo');
    input.setAttribute('list', datalist.id);
    input.setAttribute('autocomplete', 'off');
    input.parentNode.appendChild(datalist);

    let validos = [];
    let original = null;

    // El valor original se toma la primera vez que el usuario toca el campo
    // (para entonces la pantalla ya cargó el dato guardado).
    input.addEventListener('focus', () => {
      if (original === null) original = input.value.trim().toUpperCase();
    });

    input.addEventListener('input', () => {
      const valor = input.value.trim().toUpperCase();
      const permitido = !valor || valor === original || validos.includes(valor);
      input.setCustomValidity(permitido ? '' : 'El cliente escrito no existe. Selecciona un cliente válido de la lista.');
    });

    // Cliente que no existe: se crea aquí mismo y queda seleccionado
    if (window.AppAltaRapida) {
      AppAltaRapida.cliente(input, {
        existe: (nombre) => { const v = nombre.trim().toUpperCase(); return v === original || validos.includes(v); },
        alCrear: (c) => {
          const option = document.createElement('option');
          option.value = c.nombre;
          datalist.appendChild(option);
          validos.push(c.nombre.trim().toUpperCase());
        }
      });
    }

    try {
      const response = await fetch('../backend/obtener-clientes.php?t=' + Date.now(), { cache: 'no-store' });
      const clientes = await response.json();
      if (!Array.isArray(clientes)) return;

      clientes.forEach(cliente => {
        const option = document.createElement('option');
        option.value = cliente.nombre;
        datalist.appendChild(option);
        validos.push(cliente.nombre.trim().toUpperCase());
      });
    } catch (error) {
      console.error('Error al cargar clientes:', error);
    }
  },
};
