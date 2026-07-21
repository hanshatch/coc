-- ============================================================
-- Migración 008 — El bot pasa a ser asistente de administradores
--
-- Se descarta el grupo abierto al clan, así que la verificación por
-- token deja de tener sentido: existía para decidir sin intervención
-- humana quién entraba. Con una lista corta de administradores, la
-- autorización la da directamente el dueño del bot.
-- ============================================================

DROP TABLE IF EXISTS telegram_conversaciones;
DROP TABLE IF EXISTS telegram_vinculos;

-- Quién puede recibir avisos y consultar al bot.
CREATE TABLE IF NOT EXISTS telegram_admins (
    telegram_id   BIGINT       PRIMARY KEY,
    nombre        VARCHAR(120) NULL,
    -- El dueño es quien puede autorizar a los demás. Solo hay uno.
    es_duenio     TINYINT(1)   NOT NULL DEFAULT 0,
    recibe_avisos TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
