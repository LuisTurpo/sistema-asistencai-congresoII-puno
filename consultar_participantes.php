
<?php
session_start();

date_default_timezone_set('America/Lima');

// ==========================================
// 1. SEGURIDAD
// ==========================================

if (!isset($_SESSION['id_usuario'])) {
    header('Location: login.php');
    exit;
}

// ==========================================
// 2. CONEXIÓN A BASE DE DATOS DE PRUEBAS
// ==========================================

require_once __DIR__ . '/config/database_test.php';

if (
    !isset($conexion) ||
    !($conexion instanceof PDO)
) {
    exit('Error: no se encontró la conexión.');
}

$conexion->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);

if (
    $conexion->query('SELECT DATABASE()')->fetchColumn()
    !== 'congreso_puno_pruebas'
) {
    http_response_code(500);
    exit('Seguridad: base de datos incorrecta.');
}

// ==========================================
// 3. FUNCIONES
// ==========================================

function escapar($valor)
{
    return htmlspecialchars(
        (string)($valor ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

// ==========================================
// 4. CONFIGURACIÓN DE CERTIFICACIÓN
// ==========================================

// Regla temporal: 5 asistencias.
// Se revisará con el programa definitivo.

$minimoAsistencias = 5;

// Total de sesiones configuradas en MySQL.

$totalSesiones = (int)$conexion->query("
    SELECT COUNT(*)
    FROM sesiones
")->fetchColumn();

// ==========================================
// 5. CONSULTAR PARTICIPANTES
// ==========================================

$busqueda = trim($_GET['buscar'] ?? '');

$participantes = [];

if ($busqueda !== '') {

    $sql = "
        SELECT
            p.id_participante,
            p.documento,
            p.nombres_completos,
            p.correo,
            p.codigo_inscripcion,
            p.institucion,
            p.estado,

            (
                SELECT COUNT(DISTINCT a.id_sesion)
                FROM asistencias a
                WHERE a.id_participante = p.id_participante
            ) AS total_asistencias,

            c.id_certificado,
            c.codigo_verificacion,
            c.estado AS estado_certificado,

            (
                SELECT COUNT(*)
                FROM envios_certificados ec
                WHERE ec.id_certificado = c.id_certificado
                  AND ec.estado = 'ENVIADO'
            ) AS total_envios

        FROM participantes p

        LEFT JOIN certificados c
            ON c.id_participante = p.id_participante

        WHERE
            p.documento LIKE :documento
            OR p.nombres_completos LIKE :nombre
            OR p.codigo_inscripcion LIKE :codigo

        ORDER BY p.nombres_completos ASC

        LIMIT 100
    ";

    $stmt = $conexion->prepare($sql);

    $termino = '%' . $busqueda . '%';

    $stmt->execute([
        ':documento' => $termino,
        ':nombre' => $termino,
        ':codigo' => $termino
    ]);

    $participantes = $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}

// ==========================================
// 6. ESTADÍSTICAS DE RESULTADOS
// ==========================================

$totalResultados = count($participantes);

$aptosEncontrados = 0;
$certificadosEncontrados = 0;
$correosEnviados = 0;

foreach ($participantes as $p) {

    $esApto =
        $p['estado'] === 'ACTIVO' &&
        (int)$p['total_asistencias'] >= $minimoAsistencias;

    if ($esApto) {
        $aptosEncontrados++;
    }

    if (
        $p['id_certificado'] !== null &&
        $p['estado_certificado'] === 'VIGENTE'
    ) {
        $certificadosEncontrados++;
    }

    if ((int)$p['total_envios'] > 0) {
        $correosEnviados++;
    }
}

?>

<!DOCTYPE html>
<html lang="es">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>
    Consulta de Participantes - Congreso Puno 2026
</title>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    rel="stylesheet"
>

<style>

body {
    background: #f3f6fa;
    font-family: Arial, sans-serif;
}

.encabezado {
    background: linear-gradient(
        120deg,
        #17406b,
        #24659b
    );
    color: white;
    padding: 25px;
    border-radius: 15px;
}

.tarjeta {
    border: none;
    border-radius: 15px;
}

.titulo {
    color: #17406b;
    font-weight: 700;
}

.numero {
    font-size: 26px;
    font-weight: 700;
}

.table thead th {
    background: #17406b;
    color: white;
    white-space: nowrap;
}

.table td {
    vertical-align: middle;
}

.nombre {
    font-weight: 600;
    color: #24364a;
}

.correo {
    font-size: 12px;
    color: #6c757d;
    overflow-wrap: anywhere;
}

.badge {
    font-size: 12px;
    padding: 7px 9px;
}

.btn-principal {
    background: #17406b;
    color: white;
    border: none;
}

.btn-principal:hover {
    background: #102f50;
    color: white;
}

</style>

</head>

<body>

<div class="container-fluid px-3 px-lg-5 py-4">

    <!-- =====================================
         ENCABEZADO
    ====================================== -->

    <div class="encabezado mb-4 shadow-sm">

        <div class="d-flex justify-content-between
                    align-items-center flex-wrap gap-3">

            <div>

                <h3 class="fw-bold mb-2">
                    Consulta de Participantes
                </h3>

                <p class="mb-1">
                    II Congreso Peruano de Extensión Agropecuaria
                </p>

                <small>
                    Puno 2026
                </small>

                <div class="mt-2">

                    <span class="badge bg-warning text-dark">
                        MODO PRUEBAS
                    </span>

                </div>

            </div>

            <a
                href="dashboard.php"
                class="btn btn-light"
            >
                <i class="bi bi-arrow-left"></i>
                Volver al panel
            </a>

        </div>

    </div>

    <!-- =====================================
         BUSCADOR
    ====================================== -->

    <div class="card tarjeta shadow-sm mb-4">

        <div class="card-body p-4">

            <h5 class="titulo mb-3">
                <i class="bi bi-search me-2"></i>
                Buscar participante
            </h5>

            <form
                method="GET"
                class="row g-3"
            >

                <div class="col-md-9">

                    <input
                        type="text"
                        name="buscar"
                        class="form-control form-control-lg"
                        placeholder="Ingrese DNI, nombre o código de inscripción"
                        value="<?= escapar($busqueda) ?>"
                        maxlength="200"
                        required
                    >

                </div>

                <div class="col-md-3">

                    <button
                        type="submit"
                        class="btn btn-principal btn-lg w-100"
                    >
                        <i class="bi bi-search me-1"></i>
                        Buscar
                    </button>

                </div>

            </form>

            <p class="text-muted small mt-3 mb-0">

                Puede buscar utilizando el documento
                de identidad, nombres completos o
                código de inscripción.

            </p>

        </div>

    </div>

    <?php if ($busqueda !== ''): ?>

        <!-- =====================================
             ESTADÍSTICAS DE BÚSQUEDA
        ====================================== -->

        <div class="row g-3 mb-4">

            <div class="col-md-3">

                <div class="card tarjeta shadow-sm">

                    <div class="card-body">

                        <small class="text-muted">
                            Resultados encontrados
                        </small>

                        <div class="numero">
                            <?= $totalResultados ?>
                        </div>

                    </div>

                </div>

            </div>

            <div class="col-md-3">

                <div class="card tarjeta shadow-sm">

                    <div class="card-body">

                        <small class="text-muted">
                            Participantes aptos
                        </small>

                        <div class="numero text-success">
                            <?= $aptosEncontrados ?>
                        </div>

                    </div>

                </div>

            </div>

            <div class="col-md-3">

                <div class="card tarjeta shadow-sm">

                    <div class="card-body">

                        <small class="text-muted">
                            Certificados vigentes
                        </small>

                        <div class="numero text-primary">
                            <?= $certificadosEncontrados ?>
                        </div>

                    </div>

                </div>

            </div>

            <div class="col-md-3">

                <div class="card tarjeta shadow-sm">

                    <div class="card-body">

                        <small class="text-muted">
                            Certificados enviados
                        </small>

                        <div class="numero text-success">
                            <?= $correosEnviados ?>
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <!-- =====================================
             RESULTADOS
        ====================================== -->

        <div class="card tarjeta shadow-sm">

            <div class="card-body">

                <h5 class="titulo mb-3">
                    Resultados de la búsqueda
                </h5>

                <?php if ($participantes): ?>

                    <div class="table-responsive">

                        <table class="table table-striped
                                      table-bordered table-hover">

                            <thead>

                                <tr>
                                    <th>Documento</th>
                                    <th>Participante</th>
                                    <th>Código</th>
                                    <th>Institución</th>
                                    <th>Estado</th>
                                    <th>Asistencias</th>
                                    <th>Condición</th>
                                    <th>Certificado</th>
                                    <th>Correo</th>
                                </tr>

                            </thead>

                            <tbody>

                            <?php foreach ($participantes as $p): ?>

                                <?php

                                $esApto =
                                    $p['estado'] === 'ACTIVO' &&
                                    (int)$p['total_asistencias']
                                        >= $minimoAsistencias;

                                $tieneCertificado =
                                    $p['id_certificado'] !== null;

                                $correoEnviado =
                                    (int)$p['total_envios'] > 0;

                                ?>

                                <tr>

                                    <!-- DOCUMENTO -->

                                    <td>
                                        <?= escapar($p['documento']) ?>
                                    </td>

                                    <!-- PARTICIPANTE -->

                                    <td>

                                        <div class="nombre">
                                            <?= escapar(
                                                $p['nombres_completos']
                                            ) ?>
                                        </div>

                                        <?php if (!empty($p['correo'])): ?>

                                            <div class="correo">
                                                <?= escapar($p['correo']) ?>
                                            </div>

                                        <?php else: ?>

                                            <small class="text-muted">
                                                Sin correo registrado
                                            </small>

                                        <?php endif; ?>

                                    </td>

                                    <!-- CÓDIGO -->

                                    <td>
                                        <?= escapar(
                                            $p['codigo_inscripcion']
                                        ) ?>
                                    </td>

                                    <!-- INSTITUCIÓN -->

                                    <td>
                                        <?= escapar($p['institucion']) ?>
                                    </td>

                                    <!-- ESTADO -->

                                    <td>

                                        <?php if (
                                            $p['estado'] === 'ACTIVO'
                                        ): ?>

                                            <span class="badge bg-success">
                                                ACTIVO
                                            </span>

                                        <?php else: ?>

                                            <span class="badge bg-secondary">
                                                INACTIVO
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <!-- ASISTENCIAS -->

                                    <td>

                                        <strong>
                                            <?= (int)$p['total_asistencias'] ?>
                                            /
                                            <?= $totalSesiones ?>
                                        </strong>

                                    </td>

                                    <!-- CONDICIÓN -->

                                    <td>

                                        <?php if ($esApto): ?>

                                            <span class="badge bg-success">
                                                APTO
                                            </span>

                                        <?php else: ?>

                                            <span class="badge bg-warning text-dark">
                                                NO APTO
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <!-- CERTIFICADO -->

                                    <td>

                                        <?php if (!$tieneCertificado): ?>

                                            <span class="badge bg-secondary">
                                                SIN EMITIR
                                            </span>

                                        <?php elseif (
                                            $p['estado_certificado']
                                                === 'VIGENTE'
                                        ): ?>

                                            <span class="badge bg-primary">
                                                VIGENTE
                                            </span>

                                        <?php else: ?>

                                            <span class="badge bg-danger">
                                                <?= escapar(
                                                    $p['estado_certificado']
                                                ) ?>
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <!-- CORREO -->

                                    <td>

                                        <?php if ($correoEnviado): ?>

                                            <span class="badge bg-success">
                                                ENVIADO
                                            </span>

                                        <?php elseif (
                                            $tieneCertificado &&
                                            $p['estado_certificado']
                                                === 'VIGENTE'
                                        ): ?>

                                            <span class="badge bg-warning text-dark">
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

                    <div class="alert alert-info mt-3 mb-0">

                        Se muestran hasta 100 participantes
                        por búsqueda.

                    </div>

                <?php else: ?>

                    <div class="alert alert-warning mb-0">

                        <i class="bi bi-exclamation-circle me-2"></i>

                        No se encontraron participantes
                        con el dato ingresado.

                    </div>

                <?php endif; ?>

            </div>

        </div>

    <?php endif; ?>

    <!-- =====================================
         INFORMACIÓN
    ====================================== -->

    <div class="alert alert-light border mt-4">

        <strong>Información:</strong>

        <p class="mb-1 mt-2">

            La condición APTO se asigna temporalmente
            a los participantes activos con al menos
            <?= $minimoAsistencias ?> asistencias.

        </p>

        <p class="mb-0">

            Actualmente existen
            <?= $totalSesiones ?>
            sesiones configuradas en la base de datos
            de pruebas.

        </p>

    </div>

</div>

</body>
</html>
