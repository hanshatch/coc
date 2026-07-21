<?php
declare(strict_types=1);

/**
 * Arma el resumen diario que se manda por Telegram.
 *
 * Solo reporta lo que cambió o lo que exige una decisión. Un mensaje
 * que repite lo mismo todos los días se deja de leer a la semana.
 */

require_once __DIR__ . '/telegram.php';

/**
 * @param  array{actualizados:int,altas:int,bajas:int}|null $roster Cambios detectados hoy
 * @return array{texto:string,hayNovedades:bool}
 */
function resumenDiario(?array $roster = null): array
{
    $db      = getDB();
    $dias    = 30;
    $desde   = date('Y-m-d', strtotime("-$dias days"));
    $lineas  = [];
    $novedad = false;

    $clan = $db->query('SELECT * FROM snapshots_clan ORDER BY fecha DESC LIMIT 1')->fetch();
    $lineas[] = '<b>⚔️ Clan H@TCH</b> — ' . date('d/m/Y');
    if ($clan) {
        $lineas[] = sprintf(
            '%d/50 miembros · %s puntos · guerras %d-%d-%d',
            (int) $clan['miembros'],
            number_format((int) $clan['puntos_clan']),
            (int) $clan['guerras_ganadas'],
            (int) $clan['guerras_perdidas'],
            (int) $clan['guerras_empatadas']
        );
    }

    // ── Movimientos del roster ────────────────────────────────
    if ($roster && ($roster['altas'] > 0 || $roster['bajas'] > 0)) {
        $novedad  = true;
        $lineas[] = '';
        $lineas[] = '<b>Movimientos</b>';
        if ($roster['altas'] > 0) {
            $lineas[] = '➕ ' . $roster['altas'] . ' entró' . ($roster['altas'] === 1 ? '' : 'aron') . ' al clan';
        }
        if ($roster['bajas'] > 0) {
            $lineas[] = '➖ ' . $roster['bajas'] . ' salió' . ($roster['bajas'] === 1 ? '' : 'eron') . ' del clan';
        }
    }

    // ── Guerra en curso: quién falta por atacar ───────────────
    $guerra = $db->query(
        "SELECT id, oponente, fecha FROM guerras WHERE resultado = 'en_curso' ORDER BY fecha DESC LIMIT 1"
    )->fetch();

    if ($guerra) {
        $faltan = $db->prepare(
            'SELECT j.nombre_juego,
                    (gp.ataque1_estrellas IS NOT NULL) + (gp.ataque2_estrellas IS NOT NULL) AS hechos
               FROM guerra_participaciones gp
               JOIN jugadores j ON j.id = gp.jugador_id
              WHERE gp.guerra_id = ?
             HAVING hechos < 2
           ORDER BY hechos ASC, j.nombre_juego ASC'
        );
        $faltan->execute([(int) $guerra['id']]);
        $pendientes = $faltan->fetchAll();

        if ($pendientes) {
            $novedad  = true;
            $lineas[] = '';
            $lineas[] = '<b>🔥 Guerra contra ' . tgEscapar((string) $guerra['oponente']) . '</b>';
            $lineas[] = 'Faltan ataques de ' . count($pendientes) . ':';
            foreach (array_slice($pendientes, 0, 15) as $p) {
                $lineas[] = '· ' . tgEscapar((string) $p['nombre_juego']) . ' — ' . (2 - (int) $p['hechos']) . ' por usar';
            }
            if (count($pendientes) > 15) {
                $lineas[] = '· y ' . (count($pendientes) - 15) . ' más';
            }
        }
    }

    // ── Sin participar en el último mes ───────────────────────
    $capOp = (int) $db->query(
        "SELECT COUNT(DISTINCT cs.id) FROM capital_semanas cs
           JOIN capital_participaciones cp ON cp.semana_id = cs.id
          WHERE cs.fecha_inicio >= '$desde'"
    )->fetchColumn();

    if ($capOp > 0) {
        $sin = $db->query(
            "SELECT j.nombre_juego, COALESCE(s.acum_guerra_estrellas, 0) historia
               FROM jugadores j
               LEFT JOIN snapshots_jugador s
                      ON s.jugador_id = j.id
                     AND s.fecha = (SELECT MAX(fecha) FROM snapshots_jugador)
              WHERE j.activo = 1
                AND j.id NOT IN (
                    SELECT cp.jugador_id FROM capital_participaciones cp
                      JOIN capital_semanas cs ON cs.id = cp.semana_id
                     WHERE cs.fecha_inicio >= '$desde' AND cp.ataques_realizados > 0)
                AND j.id NOT IN (
                    SELECT gp.jugador_id FROM guerra_participaciones gp
                      JOIN guerras g ON g.id = gp.guerra_id
                     WHERE g.fecha >= '$desde'
                       AND (gp.ataque1_estrellas IS NOT NULL OR gp.ataque2_estrellas IS NOT NULL))
                AND j.id NOT IN (
                    SELECT p.jugador_id FROM cwl_participaciones p WHERE p.ataques > 0)
           ORDER BY historia ASC"
        )->fetchAll();

        if ($sin) {
            $lineas[] = '';
            $lineas[] = '<b>😴 Sin participar en ' . $dias . ' días: ' . count($sin) . '</b>';
            foreach (array_slice($sin, 0, 8) as $s) {
                $lineas[] = '· ' . tgEscapar((string) $s['nombre_juego'])
                          . ' <i>(' . number_format((int) $s['historia']) . ' ⭐ de por vida)</i>';
            }
            if (count($sin) > 8) {
                $lineas[] = '· y ' . (count($sin) - 8) . ' más en el tablero';
            }
        }
    }

    $lineas[] = '';
    $lineas[] = '<a href="https://hanshatch.com/_coc/">Ver el tablero completo</a>';

    return ['texto' => implode("\n", $lineas), 'hayNovedades' => $novedad];
}
