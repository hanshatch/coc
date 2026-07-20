-- ============================================================
-- Migración 003 — Identidad real de los jugadores
--
-- Hasta ahora 'usuario' guardaba el nombre del jugador en mayúsculas
-- con un '#' pegado delante, imitando un tag sin serlo. Eso ata la
-- identidad al nombre visible, que en Clash of Clans se puede cambiar
-- cuando el jugador quiera: si alguien se renombra, se pierde su
-- historial de guerras y donaciones.
--
-- El tag real (#V889GC29) nunca cambia. Estas columnas lo guardan,
-- junto al nombre tal cual aparece en el juego, con sus tipografías
-- decorativas intactas.
--
-- 'usuario' se conserva sin tocar para no romper las pantallas
-- existentes, que lo usan para mostrar y ordenar.
-- ============================================================

ALTER TABLE jugadores
    ADD COLUMN tag VARCHAR(15) NULL AFTER id,
    ADD COLUMN nombre_juego VARCHAR(50) NULL AFTER tag,
    ADD COLUMN sincronizado_en DATETIME NULL AFTER notas;

-- Único pero acepta NULL: los jugadores que ya no están en el clan
-- pueden quedarse sin tag hasta que se identifiquen.
ALTER TABLE jugadores
    ADD UNIQUE KEY uk_jugador_tag (tag);
