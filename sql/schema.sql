-- ============================================================
-- Clash Tracker — Esquema
--
-- Generado desde la estructura real de producción, para que una
-- instalación limpia coincida con lo que corre hoy. El sistema es un
-- espejo de la API de Clash of Clans: no hay captura manual, así que
-- no existen tablas ni columnas para datos que la API no entregue.
--
-- Las migraciones incrementales viven en sql/migrations/.
-- ============================================================

SET NAMES utf8mb4;

-- Las tablas salen en orden alfabético, así que las claves foráneas
-- apuntan a tablas que todavía no existen al crearlas.
SET FOREIGN_KEY_CHECKS = 0;

/*M!999999\- enable the sandbox mode */ 

/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
CREATE TABLE `capital_participaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `semana_id` int(11) NOT NULL,
  `jugador_id` int(11) NOT NULL,
  `oro_aportado` bigint(20) NOT NULL DEFAULT 0,
  `ataques_realizados` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_capital_jugador` (`semana_id`,`jugador_id`),
  KEY `fk_cap_jugador` (`jugador_id`),
  CONSTRAINT `fk_cap_jugador` FOREIGN KEY (`jugador_id`) REFERENCES `jugadores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cap_semana` FOREIGN KEY (`semana_id`) REFERENCES `capital_semanas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `capital_semanas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha_inicio` date NOT NULL,
  `fecha_fin` date NOT NULL,
  `oro_total_recaudado` bigint(20) NOT NULL DEFAULT 0,
  `ataques_totales` int(11) NOT NULL DEFAULT 0,
  `distritos_destruidos` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `cron_ejecuciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tarea` varchar(50) NOT NULL,
  `inicio` datetime NOT NULL,
  `fin` datetime DEFAULT NULL,
  `estado` enum('ok','parcial','error') DEFAULT NULL,
  `detalle` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cron_inicio` (`inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `cwl_participaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `temporada_id` int(11) NOT NULL,
  `jugador_id` int(11) NOT NULL,
  `participo` tinyint(1) NOT NULL DEFAULT 0,
  `estrellas` tinyint(4) DEFAULT NULL,
  `porcentaje` decimal(5,2) DEFAULT NULL,
  `ataques` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cwl_jugador` (`temporada_id`,`jugador_id`),
  KEY `fk_cwl_jugador` (`jugador_id`),
  CONSTRAINT `fk_cwl_jugador` FOREIGN KEY (`jugador_id`) REFERENCES `jugadores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cwl_temporada` FOREIGN KEY (`temporada_id`) REFERENCES `cwl_temporadas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `cwl_temporadas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mes` varchar(7) NOT NULL,
  `tamano` int(11) DEFAULT 15,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `guerra_participaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `guerra_id` int(11) NOT NULL,
  `jugador_id` int(11) NOT NULL,
  `ataque1_estrellas` tinyint(4) DEFAULT NULL,
  `ataque1_porcentaje` decimal(5,2) DEFAULT NULL,
  `ataque2_estrellas` tinyint(4) DEFAULT NULL,
  `ataque2_porcentaje` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_guerra_jugador` (`guerra_id`,`jugador_id`),
  KEY `fk_gp_jugador` (`jugador_id`),
  CONSTRAINT `fk_gp_guerra` FOREIGN KEY (`guerra_id`) REFERENCES `guerras` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gp_jugador` FOREIGN KEY (`jugador_id`) REFERENCES `jugadores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `guerras` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `api_id` varchar(24) DEFAULT NULL,
  `fecha` date NOT NULL,
  `oponente` varchar(100) NOT NULL,
  `tamano` int(11) DEFAULT 15,
  `resultado` enum('victoria','derrota','empate','en_curso') NOT NULL DEFAULT 'en_curso',
  `estrellas_clan` int(11) NOT NULL DEFAULT 0,
  `destruccion_clan` decimal(5,2) DEFAULT NULL,
  `estrellas_oponente` int(11) NOT NULL DEFAULT 0,
  `destruccion_oponente` decimal(5,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_guerra_api` (`api_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `jugadores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tag` varchar(15) NOT NULL,
  `nombre_juego` varchar(50) NOT NULL DEFAULT '',
  `rol_clan` enum('lider','colider','veterano','miembro') NOT NULL DEFAULT 'miembro',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `sincronizado_en` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_jugador_tag` (`tag`),
  KEY `idx_activo` (`activo`),
  KEY `idx_rol_clan` (`rol_clan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `log_actividad` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `accion` varchar(50) NOT NULL,
  `tabla_afectada` varchar(50) NOT NULL,
  `registro_id` int(11) DEFAULT NULL,
  `detalle` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_log_fecha` (`created_at`),
  KEY `fk_log_usuario` (`usuario_id`),
  CONSTRAINT `fk_log_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `login_intentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `exitoso` tinyint(1) NOT NULL DEFAULT 0,
  `intentado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_intento_user` (`username`,`intentado_en`),
  KEY `idx_intento_ip` (`ip`,`intentado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `snapshots_clan` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `miembros` int(11) NOT NULL DEFAULT 0,
  `nivel` int(11) DEFAULT NULL,
  `puntos_clan` int(11) DEFAULT NULL,
  `puntos_capital` int(11) DEFAULT NULL,
  `guerras_ganadas` int(11) DEFAULT NULL,
  `guerras_perdidas` int(11) DEFAULT NULL,
  `guerras_empatadas` int(11) DEFAULT NULL,
  `racha_victorias` int(11) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_snap_clan_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `snapshots_jugador` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `jugador_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `donaciones` int(11) DEFAULT NULL,
  `donaciones_recibidas` int(11) DEFAULT NULL,
  `trofeos` int(11) DEFAULT NULL,
  `th_nivel` tinyint(4) DEFAULT NULL,
  `exp_nivel` int(11) DEFAULT NULL,
  `rol` varchar(20) DEFAULT NULL,
  `acum_guerra_estrellas` int(11) DEFAULT NULL,
  `acum_cwl_estrellas` int(11) DEFAULT NULL,
  `acum_capital_oro` bigint(20) DEFAULT NULL,
  `acum_juegos_puntos` bigint(20) DEFAULT NULL,
  `acum_donaciones` bigint(20) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_snap_jugador` (`jugador_id`,`fecha`),
  KEY `idx_snap_fecha` (`fecha`),
  CONSTRAINT `fk_snap_jugador` FOREIGN KEY (`jugador_id`) REFERENCES `jugadores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `rol` enum('admin','editor') NOT NULL DEFAULT 'editor',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

SET FOREIGN_KEY_CHECKS = 1;
