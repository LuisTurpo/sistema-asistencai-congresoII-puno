
<?php
session_start();

date_default_timezone_set('America/Lima');

// ======================================
// SEGURIDAD
// ======================================

if (!isset($_SESSION['id_usuario'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/database_test.php';

if (
    !isset($conexion) ||
    !($conexion instanceof PDO)
) {
    exit('Error de conexión.');
}

$conexion->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);

if (
    $conexion->query('SELECT DATABASE()')->fetchColumn()
    !== 'congreso_puno_pruebas'
) {
    exit('Base de datos incorrecta.');
}

function escapar($valor) {
    return htmlspecialchars(
        (string)($valor ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function numero($valor) {
    return number_format((int)$valor, 0, ',', '.');
}

$nombre = $_SESSION['nombre'] ?? 'Usuario';
$rol = strtoupper($_SESSION['rol'] ?? '');
$esAdmin = $rol === 'ADMIN';

// ======================================
// ESTADÍSTICAS
// ======================================

$totalParticipantes = (int)$conexion->query("
    SELECT COUNT(*)
    FROM participantes
    WHERE estado = 'ACTIVO'
")->fetchColumn();

$totalAsistencias = (int)$conexion->query("
    SELECT COUNT(*)
    FROM asistencias
")->fetchColumn();

$totalSesiones = (int)$conexion->query("
    SELECT COUNT(*)
    FROM sesiones
")->fetchColumn();

// Se mantiene temporalmente la regla
// de 5 asistencias para certificación.

$totalAptos = (int)$conexion->query("
    SELECT COUNT(*)
    FROM (
        SELECT p.id_participante
        FROM participantes p
        INNER JOIN asistencias a
            ON a.id_participante = p.id_participante
        WHERE p.estado = 'ACTIVO'
        GROUP BY p.id_participante
        HAVING COUNT(DISTINCT a.id_sesion) >= 5
    ) AS aptos
")->fetchColumn();

$totalCertificados = (int)$conexion->query("
    SELECT COUNT(*)
    FROM certificados
    WHERE estado = 'VIGENTE'
")->fetchColumn();

$totalCorreosEnviados = (int)$conexion->query("
    SELECT COUNT(DISTINCT id_certificado)
    FROM envios_certificados
    WHERE estado = 'ENVIADO'
")->fetchColumn();

$totalCertificadosPendientes = (int)$conexion->query("
    SELECT COUNT(*)
    FROM participantes p
    WHERE p.estado = 'ACTIVO'
      AND (
          SELECT COUNT(DISTINCT a.id_sesion)
          FROM asistencias a
          WHERE a.id_participante = p.id_participante
      ) >= 5
      AND NOT EXISTS (
          SELECT 1
          FROM certificados c
          WHERE c.id_participante = p.id_participante
      )
")->fetchColumn();

// ======================================
// ASISTENCIAS POR SESIÓN
// ======================================

$sesiones = $conexion->query("
    SELECT
        s.id_sesion,
        s.codigo_sesion,
        s.fecha,
        s.turno,
        COUNT(a.id_asistencia) AS total
    FROM sesiones s
    LEFT JOIN asistencias a
        ON a.id_sesion = s.id_sesion
    GROUP BY
        s.id_sesion,
        s.codigo_sesion,
        s.fecha,
        s.turno
    ORDER BY s.fecha, s.id_sesion
")->fetchAll(PDO::FETCH_ASSOC);

// ======================================
// PORCENTAJE DE CERTIFICACIÓN
// ======================================

$porcentajeCertificados = $totalAptos > 0
    ? min(
        100,
        round(($totalCertificados / $totalAptos) * 100)
    )
    : 0;

// ======================================
// FECHA ACTUAL
// ======================================

$fechaActual = date('d/m/Y');
?>

<!DOCTYPE html>
<html lang="es">

<head>
<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1">

<title>
    Panel principal - Congreso Puno 2026
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

:root {
    --principal: #17406b;
    --principal-oscuro: #102f50;
    --fondo: #f3f6fa;
}

body {
    background: var(--fondo);
    font-family: Arial, sans-serif;
    color: #24364a;
}

.navbar {
    background: var(--principal);
}

.navbar-brand {
    font-weight: 700;
}

.encabezado {
    background: linear-gradient(
        120deg,
        #17406b,
        #24659b
    );
    color: white;
    padding: 30px;
    border-radius: 16px;
}

.tarjeta {
    border: none;
    border-radius: 15px;
    height: 100%;
}

.tarjeta-estadistica {
    border-left: 4px solid var(--principal);
}

.numero {
    font-size: 32px;
    font-weight: 700;
}

.icono-estadistica {
    font-size: 29px;
    color: var(--principal);
}

.modulo {
    border: none;
    border-radius: 15px;
    height: 100%;
    transition: 0.2s;
}

.modulo:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,.08);
}

.icono-modulo {
    font-size: 32px;
    color: var(--principal);
}

.titulo-seccion {
    color: var(--principal);
    font-weight: 700;
}

.btn-principal {
    background: var(--principal);
    color: white;
    border: none;
}

.btn-principal:hover {
    background: var(--principal-oscuro);
    color: white;
}

.table thead th {
    background: var(--principal);
    color: white;
}

.pie {
    color: #6c757d;
    font-size: 13px;
}

@media (max-width: 768px) {

    .encabezado {
        padding: 20px;
    }

    .numero {
        font-size: 26px;
    }

}

</style>

</head>

<body>

<!-- ======================================
     BARRA SUPERIOR
====================================== -->

<nav class="navbar navbar-dark navbar-expand-lg">

    <div class="container">

        <a class="navbar-brand" href="dashboard.php">
            <i class="bi bi-mortarboard-fill me-2"></i>
            Congreso Puno 2026
        </a>

        <div class="d-flex align-items-center gap-3">

            <span class="text-white small d-none d-md-inline">
                <?= escapar($nombre) ?>
            </span>

            <a
                href="logout.php"
                class="btn btn-outline-light btn-sm"
            >
                <i class="bi bi-box-arrow-right"></i>
                Salir
            </a>

        </div>

    </div>

</nav>

<div class="container py-4">

    <!-- ======================================
         ENCABEZADO
    ====================================== -->

    <div class="encabezado mb-4 shadow-sm">

        <div class="d-flex justify-content-between
                    align-items-center flex-wrap gap-3">

            <div>

                <h2 class="fw-bold mb-2">
                    Panel principal
                </h2>

                <p class="mb-2">
                    Sistema de Gestión de Asistencias
                    y Certificados
                </p>

                <div class="small">
                    Bienvenido,
                    <strong><?= escapar($nombre) ?></strong>

                    | Rol:
                    <?= escapar($rol) ?>
                </div>

            </div>

            <div class="text-md-end">

                <span class="badge bg-warning text-dark mb-2">
                    MODO PRUEBAS
                </span>

                <div>
                    <i class="bi bi-calendar3 me-1"></i>
                    <?= escapar($fechaActual) ?>
                </div>

            </div>

        </div>

    </div>

    <!-- ======================================
         ESTADÍSTICAS
    ====================================== -->

    <h5 class="titulo-seccion mb-3">
        <i class="bi bi-bar-chart-line me-2"></i>
        Resumen general
    </h5>

    <div class="row g-3 mb-4">

        <!-- PARTICIPANTES -->

        <div class="col-md-6 col-lg-3">

            <div class="card tarjeta tarjeta-estadistica shadow-sm">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <small class="text-muted">
                                Participantes activos
                            </small>

                            <div class="numero">
                                <?= numero($totalParticipantes) ?>
                            </div>

                        </div>

                        <i class="bi bi-people-fill icono-estadistica"></i>

                    </div>

                </div>

            </div>

        </div>

        <!-- ASISTENCIAS -->

        <div class="col-md-6 col-lg-3">

            <div class="card tarjeta tarjeta-estadistica shadow-sm">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <small class="text-muted">
                                Asistencias registradas
                            </small>

                            <div class="numero">
                                <?= numero($totalAsistencias) ?>
                            </div>

                        </div>

                        <i class="bi bi-clipboard-check icono-estadistica"></i>

                    </div>

                </div>

            </div>

        </div>

        <!-- APTOS -->

        <div class="col-md-6 col-lg-3">

            <div class="card tarjeta tarjeta-estadistica shadow-sm">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <small class="text-muted">
                                Aptos para certificado
                            </small>

                            <div class="numero text-success">
                                <?= numero($totalAptos) ?>
                            </div>

                        </div>

                        <i class="bi bi-person-check-fill icono-estadistica"></i>

                    </div>

                </div>

            </div>

        </div>

        <!-- CERTIFICADOS -->

        <div class="col-md-6 col-lg-3">

            <div class="card tarjeta tarjeta-estadistica shadow-sm">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <small class="text-muted">
                                Certificados vigentes
                            </small>

                            <div class="numero text-primary">
                                <?= numero($totalCertificados) ?>
                            </div>

                        </div>

                        <i class="bi bi-award-fill icono-estadistica"></i>

                    </div>

                </div>

            </div>

        </div>

        <!-- CORREOS -->

        <div class="col-md-6 col-lg-3">

            <div class="card tarjeta tarjeta-estadistica shadow-sm">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <small class="text-muted">
                                Certificados enviados
                            </small>

                            <div class="numero text-success">
                                <?= numero($totalCorreosEnviados) ?>
                            </div>

                        </div>

                        <i class="bi bi-envelope-check-fill icono-estadistica"></i>

                    </div>

                </div>

            </div>

        </div>

        <!-- PENDIENTES -->

        <div class="col-md-6 col-lg-3">

            <div class="card tarjeta tarjeta-estadistica shadow-sm">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <small class="text-muted">
                                Pendientes de emisión
                            </small>

                            <div class="numero text-warning">
                                <?= numero($totalCertificadosPendientes) ?>
                            </div>

                        </div>

                        <i class="bi bi-hourglass-split icono-estadistica"></i>

                    </div>

                </div>

            </div>

        </div>

        <!-- SESIONES -->

        <div class="col-md-6 col-lg-3">

            <div class="card tarjeta tarjeta-estadistica shadow-sm">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <small class="text-muted">
                                Sesiones configuradas
                            </small>

                            <div class="numero">
                                <?= numero($totalSesiones) ?>
                            </div>

                        </div>

                        <i class="bi bi-calendar-event icono-estadistica"></i>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- ======================================
         MÓDULOS
    ====================================== -->

    <h5 class="titulo-seccion mb-3">
        <i class="bi bi-grid-fill me-2"></i>
        Módulos del sistema
    </h5>

    <div class="row g-3 mb-4">

        <!-- REGISTRO DE ASISTENCIAS -->

        <div class="col-md-6 col-lg-4">

            <div class="card modulo shadow-sm">

                <div class="card-body p-4">

                    <i class="bi bi-clipboard-check icono-modulo"></i>

                    <h5 class="mt-3">
                        Registro de asistencias
                    </h5>

                    <p class="text-muted small">
                        Buscar participantes, registrar
                        asistencias e inscribir nuevos
                        asistentes presenciales.
                    </p>

                    <a
                        href="registrar_asistencia_test.php"
                        class="btn btn-principal w-100"
                    >
                        Abrir módulo
                    </a>

                </div>

            </div>

        </div>

        <!-- CONSULTAR PARTICIPANTES -->

        <div class="col-md-6 col-lg-4">

            <div class="card modulo shadow-sm">

                <div class="card-body p-4">

                    <i class="bi bi-people icono-modulo"></i>

                    <h5 class="mt-3">
                        Participantes
                    </h5>

                    <p class="text-muted small">
                        Consultar los participantes
                        inscritos y sus datos registrados.
                    </p>

                    <a
                        href="consultar_participantes.php"
                        class="btn btn-principal w-100"
                    >
                        Consultar participantes
                    </a>

                </div>

            </div>

        </div>

        <!-- IMPORTAR PARTICIPANTES -->

        <?php if ($esAdmin): ?>

        <div class="col-md-6 col-lg-4">

            <div class="card modulo shadow-sm">

                <div class="card-body p-4">

                    <i class="bi bi-file-earmark-spreadsheet icono-modulo"></i>

                    <h5 class="mt-3">
                        Importar participantes
                    </h5>

                    <p class="text-muted small">
                        Importar nuevos participantes
                        desde archivos Excel o CSV.
                    </p>

                    <a
                        href="importar_participantes.php"
                        class="btn btn-principal w-100"
                    >
                        Importar archivo
                    </a>

                </div>

            </div>

        </div>

        <?php endif; ?>

        <!-- REPORTES -->

        <div class="col-md-6 col-lg-4">

            <div class="card modulo shadow-sm">

                <div class="card-body p-4">

                    <i class="bi bi-file-earmark-bar-graph icono-modulo"></i>

                    <h5 class="mt-3">
                        Reportes de asistencia
                    </h5>

                    <p class="text-muted small">
                        Consultar las asistencias
                        registradas y generar reportes.
                    </p>

                    <a
                        href="reporte_asistencias.php"
                        class="btn btn-principal w-100"
                    >
                        Ver reportes
                    </a>

                </div>

            </div>

        </div>

        <!-- CERTIFICADOS -->

        <?php if ($esAdmin): ?>

        <div class="col-md-6 col-lg-4">

            <div class="card modulo shadow-sm">

                <div class="card-body p-4">

                    <i class="bi bi-award icono-modulo"></i>

                    <h5 class="mt-3">
                        Gestión de certificados
                    </h5>

                    <p class="text-muted small">
                        Generar certificados,
                        descargarlos y controlar
                        su emisión.
                    </p>

                    <a
                        href="generar_certificados_test.php"
                        class="btn btn-principal w-100"
                    >
                        Gestionar certificados
                    </a>

                </div>

            </div>

        </div>

        <!-- CORREOS -->

        <div class="col-md-6 col-lg-4">

            <div class="card modulo shadow-sm">

                <div class="card-body p-4">

                    <i class="bi bi-envelope-paper icono-modulo"></i>

                    <h5 class="mt-3">
                        Envío de certificados
                    </h5>

                    <p class="text-muted small">
                        Enviar certificados por Gmail
                        de forma individual o masiva.
                    </p>

                    <a
                        href="generar_certificados_test.php"
                        class="btn btn-principal w-100"
                    >
                        Gestionar correos
                    </a>

                </div>

            </div>

        </div>

        <?php endif; ?>

    </div>

    <!-- ======================================
         ASISTENCIAS POR SESIÓN
    ====================================== -->

    <div class="card tarjeta shadow-sm mb-4">

        <div class="card-body">

            <div class="d-flex justify-content-between
                        align-items-center flex-wrap gap-2 mb-3">

                <h5 class="titulo-seccion mb-0">
                    Asistencias por sesión
                </h5>

                <span class="badge bg-primary">
                    <?= numero($totalSesiones) ?> sesiones
                </span>

            </div>

            <div class="table-responsive">

                <table class="table table-striped
                              table-bordered align-middle">

                    <thead>

                        <tr>
                            <th>Sesión</th>
                            <th>Fecha</th>
                            <th>Turno</th>
                            <th>Asistencias</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($sesiones as $sesion): ?>

                        <tr>

                            <td>
                                <?= escapar(
                                    $sesion['codigo_sesion']
                                ) ?>
                            </td>

                            <td>
                                <?= escapar(
                                    $sesion['fecha']
                                ) ?>
                            </td>

                            <td>
                                <?= escapar(
                                    $sesion['turno']
                                ) ?>
                            </td>

                            <td>
                                <span class="badge bg-success">
                                    <?= numero($sesion['total']) ?>
                                </span>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    <?php if (empty($sesiones)): ?>

                        <tr>
                            <td colspan="4"
                                class="text-center text-muted">
                                No existen sesiones registradas.
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

    <!-- ======================================
         PROGRESO DE CERTIFICACIÓN
    ====================================== -->

    <div class="card tarjeta shadow-sm mb-4">

        <div class="card-body">

            <h5 class="titulo-seccion">
                Progreso de certificación
            </h5>

            <p class="text-muted small">
                Relación entre certificados vigentes
                y participantes aptos.
            </p>

            <div class="progress mb-2"
                 style="height: 22px;">

                <div
                    class="progress-bar bg-success"
                    role="progressbar"
                    style="width: <?= $porcentajeCertificados ?>%;"
                    aria-valuenow="<?= $porcentajeCertificados ?>"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <?= $porcentajeCertificados ?>%
                </div>

            </div>

            <small class="text-muted">
                <?= numero($totalCertificados) ?>
                certificados vigentes /
                <?= numero($totalAptos) ?>
                participantes aptos
            </small>

        </div>

    </div>

    <!-- ======================================
         PIE DE PÁGINA
    ====================================== -->

    <div class="text-center pie py-3">

        II Congreso Peruano de Extensión Agropecuaria
        - Puno 2026

        <br>

        Sistema de Gestión de Asistencias y Certificados

        <br>

        Entorno de pruebas

    </div>

</div>

</body>
</html>
