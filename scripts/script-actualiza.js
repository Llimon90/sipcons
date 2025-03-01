document.addEventListener("DOMContentLoaded", function () {
    const tablaBody = document.getElementById("tabla-body");
    const form = document.getElementById("new-incidencia-form");

    const btnRegistrar = document.getElementById("btn-registrar");
    const btnActualizar = document.getElementById("btn-actualizar");
    const btnEliminar = document.getElementById("btn-eliminar");
    const inputId = document.getElementById("incidencia-id");

    // Cargar datos en el formulario al hacer doble clic en una fila
    tablaBody.addEventListener("dblclick", function (event) {
        const row = event.target.closest("tr");
        if (!row) return;

        // Obtener datos de la fila
        inputId.value = row.dataset.id;
        document.getElementById("cliente").value = row.querySelector(".cliente").textContent;
        document.getElementById("sucursal").value = row.querySelector(".sucursal").textContent;
        document.getElementById("contacto").value = row.querySelector(".contacto").textContent;
        document.getElementById("falla").value = row.querySelector(".falla").textContent;
        document.getElementById("fecha").value = row.querySelector(".fecha").textContent;
        document.getElementById("tecnico").value = row.querySelector(".tecnico").textContent;
        document.getElementById("estatus").value = row.querySelector(".estatus").textContent;

        // Ocultar botón de registrar y mostrar botones de actualizar/eliminar
        btnRegistrar.style.display = "none";
        btnActualizar.style.display = "inline-block";
        btnEliminar.style.display = "inline-block";
    });

    // Actualizar incidencia
    btnActualizar.addEventListener("click", function () {
        if (!inputId.value) {
            alert("No se encontró el ID de la incidencia");
            return;
        }

        const incidenciaActualizada = {
            id: inputId.value,
            cliente: document.getElementById("cliente").value,
            sucursal: document.getElementById("sucursal").value,
            contacto: document.getElementById("contacto").value,
            falla: document.getElementById("falla").value,
            fecha: document.getElementById("fecha").value,
            tecnico: document.getElementById("tecnico").value,
            estatus: document.getElementById("estatus").value,
        };

        fetch("../backend/actualiza.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(incidenciaActualizada),
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert(data.error);
            } else {
                alert(data.message);
                location.reload(); // Recargar la página
            }
        })
        .catch(error => alert("Error al actualizar la incidencia"));
    });

    // Eliminar incidencia
    btnEliminar.addEventListener("click", function () {
        if (!inputId.value) {
            alert("No se encontró el ID de la incidencia");
            return;
        }

        if (confirm("¿Estás seguro de eliminar esta incidencia?")) {
            fetch("../backend/elimina.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ id: inputId.value }),
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert(data.error);
                } else {
                    alert(data.message);
                    location.reload(); // Recargar la página
                }
            })
            .catch(error => alert("Error al eliminar la incidencia"));
        }
    });
});
