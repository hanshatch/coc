<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db     = getDB();
$action = $_GET['action'] ?? 'list';

if ($action === 'delete' && isset($_GET['id'])) {
    verifyCsrf();
    $db->prepare('DELETE FROM capital_semanas WHERE id = ?')->execute([(int) $_GET['id']]);
    logActivity('eliminar', 'capital_semanas', (int) $_GET['id']);
    setFlash('success', 'Semana de capital eliminada.');
    header('Location: capital'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $id                   = (int) ($_POST['id'] ?? 0);
    $fecha_inicio         = $_POST['fecha_inicio'] ?? '';
    $fecha_fin            = $_POST['fecha_fin'] ?? '';
    $oro_total_recaudado  = (int) ($_POST['oro_total_recaudado'] ?? 0);
    $ataques_totales      = (int) ($_POST['ataques_totales'] ?? 0);
    $distritos_destruidos = (int) ($_POST['distritos_destruidos'] ?? 0);
    $notas                = trim($_POST['notas'] ?? '') ?: null;

    if ($fecha_inicio === '' || $fecha_fin === '') {
        setFlash('error', 'Las fechas son obligatorias.');
        header('Location: capital?action=' . ($id ? 'edit&id=' . $id : 'create')); exit;
    }

    if ($id > 0) {
        $db->prepare('UPDATE capital_semanas SET fecha_inicio=?, fecha_fin=?, oro_total_recaudado=?, ataques_totales=?, distritos_destruidos=?, notas=? WHERE id=?')
           ->execute([$fecha_inicio, $fecha_fin, $oro_total_recaudado, $ataques_totales, $distritos_destruidos, $notas, $id]);
        logActivity('editar', 'capital_semanas', $id);
        setFlash('success', 'Semana actualizada.');
    } else {
        $db->prepare('INSERT INTO capital_semanas (fecha_inicio, fecha_fin, oro_total_recaudado, ataques_totales, distritos_destruidos, notas) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$fecha_inicio, $fecha_fin, $oro_total_recaudado, $ataques_totales, $distritos_destruidos, $notas]);
        logActivity('crear', 'capital_semanas', (int) $db->lastInsertId());
        setFlash('success', 'Semana de capital creada.');
    }
    header('Location: capital'); exit;
}

if ($action === 'create' || $action === 'edit') {
    $semana = null;
    if ($action === 'edit' && isset($_GET['id'])) {
        $stmt = $db->prepare('SELECT * FROM capital_semanas WHERE id = ?');
        $stmt->execute([(int) $_GET['id']]);
        $semana = $stmt->fetch();
        if (!$semana) { setFlash('error', 'No encontrada.'); header('Location: capital'); exit; }
        $pageTitle = 'Editar Semana Capital';
    } else {
        $pageTitle = 'Nueva Semana de Raid';
    }
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="ct-page-header">
        <h1><i class="bi bi-building-fill"></i> <?= clean($pageTitle) ?></h1>
        <a href="capital" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>
    <div class="card"><div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= $semana['id'] ?? 0 ?>">
            <div class="row g-3">
                <div class="col-md-6 col-lg-3">
                    <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                    <input type="date" name="fecha_inicio" id="fecha_inicio" class="form-control"
                           value="<?= clean($semana['fecha_inicio'] ?? date('Y-m-d')) ?>" required>
                </div>
                <div class="col-md-6 col-lg-3">
                    <label for="fecha_fin" class="form-label">Fecha Fin</label>
                    <input type="date" name="fecha_fin" id="fecha_fin" class="form-control"
                           value="<?= clean($semana['fecha_fin'] ?? '') ?>" required>
                </div>
                <div class="col-md-6 col-lg-2">
                    <label for="oro_total_recaudado" class="form-label">Oro Total</label>
                    <input type="number" name="oro_total_recaudado" id="oro_total_recaudado" class="form-control"
                           min="0" value="<?= (int) ($semana['oro_total_recaudado'] ?? 0) ?>">
                </div>
                <div class="col-md-6 col-lg-2">
                    <label for="ataques_totales" class="form-label">Ataques Totales</label>
                    <input type="number" name="ataques_totales" id="ataques_totales" class="form-control"
                           min="0" value="<?= (int) ($semana['ataques_totales'] ?? 0) ?>">
                </div>
                <div class="col-md-6 col-lg-2">
                    <label for="distritos_destruidos" class="form-label">Distritos</label>
                    <input type="number" name="distritos_destruidos" id="distritos_destruidos" class="form-control"
                           min="0" value="<?= (int) ($semana['distritos_destruidos'] ?? 0) ?>">
                </div>
                <div class="col-12">
                    <label for="notas" class="form-label">Notas</label>
                    <textarea name="notas" id="notas" class="form-control" rows="2"><?= clean($semana['notas'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar</button>
                <a href="capital" class="btn btn-secondary ms-2">Cancelar</a>
            </div>
        </form>
    </div></div>
    <?php require __DIR__ . '/includes/footer.php'; exit;
}

// ── LISTADO ───────────────────────────────────────────────────
$semanas = $db->query(
    'SELECT cs.*,
            (SELECT COALESCE(SUM(oro_aportado),0) FROM capital_participaciones WHERE semana_id = cs.id) AS oro_sum,
            (SELECT COUNT(*) FROM capital_participaciones WHERE semana_id = cs.id) AS participantes
     FROM capital_semanas cs ORDER BY cs.fecha_inicio DESC'
)->fetchAll();

$pageTitle = 'Capital de Clan';
require __DIR__ . '/includes/header.php';
?>
<div class="ct-page-header">
    <h1><i class="bi bi-building-fill"></i> Capital de Clan</h1>
    <a href="capital?action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nueva Semana de Raid</a>
</div>

<?php if (empty($semanas)): ?>
    <div class="empty-state"><div class="empty-icon">🏰</div><p>No hay semanas de capital registradas.</p></div>
<?php else: ?>
    <div class="card"><div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Fechas</th><th class="text-center">Oro</th><th class="text-center">Ataques</th><th class="text-center">Distritos</th><th class="text-center">Participantes</th><th class="text-end">Acciones</th></tr></thead>
            <tbody>
                <?php foreach ($semanas as $s): ?>
                <tr>
                    <td><?= date('d/m', strtotime($s['fecha_inicio'])) ?> — <?= date('d/m/Y', strtotime($s['fecha_fin'])) ?></td>
                    <td class="text-center"><strong><?= number_format($s['oro_sum']) ?></strong></td>
                    <td class="text-center"><?= (int) $s['ataques_totales'] ?></td>
                    <td class="text-center"><?= (int) $s['distritos_destruidos'] ?></td>
                    <td class="text-center"><?= (int) $s['participantes'] ?></td>
                    <td class="text-end">
                        <a href="capital_detalle?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary" title="Detalle"><i class="bi bi-eye"></i></a>
                        <a href="capital?action=edit&id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary" title="Editar"><i class="bi bi-pencil"></i></a>
                        <a href="capital?action=delete&id=<?= $s['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-sm btn-danger"
                           data-confirm="¿Eliminar esta semana de raid?"><i class="bi bi-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
