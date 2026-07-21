<?php
declare(strict_types=1);

/**
 * Tablero de decisión: a quién sacar y a quién meter a guerra.
 *
 * Se evalúa un mes completo, no un evento suelto. Faltar una vez puede
 * ser una mala semana; no aparecer en nada durante treinta días es un
 * patrón, y eso sí sostiene una expulsión.
 *
 * Cada actividad cuenta como una "arena" y solo pesa si el jugador tuvo
 * oportunidad real de jugarla. Quedar fuera del roster de CWL o no ser
 * convocado a una guerra son decisiones de la dirigencia: cobrárselas al
 * jugador marcaría a gente por algo que no depende de ellos.
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db    = getDB();
$dias  = 30;
$desde = date('Y-m-d', strtotime("-$dias days"));

$lectura = $db->query('SELECT MAX(fecha) FROM snapshots_jugador')->fetchColumn();

// ── Capital: cada fin de semana con desglose es una oportunidad ──
$capital = [];
$capOportunidades = (int) $db->query(
    "SELECT COUNT(DISTINCT cs.id) FROM capital_semanas cs
       JOIN capital_participaciones cp ON cp.semana_id = cs.id
      WHERE cs.fecha_inicio >= '$desde'"
)->fetchColumn();
foreach ($db->query(
    "SELECT cp.jugador_id, SUM(cp.ataques_realizados > 0) participo, SUM(cp.oro_aportado) oro
       FROM capital_participaciones cp
       JOIN capital_semanas cs ON cs.id = cp.semana_id
      WHERE cs.fecha_inicio >= '$desde'
   GROUP BY cp.jugador_id"
) as $r) {
    $capital[(int) $r['jugador_id']] = ['participo' => (int) $r['participo'], 'oro' => (int) $r['oro']];
}

// ── Guerras: solo cuentan para quien fue convocado al mapa ──
$guerra = [];
foreach ($db->query(
    "SELECT gp.jugador_id, COUNT(*) convocado,
            SUM(gp.ataque1_estrellas IS NOT NULL OR gp.ataque2_estrellas IS NOT NULL) participo,
            SUM(COALESCE(gp.ataque1_estrellas,0) + COALESCE(gp.ataque2_estrellas,0)) estrellas
       FROM guerra_participaciones gp
       JOIN guerras g ON g.id = gp.guerra_id
      WHERE g.fecha >= '$desde'
   GROUP BY gp.jugador_id"
) as $r) {
    $guerra[(int) $r['jugador_id']] = [
        'convocado' => (int) $r['convocado'],
        'participo' => (int) $r['participo'],
        'estrellas' => (int) $r['estrellas'],
    ];
}
$guerrasEnVentana = (int) $db->query("SELECT COUNT(*) FROM guerras WHERE fecha >= '$desde'")->fetchColumn();
$guerrasConDetalle = (int) $db->query(
    "SELECT COUNT(DISTINCT g.id) FROM guerras g
       JOIN guerra_participaciones gp ON gp.guerra_id = g.id
      WHERE g.fecha >= '$desde'"
)->fetchColumn();

// ── CWL: solo cuenta para quien entró al roster ──
$cwl = [];
foreach ($db->query(
    "SELECT p.jugador_id, p.ataques, p.estrellas
       FROM cwl_participaciones p
       JOIN cwl_temporadas t ON t.id = p.temporada_id
      WHERE CONCAT(t.mes, '-01') >= DATE_SUB('$desde', INTERVAL 1 MONTH)"
) as $r) {
    $cwl[(int) $r['jugador_id']] = ['ataques' => (int) $r['ataques'], 'estrellas' => (int) $r['estrellas']];
}
$hayCwl = (bool) $cwl;

// ── Juegos del Clan: diferencia del acumulado entre dos lecturas ──
$juegos = [];
$fechasSnap = $db->query("SELECT DISTINCT fecha FROM snapshots_jugador WHERE fecha >= '$desde' ORDER BY fecha")->fetchAll(PDO::FETCH_COLUMN);
$hayJuegos = count($fechasSnap) >= 2;
if ($hayJuegos) {
    $stmt = $db->prepare(
        'SELECT ini.jugador_id, (fin.acum_juegos_puntos - ini.acum_juegos_puntos) puntos
           FROM snapshots_jugador ini
           JOIN snapshots_jugador fin ON fin.jugador_id = ini.jugador_id AND fin.fecha = ?
          WHERE ini.fecha = ? AND ini.acum_juegos_puntos IS NOT NULL AND fin.acum_juegos_puntos IS NOT NULL'
    );
    $stmt->execute([end($fechasSnap), $fechasSnap[0]]);
    foreach ($stmt as $r) {
        $juegos[(int) $r['jugador_id']] = (int) $r['puntos'];
    }
}

// ── Jugadores activos con su última lectura ──
$stmt = $db->prepare(
    'SELECT j.id, j.nombre_juego, j.rol_clan, s.th_nivel, s.donaciones, s.acum_guerra_estrellas
       FROM jugadores j
       LEFT JOIN snapshots_jugador s ON s.jugador_id = j.id AND s.fecha = ?
      WHERE j.activo = 1'
);
$stmt->execute([$lectura]);
$jugadores = $stmt->fetchAll();

foreach ($jugadores as &$j) {
    $id = (int) $j['id'];
    $arenas = $aporta = 0;
    $detalle = [];

    // Capital: abierto a todo el clan, no jugarlo es decisión propia.
    if ($capOportunidades > 0) {
        $arenas++;
        $p = $capital[$id]['participo'] ?? 0;
        if ($p > 0) { $aporta++; }
        $detalle['capital'] = ['de' => $capOportunidades, 'hizo' => $p, 'tuvo' => true];
    }

    // Guerras: solo si lo convocaron.
    $conv = $guerra[$id]['convocado'] ?? 0;
    if ($conv > 0) {
        $arenas++;
        $p = $guerra[$id]['participo'] ?? 0;
        if ($p > 0) { $aporta++; }
        $detalle['guerra'] = ['de' => $conv, 'hizo' => $p, 'tuvo' => true];
    } else {
        $detalle['guerra'] = ['tuvo' => false];
    }

    // CWL: solo si entró al roster.
    if (isset($cwl[$id])) {
        $arenas++;
        if ($cwl[$id]['ataques'] > 0) { $aporta++; }
        $detalle['cwl'] = ['de' => 7, 'hizo' => $cwl[$id]['ataques'], 'tuvo' => true];
    } else {
        $detalle['cwl'] = ['tuvo' => false];
    }

    // Juegos del Clan: abiertos a todos.
    if ($hayJuegos) {
        $arenas++;
        $pts = $juegos[$id] ?? 0;
        if ($pts > 0) { $aporta++; }
        $detalle['juegos'] = ['puntos' => $pts, 'tuvo' => true];
    } else {
        $detalle['juegos'] = ['tuvo' => false];
    }

    $j['arenas']   = $arenas;
    $j['aporta']   = $aporta;
    $j['detalle']  = $detalle;
    $j['historia'] = (int) ($j['acum_guerra_estrellas'] ?? 0);
    $j['cwlProm']  = ($cwl[$id]['ataques'] ?? 0) > 0
        ? round($cwl[$id]['estrellas'] / $cwl[$id]['ataques'], 2) : null;

    $j['expulsar'] = $arenas > 0 && $aporta === 0;
    $j['completo'] = $arenas >= 2 && $aporta === $arenas;
}
unset($j);

// Arriba los que menos historia tienen: son los candidatos reales.
$expulsar = array_filter($jugadores, fn($j) => $j['expulsar']);
usort($expulsar, fn($a, $b) => $a['historia'] <=> $b['historia']);

// Una guerra de clan puede necesitar hasta 25 jugadores, así que la
// lista se llena hasta ese cupo aunque no todos hayan cubierto el 100%: se ordena por
// cobertura primero y por calidad de ataque después, y quien cubrió todo
// queda marcado. Así siempre hay con quién armar el mapa.
const CUPO_GUERRA = 25;

$mejores = $jugadores;
usort($mejores, function (array $a, array $b): int {
    $ca = $a['arenas'] ? $a['aporta'] / $a['arenas'] : 0;
    $cb = $b['arenas'] ? $b['aporta'] / $b['arenas'] : 0;
    return [$cb, $b['cwlProm'] ?? 0, $b['historia']]
       <=> [$ca, $a['cwlProm'] ?? 0, $a['historia']];
});
// Nadie que esté en la lista de expulsar tiene sitio en el mapa.
$mejores = array_slice(array_values(array_filter($mejores, fn($j) => !$j['expulsar'])), 0, CUPO_GUERRA);

$parciales = array_filter($jugadores, fn($j) => !$j['expulsar'] && !$j['completo']);
usort($parciales, fn($a, $b) => [$a['aporta'], $a['arenas']] <=> [$b['aporta'], $b['arenas']]);

$rolBadge = ['lider'=>'badge-gold','colider'=>'badge-purple','veterano'=>'badge-blue','miembro'=>'badge-muted'];

$pageTitle = 'Decisiones';
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-clipboard-data-fill"></i> Decisiones</h1>
    <span class="text-muted" style="font-size:.85rem">
        Últimos <?= $dias ?> días<?= $lectura ? ' · lectura del ' . date('d/m/Y', strtotime((string) $lectura)) : '' ?>
    </span>
</div>

<?php if (!$jugadores): ?>
    <div class="empty-state"><div class="empty-icon">📊</div><p>Sin jugadores activos todavía.</p></div>
<?php else: ?>

<!-- Qué se pudo medir en la ventana -->
<div class="card mb-4">
    <div class="card-body py-2 d-flex flex-wrap gap-3" style="font-size:.85rem">
        <span><i class="bi bi-building-fill text-<?= $capOportunidades ? 'success' : 'muted' ?>"></i>
            Capital: <strong><?= $capOportunidades ?></strong> fin<?= $capOportunidades === 1 ? '' : 'es' ?> de semana con detalle</span>
        <span><i class="bi bi-lightning-fill text-<?= $guerrasConDetalle ? 'success' : 'muted' ?>"></i>
            Guerras: <strong><?= $guerrasConDetalle ?></strong> de <?= $guerrasEnVentana ?> con detalle por jugador</span>
        <span><i class="bi bi-trophy-fill text-<?= $hayCwl ? 'success' : 'muted' ?>"></i>
            Liga: <strong><?= $hayCwl ? 'sí' : 'no' ?></strong></span>
        <span><i class="bi bi-controller text-<?= $hayJuegos ? 'success' : 'muted' ?>"></i>
            Juegos: <strong><?= $hayJuegos ? count($fechasSnap) . ' lecturas' : 'faltan lecturas' ?></strong></span>
    </div>
</div>

<div class="row g-3">
    <!-- ── IZQUIERDA: expulsar ─────────────────────────────────── -->
    <div class="col-lg-6">
        <div class="card" style="border-left:3px solid var(--ct-red-text)">
            <div class="card-header">
                <i class="bi bi-person-x-fill text-danger"></i> Expulsar
                <span class="badge badge-red ms-1"><?= count($expulsar) ?></span>
            </div>
            <div class="card-body py-2 flex-grow-0">
                <small class="text-muted">Cero participación en todo lo que tuvieron disponible durante el mes.</small>
            </div>
            <?php if (!$expulsar): ?>
                <div class="card-body text-muted pt-0">Nadie quedó en cero absoluto.</div>
            <?php else: ?>
            <div class="table-responsive"><table class="table table-hover mb-0">
                <thead><tr><th>Jugador</th><th class="text-center">Capital</th><th class="text-center">Guerra</th><th class="text-center">Liga</th><th class="text-center">Juegos</th><th class="text-center">Historia</th></tr></thead>
                <tbody>
                <?php foreach ($expulsar as $j): $d = $j['detalle']; ?>
                    <tr>
                        <td>
                            <strong class="text-white"><?= clean($j['nombre_juego']) ?></strong>
                            <div><small class="text-muted"><?= $j['th_nivel'] ? 'TH' . (int) $j['th_nivel'] : '' ?></small></div>
                        </td>
                        <td class="text-center"><?= $d['capital']['tuvo'] ? '<span class="badge badge-red">0 de ' . $d['capital']['de'] . '</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-center"><?= $d['guerra']['tuvo'] ? '<span class="badge badge-red">0 de ' . $d['guerra']['de'] . '</span>' : '<span class="text-muted">no convocado</span>' ?></td>
                        <td class="text-center"><?= $d['cwl']['tuvo'] ? '<span class="badge badge-red">0/7</span>' : '<span class="text-muted">no entró</span>' ?></td>
                        <td class="text-center"><?= $d['juegos']['tuvo'] ? '<span class="badge badge-red">0</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-center">
                            <span class="<?= $j['historia'] >= 500 ? 'badge badge-blue' : 'text-muted' ?>"><?= number_format($j['historia']) ?> ⭐</span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="card-body pt-2">
                <small class="text-muted">
                    La columna Historia son estrellas de guerra de por vida. Los de arriba, sin historia,
                    son los candidatos claros; uno con miles de estrellas merece preguntarle antes de sacarlo.
                </small>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── DERECHA: los mejores ────────────────────────────────── -->
    <div class="col-lg-6">
        <div class="card" style="border-left:3px solid var(--ct-green-text)">
            <div class="card-header">
                <i class="bi bi-shield-fill-check text-success"></i> Mejores para guerra
                <span class="badge badge-green ms-1"><?= count($mejores) ?> de <?= CUPO_GUERRA ?></span>
            </div>
            <div class="card-body py-2 flex-grow-0">
                <small class="text-muted">Una guerra de clan puede llegar a necesitar <?= CUPO_GUERRA ?> jugadores. Van ordenados por cobertura y calidad de ataque.</small>
            </div>
            <?php if (!$mejores): ?>
                <div class="card-body text-muted pt-0">Sin jugadores disponibles.</div>
            <?php else: ?>
            <div class="table-responsive"><table class="table table-hover mb-0">
                <thead><tr><th class="text-center">#</th><th>Jugador</th><th class="text-center">Cubrió</th><th class="text-center">Capital</th><th class="text-center">Liga</th><th class="text-center">⭐/ataque</th></tr></thead>
                <tbody>
                <?php foreach ($mejores as $i => $j): $d = $j['detalle']; ?>
                    <tr>
                        <td class="text-center text-muted"><?= $i + 1 ?></td>
                        <td>
                            <strong class="text-white"><?= clean($j['nombre_juego']) ?></strong>
                            <?php if ($j['completo']): ?>
                                <i class="bi bi-check-circle-fill text-success ms-1" title="Participó en todos sus frentes"></i>
                            <?php endif; ?>
                            <div><small class="text-muted"><?= $j['th_nivel'] ? 'TH' . (int) $j['th_nivel'] : '' ?></small></div>
                        </td>
                        <td class="text-center">
                            <span class="badge <?= $j['completo'] ? 'badge-green' : ($j['aporta'] ? 'badge-gold' : 'badge-muted') ?>">
                                <?= (int) $j['aporta'] ?> de <?= (int) $j['arenas'] ?>
                            </span>
                        </td>
                        <td class="text-center"><?= $d['capital']['tuvo'] ? $d['capital']['hizo'] . ' de ' . $d['capital']['de'] : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-center"><?= $d['cwl']['tuvo'] ? $d['cwl']['hizo'] . '/7' : '<span class="text-muted">no entró</span>' ?></td>
                        <td class="text-center">
                            <?php if ($j['cwlProm'] !== null): ?>
                                <span class="badge <?= $j['cwlProm'] >= 2.5 ? 'badge-green' : ($j['cwlProm'] >= 2 ? 'badge-gold' : 'badge-muted') ?>"><?= $j['cwlProm'] ?></span>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="card-body pt-2 flex-grow-0">
                <small class="text-muted">
                    La palomita marca a quien participó en todo lo que tuvo disponible.
                    Estrellas por ataque mide calidad, no esfuerzo: arriba de 2.5 destruye casi todo lo que toca.
                </small>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Zona gris ──────────────────────────────────────────────── -->
<div class="card mt-3">
    <div class="card-header">
        <i class="bi bi-hourglass-split text-warning"></i> Participan a medias
        <span class="badge badge-gold ms-1"><?= count($parciales) ?></span>
    </div>
    <div class="card-body py-2">
        <small class="text-muted">Aportan en algunos frentes pero no en todos. Es la lista a vigilar el mes que viene.</small>
    </div>
    <div class="table-responsive"><table class="table table-hover mb-0">
        <thead><tr><th>Jugador</th><th class="text-center">Cubrió</th><th class="text-center">Capital</th><th class="text-center">Guerra</th><th class="text-center">Liga</th><th class="text-center">Juegos</th><th class="text-center">Donaciones</th></tr></thead>
        <tbody>
        <?php foreach ($parciales as $j): $d = $j['detalle']; ?>
            <tr>
                <td>
                    <strong class="text-white"><?= clean($j['nombre_juego']) ?></strong>
                    <span class="badge <?= $rolBadge[$j['rol_clan']] ?? 'badge-muted' ?> ms-1"><?= ucfirst($j['rol_clan']) ?></span>
                </td>
                <td class="text-center"><span class="badge <?= $j['aporta'] ? 'badge-gold' : 'badge-red' ?>"><?= $j['aporta'] ?> de <?= $j['arenas'] ?></span></td>
                <td class="text-center"><?= $d['capital']['tuvo'] ? $d['capital']['hizo'] . ' de ' . $d['capital']['de'] : '<span class="text-muted">—</span>' ?></td>
                <td class="text-center"><?= $d['guerra']['tuvo'] ? $d['guerra']['hizo'] . ' de ' . $d['guerra']['de'] : '<span class="text-muted">no convocado</span>' ?></td>
                <td class="text-center"><?= $d['cwl']['tuvo'] ? $d['cwl']['hizo'] . '/7' : '<span class="text-muted">no entró</span>' ?></td>
                <td class="text-center"><?= $d['juegos']['tuvo'] ? number_format($d['juegos']['puntos']) : '<span class="text-muted">—</span>' ?></td>
                <td class="text-center text-muted"><?= number_format((int) ($j['donaciones'] ?? 0)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="text-muted mt-3" style="font-size:.85rem">
    <strong>Cómo se decide.</strong>
    Se miran cuatro actividades del último mes: asaltos al capital, guerras, CWL y Juegos del Clan.
    Cada una cuenta solo si el jugador tuvo la oportunidad: el capital y los juegos están abiertos a
    todo el clan, pero la guerra y la CWL dependen de que tú lo convoques, y no sería justo cobrarle
    una ausencia que no eligió. Aparece en Expulsar quien quedó en cero en todas las que sí tuvo.
</div>

<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
