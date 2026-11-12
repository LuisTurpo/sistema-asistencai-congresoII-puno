
<?php

require_once __DIR__ . '/config/database_test.php';

function escapar($valor) {
    return htmlspecialchars(
        (string)$valor,
        ENT_QUOTES,
        'UTF-8'
    );
}

$codigo = trim($_GET['codigo'] ?? '');
$certificado = null;

if ($codigo !== '' && strlen($codigo) <= 100) {

    $sql = "
        SELECT
            c.codigo_verificacion,
            c.fecha_emision,
            c.estado,
            p.nombres_completos,
            COUNT(DISTINCT a.id_sesion) AS total_asistencias
        FROM certificados c
        INNER JOIN participantes p
            ON c.id_participante = p.id_participante
        LEFT JOIN asistencias a
            ON p.id_participante = a.id_participante
        WHERE c.codigo_verificacion = ?
        GROUP BY
            c.id_certificado,
            c.codigo_verificacion,
            c.fecha_emision,
            c.estado,
            p.nombres_completos
    ";

    $stmt = $conexion->prepare($sql);
    $stmt->execute([$codigo]);

    $certificado = $stmt->fetch(PDO::FETCH_ASSOC);
}

$valido = (
    $certificado &&
    $certificado['estado'] === 'VIGENTE' &&
    (int)$certificado['total_asistencias'] >= 5
);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Verificar certificado - Congreso Puno 2026</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <style>
        body {
            background: #f3f6fa;
        }

        .contenedor {
            max-width: 650px;
            margin: 70px auto;
        }

        .encabezado {
            background: #17406b;
            color: white;
            padding: 25px;
            border-radius: 12px 12px 0 0;
        }

        .card {
            border: none;
            border-radius: 12px;
            overflow: hidden;
        }
    </style>
</head>

<body>

<div class="container contenedor">

    <div class="card shadow">

        <div class="encabezado text-center">
            <h3>Verificación de certificados</h3>
            <p class="mb-0">
                II Congreso Peruano de Extensión Agropecuaria
                - Puno 2026
            </p>
        </div>

        <div class="card-body p-4">

            <form method="GET" class="mb-4">

                <label class="form-label">
                    Código de verificación
                </label>

                <div class="input-group">
                    <input
                        type="text"
                        name="codigo"
                        class="form-control"
                        value="<?= escapar($codigo) ?>"
                        maxlength="100"
                        required>

                    <button
                        class="btn btn-primary"
                        type="submit">
                        Verificar
                    </button>
                </div>

            </form>

            <?php if ($codigo !== ''): ?>

                <?php if ($valido): ?>

                    <div class="alert alert-success text-center">
                        <h4>✓ CERTIFICADO VÁLIDO</h4>
                        <p class="mb-0">
                            El certificado se encuentra registrado
                            y vigente.
                        </p>
                    </div>

                    <h5>Datos del certificado</h5>

                    <table class="table table-bordered">

                        <tr>
                            <th>Participante</th>
                            <td>
                                <?= escapar(
                                    $certificado['nombres_completos']
                                ) ?>
                            </td>
                        </tr>

                        <tr>
                            <th>Evento</th>
                            <td>
                                II Congreso Peruano de
                                Extensión Agropecuaria
                            </td>
                        </tr>

                        <tr>
                            <th>Asistencias</th>
                            <td>
                                <?= (int)$certificado['total_asistencias'] ?>
                                de 8
                            </td>
                        </tr>

                        <tr>
                            <th>Fecha de emisión</th>
                            <td>
                                <?= escapar(
                                    $certificado['fecha_emision']
                                ) ?>
                            </td>
                        </tr>

                        <tr>
                            <th>Código</th>
                            <td>
                                <?= escapar(
                                    $certificado['codigo_verificacion']
                                ) ?>
                            </td>
                        </tr>

                    </table>

                <?php elseif ($certificado): ?>

                    <div class="alert alert-warning text-center">
                        <h4>Certificado no vigente</h4>
                        <p class="mb-0">
                            Este certificado no cumple las
                            condiciones de validación actuales.
                        </p>
                    </div>

                <?php else: ?>

                    <div class="alert alert-danger text-center">
                        <h4>Certificado no encontrado</h4>
                        <p class="mb-0">
                            El código ingresado no corresponde
                            a un certificado registrado.
                        </p>
                    </div>

                <?php endif; ?>

            <?php endif; ?>

            <div class="text-center mt-3">
                <small class="text-muted">
                    Sistema de verificación de prueba.
                    No corresponde a certificados oficiales.
                </small>
            </div>

        </div>
    </div>

</div>

</body>
</html>
