// document.addEventListener("DOMContentLoaded", function () {
//     function cargarIncidencias() {
//         fetch("servertest.php")
//             .then(response => response.json())
//             .then(data => {
//                 const tbody = document.getElementById("tabla-body");
//                 // tbody.innerHTML = ""; // Limpiar tabla antes de agregar nuevas filas

//                 if (data.error) {
//                     tbody.innerHTML = `<tr><td colspan="8">${data.error}</td></tr>`;
//                     return;
//                 }

//                 if (data.message) {
//                     tbody.innerHTML = `<tr><td colspan="9">${data.message}</td></tr>`;
//                     return;
//                 }

//                 data.forEach(incidencia => {
//                     const fila = document.createElement("tr");
//                     fila.innerHTML = `
//                         <td>${incidencia.numero}</td>
//                         <td>${incidencia.cliente}</td>
//                         <td>${incidencia.contacto}</td>
//                         <td>${incidencia.sucursal}</td>
//                         <td>${incidencia.falla}</td>
//                         <td>${incidencia.fecha}</td>
//                         <td>${incidencia.tecnico}</td>
//                         <td>${incidencia.estatus}</td>
//                         <td>${incidencia.numero_incidente}</td>
//                     `;
//                     tbody.appendChild(fila);
//                 });
//             })
//             .catch(error => console.error("Error al cargar incidencias:", error));
//     }

//     cargarIncidencias(); // Llamar a la función al cargar la página
// });
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
                    tbody.innerHTML = `<tr><td colspan="30">${data.error}</td></tr>`;
                    return;
                }

                if (data.message) {
                    tbody.innerHTML = `<tr><td colspan="30">${data.message}</td></tr>`;
                    return;
                }

                data.forEach(incidencia => {
                    const fila = document.createElement("tr");
                    fila.innerHTML = `
                        <td>${incidencia.numero}</td>
                        <td>${incidencia.cliente}</td>
                      
                 
                        <td>${incidencia.falla}</td>
                        <td>${incidencia.fecha}</td>
                        <td>${incidencia.tecnico}</td>
                
                        <td>${incidencia.numero_incidente ?? }</td>
                    `;
                    tbody.appendChild(fila);
                });
            })
            .catch(error => console.error("Error al cargar incidencias:", error));
    }

    cargarIncidencias();
});
