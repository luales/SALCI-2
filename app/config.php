<?php
declare(strict_types=1);

/* ---------------------------------------------------------------------
   Configuración general del prototipo
   --------------------------------------------------------------------- */

const APP_NAME  = 'SALCI';
const APP_DEBUG = true;           // en producción: false

/* Conexión a SQL Server Express por shared memory (mismo equipo).
   Si se conecta desde otro PC, habilitar TCP/IP y usar
   Server=192.168.x.x,1433 (o Server=NOMBRE_PC\SQLEXPRESS).
   PDO_SQLSRV siempre trabaja en UTF-8: no se debe indicar
   CharacterSet en el DSN (lo rechaza). */
const DB_DSN  = 'sqlsrv:Server=.\\SQLEXPRESS;Database=SALCI;TrustServerCertificate=1';
const DB_USER = null;             // null = autenticación de Windows
const DB_PASS = null;

const SESSION_NAME = 'salci_sid';
