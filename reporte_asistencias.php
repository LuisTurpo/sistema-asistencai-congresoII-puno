
<?php
session_start();

date_default_timezone_set('America/Lima');

// ======================================
// 1. SEGURIDAD
// ======================================

if (!isset($_SESSION['id_usuario'])) {
    header('Location: login.php');
    exit;
}

if (strtoupper($_SESSION['rol'] ?? '') !== 'ADMIN') {
    http_response_code(403);
    exit('Acceso denegado.');
}

// ======================================
// 2. BASE DE DATOS DE PRUEBAS
// ======================================

require_once __DIR__ . '/config/database_test.php';

if (!isset($conexion) || !($conexion instanceof PDO)) {
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
    http_response_code(500);
    exit('Base de datos incorrecta.');
}

// ======================================
// 3. FUNCIONES
// ======================================

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

// Regla temporal de certificación.
$minimoAsistencias = 5;

// ======================================
// 4. FILTROS
// ======================================

$fecha = trim((string)($_GET['fecha'] ?? ''));
$idSesion = filter_var(
    $_GET['sesion'] ?? 0,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
) ?: 0;

$buscar = trim((string)($_GET['buscar'] ?? ''));

if (
    $fecha !== '' &&
    (
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) ||
        !DateTimeImmutable::createFromFormat('!Y-m-d', $fecha) ||
        DateTimeImmutable::createFromFormat(
            '!Y-m-d', $fecha
        )->format('Y-m-d') !== $fecha
    )
) {
    http_response_code(400);
    exit('Fecha no válida.');
}

// ======================================
// 5. ESTADÍSTICAS GENERALES
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

// ======================================
// 6. LISTA DE SESIONES
// ======================================

