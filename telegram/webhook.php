<?php
declare(strict_types=1);

/**
 * Punto de entrada de Telegram: el bot como asistente de los
 * administradores del clan.
 *
 * Es público por necesidad, porque Telegram tiene que alcanzarlo sin
 * sesión. Se protege con un secreto compartido que Telegram manda en
 * una cabecera y que se fija al registrar el webhook.
 *
 * Además del secreto hay una lista blanca: el bot solo contesta a
 * administradores autorizados. Cualquiera puede encontrar un bot por su
 * nombre y escribirle, y las estadísticas del clan no tienen por qué
 * estar abiertas a quien pase por ahí.
 */

require_once __DIR__ . '/../includes/coc_sync.php';
require_once __DIR__ . '/../includes/telegram.php';
require_once __DIR__ . '/../includes/resumen.php';

$secreto = defined('TELEGRAM_WEBHOOK_SECRET') ? TELEGRAM_WEBHOOK_SECRET : '';
$enviado = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';

if ($secreto === '' || !hash_equals($secreto, $enviado)) {
    http_response_code(403);
    exit;
}

$update = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($update)) {
    http_response_code(400);
    exit;
}

// Telegram reintenta si no recibe 200 pronto, así que se responde antes
// de trabajar para no acabar procesando el mismo evento dos veces.
http_response_code(200);
header('Content-Length: 0');
header('Connection: close');
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

try {
    if (isset($update['message'])) {
        atender($update['message']);
    }
} catch (Throwable $e) {
    error_log('Webhook Telegram: ' . $e->getMessage());
}

/** @param array<string,mixed> $msg */
function atender(array $msg): void
{
    $tgId  = (int) ($msg['from']['id'] ?? 0);
    $texto = trim((string) ($msg['text'] ?? ''));
    if ($tgId === 0 || $texto === '') {
        return;
    }

    $nombre = trim((string) ($msg['from']['first_name'] ?? '') . ' ' . (string) ($msg['from']['last_name'] ?? ''));
    $db     = getDB();

    $stmt = $db->prepare('SELECT * FROM telegram_admins WHERE telegram_id = ?');
    $stmt->execute([$tgId]);
    $admin = $stmt->fetch();

    if (!$admin) {
        // Sin filtrar, cualquiera que dé con el bot vería el estado del
        // clan. Se responde algo neutro para no confirmar qué es esto.
        tgEnviar('No tienes acceso a este bot.', (string) $tgId);
        return;
    }

    [$comando, $arg] = array_pad(explode(' ', $texto, 2), 2, '');
    $comando = strtolower(ltrim($comando, '/'));

    switch ($comando) {
        case 'start':
        case 'ayuda':
        case 'help':
            tgEnviar(ayuda((bool) $admin['es_duenio']), (string) $tgId);
            break;

        case 'resumen':
            tgEnviar(resumenDiario()['texto'], (string) $tgId);
            break;

        case 'guerra':
            tgEnviar(estadoGuerra(), (string) $tgId);
            break;

        case 'jugador':
            tgEnviar(fichaJugador(trim($arg)), (string) $tgId);
            break;

        case 'autorizar':
            tgEnviar(autorizar($admin, trim($arg)), (string) $tgId);
            break;

        case 'admins':
            tgEnviar(listaAdmins(), (string) $tgId);
            break;

        default:
            tgEnviar("No conozco ese comando.\n\n" . ayuda((bool) $admin['es_duenio']), (string) $tgId);
    }
}

function ayuda(bool $duenio): string
{
    $t = "<b>Asistente del clan H@TCH</b>\n\n"
       . "/resumen — estado del clan y quién no participa\n"
       . "/guerra — ataques pendientes de la guerra en curso\n"
       . "/jugador <i>nombre</i> — ficha de un jugador\n"
       . "/admins — quién recibe avisos\n";
    if ($duenio) {
        $t .= "/autorizar <i>id</i> — dar acceso a otro administrador\n";
    }
    return $t;
}

