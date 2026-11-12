
<?php

// Solo permitir ejecución local desde la terminal.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acceso no permitido.');
}

require_once __DIR__ . '/config/database_test.php';

$stmt = $conexion->query(
    "SELECT DATABASE() AS base_actual"
);

$resultado = $stmt->fetch();

echo "Conexion de pruebas exitosa.\n";
echo "Base de datos: " . $resultado['base_actual'] . "\n";