$todasSesiones = $conexion->query("
    SELECT
        id_sesion,
        codigo_sesion,
        fecha,
        turno
    FROM sesiones
    ORDER BY fecha, id_sesion
")->fetchAll(PDO::FETCH_ASSOC);

// ======================================
// 7. REPORTE POR SESIÓN
// ======================================

$sqlSesiones = "
    SELECT
        s.id_sesion,
        s.codigo_sesion,
        s.fecha,
        s.turno,
        COUNT(DISTINCT a.id_participante) AS total_asistentes
    FROM sesiones s
    LEFT JOIN asistencias a
        ON a.id_sesion = s.id_sesion
    WHERE 1 = 1
";

$paramsSesiones = [];

if ($fecha !== '') {
    $sqlSesiones .= " AND s.fecha = :fecha";
    $paramsSesiones[':fecha'] = $fecha;
}

if ($idSesion > 0) {
    $sqlSesiones .= " AND s.id_sesion = :sesion";
    $paramsSesiones[':sesion'] = $idSesion;
}

$sqlSesiones .= "
    GROUP BY
        s.id_sesion,
        s.codigo_sesion,
        s.fecha,
        s.turno
    ORDER BY s.fecha, s.id_sesion
";

$stmt = $conexion->prepare($sqlSesiones);
$stmt->execute($paramsSesiones);

$sesiones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ======================================
// 8. REPORTE INDIVIDUAL
// ======================================

$sqlParticipantes = "
    SELECT
        p.id_participante,
        p.documento,
        p.nombres_completos,
        p.correo,
        p.institucion,
        p.estado,

        (
            SELECT COUNT(DISTINCT ax.id_sesion)
            FROM asistencias ax
            WHERE ax.id_participante = p.id_participante
        ) AS total_asistencias,

        COUNT(DISTINCT a.id_sesion) AS asistencias_filtradas,

        c.estado AS estado_certificado

    FROM participantes p

    LEFT JOIN asistencias a
        ON a.id_participante = p.id_participante

    LEFT JOIN sesiones s
        ON s.id_sesion = a.id_sesion

    LEFT JOIN certificados c
        ON c.id_participante = p.id_participante

    WHERE 1 = 1
";

$paramsParticipantes = [];

if ($buscar !== '') {
    $sqlParticipantes .= "
        AND (
            p.documento LIKE :documento
            OR p.nombres_completos LIKE :nombre
        )
    ";

    $termino = '%' . $buscar . '%';

    $paramsParticipantes[':documento'] = $termino;
    $paramsParticipantes[':nombre'] = $termino;
}

if ($fecha !== '') {
    $sqlParticipantes .= " AND s.fecha = :fecha";
    $paramsParticipantes[':fecha'] = $fecha;
}

if ($idSesion > 0) {
    $sqlParticipantes .= " AND s.id_sesion = :sesion";
    $paramsParticipantes[':sesion'] = $idSesion;
}

$sqlParticipantes .= "
    GROUP BY
        p.id_participante,
        p.documento,
        p.nombres_completos,
        p.correo,
        p.institucion,
        p.estado,
        c.estado

    ORDER BY p.nombres_completos ASC
    LIMIT 200
";

$stmt = $conexion->prepare($sqlParticipantes);
$stmt->execute($paramsParticipantes);

$participantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ======================================
// 9. TOTALES FILTRADOS
// ======================================

$totalRegistrosFiltrados = array_sum(
    array_map(
        fn($s) => (int)$s['total_asistentes'],
        $sesiones
    )
);

$totalParticipantesFiltrados = count($participantes);

?>

<!DOCTYPE html>
<html lang="es">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1"
>

<title>Reporte de asistencias - Congreso Puno 2026</title>

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
    font-size: 29px;
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

.btn-principal {
    background: #17406b;
    color: white;
    border: none;
}

.btn-principal:hover {
    background: #102f50;
    color: white;
}

.badge {
    padding: 7px 9px;
}

</style>

</head>

<body>

<div class="container-fluid px-3 px-lg-5 py-4">

    <!-- ENCABEZADO -->

    <div class="encabezado mb-4 shadow-sm">

        <div class="d-flex justify-content-between
                    align-items-center flex-wrap gap-3">

            <div>

                <h3 class="fw-bold mb-2">
                    Reporte de Asistencias
                </h3>

                <p class="mb-1">
                    II Congreso Peruano de Extensión Agropecuaria
                </p>

                <small>Puno 2026</small>

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

    <!-- ESTADÍSTICAS -->

    <h5 class="titulo mb-3">
        <i class="bi bi-bar-chart-line me-2"></i>
        Estadísticas generales
    </h5>

    <div class="row g-3 mb-4">

        <div class="col-md-3">

            <div class="card tarjeta shadow-sm p-4">

                <small class="text-muted">
                    Participantes activos
                </small>

                <div class="numero">
                    <?= numero($totalParticipantes) ?>
                </div>

            </div>

        </div>

        <div class="col-md-3">

            <div class="card tarjeta shadow-sm p-4">

                <small class="text-muted">
                    Asistencias registradas
                </small>

                <div class="numero">
                    <?= numero($totalAsistencias) ?>
                </div>

            </div>

        </div>

        <div class="col-md-3">

            <div class="card tarjeta shadow-sm p-4">

                <small class="text-muted">
                    Aptos para certificado
                </small>

                <div class="numero text-success">
                    <?= numero($totalAptos) ?>
                </div>

            </div>

        </div>

        <div class="col-md-3">

            <div class="card tarjeta shadow-sm p-4">

                <small class="text-muted">
                    Certificados vigentes
                </small>

                <div class="numero text-primary">
                    <?= numero($totalCertificados) ?>
                </div>

            </div>

        </div>

    </div>

    <!-- FILTROS -->

    <div class="card tarjeta shadow-sm mb-4">

        <div class="card-body p-4">

            <h5 class="titulo mb-3">
                <i class="bi bi-funnel me-2"></i>
                Filtrar reportes
            </h5>

            <form method="GET" class="row g-3">

                <div class="col-md-3">

                    <label class="form-label">
                        Fecha
                    </label>

                    <input
                        type="date"
                        name="fecha"
                        class="form-control"
                        value="<?= escapar($fecha) ?>"
                    >

                </div>

                <div class="col-md-3">

                    <label class="form-label">
                        Sesión
                    </label>

                    <select
                        name="sesion"
                        class="form-select"
                    >

                        <option value="0">
                            Todas las sesiones
                        </option>

                        <?php foreach ($todasSesiones as $s): ?>

                            <option
                                value="<?= (int)$s['id_sesion'] ?>"
                                <?= $idSesion === (int)$s['id_sesion']
                                    ? 'selected'
                                    : '' ?>
                            >

                                <?= escapar($s['codigo_sesion']) ?>
                                -
                                <?= escapar($s['turno']) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-md-4">

                    <label class="form-label">
                        Buscar participante
                    </label>

                    <input
                        type="text"
                        name="buscar"
                        class="form-control"
                        placeholder="DNI o nombre"
                        maxlength="200"
                        value="<?= escapar($buscar) ?>"
                    >

                </div>

                <div class="col-md-2 d-flex align-items-end">

                    <button
                        type="submit"
                        class="btn btn-principal w-100"
                    >
                        <i class="bi bi-search"></i>
                        Filtrar
                    </button>

                </div>

            </form>

            <div class="mt-3">

                <a
                    href="reporte_asistencias.php"
                    class="btn btn-outline-secondary btn-sm"
                >
                    Limpiar filtros
                </a>

            </div>

        </div>

    </div>

    <!-- REPORTE POR SESIÓN -->

    <div class="card tarjeta shadow-sm mb-4">

        <div class="card-body p-4">

            <div class="d-flex justify-content-between
                        align-items-center flex-wrap gap-2 mb-3">

                <h5 class="titulo mb-0">
                    Asistencias por sesión
                </h5>

                <span class="badge bg-primary">
                    <?= numero($totalRegistrosFiltrados) ?>
                    asistencias
                </span>

            </div>

            <div class="table-responsive">

                <table class="table table-striped table-bordered">

                    <thead>

                        <tr>
                            <th>Sesión</th>
                            <th>Fecha</th>
                            <th>Turno</th>
                            <th>Asistentes</th>
                            <th>Porcentaje</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($sesiones as $sesion): ?>

                        <?php

                        $asistentes =
                            (int)$sesion['total_asistentes'];

                        $porcentaje = $totalParticipantes > 0
                            ? round(
                                ($asistentes / $totalParticipantes) * 100,
                                1
                            )
                            : 0;

                        ?>

                        <tr>

                            <td>
                                <?= escapar(
                                    $sesion['codigo_sesion']
                                ) ?>
                            </td>

                            <td>
                                <?= escapar($sesion['fecha']) ?>
                            </td>

                            <td>
                                <?= escapar($sesion['turno']) ?>
                            </td>

                            <td>
                                <strong><?= $asistentes ?></strong>
                            </td>

                            <td>

                                <div class="d-flex align-items-center gap-2">

                                    <div
                                        class="progress flex-grow-1"
                                        style="height: 9px; min-width: 70px;"
                                    >

                                        <div
                                            class="progress-bar bg-success"
                                            style="width: <?= min(100, $porcentaje) ?>%;"
                                        ></div>

                                    </div>

                                    <small>
                                        <?= $porcentaje ?>%
                                    </small>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    <?php if (empty($sesiones)): ?>

                        <tr>
                            <td colspan="5"
                                class="text-center text-muted">
                                No se encontraron sesiones.
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

    <!-- REPORTE DE PARTICIPANTES -->

    <div class="card tarjeta shadow-sm mb-4">

        <div class="card-body p-4">

            <div class="d-flex justify-content-between
                        align-items-center flex-wrap gap-2 mb-3">

                <h5 class="titulo mb-0">
                    Asistencias por participante
                </h5>

                <span class="badge bg-primary">
                    <?= numero($totalParticipantesFiltrados) ?>
                    resultados mostrados
                </span>

            </div>

            <div class="table-responsive">

                <table class="table table-striped
                              table-bordered table-hover">

                    <thead>

                        <tr>
                            <th>Documento</th>
                            <th>Participante</th>
                            <th>Institución</th>
                            <th>Asistencias totales</th>
                            <th>Asistencias filtradas</th>
                            <th>Condición</th>
                            <th>Certificado</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($participantes as $p): ?>

                        <?php

                        $esApto =
                            $p['estado'] === 'ACTIVO' &&
                            (int)$p['total_asistencias']
                                >= $minimoAsistencias;

                        ?>

                        <tr>

                            <td>
                                <?= escapar($p['documento']) ?>
                            </td>

                            <td>

                                <strong>
                                    <?= escapar(
                                        $p['nombres_completos']
                                    ) ?>
                                </strong>

                                <?php if (!empty($p['correo'])): ?>

                                    <div class="small text-muted">
                                        <?= escapar($p['correo']) ?>
                                    </div>

                                <?php endif; ?>

                            </td>

                            <td>
                                <?= escapar($p['institucion']) ?>
                            </td>

                            <td>
                                <strong>
                                    <?= (int)$p['total_asistencias'] ?>
                                    /
                                    <?= $totalSesiones ?>
                                </strong>
                            </td>

                            <td>
                                <?= (int)$p['asistencias_filtradas'] ?>
                            </td>

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

                            <td>

                                <?php if (
                                    $p['estado_certificado'] === 'VIGENTE'
                                ): ?>

                                    <span class="badge bg-primary">
                                        VIGENTE
                                    </span>

                                <?php elseif (
                                    $p['estado_certificado'] === 'ANULADO'
                                ): ?>

                                    <span class="badge bg-danger">
                                        ANULADO
                                    </span>

                                <?php else: ?>

                                    <span class="badge bg-secondary">
                                        SIN EMITIR
                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    <?php if (empty($participantes)): ?>

                        <tr>
                            <td colspan="7"
                                class="text-center text-muted">
                                No se encontraron participantes.
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <small class="text-muted">
                Se muestran hasta 200 participantes por consulta.
            </small>

        </div>

    </div>

    <!-- EXPORTACIÓN -->

    <div class="card tarjeta shadow-sm mb-4">

        <div class="card-body p-4">

            <h5 class="titulo mb-3">
                <i class="bi bi-file-earmark-excel me-2"></i>
                Exportar reporte
            </h5>

            <p class="text-muted">
                Descargue el reporte de asistencias
                en formato Excel.
            </p>

            <a
    href="exportar_asistencias.php?<?= escapar(
        http_build_query([
            'fecha' => $fecha,
            'sesion' => $idSesion,
            'buscar' => $buscar
        ])
    ) ?>"
    class="btn btn-success"
>
                <i class="bi bi-download me-1"></i>
                Descargar reporte Excel
            </a>

            <p class="text-muted small mt-3 mb-0">
                El archivo de exportación se revisará
                para conectarlo a la base de pruebas.
                No utilice todavía este botón.
            </p>

        </div>

    </div>

    <!-- INFORMACIÓN -->

    <div class="alert alert-light border">

        <strong>Información:</strong>

        La condición APTO se asigna temporalmente
        a los participantes activos con al menos
        <?= $minimoAsistencias ?> asistencias
        de <?= $totalSesiones ?> sesiones configuradas.

        Las estadísticas generales no cambian al aplicar filtros.

    </div>

</div>

</body>
</html>
