document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("new-cliente-form");

    form.addEventListener("submit", function (event) {
        event.preventDefault(); // Evita que el formulario se envíe normalmente

        // Obtener los valores del formulario
        const formData = new FormData(form);

        // Enviar los datos al servidor mediante fetch
        fetch("server-clientes.php", {
            method: "POST",
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert("Cliente registrado con éxito");
                form.reset(); // Limpiar el formulario
                // Aquí podrías agregar código para actualizar la lista de clientes sin recargar la página
            } else {
                alert("Error al registrar cliente: " + data.message);
            }
        })
        .catch(error => console.error("Error en la solicitud:", error));
    });
});
