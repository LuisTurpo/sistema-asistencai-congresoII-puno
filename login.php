
<?php
session_start();

require_once __DIR__ . '/config/database.php';

if (isset($_SESSION['id_usuario'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = trim($_POST['correo'] ?? '');
    $contrasena = $_POST['contrasena'] ?? '';

    $sql = "SELECT id_usuario, nombre, correo,
                   password_hash, rol, estado
            FROM usuarios
            WHERE correo = ?
            LIMIT 1";

    $stmt = $conexion->prepare($sql);
    $stmt->execute([$correo]);
    $usuario = $stmt->fetch();

    if (
        $usuario &&
        $usuario['estado'] === 'ACTIVO' &&
        password_verify(
            $contrasena,
            $usuario['password_hash']
        )
    ) {
        session_regenerate_id(true);

        $_SESSION['id_usuario'] = $usuario['id_usuario'];
        $_SESSION['nombre'] = $usuario['nombre'];
        $_SESSION['rol'] = $usuario['rol'];

        header('Location: dashboard.php');
        exit;
    }

    $error = 'Correo o contraseña incorrectos.';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Iniciar sesión - Congreso Puno 2026</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">
</head>

<body class="bg-light">

<div class="container min-vh-100 d-flex
            justify-content-center align-items-center">

    <div class="card shadow p-4" style="max-width:420px;width:100%;">

        <h4 class="text-center mb-2">
            Congreso Puno 2026
        </h4>

        <p class="text-center text-muted">
            Sistema de Registro de Asistencias
        </p>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Correo electrónico</label>
                <input type="email"
                       name="correo"
                       class="form-control"
                       required>
            </div>

            <div class="mb-3">
                <label class="form-label">Contraseña</label>
                <input type="password"
                       name="contrasena"
                       class="form-control"
                       required>
            </div>

            <button type="submit"
                    class="btn btn-primary w-100">
                Iniciar sesión
            </button>
        </form>

    </div>
</div>

</body>
</html>
