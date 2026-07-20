<?php
declare(strict_types=1);

/**
 * Capital de Clan. Solo lectura desde la API.
 * El desglose por jugador solo existe para el fin de semana más reciente:
 * los anteriores conservan únicamente los totales del clan.
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db = getDB();
$semanas = $db->query(
    'SELECT s.*, COUNT(p.id) participantes, SUM(p.oro_aportado) oro_jugadores
       FROM capital_semanas s
       LEFT JOIN capital_participaciones p ON p.semana_id = s.id
      GROUP BY s.id
      ORDER BY s.fecha_inicio DESC'
)->fetchAll();

$totalOro = array_sum(array_column($semanas, 'oro_total_recaudado'));

$pageTitle = 'Capital de Clan';
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-building-fill"></i> Capital de Clan</h1>
</div>

<?php if (!$semanas): ?>
    <div class="empty-state">
        <div class="empty-icon">🏰</div>
        <p>Sin fines de semana de asalto. La captura automática los trae cada noche.</p>
    </div>
<?php else: ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4"><div class="stat-card"><div class="stat-icon">📅</div><div class="stat-value"><?= count($semanas) ?></div><div class="stat-label">Fines de semana</div></div></div>
    <div class="col-6 col-md-4"><div class="stat-card"><div class="stat-icon">🪙</div><div class="stat-value"><?= number_format((int) $totalOro) ?></div><div class="stat-label">Oro acumulado</div></div></div>
    <div class="col-12 col-md-4"><div class="stat-card"><div class="stat-icon">📈</div><div class="stat-value"><?= number_format((int) round($totalOro / max(1, count($semanas)))) ?></div><div class="stat-label">Promedio por fin de semana</div></div></div>
</div>

<div class="card"><div class="table-responsive">
    <table class="table table-hover mb-0">
        <thead><tr>
            <th>Fin de semana</th><th class="text-center">Oro</th><th class="text-center">Ataques</th>
            <th class="text-center">Distritos</th><th class="text-center">Detalle</th><th class="text-end"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($semanas as $s): ?>
            <tr>
                <td><?= date('d/m', strtotime($s['fecha_inicio'])) ?> — <?= date('d/m/Y', strtotime($s['fecha_fin'])) ?></td>
                <td class="text-center"><strong><?= number_format((int) $s['oro_total_recaudado']) ?></strong></td>
                <td class="text-center"><?= (int) $s['ataques_totales'] ?></td>
                <td class="text-center"><?= (int) $s['distritos_destruidos'] ?></td>
                <td class="text-center">
                    <?php if ($s['participantes']): ?>
                        <span class="badge badge-green"><?= (int) $s['participantes'] ?> jugadores</span>
                    <?php else: ?>
                        <span class="text-muted" title="La API ya no conserva el desglose de esta semana">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-end">
                    <?php if ($s['participantes']): ?>
                        <a href="capital_detalle?id=<?= (int) $s['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<div class="text-muted text-center mt-3" style="font-size:.85rem">
    Solo el fin de semana más reciente trae desglose por jugador. Los anteriores se capturaron cuando aún estaba disponible.
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
