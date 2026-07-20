<?php
declare(strict_types=1);

/**
 * Liga de guerras (CWL). Solo lectura desde la API.
 * Supercell solo conserva la temporada corriente, así que el histórico
 * depende de que el cron la capture antes de que arranque la siguiente.
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db     = getDB();
$temps  = $db->query(
    'SELECT t.*,
            COUNT(p.id) jugadores,
            SUM(p.estrellas) estrellas,
            SUM(p.ataques)   ataques
       FROM cwl_temporadas t
       LEFT JOIN cwl_participaciones p ON p.temporada_id = t.id
      GROUP BY t.id
      ORDER BY t.mes DESC'
)->fetchAll();

$pageTitle = 'Liga de guerras';
require __DIR__ . '/includes/header.php';
?>

<div class="ct-page-header">
    <h1><i class="bi bi-trophy-fill"></i> Liga de guerras</h1>
</div>

<?php if (!$temps): ?>
    <div class="empty-state">
        <div class="empty-icon">🏆</div>
        <p>Sin temporadas registradas. Se importan solas cuando hay una CWL en curso.</p>
    </div>
<?php else: ?>
<div class="card"><div class="table-responsive">
    <table class="table table-hover mb-0">
        <thead><tr>
            <th>Temporada</th><th class="text-center">Jugadores</th>
            <th class="text-center">Estrellas</th><th class="text-center">Ataques</th>
            <th class="text-end">Detalle</th>
        </tr></thead>
        <tbody>
        <?php foreach ($temps as $t):
            $maxAtaques = (int) $t['jugadores'] * 7;
            $uso = $maxAtaques ? round((int) $t['ataques'] * 100 / $maxAtaques) : 0;
        ?>
            <tr>
                <td><strong><?= clean($t['mes']) ?></strong></td>
                <td class="text-center"><?= (int) $t['jugadores'] ?></td>
                <td class="text-center"><strong><?= (int) $t['estrellas'] ?></strong></td>
                <td class="text-center">
                    <?= (int) $t['ataques'] ?>/<?= $maxAtaques ?>
                    <span class="badge <?= $uso >= 85 ? 'badge-green' : ($uso >= 60 ? 'badge-gold' : 'badge-red') ?>"><?= $uso ?>%</span>
                </td>
                <td class="text-end"><a href="cwl_detalle?id=<?= (int) $t['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<div class="text-muted text-center mt-3" style="font-size:.85rem">
    El porcentaje mide ataques usados sobre los 7 posibles por jugador.
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
