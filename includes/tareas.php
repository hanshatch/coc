<?php
declare(strict_types=1);

/**
 * Las tareas periódicas del sistema, cada una como una función.
 *
 * Viven aquí y no como scripts sueltos para que un único cron pueda
 * decidir cuál toca. Así el calendario está en el código, versionado y
 * a la vista, en vez de repartido en un panel donde nadie recuerda qué
 * se configuró ni cuándo.
 */

require_once __DIR__ . '/eventos.php';

/**
 * Vigila lo que no espera al reloj: jugadores nuevos y cambios de
 * estado de la guerra. Barata a propósito, un par de llamadas a la API.
 *
 * @return string Resumen de lo que hizo
 */
function tareaEventos(): string
{
    $db     = getDB();
    $hechos = [];

    try {
        $diff = cocDiffRoster();

        if ($diff['altas']) {
            $texto = avisoBienvenida($diff['altas']);
            if ($texto) {
                avisarAdmins($texto);
            }
            $hechos[] = count($diff['altas']) . ' nuevo(s), bienvenida enviada';
        }

        if ($diff['altas'] || $diff['bajas'] || $diff['cambiosRol'] || $diff['reactivar']) {
            $r = cocAplicarSync($diff);
            $hechos[] = sprintf('roster %d altas %d bajas', $r['altas'], $r['bajas']);
        }
    } catch (Throwable $e) {
        $hechos[] = 'error roster: ' . $e->getMessage();
    }

    try {
        $r        = cocImportarGuerraActual();
        $ahora    = $r['estado'];
        $anterior = tgAjuste('guerra_estado') ?? 'notInWar';

        if ($ahora !== $anterior) {
            $hechos[] = "guerra $anterior→$ahora";

            if ($ahora === 'warEnded') {
                $g = $db->query('SELECT id, api_id FROM guerras ORDER BY fecha DESC, id DESC LIMIT 1')->fetch();
                // El estado warEnded dura horas: sin esta marca se
                // remandaría el mismo aviso en cada corrida.
                if ($g && (tgAjuste('guerra_avisada') ?? '') !== (string) $g['api_id']) {
                    if ($texto = avisoFinDeGuerra((int) $g['id'])) {
                        avisarAdmins($texto);
                        $hechos[] = 'felicitación enviada';
                    }
                    avisarAdmins(avisoIniciarGuerra());
                    $hechos[] = 'recordatorio de nueva guerra';
                    tgGuardarAjuste('guerra_avisada', (string) $g['api_id']);
                }
            }

            tgGuardarAjuste('guerra_estado', $ahora);
        }
    } catch (Throwable $e) {
        $hechos[] = 'error guerra: ' . $e->getMessage();
    }

    return $hechos ? implode(' | ', $hechos) : 'sin novedades';
}

/**
 * Captura diaria completa: estado del clan, ficha de cada jugador,
 * historial de guerras, capital, liga y el resumen por Telegram.
 *
 * Es la cara: unas 40 llamadas a la API, por eso corre una vez al día.
 *
 * @return string Resumen de lo que hizo
 */
function tareaSnapshot(): string
{
    $db      = getDB();
    $hoy     = date('Y-m-d');
    $partes  = [];
    $roster  = null;

    $sub = static function (string $nombre, callable $fn) use (&$partes): void {
        try {
            $partes[] = "$nombre: " . $fn();
        } catch (Throwable $e) {
            $partes[] = "$nombre ERROR: " . $e->getMessage();
        }
    };

    $sub('roster', function () use (&$roster): string {
        $roster = cocAplicarSync(cocDiffRoster());
        return sprintf('%d act, %d altas, %d bajas', $roster['actualizados'], $roster['altas'], $roster['bajas']);
    });

    $sub('clan', function () use ($db, $hoy): string {
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
            $hoy, $c['members'] ?? 0, $c['clanLevel'] ?? null, $c['clanPoints'] ?? null,
            $c['clanCapitalPoints'] ?? null, $c['warWins'] ?? null, $c['warLosses'] ?? null,
            $c['warTies'] ?? null, $c['warWinStreak'] ?? null,
        ]);
        return sprintf('%d miembros', $c['members'] ?? 0);
    });

    $sub('jugadores', function () use ($db, $hoy): string {
        $logro = static function (array $p, string $n): ?int {
            foreach ($p['achievements'] ?? [] as $a) {
                if (($a['name'] ?? '') === $n) { return (int) $a['value']; }
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
                    $p['donations'] ?? null, $p['donationsReceived'] ?? null, $p['trophies'] ?? null,
                    $p['townHallLevel'] ?? null, $p['expLevel'] ?? null, $p['role'] ?? null,
                    $p['warStars'] ?? null,
                    $logro($p, 'War League Legend'), $logro($p, 'Aggressive Capitalism'),
                    $logro($p, 'Games Champion'), $logro($p, 'Friend in Need'),
                ]);
                $ok++;
            } catch (Throwable $e) {
                $fallos++;
            }
        }
        return "$ok capturados" . ($fallos ? ", $fallos fallaron" : '');
    });

    $sub('guerras',  fn(): string => sprintf('%d nuevas', cocImportarGuerras(50)['nuevas']));
    $sub('capital',  fn(): string => sprintf('%d semanas', cocImportarCapital(10)['semanas']));

    $sub('liga', function (): string {
        try {
            $r = cocImportarCwl();
        } catch (CocApiException $e) {
            return 'sin temporada';
        }
        return $r['mes'] === '' ? 'sin temporada' : sprintf('%s, %d jugadores', $r['mes'], $r['jugadores']);
    });

    $sub('inactivos', function (): string {
        $texto = avisoInactivos(90);
        if ($texto === null) {
            $r = jugadoresInactivos(90);
            return $r['suficiente'] ? 'ninguno' : 'faltan datos (' . $r['diasCubiertos'] . ' días)';
        }
        return avisarAdmins($texto) . ' avisados';
    });

    $sub('resumen', function () use ($roster): string {
        if (!tgConfigurado()) {
            return 'sin configurar';
        }
        $n = avisarAdmins(resumenDiario($roster)['texto']);
        return "$n avisados";
    });

    return implode(' | ', $partes);
}
