<?php
// editar-incidencia.php
$id = $_GET['id'];

// Aquí deberías consultar la base de datos para obtener la incidencia por el ID
// Suponiendo que tienes una función llamada getIncidenciaById($id)
$incidencia = getIncidenciaById($id);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar Incidencia</title>
</head>
<body>
    <h1>Editar Incidencia</h1>
    <form id="editar-incidencia-form">
        <input type="hidden" id="id" name="id" value="<?php echo $incidencia['id']; ?>">
        <input type="text" id="numero" name="numero" value="<?php echo $incidencia['numero']; ?>" placeholder="Número">
        <input type="text" id="cliente" name="cliente" value="<?php echo $incidencia['cliente']; ?>" placeholder="Cliente">
        <input type="text" id="contacto" name="contacto" value="<?php echo $incidencia['contacto']; ?>" placeholder="Contacto">
        <input type="text" id="sucursal" name="sucursal" value="<?php echo $incidencia['sucursal']; ?>" placeholder="Sucursal">
        <input type="text" id="falla" name="falla" value="<?php echo $incidencia['falla']; ?>" placeholder="Falla">
        <input type="date" id="fecha" name="fecha" value="<?php echo $incidencia['fecha']; ?>" placeholder="Fecha">
        <input type="text" id="tecnico" name="tecnico" value="<?php echo $incidencia['tecnico']; ?>" placeholder="Técnico">
        <input type="text" id="estatus" name="estatus" value="<?php echo $incidencia['estatus']; ?>" placeholder="Estatus">
        <button type="submit">Actualizar Incidencia</button>
    </form>

    <script>
    document.getElementById("editar-incidencia-form").addEventListener("submit", function(event) {
        event.preventDefault();
        
        const formData = new FormData(this);
        
        // Enviar los datos actualizados al servidor
        fetch("crude.php", {
            method: "POST",
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert("Incidencia actualizada correctamente");
                // Redirigir a la página anterior o donde quieras
                window.location.href = "tu-pagina-de-incidencias.php"; // Cambia esto a la página de incidencias
            } else {
                alert("Error al actualizar incidencia: " + data.error);
            }
        })
        .catch(error => console.error("Error al actualizar incidencia:", error));
    });
    </script>
</body>
</html>
