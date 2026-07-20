-- ============================================================
-- Migración 002 — Transición 'tag' → 'usuario' en jugadores
--                 y columna 'tamano' en guerras
--
-- Estas sentencias vivían dentro de jugadores.php y guerras.php y se
-- ejecutaban en CADA carga de página, con los errores silenciados.
-- Se documentan aquí como registro histórico: si tu base ya está al día
-- (lo normal), no hace falta ejecutarlas.
-- ============================================================

-- jugadores: renombrar 'tag' a 'usuario'
-- ALTER TABLE jugadores CHANGE tag usuario VARCHAR(50) NOT NULL;

-- jugadores: eliminar columnas obsoletas
-- ALTER TABLE jugadores DROP COLUMN nombre;
-- ALTER TABLE jugadores DROP COLUMN nivel_th;
-- ALTER TABLE jugadores DROP COLUMN nivel_jugador;

-- guerras: agregar tamaño de guerra
-- ALTER TABLE guerras ADD COLUMN tamano INT DEFAULT 15 AFTER oponente;

-- ------------------------------------------------------------
-- Columnas que el código ya usa pero que faltaban en schema.sql.
-- Si tu base de producción ya las tiene, MySQL dará error de columna
-- duplicada: es esperado y puedes ignorarlo.
-- ------------------------------------------------------------
-- ALTER TABLE cwl_temporadas     ADD COLUMN tamano  INT        NOT NULL DEFAULT 15 AFTER liga;
-- ALTER TABLE cwl_participaciones ADD COLUMN ataques TINYINT    NULL                AFTER porcentaje;
-- ALTER TABLE cwl_participaciones ADD COLUMN bonus   TINYINT(1) NOT NULL DEFAULT 0  AFTER ataques;
