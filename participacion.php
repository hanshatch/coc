<?php
declare(strict_types=1);

/**
 * Registro de participación general: cada miembro contra todas las
 * actividades del clan, para decidir expulsiones por baja participación.
 *
 * Usa el mismo cálculo del tablero (includes/decisiones.php), así que
 * los criterios son idénticos. Aquí se ordena de menor a mayor
 * participación: arriba quedan los candidatos a salir.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/decisiones.php';
requireLogin();

$db = getDB();

// Ventana configurable. Cuanto más largo el periodo, más confiable el
// juicio, pero el sistema solo puede medir desde que empezó a capturar.
$opciones = [30 => '30 días', 60 => '2 meses', 90 => '3 meses'];
$dias     = (int) ($_GET['dias'] ?? 30);
if (!isset($opciones[$dias])) {
    $dias = 30;
}

$midiendoDesde = midiendoDesde();
$diasReales    = $midiendoDesde
    ? (int) ((time() - strtotime($midiendoDesde)) / 86400)
    : 0;

$d = decisionesClan($dias);
$jugadores = $d['jugadores'];

/**
 * Participación general: fracción de arenas disponibles en las que el
 * jugador sí apareció. Es la misma medida que usa el tablero, aquí
 * expuesta como porcentaje para poder ordenar a todos en una escala.
 */
foreach ($jugadores as &$j) {
    $j['participacion'] = $j['arenas'] > 0 ? $j['aporta'] / $j['arenas'] : null;
}
unset($j);

// De menor a mayor participación. A igualdad, primero quien tuvo más
// arenas disponibles (más evidencia de ausencia) y quien lleva más sin
// dar señales de vida.
usort($jugadores, function (array $a, array $b): int {
    return [$a['participacion'] ?? 2, -$a['arenas'], $a['ultimaActividad'] ?? '0']
       <=> [$b['participacion'] ?? 2, -$b['arenas'], $b['ultimaActividad'] ?? '0'];
});

$rolBadge = ['lider'=>'badge-gold','colider'=>'badge-purple','veterano'=>'badge-blue','miembro'=>'badge-muted'];

/** Pinta una arena: verde si participó, rojo si tuvo la oportunidad y no, gris si no aplicaba. */
function celdaArena(array $info, string $texto): string
{
    if (!($info['tuvo'] ?? false)) {
        return '<span class="text-muted">—</span>';
    }
    $hizo  = $info['hizo'] ?? ($info['puntos'] ?? 0);
    $clase = $hizo > 0 ? 'text-success' : 'text-danger';
    return '<span class="' . $clase . '">' . $texto . '</span>';
}

$pageTitle = 'Participación';
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-people-fill"></i> Participación general</h1>
    <form method="GET" class="d-flex gap-2">
        <select name="dias" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach ($opciones as $v => $txt): ?>
                <option value="<?= $v ?>" <?= $v === $dias ? 'selected' : '' ?>>Últimos <?= $txt ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if ($diasReales < $dias): ?>
<div class="alert alert-warning">
    <i class="bi bi-hourglass-split"></i>
    Llevas <strong><?= $diasReales ?> días</strong> de historial capturado, menos que la ventana de <?= $dias ?>.
    La tabla ya es útil, pero se vuelve concluyente cuando el periodo esté cubierto por completo:
    conviene esperar antes de expulsar a alguien solo por lo que se ve aquí.
</div>
<?php endif; ?>

<!-- Qué se pudo medir -->
<div class="card mb-4"><div class="card-body py-2 d-flex flex-wrap gap-3" style="font-size:.85rem">
    <span><i class="bi bi-building-fill text-<?= $d['capOportunidades'] ? 'success' : 'muted' ?>"></i> Capital: <strong><?= $d['capOportunidades'] ?></strong> fin<?= $d['capOportunidades'] === 1 ? '' : 'es' ?> de semana</span>
    <span><i class="bi bi-lightning-fill text-<?= $d['guerrasConDetalle'] ? 'success' : 'muted' ?>"></i> Guerras con detalle: <strong><?= $d['guerrasConDetalle'] ?></strong> de <?= $d['guerrasEnVentana'] ?></span>
    <span><i class="bi bi-trophy-fill text-<?= $d['hayLiga'] ? 'success' : 'muted' ?>"></i> Liga: <strong><?= $d['hayLiga'] ? 'sí' : 'no' ?></strong></span>
    <span><i class="bi bi-controller text-<?= $d['hayJuegos'] ? 'success' : 'muted' ?>"></i> Juegos: <strong><?= $d['hayJuegos'] ? 'medibles' : 'faltan lecturas' ?></strong></span>
