// Función común para manejar la carga inicial desde localStorage
const loadFromLocalStorage = (key, callback) => {
  const data = JSON.parse(localStorage.getItem(key)) || [];
  data.forEach(callback);
  return data;
};

// Función común para guardar datos en localStorage
const saveToLocalStorage = (key, data) => {
  localStorage.setItem(key, JSON.stringify(data));
};

// Función común para resetear formularios
const resetForm = (form) => form.reset();

// Incidencias - Actualizado
document.addEventListener("DOMContentLoaded", () => {
  // Gestión de incidencias
  const incidenciaForm = document.getElementById("new-incidencia-form");
  const incidenciaTableBody = document.querySelector("#incidencias-table tbody");

  const getNextIncidenciaNumber = () => {
    const incidencias = JSON.parse(localStorage.getItem("incidencias")) || [];
    return incidencias.length + 1;
  };

  const formatIncidenciaNumber = (number) => `SIP-${String(number).padStart(4, "0")}`;

  const addIncidenciaToTable = (incidencia) => {
    const row = document.createElement("tr");
    row.innerHTML = `
      <td>${incidencia.numero}</td>
      <td>${incidencia.cliente}</td>
      <td class="hidden-xs">${incidencia.contacto}</td>
      <td>${incidencia.estatus}</td>
    
      </td>
    `;
    incidenciaTableBody.appendChild(row);
  };

  incidenciaForm.addEventListener("submit", (event) => {
    event.preventDefault();

    const nuevaIncidencia = {
      numero: formatIncidenciaNumber(getNextIncidenciaNumber()),
      cliente: document.getElementById("cliente").value.trim(),
      contacto: document.getElementById("contacto").value.trim(),
      estatus: document.getElementById("estatus").value.trim(),
    };

    const incidencias = loadFromLocalStorage("incidencias", () => {});
    incidencias.push(nuevaIncidencia);
    saveToLocalStorage("incidencias", incidencias);
    addIncidenciaToTable(nuevaIncidencia);
    resetForm(incidenciaForm);
  });

  loadFromLocalStorage("incidencias", addIncidenciaToTable);
});

// Clientes - Actualizado
document.addEventListener("DOMContentLoaded", () => {
  // Gestión de clientes
  const clientForm = document.getElementById("client-form");
  const clientTableBody = document.querySelector("#client-table tbody");

  const addClientToTable = (client) => {
    const row = document.createElement("tr");
    row.innerHTML = `
      <td><a href="detalles-cliente.html?id=${encodeURIComponent(client.name)}">${client.name}</a></td>

      <td class="hidden-xs">${client.taxInfo}</td>
      

    `;
    clientTableBody.appendChild(row);
  };

  clientForm.addEventListener("submit", (event) => {
    event.preventDefault();

    const newClient = {
      name: document.getElementById("name").value.trim(),
      taxInfo: document.getElementById("tax-info").value.trim(),
      address: document.getElementById("branches").value.trim(),
    };

    const clients = loadFromLocalStorage("clients", () => {});
    clients.push(newClient);
    saveToLocalStorage("clients", clients);
    addClientToTable(newClient);
    resetForm(clientForm);
  });

  loadFromLocalStorage("clients", addClientToTable);
});

// Incidencias - Actualizado
document.addEventListener("DOMContentLoaded", () => {
  // Gestión de incidencias
  const incidenciaForm = document.getElementById("new-incidencia-form");
  const incidenciaTableBody = document.querySelector("#incidencias-table tbody");

  const getNextIncidenciaNumber = () => {
    const incidencias = JSON.parse(localStorage.getItem("incidencias")) || [];
    return incidencias.length + 1;
  };

  const formatIncidenciaNumber = (number) => `SIP-${String(number).padStart(4, "0")}`;

  const addIncidenciaToTable = (incidencia) => {
    const row = document.createElement("tr");
    row.innerHTML = `
      <td><a href="detalles-incidencia.html?id=${incidencia.numero}">${incidencia.numero}</a></td>
      <td>${incidencia.cliente}</td>
      <td class="hidden-xs">${incidencia.contacto}</td>
      <td>${incidencia.estatus}</td>
    `;
    incidenciaTableBody.appendChild(row);
  };

  incidenciaForm.addEventListener("submit", (event) => {
    event.preventDefault();

    const nuevaIncidencia = {
      numero: formatIncidenciaNumber(getNextIncidenciaNumber()),
      cliente: document.getElementById("cliente").value.trim(),
      contacto: document.getElementById("contacto").value.trim(),
      estatus: document.getElementById("estatus").value.trim(),
    };

    const incidencias = loadFromLocalStorage("incidencias", () => {});
    incidencias.push(nuevaIncidencia);
    saveToLocalStorage("incidencias", incidencias);
    addIncidenciaToTable(nuevaIncidencia);
    resetForm(incidenciaForm);
  });

  loadFromLocalStorage("incidencias", addIncidenciaToTable);
});

