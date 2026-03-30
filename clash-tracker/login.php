<?php
declare(strict_types=1);

/**
 * Pantalla de login.
 */

require_once __DIR__ . '/includes/auth.php';

// Ya logueado → dashboard
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Completa todos los campos.';
    } elseif (login($username, $password)) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Credenciales incorrectas.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión — <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/style.css" rel="stylesheet">
</head>
<body>
<div class="login-wrapper">
    <div class="login-box">
        <div class="login-title">⚔️ <?= APP_NAME ?></div>
        <p class="login-subtitle">Inicia sesión para gestionar tu clan</p>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2">
                <i class="bi bi-exclamation-circle"></i> <?= clean($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <?= csrfField() ?>
            <div class="mb-3">
                <label for="username" class="form-label">Usuario</label>
                <div class="input-group">
                    <span class="input-group-text" style="background:var(--ct-surface2);border-color:var(--ct-border);color:var(--ct-gold)">
                        <i class="bi bi-person-fill"></i>
                    </span>
                    <input type="text" name="username" id="username" class="form-control" placeholder="Tu usuario"
                           value="<?= clean($_POST['username'] ?? '') ?>" required autofocus>
                </div>
            </div>
            <div class="mb-4">
                <label for="password" class="form-label">Contraseña</label>
                <div class="input-group">
                    <span class="input-group-text" style="background:var(--ct-surface2);border-color:var(--ct-border);color:var(--ct-gold)">
                        <i class="bi bi-lock-fill"></i>
                    </span>
                    <input type="password" name="password" id="password" class="form-control" placeholder="Tu contraseña" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100 py-2">
                <i class="bi bi-box-arrow-in-right"></i> Entrar
            </button>
        </form>
    </div>
</div>
</body>
</html>
