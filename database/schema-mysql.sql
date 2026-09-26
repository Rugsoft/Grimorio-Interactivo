-- =====================================================================
-- schema-mysql.sql — Esquema DDL del Grimorio Interactivo, dialecto MySQL
--
-- GEMELO DIALECTAL de database/schema.sql (SPEC-13). MISMA forma de
-- datos: tablas, columnas, CHECK, claves foráneas e invariantes son
-- IDÉNTICOS en ambos motores; solo cambia el dialecto del DDL. El
-- mapeo dialectal canónico y sus justificaciones viven en la SPEC-13
-- (sección 3) y no se repiten aquí columna a columna.
--
-- Importar en phpMyAdmin (MySQL 8 / MariaDB 10.4+) EN ESTE ORDEN:
--   1. Este fichero íntegro.
--   2. database/seeds.sql (portable: se usa idéntico en ambos motores).
--
-- Compatibilidad: MySQL 8+ y MariaDB 10.4+ (InfinityFree sirve 10.4 a
-- 10.6: por eso NO se usan índices funcionales, llegados en 10.8; la
-- emulación de índices parciales va por columnas generadas, válidas
-- desde 10.2).
--
-- Diferencias dialectales resumidas (detalle: SPEC-13 §3):
--   * Claves textuales: VARCHAR(64)/VARCHAR(191) (MySQL #1170 prohíbe
--     TEXT como clave sin tamaño).
--   * REFERENCES en línea NO funcionan en MySQL: todas las claves
--     foráneas van explícitas con FOREIGN KEY (...).
--   * Índices parciales de SQLite (WHERE ...) emulados con columnas
--     generadas STORED + índice único (semántica exacta, RF-01.1 y
--     RF-02.1).
--   * Triggers de la Bitácora: SIGNAL SQLSTATE '45000' (equivalente
--     del RAISE(ABORT) SQLite).
--   * Rowid autoincrementales: INT NOT NULL AUTO_INCREMENT.
--   * SET FOREIGN_KEY_CHECKS: el orden de creación exige referenciar
--     tablas aún no nacidas (misma doctrina que el
--     PRAGMA foreign_keys = OFF de los guiones sql/*.sql).
--   * BOOLEAN: TINYINT CHECK (IN (0,1)) en vez de INTEGER CHECK.
--   * Motor InnoDB + utf8mb4_unicode_ci obligatorios (FK por defecto;
--     acentos y «comillas angulares» de las leyendas).
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Tabla: clans — Hermandades del santuario: identidad heráldica, gobierno
-- y gloria del Dominio Semanal (Artículo III; SPEC-01 y SPEC-07).
--
-- Forma idéntica a schema.sql (SPEC-01, SPEC-07): el contador
-- `domain_points` de SPEC-01 sigue RETIRADO; dos contadores canónicos
-- (`weekly_points`, `historical_points`) y el contrato público
-- `domainPoints` servido desde `weekly_points`.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clans (
    id                VARCHAR(64) PRIMARY KEY,            -- Identificador textual (ej. 'cln_primordial')
    slug              VARCHAR(191) NOT NULL UNIQUE,       -- Enlace público del linaje
    name              VARCHAR(191) NOT NULL,              -- Nombre Canónico Único (RF-01.2, RF-05.4)
    motto             TEXT NOT NULL,                      -- Lema heráldico en castellano
    created_at        VARCHAR(32) NOT NULL,               -- Fundación (ISO 8601 UTC)
    coat_of_arms      TEXT NOT NULL,                      -- Blasón rúnico identificador
    lineage_type      VARCHAR(32) NOT NULL DEFAULT 'primordialFlame'
                      CHECK (lineage_type IN (
                          'primordialFlame', 'celestialTides', 'eternalTempest', 'worldRoots',
                          'dawnWinds', 'solarCrown', 'abyssalShadows', 'aetherWeavers'
                      )),                                  -- Linaje rector (RF-02.1)
    admission_mode    VARCHAR(32) NOT NULL DEFAULT 'open'
                      CHECK (admission_mode IN ('open', 'byApplication')),  -- Régimen (RF-01.5)
    status            VARCHAR(32) NOT NULL DEFAULT 'active'
                      CHECK (status IN ('active', 'archived')),  -- Herencia Ancestral (RF-05.3)
    patriarch_id      VARCHAR(64) NULL,                   -- Corona (RF-01.3)
    weekly_points     INTEGER NOT NULL DEFAULT 0,         -- PDA de la semana (RF-03)
    historical_points INTEGER NOT NULL DEFAULT 0,         -- Gloria perpetua (RF-04.3)
    last_activity_at  VARCHAR(32) NOT NULL DEFAULT '',    -- Actividad del Patriarca (RF-01.9)
    updated_at        VARCHAR(32) NOT NULL DEFAULT '',    -- Última modificación
    CONSTRAINT fk_clans_patriarch FOREIGN KEY (patriarch_id)
        REFERENCES users (id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: lineage_doctrines — El canon ceremonial de los Ocho Linajes
-- [SPEC-09, Tarea 1.2]. Canon INMUTABLE (exclusión 5): estas filas solo
-- nacen aquí y en semillas; ninguna operación del sistema las altera.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lineage_doctrines (
    id                 VARCHAR(64) PRIMARY KEY,            -- Clave canónica del linaje (ej. 'primordialFlame')
    name               VARCHAR(191) NOT NULL,              -- Nombre ceremonial en castellano (RF-04.2)
    glyph              VARCHAR(64) NOT NULL,               -- Glifo rúnico ancestral (heráldica de SPEC-07)
    banner_color       VARCHAR(16) NOT NULL,               -- Estandarte ceremonial #rrggbb
    ruling_element     VARCHAR(32) NOT NULL,               -- Afinidad elemental rectora (ej. 'fire')
    doctrine_condensed TEXT NOT NULL,                      -- Doctrina condensada: 1-2 frases (RF-02.1)
    doctrine_full      TEXT NOT NULL,                      -- Doctrina íntegra: 2-4 frases (RF-02.2)
    position           INTEGER NOT NULL DEFAULT 0          -- Orden ceremonial de la rejilla
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: clan_members — Membresías, roles y Convalecencia Arcana
-- [RF-01.1, RF-01.3, RF-01.4, RF-01.6, RF-01.8, RF-01.9].
--
-- AUTORIDAD ÚNICA de la afiliación. `left_at` NULL = afiliación ACTIVA;
-- las filas cerradas son historial (jamás se borran; Artículo III).
--
-- EMULACIÓN DEL ÍNDICE PARCIAL (SPEC-13 §3): SQLite declara
-- CREATE UNIQUE INDEX ... ON clan_members (user_id) WHERE left_at IS NULL;
-- MariaDB no admite índices parciales, así que `membership_bucket`
-- (generada STORED) vale '<<active>>' mientras la membresía viva y su
-- propio `id` al cerrarse. Dos membresías activas simultáneas del mismo
-- usuario repiten '<<active>>' → la unicidad (user_id, membership_bucket)
-- las rechaza (RF-01.1 garantizado estructuralmente); las cerradas jamás
-- colisionan (su bucket es su id único).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clan_members (
    id                       VARCHAR(64) PRIMARY KEY,       -- UUID v4 (ej. 'clm_01928a3b')
    clan_id                  VARCHAR(64) NOT NULL,          -- Clan de vinculación
    user_id                  VARCHAR(64) NOT NULL,          -- Usuario adepto
    role                     VARCHAR(32) NOT NULL DEFAULT 'adept'
                             CHECK (role IN ('patriarch', 'adept')), -- Rol canónico (RF-01.3)
    joined_at                VARCHAR(32) NOT NULL,          -- Ingreso formal (ISO 8601 UTC)
    left_at                  VARCHAR(32) NULL,              -- Partida o expulsión (NULL = activo)
    convalescence_expires_at VARCHAR(32) NULL,              -- Fin de los 14 días (RF-01.6)
    membership_bucket        VARCHAR(64) AS (
                                 CASE WHEN left_at IS NULL THEN '<<active>>' ELSE id END
                             ) STORED,                      -- Emulación del índice parcial (SPEC-13)
    CONSTRAINT fk_clan_members_clan FOREIGN KEY (clan_id)
        REFERENCES clans (id) ON DELETE CASCADE,
    CONSTRAINT fk_clan_members_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: clan_applications — Solicitudes de ingreso [RF-01.5].
-- El tope de 3 pendientes lo refuerza ClanApplicationRepository; la
-- clausura perpetua por casa y cuenta va por el índice único de abajo
-- (misma doctrina que schema.sql: los estados terminales persisten y
-- bloquean nuevas peticiones; el servicio discierne
-- APPLICATION_HOUSE_CLOSED de APPLICATION_ALREADY_PENDING).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clan_applications (
    id              VARCHAR(64) PRIMARY KEY,                -- UUID v4
    clan_id         VARCHAR(64) NOT NULL,                   -- Clan al que se postula
    user_id         VARCHAR(64) NOT NULL,                   -- Usuario postulante
    status          VARCHAR(32) NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled')),
    motivation      TEXT NULL,                              -- Motivación formal [SPEC-10, RF-03.1]; NULL en la vía de ingreso
    verdict_motive  TEXT NULL,                              -- Motivo solemne del dictamen [SPEC-10, Art. III.3]
    created_at      VARCHAR(32) NOT NULL,                   -- Emisión (ISO 8601 UTC)
    resolved_at     VARCHAR(32) NULL,                       -- Veredicto (NULL = en deliberación)
    verdict_seen_at VARCHAR(32) NULL,                       -- Instante de lectura del veredicto [SPEC-10]
    CONSTRAINT fk_clan_applications_clan FOREIGN KEY (clan_id)
        REFERENCES clans (id) ON DELETE CASCADE,
    CONSTRAINT fk_clan_applications_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Clausura perpetua por casa y cuenta [SPEC-10, RF-03.1]: cualquier fila
-- histórica (pendiente, aprobada, rechazada o cancelada) bloquea una nueva
-- petición de esa cuenta a esa casa.
CREATE UNIQUE INDEX uq_clan_application_house
    ON clan_applications (user_id, clan_id);

-- Archivo espejo de los duplicados legados que la migración
-- sql/10_clan_vestibule.sql retira antes de erigir el índice único
-- [SPEC-10, plan §1.3]: no son clausura, son eco de escrituras previas
-- a la regla.
CREATE TABLE IF NOT EXISTS clan_applications_archive (
    id              VARCHAR(64) PRIMARY KEY,
    clan_id         VARCHAR(64) NOT NULL,
    user_id         VARCHAR(64) NOT NULL,
    status          VARCHAR(32) NOT NULL,
    motivation      TEXT NULL,
    verdict_motive  TEXT NULL,
    created_at      VARCHAR(32) NOT NULL,
    resolved_at     VARCHAR(32) NULL,
    verdict_seen_at VARCHAR(32) NULL,
    archived_at     VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: weekly_cycles — Registro histórico de ciclos y campeones
-- [RF-04.1, RF-04.2, RF-04.4, RF-06.1]. Alimenta el Libro Mayor de
-- Campeones del Salón de los Linajes.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS weekly_cycles (
    id                 VARCHAR(64) PRIMARY KEY,             -- UUID v4
    week_number        INTEGER NOT NULL,                    -- Número ISO de semana (1 a 53)
    cycle_year         INTEGER NOT NULL,                    -- Año del ciclo (ej. 2026)
    regent_clan_id     VARCHAR(64) NOT NULL,                -- Clan proclamado soberano
    winning_points     INTEGER NOT NULL,                    -- PDA de la coronación
    winner_spell_count INTEGER NOT NULL,                    -- Conjuros validados de la semana
    closed_at          VARCHAR(32) NOT NULL,                -- Corte dominical (ISO 8601 UTC)
    CONSTRAINT fk_weekly_cycles_regent FOREIGN KEY (regent_clan_id)
        REFERENCES clans (id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: daily_simulator_tracker — Techo diario de 50 PDA [RF-03.2, RNF-02].
-- Acumulador diario por adepto y clan; se reinicia a las 00:00:00 UTC
-- porque `cycle_date` es la fecha UTC del día en curso.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS daily_simulator_tracker (
    id             VARCHAR(64) PRIMARY KEY,                 -- UUID v4
    user_id        VARCHAR(64) NOT NULL,                    -- Adepto que practicó
    clan_id        VARCHAR(64) NOT NULL,                    -- Clan beneficiario
    cycle_date     VARCHAR(16) NOT NULL,                    -- Fecha UTC (YYYY-MM-DD)
    points_awarded INTEGER NOT NULL DEFAULT 0,              -- Acumulado del día (tope 50)
    CONSTRAINT fk_daily_tracker_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_daily_tracker_clan FOREIGN KEY (clan_id)
        REFERENCES clans (id) ON DELETE CASCADE,
    UNIQUE (user_id, clan_id, cycle_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Índices del Sistema de Clanes y Dominio Semanal (SPEC-07).
CREATE UNIQUE INDEX idx_active_member_emulated
    ON clan_members (user_id, membership_bucket);         -- Emulación del parcial (RF-01.1)
CREATE UNIQUE INDEX idx_clans_name_reserved
    ON clans (name);                                       -- RF-05.4: nombre reservado a perpetuidad
CREATE INDEX idx_clans_leaderboard
    ON clans (status, weekly_points DESC);                 -- Ranking semanal (RF-06.1)
CREATE INDEX idx_clans_historical
    ON clans (status, historical_points DESC);             -- Ranking histórico (RF-06.1)
CREATE INDEX idx_applications_user
    ON clan_applications (user_id, status);                -- Tope de 3 pendientes (RF-01.5)
CREATE INDEX idx_member_history_ethics
    ON clan_members (user_id, clan_id, left_at);           -- Veto de 30 días a Maestros (RF-01.8)
CREATE INDEX idx_daily_tracker_lookup
    ON daily_simulator_tracker (user_id, cycle_date);      -- Acumulado diario (RF-03.2)
CREATE INDEX idx_weekly_cycles_chronicle
    ON weekly_cycles (cycle_year DESC, week_number DESC);  -- Crónica del Libro Mayor (RF-06.1)
CREATE INDEX idx_weekly_cycles_regent
    ON weekly_cycles (regent_clan_id);

-- ---------------------------------------------------------------------
-- Tabla: magic_schools — Catálogo canónico de Escuelas de Magia
-- (Filtros disyuntivos del catálogo, RF-03.5)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS magic_schools (
    slug        VARCHAR(64) PRIMARY KEY,                    -- Clave natural (ej. 'evocation')
    name        VARCHAR(191) NOT NULL                       -- Etiqueta en castellano (ej. 'Evocación')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: spells — Hechizos del santuario (SPEC-01 + TASKS-04 + TASKS-08).
-- Estados de moderación y espejos denormalizados EXACTAMENTE como en
-- schema.sql (ver allí el comentario íntegro de `status`,
-- `validation_signatures_count` y `signatures_count`).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS spells (
    id                    VARCHAR(64) PRIMARY KEY,          -- Identificador textual (ej. 'spl_genesis_01')
    slug                  VARCHAR(191) NOT NULL UNIQUE,     -- Enlace directo '#hechizo-slug' (RF-04.1)
    name                  VARCHAR(191) NOT NULL,            -- Nombre visible del conjuro
    author_id             VARCHAR(64) NOT NULL,             -- Creador del conjuro (TASKS-04)
    magic_school          VARCHAR(64) NOT NULL,             -- Escuela válida (filtro RF-03.5)
    elemental_affinity    VARCHAR(32) NOT NULL DEFAULT 'none', -- Afinidad elemental (SPEC-06)
    casting_time          VARCHAR(32) NOT NULL DEFAULT 'action', -- 'action', 'reaction', 'ritual'
    mana_cost             INTEGER NOT NULL CHECK (mana_cost >= 0 AND mana_cost <= 200),  -- Coste determinista (Artículo II)
    circle                INTEGER NOT NULL DEFAULT 1 CHECK (circle >= 1 AND circle <= 5), -- Círculo arcano (1 a 5, TASKS-04)
    math_fingerprint      CHAR(64) NOT NULL DEFAULT '' CHECK (length(math_fingerprint) = 64), -- SHA-256 hex (antifraude, TASKS-04)
    clan_id               VARCHAR(64) NOT NULL,             -- Linaje de origen
    summary               TEXT NOT NULL,                    -- Resumen breve (máx. 3 líneas en tarjeta)
    description           TEXT NOT NULL,                    -- Ficha técnica completa (modal de detalle)
    components_verbal     TEXT NOT NULL,                    -- Componente verbal (fórmula arcana)
    components_somatic    TEXT NOT NULL,                    -- Componente somático (gesto místico)
    components_material   TEXT NOT NULL,                    -- Componente material (reliquia o substancia)
    damage                INTEGER NOT NULL DEFAULT 0 CHECK (damage >= 0),          -- Daño directo o continuo (TASKS-04)
    healing               INTEGER NOT NULL DEFAULT 0 CHECK (healing >= 0),         -- Curación directa (TASKS-04)
    barrier               INTEGER NOT NULL DEFAULT 0 CHECK (barrier >= 0),         -- Absorción o protección (TASKS-04)
    crowd_control_type    VARCHAR(32) NOT NULL DEFAULT 'none'
                          CHECK (crowd_control_type IN ('none', 'slow', 'root', 'stun')),  -- Control de masas (TASKS-04)
    range_type            VARCHAR(32) NOT NULL DEFAULT 'touch'
                          CHECK (range_type IN ('touch', 'short', 'medium', 'long')),      -- Alcance (TASKS-04)
    area_type             VARCHAR(32) NOT NULL DEFAULT 'singleTarget'
                          CHECK (area_type IN ('singleTarget', 'cone', 'line', 'sphere')), -- Geometría de área (TASKS-04)
    duration_type         VARCHAR(32) NOT NULL DEFAULT 'instant'
                          CHECK (duration_type IN ('instant', 'concentration', 'sustained')), -- Duración (TASKS-04)
    has_verbal            TINYINT NOT NULL DEFAULT 0 CHECK (has_verbal IN (0, 1)),  -- Atenuador verbal (-10%)
    has_somatic           TINYINT NOT NULL DEFAULT 0 CHECK (has_somatic IN (0, 1)), -- Atenuador somático (-10%)
    has_material          TINYINT NOT NULL DEFAULT 0 CHECK (has_material IN (0, 1)),-- Atenuador material (-10%)
    status                VARCHAR(32) NOT NULL DEFAULT 'draft'
                          CHECK (status IN ('draft', 'experimental', 'validated', 'rejected', 'archived')),  -- Espejo del ciclo de vida (TASKS-08, RF-01.1)
    validation_signatures_count INTEGER NOT NULL DEFAULT 0 CHECK (validation_signatures_count >= 0),
    signatures_count      INTEGER NOT NULL DEFAULT 0 CHECK (signatures_count >= 0 AND signatures_count <= 3), -- Firmas de Maestros 0/3 (RF-02.1)
    is_genesis_sample     TINYINT NOT NULL DEFAULT 0 CHECK (is_genesis_sample IN (0, 1)),  -- Pergamino Primordial (RF-01.3)
    created_at            VARCHAR(32) NOT NULL,             -- Nacimiento (ISO 8601 UTC)
    updated_at            VARCHAR(32) NOT NULL,             -- Última modificación (ISO 8601 UTC)
    validated_at          VARCHAR(32) NULL,                 -- Fecha de validación (NULL si no validado)
    CONSTRAINT fk_spells_author FOREIGN KEY (author_id) REFERENCES users (id),
    CONSTRAINT fk_spells_school FOREIGN KEY (magic_school) REFERENCES magic_schools (slug),
    CONSTRAINT fk_spells_clan FOREIGN KEY (clan_id) REFERENCES clans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_spells_slug ON spells (slug);                       -- Enlaces directos (RF-04.1, RF-06.2)
CREATE INDEX idx_spells_magic_school ON spells (magic_school);       -- Filtrado disyuntivo (RF-03.5)
CREATE INDEX idx_spell_author_status ON spells (author_id, status);  -- Cuota de borradores y listas del autor (RF-05.1)
CREATE INDEX idx_spell_clan_validated ON spells (clan_id, status);   -- Catálogo por linaje y Moderación en 2 pasos (RF-08.2)
CREATE INDEX idx_spells_status_validated_at ON spells (status, validated_at); -- Descubrimiento del portal
CREATE INDEX idx_spells_clan_id ON spells (clan_id);

-- ---------------------------------------------------------------------
-- SPEC-03 — Autenticación, Sesiones y Control de Acceso (RBAC).
-- Los BOOLEAN viajan como TINYINT CHECK (IN (0,1)); los TIMESTAMP como
-- VARCHAR ISO 8601 UTC, igual que el resto del esquema (contratos JSON).
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- Tabla: users — Miembros consagrados del santuario [RF-01, RF-05].
-- Roles canónicos: 'reader', 'editor', 'master', 'supremeAdmin' (RF-05.1).
-- `lineage` es EL VÍNCULO PERPETUO DEL JURAMENTO (SPEC-09, RF-03.4) y
-- `avatar` LA EFIGIE DEL ADEPTO (SPEC-12, RF-03.1…RF-03.5): semántica
-- íntegra comentada en schema.sql; aquí la forma es idéntica.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            VARCHAR(64) PRIMARY KEY,                  -- Identificador textual (ej. 'usr_8f1a2b3c')
    alias         VARCHAR(191) NOT NULL UNIQUE,             -- Nombre de iniciado público (3 a 30 caracteres)
    email         VARCHAR(191) NOT NULL UNIQUE,             -- Correo electrónico validado
    password_hash TEXT NOT NULL,                            -- Frase de paso hasheada (BCRYPT coste 12)
    role          VARCHAR(32) NOT NULL DEFAULT 'editor'
                  CHECK (role IN ('reader', 'editor', 'master', 'supremeAdmin')),  -- Jerarquía sagrada (RF-05.1)
    clan_id       VARCHAR(64) NULL,                         -- ESPEJO denormalizado de la membresía ACTIVA (SPEC-07); NULL = sin linaje
    lineage       VARCHAR(32) NULL
                  CHECK (lineage IN (
                      'primordialFlame', 'celestialTides', 'eternalTempest', 'worldRoots',
                      'dawnWinds', 'solarCrown', 'abyssalShadows', 'aetherWeavers'
                  )),                                      -- Vínculo perpetuo del juramento (SPEC-09, canon cerrado)
    avatar        TEXT NULL,                                -- Efigie del adepto (SPEC-12): NULL | 'catalog:<id>' | 'own:<fileId>'
    recovery_token_hash         TEXT NOT NULL,              -- SHA-256 del pergamino activo ('' = sin pergamino, RF-04.1)
    recovery_token_expires_at   VARCHAR(32) NULL,           -- Vigencia de 60 minutos (RF-04.1)
    created_at    VARCHAR(32) NOT NULL,                     -- Alta del iniciado (ISO 8601 UTC)
    updated_at    VARCHAR(32) NOT NULL,                     -- Última modificación (ISO 8601 UTC)
    CONSTRAINT fk_users_clan FOREIGN KEY (clan_id) REFERENCES clans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_users_email ON users (email);
CREATE INDEX idx_users_clan_id ON users (clan_id);

-- ---------------------------------------------------------------------
-- Tabla: user_sessions — Vínculos activos multidispositivo [RF-02].
-- `expires_at` renovable (14 días), `absolute_expires_at` inmutable
-- (30 días). El token jamás en claro: solo su SHA-256.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_sessions (
    id                  VARCHAR(64) PRIMARY KEY,            -- Identificador textual de la sesión
    session_token_hash  VARCHAR(191) NOT NULL UNIQUE,       -- SHA-256 del token (jamás en claro)
    user_id             VARCHAR(64) NOT NULL,               -- Titular del vínculo
    ip_address          VARCHAR(45) NOT NULL,               -- Procedencia (IPv4 e IPv6)
    user_agent          TEXT NOT NULL,                      -- Cliente declarado (informativo)
    created_at          VARCHAR(32) NOT NULL,               -- Nacimiento del vínculo (ISO 8601 UTC)
    last_activity_at    VARCHAR(32) NOT NULL,               -- Renovado en cada acción (RF-02.2)
    expires_at          VARCHAR(32) NOT NULL,               -- Ventana renovable de 14 días
    absolute_expires_at VARCHAR(32) NOT NULL,               -- Límite absoluto inmutable de 30 días
    retained_route      VARCHAR(64) NULL,                   -- Ruta retenida por el juramento (SPEC-09, RF-03.1); NULL = nada retenido
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_sessions_user_id ON user_sessions (user_id);
CREATE INDEX idx_sessions_expires ON user_sessions (expires_at);

-- ---------------------------------------------------------------------
-- Tabla: login_attempts — Defensa anti-fuerza bruta y anti-DoS [RF-03].
-- Ventana: 5 fallos por IP en 15 minutos congelan la procedencia (RF-03.2).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id                 INT NOT NULL AUTO_INCREMENT PRIMARY KEY,  -- Equivalente del rowid SQLite
    ip_address         VARCHAR(45) NOT NULL,                -- Procedencia congelable
    attempted_identity VARCHAR(191) NOT NULL,               -- Alias o correo intentado (no verificado)
    attempted_at       VARCHAR(32) NOT NULL,                -- Marca temporal (ISO 8601 UTC)
    is_success         TINYINT NOT NULL DEFAULT 0 CHECK (is_success IN (0, 1))  -- 1 si el vínculo se renovó
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_ip_attempt ON login_attempts (ip_address, attempted_at);

-- ---------------------------------------------------------------------
-- Tabla: clan_history — LEGADO, superada por `clan_members` (SPEC-07).
-- Ninguna clase de src/ escribe ni lee ya aquí; se conserva por los
-- arneses de SPEC-01/03 y por no romper bases ya construidas.
-- Incompatibilidad histórica de 30 días (RF-06.1): sin CASCADE al
-- borrar usuario (RF-09.2: el legado sobrevive a la cuenta).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clan_history (
    id        INT NOT NULL AUTO_INCREMENT PRIMARY KEY,      -- Equivalente del rowid SQLite
    user_id   VARCHAR(64) NOT NULL,                         -- Historia del iniciado (sobrevive al olvido)
    clan_id   VARCHAR(64) NOT NULL,                         -- Linaje habitado
    joined_at VARCHAR(32) NOT NULL,                         -- Ingreso al linaje (ISO 8601 UTC)
    left_at   VARCHAR(32) NULL,                             -- Salida (NULL = clan activo)
    CONSTRAINT fk_clan_history_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE RESTRICT,         -- Sin CASCADE: legado > cuenta (RF-09.2)
    CONSTRAINT fk_clan_history_clan FOREIGN KEY (clan_id)
        REFERENCES clans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_user_clan_time ON clan_history (user_id, left_at);  -- Conflicto de 30 días
CREATE INDEX idx_clan_history_clan ON clan_history (clan_id);        -- Puntos históricos por linaje (RF-07.2)

-- ---------------------------------------------------------------------
-- Tabla: audit_log — Bitácora inmutable de auditoría arcana [RF-08].
-- Registro IMBORRABLE: solo admite INSERT (triggers más abajo).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id                 INT NOT NULL AUTO_INCREMENT PRIMARY KEY,  -- Equivalente del rowid SQLite
    actor_user_id      VARCHAR(64) NOT NULL,               -- Identidad del actuante
    actor_alias        VARCHAR(191) NOT NULL,              -- Alias público en el instante de la acción
    actor_role         VARCHAR(32) NOT NULL,               -- Rol técnico activo
    action_type        VARCHAR(64) NOT NULL,               -- 'SIGN_VALIDATE', 'SIGN_REJECT', 'ADMIN_VETO', 'PROMOTE_MASTER', 'DEMOTE_MASTER', 'CLAN_MODIFY'
    target_entity_type VARCHAR(32) NOT NULL,               -- 'spell', 'clan', 'user'
    target_entity_id   VARCHAR(64) NOT NULL,               -- Entidad afectada
    justification      TEXT NOT NULL,                      -- Motivo solemne obligatorio (castellano)
    created_at         VARCHAR(32) NOT NULL                -- Marca temporal UTC (ISO 8601)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_audit_created ON audit_log (created_at);
CREATE INDEX idx_audit_actor ON audit_log (actor_user_id);
CREATE INDEX idx_audit_target ON audit_log (target_entity_type, target_entity_id);

-- ---------------------------------------------------------------------
-- Inmutabilidad blindada de la bitácora (RF-08.1, RNF-02, Art. III):
-- triggers MySQL equivalentes al RAISE(ABORT) de SQLite. La Bitácora
-- solo admite INSERT; ni moderadores ni el Admin Supremo alteran o
-- borran un veredicto registrado.
-- ---------------------------------------------------------------------
CREATE TRIGGER trg_audit_log_no_update
BEFORE UPDATE ON audit_log
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La Bitácora de Auditoría Arcana es inmutable: los veredictos jamás se alteran.';
END;

CREATE TRIGGER trg_audit_log_no_delete
BEFORE DELETE ON audit_log
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La Bitácora de Auditoría Arcana es inmutable: los veredictos jamás se borran.';
END;

-- ---------------------------------------------------------------------
-- Libro de Gloria del Dominio Semanal (SPEC-07, Tarea 2.5) y tomo
-- personal (SPEC-11). Con FOREIGN_KEY_CHECKS = 0 el orden deja de ser
-- crítico, pero se conserva el del gemelo para que ambos guiones sean
-- paralelos línea a línea.
-- ---------------------------------------------------------------------

-- Libreta de Favoritos del santuario [RF-03.3, RNF-02]: unicidad
-- (user_id, spell_id) = un solo voto computable por cuenta y conjuro.
CREATE TABLE IF NOT EXISTS favorites (
    id         VARCHAR(64) PRIMARY KEY,                    -- UUID v4
    user_id    VARCHAR(64) NOT NULL,                       -- Mago que elogia
    spell_id   VARCHAR(64) NOT NULL,                       -- Conjuro sellado elogiado
    created_at VARCHAR(32) NOT NULL,                       -- Instante del elogio (ISO 8601 UTC)
    CONSTRAINT fk_favorites_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_favorites_spell FOREIGN KEY (spell_id)
        REFERENCES spells (id) ON DELETE CASCADE,
    UNIQUE (user_id, spell_id)                             -- Un solo voto computable (RNF-02)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Libro de acreditaciones de PDA [RF-03.1, RF-03.3]: la unicidad
-- (action_type, source_id) garantiza que una gloria jamás se cobra dos
-- veces (RNF-01). La práctica del simulador vive en
-- daily_simulator_tracker y NO se duplica aquí.
CREATE TABLE IF NOT EXISTS dominion_awards (
    id             VARCHAR(64) PRIMARY KEY,                -- UUID v4
    clan_id        VARCHAR(64) NOT NULL,                   -- Linaje acreditado (patrimonio del conjuro)
    user_id        VARCHAR(64) NOT NULL,                   -- Adepto cuyo mérito acreditó la gloria
    action_type    VARCHAR(32) NOT NULL
                   CHECK (action_type IN ('spellValidated', 'communityFavorite')),
    base_points    INTEGER NOT NULL DEFAULT 0 CHECK (base_points >= 0),    -- Valor base de la acción
    awarded_points INTEGER NOT NULL DEFAULT 0 CHECK (awarded_points > 0),  -- Gloria realmente acreditada
    has_synergy    TINYINT NOT NULL DEFAULT 0 CHECK (has_synergy IN (0, 1)), -- Bonificación de linaje (RF-03.4)
    source_id      VARCHAR(64) NOT NULL,                   -- Conjuro validado o favorito que la motiva
    awarded_at     VARCHAR(32) NOT NULL,                   -- Marca temporal UTC (ISO 8601)
    CONSTRAINT fk_dominion_awards_clan FOREIGN KEY (clan_id)
        REFERENCES clans (id) ON DELETE CASCADE,
    CONSTRAINT fk_dominion_awards_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    UNIQUE (action_type, source_id)                        -- Cada mérito paga exactamente una vez
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_favorites_spell ON favorites (spell_id);
CREATE INDEX idx_dominion_awards_clan ON dominion_awards (clan_id, awarded_at);
CREATE INDEX idx_dominion_awards_member ON dominion_awards (user_id, clan_id);

-- ---------------------------------------------------------------------
-- COLECCIÓN DEL ADEPTO — EL TOMO PERSONAL (SPEC-11, Tarea 1.1).
-- TABLA NUEVA separada de `favorites` a propósito (rito distinto, mesa
-- distinta). El guion de ascensión es sql/11_grimoire_collections.sql
-- y su DDL ha de permanecer idéntico al de esta sección.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS grimoire_collections (
    id         VARCHAR(64) PRIMARY KEY,                    -- UUID v4
    user_id    VARCHAR(64) NOT NULL,                       -- El tomo muere con su adepto (RF-05.3)
    spell_id   VARCHAR(64) NOT NULL,                       -- La retirada es archived, jamás DELETE (RF-05.5)
    added_at   VARCHAR(32) NOT NULL,                       -- Instante del sellado (ISO 8601 UTC)
    CONSTRAINT fk_grimoire_collections_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_grimoire_collections_spell FOREIGN KEY (spell_id)
        REFERENCES spells (id) ON DELETE CASCADE,
    UNIQUE (user_id, spell_id)                             -- Un solo sellado por hechizo y adepto (RF-01.3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_grimoire_collections_user_added
    ON grimoire_collections (user_id, added_at DESC);      -- Latencia del tomo (RNF-01)

-- ---------------------------------------------------------------------
-- SISTEMA DE MODERACIÓN SOLEMNE EN DOS PASOS (SPEC-08, Tarea 1.1).
-- Las cuatro tablas del cónclave viven AQUÍ (lección de SPEC-07: un
-- esquema repartido dejaba sin tablas a las bases levantadas solo con
-- este fichero). Dominio cerrado idéntico al de schema.sql.
-- ---------------------------------------------------------------------

-- 1. Seguimiento del estado de moderación (relación 1:1 con `spells`).
CREATE TABLE IF NOT EXISTS spell_reviews (
    id                VARCHAR(64) PRIMARY KEY,             -- UUID v4 de la revisión
    spell_id          VARCHAR(64) NOT NULL UNIQUE,         -- Relación 1:1 con `spells`
    author_id         VARCHAR(64) NOT NULL,                -- Mago creador de la obra
    origin_clan_id    VARCHAR(64) NULL,                    -- Clan patrimonial (o NULL si ermitaño)
    status            VARCHAR(32) NOT NULL DEFAULT 'draft'
                      CHECK (status IN ('draft', 'experimental', 'validated', 'rejected', 'archived')),
    signatures_count  INTEGER NOT NULL DEFAULT 0
                      CHECK (signatures_count >= 0 AND signatures_count <= 3),
    math_fingerprint  CHAR(64) NOT NULL
                      CHECK (length(math_fingerprint) = 64),  -- SHA-256 hex del balance sellado (Art. II)
    submitted_at      VARCHAR(32) NULL,                    -- Entrada a la Torre de Moderación
    validated_at      VARCHAR(32) NULL,                    -- Consagración solemne
    rejected_at       VARCHAR(32) NULL,                    -- Objeción o caducidad
    reopened_at       VARCHAR(32) NULL,                    -- Re-apertura como borrador (RF-01.4)
    archived_at       VARCHAR(32) NULL,                    -- Degradación o destierro póstumo (RF-04.4)
    CONSTRAINT fk_spell_reviews_spell FOREIGN KEY (spell_id)
        REFERENCES spells (id) ON DELETE CASCADE,
    CONSTRAINT fk_spell_reviews_author FOREIGN KEY (author_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_spell_reviews_origin_clan FOREIGN KEY (origin_clan_id)
        REFERENCES clans (id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Firmas de Maestros y sus glosas litúrgicas (RF-02.1, RF-02.2).
--
-- EMULACIÓN DEL ÍNDICE PARCIAL (SPEC-13 §3): el parcial SQLite
-- (WHERE is_revoked = 0) se traduce con `signature_bucket`: '<<active>>'
-- mientras la firma viva, su propio `id` al retractarse. Dos firmas
-- vivas del mismo Maestro al mismo conjuro chocan (RF-02.1 garantizado
-- estructuralmente); las retractadas jamás colisionan.
CREATE TABLE IF NOT EXISTS master_signatures (
    id                 VARCHAR(64) PRIMARY KEY,            -- UUID v4 de la firma
    spell_id           VARCHAR(64) NOT NULL,               -- Conjuro avalado
    master_id          VARCHAR(64) NOT NULL,               -- Maestro firmante
    master_clan_id     VARCHAR(64) NULL,                   -- Clan del firmante al firmar
    ceremonial_gloss   TEXT NULL
                       CHECK (ceremonial_gloss IS NULL OR length(ceremonial_gloss) <= 250),  -- Glosa de RF-02.2
    signed_at          VARCHAR(32) NOT NULL,               -- Marca temporal de la firma
    is_revoked         TINYINT NOT NULL DEFAULT 0
                       CHECK (is_revoked IN (0, 1)),       -- 1 si fue retractada o anulada de oficio
    revoked_at         VARCHAR(32) NULL,                   -- Fecha de revocación
    revocation_reason  VARCHAR(64) NULL,                   -- 'retracted' | 'clan_conflict_arisen' | 'rank_lost' | 'author_withdrawn' | 'sovereign_archive' | 'review_expired' | 'review_rejected'
    signature_bucket   VARCHAR(64) AS (
                           CASE WHEN is_revoked = 0 THEN '<<active>>' ELSE id END
                       ) STORED,                           -- Emulación del índice parcial (SPEC-13)
    CONSTRAINT fk_master_signatures_spell FOREIGN KEY (spell_id)
        REFERENCES spells (id) ON DELETE CASCADE,
    CONSTRAINT fk_master_signatures_master FOREIGN KEY (master_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_master_signatures_clan FOREIGN KEY (master_clan_id)
        REFERENCES clans (id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX idx_active_master_signature_emulated
    ON master_signatures (spell_id, master_id, signature_bucket);  -- Firma única viva (RF-02.1)

-- 3. Dictámenes de objeción fundamentada (RF-02.5, RF-02.6).
CREATE TABLE IF NOT EXISTS objection_verdicts (
    id                VARCHAR(64) PRIMARY KEY,             -- UUID v4 del dictamen
    spell_id          VARCHAR(64) NOT NULL,                -- Conjuro objetado
    master_id         VARCHAR(64) NOT NULL,                -- Maestro que emitió el veto
    objection_reason  TEXT NOT NULL
                      CHECK (length(objection_reason) >= 20),  -- Justificación obligatoria (RF-02.5)
    objected_at       VARCHAR(32) NOT NULL,                -- Marca temporal del dictamen
    CONSTRAINT fk_objection_verdicts_spell FOREIGN KEY (spell_id)
        REFERENCES spells (id) ON DELETE CASCADE,
    CONSTRAINT fk_objection_verdicts_master FOREIGN KEY (master_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Decretos del Administrador Supremo (RF-04.1, RF-04.3 a RF-04.5).
CREATE TABLE IF NOT EXISTS sovereign_decrees (
    id                    VARCHAR(64) PRIMARY KEY,         -- UUID v4 del decreto
    spell_id              VARCHAR(64) NOT NULL,            -- Conjuro sobre el que se decretó
    admin_id              VARCHAR(64) NOT NULL,            -- Administrador Supremo actuante
    decree_type           VARCHAR(32) NOT NULL
                          CHECK (decree_type IN ('sovereignValidation', 'rescueToExperimental', 'rescueToValidated', 'revokeAndArchive')),
    imperial_decree_text  TEXT NOT NULL
                          CHECK (length(imperial_decree_text) >= 20),  -- Edicto obligatorio (RF-04.5)
    decreed_at            VARCHAR(32) NOT NULL,            -- Marca temporal del decreto
    CONSTRAINT fk_sovereign_decrees_spell FOREIGN KEY (spell_id)
        REFERENCES spells (id) ON DELETE CASCADE,
    CONSTRAINT fk_sovereign_decrees_admin FOREIGN KEY (admin_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_reviews_queue ON spell_reviews (status, submitted_at ASC);          -- Cola del Atrio (RF-05.1)
CREATE INDEX idx_reviews_author_active ON spell_reviews (author_id, status);         -- Cupo anti-spam del autor (RF-01.2)
CREATE INDEX idx_signatures_spell_active ON master_signatures (spell_id, is_revoked); -- Recuento de firmas vivas

SET FOREIGN_KEY_CHECKS = 1;
