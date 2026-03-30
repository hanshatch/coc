<?php
declare(strict_types=1);

/**
 * Script de instalación automática de base de datos.
 * Ejecuta el contenido de schema.sql usando las credenciales configuradas.
 */

require_once __DIR__ . '/config/database.php';

echo "<h1>🛠️ Instalador de Base de Datos — Clash Tracker</h1>";

try {
    $db = getDB();
    echo "✅ Conectado a la base de datos.<br>";

    $sqlFile = __DIR__ . '/sql/schema.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("No se encuentra el archivo SQL en: $sqlFile");
    }

    // Obtener el contenido del SQL
    $sql = file_get_contents($sqlFile);

    // Dividir en sentencias por el punto y coma (;)
    // Esto es un parser simple, pero suficiente para este esquema.
    $queries = explode(';', $sql);

    echo "Ejecutando sentencias SQL una por una...<br>";

    $executedCount = 0;
    foreach ($queries as $query) {
        $q = trim($query);
        if (empty($q)) continue;

        // Omitir sentencias que interfieren con una conexión ya establecida
        if (stripos($q, 'CREATE DATABASE') === 0 || stripos($q, 'USE ') === 0) {
            continue;
        }

        try {
            $db->exec($q);
            $executedCount++;
        } catch (PDOException $e) {
            // Si falla una sentencia, la mostramos para depurar
            echo "<div style='color:orange; font-size: 0.8em;'>⚠️ Falló: " . substr($q, 0, 50) . "... - Error: " . $e->getMessage() . "</div>";
        }
    }

    echo "<br>✅ $executedCount sentencias ejecutadas correctamente!<br>";
    echo "<hr>";
    echo "<p>Ahora puedes ir al setup para crear tu usuario administrador:</p>";
    echo "<a href='setup.php' style='padding:10px 20px; background:gold; color:black; font-weight:bold; text-decoration:none; border-radius:5px;'>Ir al Setup (Crear Admin)</a>";

} catch (PDOException $e) {
    echo "<h2 style='color:red'>❌ Error de PDO:</h2>";
    echo "<pre>" . $e->getMessage() . "</pre>";
} catch (Exception $e) {
    echo "<h2 style='color:red'>❌ Error General:</h2>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
