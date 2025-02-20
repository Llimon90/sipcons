//FUNCION PARA BARRA LATERAL

  
document.querySelector(".menu-icon").addEventListener("click", function () {
    console.log("Icono de menú presionado"); // Para verificar si funciona
    document.querySelector(".sidebar").classList.toggle("active");
});

function toggleSidebar() {
    console.log("Toggle Sidebar ejecutado"); // Para verificar si la función se ejecuta
    document.querySelector(".sidebar").classList.toggle("active");
}

//ENVIA INCIDENCIAS A BD


document.getElementById('new-incidencia-form').addEventListener('submit', function(event) {
    event.preventDefault(); // Prevenir envío por defecto
    
    const nuevaIncidencia = {
        numero: document.getElementById('numero').value,
        cliente: document.getElementById('cliente').value,
        contacto: document.getElementById('contacto').value,
        sucursal: document.getElementById('sucursal').value,
        falla: document.getElementById('falla').value,
        fecha: document.getElementById('fecha').value,
        tecnico: document.getElementById('tecnico').value,
        status: document.getElementById('estatus').value,
    };

    fetch('server.php', { // Ahora llama a server.php
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(nuevaIncidencia),
    })
    .then(response => response.json())
    .then(data => {
        console.log('Respuesta del servidor:', data);
        alert(data.message || data.error);
    })
    .catch(error => {
        console.error('Error al enviar los datos:', error);
        alert('Hubo un error al enviar los datos');
    });
});



//FUNCION PARA TENER FECHA ACTUAL EN INPUT FECHA INCIDENCIA
const fechaActual = new Date().toISOString().split('T')[0];

document.getElementById('fecha').value = fechaActual;


