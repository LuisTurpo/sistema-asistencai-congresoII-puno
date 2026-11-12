
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
    exit('Solicitud no autorizada.');
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database_test.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

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
// 2. CONFIGURACIÓN DEL CONGRESO
// ============================================

// Fechas definitivas del congreso.
const FECHA_INICIO_CONGRESO = '2026-12-02';
const FECHA_FIN_CONGRESO = '2026-12-04';

// Total de sesiones esperadas.
const SESIONES_ESPERADAS = 6;

// Requisito mínimo para obtener certificado.
const MINIMO_ASISTENCIAS = 5;

// URL local utilizada para los códigos QR de prueba.
// IMPORTANTE: reemplazar por una URL HTTPS pública
// estable antes de emitir certificados oficiales.
const URL_VERIFICACION_PRUEBA =
    'http://10.88.44.144:8000/verificar_certificado_test.php';

// ============================================
// 3. VALIDAR SESIONES CONFIGURADAS
// ============================================

function contarSesionesValidas(PDO $db): int
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
// 4. CONTAR ASISTENCIAS VÁLIDAS
// ============================================

function contarAsistenciasValidas(
    PDO $db,
    int $idParticipante
): int {

    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT a.id_sesion)
        FROM asistencias a
        INNER JOIN sesiones s
            ON s.id_sesion = a.id_sesion
        WHERE a.id_participante = ?
          AND s.fecha BETWEEN ? AND ?
    ");

    $stmt->execute([
        $idParticipante,
        FECHA_INICIO_CONGRESO,
        FECHA_FIN_CONGRESO
    ]);

    return (int)$stmt->fetchColumn();
}

// ============================================
// 5. GENERAR CERTIFICADO INDIVIDUAL
// ============================================

