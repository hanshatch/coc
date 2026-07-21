<?php
declare(strict_types=1);

/**
 * Detalle de una temporada de CWL. Solo lectura desde la API.
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db = getDB();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM cwl_temporadas WHERE id = ?');
$stmt->execute([$id]);
$temp = $stmt->fetch();
if (!$temp) {
    setFlash('error', 'Temporada no encontrada.');
    header('Location: cwl');
    exit;
}

$stmt = $db->prepare(
    'SELECT p.*, j.nombre_juego, j.tag, j.activo
       FROM cwl_participaciones p
       JOIN jugadores j ON j.id = p.jugador_id
      WHERE p.temporada_id = ?
      ORDER BY p.estrellas DESC, p.porcentaje DESC'
);
$stmt->execute([$id]);
$filas = $stmt->fetchAll();

$totEstrellas = array_sum(array_column($filas, 'estrellas'));
$totAtaques   = array_sum(array_column($filas, 'ataques'));
$maxAtaques   = count($filas) * 7;

$pageTitle = 'Liga ' . $temp['mes'];
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-trophy-fill"></i> Liga <?= clean($temp['mes']) ?></h1>
    <a href="cwl" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">👥</div><div class="stat-value"><?= count($filas) ?></div><div class="stat-label">En el roster</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">⭐</div><div class="stat-value"><?= $totEstrellas ?></div><div class="stat-label">Estrellas</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">🎯</div><div class="stat-value"><?= $totAtaques ?>/<?= $maxAtaques ?></div><div class="stat-label">Ataques usados</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">📊</div><div class="stat-value"><?= $totAtaques ? round($totEstrellas / $totAtaques, 1) : 0 ?></div><div class="stat-label">Estrellas por ataque</div></div></div>
</div>

<?php if (!$filas): ?>
    <div class="empty-state"><div class="empty-icon">🏆</div><p>Sin participaciones registradas.</p></div>
<?php else: ?>
<div class="card"><div class="table-responsive">
    <table class="table table-hover mb-0">
        <thead><tr>
            <th class="text-center">#</th><th>Jugador</th>
            <th class="text-center">Estrellas</th><th class="text-center">Ataques</th>
            <th class="text-center">Destrucción</th><th class="text-center">Promedio</th>
        </tr></thead>
        <tbody>
        <?php foreach ($filas as $i => $f):
            $prom = $f['ataques'] ? round((int) $f['estrellas'] / (int) $f['ataques'], 2) : 0;
        ?>
            <tr<?= $f['ataques'] == 0 ? ' class="table-danger-subtle"' : '' ?>>
                <td class="text-center text-muted"><?= $i + 1 ?></td>
                <td>
                    <strong class="text-white"><?= clean($f['nombre_juego']) ?></strong>
                    <?php if (!$f['activo']): ?><span class="badge badge-red ms-1">Ya no está</span><?php endif; ?>
                </td>
                <td class="text-center"><strong><?= (int) $f['estrellas'] ?></strong> <span class="text-muted">/21</span></td>
                <td class="text-center">
                    <span class="badge <?= $f['ataques'] >= 6 ? 'badge-green' : ((int) $f['ataques'] >= 4 ? 'badge-gold' : 'badge-red') ?>">
                        <?= (int) $f['ataques'] ?>/7
                    </span>
                </td>
                <td class="text-center"><?= number_format((float) $f['porcentaje'], 0) ?>%</td>
                <td class="text-center"><?= $prom ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<div class="text-muted text-center mt-3" style="font-size:.85rem">
    Cada jugador puede atacar una vez por ronda, siete en total. Las filas resaltadas no atacaron ninguna vez.
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
