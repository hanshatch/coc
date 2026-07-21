<?php
declare(strict_types=1);

/**
 * Sincronización contra la API de Clash of Clans.
 *
 * El sistema es un espejo: todo lo que se guarda viene de la API y la
 * identidad de cada jugador es su tag, que nunca cambia. No hay captura
 * manual ni emparejamiento por nombre.
 */

require_once __DIR__ . '/coc_api.php';

/**
 * Compara el clan real contra la base sin escribir nada.
 *
 * @return array{miembros:int, sinCambios:list<array>, cambiosRol:list<array>,
 *               reactivar:list<array>, altas:list<array>, bajas:list<array>}
 * @throws CocApiException
 */
function cocDiffRoster(): array
{
    $db  = getDB();
    $api = cocMiembros();

    $porTag = [];
    foreach ($db->query('SELECT id, tag, nombre_juego, rol_clan, activo FROM jugadores') as $r) {
        $porTag[$r['tag']] = $r;
    }

    $vistos = [];
    $sinCambios = $cambiosRol = $reactivar = $altas = [];

    foreach ($api as $m) {
        $fila = $porTag[$m['tag']] ?? null;
        if ($fila === null) {
            $altas[] = $m;
            continue;
        }

        $vistos[$m['tag']] = true;
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
    foreach ($porTag as $tag => $r) {
        if (!isset($vistos[$tag]) && $r['activo']) {
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
 * se borran: conservarlas permite ver quién se fue y comparar su historial.
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
            'UPDATE jugadores SET nombre_juego=?, rol_clan=?, activo=1, sincronizado_en=NOW() WHERE id=?'
        );

        $actualizados = 0;
        foreach ([...$diff['sinCambios'], ...$diff['cambiosRol'], ...$diff['reactivar']] as $p) {
            $up->execute([$p['api']['name'], cocRolALocal($p['api']['role']), $p['fila']['id']]);
            $actualizados++;
        }

        foreach ($diff['altas'] as $m) {
            cocAsegurarJugador($m['tag'], $m['name'], cocRolALocal($m['role']), true);
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

/**
 * Devuelve el id del jugador con ese tag, creándolo si hace falta.
 *
 * Los datos de guerra y CWL a veces mencionan a alguien que ya salió del
 * clan y por eso no aparece en el roster. Se crea inactivo para poder
 * guardar su participación sin ensuciar la lista de miembros actuales.
 */
function cocAsegurarJugador(string $tag, string $nombre, string $rol = 'miembro', bool $activo = false): int
{
    $db   = getDB();
    $stmt = $db->prepare('SELECT id FROM jugadores WHERE tag = ?');
    $stmt->execute([$tag]);
    $id = $stmt->fetchColumn();

    if ($id) {
        $db->prepare('UPDATE jugadores SET nombre_juego = ? WHERE id = ?')->execute([$nombre, (int) $id]);
        return (int) $id;
    }

    $db->prepare(
        'INSERT INTO jugadores (tag, nombre_juego, rol_clan, activo, sincronizado_en) VALUES (?,?,?,?,NOW())'
    )->execute([$tag, $nombre, $rol, $activo ? 1 : 0]);

    return (int) $db->lastInsertId();
}

/**
 * Importa el registro de guerras. La API solo entrega totales del clan:
 * el desglose por jugador únicamente existe mientras la guerra está en
 * curso, y se captura por separado.
 *
 * @return array{nuevas:int,total:int}
 */
function cocImportarGuerras(int $limite = 50): array
{
    $db = getDB();
    $d  = cocGet('/clans/' . rawurlencode(cocNormalizarTag(COC_CLAN_TAG)) . '/warlog?limit=' . $limite);

    $busca = $db->prepare('SELECT id FROM guerras WHERE api_id = ?');
    $ins   = $db->prepare(
        'INSERT INTO guerras (api_id, fecha, oponente, tamano, resultado,
                              estrellas_clan, destruccion_clan, estrellas_oponente, destruccion_oponente)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );

    $mapa   = ['win' => 'victoria', 'lose' => 'derrota', 'tie' => 'empate'];
    $nuevas = 0;
    $total  = 0;

    foreach ($d['items'] ?? [] as $w) {
        $total++;
        $apiId = (string) ($w['endTime'] ?? '');
        if ($apiId === '') {
            continue;
        }
        $busca->execute([$apiId]);
        if ($busca->fetchColumn()) {
            continue;
        }

        $ins->execute([
            $apiId,
            date('Y-m-d', strtotime(substr($apiId, 0, 8))),
            // Los clanes con registro privado llegan sin nombre.
            (string) ($w['opponent']['name'] ?? 'Desconocido'),
            (int) ($w['teamSize'] ?? 0),
            $mapa[$w['result'] ?? ''] ?? 'en_curso',
            (int) ($w['clan']['stars'] ?? 0),
            round((float) ($w['clan']['destructionPercentage'] ?? 0), 2),
            (int) ($w['opponent']['stars'] ?? 0),
            round((float) ($w['opponent']['destructionPercentage'] ?? 0), 2),
        ]);
        $nuevas++;
    }

    return ['nuevas' => $nuevas, 'total' => $total];
}

/**
 * Captura la guerra en curso con su detalle por jugador.
 *
 * Es la única ventana para saber quién atacó en una guerra normal: el
 * historial solo guarda totales del clan. Si nadie mira mientras la
 * guerra está viva, ese dato se pierde.
 *
 * Se registra desde la fase de preparación, cuando todavía no hay
 * ataques pero ya se sabe a quién metieron al mapa. Esa distinción es la
 * que permite detectar al que fue convocado y no atacó.
 *
 * @return array{estado:string,jugadores:int}
 * @throws CocApiException
 */
function cocImportarGuerraActual(): array
{
    $db = getDB();
    $w  = cocGet('/clans/' . rawurlencode(cocNormalizarTag(COC_CLAN_TAG)) . '/currentwar');

    $estado = (string) ($w['state'] ?? 'notInWar');
    if (!in_array($estado, ['preparation', 'inWar', 'warEnded'], true)) {
        return ['estado' => $estado, 'jugadores' => 0];
    }

    $apiId = (string) ($w['endTime'] ?? '');
    if ($apiId === '') {
        return ['estado' => $estado, 'jugadores' => 0];
    }

    $mios   = $w['clan'] ?? [];
    $rival  = $w['opponent'] ?? [];
    $result = 'en_curso';
    if ($estado === 'warEnded') {
        $ne = (int) ($mios['stars'] ?? 0);
        $re = (int) ($rival['stars'] ?? 0);
        $nd = (float) ($mios['destructionPercentage'] ?? 0);
        $rd = (float) ($rival['destructionPercentage'] ?? 0);
        $result = $ne > $re ? 'victoria' : ($ne < $re ? 'derrota' : ($nd > $rd ? 'victoria' : ($nd < $rd ? 'derrota' : 'empate')));
    }

    $busca = $db->prepare('SELECT id FROM guerras WHERE api_id = ?');
    $busca->execute([$apiId]);
    $gid = $busca->fetchColumn();

    $datos = [
        date('Y-m-d', strtotime(substr($apiId, 0, 8))),
        (string) ($rival['name'] ?? 'Desconocido'),
        (int) ($w['teamSize'] ?? 0),
        $result,
        (int) ($mios['stars'] ?? 0),
        round((float) ($mios['destructionPercentage'] ?? 0), 2),
        (int) ($rival['stars'] ?? 0),
        round((float) ($rival['destructionPercentage'] ?? 0), 2),
    ];

    if ($gid) {
        $db->prepare(
            'UPDATE guerras SET fecha=?, oponente=?, tamano=?, resultado=?,
                    estrellas_clan=?, destruccion_clan=?, estrellas_oponente=?, destruccion_oponente=?
              WHERE id=?'
        )->execute([...$datos, $gid]);
    } else {
        $db->prepare(
            'INSERT INTO guerras (fecha, oponente, tamano, resultado,
                                  estrellas_clan, destruccion_clan, estrellas_oponente, destruccion_oponente, api_id)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([...$datos, $apiId]);
        $gid = (int) $db->lastInsertId();
    }

    $ins = $db->prepare(
        'INSERT INTO guerra_participaciones
            (guerra_id, jugador_id, ataque1_estrellas, ataque1_porcentaje, ataque2_estrellas, ataque2_porcentaje)
         VALUES (?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            ataque1_estrellas=VALUES(ataque1_estrellas), ataque1_porcentaje=VALUES(ataque1_porcentaje),
            ataque2_estrellas=VALUES(ataque2_estrellas), ataque2_porcentaje=VALUES(ataque2_porcentaje)'
    );

    $n = 0;
    foreach ($mios['members'] ?? [] as $m) {
        $jid = cocAsegurarJugador($m['tag'], $m['name']);
        $a   = $m['attacks'] ?? [];
        // Sin ataque queda en NULL, que es distinto de cero estrellas:
        // NULL significa que no atacó, 0 que atacó y falló.
        $ins->execute([
            (int) $gid, $jid,
            isset($a[0]) ? (int) $a[0]['stars'] : null,
            isset($a[0]) ? round((float) $a[0]['destructionPercentage'], 2) : null,
            isset($a[1]) ? (int) $a[1]['stars'] : null,
            isset($a[1]) ? round((float) $a[1]['destructionPercentage'], 2) : null,
        ]);
        $n++;
    }

    return ['estado' => $estado, 'jugadores' => $n];
}

/**
 * Importa la CWL de la temporada corriente, con el detalle por jugador.
 * Supercell solo conserva la temporada en curso, así que esto tiene que
 * pasar antes de que arranque la siguiente.
 *
 * @return array{mes:string,jugadores:int}
 * @throws CocApiException
 */
function cocImportarCwl(): array
{
    $db    = getDB();
    $miTag = cocNormalizarTag(COC_CLAN_TAG);
    $g     = cocGet('/clans/' . rawurlencode($miTag) . '/currentwar/leaguegroup');
    $mes   = substr((string) ($g['season'] ?? ''), 0, 7);

    if ($mes === '') {
        return ['mes' => '', 'jugadores' => 0];
    }

    $agg = [];
    foreach ($g['rounds'] ?? [] as $r) {
        foreach ($r['warTags'] ?? [] as $wt) {
            if ($wt === '#0') {
                continue;
            }
            try {
                $w = cocGet('/clanwarleagues/wars/' . rawurlencode($wt));
            } catch (Throwable $e) {
                continue;
            }
            $lado = (($w['clan']['tag'] ?? '') === $miTag) ? $w['clan']
                  : ((($w['opponent']['tag'] ?? '') === $miTag) ? $w['opponent'] : null);
            if (!$lado) {
                continue;
            }
            foreach ($lado['members'] ?? [] as $m) {
                $agg[$m['tag']] ??= ['name' => $m['name'], 'est' => 0, 'pct' => 0.0, 'atq' => 0];
                foreach ($m['attacks'] ?? [] as $a) {
                    $agg[$m['tag']]['est'] += (int) $a['stars'];
                    $agg[$m['tag']]['pct'] += (float) $a['destructionPercentage'];
                    $agg[$m['tag']]['atq']++;
                }
            }
        }
    }

    if (!$agg) {
        return ['mes' => $mes, 'jugadores' => 0];
    }

    $tid = $db->query('SELECT id FROM cwl_temporadas WHERE mes = ' . $db->quote($mes))->fetchColumn();
    if (!$tid) {
        $db->prepare('INSERT INTO cwl_temporadas (mes, tamano) VALUES (?,?)')->execute([$mes, count($agg)]);
        $tid = (int) $db->lastInsertId();
    }

    $ins = $db->prepare(
        'INSERT INTO cwl_participaciones (temporada_id, jugador_id, participo, estrellas, porcentaje, ataques)
         VALUES (?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE participo=VALUES(participo), estrellas=VALUES(estrellas),
            porcentaje=VALUES(porcentaje), ataques=VALUES(ataques)'
    );

    $n = 0;
    foreach ($agg as $tag => $a) {
        $jid = cocAsegurarJugador($tag, $a['name']);
        $ins->execute([$tid, $jid, $a['atq'] > 0 ? 1 : 0, $a['est'], round($a['pct'], 2), $a['atq']]);
        $n++;
    }

    return ['mes' => $mes, 'jugadores' => $n];
}

/**
 * Importa los fines de semana de asalto al capital. Solo el más reciente
 * trae desglose por jugador: los anteriores llegan con totales del clan.
 *
 * @return array{semanas:int,participaciones:int}
 * @throws CocApiException
 */
function cocImportarCapital(int $limite = 10): array
{
    $db = getDB();
    $c  = cocGet('/clans/' . rawurlencode(cocNormalizarTag(COC_CLAN_TAG)) . '/capitalraidseasons?limit=' . $limite);

    $busca = $db->prepare('SELECT id FROM capital_semanas WHERE fecha_inicio = ?');
    $ins   = $db->prepare('INSERT INTO capital_semanas (fecha_inicio, fecha_fin, oro_total_recaudado, ataques_totales, distritos_destruidos) VALUES (?,?,?,?,?)');
    $upd   = $db->prepare('UPDATE capital_semanas SET fecha_fin=?, oro_total_recaudado=?, ataques_totales=?, distritos_destruidos=? WHERE id=?');
    $bPart = $db->prepare('SELECT id FROM capital_participaciones WHERE semana_id=? AND jugador_id=?');
    $iPart = $db->prepare('INSERT INTO capital_participaciones (semana_id, jugador_id, oro_aportado, ataques_realizados) VALUES (?,?,?,?)');
    $uPart = $db->prepare('UPDATE capital_participaciones SET oro_aportado=?, ataques_realizados=? WHERE id=?');

    $semanas = $participaciones = 0;

    foreach ($c['items'] ?? [] as $s) {
        $ini = date('Y-m-d', strtotime(substr((string) $s['startTime'], 0, 8)));
        $fin = date('Y-m-d', strtotime(substr((string) $s['endTime'], 0, 8)));
        $busca->execute([$ini]);
        $sid = $busca->fetchColumn();

        if ($sid) {
            $upd->execute([$fin, $s['capitalTotalLoot'] ?? 0, $s['totalAttacks'] ?? 0, $s['enemyDistrictsDestroyed'] ?? 0, $sid]);
        } else {
            $ins->execute([$ini, $fin, $s['capitalTotalLoot'] ?? 0, $s['totalAttacks'] ?? 0, $s['enemyDistrictsDestroyed'] ?? 0]);
            $sid = (int) $db->lastInsertId();
            $semanas++;
        }

        foreach ($s['members'] ?? [] as $m) {
            $jid = cocAsegurarJugador($m['tag'], $m['name']);
            $bPart->execute([$sid, $jid]);
            $pid = $bPart->fetchColumn();
            if ($pid) {
                $uPart->execute([$m['capitalResourcesLooted'] ?? 0, $m['attacks'] ?? 0, $pid]);
            } else {
                $iPart->execute([$sid, $jid, $m['capitalResourcesLooted'] ?? 0, $m['attacks'] ?? 0]);
            }
            $participaciones++;
        }
    }

    return ['semanas' => $semanas, 'participaciones' => $participaciones];
}
