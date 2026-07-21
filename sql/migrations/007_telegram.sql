-- ============================================================
-- Migración 007 — Registro automático en Telegram
--
-- Para que el bot pueda aprobar o rechazar solicitudes sin que nadie
-- intervenga, necesita saber qué cuenta de Telegram corresponde a qué
-- jugador de Clash. Esa relación se establece una sola vez, cuando la
-- persona demuestra que controla la cuenta mediante el token que genera
-- el propio juego.
-- ============================================================

-- Relación verificada entre una cuenta de Telegram y un jugador.
CREATE TABLE IF NOT EXISTS telegram_vinculos (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    telegram_id      BIGINT       NOT NULL,
    jugador_id       INT          NOT NULL,
    telegram_nombre  VARCHAR(120) NULL,
    verificado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Únicos en ambos sentidos: una persona no puede reclamar dos
    -- cuentas de Clash, ni una cuenta de Clash tener dos Telegram.
    UNIQUE KEY uk_tg_id     (telegram_id),
    UNIQUE KEY uk_tg_jugador (jugador_id),
    CONSTRAINT fk_tv_jugador FOREIGN KEY (jugador_id) REFERENCES jugadores(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Estado de la conversación de alta. Es efímero: se borra al terminar.
CREATE TABLE IF NOT EXISTS telegram_conversaciones (
    telegram_id     BIGINT      PRIMARY KEY,
    paso            ENUM('espera_tag','espera_token') NOT NULL,
    tag_propuesto   VARCHAR(15) NULL,
    intentos        INT         NOT NULL DEFAULT 0,
    actualizado_en  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Ajustes que el sistema aprende solo, como el id del grupo cuando
-- alguien agrega el bot. Evita tener que configurarlos a mano.
CREATE TABLE IF NOT EXISTS ajustes (
    clave  VARCHAR(50) PRIMARY KEY,
    valor  TEXT        NULL,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
