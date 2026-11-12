
<?php
ob_start();
session_start();

// ==========================================
// 1. VALIDAR SESIÓN DEL ADMINISTRADOR
// ==========================================

if (!isset($_SESSION['id_usuario'])) {
    header('Location: login.php');
    exit;
}

if (strtoupper(trim($_SESSION['rol'] ?? '')) !== 'ADMIN') {
    http_response_code(403);
    exit('Acceso denegado.');
}

// ==========================================
// 2. CARGAR LIBRERÍAS Y CONEXIÓN DE PRUEBAS
// ==========================================

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database_test.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

// Tu archivo database_test.php utiliza $conexion.
// Aquí reutilizamos esa conexión.

if (!isset($conexion) || !($conexion instanceof PDO)) {
    exit('Error: conexión de pruebas no disponible.');
}

$conexionPruebas = $conexion;

// Comprobar que NO estamos en producción.

$nombreBD = $conexionPruebas
    ->query('SELECT DATABASE()')
    ->fetchColumn();

if ($nombreBD !== 'congreso_puno_pruebas') {
    exit('Error: la conexión no apunta a la base de pruebas.');
}

// ==========================================
// 3. PARTICIPANTE DE PRUEBA
// ==========================================

// Participante ficticio que tiene 5 asistencias.
$idParticipante = 1;

// Consultar participante y asistencias.

$sql = "
    SELECT
        p.id_participante,
        p.documento,
        p.nombres_completos,
        p.estado,
        COUNT(DISTINCT a.id_sesion) AS total_asistencias
    FROM participantes p
    LEFT JOIN asistencias a
        ON p.id_participante = a.id_participante
    WHERE p.id_participante = ?
    GROUP BY
        p.id_participante,
        p.documento,
        p.nombres_completos,
        p.estado
";

$stmt = $conexionPruebas->prepare($sql);
$stmt->execute([$idParticipante]);

$participante = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$participante) {
    exit('No se encontró el participante de prueba.');
}

if ($participante['estado'] !== 'ACTIVO') {
    exit('El participante está inactivo.');
}

$totalAsistencias =
    (int)$participante['total_asistencias'];

if ($totalAsistencias < 5) {
    exit(
        'El participante no cumple el requisito. ' .
        'Asistencias registradas: ' .
        $totalAsistencias . ' de 8.'
    );
}

// ==========================================
// 4. VERIFICAR CERTIFICADO EXISTENTE
// ==========================================