function generarCertificadoPrueba(
    PDO $db,
    int $id
): string {

    $archivoQR = null;
    $ruta = null;
    $archivoCreado = false;

    try {

        // Se bloquea la fila del participante mientras
        // se comprueba y emite su certificado.
        $db->beginTransaction();

        // Obtener participante.
        $stmt = $db->prepare("
            SELECT
                id_participante,
                nombres_completos,
                estado
            FROM participantes
            WHERE id_participante = ?
            FOR UPDATE
        ");

        $stmt->execute([$id]);

        $p = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$p) {
            $db->rollBack();
            return 'No encontrado';
        }

        if ($p['estado'] !== 'ACTIVO') {
            $db->rollBack();
            return 'No apto: participante inactivo';
        }

        // Contar solamente las asistencias
        // del 2, 3 y 4 de diciembre de 2026.
        $totalAsistencias = contarAsistenciasValidas(
            $db,
            $id
        );

        if ($totalAsistencias < MINIMO_ASISTENCIAS) {
            $db->rollBack();

            return 'No apto: tiene ' .
                $totalAsistencias .
                ' de ' .
                SESIONES_ESPERADAS .
                ' asistencias';
        }

        // Comprobar si ya tiene certificado.
        $stmt = $db->prepare("
            SELECT id_certificado
            FROM certificados
            WHERE id_participante = ?
            LIMIT 1
        ");

        $stmt->execute([$id]);

        if ($stmt->fetch()) {
            $db->rollBack();
            return 'Ya emitido';
        }

        // ====================================
        // PLANTILLA DEL CERTIFICADO
        // ====================================

        $plantilla = __DIR__ .
            '/plantillas/plantilla_congreso_prueba.png';

        if (!is_file($plantilla)) {
            throw new RuntimeException(
                'No se encontró la plantilla del certificado.'
            );
        }

        // ====================================
        // DIRECTORIO DE CERTIFICADOS
        // ====================================

        $directorio = __DIR__ .
            '/certificados_prueba';

        if (
            !is_dir($directorio) &&
            !mkdir($directorio, 0755, true) &&
            !is_dir($directorio)
        ) {
            throw new RuntimeException(
                'No se pudo crear la carpeta de certificados.'
            );
        }

        // ====================================
        // CÓDIGO DE VERIFICACIÓN
        // ====================================

        $codigo = 'PRUEBA-PUNO-2026-' .
            strtoupper(bin2hex(random_bytes(8)));

        $url = URL_VERIFICACION_PRUEBA .
            '?codigo=' . urlencode($codigo);

        // ====================================
        // GENERAR QR
        // ====================================

        $qr = (new Builder(
            writer: new PngWriter(),
            data: $url,
            size: 220,
            margin: 5
        ))->build();

        $archivoQR = tempnam(
            sys_get_temp_dir(),
            'qr_'
        );

        if ($archivoQR === false) {
            throw new RuntimeException(
                'No se pudo crear el QR temporal.'
            );
        }

        $ruta = $directorio .
            '/' . $codigo . '.pdf';

        $rutaRelativa =
            'certificados_prueba/' .
            $codigo . '.pdf';

        if (
            file_put_contents(
                $archivoQR,
                $qr->getString()
            ) === false
        ) {
            throw new RuntimeException(
                'No se pudo guardar el QR.'
            );
        }

        // ====================================
        // GENERAR DOCUMENTO PDF
        // ====================================

        $pdf = new TCPDF(
            'L',
            'mm',
            'A4',
            true,
            'UTF-8',
            false
        );

        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);

        $pdf->AddPage();

        // Imagen de fondo.
        $pdf->Image(
            $plantilla,
            0,
            0,
            297,
            210,
            'PNG'
        );

        $pdf->SetTextColor(25, 25, 25);

        // Título.
        $pdf->SetFont(
            'dejavusans',
            'B',
            30
        );

        $pdf->SetXY(20, 39);

        $pdf->Cell(
            257,
            18,
            'CERTIFICADO',
            0,
            0,
            'C'
        );

        // Texto introductorio.
        $pdf->SetFont(
            'dejavusans',
            '',
            13
        );

        $pdf->SetXY(20, 60);

        $pdf->Cell(
            257,
            10,
            'Otorgado a:',
            0,
            0,
            'C'
        );

        // Nombre del participante.
        $pdf->SetFont(
            'dejavusans',
            'B',
            22
        );

        $pdf->SetXY(15, 76);

        $pdf->MultiCell(
            267,
            12,
            $p['nombres_completos'],
            0,
            'C',
            false,
            1
        );

        // Texto de participación.
        $pdf->SetFont(
            'dejavusans',
            '',
            12
        );

        $pdf->SetXY(20, 92);

        $pdf->Cell(
            257,
            10,
            'Por su participación en:',
            0,
            0,
            'C'
        );

        // Nombre del congreso.
        $pdf->SetFont(
            'dejavusans',
            'B',
            16
        );

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

        // ====================================
        // FECHAS CORREGIDAS: 2 AL 4
        // ====================================

        $pdf->SetFont(
            'dejavusans',
            '',
            11
        );

        $pdf->SetXY(19, 124);

        $pdf->MultiCell(
            259,
            7,
            'Certificado de prueba del II Congreso Peruano ' .
            'de Extensión Agropecuaria, realizado del ' .
            '2 al 4 de diciembre de 2026, en Puno, Perú.',
            0,
            'C',
            false,
            1
        );

        // Código de verificación.
        $pdf->SetFont(
            'dejavusans',
            '',
            8
        );

        $pdf->SetXY(170, 146);

        $pdf->Cell(
            78,
            7,
            $codigo,
            0,
            0,
            'R'
        );

        // ====================================
        // FIRMAS
        // ====================================

        $pdf->SetDrawColor(65, 65, 65);
        $pdf->SetLineWidth(0.3);

        $pdf->Line(20, 179, 95, 179);
        $pdf->Line(111, 179, 186, 179);
        $pdf->Line(202, 179, 267, 179);

        $pdf->SetFont(
            'dejavusans',
            'B',
            9
        );

        foreach ([
            [20, 75],
            [111, 75],
            [202, 65]
        ] as $firma) {

            $pdf->SetXY(
                $firma[0],
                181
            );

            $pdf->Cell(
                $firma[1],
                6,
                'AUTORIDAD ORGANIZADORA',
                0,
                0,
                'C'
            );
        }

        // Código QR.
        $pdf->Image(
            $archivoQR,
            253,
            143,
            26,
            26,
            'PNG'
        );

        // Marca de pruebas.
        $pdf->SetTextColor(
            160,
            55,
            55
        );

        $pdf->SetFont(
            'dejavusans',
            'B',
            9
        );

        $pdf->SetXY(98, 197);

        $pdf->Cell(
            101,
            7,
            'DOCUMENTO DE PRUEBA',
            0,
            0,
            'C'
        );

        // Obtener contenido PDF.
        $contenido = $pdf->Output('', 'S');

        // ====================================
        // GUARDAR REGISTRO EN MYSQL
        // ====================================

        $insert = $db->prepare("
            INSERT INTO certificados
                (
                    id_participante,
                    codigo_verificacion,
                    url_pdf,
                    estado
                )
            VALUES (?, ?, ?, 'VIGENTE')
        ");

        $insert->execute([
            $id,
            $codigo,
            $rutaRelativa
        ]);

        // ====================================
        // GUARDAR ARCHIVO PDF
        // ====================================

        $archivo = fopen($ruta, 'x+b');

        if ($archivo === false) {
            throw new RuntimeException(
                'No se pudo crear el PDF.'
            );
        }

        // Desde este momento el archivo existe,
        // por lo que puede eliminarse si falla.
        $archivoCreado = true;

        try {
            $bytes = fwrite(
                $archivo,
                $contenido
            );

        } finally {
            fclose($archivo);
        }

        if (
            $bytes === false ||
            $bytes !== strlen($contenido)
        ) {
            throw new RuntimeException(
                'El PDF se guardó incompleto.'
            );
        }

        // Confirmar operación.
        $db->commit();

        return 'Generado';

    } catch (Throwable $e) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        // Eliminar un PDF incompleto si se creó.
        if (
            $archivoCreado &&
            $ruta !== null &&
            is_file($ruta)
        ) {
            unlink($ruta);
        }

        throw $e;

    } finally {

        // Eliminar archivo QR temporal.
        if (
            is_string($archivoQR) &&
            is_file($archivoQR)
        ) {
            unlink($archivoQR);
        }
    }
}

