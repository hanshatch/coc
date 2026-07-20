-- ============================================================
-- Migración 004 — Columna 'bonus' en cwl_participaciones
--
-- cwl_detalle.php recoge 'bonus' desde el formulario y lo guarda en un
-- UPDATE envuelto en try/catch con el comentario "columnas aún no
-- existen". Como la columna nunca se creó en producción, ese UPDATE
-- viene fallando en silencio: la medalla de bonus se captura y se
-- descarta sin avisar al usuario.
--
-- schema.sql ya la declara desde la corrección del esquema; esto la
-- alinea en la base existente.
-- ============================================================

ALTER TABLE cwl_participaciones
    ADD COLUMN bonus TINYINT(1) NOT NULL DEFAULT 0 AFTER ataques;
