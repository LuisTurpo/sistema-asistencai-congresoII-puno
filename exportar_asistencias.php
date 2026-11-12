
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

if (strtoupper($_SESSION['rol'] ?? '') !== 'ADMIN') {
    http_response_code(403);
    exit('Acceso denegado.');
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database_test.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

if (!isset($conexion) || !($conexion instanceof PDO)) {
    http_response_code(500);
    exit('Error de conexión.');
}

$conexion->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);

// Protección de la base oficial.
if (
    $conexion->query('SELECT DATABASE()')->fetchColumn()
    !== 'congreso_puno_pruebas'
) {
    http_response_code(500);
    exit('Base de datos incorrecta.');
}

// ==========================================
// 2. CONFIGURACIÓN
// ==========================================

// Regla provisional de certificación.
$minimoAsistencias = 5;

// ==========================================
// 3. FILTROS
// ==========================================

$fecha = trim((string)($_GET['fecha'] ?? ''));

$idSesion = filter_var(
    $_GET['sesion'] ?? 0,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
) ?: 0;

$buscar = trim((string)($_GET['buscar'] ?? ''));

if (mb_strlen($buscar) > 200) {
    http_response_code(400);
    exit('La búsqueda es demasiado larga.');
}

if ($fecha !== '') {
    $fechaObj = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $fecha
    );

    if (
        !$fechaObj ||
        $fechaObj->format('Y-m-d') !== $fecha
    ) {
        http_response_code(400);
        exit('Fecha no válida.');
    }
}

// ==========================================
// 4. OBTENER TODAS LAS SESIONES
// ==========================================

