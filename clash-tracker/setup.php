<?php
declare(strict_types=1);

/**
 * Setup inicial — Crear usuario admin.
 * Ejecutar una sola vez. Eliminar después de usar.
 */

require_once __DIR__ . '/config/database.php';

$adminUser = 'admin';
$adminPass = 'admin123';
$adminName = 'Administrador';

$db   = getDB();
$hash = password_hash($adminPass, PASSWORD_DEFAULT);

// Upsert: crear o actualizar
$stmt = $db->prepare(
    'INSERT INTO usuarios (username, password_hash, nombre, rol, activo)
     VALUES (?, ?, ?, "admin", 1)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), nombre = VALUES(nombre), rol = "admin", activo = 1'
);
$stmt->execute([$adminUser, $hash, $adminName]);

echo "✅ Usuario admin creado/actualizado.\n";
echo "   Usuario: {$adminUser}\n";
echo "   Contraseña: {$adminPass}\n";
echo "\n⚠️  ELIMINA este archivo (setup.php) cuando termines.\n";
