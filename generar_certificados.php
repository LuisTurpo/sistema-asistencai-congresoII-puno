
<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header('Location: login.php');
    exit;
}

if (($_SESSION['rol'] ?? '') !== 'ADMIN') {
    http_response_code(403);
    exit('Acceso denegado.');
}

require_once __DIR__ . '/config/database.php';

function escapar($valor) {
    return htmlspecialchars(
        (string)$valor,
        ENT_QUOTES,
        'UTF-8'
    );
}

// Consultar participantes activos y sus asistencias.
$sql = "
    SELECT
        p.id_participante,
        p.documento,
        p.nombres_completos,
        p.codigo_inscripcion,
        COUNT(DISTINCT a.id_sesion) AS total_asistencias,
        c.id_certificado,
        c.codigo_verificacion,
        c.estado AS estado_certificado
    FROM participantes p
    LEFT JOIN asistencias a
        ON p.id_participante = a.id_participante
    LEFT JOIN certificados c
        ON p.id_participante = c.id_participante
    WHERE p.estado = 'ACTIVO'
    GROUP BY
        p.id_participante,
        p.documento,
        p.nombres_completos,
        p.codigo_inscripcion,
        c.id_certificado,
        c.codigo_verificacion,
        c.estado
    ORDER BY p.nombres_completos
";

$stmt = $conexion->query($sql);
$participantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalAptos = 0;
$totalNoAptos = 0;
$totalEmitidos = 0;

foreach ($participantes as $p) {
    if ((int)$p['total_asistencias'] >= 5) {
        $totalAptos++;
    } else {
        $totalNoAptos++;
    }

    if (
        $p['id_certificado'] !== null &&
        $p['estado_certificado'] === 'VIGENTE'
    ) {
        $totalEmitidos++;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Certificados - Congreso Puno 2026</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <style>
        body {
            background: #f3f6fa;
        }

        .encabezado {
            background: #17406b;
            color: white;
            padding: 24px;
            border-radius: 12px;
        }

        .card {
            border: none;
            border-radius: 12px;
        }
    </style>
</head>

<body>

<div class="container py-4">

    <div class="encabezado mb-4">
        <h3>Gestión de certificados</h3>
        <p class="mb-0">
            II Congreso Peruano de Extensión Agropecuaria
            - Puno 2026
        </p>
    </div>

    <div class="row g-3 mb-4">

        <div class="col-md-4">
            <div class="card shadow-sm p-4">
                <h6>Participantes aptos</h6>
                <h2 class="text-success">
                    <?= $totalAptos ?>
                </h2>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm p-4">
                <h6>Participantes no aptos</h6>
                <h2 class="text-warning">
                    <?= $totalNoAptos ?>
                </h2>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm p-4">
                <h6>Certificados emitidos</h6>
                <h2 class="text-primary">
                    <?= $totalEmitidos ?>
                </h2>
            </div>
        </div>

    </div>

    <div class="card shadow-sm p-4">

        <h5>Listado de participantes</h5>

        <div class="table-responsive">

            <table class="table table-striped table-bordered">

                <thead class="table-dark">
                    <tr>
                        <th>Documento</th>
                        <th>Nombres completos</th>
                        <th>Asistencias</th>
                        <th>Condición</th>
                        <th>Certificado</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($participantes as $p): ?>

                        <?php
                        $asistencias =
                            (int)$p['total_asistencias'];

                        $apto = $asistencias >= 5;
                        ?>

                        <tr>
                            <td>
                                <?= escapar($p['documento']) ?>
                            </td>

                            <td>
                                <?= escapar($p['nombres_completos']) ?>
                            </td>

                            <td>
                                <?= $asistencias ?> / 8
                            </td>

                            <td>
                                <?php if ($apto): ?>
                                    <span class="badge bg-success">
                                        APTO
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">
                                        NO APTO
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (
                                    $p['id_certificado'] !== null &&
                                    $p['estado_certificado'] === 'VIGENTE'
                                ): ?>

                                    <span class="badge bg-primary">
                                        EMITIDO
                                    </span>

                                <?php elseif ($apto): ?>

                                    <span class="badge bg-info text-dark">
                                        PENDIENTE
                                    </span>

                                <?php else: ?>

                                    <span class="badge bg-secondary">
                                        NO DISPONIBLE
                                    </span>

                                <?php endif; ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                </tbody>
            </table>

        </div>

        <a href="dashboard.php"
           class="btn btn-outline-secondary mt-3">
            Volver al panel
        </a>

    </div>

</div>
</body>
</html>
