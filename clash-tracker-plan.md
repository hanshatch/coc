# Clash of Clans — Clan Tracker System

## Resumen del Proyecto

Sistema web CRUD para gestionar un clan de Clash of Clans. Permite registrar jugadores y llevar control de su participación en las distintas actividades del juego. El sistema soporta múltiples usuarios (colaboradores) con roles diferenciados para que otros miembros ayuden a actualizar la información.

---

## Stack Tecnológico

- **Backend:** PHP 8+ (vanilla, sin frameworks)
- **Base de datos:** MySQL 8 con PDO
- **Frontend:** HTML5, CSS3 vanilla, JavaScript vanilla
- **Sin dependencias externas** (no Composer, no npm, no CDN obligatorios)
- **Servidor:** Apache con mod_rewrite o cualquier servidor PHP

---

## Estructura de Carpetas

```
clash-tracker/
├── config/
│   └── database.php              # Conexión PDO y constantes globales
├── includes/
│   ├── auth.php                  # Sesiones, login, CSRF, helpers
│   ├── header.php                # Template: HTML head + sidebar + nav
│   └── footer.php                # Template: cierre HTML + scripts globales
├── assets/
│   └── style.css                 # Estilos globales del sistema
├── sql/
│   └── schema.sql                # DDL completo de la base de datos
├── setup.php                     # Script de setup inicial (crear admin)
├── login.php                     # Pantalla de login
├── logout.php                    # Handler de logout
├── index.php                     # Dashboard principal con resumen
├── jugadores.php                 # CRUD de jugadores del clan
├── guerras.php                   # CRUD de guerras normales
├── guerra_detalle.php            # Participaciones de una guerra específica
├── cwl.php                       # CRUD de temporadas CWL
├── cwl_detalle.php               # Participaciones por día de una temporada CWL
├── juegos.php                    # CRUD de juegos del clan
├── juegos_detalle.php            # Participaciones de un evento de juegos
├── donaciones.php                # CRUD de periodos de donación
├── donaciones_detalle.php        # Registro de donaciones por jugador
├── capital.php                   # CRUD de semanas de capital de clan
├── capital_detalle.php           # Participaciones de una semana de capital
├── usuarios.php                  # CRUD de usuarios del sistema (solo admin)
├── log.php                       # Vista del log de actividad (solo admin)
└── README.md
```

---

## Base de Datos — Esquema Completo

### Tabla `usuarios`

Usuarios del sistema que pueden iniciar sesión y gestionar datos.

| Columna       | Tipo                         | Notas                          |
|---------------|------------------------------|--------------------------------|
| id            | INT AUTO_INCREMENT PK        |                                |
| username      | VARCHAR(50) UNIQUE NOT NULL  |                                |
| password_hash | VARCHAR(255) NOT NULL        | Generado con password_hash()   |
| nombre        | VARCHAR(100) NOT NULL        | Nombre para mostrar            |
| rol           | ENUM('admin','editor')       | Default 'editor'               |
| activo        | TINYINT(1)                   | Default 1                      |
| created_at    | DATETIME                     | Default CURRENT_TIMESTAMP      |
| updated_at    | DATETIME                     | ON UPDATE CURRENT_TIMESTAMP    |

- **admin**: acceso total, puede crear/editar/eliminar usuarios y ver log de actividad.
- **editor**: puede gestionar jugadores, guerras, juegos, donaciones y capital, pero NO gestionar usuarios ni ver log.

### Tabla `jugadores`

Miembros del clan.

