<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db     = getDB();

// ── AUTO-UPDATE DB (Manejo de transición Tag -> Usuario) ─────
try {
    $check = $db->query("SHOW COLUMNS FROM jugadores LIKE 'usuario'");
    if (!$check->fetch()) {
        // Si no existe 'usuario', intentamos renombrar 'tag'
        $checkTag = $db->query("SHOW COLUMNS FROM jugadores LIKE 'tag'");
        if ($checkTag->fetch()) {
            $db->exec("ALTER TABLE jugadores CHANGE tag usuario VARCHAR(50) NOT NULL");
            // También quitamos columnas viejas si existen
            $db->exec("ALTER TABLE jugadores DROP COLUMN IF EXISTS nombre");
            $db->exec("ALTER TABLE jugadores DROP COLUMN IF EXISTS nivel_th");
            $db->exec("ALTER TABLE jugadores DROP COLUMN IF EXISTS nivel_jugador");
        }
    }
} catch (Exception $e) {
    // Ignoramos errores de auto-update para no bloquear la app
}

$action = $_GET['action'] ?? 'list';

// ── ELIMINAR ──────────────────────────────────────────────────
if ($action === 'delete' && isset($_GET['id'])) {
    verifyCsrf();
    $id   = (int) $_GET['id'];
    $stmt = $db->prepare('SELECT usuario FROM jugadores WHERE id = ?');
    $stmt->execute([$id]);
    $j = $stmt->fetch();

    if ($j) {
        $db->prepare('DELETE FROM jugadores WHERE id = ?')->execute([$id]);
        logActivity('eliminar', 'jugadores', $id, 'Usuario: ' . $j['usuario']);
        setFlash('success', 'Jugador eliminado correctamente.');
    }
    header('Location: jugadores');
    exit;
}

// ── GUARDAR (crear / editar) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $id            = (int) ($_POST['id'] ?? 0);
    $usuario       = strtoupper(trim($_POST['usuario'] ?? ''));
    $rol_clan      = $_POST['rol_clan'] ?? 'miembro';
    $fecha_ingreso = $_POST['fecha_ingreso'] ?: null;
    $activo        = isset($_POST['activo']) ? 1 : 0;
    $notas         = trim($_POST['notas'] ?? '') ?: null;

    // Agregar # si falta
    if ($usuario !== '' && $usuario[0] !== '#') {
        $usuario = '#' . $usuario;
    }

    if ($usuario === '') {
        setFlash('error', 'El usuario (tag) es obligatorio.');
        header('Location: jugadores?action=' . ($id ? 'edit&id=' . $id : 'create'));
        exit;
    }

    if ($id > 0) {
        // Editar
        $stmt = $db->prepare(
            'UPDATE jugadores SET usuario=?, rol_clan=?, fecha_ingreso=?, activo=?, notas=?
             WHERE id=?'
        );
        $stmt->execute([$usuario, $rol_clan, $fecha_ingreso, $activo, $notas, $id]);
        logActivity('editar', 'jugadores', $id, 'Usuario: ' . $usuario);
        setFlash('success', 'Jugador actualizado.');
    } else {
        // Crear
        $stmt = $db->prepare(
            'INSERT INTO jugadores (usuario, rol_clan, fecha_ingreso, activo, notas)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$usuario, $rol_clan, $fecha_ingreso, $activo, $notas]);
        $newId = (int) $db->lastInsertId();
        logActivity('crear', 'jugadores', $newId, 'Usuario: ' . $usuario);
        setFlash('success', 'Jugador registrado.');
    }

    header('Location: jugadores');
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
            header('Location: jugadores');
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
        <a href="jugadores" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= $jugador['id'] ?? 0 ?>">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="usuario" class="form-label">Usuario</label>
                        <input type="text" name="usuario" id="usuario" class="form-control" placeholder="#ABC123"
                               value="<?= clean($jugador['usuario'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="rol_clan" class="form-label">Rol en el Clan</label>
                        <select name="rol_clan" id="rol_clan" class="form-select">
                            <?php foreach (['lider','colider','veterano','miembro'] as $r): ?>
                                <option value="<?= $r ?>" <?= ($jugador['rol_clan'] ?? 'miembro') === $r ? 'selected' : '' ?>>
                                    <?= ucfirst($r) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="fecha_ingreso" class="form-label">Fecha de Ingreso</label>
                        <input type="date" name="fecha_ingreso" id="fecha_ingreso" class="form-control"
                               value="<?= clean($jugador['fecha_ingreso'] ?? '') ?>">
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
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
                    <a href="jugadores" class="btn btn-secondary ms-2">Cancelar</a>
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
    $where[]  = 'usuario LIKE ?';
    $params[] = "%{$search}%";
}

if ($filter === 'activos') {
    $where[] = 'activo = 1';
} elseif ($filter === 'inactivos') {
    $where[] = 'activo = 0';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$jugadores = $db->prepare("SELECT * FROM jugadores {$whereSQL} ORDER BY usuario ASC");
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
    <a href="jugadores?action=create" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> Nuevo Jugador
    </a>
</div>

<!-- Filtros -->
<div class="card mb-4">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-auto">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Buscar por usuario..."
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
        <a href="jugadores?action=create" class="btn btn-primary btn-sm">Agregar primer jugador</a>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Usuario (Tag)</th>
                        <th>Rol</th>
                        <th>Ingreso</th>
                        <th>Estado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jugadores as $j): ?>
                        <tr>
                            <td><span class="text-white fw-bold"><?= clean($j['usuario']) ?></span></td>
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
                                <a href="jugadores?action=edit&id=<?= $j['id'] ?>" class="btn btn-sm btn-outline-primary" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="jugadores?action=delete&id=<?= $j['id'] ?>&csrf_token=<?= csrfToken() ?>"
                                   class="btn btn-sm btn-danger" title="Eliminar"
                                   data-confirm="¿Eliminar a <?= clean($j['usuario']) ?>? Esto borrará todas sus participaciones.">
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
