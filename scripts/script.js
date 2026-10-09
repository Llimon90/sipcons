// Registro de nuevas incidencias (incidencias.html).
// Envía los datos y los archivos del cliente en un solo paso; el resultado se
// muestra en un modal (dialogo.js). Si algo falla, el formulario se conserva
// tal como estaba para poder corregir o reintentar.
const form = document.getElementById('new-incidencia-form');
const btnRegistrar = document.getElementById('btn-registrar');
const btnActualizar = document.getElementById('btn-actualizar');
const btnEliminar = document.getElementById('btn-eliminar');

// Establece fecha actual al cargar
document.getElementById('fecha').value = new Date().toISOString().split('T')[0];

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

// Resumen de lo que quedó guardado en la base (lo devuelve el servidor tras releerlo)
function resumenRegistrado(resultado) {
  const inc = resultado.incidencia;
  if (!inc) return null;
  const recortar = (texto, max = 90) => {
    const t = String(texto || '').replace(/\s+/g, ' ').trim();
    return t.length > max ? t.slice(0, max - 1) + '…' : t;
  };
  const filas = [
    ['Cliente', inc.cliente],
    ['Reporta', inc.contacto],
    ['Sucursal', inc.sucursal],
    ['Fecha', inc.fecha ? inc.fecha.split('-').reverse().join('/') : ''],
    ['Falla', inc.falla],
    ['Técnico', inc.tecnico],
    ['Estatus', inc.estatus]
  ].filter(([, valor]) => String(valor || '').trim())
   .map(([nombre, valor]) => `${nombre}: ${recortar(valor)}`);
  filas.push(`Archivos adjuntos: ${inc.archivos || 0}`);
  return filas;
}

function marcarRegistrando(activo) {
  registrando = activo;
  if (activo) {
    btnRegistrar.dataset.textoOriginal = btnRegistrar.innerHTML;
    btnRegistrar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Registrando…';
    btnRegistrar.classList.add('guardando');
    btnRegistrar.disabled = true;
  } else {
    btnRegistrar.innerHTML = btnRegistrar.dataset.textoOriginal || 'Registrar Incidencia';
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
  selectorArchivos.archivos().forEach(archivo => datos.append('archivos[]', archivo));

  marcarRegistrando(true);
  const inicio = performance.now();
  let respuesta = null;
  let resultado = null;
  let sinConexion = false;

  try {
    respuesta = await fetch('../backend/server.php', {
      method: 'POST',
      body: datos,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    });
    const texto = await respuesta.text();
    try { resultado = JSON.parse(texto); } catch (e) { resultado = null; }
  } catch (error) {
    sinConexion = true;
  } finally {
    marcarRegistrando(false);
  }

  const ms = performance.now() - inicio;
  const estado = respuesta ? respuesta.status : 0;
  const sesionCerrada = estado === 401 || (respuesta && respuesta.redirected && /login/i.test(respuesta.url));

  if (resultado && resultado.success) {
    registrarEnAnaliticas(true, '', ms, estado, resultado.numero_incidente);
    hayDatosSinRegistrar = false;
    const eleccion = await SipconsDialogo.mostrar({
      tipo: 'ok',
      titulo: 'Incidencia registrada',
      mensaje: `La incidencia ${resultado.numero_incidente} se registró correctamente${resultado.guardado_en ? ` el ${formatearFechaHora(resultado.guardado_en)}` : ''}.`,
      lista: resumenRegistrado(resultado),
      nota: 'Estos son los datos que quedaron guardados.',
      botones: [
        { texto: 'Registrar otra', valor: 'otra', estilo: 'secundario' },
        { texto: 'Abrir incidencia', valor: 'abrir', estilo: 'primario' }
      ]
    });
    if (eleccion === 'abrir' && resultado.id) {
      window.location.href = `detalle.html?id=${encodeURIComponent(resultado.id)}`;
    } else {
      window.location.reload();
    }
    return;
  }

  const motivo = sinConexion ? 'sin_conexion' : sesionCerrada ? 'sesion_cerrada'
    : (resultado && resultado.motivo) || `respuesta_invalida_${estado}`;
  registrarEnAnaliticas(false, motivo, ms, estado);

  if (sesionCerrada) {
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
  if (sinConexion) {
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
