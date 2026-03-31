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
    $stmt = $db->prepare('SELECT oponente FROM guerras WHERE id = ?');
    $stmt->execute([$id]);
    $g = $stmt->fetch();

    if ($g) {
        $db->prepare('DELETE FROM guerras WHERE id = ?')->execute([$id]);
        logActivity('eliminar', 'guerras', $id, 'Guerra vs ' . $g['oponente']);
        setFlash('success', 'Guerra eliminada.');
    }
    header('Location: guerras');
    exit;
}

// ── GUARDAR ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $id                 = (int) ($_POST['id'] ?? 0);
    $fecha              = $_POST['fecha'] ?? '';
    $oponente           = trim($_POST['oponente'] ?? '');
    $tamano             = (int) ($_POST['tamano'] ?? 15);
    $resultado          = $_POST['resultado'] ?? 'en_curso';
    $estrellas_clan     = max(0, (int) ($_POST['estrellas_clan'] ?? 0));
    $estrellas_oponente = max(0, (int) ($_POST['estrellas_oponente'] ?? 0));
    $notas              = trim($_POST['notas'] ?? '') ?: null;

    if ($fecha === '' || $oponente === '') {
        setFlash('error', 'Fecha y oponente son obligatorios.');
        header('Location: guerras?action=' . ($id ? 'edit&id=' . $id : 'create'));
        exit;
    }

    if ($id > 0) {
        $stmt = $db->prepare(
            'UPDATE guerras SET fecha=?, oponente=?, tamano=?, resultado=?, estrellas_clan=?, estrellas_oponente=?, notas=? WHERE id=?'
        );
        $stmt->execute([$fecha, $oponente, $tamano, $resultado, $estrellas_clan, $estrellas_oponente, $notas, $id]);
        logActivity('editar', 'guerras', $id, 'Guerra vs ' . $oponente);
        setFlash('success', 'Guerra actualizada.');
    } else {
        $stmt = $db->prepare(
            'INSERT INTO guerras (fecha, oponente, tamano, resultado, estrellas_clan, estrellas_oponente, notas)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$fecha, $oponente, $tamano, $resultado, $estrellas_clan, $estrellas_oponente, $notas]);
        $newId = (int) $db->lastInsertId();
        logActivity('crear', 'guerras', $newId, 'Guerra vs ' . $oponente);
        setFlash('success', 'Guerra registrada.');
    }

    header('Location: guerras');
    exit;
}

// ── VISTA ─────────────────────────────────────────────────────
$pageTitle = 'Guerras';

if ($action === 'create' || $action === 'edit') {
    $guerra = null;
    if ($action === 'edit' && isset($_GET['id'])) {
        $stmt = $db->prepare('SELECT * FROM guerras WHERE id = ?');
        $stmt->execute([(int) $_GET['id']]);
        $guerra = $stmt->fetch();
        if (!$guerra) {
            setFlash('error', 'Guerra no encontrada.');
            header('Location: guerras');
            exit;
        }
        $pageTitle = 'Editar Guerra';
    } else {
        $pageTitle = 'Nueva Guerra';
    }

    require __DIR__ . '/includes/header.php';
    ?>

    <div class="ct-page-header">
        <h1><i class="bi bi-lightning-fill"></i> <?= clean($pageTitle) ?></h1>
        <a href="guerras" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= $guerra['id'] ?? 0 ?>">

                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="fecha" class="form-label">Fecha</label>
                        <input type="date" name="fecha" id="fecha" class="form-control"
                               value="<?= clean($guerra['fecha'] ?? date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label for="oponente" class="form-label">Clan Oponente</label>
                        <input type="text" name="oponente" id="oponente" class="form-control"
                               value="<?= clean($guerra['oponente'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label for="tamano" class="form-label">Tamaño (Participantes)</label>
                        <select name="tamano" id="tamano" class="form-select">
                            <?php foreach ([5, 10, 15, 20, 25, 30, 40, 50] as $t): ?>
                                <option value="<?= $t ?>" <?= ($guerra['tamano'] ?? 15) == $t ? 'selected' : '' ?>>
                                    <?= $t ?> vs <?= $t ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="resultado" class="form-label">Resultado</label>
                        <select name="resultado" id="resultado" class="form-select">
                            <?php foreach (['en_curso','victoria','derrota','empate'] as $r): ?>
                                <option value="<?= $r ?>" <?= ($guerra['resultado'] ?? 'en_curso') === $r ? 'selected' : '' ?>>
                                    <?= ucfirst(str_replace('_', ' ', $r)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="estrellas_clan" class="form-label">⭐ Estrellas Clan</label>
                        <input type="number" name="estrellas_clan" id="estrellas_clan" class="form-control"
                               min="0" value="<?= (int) ($guerra['estrellas_clan'] ?? 0) ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="estrellas_oponente" class="form-label">⭐ Estrellas Oponente</label>
                        <input type="number" name="estrellas_oponente" id="estrellas_oponente" class="form-control"
                               min="0" value="<?= (int) ($guerra['estrellas_oponente'] ?? 0) ?>">
                    </div>
                    <div class="col-12">
                        <label for="notas" class="form-label">Notas</label>
                        <textarea name="notas" id="notas" class="form-control" rows="2"><?= clean($guerra['notas'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar</button>
                    <a href="guerras" class="btn btn-secondary ms-2">Cancelar</a>
                </div>
            </form>
        </div>
    </div>

    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ── LISTADO ───────────────────────────────────────────────────
$guerras = $db->query(
    'SELECT g.*, (SELECT COUNT(*) FROM guerra_participaciones WHERE guerra_id = g.id) AS participantes
     FROM guerras g ORDER BY g.fecha DESC'
)->fetchAll();

$resBadge = [
    'victoria' => 'badge-green',
    'derrota'  => 'badge-red',
    'empate'   => 'badge-blue',
    'en_curso' => 'badge-gold',
];

require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-lightning-fill"></i> Guerras</h1>
    <a href="guerras?action=create" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> Nueva Guerra
    </a>
</div>

<?php if (empty($guerras)): ?>
    <div class="empty-state">
        <div class="empty-icon">⚔️</div>
        <p>No hay guerras registradas.</p>
        <a href="guerras?action=create" class="btn btn-primary btn-sm">Registrar primera guerra</a>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Oponente</th>
                        <th>Resultado</th>
                        <th class="text-center">⭐ Clan</th>
                        <th class="text-center">⭐ Oponente</th>
                        <th class="text-center">Participantes</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($guerras as $g): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($g['fecha'])) ?></td>
                            <td><strong><?= clean($g['oponente']) ?></strong></td>
                            <td>
                                <span class="badge <?= $resBadge[$g['resultado']] ?? 'badge-muted' ?>">
                                    <?= ucfirst(str_replace('_', ' ', $g['resultado'])) ?>
                                </span>
                            </td>
                            <td class="text-center"><?= (int) $g['estrellas_clan'] ?></td>
                            <td class="text-center"><?= (int) $g['estrellas_oponente'] ?></td>
                            <td class="text-center"><?= (int) $g['participantes'] ?></td>
                            <td class="text-end">
                                <a href="guerra_detalle?id=<?= $g['id'] ?>" class="btn btn-sm btn-outline-primary" title="Detalle">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="guerras?action=edit&id=<?= $g['id'] ?>" class="btn btn-sm btn-outline-primary" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="guerras?action=delete&id=<?= $g['id'] ?>&csrf_token=<?= csrfToken() ?>"
                                   class="btn btn-sm btn-danger" title="Eliminar"
                                   data-confirm="¿Eliminar guerra vs <?= clean($g['oponente']) ?>?">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
