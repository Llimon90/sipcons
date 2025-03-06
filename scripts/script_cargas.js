// document.addEventListener("DOMContentLoaded", function () {
//     function cargarIncidencias() {
//         fetch("../backend/server.php")
//             .then(response => response.json())
//             .then(data => {
//                 const tbody = document.getElementById("tabla-body");
//                 tbody.innerHTML = ""; 

//                 if (data.error) {
//                     tbody.innerHTML = `<tr><td colspan="10">${data.error}</td></tr>`;
//                     return;
//                 }

//                 if (data.message) {
//                     tbody.innerHTML = `<tr><td colspan="10">${data.message}</td></tr>`;
//                     return;
//                 }

//                 // Convertir la BD en un arreglo de objetos
//                 let incidenciasArray = data.map(incidencia => ({
//                     numero: incidencia.numero,
//                     numero_incidente: incidencia.numero_incidente,
//                     cliente: incidencia.cliente,
//                     sucursal: incidencia.sucursal,
//                     falla: incidencia.falla,
//                     fecha: incidencia.fecha,
//                     estatus: incidencia.estatus
//                 }));

//                 console.log("Arreglo de incidencias:", incidenciasArray);

//                 // Mostrar el arreglo en la tabla
//                 incidenciasArray.forEach(incidencia => {
//                     const fila = document.createElement("tr");
//                     fila.innerHTML = `
//                         <td>${incidencia.numero}</td>
//                         <td>${incidencia.numero_incidente}</td>
//                         <td>${incidencia.cliente}</td>
//                         <td>${incidencia.sucursal}</td>
//                         <td>${incidencia.falla}</td>
//                         <td>${incidencia.fecha}</td>
//                         <td>${incidencia.estatus}</td>
//                     `;

//                     tbody.appendChild(fila);
//                 });
//             })
//             .catch(error => console.error("Error al cargar incidencias:", error));
//     }

//     cargarIncidencias(); // Cargar los datos cuando la página se carga


// });


document.addEventListener("DOMContentLoaded", function () {
    function cargarIncidencias() {
        fetch("../backend/server.php")
            .then(response => response.json())
            .then(data => {
                const tbody = document.getElementById("tabla-body");
                tbody.innerHTML = ""; 

                if (data.error) {
                    tbody.innerHTML = `<tr><td colspan="10">${data.error}</td></tr>`;
                    return;
                }

                if (data.message) {
                    tbody.innerHTML = `<tr><td colspan="10">${data.message}</td></tr>`;
                    return;
                }

                // Convertir la BD en un arreglo de objetos
                let incidenciasArray = data.map(incidencia => ({
                    id: incidencia.id,
                    numero: incidencia.numero,
                    numero_incidente: incidencia.numero_incidente,
                    cliente: incidencia.cliente,
                    sucursal: incidencia.sucursal,
                    falla: incidencia.falla,
                    fecha: incidencia.fecha,
                    estatus: incidencia.estatus
                }));

                console.log("Arreglo de incidencias:", incidenciasArray);

                // Mostrar el arreglo en la tabla
                incidenciasArray.forEach(incidencia => {
                    const fila = document.createElement("tr");
                    fila.innerHTML = `
                        <td>${incidencia.numero}</td>
                        <td><a href="detalle.html?id=${incidencia.id}" style="color: blue; text-decoration: underline;">${incidencia.numero_incidente}</a></td>
                        <td>${incidencia.cliente}</td>
                        <td>${incidencia.sucursal}</td>
                        <td>${incidencia.falla}</td>
                        <td>${incidencia.fecha}</td>
                        <td>${incidencia.estatus}</td>
                    `;

                    tbody.appendChild(fila);
                });
            })
            .catch(error => console.error("Error al cargar incidencias:", error));
    }

    cargarIncidencias(); // Cargar los datos cuando la página se carga
});
