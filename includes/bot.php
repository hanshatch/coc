<?php
declare(strict_types=1);

/**
 * El bot: comandos para los administradores del clan.
 *
 * Pregunta a Telegram en vez de exponer un endpoint público. El webhook
 * no funcionaba: el sitio está detrás de Cloudflare, que interfiere con
 * las peticiones de Telegram, y el bot quedaba mudo sin error visible.
 *
 * Preguntando desaparece el problema y también la superficie de ataque,
 * porque ya no hace falta una URL pública ni un secreto compartido. El
 * precio es que una respuesta puede tardar lo que falte para el próximo
 * paso del cron; con dos o tres administradores eso da igual.
 */

require_once __DIR__ . '/coc_sync.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/resumen.php';

/**
 * Recoge los mensajes nuevos y los atiende.
 *
 * El identificador del último visto se guarda en 'ajustes': Telegram
 * repite un mensaje hasta que se confirma, y sin esa marca el bot
 * respondería lo mismo una y otra vez.
 */
function tareaBot(): string
{
    if (!tgConfigurado()) {
        return 'sin configurar';
    }

    $desde = (int) (tgAjuste('telegram_offset') ?? 0);
    $r = tgLlamar('getUpdates', [
        'offset'          => $desde,
        'timeout'         => 0,
        'allowed_updates' => ['message'],
    ]);

    if (!$r || !($r['ok'] ?? false)) {
        return 'no se pudo consultar';
    }

    $mensajes = $r['result'] ?? [];
    if (!$mensajes) {
        return 'sin mensajes';
    }

    $n = 0;
    $ultimo = $desde;
    foreach ($mensajes as $u) {
        $ultimo = max($ultimo, (int) $u['update_id']);
        if (isset($u['message'])) {
            try {
                atender($u['message']);
                $n++;
            } catch (Throwable $e) {
                error_log('Bot: ' . $e->getMessage());
            }
        }
    }

    // Se confirma con +1 para que Telegram no los vuelva a mandar.
    tgGuardarAjuste('telegram_offset', (string) ($ultimo + 1));

    return "$n mensaje(s) atendido(s)";
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
        // Queda anotado: sin esto, cuando alguien pedía acceso no había
        // forma de saber quién fue para poder dárselo.
        $db->prepare(
            'INSERT INTO telegram_intentos (telegram_id, nombre, usuario) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE veces = veces + 1, nombre = VALUES(nombre), usuario = VALUES(usuario)'
        )->execute([$tgId, $nombre ?: null, $msg['from']['username'] ?? null]);

        // Se avisa al dueño para que pueda autorizarlo de inmediato.
        $duenio = $db->query('SELECT telegram_id FROM telegram_admins WHERE es_duenio = 1 LIMIT 1')->fetchColumn();
        if ($duenio) {
            tgEnviar(
                "🔔 <b>" . tgEscapar($nombre ?: 'Alguien') . "</b>"
                . (isset($msg['from']['username']) ? ' (@' . tgEscapar((string) $msg['from']['username']) . ')' : '')
                . " intentó usar el bot.\n\n"
                . "Para darle acceso:\n<code>/autorizar " . $tgId . "</code>",
                (string) $duenio
            );
        }

        // Se responde algo neutro: sin filtrar, cualquiera que dé con el
        // bot vería el estado del clan.
        tgEnviar('No tienes acceso a este bot. Ya avisé a los administradores.', (string) $tgId);
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

    // El nombre ya lo sabemos de cuando intentó entrar: sin esto la
    // lista de administradores queda con identificadores sueltos.
    $db = getDB();
    $stmt = $db->prepare('SELECT nombre FROM telegram_intentos WHERE telegram_id = ?');
    $stmt->execute([(int) $arg]);
    $nombre = $stmt->fetchColumn() ?: null;

    $db->prepare(
        'INSERT INTO telegram_admins (telegram_id, nombre, es_duenio) VALUES (?, ?, 0)
         ON DUPLICATE KEY UPDATE recibe_avisos = 1, nombre = COALESCE(VALUES(nombre), nombre)'
    )->execute([(int) $arg, $nombre]);

    tgEnviar('Te dieron acceso al asistente del clan H@TCH. Escribe /ayuda para ver qué puedo hacer.', $arg);
    return '✅ ' . tgEscapar($nombre ?: 'Autorizado') . ' ya tiene acceso. Le avisé por privado.';
}

function listaAdmins(): string
{
    $db = getDB();
    $l  = ['<b>Con acceso al bot</b>'];
    foreach ($db->query('SELECT * FROM telegram_admins ORDER BY es_duenio DESC, creado_en ASC') as $a) {
        $l[] = '· ' . tgEscapar((string) ($a['nombre'] ?: $a['telegram_id']))
             . ($a['es_duenio'] ? ' <i>(dueño)</i>' : '')
             . ($a['recibe_avisos'] ? '' : ' <i>(sin avisos)</i>');
    }

    // Quien pidió acceso y sigue sin tenerlo, con el comando listo para
    // copiar: es el caso en que uno abre esta lista.
    $pend = $db->query(
        'SELECT i.* FROM telegram_intentos i
          WHERE i.telegram_id NOT IN (SELECT telegram_id FROM telegram_admins)
       ORDER BY i.ultimo_intento DESC LIMIT 10'
    )->fetchAll();

    if ($pend) {
        $l[] = '';
        $l[] = '<b>Pidieron acceso</b>';
        foreach ($pend as $p) {
            $l[] = '· ' . tgEscapar((string) ($p['nombre'] ?: 'sin nombre'))
                 . ($p['usuario'] ? ' (@' . tgEscapar((string) $p['usuario']) . ')' : '')
                 . ' — ' . $p['veces'] . ' vez' . ((int) $p['veces'] === 1 ? '' : 'es');
            $l[] = '  <code>/autorizar ' . $p['telegram_id'] . '</code>';
        }
    }

    return implode("\n", $l);
}
