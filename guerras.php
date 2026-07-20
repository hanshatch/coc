<?php
declare(strict_types=1);

/**
 * Registro de guerras. Solo lectura desde la API.
 *
 * La API entrega únicamente totales del clan: el desglose por jugador
 * solo existe mientras la guerra está en curso, no en el historial.
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db      = getDB();
$guerras = $db->query('SELECT * FROM guerras ORDER BY fecha DESC')->fetchAll();

$stats = $db->query(
    "SELECT COUNT(*) total,
            SUM(resultado='victoria') ganadas,
            SUM(resultado='derrota')  perdidas,
            SUM(resultado='empate')   empatadas,
            SUM(estrellas_clan) estrellas,
            SUM(estrellas_oponente) estrellas_rival
       FROM guerras"
)->fetch();

$badge = ['victoria'=>'badge-green','derrota'=>'badge-red','empate'=>'badge-muted','en_curso'=>'badge-gold'];

$pageTitle = 'Guerras';
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-lightning-fill"></i> Guerras</h1>
</div>

<?php if (!$guerras): ?>
    <div class="empty-state">
        <div class="empty-icon">⚔️</div>
        <p>Sin guerras registradas. La captura automática trae el historial cada noche.</p>
    </div>
<?php else:
    $t = max(1, (int) $stats['total']);
?>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">⚔️</div><div class="stat-value"><?= (int) $stats['total'] ?></div><div class="stat-label">Guerras</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">🏆</div><div class="stat-value"><?= round((int) $stats['ganadas'] * 100 / $t) ?>%</div><div class="stat-label">Victorias</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">⭐</div><div class="stat-value"><?= number_format((int) $stats['estrellas']) ?></div><div class="stat-label">Estrellas a favor</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">🛡️</div><div class="stat-value"><?= number_format((int) $stats['estrellas_rival']) ?></div><div class="stat-label">Estrellas en contra</div></div></div>
</div>

<div class="card"><div class="table-responsive">
    <table class="table table-hover mb-0">
        <thead><tr>
            <th>Fecha</th><th>Oponente</th><th class="text-center">Tamaño</th>
            <th class="text-center">Estrellas</th><th class="text-center">Destrucción</th><th>Resultado</th>
        </tr></thead>
        <tbody>
        <?php foreach ($guerras as $g): ?>
            <tr>
                <td><?= date('d/m/Y', strtotime($g['fecha'])) ?></td>
                <td>
                    <?php if ($g['oponente'] === 'Desconocido'): ?>
                        <span class="text-muted" title="El clan rival tiene su registro de guerras en privado">Desconocido</span>
                    <?php else: ?>
                        <strong><?= clean($g['oponente']) ?></strong>
                    <?php endif; ?>
                </td>
                <td class="text-center"><?= (int) $g['tamano'] ?>v<?= (int) $g['tamano'] ?></td>
                <td class="text-center">
                    <strong class="<?= $g['estrellas_clan'] >= $g['estrellas_oponente'] ? 'text-success' : 'text-danger' ?>"><?= (int) $g['estrellas_clan'] ?></strong>
                    <span class="text-muted">–</span><?= (int) $g['estrellas_oponente'] ?>
                </td>
                <td class="text-center">
                    <?= $g['destruccion_clan'] !== null ? number_format((float) $g['destruccion_clan'], 1) . '%' : '—' ?>
                    <span class="text-muted">vs</span>
                    <?= $g['destruccion_oponente'] !== null ? number_format((float) $g['destruccion_oponente'], 1) . '%' : '—' ?>
                </td>
                <td><span class="badge <?= $badge[$g['resultado']] ?? 'badge-muted' ?>"><?= ucfirst($g['resultado']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<div class="text-muted text-center mt-3" style="font-size:.85rem">
    La API no conserva el detalle por jugador de guerras pasadas, solo los totales del clan.
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
