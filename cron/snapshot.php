<?php
declare(strict_types=1);

/**
 * Captura diaria del estado del clan.
 *
 * Se ejecuta desde el cron de Hostinger:
 *   /usr/bin/php /home/USUARIO/.../\_coc/cron/snapshot.php
 *
 * La API de Clash of Clans no guarda historial: las donaciones se
 * reinician cada temporada y el detalle por jugador de los asaltos de
 * capital solo existe para el fin de semana más reciente. Este script
 * levanta una lectura diaria para que el historial se construya solo.
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

echo "=== Captura diaria $inicio (UTC) ===\n";

// ── 1. Roster: altas, bajas y cambios de rol ──────────────────
paso('roster', function (): string {
    $r = cocAplicarSync(cocDiffRoster());
    return sprintf('%d actualizados, %d altas, %d bajas', $r['actualizados'], $r['altas'], $r['bajas']);
});

// ── 2. Estado del clan ────────────────────────────────────────
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

// ── 3. Estado de cada jugador activo ──────────────────────────
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
    foreach ($db->query('SELECT id, tag FROM jugadores WHERE activo = 1 AND tag IS NOT NULL') as $j) {
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

// ── 4. Asaltos de capital ─────────────────────────────────────
// El detalle por jugador solo viene del fin de semana más reciente,
// así que hay que pasar por aquí antes de que empiece el siguiente.
paso('capital', function () use ($db): string {
    $c = cocGet('/clans/' . rawurlencode(cocNormalizarTag(COC_CLAN_TAG)) . '/capitalraidseasons?limit=10');

    $porTag = [];
    foreach ($db->query('SELECT id, tag FROM jugadores WHERE tag IS NOT NULL') as $r) {
        $porTag[$r['tag']] = (int) $r['id'];
    }

    $busca  = $db->prepare('SELECT id FROM capital_semanas WHERE fecha_inicio = ?');
    $ins    = $db->prepare('INSERT INTO capital_semanas (fecha_inicio, fecha_fin, oro_total_recaudado, ataques_totales, distritos_destruidos, notas) VALUES (?,?,?,?,?,?)');
    $upd    = $db->prepare('UPDATE capital_semanas SET fecha_fin=?, oro_total_recaudado=?, ataques_totales=?, distritos_destruidos=? WHERE id=?');
    $bPart  = $db->prepare('SELECT id FROM capital_participaciones WHERE semana_id=? AND jugador_id=?');
    $iPart  = $db->prepare('INSERT INTO capital_participaciones (semana_id, jugador_id, oro_aportado, ataques_realizados, medallas_obtenidas) VALUES (?,?,?,?,0)');
    $uPart  = $db->prepare('UPDATE capital_participaciones SET oro_aportado=?, ataques_realizados=? WHERE id=?');

    $semanas = $detalle = 0;
    foreach ($c['items'] ?? [] as $s) {
        $iniF = date('Y-m-d', strtotime(substr((string) $s['startTime'], 0, 8)));
        $finF = date('Y-m-d', strtotime(substr((string) $s['endTime'], 0, 8)));
        $busca->execute([$iniF]);
        $sid = $busca->fetchColumn();

        if ($sid) {
            $upd->execute([$finF, $s['capitalTotalLoot'] ?? 0, $s['totalAttacks'] ?? 0, $s['enemyDistrictsDestroyed'] ?? 0, $sid]);
        } else {
            $ins->execute([$iniF, $finF, $s['capitalTotalLoot'] ?? 0, $s['totalAttacks'] ?? 0, $s['enemyDistrictsDestroyed'] ?? 0, 'Capturado por el cron.']);
            $sid = (int) $db->lastInsertId();
            $semanas++;
        }

        foreach ($s['members'] ?? [] as $m) {
            $jid = $porTag[$m['tag']] ?? null;
            if (!$jid) {
                continue;
            }
            $bPart->execute([$sid, $jid]);
            $pid = $bPart->fetchColumn();
            if ($pid) {
                $uPart->execute([$m['capitalResourcesLooted'] ?? 0, $m['attacks'] ?? 0, $pid]);
            } else {
                $iPart->execute([$sid, $jid, $m['capitalResourcesLooted'] ?? 0, $m['attacks'] ?? 0]);
            }
            $detalle++;
        }
    }
    return "$semanas semanas nuevas, $detalle participaciones";
});

// ── 5. CWL, solo si hay temporada ─────────────────────────────
paso('cwl', function () use ($db): string {
    $miTag = cocNormalizarTag(COC_CLAN_TAG);
    try {
        $g = cocGet('/clans/' . rawurlencode($miTag) . '/currentwar/leaguegroup');
    } catch (CocApiException $e) {
        return 'sin temporada activa';
    }

    $mes = substr((string) ($g['season'] ?? ''), 0, 7);
    if ($mes === '') {
        return 'sin temporada';
    }

    $agg = [];
    foreach ($g['rounds'] ?? [] as $r) {
        foreach ($r['warTags'] ?? [] as $wt) {
            if ($wt === '#0') {
                continue;
            }
            try {
                $w = cocGet('/clanwarleagues/wars/' . rawurlencode($wt));
            } catch (Throwable $e) {
                continue;
            }
            $lado = (($w['clan']['tag'] ?? '') === $miTag) ? $w['clan']
                  : ((($w['opponent']['tag'] ?? '') === $miTag) ? $w['opponent'] : null);
            if (!$lado) {
                continue;
            }
            foreach ($lado['members'] ?? [] as $m) {
                $agg[$m['tag']] ??= ['name' => $m['name'], 'est' => 0, 'pct' => 0.0, 'atq' => 0];
                foreach ($m['attacks'] ?? [] as $a) {
                    $agg[$m['tag']]['est'] += (int) $a['stars'];
                    $agg[$m['tag']]['pct'] += (float) $a['destructionPercentage'];
                    $agg[$m['tag']]['atq']++;
                }
            }
        }
    }
    if (!$agg) {
        return "temporada $mes sin datos aún";
    }

    $tid = $db->query("SELECT id FROM cwl_temporadas WHERE mes = " . $db->quote($mes))->fetchColumn();
    if (!$tid) {
        $db->prepare('INSERT INTO cwl_temporadas (mes, tamano) VALUES (?, ?)')->execute([$mes, count($agg)]);
        $tid = (int) $db->lastInsertId();
    }

    $porTag = $porNombre = [];
    foreach ($db->query('SELECT id, tag, usuario FROM jugadores') as $r) {
        if (!empty($r['tag'])) {
            $porTag[$r['tag']] = (int) $r['id'];
        }
        $porNombre[cocEsqueleto(ltrim((string) $r['usuario'], '#'))] = (int) $r['id'];
    }

    $ins = $db->prepare(
        'INSERT INTO cwl_participaciones (temporada_id, jugador_id, dia, participo, estrellas, porcentaje, ataques, bonus)
         VALUES (?,?,1,?,?,?,?,0)
         ON DUPLICATE KEY UPDATE participo=VALUES(participo), estrellas=VALUES(estrellas),
            porcentaje=VALUES(porcentaje), ataques=VALUES(ataques)'
    );
    $n = 0;
    foreach ($agg as $t => $a) {
        $jid = $porTag[$t] ?? $porNombre[cocEsqueleto($a['name'])] ?? null;
        if (!$jid) {
            continue;
        }
        $ins->execute([$tid, $jid, $a['atq'] > 0 ? 1 : 0, $a['est'], round($a['pct'], 2), $a['atq']]);
        $n++;
    }
    return "temporada $mes, $n jugadores";
});

// ── Bitácora ──────────────────────────────────────────────────
$estado = $errores ? (count($errores) >= 4 ? 'error' : 'parcial') : 'ok';
$db->prepare('INSERT INTO cron_ejecuciones (tarea, inicio, fin, estado, detalle) VALUES (?,?,NOW(),?,?)')
   ->execute(['snapshot', $inicio, $estado, implode(' | ', $errores ?: $resumen)]);

echo "=== Fin: $estado ===\n";
exit($estado === 'error' ? 1 : 0);
