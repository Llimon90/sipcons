
document.addEventListener("DOMContentLoaded", function () {
    function cargarIncidencias() {
        fetch("servertest.php")
            .then(response => response.json())
            .then(data => {
                console.log("Datos recibidos:", data); // Depuración

                const tbody = document.getElementById("tabla-body");
                if (!tbody) {
                    console.error("No se encontró el elemento con id 'tabla-body'");
                    return;
                }

                tbody.innerHTML = ""; // Limpia la tabla antes de agregar nuevas filas

                if (data.error) {
                    tbody.innerHTML = `<tr><td colspan="11">${data.error}</td></tr>`;
                    return;
                }

                if (data.message) {
                    tbody.innerHTML = `<tr><td colspan="11">${data.message}</td></tr>`;
                    return;
                }

                data.forEach(incidencia => {
                    console.log("Incidencia recibida:", incidencia); // 🔍 Verifica qué datos llegan
                    const fila = document.createElement("tr");
                    fila.innerHTML = `
                        <td>${incidencia.id ?? 'N/A'}</td>
                        <td>${incidencia.numero}</td>
                        <td>${incidencia.cliente}</td>
                        <td>${incidencia.contacto}</td>
                        <td>${incidencia.sucursal}</td>
                        <td>${incidencia.falla}</td>
                        <td>${incidencia.fecha}</td>
                        <td>${incidencia.tecnico}</td>
                        <td>${incidencia.estatus}</td>
                        <td>${incidencia.numero_incidente ?? 'N/A'}</td>
                    `;
                    tbody.appendChild(fila);
                });
                
            })
            .catch(error => console.error("Error al cargar incidencias:", error));
    }

    cargarIncidencias();
});
