<?php
declare(strict_types=1);

/**
 * Detalle de un fin de semana de asalto. Solo lectura desde la API.
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db = getDB();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM capital_semanas WHERE id = ?');
$stmt->execute([$id]);
$semana = $stmt->fetch();
if (!$semana) {
    setFlash('error', 'Fin de semana no encontrado.');
    header('Location: capital');
    exit;
}

$stmt = $db->prepare(
    'SELECT p.*, j.nombre_juego, j.tag, j.activo
       FROM capital_participaciones p
       JOIN jugadores j ON j.id = p.jugador_id
      WHERE p.semana_id = ?
      ORDER BY p.oro_aportado DESC'
);
$stmt->execute([$id]);
$filas = $stmt->fetchAll();

$oroJugadores = array_sum(array_column($filas, 'oro_aportado'));

$pageTitle = 'Capital ' . date('d/m/Y', strtotime($semana['fecha_inicio']));
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-building-fill"></i> Asalto del <?= date('d/m/Y', strtotime($semana['fecha_inicio'])) ?></h1>
    <a href="capital" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">🪙</div><div class="stat-value"><?= number_format((int) $semana['oro_total_recaudado']) ?></div><div class="stat-label">Oro del clan</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">👥</div><div class="stat-value"><?= count($filas) ?></div><div class="stat-label">Participantes</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">⚔️</div><div class="stat-value"><?= (int) $semana['ataques_totales'] ?></div><div class="stat-label">Ataques</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">🏘️</div><div class="stat-value"><?= (int) $semana['distritos_destruidos'] ?></div><div class="stat-label">Distritos</div></div></div>
</div>

<?php if (!$filas): ?>
    <div class="empty-state"><div class="empty-icon">🏰</div><p>Sin desglose por jugador para este fin de semana.</p></div>
<?php else: ?>
<div class="card"><div class="table-responsive">
    <table class="table table-hover mb-0">
        <thead><tr>
            <th class="text-center">#</th><th>Jugador</th>
            <th class="text-center">Oro aportado</th><th class="text-center">Ataques</th><th class="text-center">Oro por ataque</th>
        </tr></thead>
        <tbody>
        <?php foreach ($filas as $i => $f):
            $porAtaque = $f['ataques_realizados'] ? (int) round((int) $f['oro_aportado'] / (int) $f['ataques_realizados']) : 0;
        ?>
            <tr>
                <td class="text-center text-muted"><?= $i + 1 ?></td>
                <td>
                    <strong class="text-white"><?= clean($f['nombre_juego']) ?></strong>
                    <?php if (!$f['activo']): ?><span class="badge badge-red ms-1">Ya no está</span><?php endif; ?>
                </td>
                <td class="text-center"><strong><?= number_format((int) $f['oro_aportado']) ?></strong></td>
                <td class="text-center">
                    <span class="badge <?= $f['ataques_realizados'] >= 6 ? 'badge-green' : ((int) $f['ataques_realizados'] >= 4 ? 'badge-gold' : 'badge-red') ?>">
                        <?= (int) $f['ataques_realizados'] ?>
                    </span>
                </td>
                <td class="text-center text-muted"><?= number_format($porAtaque) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<div class="text-muted text-center mt-3" style="font-size:.85rem">
    Los jugadores suman <?= number_format((int) $oroJugadores) ?> de los <?= number_format((int) $semana['oro_total_recaudado']) ?> del clan.
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
