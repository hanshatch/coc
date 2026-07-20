<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/coc_sync.php';
requireLogin();

$error = null;
$diff  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aplicar'])) {
    verifyCsrf();
    try {
        $res = cocAplicarSync(cocDiffRoster());
        logActivity(
            'sincronizar',
            'jugadores',
            null,
            sprintf('%d actualizados, %d altas, %d bajas', $res['actualizados'], $res['altas'], $res['bajas'])
        );
        setFlash('success', sprintf(
            'Roster sincronizado: %d actualizados, %d altas, %d bajas.',
            $res['actualizados'],
            $res['altas'],
            $res['bajas']
        ));
    } catch (CocApiException $e) {
        setFlash('error', $e->getMessage());
    } catch (Throwable $e) {
        error_log('Sync roster falló: ' . $e->getMessage());
        setFlash('error', 'No se pudo sincronizar. Revisa el log del servidor.');
    }
    header('Location: sincronizar');
    exit;
}

try {
    $diff = cocDiffRoster();
} catch (CocApiException $e) {
    $error = $e->getMessage();
}

$pageTitle = 'Sincronizar Clan';
require __DIR__ . '/includes/header.php';

$rolBadge = [
    'lider'    => 'badge-gold',
    'colider'  => 'badge-purple',
    'veterano' => 'badge-blue',
    'miembro'  => 'badge-muted',
];
?>

<div class="ct-page-header">
    <h1><i class="bi bi-arrow-repeat"></i> Sincronizar Clan</h1>
    <a href="jugadores" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Jugadores</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= clean($error) ?></div>
<?php else:
    $totalCambios = count($diff['altas']) + count($diff['bajas'])
                  + count($diff['cambiosRol']) + count($diff['reactivar']);
?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">🏰</div><div class="stat-value"><?= $diff['miembros'] ?></div><div class="stat-label">En el clan</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">➕</div><div class="stat-value"><?= count($diff['altas']) ?></div><div class="stat-label">Altas</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">➖</div><div class="stat-value"><?= count($diff['bajas']) ?></div><div class="stat-label">Bajas</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon">🔄</div><div class="stat-value"><?= count($diff['cambiosRol']) + count($diff['reactivar']) ?></div><div class="stat-label">Cambios</div></div></div>
</div>

<?php if ($totalCambios === 0): ?>
    <div class="empty-state">
        <div class="empty-icon">✅</div>
        <p>Tu base ya coincide con el clan. No hay nada que sincronizar.</p>
    </div>
<?php else: ?>

    <?php if ($diff['altas']): ?>
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-person-plus-fill text-success"></i> Altas — entraron al clan</div>
        <div class="table-responsive"><table class="table table-hover mb-0">
            <thead><tr><th>Tag</th><th>Nombre</th><th>Rol</th><th class="text-center">TH</th></tr></thead>
            <tbody>
            <?php foreach ($diff['altas'] as $m): ?>
                <tr>
                    <td><code><?= clean($m['tag']) ?></code></td>
                    <td><strong><?= clean($m['name']) ?></strong></td>
                    <td><span class="badge <?= $rolBadge[cocRolALocal($m['role'])] ?>"><?= ucfirst(cocRolALocal($m['role'])) ?></span></td>
                    <td class="text-center"><?= (int) $m['townHallLevel'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

    <?php if ($diff['bajas']): ?>
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-person-dash-fill text-danger"></i> Bajas — ya no están en el clan</div>
        <div class="card-body py-2">
            <small class="text-muted">Se marcan como inactivos. Su historial de guerras, CWL y donaciones se conserva intacto.</small>
        </div>
        <div class="table-responsive"><table class="table table-hover mb-0">
            <thead><tr><th>Jugador</th><th>Tag</th><th>Rol</th></tr></thead>
            <tbody>
            <?php foreach ($diff['bajas'] as $r): ?>
                <tr>
                    <td><strong><?= clean($r['nombre_juego'] ?: $r['usuario']) ?></strong></td>
                    <td><code><?= clean($r['tag'] ?: '—') ?></code></td>
                    <td><span class="badge <?= $rolBadge[$r['rol_clan']] ?? 'badge-muted' ?>"><?= ucfirst($r['rol_clan']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

    <?php if ($diff['cambiosRol'] || $diff['reactivar']): ?>
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-arrow-left-right text-warning"></i> Cambios</div>
        <div class="table-responsive"><table class="table table-hover mb-0">
            <thead><tr><th>Jugador</th><th>Cambio</th></tr></thead>
            <tbody>
            <?php foreach ($diff['cambiosRol'] as $p): ?>
                <tr>
                    <td><strong><?= clean($p['api']['name']) ?></strong></td>
                    <td>
                        <span class="badge <?= $rolBadge[$p['fila']['rol_clan']] ?? 'badge-muted' ?>"><?= ucfirst($p['fila']['rol_clan']) ?></span>
                        <i class="bi bi-arrow-right mx-1"></i>
                        <span class="badge <?= $rolBadge[$p['rolNuevo']] ?>"><?= ucfirst($p['rolNuevo']) ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php foreach ($diff['reactivar'] as $p): ?>
                <tr>
                    <td><strong><?= clean($p['api']['name']) ?></strong></td>
                    <td><span class="badge badge-green">Regresó al clan — se reactiva</span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

    <form method="POST" class="text-center mt-4">
        <?= csrfField() ?>
        <input type="hidden" name="aplicar" value="1">
        <button type="submit" class="btn btn-primary btn-lg"
                data-confirm="¿Aplicar <?= $totalCambios ?> cambio(s) al roster?">
            <i class="bi bi-check-lg"></i> Aplicar <?= $totalCambios ?> cambio<?= $totalCambios !== 1 ? 's' : '' ?>
        </button>
    </form>

<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
