<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireAdmin();

$db = getDB();

// ── Paginación ────────────────────────────────────────────────
$count = (int) $db->query('SELECT COUNT(*) FROM log_actividad')->fetchColumn();
$pagination = paginate(30);

// ── Filtros ───────────────────────────────────────────────────
$userFilter = (int) ($_GET['u'] ?? 0);
$tableFilter = trim($_GET['t'] ?? '');

$where = [];
$params = [];

if ($userFilter > 0) { $where[] = 'l.usuario_id = ?'; $params[] = $userFilter; }
if ($tableFilter !== '') { $where[] = 'l.tabla_afectada = ?'; $params[] = $tableFilter; }

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$logs = $db->prepare(
    "SELECT l.*, u.nombre AS usuario_nombre, u.username
     FROM log_actividad l
     JOIN usuarios u ON l.usuario_id = u.id
     {$whereSQL}
     ORDER BY l.created_at DESC
     LIMIT ? OFFSET ?"
);

// Append limit/offset to params (PDO requires them to be strictly typed or passed as int)
$logs->bindValue(count($params) + 1, $pagination['limit'], PDO::PARAM_INT);
$logs->bindValue(count($params) + 2, $pagination['offset'], PDO::PARAM_INT);
foreach ($params as $key => $val) { $logs->bindValue($key + 1, $val); }
$logs->execute();
$logs = $logs->fetchAll();

// Listado de usuarios para filtro
$users = $db->query('SELECT id, nombre, username FROM usuarios ORDER BY nombre ASC')->fetchAll();

$pageTitle = 'Log de Actividad';
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-journal-text"></i> Log de Actividad del Sistema</h1>
</div>

<!-- Filtros -->
<div class="card mb-4">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label mb-0 small">Usuario</label>
                <select name="u" class="form-select form-select-sm">
                    <option value="">— Todos —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $userFilter === (int)$u['id'] ? 'selected' : '' ?>><?= clean($u['nombre']) ?> (@<?= clean($u['username']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label mb-0 small">Sección</label>
                <select name="t" class="form-select form-select-sm">
                    <option value="">— Todas —</option>
                    <?php foreach (['jugadores','guerras','cwl_temporadas','juegos_clan','donaciones_periodos','capital_semanas','usuarios'] as $tab): ?>
                        <option value="<?= $tab ?>" <?= $tableFilter === $tab ? 'selected' : '' ?>><?= ucfirst($tab) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-outline-primary btn-sm px-3"><i class="bi bi-filter"></i> Filtrar</button>
                <a href="log.php" class="btn btn-outline-secondary btn-sm px-3">Limpiar</a>
            </div>
        </form>
    </div>
</div>

<?php if (empty($logs)): ?>
    <div class="empty-state"><div class="empty-icon"><i class="bi bi-journal-x"></i></div><p>No hay registros de actividad.</p></div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:0.85rem">
                <thead>
                    <tr>
                        <th class="ps-3 py-2">Fecha y Hora</th>
                        <th class="py-2">Usuario</th>
                        <th class="py-2">Acción</th>
                        <th class="py-2">Sección</th>
                        <th class="py-2">Detalle</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $l):
                        $badgeClass = match($l['accion']) {
                            'crear'    => 'badge-green',
                            'editar'   => 'badge-blue',
                            'eliminar' => 'badge-red',
                            default    => 'badge-muted'
                        };
                    ?>
                        <tr>
                            <td class="ps-3"><?= date('d/m/Y H:i:s', strtotime($l['created_at'])) ?></td>
                            <td><strong><?= clean($l['usuario_nombre']) ?></strong> <small class="text-muted">(@<?= clean($l['username']) ?>)</small></td>
                            <td><span class="badge <?= $badgeClass ?>"><?= $l['accion'] ?></span></td>
                            <td><span class="badge badge-muted"><?= ucfirst($l['tabla_afectada']) ?></span></td>
                            <td><?= clean($l['detalle']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Paginación -->
    <div class="mt-4">
        <?= paginationLinks($count, 30, $pagination['page'], 'log.php') ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
