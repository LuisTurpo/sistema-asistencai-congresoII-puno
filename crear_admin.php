
<?php
// Ejecutar únicamente desde la terminal.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acceso no permitido.');
}

require_once __DIR__ . '/config/database.php';

echo "CREAR ADMINISTRADOR\n\n";

echo "Nombre completo: ";
$nombre = trim(fgets(STDIN));

echo "Correo electrónico: ";
$correo = trim(fgets(STDIN));

if ($nombre === '' ||
    !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    exit("Nombre o correo no válido.\n");
}

echo "Contraseña: ";
$contrasena = trim(fgets(STDIN));

if (strlen($contrasena) < 8) {
    exit("La contraseña debe tener al menos 8 caracteres.\n");
}

$hash = password_hash($contrasena, PASSWORD_DEFAULT);

try {
    $sql = "INSERT INTO usuarios
            (nombre, correo, password_hash, rol, estado)
            VALUES (?, ?, ?, 'ADMIN', 'ACTIVO')";

    $stmt = $conexion->prepare($sql);
    $stmt->execute([$nombre, $correo, $hash]);

    echo "\nAdministrador creado correctamente.\n";
} catch (PDOException $e) {
    echo "\nNo se pudo crear el administrador. ";
    echo "Comprueba si el correo ya está registrado.\n";
}
