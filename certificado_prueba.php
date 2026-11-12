
<?php
ob_start();
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header('Location: login.php');
    exit;
}

if (strtoupper($_SESSION['rol'] ?? '') !== 'ADMIN') {
    http_response_code(403);
    exit('Acceso denegado.');
}

require_once __DIR__ . '/vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

// PLANTILLA LIMPIA
$plantilla = __DIR__ .
    '/plantillas/plantilla_congreso_prueba.png';

if (!is_file($plantilla)) {
    exit('No se encontró la plantilla de prueba.');
}

// DATOS FICTICIOS
$nombre = 'JUAN CARLOS PEREZ QUISPE';
$codigo = 'INIA-DSEA-2026-PRUEBA-001';

// URL provisional, todavía no verifica certificados.
$url = 'https://example.org/verificar?codigo=' .
       urlencode($codigo);

// GENERAR QR
$qr = (new Builder(
    writer: new PngWriter(),
    data: $url,
    size: 220,
    margin: 5
))->build();

$archivoQR = tempnam(sys_get_temp_dir(), 'qr_');

if ($archivoQR === false) {
    exit('No se pudo generar el QR temporal.');
}

file_put_contents($archivoQR, $qr->getString());

// CREAR PDF
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

$pdf->SetCreator('Congreso Puno 2026');
$pdf->SetTitle('Certificado institucional de prueba');

$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(false, 0);

$pdf->AddPage();

// COLOCAR FONDO CON LOGOTIPOS ORIGINALES
$pdf->Image(
    $plantilla,
    0,
    0,
    297,
    210,
    'PNG'
);

// TÍTULO ORIGINAL
$pdf->SetTextColor(25, 25, 25);
$pdf->SetFont('dejavusans', 'B', 30);
$pdf->SetXY(20, 39);
$pdf->Cell(257, 18, 'CERTIFICADO', 0, 0, 'C');

// OTORGADO A
$pdf->SetFont('dejavusans', '', 13);
$pdf->SetXY(20, 60);
$pdf->Cell(257, 10, 'Otorgado a:', 0, 0, 'C');

// NOMBRE DEL PARTICIPANTE
$pdf->SetFont('dejavusans', 'B', 22);
$pdf->SetXY(15, 76);
$pdf->MultiCell(
    267,
    12,
    $nombre,
    0,
    'C',
    false,
    1
);

// TEXTO DE PARTICIPACIÓN
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

// NOMBRE DEL EVENTO
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

// DESCRIPCIÓN INSTITUCIONAL PROVISIONAL
$pdf->SetFont('dejavusans', '', 11);
$pdf->SetXY(19, 124);
$pdf->MultiCell(
    259,
    7,
    'Certificado de prueba del II Congreso Peruano de Extensión ' .
    'Agropecuaria, realizado del 2 al 5 de diciembre de 2026, ' .
    'en Puno, Perú.',
    0,
    'C',
    false,
    1
);

// CÓDIGO ÚNICO DE PRUEBA
$pdf->SetFont('dejavusans', '', 9);
$pdf->SetXY(178, 146);
$pdf->Cell(70, 7, $codigo, 0, 0, 'R');

// ESPACIOS PARA FIRMAS OFICIALES
$pdf->SetDrawColor(65, 65, 65);
$pdf->SetLineWidth(0.3);

$pdf->Line(20, 179, 95, 179);
$pdf->Line(111, 179, 186, 179);
$pdf->Line(202, 179, 267, 179);

$pdf->SetFont('dejavusans', 'B', 9);

$pdf->SetXY(20, 181);
$pdf->Cell(75, 6, 'AUTORIDAD ORGANIZADORA', 0, 0, 'C');

$pdf->SetXY(111, 181);
$pdf->Cell(75, 6, 'AUTORIDAD ORGANIZADORA', 0, 0, 'C');

$pdf->SetXY(202, 181);
$pdf->Cell(65, 6, 'AUTORIDAD ORGANIZADORA', 0, 0, 'C');

// QR DE PRUEBA
$pdf->Image(
    $archivoQR,
    253,
    143,
    26,
    26,
    'PNG'
);

// MARCA DE PRUEBA
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

// LIMPIEZA
if (is_file($archivoQR)) {
    unlink($archivoQR);
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

// MOSTRAR PDF
$pdf->Output('certificado_congreso_puno_prueba.pdf', 'I');
exit;
