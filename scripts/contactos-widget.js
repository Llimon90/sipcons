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

  const ESTILO_INPUT = 'width:100%;padding:6px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;';

  window.SipconsContactos = {
    parsear,

    init(contenedor, oculto) {
      let filas = [];

      contenedor.innerHTML =
        '<div style="overflow-x:auto;">' +
          '<table style="width:100%;border-collapse:collapse;">' +
            '<thead><tr style="text-align:left;font-size:0.85rem;color:#555;">' +
              '<th style="padding:2px 4px;">Nombre</th><th style="padding:2px 4px;">Teléfono</th><th style="padding:2px 4px;">Email</th><th></th>' +
            '</tr></thead>' +
            '<tbody class="ctc-filas"></tbody>' +
          '</table>' +
        '</div>' +
        '<button type="button" class="ctc-add" style="margin-top:6px;background:#2980b9;color:#fff;border:none;border-radius:5px;padding:6px 14px;cursor:pointer;">+ Agregar contacto</button>';

      const tbody = contenedor.querySelector('.ctc-filas');
      const btnAdd = contenedor.querySelector('.ctc-add');

      function sincronizar() {
        const validas = filas
          .map(f => ({ nombre: f.nombre.trim(), telefono: f.telefono.trim(), email: f.email.trim() }))
          .filter(f => f.nombre);
        oculto.value = JSON.stringify(validas);
      }

      function celda(fila, campo, tipo, placeholder) {
        const td = document.createElement('td');
        td.style.padding = '2px 4px';
        const input = document.createElement('input');
        input.type = tipo;
        input.placeholder = placeholder;
        input.value = fila[campo];
        input.style.cssText = ESTILO_INPUT;
        input.addEventListener('input', () => { fila[campo] = input.value; sincronizar(); });
        // Enter no debe enviar el formulario: agrega una fila nueva
        input.addEventListener('keydown', e => {
          if (e.key === 'Enter') { e.preventDefault(); agregar(true); }
        });
        td.appendChild(input);
        return td;
      }

      function pintar(enfocarUltima) {
        tbody.innerHTML = '';
        filas.forEach((fila, i) => {
          const tr = document.createElement('tr');
          tr.appendChild(celda(fila, 'nombre', 'text', 'Nombre del contacto'));
          tr.appendChild(celda(fila, 'telefono', 'tel', 'Con clave lada'));
          tr.appendChild(celda(fila, 'email', 'email', 'correo@dominio.com'));

          const td = document.createElement('td');
          td.style.padding = '2px 4px';
          const x = document.createElement('button');
          x.type = 'button';
          x.textContent = '×';
          x.title = 'Quitar contacto';
          x.style.cssText = 'border:none;background:none;cursor:pointer;color:#c0392b;font-size:1.2rem;line-height:1;';
          x.addEventListener('click', () => { filas.splice(i, 1); pintar(); });
          td.appendChild(x);
          tr.appendChild(td);
          tbody.appendChild(tr);
        });
        if (enfocarUltima && filas.length) {
          const inputs = tbody.querySelectorAll('tr:last-child input');
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
