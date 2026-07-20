<?php
declare(strict_types=1);

/**
 * Cliente de la API oficial de Clash of Clans.
 *
 * El token se ata a la IP de salida del servidor (ver developer.clashofclans.com).
 * Vive en config/credentials.php, que no se versiona.
 */

require_once __DIR__ . '/../config/database.php';

define('COC_API_BASE', 'https://api.clashofclans.com/v1');
define('COC_TIMEOUT', 15);

/**
 * Excepción de la API, con el código HTTP para poder distinguir causas.
 */
class CocApiException extends RuntimeException
{
}

/**
 * ¿Están configuradas las credenciales de la API?
 */
function cocConfigurado(): bool
{
    return defined('COC_API_TOKEN') && COC_API_TOKEN !== ''
        && defined('COC_CLAN_TAG') && COC_CLAN_TAG !== '';
}

/**
 * Normaliza un tag: mayúsculas, con # inicial y sin espacios.
 * Supercell usa O y 0 de forma ambigua en la fuente del juego, pero el tag
 * real solo contiene 0 (cero), nunca la letra O.
 */
function cocNormalizarTag(string $tag): string
{
    $tag = strtoupper(trim($tag));
    $tag = str_replace(['O', ' '], ['0', ''], $tag);
    return str_starts_with($tag, '#') ? $tag : '#' . $tag;
}

/**
 * Petición GET a la API.
 *
 * @param  string $path Ruta relativa, ya codificada (ej. "/clans/%232YQ0VLR8/members")
 * @return array<string,mixed>
 * @throws CocApiException
 */
function cocGet(string $path): array
{
    if (!cocConfigurado()) {
        throw new CocApiException(
            'Falta configurar COC_API_TOKEN y COC_CLAN_TAG en config/credentials.php.'
        );
    }

    $ch = curl_init(COC_API_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => COC_TIMEOUT,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . COC_API_TOKEN,
            'Accept: application/json',
        ],
    ]);

    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno  = curl_errno($ch);
    $error  = curl_error($ch);
    curl_close($ch);

    if ($errno !== 0) {
        throw new CocApiException('No se pudo contactar la API de Clash of Clans: ' . $error);
    }

    if ($status === 403) {
        throw new CocApiException(
            'La API rechazó el token (403). Casi siempre es que la IP autorizada no coincide: '
            . 'el token debe permitir la IP de salida de este servidor.'
        );
    }

    if ($status === 404) {
        throw new CocApiException('El clan no existe o el tag es incorrecto (404).');
    }

    if ($status === 429) {
        throw new CocApiException('Demasiadas peticiones a la API (429). Espera un momento.');
    }

    if ($status !== 200) {
        throw new CocApiException('La API respondió con código ' . $status . '.');
    }

    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        throw new CocApiException('La API devolvió una respuesta que no se pudo interpretar.');
    }

    return $data;
}

/**
 * Miembros actuales del clan.
 *
 * @return list<array{tag:string,name:string,role:string,expLevel:int,
 *                    trophies:int,donations:int,donationsReceived:int,townHallLevel:int}>
 * @throws CocApiException
 */
function cocMiembros(): array
{
    $data = cocGet('/clans/' . rawurlencode(cocNormalizarTag(COC_CLAN_TAG)) . '/members');

    $miembros = [];
    foreach ($data['items'] ?? [] as $m) {
        $miembros[] = [
            'tag'               => (string) ($m['tag'] ?? ''),
            'name'              => (string) ($m['name'] ?? ''),
            'role'              => (string) ($m['role'] ?? 'member'),
            'expLevel'          => (int)    ($m['expLevel'] ?? 0),
            'trophies'          => (int)    ($m['trophies'] ?? 0),
            'donations'         => (int)    ($m['donations'] ?? 0),
            'donationsReceived' => (int)    ($m['donationsReceived'] ?? 0),
            'townHallLevel'     => (int)    ($m['townHallLevel'] ?? 0),
        ];
    }

    return $miembros;
}

/**
 * Datos generales del clan (nombre, nivel, cantidad de miembros).
 *
 * @return array<string,mixed>
 * @throws CocApiException
 */
function cocClan(): array
{
    return cocGet('/clans/' . rawurlencode(cocNormalizarTag(COC_CLAN_TAG)));
}

/**
 * Traduce el rol de la API al ENUM de la tabla jugadores.
 */
function cocRolALocal(string $role): string
{
    return match ($role) {
        'leader'  => 'lider',
        'coLeader', 'coleader' => 'colider',
        'admin', 'elder'       => 'veterano',
        default   => 'miembro',
    };
}
