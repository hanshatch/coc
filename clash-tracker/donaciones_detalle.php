<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db = getDB();
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: donaciones.php'); exit; }

$stmt = $db->prepare('SELECT * FROM donaciones_periodos WHERE id = ?');
$stmt->execute([$id]);
$periodo = $stmt->fetch();
if (!$periodo) { setFlash('error', 'Periodo no encontrado.'); header('Location: donaciones.php'); exit; }

// ── Agregar jugadores ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_players'])) {
    verifyCsrf();
    $playerIds = $_POST['jugador_ids'] ?? [];
    if (!empty($playerIds)) {
        $stmt = $db->prepare('INSERT IGNORE INTO donaciones (periodo_id, jugador_id) VALUES (?, ?)');
        foreach ($playerIds as $pid) { $stmt->execute([$id, (int) $pid]); }
        logActivity('crear', 'donaciones', $id, 'Agregados ' . count($playerIds) . ' jugadores');
        setFlash('success', count($playerIds) . ' jugador(es) agregado(s).');
    }
    header('Location: donaciones_detalle.php?id=' . $id); exit;
}

// ── Guardar participaciones ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_participation'])) {
    verifyCsrf();
    $donadas   = $_POST['tropas_donadas'] ?? [];
    $recibidas = $_POST['tropas_recibidas'] ?? [];

    $stmtUp = $db->prepare(
        'UPDATE donaciones SET tropas_donadas=?, tropas_recibidas=? WHERE periodo_id=? AND jugador_id=?'
    );

    foreach ($donadas as $jid => $don) {
        $rec = (int) ($recibidas[$jid] ?? 0);
        $stmtUp->execute([(int) $don, $rec, $id, (int) $jid]);
    }

    logActivity('editar', 'donaciones', $id, 'Donaciones actualizadas');
    setFlash('success', 'Registro de donaciones guardado.');
    header('Location: donaciones_detalle.php?id=' . $id); exit;
}

// ── Datos ─────────────────────────────────────────────────────
$participaciones = $db->prepare(
    'SELECT d.*, j.nombre, j.tag
     FROM donaciones d
     JOIN jugadores j ON d.jugador_id = j.id
     WHERE d.periodo_id = ?
     ORDER BY d.tropas_donadas DESC, j.nombre ASC'
);
$participaciones->execute([$id]);
$participaciones = $participaciones->fetchAll();

$jugadoresDisp = $db->prepare(
    'SELECT id, nombre, tag FROM jugadores
     WHERE activo = 1 AND id NOT IN (SELECT jugador_id FROM donaciones WHERE periodo_id = ?)
     ORDER BY nombre ASC'
);
$jugadoresDisp->execute([$id]);
$jugadoresDisp = $jugadoresDisp->fetchAll();

$pageTitle = 'Donaciones — ' . date('d/m/Y', strtotime($periodo['fecha_inicio']));
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-gift-fill"></i> Donaciones</h1>
    <a href="donaciones.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6"><div class="stat-card"><div class="stat-icon">📥</div><div class="stat-value"><?= number_format(array_sum(array_column($participaciones, 'tropas_donadas'))) ?></div><div class="stat-label">Tropas Donadas totales</div></div></div>
    <div class="col-md-6"><div class="stat-card"><div class="stat-icon">👥</div><div class="stat-value"><?= count($participaciones) ?></div><div class="stat-label">Participantes</div></div></div>
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
                        <?php foreach ($jugadoresDisp as $j): ?><option value="<?= $j['id'] ?>"><?= clean($j['nombre']) ?> (<?= clean($j['tag']) ?>)</option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4"><button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i> Agregar</button></div>
            </div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<?php if (empty($participaciones)): ?>
    <div class="empty-state"><div class="empty-icon">🎁</div><p>No hay registro de donaciones aún.</p></div>
<?php else: ?>
    <form method="POST">
        <?= csrfField() ?><input type="hidden" name="save_participation" value="1">
        <div class="card"><div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr><th>Jugador</th><th>Donadas</th><th>Recibidas</th><th>Ratio (D/R)</th><th class="text-end">Acciones</th></tr></thead>
                <tbody>
                    <?php foreach ($participaciones as $p):
                        $ratio = $p['tropas_recibidas'] > 0 ? round($p['tropas_donadas'] / $p['tropas_recibidas'], 2) : $p['tropas_donadas'];
                        $ratioColor = $ratio >= 1 ? 'var(--ct-green)' : ($ratio < 0.5 ? 'var(--ct-red)' : 'var(--ct-text)');
                    ?>
                    <tr>
                        <td><strong><?= clean($p['nombre']) ?></strong> <small class="text-muted"><?= clean($p['tag']) ?></small></td>
                        <td><input type="number" name="tropas_donadas[<?= $p['jugador_id'] ?>]" class="form-control form-control-sm" min="0" value="<?= (int) $p['tropas_donadas'] ?>" style="width:100px"></td>
                        <td><input type="number" name="tropas_recibidas[<?= $p['jugador_id'] ?>]" class="form-control form-control-sm" min="0" value="<?= (int) $p['tropas_recibidas'] ?>" style="width:100px"></td>
                        <td><span style="color:<?= $ratioColor ?>;font-weight:600"><?= $ratio ?></span></td>
                        <td class="text-end">
                            <a href="donaciones_detalle.php?id=<?= $id ?>&remove=<?= $p['jugador_id'] ?>&csrf_token=<?= csrfToken() ?>"
                               class="btn btn-sm btn-danger py-0 px-1" title="Remover" data-confirm="¿Remover de este periodo?">
                                <i class="bi bi-x"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
        <div class="mt-4 text-center">
            <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> Guardar Donaciones</button>
        </div>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
