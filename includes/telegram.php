<?php
declare(strict_types=1);

/**
 * Envío de mensajes por Telegram.
 *
 * El token del bot vive en config/credentials.php, que no se versiona.
 * Un bot no puede iniciar una conversación: la persona tiene que
 * escribirle primero para que exista el chat.
 */

require_once __DIR__ . '/../config/database.php';

define('TG_API', 'https://api.telegram.org/bot');
define('TG_TIMEOUT', 15);

function tgConfigurado(): bool
{
    return defined('TELEGRAM_BOT_TOKEN') && TELEGRAM_BOT_TOKEN !== '';
}

/**
 * Escapa lo que HTML de Telegram interpreta como marcado.
 * Los nombres de Clash traen símbolos raros con frecuencia.
 */
function tgEscapar(string $s): string
{
    return htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Llama a un método de la API de Telegram.
 *
 * @param  array<string,mixed> $params
 * @return array<string,mixed>|null  null si no se pudo contactar
 */
function tgLlamar(string $metodo, array $params = []): ?array
{
    if (!tgConfigurado()) {
        return null;
    }

    $ch = curl_init(TG_API . TELEGRAM_BOT_TOKEN . '/' . $metodo);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => TG_TIMEOUT,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        error_log('Telegram: no se pudo contactar la API — ' . $err);
        return null;
    }

    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        error_log('Telegram: respuesta ilegible');
        return null;
    }

    if (!($data['ok'] ?? false)) {
        error_log('Telegram: ' . ($data['description'] ?? 'error desconocido'));
    }

    return $data;
}

/**
 * Manda un mensaje. El texto admite HTML sencillo: <b>, <i>, <code>.
 *
 * Telegram corta los mensajes a 4096 caracteres, así que se parte por
 * saltos de línea para no cortar una palabra a la mitad.
 */
function tgEnviar(string $texto, ?string $chatId = null): bool
{
    $destino = $chatId ?? (defined('TELEGRAM_CHAT_ID') ? TELEGRAM_CHAT_ID : '');
    if ($destino === '') {
        error_log('Telegram: falta el chat de destino');
        return false;
    }

    $ok = true;
    foreach (tgPartir($texto) as $parte) {
        $r = tgLlamar('sendMessage', [
            'chat_id'    => $destino,
            'text'       => $parte,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ]);
        $ok = $ok && (bool) ($r['ok'] ?? false);
    }

    return $ok;
}

// ── Ajustes que el sistema aprende solo ───────────────────────

function tgAjuste(string $clave): ?string
{
    $stmt = getDB()->prepare('SELECT valor FROM ajustes WHERE clave = ?');
    $stmt->execute([$clave]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (string) $v;
}

function tgGuardarAjuste(string $clave, string $valor): void
{
    getDB()->prepare('INSERT INTO ajustes (clave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')
           ->execute([$clave, $valor]);
}

/**
 * Parte un texto largo en trozos que quepan en un mensaje.
 *
 * @return list<string>
 */
function tgPartir(string $texto, int $limite = 3900): array
{
    if (mb_strlen($texto) <= $limite) {
        return [$texto];
    }

    $partes = [];
    $actual = '';
    foreach (explode("\n", $texto) as $linea) {
        if (mb_strlen($actual) + mb_strlen($linea) + 1 > $limite) {
            $partes[] = rtrim($actual);
            $actual   = '';
        }
        $actual .= $linea . "\n";
    }
    if (trim($actual) !== '') {
        $partes[] = rtrim($actual);
    }

    return $partes;
}
