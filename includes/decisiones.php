<?php
declare(strict_types=1);

/**
 * Clasificación de jugadores por participación.
 *
 * Vive aquí y no dentro de una pantalla porque el tablero, el resumen
 * diario y los avisos de guerra necesitan el mismo cálculo. Duplicarlo
 * garantizaría que tarde o temprano digan cosas distintas.
 *
 * Cada actividad cuenta como una "arena" y solo pesa si el jugador tuvo
 * oportunidad real de jugarla: quedar fuera del roster de liga o no ser
 * convocado a una guerra son decisiones de la dirigencia.
 */

require_once __DIR__ . '/../config/database.php';

const CUPO_GUERRA        = 25;
const VETERANO_ESTRELLAS = 500;

/**
 * @return array{
 *   jugadores:list<array>, expulsar:list<array>, mejores:list<array>, parciales:list<array>,
 *   capOportunidades:int, guerrasConDetalle:int, guerrasEnVentana:int,
 *   hayLiga:bool, hayJuegos:bool, lecturas:int, dias:int
 * }
 */
function decisionesClan(int $dias = 30): array
{
    $db    = getDB();
    $desde = date('Y-m-d', strtotime("-$dias days"));

    $lectura = $db->query('SELECT MAX(fecha) FROM snapshots_jugador')->fetchColumn();

    // ── Capital ───────────────────────────────────────────────
    $capOportunidades = (int) $db->query(
        "SELECT COUNT(DISTINCT cs.id) FROM capital_semanas cs
           JOIN capital_participaciones cp ON cp.semana_id = cs.id
          WHERE cs.fecha_inicio >= '$desde'"
    )->fetchColumn();

    $capital = [];
    foreach ($db->query(
        "SELECT cp.jugador_id, SUM(cp.ataques_realizados > 0) participo
           FROM capital_participaciones cp
           JOIN capital_semanas cs ON cs.id = cp.semana_id
          WHERE cs.fecha_inicio >= '$desde'
       GROUP BY cp.jugador_id"
    ) as $r) {
        $capital[(int) $r['jugador_id']] = (int) $r['participo'];
    }

    // ── Guerras ───────────────────────────────────────────────
    $guerra = [];
    foreach ($db->query(
        "SELECT gp.jugador_id, COUNT(*) convocado,
                SUM(gp.ataque1_estrellas IS NOT NULL OR gp.ataque2_estrellas IS NOT NULL) participo,
                SUM(COALESCE(gp.ataque1_estrellas,0) + COALESCE(gp.ataque2_estrellas,0)) estrellas,
                SUM((gp.ataque1_estrellas IS NOT NULL) + (gp.ataque2_estrellas IS NOT NULL)) ataques
           FROM guerra_participaciones gp
           JOIN guerras g ON g.id = gp.guerra_id
          WHERE g.fecha >= '$desde'
       GROUP BY gp.jugador_id"
    ) as $r) {
        $guerra[(int) $r['jugador_id']] = [
            'convocado' => (int) $r['convocado'],
            'participo' => (int) $r['participo'],
            'estrellas' => (int) $r['estrellas'],
            'ataques'   => (int) $r['ataques'],
        ];
    }
    $guerrasEnVentana  = (int) $db->query("SELECT COUNT(*) FROM guerras WHERE fecha >= '$desde'")->fetchColumn();
    $guerrasConDetalle = (int) $db->query(
        "SELECT COUNT(DISTINCT g.id) FROM guerras g
           JOIN guerra_participaciones gp ON gp.guerra_id = g.id
          WHERE g.fecha >= '$desde'"
    )->fetchColumn();

    // ── Liga de guerras ───────────────────────────────────────
    $liga = [];
    foreach ($db->query(
        "SELECT p.jugador_id, p.ataques, p.estrellas
           FROM cwl_participaciones p
           JOIN cwl_temporadas t ON t.id = p.temporada_id
          WHERE CONCAT(t.mes, '-01') >= DATE_SUB('$desde', INTERVAL 1 MONTH)"
    ) as $r) {
        $liga[(int) $r['jugador_id']] = ['ataques' => (int) $r['ataques'], 'estrellas' => (int) $r['estrellas']];
    }

    // ── Juegos del Clan ───────────────────────────────────────
    $juegos = [];
    $fechas = $db->query("SELECT DISTINCT fecha FROM snapshots_jugador WHERE fecha >= '$desde' ORDER BY fecha")
                 ->fetchAll(PDO::FETCH_COLUMN);
    $hayJuegos = count($fechas) >= 2;
    if ($hayJuegos) {
        $stmt = $db->prepare(
            'SELECT ini.jugador_id, (fin.acum_juegos_puntos - ini.acum_juegos_puntos) puntos
               FROM snapshots_jugador ini
               JOIN snapshots_jugador fin ON fin.jugador_id = ini.jugador_id AND fin.fecha = ?
              WHERE ini.fecha = ? AND ini.acum_juegos_puntos IS NOT NULL AND fin.acum_juegos_puntos IS NOT NULL'
        );
        $stmt->execute([end($fechas), $fechas[0]]);
        foreach ($stmt as $r) {
            $juegos[(int) $r['jugador_id']] = (int) $r['puntos'];
        }
    }

    // ── Jugadores ─────────────────────────────────────────────
    $stmt = $db->prepare(
        'SELECT j.id, j.tag, j.nombre_juego, j.rol_clan,
                s.th_nivel, s.donaciones, s.acum_guerra_estrellas
           FROM jugadores j
           LEFT JOIN snapshots_jugador s ON s.jugador_id = j.id AND s.fecha = ?
          WHERE j.activo = 1'
    );
    $stmt->execute([$lectura]);
    $jugadores = $stmt->fetchAll();

    $actividad = ultimaActividad();

    foreach ($jugadores as &$j) {
        $id     = (int) $j['id'];
        $arenas = $aporta = 0;
        $d      = [];

        if ($capOportunidades > 0) {
            $arenas++;
            $p = $capital[$id] ?? 0;
            if ($p > 0) { $aporta++; }
            $d['capital'] = ['de' => $capOportunidades, 'hizo' => $p, 'tuvo' => true];
        } else {
            $d['capital'] = ['tuvo' => false];
        }

        $conv = $guerra[$id]['convocado'] ?? 0;
        if ($conv > 0) {
            $arenas++;
            $p = $guerra[$id]['participo'] ?? 0;
            if ($p > 0) { $aporta++; }
            $d['guerra'] = ['de' => $conv, 'hizo' => $p, 'tuvo' => true];
        } else {
            $d['guerra'] = ['tuvo' => false];
        }

        if (isset($liga[$id])) {
            $arenas++;
            if ($liga[$id]['ataques'] > 0) { $aporta++; }
            $d['liga'] = ['de' => 7, 'hizo' => $liga[$id]['ataques'], 'tuvo' => true];
        } else {
            $d['liga'] = ['tuvo' => false];
        }

        if ($hayJuegos) {
            $arenas++;
            $pts = $juegos[$id] ?? 0;
            if ($pts > 0) { $aporta++; }
            $d['juegos'] = ['puntos' => $pts, 'tuvo' => true];
        } else {
            $d['juegos'] = ['tuvo' => false];
        }

        $j['arenas']   = $arenas;
        $j['aporta']   = $aporta;
        $j['detalle']  = $d;
        $j['historia'] = (int) ($j['acum_guerra_estrellas'] ?? 0);
        $j['veterano'] = $j['historia'] >= VETERANO_ESTRELLAS;

        // Calidad de ataque: se prefiere la guerra normal por ser lo que
        // se está armando, y la liga sirve de respaldo cuando no hay.
        $gAtaques = $guerra[$id]['ataques'] ?? 0;
        $j['promGuerra'] = $gAtaques > 0 ? round($guerra[$id]['estrellas'] / $gAtaques, 2) : null;
        $j['promLiga']   = ($liga[$id]['ataques'] ?? 0) > 0
            ? round($liga[$id]['estrellas'] / $liga[$id]['ataques'], 2) : null;
        $j['calidad']    = $j['promGuerra'] ?? $j['promLiga'];

        $j['ultimaActividad'] = $actividad[$id] ?? null;
        $j['expulsar'] = $arenas > 0 && $aporta === 0;
        $j['completo'] = $arenas >= 2 && $aporta === $arenas;
    }
    unset($j);

    $expulsar = array_values(array_filter($jugadores, fn($j) => $j['expulsar']));
    usort($expulsar, fn($a, $b) => $a['historia'] <=> $b['historia']);

    $mejores = array_values(array_filter($jugadores, fn($j) => !$j['expulsar']));
    usort($mejores, function (array $a, array $b): int {
        $ca = $a['arenas'] ? $a['aporta'] / $a['arenas'] : 0;
        $cb = $b['arenas'] ? $b['aporta'] / $b['arenas'] : 0;
        return [$cb, $b['calidad'] ?? 0, $b['historia']] <=> [$ca, $a['calidad'] ?? 0, $a['historia']];
    });
    $mejores = array_slice($mejores, 0, CUPO_GUERRA);

    $parciales = array_values(array_filter($jugadores, fn($j) => !$j['expulsar'] && !$j['completo']));
    usort($parciales, fn($a, $b) => [$a['aporta'], $a['arenas']] <=> [$b['aporta'], $b['arenas']]);

    return [
        'jugadores'         => $jugadores,
        'expulsar'          => $expulsar,
        'mejores'           => $mejores,
        'parciales'         => $parciales,
        'capOportunidades'  => $capOportunidades,
        'guerrasConDetalle' => $guerrasConDetalle,
        'guerrasEnVentana'  => $guerrasEnVentana,
        'hayLiga'           => (bool) $liga,
        'hayJuegos'         => $hayJuegos,
        'lecturas'          => count($fechas),
        'midiendoDesde'     => midiendoDesde(),
        'dias'              => $dias,
    ];
}

