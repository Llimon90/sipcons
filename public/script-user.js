    // FORMULARIO DE USUARIOS
    const userForm = document.getElementById('alta-usuarios-form');
    if (userForm !== null) {
        userForm.addEventListener('submit', function(event) {
            event.preventDefault();

            const nuevoUsuario = {
                nombre: document.getElementById('nombre').value,
                correo: document.getElementById('correo').value,
                telefono: document.getElementById('telefono').value,
                usuario: document.getElementById('usuario').value,
                password: document.getElementById('password').value,
                rol: document.getElementById('rol').value,
            };

            const confirmPassword = document.getElementById('confirm-password').value;
            if (nuevoUsuario.password !== confirmPassword) {
                alert("Las contraseñas no coinciden");
                return;
            }

            fetch('public/server.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(nuevoUsuario),
            })
            .then(response => response.json())
            .then(data => {
                console.log('Respuesta del servidor:', data);
                alert(data.message || data.error);
            })
            .catch(error => {
                console.error('Error al enviar los datos:', error);
                alert('Hubo un error al registrar el usuario');
            });
        });

    };
