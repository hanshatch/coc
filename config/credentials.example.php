<?php
declare(strict_types=1);

/**
 * Plantilla de credenciales. Copiar a credentials.php y completar.
 * credentials.php está en .gitignore y nunca debe versionarse.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ── API oficial de Clash of Clans (developer.clashofclans.com) ──
// El token se ata a la IP de salida del servidor. Si la IP no coincide,
// la API responde 403 a todas las peticiones.
define('COC_API_TOKEN', '');
define('COC_CLAN_TAG', '');

// ── Telegram (crear el bot con @BotFather) ──
// Un bot no puede escribir primero: la persona debe mandarle un mensaje
// para que exista el chat y se pueda obtener su id.
define('TELEGRAM_BOT_TOKEN', '');
define('TELEGRAM_CHAT_ID', '');