</div></div>

<div class="card"><div class="table-responsive">
    <table class="table table-hover mb-0" style="font-size:.9rem">
        <thead><tr>
            <th>#</th><th>Jugador</th>
            <th class="text-center">Capital</th><th class="text-center">Guerra</th>
            <th class="text-center">Liga</th><th class="text-center">Juegos</th>
            <th class="text-center">Donaciones</th><th class="text-center">Últ. actividad</th>
            <th class="text-center">Participación</th>
        </tr></thead>
        <tbody>
        <?php foreach ($jugadores as $i => $j): $x = $j['detalle']; ?>
            <?php
                $pct  = $j['participacion'];
                $badge = $pct === null ? 'badge-muted'
                       : ($pct == 0 ? 'badge-red' : ($pct < 0.5 ? 'badge-gold' : ($pct < 1 ? 'badge-blue' : 'badge-green')));
            ?>
            <tr>
                <td class="text-muted"><?= $i + 1 ?></td>
                <td>
                    <strong class="text-white"><?= clean($j['nombre_juego']) ?></strong>
                    <span class="badge <?= $rolBadge[$j['rol_clan']] ?? 'badge-muted' ?> ms-1"><?= ucfirst($j['rol_clan']) ?></span>
                    <?php if (!$j['guerraActiva']): ?><span class="badge badge-gold ms-1" title="Tiene la guerra apagada">⚙️</span><?php endif; ?>
                </td>
                <td class="text-center"><?= celdaArena($x['capital'], ($x['capital']['hizo'] ?? 0) . '/' . ($x['capital']['de'] ?? 0)) ?></td>
                <td class="text-center"><?= celdaArena($x['guerra'], ($x['guerra']['hizo'] ?? 0) . '/' . ($x['guerra']['de'] ?? 0)) ?></td>
                <td class="text-center"><?= celdaArena($x['liga'], ($x['liga']['hizo'] ?? 0) . '/7') ?></td>
                <td class="text-center"><?= celdaArena($x['juegos'], number_format((int) ($x['juegos']['puntos'] ?? 0))) ?></td>
                <td class="text-center text-muted"><?= number_format((int) ($j['donaciones'] ?? 0)) ?></td>
                <td class="text-center">
                    <?php if ($j['ultimaActividad']): ?>
                        <?php $ds = (int) ((time() - strtotime((string) $j['ultimaActividad'])) / 86400); ?>
                        <span class="<?= $ds >= 30 ? 'text-danger' : 'text-muted' ?>"><?= date('d/m', strtotime((string) $j['ultimaActividad'])) ?></span>
                    <?php else: ?><span class="text-danger">nada</span><?php endif; ?>
                </td>
                <td class="text-center">
                    <?php if ($pct === null): ?>
                        <span class="text-muted">sin medir</span>
                    <?php else: ?>
                        <span class="badge <?= $badge ?>"><?= round($pct * 100) ?>%</span>
                        <div><small class="text-muted"><?= $j['aporta'] ?> de <?= $j['arenas'] ?></small></div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>

<div class="text-muted mt-3" style="font-size:.85rem">
    <strong>Cómo leerla.</strong>
    Cada columna es una actividad del clan en los últimos <?= $dias ?> días. Verde es que participó,
    rojo que tuvo la oportunidad y no lo hizo, y una raya que esa actividad no estuvo disponible o no lo convocaron.
    La <b>Participación</b> es en cuántas de las actividades disponibles apareció; los de arriba, con el porcentaje
    más bajo, son los candidatos a expulsión por baja participación general.
    El engrane ⚙️ marca a quien tiene la guerra apagada, para no contarle en contra una ausencia que eligió.
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
