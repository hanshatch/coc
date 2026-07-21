<?php
declare(strict_types=1);

/**
 * Vigilancia de eventos. Corre cada media hora.
 *
 * Hay cosas que no esperan al reloj: una guerra termina cuando termina,
 * y su detalle por jugador solo existe mientras la API lo expone. La
 * captura diaria llegaría tarde o directamente lo perdería.
 *
 * Es barato a propósito: dos llamadas a la API por corrida, y solo
 * trabaja de verdad cuando detecta un cambio.
 *
 * Cron de Hostinger:
 *   *\/30 * * * *  /usr/bin/php /home/USUARIO/.../\_coc/cron/eventos.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/eventos.php';

$inicio  = date('Y-m-d H:i:s');
$db      = getDB();
$hechos  = [];

function anotar(string $t): void
{
    global $hechos;
    $hechos[] = $t;
    echo "  $t\n";
}

echo "=== Eventos $inicio ===\n";

// ── 1. Jugadores nuevos ───────────────────────────────────────
try {
    $diff = cocDiffRoster();

    if ($diff['altas']) {
        $texto = avisoBienvenida($diff['altas']);
        if ($texto) {
            avisarAdmins($texto);
        }
        anotar(count($diff['altas']) . ' jugador(es) nuevo(s), aviso enviado');
    }

    if ($diff['altas'] || $diff['bajas'] || $diff['cambiosRol'] || $diff['reactivar']) {
        $r = cocAplicarSync($diff);
        anotar(sprintf('roster: %d altas, %d bajas', $r['altas'], $r['bajas']));
    }
} catch (Throwable $e) {
    echo "  [ERROR] roster — " . $e->getMessage() . "\n";
}

// ── 2. Estado de la guerra ────────────────────────────────────
try {
    $r = cocImportarGuerraActual();
    $estadoAhora = $r['estado'];
    $anterior    = tgAjuste('guerra_estado') ?? 'notInWar';

    // La guerra en curso, para saber a cuál corresponde el aviso.
    $guerra = $db->query(
        "SELECT id, api_id, resultado FROM guerras ORDER BY fecha DESC, id DESC LIMIT 1"
    )->fetch();

    if ($estadoAhora !== $anterior) {
        anotar("guerra: $anterior → $estadoAhora");

        // Terminó una guerra: felicitar y recordar la siguiente.
        if ($estadoAhora === 'warEnded' && $guerra) {
            $avisadas = tgAjuste('guerra_avisada') ?? '';
            if ($avisadas !== (string) $guerra['api_id']) {
                $texto = avisoFinDeGuerra((int) $guerra['id']);
                if ($texto) {
                    avisarAdmins($texto);
                    anotar('aviso de fin de guerra enviado');
                }
                avisarAdmins(avisoIniciarGuerra());
                anotar('recordatorio de nueva guerra enviado');
                tgGuardarAjuste('guerra_avisada', (string) $guerra['api_id']);
            }
        }

        // Nueva guerra en preparación: ya se sabe a quién convocaron.
        if ($estadoAhora === 'preparation') {
            anotar('guerra en preparación, roster capturado');
        }

        tgGuardarAjuste('guerra_estado', $estadoAhora);
    } else {
        anotar("guerra: sin cambios ($estadoAhora)");
    }
} catch (Throwable $e) {
    echo "  [ERROR] guerra — " . $e->getMessage() . "\n";
}

$db->prepare('INSERT INTO cron_ejecuciones (tarea, inicio, fin, estado, detalle) VALUES (?,?,NOW(),?,?)')
   ->execute(['eventos', $inicio, 'ok', implode(' | ', $hechos ?: ['sin novedades'])]);

echo "=== Fin ===\n";
