<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db = getDB();
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: cwl'); exit; }

$stmt = $db->prepare('SELECT * FROM cwl_temporadas WHERE id = ?');
$stmt->execute([$id]);
$temp = $stmt->fetch();
if (!$temp) { setFlash('error', 'Temporada no encontrada.'); header('Location: cwl'); exit; }

// ── Agregar jugadores ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_players'])) {
    verifyCsrf();
    $playerIds = $_POST['jugador_ids'] ?? [];
    if (!empty($playerIds)) {
        $stmt = $db->prepare('INSERT IGNORE INTO cwl_participaciones (temporada_id, jugador_id, dia) VALUES (?, ?, ?)');
        foreach ($playerIds as $pid) {
            for ($dia = 1; $dia <= 7; $dia++) {
                $stmt->execute([$id, (int) $pid, $dia]);
            }
        }
        logActivity('crear', 'cwl_participaciones', $id, 'Agregados ' . count($playerIds) . ' jugadores');
        setFlash('success', count($playerIds) . ' jugador(es) agregado(s) al roster.');
    }
    header('Location: cwl_detalle?id=' . $id); exit;
}

// ── Guardar participaciones ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_participation'])) {
    verifyCsrf();
    $participo  = $_POST['participo'] ?? [];
    $estrellas  = $_POST['estrellas'] ?? [];
    $porcentaje = $_POST['porcentaje'] ?? [];

    $stmt = $db->prepare(
        'UPDATE cwl_participaciones SET participo=?, estrellas=?, porcentaje=?
         WHERE temporada_id=? AND jugador_id=? AND dia=?'
    );

    foreach ($participo as $jid => $dias) {
        for ($dia = 1; $dia <= 7; $dia++) {
            $part = isset($dias[$dia]) ? 1 : 0;
            $est  = ($estrellas[$jid][$dia] ?? '') !== '' ? (int) $estrellas[$jid][$dia] : null;
            $pct  = ($porcentaje[$jid][$dia] ?? '') !== '' ? (float) $porcentaje[$jid][$dia] : null;
            $stmt->execute([$part, $est, $pct, $id, (int) $jid, $dia]);
        }
    }

    logActivity('editar', 'cwl_participaciones', $id, 'Participaciones actualizadas');
    setFlash('success', 'Participaciones CWL guardadas.');
    header('Location: cwl_detalle?id=' . $id); exit;
}

// ── Datos ─────────────────────────────────────────────────────
$rawParticipaciones = $db->prepare(
    'SELECT cp.*, j.nombre, j.tag
     FROM cwl_participaciones cp
     JOIN jugadores j ON cp.jugador_id = j.id
     WHERE cp.temporada_id = ?
     ORDER BY j.nombre ASC, cp.dia ASC'
);
$rawParticipaciones->execute([$id]);
$rawParticipaciones = $rawParticipaciones->fetchAll();

// Agrupar por jugador
$jugadores = [];
foreach ($rawParticipaciones as $row) {
    $jid = $row['jugador_id'];
    if (!isset($jugadores[$jid])) {
        $jugadores[$jid] = ['nombre' => $row['nombre'], 'tag' => $row['tag'], 'dias' => []];
    }
    $jugadores[$jid]['dias'][$row['dia']] = $row;
}

// Jugadores disponibles
$jugadoresDisp = $db->prepare(
    'SELECT id, nombre, tag FROM jugadores
     WHERE activo = 1 AND id NOT IN (SELECT DISTINCT jugador_id FROM cwl_participaciones WHERE temporada_id = ?)
     ORDER BY nombre ASC'
);
$jugadoresDisp->execute([$id]);
$jugadoresDisp = $jugadoresDisp->fetchAll();

$pageTitle = 'CWL — ' . $temp['mes'];
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-trophy-fill"></i> CWL <?= clean($temp['mes']) ?></h1>
    <a href="cwl" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="stat-card"><div class="stat-icon">🏆</div><div class="stat-value"><?= clean($temp['liga'] ?? '—') ?></div><div class="stat-label">Liga</div></div></div>
    <div class="col-md-4"><div class="stat-card"><div class="stat-icon">🏅</div><div class="stat-value"><?= $temp['posicion_final'] ? $temp['posicion_final'] . '°' : '—' ?></div><div class="stat-label">Posición Final</div></div></div>
    <div class="col-md-4"><div class="stat-card"><div class="stat-icon">👥</div><div class="stat-value"><?= count($jugadores) ?></div><div class="stat-label">Jugadores en Roster</div></div></div>
</div>

<?php if (!empty($jugadoresDisp)): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-person-plus"></i> Agregar al Roster</span>
        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#addPlayersForm"><i class="bi bi-plus-lg"></i></button>
    </div>
    <div class="collapse" id="addPlayersForm"><div class="card-body">
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="add_players" value="1">
            <div class="row g-2 align-items-end">
                <div class="col-md-8">
                    <select name="jugador_ids[]" class="form-select" multiple size="5">
                        <?php foreach ($jugadoresDisp as $j): ?>
                            <option value="<?= $j['id'] ?>"><?= clean($j['nombre']) ?> (<?= clean($j['tag']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4"><button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i> Agregar</button></div>
            </div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<?php if (empty($jugadores)): ?>
    <div class="empty-state"><div class="empty-icon">🏆</div><p>No hay jugadores en el roster de esta temporada.</p></div>
<?php else: ?>
    <form method="POST">
        <?= csrfField() ?><input type="hidden" name="save_participation" value="1">
        <div class="card"><div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:.85rem">
                <thead>
                    <tr>
                        <th>Jugador</th>
                        <?php for ($dia = 1; $dia <= 7; $dia++): ?>
                            <th class="text-center">Día <?= $dia ?></th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jugadores as $jid => $jdata): ?>
                    <tr>
                        <td><strong><?= clean($jdata['nombre']) ?></strong></td>
                        <?php for ($dia = 1; $dia <= 7; $dia++):
                            $d = $jdata['dias'][$dia] ?? null;
                        ?>
                        <td class="text-center">
                            <div class="form-check form-check-inline mb-1">
                                <input type="checkbox" name="participo[<?= $jid ?>][<?= $dia ?>]" value="1"
                                       class="form-check-input" <?= ($d && $d['participo']) ? 'checked' : '' ?>>
                            </div>
                            <input type="number" name="estrellas[<?= $jid ?>][<?= $dia ?>]" class="form-control form-control-sm mb-1"
                                   min="0" max="3" placeholder="⭐" style="width:60px;display:inline-block"
                                   value="<?= $d['estrellas'] ?? '' ?>">
                            <input type="number" name="porcentaje[<?= $jid ?>][<?= $dia ?>]" class="form-control form-control-sm"
                                   min="0" max="100" step="0.01" placeholder="%" style="width:65px;display:inline-block"
                                   value="<?= $d['porcentaje'] ?? '' ?>">
                        </td>
                        <?php endfor; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
        <div class="mt-4 text-center">
            <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> Guardar CWL</button>
        </div>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
