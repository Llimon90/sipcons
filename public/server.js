// Importar dependencias
const express = require('express');
const mysql = require('mysql');
const cors = require('cors');

// Crear una instancia de Express
const app = express();

// Usar CORS para permitir solicitudes desde tu frontend
app.use(cors());

// Usar JSON en el cuerpo de las solicitudes
app.use(express.json());

// Establecer la conexión a la base de datos
const conexion = mysql.createConnection({
  host: 'localhost',
  database: 'u179371012_sipcons',
  user: 'u179371012_root',
  password: 'Weruzco17@',
});

// Conectar a la base de datos
conexion.connect(function (err) {
  if (err) {
    console.error('Error de conexión a la base de datos:', err);
    return;
  }
  console.log('Conexión exitosa a la base de datos');
});

// Ruta para crear una nueva incidencia con número incremental
app.post('/nueva-incidencia', (req, res) => {
  const { cliente, contacto, sucursal, fecha, tecnico, status, falla } = req.body;

  // Validar campos obligatorios
  if (!cliente || !contacto || !sucursal || !fecha || !tecnico || !status || !falla) {
    return res.status(400).json({ error: 'Todos los campos son obligatorios' });
  }

  // Obtener el último número generado
  const consultaUltimoNumero = `
    SELECT numero FROM incidencias ORDER BY id DESC LIMIT 1
  `;

  conexion.query(consultaUltimoNumero, (error, results) => {
    if (error) {
      console.error('Error al obtener el último número:', error);
      return res.status(500).json({ error: 'Error al obtener el último número' });
    }

    let nuevoNumero = 'SIP-0001'; // Número inicial si no hay registros previos

    if (results.length > 0) {
      const ultimoNumero = results[0].numero; // Ejemplo: "SIP-0001"
      const numeroIncremental = parseInt(ultimoNumero.split('-')[1]) + 1;

      // Generar el nuevo número con formato "SIP-XXXX"
      nuevoNumero = `SIP-${numeroIncremental.toString().padStart(4, '0')}`;
    }

    // Query para insertar la nueva incidencia
    const nuevaIncidencia = `
      INSERT INTO incidencias (numero, cliente, contacto, sucursal, fecha, tecnico, estatus, falla)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    `;

    const valores = [nuevoNumero, cliente, contacto, sucursal, fecha, tecnico, status, falla];

    conexion.query(nuevaIncidencia, valores, (error, result) => {
      if (error) {
        console.error('Error al insertar la incidencia:', error);
        return res.status(500).json({ error: 'Error al insertar la incidencia' });
      }

      res.status(201).json({ 
        message: 'Incidencia registrada correctamente', 
        numero: nuevoNumero, 
        id: result.insertId 
      });
    });
  });
});

// Iniciar el servidor
const PORT = 3000;
app.listen(PORT, () => {
  console.log(`Servidor escuchando en el puerto ${PORT}`);
});


//BLOQUE QUE OBTIENE Y SIEMBRA CONSECUTIVO DE INCIDENCIA EN FORMULARIO
