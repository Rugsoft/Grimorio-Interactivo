-- =====================================================================
-- schema.sql — Esquema DDL del Grimorio Interactivo
--
-- Tarea 1.1 (TASKS-01): Infraestructura de datos para el Portal,
-- Navegación y Descubrimiento Arcano.
--
-- Cubre: RF-01.2, RF-01.3, RF-03.1, RF-03.5, RF-03.7 (SPEC-01)
-- Constitución: Artículo I (Dogma Vanilla — SQL nativo sin ORM),
--               Artículo V (identificadores en inglés snake_case).
--
-- Compatibilidad: SQLite 3.35+ y MySQL 8 / MariaDB 10.4+.
-- Notas de compatibilidad:
--   * AUTOINCREMENT (SQLite) y AUTO_INCREMENT (MySQL) difieren; se
--     evita el id autoincremental usando identificadores textuales
--     (ej. 'spl_genesis_01', 'cln_primordial') según el plan técnico.
--   * Los TIMESTAMP se emiten en formato ISO 8601 UTC (TEXT), tal y
--     como exigen los contratos JSON del plan (sección 2).
--   * MySQL necesita InnoDB para aplicar las claves foráneas.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Tabla: clans — Linajes mágicos que compiten por el Dominio semanal
-- (Artículo III de la Constitución; RF-02.2, Salón de Linajes)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clans (
    id          TEXT PRIMARY KEY,                         -- Identificador textual (ej. 'cln_primordial')
    slug        TEXT NOT NULL UNIQUE,                     -- Enlace público del linaje
    name        TEXT NOT NULL,                            -- Nombre en castellano visible al usuario
    motto       TEXT NOT NULL DEFAULT '',                 -- Lema temático del linaje
    domain_points INTEGER NOT NULL DEFAULT 0,             -- Puntos de Dominio del Grimorio (SPEC-07)
    created_at  TEXT NOT NULL                             -- Fecha de fundación (ISO 8601 UTC)
);

-- ---------------------------------------------------------------------
-- Tabla: magic_schools — Catálogo canónico de Escuelas de Magia
-- (Filtros disyuntivos del catálogo, RF-03.5)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS magic_schools (
    slug        TEXT PRIMARY KEY,                         -- Clave natural (ej. 'evocation')
    name        TEXT NOT NULL                             -- Etiqueta en castellano (ej. 'Evocación')
);

-- ---------------------------------------------------------------------
-- Tabla: spells — Hechizos del santuario
-- (Entidad núcleo de SPEC-01: destacados, catálogo y fichas de detalle)
--
-- Estado de moderación conforme al Artículo III:
--   'experimental' → nacimiento de todo hechizo de Editor.
--   'validated'    → tres firmas de Maestro o ratificación del Admin.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS spells (
    id                    TEXT PRIMARY KEY,               -- Identificador textual (ej. 'spl_genesis_01')
    slug                  TEXT NOT NULL UNIQUE,           -- Enlace directo '#hechizo-slug' (RF-04.1)
    name                  TEXT NOT NULL,                  -- Nombre visible del conjuro
    magic_school          TEXT NOT NULL REFERENCES magic_schools (slug),  -- FK: escuela válida (filtro RF-03.5)
    mana_cost             INTEGER NOT NULL CHECK (mana_cost >= 0),        -- Coste determinista (Artículo II)
    clan_id               TEXT NOT NULL REFERENCES clans (id),            -- FK: linaje de origen
    summary               TEXT NOT NULL,                  -- Resumen breve (máx. 3 líneas en tarjeta)
    description           TEXT NOT NULL DEFAULT '',       -- Ficha técnica completa (modal de detalle)
    components_verbal     TEXT NOT NULL DEFAULT '',       -- Componente verbal (fórmula arcana)
    components_somatic    TEXT NOT NULL DEFAULT '',       -- Componente somático (gesto místico)
    components_material   TEXT NOT NULL DEFAULT '',       -- Componente material (reliquia o substancia)
    status                TEXT NOT NULL DEFAULT 'experimental'
                          CHECK (status IN ('experimental', 'validated')),  -- Moderación en 2 pasos
    validation_signatures_count INTEGER NOT NULL DEFAULT 0 CHECK (validation_signatures_count >= 0),
    is_genesis_sample     INTEGER NOT NULL DEFAULT 0 CHECK (is_genesis_sample IN (0, 1)),  -- Pergamino Primordial (RF-01.3)
    created_at            TEXT NOT NULL,                  -- Nacimiento del conjuro (ISO 8601 UTC)
    validated_at          TEXT                            -- Fecha de validación (NULL si es experimental)
);

-- ---------------------------------------------------------------------
-- Índices exigidos por el criterio "Hecho cuando" de la Tarea 1.1:
--   * slug: enlaces directos y búsquedas de ficha (RF-04.1, RF-06.2).
--   * magic_school: filtrado disyuntivo de escuelas (RF-03.5).
-- ---------------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_spells_slug ON spells (slug);
CREATE INDEX IF NOT EXISTS idx_spells_magic_school ON spells (magic_school);

-- Índices de apoyo para las consultas de descubrimiento del portal:
CREATE INDEX IF NOT EXISTS idx_spells_status_validated_at ON spells (status, validated_at);
CREATE INDEX IF NOT EXISTS idx_spells_clan_id ON spells (clan_id);

-- ---------------------------------------------------------------------
-- Integridad referencial (claves foráneas):
--   * spells.magic_school -> magic_schools.slug (escuelas válidas).
--   * spells.clan_id       -> clans.id          (linaje existente).
-- SQLite exige 'PRAGMA foreign_keys = ON' por conexión (lo aplica el
-- test de integración y, más adelante, Connection.php en la Tarea 1.2).
-- MySQL con InnoDB las aplica por defecto.
-- ---------------------------------------------------------------------

-- NOTA DE COMPATIBILIDAD: SQLite no admite 'ALTER TABLE ... ADD CONSTRAINT',
-- por lo que las claves foráneas se declaran en línea dentro de cada tabla
-- mediante su cláusula REFERENCES nativa. Se replican aquí como
-- documentación viva del contrato referencial del esquema.
--
-- | Tabla  | Columna       | Referencia          |
-- |--------|---------------|---------------------|
-- | spells | magic_school  | magic_schools(slug) |
-- | spells | clan_id       | clans(id)           |