// ============================================
// 6. PROCESAR SOLICITUD
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

try {

    // Verificar que existan exactamente
    // las 6 sesiones definitivas.
    $totalSesiones = contarSesionesValidas(
        $conexion
    );

    if ($totalSesiones !== SESIONES_ESPERADAS) {
        throw new RuntimeException(
            'Configuración incorrecta de sesiones. ' .
            'Se esperaban 6 y se encontraron ' .
            $totalSesiones . '.'
        );
    }

    // ========================================
    // GENERACIÓN INDIVIDUAL
    // ========================================

    if ($accion === 'individual') {

        $id = filter_var(
            $_POST['id_participante'] ?? null,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1
                ]
            ]
        );

        if (!$id) {
            throw new RuntimeException(
                'Participante inválido.'
            );
        }

        $resultado = generarCertificadoPrueba(
            $conexion,
            (int)$id
        );

        $_SESSION['mensaje_certificados_test'] =
            "Participante {$id}: {$resultado}.";

    // ========================================
    // GENERACIÓN MASIVA
    // ========================================

    } elseif ($accion === 'masivo') {

        // Contar únicamente asistencias
        // de sesiones del 2 al 4 de diciembre.
        $stmt = $conexion->prepare("
            SELECT p.id_participante

            FROM participantes p

            LEFT JOIN asistencias a
                ON a.id_participante = p.id_participante

            LEFT JOIN sesiones s
                ON s.id_sesion = a.id_sesion
               AND s.fecha BETWEEN ? AND ?

            LEFT JOIN certificados c
                ON c.id_participante = p.id_participante

            WHERE p.estado = 'ACTIVO'
              AND c.id_certificado IS NULL

            GROUP BY p.id_participante

            HAVING COUNT(
                DISTINCT CASE
                    WHEN s.id_sesion IS NOT NULL
                    THEN s.id_sesion
                END
            ) >= ?

            ORDER BY p.id_participante
        ");

        $stmt->execute([
            FECHA_INICIO_CONGRESO,
            FECHA_FIN_CONGRESO,
            MINIMO_ASISTENCIAS
        ]);

        $ids = $stmt->fetchAll(
            PDO::FETCH_COLUMN
        );

        $generados = 0;
        $omitidos = 0;
        $errores = 0;

        foreach ($ids as $id) {

            try {

                $resultado = generarCertificadoPrueba(
                    $conexion,
                    (int)$id
                );

                if ($resultado === 'Generado') {
                    $generados++;
                } else {
                    $omitidos++;
                }

            } catch (Throwable $e) {

                error_log(
                    'Error certificado participante ' .
                    $id . ': ' .
                    $e->getMessage()
                );

                $errores++;
            }
        }

        $_SESSION['mensaje_certificados_test'] =
            "Proceso terminado. " .
            "Generados: {$generados}. " .
            "Omitidos: {$omitidos}. " .
            "Errores: {$errores}.";
    }

} catch (Throwable $e) {

    error_log(
        'Error de generación de certificados: ' .
        $e->getMessage()
    );

    $_SESSION['mensaje_certificados_test'] =
        'No se pudo completar la operación. ' .
        'Revisa la configuración de sesiones ' .
        'y el registro de errores del servidor.';
}

// ============================================
// 7. REGRESAR AL PANEL
// ============================================

header(
    'Location: generar_certificados_test.php',
    true,
    303
);

exit;
