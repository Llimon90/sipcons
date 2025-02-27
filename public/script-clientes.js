document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("new-cliente-form");

    form.addEventListener("submit", function (event) {
        event.preventDefault(); // Evita que el formulario se envíe normalmente

        const formData = new FormData(form);

        fetch("server-clientes.php", {
            method: "POST",
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log("Respuesta del servidor:", data);
            if (data.success) {
                alert("Cliente registrado con éxito");
                form.reset(); 
            } else {
                alert("Error al registrar cliente: " + data.message);
            }
        })
        .catch(error => {
            console.error("Error en la solicitud:", error);
            alert("Hubo un problema al procesar la solicitud.");
        });
    });
});
