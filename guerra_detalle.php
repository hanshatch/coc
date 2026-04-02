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

    $stmt = $db->prepare(
        'UPDATE guerra_participaciones
         SET ataque1_estrellas=?, ataque1_porcentaje=?, ataque2_estrellas=?, ataque2_porcentaje=?
         WHERE guerra_id=? AND jugador_id=?'
    );

    foreach ($a1est as $jid => $val) {
        $stmt->execute([
            $val !== '' ? (int) $val : null,
            ($a1pct[$jid] ?? '') !== '' ? (float) $a1pct[$jid] : null,
            ($a2est[$jid] ?? '') !== '' ? (int) $a2est[$jid] : null,
            ($a2pct[$jid] ?? '') !== '' ? (float) $a2pct[$jid] : null,
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
    <div class="col">
        <div class="stat-card">
            <div class="stat-icon">📅</div>
            <div class="stat-value"><?= date('d/m', strtotime($guerra['fecha'])) ?></div>
            <div class="stat-label">Fecha</div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card">
            <div class="stat-icon">👥</div>
            <div class="stat-value">
                <?= count($participaciones) ?> / <?= (int) $guerra['tamano'] ?>
            </div>
            <div class="stat-label">
                Jugadores 
                <?php if (count($participaciones) != $guerra['tamano']): ?>
                    <span class="badge bg-danger ms-1">¡Incompleto!</span>
                <?php else: ?>
                    <span class="badge bg-success ms-1">OK</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card">
            <div class="stat-icon">
                <span class="badge <?= $resBadge[$guerra['resultado']] ?? 'badge-muted' ?>" style="font-size:1rem">
                    <?= ucfirst(str_replace('_', ' ', $guerra['resultado'])) ?>
                </span>
            </div>
            <div class="stat-value"><?= (int) $guerra['estrellas_clan'] ?> - <?= (int) $guerra['estrellas_oponente'] ?></div>
            <div class="stat-label">Estrellas Marcador</div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card">
            <div class="stat-icon">⭐</div>
            <div class="stat-value"><?= $totalEstrellas ?></div>
            <div class="stat-label">Estrellas logradas</div>
        </div>
    </div>
    <div class="col">
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
                    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-plus-lg"></i> Agregar al Escuadrón</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('playerSearch').addEventListener('input', function(e) {
    const q = e.target.value.toLowerCase();
    document.querySelectorAll('.player-item').forEach(item => {
        const name = item.getAttribute('data-name');
        item.style.display = name.includes(q) ? 'block' : 'none';
    });
});

function toggleAllPlayers(checked) {
    document.querySelectorAll('#playerGrid input[type="checkbox"]').forEach(cb => {
        if (cb.parentElement.parentElement.style.display !== 'none') {
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
    background: rgba(238, 179, 75, 0.15);
    border-color: var(--ct-gold);
    color: var(--ct-gold);
}
.player-selection-grid::-webkit-scrollbar {
    width: 6px;
}
.player-selection-grid::-webkit-scrollbar-thumb {
    background: var(--ct-border);
    border-radius: 10px;
}
.sortable { cursor: pointer; user-select: none; }
.sortable:hover { background: rgba(255,255,255,0.05); }
.text-gold { color: var(--ct-gold) !important; }
</style>
<?php endif; ?>

<!-- Participaciones -->
<?php if (empty($participaciones)): ?>
    <div class="empty-state">
        <div class="empty-icon">🗡️</div>
        <p>No hay jugadores en esta guerra.</p>
    </div>
<?php else: ?>
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-surface border-secondary text-muted"><i class="bi bi-funnel"></i></span>
                        <input type="text" id="participationFilter" class="form-control bg-surface border-secondary text-white" placeholder="Filtrar por jugador...">
                    </div>
                </div>
                <div class="col-md-6 text-end d-none d-md-block">
                    <span class="text-muted small">Mostrando <span id="visibleCount"><?= count($participaciones) ?></span> de <?= count($participaciones) ?> jugadores</span>
                </div>
            </div>
        </div>
    </div>

    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="save_participation" value="1">

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="participationTable" data-sort-col="" data-sort-dir="desc">
                    <thead>
                        <tr>
                            <th onclick="sortPartTable(0)" class="sortable text-nowrap">
                                Jugador <i class="bi bi-arrow-down-up small ms-1 text-muted"></i>
                            </th>
                            <th onclick="sortPartTable(1)" class="sortable text-nowrap" style="width: 250px;">
                                Ataque 1 <i class="bi bi-arrow-down-up small ms-1 text-muted"></i>
                            </th>
                            <th onclick="sortPartTable(2)" class="sortable text-nowrap" style="width: 250px;">
                                Ataque 2 <i class="bi bi-arrow-down-up small ms-1 text-muted"></i>
                            </th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($participaciones as $p): ?>
                        <tr class="participation-row" data-name="<?= strtolower(clean($p['usuario'])) ?>">
                            <td>
                                <div class="fw-bold"><?= clean($p['usuario']) ?></div>
                                <small class="text-muted"><?= clean($p['usuario']) ?></small>
                            </td>
                            <td>
                                <div class="row g-1">
                                    <div class="col-6">
                                        <select name="ataque1_estrellas[<?= $p['jugador_id'] ?>]" class="form-select form-select-sm">
                                            <option value="">—</option>
                                            <?php for ($s = 0; $s <= 3; $s++): ?>
                                                <option value="<?= $s ?>" <?= $p['ataque1_estrellas'] !== null && (int)$p['ataque1_estrellas'] === $s ? 'selected' : '' ?>><?= $s ?> ⭐</option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <div class="input-group input-group-sm">
                                            <input type="number" name="ataque1_porcentaje[<?= $p['jugador_id'] ?>]" class="form-control"
                                                   min="0" max="100" step="0.01" value="<?= $p['ataque1_porcentaje'] ?? '' ?>" placeholder="%">
                                            <span class="input-group-text px-1">%</span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="row g-1">
                                    <div class="col-6">
                                        <select name="ataque2_estrellas[<?= $p['jugador_id'] ?>]" class="form-select form-select-sm">
                                            <option value="">—</option>
                                            <?php for ($s = 0; $s <= 3; $s++): ?>
                                                <option value="<?= $s ?>" <?= $p['ataque2_estrellas'] !== null && (int)$p['ataque2_estrellas'] === $s ? 'selected' : '' ?>><?= $s ?> ⭐</option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <div class="input-group input-group-sm">
                                            <input type="number" name="ataque2_porcentaje[<?= $p['jugador_id'] ?>]" class="form-control"
                                                   min="0" max="100" step="0.01" value="<?= $p['ataque2_porcentaje'] ?? '' ?>" placeholder="%">
                                            <span class="input-group-text px-1">%</span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="text-end">
                                <a href="guerra_detalle?id=<?= $id ?>&remove=<?= $p['jugador_id'] ?>&csrf_token=<?= csrfToken() ?>"
                                   class="btn btn-sm btn-outline-danger" title="Remover"
                                   data-confirm="¿Remover a <?= clean($p['usuario']) ?> de la guerra?">
                                    <i class="bi bi-x-lg"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4 text-center">
            <button type="submit" class="btn btn-primary btn-lg px-5">
                <i class="bi bi-cloud-upload"></i> Guardar Participaciones
            </button>
        </div>
    </form>

    <script>
    document.getElementById('participationFilter').addEventListener('input', function(e) {
        const q = e.target.value.toLowerCase();
        let visible = 0;
        document.querySelectorAll('.participation-row').forEach(row => {
            const name = row.getAttribute('data-name');
            if (name.includes(q)) {
                row.style.display = '';
                visible++;
            } else {
                row.style.display = 'none';
            }
        });
        document.getElementById('visibleCount').textContent = visible;
    });

    function sortPartTable(columnIndex) {
        const table = document.getElementById('participationTable');
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('.participation-row'));
        const currentDir = table.getAttribute('data-sort-dir') || 'desc';
        const currentCol = table.getAttribute('data-sort-col');
        
        let newDir = 'desc';
        if (currentCol == columnIndex) {
            newDir = (currentDir === 'asc') ? 'desc' : 'asc';
        } else {
            // Default to desc for attacks, asc for name
            newDir = (columnIndex === 0) ? 'asc' : 'desc';
        }
        
        table.setAttribute('data-sort-dir', newDir);
        table.setAttribute('data-sort-col', columnIndex);

        // Update icons
        table.querySelectorAll('thead th i').forEach((icon, i) => {
            icon.className = 'bi bi-arrow-down-up small ms-1 text-muted';
            if (i == columnIndex) {
                icon.className = newDir === 'asc' ? 'bi bi-sort-up small ms-1 text-gold' : 'bi bi-sort-down small ms-1 text-gold';
            }
        });

        rows.sort((a, b) => {
            let valA, valB;
            if (columnIndex === 0) {
                valA = a.querySelector('.fw-bold').textContent.trim().toLowerCase();
                valB = b.querySelector('.fw-bold').textContent.trim().toLowerCase();
            } else {
                const attackNum = columnIndex;
                const starsSelect  = a.querySelector(`select[name^="ataque${attackNum}_estrellas"]`);
                const pctInput     = a.querySelector(`input[name^="ataque${attackNum}_porcentaje"]`);
                const starsSelectB = b.querySelector(`select[name^="ataque${attackNum}_estrellas"]`);
                const pctInputB    = b.querySelector(`input[name^="ataque${attackNum}_porcentaje"]`);

                // We handle nulls as -1 to put them at the bottom if sorting desc
                const starsA = starsSelect.value === "" ? -1 : parseInt(starsSelect.value);
                const pctA   = parseFloat(pctInput.value) || 0;
                const starsB = starsSelectB.value === "" ? -1 : parseInt(starsSelectB.value);
                const pctB   = parseFloat(pctInputB.value) || 0;

                valA = (starsA * 1000) + pctA;
                valB = (starsB * 1000) + pctB;
            }

            if (valA === valB) return 0;
            const res = valA < valB ? -1 : 1;
            return newDir === 'asc' ? res : -res;
        });

        rows.forEach(row => tbody.appendChild(row));
    }
    </script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