/**
 * Última fecha en que se detectó que cada jugador jugó.
 *
 * La API no publica la última conexión: no tiene ni un campo de fecha.
 * Esto lo deduce comparando capturas consecutivas: si sus acumulados de
 * por vida se movieron, jugó; si no, no tocó el juego ese día.
 *
 * Solo mira lo que exige jugar de verdad. Los trofeos quedan fuera a
 * propósito, porque cambian solos cuando a uno lo atacan estando
 * desconectado, y darían por activo a quien no lo está.
 *
 * @return array<int,string> jugador_id => fecha
 */
function ultimaActividad(): array
{
    $filas = getDB()->query(
        'SELECT s.jugador_id, MAX(s.fecha) AS ultima
           FROM snapshots_jugador s
           JOIN snapshots_jugador prev
             ON prev.jugador_id = s.jugador_id
            AND prev.fecha = (SELECT MAX(f.fecha) FROM snapshots_jugador f
                               WHERE f.jugador_id = s.jugador_id AND f.fecha < s.fecha)
          WHERE s.acum_guerra_estrellas <> prev.acum_guerra_estrellas
             OR s.acum_capital_oro      <> prev.acum_capital_oro
             OR s.acum_juegos_puntos    <> prev.acum_juegos_puntos
             OR s.acum_donaciones       <> prev.acum_donaciones
       GROUP BY s.jugador_id'
    )->fetchAll();

    $r = [];
    foreach ($filas as $f) {
        $r[(int) $f['jugador_id']] = (string) $f['ultima'];
    }
    return $r;
}

