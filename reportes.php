<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db = getDB();

// ── Estadísticas por Jugador ──────────────────────────────────
$stats = $db->query(
    'SELECT j.id, j.usuario,
            COUNT(gp.guerra_id) AS total_guerras,
            SUM(CASE WHEN gp.ataque1_estrellas IS NOT NULL THEN 1 ELSE 0 END +
                CASE WHEN gp.ataque2_estrellas IS NOT NULL THEN 1 ELSE 0 END) AS ataques_realizados,
            SUM(CASE WHEN gp.ataque1_estrellas IS NULL THEN 1 ELSE 0 END +
                CASE WHEN gp.ataque2_estrellas IS NULL THEN 1 ELSE 0 END) AS ataques_perdidos,
            AVG(COALESCE(gp.ataque1_estrellas, 0) + COALESCE(gp.ataque2_estrellas, 0)) AS avg_estrellas,
            AVG(COALESCE(gp.ataque1_porcentaje, 0) + COALESCE(gp.ataque2_porcentaje, 0)) / 2 AS avg_destruccion
     FROM jugadores j
     LEFT JOIN guerra_participaciones gp ON j.id = gp.jugador_id
     WHERE j.activo = 1
     GROUP BY j.id
     ORDER BY ataques_perdidos DESC, avg_estrellas ASC'
)->fetchAll();

$pageTitle = 'Reporte de Rendimiento';
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-bar-chart-fill"></i> Reporte de Rendimiento</h1>
    <p class="text-muted">Análisis de ataques en guerra para identificar cumplimiento de normas.</p>
</div>

<div class="card">
    <div class="card-header bg-surface2">
        <i class="bi bi-exclamation-triangle-fill text-warning"></i> Jugadores con ataques perdidos (Prioridad para revisión)
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Usuario</th>
                    <th class="text-center">Guerras</th>
                    <th class="text-center">Ataques Realizados</th>
                    <th class="text-center text-danger">Ataques PERDIDOS</th>
                    <th class="text-center">Prom. Estrellas</th>
                    <th class="text-center">Prom. %</th>
                    <th class="text-center">Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stats as $s): 
                    $warningClass = $s['ataques_perdidos'] > 0 ? 'table-danger-soft' : '';
                    $pk = (int)$s['ataques_perdidos'];
                ?>
                    <tr class="<?= $warningClass ?>">
                        <td><strong><?= clean($s['usuario']) ?></strong></td>
                        <td class="text-center"><?= $s['total_guerras'] ?></td>
                        <td class="text-center"><?= $s['ataques_realizados'] ?></td>
                        <td class="text-center">
                            <?php if ($pk > 0): ?>
                                <span class="badge badge-red fs-6"><?= $pk ?></span>
                            <?php else: ?>
                                <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center text-gold"><?= number_format((float)$s['avg_estrellas'], 1) ?> ⭐</td>
                        <td class="text-center"><?= number_format((float)$s['avg_destruccion'], 1) ?>%</td>
                        <td class="text-center">
                            <?php if ($pk >= 4): ?>
                                <span class="badge badge-red">EXPULSIÓN</span>
                            <?php elseif ($pk >= 2): ?>
                                <span class="badge badge-gold">ADVERTENCIA</span>
                            <?php else: ?>
                                <span class="badge badge-green">CUMPLIDOR</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="alert alert-info mt-4">
    <i class="bi bi-info-circle"></i> <strong>Nota:</strong> Los ataques se consideran "perdidos" si el jugador fue agregado a una guerra pero no se registraron estrellas en sus ataques.
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>

<style>
.table-danger-soft {
    background-color: rgba(255, 107, 107, 0.05) !important;
}
</style>
