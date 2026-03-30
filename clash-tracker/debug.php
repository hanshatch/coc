<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h1>Debug Mode</h1>";

try {
    echo "Probando conexión a base de datos...<br>";
    require_once __DIR__ . '/config/database.php';
    $db = getDB();
    echo "✅ Conexión exitosa!<br>";

    echo "Verificando tabla 'usuarios'...<br>";
    $stmt = $db->query('SHOW TABLES LIKE "usuarios"');
    if ($stmt->fetch()) {
        echo "✅ La tabla 'usuarios' existe.<br>";
    } else {
        echo "❌ La tabla 'usuarios' NO existe. ¿Has importado el SQL?<br>";
    }

    echo "Probando sesión...<br>";
    session_start();
    echo "✅ Sesión iniciada correctamente.<br>";

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage();
}
