<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

echo "<h1>🛠️ Actualizando Tabla Jugadores</h1>";

try {
    $db = getDB();
    echo "✅ Conectado.<br>";

    // 1. Eliminar columnas innecesarias
    echo "Eliminando columnas 'nombre', 'nivel_th', 'nivel_jugador'...<br>";
    $db->exec("ALTER TABLE jugadores DROP COLUMN IF EXISTS nombre");
    $db->exec("ALTER TABLE jugadores DROP COLUMN IF EXISTS nivel_th");
    $db->exec("ALTER TABLE jugadores DROP COLUMN IF EXISTS nivel_jugador");

    // 2. Renombrar 'tag' a 'usuario'
    // Nota: Usamos CHANGE para MariaDB/MySQL antiguo o RENAME COLUMN para nuevos.
    // CHANGE es más compatible.
    echo "Renombrando 'tag' a 'usuario'...<br>";
    $db->exec("ALTER TABLE jugadores CHANGE tag usuario VARCHAR(20) NOT NULL");

    echo "✅ Actualización de base de datos completada!<br>";

} catch (Exception $e) {
    echo "<h2 style='color:red'>❌ Error:</h2>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
