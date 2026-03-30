<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireAdmin();

$db     = getDB();
$action = $_GET['action'] ?? 'list';

if ($action === 'delete' && isset($_GET['id'])) {
    verifyCsrf();
    $id = (int) $_GET['id'];
    if ($id === currentUser()['id']) {
        setFlash('error', 'No puedes eliminarte a ti mismo.');
    } else {
        $db->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
        logActivity('eliminar', 'usuarios', $id);
        setFlash('success', 'Usuario eliminado.');
    }
    header('Location: usuarios'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $id       = (int) ($_POST['id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $nombre   = trim($_POST['usuario'] ?? '');
    $rol      = $_POST['rol'] ?? 'editor';
    $password = $_POST['password'] ?? '';
    $activo   = isset($_POST['activo']) ? 1 : 0;

    if ($username === '' || $nombre === '') {
        setFlash('error', 'Usuario y nombre son obligatorios.');
        header('Location: usuarios?action=' . ($id ? 'edit&id=' . $id : 'create')); exit;
    }

    if ($id > 0) {
        $sql = 'UPDATE usuarios SET username=?, nombre=?, rol=?, activo=?';
        $params = [$username, $nombre, $rol, $activo];
        if ($password !== '') {
            $sql .= ', password_hash=?';
            $params[] = password_hash($password, PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE id=?';
        $params[] = $id;
        $db->prepare($sql)->execute($params);
        logActivity('editar', 'usuarios', $id, 'Usuario: ' . $username);
        setFlash('success', 'Usuario actualizado.');
    } else {
        if ($password === '') {
            setFlash('error', 'La contraseña es obligatoria para nuevos usuarios.');
            header('Location: usuarios?action=create'); exit;
        }
        $db->prepare('INSERT INTO usuarios (username, nombre, rol, activo, password_hash) VALUES (?, ?, ?, ?, ?)')
           ->execute([$username, $nombre, $rol, $activo, password_hash($password, PASSWORD_DEFAULT)]);
        logActivity('crear', 'usuarios', (int) $db->lastInsertId(), 'Usuario: ' . $username);
        setFlash('success', 'Usuario creado.');
    }
    header('Location: usuarios'); exit;
}

if ($action === 'create' || $action === 'edit') {
    $u = null;
    if ($action === 'edit' && isset($_GET['id'])) {
        $stmt = $db->prepare('SELECT * FROM usuarios WHERE id = ?');
        $stmt->execute([(int) $_GET['id']]);
        $u = $stmt->fetch();
    }
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="ct-page-header">
        <h1><i class="bi bi-shield-lock-fill"></i> <?= $u ? 'Editar Usuario' : 'Nuevo Usuario' ?></h1>
        <a href="usuarios" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>
    <div class="card"><div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= $u['id'] ?? 0 ?>">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Usuario (login)</label><input type="text" name="username" class="form-control" value="<?= clean($u['username'] ?? '') ?>" required></div>
                <div class="col-md-6"><label class="form-label">Nombre completo</label><input type="text" name="nombre" class="form-control" value="<?= clean($u['usuario'] ?? '') ?>" required></div>
                <div class="col-md-6"><label class="form-label">Contraseña</label><input type="password" name="password" class="form-control" <?= $u ? '' : 'required' ?> placeholder="<?= $u ? 'Dejar vacío para no cambiar' : 'Ingresa contraseña' ?>"></div>
                <div class="col-md-3">
                    <label class="form-label">Rol</label>
                    <select name="rol" class="form-select">
                        <option value="editor" <?= ($u['rol'] ?? 'editor') === 'editor' ? 'selected' : '' ?>>Editor</option>
                        <option value="admin" <?= ($u['rol'] ?? '') === 'admin' ? 'selected' : '' ?>>Administrador</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input type="checkbox" name="activo" id="activo" class="form-check-input" <?= ($u['activo'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label">Activo</label></div></div>
            </div>
            <div class="mt-4"><button type="submit" class="btn btn-primary">Guardar</button></div>
        </form>
    </div></div>
    <?php require __DIR__ . '/includes/footer.php'; exit;
}

// ── LISTADO ───────────────────────────────────────────────────
$usuariosArr = $db->query('SELECT * FROM usuarios ORDER BY username ASC')->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<div class="ct-page-header"><h1><i class="bi bi-shield-lock-fill"></i> Gestión de Usuarios</h1><a href="usuarios?action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nuevo Usuario</a></div>
<div class="card"><div class="table-responsive">
    <table class="table table-hover mb-0">
        <thead><tr><th>Usuario</th><th>Nombre</th><th>Rol</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
        <tbody>
            <?php foreach ($usuariosArr as $u): ?>
            <tr>
                <td><strong><?= clean($u['username']) ?></strong></td>
                <td><?= clean($u['usuario']) ?></td>
                <td><span class="badge <?= $u['rol'] === 'admin' ? 'badge-gold' : 'badge-blue' ?>"><?= $u['rol'] ?></span></td>
                <td><span class="badge <?= $u['activo'] ? 'badge-green' : 'badge-red' ?>"><?= $u['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
                <td class="text-end">
                    <a href="usuarios?action=edit&id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                    <?php if ($u['id'] !== currentUser()['id']): ?>
                        <a href="usuarios?action=delete&id=<?= $u['id'] ?>&csrf_token=<?= csrfToken() ?>" class="btn btn-sm btn-danger" data-confirm="¿Eliminar este usuario?"><i class="bi bi-trash"></i></a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
