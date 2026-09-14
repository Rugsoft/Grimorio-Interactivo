-- =====================================================================
-- 07_clans_lineages_schema.sql — Migración DDL del Sistema de Clanes,
-- Linajes y Dominio Semanal del Grimorio (SPEC-07).
--
-- Tarea 1.1 (TASKS-07): materializa el esquema relacional de la sección
-- 2.1 del PLAN-07 como MIGRACIÓN INCREMENTAL sobre el esquema raíz.
--
-- Cubre: RF-01.1, RF-01.4, RF-01.5, RF-03.2, RF-04.1, RF-05.4, RNF-05,
--        Artículo V (Dualidad Lingüística: snake_case técnico).
--
-- =====================================================================
-- SÓLO PARA BASES LEGADAS — YA INCORPORADO AL DDL CANÓNICO
-- =====================================================================
-- `database/schema.sql` contiene HOY la forma completa de SPEC-07: las
-- columnas de `clans` y las cuatro tablas del Dominio Semanal se declaran
-- allí de forma directa. Este script subsiste como VÍA DE ASCENSO para
-- bases construidas antes de esa unificación y NO debe aplicarse sobre una
-- base nueva: los `ALTER TABLE ... ADD COLUMN` fallarían con «duplicate
-- column name».
--
-- Motivo de la unificación: `clan_members` es la autoridad de la afiliación
-- y la consumen clases de SPEC-03 (AuthService) y SPEC-07; un esquema
-- repartido entre el DDL raíz y una migración opcional dejaba sin tabla a
-- toda base levantada solo con `database/schema.sql`.
--
-- ---------------------------------------------------------------------
-- ORDEN DE APLICACIÓN SOBRE UNA BASE LEGADA (secuencial, un solo paso)
-- ---------------------------------------------------------------------
--   1) database/schema.sql  → esquema raíz histórico (users, clans de
--                             SPEC-01, clan_history, audit_log, spells…).
--   2) database/seeds.sql   → linaje fundacional neutro y su custodio.
--   3) ESTE script          → amplía la tabla `clans` con las columnas
--                             del dominio de SPEC-07 y crea las cuatro
--                             tablas nuevas del Dominio Semanal.
--
-- ---------------------------------------------------------------------
-- POR QUÉ ES UNA MIGRACIÓN INCREMENTAL Y NO UN ESQUEMA AUTÓNOMO
-- ---------------------------------------------------------------------
-- `clans` ya fue instituida por la Tarea 1.1 de SPEC-01 con una forma
-- mínima (id, slug, name, motto, created_at) y es la referencia viva de
-- `users.clan_id`, `spells.clan_id`, `clan_history` y del endpoint
-- `GET /api/v1/clans` (ClanController). Recrearla rompería esas
-- dependencias; por eso este script AMPLÍA `clans` y añade lo nuevo.
--
-- NOTA (Tarea 2.6): la forma mínima original portaba además
-- `domain_points`, retirada después por duplicar a `weekly_points` y
-- carecer de escritor. El guion de ascenso completo es:
--   … → sql/07_weekly_dominion_ledger.sql → sql/07_retire_domain_points.sql.
--
-- ---------------------------------------------------------------------
-- ADVERTENCIA DE RE-EJECUCIÓN (restricción nativa de SQLite)
-- ---------------------------------------------------------------------
-- SQLite NO admite `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`. Por tanto,
-- las sentencias ALTER de este script se aplican UNA SOLA VEZ sobre una
-- base que aún no haya sido migrada. Las cuatro tablas nuevas sí nacen
-- con `IF NOT EXISTS` y son idempotentes.
--
-- ---------------------------------------------------------------------
-- COMPATIBILIDAD DE DIALECTO
-- ---------------------------------------------------------------------
--   * SQLite 3.35+ y MySQL 8 / MariaDB 10.4+.
--   * `TEXT` equivale a `VARCHAR(n)` y `INTEGER` a `INT` en MySQL: se usa
--     `TEXT` de forma consistente con database/schema.sql (SQLite es de
--     tipado dinámico y no aplica longitudes).
--   * MySQL exige InnoDB para aplicar claves foráneas.
--   * Los TIMESTAMP viajan como TEXT en formato ISO 8601 UTC.
--
-- Constitución:
--   - Artículo I (Dogma Vanilla): SQL nativo, sin ORM ni migradores
--     externos.
--   - Artículo III (Ética de Linajes): el historial de membresía
--     (`clan_members.left_at`) sostiene el veto de 30 días de los Maestros.
--   - Artículo V (Dualidad): identificadores técnicos en inglés
--     snake_case; comentarios y narrativa en castellano.
-- =====================================================================


