// Editor de contactos múltiples de un cliente.
// Se guarda en la columna clientes.contactos como texto separado por ";"
// (mismo formato que ya usaban los registros existentes: "Juan; Pedro").
(function () {
  const SEP = ';';

  function parsear(texto) {
    return String(texto || '')
      .split(SEP)
      .map(s => s.trim())
      .filter(Boolean);
  }

  window.SipconsContactos = {
    parsear,

    // contenedor: div vacío donde se dibuja el editor
    // oculto:     <input type="hidden" name="contactos"> que viaja en el form
    init(contenedor, oculto) {
      let lista = [];

      contenedor.innerHTML =
        '<div class="ctc-chips" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px;"></div>' +
        '<div style="display:flex;gap:6px;">' +
          '<input type="text" class="ctc-input" placeholder="Nombre del contacto y Enter" style="flex:1;">' +
          '<button type="button" class="ctc-add" style="background:#2980b9;color:#fff;border:none;border-radius:5px;padding:0 14px;cursor:pointer;">Agregar</button>' +
        '</div>';

      const chips = contenedor.querySelector('.ctc-chips');
      const input = contenedor.querySelector('.ctc-input');
      const btn = contenedor.querySelector('.ctc-add');

      // El texto aún no agregado también viaja, para no perderlo al guardar.
      function sincronizar() {
        oculto.value = lista.concat(parsear(input.value)).join(SEP + ' ');
      }

      function pintar() {
        chips.innerHTML = '';
        lista.forEach((nombre, i) => {
          const chip = document.createElement('span');
          chip.style.cssText = 'background:#eaf2f8;border:1px solid #aed6f1;border-radius:14px;padding:3px 6px 3px 10px;font-size:0.9rem;display:inline-flex;align-items:center;gap:6px;';
          chip.appendChild(document.createTextNode(nombre));
          const x = document.createElement('button');
          x.type = 'button';
          x.textContent = '×';
          x.title = 'Quitar contacto';
          x.style.cssText = 'border:none;background:none;cursor:pointer;color:#c0392b;font-size:1rem;line-height:1;';
          x.addEventListener('click', () => { lista.splice(i, 1); pintar(); });
          chip.appendChild(x);
          chips.appendChild(chip);
        });
        sincronizar();
      }

      function agregar() {
        parsear(input.value).forEach(n => {
          if (!lista.some(e => e.toLowerCase() === n.toLowerCase())) lista.push(n);
        });
        input.value = '';
        pintar();
      }

      btn.addEventListener('click', agregar);
      input.addEventListener('input', sincronizar);
      input.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); agregar(); }
      });

      const form = oculto.form;
      if (form) form.addEventListener('reset', () => setTimeout(() => { lista = []; input.value = ''; pintar(); }, 0));

      pintar();

      return {
        set(texto) { lista = parsear(texto); input.value = ''; pintar(); },
        get() { return oculto.value; },
      };
    },
  };
})();
