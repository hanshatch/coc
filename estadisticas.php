<?php
declare(strict_types=1);

/**
 * Estadísticas generales del clan. Vista de contexto, no de decisión:
 * lo accionable vive en el tablero principal.
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db = getDB();

$clan    = $db->query('SELECT * FROM snapshots_clan ORDER BY fecha DESC LIMIT 1')->fetch();
$lectura = $db->query('SELECT MAX(fecha) FROM snapshots_jugador')->fetchColumn();
$activos = (int) $db->query('SELECT COUNT(*) FROM jugadores WHERE activo = 1')->fetchColumn();

$guerras = $db->query(
    "SELECT COUNT(*) total, SUM(resultado='victoria') ganadas,
            SUM(estrellas_clan) estrellas, SUM(estrellas_oponente) estrellas_rival
       FROM guerras"
)->fetch();

$capital = $db->query('SELECT * FROM capital_semanas ORDER BY fecha_inicio DESC LIMIT 1')->fetch();
$capitalTotal = (int) $db->query('SELECT SUM(oro_total_recaudado) FROM capital_semanas')->fetchColumn();

$cwl = $db->query(
    'SELECT t.mes, COUNT(p.id) jugadores, SUM(p.estrellas) estrellas, SUM(p.ataques) ataques
       FROM cwl_temporadas t
       JOIN cwl_participaciones p ON p.temporada_id = t.id
   GROUP BY t.id ORDER BY t.mes DESC LIMIT 1'
)->fetch();

// Acumulados de por vida: quién ha construido más historia en el clan.
$leyendas = [];
if ($lectura) {
    $stmt = $db->prepare(
        'SELECT j.nombre_juego, s.acum_guerra_estrellas, s.acum_cwl_estrellas,
                s.acum_capital_oro, s.acum_juegos_puntos, s.acum_donaciones
           FROM snapshots_jugador s
           JOIN jugadores j ON j.id = s.jugador_id
          WHERE s.fecha = ? AND j.activo = 1
       ORDER BY s.acum_guerra_estrellas DESC LIMIT 10'
    );
    $stmt->execute([$lectura]);
    $leyendas = $stmt->fetchAll();
}

$ultimoCron = $db->query('SELECT * FROM cron_ejecuciones ORDER BY id DESC LIMIT 1')->fetch();

$pageTitle = 'Estadísticas del Clan';
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-graph-up"></i> Estadísticas del Clan</h1>
    <span class="text-muted" style="font-size:.85rem">
        <?= $lectura ? 'Lectura del ' . date('d/m/Y', strtotime((string) $lectura)) : 'Sin lecturas' ?>
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
            <div class="card-header"><i class="bi bi-lightning-fill"></i> Guerras</div>
            <div class="card-body">
                <h4 class="text-white mb-1"><?= (int) $clan['guerras_ganadas'] ?>–<?= (int) $clan['guerras_perdidas'] ?>–<?= (int) $clan['guerras_empatadas'] ?></h4>
                <p class="mb-1"><?= (int) $guerras['total'] ?> registradas en el sistema</p>
                <p class="text-muted mb-0" style="font-size:.85rem">
                    <?= number_format((int) $guerras['estrellas']) ?> estrellas a favor,
                    <?= number_format((int) $guerras['estrellas_rival']) ?> en contra
                </p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-trophy-fill"></i> Última CWL</div>
            <div class="card-body">
                <?php if ($cwl): ?>
                    <h4 class="text-white mb-1"><?= clean((string) $cwl['mes']) ?></h4>
                    <p class="mb-1"><?= (int) $cwl['estrellas'] ?> estrellas con <?= (int) $cwl['ataques'] ?> ataques</p>
                    <p class="text-muted mb-0" style="font-size:.85rem">
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
            <div class="card-header"><i class="bi bi-building-fill"></i> Capital</div>
            <div class="card-body">
                <?php if ($capital): ?>
                    <h4 class="text-white mb-1"><?= number_format($capitalTotal) ?></h4>
                    <p class="mb-1">de oro acumulado</p>
                    <p class="text-muted mb-0" style="font-size:.85rem">
                        Último: <?= number_format((int) $capital['oro_total_recaudado']) ?> el <?= date('d/m/Y', strtotime($capital['fecha_inicio'])) ?>
                    </p>
                <?php else: ?>
                    <p class="text-muted mb-0">Sin fines de semana registrados.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($leyendas): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-award-fill"></i> Historia acumulada de por vida</div>
    <div class="card-body py-2">
        <small class="text-muted">Totales de toda la carrera del jugador, no solo de su tiempo en este clan.</small>
    </div>
    <div class="table-responsive"><table class="table table-hover mb-0">
        <thead><tr>
            <th>Jugador</th><th class="text-center">Estrellas guerra</th><th class="text-center">Estrellas CWL</th>
            <th class="text-center">Oro capital</th><th class="text-center">Puntos juegos</th><th class="text-center">Donaciones</th>
        </tr></thead>
        <tbody>
        <?php foreach ($leyendas as $l): ?>
            <tr>
                <td><strong class="text-white"><?= clean($l['nombre_juego']) ?></strong></td>
                <td class="text-center"><strong><?= number_format((int) $l['acum_guerra_estrellas']) ?></strong></td>
                <td class="text-center"><?= number_format((int) $l['acum_cwl_estrellas']) ?></td>
                <td class="text-center"><?= number_format((int) $l['acum_capital_oro']) ?></td>
                <td class="text-center"><?= number_format((int) $l['acum_juegos_puntos']) ?></td>
                <td class="text-center"><?= number_format((int) $l['acum_donaciones']) ?></td>
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
