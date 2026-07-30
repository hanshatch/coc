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

// El chat de Clash corta los mensajes y limita cuántas menciones enlaza.
const CHAT_MAX_CHARS     = 160;
const CHAT_MAX_MENCIONES = 5;

/**
 * Mensajes listos para pegar al chat del clan, uno por cada bloque.
 *
 * Se agrupan por número de aviso, que es la racha de guerras seguidas
 * sin atacar: 1 = primer aviso, 2 = segundo, 3 o más = aviso final con
 * expulsión. Cada bloque respeta los topes del chat de Clash (160
 * caracteres y 5 menciones), troceando si hace falta, para que el
 * administrador copie y pegue sin tener que recortar nada.
 *
 * Van en texto plano y con los nombres exactos: el chat no entiende
 * formato y la mención solo enlaza si el nombre coincide.
 *
 * @return list<string>
 */
function mensajesNoAtacaron(int $guerraId): array
{
    $stmt = getDB()->prepare(
        'SELECT gp.jugador_id, j.nombre_juego
           FROM guerra_participaciones gp
           JOIN jugadores j ON j.id = gp.jugador_id
          WHERE gp.guerra_id = ?
            AND gp.ataque1_estrellas IS NULL
            AND gp.ataque2_estrellas IS NULL
       ORDER BY j.nombre_juego'
    );
    $stmt->execute([$guerraId]);
    $filas = $stmt->fetchAll();

    if (!$filas) {
        return [];
    }

    $rachas = rachasSinAtacar();

    // Agrupar por nivel de aviso; de 3 en adelante es el aviso final.
    $grupos = [1 => [], 2 => [], 3 => []];
    foreach ($filas as $f) {
        $nivel = min(3, max(1, $rachas[(int) $f['jugador_id']] ?? 1));
        $grupos[$nivel][] = (string) $f['nombre_juego'];
    }

    $plantillas = [
        1 => ['1er aviso por no atacar en guerra:', 'Al 3er aviso no se les agrega a guerras.'],
        2 => ['2do aviso por no atacar en guerra:', 'Al 3er aviso no se les agrega a guerras.'],
        3 => ['AVISO FINAL: 3 guerras sin atacar.', 'No se les agregará a próximas guerras.'],
    ];

    $mensajes = [];
    foreach ([1, 2, 3] as $nivel) {
        if (!$grupos[$nivel]) {
            continue;
        }
        [$header, $nota] = $plantillas[$nivel];
        // Todo en una sola línea: el chat del juego toma el salto de línea
        // como "enviar" y cortaría el mensaje al pegarlo. El margen es lo
        // que ocupa el encabezado, la nota y los separadores.
        $margen = mb_strlen($header) + mb_strlen($nota) + 3;
        foreach (empaquetarMenciones($grupos[$nivel], $margen) as $lote) {
            $arrobas = implode(' ', array_map(fn($n) => '@' . $n, $lote));
            $mensajes[] = $header . ' ' . $arrobas . '. ' . $nota;
        }
    }

    // Cierre en buen tono, también en una sola línea: quien no quiera o no
    // pueda jugar puede desactivar la guerra en vez de arriesgar expulsión.
    $mensajes[] = 'Si no vas a poder participar, cambia tu estatus a '
                . '"No guerra" en el juego para que no se te agregue. '
                . 'Así evitamos avisos.';

    return $mensajes;
}

/**
 * Convierte la hora de la API (20260730T143000.000Z, siempre UTC) a
 * timestamp. Se leen posiciones fijas, así que da igual si trae o no
 * milisegundos.
 */
function cocHoraATimestamp(string $s): int
{
    if (strlen($s) < 15) {
        return 0;
    }
    return gmmktime(
        (int) substr($s, 9, 2), (int) substr($s, 11, 2), (int) substr($s, 13, 2),
        (int) substr($s, 4, 2), (int) substr($s, 6, 2), (int) substr($s, 0, 4)
    );
}

