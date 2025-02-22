document.addEventListener("DOMContentLoaded", function () {
    function cargarIncidencias() {
        fetch("servertest.php")
            .then(response => response.json())
            .then(data => {
                const tbody = document.getElementById("tabla-body");
                tbody.innerHTML = ""; // Limpiar tabla antes de agregar nuevas filas

                if (data.error) {
                    tbody.innerHTML = `<tr><td colspan="8">${data.error}</td></tr>`;
                    return;
                }

                if (data.message) {
                    tbody.innerHTML = `<tr><td colspan="9">${data.message}</td></tr>`;
                    return;
                }

                data.forEach(kk => {
                    console.log(kk); // Verifica los datos recibidos

                    const fila = document.createElement("tr");
                    fila.innerHTML = `
                        <td>${kk.copia_numero_incidente ?? 'N/A'}</td>
                    `;
                    
                    tbody.appendChild(fila);
                });
            })
            .catch(error => console.error("Error al cargar incidencias:", error));
    }

    cargarIncidencias(); // Llamar a la función al cargar la página
});
