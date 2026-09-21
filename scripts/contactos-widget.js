// Contactos de un cliente: cada uno con nombre, teléfono y email.
//
// - SipconsContactos.parsear(texto): nombres de un texto "Juan; Pedro"
//   (columna clientes.contactos, que el servidor mantiene sincronizada con los
//   nombres). La usan los desplegables de "Reporta".
// - SipconsContactos.init(contenedor, oculto): editor de filas. El <input
//   type="hidden"> recibe la lista como JSON: [{nombre, telefono, email}].
(function () {
  const SEP = ';';

  function parsear(texto) {
    return String(texto || '')
      .split(SEP)
      .map(s => s.trim())
      .filter(Boolean);
  }

  // Estilos propios: la hoja global da formato a table/thead/tr/button y
  // descuadraría el editor, por eso se usa una cuadrícula de divs.
  function inyectarEstilos() {
    if (document.getElementById('ctc-estilos')) return;
    const st = document.createElement('style');
    st.id = 'ctc-estilos';
    st.textContent =
      '.ctc-fila{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr) minmax(0,1.6fr) 40px;gap:12px;align-items:center;margin-bottom:10px;}' +
      '.ctc-cab{font-size:0.85rem;font-weight:600;color:#7f8c8d;text-transform:uppercase;letter-spacing:.03em;margin-bottom:6px;}' +
      '.ctc-fila input{margin:0;}' +
      '.ctc-quitar{background:none !important;box-shadow:none !important;transform:none !important;color:#c0392b;font-size:1.5rem;line-height:1;padding:0 !important;width:40px;height:40px;border-radius:50% !important;}' +
      '.ctc-quitar:hover{background:#fdecea !important;}' +
      '.ctc-add{background:#eaf2f8 !important;color:#2980b9 !important;border:1px dashed #2980b9 !important;box-shadow:none !important;transform:none !important;padding:10px 18px !important;font-size:0.95rem !important;}' +
      '.ctc-add:hover{background:#d6eaf8 !important;}' +
      '@media (max-width:700px){.ctc-cab{display:none;}.ctc-fila{grid-template-columns:1fr 40px;padding-bottom:10px;border-bottom:1px solid #e5e8eb;}.ctc-fila>*:nth-child(1),.ctc-fila>*:nth-child(2),.ctc-fila>*:nth-child(3){grid-column:1;}.ctc-fila>.ctc-quitar{grid-column:2;grid-row:1;}}';
    document.head.appendChild(st);
  }

  window.SipconsContactos = {
    parsear,

    init(contenedor, oculto) {
      let filas = [];

      inyectarEstilos();
      contenedor.innerHTML =
        '<div class="ctc-fila ctc-cab"><span>Nombre</span><span>Teléfono</span><span>Email</span><span></span></div>' +
        '<div class="ctc-filas"></div>' +
        '<button type="button" class="ctc-add">+ Agregar contacto</button>';

      const tbody = contenedor.querySelector('.ctc-filas');
      const btnAdd = contenedor.querySelector('.ctc-add');

      function sincronizar() {
        const validas = filas
          .map(f => ({ nombre: f.nombre.trim(), telefono: f.telefono.trim(), email: f.email.trim() }))
          .filter(f => f.nombre);
        oculto.value = JSON.stringify(validas);
      }

      function celda(fila, campo, tipo, placeholder) {
        const input = document.createElement('input');
        input.type = tipo;
        if (campo === 'telefono') input.setAttribute('inputmode', 'tel');
        input.placeholder = placeholder;
        input.value = fila[campo];
        input.addEventListener('input', () => { fila[campo] = input.value; sincronizar(); });
        // Enter no debe enviar el formulario: agrega una fila nueva
        input.addEventListener('keydown', e => {
          if (e.key === 'Enter') { e.preventDefault(); agregar(true); }
        });
        return input;
      }

      function pintar(enfocarUltima) {
        tbody.innerHTML = '';
        filas.forEach((fila, i) => {
          const tr = document.createElement('div');
          tr.className = 'ctc-fila';
          tr.appendChild(celda(fila, 'nombre', 'text', 'Nombre del contacto'));
          tr.appendChild(celda(fila, 'telefono', 'text', 'Teléfono con clave lada'));
          tr.appendChild(celda(fila, 'email', 'email', 'correo@dominio.com'));

          const x = document.createElement('button');
          x.type = 'button';
          x.className = 'ctc-quitar';
          x.textContent = '×';
          x.title = 'Quitar contacto';
          x.addEventListener('click', () => { filas.splice(i, 1); pintar(); });
          tr.appendChild(x);
          tbody.appendChild(tr);
        });
        if (enfocarUltima && filas.length) {
          const inputs = tbody.querySelectorAll('.ctc-fila:last-child input');
          if (inputs[0]) inputs[0].focus();
        }
        sincronizar();
      }

      function agregar(enfocar) {
        filas.push({ nombre: '', telefono: '', email: '' });
        pintar(enfocar);
      }

      btnAdd.addEventListener('click', () => agregar(true));

      const form = oculto.form;
      if (form) form.addEventListener('reset', () => setTimeout(() => { filas = []; agregar(false); }, 0));

      agregar(false);

      return {
        // Acepta la lista [{nombre, telefono, email}] o el texto "Juan; Pedro"
        set(datos) {
          const lista = Array.isArray(datos) ? datos : parsear(datos).map(nombre => ({ nombre }));
          filas = lista.map(c => ({
            nombre: c.nombre || '',
            telefono: c.telefono || '',
            email: c.email || '',
          }));
          if (!filas.length) filas.push({ nombre: '', telefono: '', email: '' });
          pintar();
        },
        get() { return oculto.value; },
      };
    },
  };
})();