| Columna        | Tipo                                            | Notas                     |
|----------------|-------------------------------------------------|---------------------------|
| id             | INT AUTO_INCREMENT PK                           |                           |
| tag            | VARCHAR(15) UNIQUE NOT NULL                     | Tag de CoC (#ABC123)      |
| nombre         | VARCHAR(50) NOT NULL                            |                           |
| nivel_th       | INT DEFAULT 1                                   | Town Hall 1-17            |
| nivel_jugador  | INT DEFAULT 1                                   |                           |
| rol_clan       | ENUM('lider','colider','veterano','miembro')    | Default 'miembro'         |
| fecha_ingreso  | DATE NULL                                       |                           |
| activo         | TINYINT(1) DEFAULT 1                            |                           |
| notas          | TEXT NULL                                       |                           |
| created_at     | DATETIME                                        |                           |
| updated_at     | DATETIME                                        |                           |

Índices en: `activo`, `rol_clan`.

### Tabla `guerras`

Guerras normales del clan.

| Columna            | Tipo                                                 | Notas              |
|--------------------|------------------------------------------------------|---------------------|
| id                 | INT AUTO_INCREMENT PK                                |                     |
| fecha              | DATE NOT NULL                                        |                     |
| oponente           | VARCHAR(100) NOT NULL                                | Nombre clan enemigo |
| resultado          | ENUM('victoria','derrota','empate','en_curso')       | Default 'en_curso'  |
| estrellas_clan     | INT DEFAULT 0                                        |                     |
| estrellas_oponente | INT DEFAULT 0                                        |                     |
| notas              | TEXT NULL                                            |                     |
| created_at         | DATETIME                                             |                     |

### Tabla `guerra_participaciones`

Registro de ataques y defensas de cada jugador en una guerra.

| Columna             | Tipo           | Notas                              |
|---------------------|----------------|-------------------------------------|
| id                  | INT PK         |                                     |
| guerra_id           | INT FK         | → guerras(id) ON DELETE CASCADE     |
| jugador_id          | INT FK         | → jugadores(id) ON DELETE CASCADE   |
| ataque1_estrellas   | TINYINT NULL   | 0-3                                 |
| ataque1_porcentaje  | DECIMAL(5,2)   |                                     |
| ataque2_estrellas   | TINYINT NULL   | 0-3                                 |
| ataque2_porcentaje  | DECIMAL(5,2)   |                                     |
| defensa_estrellas   | TINYINT NULL   |                                     |
| defensa_porcentaje  | DECIMAL(5,2)   |                                     |

UNIQUE KEY: `(guerra_id, jugador_id)`.

### Tabla `cwl_temporadas`

Temporadas mensuales de Liga de Guerra de Clan.

| Columna         | Tipo          | Notas                        |
|-----------------|---------------|-------------------------------|
| id              | INT PK        |                               |
| mes             | VARCHAR(7)    | Formato 'YYYY-MM'            |
| liga            | VARCHAR(50)   | Ej: "Cristal I"              |
| posicion_final  | INT NULL      | Posición 1-8                 |
| notas           | TEXT NULL     |                               |
| created_at      | DATETIME      |                               |

### Tabla `cwl_participaciones`

Participación diaria de cada jugador en la CWL (7 días).

| Columna      | Tipo          | Notas                                    |
|--------------|---------------|-------------------------------------------|
| id           | INT PK        |                                           |
| temporada_id | INT FK        | → cwl_temporadas(id) ON DELETE CASCADE    |
| jugador_id   | INT FK        | → jugadores(id) ON DELETE CASCADE         |
| dia          | INT           | 1-7                                       |
| participo    | TINYINT(1)    | Default 0                                 |
| estrellas    | TINYINT NULL  |                                           |
| porcentaje   | DECIMAL(5,2)  |                                           |

UNIQUE KEY: `(temporada_id, jugador_id, dia)`.

### Tabla `juegos_clan`

Eventos de Juegos del Clan.

| Columna         | Tipo         | Notas              |
|-----------------|--------------|---------------------|
| id              | INT PK       |                     |
| fecha_inicio    | DATE         |                     |
| fecha_fin       | DATE         |                     |
| meta_puntos     | INT          | Default 50000       |
| puntos_totales  | INT          | Default 0           |
| completado      | TINYINT(1)   | Default 0           |
| notas           | TEXT NULL    |                     |
| created_at      | DATETIME     |                     |

### Tabla `juegos_participaciones`

Puntos aportados por cada jugador.

| Columna         | Tipo         | Notas                                       |
|-----------------|--------------|----------------------------------------------|
| id              | INT PK       |                                              |
| juego_id        | INT FK       | → juegos_clan(id) ON DELETE CASCADE          |
| jugador_id      | INT FK       | → jugadores(id) ON DELETE CASCADE            |
| puntos          | INT          | Default 0                                    |
| alcanzo_maximo  | TINYINT(1)   | Default 0 (máximo individual = 4000)         |

UNIQUE KEY: `(juego_id, jugador_id)`.

### Tabla `donaciones_periodos`

Periodos para agrupar las donaciones (semanal o mensual).

| Columna      | Tipo                          | Notas          |
|--------------|-------------------------------|-----------------|
| id           | INT PK                        |                 |
| fecha_inicio | DATE                          |                 |
| fecha_fin    | DATE                          |                 |
| tipo         | ENUM('semanal','mensual')     | Default semanal |
| notas        | TEXT NULL                     |                 |
| created_at   | DATETIME                      |                 |

### Tabla `donaciones`

Registro de tropas donadas/recibidas por jugador en un periodo.

| Columna          | Tipo     | Notas                                          |
|------------------|----------|------------------------------------------------|
| id               | INT PK   |                                                |
| periodo_id       | INT FK   | → donaciones_periodos(id) ON DELETE CASCADE    |
| jugador_id       | INT FK   | → jugadores(id) ON DELETE CASCADE              |
| tropas_donadas   | INT      | Default 0                                      |
| tropas_recibidas | INT      | Default 0                                      |

UNIQUE KEY: `(periodo_id, jugador_id)`.

### Tabla `capital_semanas`

Semanas de raid de Capital de Clan.

| Columna              | Tipo      | Notas        |
|----------------------|-----------|--------------|
| id                   | INT PK    |              |
| fecha_inicio         | DATE      |              |
| fecha_fin            | DATE      |              |
| oro_total_recaudado  | BIGINT    | Default 0    |
| ataques_totales      | INT       | Default 0    |
| distritos_destruidos | INT       | Default 0    |
| notas                | TEXT NULL |              |
| created_at           | DATETIME  |              |

### Tabla `capital_participaciones`

Participación individual en capital.

| Columna             | Tipo      | Notas                                        |
|---------------------|-----------|-----------------------------------------------|
| id                  | INT PK    |                                               |
| semana_id           | INT FK    | → capital_semanas(id) ON DELETE CASCADE       |
| jugador_id          | INT FK    | → jugadores(id) ON DELETE CASCADE             |
| oro_aportado        | BIGINT    | Default 0                                     |
| ataques_realizados  | INT       | Default 0                                     |
| medallas_obtenidas  | INT       | Default 0                                     |

UNIQUE KEY: `(semana_id, jugador_id)`.

### Tabla `log_actividad`

Auditoría de acciones en el sistema.

| Columna         | Tipo         | Notas                          |
|-----------------|--------------|--------------------------------|
| id              | INT PK       |                                |
| usuario_id      | INT FK       | → usuarios(id)                 |
| accion          | VARCHAR(50)  | crear, editar, eliminar        |
| tabla_afectada  | VARCHAR(50)  |                                |
| registro_id     | INT NULL     |                                |
| detalle         | TEXT NULL     |                                |
| created_at      | DATETIME     | Índice en este campo           |

---

## Módulos y Funcionalidad Detallada

### 1. Autenticación y Sesiones (`includes/auth.php`)

- Sesiones PHP nativas con `session_start()`.
- Funciones: `isLoggedIn()`, `requireLogin()`, `requireAdmin()`, `currentUser()`, `login()`, `logout()`.
- Protección CSRF en todos los formularios con `csrfToken()`, `csrfField()`, `verifyCsrf()`.
- Mensajes flash: `setFlash($type, $message)` y `getFlash()`.
- Función `logActivity()` para registrar cada acción CRUD en `log_actividad`.
- Función `clean()` para sanitizar output con `htmlspecialchars()`.
- Passwords hasheados con `password_hash()` / `password_verify()`.

### 2. Login (`login.php`)

- Formulario con campos: usuario y contraseña.
- Si ya está logueado, redirige al dashboard.
- Muestra error si las credenciales son incorrectas.
- No usa layout con sidebar (es una página independiente).

### 3. Setup Inicial (`setup.php`)

- Script para ejecutar una sola vez (por CLI o navegador).
- Crea o actualiza el usuario admin con password por defecto.
- Imprime instrucciones para eliminar el archivo después de usar.

### 4. Dashboard (`index.php`)

Página principal con resumen general del clan:

- **4 stat cards**: jugadores activos, guerras totales, victorias, win rate (%).
- **Última guerra**: oponente, resultado (badge con color), estrellas, participantes.
- **Últimos juegos del clan**: puntos acumulados vs meta, participantes, badge si completado.
- **Última semana de capital**: oro aportado total, participantes.
- **Top 5 donadores** del último periodo: tabla con nombre, donadas, recibidas.
- **Actividad reciente**: últimas 8 entradas del log con usuario, acción, sección, detalle, fecha.

### 5. Jugadores (`jugadores.php`)

CRUD completo:

- **Listado**: tabla con tag, nombre, TH, nivel, rol (badge con color), fecha ingreso, estado activo/inactivo. Barra de búsqueda por nombre o tag. Filtro por activos/inactivos.
- **Crear**: formulario con todos los campos de la tabla. Tag con autocompletado de `#`. Select para TH (1-17), rol del clan. Checkbox para activo. Campo de notas.
- **Editar**: mismo formulario precargado con datos actuales.
- **Eliminar**: con confirmación JavaScript y protección CSRF.
- Todas las acciones registran en `log_actividad`.

### 6. Guerras (`guerras.php` + `guerra_detalle.php`)

**guerras.php** — CRUD del evento de guerra:
- **Listado**: tabla con fecha, oponente, resultado (badge), estrellas clan vs oponente, cantidad de participantes. Ordenado por fecha descendente.
- **Crear/Editar**: formulario con fecha, oponente, resultado (select), estrellas clan, estrellas oponente, notas.
- **Eliminar**: con confirmación (cascade borra participaciones).

**guerra_detalle.php?id=X** — Participaciones:
- Muestra el header de la guerra (oponente, fecha, resultado).
- Grid de tarjetas: una por cada jugador participante. Cada tarjeta tiene campos para ataque 1 (estrellas 0-3, porcentaje), ataque 2 (estrellas, porcentaje), defensa (estrellas, porcentaje).
- Botón "Agregar jugadores" que muestra un select múltiple con los jugadores activos que aún no están en esta guerra.
- Formulario de guardado masivo (un solo submit guarda todos los datos de participación).
- Resumen al tope: total estrellas logradas, promedio de destrucción.

### 7. Liga de Guerra — CWL (`cwl.php` + `cwl_detalle.php`)

**cwl.php** — CRUD de temporadas:
- **Listado**: tabla con mes, liga, posición final, participantes.
- **Crear/Editar**: formulario con mes (YYYY-MM), liga, posición final (1-8), notas.

**cwl_detalle.php?id=X** — Participaciones por día:
- Muestra header de la temporada.
- Tabla/grid con 7 columnas (día 1-7). Filas = jugadores. En cada celda: checkbox "participó", estrellas (0-3), porcentaje.
- Botón para agregar jugadores al roster de la temporada.
- Guardado masivo de todos los datos.
- Resumen: total estrellas por día, jugadores más participativos.

### 8. Juegos del Clan (`juegos.php` + `juegos_detalle.php`)

**juegos.php** — CRUD de eventos:
- **Listado**: tabla con fechas, meta de puntos, puntos acumulados, porcentaje de avance, participantes, badge si completado.
- **Crear/Editar**: fecha inicio, fecha fin, meta de puntos, notas.

**juegos_detalle.php?id=X** — Participaciones:
- Header con info del evento y barra de progreso visual (puntos / meta).
- Grid de tarjetas por jugador: campo de puntos aportados, checkbox "alcanzó máximo" (4000 puntos).
- Botón para agregar jugadores.
- Guardado masivo.
- Resumen: total puntos, jugadores que llegaron al máximo, porcentaje de participación.

### 9. Donaciones (`donaciones.php` + `donaciones_detalle.php`)

**donaciones.php** — CRUD de periodos:
- **Listado**: tabla con fecha inicio/fin, tipo (semanal/mensual), total donadas del periodo, participantes.
- **Crear/Editar**: fecha inicio, fecha fin, tipo, notas.

**donaciones_detalle.php?id=X** — Registro por jugador:
- Header con info del periodo.
- Tabla editable: una fila por jugador con campos de tropas donadas y tropas recibidas.
- Botón para agregar jugadores.
- Guardado masivo.
- Resumen: top donadores, ratio donadas/recibidas, jugadores que no donaron.

### 10. Capital de Clan (`capital.php` + `capital_detalle.php`)

**capital.php** — CRUD de semanas:
- **Listado**: tabla con fechas, oro recaudado, ataques totales, distritos destruidos, participantes.
- **Crear/Editar**: fecha inicio, fecha fin, oro total recaudado, ataques totales, distritos destruidos, notas.

**capital_detalle.php?id=X** — Participaciones:
- Header con info de la semana.
- Grid de tarjetas por jugador: oro aportado, ataques realizados, medallas obtenidas.
- Botón para agregar jugadores.
- Guardado masivo.
- Resumen: top aportadores de oro, promedio de ataques.

### 11. Gestión de Usuarios (`usuarios.php`) — Solo Admin

CRUD de usuarios del sistema:

- **Listado**: tabla con username, nombre, rol (badge), estado activo/inactivo, fecha de creación.
- **Crear**: username, nombre, contraseña, rol (admin/editor). Password se hashea al guardar.
- **Editar**: mismos campos. Si el campo password se deja vacío, no se cambia.
- **Eliminar**: con confirmación. No permitir eliminar al propio usuario logueado.
- Acceso restringido: `requireAdmin()` al inicio del archivo.

### 12. Log de Actividad (`log.php`) — Solo Admin

Vista de solo lectura:

- Tabla con: usuario, acción (badge con color según crear/editar/eliminar), sección, detalle, fecha.
- Ordenado por fecha descendente.
- Paginación (20 registros por página).
- Filtro opcional por usuario y por sección.

---

## Diseño Visual

### Temática y Estilo

- **Tema oscuro** inspirado en la estética de Clash of Clans.
- Fondo principal: negro/azul muy oscuro (`#0f1117`).
- Cards y sidebar: gris oscuro (`#1a1d27`).
- Color primario/acento: dorado (`#f0a030`) para reflejar el oro del juego.
- Colores secundarios: verde (`#3ddc84`) para victorias/éxito, rojo (`#ff5252`) para derrotas/error, azul (`#5c9eff`) para info, púrpura (`#b388ff`), cyan (`#64ffda`) para tags.

### Tipografías (Google Fonts)

- **Títulos y elementos UI**: `Rajdhani` (bold, semi-condensada, aspecto gaming).
- **Texto general**: `Outfit` (moderna, legible, limpia).

### Layout

- **Sidebar fija** a la izquierda (240px) con: logo + nombre del clan, links de navegación con iconos emoji, sección de usuario + botón logout.
- **Área de contenido** al lado derecho con header (título de página) y contenido.
- **Responsive**: en mobile (<768px) la sidebar se oculta con un toggle hamburguesa.

### Componentes CSS Necesarios

- `.stat-card` — tarjeta de estadística con label y valor grande.
- `.card` — contenedor genérico con borde y padding.
- `.badge` — etiqueta pequeña con color (para roles, resultados, estados).
- `.alert` — mensajes flash con variantes success/error/info y auto-dismiss.
- `.table-wrapper` + `table` — tablas con estilo oscuro, hover en filas.
- `.form-grid` — formularios en grid responsive de 2 columnas.
- `.participation-grid` — grid de tarjetas para registrar participaciones masivas.
- `.btn` — botones con variantes primary (dorado), secondary, danger, sm.
- `.pagination` — paginación simple.
- `.empty-state` — estado vacío con emoji y mensaje.
- `.modal-overlay` + `.modal` — modal genérico para confirmaciones o formularios.
- `.login-wrapper` + `.login-box` — pantalla de login centrada.

---

## Reglas de Implementación

1. **Toda conexión a BD usa PDO** con prepared statements. Nunca concatenar variables en queries SQL.
2. **Todos los formularios** llevan token CSRF. Validar con `verifyCsrf()` antes de procesar POST.
3. **Todo output** de datos del usuario pasa por `clean()` (htmlspecialchars).
4. **Toda acción CRUD** (crear, editar, eliminar) registra en `log_actividad` usando `logActivity()`.
5. **Las páginas protegidas** inician con `requireLogin()`. Las de admin con `requireAdmin()`.
6. **Eliminaciones en cascada**: al borrar una guerra, se borran sus participaciones automáticamente (ON DELETE CASCADE en FK).
7. **Guardado masivo de participaciones**: un solo formulario con arrays en los name (`name="estrellas[jugador_id]"`), se procesa en un loop con INSERT ... ON DUPLICATE KEY UPDATE.
8. **Sin JavaScript frameworks**: solo vanilla JS para toggles, confirmaciones y auto-dismiss de alertas.
9. **Charset UTF-8** en todo: base de datos (utf8mb4), conexión PDO, HTML meta tag.
10. **Zona horaria**: `America/Mexico_City` configurada en `database.php`.

---

## Orden de Implementación Sugerido

1. `sql/schema.sql` — Crear todas las tablas.
2. `config/database.php` — Configurar conexión.
3. `includes/auth.php` — Toda la lógica de autenticación y helpers.
4. `assets/style.css` — Estilos completos.
5. `includes/header.php` + `includes/footer.php` — Templates.
6. `setup.php` — Crear usuario admin.
7. `login.php` + `logout.php` — Autenticación.
8. `jugadores.php` — CRUD de jugadores (es la base para todo lo demás).
9. `guerras.php` + `guerra_detalle.php` — Guerras y participaciones.
10. `cwl.php` + `cwl_detalle.php` — Liga de guerra.
11. `juegos.php` + `juegos_detalle.php` — Juegos del clan.
12. `donaciones.php` + `donaciones_detalle.php` — Donaciones.
13. `capital.php` + `capital_detalle.php` — Capital de clan.
14. `index.php` — Dashboard (necesita datos de todas las tablas).
15. `usuarios.php` — Gestión de usuarios.
16. `log.php` — Log de actividad.

---

## Configuración del Servidor

### Requisitos

- PHP 8.0 o superior
- MySQL 8.0 o superior
- Extensión PDO MySQL habilitada

### Instalación

```bash
# 1. Clonar/copiar archivos al directorio del servidor web
cp -r clash-tracker/ /var/www/html/clash-tracker/

# 2. Importar esquema de base de datos
mysql -u root -p < sql/schema.sql

# 3. Editar configuración de conexión
nano config/database.php

# 4. Ejecutar setup para crear usuario admin
php setup.php

# 5. Eliminar setup.php
rm setup.php

# 6. Abrir en el navegador
# http://localhost/clash-tracker/
```
