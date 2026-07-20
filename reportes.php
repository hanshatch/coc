<?php
declare(strict_types=1);

/**
 * Tablero de decisión: a quién expulsar y a quién meter a guerra.
 *
 * No inventa un puntaje único que esconda el porqué. Muestra las señales
 * lado a lado y marca las que están por debajo del umbral, para que la
 * decisión sea tuya y puedas ver en qué se basa.
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db = getDB();

// Última temporada de CWL con datos.
$cwlId = $db->query(
    'SELECT t.id FROM cwl_temporadas t
       JOIN cwl_participaciones p ON p.temporada_id = t.id
   GROUP BY t.id ORDER BY t.mes DESC LIMIT 1'
)->fetchColumn();

// Último fin de semana de capital con desglose.
$capId = $db->query(
    'SELECT s.id FROM capital_semanas s
       JOIN capital_participaciones p ON p.semana_id = s.id
   GROUP BY s.id ORDER BY s.fecha_inicio DESC LIMIT 1'
)->fetchColumn();

$ultimaLectura = $db->query('SELECT MAX(fecha) FROM snapshots_jugador')->fetchColumn();

$sql = 'SELECT j.id, j.tag, j.nombre_juego, j.rol_clan,
               s.th_nivel, s.trofeos, s.donaciones, s.donaciones_recibidas,
               s.acum_guerra_estrellas, s.acum_cwl_estrellas,
               cw.estrellas AS cwl_estrellas, cw.ataques AS cwl_ataques,
               cp.oro_aportado AS cap_oro, cp.ataques_realizados AS cap_ataques
          FROM jugadores j
          LEFT JOIN snapshots_jugador s
                 ON s.jugador_id = j.id AND s.fecha = :lectura
          LEFT JOIN cwl_participaciones cw
                 ON cw.jugador_id = j.id AND cw.temporada_id = :cwl
          LEFT JOIN capital_participaciones cp
                 ON cp.jugador_id = j.id AND cp.semana_id = :cap
         WHERE j.activo = 1
      ORDER BY j.nombre_juego ASC';

$stmt = $db->prepare($sql);
$stmt->execute([
    'lectura' => $ultimaLectura,
    'cwl'     => $cwlId ?: 0,
    'cap'     => $capId ?: 0,
]);
$jugadores = $stmt->fetchAll();

/**
 * Marca las señales flojas de un jugador.
 *
 * No tener fila en capital o CWL significa que no participó, no que
 * falte el dato: la API solo lista a quien atacó. Tratar esa ausencia
 * como "sin información" escondería justo a los que hay que revisar.
 *
 * @param  bool $hayCwl     ¿Existe una temporada de CWL con datos?
 * @param  bool $hayCapital ¿Existe un fin de semana con desglose?
 * @return list<string>
 */
function alertas(array $j, bool $hayCwl, bool $hayCapital): array
{
    $a = [];

    if ($hayCwl) {
        $ataques = (int) ($j['cwl_ataques'] ?? 0);
        if ($j['cwl_ataques'] === null) {
            $a[] = 'Fuera del roster de CWL';
        } elseif ($ataques === 0) {
            $a[] = 'No atacó en CWL';
        } elseif ($ataques < 4) {
            $a[] = 'Pocos ataques en CWL';
        }
    }

    if ($hayCapital && $j['cap_ataques'] === null) {
        $a[] = 'No participó en el capital';
    } elseif ($hayCapital && (int) $j['cap_ataques'] === 0) {
        $a[] = 'No atacó en capital';
    }

    $dad = (int) ($j['donaciones'] ?? 0);
    $rec = (int) ($j['donaciones_recibidas'] ?? 0);
    if ($rec > 100 && $dad < $rec * 0.5) {
        $a[] = 'Recibe mucho más de lo que dona';
    }

    return $a;
}

$hayCwl     = (bool) $cwlId;
$hayCapital = (bool) $capId;

