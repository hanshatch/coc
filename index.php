<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db = getDB();

// ── Estadísticas rápidas ──────────────────────────────────────
$totalJugadores = (int) $db->query('SELECT COUNT(*) FROM jugadores WHERE activo = 1')->fetchColumn();
$totalGuerras   = (int) $db->query('SELECT COUNT(*) FROM guerras')->fetchColumn();
$victorias      = (int) $db->query('SELECT COUNT(*) FROM guerras WHERE resultado = "victoria"')->fetchColumn();
$winRate        = $totalGuerras > 0 ? round(($victorias / $totalGuerras) * 100) : 0;

// ── Última guerra ─────────────────────────────────────────────
$ultimaGuerra = $db->query(
    'SELECT g.*, (SELECT COUNT(*) FROM guerra_participaciones WHERE guerra_id = g.id) AS participantes
     FROM guerras g ORDER BY g.fecha DESC LIMIT 1'
)->fetch();

// ── Últimos juegos del clan ───────────────────────────────────
$ultimosJuegos = $db->query(
    'SELECT jc.*,
            (SELECT COALESCE(SUM(puntos),0) FROM juegos_participaciones WHERE juego_id = jc.id) AS puntos_sum,
            (SELECT COUNT(*) FROM juegos_participaciones WHERE juego_id = jc.id) AS participantes
     FROM juegos_clan jc ORDER BY jc.fecha_inicio DESC LIMIT 1'
)->fetch();

// ── Top donadores (último periodo) ───────────────────────────
$ultimoPeriodo = $db->query('SELECT id FROM donaciones_periodos ORDER BY fecha_inicio DESC LIMIT 1')->fetchColumn();
$donadores = [];
if ($ultimoPeriodo) {
    $donadores = $db->prepare(
        'SELECT d.*, j.nombre FROM donaciones d
         JOIN jugadores j ON d.jugador_id = j.id
         WHERE d.periodo_id = ? ORDER BY d.tropas_donadas DESC LIMIT 5'
    );
    $donadores->execute([$ultimoPeriodo]);
    $donadores = $donadores->fetchAll();
}

// ── Actividad reciente ────────────────────────────────────────
$actividad = $db->query(
    'SELECT l.*, u.nombre AS usuario_nombre
     FROM log_actividad l
     JOIN usuarios u ON l.usuario_id = u.id
     ORDER BY l.created_at DESC LIMIT 8'
)->fetchAll();

$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1>⚔️ Dashboard — Clan Tracker</h1>
    <div class="text-muted"><i class="bi bi-calendar3"></i> <?= date('d/m/Y') ?></div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon">👥</div>
            <div class="stat-value"><?= $totalJugadores ?></div>
            <div class="stat-label">Jugadores Activos</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon">⚔️</div>
            <div class="stat-value"><?= $totalGuerras ?></div>
            <div class="stat-label">Guerras Totales</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon">🏆</div>
            <div class="stat-value"><?= $victorias ?></div>
            <div class="stat-label">Victorias</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon">📈</div>
            <div class="stat-value"><?= $winRate ?>%</div>
            <div class="stat-label">Win Rate</div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Última Guerra -->
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Última Guerra</span>
                <a href="guerras" class="btn btn-sm btn-outline-primary py-0">Ver todas</a>
            </div>
            <div class="card-body">
                <?php if ($ultimaGuerra):
                    $resBadge = [
                        'victoria' => 'badge-green',
                        'derrota'  => 'badge-red',
                        'empate'   => 'badge-blue',
                        'en_curso' => 'badge-gold',
                    ];
                ?>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h3 class="mb-0">vs <?= clean($ultimaGuerra['oponente']) ?></h3>
                            <small class="text-muted"><?= date('d F Y', strtotime($ultimaGuerra['fecha'])) ?></small>
                        </div>
                        <span class="badge <?= $resBadge[$ultimaGuerra['resultado']] ?? 'badge-muted' ?> fs-6">
                            <?= ucfirst(str_replace('_', ' ', $ultimaGuerra['resultado'])) ?>
                        </span>
                    </div>
                    <div class="row text-center my-4">
                        <div class="col-6 border-end border-secondary">
                            <div class="h2 mb-0"><?= (int) $ultimaGuerra['estrellas_clan'] ?></div>
                            <div class="text-muted small">Clan</div>
                        </div>
                        <div class="col-6">
                            <div class="h2 mb-0"><?= (int) $ultimaGuerra['estrellas_oponente'] ?></div>
                            <div class="text-muted small">Oponente</div>
                        </div>
                    </div>
                    <div class="text-center">
                        <p class="mb-0 small text-muted"><?= (int) $ultimaGuerra['participantes'] ?> jugadores registrados</p>
                        <a href="guerra_detalle?id=<?= $ultimaGuerra['id'] ?>" class="btn btn-sm btn-primary mt-2">Detalles</a>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4 text-muted">No hay guerras registradas.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Top Donadores -->
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Top Donadores</span>
                <a href="donaciones" class="btn btn-sm btn-outline-primary py-0">Donaciones</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($donadores)): ?>
                    <div class="text-center py-4 text-muted">No hay donaciones en el periodo actual.</div>
                <?php else: ?>
                    <table class="table table-sm table-hover mb-0">
                        <thead class="bg-surface2">
                            <tr>
                                <th class="ps-3 py-2">Jugador</th>
                                <th class="text-end pe-3 py-2">Donadas</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($donadores as $d): ?>
                                <tr>
                                    <td class="ps-3 py-2"><?= clean($d['nombre']) ?></td>
                                    <td class="text-end pe-3 py-2 text-gold font-monospace"><?= number_format($d['tropas_donadas']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Actividad Reciente -->
    <div class="col-12">
        <div class="card">
            <div class="card-header">Actividad Reciente del Sistema</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0" style="font-size:0.85rem">
                        <thead>
                            <tr>
                                <th class="ps-3">Usuario</th>
                                <th>Acción</th>
                                <th>Sección</th>
                                <th>Detalle</th>
                                <th class="text-end pe-3">Fecha</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($actividad as $l):
                                $badgeClass = match($l['accion']) {
                                    'crear'    => 'badge-green',
                                    'editar'   => 'badge-blue',
                                    'eliminar' => 'badge-red',
                                    default    => 'badge-muted'
                                };
                            ?>
                                <tr>
                                    <td class="ps-3"><?= clean($l['usuario_nombre']) ?></td>
                                    <td><span class="badge <?= $badgeClass ?>"><?= $l['accion'] ?></span></td>
                                    <td><?= ucfirst($l['tabla_afectada']) ?></td>
                                    <td><?= clean($l['detalle']) ?></td>
                                    <td class="text-end pe-3 text-muted"><?= date('d/m H:i', strtotime($l['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
