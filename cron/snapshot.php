<?php
declare(strict_types=1);

/**
 * Captura diaria del estado del clan.
 *
 * Cron de Hostinger:
 *   30 5 * * *  /usr/bin/php /home/USUARIO/.../\_coc/cron/snapshot.php
 *
 * La API de Clash of Clans es una foto del presente, no un archivo: las
 * donaciones se reinician cada temporada, el detalle por jugador de los
 * asaltos de capital solo existe para el fin de semana más reciente, y
 * los Juegos del Clan solo se miden restando dos lecturas del acumulado
 * de por vida. Sin esta captura diaria, esos datos se pierden.
 *
 * Cada bloque va en su propio try/catch: que falle uno no debe impedir
 * que los demás guarden lo suyo.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/coc_sync.php';
require_once __DIR__ . '/../includes/resumen.php';

$inicio  = date('Y-m-d H:i:s');
$hoy     = date('Y-m-d');
$db      = getDB();
$errores = [];
$resumen = [];
$roster  = null;

function paso(string $nombre, callable $fn): void
{
    global $errores, $resumen;
    try {
        $r = $fn();
        $resumen[] = "$nombre: $r";
        echo "[OK]    $nombre — $r\n";
    } catch (Throwable $e) {
        $errores[] = "$nombre: " . $e->getMessage();
        echo "[ERROR] $nombre — " . $e->getMessage() . "\n";
    }
}

echo "=== Captura diaria $inicio ===\n";

paso('roster', function () use (&$roster): string {
    $roster = cocAplicarSync(cocDiffRoster());
    return sprintf('%d actualizados, %d altas, %d bajas', $roster['actualizados'], $roster['altas'], $roster['bajas']);
});

paso('clan', function () use ($db, $hoy): string {
    $c = cocClan();
    $db->prepare(
        'INSERT INTO snapshots_clan
            (fecha, miembros, nivel, puntos_clan, puntos_capital,
             guerras_ganadas, guerras_perdidas, guerras_empatadas, racha_victorias)
         VALUES (?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            miembros=VALUES(miembros), nivel=VALUES(nivel), puntos_clan=VALUES(puntos_clan),
            puntos_capital=VALUES(puntos_capital), guerras_ganadas=VALUES(guerras_ganadas),
            guerras_perdidas=VALUES(guerras_perdidas), guerras_empatadas=VALUES(guerras_empatadas),
            racha_victorias=VALUES(racha_victorias)'
    )->execute([
        $hoy,
        $c['members'] ?? 0,
        $c['clanLevel'] ?? null,
        $c['clanPoints'] ?? null,
        $c['clanCapitalPoints'] ?? null,
        $c['warWins'] ?? null,
        $c['warLosses'] ?? null,
        $c['warTies'] ?? null,
        $c['warWinStreak'] ?? null,
    ]);
    return sprintf('%d miembros, nivel %s', $c['members'] ?? 0, $c['clanLevel'] ?? '?');
});

paso('jugadores', function () use ($db, $hoy): string {
    $logro = static function (array $p, string $nombre): ?int {
        foreach ($p['achievements'] ?? [] as $a) {
            if (($a['name'] ?? '') === $nombre) {
                return (int) $a['value'];
            }
        }
        return null;
    };

    $stmt = $db->prepare(
        'INSERT INTO snapshots_jugador
            (jugador_id, fecha, donaciones, donaciones_recibidas, trofeos, th_nivel, exp_nivel, rol,
             acum_guerra_estrellas, acum_cwl_estrellas, acum_capital_oro, acum_juegos_puntos, acum_donaciones)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            donaciones=VALUES(donaciones), donaciones_recibidas=VALUES(donaciones_recibidas),
            trofeos=VALUES(trofeos), th_nivel=VALUES(th_nivel), exp_nivel=VALUES(exp_nivel), rol=VALUES(rol),
            acum_guerra_estrellas=VALUES(acum_guerra_estrellas), acum_cwl_estrellas=VALUES(acum_cwl_estrellas),
            acum_capital_oro=VALUES(acum_capital_oro), acum_juegos_puntos=VALUES(acum_juegos_puntos),
            acum_donaciones=VALUES(acum_donaciones)'
    );

    $ok = $fallos = 0;
    foreach ($db->query('SELECT id, tag FROM jugadores WHERE activo = 1') as $j) {
        try {
            $p = cocGet('/players/' . rawurlencode($j['tag']));
            $stmt->execute([
                (int) $j['id'], $hoy,
                $p['donations'] ?? null,
                $p['donationsReceived'] ?? null,
                $p['trophies'] ?? null,
                $p['townHallLevel'] ?? null,
                $p['expLevel'] ?? null,
                $p['role'] ?? null,
                $p['warStars'] ?? null,
                $logro($p, 'War League Legend'),
                $logro($p, 'Aggressive Capitalism'),
                $logro($p, 'Games Champion'),
                $logro($p, 'Friend in Need'),
            ]);
            $ok++;
        } catch (Throwable $e) {
            $fallos++;
        }
    }
    return "$ok capturados" . ($fallos ? ", $fallos fallaron" : '');
});

paso('guerras', function (): string {
    $r = cocImportarGuerras(50);
    return sprintf('%d nuevas de %d en el registro', $r['nuevas'], $r['total']);
});

// El detalle por jugador de una guerra normal solo existe mientras está
// viva. Si el cron no pasa a tiempo, ese dato no se recupera nunca.
paso('guerra en curso', function (): string {
    $r = cocImportarGuerraActual();
    return $r['jugadores'] === 0
        ? 'sin guerra activa (' . $r['estado'] . ')'
        : sprintf('%s, %d jugadores en el mapa', $r['estado'], $r['jugadores']);
});

paso('capital', function (): string {
    $r = cocImportarCapital(10);
    return sprintf('%d semanas nuevas, %d participaciones', $r['semanas'], $r['participaciones']);
});

paso('cwl', function (): string {
    try {
        $r = cocImportarCwl();
    } catch (CocApiException $e) {
        return 'sin temporada activa';
    }
    return $r['mes'] === '' ? 'sin temporada' : sprintf('temporada %s, %d jugadores', $r['mes'], $r['jugadores']);
});

// ── Limpieza del grupo ────────────────────────────────────────
// Sin esto el grupo se llena de exmiembros: nadie se acuerda de sacar a
// quien se fue del clan hace tres semanas.
paso('grupo telegram', function () use ($db): string {
    $grupo = tgConfigurado() ? tgGrupoId() : null;
    if (!$grupo) {
        return 'sin grupo configurado';
    }

    $fuera = $db->query(
        'SELECT v.telegram_id, v.telegram_nombre, j.nombre_juego
           FROM telegram_vinculos v
           JOIN jugadores j ON j.id = v.jugador_id
          WHERE j.activo = 0'
    )->fetchAll();

    if (!$fuera) {
        return 'nadie que sacar';
    }

    $n = 0;
    foreach ($fuera as $f) {
        $tgId = (int) $f['telegram_id'];
        tgEnviar(
            'Saliste del clan H@TCH, así que te quito del grupo. '
            . 'Si vuelves a entrar, solicita el ingreso de nuevo y te dejo pasar sin más trámite.',
            (string) $tgId
        );
        if (tgExpulsar($grupo, $tgId)) {
            $n++;
        }
        $db->prepare('DELETE FROM telegram_vinculos WHERE telegram_id = ?')->execute([$tgId]);
    }

    return "$n sacados del grupo por dejar el clan";
});

// ── Aviso por Telegram ────────────────────────────────────────
// Se manda después de capturar, para que el resumen refleje los datos
// de hoy y no los de ayer.
paso('telegram', function () use ($roster): string {
    if (!tgConfigurado() || !defined('TELEGRAM_CHAT_ID') || TELEGRAM_CHAT_ID === '') {
        return 'sin configurar';
    }
    $r = resumenDiario($roster);
    return tgEnviar($r['texto']) ? 'resumen enviado' : 'no se pudo enviar';
});

$estado = $errores ? (count($errores) >= 4 ? 'error' : 'parcial') : 'ok';
$db->prepare('INSERT INTO cron_ejecuciones (tarea, inicio, fin, estado, detalle) VALUES (?,?,NOW(),?,?)')
   ->execute(['snapshot', $inicio, $estado, implode(' | ', $errores ?: $resumen)]);

echo "=== Fin: $estado ===\n";
exit($estado === 'error' ? 1 : 0);
