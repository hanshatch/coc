<?php
declare(strict_types=1);

/**
 * Roster del clan. Solo lectura: los jugadores entran y salen desde la
 * API, no desde aquí.
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db     = getDB();
$filtro = $_GET['filter'] ?? 'activos';
$buscar = trim($_GET['q'] ?? '');

$where  = [];
$params = [];

if ($buscar !== '') {
    $where[]  = '(j.nombre_juego LIKE ? OR j.tag LIKE ?)';
    $params[] = "%{$buscar}%";
    $params[] = "%{$buscar}%";
}
if ($filtro === 'activos') {
    $where[] = 'j.activo = 1';
} elseif ($filtro === 'inactivos') {
    $where[] = 'j.activo = 0';
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// La última lectura del cron trae el estado del jugador.
$stmt = $db->prepare(
    "SELECT j.*, s.trofeos, s.th_nivel, s.donaciones, s.donaciones_recibidas,
            s.acum_guerra_estrellas, s.acum_cwl_estrellas, s.acum_capital_oro
       FROM jugadores j
       LEFT JOIN snapshots_jugador s
              ON s.jugador_id = j.id
             AND s.fecha = (SELECT MAX(fecha) FROM snapshots_jugador WHERE jugador_id = j.id)
       $whereSQL
   ORDER BY j.activo DESC, s.th_nivel DESC, j.nombre_juego ASC"
);
$stmt->execute($params);
$jugadores = $stmt->fetchAll();

$rolBadge = ['lider'=>'badge-gold','colider'=>'badge-purple','veterano'=>'badge-blue','miembro'=>'badge-muted'];

$pageTitle = 'Jugadores';
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-people-fill"></i> Jugadores</h1>
</div>

<div class="card mb-4"><div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-center">
        <div class="col-auto">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Nombre o tag..." value="<?= clean($buscar) ?>">
        </div>
        <div class="col-auto">
            <select name="filter" class="form-select form-select-sm">
                <option value="activos"   <?= $filtro === 'activos'   ? 'selected' : '' ?>>En el clan</option>
                <option value="inactivos" <?= $filtro === 'inactivos' ? 'selected' : '' ?>>Ya no están</option>
                <option value="todos"     <?= $filtro === 'todos'     ? 'selected' : '' ?>>Todos</option>
            </select>
        </div>
        <div class="col-auto"><button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-search"></i> Filtrar</button></div>
    </form>
</div></div>

<?php if (!$jugadores): ?>
    <div class="empty-state">
        <div class="empty-icon">🏰</div>
        <p>Sin jugadores todavía. La captura automática los trae de la API cada noche.</p>
    </div>
<?php else: ?>
<div class="card"><div class="table-responsive">
    <table class="table table-hover mb-0">
        <thead><tr>
            <th>Jugador</th><th>Rol</th><th class="text-center">TH</th>
            <th class="text-center">Trofeos</th><th class="text-center">Donaciones</th>
            <th class="text-center">Estrellas guerra</th><th class="text-center">Oro capital</th>
        </tr></thead>
        <tbody>
        <?php foreach ($jugadores as $j): ?>
            <tr<?= $j['activo'] ? '' : ' class="opacity-50"' ?>>
                <td>
                    <strong class="text-white"><?= clean($j['nombre_juego']) ?></strong>
                    <div><small class="text-muted"><code><?= clean($j['tag']) ?></code></small></div>
                    <?php if (!$j['activo']): ?><span class="badge badge-red">Ya no está</span><?php endif; ?>
                </td>
                <td><span class="badge <?= $rolBadge[$j['rol_clan']] ?? 'badge-muted' ?>"><?= ucfirst($j['rol_clan']) ?></span></td>
                <td class="text-center"><?= $j['th_nivel'] ? 'TH' . (int) $j['th_nivel'] : '—' ?></td>
                <td class="text-center"><?= $j['trofeos'] !== null ? number_format((int) $j['trofeos']) : '—' ?></td>
                <td class="text-center">
                    <?php if ($j['donaciones'] !== null): ?>
                        <span class="text-success"><?= number_format((int) $j['donaciones']) ?></span>
                        <span class="text-muted">/</span>
                        <span class="text-danger"><?= number_format((int) $j['donaciones_recibidas']) ?></span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td class="text-center"><?= $j['acum_guerra_estrellas'] !== null ? number_format((int) $j['acum_guerra_estrellas']) : '—' ?></td>
                <td class="text-center"><?= $j['acum_capital_oro'] !== null ? number_format((int) $j['acum_capital_oro']) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<div class="text-muted text-center mt-3" style="font-size:.85rem">
    <?= count($jugadores) ?> jugador<?= count($jugadores) !== 1 ? 'es' : '' ?>.
    Donaciones en verde son dadas, en rojo recibidas.
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