$stmt = $conexion->query("
    SELECT
        id_sesion,
        codigo_sesion,
        fecha,
        turno
    FROM sesiones
    ORDER BY fecha, id_sesion
");

$todasSesiones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sesionesPorId = [];

foreach ($todasSesiones as $sesion) {
    $sesionesPorId[(int)$sesion['id_sesion']] = $sesion;
}

if ($idSesion > 0 && !isset($sesionesPorId[$idSesion])) {
    http_response_code(400);
    exit('La sesión seleccionada no existe.');
}

$totalSesiones = count($todasSesiones);

// ==========================================
// 5. CONSULTAR PARTICIPANTES
// ==========================================

$sql = "
    SELECT
        p.id_participante,
        p.documento,
        p.nombres_completos,
        p.correo,
        p.codigo_inscripcion,
        p.institucion,
        p.estado,
        c.estado AS estado_certificado

    FROM participantes p

    LEFT JOIN certificados c
        ON c.id_participante = p.id_participante

    WHERE 1 = 1
";

$params = [];

if ($buscar !== '') {
    $sql .= "
        AND (
            p.documento LIKE :documento
            OR p.nombres_completos LIKE :nombre
        )
    ";

    $termino = '%' . $buscar . '%';

    $params[':documento'] = $termino;
    $params[':nombre'] = $termino;
}

// Si hay filtro de fecha o sesión, mostrar
// únicamente participantes con asistencia
// en el período o sesión seleccionados.

if ($fecha !== '' || $idSesion > 0) {

    $sql .= "
        AND EXISTS (
            SELECT 1
            FROM asistencias af
            INNER JOIN sesiones sf
                ON sf.id_sesion = af.id_sesion
            WHERE af.id_participante = p.id_participante
    ";

    if ($fecha !== '') {
        $sql .= " AND sf.fecha = :fecha";
        $params[':fecha'] = $fecha;
    }

    if ($idSesion > 0) {
        $sql .= " AND sf.id_sesion = :sesion";
        $params[':sesion'] = $idSesion;
    }

    $sql .= ")";
}

$sql .= "
    ORDER BY p.nombres_completos ASC
";

$stmt = $conexion->prepare($sql);
$stmt->execute($params);

$participantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ==========================================
// 6. CONSULTAR ASISTENCIAS
// ==========================================

$stmt = $conexion->query("
    SELECT
        a.id_participante,
        a.id_sesion
    FROM asistencias a
");

$registrosAsistencia = $stmt->fetchAll(PDO::FETCH_ASSOC);

$asistencias = [];

foreach ($registrosAsistencia as $registro) {

    $idParticipante = (int)$registro['id_participante'];
    $idSesionAsistencia = (int)$registro['id_sesion'];

    $asistencias[$idParticipante][$idSesionAsistencia] = true;
}

// ==========================================
// 7. RESUMEN POR SESIÓN
// ==========================================

$sqlResumen = "
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

$paramsResumen = [];

if ($fecha !== '') {
    $sqlResumen .= " AND s.fecha = :fecha";
    $paramsResumen[':fecha'] = $fecha;
}

if ($idSesion > 0) {
    $sqlResumen .= " AND s.id_sesion = :sesion";
    $paramsResumen[':sesion'] = $idSesion;
}

$sqlResumen .= "
    GROUP BY
        s.id_sesion,
        s.codigo_sesion,
        s.fecha,
        s.turno
    ORDER BY s.fecha, s.id_sesion
";

$stmt = $conexion->prepare($sqlResumen);
$stmt->execute($paramsResumen);

$resumenSesiones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalParticipantesActivos = (int)$conexion->query("
    SELECT COUNT(*)
    FROM participantes
    WHERE estado = 'ACTIVO'
")->fetchColumn();

// ==========================================
// 8. CREAR ARCHIVO EXCEL
// ==========================================

$excel = new Spreadsheet();

$excel->getProperties()
    ->setCreator('Congreso Puno 2026')
    ->setTitle('Reporte de asistencias')
    ->setSubject('Control de asistencias del congreso')
    ->setDescription('Reporte generado desde la base de pruebas');

// ==========================================
// HOJA 1: PARTICIPANTES
// ==========================================

$hoja = $excel->getActiveSheet();
$hoja->setTitle('Participantes');

$encabezados = [
    'N°',
    'Documento',
    'Nombres completos',
    'Correo electrónico',
    'Código de inscripción',
    'Institución',
    'Estado participante'
];

foreach ($todasSesiones as $sesion) {
    $encabezados[] =
        $sesion['codigo_sesion'] .
        ' (' . $sesion['turno'] . ')';
}

$encabezados[] = 'Total asistencias';
$encabezados[] = 'Asistencias filtradas';
$encabezados[] = 'Condición';
$encabezados[] = 'Estado certificado';

foreach ($encabezados as $indice => $titulo) {

    $columna = Coordinate::stringFromColumnIndex(
        $indice + 1
    );

    $hoja->setCellValue(
        $columna . '1',
        $titulo
    );
}

$fila = 2;

$totalAptosExportados = 0;

foreach ($participantes as $indice => $p) {

    $idParticipante = (int)$p['id_participante'];

    $sesionesAsistidas = $asistencias[$idParticipante] ?? [];

    $totalAsistencias = count($sesionesAsistidas);

    $asistenciasFiltradas = 0;

    foreach ($sesionesAsistidas as $idAsistencia => $valor) {

        $sesion = $sesionesPorId[$idAsistencia] ?? null;

        if (!$sesion) {
            continue;
        }

        if (
            $fecha !== '' &&
            $sesion['fecha'] !== $fecha
        ) {
            continue;
        }

        if (
            $idSesion > 0 &&
            $idAsistencia !== $idSesion
        ) {
            continue;
        }

        $asistenciasFiltradas++;
    }

    $esApto =
        $p['estado'] === 'ACTIVO' &&
        $totalAsistencias >= $minimoAsistencias;

    if ($esApto) {
        $totalAptosExportados++;
    }

    $condicion = $p['estado'] !== 'ACTIVO'
        ? 'INACTIVO'
        : ($esApto ? 'APTO' : 'NO APTO');

    $estadoCertificado =
        $p['estado_certificado'] ?? 'SIN EMITIR';

    // Datos generales.

    $hoja->setCellValue("A$fila", $indice + 1);

    $hoja->setCellValueExplicit(
        "B$fila",
        (string)$p['documento'],
        DataType::TYPE_STRING
    );

    $hoja->setCellValueExplicit(
        "C$fila",
        (string)$p['nombres_completos'],
        DataType::TYPE_STRING
    );

    $hoja->setCellValueExplicit(
        "D$fila",
        (string)($p['correo'] ?? ''),
        DataType::TYPE_STRING
    );

    $hoja->setCellValueExplicit(
        "E$fila",
        (string)($p['codigo_inscripcion'] ?? ''),
        DataType::TYPE_STRING
    );

    $hoja->setCellValueExplicit(
        "F$fila",
        (string)($p['institucion'] ?? ''),
        DataType::TYPE_STRING
    );

    $hoja->setCellValueExplicit(
        "G$fila",
        (string)$p['estado'],
        DataType::TYPE_STRING
    );

    // Columnas dinámicas de sesiones.

    $columnaActual = 8;

    foreach ($todasSesiones as $sesion) {

        $idSesionActual = (int)$sesion['id_sesion'];

        $letra = Coordinate::stringFromColumnIndex(
            $columnaActual
        );

        $asistio = isset(
            $sesionesAsistidas[$idSesionActual]
        );

        $hoja->setCellValueExplicit(
            $letra . $fila,
            $asistio ? 'SI' : 'NO',
            DataType::TYPE_STRING
        );

        $columnaActual++;
    }

    // Totales y condición.

    $letraTotal = Coordinate::stringFromColumnIndex(
        $columnaActual++
    );

    $letraFiltradas = Coordinate::stringFromColumnIndex(
        $columnaActual++
    );

    $letraCondicion = Coordinate::stringFromColumnIndex(
        $columnaActual++
    );

    $letraCertificado = Coordinate::stringFromColumnIndex(
        $columnaActual++
    );

    $hoja->setCellValue(
        $letraTotal . $fila,
        $totalAsistencias
    );

    $hoja->setCellValue(
        $letraFiltradas . $fila,
        $asistenciasFiltradas
    );

    $hoja->setCellValueExplicit(
        $letraCondicion . $fila,
        $condicion,
        DataType::TYPE_STRING
    );

    $hoja->setCellValueExplicit(
        $letraCertificado . $fila,
        $estadoCertificado,
        DataType::TYPE_STRING
    );

    $fila++;
}

// ==========================================
// 9. FORMATO HOJA PARTICIPANTES
// ==========================================

$ultimaColumna = Coordinate::stringFromColumnIndex(
    count($encabezados)
);

$ultimaFila = max(1, $fila - 1);

$hoja->getStyle("A1:{$ultimaColumna}1")
    ->getFont()
    ->setBold(true)
    ->getColor()
    ->setRGB('FFFFFF');

$hoja->getStyle("A1:{$ultimaColumna}1")
    ->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()
    ->setRGB('17406B');

$hoja->getStyle("A1:{$ultimaColumna}1")
    ->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER);

$hoja->getRowDimension(1)->setRowHeight(30);

$hoja->freezePane('H2');

$hoja->setAutoFilter(
    "A1:{$ultimaColumna}{$ultimaFila}"
);

$anchos = [
    'A' => 8,
    'B' => 18,
    'C' => 42,
    'D' => 35,
    'E' => 25,
    'F' => 35,
    'G' => 22
];

foreach ($anchos as $columna => $ancho) {
    $hoja->getColumnDimension($columna)
        ->setWidth($ancho);
}

for ($i = 8; $i <= count($encabezados); $i++) {

    $columna = Coordinate::stringFromColumnIndex($i);

    $hoja->getColumnDimension($columna)
        ->setWidth(20);
}

// ==========================================
// HOJA 2: RESUMEN POR SESIÓN
// ==========================================

$resumen = $excel->createSheet();
$resumen->setTitle('Resumen por sesion');

$resumen->fromArray(
    [
        [
            'Sesión',
            'Fecha',
            'Turno',
            'Asistentes',
            'Porcentaje'
        ]
    ],
    null,
    'A1'
);

$filaResumen = 2;

foreach ($resumenSesiones as $sesion) {

    $asistentes = (int)$sesion['total_asistentes'];

    $porcentaje = $totalParticipantesActivos > 0
        ? round(
            ($asistentes / $totalParticipantesActivos) * 100,
            1
        )
        : 0;

    $resumen->setCellValueExplicit(
        "A$filaResumen",
        (string)$sesion['codigo_sesion'],
        DataType::TYPE_STRING
    );

    $resumen->setCellValue(
        "B$filaResumen",
        $sesion['fecha']
    );

    $resumen->setCellValueExplicit(
        "C$filaResumen",
        (string)$sesion['turno'],
        DataType::TYPE_STRING
    );

    $resumen->setCellValue(
        "D$filaResumen",
        $asistentes
    );

    $resumen->setCellValue(
        "E$filaResumen",
        $porcentaje
    );

    $filaResumen++;
}

$resumen->getStyle('A1:E1')
    ->getFont()
    ->setBold(true)
    ->getColor()
    ->setRGB('FFFFFF');

$resumen->getStyle('A1:E1')
    ->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()
    ->setRGB('17406B');

$resumen->freezePane('A2');

$resumen->setAutoFilter(
    'A1:E' . max(1, $filaResumen - 1)
);

foreach ([
    'A' => 25,
    'B' => 18,
    'C' => 20,
    'D' => 18,
    'E' => 18
] as $columna => $ancho) {
    $resumen->getColumnDimension($columna)
        ->setWidth($ancho);
}

// ==========================================
// 10. DESCARGAR ARCHIVO
// ==========================================

$nombreArchivo =
    'reporte_asistencias_PRUEBAS_puno_2026_' .
    date('Ymd_His') .
    '.xlsx';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header(
    'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
);

header(
    'Content-Disposition: attachment; filename="' .
    $nombreArchivo . '"'
);

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$writer = new Xlsx($excel);
$writer->save('php://output');

$excel->disconnectWorksheets();

exit;
