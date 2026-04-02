<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db     = getDB();
$action = $_GET['action'] ?? 'list';

// ── ELIMINAR ──────────────────────────────────────────────────
if ($action === 'delete' && isset($_GET['id'])) {
    verifyCsrf();
    $id   = (int) $_GET['id'];
    $stmt = $db->prepare('SELECT mes FROM cwl_temporadas WHERE id = ?');
    $stmt->execute([$id]);
    $t = $stmt->fetch();
    if ($t) {
        $db->prepare('DELETE FROM cwl_temporadas WHERE id = ?')->execute([$id]);
        logActivity('eliminar', 'cwl_temporadas', $id, 'CWL ' . $t['mes']);
        setFlash('success', 'Temporada CWL eliminada.');
    }
    header('Location: cwl');
    exit;
}

// ── GUARDAR ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $id             = (int) ($_POST['id'] ?? 0);
    $mes            = trim($_POST['mes'] ?? '');
    $liga           = trim($_POST['liga'] ?? '') ?: null;
    $posicion_final = ($_POST['posicion_final'] ?? '') !== '' ? (int) $_POST['posicion_final'] : null;
    $tamano         = (int) ($_POST['tamano'] ?? 15);
    $notas          = trim($_POST['notas'] ?? '') ?: null;

    if ($mes === '') {
        setFlash('error', 'El mes es obligatorio.');
        header('Location: cwl?action=' . ($id ? 'edit&id=' . $id : 'create'));
        exit;
    }

    if ($id > 0) {
        $stmt = $db->prepare('UPDATE cwl_temporadas SET mes=?, liga=?, tamano=?, posicion_final=?, notas=? WHERE id=?');
        $stmt->execute([$mes, $liga, $tamano, $posicion_final, $notas, $id]);
        logActivity('editar', 'cwl_temporadas', $id, 'CWL ' . $mes);
        setFlash('success', 'Temporada actualizada.');
    } else {
        $stmt = $db->prepare('INSERT INTO cwl_temporadas (mes, liga, tamano, posicion_final, notas) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$mes, $liga, $tamano, $posicion_final, $notas]);
        logActivity('crear', 'cwl_temporadas', (int) $db->lastInsertId(), 'CWL ' . $mes);
        setFlash('success', 'Temporada CWL creada.');
    }
    header('Location: cwl');
    exit;
}

// ── FORMULARIO ────────────────────────────────────────────────
if ($action === 'create' || $action === 'edit') {
    $temp = null;
    if ($action === 'edit' && isset($_GET['id'])) {
        $stmt = $db->prepare('SELECT * FROM cwl_temporadas WHERE id = ?');
        $stmt->execute([(int) $_GET['id']]);
        $temp = $stmt->fetch();
        if (!$temp) { setFlash('error', 'No encontrada.'); header('Location: cwl'); exit; }
        $pageTitle = 'Editar CWL';
    } else {
        $pageTitle = 'Nueva Temporada CWL';
    }

    require __DIR__ . '/includes/header.php';
    ?>
    <div class="ct-page-header">
        <h1><i class="bi bi-trophy-fill"></i> <?= clean($pageTitle) ?></h1>
        <a href="cwl" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>
    <div class="card"><div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= $temp['id'] ?? 0 ?>">
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="mes" class="form-label">Mes (YYYY-MM)</label>
                    <input type="month" name="mes" id="mes" class="form-control"
                           value="<?= clean($temp['mes'] ?? date('Y-m')) ?>" required>
                </div>
                <div class="col-md-2">
                    <label for="tamano" class="form-label">Tamaño</label>
                    <select name="tamano" id="tamano" class="form-select">
                        <option value="15" <?= ($temp['tamano'] ?? 15) == 15 ? 'selected' : '' ?>>15 vs 15</option>
                        <option value="30" <?= ($temp['tamano'] ?? 15) == 30 ? 'selected' : '' ?>>30 vs 30</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="posicion_final" class="form-label">Posición Final</label>
                    <select name="posicion_final" id="posicion_final" class="form-select">
                        <option value="">—</option>
                        <?php for ($i = 1; $i <= 8; $i++): ?>
                            <option value="<?= $i ?>" <?= ($temp['posicion_final'] ?? '') == $i ? 'selected' : '' ?>><?= $i ?>°</option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label for="notas" class="form-label">Notas</label>
                    <textarea name="notas" id="notas" class="form-control" rows="2"><?= clean($temp['notas'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar</button>
                <a href="cwl" class="btn btn-secondary ms-2">Cancelar</a>
            </div>
        </form>
    </div></div>
    <?php require __DIR__ . '/includes/footer.php'; exit;
}

// ── LISTADO ───────────────────────────────────────────────────
$temps = $db->query(
    'SELECT t.*, (SELECT COUNT(DISTINCT jugador_id) FROM cwl_participaciones WHERE temporada_id = t.id) AS participantes
     FROM cwl_temporadas t ORDER BY t.mes DESC'
)->fetchAll();

$pageTitle = 'Liga de Guerra (CWL)';
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-trophy-fill"></i> Liga CWL</h1>
    <a href="cwl?action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nueva Temporada</a>
</div>

<?php if (empty($temps)): ?>
    <div class="empty-state"><div class="empty-icon">🏆</div><p>No hay temporadas CWL registradas.</p></div>
<?php else: ?>
    <div class="card"><div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Mes</th><th>Liga</th><th class="text-center">Tamaño</th><th class="text-center">Posición</th><th class="text-center">Participantes</th><th class="text-end">Acciones</th></tr></thead>
            <tbody>
                <?php foreach ($temps as $t): ?>
                <tr>
                    <td><strong><?= clean($t['mes']) ?></strong></td>
                    <td><?= clean($t['liga'] ?? '—') ?></td>
                    <td class="text-center"><?= $t['tamano'] == 30 ? '30 vs 30' : '15 vs 15' ?></td>
                    <td class="text-center"><?= $t['posicion_final'] ? $t['posicion_final'] . '°' : '—' ?></td>
                    <td class="text-center"><?= (int) $t['participantes'] ?></td>
                    <td class="text-end">
                        <a href="cwl_detalle?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                        <a href="cwl?action=edit&id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <a href="cwl?action=delete&id=<?= $t['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-sm btn-danger"
                           data-confirm="¿Eliminar temporada CWL <?= clean($t['mes']) ?>?"><i class="bi bi-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
