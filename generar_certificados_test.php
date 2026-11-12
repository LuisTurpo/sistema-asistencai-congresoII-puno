
<?php
session_start();
date_default_timezone_set('America/Lima');

// ========================================
// 1. SEGURIDAD
// ========================================

if (
    !isset($_SESSION['id_usuario']) ||
    strtoupper(trim($_SESSION['rol'] ?? '')) !== 'ADMIN'
) {
    header('Location: login.php');
    exit;
}

// ========================================
// 2. CONEXIÓN A BASE DE PRUEBAS
// ========================================

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

$baseActual = $conexion
    ->query('SELECT DATABASE()')
    ->fetchColumn();

if ($baseActual !== 'congreso_puno_pruebas') {
    http_response_code(500);
    exit('Seguridad: base de datos incorrecta.');
}

// ========================================
// 3. FUNCIONES Y CONFIGURACIÓN
// ========================================

function e($valor): string
{
    return htmlspecialchars(
        (string)($valor ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

if (empty($_SESSION['csrf_certificados_test'])) {
    $_SESSION['csrf_certificados_test'] =
        bin2hex(random_bytes(32));
}

// Fechas definitivas del congreso.
$fechaInicio = '2026-12-02';
$fechaFin = '2026-12-04';

// Regla actual de certificados.
$minimoAsistencias = 5;

// ========================================
// 4. OBTENER SESIONES DEFINITIVAS
// ========================================

$stmt = $conexion->prepare("
    SELECT COUNT(*)
    FROM sesiones
    WHERE fecha BETWEEN ? AND ?
");

$stmt->execute([$fechaInicio, $fechaFin]);

$totalSesiones = (int)$stmt->fetchColumn();

// ========================================
// 5. CONSULTAR PARTICIPANTES
// ========================================

// IMPORTANTE:
// Las asistencias solo se cuentan si pertenecen
// a una sesión existente entre el 2 y 4 de diciembre.

$sql = "
    SELECT
        p.id_participante,
        p.nombres_completos,
        p.documento,
        p.correo,
        p.estado,

        (
            SELECT COUNT(DISTINCT a.id_sesion)
            FROM asistencias a
            INNER JOIN sesiones s
                ON s.id_sesion = a.id_sesion
            WHERE a.id_participante = p.id_participante
              AND s.fecha BETWEEN ? AND ?
        ) AS total_asistencias,

        c.id_certificado,
        c.codigo_verificacion,
        c.estado AS estado_certificado,
        c.fecha_emision,

        (
            SELECT COUNT(*)
            FROM envios_certificados ec
            WHERE ec.id_certificado = c.id_certificado
              AND ec.estado = 'ENVIADO'
        ) AS total_envios,

        (
            SELECT MAX(ec.fecha_envio)
            FROM envios_certificados ec
            WHERE ec.id_certificado = c.id_certificado
              AND ec.estado = 'ENVIADO'
        ) AS ultima_fecha_envio,

        (
            SELECT ec.estado
            FROM envios_certificados ec
            WHERE ec.id_certificado = c.id_certificado
            ORDER BY ec.id_envio DESC
            LIMIT 1
        ) AS ultimo_estado_envio

    FROM participantes p

    LEFT JOIN certificados c
        ON c.id_participante = p.id_participante

    ORDER BY p.id_participante ASC
";

$stmt = $conexion->prepare($sql);

$stmt->execute([
    $fechaInicio,
    $fechaFin
]);

$participantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ========================================
// 6. CALCULAR ESTADÍSTICAS
// ========================================

$totalParticipantes = count($participantes);

$aptos = 0;
$emitidos = 0;
$pendientes = 0;

$correosEnviados = 0;
$correosPendientes = 0;
$correosError = 0;

foreach ($participantes as $p) {

    $esApto =
        $p['estado'] === 'ACTIVO' &&
        (int)$p['total_asistencias'] >= $minimoAsistencias;

    $tieneCertificado =
        $p['id_certificado'] !== null;

    $certificadoVigente =
        $tieneCertificado &&
        $p['estado_certificado'] === 'VIGENTE';

    $correoEnviado =
        (int)$p['total_envios'] > 0;

    if ($esApto) {
        $aptos++;
    }

    if ($tieneCertificado) {
        $emitidos++;
    }

    if ($esApto && !$tieneCertificado) {
        $pendientes++;
    }

    if (
        $certificadoVigente &&
        $correoEnviado
    ) {
        $correosEnviados++;

    } elseif (
        $certificadoVigente &&
        $esApto
    ) {
        $correosPendientes++;

        if ($p['ultimo_estado_envio'] === 'ERROR') {
            $correosError++;
        }
    }
}

// ========================================
// 7. MENSAJES
// ========================================

$mensaje =
    $_SESSION['mensaje_certificados_test'] ?? '';

unset($_SESSION['mensaje_certificados_test']);

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
        Gestión de Certificados - Congreso Puno 2026
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>

        body {
            background: #f3f6fa;
            font-family: Arial, sans-serif;
        }

        .contenedor {
            max-width: 1400px;
        }

        .titulo {
            color: #17406b;
            font-weight: 700;
        }

        .cabecera {
            background: #17406b;
            color: white;
            border-radius: 15px;
            padding: 25px;
        }

        .cabecera h2 {
            font-weight: 700;
        }

        .tarjeta {
            border: none;
            border-radius: 14px;
        }

        .tarjeta-estadistica {
            border: none;
            border-radius: 14px;
            transition: 0.2s;
        }

        .tarjeta-estadistica:hover {
            transform: translateY(-2px);
        }

        .numero {
            font-size: 30px;
            font-weight: 700;
        }

        .tabla-certificados td {
            vertical-align: middle;
        }

        .nombre-participante {
            font-weight: 600;
            color: #24364a;
        }

        .acciones {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .acciones form {
            margin: 0;
        }

        .btn-accion {
            white-space: nowrap;
        }

        .correo-participante {
            font-size: 12px;
            color: #6c757d;
            overflow-wrap: anywhere;
        }

        .badge {
            font-size: 12px;
            padding: 7px 9px;
        }

        @media (max-width: 768px) {

            .cabecera {
                padding: 18px;
            }

            .cabecera h2 {
                font-size: 23px;
            }

            .numero {
                font-size: 25px;
            }

        }

    </style>

</head>

<body>

<div class="container-fluid contenedor py-4">

    <!-- ====================================
         CABECERA
    ===================================== -->

    <div class="cabecera mb-4 shadow-sm">

        <div class="d-flex justify-content-between
                    align-items-center flex-wrap gap-3">

            <div>

                <h2 class="mb-2">
                    Gestión de Certificados
                </h2>

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

                    <span class="badge bg-light text-dark">
                        <?= $totalSesiones ?> sesiones configuradas
                    </span>

                </div>

            </div>

            <a
                href="dashboard.php"
                class="btn btn-light"
            >
                Volver al panel
            </a>

        </div>

    </div>

    <!-- ====================================
         MENSAJES
    ===================================== -->

    <?php if ($mensaje): ?>

        <div class="alert alert-info alert-dismissible fade show">

            <?= e($mensaje) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
                aria-label="Cerrar"
            ></button>

        </div>

    <?php endif; ?>

    <!-- Verificar las seis sesiones -->

    <?php if ($totalSesiones !== 6): ?>

        <div class="alert alert-danger">

            <strong>Atención:</strong>

            Se esperaban 6 sesiones entre el
            2 y el 4 de diciembre de 2026,
            pero se encontraron <?= $totalSesiones ?>.

            Revisa la tabla sesiones antes
            de generar o enviar certificados.

        </div>

    <?php endif; ?>

    <!-- ====================================
         ESTADÍSTICAS
    ===================================== -->

    <div class="row g-3 mb-4">

        <div class="col-md-4 col-lg-2">

            <div class="card tarjeta-estadistica shadow-sm">
                <div class="card-body">

                    <small class="text-muted">
                        Participantes
                    </small>

                    <div class="numero">
                        <?= $totalParticipantes ?>
                    </div>

                </div>
            </div>

        </div>

        <div class="col-md-4 col-lg-2">

            <div class="card tarjeta-estadistica shadow-sm">
                <div class="card-body">

                    <small class="text-muted">
                        Aptos
                    </small>

                    <div class="numero text-success">
                        <?= $aptos ?>
                    </div>

                </div>
            </div>

        </div>

        <div class="col-md-4 col-lg-2">

            <div class="card tarjeta-estadistica shadow-sm">
                <div class="card-body">

                    <small class="text-muted">
                        Certificados emitidos
                    </small>

                    <div class="numero text-primary">
                        <?= $emitidos ?>
                    </div>

                </div>
            </div>

        </div>

        <div class="col-md-4 col-lg-2">

            <div class="card tarjeta-estadistica shadow-sm">
                <div class="card-body">

                    <small class="text-muted">
                        Pendientes de emisión
                    </small>

                    <div class="numero text-warning">
                        <?= $pendientes ?>
                    </div>

                </div>
            </div>

        </div>

        <div class="col-md-4 col-lg-2">

            <div class="card tarjeta-estadistica shadow-sm">
                <div class="card-body">

                    <small class="text-muted">
                        Correos enviados
                    </small>

                    <div class="numero text-success">
                        <?= $correosEnviados ?>
                    </div>

                </div>
            </div>

        </div>

        <div class="col-md-4 col-lg-2">

            <div class="card tarjeta-estadistica shadow-sm">
                <div class="card-body">

                    <small class="text-muted">
                        Correos pendientes
                    </small>

                    <div class="numero text-danger">
                        <?= $correosPendientes ?>
                    </div>

                </div>
            </div>

        </div>

    </div>

    <!-- ====================================
         RESUMEN DE ENVÍOS
    ===================================== -->

    <div class="card tarjeta shadow-sm mb-4">

        <div class="card-body">

            <div class="d-flex justify-content-between
                        align-items-center flex-wrap gap-3">

                <div>

                    <h5 class="titulo mb-1">
                        Control de envío de certificados
                    </h5>

                    <p class="text-muted mb-0">

                        Enviados:
                        <strong>
                            <?= $correosEnviados ?>
                        </strong>

                        |

                        Pendientes:
                        <strong>
                            <?= $correosPendientes ?>
                        </strong>

                        |

                        Último intento fallido:
                        <strong>
                            <?= $correosError ?>
                        </strong>

                    </p>

                </div>

                <form
                    method="POST"
                    action="procesar_envios_test.php"
                    class="form-envio"
                    onsubmit="return confirm('¿Procesar hasta 10 certificados pendientes para los correos de prueba autorizados?');"
                >

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= e($_SESSION['csrf_certificados_test']) ?>"
                    >

                    <input
                        type="hidden"
                        name="accion"
                        value="masivo"
                    >

                    <button
                        type="submit"
                        class="btn btn-warning"
                        <?= (
                            $correosPendientes === 0 ||
                            $totalSesiones !== 6
                        ) ? 'disabled' : '' ?>
                    >
                        Enviar certificados pendientes
                    </button>

                </form>

            </div>

            <div class="alert alert-warning mt-3 mb-0">

                <strong>Modo de pruebas:</strong>

                Los certificados se enviarán a los correos
                individuales registrados en MySQL,
                siempre que estén autorizados en
                destinatarios_prueba.php.

                No se enviarán correos a los
                participantes oficiales.

            </div>

        </div>

    </div>

    <!-- ====================================
         LISTADO DE PARTICIPANTES
    ===================================== -->

    <div class="card tarjeta shadow-sm">

        <div class="card-body">

            <div class="d-flex justify-content-between
                        align-items-center flex-wrap gap-3 mb-3">

                <h5 class="titulo mb-0">
                    Listado de participantes
                </h5>

                <form
                    method="POST"
                    action="procesar_certificados_test.php"
                    onsubmit="return confirm('¿Generar certificados para los participantes aptos pendientes?');"
                >

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= e($_SESSION['csrf_certificados_test']) ?>"
                    >

                    <input
                        type="hidden"
                        name="accion"
                        value="masivo"
                    >

                    <button
                        type="submit"
                        class="btn btn-success"
                        <?= (
                            $pendientes === 0 ||
                            $totalSesiones !== 6
                        ) ? 'disabled' : '' ?>
                    >
                        Generar todos los aptos
                    </button>

                </form>

            </div>

            <div class="table-responsive">

                <table class="table table-striped
                              table-bordered table-hover
                              tabla-certificados">

                    <thead class="table-dark">

                        <tr>
                            <th>ID</th>
                            <th>Participante</th>
                            <th>Documento</th>
                            <th>Asistencias</th>
                            <th>Condición</th>
                            <th>Certificado</th>
                            <th>Correo</th>
                            <th>Acciones</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($participantes as $p): ?>

                        <?php

                        $totalAsistencias =
                            (int)$p['total_asistencias'];

                        $esApto =
                            $p['estado'] === 'ACTIVO' &&
                            $totalAsistencias >= $minimoAsistencias;

                        $tieneCertificado =
                            $p['id_certificado'] !== null;

                        $certificadoVigente =
                            $tieneCertificado &&
                            $p['estado_certificado'] === 'VIGENTE';

                        $correoEnviado =
                            (int)$p['total_envios'] > 0;

                        ?>

                        <tr>

                            <!-- ID -->

                            <td>
                                <?= (int)$p['id_participante'] ?>
                            </td>

                            <!-- PARTICIPANTE -->

                            <td>

                                <div class="nombre-participante">
                                    <?= e($p['nombres_completos']) ?>
                                </div>

                                <?php if (!empty($p['correo'])): ?>

                                    <div class="correo-participante">
                                        <?= e($p['correo']) ?>
                                    </div>

                                <?php else: ?>

                                    <small class="text-muted">
                                        Sin correo registrado
                                    </small>

                                <?php endif; ?>

                            </td>

                            <!-- DOCUMENTO -->

                            <td>
                                <?= e($p['documento']) ?>
                            </td>

                            <!-- ASISTENCIAS CORREGIDAS -->

                            <td>

                                <span class="fw-bold">
                                    <?= $totalAsistencias ?>
                                </span>

                                /

                                <span class="fw-bold">
                                    <?= $totalSesiones ?>
                                </span>

                            </td>

                            <!-- CONDICIÓN -->

                            <td>

                                <?php if ($esApto): ?>

                                    <span class="badge bg-success">
                                        APTO
                                    </span>

                                <?php else: ?>

                                    <span class="badge bg-secondary">
                                        NO APTO
                                    </span>

                                <?php endif; ?>

                            </td>

                            <!-- CERTIFICADO -->

                            <td>

                                <?php if ($tieneCertificado): ?>

                                    <?php if ($certificadoVigente): ?>

                                        <span class="badge bg-primary">
                                            VIGENTE
                                        </span>

                                    <?php else: ?>

                                        <span class="badge bg-danger">
                                            <?= e($p['estado_certificado']) ?>
                                        </span>

                                    <?php endif; ?>

                                <?php else: ?>

                                    <span class="badge bg-warning text-dark">
                                        SIN EMITIR
                                    </span>

                                <?php endif; ?>

                            </td>

                            <!-- ESTADO DE CORREO -->

                            <td>

                                <?php if ($correoEnviado): ?>

                                    <span class="badge bg-success">
                                        ENVIADO
                                    </span>

                                    <?php if (!empty($p['ultima_fecha_envio'])): ?>

                                        <div class="mt-1">
                                            <small class="text-muted">
                                                <?= e($p['ultima_fecha_envio']) ?>
                                            </small>
                                        </div>

                                    <?php endif; ?>

                                <?php elseif (
                                    $certificadoVigente &&
                                    $p['ultimo_estado_envio'] === 'ERROR'
                                ): ?>

                                    <span class="badge bg-danger">
                                        ERROR
                                    </span>

                                <?php elseif (
                                    $certificadoVigente &&
                                    $p['ultimo_estado_envio'] === 'PENDIENTE'
                                ): ?>

                                    <span class="badge bg-info text-dark">
                                        EN PROCESO
                                    </span>

                                <?php elseif ($certificadoVigente): ?>

                                    <span class="badge bg-warning text-dark">
                                        PENDIENTE
                                    </span>

                                <?php else: ?>

                                    <span class="badge bg-secondary">
                                        NO DISPONIBLE
                                    </span>

                                <?php endif; ?>

                            </td>

                            <!-- ACCIONES -->

                            <td>

                                <div class="acciones">

                                    <?php if ($tieneCertificado): ?>

                                        <!-- DESCARGAR -->

                                        <a
                                            class="btn btn-sm btn-outline-primary btn-accion"
                                            href="descargar_certificado_test.php?id=<?= (int)$p['id_participante'] ?>"
                                        >
                                            Descargar
                                        </a>

                                        <!-- ENVIAR CORREO -->

                                        <?php if (
                                            $esApto &&
                                            $certificadoVigente &&
                                            !$correoEnviado &&
                                            $p['ultimo_estado_envio'] !== 'PENDIENTE' &&
                                            $totalSesiones === 6
                                        ): ?>

                                            <form
                                                method="POST"
                                                action="procesar_envios_test.php"
                                                class="form-envio"
                                                onsubmit="return confirm('¿Enviar este certificado al correo de prueba autorizado del participante?');"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf"
                                                    value="<?= e($_SESSION['csrf_certificados_test']) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="accion"
                                                    value="individual"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="id_participante"
                                                    value="<?= (int)$p['id_participante'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-success btn-accion"
                                                >
                                                    Enviar correo
                                                </button>

                                            </form>

                                        <?php elseif ($correoEnviado): ?>

                                            <span class="badge bg-success align-self-center">
                                                Correo enviado
                                            </span>

                                        <?php endif; ?>

                                    <?php elseif (
                                        $esApto &&
                                        $totalSesiones === 6
                                    ): ?>

                                        <!-- GENERAR CERTIFICADO -->

                                        <form
                                            method="POST"
                                            action="procesar_certificados_test.php"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf"
                                                value="<?= e($_SESSION['csrf_certificados_test']) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="accion"
                                                value="individual"
                                            >

                                            <input
                                                type="hidden"
                                                name="id_participante"
                                                value="<?= (int)$p['id_participante'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-primary btn-accion"
                                            >
                                                Generar certificado
                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            No disponible
                                        </span>

                                    <?php endif; ?>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

    <!-- ====================================
         INFORMACIÓN ACTUALIZADA
    ===================================== -->

    <div class="alert alert-light border mt-4">

        <strong>Información:</strong>

        <p class="mb-1 mt-2">

            El congreso se desarrollará los días
            2, 3 y 4 de diciembre de 2026.

        </p>

        <p class="mb-1">

            Total de sesiones configuradas:
            <strong><?= $totalSesiones ?></strong>.

        </p>

        <p class="mb-0">

            Para ser considerado APTO,
            el participante debe estar activo
            y registrar al menos
            <strong><?= $minimoAsistencias ?></strong>
            asistencias válidas.

        </p>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

// Evitar doble clic en formularios de envío.
document.querySelectorAll('.form-envio')
    .forEach(function(formulario) {

        formulario.addEventListener('submit', function(evento) {

            if (evento.defaultPrevented) {
                return;
            }

            const boton = formulario.querySelector(
                'button[type="submit"]'
            );

            if (boton) {
                boton.disabled = true;
                boton.textContent = 'Procesando...';
            }
        });
    });

</script>

</body>
</html>