foreach ($jugadores as &$j) {
    $j['alertas']  = alertas($j, $hayCwl, $hayCapital);
    $j['cwl_prom'] = $j['cwl_ataques'] ? round((int) $j['cwl_estrellas'] / (int) $j['cwl_ataques'], 2) : null;
}
unset($j);

// Para expulsar: los que más señales flojas acumulan.
$paraExpulsar = array_filter($jugadores, fn($j) => count($j['alertas']) >= 2);
usort($paraExpulsar, fn($a, $b) => count($b['alertas']) <=> count($a['alertas']));

// Para guerra: los que mejor rinden por ataque, con al menos 4 ataques.
$paraGuerra = array_filter($jugadores, fn($j) => $j['cwl_prom'] !== null && (int) $j['cwl_ataques'] >= 4);
usort($paraGuerra, fn($a, $b) => $b['cwl_prom'] <=> $a['cwl_prom']);

$pageTitle = 'Decisiones';
require __DIR__ . '/includes/header.php';

$rolBadge = ['lider'=>'badge-gold','colider'=>'badge-purple','veterano'=>'badge-blue','miembro'=>'badge-muted'];
?>

<div class="ct-page-header">
    <h1><i class="bi bi-clipboard-data-fill"></i> Decisiones</h1>
    <span class="text-muted" style="font-size:.85rem">
        <?= $ultimaLectura ? 'Lectura del ' . date('d/m/Y', strtotime((string) $ultimaLectura)) : 'Sin lecturas' ?>
    </span>
</div>

<?php if (!$jugadores): ?>
    <div class="empty-state"><div class="empty-icon">📊</div><p>Sin jugadores activos. Corre una sincronización.</p></div>
<?php else: ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">👥</div><div class="stat-value"><?= count($jugadores) ?></div><div class="stat-label">En el clan</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">⚠️</div><div class="stat-value"><?= count($paraExpulsar) ?></div><div class="stat-label">Con 2+ alertas</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">⭐</div><div class="stat-value"><?= count($paraGuerra) ?></div><div class="stat-label">Confiables en guerra</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">😴</div><div class="stat-value"><?= count(array_filter($jugadores, fn($j) => $j['cwl_ataques'] !== null && (int) $j['cwl_ataques'] === 0)) ?></div><div class="stat-label">No atacaron en CWL</div></div></div>
</div>

