// Registro de nuevas incidencias (incidencias.html).
// Envía los datos y los archivos del cliente en un solo paso (las fotos se
// reducen antes de subirlas y el botón muestra el avance); el resultado se
// muestra en un modal (dialogo.js). Si algo falla, el formulario se conserva
// tal como estaba para poder corregir o reintentar.
const form = document.getElementById('new-incidencia-form');
const btnRegistrar = document.getElementById('btn-registrar');
const btnActualizar = document.getElementById('btn-actualizar');
const btnEliminar = document.getElementById('btn-eliminar');

function fechaDeHoy() {
  // Fecha local (toISOString daría la de UTC: después de las 4-5 p. m. en Tijuana sería "mañana")
  const hoy = new Date();
  const dos = n => String(n).padStart(2, '0');
  return `${hoy.getFullYear()}-${dos(hoy.getMonth() + 1)}-${dos(hoy.getDate())}`;
}

// Establece fecha actual al cargar
document.getElementById('fecha').value = fechaDeHoy();

// Selector de imágenes/archivos del cliente
const selectorArchivos = SipconsArchivos.crear(document.getElementById('archivos-nuevos'), {
  texto: 'Toca para agregar fotos o archivos del cliente'
});
SipconsArchivos.cargarLimites().then(limites => selectorArchivos.establecerLimites(limites));

let registrando = false;

// Aviso al salir si hay datos capturados que no se han registrado
let hayDatosSinRegistrar = false;
form.addEventListener('input', () => { hayDatosSinRegistrar = true; });
form.addEventListener('change', () => { hayDatosSinRegistrar = true; });
window.addEventListener('beforeunload', (e) => {
  if (!hayDatosSinRegistrar || registrando) return;
  e.preventDefault();
  e.returnValue = '';
});

function formatearFechaHora(valor) {
  const fecha = new Date(String(valor).replace(' ', 'T'));
  if (isNaN(fecha)) return '';
  const dos = n => String(n).padStart(2, '0');
  return `${dos(fecha.getDate())}/${dos(fecha.getMonth() + 1)}/${fecha.getFullYear()} a las ${dos(fecha.getHours())}:${dos(fecha.getMinutes())}`;
}

function textoBoton(html) {
  btnRegistrar.innerHTML = html;
}

function marcarRegistrando(activo) {
  registrando = activo;
  if (activo) {
    btnRegistrar.dataset.textoOriginal = btnRegistrar.innerHTML;
    textoBoton('<i class="fas fa-spinner fa-spin"></i> Registrando…');
    btnRegistrar.classList.add('guardando');
    btnRegistrar.disabled = true;
  } else {
    textoBoton(btnRegistrar.dataset.textoOriginal || 'Registrar Incidencia');
    btnRegistrar.classList.remove('guardando');
    btnRegistrar.disabled = false;
  }
}

function registrarEnAnaliticas(correcto, motivo, ms, estado, folio) {
  try {
    if (!window.sipconsTrack) return;
    window.sipconsTrack('guardado',
      correcto ? 'Registro correcto' : `Registro fallido: ${motivo}`,
      `${correcto ? 'ok' : 'error'} · ${folio || 'nueva incidencia'}`, ms, estado);
    if (window.sipconsFlush) window.sipconsFlush();
  } catch (e) { /* las analíticas nunca deben estorbar */ }
}

// Deja el formulario listo para otra incidencia sin recargar la página
function limpiarFormulario() {
  form.reset();
  selectorArchivos.limpiar();
  document.getElementById('fecha').value = fechaDeHoy();
  hayDatosSinRegistrar = false;
  if (window.recargarListaIncidencias) window.recargarListaIncidencias();
}

