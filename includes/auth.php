<?php
declare(strict_types=1);

/**
 * Autenticación, sesiones, CSRF, helpers y logging.
 */

require_once __DIR__ . '/../config/database.php';

// Cierra la sesión tras este tiempo sin actividad.
define('SESSION_IDLE_TIMEOUT', 7200); // 2 horas

// Longitud mínima de contraseña al crear o cambiar un usuario.
define('PASSWORD_MIN_LENGTH', 12);

// ── Sesión ────────────────────────────────────────────────────

/**
 * ¿La petición llegó por HTTPS? Contempla el proxy inverso de Hostinger,
 * que termina TLS y reenvía por HTTP con X-Forwarded-Proto.
 */
function isHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Expiración por inactividad. No redirige aquí para no crear un bucle en
// login.php: al vaciar user_id, requireLogin() se encarga del redirect.
if (isset($_SESSION['last_activity'])
    && (time() - (int) $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
    $_SESSION = [];
    session_regenerate_id(true);
    setFlash('error', 'Tu sesión expiró por inactividad. Inicia sesión de nuevo.');
}
$_SESSION['last_activity'] = time();

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

// ── Control de fuerza bruta ───────────────────────────────────

define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_WINDOW_SECONDS', 900); // 15 minutos

function clientIp(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/**
 * Intentos fallidos recientes para este usuario o esta IP.
 * Si la tabla aún no existe, devuelve 0 y lo registra: preferimos no
 * dejar a todo el clan fuera del sistema por una migración pendiente.
 */
function failedLoginCount(string $username): int
{
    try {
        $stmt = getDB()->prepare(
            'SELECT COUNT(*) FROM login_intentos
             WHERE exitoso = 0
               AND intentado_en > (NOW() - INTERVAL ' . LOGIN_WINDOW_SECONDS . ' SECOND)
               AND (username = ? OR ip = ?)'
        );
        $stmt->execute([$username, clientIp()]);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('Rate limit no disponible: ' . $e->getMessage());
        return 0;
    }
}

function loginIsBlocked(string $username): bool
{
    return failedLoginCount($username) >= LOGIN_MAX_ATTEMPTS;
}

function recordLoginAttempt(string $username, bool $success): void
{
    try {
        $db = getDB();
        $db->prepare('INSERT INTO login_intentos (username, ip, exitoso) VALUES (?, ?, ?)')
           ->execute([mb_substr($username, 0, 50), clientIp(), $success ? 1 : 0]);

        if ($success) {
            // Limpieza oportunista del histórico.
            $db->exec('DELETE FROM login_intentos WHERE intentado_en < (NOW() - INTERVAL 7 DAY)');
        }
    } catch (PDOException $e) {
        error_log('No se pudo registrar el intento de login: ' . $e->getMessage());
    }
}

function login(string $username, string $password): bool
{
    if (loginIsBlocked($username)) {
        return false;
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM usuarios WHERE username = ? AND activo = 1 LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        recordLoginAttempt($username, true);
        session_regenerate_id(true);
        $_SESSION['user_id']  = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['nombre']   = $user['nombre'];
        $_SESSION['rol']      = $user['rol'];
        return true;
    }

    // Iguala el costo de un usuario inexistente al de uno real, para que el
    // tiempo de respuesta no revele qué usuarios existen.
    if (!$user) {
        password_verify($password, '$2y$12$dC5L4VkzdO.ImBZejADt/ORjgZewUUZkL3tQfKkFMV21oJ.ezCONK');
    }

    recordLoginAttempt($username, false);
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

/**
 * Botón de borrado como formulario POST con token CSRF.
 * Evita exponer el token en la URL y que un prefetch dispare el borrado.
 *
 * @param string               $url     Destino del formulario
 * @param array<string,scalar> $fields  Campos ocultos (ej. ['id' => 5])
 */
function deleteButton(
    string $url,
    array $fields,
    string $confirm,
    string $title = 'Eliminar',
    string $class = 'btn btn-sm btn-danger',
    string $icon = 'bi-trash'
): string {
    $html = '<form method="POST" action="' . clean($url) . '" class="d-inline">'
          . csrfField()
          . '<input type="hidden" name="_action" value="delete">';

    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . clean($name) . '" value="' . clean((string) $value) . '">';
    }

    return $html
        . '<button type="submit" class="' . clean($class) . '" title="' . clean($title) . '"'
        . ' data-confirm="' . clean($confirm) . '">'
        . '<i class="bi ' . clean($icon) . '"></i></button>'
        . '</form>';
}

function isDeleteRequest(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete';
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
