// En el script donde cargas las incidencias
incidenciasArray.forEach(incidencia => {
    const fila = document.createElement("tr");
    fila.innerHTML = `
        <td>${incidencia.numero}</td>
        <td>${incidencia.numero_incidente}</td>
        <td>${incidencia.cliente}</td>
        <td>${incidencia.sucursal}</td>
        <td>${incidencia.falla}</td>
        <td>${incidencia.fecha}</td>
        <td>${incidencia.estatus}</td>
    `;

    // Añadir evento de clic a la fila
    fila.addEventListener("click", () => {
        // Redirigir a la página de edición con el ID de la incidencia
        window.location.href = `editar-incidencia.php?id=${incidencia.id}`;
    });

    tbody.appendChild(fila);
});
