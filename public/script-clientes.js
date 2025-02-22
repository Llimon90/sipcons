document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("client-form");

    form.addEventListener("submit", function (event) {
        event.preventDefault(); // Evita que el formulario se envíe de manera tradicional

        // Crear un objeto FormData con los datos del formulario
        const formData = new FormData(form);

        // Enviar los datos al servidor
        fetch("server-clientes.php", {
            method: "POST",
            body: formData,
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert(data.error); // Muestra un mensaje de error
            } else {
                alert(data.message); // Muestra un mensaje de éxito
                form.reset(); // Limpia el formulario después de enviar
            }
        })
        .catch(error => {
            console.error("Error al enviar los datos:", error);
            alert("Ocurrió un error al enviar los datos. Inténtalo de nuevo.");
        });
    });
});
