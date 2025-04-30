document.addEventListener("DOMContentLoaded", function () {
    // Seleccionar la tabla donde se listan las incidencias
    const tablaBody = document.getElementById("tabla-body");

    // Cargar incidencias existentes y permitir edición de estatus
    function cargarIncidencias() {
        fetch("../backend/server.php")
            .then(response => response.json())
            .then(incidencias => {
                tablaBody.innerHTML = ""; // Limpiar tabla antes de llenar

                incidencias.forEach(incidencia => {
                    const fila = document.createElement("tr");

                    fila.innerHTML = `
                        <td>${incidencia.numero}</td>
                        <td>${incidencia.numero_incidente}</td>
                        <td>${incidencia.cliente}</td>
                        <td>${incidencia.sucursal}</td>
                        <td>${incidencia.falla}</td>
                        <td>${incidencia.fecha}</td>
                        <td>
                            <select class="estatus-select" data-id="${incidencia.id}">
                                <option value="Abierta" ${incidencia.estatus === "Abierta" ? "selected" : ""}>Abierta</option>
                                <option value="Pendiente" ${incidencia.estatus === "Pendiente" ? "selected" : ""}>Pendiente</option>
                                <option value="En seguimiento" ${incidencia.estatus === "En seguimiento" ? "selected" : ""}>En Seguimiento</option>
                                 <option value="Cerrada" ${incidencia.estatus === "Cerrada" ? "selected" : ""}>Cerrada</option>
                                <option value="Facturada" ${incidencia.estatus === "Facturada" ? "selected" : ""}>Facturada</option>
                            </select>
                        </td>
                    `;

                    tablaBody.appendChild(fila);
                });

                // Agregar evento para actualizar estatus en la BD
                document.querySelectorAll(".estatus-select").forEach(select => {
                    select.addEventListener("change", function () {
                        const id = this.dataset.id;
                        const nuevoEstatus = this.value;

                        fetch("../backend/actualiza.php", {
                            method: "POST",
                            headers: { "Content-Type": "application/json" },
                            body: JSON.stringify({ id, estatus: nuevoEstatus }),
                        })
                        .then(response => response.json())
                        .then(data => {
                            alert(data.message || data.error);
                        })
                        .catch(error => {
                            console.error("Error al actualizar estatus:", error);
                            alert("Hubo un error al actualizar el estatus");
                        });
                    });
                });
            })
            .catch(error => console.error("Error al cargar incidencias:", error));
    }

    // Cargar incidencias al cargar la página
    cargarIncidencias();
});

async function cargarArchivos(numeroIncidente) {
    const response = await fetch(`../backend/obtener_archivos.php?numero_incidente=${numeroIncidente}`);
    const archivos = await response.json();

    const contenedor = document.getElementById('contenedor-archivos');
    contenedor.innerHTML = ''; // Limpiar antes de cargar nuevos archivos

    if (archivos.length === 0) {
        contenedor.innerHTML = '<p>No hay archivos para esta incidencia.</p>';
        return;
    }

    archivos.forEach(ruta => {
        const ext = ruta.split('.').pop().toLowerCase(); // Obtener la extensión del archivo
        const fileContainer = document.createElement('div');
        fileContainer.style.margin = '10px';

        const rutaAjustada = ruta.replace('../', ''); // Ajustar la ruta si es necesario

        if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) {
            // Si es imagen, mostrarla
            const imgElement = document.createElement('img');
            imgElement.src = rutaAjustada;
            imgElement.alt = 'Archivo de incidencia';
            imgElement.style.width = '150px';
            imgElement.style.cursor = 'pointer';
            imgElement.addEventListener('click', () => {
                window.open(rutaAjustada, '_blank');
            });
            fileContainer.appendChild(imgElement);
        } else if (ext === 'pdf') {
            // Si es PDF, mostrar una miniatura utilizando un iframe
            const iframeElement = document.createElement('iframe');
            iframeElement.src = rutaAjustada;
            iframeElement.width = '150';
            iframeElement.height = '200';
            iframeElement.style.cursor = 'pointer';
            iframeElement.addEventListener('click', () => {
                window.open(rutaAjustada, '_blank');
            });
            fileContainer.appendChild(iframeElement);
        } else if (['mp4', 'webm', 'ogg'].includes(ext)) {
            // Si es video, mostrar una miniatura utilizando la etiqueta video
            const videoElement = document.createElement('video');
            videoElement.src = rutaAjustada;
            videoElement.width = 150;
            videoElement.controls = true;
            videoElement.style.cursor = 'pointer';
            videoElement.addEventListener('click', () => {
                window.open(rutaAjustada, '_blank');
            });
            fileContainer.appendChild(videoElement);
        } else if (['mp3', 'wav', 'ogg'].includes(ext)) {
            // Si es audio, mostrar una miniatura utilizando la etiqueta audio
            const audioElement = document.createElement('audio');
            audioElement.src = rutaAjustada;
            audioElement.controls = true;
            audioElement.style.width = '150px';
            audioElement.style.cursor = 'pointer';
            audioElement.addEventListener('click', () => {
                window.open(rutaAjustada, '_blank');
            });
            fileContainer.appendChild(audioElement);
        } else {
            // Para otros tipos de archivos, crear un enlace que abra en nueva pestaña
            const linkElement = document.createElement('a');
            linkElement.href = rutaAjustada;
            linkElement.target = '_blank';
            linkElement.textContent = `Abrir: ${rutaAjustada.split('/').pop()}`;
            linkElement.style.display = 'block';
            fileContainer.appendChild(linkElement);
        }

        contenedor.appendChild(fileContainer);
    });
}


// Llamar a la función cuando se seleccione un incidente
document.addEventListener("DOMContentLoaded", function () {
    const tablaBody = document.getElementById("tabla-body");

    tablaBody.addEventListener("click", function (event) {
        const fila = event.target.closest("tr");
        if (!fila) return;

        const numeroIncidente = fila.cells[1].textContent.trim(); // Obtener el número de incidente
        cargarArchivos(numeroIncidente);
    });
});
