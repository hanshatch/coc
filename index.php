<?php
declare(strict_types=1);

/**
 * Tablero de decisión: a quién sacar y a quién meter a guerra.
 *
 * El cálculo vive en includes/decisiones.php porque los avisos de
 * Telegram necesitan exactamente el mismo criterio. Si cada uno tuviera
 * su copia, el tablero y el bot acabarían recomendando cosas distintas.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/decisiones.php';
requireLogin();

$db      = getDB();
$dias    = 30;
$lectura = $db->query('SELECT MAX(fecha) FROM snapshots_jugador')->fetchColumn();

$d = decisionesClan($dias);

$jugadores         = $d['jugadores'];
$expulsar          = $d['expulsar'];
$mejores           = $d['mejores'];
$parciales         = $d['parciales'];
$capOportunidades  = $d['capOportunidades'];
$guerrasConDetalle = $d['guerrasConDetalle'];
$guerrasEnVentana  = $d['guerrasEnVentana'];
$hayCwl            = $d['hayLiga'];
$hayJuegos         = $d['hayJuegos'];
$fechasSnap        = array_fill(0, $d['lecturas'], null);

$rolBadge = ['lider'=>'badge-gold','colider'=>'badge-purple','veterano'=>'badge-blue','miembro'=>'badge-muted'];

$pageTitle = 'Decisiones';
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-clipboard-data-fill"></i> Decisiones</h1>
    <span class="text-muted" style="font-size:.85rem">
        Últimos <?= $dias ?> días<?= $lectura ? ' · lectura del ' . date('d/m/Y', strtotime((string) $lectura)) : '' ?>
    </span>
</div>

<?php if (!$jugadores): ?>
    <div class="empty-state"><div class="empty-icon">📊</div><p>Sin jugadores activos todavía.</p></div>
<?php else: ?>

<!-- Qué se pudo medir en la ventana -->
<div class="card mb-4">
    <div class="card-body py-2 d-flex flex-wrap gap-3" style="font-size:.85rem">
        <span><i class="bi bi-building-fill text-<?= $capOportunidades ? 'success' : 'muted' ?>"></i>
            Capital: <strong><?= $capOportunidades ?></strong> fin<?= $capOportunidades === 1 ? '' : 'es' ?> de semana con detalle</span>
        <span><i class="bi bi-lightning-fill text-<?= $guerrasConDetalle ? 'success' : 'muted' ?>"></i>
            Guerras: <strong><?= $guerrasConDetalle ?></strong> de <?= $guerrasEnVentana ?> con detalle por jugador</span>
        <span><i class="bi bi-trophy-fill text-<?= $hayCwl ? 'success' : 'muted' ?>"></i>
            Liga: <strong><?= $hayCwl ? 'sí' : 'no' ?></strong></span>
        <span><i class="bi bi-controller text-<?= $hayJuegos ? 'success' : 'muted' ?>"></i>
            Juegos: <strong><?= $hayJuegos ? count($fechasSnap) . ' lecturas' : 'faltan lecturas' ?></strong></span>
    </div>
</div>

<div class="row g-3">
    <!-- ── IZQUIERDA: expulsar ─────────────────────────────────── -->
    <div class="col-lg-6">
        <div class="card" style="border-left:3px solid var(--ct-red-text)">
            <div class="card-header">
                <i class="bi bi-person-x-fill text-danger"></i> Expulsar
                <span class="badge badge-red ms-1"><?= count($expulsar) ?></span>
            </div>
            <div class="card-body py-2 flex-grow-0">
                <small class="text-muted">Cero participación en todo lo que tuvieron disponible durante el mes.</small>
            </div>
            <?php if (!$expulsar): ?>
                <div class="card-body text-muted pt-0">Nadie quedó en cero absoluto.</div>
            <?php else: ?>
            <div class="table-responsive"><table class="table table-hover mb-0">
                <thead><tr><th>Jugador</th><th class="text-center">Capital</th><th class="text-center">Guerra</th><th class="text-center">Liga</th><th class="text-center">Juegos</th><th class="text-center">Historia</th></tr></thead>
                <tbody>
                <?php foreach ($expulsar as $j): $d = $j['detalle']; ?>
                    <tr>
                        <td>
                            <strong class="text-white"><?= clean($j['nombre_juego']) ?></strong>
                            <div><small class="text-muted"><?= $j['th_nivel'] ? 'TH' . (int) $j['th_nivel'] : '' ?></small></div>
                        </td>
                        <td class="text-center"><?= $d['capital']['tuvo'] ? '<span class="badge badge-red">0 de ' . $d['capital']['de'] . '</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-center"><?= $d['guerra']['tuvo'] ? '<span class="badge badge-red">0 de ' . $d['guerra']['de'] . '</span>' : '<span class="text-muted">no convocado</span>' ?></td>
                        <td class="text-center"><?= $d['liga']['tuvo'] ? '<span class="badge badge-red">0/7</span>' : '<span class="text-muted">no entró</span>' ?></td>
                        <td class="text-center"><?= $d['juegos']['tuvo'] ? '<span class="badge badge-red">0</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-center">
                            <span class="<?= $j['historia'] >= 500 ? 'badge badge-blue' : 'text-muted' ?>"><?= number_format($j['historia']) ?> ⭐</span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="card-body pt-2">
                <small class="text-muted">
                    La columna Historia son estrellas de guerra de por vida. Los de arriba, sin historia,
                    son los candidatos claros; uno con miles de estrellas merece preguntarle antes de sacarlo.
                </small>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── DERECHA: los mejores ────────────────────────────────── -->
    <div class="col-lg-6">
        <div class="card" style="border-left:3px solid var(--ct-green-text)">
            <div class="card-header">
                <i class="bi bi-shield-fill-check text-success"></i> Mejores para guerra
                <span class="badge badge-green ms-1"><?= count($mejores) ?> de <?= CUPO_GUERRA ?></span>
            </div>
            <div class="card-body py-2 flex-grow-0">
                <small class="text-muted">Una guerra de clan puede llegar a necesitar <?= CUPO_GUERRA ?> jugadores. Van ordenados por cobertura y calidad de ataque.</small>
            </div>
            <?php if (!$mejores): ?>
                <div class="card-body text-muted pt-0">Sin jugadores disponibles.</div>
            <?php else: ?>
            <div class="table-responsive"><table class="table table-hover mb-0">
                <thead><tr><th class="text-center">#</th><th>Jugador</th><th class="text-center">Cubrió</th><th class="text-center">Capital</th><th class="text-center">Liga</th><th class="text-center">⭐/ataque</th></tr></thead>
                <tbody>
                <?php foreach ($mejores as $i => $j): $d = $j['detalle']; ?>
                    <tr>
                        <td class="text-center text-muted"><?= $i + 1 ?></td>
                        <td>
                            <strong class="text-white"><?= clean($j['nombre_juego']) ?></strong>
                            <?php if ($j['completo']): ?>
                                <i class="bi bi-check-circle-fill text-success ms-1" title="Participó en todos sus frentes"></i>
                            <?php endif; ?>
                            <div><small class="text-muted"><?= $j['th_nivel'] ? 'TH' . (int) $j['th_nivel'] : '' ?></small></div>
                        </td>
                        <td class="text-center">
                            <span class="badge <?= $j['completo'] ? 'badge-green' : ($j['aporta'] ? 'badge-gold' : 'badge-muted') ?>">
                                <?= (int) $j['aporta'] ?> de <?= (int) $j['arenas'] ?>
                            </span>
                        </td>
                        <td class="text-center"><?= $d['capital']['tuvo'] ? $d['capital']['hizo'] . ' de ' . $d['capital']['de'] : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-center"><?= $d['liga']['tuvo'] ? $d['liga']['hizo'] . '/7' : '<span class="text-muted">no entró</span>' ?></td>
                        <td class="text-center">
                            <?php if ($j['calidad'] !== null): ?>
                                <span class="badge <?= $j['calidad'] >= 2.5 ? 'badge-green' : ($j['calidad'] >= 2 ? 'badge-gold' : 'badge-muted') ?>"><?= $j['calidad'] ?></span>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="card-body pt-2 flex-grow-0">
                <small class="text-muted">
                    La palomita marca a quien participó en todo lo que tuvo disponible.
                    Estrellas por ataque mide calidad, no esfuerzo: arriba de 2.5 destruye casi todo lo que toca.
                </small>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Zona gris ──────────────────────────────────────────────── -->
<div class="card mt-3">
    <div class="card-header">
        <i class="bi bi-hourglass-split text-warning"></i> Participan a medias
        <span class="badge badge-gold ms-1"><?= count($parciales) ?></span>
    </div>
    <div class="card-body py-2">
        <small class="text-muted">Aportan en algunos frentes pero no en todos. Es la lista a vigilar el mes que viene.</small>
    </div>
    <div class="table-responsive"><table class="table table-hover mb-0">
        <thead><tr><th>Jugador</th><th class="text-center">Cubrió</th><th class="text-center">Capital</th><th class="text-center">Guerra</th><th class="text-center">Liga</th><th class="text-center">Juegos</th><th class="text-center">Donaciones</th></tr></thead>
        <tbody>
        <?php foreach ($parciales as $j): $d = $j['detalle']; ?>
            <tr>
                <td>
                    <strong class="text-white"><?= clean($j['nombre_juego']) ?></strong>
                    <span class="badge <?= $rolBadge[$j['rol_clan']] ?? 'badge-muted' ?> ms-1"><?= ucfirst($j['rol_clan']) ?></span>
                </td>
                <td class="text-center"><span class="badge <?= $j['aporta'] ? 'badge-gold' : 'badge-red' ?>"><?= $j['aporta'] ?> de <?= $j['arenas'] ?></span></td>
                <td class="text-center"><?= $d['capital']['tuvo'] ? $d['capital']['hizo'] . ' de ' . $d['capital']['de'] : '<span class="text-muted">—</span>' ?></td>
                <td class="text-center"><?= $d['guerra']['tuvo'] ? $d['guerra']['hizo'] . ' de ' . $d['guerra']['de'] : '<span class="text-muted">no convocado</span>' ?></td>
                <td class="text-center"><?= $d['liga']['tuvo'] ? $d['liga']['hizo'] . '/7' : '<span class="text-muted">no entró</span>' ?></td>
                <td class="text-center"><?= $d['juegos']['tuvo'] ? number_format($d['juegos']['puntos']) : '<span class="text-muted">—</span>' ?></td>
                <td class="text-center text-muted"><?= number_format((int) ($j['donaciones'] ?? 0)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="text-muted mt-3" style="font-size:.85rem">
    <strong>Cómo se decide.</strong>
    Se miran cuatro actividades del último mes: asaltos al capital, guerras, CWL y Juegos del Clan.
    Cada una cuenta solo si el jugador tuvo la oportunidad: el capital y los juegos están abiertos a
    todo el clan, pero la guerra y la CWL dependen de que tú lo convoques, y no sería justo cobrarle
    una ausencia que no eligió. Aparece en Expulsar quien quedó en cero en todas las que sí tuvo.
</div>

<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
