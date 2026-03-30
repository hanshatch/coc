-- ============================================================
-- Clash Tracker — Schema SQL
-- Base de datos: clash_tracker
-- Charset: utf8mb4 / Collation: utf8mb4_unicode_ci
-- ============================================================

CREATE DATABASE IF NOT EXISTS clash_tracker
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE clash_tracker;

-- ------------------------------------------------------------
-- Usuarios del sistema
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    nombre        VARCHAR(100) NOT NULL,
    rol           ENUM('admin','editor') NOT NULL DEFAULT 'editor',
    activo        TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Jugadores del clan
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS jugadores (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    tag            VARCHAR(15)  NOT NULL UNIQUE,
    nombre         VARCHAR(50)  NOT NULL,
    nivel_th       INT          NOT NULL DEFAULT 1,
    nivel_jugador  INT          NOT NULL DEFAULT 1,
    rol_clan       ENUM('lider','colider','veterano','miembro') NOT NULL DEFAULT 'miembro',
    fecha_ingreso  DATE         NULL,
    activo         TINYINT(1)   NOT NULL DEFAULT 1,
    notas          TEXT         NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_activo   (activo),
    INDEX idx_rol_clan (rol_clan)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Guerras normales
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS guerras (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    fecha              DATE         NOT NULL,
    oponente           VARCHAR(100) NOT NULL,
    resultado          ENUM('victoria','derrota','empate','en_curso') NOT NULL DEFAULT 'en_curso',
    estrellas_clan     INT          NOT NULL DEFAULT 0,
    estrellas_oponente INT          NOT NULL DEFAULT 0,
    notas              TEXT         NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Participaciones en guerras
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS guerra_participaciones (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    guerra_id           INT NOT NULL,
    jugador_id          INT NOT NULL,
    ataque1_estrellas   TINYINT      NULL,
    ataque1_porcentaje  DECIMAL(5,2) NULL,
    ataque2_estrellas   TINYINT      NULL,
    ataque2_porcentaje  DECIMAL(5,2) NULL,
    defensa_estrellas   TINYINT      NULL,
    defensa_porcentaje  DECIMAL(5,2) NULL,
    UNIQUE KEY uk_guerra_jugador (guerra_id, jugador_id),
    CONSTRAINT fk_gp_guerra  FOREIGN KEY (guerra_id)  REFERENCES guerras(id)   ON DELETE CASCADE,
    CONSTRAINT fk_gp_jugador FOREIGN KEY (jugador_id) REFERENCES jugadores(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Temporadas CWL
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cwl_temporadas (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    mes             VARCHAR(7)  NOT NULL,
    liga            VARCHAR(50) NULL,
    posicion_final  INT         NULL,
    notas           TEXT        NULL,
    created_at      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Participaciones CWL (por día)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cwl_participaciones (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    temporada_id  INT          NOT NULL,
    jugador_id    INT          NOT NULL,
    dia           INT          NOT NULL,
    participo     TINYINT(1)   NOT NULL DEFAULT 0,
    estrellas     TINYINT      NULL,
    porcentaje    DECIMAL(5,2) NULL,
    UNIQUE KEY uk_cwl_jugador_dia (temporada_id, jugador_id, dia),
    CONSTRAINT fk_cwl_temporada FOREIGN KEY (temporada_id) REFERENCES cwl_temporadas(id) ON DELETE CASCADE,
    CONSTRAINT fk_cwl_jugador   FOREIGN KEY (jugador_id)   REFERENCES jugadores(id)      ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Juegos del Clan
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS juegos_clan (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    fecha_inicio    DATE       NOT NULL,
    fecha_fin       DATE       NOT NULL,
    meta_puntos     INT        NOT NULL DEFAULT 50000,
    puntos_totales  INT        NOT NULL DEFAULT 0,
    completado      TINYINT(1) NOT NULL DEFAULT 0,
    notas           TEXT       NULL,
    created_at      DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Participaciones en juegos del clan
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS juegos_participaciones (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    juego_id        INT        NOT NULL,
    jugador_id      INT        NOT NULL,
    puntos          INT        NOT NULL DEFAULT 0,
    alcanzo_maximo  TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uk_juego_jugador (juego_id, jugador_id),
    CONSTRAINT fk_jp_juego   FOREIGN KEY (juego_id)   REFERENCES juegos_clan(id) ON DELETE CASCADE,
    CONSTRAINT fk_jp_jugador FOREIGN KEY (jugador_id) REFERENCES jugadores(id)   ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Periodos de donaciones
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS donaciones_periodos (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    fecha_inicio  DATE NOT NULL,
    fecha_fin     DATE NOT NULL,
    tipo          ENUM('semanal','mensual') NOT NULL DEFAULT 'semanal',
    notas         TEXT     NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Donaciones por jugador
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS donaciones (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    periodo_id       INT NOT NULL,
    jugador_id       INT NOT NULL,
    tropas_donadas   INT NOT NULL DEFAULT 0,
    tropas_recibidas INT NOT NULL DEFAULT 0,
    UNIQUE KEY uk_periodo_jugador (periodo_id, jugador_id),
    CONSTRAINT fk_don_periodo FOREIGN KEY (periodo_id) REFERENCES donaciones_periodos(id) ON DELETE CASCADE,
    CONSTRAINT fk_don_jugador FOREIGN KEY (jugador_id) REFERENCES jugadores(id)           ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Semanas de Capital de Clan
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS capital_semanas (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    fecha_inicio         DATE       NOT NULL,
    fecha_fin            DATE       NOT NULL,
    oro_total_recaudado  BIGINT     NOT NULL DEFAULT 0,
    ataques_totales      INT        NOT NULL DEFAULT 0,
    distritos_destruidos INT        NOT NULL DEFAULT 0,
    notas                TEXT       NULL,
    created_at           DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Participaciones en capital
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS capital_participaciones (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    semana_id           INT    NOT NULL,
    jugador_id          INT    NOT NULL,
    oro_aportado        BIGINT NOT NULL DEFAULT 0,
    ataques_realizados  INT    NOT NULL DEFAULT 0,
    medallas_obtenidas  INT    NOT NULL DEFAULT 0,
    UNIQUE KEY uk_capital_jugador (semana_id, jugador_id),
    CONSTRAINT fk_cap_semana  FOREIGN KEY (semana_id)  REFERENCES capital_semanas(id) ON DELETE CASCADE,
    CONSTRAINT fk_cap_jugador FOREIGN KEY (jugador_id) REFERENCES jugadores(id)       ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Log de actividad
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS log_actividad (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id      INT          NOT NULL,
    accion          VARCHAR(50)  NOT NULL,
    tabla_afectada  VARCHAR(50)  NOT NULL,
    registro_id     INT          NULL,
    detalle         TEXT         NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_log_fecha (created_at),
    CONSTRAINT fk_log_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;
