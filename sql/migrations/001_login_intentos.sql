-- ============================================================
-- Migración 001 — Tabla de intentos de login (rate limiting)
-- Ejecutar una vez sobre la base de producción (phpMyAdmin de Hostinger).
-- Sin ella, el control de fuerza bruta queda inactivo (falla en abierto
-- y lo registra en el log de errores de PHP).
-- ============================================================

CREATE TABLE IF NOT EXISTS login_intentos (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL,
    ip            VARCHAR(45)  NOT NULL,
    exitoso       TINYINT(1)   NOT NULL DEFAULT 0,
    intentado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_intento_user (username, intentado_en),
    INDEX idx_intento_ip   (ip, intentado_en)
) ENGINE=InnoDB;
