// Periodicidad de mantenimiento/calibración + check de póliza.
// Un solo campo de meses; el check decide qué ticket automático se genera:
//   sin póliza -> ticket para contactar al cliente y recomendar el servicio
//   con póliza -> ticket de servicio programado
// Debajo del check se muestra en lenguaje claro qué va a pasar.
window.SipconsPeriodicidad = {
  // Meses guardados de un equipo, venga de póliza o de recomendación.
  mesesDe(eq) {
    if (!eq) return 0;
    const meses = eq.servicio == 1 ? (eq.frecuencia_servicio || eq.calibracion) : eq.calibracion;
    return parseInt(meses, 10) || 0;
  },

  // Texto corto para tablas: "Póliza c/6m", "Recomendar c/12m", "Sin programa".
  etiqueta(eq) {
    const meses = this.mesesDe(eq);
    if (!meses) return 'Sin programa';
    return eq.servicio == 1 ? `Póliza c/${meses}m` : `Recomendar c/${meses}m`;
  },

  enlazar(inputMeses, check, aviso) {
    if (!inputMeses || !check) return () => {};

    const actualizar = () => {
      const meses = parseInt(inputMeses.value, 10) || 0;
      const poliza = check.checked;

      inputMeses.setCustomValidity(poliza && meses <= 0
        ? 'Con póliza, indica cada cuántos meses se da el mantenimiento/calibración.'
        : '');

      if (!aviso) return;
      let icono, texto, color;
      if (meses <= 0) {
        icono = 'fa-ban'; color = '#7f8c8d';
        texto = poliza
          ? 'Indica la periodicidad en meses para programar la póliza.'
          : 'Sin periodicidad: no se generarán tickets automáticos.';
      } else if (poliza) {
        icono = 'fa-calendar-check'; color = '#1e8449';
        texto = `Con póliza: cada ${meses} mes(es) se abrirá un ticket de <strong>servicio programado</strong> (mantenimiento/calibración).`;
      } else {
        icono = 'fa-phone-alt'; color = '#b9770e';
        texto = `Sin póliza: cada ${meses} mes(es) se abrirá un ticket para <strong>contactar al cliente</strong> y recomendar mantenimiento/calibración.`;
      }
      aviso.style.color = color;
      aviso.innerHTML = `<i class="fas ${icono}"></i> ${texto}`;
    };

    inputMeses.addEventListener('input', actualizar);
    check.addEventListener('change', actualizar);
    actualizar();
    // Se regresa para refrescar el aviso tras cargar valores por código.
    return actualizar;
  }
};
