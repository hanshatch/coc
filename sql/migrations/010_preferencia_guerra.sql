-- ============================================================
-- Migración 010 — Preferencia de guerra
--
-- La API expone warPreference: 'in' si el jugador tiene la
-- participación en guerra activada, 'out' si la apagó. Es de los pocos
-- ajustes personales que Supercell publica.
--
-- Sirve para no recomendar en el armado de guerra a quien no puede ser
-- incluido, y para no confundir "tiene la guerra apagada" con "no
-- participa por flojera": lo primero es una configuración, no una falta.
--
-- Se guarda por día para ver el histórico: apagar la guerra suele
-- anticipar que alguien piensa ausentarse.
-- ============================================================

ALTER TABLE snapshots_jugador
    ADD COLUMN guerra_activa TINYINT(1) NULL AFTER rol;
