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

    $sql = file_get_contents($sqlFile);

    // Limpiar el SQL: quitar comentarios y sentencias 'CREATE DATABASE' o 'USE'
    // ya que estamos conectados directamente a la DB configurada.
    $lines = explode("\n", $sql);
    $cleanSql = "";
    foreach ($lines as $line) {
        $l = trim($line);
        if (empty($l) || str_starts_with($l, '--') || str_starts_with($l, 'CREATE DATABASE') || str_starts_with($l, 'USE')) {
            continue;
        }
        $cleanSql .= $line . "\n";
    }

    echo "Ejecutando sentencias SQL...<br>";

    // Separar por ; y ejecutar cada comando (exec() no soporta multi-query de forma segura a veces)
    // Pero si usamos exec() sobre el string completo en MySQL funciona para DDL.
    $db->exec($cleanSql);

    echo "✅ Tablas creadas correctamente!<br>";
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
