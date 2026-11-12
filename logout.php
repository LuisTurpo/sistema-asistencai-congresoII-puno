
<?php
// Iniciar o recuperar la sesión actual
session_start();

// Eliminar todas las variables de sesión
$_SESSION = [];

// Eliminar la cookie de sesión, si existe
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax'
    ]);
}

// Destruir la sesión
session_destroy();

// Evitar que se almacene en caché
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");

// Redirigir al login
header("Location: login.php");
exit;
?>
