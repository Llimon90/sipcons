document.addEventListener("DOMContentLoaded", () => {
    cargarIncidencias();
});

// Función para mostrar las incidencias en la tabla
function cargarIncidencias() {
    fetch('../backend/crud.php')
        .then(response => response.json())
        .then(data => {
            const tabla = document.getElementById('tabla-incidencias');
            tabla.innerHTML = ''; // Limpiar la tabla antes de cargar nuevas filas
            
            data.forEach(incidencia => {
                const fila = document.createElement('tr');
                fila.innerHTML = `
                    <td>${incidencia.id}</td>
                    <td>${incidencia.numero}</td>
                    <td>${incidencia.cliente}</td>
                    <td>${incidencia.sucursal}</td>
                    <td>${incidencia.estatus}</td>
                    <td><button onclick="seleccionarIncidencia(${incidencia.id})">Seleccionar</button></td>
                `;
                tabla.appendChild(fila);
            });
        })
        .catch(error => console.error("Error al cargar incidencias:", error));
}

// Función para obtener los datos de una incidencia específica
function seleccionarIncidencia(id) {
    fetch(`../backend/crud.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            document.getElementById('id').value = data.id;
            document.getElementById('numero').value = data.numero;
            document.getElementById('cliente').value = data.cliente;
            document.getElementById('sucursal').value = data.sucursal;
            document.getElementById('estatus').value = data.estatus;
        })
        .catch(error => console.error("Error al obtener incidencia:", error));
}

// Función para actualizar el estatus
document.getElementById('actualizar-incidencia').addEventListener('click', () => {
    const id = document.getElementById('id').value;
    const estatus = document.getElementById('estatus').value;

    fetch('../backend/crud.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, estatus }),
    })
    .then(response => response.json())
    .then(data => {
        alert(data.message || data.error);
        cargarIncidencias(); // Recargar la tabla
    })
    .catch(error => console.error("Error al actualizar estatus:", error));
});
