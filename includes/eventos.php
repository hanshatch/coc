<?php
declare(strict_types=1);

/**
 * Avisos que dependen de que algo ocurra, no del reloj.
 *
 * Una guerra termina cuando termina, y el detalle por jugador solo vive
 * mientras la API lo expone. Por eso estos avisos los dispara un cron
 * frecuente que vigila cambios de estado, no la captura diaria.
 *
 * El estado anterior se guarda en 'ajustes' para poder distinguir un
 * cambio real de una lectura repetida: sin eso, cada corrida del cron
 * mandaría el mismo mensaje otra vez.
 */

require_once __DIR__ . '/coc_sync.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/decisiones.php';

/** Manda un aviso a todos los administradores que los tengan activados. */
function avisarAdmins(string $texto): int
{
    $destinos = getDB()->query('SELECT telegram_id FROM telegram_admins WHERE recibe_avisos = 1')
                       ->fetchAll(PDO::FETCH_COLUMN);
    $n = 0;
    foreach ($destinos as $chat) {
        if (tgEnviar($texto, (string) $chat)) {
            $n++;
        }
    }
    return $n;
}

/**
 * Da la bienvenida a quien acaba de entrar al clan.
 *
 * El bot no puede escribirle al jugador: no lo conoce en Telegram. El
 * aviso va a los administradores con la ficha del recién llegado, para
 * que sepan qué entró sin ir a buscarlo al juego.
 *
 * @param list<array{tag:string,name:string,role:string,townHallLevel:int}> $altas
 */
function avisoBienvenida(array $altas): ?string
{
    if (!$altas) {
        return null;
    }

    $l = [count($altas) === 1 ? '<b>👋 Jugador nuevo en el clan</b>' : '<b>👋 ' . count($altas) . ' jugadores nuevos</b>', ''];

    foreach ($altas as $m) {
        $l[] = '<b>' . tgEscapar((string) $m['name']) . '</b> <code>' . tgEscapar((string) $m['tag']) . '</code>';
        $l[] = 'TH' . (int) ($m['townHallLevel'] ?? 0) . ' · ' . ucfirst(cocRolALocal((string) ($m['role'] ?? 'member')));

        // Los acumulados dicen si llega alguien con recorrido o una
        // cuenta nueva, que es lo que decide si vale meterlo a guerra.
        try {
            $p = cocGet('/players/' . rawurlencode((string) $m['tag']));
            $logro = static function (array $p, string $n): int {
                foreach ($p['achievements'] ?? [] as $a) {
                    if (($a['name'] ?? '') === $n) { return (int) $a['value']; }
                }
                return 0;
            };
            $l[] = '⭐ ' . number_format((int) ($p['warStars'] ?? 0)) . ' estrellas de guerra · '
                 . '🪙 ' . number_format($logro($p, 'Aggressive Capitalism')) . ' oro de capital';
        } catch (Throwable $e) {
            // Sin la ficha el aviso sigue sirviendo, solo va más pelado.
        }
        $l[] = '';
    }

    return rtrim(implode("\n", $l));
}

/**
 * Felicita a los tres que más aportaron en la guerra que acaba de
 * terminar. Se ordena por estrellas y se desempata por destrucción.
 */
