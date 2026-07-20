<?php
declare(strict_types=1);

/**
 * Sincronización del roster contra la API de Clash of Clans.
 *
 * El emparejamiento es por tag, que nunca cambia. El nombre solo se usa
 * como respaldo para los registros anteriores a la migración 003, que
 * todavía no tienen tag.
 */

require_once __DIR__ . '/coc_api.php';

/**
 * Reduce un nombre decorado a su esqueleto comparable.
 *
 * Los jugadores usan tipografías unicode y símbolos como adorno: 'phoînix',
 * 'DJ T¡T ¥', 'NOVA | BOLILLO'. Sin esta normalización, el mismo jugador
 * parecería dos personas distintas.
 */
function cocEsqueleto(string $s): string
{
    $map = [
        'Î'=>'I','Í'=>'I','Ì'=>'I','Ï'=>'I','¡'=>'I','|'=>'I','!'=>'I','1'=>'I',
        'É'=>'E','È'=>'E','Ê'=>'E','€'=>'E','3'=>'E',
        'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','@'=>'A','4'=>'A',
        'Ó'=>'O','Ò'=>'O','Ô'=>'O','Ö'=>'O','0'=>'O',
        'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U',
        'Ñ'=>'N','¥'=>'Y','$'=>'S','5'=>'S','7'=>'T','8'=>'B',
    ];

    return preg_replace('/[^A-Z0-9]/u', '', strtr(mb_strtoupper(trim($s)), $map)) ?? '';
}

/**
 * Compara el clan real contra la base sin escribir nada.
 *
 * @return array{
 *   miembros:int,
 *   sinCambios:list<array{api:array,fila:array}>,
 *   cambiosRol:list<array{api:array,fila:array,rolNuevo:string}>,
 *   reactivar:list<array{api:array,fila:array}>,
 *   altas:list<array>,
 *   bajas:list<array>
 * }
 * @throws CocApiException
 */
function cocDiffRoster(): array
{
    $db  = getDB();
    $api = cocMiembros();

    $filas = [];
    foreach ($db->query('SELECT id, tag, usuario, nombre_juego, rol_clan, activo FROM jugadores') as $r) {
        $filas[(int) $r['id']] = $r;
    }

    // Índice por tag: la vía fiable.
    $porTag = [];
    foreach ($filas as $id => $r) {
        if (!empty($r['tag'])) {
            $porTag[$r['tag']] = $id;
        }
    }

    $usados = [];
    $sinCambios = $cambiosRol = $reactivar = $altas = [];

    foreach ($api as $m) {
        $id = $porTag[$m['tag']] ?? null;

        // Respaldo por nombre solo para filas sin tag (previas a la migración 003).
        if ($id === null) {
            $e = cocEsqueleto($m['name']);
            foreach ($filas as $fid => $r) {
                if (isset($usados[$fid]) || !empty($r['tag'])) {
                    continue;
                }
                if (cocEsqueleto(ltrim((string) $r['usuario'], '#')) === $e) {
                    $id = $fid;
                    break;
                }
            }
        }

        if ($id === null) {
            $altas[] = $m;
            continue;
        }

        $usados[$id] = true;
        $fila     = $filas[$id];
        $rolNuevo = cocRolALocal($m['role']);
        $par      = ['api' => $m, 'fila' => $fila];

        if (!$fila['activo']) {
            $reactivar[] = $par;
        } elseif ($rolNuevo !== $fila['rol_clan']) {
            $cambiosRol[] = $par + ['rolNuevo' => $rolNuevo];
        } else {
            $sinCambios[] = $par;
        }
    }

    $bajas = [];
    foreach ($filas as $id => $r) {
        if (!isset($usados[$id]) && $r['activo']) {
            $bajas[] = $r;
        }
    }

    return [
        'miembros'   => count($api),
        'sinCambios' => $sinCambios,
        'cambiosRol' => $cambiosRol,
        'reactivar'  => $reactivar,
        'altas'      => $altas,
        'bajas'      => $bajas,
    ];
}

/**
 * Aplica el diff en una transacción. Las bajas se marcan inactivas, nunca
 * se borran: el ON DELETE CASCADE destruiría su historial de guerras y CWL.
 *
 * @param  array $diff Resultado de cocDiffRoster()
 * @return array{actualizados:int,altas:int,bajas:int}
 */
function cocAplicarSync(array $diff): array
{
    $db = getDB();
    $db->beginTransaction();

    try {
        $up = $db->prepare(
            'UPDATE jugadores
                SET tag=?, nombre_juego=?, rol_clan=?, activo=1, sincronizado_en=NOW()
              WHERE id=?'
        );

        $actualizados = 0;
        foreach ([...$diff['sinCambios'], ...$diff['cambiosRol'], ...$diff['reactivar']] as $p) {
            $up->execute([
                $p['api']['tag'],
                $p['api']['name'],
                cocRolALocal($p['api']['role']),
                $p['fila']['id'],
            ]);
            $actualizados++;
        }

        $ins = $db->prepare(
            'INSERT INTO jugadores (tag, nombre_juego, usuario, rol_clan, activo, fecha_ingreso, sincronizado_en)
             VALUES (?, ?, ?, ?, 1, CURDATE(), NOW())'
        );
        foreach ($diff['altas'] as $m) {
            $ins->execute([
                $m['tag'],
                $m['name'],
                '#' . mb_strtoupper($m['name']),
                cocRolALocal($m['role']),
            ]);
        }

        $baja = $db->prepare('UPDATE jugadores SET activo=0, sincronizado_en=NOW() WHERE id=?');
        foreach ($diff['bajas'] as $r) {
            $baja->execute([$r['id']]);
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return [
        'actualizados' => $actualizados,
        'altas'        => count($diff['altas']),
        'bajas'        => count($diff['bajas']),
    ];
}
