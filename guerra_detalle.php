<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db = getDB();
$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: guerras');
    exit;
}

// Obtener guerra
$stmt = $db->prepare('SELECT * FROM guerras WHERE id = ?');
$stmt->execute([$id]);
$guerra = $stmt->fetch();

if (!$guerra) {
    setFlash('error', 'Guerra no encontrada.');
    header('Location: guerras');
    exit;
}

// ── Agregar jugadores ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_players'])) {
    verifyCsrf();
    $playerIds = $_POST['jugador_ids'] ?? [];

    if (!empty($playerIds)) {
        $stmt = $db->prepare(
            'INSERT IGNORE INTO guerra_participaciones (guerra_id, jugador_id) VALUES (?, ?)'
        );
        foreach ($playerIds as $pid) {
            $stmt->execute([$id, (int) $pid]);
        }
        logActivity('crear', 'guerra_participaciones', $id, 'Agregados ' . count($playerIds) . ' jugadores');
        setFlash('success', count($playerIds) . ' jugador(es) agregado(s).');
    }
    header('Location: guerra_detalle?id=' . $id);
    exit;
}

// ── Guardar participaciones masivo ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_participation'])) {
    verifyCsrf();

    $a1est  = $_POST['ataque1_estrellas'] ?? [];
    $a1pct  = $_POST['ataque1_porcentaje'] ?? [];
    $a2est  = $_POST['ataque2_estrellas'] ?? [];
    $a2pct  = $_POST['ataque2_porcentaje'] ?? [];
    $defest = $_POST['defensa_estrellas'] ?? [];
    $defpct = $_POST['defensa_porcentaje'] ?? [];

    $stmt = $db->prepare(
        'UPDATE guerra_participaciones
         SET ataque1_estrellas=?, ataque1_porcentaje=?, ataque2_estrellas=?, ataque2_porcentaje=?,
             defensa_estrellas=?, defensa_porcentaje=?
         WHERE guerra_id=? AND jugador_id=?'
    );

    foreach ($a1est as $jid => $val) {
        $stmt->execute([
            $val !== '' ? (int) $val : null,
            ($a1pct[$jid] ?? '') !== '' ? (float) $a1pct[$jid] : null,
            ($a2est[$jid] ?? '') !== '' ? (int) $a2est[$jid] : null,
            ($a2pct[$jid] ?? '') !== '' ? (float) $a2pct[$jid] : null,
            ($defest[$jid] ?? '') !== '' ? (int) $defest[$jid] : null,
            ($defpct[$jid] ?? '') !== '' ? (float) $defpct[$jid] : null,
            $id,
            (int) $jid,
        ]);
    }

    logActivity('editar', 'guerra_participaciones', $id, 'Participaciones actualizadas');
    setFlash('success', 'Participaciones guardadas.');
    header('Location: guerra_detalle?id=' . $id);
    exit;
}

// ── Eliminar jugador de la guerra ─────────────────────────────
if (isset($_GET['remove']) && isset($_GET['csrf_token'])) {
    verifyCsrf();
    $removeId = (int) $_GET['remove'];
    $db->prepare('DELETE FROM guerra_participaciones WHERE guerra_id=? AND jugador_id=?')
       ->execute([$id, $removeId]);
    logActivity('eliminar', 'guerra_participaciones', $id, 'Jugador removido: ' . $removeId);
    setFlash('success', 'Jugador removido de la guerra.');
    header('Location: guerra_detalle?id=' . $id);
    exit;
}

// ── Datos ─────────────────────────────────────────────────────
$participaciones = $db->prepare(
    'SELECT gp.*, j.usuario, j.usuario
     FROM guerra_participaciones gp
     JOIN jugadores j ON gp.jugador_id = j.id
     WHERE gp.guerra_id = ?
     ORDER BY j.usuario ASC'
);
$participaciones->execute([$id]);
$participaciones = $participaciones->fetchAll();

// Jugadores activos no en esta guerra
$jugadoresDisp = $db->prepare(
    'SELECT id, usuario FROM jugadores
     WHERE activo = 1 AND id NOT IN (SELECT jugador_id FROM guerra_participaciones WHERE guerra_id = ?)
     ORDER BY usuario ASC'
);
$jugadoresDisp->execute([$id]);
$jugadoresDisp = $jugadoresDisp->fetchAll();

// Resumen
$totalEstrellas = 0;
$totalPct = 0;
$ataques = 0;
foreach ($participaciones as $p) {
    if ($p['ataque1_estrellas'] !== null) { $totalEstrellas += $p['ataque1_estrellas']; $totalPct += $p['ataque1_porcentaje']; $ataques++; }
    if ($p['ataque2_estrellas'] !== null) { $totalEstrellas += $p['ataque2_estrellas']; $totalPct += $p['ataque2_porcentaje']; $ataques++; }
}
$avgPct = $ataques > 0 ? round($totalPct / $ataques, 1) : 0;

$resBadge = [
    'victoria' => 'badge-green',
    'derrota'  => 'badge-red',
    'empate'   => 'badge-blue',
    'en_curso' => 'badge-gold',
];

