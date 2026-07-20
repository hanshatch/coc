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

$inicio  = date('Y-m-d H:i:s');
$hoy     = date('Y-m-d');
$db      = getDB();
$errores = [];
$resumen = [];

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

paso('roster', function (): string {
    $r = cocAplicarSync(cocDiffRoster());
    return sprintf('%d actualizados, %d altas, %d bajas', $r['actualizados'], $r['altas'], $r['bajas']);
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

$estado = $errores ? (count($errores) >= 4 ? 'error' : 'parcial') : 'ok';
$db->prepare('INSERT INTO cron_ejecuciones (tarea, inicio, fin, estado, detalle) VALUES (?,?,NOW(),?,?)')
   ->execute(['snapshot', $inicio, $estado, implode(' | ', $errores ?: $resumen)]);

echo "=== Fin: $estado ===\n";
exit($estado === 'error' ? 1 : 0);
