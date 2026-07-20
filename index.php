<?php
declare(strict_types=1);

/**
 * Tablero principal: a quién sacar y a quién meter a guerra.
 *
 * La idea es medir participación en "arenas": los espacios donde un
 * miembro puede aportar y se puede comprobar con datos de la API. Hoy
 * son los asaltos al capital y la CWL.
 *
 * Solo cuenta una arena si el jugador tuvo oportunidad real de jugarla:
 * quedar fuera del roster de CWL es una decisión de la dirigencia, no
 * del jugador, y contarlo en contra marcaría a media plantilla por algo
 * que no depende de ellos.
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db = getDB();

// Umbral para considerar que alguien donó algo. Las donaciones se
// reinician cada temporada, así que a principio de mes todos se ven
// bajos: por eso pesa menos que las arenas de evento.
const MINIMO_DONACIONES = 100;

$cwlTemp = $db->query(
    'SELECT t.id, t.mes FROM cwl_temporadas t
       JOIN cwl_participaciones p ON p.temporada_id = t.id
   GROUP BY t.id ORDER BY t.mes DESC LIMIT 1'
)->fetch();

$capSemana = $db->query(
    'SELECT s.id, s.fecha_inicio FROM capital_semanas s
       JOIN capital_participaciones p ON p.semana_id = s.id
   GROUP BY s.id ORDER BY s.fecha_inicio DESC LIMIT 1'
)->fetch();

$lectura = $db->query('SELECT MAX(fecha) FROM snapshots_jugador')->fetchColumn();

$stmt = $db->prepare(
    'SELECT j.id, j.nombre_juego, j.rol_clan,
            s.th_nivel, s.donaciones, s.donaciones_recibidas, s.acum_guerra_estrellas,
            cw.estrellas AS cwl_estrellas, cw.ataques AS cwl_ataques,
            cp.oro_aportado AS cap_oro, cp.ataques_realizados AS cap_ataques
       FROM jugadores j
       LEFT JOIN snapshots_jugador s        ON s.jugador_id  = j.id AND s.fecha = :lectura
       LEFT JOIN cwl_participaciones cw     ON cw.jugador_id = j.id AND cw.temporada_id = :cwl
       LEFT JOIN capital_participaciones cp ON cp.jugador_id = j.id AND cp.semana_id = :cap
      WHERE j.activo = 1'
);
$stmt->execute([
    'lectura' => $lectura,
    'cwl'     => $cwlTemp['id'] ?? 0,
    'cap'     => $capSemana['id'] ?? 0,
]);
$jugadores = $stmt->fetchAll();

$hayCwl     = (bool) ($cwlTemp['id'] ?? null);
$hayCapital = (bool) ($capSemana['id'] ?? null);

foreach ($jugadores as &$j) {
    $arenas = 0;   // en cuántas pudo participar
    $aporta = 0;   // en cuántas participó

    // El capital está abierto a todo el clan: no jugarlo es decisión propia.
    if ($hayCapital) {
        $arenas++;
        if ((int) ($j['cap_ataques'] ?? 0) > 0) {
            $aporta++;
        }
    }

    // La CWL solo cuenta para quien entró al roster.
    $enRosterCwl = $hayCwl && $j['cwl_ataques'] !== null;
    if ($enRosterCwl) {
        $arenas++;
        if ((int) $j['cwl_ataques'] > 0) {
            $aporta++;
        }
    }

    $dono = (int) ($j['donaciones'] ?? 0) >= MINIMO_DONACIONES;

    $j['arenas']      = $arenas;
    $j['aporta']      = $aporta;
    $j['enRosterCwl'] = $enRosterCwl;
    $j['dono']        = $dono;
    $j['cwl_prom']    = (int) ($j['cwl_ataques'] ?? 0) > 0
        ? round((int) $j['cwl_estrellas'] / (int) $j['cwl_ataques'], 2)
        : null;

    // No aporta nada: cero en todas las arenas que pudo jugar y encima
    // sin donar. Es el caso que justifica sacar a alguien.
    $j['nulo'] = $arenas > 0 && $aporta === 0 && !$dono;

    // Juega todo: participó en cada arena que tuvo disponible.
    $j['completo'] = $arenas >= 2 && $aporta === $arenas;
}
unset($j);

$nulos = array_filter($jugadores, fn($j) => $j['nulo']);
usort($nulos, fn($a, $b) => (int) ($a['donaciones'] ?? 0) <=> (int) ($b['donaciones'] ?? 0));

$completos = array_filter($jugadores, fn($j) => $j['completo']);
usort($completos, fn($a, $b) => ($b['cwl_prom'] ?? 0) <=> ($a['cwl_prom'] ?? 0));

$parciales = array_filter($jugadores, fn($j) => !$j['nulo'] && !$j['completo']);
usort($parciales, fn($a, $b) => [$a['aporta'], $a['arenas']] <=> [$b['aporta'], $b['arenas']]);

$rolBadge = ['lider'=>'badge-gold','colider'=>'badge-purple','veterano'=>'badge-blue','miembro'=>'badge-muted'];

$pageTitle = 'Decisiones';
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-clipboard-data-fill"></i> Decisiones</h1>
    <span class="text-muted" style="font-size:.85rem">
        <?= $lectura ? 'Lectura del ' . date('d/m/Y', strtotime((string) $lectura)) : 'Sin lecturas' ?>
    </span>
</div>

<?php if (!$jugadores): ?>
    <div class="empty-state"><div class="empty-icon">📊</div><p>Sin jugadores activos todavía.</p></div>
<?php else: ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">👥</div><div class="stat-value"><?= count($jugadores) ?></div><div class="stat-label">En el clan</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">🚫</div><div class="stat-value text-danger"><?= count($nulos) ?></div><div class="stat-label">No aportan nada</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">🏅</div><div class="stat-value text-success"><?= count($completos) ?></div><div class="stat-label">Juegan todo</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">◐</div><div class="stat-value"><?= count($parciales) ?></div><div class="stat-label">Participan a medias</div></div></div>
</div>

<!-- ── Para sacar ─────────────────────────────────────────────── -->
<div class="card mb-4" style="border-left:3px solid var(--ct-red-text)">
    <div class="card-header"><i class="bi bi-person-x-fill text-danger"></i> No aportan nada — candidatos a expulsión</div>
    <?php if (!$nulos): ?>
        <div class="card-body text-muted">Nadie está en cero absoluto. Revisa "Participan a medias" más abajo.</div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-hover mb-0">
        <thead><tr>
            <th>Jugador</th><th>Rol</th><th class="text-center">TH</th>
            <th class="text-center">Capital</th><th class="text-center">CWL</th><th class="text-center">Donaciones</th>
        </tr></thead>
        <tbody>
        <?php foreach ($nulos as $j): ?>
            <tr>
                <td><strong class="text-white"><?= clean($j['nombre_juego']) ?></strong></td>
                <td><span class="badge <?= $rolBadge[$j['rol_clan']] ?? 'badge-muted' ?>"><?= ucfirst($j['rol_clan']) ?></span></td>
                <td class="text-center"><?= $j['th_nivel'] ? 'TH' . (int) $j['th_nivel'] : '—' ?></td>
                <td class="text-center"><span class="badge badge-red">No jugó</span></td>
                <td class="text-center">
                    <?php if ($j['enRosterCwl']): ?>
                        <span class="badge badge-red">0 de 7 ataques</span>
                    <?php else: ?>
                        <span class="text-muted">No entró</span>
                    <?php endif; ?>
                </td>
                <td class="text-center text-danger"><?= number_format((int) ($j['donaciones'] ?? 0)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<!-- ── Para meter a guerra ────────────────────────────────────── -->
<div class="card mb-4" style="border-left:3px solid var(--ct-green-text)">
    <div class="card-header"><i class="bi bi-shield-fill-check text-success"></i> Juegan todo — material para guerra</div>
    <?php if (!$completos): ?>
        <div class="card-body text-muted">Nadie participó en todas las arenas disponibles.</div>
    <?php else: ?>
    <div class="card-body py-2">
        <small class="text-muted">Participaron en cada frente que tuvieron disponible. Ordenados por estrellas por ataque, que mide calidad y no solo esfuerzo.</small>
    </div>
    <div class="table-responsive"><table class="table table-hover mb-0">
        <thead><tr>
            <th class="text-center">#</th><th>Jugador</th><th class="text-center">TH</th>
            <th class="text-center">Estrellas por ataque</th><th class="text-center">CWL</th>
            <th class="text-center">Capital</th><th class="text-center">Donadas</th>
        </tr></thead>
        <tbody>
        <?php foreach ($completos as $i => $j): ?>
            <tr>
                <td class="text-center text-muted"><?= $i + 1 ?></td>
                <td><strong class="text-white"><?= clean($j['nombre_juego']) ?></strong></td>
                <td class="text-center"><?= $j['th_nivel'] ? 'TH' . (int) $j['th_nivel'] : '—' ?></td>
                <td class="text-center">
                    <?php if ($j['cwl_prom'] !== null): ?>
                        <span class="badge <?= $j['cwl_prom'] >= 2.5 ? 'badge-green' : ($j['cwl_prom'] >= 2 ? 'badge-gold' : 'badge-muted') ?>"><?= $j['cwl_prom'] ?></span>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <td class="text-center"><?= $j['cwl_ataques'] !== null ? (int) $j['cwl_ataques'] . '/7' : '—' ?></td>
                <td class="text-center"><?= (int) ($j['cap_ataques'] ?? 0) ?> ataques</td>
                <td class="text-center text-success"><?= number_format((int) ($j['donaciones'] ?? 0)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<!-- ── Zona gris ──────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-hourglass-split text-warning"></i> Participan a medias</div>
    <div class="card-body py-2">
        <small class="text-muted">Aportan en algunos frentes pero no en todos. Si repiten el patrón, bajan al grupo de arriba.</small>
    </div>
    <div class="table-responsive"><table class="table table-hover mb-0">
        <thead><tr>
            <th>Jugador</th><th class="text-center">Participación</th>
            <th class="text-center">Capital</th><th class="text-center">CWL</th><th class="text-center">Donaciones</th>
        </tr></thead>
        <tbody>
        <?php foreach ($parciales as $j): ?>
            <tr>
                <td><strong class="text-white"><?= clean($j['nombre_juego']) ?></strong></td>
                <td class="text-center">
                    <span class="badge <?= $j['arenas'] && $j['aporta'] === $j['arenas'] ? 'badge-green' : ((int) $j['aporta'] ? 'badge-gold' : 'badge-red') ?>">
                        <?= (int) $j['aporta'] ?> de <?= (int) $j['arenas'] ?>
                    </span>
                </td>
                <td class="text-center">
                    <?php if ($j['cap_ataques'] === null): ?>
                        <span class="text-danger">No jugó</span>
                    <?php else: ?>
                        <?= (int) $j['cap_ataques'] ?> ataques
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <?php if ($j['enRosterCwl']): ?>
                        <?= (int) $j['cwl_ataques'] ?>/7
                    <?php else: ?>
                        <span class="text-muted">No entró</span>
                    <?php endif; ?>
                </td>
                <td class="text-center <?= $j['dono'] ? 'text-success' : 'text-muted' ?>"><?= number_format((int) ($j['donaciones'] ?? 0)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="text-muted" style="font-size:.85rem">
    <strong>Cómo se calcula.</strong>
    Se miden las arenas donde el jugador pudo aportar y queda registro en la API:
    el asalto al capital del <?= $capSemana ? date('d/m/Y', strtotime($capSemana['fecha_inicio'])) : '—' ?>
    y la CWL de <?= $cwlTemp ? clean((string) $cwlTemp['mes']) : '—' ?>.
    El capital cuenta para todos porque está abierto a todo el clan; la CWL solo para quien entró al roster,
    ya que esa selección la haces tú y no sería justo cobrársela al jugador.
    Se considera que donó a partir de <?= MINIMO_DONACIONES ?> tropas en la temporada, que se reinicia cada mes.
</div>

<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
