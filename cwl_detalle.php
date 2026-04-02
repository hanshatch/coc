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
    'SELECT cp.*, j.usuario
     FROM cwl_participaciones cp
     JOIN jugadores j ON cp.jugador_id = j.id
     WHERE cp.temporada_id = ?
     ORDER BY j.usuario ASC, cp.dia ASC'
);
$rawParticipaciones->execute([$id]);
$rawParticipaciones = $rawParticipaciones->fetchAll();

// Agrupar por jugador
$jugadores = [];
foreach ($rawParticipaciones as $row) {
    $jid = $row['jugador_id'];
    if (!isset($jugadores[$jid])) {
        $jugadores[$jid] = ['nombre' => $row['usuario'], 'tag' => $row['usuario'], 'dias' => []];
    }
    $jugadores[$jid]['dias'][$row['dia']] = $row;
}

// Jugadores disponibles
$jugadoresDisp = $db->prepare(
    'SELECT id, usuario FROM jugadores
     WHERE activo = 1 AND id NOT IN (SELECT DISTINCT jugador_id FROM cwl_participaciones WHERE temporada_id = ?)
     ORDER BY usuario ASC'
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
<div class="card mb-4 mb-5">
    <div class="card-header d-flex justify-content-between align-items-center bg-surface2">
        <span class="text-gold"><i class="bi bi-person-plus-fill"></i> Seleccionar Participantes</span>
        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#addPlayersForm">
            <i class="bi bi-chevron-down"></i> Panel de Selección
        </button>
    </div>
    <div class="collapse" id="addPlayersForm">
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="add_players" value="1">
                
                <!-- Buscador y Acciones -->
                <div class="row g-2 mb-3">
                    <div class="col-md-8">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-surface border-secondary text-muted"><i class="bi bi-search"></i></span>
                            <input type="text" id="playerSearch" class="form-control bg-surface border-secondary text-white" placeholder="Filtrar jugadores por usuario...">
                        </div>
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100" onclick="toggleAllPlayers(true)">Todos</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100" onclick="toggleAllPlayers(false)">Ninguno</button>
                    </div>
                </div>

                <!-- Rejilla de Jugadores -->
                <div class="player-selection-grid mb-3" style="max-height: 250px; overflow-y: auto;">
                    <div class="row g-2" id="playerGrid">
                        <?php foreach ($jugadoresDisp as $j): ?>
                            <div class="col-6 col-md-4 col-lg-3 player-item" data-name="<?= strtolower(clean($j['usuario'])) ?>">
                                <div class="player-checkbox-card">
                                    <input type="checkbox" name="jugador_ids[]" value="<?= $j['id'] ?>" id="p_<?= $j['id'] ?>" class="btn-check">
                                    <label class="btn btn-outline-surface w-100 text-start text-truncate" for="p_<?= $j['id'] ?>">
                                        <i class="bi bi-person"></i> <?= clean($j['usuario']) ?>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="text-end">
                    <button type="submit" class="btn btn-primary px-4" style="background-color: var(--ct-gold); border-color: var(--ct-gold); color: #000; font-weight: bold;">
                        <i class="bi bi-plus-lg"></i> AGREGAR AL ESCUADRÓN
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('playerSearch')?.addEventListener('input', function(e) {
    const q = e.target.value.toLowerCase();
    document.querySelectorAll('.player-item').forEach(item => {
        const name = item.getAttribute('data-name');
        item.style.display = name.includes(q) ? 'block' : 'none';
    });
});

function toggleAllPlayers(checked) {
    document.querySelectorAll('#playerGrid input[type="checkbox"]').forEach(cb => {
        if (cb.closest('.player-item').style.display !== 'none') {
            cb.checked = checked;
        }
    });
}
</script>

<style>
.btn-outline-surface {
    color: var(--ct-text);
    border-color: var(--ct-border);
    background: var(--ct-surface);
    font-size: 0.9rem;
}
.btn-check:checked + .btn-outline-surface {
    background: rgba(245, 158, 11, 0.15);
    border-color: var(--ct-gold);
    color: var(--ct-gold);
}
.player-selection-grid::-webkit-scrollbar { width: 6px; }
.player-selection-grid::-webkit-scrollbar-thumb { background: var(--ct-border); border-radius: 10px; }
.text-gold { color: var(--ct-gold) !important; }
</style>
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
                        <td><strong><?= clean($jdata['usuario']) ?></strong></td>
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
