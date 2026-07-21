<?php
declare(strict_types=1);

/**
 * Único punto de entrada del cron. Corre cada 5 minutos y decide qué
 * toca según el calendario de abajo.
 *
 * Un solo trabajo en el panel del hosting, y el calendario en el código:
 * versionado, a la vista y con historial de quién lo cambió. Agregar una
 * tarea nueva no obliga a tocar la configuración del servidor.
 *
 * Cron de Hostinger:
 *   *\/5 * * * *  /usr/bin/php /home/USUARIO/.../\_coc/cron/run.php
 *
 * Uso manual, para depurar:
 *   php cron/run.php eventos     ejecuta esa tarea aunque no toque
 *   php cron/run.php --estado    muestra cuándo corrió cada una
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/tareas.php';

/**
 * Calendario. 'cada' son minutos; 'desdeHora' limita a partir de qué
 * hora local puede correr, para las que conviene que pasen de noche.
 */
const TAREAS = [
    'bot' => [
        'cada'      => 5,
        'desdeHora' => null,
        'fn'        => 'tareaBot',
        'describe'  => 'atender los comandos que llegaron por Telegram',
    ],
    'eventos' => [
        'cada'      => 5,
        'desdeHora' => null,
        'fn'        => 'tareaEventos',
        'describe'  => 'jugadores nuevos y cambios de guerra',
    ],
    'snapshot' => [
        'cada'      => 1380,   // 23 horas: así no se corre solo si un día se atrasa
        'desdeHora' => 23,
        'fn'        => 'tareaSnapshot',
        'describe'  => 'captura diaria completa y resumen',
    ],
];

$db       = getDB();
$forzada  = $argv[1] ?? null;

if ($forzada === '--estado') {
    mostrarEstado($db);
    exit;
}

// ── Candado ───────────────────────────────────────────────────
// La captura diaria tarda un par de minutos; sin candado, el tick
// siguiente la arrancaría otra vez encima de la anterior.
$lock = fopen(sys_get_temp_dir() . '/coc_cron.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Otra corrida sigue activa, se salta esta.\n";
    exit;
}

foreach (TAREAS as $nombre => $t) {
    if ($forzada !== null && $forzada !== $nombre) {
        continue;
    }
    if ($forzada === null && !toca($db, $nombre, $t)) {
        continue;
    }

    $inicio = date('Y-m-d H:i:s');
    echo "[$inicio] $nombre\n";

    try {
        $detalle = ($t['fn'])();
        $estado  = str_contains($detalle, 'ERROR') ? 'parcial' : 'ok';
    } catch (Throwable $e) {
        $detalle = $e->getMessage();
        $estado  = 'error';
    }

    echo "  $estado — $detalle\n";
    $db->prepare('INSERT INTO cron_ejecuciones (tarea, inicio, fin, estado, detalle) VALUES (?,?,NOW(),?,?)')
       ->execute([$nombre, $inicio, $estado, $detalle]);
}

flock($lock, LOCK_UN);
fclose($lock);

/**
 * ¿Le toca correr a esta tarea?
 *
 * @param array{cada:int,desdeHora:int|null} $t
 */
function toca(PDO $db, string $nombre, array $t): bool
{
    if ($t['desdeHora'] !== null && (int) date('G') < $t['desdeHora']) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT inicio FROM cron_ejecuciones
          WHERE tarea = ? AND estado <> 'error'
       ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$nombre]);
    $ultima = $stmt->fetchColumn();

    if (!$ultima) {
        return true;
    }

    return (time() - strtotime((string) $ultima)) >= $t['cada'] * 60;
}

function mostrarEstado(PDO $db): void
{
    echo "Tarea      Cada    Última corrida        Estado   Próxima\n";
    echo str_repeat('-', 72) . "\n";

    foreach (TAREAS as $nombre => $t) {
        $stmt = $db->prepare('SELECT inicio, estado FROM cron_ejecuciones WHERE tarea = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$nombre]);
        $u = $stmt->fetch();

        $proxima = '—';
        if ($u) {
            $siguiente = strtotime((string) $u['inicio']) + $t['cada'] * 60;
            $proxima   = $siguiente <= time() ? 'ahora' : date('d/m H:i', $siguiente);
        } else {
            $proxima = 'ahora';
        }

        printf(
            "%-10s %4dm   %-20s  %-7s  %s\n",
            $nombre,
            $t['cada'],
            $u ? $u['inicio'] : 'nunca',
            $u ? $u['estado'] : '—',
            $proxima
        );
    }
}