async function registrarIncidencia() {
  if (registrando) return;

  const problema = selectorArchivos.revisar();
  if (problema) {
    await SipconsDialogo.mostrar({
      tipo: 'aviso',
      titulo: 'Revisa los archivos',
      mensaje: problema.mensaje,
      lista: problema.lista,
      nota: 'Todavía no se registró la incidencia. Lo que capturaste sigue en pantalla.',
      botones: [{ texto: 'Entendido', valor: 'ok', estilo: 'primario' }]
    });
    return;
  }

  marcarRegistrando(true);
  const inicio = performance.now();

  const datos = new FormData();
  datos.append('reporte_cliente', document.getElementById('reporte_cliente').value);
  datos.append('cliente', document.getElementById('cliente').value);
  datos.append('contacto', document.getElementById('contacto').value);
  datos.append('sucursal', document.getElementById('sucursal').value);
  datos.append('categoria', document.getElementById('categoria').value);
  datos.append('numero_serie', (document.getElementById('numero_serie')?.value || '').trim());
  datos.append('falla', document.getElementById('falla').value);
  datos.append('fecha', document.getElementById('fecha').value);
  datos.append('tecnico', document.getElementById('tecnico').value);
  datos.append('status', document.getElementById('estatus').value);
  datos.append('notas', document.getElementById('notas').value);

  const elegidos = selectorArchivos.archivos();
  if (elegidos.length) {
    textoBoton('<i class="fas fa-spinner fa-spin"></i> Preparando archivos…');
    const listos = await SipconsArchivos.prepararParaEnvio(elegidos);
    listos.forEach(archivo => datos.append('archivos[]', archivo));
  }

  const respuesta = await SipconsArchivos.enviarConAvance('../backend/server.php', datos, (porcentaje) => {
    textoBoton(porcentaje < 100 && elegidos.length
      ? `<i class="fas fa-spinner fa-spin"></i> Subiendo… ${porcentaje}%`
      : '<i class="fas fa-spinner fa-spin"></i> Registrando…');
  });
  marcarRegistrando(false);

  const ms = performance.now() - inicio;
  const resultado = respuesta.datos;

  if (resultado && resultado.success) {
    registrarEnAnaliticas(true, '', ms, respuesta.estado, resultado.numero_incidente);
    hayDatosSinRegistrar = false;
    const adjuntos = (resultado.archivos_agregados || []).length;
    const eleccion = await SipconsDialogo.mostrar({
      tipo: 'ok',
      titulo: 'Incidencia registrada',
      mensaje: `La incidencia ${resultado.numero_incidente} se registró correctamente${resultado.guardado_en ? ` el ${formatearFechaHora(resultado.guardado_en)}` : ''}.`,
      nota: adjuntos ? `Se ${adjuntos === 1 ? 'adjuntó 1 archivo' : `adjuntaron ${adjuntos} archivos`}.` : '',
      botones: [
        { texto: 'Registrar otra', valor: 'otra', estilo: 'secundario' },
        { texto: 'Abrir incidencia', valor: 'abrir', estilo: 'primario' }
      ]
    });
    if (eleccion === 'abrir' && resultado.id) {
      window.location.href = `detalle.html?id=${encodeURIComponent(resultado.id)}`;
    } else {
      limpiarFormulario();
    }
    return;
  }

  const motivo = respuesta.sinConexion ? 'sin_conexion' : respuesta.sesionCerrada ? 'sesion_cerrada'
    : (resultado && resultado.motivo) || `respuesta_invalida_${respuesta.estado}`;
  registrarEnAnaliticas(false, motivo, ms, respuesta.estado);

  if (respuesta.sesionCerrada) {
    const eleccion = await SipconsDialogo.mostrar({
      tipo: 'aviso',
      titulo: 'Tu sesión se cerró',
      mensaje: 'Para registrar la incidencia, vuelve a iniciar sesión. Lo que capturaste sigue en pantalla y no se perderá.',
      botones: [
        { texto: 'Cerrar', valor: null, estilo: 'secundario' },
        { texto: 'Iniciar sesión', valor: 'login', estilo: 'primario' }
      ]
    });
    if (eleccion === 'login') {
      window.open('../auth/login.html', '_blank');
      const siguiente = await SipconsDialogo.mostrar({
        tipo: 'info',
        titulo: 'Inicia sesión y regresa',
        mensaje: 'Se abrió una pestaña para iniciar sesión. Cuando termines, vuelve a esta pestaña y presiona "Registrar ahora".',
        botones: [
          { texto: 'Cerrar', valor: null, estilo: 'secundario' },
          { texto: 'Registrar ahora', valor: 'reintentar', estilo: 'primario' }
        ]
      });
      if (siguiente === 'reintentar') registrarIncidencia();
    }
    return;
  }

  let mensaje;
  if (respuesta.sinConexion) {
    mensaje = 'No hubo respuesta del servidor. Revisa tu conexión a internet e inténtalo de nuevo.';
  } else if (resultado && (resultado.error || resultado.message)) {
    mensaje = resultado.error || resultado.message;
  } else {
    mensaje = 'El servidor respondió de forma inesperada y no se pudo confirmar el registro.';
  }

  const sinReintento = ['envio_muy_grande', 'archivo_muy_grande', 'archivo_no_permitido', 'faltan_datos', 'sin_permiso'];
  const botones = [{ texto: 'Cerrar', valor: null, estilo: 'secundario' }];
  if (!sinReintento.includes(motivo)) botones.push({ texto: 'Intentar de nuevo', valor: 'reintentar', estilo: 'primario' });

  const eleccion = await SipconsDialogo.mostrar({
    tipo: 'error',
    titulo: 'No se registró la incidencia',
    mensaje,
    nota: 'Lo que capturaste sigue en pantalla; no se perdió nada.',
    botones
  });
  if (eleccion === 'reintentar') registrarIncidencia();
}

form.addEventListener('submit', function (event) {
  event.preventDefault();
  registrarIncidencia();
});
