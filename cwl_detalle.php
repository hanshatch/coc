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
        $stmt = $db->prepare('INSERT IGNORE INTO cwl_participaciones (temporada_id, jugador_id, dia) VALUES (?, ?, 1)');
        foreach ($playerIds as $pid) {
            $stmt->execute([$id, (int) $pid]);
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
    $ataques    = $_POST['ataques'] ?? [];

    $stmt = $db->prepare(
        'UPDATE cwl_participaciones SET participo=?, estrellas=?, porcentaje=?
         WHERE temporada_id=? AND jugador_id=? AND dia=?'
    );

    // Intentar actualizar ataques por separado (requiere columna en BD)
    $stmtAtq = null;
    try {
        $stmtAtq = $db->prepare(
            'UPDATE cwl_participaciones SET ataques=?
             WHERE temporada_id=? AND jugador_id=? AND dia=?'
        );
    } catch (\PDOException $e) { /* columna aún no existe */ }

    foreach ($participo as $jid => $val) {
        $par = (int) $val;
        $est = ($estrellas[$jid] ?? '') !== '' ? (int) $estrellas[$jid] : null;
        $pct = ($porcentaje[$jid] ?? '') !== '' ? (float) $porcentaje[$jid] : null;
        $atq = ($ataques[$jid] ?? '') !== '' ? (int) $ataques[$jid] : null;
        $stmt->execute([$par, $est, $pct, $id, (int) $jid, 1]);
        if ($stmtAtq) {
            $stmtAtq->execute([$atq, $id, (int) $jid, 1]);
        }
    }

    logActivity('editar', 'cwl_participaciones', $id, 'Participaciones actualizadas');
    setFlash('success', 'Participaciones CWL guardadas.');
    header('Location: cwl_detalle?id=' . $id); exit;
}

// ── Remover jugador del roster ────────────────────────────────
if (isset($_GET['remove'])) {
    verifyCsrf();
    $removeJid = (int) $_GET['remove'];
    $db->prepare('DELETE FROM cwl_participaciones WHERE temporada_id=? AND jugador_id=?')
       ->execute([$id, $removeJid]);
    logActivity('eliminar', 'cwl_participaciones', $id, 'Jugador removido del roster: ' . $removeJid);
    setFlash('success', 'Jugador removido de esta temporada.');
    header('Location: cwl_detalle?id=' . $id); exit;
}

// ── Datos ─────────────────────────────────────────────────────
$rawParticipaciones = $db->prepare(
    'SELECT cp.*, j.usuario, j.rol_clan
     FROM cwl_participaciones cp
     JOIN jugadores j ON cp.jugador_id = j.id
     WHERE cp.temporada_id = ?
     ORDER BY j.usuario ASC, cp.dia ASC'
);
$rawParticipaciones->execute([$id]);
$rawParticipaciones = $rawParticipaciones->fetchAll();

// Agrupar por jugador (solo tomamos el primer registro como Total)
$jugadores = [];
foreach ($rawParticipaciones as $row) {
    if ($row['dia'] != 1) continue; // Ignoramos otros días si existen
    $jid = $row['jugador_id'];
    $jugadores[$jid] = [
        'nombre'     => $row['usuario'],
        'rol_clan'   => $row['rol_clan'],
        'estrellas'  => $row['estrellas'],
        'porcentaje' => $row['porcentaje'],
        'participo'  => $row['participo'],
        'ataques'    => $row['ataques'] ?? null,
    ];
}

