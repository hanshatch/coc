<?php
declare(strict_types=1);

/**
 * Punto de entrada de Telegram. Aquí llegan las solicitudes de ingreso
 * al grupo y los mensajes privados al bot.
 *
 * Es público por necesidad: Telegram tiene que poder alcanzarlo sin
 * sesión. Se protege con un secreto compartido que Telegram manda en
 * una cabecera y que se fija al registrar el webhook, así nadie más
 * puede inyectar eventos falsos.
 *
 * Todo el flujo de alta ocurre aquí sin intervención humana:
 * pide el tag, comprueba que esté en el clan, pide el token que genera
 * el juego, lo valida contra la API y aprueba o rechaza.
 */

require_once __DIR__ . '/../includes/coc_sync.php';
require_once __DIR__ . '/../includes/telegram.php';

// ── Autenticación del webhook ─────────────────────────────────
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

// Telegram reintenta si no recibe 200 pronto. Se responde de inmediato
// y se procesa después, para que un fallo nuestro no genere duplicados.
http_response_code(200);
header('Content-Length: 0');
header('Connection: close');
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

try {
    procesar($update);
} catch (Throwable $e) {
    error_log('Webhook Telegram: ' . $e->getMessage());
}

/** @param array<string,mixed> $u */
function procesar(array $u): void
{
    if (isset($u['my_chat_member'])) {
        aprenderGrupo($u['my_chat_member']);
        return;
    }
    if (isset($u['chat_join_request'])) {
        solicitudDeIngreso($u['chat_join_request']);
        return;
    }
    if (isset($u['message'])) {
        mensajePrivado($u['message']);
    }
}

/**
 * Cuando agregan el bot a un grupo, guarda el id para no tener que
 * configurarlo a mano.
 *
 * @param array<string,mixed> $ev
 */
function aprenderGrupo(array $ev): void
{
    $chat   = $ev['chat'] ?? [];
    $estado = $ev['new_chat_member']['status'] ?? '';

    if (!in_array($chat['type'] ?? '', ['group', 'supergroup'], true)) {
        return;
    }

    if (in_array($estado, ['administrator', 'member'], true)) {
        tgGuardarAjuste('telegram_grupo_id', (string) $chat['id']);
        $aviso = $estado === 'administrator'
            ? "✅ Listo. Quedé como administrador de <b>" . tgEscapar((string) ($chat['title'] ?? 'el grupo')) . "</b> y ya puedo aprobar solicitudes."
            : "⚠️ Me agregaron a <b>" . tgEscapar((string) ($chat['title'] ?? 'el grupo')) . "</b> pero <b>no soy administrador</b>. Sin ese permiso no puedo aprobar ni rechazar a nadie.";
        tgEnviar($aviso);
    }
}

/**
 * Alguien pidió entrar al grupo. Se le escribe por privado para iniciar
 * la verificación; la solicitud queda pendiente mientras tanto.
 *
 * @param array<string,mixed> $sol
 */
function solicitudDeIngreso(array $sol): void
{
    $tgId  = (int) ($sol['from']['id'] ?? 0);
    $chat  = (string) ($sol['chat']['id'] ?? '');
    $nombre = trim((string) ($sol['from']['first_name'] ?? '') . ' ' . (string) ($sol['from']['last_name'] ?? ''));

    if ($tgId === 0 || $chat === '') {
        return;
    }
    tgGuardarAjuste('telegram_grupo_id', $chat);

    // Si ya estaba verificado y sigue en el clan, entra directo.
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT j.id FROM telegram_vinculos v JOIN jugadores j ON j.id = v.jugador_id
          WHERE v.telegram_id = ? AND j.activo = 1'
    );
    $stmt->execute([$tgId]);
    if ($stmt->fetchColumn()) {
        tgAprobarSolicitud($chat, $tgId);
        tgEnviar('✅ Ya estabas verificado, te dejé entrar.', (string) $tgId);
        return;
    }

    $db->prepare(
        'INSERT INTO telegram_conversaciones (telegram_id, paso, intentos) VALUES (?, \'espera_tag\', 0)
         ON DUPLICATE KEY UPDATE paso = \'espera_tag\', tag_propuesto = NULL, intentos = 0'
    )->execute([$tgId]);

    tgEnviar(
        "👋 Hola" . ($nombre ? ' ' . tgEscapar($nombre) : '') . ", soy el bot del clan <b>H@TCH</b>.\n\n"
        . "Para dejarte entrar al grupo necesito comprobar que eres miembro del clan.\n\n"
        . "<b>Mándame tu tag de jugador.</b> Lo encuentras en tu perfil dentro del juego, debajo de tu nombre. "
        . "Se ve así: <code>#2ABC3DEF</code>",
        (string) $tgId
    );
}

/**
 * Conversación privada: recibe el tag, luego el token, y decide.
 *
 * @param array<string,mixed> $msg
 */
