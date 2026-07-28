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
function avisarAdmins(string $texto, bool $html = true): int
{
    $destinos = getDB()->query('SELECT telegram_id FROM telegram_admins WHERE recibe_avisos = 1')
                       ->fetchAll(PDO::FETCH_COLUMN);
    $n = 0;
    foreach ($destinos as $chat) {
        if (tgEnviar($texto, (string) $chat, $html)) {
            $n++;
        }
    }
    return $n;
}

/**
 * Línea lista para pegar al chat del clan: menciona con @ a quienes no
 * hicieron ningún ataque en la guerra. Va sin formato y con los nombres
 * exactos del juego, porque es para copiar y pegar tal cual.
 *
 * El chat de Clash reconoce la mención solo si el nombre coincide
 * exacto; con nombres muy decorados puede no enlazar, pero el texto
 * igual sirve de llamado.
 */
function mencionNoAtacaron(int $guerraId): ?string
{
    $stmt = getDB()->prepare(
        'SELECT j.nombre_juego
           FROM guerra_participaciones gp
           JOIN jugadores j ON j.id = gp.jugador_id
          WHERE gp.guerra_id = ?
            AND gp.ataque1_estrellas IS NULL
            AND gp.ataque2_estrellas IS NULL
       ORDER BY j.nombre_juego'
    );
    $stmt->execute([$guerraId]);
    $nombres = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$nombres) {
        return null;
    }

    $arrobas = implode(' ', array_map(fn($n) => '@' . $n, $nombres));
    return 'No hicieron ningún ataque en la guerra: ' . $arrobas;
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
        'SELECT gp.jugador_id, j.nombre_juego,
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

    $rachas = rachasSinAtacar();

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
        // Se ordenan por racha: arriba los que están más cerca de expulsión.
        usort($sinAtacar, fn($a, $b) => ($rachas[(int) $b['jugador_id']] ?? 0) <=> ($rachas[(int) $a['jugador_id']] ?? 0));

        $l[] = '';
        $l[] = '<b>❌ No hicieron ningún ataque (' . count($sinAtacar) . ')</b>';
        $paraExpulsar = [];
        foreach ($sinAtacar as $f) {
            $r = $rachas[(int) $f['jugador_id']] ?? 1;
            $nota = $r === 1
                ? '1ª guerra'
                : $r . ' guerras seguidas';
            if ($r >= GUERRAS_SIN_ATACAR_EXPULSA) {
                $nota .= ' — 🚫 EXPULSAR (política de ' . GUERRAS_SIN_ATACAR_EXPULSA . ')';
                $paraExpulsar[] = $f['nombre_juego'];
            }
            $l[] = '· ' . tgEscapar((string) $f['nombre_juego']) . ' — ' . $nota;
        }

        if ($paraExpulsar) {
            $l[] = '';
            $l[] = '⚠️ <b>Por política, a expulsar:</b> ' . tgEscapar(implode(', ', $paraExpulsar));
        }

        $l[] = '';
        $l[] = '👇 <i>Abajo te dejo el texto listo para copiar al chat del clan.</i>';
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

    if (!empty($d['guerraApagada'])) {
        $l[] = '';
        $l[] = '<b>⚙️ Tienen la guerra apagada</b> (el juego no los deja incluir):';
        $l[] = implode(', ', array_map(
            fn($j) => tgEscapar((string) $j['nombre_juego']),
            array_slice($d['guerraApagada'], 0, 15)
        ));
        $l[] = '<i>Si quieres contar con ellos, pídeles que la activen en el juego.</i>';
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
