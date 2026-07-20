<?php
declare(strict_types=1);

/**
 * Dashboard. Estado del clan según la última captura.
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db = getDB();

$clan    = $db->query('SELECT * FROM snapshots_clan ORDER BY fecha DESC LIMIT 1')->fetch();
$lectura = $db->query('SELECT MAX(fecha) FROM snapshots_jugador')->fetchColumn();
$activos = (int) $db->query('SELECT COUNT(*) FROM jugadores WHERE activo = 1')->fetchColumn();

$guerras = $db->query(
    "SELECT COUNT(*) total, SUM(resultado='victoria') ganadas FROM guerras"
)->fetch();

$capital = $db->query('SELECT * FROM capital_semanas ORDER BY fecha_inicio DESC LIMIT 1')->fetch();

$cwl = $db->query(
    'SELECT t.mes, COUNT(p.id) jugadores, SUM(p.estrellas) estrellas, SUM(p.ataques) ataques
       FROM cwl_temporadas t
       JOIN cwl_participaciones p ON p.temporada_id = t.id
   GROUP BY t.id ORDER BY t.mes DESC LIMIT 1'
)->fetch();

// Los que no atacaron en la última CWL: la señal más accionable.
$flojos = [];
if ($cwl) {
    $flojos = $db->query(
        "SELECT j.nombre_juego, p.ataques, p.estrellas
           FROM cwl_participaciones p
           JOIN jugadores j ON j.id = p.jugador_id
           JOIN cwl_temporadas t ON t.id = p.temporada_id
          WHERE j.activo = 1 AND p.ataques < 4
            AND t.mes = " . $db->quote((string) $cwl['mes']) . "
       ORDER BY p.ataques ASC LIMIT 8"
    )->fetchAll();
}

$ultimoCron = $db->query('SELECT * FROM cron_ejecuciones ORDER BY id DESC LIMIT 1')->fetch();

$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-speedometer2"></i> Dashboard</h1>
    <span class="text-muted" style="font-size:.85rem">
        <?= $lectura ? 'Última captura: ' . date('d/m/Y', strtotime((string) $lectura)) : 'Sin capturas' ?>
    </span>
</div>

<?php if (!$clan): ?>
    <div class="empty-state">
        <div class="empty-icon">🏰</div>
        <p>Sin datos todavía. La captura automática corre cada noche a las 23:30.</p>
    </div>
<?php else: ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">👥</div><div class="stat-value"><?= $activos ?>/50</div><div class="stat-label">Miembros</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">🏅</div><div class="stat-value"><?= number_format((int) $clan['puntos_clan']) ?></div><div class="stat-label">Puntos del clan</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">⚔️</div><div class="stat-value"><?= (int) $clan['guerras_ganadas'] ?></div><div class="stat-label">Guerras ganadas</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">🔥</div><div class="stat-value"><?= (int) $clan['racha_victorias'] ?></div><div class="stat-label">Racha actual</div></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-trophy-fill"></i> Última CWL</div>
            <div class="card-body">
                <?php if ($cwl): ?>
                    <h4 class="text-white mb-1"><?= clean((string) $cwl['mes']) ?></h4>
                    <p class="mb-1"><?= (int) $cwl['estrellas'] ?> estrellas con <?= (int) $cwl['ataques'] ?> ataques</p>
                    <p class="text-muted mb-0" style="font-size:.85rem">
                        <?= (int) $cwl['jugadores'] ?> jugadores ·
                        <?= (int) $cwl['ataques'] ? round((int) $cwl['estrellas'] / (int) $cwl['ataques'], 2) : 0 ?> estrellas por ataque
                    </p>
                <?php else: ?>
                    <p class="text-muted mb-0">Sin temporadas registradas.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-building-fill"></i> Último capital</div>
            <div class="card-body">
                <?php if ($capital): ?>
                    <h4 class="text-white mb-1"><?= number_format((int) $capital['oro_total_recaudado']) ?></h4>
                    <p class="mb-1"><?= (int) $capital['distritos_destruidos'] ?> distritos, <?= (int) $capital['ataques_totales'] ?> ataques</p>
                    <p class="text-muted mb-0" style="font-size:.85rem"><?= date('d/m/Y', strtotime($capital['fecha_inicio'])) ?></p>
                <?php else: ?>
                    <p class="text-muted mb-0">Sin fines de semana registrados.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-lightning-fill"></i> Guerras</div>
            <div class="card-body">
                <h4 class="text-white mb-1"><?= (int) $guerras['total'] ?> registradas</h4>
                <p class="mb-1"><?= (int) $guerras['ganadas'] ?> ganadas</p>
                <p class="text-muted mb-0" style="font-size:.85rem">
                    Histórico del clan: <?= (int) $clan['guerras_ganadas'] ?>–<?= (int) $clan['guerras_perdidas'] ?>–<?= (int) $clan['guerras_empatadas'] ?>
                </p>
            </div>
        </div>
    </div>
</div>

<?php if ($flojos): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-exclamation-triangle-fill text-warning"></i> Poca participación en la última CWL</span>
        <a href="reportes" class="btn btn-sm btn-outline-primary">Ver decisiones</a>
    </div>
    <div class="table-responsive"><table class="table table-hover mb-0">
        <thead><tr><th>Jugador</th><th class="text-center">Ataques</th><th class="text-center">Estrellas</th></tr></thead>
        <tbody>
        <?php foreach ($flojos as $f): ?>
            <tr>
                <td><strong class="text-white"><?= clean($f['nombre_juego']) ?></strong></td>
                <td class="text-center"><span class="badge <?= (int) $f['ataques'] === 0 ? 'badge-red' : 'badge-gold' ?>"><?= (int) $f['ataques'] ?>/7</span></td>
                <td class="text-center"><?= (int) $f['estrellas'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php if ($ultimoCron): ?>
<div class="text-muted" style="font-size:.8rem">
    Última captura automática: <?= date('d/m/Y H:i', strtotime($ultimoCron['inicio'])) ?>
    — <span class="badge <?= $ultimoCron['estado'] === 'ok' ? 'badge-green' : ($ultimoCron['estado'] === 'parcial' ? 'badge-gold' : 'badge-red') ?>"><?= clean($ultimoCron['estado']) ?></span>
</div>
<?php endif; ?>

<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