-- =====================================================================
-- 1. TABLA `clans` — Ampliación con las columnas del dominio de SPEC-07
--
-- La forma mínima de SPEC-01 (id, slug, name, motto, created_at) se
-- PRESERVA intacta: `slug` sigue siendo el enlace público único del linaje
-- y el catálogo público `GET /api/v1/clans` se sirve del contador semanal
-- canónico `weekly_points` (único contador de gloria tras la Tarea 2.6).
--
-- Columnas añadidas [RF-01.2, RF-01.3, RF-01.5, RF-01.9, RF-02.1, RF-05.3]:
--   * coat_of_arms   → blasón rúnico/icono SVG del estandarte.
--   * lineage_type   → uno de los 8 Linajes Canónicos (RF-02.1).
--   * admission_mode → 'open' | 'byApplication' (RF-01.5).
--   * status         → 'active' | 'archived' (Herencia Ancestral, RF-05.3).
--   * patriarch_id   → Patriarca/Matriarca en funciones (RF-01.3).
--   * weekly_points  → PDA de la semana en curso (RF-03 / RF-04.3).
--   * historical_points → acumulado perpetuo de todos los tiempos.
--   * last_activity_at  → última actividad del Patriarca (RF-01.9).
--   * updated_at        → marca de última modificación.
--
-- NOTA DE INTEGRIDAD (RF-05.3 y Caso Límite 1): `patriarch_id` se declara
-- ANULABLE y CON clave foránea. SQLite prohíbe añadir por ALTER una
-- columna REFERENCES con valor por defecto no nulo ("Cannot add a
-- REFERENCES column with non-NULL default value"), de modo que la
-- alternativa habría sido renunciar a la FK. La nulabilidad es además
-- semánticamente correcta: un clan puede quedar acéfalo y disolverse
-- hacia `archived` sin Patriarca vivo.
-- =====================================================================

ALTER TABLE clans ADD COLUMN coat_of_arms TEXT NOT NULL DEFAULT '';
ALTER TABLE clans ADD COLUMN lineage_type TEXT NOT NULL DEFAULT 'primordialFlame'
    CHECK (lineage_type IN (
        'primordialFlame', 'celestialTides', 'eternalTempest', 'worldRoots',
        'dawnWinds', 'solarCrown', 'abyssalShadows', 'aetherWeavers'
    ));
ALTER TABLE clans ADD COLUMN admission_mode TEXT NOT NULL DEFAULT 'open'
    CHECK (admission_mode IN ('open', 'byApplication'));
ALTER TABLE clans ADD COLUMN status TEXT NOT NULL DEFAULT 'active'
    CHECK (status IN ('active', 'archived'));
ALTER TABLE clans ADD COLUMN patriarch_id TEXT REFERENCES users (id) ON UPDATE CASCADE;
ALTER TABLE clans ADD COLUMN weekly_points INTEGER NOT NULL DEFAULT 0;
ALTER TABLE clans ADD COLUMN historical_points INTEGER NOT NULL DEFAULT 0;
ALTER TABLE clans ADD COLUMN last_activity_at TEXT NOT NULL DEFAULT '';
ALTER TABLE clans ADD COLUMN updated_at TEXT NOT NULL DEFAULT '';

-- Relleno de las marcas temporales de las filas preexistentes: el linaje
-- fundacional de los Pergaminos Primordiales hereda su fecha de génesis.
UPDATE clans SET last_activity_at = created_at WHERE last_activity_at = '';
UPDATE clans SET updated_at = created_at WHERE updated_at = '';


-- =====================================================================
-- 2. TABLA `clan_members` — Membresías, Roles y Convalecencia Arcana
--    [RF-01.1, RF-01.3, RF-01.4, RF-01.6, RF-01.8, RF-01.9]
--
-- `left_at` NULL señala la afiliación ACTIVA; `convalescence_expires_at`
-- fija el fin de los 14 días naturales de meditación (RF-01.6). El índice
-- único condicional de la sección 6 es la garantía de pertenencia única
-- simultánea (RF-01.1); el historial con `left_at` sostiene el veto de 30
-- días a los Maestros (RF-01.8, Artículo III).
-- =====================================================================

CREATE TABLE IF NOT EXISTS clan_members (
    id                       TEXT PRIMARY KEY,                       -- UUID v4 (ej. 'clm_01928a3b')
    clan_id                  TEXT NOT NULL,                          -- Clan de vinculación
    user_id                  TEXT NOT NULL,                          -- Usuario adepto
    role                     TEXT NOT NULL DEFAULT 'adept'
                             CHECK (role IN ('patriarch', 'adept')), -- Rol canónico dentro del clan (RF-01.3)
    joined_at                TEXT NOT NULL,                          -- Ingreso formal (ISO 8601 UTC)
    left_at                  TEXT,                                   -- Partida o expulsión (NULL = activo)
    convalescence_expires_at TEXT,                                   -- Fin de los 14 días naturales (RF-01.6)
    FOREIGN KEY (clan_id) REFERENCES clans (id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);


-- =====================================================================
-- 3. TABLA `clan_applications` — Solicitudes de Ingreso
--    [RF-01.5]
--
-- El régimen `byApplication` exige deliberación del Patriarca; un mismo
-- usuario no puede acumular más de 3 solicitudes `pending` (el tope lo
-- refuerza ClanApplicationRepository en la Tarea 1.4).
-- =====================================================================

CREATE TABLE IF NOT EXISTS clan_applications (
    id          TEXT PRIMARY KEY,                                    -- UUID v4
    clan_id     TEXT NOT NULL,                                       -- Clan al que se postula
    user_id     TEXT NOT NULL,                                       -- Usuario postulante
    status      TEXT NOT NULL DEFAULT 'pending'
                CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled')),
    created_at  TEXT NOT NULL,                                       -- Emisión (ISO 8601 UTC)
    resolved_at TEXT,                                                -- Veredicto del Patriarca (NULL = en deliberación)
    FOREIGN KEY (clan_id) REFERENCES clans (id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);


-- =====================================================================
-- 4. TABLA `weekly_cycles` — Registro Histórico de Ciclos y Campeones
--    [RF-04.1, RF-04.2, RF-04.4, RF-06.1]
--
-- Cada fila es una semana concluida (corte dominical a las 23:59:59 UTC)
-- y alimenta el Libro Mayor de Campeones del Salón de los Linajes.
-- =====================================================================

CREATE TABLE IF NOT EXISTS weekly_cycles (
    id                 TEXT PRIMARY KEY,                             -- UUID v4
    week_number        INTEGER NOT NULL,                             -- Número ISO de semana (1 a 53)
    cycle_year         INTEGER NOT NULL,                             -- Año del ciclo (ej. 2026)
    regent_clan_id     TEXT NOT NULL,                                -- Clan proclamado soberano
    winning_points     INTEGER NOT NULL,                             -- PDA con los que se alzó con la corona
    winner_spell_count INTEGER NOT NULL,                             -- Conjuros validados aportados en la semana
    closed_at          TEXT NOT NULL,                                -- Corte dominical (ISO 8601 UTC)
    FOREIGN KEY (regent_clan_id) REFERENCES clans (id) ON UPDATE CASCADE
);


-- =====================================================================
-- 5. TABLA `daily_simulator_tracker` — Techo Diario de 50 PDA
--    [RF-03.2, RNF-02]
--
-- Acumulador diario por adepto y clan; se reinicia a las 00:00:00 UTC
-- porque `cycle_date` es la fecha UTC del día en curso.
-- =====================================================================

CREATE TABLE IF NOT EXISTS daily_simulator_tracker (
    id             TEXT PRIMARY KEY,                                 -- UUID v4
    user_id        TEXT NOT NULL,                                    -- Adepto que practicó
    clan_id        TEXT NOT NULL,                                    -- Clan beneficiario
    cycle_date     TEXT NOT NULL,                                    -- Fecha UTC (YYYY-MM-DD)
    points_awarded INTEGER NOT NULL DEFAULT 0,                       -- Acumulado del día (tope 50)
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (clan_id) REFERENCES clans (id) ON DELETE CASCADE,
    UNIQUE (user_id, clan_id, cycle_date)
);


-- =====================================================================
-- 6. ÍNDICES DE COHERENCIA Y RENDIMIENTO
--
-- El índice parcial `idx_active_member` es la GARANTÍA ESTRUCTURAL de
-- RF-01.1: un usuario consagrado no puede tener dos membresías activas
-- simultáneas (la unicidad ignora las filas con `left_at` cerrado, que
-- son precisamente el historial exigido por el Artículo III).
-- =====================================================================

-- Criterio «Hecho cuando» de la Tarea 1.1: pertenencia única simultánea.
CREATE UNIQUE INDEX IF NOT EXISTS idx_active_member
    ON clan_members (user_id) WHERE left_at IS NULL;

-- RF-05.4: el Nombre Canónico queda inmortalizado y reservado a
-- perpetuidad. RF-05.4 exige bloquear la reutilización del nombre de un
-- clan DISUELTO, por lo que la unicidad ha de abarcar TODAS las filas
-- (activas y archivadas). SPEC-01 solo declaró UNIQUE sobre `slug`;
-- SQLite no permite añadir `UNIQUE` a una columna existente por ALTER,
-- de modo que la salvaguarda se materializa como índice único global.
CREATE UNIQUE INDEX IF NOT EXISTS idx_clans_name_reserved
    ON clans (name);

-- Rankings del Salón de los Linajes (RF-06.1): semanal en vivo e histórico.
CREATE INDEX IF NOT EXISTS idx_clans_leaderboard
    ON clans (status, weekly_points DESC);
CREATE INDEX IF NOT EXISTS idx_clans_historical
    ON clans (status, historical_points DESC);

-- Tope de 3 solicitudes pendientes por usuario (RF-01.5).
CREATE INDEX IF NOT EXISTS idx_applications_user
    ON clan_applications (user_id, status);

-- Veto ético de 30 días a los Maestros (RF-01.8, Artículo III).
CREATE INDEX IF NOT EXISTS idx_member_history_ethics
    ON clan_members (user_id, clan_id, left_at);

-- Índices de apoyo exigidos por el Dominio Semanal:
--   * consulta del acumulado diario para el techo de 50 PDA (RF-03.2).
--   * crónica cronológica del Libro Mayor de Campeones (RF-06.1).
CREATE INDEX IF NOT EXISTS idx_daily_tracker_lookup
    ON daily_simulator_tracker (user_id, cycle_date);
CREATE INDEX IF NOT EXISTS idx_weekly_cycles_chronicle
    ON weekly_cycles (cycle_year DESC, week_number DESC);
CREATE INDEX IF NOT EXISTS idx_weekly_cycles_regent
    ON weekly_cycles (regent_clan_id);

-- =====================================================================
-- FIN DE LA MIGRACIÓN DE SPEC-07 (Tarea 1.1)
--
-- NOTA PARA TAREAS POSTERIORES (no es competencia de la Tarea 1.1):
--   * `clans` conserva de SPEC-01 las columnas NOT NULL `slug` y `name`;
--     ClanRepository::createClan() (Tarea 1.2) DEBERÁ generar el `slug`
--     público a partir del Nombre Canónico, pues la tabla no admite nulos
--     en esa columna.
--   * La reserva perpetua del Nombre Canónico (RF-05.4) queda garantizada
--     por el índice único idx_clans_name_reserved, que abarca por igual a
--     los clanes `active` y a los `archived`.
-- =====================================================================
