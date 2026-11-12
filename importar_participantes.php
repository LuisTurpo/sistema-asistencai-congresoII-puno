
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

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

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

// ==========================================
// 2. FUNCIONES
// ==========================================

function escapar($valor) {
    return htmlspecialchars(
        (string)($valor ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function normalizar($texto) {
    $texto = mb_strtolower(
        trim((string)$texto),
        'UTF-8'
    );

    $texto = preg_replace('/\s+/u', ' ', $texto);

    return strtr($texto, [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ü' => 'u'
    ]);
}

function clave($texto) {
    return mb_strtoupper(
        trim((string)$texto),
        'UTF-8'
    );
}

function obtenerColumnas($hoja) {
    $encabezados = [];

    $ultimaColumna = Coordinate::columnIndexFromString(
        $hoja->getHighestDataColumn()
    );

    for ($col = 1; $col <= $ultimaColumna; $col++) {
        $letra = Coordinate::stringFromColumnIndex($col);

        $titulo = normalizar(
            $hoja->getCell($letra . '1')->getValue()
        );

        if ($titulo !== '') {
            $encabezados[$titulo] = $letra;
        }
    }

    $buscar = function ($alternativas) use ($encabezados) {
        foreach ($alternativas as $alternativa) {
            foreach ($encabezados as $titulo => $columna) {
                if (str_contains(
                    $titulo,
                    normalizar($alternativa)
                )) {
                    return $columna;
                }
            }
        }

        return null;
    };

    return [
        'documento' => $buscar([
            'dni / pasaporte',
            'dni/pasaporte',
            'documento de identidad',
            'numero de documento',
            'dni'
        ]),
        'nombre' => $buscar([
            'nombres y apellidos completos',
            'nombres completos',
            'apellidos y nombres'
        ]),
        'correo' => $buscar([
            'correo electronico',
            'email',
            'e-mail'
        ]),
        'institucion' => $buscar([
            'nombre de la institucion',
            'institucion'
        ]),
        'codigo' => $buscar([
            'codigo de inscripcion',
            'codigo_inscripcion'
        ])
    ];
}

function obtenerValor($hoja, $columna, $fila) {
    if ($columna === null) {
        return '';
    }

    return trim((string)$hoja
        ->getCell($columna . $fila)
        ->getFormattedValue());
}

function crearCodigo($conexion, $reservados) {
    $stmt = $conexion->prepare("
        SELECT COUNT(*)
        FROM participantes
        WHERE codigo_inscripcion = ?
    ");

    do {
        $codigo = 'CONG-PRUEBA-' .
            strtoupper(bin2hex(random_bytes(6)));

        $stmt->execute([$codigo]);

        $existe = (int)$stmt->fetchColumn() > 0
            || isset($reservados[clave($codigo)]);

    } while ($existe);

    return $codigo;
}

// ==========================================
// 3. TOKEN CSRF
// ==========================================

if (empty($_SESSION['csrf_importacion_test'])) {
    $_SESSION['csrf_importacion_test'] =
        bin2hex(random_bytes(32));
}

// ==========================================
// 4. VARIABLES
// ==========================================

$mensaje = '';
$tipo = 'info';

$participantes = [];
$duplicados = [];
$errores = [];

$totalFilas = 0;
$importados = 0;
$correosCorregidos = 0;
$existentes = 0;
$codigosGenerados = 0;

$accion = '';

// ==========================================
// 5. PROCESAR ARCHIVO
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals(
            $_SESSION['csrf_importacion_test'],
            $token
        )
    ) {
        http_response_code(403);
        exit('Solicitud no válida.');
    }

    $accion = $_POST['accion'] ?? '';

    if (!in_array($accion, ['revisar', 'importar'], true)) {
        http_response_code(400);
        exit('Acción no válida.');
    }

    if (
        !isset($_FILES['excel']) ||
        $_FILES['excel']['error'] !== UPLOAD_ERR_OK
    ) {
        $mensaje = 'No se pudo subir el archivo Excel.';
        $tipo = 'danger';

    } elseif (
        $_FILES['excel']['size'] > 10 * 1024 * 1024
    ) {
        $mensaje = 'El archivo supera los 10 MB.';
        $tipo = 'danger';

    } else {

        $archivo = $_FILES['excel']['tmp_name'];

        $extension = strtolower(pathinfo(
            $_FILES['excel']['name'],
            PATHINFO_EXTENSION
        ));

        if (!in_array($extension, ['xlsx', 'xls'], true)) {
            $mensaje = 'Solo se permiten archivos .xlsx y .xls.';
            $tipo = 'danger';

        } else {

            $libro = null;

            try {

                $lector = IOFactory::createReaderForFile($archivo);
                $lector->setReadDataOnly(false);

                $libro = $lector->load($archivo);
                $hoja = $libro->getActiveSheet();

                $columnas = obtenerColumnas($hoja);

                // Documento y nombre son obligatorios.
                // Los demás encabezados son opcionales.

                if (
                    $columnas['documento'] === null ||
                    $columnas['nombre'] === null
                ) {
                    throw new RuntimeException(
                        'No se encontraron los encabezados ' .
                        'de documento y nombres completos.'
                    );
                }

                $documentosVistos = [];
                $codigosVistos = [];

                // Consultas preparadas para detectar
                // participantes ya registrados.

                $buscarDocumento = $conexion->prepare("
                    SELECT id_participante
                    FROM participantes
                    WHERE UPPER(TRIM(documento)) = ?
                    LIMIT 1
                ");

                $buscarCodigo = $conexion->prepare("
                    SELECT id_participante
                    FROM participantes
                    WHERE UPPER(TRIM(codigo_inscripcion)) = ?
                    LIMIT 1
                ");

                $ultimaFila = $hoja->getHighestDataRow();

                for ($i = 2; $i <= $ultimaFila; $i++) {

                    $documento = obtenerValor(
                        $hoja, $columnas['documento'], $i
                    );

                    $nombre = obtenerValor(
                        $hoja, $columnas['nombre'], $i
                    );

                    $correo = obtenerValor(
                        $hoja, $columnas['correo'], $i
                    );

                    $institucion = obtenerValor(
                        $hoja, $columnas['institucion'], $i
                    );

                    $codigo = obtenerValor(
                        $hoja, $columnas['codigo'], $i
                    );

                    if (
                        $documento === '' &&
                        $nombre === '' &&
                        $correo === '' &&
                        $institucion === '' &&
                        $codigo === ''
                    ) {
                        continue;
                    }

                    $totalFilas++;

                    // Validaciones básicas.

                    if ($documento === '' || $nombre === '') {
                        $errores[] =
                            "Fila $i: falta documento o nombre.";
                        continue;
                    }

                    if (
                        mb_strlen($documento) > 20 ||
                        mb_strlen($nombre) > 200 ||
                        mb_strlen($institucion) > 200 ||
                        mb_strlen($codigo) > 50
                    ) {
                        $errores[] =
                            "Fila $i: un campo supera la longitud permitida.";
                        continue;
                    }

                    // Detectar duplicados dentro del Excel.

                    $claveDocumento = clave($documento);

                    if (isset($documentosVistos[$claveDocumento])) {
                        $duplicados[] = [
                            'fila' => $i,
                            'documento' => $documento,
                            'motivo' => 'Repetido en Excel, fila ' .
                                $documentosVistos[$claveDocumento]
                        ];
                        continue;
                    }

                    $documentosVistos[$claveDocumento] = $i;

                    // Detectar documentos existentes en MySQL.

                    $buscarDocumento->execute([$claveDocumento]);

                    if ($buscarDocumento->fetchColumn() !== false) {
                        $existentes++;

                        $duplicados[] = [
                            'fila' => $i,
                            'documento' => $documento,
                            'motivo' => 'Ya existe en MySQL'
                        ];

                        continue;
                    }

                    // Validar correo electrónico.

                    if (
                        $correo !== '' &&
                        (
                            mb_strlen($correo) > 254 ||
                            !filter_var(
                                $correo,
                                FILTER_VALIDATE_EMAIL
                            )
                        )
                    ) {
                        $correo = '';
                        $correosCorregidos++;
                    }

                    // Verificar código de inscripción.

                    if ($codigo !== '') {

                        $claveCodigo = clave($codigo);

                        if (isset($codigosVistos[$claveCodigo])) {
                            $errores[] =
                                "Fila $i: código de inscripción repetido.";
                            continue;
                        }

                        $buscarCodigo->execute([$claveCodigo]);

                        if ($buscarCodigo->fetchColumn() !== false) {
                            $errores[] =
                                "Fila $i: código de inscripción ya existe en MySQL.";
                            continue;
                        }

                    } else {

                        // Generar un código cuando
                        // el Excel no lo proporciona.

                        $codigo = crearCodigo(
                            $conexion,
                            $codigosVistos
                        );

                        $claveCodigo = clave($codigo);
                        $codigosGenerados++;
                    }

                    $codigosVistos[$claveCodigo] = true;

                    $participantes[] = [
                        'fila' => $i,
                        'documento' => $documento,
                        'nombre' => $nombre,
                        'correo' => $correo,
                        'institucion' => $institucion,
                        'codigo' => $codigo
                    ];
                }

                // ======================================
                // 6. RESULTADO DE LA VALIDACIÓN
                // ======================================

                if ($totalFilas === 0) {

                    $mensaje = 'El Excel está vacío.';
                    $tipo = 'warning';

                } elseif (!empty($errores)) {

                    $mensaje =
                        'Se encontraron errores. ' .
                        'No se importó ningún participante.';

                    $tipo = 'danger';

                } elseif ($accion === 'revisar') {

                    $mensaje =
                        'Validación completada. ' .
                        'No se guardaron datos en MySQL.';

                    $tipo = 'info';

                } elseif (empty($participantes)) {

                    $mensaje =
                        'No hay participantes nuevos para importar.';

                    $tipo = 'warning';

                } else {

                    // ======================================
                    // 7. IMPORTACIÓN A MYSQL
                    // ======================================

                    $conexion->beginTransaction();

                    try {

                        $insertar = $conexion->prepare("
                            INSERT INTO participantes
                            (
                                documento,
                                nombres_completos,
                                correo,
                                codigo_inscripcion,
                                institucion,
                                estado
                            )
                            VALUES (?, ?, ?, ?, ?, 'ACTIVO')
                        ");

                        foreach ($participantes as $p) {

                            // Volver a verificar el documento
                            // antes de insertar.

                            $buscarDocumento->execute([
                                clave($p['documento'])
                            ]);

                            if (
                                $buscarDocumento->fetchColumn()
                                !== false
                            ) {
                                $existentes++;

                                $duplicados[] = [
                                    'fila' => $p['fila'],
                                    'documento' => $p['documento'],
                                    'motivo' =>
                                        'Ya registrado antes de importar'
                                ];

                                continue;
                            }

                            $buscarCodigo->execute([
                                clave($p['codigo'])
                            ]);

                            if (
                                $buscarCodigo->fetchColumn()
                                !== false
                            ) {
                                throw new RuntimeException(
                                    'Código de inscripción duplicado.'
                                );
                            }

                            $insertar->execute([
                                $p['documento'],
                                $p['nombre'],
                                $p['correo'] !== ''
                                    ? $p['correo'] : null,
                                $p['codigo'],
                                $p['institucion'] !== ''
                                    ? $p['institucion'] : null
                            ]);

                            $importados++;
                        }

                        $conexion->commit();

                        $mensaje =
                            "$importados participantes nuevos " .
                            "importados correctamente. " .
                            "$existentes documentos ya existentes omitidos.";

                        $tipo = 'success';

                    } catch (Throwable $e) {

                        if ($conexion->inTransaction()) {
                            $conexion->rollBack();
                        }

                        $importados = 0;

                        error_log($e->getMessage());

                        $mensaje =
                            'No se completó la importación. ' .
                            'Revise los documentos y códigos duplicados.';

                        $tipo = 'danger';
                    }
                }

            } catch (Throwable $e) {

                error_log($e->getMessage());

                $mensaje =
                    'No se pudo procesar el archivo Excel. ' .
                    'Compruebe el formato y los encabezados.';

                $tipo = 'danger';

            } finally {

                if ($libro !== null) {
                    $libro->disconnectWorksheets();
                    unset($libro);
                }
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="es">

<head>
<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1">

<title>Importar participantes - Congreso Puno 2026</title>

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
    background: linear-gradient(120deg, #17406b, #24659b);
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
    font-size: 28px;
    font-weight: 700;
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

.table thead th {
    white-space: nowrap;
}

</style>
</head>

<body>

<div class="container py-4">

    <!-- ENCABEZADO -->

    <div class="encabezado shadow-sm mb-4">

        <div class="d-flex justify-content-between
                    align-items-center flex-wrap gap-3">

            <div>

                <h3 class="fw-bold mb-2">
                    Importación de Participantes
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

            <a href="dashboard.php"
               class="btn btn-light">
                <i class="bi bi-arrow-left"></i>
                Volver al panel
            </a>

        </div>

    </div>

    <!-- FORMULARIO -->

    <div class="card tarjeta shadow-sm mb-4">

        <div class="card-body p-4">

            <h5 class="titulo mb-3">
                <i class="bi bi-file-earmark-excel me-2"></i>
                Seleccionar archivo Excel
            </h5>

            <?php if ($mensaje): ?>

                <div class="alert alert-<?= escapar($tipo) ?>">
                    <?= escapar($mensaje) ?>
                </div>

            <?php endif; ?>

            <div class="alert alert-info">

                <strong>Información:</strong>

                El sistema detecta documentos repetidos
                dentro del Excel y documentos que ya existen
                en MySQL.

                Los participantes existentes no serán
                modificados ni perderán sus asistencias.

            </div>

            <form
                method="POST"
                enctype="multipart/form-data"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escapar(
                        $_SESSION['csrf_importacion_test']
                    ) ?>"
                >

                <div class="mb-3">

                    <label class="form-label fw-bold">
                        Archivo Excel (.xlsx o .xls)
                    </label>

                    <input
                        type="file"
                        name="excel"
                        class="form-control"
                        accept=".xlsx,.xls"
                        required
                    >

                    <div class="form-text">
                        Tamaño máximo: 10 MB.
                    </div>

                </div>

                <div class="d-flex gap-2 flex-wrap">

                    <button
                        type="submit"
                        name="accion"
                        value="revisar"
                        class="btn btn-principal"
                    >
                        <i class="bi bi-search"></i>
                        Revisar Excel
                    </button>

                    <button
                        type="submit"
                        name="accion"
                        value="importar"
                        class="btn btn-success"
                        onclick="return confirm('¿Importar únicamente los participantes nuevos a la base de pruebas?');"
                    >
                        <i class="bi bi-database-add"></i>
                        Importar a MySQL
                    </button>

                </div>

            </form>

        </div>

    </div>

    <!-- RESULTADOS -->

    <?php if ($totalFilas > 0): ?>

        <div class="row g-3 mb-4">

            <div class="col-md-3">
                <div class="card tarjeta shadow-sm p-3">
                    <small class="text-muted">Filas del Excel</small>
                    <div class="numero">
                        <?= $totalFilas ?>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card tarjeta shadow-sm p-3">
                    <small class="text-muted">Participantes nuevos</small>
                    <div class="numero text-success">
                        <?= count($participantes) ?>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card tarjeta shadow-sm p-3">
                    <small class="text-muted">Duplicados omitidos</small>
                    <div class="numero text-warning">
                        <?= count($duplicados) ?>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card tarjeta shadow-sm p-3">
                    <small class="text-muted">Errores</small>
                    <div class="numero text-danger">
                        <?= count($errores) ?>
                    </div>
                </div>
            </div>

        </div>

        <div class="card tarjeta shadow-sm mb-4">

            <div class="card-body">

                <h5 class="titulo">Resumen de validación</h5>

                <p>
                    Correos inválidos dejados en blanco:
                    <strong><?= $correosCorregidos ?></strong>
                </p>

                <p>
                    Códigos de inscripción generados:
                    <strong><?= $codigosGenerados ?></strong>
                </p>

                <p>
                    Participantes importados en esta operación:
                    <strong><?= $importados ?></strong>
                </p>

                <?php if ($errores): ?>

                    <div class="alert alert-danger">

                        <h6>Errores encontrados</h6>

                        <ul class="mb-0">
                            <?php foreach (
                                array_slice($errores, 0, 30) as $error
                            ): ?>
                                <li><?= escapar($error) ?></li>
                            <?php endforeach; ?>
                        </ul>

                    </div>

                <?php endif; ?>

                <?php if ($duplicados): ?>

                    <h6 class="mt-4">
                        Documentos duplicados u omitidos
                    </h6>

                    <div class="table-responsive">

                        <table class="table table-bordered table-sm">

                            <thead class="table-light">
                                <tr>
                                    <th>Fila</th>
                                    <th>Documento</th>
                                    <th>Motivo</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach (
                                    array_slice($duplicados, 0, 30) as $d
                                ): ?>

                                    <tr>
                                        <td><?= (int)$d['fila'] ?></td>
                                        <td><?= escapar($d['documento']) ?></td>
                                        <td><?= escapar($d['motivo']) ?></td>
                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </div>

        </div>

        <div class="card tarjeta shadow-sm mb-4">

            <div class="card-body">

                <h5 class="titulo mb-3">
                    Vista previa de participantes nuevos
                </h5>

                <div class="table-responsive">

                    <table class="table table-striped table-bordered">

                        <thead class="table-dark">

                            <tr>
                                <th>Documento</th>
                                <th>Nombres completos</th>
                                <th>Correo</th>
                                <th>Institución</th>
                                <th>Código</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach (
                                array_slice($participantes, 0, 20) as $p
                            ): ?>

                                <tr>

                                    <td>
                                        <?= escapar($p['documento']) ?>
                                    </td>

                                    <td>
                                        <?= escapar($p['nombre']) ?>
                                    </td>

                                    <td>
                                        <?= escapar($p['correo']) ?>
                                    </td>

                                    <td>
                                        <?= escapar($p['institucion']) ?>
                                    </td>

                                    <td>
                                        <?= escapar($p['codigo']) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            <?php if (empty($participantes)): ?>

                                <tr>
                                    <td colspan="5"
                                        class="text-center text-muted">
                                        No hay participantes nuevos.
                                    </td>
                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

                <small class="text-muted">
                    Se muestran hasta 20 registros nuevos.
                </small>

            </div>

        </div>

    <?php endif; ?>

    <div class="alert alert-light border">

        <strong>Importante:</strong>

        Esta herramienta trabaja exclusivamente
        con la base de datos
        <code>congreso_puno_pruebas</code>.

        No modifica participantes de la base oficial.

    </div>

</div>

</body>
</html>
