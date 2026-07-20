<?php
declare(strict_types=1);

/**
 * Conexión a base de datos y constantes globales.
 * Las credenciales viven en credentials.php (no versionado).
 * Zona horaria: America/Mexico_City
 */

date_default_timezone_set('America/Mexico_City');

$credentials = __DIR__ . '/credentials.php';
if (!is_file($credentials)) {
    http_response_code(500);
    die('Configuración incompleta. Copia config/credentials.example.php a config/credentials.php.');
}
require_once $credentials;

// ── Constantes de la app ──────────────────────────────────────
define('APP_NAME', 'H@tch ⚔️');
define('APP_VERSION', '1.0.0');

// Poner en true solo para depurar en local.
define('APP_DEBUG', false);

/**
 * Obtiene una instancia PDO singleton.
 *
 * @return PDO
 */
function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        ini_set('display_errors', APP_DEBUG ? '1' : '0');
        error_reporting(APP_DEBUG ? E_ALL : 0);

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            $pdo->exec("SET time_zone = '-06:00'");
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            die(APP_DEBUG
                ? 'Error de conexión: ' . $e->getMessage()
                : 'Servicio no disponible temporalmente. Intenta más tarde.');
        }
    }

    return $pdo;
}
