<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db     = getDB();
$action = $_GET['action'] ?? 'list';

if ($action === 'delete' && isset($_GET['id'])) {
    verifyCsrf();
    $db->prepare('DELETE FROM donaciones_periodos WHERE id = ?')->execute([(int) $_GET['id']]);
    logActivity('eliminar', 'donaciones_periodos', (int) $_GET['id']);
    setFlash('success', 'Periodo eliminado.');
    header('Location: donaciones'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $id           = (int) ($_POST['id'] ?? 0);
    $fecha_inicio = $_POST['fecha_inicio'] ?? '';
    $fecha_fin    = $_POST['fecha_fin'] ?? '';
    $tipo         = $_POST['tipo'] ?? 'semanal';
    $notas        = trim($_POST['notas'] ?? '') ?: null;

    if ($fecha_inicio === '' || $fecha_fin === '') {
        setFlash('error', 'Las fechas son obligatorias.');
        header('Location: donaciones?action=' . ($id ? 'edit&id=' . $id : 'create')); exit;
    }

    if ($id > 0) {
        $db->prepare('UPDATE donaciones_periodos SET fecha_inicio=?, fecha_fin=?, tipo=?, notas=? WHERE id=?')
           ->execute([$fecha_inicio, $fecha_fin, $tipo, $notas, $id]);
        logActivity('editar', 'donaciones_periodos', $id);
        setFlash('success', 'Periodo actualizado.');
    } else {
        $db->prepare('INSERT INTO donaciones_periodos (fecha_inicio, fecha_fin, tipo, notas) VALUES (?, ?, ?, ?)')
           ->execute([$fecha_inicio, $fecha_fin, $tipo, $notas]);
        logActivity('crear', 'donaciones_periodos', (int) $db->lastInsertId());
        setFlash('success', 'Periodo creado.');
    }
    header('Location: donaciones'); exit;
}

if ($action === 'create' || $action === 'edit') {
    $periodo = null;
    if ($action === 'edit' && isset($_GET['id'])) {
        $stmt = $db->prepare('SELECT * FROM donaciones_periodos WHERE id = ?');
        $stmt->execute([(int) $_GET['id']]);
        $periodo = $stmt->fetch();
        if (!$periodo) { setFlash('error', 'No encontrado.'); header('Location: donaciones'); exit; }
        $pageTitle = 'Editar Periodo';
    } else {
        $pageTitle = 'Nuevo Periodo de Donaciones';
    }
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="ct-page-header">
        <h1><i class="bi bi-gift-fill"></i> <?= clean($pageTitle) ?></h1>
        <a href="donaciones" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>
    <div class="card"><div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= $periodo['id'] ?? 0 ?>">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                    <input type="date" name="fecha_inicio" id="fecha_inicio" class="form-control"
                           value="<?= clean($periodo['fecha_inicio'] ?? date('Y-m-d')) ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="fecha_fin" class="form-label">Fecha Fin</label>
                    <input type="date" name="fecha_fin" id="fecha_fin" class="form-control"
                           value="<?= clean($periodo['fecha_fin'] ?? '') ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="tipo" class="form-label">Tipo</label>
                    <select name="tipo" id="tipo" class="form-select">
                        <option value="semanal" <?= ($periodo['tipo'] ?? 'semanal') === 'semanal' ? 'selected' : '' ?>>Semanal</option>
                        <option value="mensual" <?= ($periodo['tipo'] ?? '') === 'mensual' ? 'selected' : '' ?>>Mensual</option>
                    </select>
                </div>
                <div class="col-12">
                    <label for="notas" class="form-label">Notas</label>
                    <textarea name="notas" id="notas" class="form-control" rows="2"><?= clean($periodo['notas'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar</button>
                <a href="donaciones" class="btn btn-secondary ms-2">Cancelar</a>
            </div>
        </form>
    </div></div>
    <?php require __DIR__ . '/includes/footer.php'; exit;
}

// ── LISTADO ───────────────────────────────────────────────────
$periodos = $db->query(
    'SELECT dp.*,
            (SELECT COALESCE(SUM(tropas_donadas),0) FROM donaciones WHERE periodo_id = dp.id) AS total_donadas,
            (SELECT COUNT(*) FROM donaciones WHERE periodo_id = dp.id) AS participantes
     FROM donaciones_periodos dp ORDER BY dp.fecha_inicio DESC'
)->fetchAll();

$pageTitle = 'Donaciones';
require __DIR__ . '/includes/header.php';
?>
<div class="ct-page-header">
    <h1><i class="bi bi-gift-fill"></i> Donaciones</h1>
    <a href="donaciones?action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nuevo Periodo</a>
</div>

<?php if (empty($periodos)): ?>
    <div class="empty-state"><div class="empty-icon">🎁</div><p>No hay periodos de donaciones.</p></div>
<?php else: ?>
    <div class="card"><div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Periodo</th><th>Tipo</th><th class="text-center">Total Donadas</th><th class="text-center">Participantes</th><th class="text-end">Acciones</th></tr></thead>
            <tbody>
                <?php foreach ($periodos as $p): ?>
                <tr>
                    <td><?= date('d/m', strtotime($p['fecha_inicio'])) ?> — <?= date('d/m/Y', strtotime($p['fecha_fin'])) ?></td>
                    <td><span class="badge <?= $p['tipo'] === 'semanal' ? 'badge-blue' : 'badge-purple' ?>"><?= ucfirst($p['tipo']) ?></span></td>
                    <td class="text-center"><strong><?= number_format($p['total_donadas']) ?></strong></td>
                    <td class="text-center"><?= (int) $p['participantes'] ?></td>
                    <td class="text-end">
                        <a href="donaciones_detalle?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                        <a href="donaciones?action=edit&id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <a href="donaciones?action=delete&id=<?= $p['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-sm btn-danger"
                           data-confirm="¿Eliminar este periodo?"><i class="bi bi-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
