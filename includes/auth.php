<?php
declare(strict_types=1);

/**
 * Autenticación, sesiones, CSRF, helpers y logging.
 */

require_once __DIR__ . '/../config/database.php';

// Iniciar sesión si no está activa
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Autenticación ─────────────────────────────────────────────

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: login');
        exit;
    }
}

function requireAdmin(): void
{
    requireLogin();
    if (currentUser()['rol'] !== 'admin') {
        http_response_code(403);
        die('Acceso denegado. Se requiere rol de administrador.');
    }
}

/**
 * @return array{id: int, username: string, nombre: string, rol: string}|null
 */
function currentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }

    return [
        'id'       => (int) $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'nombre'   => $_SESSION['nombre'],
        'rol'      => $_SESSION['rol'],
    ];
}

function login(string $username, string $password): bool
{
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM usuarios WHERE username = ? AND activo = 1 LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id']  = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['nombre']   = $user['nombre'];
        $_SESSION['rol']      = $user['rol'];
        return true;
    }

    return false;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}

// ── CSRF ──────────────────────────────────────────────────────

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrfToken(), $token)) {
        http_response_code(403);
        die('Token CSRF inválido.');
    }
}

// ── Flash Messages ────────────────────────────────────────────

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * @return array{type: string, message: string}|null
 */
function getFlash(): ?array
{
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// ── Sanitización ──────────────────────────────────────────────

function clean(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// ── Log de actividad ──────────────────────────────────────────

function logActivity(string $accion, string $tabla, ?int $registroId = null, ?string $detalle = null): void
{
    if (!isLoggedIn()) {
        return;
    }

    $db   = getDB();
    $stmt = $db->prepare(
        'INSERT INTO log_actividad (usuario_id, accion, tabla_afectada, registro_id, detalle)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        currentUser()['id'],
        $accion,
        $tabla,
        $registroId,
        $detalle,
    ]);
}

// ── Paginación ────────────────────────────────────────────────

/**
 * @return array{limit: int, offset: int, page: int}
 */
function paginate(int $perPage = 20): array
{
    $page   = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    return [
        'limit'  => $perPage,
        'offset' => $offset,
        'page'   => $page,
    ];
}

function paginationLinks(int $totalRows, int $perPage, int $currentPage, string $baseUrl = ''): string
{
    $totalPages = max(1, (int) ceil($totalRows / $perPage));

    if ($totalPages <= 1) {
        return '';
    }

    // Preserve existing querystring params
    $params = $_GET;
    unset($params['page']);
    $qs = http_build_query($params);
    $sep = $qs ? '&' : '';

    $html = '<nav><ul class="pagination pagination-sm justify-content-center">';

    // Prev
    if ($currentPage > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?page=' . ($currentPage - 1) . $sep . $qs . '">&laquo;</a></li>';
    }

    for ($i = 1; $i <= $totalPages; $i++) {
        $active = $i === $currentPage ? ' active' : '';
        $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . $baseUrl . '?page=' . $i . $sep . $qs . '">' . $i . '</a></li>';
    }

    // Next
    if ($currentPage < $totalPages) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?page=' . ($currentPage + 1) . $sep . $qs . '">&laquo;</a></li>';
    }

    $html .= '</ul></nav>';
    return $html;
}
