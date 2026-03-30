<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db = getDB();
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: juegos.php'); exit; }

$stmt = $db->prepare('SELECT * FROM juegos_clan WHERE id = ?');
$stmt->execute([$id]);
$juego = $stmt->fetch();
if (!$juego) { setFlash('error', 'No encontrado.'); header('Location: juegos.php'); exit; }

// ── Agregar jugadores ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_players'])) {
    verifyCsrf();
    $playerIds = $_POST['jugador_ids'] ?? [];
    if (!empty($playerIds)) {
        $stmt = $db->prepare('INSERT IGNORE INTO juegos_participaciones (juego_id, jugador_id) VALUES (?, ?)');
        foreach ($playerIds as $pid) { $stmt->execute([$id, (int) $pid]); }
        logActivity('crear', 'juegos_participaciones', $id, 'Agregados ' . count($playerIds) . ' jugadores');
        setFlash('success', count($playerIds) . ' jugador(es) agregado(s).');
    }
    header('Location: juegos_detalle.php?id=' . $id); exit;
}

// ── Guardar participaciones ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_participation'])) {
    verifyCsrf();
    $puntos         = $_POST['puntos'] ?? [];
    $alcanzo_maximo = $_POST['alcanzo_maximo'] ?? [];

    $stmtUp = $db->prepare(
        'UPDATE juegos_participaciones SET puntos=?, alcanzo_maximo=? WHERE juego_id=? AND jugador_id=?'
    );

    $totalPuntos = 0;
    foreach ($puntos as $jid => $pts) {
        $pts     = max(0, (int) $pts);
        $maximo  = isset($alcanzo_maximo[$jid]) ? 1 : 0;
        $stmtUp->execute([$pts, $maximo, $id, (int) $jid]);
        $totalPuntos += $pts;
    }

    // Actualizar totales
    $completado = $totalPuntos >= $juego['meta_puntos'] ? 1 : 0;
    $db->prepare('UPDATE juegos_clan SET puntos_totales=?, completado=? WHERE id=?')
       ->execute([$totalPuntos, $completado, $id]);

    logActivity('editar', 'juegos_participaciones', $id, "Puntos totales: $totalPuntos");
    setFlash('success', 'Participaciones guardadas.');
    header('Location: juegos_detalle.php?id=' . $id); exit;
}

// ── Datos ─────────────────────────────────────────────────────
$participaciones = $db->prepare(
    'SELECT jp.*, j.nombre, j.tag
     FROM juegos_participaciones jp
     JOIN jugadores j ON jp.jugador_id = j.id
     WHERE jp.juego_id = ?
     ORDER BY jp.puntos DESC, j.nombre ASC'
);
$participaciones->execute([$id]);
$participaciones = $participaciones->fetchAll();

$jugadoresDisp = $db->prepare(
    'SELECT id, nombre, tag FROM jugadores
     WHERE activo = 1 AND id NOT IN (SELECT jugador_id FROM juegos_participaciones WHERE juego_id = ?)
     ORDER BY nombre ASC'
);
$jugadoresDisp->execute([$id]);
$jugadoresDisp = $jugadoresDisp->fetchAll();

$totalPuntos   = array_sum(array_column($participaciones, 'puntos'));
$maxAlcanzados = count(array_filter($participaciones, fn($p) => $p['alcanzo_maximo']));
$pctAvance     = $juego['meta_puntos'] > 0 ? min(100, round(($totalPuntos / $juego['meta_puntos']) * 100)) : 0;

$pageTitle = 'Juegos del Clan — ' . date('d/m/Y', strtotime($juego['fecha_inicio']));
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-controller"></i> Juegos del Clan</h1>
    <a href="juegos.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="stat-card"><div class="stat-icon">🎯</div><div class="stat-value"><?= number_format($totalPuntos) ?></div><div class="stat-label">de <?= number_format($juego['meta_puntos']) ?> puntos</div></div></div>
    <div class="col-md-3"><div class="stat-card"><div class="stat-icon">👥</div><div class="stat-value"><?= count($participaciones) ?></div><div class="stat-label">Participantes</div></div></div>
    <div class="col-md-3"><div class="stat-card"><div class="stat-icon">🏅</div><div class="stat-value"><?= $maxAlcanzados ?></div><div class="stat-label">Alcanzaron Máximo</div></div></div>
    <div class="col-md-3"><div class="stat-card"><div class="stat-icon"><?= $pctAvance >= 100 ? '✅' : '📊' ?></div><div class="stat-value"><?= $pctAvance ?>%</div><div class="stat-label">Avance</div></div></div>
</div>

<!-- Barra de progreso -->
<div class="progress mb-4" style="height:28px">
    <div class="progress-bar" style="width:<?= $pctAvance ?>%"><?= number_format($totalPuntos) ?> / <?= number_format($juego['meta_puntos']) ?></div>
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
    <div class="empty-state"><div class="empty-icon">🎮</div><p>No hay jugadores participando.</p></div>
<?php else: ?>
    <form method="POST">
        <?= csrfField() ?><input type="hidden" name="save_participation" value="1">
        <div class="row g-3">
            <?php foreach ($participaciones as $p): ?>
            <div class="col-md-4 col-lg-3">
                <div class="participation-card">
                    <div class="player-name"><?= clean($p['nombre']) ?></div>
                    <div class="mb-2">
                        <label class="form-label">Puntos</label>
                        <input type="number" name="puntos[<?= $p['jugador_id'] ?>]" class="form-control"
                               min="0" max="5000" value="<?= (int) $p['puntos'] ?>">
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="alcanzo_maximo[<?= $p['jugador_id'] ?>]" value="1"
                               class="form-check-input" <?= $p['alcanzo_maximo'] ? 'checked' : '' ?>>
                        <label class="form-check-label">Alcanzó máximo (4000)</label>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="mt-4 text-center">
            <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> Guardar Puntos</button>
        </div>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
