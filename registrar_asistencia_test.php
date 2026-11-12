
<?php
session_start();
date_default_timezone_set('America/Lima');

// ============================================
// 1. SEGURIDAD Y BASE DE DATOS
// ============================================

if (!isset($_SESSION['id_usuario'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/database_test.php';

if (!isset($conexion) || !($conexion instanceof PDO)) {
    exit('No se encontró la conexión.');
}

$conexion->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);

if (
    $conexion->query('SELECT DATABASE()')->fetchColumn()
    !== 'congreso_puno_pruebas'
) {
    http_response_code(403);
    exit('Solo se permite utilizar la base de pruebas.');
}

if (empty($_SESSION['csrf_asistencia_test'])) {
    $_SESSION['csrf_asistencia_test'] = bin2hex(random_bytes(32));
}

// ============================================
// 2. FUNCIONES
// ============================================

function e($valor): string
{
    return htmlspecialchars(
        (string)($valor ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function flash(string $mensaje, string $tipo = 'success'): void
{
    $_SESSION['flash_asistencia_test'] = [
        'mensaje' => $mensaje,
        'tipo' => $tipo
    ];
}

function regresar(string $buscar = ''): void
{
    $url = 'registrar_asistencia_test.php';

    if ($buscar !== '') {
        $url .= '?buscar=' . urlencode($buscar);
    }

    header('Location: ' . $url, true, 303);
    exit;
}

// ============================================
// 3. OPCIONES DEL FORMULARIO
// ============================================

$generos = [
    'Femenino',
    'Masculino',
    'Prefiero no decirlo'
];

$edades = [
    'Menor de 18 años',
    '18 – 24 años',
    '25 – 34 años',
    '35 – 44 años',
    '45 – 54 años',
    '55 – 64 años',
    '65 años o más'
];

$grados = [
    'Educación básica (Primaria/Secundaria)',
    'Técnico',
    'Estudiante de educación superior',
    'Bachiller',
    'Licenciado / Ingeniero',
    'Magíster',
    'Doctorado (Ph.D.)'
];

$sectores = [
    'Academia (universidades e institutos)',
    'Estado',
    'Productores y organizaciones de productores',
    'Empresa privada',
    'Cooperación internacional / ONG',
    'Independiente'
];

$ocupaciones = [
    'Extensionista Agropecuario',
    'Productor Agropecuario',
    'Estudiante',
    'Investigador',
    'Docente Universitario',
    'Consultor Independiente',
    'Técnico Agropecuario',
    'Ingeniero Agrónomo',
    'Médico Veterinario',
    'Ingeniero Zootecnista',
    'Ingeniero Agrícola',
    'Ingeniero Agroindustrial',
    'Ingeniero Forestal',
    'Ingeniero Ambiental',
    'Ingeniero Biotecnólogo',
    'Biólogo',
    'Economista',
    'Otros'
];

// 24 departamentos + Callao.
$departamentos = [
    'Amazonas',
    'Áncash',
    'Apurímac',
    'Arequipa',
    'Ayacucho',
    'Cajamarca',
    'Callao',
    'Cusco',
    'Huancavelica',
    'Huánuco',
    'Ica',
    'Junín',
    'La Libertad',
    'Lambayeque',
    'Lima',
    'Loreto',
    'Madre de Dios',
    'Moquegua',
    'Pasco',
    'Piura',
    'Puno',
    'San Martín',
    'Tacna',
    'Tumbes',
    'Ucayali'
];

// ============================================
// 4. OBTENER SESIONES DEL CONGRESO
// ============================================

$stmt = $conexion->prepare("
    SELECT
        id_sesion,
        codigo_sesion,
        fecha,
        turno
    FROM sesiones
    WHERE fecha BETWEEN ? AND ?
    ORDER BY fecha, id_sesion
");

$stmt->execute([
    '2026-12-02',
    '2026-12-04'
]);

$sesiones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sesionesPorId = [];

foreach ($sesiones as $s) {
    $sesionesPorId[(int)$s['id_sesion']] = $s;
}

$totalSesiones = count($sesiones);

// ============================================
// 5. PROCESAR FORMULARIOS
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals(
            $_SESSION['csrf_asistencia_test'],
            $token
        )
    ) {
        http_response_code(403);
        exit('Token de seguridad inválido.');
    }

    $accion = $_POST['accion'] ?? '';
    $idUsuario = (int)$_SESSION['id_usuario'];

    // ========================================
    // A. REGISTRAR ASISTENCIA
    // ========================================

    if ($accion === 'registrar_asistencia') {

        $idParticipante = filter_var(
            $_POST['id_participante'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $idSesion = filter_var(
            $_POST['id_sesion'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $buscarActual = trim(
            (string)($_POST['buscar_actual'] ?? '')
        );

        if (
            !$idParticipante ||
            !$idSesion ||
            !isset($sesionesPorId[$idSesion])
        ) {
            flash(
                'Participante o sesión inválida.',
                'danger'
            );

            regresar($buscarActual);
        }

        try {

            $conexion->beginTransaction();

            $stmt = $conexion->prepare("
                SELECT
                    id_participante,
                    nombres_completos,
                    estado
                FROM participantes
                WHERE id_participante = ?
                FOR UPDATE
            ");

            $stmt->execute([$idParticipante]);
            $participante = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                !$participante ||
                $participante['estado'] !== 'ACTIVO'
            ) {
                throw new RuntimeException(
                    'Participante no disponible.'
                );
            }

            $stmt = $conexion->prepare("
                SELECT COUNT(*)
                FROM asistencias
                WHERE id_participante = ?
                  AND id_sesion = ?
            ");

            $stmt->execute([
                $idParticipante,
                $idSesion
            ]);

            if ((int)$stmt->fetchColumn() > 0) {

                $conexion->commit();

                flash(
                    'Esta asistencia ya estaba registrada.',
                    'warning'
                );

                regresar($buscarActual);
            }

            $stmt = $conexion->prepare("
                INSERT INTO asistencias
                    (
                        id_participante,
                        id_sesion,
                        id_usuario,
                        fecha_hora
                    )
                VALUES (?, ?, ?, NOW())
            ");

            $stmt->execute([
                $idParticipante,
                $idSesion,
                $idUsuario
            ]);

            $conexion->commit();

            flash(
                'Asistencia registrada correctamente para ' .
                $participante['nombres_completos'] .
                ' - ' .
                $sesionesPorId[$idSesion]['codigo_sesion']
            );

        } catch (Throwable $ex) {

            if ($conexion->inTransaction()) {
                $conexion->rollBack();
            }

            error_log($ex->getMessage());

            flash(
                'No se pudo registrar la asistencia.',
                'danger'
            );
        }

        regresar($buscarActual);
    }

    // ========================================
    // B. INSCRIBIR NUEVO PARTICIPANTE
    // ========================================

    if ($accion === 'nuevo_participante') {

        // Obtener datos del formulario.
        $documento = strtoupper(trim(
            (string)($_POST['documento'] ?? '')
        ));

        $nombres = mb_strtoupper(
            trim((string)($_POST['nombres_completos'] ?? '')),
            'UTF-8'
        );

        $correo = strtolower(trim(
            (string)($_POST['correo'] ?? '')
        ));

        $genero = trim(
            (string)($_POST['genero'] ?? '')
        );

        $edad = trim(
            (string)($_POST['rango_edad'] ?? '')
        );

        $celular = trim(
            (string)($_POST['celular'] ?? '')
        );

        $grado = trim(
            (string)($_POST['grado_academico'] ?? '')
        );

        $sector = trim(
            (string)($_POST['sector'] ?? '')
        );

        $institucion = trim(
            (string)($_POST['institucion'] ?? '')
        );

        $ocupacionSeleccionada = trim(
            (string)($_POST['ocupacion'] ?? '')
        );

        $ocupacionOtro = trim(
            (string)($_POST['ocupacion_otro'] ?? '')
        );

        $ubicacion = trim(
            (string)($_POST['ubicacion_actual'] ?? '')
        );

        $departamento = trim(
            (string)($_POST['departamento'] ?? '')
        );

        $pais = trim(
            (string)($_POST['pais_extranjero'] ?? '')
        );

        $voluntario = strtoupper(trim(
            (string)($_POST['codigo_voluntario'] ?? '')
        ));

        // ====================================
        // VALIDACIONES DEL SERVIDOR
        // ====================================

        $errores = [];

        if (!preg_match(
            '/^[A-Z0-9-]{5,20}$/D',
            $documento
        )) {
            $errores[] =
                'El DNI o pasaporte no es válido.';
        }

        if (
            mb_strlen($nombres) < 3 ||
            mb_strlen($nombres) > 200
        ) {
            $errores[] =
                'Ingresa los nombres y apellidos completos.';
        }

        if (
            !filter_var(
                $correo,
                FILTER_VALIDATE_EMAIL
            ) ||
            strlen($correo) > 255
        ) {
            $errores[] =
                'Ingresa un correo electrónico válido.';
        }

        if (!in_array(
            $genero,
            $generos,
            true
        )) {
            $errores[] =
                'Selecciona un género válido.';
        }

        if (!in_array(
            $edad,
            $edades,
            true
        )) {
            $errores[] =
                'Selecciona un rango de edad.';
        }

        if (!preg_match(
            '/^[0-9+() -]{6,25}$/D',
            $celular
        )) {
            $errores[] =
                'Ingresa un celular válido.';
        }

        if (!in_array(
            $grado,
            $grados,
            true
        )) {
            $errores[] =
                'Selecciona un grado académico.';
        }

        if (!in_array(
            $sector,
            $sectores,
            true
        )) {
            $errores[] =
                'Selecciona el sector.';
        }

        if (mb_strlen($institucion) > 200) {
            $errores[] =
                'El nombre de la institución es demasiado largo.';
        }

        // Ocupación: opción "Otros".
        if (!in_array(
            $ocupacionSeleccionada,
            $ocupaciones,
            true
        )) {
            $errores[] =
                'Selecciona una ocupación válida.';
        }

        if ($ocupacionSeleccionada === 'Otros') {

            if (
                $ocupacionOtro === '' ||
                mb_strlen($ocupacionOtro) > 150
            ) {
                $errores[] =
                    'Debes especificar tu ocupación.';
            }

            $ocupacion = $ocupacionOtro;

        } else {

            $ocupacion = $ocupacionSeleccionada;
        }

        // ====================================
        // VALIDACIÓN DE UBICACIÓN
        // ====================================

        if (!in_array(
            $ubicacion,
            [
                'Territorio peruano',
                'Territorio fuera del Perú'
            ],
            true
        )) {
            $errores[] =
                'Selecciona una ubicación válida.';
        }

        if ($ubicacion === 'Territorio peruano') {

            // Debe seleccionar un departamento.
            if (!in_array(
                $departamento,
                $departamentos,
                true
            )) {
                $errores[] =
                    'Selecciona un departamento válido.';
            }

            // No corresponde un país extranjero.
            $pais = '';

        } elseif (
            $ubicacion === 'Territorio fuera del Perú'
        ) {

            // Debe indicar país.
            if (
                $pais === '' ||
                mb_strlen($pais) > 100
            ) {
                $errores[] =
                    'Debes indicar el país donde te encuentras.';
            }

            // No corresponde departamento peruano.
            $departamento = '';
        }

        if (mb_strlen($voluntario) > 20) {
            $errores[] =
                'Código de voluntario demasiado largo.';
        }

        if ($errores) {

            flash(
                implode(' ', $errores),
                'danger'
            );

            regresar();
        }

        // ====================================
        // GUARDAR EN MYSQL
        // ====================================

        try {

            $conexion->beginTransaction();

            // Verificar DNI duplicado.
            $stmt = $conexion->prepare("
                SELECT id_participante
                FROM participantes
                WHERE documento = ?
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([$documento]);

            if ($stmt->fetch()) {

                $conexion->rollBack();

                flash(
                    'Este documento ya está inscrito. ' .
                    'Busca al participante para consultar sus asistencias.',
                    'warning'
                );

                regresar($documento);
            }

            // Verificar correo duplicado.
            $stmt = $conexion->prepare("
                SELECT id_participante
                FROM participantes
                WHERE LOWER(TRIM(correo)) = ?
                LIMIT 1
            ");

            $stmt->execute([$correo]);

            if ($stmt->fetch()) {

                $conexion->rollBack();

                flash(
                    'Este correo electrónico ya está registrado. ' .
                    'Verifica los datos o utiliza otro correo.',
                    'warning'
                );

                regresar();
            }

            // Generar código de inscripción.
            $codigo = 'CONG-PRUEBA-' .
                strtoupper(bin2hex(random_bytes(8)));

            // Insertar todos los campos.
            $stmt = $conexion->prepare("
                INSERT INTO participantes (
                    documento,
                    nombres_completos,
                    correo,
                    codigo_inscripcion,
                    institucion,
                    genero,
                    rango_edad,
                    celular,
                    grado_academico,
                    sector,
                    ocupacion,
                    ubicacion_actual,
                    departamento,
                    pais_extranjero,
                    codigo_voluntario,
                    estado
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, 'ACTIVO'
                )
            ");

            $stmt->execute([
                $documento,
                $nombres,
                $correo,
                $codigo,
                $institucion !== ''
                    ? $institucion
                    : null,
                $genero,
                $edad,
                $celular,
                $grado,
                $sector,
                $ocupacion,
                $ubicacion,
                $departamento !== ''
                    ? $departamento
                    : null,
                $pais !== ''
                    ? $pais
                    : null,
                $voluntario !== ''
                    ? $voluntario
                    : null
            ]);

            $conexion->commit();

            flash(
                'Participante inscrito correctamente: ' .
                $nombres .
                '. Código: ' . $codigo .
                '. Ahora puedes registrar sus asistencias.'
            );

            regresar($documento);

        } catch (Throwable $ex) {

            if ($conexion->inTransaction()) {
                $conexion->rollBack();
            }

            error_log($ex->getMessage());

            $duplicado = $ex instanceof PDOException
                && (int)($ex->errorInfo[1] ?? 0) === 1062;

            flash(
                $duplicado
                    ? 'Ya existe un participante con ese documento, correo o código.'
                    : 'No se pudo completar la inscripción.',
                $duplicado ? 'warning' : 'danger'
            );

            regresar();
        }
    }

    http_response_code(400);
    exit('Acción desconocida.');
}

// ============================================
// 6. BUSCAR PARTICIPANTES
// ============================================

$buscar = trim(
    (string)($_GET['buscar'] ?? '')
);

$buscar = mb_substr(
    $buscar,
    0,
    100
);

$resultados = [];

if ($buscar !== '') {

    $stmt = $conexion->prepare("
        SELECT
            p.id_participante,
            p.documento,
            p.nombres_completos,
            p.correo,
            p.codigo_inscripcion,
            p.estado,

            (
                SELECT COUNT(DISTINCT a.id_sesion)
                FROM asistencias a
                INNER JOIN sesiones s
                    ON s.id_sesion = a.id_sesion
                WHERE a.id_participante = p.id_participante
                  AND s.fecha BETWEEN
                    '2026-12-02' AND '2026-12-04'
            ) AS total_asistencias

        FROM participantes p

        WHERE p.documento LIKE ?
           OR p.nombres_completos LIKE ?
           OR p.codigo_inscripcion LIKE ?

        ORDER BY p.nombres_completos
        LIMIT 30
    ");

    $termino = '%' . $buscar . '%';

    $stmt->execute([
        $termino,
        $termino,
        $termino
    ]);

    $resultados = $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );

    if ($resultados) {

        $ids = array_map(
            'intval',
            array_column(
                $resultados,
                'id_participante'
            )
        );

        $marcadores = implode(
            ',',
            array_fill(
                0,
                count($ids),
                '?'
            )
        );

        $stmt = $conexion->prepare("
            SELECT
                a.id_participante,
                a.id_sesion
            FROM asistencias a

            INNER JOIN sesiones s
                ON s.id_sesion = a.id_sesion

            WHERE a.id_participante IN ($marcadores)
              AND s.fecha BETWEEN
                    '2026-12-02' AND '2026-12-04'
        ");

        $stmt->execute($ids);

        $mapaAsistencias = [];

        foreach (
            $stmt->fetchAll(PDO::FETCH_ASSOC) as $a
        ) {

            $mapaAsistencias[
                (int)$a['id_participante']
            ][
                (int)$a['id_sesion']
            ] = true;
        }

        foreach ($resultados as &$p) {

            $p['asistencias'] =
                $mapaAsistencias[
                    (int)$p['id_participante']
                ] ?? [];
        }

        unset($p);
    }
}

$flashActual =
    $_SESSION['flash_asistencia_test'] ?? null;

unset($_SESSION['flash_asistencia_test']);

$nombreUsuario =
    $_SESSION['nombre'] ?? 'Usuario';

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
        Inscripción y Asistencias - Congreso Puno 2026
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
            --azul: #17406b;
            --fondo: #f3f6fb;
        }

        body {
            background: var(--fondo);
            font-family: Arial, sans-serif;
            color: #233248;
        }

        .contenedor {
            max-width: 1150px;
            margin: 30px auto 60px;
        }

        .cabecera {
            padding: 26px;
            color: white;
            background: linear-gradient(
                135deg,
                #0f2c4a,
                #2468a4
            );
            border-radius: 18px;
        }

        .tarjeta {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            box-shadow: 0 3px 16px rgba(15,44,74,.05);
        }

        .tarjeta .card-body {
            padding: 26px;
        }

        .titulo {
            font-weight: 700;
            color: var(--azul);
            margin-bottom: 20px;
        }

        .form-control,
        .form-select {
            border-radius: 10px;
            padding: 11px 13px;
        }

        .btn {
            border-radius: 9px;
        }

        .btn-primary {
            background: #2468a4;
            border-color: #2468a4;
        }

        .participante-card {
            border: 1px solid #dfe7f0;
            border-radius: 15px;
            padding: 22px;
            margin-bottom: 20px;
        }

        .sesiones-grid {
            display: grid;
            grid-template-columns:
                repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 20px;
        }

        .cuadro-sesion {
            border: 1px solid #d4dee8;
            background: white;
            border-radius: 13px;
            padding: 16px 10px;
            text-align: center;
            min-height: 164px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 8px;
        }

        .cuadro-sesion.registrada {
            background: #dcfce7;
            border: 2px solid #16a34a;
            color: #166534;
        }

        .cuadro-sesion.pendiente {
            background: white;
            color: #475569;
        }

        .cuadro-sesion .icono {
            font-size: 28px;
        }

        .cuadro-sesion .codigo {
            font-weight: 700;
            font-size: .92rem;
        }

        .cuadro-sesion .detalle {
            font-size: .76rem;
        }

        .cuadro-sesion .estado {
            font-size: .76rem;
            font-weight: 700;
        }

        .cuadro-sesion .btn {
            font-size: .78rem;
            padding: 7px 10px;
        }

        .barra-progreso {
            height: 9px;
            border-radius: 20px;
        }

        .seccion-formulario {
            background: #f8fbff;
            border: 1px solid #e1eaf4;
            border-radius: 13px;
            padding: 22px;
            margin-bottom: 20px;
        }

        .seccion-formulario h5 {
            color: var(--azul);
            font-weight: 700;
            margin-bottom: 20px;
        }

        .requerido {
            color: #dc2626;
        }

        @media (max-width: 900px) {
            .sesiones-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 480px) {

            .sesiones-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
                gap: 8px;
            }

            .cuadro-sesion {
                padding: 12px 7px;
            }

            .tarjeta .card-body {
                padding: 18px;
            }

            .participante-card {
                padding: 15px;
            }
        }

    </style>

</head>

<body>

<div class="container contenedor">

    <!-- ====================================== -->
    <!-- CABECERA -->
    <!-- ====================================== -->

    <div class="cabecera mb-4">

        <div class="d-flex justify-content-between
                    align-items-center flex-wrap gap-3">

            <div>

                <span class="badge bg-warning text-dark mb-2">
                    MODO PRUEBAS
                </span>

                <h3 class="fw-bold mb-1">
                    Inscripción y Registro de Asistencias
                </h3>

                <p class="mb-0 opacity-75">
                    II Congreso Peruano de Extensión Agropecuaria
                    — Puno 2026
                </p>

            </div>

            <div>
                <i class="bi bi-person-circle me-1"></i>
                <?= e($nombreUsuario) ?>
            </div>

        </div>

    </div>

    <!-- ====================================== -->
    <!-- MENSAJES -->
    <!-- ====================================== -->

    <?php if ($flashActual): ?>

        <div class="alert alert-<?= e($flashActual['tipo']) ?>
                    alert-dismissible fade show">

            <?= e($flashActual['mensaje']) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>

    <!-- ====================================== -->
    <!-- 1. BUSCAR PARTICIPANTE -->
    <!-- ====================================== -->

    <div class="tarjeta mb-4">

        <div class="card-body">

            <h4 class="titulo">
                <i class="bi bi-search me-2"></i>
                1. Buscar participante y registrar asistencias
            </h4>

            <p class="text-muted">

                Busca por DNI, pasaporte, nombres o
                código de inscripción.

                Los cuadros verdes representan
                asistencias registradas y los blancos
                representan sesiones pendientes.

            </p>

            <form method="GET" class="mb-4">

                <div class="input-group">

                    <input
                        type="text"
                        name="buscar"
                        class="form-control"
                        placeholder="DNI, nombre o código"
                        value="<?= e($buscar) ?>"
                        maxlength="100"
                        required
                    >

                    <button class="btn btn-primary px-4">
                        <i class="bi bi-search me-1"></i>
                        Buscar
                    </button>

                </div>

            </form>

            <div id="resultados">

                <?php if ($buscar !== ''): ?>

                    <?php if (!$resultados): ?>

                        <div class="alert alert-warning">

                            No se encontraron participantes.

                            Puedes inscribirlos en el
                            formulario inferior.

                        </div>

                    <?php else: ?>

                        <p class="small text-muted">
                            <?= count($resultados) ?>
                            participante(s) encontrado(s).
                        </p>

                        <?php foreach ($resultados as $p): ?>

                            <?php

                            $asistidas =
                                (int)$p['total_asistencias'];

                            $porcentaje = $totalSesiones > 0
                                ? min(
                                    100,
                                    round(
                                        ($asistidas / $totalSesiones) * 100
                                    )
                                )
                                : 0;

                            ?>

                            <div class="participante-card">

                                <div class="d-flex justify-content-between
                                            align-items-start flex-wrap gap-2">

                                    <div>

                                        <h5 class="fw-bold mb-1">

                                            <i class="bi bi-person-circle
                                                      text-primary me-1"></i>

                                            <?= e($p['nombres_completos']) ?>

                                        </h5>

                                        <div class="small text-muted">
                                            Documento:
                                            <strong>
                                                <?= e($p['documento']) ?>
                                            </strong>
                                        </div>

                                        <div class="small text-muted">
                                            Código:
                                            <?= e($p['codigo_inscripcion']) ?>
                                        </div>

                                        <div class="small text-muted">
                                            Correo:
                                            <?= e(
                                                $p['correo'] ?: 'No registrado'
                                            ) ?>
                                        </div>

                                    </div>

                                    <span class="badge bg-primary">

                                        <?= $asistidas ?>
                                        de
                                        <?= $totalSesiones ?>
                                        asistencias

                                    </span>

                                </div>

                                <div class="mt-3">

                                    <div class="progress barra-progreso">

                                        <div
                                            class="progress-bar bg-success"
                                            style="width: <?= $porcentaje ?>%"
                                        ></div>

                                    </div>

                                    <small class="text-muted">
                                        Progreso: <?= $porcentaje ?>%
                                    </small>

                                </div>

                                <!-- CUADROS DE SESIONES -->

                                <div class="sesiones-grid">

                                    <?php foreach ($sesiones as $sesion): ?>

                                        <?php

                                        $idSesion =
                                            (int)$sesion['id_sesion'];

                                        $registrada = isset(
                                            $p['asistencias'][$idSesion]
                                        );

                                        ?>

                                        <div class="cuadro-sesion
                                            <?= $registrada
                                                ? 'registrada'
                                                : 'pendiente' ?>">

                                            <div class="codigo">
                                                <?= e(
                                                    $sesion['codigo_sesion']
                                                ) ?>
                                            </div>

                                            <div class="detalle">

                                                <?= e($sesion['fecha']) ?>

                                                <div>
                                                    <?= e($sesion['turno']) ?>
                                                </div>

                                            </div>

                                            <?php if ($registrada): ?>

                                                <div>
                                                    <i class="bi bi-check-circle-fill
                                                              icono"></i>
                                                </div>

                                                <div class="estado">
                                                    Registrado
                                                </div>

                                            <?php elseif (
                                                $p['estado'] !== 'ACTIVO'
                                            ): ?>

                                                <div class="estado
                                                            text-secondary">
                                                    Inactivo
                                                </div>

                                            <?php else: ?>

                                                <div>
                                                    <i class="bi bi-circle icono
                                                              text-secondary"></i>
                                                </div>

                                                <div class="estado">
                                                    Pendiente
                                                </div>

                                                <form
                                                    method="POST"
                                                    class="formRegistro"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= e(
                                                            $_SESSION[
                                                                'csrf_asistencia_test'
                                                            ]
                                                        ) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="accion"
                                                        value="registrar_asistencia"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="id_participante"
                                                        value="<?= (int)$p[
                                                            'id_participante'
                                                        ] ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="id_sesion"
                                                        value="<?= $idSesion ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="buscar_actual"
                                                        value="<?= e($buscar) ?>"
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="btn btn-outline-success
                                                               w-100"
                                                    >
                                                        <i class="bi bi-check-lg"></i>
                                                        Registrar
                                                    </button>

                                                </form>

                                            <?php endif; ?>

                                        </div>

                                    <?php endforeach; ?>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                <?php else: ?>

                    <div class="text-center text-muted py-4">

                        <i class="bi bi-search fs-1
                                  d-block mb-2"></i>

                        Busca un participante para consultar
                        sus seis sesiones.

                    </div>

                <?php endif; ?>

            </div>

        </div>
    </div>

    <!-- ====================================== -->
    <!-- 2. INSCRIBIR PARTICIPANTE -->
    <!-- ====================================== -->

    <div class="tarjeta">

        <div class="card-body">

            <h4 class="titulo">
                <i class="bi bi-person-plus me-2"></i>
                2. Inscribir nuevo participante
            </h4>

            <p class="text-muted">

                Complete los datos solicitados para
                registrar al participante en el congreso.

            </p>

            <div class="alert alert-info">

                La inscripción no registra una
                asistencia automáticamente.

                Después de inscribir al participante,
                busca su documento para registrar
                las sesiones correspondientes.

            </div>

            <form
                method="POST"
                id="formNuevo"
                class="formRegistro"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(
                        $_SESSION['csrf_asistencia_test']
                    ) ?>"
                >

                <input
                    type="hidden"
                    name="accion"
                    value="nuevo_participante"
                >

                <!-- ================================= -->
                <!-- DATOS PERSONALES -->
                <!-- ================================= -->

                <div class="seccion-formulario">

                    <h5>
                        <i class="bi bi-person-vcard me-2"></i>
                        Datos personales
                    </h5>

                    <div class="row g-3">

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Correo electrónico
                                <span class="requerido">*</span>
                            </label>

                            <input
                                type="email"
                                name="correo"
                                class="form-control"
                                placeholder="ejemplo@gmail.com"
                                maxlength="255"
                                required
                            >

                            <div class="form-text">
                                Se utilizará para el certificado
                                y las comunicaciones del congreso.
                            </div>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                DNI / Pasaporte
                                <span class="requerido">*</span>
                            </label>

                            <input
                                type="text"
                                name="documento"
                                class="form-control"
                                minlength="5"
                                maxlength="20"
                                required
                            >

                        </div>

                        <div class="col-12">

                            <label class="form-label fw-semibold">
                                Nombres y apellidos completos
                                <span class="requerido">*</span>
                            </label>

                            <input
                                type="text"
                                name="nombres_completos"
                                id="nombres_completos"
                                class="form-control"
                                placeholder="PEDRO JOSE SANCHEZ CARRIÓN"
                                maxlength="200"
                                required
                            >

                            <div class="form-text">
                                Escribir como debe aparecer
                                en el certificado.
                            </div>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Género
                                <span class="requerido">*</span>
                            </label>

                            <select
                                name="genero"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Seleccionar
                                </option>

                                <?php foreach ($generos as $opcion): ?>

                                    <option value="<?= e($opcion) ?>">
                                        <?= e($opcion) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Rango de edad
                                <span class="requerido">*</span>
                            </label>

                            <select
                                name="rango_edad"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Seleccionar
                                </option>

                                <?php foreach ($edades as $opcion): ?>

                                    <option value="<?= e($opcion) ?>">
                                        <?= e($opcion) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Celular
                                <span class="requerido">*</span>
                            </label>

                            <input
                                type="tel"
                                name="celular"
                                class="form-control"
                                placeholder="987654321"
                                maxlength="25"
                                required
                            >

                        </div>

                    </div>

                </div>

                <!-- ================================= -->
                <!-- INFORMACIÓN ACADÉMICA Y LABORAL -->
                <!-- ================================= -->

                <div class="seccion-formulario">

                    <h5>
                        <i class="bi bi-mortarboard me-2"></i>
                        Información académica y laboral
                    </h5>

                    <div class="row g-3">

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Grado académico
                                <span class="requerido">*</span>
                            </label>

                            <select
                                name="grado_academico"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Seleccionar
                                </option>

                                <?php foreach ($grados as $opcion): ?>

                                    <option value="<?= e($opcion) ?>">
                                        <?= e($opcion) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Sector al que pertenece
                                <span class="requerido">*</span>
                            </label>

                            <select
                                name="sector"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Seleccionar
                                </option>

                                <?php foreach ($sectores as $opcion): ?>

                                    <option value="<?= e($opcion) ?>">
                                        <?= e($opcion) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="col-12">

                            <label class="form-label fw-semibold">
                                Nombre de la institución
                            </label>

                            <input
                                type="text"
                                name="institucion"
                                class="form-control"
                                placeholder="Nombre de institución"
                                maxlength="200"
                            >

                        </div>

                        <div class="col-12">

                            <label class="form-label fw-semibold">
                                Ocupación / Profesión actual
                                <span class="requerido">*</span>
                            </label>

                            <select
                                name="ocupacion"
                                id="ocupacion"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Seleccionar
                                </option>

                                <?php foreach ($ocupaciones as $opcion): ?>

                                    <option value="<?= e($opcion) ?>">
                                        <?= e($opcion) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div
                            class="col-12"
                            id="grupoOtraOcupacion"
                            style="display:none"
                        >

                            <label class="form-label fw-semibold">
                                Especifique su ocupación
                                <span class="requerido">*</span>
                            </label>

                            <input
                                type="text"
                                name="ocupacion_otro"
                                id="ocupacion_otro"
                                class="form-control"
                                maxlength="150"
                            >

                        </div>

                    </div>

                </div>

                <!-- ================================= -->
                <!-- UBICACIÓN Y REGISTRO -->
                <!-- ================================= -->

                <div class="seccion-formulario">

                    <h5>
                        <i class="bi bi-geo-alt me-2"></i>
                        Ubicación y registro
                    </h5>

                    <div class="row g-3">

                        <!-- UBICACIÓN ACTUAL -->

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Ubicación actual
                                <span class="requerido">*</span>
                            </label>

                            <select
                                name="ubicacion_actual"
                                id="ubicacion_actual"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Seleccionar ubicación
                                </option>

                                <option value="Territorio peruano">
                                    Territorio peruano
                                </option>

                                <option value="Territorio fuera del Perú">
                                    Territorio fuera del Perú
                                </option>

                            </select>

                        </div>

                        <!-- DEPARTAMENTO DEL PERÚ -->

                        <div
                            class="col-md-6"
                            id="grupoDepartamento"
                            style="display:none"
                        >

                            <label class="form-label fw-semibold">
                                Departamento de procedencia
                                <span class="requerido">*</span>
                            </label>

                            <select
                                name="departamento"
                                id="departamento"
                                class="form-select"
                            >

                                <option value="">
                                    Seleccionar departamento
                                </option>

                                <?php foreach ($departamentos as $dep): ?>

                                    <option value="<?= e($dep) ?>">
                                        <?= e($dep) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <!-- PAÍS EXTRANJERO -->

                        <div
                            class="col-md-6"
                            id="grupoPais"
                            style="display:none"
                        >

                            <label class="form-label fw-semibold">
                                País donde se encuentra
                                <span class="requerido">*</span>
                            </label>

                            <input
                                type="text"
                                name="pais_extranjero"
                                id="pais_extranjero"
                                class="form-control"
                                maxlength="100"
                                placeholder="Ejemplo: Bolivia"
                            >

                        </div>

                        <!-- VOLUNTARIO -->

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Código de voluntario (opcional)
                            </label>

                            <input
                                type="text"
                                name="codigo_voluntario"
                                class="form-control"
                                maxlength="20"
                                placeholder="Ejemplo: V05"
                            >

                        </div>

                    </div>

                </div>

                <!-- ================================= -->
                <!-- CONFIRMACIÓN -->
                <!-- ================================= -->

                <div class="alert alert-secondary">

                    <i class="bi bi-info-circle me-2"></i>

                    Al guardar, el sistema generará
                    automáticamente el código de inscripción.

                    Después podrás buscar al participante
                    para registrar sus asistencias.

                </div>

                <button
                    type="submit"
                    class="btn btn-primary btn-lg w-100 py-3"
                >

                    <i class="bi bi-person-check me-2"></i>
                    Inscribir participante

                </button>

            </form>

        </div>

    </div>

    <!-- ====================================== -->
    <!-- VOLVER AL PANEL -->
    <!-- ====================================== -->

    <div class="text-center mt-4">

        <a
            href="dashboard.php"
            class="btn btn-outline-secondary px-4"
        >

            <i class="bi bi-arrow-left me-1"></i>
            Volver al panel principal

        </a>

    </div>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>

<script>

// ============================================
// NOMBRES EN MAYÚSCULAS
// ============================================

const campoNombres =
    document.getElementById('nombres_completos');

campoNombres.addEventListener('input', function () {
    this.value = this.value.toLocaleUpperCase('es-PE');
});

// ============================================
// OCUPACIÓN "OTROS"
// ============================================

const ocupacion =
    document.getElementById('ocupacion');

const grupoOtra =
    document.getElementById('grupoOtraOcupacion');

const campoOtra =
    document.getElementById('ocupacion_otro');

function actualizarOcupacion() {

    const esOtro = ocupacion.value === 'Otros';

    grupoOtra.style.display =
        esOtro ? 'block' : 'none';

    campoOtra.required = esOtro;

    if (!esOtro) {
        campoOtra.value = '';
    }
}

ocupacion.addEventListener(
    'change',
    actualizarOcupacion
);

actualizarOcupacion();

// ============================================
// DEPARTAMENTO O PAÍS EXTRANJERO
// ============================================

const ubicacion =
    document.getElementById('ubicacion_actual');

const grupoDepartamento =
    document.getElementById('grupoDepartamento');

const departamento =
    document.getElementById('departamento');

const grupoPais =
    document.getElementById('grupoPais');

const campoPais =
    document.getElementById('pais_extranjero');

function actualizarUbicacion() {

    const esPeru =
        ubicacion.value === 'Territorio peruano';

    const esExtranjero =
        ubicacion.value === 'Territorio fuera del Perú';

    // Mostrar departamento si está en Perú.
    grupoDepartamento.style.display =
        esPeru ? 'block' : 'none';

    // Mostrar país si está en el extranjero.
    grupoPais.style.display =
        esExtranjero ? 'block' : 'none';

    // Configurar campos obligatorios.
    departamento.required = esPeru;
    campoPais.required = esExtranjero;

    // Limpiar opciones que no corresponden.
    if (!esPeru) {
        departamento.value = '';
    }

    if (!esExtranjero) {
        campoPais.value = '';
    }
}

ubicacion.addEventListener(
    'change',
    actualizarUbicacion
);

actualizarUbicacion();

// ============================================
// EVITAR DOBLE ENVÍO
// ============================================

document.querySelectorAll('.formRegistro').forEach(
    function(formulario) {

        formulario.addEventListener(
            'submit',
            function() {

                if (!formulario.checkValidity()) {
                    return;
                }

                const boton = formulario.querySelector(
                    'button[type="submit"]'
                );

                if (boton) {

                    boton.disabled = true;

                    boton.innerHTML =
                        '<span class="spinner-border ' +
                        'spinner-border-sm me-2"></span>' +
                        'Procesando...';
                }
            }
        );
    }
);

// ============================================
// SCROLL A RESULTADOS
// ============================================

<?php if ($buscar !== ''): ?>

window.addEventListener('load', function() {

    const resultados =
        document.getElementById('resultados');

    if (resultados) {

        resultados.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
    }
});

<?php endif; ?>

</script>

</body>
</html>
