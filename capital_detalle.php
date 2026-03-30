<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db = getDB();
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: capital'); exit; }

$stmt = $db->prepare('SELECT * FROM capital_semanas WHERE id = ?');
$stmt->execute([$id]);
$semana = $stmt->fetch();
if (!$semana) { setFlash('error', 'No encontrada.'); header('Location: capital'); exit; }

// ── Agregar jugadores ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_players'])) {
    verifyCsrf();
    $playerIds = $_POST['jugador_ids'] ?? [];
    if (!empty($playerIds)) {
        $stmt = $db->prepare('INSERT IGNORE INTO capital_participaciones (semana_id, jugador_id) VALUES (?, ?)');
        foreach ($playerIds as $pid) { $stmt->execute([$id, (int) $pid]); }
        logActivity('crear', 'capital_participaciones', $id, 'Agregados ' . count($playerIds) . ' jugadores');
        setFlash('success', count($playerIds) . ' jugador(es) agregado(s).');
    }
    header('Location: capital_detalle?id=' . $id); exit;
}

// ── Guardar participaciones ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_participation'])) {
    verifyCsrf();
    $oro      = $_POST['oro_aportado'] ?? [];
    $ataques  = $_POST['ataques_realizados'] ?? [];
    $medallas = $_POST['medallas_obtenidas'] ?? [];

    $stmtUp = $db->prepare(
        'UPDATE capital_participaciones SET oro_aportado=?, ataques_realizados=?, medallas_obtenidas=? WHERE semana_id=? AND jugador_id=?'
    );

    $totalOro = 0;
    foreach ($oro as $jid => $val) {
        $val      = (int) $val;
        $ats      = (int) ($ataques[$jid] ?? 0);
        $med      = (int) ($medallas[$jid] ?? 0);
        $stmtUp->execute([$val, $ats, $med, $id, (int) $jid]);
        $totalOro += $val;
    }

    // Actualizar total en semana
    $db->prepare('UPDATE capital_semanas SET oro_total_recaudado=? WHERE id=?')->execute([$totalOro, $id]);

    logActivity('editar', 'capital_participaciones', $id, "Oro total: $totalOro");
    setFlash('success', 'Participaciones de capital guardadas.');
    header('Location: capital_detalle?id=' . $id); exit;
}

// ── Datos ─────────────────────────────────────────────────────
$participaciones = $db->prepare(
    'SELECT cp.*, j.usuario, j.usuario
     FROM capital_participaciones cp
     JOIN jugadores j ON cp.jugador_id = j.id
     WHERE cp.semana_id = ?
     ORDER BY cp.oro_aportado DESC, j.usuario ASC'
);
$participaciones->execute([$id]);
$participaciones = $participaciones->fetchAll();

$jugadoresDisp = $db->prepare(
    'SELECT id, nombre, tag FROM jugadores
     WHERE activo = 1 AND id NOT IN (SELECT jugador_id FROM capital_participaciones WHERE semana_id = ?)
     ORDER BY nombre ASC'
);
$jugadoresDisp->execute([$id]);
$jugadoresDisp = $jugadoresDisp->fetchAll();

$pageTitle = 'Capital — ' . date('d/m/Y', strtotime($semana['fecha_inicio']));
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-building-fill"></i> Semana de Raid</h1>
    <a href="capital" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="stat-card"><div class="stat-icon">💰</div><div class="stat-value"><?= number_format(array_sum(array_column($participaciones, 'oro_aportado'))) ?></div><div class="stat-label">Oro Aportado</div></div></div>
    <div class="col-md-3"><div class="stat-card"><div class="stat-icon">⚔️</div><div class="stat-value"><?= (int) $semana['ataques_totales'] ?></div><div class="stat-label">Ataques Totales</div></div></div>
    <div class="col-md-3"><div class="stat-card"><div class="stat-icon">🏘️</div><div class="stat-value"><?= (int) $semana['distritos_destruidos'] ?></div><div class="stat-label">Distritos Destruidos</div></div></div>
    <div class="col-md-3"><div class="stat-card"><div class="stat-icon">👥</div><div class="stat-value"><?= count($participaciones) ?></div><div class="stat-label">Participantes</div></div></div>
</div>

<?php if (!empty($jugadoresDisp)): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-person-plus"></i> Agregar Jugadores</span>
        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#addPlayersForm"><i class="bi bi-plus-lg"></i></button>
    </div>
    <div class="collapse" id="addPlayersForm"><div class="card-body">
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="add_players" value="1">
            <div class="row g-2 align-items-end">
                <div class="col-md-8">
                    <select name="jugador_ids[]" class="form-select" multiple size="5">
                        <?php foreach ($jugadoresDisp as $j): ?><option value="<?= $j['id'] ?>"><?= clean($j['usuario']) ?> (<?= clean($j['usuario']) ?>)</option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4"><button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i> Agregar</button></div>
            </div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<?php if (empty($participaciones)): ?>
    <div class="empty-state"><div class="empty-icon">🏰</div><p>No hay registro de participaciones aún.</p></div>
<?php else: ?>
    <form method="POST">
        <?= csrfField() ?><input type="hidden" name="save_participation" value="1">
        <div class="row g-3">
            <?php foreach ($participaciones as $p): ?>
            <div class="col-md-6 col-lg-4">
                <div class="participation-card">
                    <div class="player-name"><?= clean($p['usuario']) ?></div>
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label">Oro Aportado</label>
                            <input type="number" name="oro_aportado[<?= $p['jugador_id'] ?>]" class="form-control" min="0" value="<?= (int) $p['oro_aportado'] ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Ataques</label>
                            <input type="number" name="ataques_realizados[<?= $p['jugador_id'] ?>]" class="form-control" min="0" max="6" value="<?= (int) $p['ataques_realizados'] ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Medallas</label>
                            <input type="number" name="medallas_obtenidas[<?= $p['jugador_id'] ?>]" class="form-control" min="0" value="<?= (int) $p['medallas_obtenidas'] ?>">
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="mt-4 text-center">
            <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> Guardar Capital</button>
        </div>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
