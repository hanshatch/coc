-- ============================================================
-- Migración 009 — Registro de quién le escribe al bot
--
-- El bot rechazaba a los no autorizados sin dejar rastro, así que
-- cuando alguien pedía acceso no había forma de saber quién fue ni de
-- dárselo. Ahora queda anotado y se puede autorizar desde el bot.
-- ============================================================

CREATE TABLE IF NOT EXISTS telegram_intentos (
    telegram_id     BIGINT       PRIMARY KEY,
    nombre          VARCHAR(120) NULL,
    usuario         VARCHAR(64)  NULL,
    veces           INT          NOT NULL DEFAULT 1,
    primer_intento  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultimo_intento  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
