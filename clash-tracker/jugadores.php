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
    $stmt = $db->prepare('SELECT nombre FROM jugadores WHERE id = ?');
    $stmt->execute([$id]);
    $j = $stmt->fetch();

    if ($j) {
        $db->prepare('DELETE FROM jugadores WHERE id = ?')->execute([$id]);
        logActivity('eliminar', 'jugadores', $id, 'Jugador: ' . $j['nombre']);
        setFlash('success', 'Jugador eliminado correctamente.');
    }
    header('Location: jugadores.php');
    exit;
}

// ── GUARDAR (crear / editar) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $id            = (int) ($_POST['id'] ?? 0);
    $tag           = strtoupper(trim($_POST['tag'] ?? ''));
    $nombre        = trim($_POST['nombre'] ?? '');
    $nivel_th      = max(1, min(17, (int) ($_POST['nivel_th'] ?? 1)));
    $nivel_jugador = max(1, (int) ($_POST['nivel_jugador'] ?? 1));
    $rol_clan      = $_POST['rol_clan'] ?? 'miembro';
    $fecha_ingreso = $_POST['fecha_ingreso'] ?: null;
    $activo        = isset($_POST['activo']) ? 1 : 0;
    $notas         = trim($_POST['notas'] ?? '') ?: null;

    // Agregar # si falta
    if ($tag !== '' && $tag[0] !== '#') {
        $tag = '#' . $tag;
    }

    if ($tag === '' || $nombre === '') {
        setFlash('error', 'Tag y nombre son obligatorios.');
        header('Location: jugadores.php?action=' . ($id ? 'edit&id=' . $id : 'create'));
        exit;
    }

    if ($id > 0) {
        // Editar
        $stmt = $db->prepare(
            'UPDATE jugadores SET tag=?, nombre=?, nivel_th=?, nivel_jugador=?, rol_clan=?, fecha_ingreso=?, activo=?, notas=?
             WHERE id=?'
        );
        $stmt->execute([$tag, $nombre, $nivel_th, $nivel_jugador, $rol_clan, $fecha_ingreso, $activo, $notas, $id]);
        logActivity('editar', 'jugadores', $id, 'Jugador: ' . $nombre);
        setFlash('success', 'Jugador actualizado.');
    } else {
        // Crear
        $stmt = $db->prepare(
            'INSERT INTO jugadores (tag, nombre, nivel_th, nivel_jugador, rol_clan, fecha_ingreso, activo, notas)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$tag, $nombre, $nivel_th, $nivel_jugador, $rol_clan, $fecha_ingreso, $activo, $notas]);
        $newId = (int) $db->lastInsertId();
        logActivity('crear', 'jugadores', $newId, 'Jugador: ' . $nombre);
        setFlash('success', 'Jugador registrado.');
    }

    header('Location: jugadores.php');
    exit;
}

// ── VISTA ─────────────────────────────────────────────────────
$pageTitle = 'Jugadores';