// Jugadores disponibles
$jugadoresDisp = $db->prepare(
    'SELECT id, usuario, rol_clan FROM jugadores
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
    <div class="col-md-4"><div class="stat-card"><div class="stat-icon">👥</div><div class="stat-value"><?= count($jugadores) ?> / <?= (int) $temp['tamano'] ?></div><div class="stat-label">Jugadores en Roster</div></div></div>
</div>

<?php if (!empty($jugadoresDisp)): ?>
<div class="card mb-4 mb-5 border-primary">
    <div class="card-header d-flex justify-content-between align-items-center bg-surface2">
        <span class="text-gold">
            <i class="bi bi-person-plus-fill"></i> Seleccionar Participantes 
            <small class="ms-2 text-muted">(Seleccionados: <span id="selectedCount">0</span> / <?= ($temp['tamano'] - count($jugadores)) ?>)</small>
        </span>
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
                                    <input type="checkbox" name="jugador_ids[]" value="<?= $j['id'] ?>" id="p_<?= $j['id'] ?>" class="btn-check participant-checkbox">
                                    <label class="btn btn-outline-surface w-100 text-start text-truncate d-flex justify-content-between align-items-center" for="p_<?= $j['id'] ?>">
                                        <span><i class="bi bi-person"></i> <?= clean($j['usuario']) ?></span>
                                        <small class="text-muted opacity-50" style="font-size: 0.7rem;"><?= strtoupper($j['rol_clan'] ?? 'miembro') ?></small>
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
const selectionLimit = <?= (int) ($temp['tamano'] - count($jugadores)) ?>;

function updateSelectionCounter() {
    const checked = document.querySelectorAll('.participant-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = checked;
    
    // Disable unchecked if limit reached
    const unchecked = document.querySelectorAll('.participant-checkbox:not(:checked)');
    if (checked >= selectionLimit) {
        unchecked.forEach(cb => cb.disabled = true);
    } else {
        unchecked.forEach(cb => cb.disabled = false);
    }
}

document.querySelectorAll('.participant-checkbox').forEach(cb => {
    cb.addEventListener('change', updateSelectionCounter);
});

document.getElementById('playerSearch')?.addEventListener('input', function(e) {
    const q = e.target.value.toLowerCase();
    document.querySelectorAll('.player-item').forEach(item => {
        const name = item.getAttribute('data-name');
        item.style.display = name.includes(q) ? 'block' : 'none';
    });
});

function toggleAllPlayers(checked) {
    const checkboxes = document.querySelectorAll('#playerGrid input[type="checkbox"]');
    let count = document.querySelectorAll('.participant-checkbox:checked').length;
    
    checkboxes.forEach(cb => {
        if (cb.closest('.player-item').style.display !== 'none') {
            if (checked && count < selectionLimit && !cb.checked) {
                cb.checked = true;
                count++;
            } else if (!checked) {
                cb.checked = false;
                count = 0;
            }
        }
    });
    updateSelectionCounter();
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
.hover-opacity-100:hover { opacity: 1 !important; }
.text-nowrap { white-space: nowrap !important; }
</style>
<?php endif; ?>

<?php if (empty($jugadores)): ?>
    <div class="empty-state"><div class="empty-icon">🏆</div><p>No hay jugadores en el roster de esta temporada.</p></div>
<?php else: ?>
    <!-- Filtro de la tabla -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-surface border-secondary text-muted"><i class="bi bi-funnel"></i></span>
                        <input type="text" id="rosterFilter" class="form-control bg-surface border-secondary text-white" placeholder="Filtrar por nombre...">
                        <button class="btn btn-outline-secondary btn-sm" type="button" onclick="document.getElementById('rosterFilter').value=''; filterRoster();">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-6 text-end d-none d-md-block">
                    <span class="text-muted small">Mostrando <span id="rosterVisible"><?= count($jugadores) ?></span> de <?= count($jugadores) ?> jugadores</span>
                </div>
            </div>
        </div>
    </div>

    <form method="POST">
        <?= csrfField() ?><input type="hidden" name="save_participation" value="1">
        <div class="card"><div class="table-responsive">
            <table id="rosterTable" class="table table-hover mb-0" style="font-size:.85rem">
                <thead>
                    <tr>
                        <th style="min-width: 200px;">Jugador</th>
                        <th class="text-center">Participó</th>
                        <th class="text-center">Estrellas Totales</th>
                        <th class="text-center">% Destrucción</th>
                        <th class="text-center"># Ataques</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jugadores as $jid => $jd): ?>
                    <tr data-name="<?= strtolower(clean($jd['nombre'])) ?>">
                        <td>
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="fw-bold text-nowrap">
                                        <?= clean($jd['nombre']) ?>
                                        <small class="text-muted fw-normal opacity-50 ms-1" style="font-size: 0.65rem;">(<?= strtoupper($jd['rol_clan'] ?? 'miembro') ?>)</small>
                                    </div>
                                </div>
                                <a href="cwl_detalle?id=<?= $id ?>&remove=<?= $jid ?>&csrf_token=<?= csrfToken() ?>" 
                                   class="btn btn-sm btn-link p-1 text-danger opacity-50 hover-opacity-100" 
                                   title="Remover del Roster"
                                   onclick="return confirm('¿Remover a <?= clean($jd['nombre']) ?> de esta temporada?')">
                                    <i class="bi bi-person-x-fill fs-5"></i>
                                </a>
                            </div>
                        </td>
                        <td class="text-center">
                            <input type="checkbox" name="participo[<?= $jid ?>]" value="1"
                                   class="form-check-input" <?= ($jd['participo']) ? 'checked' : '' ?>>
                        </td>
                        <td class="text-center">
                            <div class="input-group input-group-sm mx-auto" style="width: 100px;">
                                <span class="input-group-text p-1 text-gold"><i class="bi bi-star-fill small"></i></span>
                                <input type="number" name="estrellas[<?= $jid ?>]" 
                                       class="form-control text-center" min="0" max="21" placeholder="0-21"
                                       value="<?= $jd['estrellas'] ?? '' ?>">
                            </div>
                        </td>
                        <td class="text-center">
                            <div class="input-group input-group-sm mx-auto" style="width: 110px;">
                                <input type="number" name="porcentaje[<?= $jid ?>]" 
                                       class="form-control text-center" min="0" step="0.01" placeholder="0"
                                       value="<?= $jd['porcentaje'] ?? '' ?>">
                                <span class="input-group-text px-1 text-muted">%</span>
                            </div>
                        </td>
                        <td class="text-center">
                            <div class="input-group input-group-sm mx-auto" style="width: 90px;">
                                <span class="input-group-text p-1 text-muted"><i class="bi bi-sword"></i></span>
                                <input type="number" name="ataques[<?= $jid ?>]" 
                                       class="form-control text-center" min="0" max="7" placeholder="0-7"
                                       value="<?= $jd['ataques'] ?? '' ?>">
                            </div>
                        </td>
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

<script>
function filterRoster() {
    const q = document.getElementById('rosterFilter').value.toLowerCase();
    const rows = document.querySelectorAll('#rosterTable tbody tr');
    let visible = 0;
    rows.forEach(row => {
        const name = row.getAttribute('data-name') || '';
        if (name.includes(q)) {
            row.style.display = '';
            visible++;
        } else {
            row.style.display = 'none';
        }
    });
    const counter = document.getElementById('rosterVisible');
    if (counter) counter.textContent = visible;
}

document.getElementById('rosterFilter')?.addEventListener('input', filterRoster);
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
