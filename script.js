
//BLOQUE PARA AGREGAGR Y GESTIONAR INCIDENCIAS


document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("new-incidencia-form");
  const tableBody = document.querySelector("#incidencias-table tbody");

  // Obtener el último número de incidencia del localStorage
  const getNextIncidenciaNumber = () => {
    const incidencias = JSON.parse(localStorage.getItem("incidencias")) || [];
    return incidencias.length ? incidencias.length + 1 : 1;
  };

  // Formatear el número de incidencia con nomenclatura SIP-0001
  const formatIncidenciaNumber = (number) => {
    return `SIP-${String(number).padStart(4, "0")}`;
  };

  const addIncidenciaToTable = (incidencia) => {
    const row = document.createElement("tr");
    row.innerHTML = `
      <td>${incidencia.numero}</td>
      <td>${incidencia.cliente}</td>
      <td>${incidencia.fechaReporte}</td>
      <td>${incidencia.estatus}</td>
      <td>
        <button class="view-details" data-id="${incidencia.numero}">Ver</button>
      </td>
    `;
    tableBody.appendChild(row);
  };

  form.addEventListener("submit", (event) => {
    event.preventDefault();

    // Recopilar los datos del formulario
    const numeroIncidencia = formatIncidenciaNumber(getNextIncidenciaNumber());
    const cliente = document.getElementById("cliente").value;
    const contacto = document.getElementById("contacto").value;
    const direccion = document.getElementById("direccion").value;
    const falla = document.getElementById("falla").value;
    const fechaReporte = document.getElementById("fecha-reporte").value;
    const tecnico = document.getElementById("tecnico").value;
    const estatus = document.getElementById("estatus").value;

    const nuevaIncidencia = {
      numero: numeroIncidencia,
      cliente,
      contacto,
      direccion,
      falla,
      fechaReporte,
      tecnico,
      estatus,
    };

    // Guardar la incidencia en el localStorage
    const incidencias = JSON.parse(localStorage.getItem("incidencias")) || [];
    incidencias.push(nuevaIncidencia);
    localStorage.setItem("incidencias", JSON.stringify(incidencias));

    // Añadir la incidencia a la tabla
    addIncidenciaToTable(nuevaIncidencia);

    // Limpiar el formulario
    form.reset();
  });

  // Cargar incidencias existentes en la tabla al cargar la página
  const cargarIncidencias = () => {
    const incidencias = JSON.parse(localStorage.getItem("incidencias")) || [];
    incidencias.forEach((incidencia) => addIncidenciaToTable(incidencia));
  };

  cargarIncidencias();
});


//BLOQUE PARA AGREGAR Y GESTIONAR CLIENTES
document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("client-form");
  const clientTableBody = document.querySelector("#client-table tbody");

  // Guardar cliente en localStorage
  const saveClientToLocalStorage = (client) => {
    const clients = JSON.parse(localStorage.getItem("clients")) || [];
    clients.push(client);
    localStorage.setItem("clients", JSON.stringify(clients));
  };

  // Añadir cliente a la tabla en el DOM
  const addClientToTable = (client) => {
    const row = document.createElement("tr");
    row.innerHTML = `
      <td>${client.name}</td>
      <td>${client.taxInfo}</td>
      <td>${client.branches}</td>
      <td>${client.contacts}</td>
    `;
    clientTableBody.appendChild(row);
  };

  // Cargar clientes desde localStorage al iniciar
  const loadClientsFromLocalStorage = () => {
    const clients = JSON.parse(localStorage.getItem("clients")) || [];
    clients.forEach((client) => addClientToTable(client));
  };

  // Manejar el envío del formulario
  form.addEventListener("submit", (event) => {
    event.preventDefault();

    // Obtener valores del formulario
    const name = document.getElementById("name").value.trim();
    const taxInfo = document.getElementById("tax-info").value.trim();
    const branches = document.getElementById("branches").value.trim();
    const contacts = document.getElementById("contacts").value.trim();

    const client = {
      name,
      taxInfo,
      branches,
      contacts,
    };

    // Guardar y añadir al DOM
    saveClientToLocalStorage(client);
    addClientToTable(client);

    // Resetear formulario
    form.reset();
  });

  // Inicializar carga de clientes
  loadClientsFromLocalStorage();
});