$pageTitle = 'Guerra vs ' . $guerra['oponente'];
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-lightning-fill"></i> <?= clean($pageTitle) ?></h1>
    <a href="guerras" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
</div>

<!-- Header de la guerra -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon">📅</div>
            <div class="stat-value"><?= date('d/m', strtotime($guerra['fecha'])) ?></div>
            <div class="stat-label">Fecha</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon">
                <span class="badge <?= $resBadge[$guerra['resultado']] ?? 'badge-muted' ?>" style="font-size:1rem">
                    <?= ucfirst(str_replace('_', ' ', $guerra['resultado'])) ?>
                </span>
            </div>
            <div class="stat-value"><?= (int) $guerra['estrellas_clan'] ?> - <?= (int) $guerra['estrellas_oponente'] ?></div>
            <div class="stat-label">Estrellas</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon">⭐</div>
            <div class="stat-value"><?= $totalEstrellas ?></div>
            <div class="stat-label">Estrellas logradas</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon">💥</div>
            <div class="stat-value"><?= $avgPct ?>%</div>
            <div class="stat-label">Promedio Destrucción</div>
        </div>
    </div>
</div>

<!-- Agregar jugadores -->
<?php if (!empty($jugadoresDisp)): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-person-plus"></i> Agregar Jugadores</span>
        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#addPlayersForm">
            <i class="bi bi-plus-lg"></i>
        </button>
    </div>
    <div class="collapse" id="addPlayersForm">
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="add_players" value="1">
                <div class="row g-2 align-items-end">
                    <div class="col-md-8">
                        <label class="form-label">Seleccionar jugadores</label>
                        <select name="jugador_ids[]" class="form-select" multiple size="5">
                            <?php foreach ($jugadoresDisp as $j): ?>
                                <option value="<?= $j['id'] ?>"><?= clean($j['usuario']) ?> (<?= clean($j['usuario']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Ctrl+Click para seleccionar varios</small>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i> Agregar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Participaciones -->
<?php if (empty($participaciones)): ?>
    <div class="empty-state">
        <div class="empty-icon">🗡️</div>
        <p>No hay jugadores en esta guerra.</p>
    </div>
<?php else: ?>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="save_participation" value="1">

        <div class="row g-3">
            <?php foreach ($participaciones as $p): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="participation-card">
                        <div class="player-name d-flex justify-content-between align-items-center">
                            <span><?= clean($p['usuario']) ?> <small class="text-muted"><?= clean($p['usuario']) ?></small></span>
                            <a href="guerra_detalle?id=<?= $id ?>&remove=<?= $p['jugador_id'] ?>&csrf_token=<?= csrfToken() ?>"
                               class="btn btn-sm btn-danger py-0 px-1" title="Remover"
                               data-confirm="¿Remover a <?= clean($p['usuario']) ?>?">
                                <i class="bi bi-x"></i>
                            </a>
                        </div>

                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label">Ataque 1 ⭐</label>
                                <select name="ataque1_estrellas[<?= $p['jugador_id'] ?>]" class="form-select">
                                    <option value="">—</option>
                                    <?php for ($s = 0; $s <= 3; $s++): ?>
                                        <option value="<?= $s ?>" <?= $p['ataque1_estrellas'] !== null && (int)$p['ataque1_estrellas'] === $s ? 'selected' : '' ?>><?= $s ?>⭐</option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label">% Destrucción</label>
                                <input type="number" name="ataque1_porcentaje[<?= $p['jugador_id'] ?>]" class="form-control"
                                       min="0" max="100" step="0.01" value="<?= $p['ataque1_porcentaje'] ?? '' ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Ataque 2 ⭐</label>
                                <select name="ataque2_estrellas[<?= $p['jugador_id'] ?>]" class="form-select">
                                    <option value="">—</option>
                                    <?php for ($s = 0; $s <= 3; $s++): ?>
                                        <option value="<?= $s ?>" <?= $p['ataque2_estrellas'] !== null && (int)$p['ataque2_estrellas'] === $s ? 'selected' : '' ?>><?= $s ?>⭐</option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label">% Destrucción</label>
                                <input type="number" name="ataque2_porcentaje[<?= $p['jugador_id'] ?>]" class="form-control"
                                       min="0" max="100" step="0.01" value="<?= $p['ataque2_porcentaje'] ?? '' ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Defensa ⭐</label>
                                <select name="defensa_estrellas[<?= $p['jugador_id'] ?>]" class="form-select">
                                    <option value="">—</option>
                                    <?php for ($s = 0; $s <= 3; $s++): ?>
                                        <option value="<?= $s ?>" <?= $p['defensa_estrellas'] !== null && (int)$p['defensa_estrellas'] === $s ? 'selected' : '' ?>><?= $s ?>⭐</option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label">% Defensa</label>
                                <input type="number" name="defensa_porcentaje[<?= $p['jugador_id'] ?>]" class="form-control"
                                       min="0" max="100" step="0.01" value="<?= $p['defensa_porcentaje'] ?? '' ?>">
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-4 text-center">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="bi bi-check-lg"></i> Guardar Todas las Participaciones
            </button>
        </div>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
