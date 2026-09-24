// Llena un <datalist> con los números de serie del padrón de equipos del
// cliente elegido, para capturar el equipo en la incidencia (reincidencias).
window.SipconsSerie = {
    vincular(inputCliente, datalist) {
        if (!inputCliente || !datalist) return;
        let clienteCargado = null;

        const cargar = async () => {
            const cliente = inputCliente.value.trim();
            if (cliente === clienteCargado) return;
            clienteCargado = cliente;
            datalist.innerHTML = '';
            if (!cliente) return;
            try {
                const resp = await fetch(`../backend/obtener_equipos_cliente.php?cliente=${encodeURIComponent(cliente)}`);
                const equipos = await resp.json();
                if (!Array.isArray(equipos) || cliente !== inputCliente.value.trim()) return;
                equipos.forEach(eq => {
                    if (!eq.numero_serie) return;
                    const opt = document.createElement('option');
                    opt.value = eq.numero_serie;
                    opt.label = [eq.equipo, eq.marca, eq.modelo].filter(Boolean).join(' ');
                    datalist.appendChild(opt);
                });
            } catch (e) {
                console.error('No se pudieron cargar los equipos del cliente:', e);
            }
        };

        inputCliente.addEventListener('change', cargar);
        inputCliente.addEventListener('blur', cargar);
        cargar();
    }
};
