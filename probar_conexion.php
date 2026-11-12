
<?php

require_once __DIR__ . '/config/database.php';

$consulta = $conexion->query(
    "SELECT COUNT(*) AS total FROM sesiones"
);

$resultado = $consulta->fetch();

echo "<h2>Conexion exitosa con MySQL</h2>";
echo "<p>Base de datos: congreso_puno_2026</p>";
echo "<p>Sesiones registradas: "
    . $resultado['total'] . "</p>";
