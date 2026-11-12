
<?php
session_start();

if (
    !isset($_SESSION['id_usuario']) ||
    strtoupper($_SESSION['rol'] ?? '') !== 'ADMIN'
) {
    http_response_code(403);
    exit('Acceso denegado.');
}

require_once __DIR__ . '/config/database_test.php';

if (!isset($conexion) || !($conexion instanceof PDO)) {
    exit('Conexión no disponible.');
}

if ($conexion->query('SELECT DATABASE()')->fetchColumn()
    !== 'congreso_puno_pruebas') {
    exit('Base de datos incorrecta.');
}

$id = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$id) {
    http_response_code(400);
    exit('ID inválido.');
}

$stmt = $conexion->prepare("
    SELECT codigo_verificacion, url_pdf, estado
    FROM certificados
    WHERE id_participante = ?
");

$stmt->execute([$id]);
$certificado = $stmt->fetch();

if (!$certificado || $certificado['estado'] !== 'VIGENTE') {
    http_response_code(404);
    exit('Certificado no disponible.');
}

$nombreArchivo =
    $certificado['codigo_verificacion'] . '.pdf';

$ruta = __DIR__ . '/certificados_prueba/' . $nombreArchivo;

if (!is_file($ruta)) {
    http_response_code(404);
    exit('El archivo PDF no existe.');
}

header('Content-Type: application/pdf');
header(
    'Content-Disposition: attachment; filename="' .
    $nombreArchivo . '"'
);
header('Content-Length: ' . filesize($ruta));
header('Cache-Control: no-store');

readfile($ruta);
exit;