$stmt = $conexionPruebas->prepare("
    SELECT
        id_certificado,
        codigo_verificacion,
        url_pdf,
        estado
    FROM certificados
    WHERE id_participante = ?
");

$stmt->execute([$idParticipante]);

$certificadoExistente =
    $stmt->fetch(PDO::FETCH_ASSOC);

// Si ya existe, no generar uno nuevo.

if ($certificadoExistente) {

    $codigoExistente =
        $certificadoExistente['codigo_verificacion'];

    $rutaExistente =
        $certificadoExistente['url_pdf'];

    if (
        $certificadoExistente['estado'] === 'VIGENTE' &&
        !empty($rutaExistente)
    ) {
        $rutaAbsoluta =
            __DIR__ . '/' . $rutaExistente;

        if (is_file($rutaAbsoluta)) {

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            header('Content-Type: application/pdf');
            header(
                'Content-Disposition: inline; ' .
                'filename="certificado_prueba.pdf"'
            );
            header('Cache-Control: no-store');

            readfile($rutaAbsoluta);
            exit;
        }
    }

    exit(
        'Este participante ya tiene un certificado registrado. ' .
        'Código: ' .
        htmlspecialchars(
            $codigoExistente,
            ENT_QUOTES,
            'UTF-8'
        ) .
        '. No se generó otro certificado.'
    );
}

// ==========================================
// 5. GENERAR CÓDIGO ÚNICO
// ==========================================

$codigo =
    'PRUEBA-PUNO-2026-' .
    strtoupper(bin2hex(random_bytes(8)));

$nombreParticipante =
    $participante['nombres_completos'];

// ==========================================
// 6. VERIFICAR PLANTILLA
// ==========================================

$plantilla =
    __DIR__ .
    '/plantillas/plantilla_congreso_prueba.png';

if (!is_file($plantilla)) {
    exit(
        'No se encontró la plantilla: ' .
        'plantillas/plantilla_congreso_prueba.png'
    );
}

// ==========================================
// 7. PREPARAR CARPETA DE CERTIFICADOS
// ==========================================

$directorio =
    __DIR__ . '/certificados_prueba';

if (!is_dir($directorio)) {

    if (
        !mkdir($directorio, 0755, true) &&
        !is_dir($directorio)
    ) {
        exit(
            'No se pudo crear la carpeta ' .
            'certificados_prueba.'
        );
    }
}

$nombreArchivo = $codigo . '.pdf';

$rutaArchivo =
    $directorio . '/' . $nombreArchivo;

$rutaRelativa =
    'certificados_prueba/' . $nombreArchivo;

// ==========================================
// 8. GENERAR CÓDIGO QR
// ==========================================

// Dirección provisional para la prueba.
// Más adelante tendrá una página real de
// verificación de certificados.

$urlVerificacion =
    'http://10.88.44.144:8000/verificar_certificado_test.php?codigo=' .
    urlencode($codigo);

$qr = (new Builder(
    writer: new PngWriter(),
    data: $urlVerificacion,
    size: 220,
    margin: 5
))->build();

$archivoQR =
    tempnam(sys_get_temp_dir(), 'qr_');

if ($archivoQR === false) {
    exit('No se pudo crear el archivo temporal del QR.');
}

if (
    file_put_contents(
        $archivoQR,
        $qr->getString()
    ) === false
) {
    exit('No se pudo guardar el QR temporal.');
}

// ==========================================
// 9. CREAR CERTIFICADO PDF
// ==========================================

$pdf = new TCPDF(
    'L',
    'mm',
    'A4',
    true,
    'UTF-8',
    false
);

$pdf->SetCreator('Congreso Puno 2026');
$pdf->SetAuthor('Sistema de Asistencias');
$pdf->SetTitle('Certificado de prueba');

$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(false, 0);

$pdf->AddPage();

// ==========================================
// 10. PLANTILLA INSTITUCIONAL
// ==========================================

$pdf->Image(
    $plantilla,
    0,
    0,
    297,
    210,
    'PNG'
);

// ==========================================
// 11. TÍTULO DEL CERTIFICADO
// ==========================================

$pdf->SetTextColor(25, 25, 25);

$pdf->SetFont('dejavusans', 'B', 30);
$pdf->SetXY(20, 39);

$pdf->Cell(
    257,
    18,
    'CERTIFICADO',
    0,
    0,
    'C'
);

// ==========================================
// 12. NOMBRE DEL PARTICIPANTE
// ==========================================

$pdf->SetFont('dejavusans', '', 13);
$pdf->SetXY(20, 60);

$pdf->Cell(
    257,
    10,
    'Otorgado a:',
    0,
    0,
    'C'
);

$pdf->SetFont('dejavusans', 'B', 22);
$pdf->SetXY(15, 76);

$pdf->MultiCell(
    267,
    12,
    $nombreParticipante,
    0,
    'C',
    false,
    1
);

// ==========================================
// 13. TEXTO DEL CONGRESO
// ==========================================

$pdf->SetFont('dejavusans', '', 12);
$pdf->SetXY(20, 92);

$pdf->Cell(
    257,
    10,
    'Por su participación en:',
    0,
    0,
    'C'
);

$pdf->SetFont('dejavusans', 'B', 16);
$pdf->SetXY(20, 104);

$pdf->MultiCell(
    257,
    12,
    'II CONGRESO PERUANO DE EXTENSIÓN AGROPECUARIA',
    0,
    'C',
    false,
    1
);

// ==========================================
// 14. DESCRIPCIÓN DEL EVENTO
// ==========================================

$pdf->SetFont('dejavusans', '', 11);
$pdf->SetXY(19, 124);

$pdf->MultiCell(
    259,
    7,
    'Certificado de prueba del II Congreso Peruano ' .
    'de Extensión Agropecuaria, realizado del ' .
    '2 al 5 de diciembre de 2026, en Puno, Perú.',
    0,
    'C',
    false,
    1
);

// ==========================================
// 15. CÓDIGO DE VERIFICACIÓN
// ==========================================

$pdf->SetFont('dejavusans', '', 8);
$pdf->SetXY(170, 146);

$pdf->Cell(
    78,
    7,
    $codigo,
    0,
    0,
    'R'
);

// ==========================================
// 16. ESPACIOS PARA FIRMAS
// ==========================================

$pdf->SetDrawColor(65, 65, 65);
$pdf->SetLineWidth(0.3);

$pdf->Line(20, 179, 95, 179);
$pdf->Line(111, 179, 186, 179);
$pdf->Line(202, 179, 267, 179);

$pdf->SetFont('dejavusans', 'B', 9);

$pdf->SetXY(20, 181);
$pdf->Cell(
    75,
    6,
    'AUTORIDAD ORGANIZADORA',
    0,
    0,
    'C'
);

$pdf->SetXY(111, 181);
$pdf->Cell(
    75,
    6,
    'AUTORIDAD ORGANIZADORA',
    0,
    0,
    'C'
);

$pdf->SetXY(202, 181);
$pdf->Cell(
    65,
    6,
    'AUTORIDAD ORGANIZADORA',
    0,
    0,
    'C'
);

// ==========================================
// 17. AGREGAR QR
// ==========================================

$pdf->Image(
    $archivoQR,
    253,
    143,
    26,
    26,
    'PNG'
);

// ==========================================
// 18. MARCA DE PRUEBA
// ==========================================

$pdf->SetTextColor(160, 55, 55);
$pdf->SetFont('dejavusans', 'B', 9);

$pdf->SetXY(98, 197);

$pdf->Cell(
    101,
    7,
    'DOCUMENTO DE PRUEBA',
    0,
    0,
    'C'
);

// ==========================================
// 19. GENERAR PDF EN MEMORIA
// ==========================================

$contenidoPDF = $pdf->Output('', 'S');

// Eliminar QR temporal.
if (is_file($archivoQR)) {
    unlink($archivoQR);
}

// ==========================================
// 20. GUARDAR CERTIFICADO EN MYSQL
// ==========================================

try {

    // Iniciar transacción.
    $conexionPruebas->beginTransaction();

    $stmt = $conexionPruebas->prepare("
        INSERT INTO certificados
        (
            id_participante,
            codigo_verificacion,
            url_pdf,
            estado
        )
        VALUES (?, ?, ?, 'VIGENTE')
    ");

    $stmt->execute([
        $idParticipante,
        $codigo,
        $rutaRelativa
    ]);

    // Guardar PDF sin sobrescribir archivos.
    $archivo = fopen($rutaArchivo, 'x+b');

    if ($archivo === false) {
        throw new RuntimeException(
            'No se pudo crear el archivo PDF.'
        );
    }

    $bytes = fwrite($archivo, $contenidoPDF);
    fclose($archivo);

    if (
        $bytes === false ||
        $bytes !== strlen($contenidoPDF)
    ) {
        throw new RuntimeException(
            'No se pudo guardar completamente el PDF.'
        );
    }

    // Confirmar registro.
    $conexionPruebas->commit();

} catch (Throwable $e) {

    if ($conexionPruebas->inTransaction()) {
        $conexionPruebas->rollBack();
    }

    if (is_file($rutaArchivo)) {
        unlink($rutaArchivo);
    }

    error_log($e->getMessage());

    exit(
        'No se pudo registrar el certificado de prueba. ' .
        'Comprueba la conexión y los permisos.'
    );
}

// ==========================================
// 21. MOSTRAR CERTIFICADO EN EL NAVEGADOR
// ==========================================

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/pdf');

header(
    'Content-Disposition: inline; ' .
    'filename="certificado_prueba.pdf"'
);

header('Content-Length: ' . strlen($contenidoPDF));
header('Cache-Control: no-store');

echo $contenidoPDF;
exit;