/**
 * Desde cuándo se está midiendo. Sin dos capturas no se puede afirmar
 * nada sobre la actividad de nadie.
 */
function midiendoDesde(): ?string
{
    $n = (int) getDB()->query('SELECT COUNT(DISTINCT fecha) FROM snapshots_jugador')->fetchColumn();
    if ($n < 2) {
        return null;
    }
    return (string) getDB()->query('SELECT MIN(fecha) FROM snapshots_jugador')->fetchColumn();
}

/**
 * Jugadores sin señal de vida durante un periodo largo.
 *
 * "Sin jugar" se mide con los acumulados de por vida: si las estrellas
 * de guerra, el oro de capital y los puntos de juegos no se movieron
 * entre dos lecturas, esa persona no tocó el juego en ese lapso.
 *
 * Necesita historial: con dos días de capturas no se puede afirmar nada
 * sobre tres meses, así que devuelve también cuántos días abarca.
 *
 * @return array{jugadores:list<array>, diasCubiertos:int, suficiente:bool}
 */
function jugadoresInactivos(int $dias = 90): array
{
    $db    = getDB();
    $desde = date('Y-m-d', strtotime("-$dias days"));

    $primera = $db->query("SELECT MIN(fecha) FROM snapshots_jugador WHERE fecha >= '$desde'")->fetchColumn();
    $ultima  = $db->query('SELECT MAX(fecha) FROM snapshots_jugador')->fetchColumn();

    if (!$primera || !$ultima || $primera === $ultima) {
        return ['jugadores' => [], 'diasCubiertos' => 0, 'suficiente' => false];
    }

    $cubiertos = (int) ((strtotime((string) $ultima) - strtotime((string) $primera)) / 86400);

    $stmt = $db->prepare(
        'SELECT j.id, j.nombre_juego, j.tag, j.rol_clan, fin.th_nivel,
                (fin.acum_guerra_estrellas - ini.acum_guerra_estrellas) dGuerra,
                (fin.acum_capital_oro      - ini.acum_capital_oro)      dCapital,
                (fin.acum_juegos_puntos    - ini.acum_juegos_puntos)    dJuegos,
                (fin.acum_donaciones       - ini.acum_donaciones)       dDonaciones
           FROM snapshots_jugador ini
           JOIN snapshots_jugador fin ON fin.jugador_id = ini.jugador_id AND fin.fecha = ?
           JOIN jugadores j ON j.id = ini.jugador_id
          WHERE ini.fecha = ? AND j.activo = 1
         HAVING dGuerra = 0 AND dCapital = 0 AND dJuegos = 0 AND dDonaciones = 0
       ORDER BY j.nombre_juego'
    );
    $stmt->execute([$ultima, $primera]);

    return [
        'jugadores'     => $stmt->fetchAll(),
        'diasCubiertos' => $cubiertos,
        'suficiente'    => $cubiertos >= $dias,
    ];
}