if ($action === 'create' || $action === 'edit') {
    $jugador = null;
    if ($action === 'edit' && isset($_GET['id'])) {
        $stmt = $db->prepare('SELECT * FROM jugadores WHERE id = ?');
        $stmt->execute([(int) $_GET['id']]);
        $jugador = $stmt->fetch();
        if (!$jugador) {
            setFlash('error', 'Jugador no encontrado.');
            header('Location: jugadores.php');
            exit;
        }
        $pageTitle = 'Editar Jugador';
    } else {
        $pageTitle = 'Nuevo Jugador';
    }

    require __DIR__ . '/includes/header.php';
    ?>

    <div class="ct-page-header">
        <h1><i class="bi bi-person-plus-fill"></i> <?= clean($pageTitle) ?></h1>
        <a href="jugadores.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= $jugador['id'] ?? 0 ?>">

                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="tag" class="form-label">Tag</label>
                        <input type="text" name="tag" id="tag" class="form-control" placeholder="#ABC123"
                               value="<?= clean($jugador['tag'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label for="nombre" class="form-label">Nombre</label>
                        <input type="text" name="nombre" id="nombre" class="form-control"
                               value="<?= clean($jugador['nombre'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label for="rol_clan" class="form-label">Rol en el Clan</label>
                        <select name="rol_clan" id="rol_clan" class="form-select">
                            <?php foreach (['lider','colider','veterano','miembro'] as $r): ?>
                                <option value="<?= $r ?>" <?= ($jugador['rol_clan'] ?? 'miembro') === $r ? 'selected' : '' ?>>
                                    <?= ucfirst($r) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="nivel_th" class="form-label">Town Hall</label>
                        <select name="nivel_th" id="nivel_th" class="form-select">
                            <?php for ($i = 1; $i <= 17; $i++): ?>
                                <option value="<?= $i ?>" <?= ($jugador['nivel_th'] ?? 1) == $i ? 'selected' : '' ?>>
                                    TH <?= $i ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="nivel_jugador" class="form-label">Nivel del Jugador</label>
                        <input type="number" name="nivel_jugador" id="nivel_jugador" class="form-control"
                               min="1" value="<?= (int) ($jugador['nivel_jugador'] ?? 1) ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="fecha_ingreso" class="form-label">Fecha de Ingreso</label>
                        <input type="date" name="fecha_ingreso" id="fecha_ingreso" class="form-control"
                               value="<?= clean($jugador['fecha_ingreso'] ?? '') ?>">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <div class="form-check">
                            <input type="checkbox" name="activo" id="activo" class="form-check-input"
                                   <?= ($jugador['activo'] ?? 1) ? 'checked' : '' ?>>
                            <label for="activo" class="form-check-label">Activo</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <label for="notas" class="form-label">Notas</label>
                        <textarea name="notas" id="notas" class="form-control" rows="3"><?= clean($jugador['notas'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar</button>
                    <a href="jugadores.php" class="btn btn-secondary ms-2">Cancelar</a>
                </div>
            </form>
        </div>
    </div>

    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ── LISTADO ───────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? 'activos';

$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = '(nombre LIKE ? OR tag LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($filter === 'activos') {
    $where[] = 'activo = 1';
} elseif ($filter === 'inactivos') {
    $where[] = 'activo = 0';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$jugadores = $db->prepare("SELECT * FROM jugadores {$whereSQL} ORDER BY nombre ASC");
$jugadores->execute($params);
$jugadores = $jugadores->fetchAll();

$rolBadge = [
    'lider'    => 'badge-gold',
    'colider'  => 'badge-purple',
    'veterano' => 'badge-blue',
    'miembro'  => 'badge-muted',
];

require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-people-fill"></i> Jugadores</h1>
    <a href="jugadores.php?action=create" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> Nuevo Jugador
    </a>
</div>

<!-- Filtros -->
<div class="card mb-4">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-auto">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Buscar por nombre o tag..."
                       value="<?= clean($search) ?>">
            </div>
            <div class="col-auto">
                <select name="filter" class="form-select form-select-sm">
                    <option value="activos"   <?= $filter === 'activos' ? 'selected' : '' ?>>Activos</option>
                    <option value="inactivos" <?= $filter === 'inactivos' ? 'selected' : '' ?>>Inactivos</option>
                    <option value="todos"     <?= $filter === 'todos' ? 'selected' : '' ?>>Todos</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-search"></i> Filtrar</button>
            </div>
        </form>
    </div>
</div>

<?php if (empty($jugadores)): ?>
    <div class="empty-state">
        <div class="empty-icon">🏰</div>
        <p>No hay jugadores registrados.</p>
        <a href="jugadores.php?action=create" class="btn btn-primary btn-sm">Agregar primer jugador</a>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Tag</th>
                        <th>Nombre</th>
                        <th>TH</th>
                        <th>Nivel</th>
                        <th>Rol</th>
                        <th>Ingreso</th>
                        <th>Estado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jugadores as $j): ?>
                        <tr>
                            <td><span style="color:var(--ct-cyan);font-weight:600"><?= clean($j['tag']) ?></span></td>
                            <td><?= clean($j['nombre']) ?></td>
                            <td><span class="badge badge-gold">TH<?= (int) $j['nivel_th'] ?></span></td>
                            <td><?= (int) $j['nivel_jugador'] ?></td>
                            <td><span class="badge <?= $rolBadge[$j['rol_clan']] ?? 'badge-muted' ?>"><?= ucfirst($j['rol_clan']) ?></span></td>
                            <td><?= $j['fecha_ingreso'] ? date('d/m/Y', strtotime($j['fecha_ingreso'])) : '—' ?></td>
                            <td>
                                <?php if ($j['activo']): ?>
                                    <span class="badge badge-green">Activo</span>
                                <?php else: ?>
                                    <span class="badge badge-red">Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a href="jugadores.php?action=edit&id=<?= $j['id'] ?>" class="btn btn-sm btn-outline-primary" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="jugadores.php?action=delete&id=<?= $j['id'] ?>&csrf_token=<?= csrfToken() ?>"
                                   class="btn btn-sm btn-danger" title="Eliminar"
                                   data-confirm="¿Eliminar a <?= clean($j['nombre']) ?>? Esto borrará todas sus participaciones.">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="text-muted text-center mt-3" style="font-size:.85rem">
        <?= count($jugadores) ?> jugador<?= count($jugadores) !== 1 ? 'es' : '' ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