function avisoFinDeGuerra(int $guerraId): ?string
{
    $db = getDB();

    $stmt = $db->prepare('SELECT * FROM guerras WHERE id = ?');
    $stmt->execute([$guerraId]);
    $g = $stmt->fetch();
    if (!$g) {
        return null;
    }

    $stmt = $db->prepare(
        'SELECT j.nombre_juego,
                COALESCE(gp.ataque1_estrellas,0) + COALESCE(gp.ataque2_estrellas,0) AS estrellas,
                COALESCE(gp.ataque1_porcentaje,0) + COALESCE(gp.ataque2_porcentaje,0) AS destruccion,
                (gp.ataque1_estrellas IS NOT NULL) + (gp.ataque2_estrellas IS NOT NULL) AS ataques
           FROM guerra_participaciones gp
           JOIN jugadores j ON j.id = gp.jugador_id
          WHERE gp.guerra_id = ?
       ORDER BY estrellas DESC, destruccion DESC'
    );
    $stmt->execute([$guerraId]);
    $filas = $stmt->fetchAll();

    if (!$filas) {
        return null;
    }

    $resultado = match ($g['resultado']) {
        'victoria' => '🏆 <b>¡Ganamos!</b>',
        'derrota'  => '😤 <b>Perdimos esta</b>',
        'empate'   => '🤝 <b>Empate</b>',
        default    => '<b>Guerra terminada</b>',
    };

    $l   = [];
    $l[] = $resultado . ' contra ' . tgEscapar((string) $g['oponente']);
    $l[] = sprintf(
        '%d–%d estrellas · %.1f%% contra %.1f%%',
        (int) $g['estrellas_clan'], (int) $g['estrellas_oponente'],
        (float) $g['destruccion_clan'], (float) $g['destruccion_oponente']
    );
    $l[] = '';
    $l[] = '<b>Los tres mejores</b>';

    $medallas = ['🥇', '🥈', '🥉'];
    foreach (array_slice($filas, 0, 3) as $i => $f) {
        $l[] = $medallas[$i] . ' <b>' . tgEscapar((string) $f['nombre_juego']) . '</b> — '
             . (int) $f['estrellas'] . ' ⭐ con ' . (int) $f['ataques'] . ' ataque' . ((int) $f['ataques'] === 1 ? '' : 's')
             . ' (' . number_format((float) $f['destruccion'], 0) . '%)';
    }

    $sinAtacar = array_filter($filas, fn($f) => (int) $f['ataques'] === 0);
    if ($sinAtacar) {
        $l[] = '';
        $l[] = '<b>No atacaron:</b> ' . implode(', ', array_map(
            fn($f) => tgEscapar((string) $f['nombre_juego']),
            array_slice($sinAtacar, 0, 10)
        ));
    }

    return implode("\n", $l);
}

/**
 * Recuerda iniciar la siguiente guerra y propone a quién meter, según
 * la participación del último mes.
 */
function avisoIniciarGuerra(): string
{
    $d = decisionesClan(30);

    $l   = [];
    $l[] = '<b>⚔️ Ya se puede iniciar otra guerra</b>';
    $l[] = '';

    if (!$d['mejores']) {
        $l[] = 'Todavía no tengo datos de participación para recomendar a nadie.';
        return implode("\n", $l);
    }

    $l[] = '<b>A quién meter</b>, por participación del último mes:';
    foreach (array_slice($d['mejores'], 0, CUPO_GUERRA) as $i => $j) {
        $cal = $j['calidad'] !== null ? ' · ' . $j['calidad'] . ' ⭐/ataque' : '';
        $l[] = ($i + 1) . '. <b>' . tgEscapar((string) $j['nombre_juego']) . '</b> '
             . 'TH' . (int) $j['th_nivel'] . $cal;
    }

    if ($d['expulsar']) {
        $l[] = '';
        $l[] = '<b>No los metas:</b> ' . implode(', ', array_map(
            fn($j) => tgEscapar((string) $j['nombre_juego']),
            array_slice($d['expulsar'], 0, 10)
        ));
        $l[] = '<i>No participaron en nada el último mes.</i>';
    }

    return implode("\n", $l);
}

/**
 * Avisa de quienes llevan mucho tiempo sin dar señales de vida.
 *
 * Requiere historial de verdad: se compara el acumulado de por vida
 * entre dos lecturas separadas. Si el sistema lleva menos tiempo que el
 * periodo pedido, no afirma nada.
 */
function avisoInactivos(int $dias = 90): ?string
{
    $r = jugadoresInactivos($dias);

    if (!$r['suficiente'] || !$r['jugadores']) {
        return null;
    }

    $meses = (int) round($dias / 30);
    $l = [];
    $l[] = '<b>🚪 ' . count($r['jugadores']) . ' sin jugar en ' . $meses . ' meses</b>';
    $l[] = '<i>Ni una estrella, ni oro de capital, ni puntos de juegos, ni donaciones en ' . $r['diasCubiertos'] . ' días.</i>';
    $l[] = '';

    foreach ($r['jugadores'] as $j) {
        $l[] = '· <b>' . tgEscapar((string) $j['nombre_juego']) . '</b> '
             . 'TH' . (int) $j['th_nivel'] . ' · ' . ucfirst((string) $j['rol_clan']);
    }

    $l[] = '';
    $l[] = 'Se recomienda expulsarlos para liberar lugares.';

    return implode("\n", $l);
}
