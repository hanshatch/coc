<?php
declare(strict_types=1);

/**
 * Configuración de conexión a base de datos y constantes globales.
 * Zona horaria: America/Mexico_City
 */

date_default_timezone_set('America/Mexico_City');

// ── Credenciales ──────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'u863784331_c0fcl4ns');
define('DB_USER', 'u863784331_ucl45h');
define('DB_PASS', '7N9|9>~X9;Cv');
define('DB_CHARSET', 'utf8mb4');

// ── Constantes de la app ──────────────────────────────────────
define('APP_NAME', 'H@tch Clan System');
define('APP_VERSION', '1.0.0');

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

        try {
            ini_set('display_errors', '1');
            error_reporting(E_ALL);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            $pdo->exec("SET time_zone = '-06:00'");
        } catch (PDOException $e) {
            // Quitamos el http_response_code(500) para ver el mensaje directo en pantalla
            die('Error de conexión a la base de datos: ' . $e->getMessage());
        }
    }

    return $pdo;
}