<!-- ── A quién revisar para expulsión ────────────────────────── -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-exclamation-triangle-fill text-danger"></i> Candidatos a revisar</div>
    <?php if (!$paraExpulsar): ?>
        <div class="card-body text-muted">Nadie acumula dos o más señales flojas. El clan va bien.</div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-hover mb-0">
        <thead><tr><th>Jugador</th><th>Rol</th><th class="text-center">CWL</th><th class="text-center">Capital</th><th class="text-center">Donaciones</th><th>Por qué</th></tr></thead>
        <tbody>
        <?php foreach ($paraExpulsar as $j): ?>
            <tr>
                <td>
                    <strong class="text-white"><?= clean($j['nombre_juego']) ?></strong>
                    <div><small class="text-muted"><?= $j['th_nivel'] ? 'TH' . (int) $j['th_nivel'] : '' ?></small></div>
                </td>
                <td><span class="badge <?= $rolBadge[$j['rol_clan']] ?? 'badge-muted' ?>"><?= ucfirst($j['rol_clan']) ?></span></td>
                <td class="text-center"><?= $j['cwl_ataques'] !== null ? (int) $j['cwl_ataques'] . '/7' : '—' ?></td>
                <td class="text-center"><?= $j['cap_ataques'] !== null ? (int) $j['cap_ataques'] : '<span class="text-danger">no jugó</span>' ?></td>
                <td class="text-center">
                    <span class="text-success"><?= number_format((int) ($j['donaciones'] ?? 0)) ?></span><span class="text-muted">/</span><span class="text-danger"><?= number_format((int) ($j['donaciones_recibidas'] ?? 0)) ?></span>
                </td>
                <td>
                    <?php foreach ($j['alertas'] as $a): ?>
                        <span class="badge badge-red mb-1"><?= clean($a) ?></span>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<!-- ── A quién meter a guerra ────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-shield-fill-check text-success"></i> Mejores para guerra</div>
    <?php if (!$paraGuerra): ?>
        <div class="card-body text-muted">Sin datos de CWL suficientes todavía.</div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-hover mb-0">
        <thead><tr><th class="text-center">#</th><th>Jugador</th><th class="text-center">TH</th><th class="text-center">Estrellas por ataque</th><th class="text-center">Ataques CWL</th><th class="text-center">Estrellas de por vida</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($paraGuerra, 0, 15) as $i => $j): ?>
            <tr>
                <td class="text-center text-muted"><?= $i + 1 ?></td>
                <td><strong class="text-white"><?= clean($j['nombre_juego']) ?></strong></td>
                <td class="text-center"><?= $j['th_nivel'] ? 'TH' . (int) $j['th_nivel'] : '—' ?></td>
                <td class="text-center">
                    <span class="badge <?= $j['cwl_prom'] >= 2.5 ? 'badge-green' : ($j['cwl_prom'] >= 2 ? 'badge-gold' : 'badge-muted') ?>">
                        <?= $j['cwl_prom'] ?>
                    </span>
                </td>
                <td class="text-center"><?= (int) $j['cwl_ataques'] ?>/7</td>
                <td class="text-center text-muted"><?= number_format((int) ($j['acum_guerra_estrellas'] ?? 0)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<!-- ── Tabla completa ────────────────────────────────────────── -->
<div class="card">
    <div class="card-header"><i class="bi bi-table"></i> Todos los miembros</div>
    <div class="table-responsive"><table class="table table-hover mb-0">
        <thead><tr>
            <th>Jugador</th><th class="text-center">TH</th><th class="text-center">CWL</th>
            <th class="text-center">Capital</th><th class="text-center">Donaciones</th><th class="text-center">Alertas</th>
        </tr></thead>
        <tbody>
        <?php foreach ($jugadores as $j): ?>
            <tr>
                <td>
                    <strong class="text-white"><?= clean($j['nombre_juego']) ?></strong>
                    <span class="badge <?= $rolBadge[$j['rol_clan']] ?? 'badge-muted' ?> ms-1"><?= ucfirst($j['rol_clan']) ?></span>
                </td>
                <td class="text-center"><?= $j['th_nivel'] ? (int) $j['th_nivel'] : '—' ?></td>
                <td class="text-center">
                    <?php if ($j['cwl_ataques'] !== null): ?>
                        <?= (int) $j['cwl_estrellas'] ?>⭐ <small class="text-muted">(<?= (int) $j['cwl_ataques'] ?>/7)</small>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td class="text-center">
                    <?= $j['cap_oro'] !== null ? number_format((int) $j['cap_oro']) : '—' ?>
                </td>
                <td class="text-center">
                    <span class="text-success"><?= number_format((int) ($j['donaciones'] ?? 0)) ?></span><span class="text-muted">/</span><span class="text-danger"><?= number_format((int) ($j['donaciones_recibidas'] ?? 0)) ?></span>
                </td>
                <td class="text-center">
                    <?php if ($j['alertas']): ?>
                        <span class="badge <?= count($j['alertas']) >= 2 ? 'badge-red' : 'badge-gold' ?>"><?= count($j['alertas']) ?></span>
                    <?php else: ?>
                        <span class="badge badge-green">✓</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="text-muted mt-3" style="font-size:.85rem">
    <strong>Cómo leer esto.</strong> Una alerta salta cuando el jugador no atacó en CWL o usó menos de
    4 de sus 7 ataques, cuando no atacó en el capital, o cuando recibe más del doble de lo que dona.
    Un solo dato flojo puede ser una mala semana; dos o más señales sostenidas es un patrón.
    Las estrellas por ataque miden calidad, no esfuerzo: un jugador con 3.0 destruye todo lo que toca.
</div>

<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
