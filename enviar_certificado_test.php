
<?php
session_start();

if (
    !isset($_SESSION['id_usuario']) ||
    strtoupper($_SESSION['rol'] ?? '') !== 'ADMIN'
) {
    http_response_code(403);
    exit('Acceso denegado.');
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database_test.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($conexion) || !($conexion instanceof PDO)) {
    exit('Error de conexión.');
}

if (
    $conexion->query('SELECT DATABASE()')->fetchColumn()
    !== 'congreso_puno_pruebas'
) {
    exit('La conexión no apunta a la base de pruebas.');
}

// Cargar configuración local de Gmail.
$config = require __DIR__ . '/privado/correo.php';

// Participante ficticio con certificado emitido.
$idParticipante = 1;

$stmt = $conexion->prepare("
    SELECT
        p.nombres_completos,
        c.codigo_verificacion,
        c.estado
    FROM certificados c
    INNER JOIN participantes p
        ON p.id_participante = c.id_participante
    WHERE c.id_participante = ?
");

$stmt->execute([$idParticipante]);
$certificado = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$certificado) {
    exit('El participante no tiene certificado.');
}

if ($certificado['estado'] !== 'VIGENTE') {
    exit('El certificado no está vigente.');
}

// Ruta controlada: nunca usar una ruta enviada por GET.
$codigo = $certificado['codigo_verificacion'];

if (!preg_match('/^PRUEBA-PUNO-2026-[A-F0-9]{16}$/', $codigo)) {
    exit('Código de certificado inválido.');
}

$rutaPDF = __DIR__
    . '/certificados_prueba/'
    . $codigo
    . '.pdf';

if (!is_file($rutaPDF)) {
    exit('No se encontró el archivo PDF.');
}

// Durante esta prueba se envía al mismo Gmail configurado.
$destinatario = $config['correo'];

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();

    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;

    $mail->Username = $config['correo'];
    $mail->Password = $config['password'];

    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    $mail->CharSet = 'UTF-8';

    $mail->setFrom(
        $config['correo'],
        $config['nombre']
    );

    $mail->addAddress($destinatario);

    $mail->isHTML(true);

    $mail->Subject =
        'Certificado de prueba - Congreso Puno 2026';

    $nombreSeguro = htmlspecialchars(
        $certificado['nombres_completos'],
        ENT_QUOTES,
        'UTF-8'
    );

    $mail->Body = "
        <h2>II Congreso Peruano de Extensión Agropecuaria</h2>

        <p>Estimado/a <strong>{$nombreSeguro}</strong>:</p>

        <p>
            Adjuntamos su certificado de participación
            correspondiente al Congreso Puno 2026.
        </p>

        <p>
            Este documento es únicamente una prueba
            del sistema de emisión de certificados.
        </p>

        <p>
            Atentamente,<br>
            Equipo organizador del Congreso Puno 2026
        </p>
    ";

    $mail->AltBody =
        'Adjuntamos su certificado de prueba del Congreso Puno 2026.';

    $mail->addAttachment(
        $rutaPDF,
        'Certificado_Prueba.pdf'
    );

    $mail->send();

    echo '<h2>Correo enviado correctamente</h2>';
    echo '<p>El certificado PDF fue enviado a tu Gmail.</p>';
    echo '<p>Revisa tu bandeja de entrada.</p>';

} catch (Exception $e) {

    error_log('Error PHPMailer: ' . $mail->ErrorInfo);

    http_response_code(500);

    echo '<h2>No se pudo enviar el correo</h2>';
    echo '<p>Revisa las credenciales SMTP y el registro de errores de PHP.</p>';
}
