<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

echo "<h1>🛠️ Actualizando Tabla Jugadores</h1>";

try {
    $db = getDB();
    echo "✅ Conectado.<br>";

    // Función auxiliar para saber si una columna existe
    function columnExists($db, $table, $column) {
        $stmt = $db->query("SHOW COLUMNS FROM $table LIKE '$column'");
        return (bool) $stmt->fetch();
    }

    // 1. Eliminar columnas innecesarias
    $colsToDelete = ['nombre', 'nivel_th', 'nivel_jugador'];
    foreach ($colsToDelete as $col) {
        if (columnExists($db, 'jugadores', $col)) {
            echo "Eliminando columna '$col'... ";
            $db->exec("ALTER TABLE jugadores DROP COLUMN $col");
            echo "✅<br>";
        } else {
            echo "La columna '$col' ya no existe. Saltando...<br>";
        }
    }

    // 2. Renombrar 'tag' a 'usuario'
    if (columnExists($db, 'jugadores', 'tag')) {
        echo "Renombrando 'tag' a 'usuario'... ";
        // MySQL/MariaDB compatible syntax
        $db->exec("ALTER TABLE jugadores CHANGE tag usuario VARCHAR(50) NOT NULL");
        echo "✅<br>";
    } elseif (columnExists($db, 'jugadores', 'usuario')) {
        echo "La columna 'usuario' ya existe. ✅<br>";
    } else {
        echo "❌ No se encontró columna 'tag' ni 'usuario' en jugadores. Verifica la tabla.<br>";
    }

    // 3. Agregar 'tamano' a guerras
    if (!columnExists($db, 'guerras', 'tamano')) {
        echo "Añadiendo columna 'tamano' a guerras... ";
        $db->exec("ALTER TABLE guerras ADD COLUMN tamano INT DEFAULT 15 AFTER oponente");
        echo "✅<br>";
    } else {
        echo "La columna 'tamano' ya existe en guerras. ✅<br>";
    }

    echo "<hr>✅ Proceso de actualización finalizado!";
    echo "<br><br><a href='jugadores' style='padding:10px 20px; background:gold; color:black; text-decoration:none; font-weight:bold;'>Volver a Jugadores</a>";

} catch (Exception $e) {
    echo "<h2 style='color:red'>❌ Error:</h2>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
