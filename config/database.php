
<?php

$host = '127.0.0.1';
$puerto = '3306';
$base_datos = 'congreso_puno_2026';
$usuario = 'congreso_app';
$contrasena = 'congreso2026';

try {
    $conexion = new PDO(
        "mysql:host=$host;port=$puerto;dbname=$base_datos;charset=utf8mb4",
        $usuario,
        $contrasena,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('Error al conectar con la base de datos.');
}
