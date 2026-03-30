<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db     = getDB();
$action = $_GET['action'] ?? 'list';

// ── ELIMINAR ──────────────────────────────────────────────────
if ($action === 'delete' && isset($_GET['id'])) {
    verifyCsrf();
    $id = (int) $_GET['id'];
    $db->prepare('DELETE FROM juegos_clan WHERE id = ?')->execute([$id]);
    logActivity('eliminar', 'juegos_clan', $id, 'Juegos eliminados');
    setFlash('success', 'Juegos del clan eliminados.');
    header('Location: juegos.php'); exit;
}

// ── GUARDAR ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $id           = (int) ($_POST['id'] ?? 0);
    $fecha_inicio = $_POST['fecha_inicio'] ?? '';
    $fecha_fin    = $_POST['fecha_fin'] ?? '';
    $meta_puntos  = max(1, (int) ($_POST['meta_puntos'] ?? 50000));
    $notas        = trim($_POST['notas'] ?? '') ?: null;

    if ($fecha_inicio === '' || $fecha_fin === '') {
        setFlash('error', 'Las fechas son obligatorias.');
        header('Location: juegos.php?action=' . ($id ? 'edit&id=' . $id : 'create')); exit;
    }

    if ($id > 0) {
        $stmt = $db->prepare('UPDATE juegos_clan SET fecha_inicio=?, fecha_fin=?, meta_puntos=?, notas=? WHERE id=?');
        $stmt->execute([$fecha_inicio, $fecha_fin, $meta_puntos, $notas, $id]);
        logActivity('editar', 'juegos_clan', $id);
        setFlash('success', 'Juegos actualizados.');
    } else {
        $stmt = $db->prepare('INSERT INTO juegos_clan (fecha_inicio, fecha_fin, meta_puntos, notas) VALUES (?, ?, ?, ?)');
        $stmt->execute([$fecha_inicio, $fecha_fin, $meta_puntos, $notas]);
        logActivity('crear', 'juegos_clan', (int) $db->lastInsertId());
        setFlash('success', 'Juegos del clan creados.');
    }
    header('Location: juegos.php'); exit;
}

// ── FORMULARIO ────────────────────────────────────────────────
if ($action === 'create' || $action === 'edit') {
    $juego = null;
    if ($action === 'edit' && isset($_GET['id'])) {
        $stmt = $db->prepare('SELECT * FROM juegos_clan WHERE id = ?');
        $stmt->execute([(int) $_GET['id']]);
        $juego = $stmt->fetch();
        if (!$juego) { setFlash('error', 'No encontrado.'); header('Location: juegos.php'); exit; }
        $pageTitle = 'Editar Juegos del Clan';
    } else {
        $pageTitle = 'Nuevos Juegos del Clan';
    }

    require __DIR__ . '/includes/header.php';
    ?>
    <div class="ct-page-header">
        <h1><i class="bi bi-controller"></i> <?= clean($pageTitle) ?></h1>
        <a href="juegos.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>
    <div class="card"><div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= $juego['id'] ?? 0 ?>">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                    <input type="date" name="fecha_inicio" id="fecha_inicio" class="form-control"
                           value="<?= clean($juego['fecha_inicio'] ?? date('Y-m-d')) ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="fecha_fin" class="form-label">Fecha Fin</label>
                    <input type="date" name="fecha_fin" id="fecha_fin" class="form-control"
                           value="<?= clean($juego['fecha_fin'] ?? '') ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="meta_puntos" class="form-label">Meta de Puntos</label>
                    <input type="number" name="meta_puntos" id="meta_puntos" class="form-control"
                           min="1" value="<?= (int) ($juego['meta_puntos'] ?? 50000) ?>">
                </div>
                <div class="col-12">
                    <label for="notas" class="form-label">Notas</label>
                    <textarea name="notas" id="notas" class="form-control" rows="2"><?= clean($juego['notas'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar</button>
                <a href="juegos.php" class="btn btn-secondary ms-2">Cancelar</a>
            </div>
        </form>
    </div></div>
    <?php require __DIR__ . '/includes/footer.php'; exit;
}

// ── LISTADO ───────────────────────────────────────────────────
$juegos = $db->query(
    'SELECT jc.*,
            (SELECT COALESCE(SUM(puntos),0) FROM juegos_participaciones WHERE juego_id = jc.id) AS puntos_sum,
            (SELECT COUNT(*) FROM juegos_participaciones WHERE juego_id = jc.id) AS participantes
     FROM juegos_clan jc ORDER BY jc.fecha_inicio DESC'
)->fetchAll();

$pageTitle = 'Juegos del Clan';
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-controller"></i> Juegos del Clan</h1>
    <a href="juegos.php?action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nuevos Juegos</a>
</div>

<?php if (empty($juegos)): ?>
    <div class="empty-state"><div class="empty-icon">🎮</div><p>No hay juegos del clan registrados.</p></div>
<?php else: ?>
    <div class="card"><div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Fechas</th><th>Meta</th><th>Puntos</th><th>Avance</th><th class="text-center">Participantes</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
            <tbody>
                <?php foreach ($juegos as $j):
                    $pct = $j['meta_puntos'] > 0 ? min(100, round(($j['puntos_sum'] / $j['meta_puntos']) * 100)) : 0;
                ?>
                <tr>
                    <td><?= date('d/m', strtotime($j['fecha_inicio'])) ?> — <?= date('d/m/Y', strtotime($j['fecha_fin'])) ?></td>
                    <td><?= number_format($j['meta_puntos']) ?></td>
                    <td><strong><?= number_format($j['puntos_sum']) ?></strong></td>
                    <td style="min-width:140px">
                        <div class="progress" style="height:20px">
                            <div class="progress-bar" style="width:<?= $pct ?>%"><?= $pct ?>%</div>
                        </div>
                    </td>
                    <td class="text-center"><?= (int) $j['participantes'] ?></td>
                    <td>
                        <?php if ($j['puntos_sum'] >= $j['meta_puntos']): ?>
                            <span class="badge badge-green">✅ Completado</span>
                        <?php else: ?>
                            <span class="badge badge-gold">En curso</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <a href="juegos_detalle.php?id=<?= $j['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                        <a href="juegos.php?action=edit&id=<?= $j['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <a href="juegos.php?action=delete&id=<?= $j['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-sm btn-danger"
                           data-confirm="¿Eliminar estos juegos del clan?"><i class="bi bi-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
