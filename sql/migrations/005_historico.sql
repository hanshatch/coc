-- ============================================================
-- Migración 005 — Histórico diario
--
-- La API de Clash of Clans es una foto del presente, no un archivo:
--   · Las donaciones se reinician cada temporada.
--   · El detalle por jugador de los asaltos de capital solo existe
--     para el fin de semana más reciente.
--   · Los Juegos del Clan solo se pueden medir por diferencia entre
--     dos lecturas del acumulado de por vida.
--
-- Estas tablas guardan una lectura diaria para que el historial se
-- construya con el tiempo y se puedan calcular deltas.
-- ============================================================

-- Estado del clan, una fila por día.
CREATE TABLE IF NOT EXISTS snapshots_clan (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    fecha             DATE     NOT NULL,
    miembros          INT      NOT NULL DEFAULT 0,
    nivel             INT      NULL,
    puntos_clan       INT      NULL,
    puntos_capital    INT      NULL,
    guerras_ganadas   INT      NULL,
    guerras_perdidas  INT      NULL,
    guerras_empatadas INT      NULL,
    racha_victorias   INT      NULL,
    creado_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_snap_clan_fecha (fecha)
) ENGINE=InnoDB;

-- Estado de cada jugador, una fila por jugador y día.
CREATE TABLE IF NOT EXISTS snapshots_jugador (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    jugador_id            INT      NOT NULL,
    fecha                 DATE     NOT NULL,

    -- Valores de temporada: se reinician solos, hay que capturarlos antes.
    donaciones            INT      NULL,
    donaciones_recibidas  INT      NULL,
    trofeos               INT      NULL,

    -- Estado del momento.
    th_nivel              TINYINT  NULL,
    exp_nivel             INT      NULL,
    rol                   VARCHAR(20) NULL,

    -- Acumulados de por vida. La diferencia entre dos días da lo
    -- aportado en ese periodo, que es como se miden los Juegos del Clan.
    acum_guerra_estrellas INT      NULL,
    acum_cwl_estrellas    INT      NULL,
    acum_capital_oro      BIGINT   NULL,
    acum_juegos_puntos    BIGINT   NULL,
    acum_donaciones       BIGINT   NULL,

    creado_en             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_snap_jugador (jugador_id, fecha),
    INDEX idx_snap_fecha (fecha),
    CONSTRAINT fk_snap_jugador FOREIGN KEY (jugador_id) REFERENCES jugadores(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Bitácora del cron, para poder ver si dejó de correr.
CREATE TABLE IF NOT EXISTS cron_ejecuciones (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    tarea    VARCHAR(50) NOT NULL,
    inicio   DATETIME    NOT NULL,
    fin      DATETIME    NULL,
    estado   ENUM('ok','parcial','error') NULL,
    detalle  TEXT        NULL,
    INDEX idx_cron_inicio (inicio)
) ENGINE=InnoDB;
