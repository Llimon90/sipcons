document.addEventListener("DOMContentLoaded", function () {
    const modal = document.getElementById("modal-editar");
    const formEditar = document.getElementById("form-editar");
    const btnEliminar = document.getElementById("btn-eliminar");
    const cerrarModal = document.querySelector(".cerrar");
  
    // Abre el modal y carga los datos de la fila seleccionada
    document.getElementById("tabla-body").addEventListener("click", function (event) {
      const row = event.target.closest("tr");
      if (!row) return;
  
      document.getElementById("edit-id").value = row.dataset.id;
      document.getElementById("edit-cliente").value = row.querySelector(".cliente").textContent;
      document.getElementById("edit-sucursal").value = row.querySelector(".sucursal").textContent;
      document.getElementById("edit-contacto").value = row.querySelector(".contacto").textContent;
      document.getElementById("edit-falla").value = row.querySelector(".falla").textContent;
      document.getElementById("edit-fecha").value = row.querySelector(".fecha").textContent;
      document.getElementById("edit-tecnico").value = row.querySelector(".tecnico").textContent;
      document.getElementById("edit-estatus").value = row.querySelector(".estatus").textContent;
  
      modal.style.display = "block"; // Mostrar modal
    });
  
    // Cierra el modal
    cerrarModal.addEventListener("click", function () {
      modal.style.display = "none";
    });
  
    // Actualizar incidencia
    formEditar.addEventListener("submit", function (event) {
      event.preventDefault();
  
      const incidenciaActualizada = {
        id: document.getElementById("edit-id").value,
        cliente: document.getElementById("edit-cliente").value,
        sucursal: document.getElementById("edit-sucursal").value,
        contacto: document.getElementById("edit-contacto").value,
        falla: document.getElementById("edit-falla").value,
        fecha: document.getElementById("edit-fecha").value,
        tecnico: document.getElementById("edit-tecnico").value,
        estatus: document.getElementById("edit-estatus").value,
      };
  
      fetch("../backend/actualiza.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(incidenciaActualizada),
      })
        .then(response => response.json())
        .then(data => {
          alert(data.message || data.error);
          modal.style.display = "none";
          location.reload(); // Recargar la tabla
        })
        .catch(error => alert("Error al actualizar la incidencia"));
    });
  
    // Eliminar incidencia
    btnEliminar.addEventListener("click", function () {
      const id = document.getElementById("edit-id").value;
  
      if (confirm("¿Estás seguro de eliminar esta incidencia?")) {
        fetch("../backend/elimina.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ id: id }),
        })
          .then(response => response.json())
          .then(data => {
            alert(data.message || data.error);
            modal.style.display = "none";
            location.reload();
          })
          .catch(error => alert("Error al eliminar la incidencia"));
      }
    });
  });
  