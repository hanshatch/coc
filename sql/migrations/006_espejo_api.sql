-- ============================================================
-- Migración 006 — El sistema pasa a ser un espejo de la API
--
-- Principio: si el dato no lo entrega la API de Clash of Clans, no
-- existe en el sistema. Desaparece toda la captura manual, y con ella
-- los campos que solo se llenaban a mano y nunca se llenaron.
--
-- Auditoría previa sobre los datos reales: los campos que se eliminan
-- aquí tenían 0 usos en toda la vida del sistema.
--
-- Hay respaldo completo previo en ~/backups/ por si hace falta volver.
-- ============================================================

-- ── 1. Datos capturados a mano ────────────────────────────────
-- La API no puede reponerlos, pero sí vuelve a traer lo suyo: el cron
-- reimporta la CWL de julio y los asaltos de capital en su próxima
-- corrida, porque ese dato sí vive en la API.
DELETE FROM guerra_participaciones;
DELETE FROM guerras;
DELETE FROM cwl_participaciones;
DELETE FROM cwl_temporadas;

-- ── 2. Tablas que los snapshots reemplazan ────────────────────
-- Donaciones y Juegos del Clan ya no necesitan tablas propias: se
-- derivan de snapshots_jugador, que es lo único que la API permite.
-- Las cuatro estaban vacías.
DROP TABLE IF EXISTS donaciones;
DROP TABLE IF EXISTS donaciones_periodos;
DROP TABLE IF EXISTS juegos_participaciones;
DROP TABLE IF EXISTS juegos_clan;

-- ── 3. Jugadores fantasma ─────────────────────────────────────
-- Sin tag no hay forma de identificarlos contra la API: son nombres
-- sueltos de gente que ya se fue y que nunca vamos a poder reconciliar.
DELETE FROM jugadores WHERE tag IS NULL;

-- ── 4. Campos muertos ─────────────────────────────────────────
ALTER TABLE jugadores
    DROP COLUMN notas,
    DROP COLUMN fecha_ingreso,
    DROP COLUMN usuario;

ALTER TABLE jugadores
    MODIFY tag VARCHAR(15) NOT NULL,
    MODIFY nombre_juego VARCHAR(50) NOT NULL DEFAULT '';

ALTER TABLE guerra_participaciones
    DROP COLUMN defensa_estrellas,
    DROP COLUMN defensa_porcentaje;

ALTER TABLE cwl_temporadas
    DROP COLUMN liga,
    DROP COLUMN posicion_final,
    DROP COLUMN notas;

-- 'dia' quedó sin sentido: la API entrega la temporada completa y el
-- modelo real siempre fue un total por jugador, con 'dia' fijo en 1.
ALTER TABLE cwl_participaciones
    DROP INDEX uk_cwl_jugador_dia,
    DROP COLUMN dia,
    DROP COLUMN bonus,
    ADD UNIQUE KEY uk_cwl_jugador (temporada_id, jugador_id);

ALTER TABLE capital_semanas   DROP COLUMN notas;
ALTER TABLE capital_participaciones DROP COLUMN medallas_obtenidas;

-- ── 5. Guerras: lo que la API sí entrega ──────────────────────
-- El registro de guerras da porcentaje de destrucción de ambos lados,
-- que antes no se guardaba. 'api_id' es el endTime crudo y sirve para
-- que reimportar sea idempotente.
ALTER TABLE guerras
    DROP COLUMN notas,
    ADD COLUMN api_id VARCHAR(24) NULL AFTER id,
    ADD COLUMN destruccion_clan DECIMAL(5,2) NULL AFTER estrellas_clan,
    ADD COLUMN destruccion_oponente DECIMAL(5,2) NULL AFTER estrellas_oponente,
    ADD UNIQUE KEY uk_guerra_api (api_id);