function mensajePrivado(array $msg): void
{
    if (($msg['chat']['type'] ?? '') !== 'private') {
        return;
    }

    $tgId  = (int) ($msg['from']['id'] ?? 0);
    $texto = trim((string) ($msg['text'] ?? ''));
    if ($tgId === 0 || $texto === '') {
        return;
    }

    $nombre = trim((string) ($msg['from']['first_name'] ?? '') . ' ' . (string) ($msg['from']['last_name'] ?? ''));
    $db     = getDB();

    if ($texto === '/start' || $texto === '/ayuda') {
        tgEnviar(
            "Soy el bot del clan <b>H@TCH</b>.\n\n"
            . "Si quieres entrar al grupo, primero solicita el ingreso y yo te escribo para verificarte.\n\n"
            . "Comandos: /yo para ver con qué cuenta estás vinculado.",
            (string) $tgId
        );
        return;
    }

    if ($texto === '/yo') {
        $stmt = $db->prepare(
            'SELECT j.nombre_juego, j.tag, j.activo FROM telegram_vinculos v
               JOIN jugadores j ON j.id = v.jugador_id WHERE v.telegram_id = ?'
        );
        $stmt->execute([$tgId]);
        $v = $stmt->fetch();
        tgEnviar(
            $v
                ? "Estás vinculado a <b>" . tgEscapar((string) $v['nombre_juego']) . "</b> (<code>" . tgEscapar((string) $v['tag']) . "</code>)"
                  . ($v['activo'] ? "\nSigues en el clan ✅" : "\n⚠️ Ya no apareces en el clan.")
                : 'Todavía no estás vinculado a ninguna cuenta.',
            (string) $tgId
        );
        return;
    }

    $stmt = $db->prepare('SELECT * FROM telegram_conversaciones WHERE telegram_id = ?');
    $stmt->execute([$tgId]);
    $conv = $stmt->fetch();

    if (!$conv) {
        tgEnviar('Si quieres entrar al grupo, solicita el ingreso y te escribo para verificarte.', (string) $tgId);
        return;
    }

    if ($conv['paso'] === 'espera_tag') {
        recibirTag($tgId, $texto, $nombre);
        return;
    }
    recibirToken($tgId, $texto, (string) $conv['tag_propuesto'], $nombre);
}

function recibirTag(int $tgId, string $texto, string $nombre): void
{
    $db  = getDB();
    $tag = cocNormalizarTag($texto);

    $stmt = $db->prepare('SELECT id, nombre_juego FROM jugadores WHERE tag = ? AND activo = 1');
    $stmt->execute([$tag]);
    $jugador = $stmt->fetch();

    if (!$jugador) {
        $db->prepare('UPDATE telegram_conversaciones SET intentos = intentos + 1 WHERE telegram_id = ?')->execute([$tgId]);
        tgEnviar(
            "❌ No encuentro <code>" . tgEscapar($tag) . "</code> entre los miembros del clan.\n\n"
            . "Revisa que lo hayas copiado bien. Recuerda que el tag lleva ceros, nunca la letra O.\n"
            . "Si acabas de entrar al clan, espera a mañana: la lista se actualiza cada noche.",
            (string) $tgId
        );
        return;
    }

    // Si ese jugador ya está tomado por otra cuenta de Telegram, se corta.
    $ocupado = $db->prepare('SELECT telegram_id FROM telegram_vinculos WHERE jugador_id = ?');
    $ocupado->execute([(int) $jugador['id']]);
    $duenio = $ocupado->fetchColumn();
    if ($duenio && (int) $duenio !== $tgId) {
        tgEnviar('❌ Esa cuenta ya está vinculada a otro Telegram. Si es un error, avísale a un colíder.', (string) $tgId);
        return;
    }

    $db->prepare('UPDATE telegram_conversaciones SET paso = \'espera_token\', tag_propuesto = ? WHERE telegram_id = ?')
       ->execute([$tag, $tgId]);

    tgEnviar(
        "✅ Encontré a <b>" . tgEscapar((string) $jugador['nombre_juego']) . "</b> en el clan.\n\n"
        . "Ahora comprueba que la cuenta es tuya. Dentro de Clash of Clans:\n\n"
        . "1️⃣ Abre <b>Ajustes</b> (el engrane)\n"
        . "2️⃣ Entra a <b>Más ajustes</b>\n"
        . "3️⃣ Hasta abajo busca <b>API Token</b> y tócalo\n"
        . "4️⃣ Cópialo y pégamelo aquí\n\n"
        . "<i>Es un código temporal que genera el propio juego. No es una contraseña y no sirve para nada más.</i>",
        (string) $tgId
    );
}

function recibirToken(int $tgId, string $token, string $tag, string $nombre): void
{
    $db = getDB();

    $ok = false;
    try {
        $r  = cocVerificarToken($tag, $token);
        $ok = $r === 'ok';
    } catch (Throwable $e) {
        error_log('Verificar token: ' . $e->getMessage());
        tgEnviar('⚠️ No pude comprobarlo ahora mismo. Inténtalo en un minuto.', (string) $tgId);
        return;
    }

    if (!$ok) {
        $db->prepare('UPDATE telegram_conversaciones SET intentos = intentos + 1 WHERE telegram_id = ?')->execute([$tgId]);
        tgEnviar(
            "❌ Ese token no es válido para <code>" . tgEscapar($tag) . "</code>.\n\n"
            . "Los tokens caducan a los pocos minutos: genera uno nuevo en el juego y mándamelo enseguida.",
            (string) $tgId
        );
        return;
    }

    $stmt = $db->prepare('SELECT id, nombre_juego FROM jugadores WHERE tag = ?');
    $stmt->execute([$tag]);
    $jugador = $stmt->fetch();
    if (!$jugador) {
        return;
    }

    $db->prepare(
        'INSERT INTO telegram_vinculos (telegram_id, jugador_id, telegram_nombre) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE jugador_id = VALUES(jugador_id), telegram_nombre = VALUES(telegram_nombre), verificado_en = NOW()'
    )->execute([$tgId, (int) $jugador['id'], $nombre ?: null]);

    $db->prepare('DELETE FROM telegram_conversaciones WHERE telegram_id = ?')->execute([$tgId]);

    $grupo = tgGrupoId();
    if ($grupo) {
        tgAprobarSolicitud($grupo, $tgId);
    }

    tgEnviar(
        "🎉 Verificado. Bienvenido, <b>" . tgEscapar((string) $jugador['nombre_juego']) . "</b>.\n\n"
        . "Ya puedes entrar al grupo. No vuelvo a pedirte el token: quedaste vinculado.",
        (string) $tgId
    );
}
