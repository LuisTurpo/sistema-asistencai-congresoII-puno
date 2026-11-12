
<?php
session_start();

date_default_timezone_set('America/Lima');

// Verificar inicio de sesión
if (!isset($_SESSION['id_usuario'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';

// Token CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Escapar texto para HTML
function escapar($valor) {
    return htmlspecialchars(
        (string)$valor,
        ENT_QUOTES,
        'UTF-8'
    );
}

// Detectar la sesión según fecha y hora de Perú
function obtenerSesionActual($conexion) {

    $ahora = new DateTimeImmutable(
        'now',
        new DateTimeZone('America/Lima')
    );

    $fecha = $ahora->format('Y-m-d');
    $hora = $ahora->format('H:i:s');

    $turno = null;

    if ($hora >= '08:00:00' && $hora < '12:00:00') {
        $turno = 'MAÑANA';
    } elseif ($hora >= '14:00:00' && $hora < '18:00:00') {
        $turno = 'TARDE';
    }

    if ($turno === null) {
        return null;
    }

    $stmt = $conexion->prepare(
        "SELECT id_sesion, codigo_sesion, fecha, turno
         FROM sesiones
         WHERE fecha = ?
           AND turno = ?
         LIMIT 1"
    );

    $stmt->execute([$fecha, $turno]);

    return $stmt->fetch() ?: null;
}

// Datos iniciales
$mensaje = '';
$tipo = '';
$participante = null;
$totalAsistencias = null;

$sesion = obtenerSesionActual($conexion);

// Procesar registro de asistencia
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(403);
        exit('Solicitud no válida.');
    }

    $dni = trim($_POST['dni'] ?? '');

    if (!preg_match('/^[0-9]{8}$/D', $dni)) {

        $mensaje = 'Ingrese un DNI válido de 8 dígitos.';
        $tipo = 'danger';

    } else {

        // Buscar participante
        $stmt = $conexion->prepare(
            "SELECT id_participante,
                    documento,
                    nombres_completos
             FROM participantes
             WHERE documento = ?
               AND estado = 'ACTIVO'
             LIMIT 1"
        );

        $stmt->execute([$dni]);
        $participante = $stmt->fetch();

        if (!$participante) {

            $mensaje = 'Participante no encontrado o inactivo.';
            $tipo = 'danger';

        } else {

            try {

                $conexion->beginTransaction();

                // Bloquear la sesión que corresponde
                // al horario actual.
                $sesionActual = obtenerSesionActual($conexion);

                if (!$sesionActual) {
                    $conexion->rollBack();

                    $mensaje =
                        'El registro está fuera del horario del congreso.';
                    $tipo = 'warning';

                } else {

                    $stmt = $conexion->prepare(
                        "SELECT id_sesion
                         FROM sesiones
                         WHERE id_sesion = ?
                         FOR UPDATE"
                    );

                    $stmt->execute([
                        $sesionActual['id_sesion']
                    ]);

                    if (!$stmt->fetch()) {
                        throw new RuntimeException(
                            'No se encontró la sesión.'
                        );
                    }

                    // Comprobar nuevamente el horario
                    // antes de guardar.
                    $sesionVerificada =
                        obtenerSesionActual($conexion);

                    if (
                        !$sesionVerificada ||
                        (int)$sesionVerificada['id_sesion'] !==
                        (int)$sesionActual['id_sesion']
                    ) {

                        $conexion->rollBack();

                        $mensaje =
                            'El horario de registro ha finalizado.';
                        $tipo = 'warning';

                    } else {

                        // Registrar asistencia
                        $stmt = $conexion->prepare(
                            "INSERT INTO asistencias
                             (id_participante,
                              id_sesion,
                              id_usuario,
                              fecha_hora)
                             VALUES (?, ?, ?, ?)"
                        );

                        $fechaHora = (new DateTimeImmutable(
                            'now',
                            new DateTimeZone('America/Lima')
                        ))->format('Y-m-d H:i:s');

                        $stmt->execute([
                            $participante['id_participante'],
                            $sesionActual['id_sesion'],
                            $_SESSION['id_usuario'],
                            $fechaHora
                        ]);

                        $conexion->commit();

                        $mensaje =
                            'Asistencia registrada correctamente en ' .
                            $sesionActual['codigo_sesion'] . '.';

                        $tipo = 'success';
                    }
                }

            } catch (PDOException $e) {

                if ($conexion->inTransaction()) {
                    $conexion->rollBack();
                }

                // Código 1062: registro duplicado
                if (
                    isset($e->errorInfo[1]) &&
                    (int)$e->errorInfo[1] === 1062
                ) {
                    $mensaje =
                        'Este participante ya registró su asistencia en esta sesión.';
                    $tipo = 'warning';
                } else {
                    error_log($e->getMessage());

                    $mensaje =
                        'Ocurrió un error al registrar la asistencia.';
                    $tipo = 'danger';
                }

            } catch (Throwable $e) {

                if ($conexion->inTransaction()) {
                    $conexion->rollBack();
                }

                error_log($e->getMessage());

                $mensaje =
                    'No se pudo completar el registro.';
                $tipo = 'danger';
            }

            // Consultar total acumulado
            $stmt = $conexion->prepare(
                "SELECT COUNT(*) AS total
                 FROM asistencias
                 WHERE id_participante = ?"
            );

            $stmt->execute([
                $participante['id_participante']
            ]);

            $totalAsistencias =
                (int)$stmt->fetchColumn();
        }
    }
}

// Actualizar sesión visible
$sesion = obtenerSesionActual($conexion);

$nombreUsuario = escapar(
    $_SESSION['nombre'] ?? 'Usuario'
);
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Registro de Asistencias - Congreso Puno 2026</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <style>
        body {
            background: #f3f6fa;
        }

        .contenedor {
            max-width: 650px;
            margin: 45px auto;
        }

        .tarjeta {
            border: none;
            border-radius: 15px;
        }

        .titulo {
            color: #17406b;
            font-weight: 700;
        }

        .dni-input {
            font-size: 26px;
            text-align: center;
            letter-spacing: 3px;
        }

        .btn-registrar {
            padding: 13px;
            font-weight: 600;
        }
    </style>
</head>

<body>

<div class="container contenedor">

    <div class="card tarjeta shadow">

        <div class="card-body p-4">

            <h3 class="titulo text-center">
                Registro de Asistencias
            </h3>

            <p class="text-center text-muted">
                II Congreso Peruano de Extensión Agropecuaria
                <br>
                Puno 2026
            </p>

            <hr>

            <p class="text-muted">
                Encargado:
                <strong><?= $nombreUsuario ?></strong>
            </p>

            <?php if ($sesion): ?>

                <div class="alert alert-info text-center">

                    <strong>Sesión actual:</strong>
                    <?= escapar($sesion['codigo_sesion']) ?>

                    <br>

                    <strong>Turno:</strong>
                    <?= escapar($sesion['turno']) ?>

                    <br>

                    <strong>Fecha:</strong>
                    <?= escapar($sesion['fecha']) ?>

                </div>

            <?php else: ?>

                <div class="alert alert-warning text-center">

                    <strong>
                        Registro de asistencia no disponible
                    </strong>

                    <br>

                    El sistema habilitará el registro
                    automáticamente durante los días
                    y horarios del congreso.

                </div>

            <?php endif; ?>

            <?php if ($mensaje): ?>

                <div class="alert alert-<?= escapar($tipo) ?>">

                    <strong>
                        <?= escapar($mensaje) ?>
                    </strong>

                    <?php if ($participante): ?>

                        <hr>

                        <strong>Participante:</strong>

                        <?= escapar(
                            $participante['nombres_completos']
                        ) ?>

                        <br>

                        <strong>DNI:</strong>

                        <?= escapar(
                            $participante['documento']
                        ) ?>

                        <?php if ($totalAsistencias !== null): ?>

                            <br>

                            <strong>
                                Asistencias acumuladas:
                            </strong>

                            <?= $totalAsistencias ?> de 8

                            <?php if ($totalAsistencias >= 5): ?>

                                <div class="mt-2 text-success">
                                    Cumple el mínimo de
                                    asistencias para certificado.
                                </div>

                            <?php endif; ?>

                        <?php endif; ?>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

            <form method="POST" id="formAsistencia">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escapar(
                        $_SESSION['csrf_token']
                    ) ?>">

                <div class="mb-3">

                    <label class="form-label fw-bold">
                        DNI del participante
                    </label>

                    <input
                        type="text"
                        name="dni"
                        id="dni"
                        class="form-control form-control-lg dni-input"
                        maxlength="8"
                        minlength="8"
                        pattern="[0-9]{8}"
                        inputmode="numeric"
                        autocomplete="off"
                        placeholder="Ingrese DNI"
                        autofocus
                        required
                        <?= !$sesion ? 'disabled' : '' ?>>

                </div>

                <button
                    type="submit"
                    id="btnRegistrar"
                    class="btn btn-primary btn-lg w-100 btn-registrar"
                    <?= !$sesion ? 'disabled' : '' ?>>

                    Registrar asistencia

                </button>

            </form>

            <div class="text-center mt-3">

                <small class="text-muted">
                    Horario de registro:
                    <br>
                    Mañana: 8:00 a. m. - 12:00 p. m.
                    <br>
                    Tarde: 2:00 p. m. - 6:00 p. m.
                </small>

            </div>

            <hr>

            <a href="dashboard.php"
               class="btn btn-outline-secondary w-100">

                Volver al panel

            </a>

        </div>

    </div>

</div>

<script>
const formulario = document.getElementById('formAsistencia');
const boton = document.getElementById('btnRegistrar');
const dni = document.getElementById('dni');

if (dni && !dni.disabled) {
    dni.focus();
}

formulario.addEventListener('submit', function () {
    if (formulario.checkValidity()) {
        boton.disabled = true;
        boton.textContent = 'Registrando asistencia...';
    }
});
</script>

</body>
</html>
