<?php
declare(strict_types=1);

/**
 * Donaciones. Derivadas de las capturas diarias, no de captura manual.
 *
 * La API solo entrega el acumulado de la temporada en curso, que se
 * reinicia cada mes. El histórico se construye con lo que el cron va
 * guardando día a día.
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db = getDB();

$ultima = $db->query('SELECT MAX(fecha) FROM snapshots_jugador')->fetchColumn();

$filas = [];
if ($ultima) {
    $stmt = $db->prepare(
        'SELECT j.nombre_juego, j.tag, j.activo, s.donaciones, s.donaciones_recibidas, s.acum_donaciones
           FROM snapshots_jugador s
           JOIN jugadores j ON j.id = s.jugador_id
          WHERE s.fecha = ?
          ORDER BY s.donaciones DESC'
    );
    $stmt->execute([$ultima]);
    $filas = $stmt->fetchAll();
}

$totalDadas    = array_sum(array_column($filas, 'donaciones'));
$totalRecibidas = array_sum(array_column($filas, 'donaciones_recibidas'));
$dias = (int) $db->query('SELECT COUNT(DISTINCT fecha) FROM snapshots_jugador')->fetchColumn();

$pageTitle = 'Donaciones';
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-gift-fill"></i> Donaciones</h1>
    <span class="text-muted" style="font-size:.85rem">
        <?= $ultima ? 'Lectura del ' . date('d/m/Y', strtotime((string) $ultima)) : 'Sin lecturas' ?>
    </span>
</div>

<?php if (!$filas): ?>
    <div class="empty-state">
        <div class="empty-icon">🎁</div>
        <p>Sin lecturas todavía. La captura diaria las irá guardando.</p>
    </div>
<?php else: ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">📤</div><div class="stat-value"><?= number_format((int) $totalDadas) ?></div><div class="stat-label">Donadas</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">📥</div><div class="stat-value"><?= number_format((int) $totalRecibidas) ?></div><div class="stat-label">Recibidas</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">⚖️</div><div class="stat-value"><?= $totalRecibidas ? round($totalDadas / $totalRecibidas, 2) : '—' ?></div><div class="stat-label">Ratio del clan</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">📅</div><div class="stat-value"><?= $dias ?></div><div class="stat-label">Días capturados</div></div></div>
</div>

<div class="card"><div class="table-responsive">
    <table class="table table-hover mb-0">
        <thead><tr>
            <th class="text-center">#</th><th>Jugador</th>
            <th class="text-center">Donadas</th><th class="text-center">Recibidas</th>
            <th class="text-center">Ratio</th><th class="text-center">De por vida</th>
        </tr></thead>
        <tbody>
        <?php foreach ($filas as $i => $f):
            $rec   = (int) $f['donaciones_recibidas'];
            $dad   = (int) $f['donaciones'];
            $ratio = $rec > 0 ? round($dad / $rec, 2) : ($dad > 0 ? null : 0);
        ?>
            <tr>
                <td class="text-center text-muted"><?= $i + 1 ?></td>
                <td><strong class="text-white"><?= clean($f['nombre_juego']) ?></strong></td>
                <td class="text-center text-success"><strong><?= number_format($dad) ?></strong></td>
                <td class="text-center text-danger"><?= number_format($rec) ?></td>
                <td class="text-center">
                    <?php if ($ratio === null): ?>
                        <span class="badge badge-green">∞</span>
                    <?php else: ?>
                        <span class="badge <?= $ratio >= 1 ? 'badge-green' : ($ratio >= 0.5 ? 'badge-gold' : 'badge-red') ?>"><?= $ratio ?></span>
                    <?php endif; ?>
                </td>
                <td class="text-center text-muted"><?= $f['acum_donaciones'] !== null ? number_format((int) $f['acum_donaciones']) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<div class="text-muted text-center mt-3" style="font-size:.85rem">
    Las cifras de la temporada se reinician cada mes. La columna de por vida no.
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
