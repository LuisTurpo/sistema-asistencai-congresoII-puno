
<?php
session_start();
date_default_timezone_set('America/Lima');

// ============================================
// 1. SEGURIDAD
// ============================================

if (
    !isset($_SESSION['id_usuario']) ||
    strtoupper(trim($_SESSION['rol'] ?? '')) !== 'ADMIN'
) {
    http_response_code(403);
    exit('Acceso denegado.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

$token = $_POST['csrf'] ?? '';

if (
    !is_string($token) ||
    empty($_SESSION['csrf_certificados_test']) ||
    !hash_equals(
        $_SESSION['csrf_certificados_test'],
        $token
    )
) {
    http_response_code(403);
    exit('Token de seguridad inválido.');
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database_test.php';

use PHPMailer\PHPMailer\PHPMailer;

// ============================================
// 2. CONFIGURACIÓN
// ============================================

const FECHA_INICIO_CONGRESO = '2026-12-02';
const FECHA_FIN_CONGRESO = '2026-12-04';
const SESIONES_ESPERADAS = 6;
const MINIMO_ASISTENCIAS = 5;
const MAXIMO_ENVIOS = 10;

function finalizar(string $mensaje): void
{
    $_SESSION['mensaje_certificados_test'] = $mensaje;

    header(
        'Location: generar_certificados_test.php',
        true,
        303
    );
    exit;
}

if (!isset($conexion) || !($conexion instanceof PDO)) {
    exit('Conexión no disponible.');
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
    exit('Base de datos incorrecta.');
}

// ============================================
// 3. CONFIGURACIÓN SMTP
// ============================================

$config = require __DIR__ . '/privado/correo.php';

$listaCorreos = require
    __DIR__ . '/privado/destinatarios_prueba.php';

if (
    !is_array($config) ||
    empty($config['correo']) ||
    !filter_var(
        $config['correo'],
        FILTER_VALIDATE_EMAIL
    ) ||
    empty($config['password']) ||
    empty($config['nombre'])
) {
    exit('Configuración SMTP incompleta.');
}

if (
    !is_array($listaCorreos) ||
    count($listaCorreos) === 0
) {
    exit('No hay correos de prueba configurados.');
}

$correosPermitidos = [];

foreach ($listaCorreos as $correo) {

    if (!is_string($correo)) {
        exit('Lista de correos inválida.');
    }

    $correo = strtolower(trim($correo));

    if (!filter_var(
        $correo,
        FILTER_VALIDATE_EMAIL
    )) {
        exit('Hay un correo de prueba inválido.');
    }

    $correosPermitidos[] = $correo;
}

$correosPermitidos = array_values(
    array_unique($correosPermitidos)
);

// ============================================
// 4. COMPROBAR SESIONES DEFINITIVAS
// ============================================

function contarSesiones(PDO $db): int
{
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM sesiones
        WHERE fecha BETWEEN ? AND ?
    ");

    $stmt->execute([
        FECHA_INICIO_CONGRESO,
        FECHA_FIN_CONGRESO
    ]);

    return (int)$stmt->fetchColumn();
}

// ============================================
// 5. OBTENER CERTIFICADOS APTOS
// ============================================

function obtenerCertificados(
    PDO $db,
    ?int $id = null,
    ?int $limite = null
): array {

    $sql = "
        SELECT
            c.id_certificado,
            c.id_participante,
            c.codigo_verificacion,
            p.nombres_completos,
            p.correo

        FROM certificados c

        INNER JOIN participantes p
            ON p.id_participante = c.id_participante

        WHERE c.estado = 'VIGENTE'
          AND p.estado = 'ACTIVO'

          AND (
              SELECT COUNT(DISTINCT a.id_sesion)
              FROM asistencias a
              INNER JOIN sesiones s
                  ON s.id_sesion = a.id_sesion
              WHERE a.id_participante = p.id_participante
                AND s.fecha BETWEEN ? AND ?
          ) >= ?

          AND NOT EXISTS (
              SELECT 1
              FROM envios_certificados ec
              WHERE ec.id_certificado = c.id_certificado
                AND ec.estado IN ('ENVIADO', 'PENDIENTE')
          )
    ";

    $params = [
        FECHA_INICIO_CONGRESO,
        FECHA_FIN_CONGRESO,
        MINIMO_ASISTENCIAS
    ];

    if ($id !== null) {
        $sql .= " AND p.id_participante = ?";
        $params[] = $id;
    }

    $sql .= " ORDER BY c.id_certificado ASC";

    if ($limite !== null) {
        $sql .= " LIMIT " . (int)$limite;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// 6. VALIDAR DESTINATARIO
// ============================================

function correoAutorizado(
    string $correo,
    array $permitidos
): bool {

    $correo = strtolower(trim($correo));

    return filter_var(
        $correo,
        FILTER_VALIDATE_EMAIL
    ) !== false
        && in_array($correo, $permitidos, true);
}

// ============================================
// 7. RESERVAR UN ENVÍO
// ============================================

// Se bloquea temporalmente el certificado
// para evitar dos solicitudes simultáneas
// que intenten reservar el mismo envío.

function reservarEnvio(
    PDO $db,
    int $idCertificado,
    string $destinatario
): ?int {

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("
            SELECT id_certificado, estado
            FROM certificados
            WHERE id_certificado = ?
            FOR UPDATE
        ");

        $stmt->execute([$idCertificado]);
        $certificado = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$certificado ||
            $certificado['estado'] !== 'VIGENTE'
        ) {
            $db->rollBack();
            return null;
        }

        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM envios_certificados
            WHERE id_certificado = ?
              AND estado IN ('ENVIADO', 'PENDIENTE')
        ");

        $stmt->execute([$idCertificado]);

        if ((int)$stmt->fetchColumn() > 0) {
            $db->rollBack();
            return null;
        }

        $stmt = $db->prepare("
            INSERT INTO envios_certificados
                (id_certificado, correo_destino, estado)
            VALUES (?, ?, 'PENDIENTE')
        ");

        $stmt->execute([
            $idCertificado,
            $destinatario
        ]);

        $idEnvio = (int)$db->lastInsertId();

        $db->commit();

        return $idEnvio;

    } catch (Throwable $e) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $e;
    }
}

// ============================================
// 8. ACTUALIZAR ESTADO DEL ENVÍO
// ============================================

function actualizarEnvio(
    PDO $db,
    int $idEnvio,
    string $estado,
    ?string $error = null
): void {

    $stmt = $db->prepare("
        UPDATE envios_certificados
        SET estado = ?,
            fecha_envio = CASE
                WHEN ? = 'ENVIADO' THEN NOW()
                ELSE NULL
            END,
            mensaje_error = ?
        WHERE id_envio = ?
    ");

    $stmt->execute([
        $estado,
        $estado,
        $error,
        $idEnvio
    ]);
}

// ============================================
// 9. ENVIAR CERTIFICADO
// ============================================

function enviarCertificado(
    PDO $db,
    array $certificado,
    array $config,
    string $destinatario,
    array $correosPermitidos
): string {

    $idCertificado =
        (int)$certificado['id_certificado'];

    $destinatario = strtolower(
        trim($destinatario)
    );

    // Proteger los destinatarios de prueba.
    if (!correoAutorizado(
        $destinatario,
        $correosPermitidos
    )) {
        return 'OMITIDO';
    }

    $codigo =
        (string)$certificado['codigo_verificacion'];

    // Solo certificados de prueba.
    if (!preg_match(
        '/^PRUEBA-PUNO-2026-[A-F0-9]{16}$/D',
        $codigo
    )) {
        return 'OMITIDO';
    }

    $ruta = __DIR__ .
        '/certificados_prueba/' .
        $codigo . '.pdf';

    if (!is_file($ruta) || !is_readable($ruta)) {
        error_log(
            'No existe o no se puede leer PDF: ' .
            $codigo
        );

        return 'ERROR';
    }

    // Reservar el envío antes de contactar a SMTP.
    $idEnvio = reservarEnvio(
        $db,
        $idCertificado,
        $destinatario
    );

    if ($idEnvio === null) {
        return 'OMITIDO';
    }

    $mail = new PHPMailer(true);

    try {

        // ====================================
        // CONFIGURACIÓN GMAIL SMTP
        // ====================================

        $mail->isSMTP();

        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;

        $mail->Username = $config['correo'];
        $mail->Password = $config['password'];

        $mail->SMTPSecure =
            PHPMailer::ENCRYPTION_STARTTLS;

        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 30;

        $mail->setFrom(
            $config['correo'],
            $config['nombre']
        );

        // ====================================
        // DESTINATARIO INDIVIDUAL
        // ====================================

        $mail->addAddress(
            $destinatario,
            $certificado['nombres_completos']
        );

        // ====================================
        // CONTENIDO DEL CORREO
        // ====================================

        $mail->isHTML(true);

        $mail->Subject =
            'Certificado de prueba - Congreso Puno 2026';

        $nombre = htmlspecialchars(
            (string)$certificado['nombres_completos'],
            ENT_QUOTES,
            'UTF-8'
        );

        $mail->Body = "
            <h2>
                II Congreso Peruano de
                Extensión Agropecuaria
            </h2>

            <p>
                Estimado/a
                <strong>{$nombre}</strong>:
            </p>

            <p>
                Adjuntamos su certificado de prueba
                correspondiente al Congreso Puno 2026.
            </p>

            <p>
                El congreso está programado para
                los días 2, 3 y 4 de diciembre de 2026.
            </p>

            <p>
                Este mensaje es una prueba del sistema
                y no constituye una emisión oficial.
            </p>

            <p>
                Atentamente,<br>
                Equipo organizador
            </p>
        ";

        $mail->AltBody =
            'Estimado/a ' .
            $certificado['nombres_completos'] .
            ', adjuntamos su certificado de prueba ' .
            'del Congreso Puno 2026.';

        // ====================================
        // ADJUNTAR PDF CORRECTO
        // ====================================

        $mail->addAttachment(
            $ruta,
            'Certificado_' . $codigo . '.pdf'
        );

        // Enviar.
        $mail->send();

    } catch (Throwable $e) {

        error_log(
            'Error SMTP certificado ' .
            $idCertificado . ': ' .
            $e->getMessage()
        );

        // El SMTP puede fallar después de aceptar
        // el mensaje. Antes de reintentar se debe
        // revisar el registro correspondiente.
        try {
            actualizarEnvio(
                $db,
                $idEnvio,
                'ERROR',
                mb_substr(
                    $e->getMessage(),
                    0,
                    1000
                )
            );

        } catch (Throwable $errorBD) {
            error_log($errorBD->getMessage());
        }

        return 'ERROR';
    }

    // SMTP aceptó el mensaje.
    // Registrar el resultado en MySQL.
    try {

        actualizarEnvio(
            $db,
            $idEnvio,
            'ENVIADO'
        );

        return 'ENVIADO';

    } catch (Throwable $e) {

        error_log(
            'SMTP aceptó el certificado ' .
            $idCertificado .
            ', pero falló el registro: ' .
            $e->getMessage()
        );

        // Dejar PENDIENTE para revisión manual.
        // No se reenvía automáticamente.
        return 'REVISAR';
    }
}

// ============================================
// 10. VALIDAR ACCIÓN
// ============================================

$accion = $_POST['accion'] ?? '';

if (!in_array(
    $accion,
    ['individual', 'masivo'],
    true
)) {
    http_response_code(400);
    exit('Acción inválida.');
}

$id = null;

if ($accion === 'individual') {

    $id = filter_var(
        $_POST['id_participante'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (!$id) {
        http_response_code(400);
        exit('Participante inválido.');
    }
}

// ============================================
// 11. PROCESAR ENVÍOS
// ============================================

try {

    // Deben existir exactamente seis sesiones
    // entre el 2 y el 4 de diciembre.
    $totalSesiones = contarSesiones($conexion);

    if ($totalSesiones !== SESIONES_ESPERADAS) {
        finalizar(
            'No se enviaron certificados: ' .
            'se esperaban 6 sesiones válidas, ' .
            'pero se encontraron ' .
            $totalSesiones . '.'
        );
    }

    $enviados = 0;
    $omitidos = 0;
    $errores = 0;
    $revision = 0;

    if ($accion === 'individual') {

        $certificados = obtenerCertificados(
            $conexion,
            $id
        );

    } else {

        // Obtener candidatos elegibles sin limitar
        // antes de validar la lista autorizada.
        $certificados = obtenerCertificados(
            $conexion
        );
    }

    $procesados = 0;

    foreach ($certificados as $certificado) {

        $destinatario = strtolower(trim(
            (string)($certificado['correo'] ?? '')
        ));

        // No enviar a direcciones no autorizadas.
        if (!correoAutorizado(
            $destinatario,
            $correosPermitidos
        )) {
            $omitidos++;
            continue;
        }

        // Máximo 10 envíos elegibles por operación.
        if (
            $accion === 'masivo' &&
            $procesados >= MAXIMO_ENVIOS
        ) {
            break;
        }

        $procesados++;

        $resultado = enviarCertificado(
            $conexion,
            $certificado,
            $config,
            $destinatario,
            $correosPermitidos
        );

        if ($resultado === 'ENVIADO') {

            $enviados++;

        } elseif ($resultado === 'OMITIDO') {

            $omitidos++;

        } elseif ($resultado === 'REVISAR') {

            $revision++;

        } else {

            $errores++;
        }
    }

    finalizar(
        "Correos enviados: {$enviados}. " .
        "Omitidos: {$omitidos}. " .
        "Errores: {$errores}. " .
        "Requieren revisión: {$revision}."
    );

} catch (Throwable $e) {

    error_log(
        'Error general de envíos: ' .
        $e->getMessage()
    );

    finalizar(
        'Ocurrió un error al procesar los correos. ' .
        'Revisa el registro del servidor.'
    );
}