/**
 * Recordatorio a falta de pocas horas: mensajes listos para pegar al
 * chat del clan con quienes aún no atacaron y quienes tienen su segundo
 * ataque pendiente. Cada bloque respeta los topes del chat de Clash
 * (una línea, 160 caracteres, 5 menciones).
 *
 * @return list<string>
 */
function mensajesRecordatorioGuerra(int $guerraId): array
{
    $stmt = getDB()->prepare(
        'SELECT j.nombre_juego,
                (gp.ataque1_estrellas IS NOT NULL) + (gp.ataque2_estrellas IS NOT NULL) AS hechos
           FROM guerra_participaciones gp
           JOIN jugadores j ON j.id = gp.jugador_id
          WHERE gp.guerra_id = ?
       ORDER BY j.nombre_juego'
    );
    $stmt->execute([$guerraId]);
    $filas = $stmt->fetchAll();

    $sinAtacar = [];
    $unaMenos  = [];
    foreach ($filas as $f) {
        $h = (int) $f['hechos'];
        if ($h === 0) {
            $sinAtacar[] = (string) $f['nombre_juego'];
        } elseif ($h === 1) {
            $unaMenos[] = (string) $f['nombre_juego'];
        }
    }

    $mensajes = [];

    foreach ([
        [$sinAtacar, '⏳ Faltan horas y no han atacado:', 'Ataquen sus 2 ya.'],
        [$unaMenos,  '⏳ Les queda 1 ataque pendiente:', 'Aprovechen para limpiar.'],
    ] as [$nombres, $header, $nota]) {
        if (!$nombres) {
            continue;
        }
        $margen = mb_strlen($header) + mb_strlen($nota) + 3;
        foreach (empaquetarMenciones($nombres, $margen) as $lote) {
            $arrobas = implode(' ', array_map(fn($n) => '@' . $n, $lote));
            $mensajes[] = $header . ' ' . $arrobas . '. ' . $nota;
        }
    }

    return $mensajes;
}

/**
 * Reparte nombres en lotes que quepan en un mensaje del chat de Clash:
 * a lo sumo 5 menciones y 160 caracteres contando lo que ya ocupan el
 * encabezado y la nota, que se pasa en $margen.
 *
 * @param  list<string> $nombres
 * @return list<list<string>>
 */
function empaquetarMenciones(array $nombres, int $margen): array
{
    $lotes = [];
    $lote  = [];
    foreach ($nombres as $n) {
        $prueba = array_merge($lote, [$n]);
        $largo  = $margen + mb_strlen(implode(' ', array_map(fn($x) => '@' . $x, $prueba)));

        if ($lote && (count($prueba) > CHAT_MAX_MENCIONES || $largo > CHAT_MAX_CHARS)) {
            $lotes[] = $lote;
            $lote    = [$n];
        } else {
            $lote = $prueba;
        }
    }
    if ($lote) {
        $lotes[] = $lote;
    }

    return $lotes;
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
        $fueraDeGuerra = [];
        foreach ($sinAtacar as $f) {
            $r = $rachas[(int) $f['jugador_id']] ?? 1;
            $nota = $r === 1
                ? '1ª guerra'
                : $r . ' guerras seguidas';
            if ($r >= GUERRAS_SIN_ATACAR_LIMITE) {
                $nota .= ' — 🚫 fuera de próximas guerras (política de ' . GUERRAS_SIN_ATACAR_LIMITE . ')';
                $fueraDeGuerra[] = $f['nombre_juego'];
            }
            $l[] = '· ' . tgEscapar((string) $f['nombre_juego']) . ' — ' . $nota;
        }

        if ($fueraDeGuerra) {
            $l[] = '';
            $l[] = '⚠️ <b>Por política, ya no se agregan a guerras:</b> ' . tgEscapar(implode(', ', $fueraDeGuerra));
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