function estadoGuerra(): string
{
    $db = getDB();
    $g  = $db->query(
        "SELECT * FROM guerras WHERE resultado = 'en_curso' ORDER BY fecha DESC LIMIT 1"
    )->fetch();

    if (!$g) {
        return 'No hay ninguna guerra en curso ahora mismo.';
    }

    $stmt = $db->prepare(
        'SELECT j.nombre_juego,
                (gp.ataque1_estrellas IS NOT NULL) + (gp.ataque2_estrellas IS NOT NULL) AS hechos,
                COALESCE(gp.ataque1_estrellas,0) + COALESCE(gp.ataque2_estrellas,0) AS estrellas
           FROM guerra_participaciones gp
           JOIN jugadores j ON j.id = gp.jugador_id
          WHERE gp.guerra_id = ?
       ORDER BY hechos ASC, j.nombre_juego ASC'
    );
    $stmt->execute([(int) $g['id']]);
    $filas = $stmt->fetchAll();

    if (!$filas) {
        return 'Hay guerra contra <b>' . tgEscapar((string) $g['oponente']) . '</b> pero todavía no tengo el detalle por jugador.';
    }

    $pendientes = array_filter($filas, fn($f) => (int) $f['hechos'] < 2);
    $l = [];
    $l[] = '<b>⚔️ Guerra contra ' . tgEscapar((string) $g['oponente']) . '</b>';
    $l[] = sprintf('%d–%d estrellas · %d en el mapa', (int) $g['estrellas_clan'], (int) $g['estrellas_oponente'], count($filas));
    $l[] = '';

    if (!$pendientes) {
        $l[] = '✅ Todos usaron sus dos ataques.';
        return implode("\n", $l);
    }

    $l[] = '<b>Faltan ataques de ' . count($pendientes) . ':</b>';
    foreach ($pendientes as $p) {
        $l[] = '· ' . tgEscapar((string) $p['nombre_juego']) . ' — le quedan ' . (2 - (int) $p['hechos']);
    }
    return implode("\n", $l);
}

function fichaJugador(string $busqueda): string
{
    if ($busqueda === '') {
        return 'Dime a quién busco. Ejemplo: <code>/jugador Lord H</code>';
    }

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT j.*, s.th_nivel, s.trofeos, s.donaciones, s.donaciones_recibidas,
                s.acum_guerra_estrellas, s.acum_cwl_estrellas, s.acum_capital_oro, s.acum_juegos_puntos
           FROM jugadores j
           LEFT JOIN snapshots_jugador s
                  ON s.jugador_id = j.id
                 AND s.fecha = (SELECT MAX(fecha) FROM snapshots_jugador)
          WHERE j.nombre_juego LIKE ? OR j.tag = ?
       ORDER BY j.activo DESC LIMIT 1'
    );
    $stmt->execute(['%' . $busqueda . '%', cocNormalizarTag($busqueda)]);
    $j = $stmt->fetch();

    if (!$j) {
        return 'No encuentro a nadie que se llame así.';
    }

    $l = [];
    $l[] = '<b>' . tgEscapar((string) $j['nombre_juego']) . '</b> <code>' . tgEscapar((string) $j['tag']) . '</code>';
    $l[] = ucfirst((string) $j['rol_clan']) . ($j['activo'] ? '' : ' · ⚠️ ya no está en el clan');
    $l[] = 'TH' . (int) $j['th_nivel'] . ' · ' . number_format((int) $j['trofeos']) . ' trofeos';
    $l[] = '';
    $l[] = 'Donaciones: ' . number_format((int) $j['donaciones']) . ' dadas, ' . number_format((int) $j['donaciones_recibidas']) . ' recibidas';
    $l[] = '';
    $l[] = '<b>De por vida</b>';
    $l[] = '⭐ ' . number_format((int) $j['acum_guerra_estrellas']) . ' estrellas de guerra';
    $l[] = '🏆 ' . number_format((int) $j['acum_cwl_estrellas']) . ' estrellas de liga';
    $l[] = '🪙 ' . number_format((int) $j['acum_capital_oro']) . ' oro de capital';
    $l[] = '🎮 ' . number_format((int) $j['acum_juegos_puntos']) . ' puntos de juegos';

    return implode("\n", $l);
}

/** @param array<string,mixed> $admin */
function autorizar(array $admin, string $arg): string
{
    if (!$admin['es_duenio']) {
        return 'Solo el dueño del bot puede autorizar a otros.';
    }
    if (!preg_match('/^\d{5,15}$/', $arg)) {
        return "Mándame el id numérico de Telegram de esa persona.\n\n"
             . "Puede obtenerlo escribiéndole a <code>@userinfobot</code>. "
             . "Antes de que le funcione, esa persona tiene que escribirme a mí primero.";
    }

    getDB()->prepare(
        'INSERT INTO telegram_admins (telegram_id, es_duenio) VALUES (?, 0)
         ON DUPLICATE KEY UPDATE recibe_avisos = 1'
    )->execute([(int) $arg]);

    tgEnviar('Te dieron acceso al asistente del clan H@TCH. Escribe /ayuda para ver qué puedo hacer.', $arg);
    return '✅ Autorizado. Le avisé por privado.';
}

function listaAdmins(): string
{
    $l = ['<b>Con acceso al bot</b>'];
    foreach (getDB()->query('SELECT * FROM telegram_admins ORDER BY es_duenio DESC, creado_en ASC') as $a) {
        $l[] = '· ' . tgEscapar((string) ($a['nombre'] ?: $a['telegram_id']))
             . ($a['es_duenio'] ? ' <i>(dueño)</i>' : '')
             . ($a['recibe_avisos'] ? '' : ' <i>(sin avisos)</i>');
    }
    return implode("\n", $l);
}
