<?php
declare(strict_types=1);

/**
 * Juegos del Clan. Derivados de las capturas diarias.
 *
 * La API no expone los puntos de un evento concreto: solo el logro
 * "Games Champion", que es un acumulado de por vida. Los puntos de un
 * periodo se obtienen restando la lectura del final menos la del inicio.
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db = getDB();

$fechas = $db->query('SELECT DISTINCT fecha FROM snapshots_jugador ORDER BY fecha DESC')->fetchAll(PDO::FETCH_COLUMN);
$hasta  = $_GET['hasta'] ?? ($fechas[0] ?? null);
$desde  = $_GET['desde'] ?? ($fechas[count($fechas) - 1] ?? null);

$filas = [];
if ($desde && $hasta && $desde !== $hasta) {
    $stmt = $db->prepare(
        'SELECT j.nombre_juego, j.activo,
                (fin.acum_juegos_puntos - ini.acum_juegos_puntos) AS puntos,
                fin.acum_juegos_puntos AS total
           FROM snapshots_jugador ini
           JOIN snapshots_jugador fin ON fin.jugador_id = ini.jugador_id AND fin.fecha = ?
           JOIN jugadores j ON j.id = ini.jugador_id
          WHERE ini.fecha = ?
            AND ini.acum_juegos_puntos IS NOT NULL
            AND fin.acum_juegos_puntos IS NOT NULL
          ORDER BY puntos DESC'
    );
    $stmt->execute([$hasta, $desde]);
    $filas = $stmt->fetchAll();
}

$totalPuntos = array_sum(array_column($filas, 'puntos'));

$pageTitle = 'Juegos del Clan';
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-controller"></i> Juegos del Clan</h1>
</div>

<?php if (count($fechas) < 2): ?>
    <div class="empty-state">
        <div class="empty-icon">🎮</div>
        <p>Hacen falta al menos dos días de captura para poder medir puntos.</p>
        <p class="text-muted" style="font-size:.85rem">
            La API solo da el acumulado de por vida de cada jugador, así que los puntos
            de un periodo se calculan restando dos lecturas. Llevas <?= count($fechas) ?>.
        </p>
    </div>
<?php else: ?>

<div class="card mb-4"><div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-auto">
            <label class="form-label mb-1" style="font-size:.8rem">Desde</label>
            <select name="desde" class="form-select form-select-sm">
                <?php foreach ($fechas as $f): ?>
                    <option value="<?= $f ?>" <?= $f === $desde ? 'selected' : '' ?>><?= date('d/m/Y', strtotime($f)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label mb-1" style="font-size:.8rem">Hasta</label>
            <select name="hasta" class="form-select form-select-sm">
                <?php foreach ($fechas as $f): ?>
                    <option value="<?= $f ?>" <?= $f === $hasta ? 'selected' : '' ?>><?= date('d/m/Y', strtotime($f)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto"><button type="submit" class="btn btn-outline-primary btn-sm">Calcular</button></div>
    </form>
</div></div>

<?php if (!$filas): ?>
    <div class="empty-state"><div class="empty-icon">🎮</div><p>Sin datos para ese rango.</p></div>
<?php else: ?>
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4"><div class="stat-card"><div class="stat-icon">🎯</div><div class="stat-value"><?= number_format((int) $totalPuntos) ?></div><div class="stat-label">Puntos del periodo</div></div></div>
        <div class="col-6 col-md-4"><div class="stat-card"><div class="stat-icon">👥</div><div class="stat-value"><?= count(array_filter($filas, fn($f) => $f['puntos'] > 0)) ?></div><div class="stat-label">Aportaron</div></div></div>
        <div class="col-12 col-md-4"><div class="stat-card"><div class="stat-icon">😴</div><div class="stat-value"><?= count(array_filter($filas, fn($f) => $f['puntos'] == 0)) ?></div><div class="stat-label">Sin aportar</div></div></div>
    </div>

    <div class="card"><div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th class="text-center">#</th><th>Jugador</th><th class="text-center">Puntos del periodo</th><th class="text-center">De por vida</th></tr></thead>
            <tbody>
            <?php foreach ($filas as $i => $f): ?>
                <tr<?= $f['puntos'] == 0 ? ' class="opacity-50"' : '' ?>>
                    <td class="text-center text-muted"><?= $i + 1 ?></td>
                    <td>
                        <strong class="text-white"><?= clean($f['nombre_juego']) ?></strong>
                        <?php if (!$f['activo']): ?><span class="badge badge-red ms-1">Ya no está</span><?php endif; ?>
                    </td>
                    <td class="text-center"><strong class="<?= $f['puntos'] > 0 ? 'text-success' : 'text-muted' ?>"><?= number_format((int) $f['puntos']) ?></strong></td>
                    <td class="text-center text-muted"><?= number_format((int) $f['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
    <div class="text-muted text-center mt-3" style="font-size:.85rem">
        Elige un rango que abarque unos Juegos del Clan para ver cuánto aportó cada quien en ese evento.
    </div>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
