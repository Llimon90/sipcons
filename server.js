// Importar dependencias
const express = require('express');
const cors = require('cors');
const mysql = require('mysql');

// Crear una instancia de Express
const app = express();

// Usar middlewares
app.use(cors()); // Permitir solicitudes desde el frontend
app.use(express.json()); // Parsear JSON en el cuerpo de las solicitudes

// Conectar a la base de datos MySQL
const conexion = mysql.createConnection({
  host: 'localhost',
  user: 'root',
  password: 'Llimon',
  database: 'sipcons',
  port: 3306
});

conexion.connect((err) => {
  if (err) {
    console.error('Error al conectar a la base de datos:', err);
    return;
  }
  console.log('Conectado a la base de datos');
});

// Ruta para registrar una nueva incidencia
app.post('/nueva-incidencia', (req, res) => {
  const { cliente, contacto, sucursal, fecha, tecnico, status, falla } = req.body;

  // Validar que todos los campos sean enviados
  if (!cliente || !contacto || !sucursal || !fecha || !tecnico || !status || !falla) {
    return res.status(400).json({ error: 'Todos los campos son obligatorios' });
  }

  // Consultar el último número_incidente generado
  const consultaUltimoNumero = `
    SELECT numero_incidente FROM incidencias ORDER BY id DESC LIMIT 1
  `;

  conexion.query(consultaUltimoNumero, (error, results) => {
    if (error) {
      console.error('Error al obtener el último número:', error);
      return res.status(500).json({ error: 'Error al obtener el último número' });
    }

    let nuevoNumeroIncidente = 'SIP-0001'; // Número inicial si no hay registros previos

    if (results.length > 0) {
      const ultimoNumero = results[0].numero_incidente; // Ejemplo: "SIP-0001"
      const numeroIncremental = parseInt(ultimoNumero.split('-')[1]) + 1;

      // Generar el nuevo número con formato "SIP-XXXX"
      nuevoNumeroIncidente = `SIP-${numeroIncremental.toString().padStart(4, '0')}`;
    }

    // Insertar la nueva incidencia
    const nuevaIncidencia = `
      INSERT INTO incidencias (numero, cliente, contacto, sucursal, fecha, tecnico, estatus, falla, numero_incidente)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    `;

    const valores = ['CLIENTE-MANUAL', cliente, contacto, sucursal, fecha, tecnico, status, falla, nuevoNumeroIncidente];

    conexion.query(nuevaIncidencia, valores, (error, result) => {
      if (error) {
        console.error('Error al insertar la incidencia:', error);
        return res.status(500).json({ error: 'Error al insertar la incidencia' });
      }

      res.status(201).json({ 
        message: 'Incidencia registrada correctamente', 
        numero_incidente: nuevoNumeroIncidente, 
        id: result.insertId 
      });
    });
  });
});

// Iniciar el servidor en el puerto 3000
const PORT = 3000;
app.listen(PORT, () => {
  console.log(`Servidor corriendo en http://localhost:${PORT}`);
});
