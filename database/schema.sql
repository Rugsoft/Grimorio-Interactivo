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
-- (Entidad núcleo de SPEC-01: destacados, catálogo y fichas de detalle;
--  ampliada por la Tarea 1.1 de TASKS-04 con magnitudes cuantitativas)
--
-- Estado de moderación conforme al Artículo III:
--   'draft'        → borrador privado del autor (no visible; TASKS-04).
--   'experimental' → nacimiento de todo hechizo publicado de Editor.
--   'validated'    → tres firmas de Maestro o ratificación del Admin.
--
-- Magnitudes cuantitativas (TASKS-04, Artículo II):
--   * damage/healing/barrier son los efectos base ponderados por
--     SpellBalanceService (Tarea 2.2).
--   * crowd_control_type/range_type/area_type/duration_type son los
--     modificadores canónicos de la fórmula de maná.
--   * has_verbal/has_somatic/has_material son los componentes
--     atenuadores (-10% cada uno, tope del 30% combinado).
--   * mana_cost/circle/math_fingerprint son RESULTADOS deterministas
--     del backend (Artículo II: el cliente jamás dicta el coste).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS spells (
    id                    TEXT PRIMARY KEY,               -- Identificador textual (ej. 'spl_genesis_01')
    slug                  TEXT NOT NULL UNIQUE,           -- Enlace directo '#hechizo-slug' (RF-04.1)
    name                  TEXT NOT NULL,                  -- Nombre visible del conjuro
    author_id             TEXT NOT NULL REFERENCES users (id),            -- Creador del conjuro (TASKS-04, FK)
    magic_school          TEXT NOT NULL REFERENCES magic_schools (slug),  -- FK: escuela válida (filtro RF-03.5)
    elemental_affinity    TEXT NOT NULL DEFAULT 'none',   -- Afinidad elemental (matrix SPEC-06; neutro por defecto)
    casting_time          TEXT NOT NULL DEFAULT 'action', -- 'action', 'reaction', 'ritual'
    mana_cost             INTEGER NOT NULL CHECK (mana_cost >= 0 AND mana_cost <= 200),  -- Coste determinista (Artículo II: suelo 5, techo 200)
    circle                INTEGER NOT NULL DEFAULT 1 CHECK (circle >= 1 AND circle <= 5), -- Círculo arcano asignado (1 a 5, TASKS-04)
    math_fingerprint      TEXT NOT NULL DEFAULT '' CHECK (length(math_fingerprint) = 64), -- SHA-256 hex de parámetros matemáticos (antifraude, TASKS-04)
    clan_id               TEXT NOT NULL REFERENCES clans (id),            -- FK: linaje de origen
    summary               TEXT NOT NULL,                  -- Resumen breve (máx. 3 líneas en tarjeta)
    description           TEXT NOT NULL DEFAULT '',       -- Ficha técnica completa (modal de detalle)
    components_verbal     TEXT NOT NULL DEFAULT '',       -- Componente verbal (fórmula arcana)
    components_somatic    TEXT NOT NULL DEFAULT '',       -- Componente somático (gesto místico)
    components_material   TEXT NOT NULL DEFAULT '',       -- Componente material (reliquia o substancia)
    damage                INTEGER NOT NULL DEFAULT 0 CHECK (damage >= 0),          -- Puntos de daño directo o continuo (TASKS-04)
    healing               INTEGER NOT NULL DEFAULT 0 CHECK (healing >= 0),         -- Puntos de curación directa (TASKS-04)
    barrier               INTEGER NOT NULL DEFAULT 0 CHECK (barrier >= 0),         -- Puntos de absorción o protección (TASKS-04)
    crowd_control_type    TEXT NOT NULL DEFAULT 'none'
                          CHECK (crowd_control_type IN ('none', 'slow', 'root', 'stun')),  -- Control de masas (TASKS-04)
    range_type            TEXT NOT NULL DEFAULT 'touch'
                          CHECK (range_type IN ('touch', 'short', 'medium', 'long')),      -- Alcance del conjuro (TASKS-04)
    area_type             TEXT NOT NULL DEFAULT 'singleTarget'
                          CHECK (area_type IN ('singleTarget', 'cone', 'line', 'sphere')), -- Geometría de área (TASKS-04)
    duration_type         TEXT NOT NULL DEFAULT 'instant'
                          CHECK (duration_type IN ('instant', 'concentration', 'sustained')), -- Duración (TASKS-04)
    has_verbal            INTEGER NOT NULL DEFAULT 0 CHECK (has_verbal IN (0, 1)),  -- Componente atenuador verbal (-10%)
    has_somatic           INTEGER NOT NULL DEFAULT 0 CHECK (has_somatic IN (0, 1)), -- Componente atenuador somático (-10%)
    has_material          INTEGER NOT NULL DEFAULT 0 CHECK (has_material IN (0, 1)),-- Componente atenuador material (-10%)
    status                TEXT NOT NULL DEFAULT 'draft'
                          CHECK (status IN ('draft', 'experimental', 'validated')),  -- Ciclo de vida completo (TASKS-04)
    validation_signatures_count INTEGER NOT NULL DEFAULT 0 CHECK (validation_signatures_count >= 0),
    signatures_count      INTEGER NOT NULL DEFAULT 0 CHECK (signatures_count >= 0 AND signatures_count <= 3), -- Firmas de Maestros 0/3 (TASKS-04); la columna génesis validation_signatures_count se conserva por compatibilidad hasta SPEC-08
    is_genesis_sample     INTEGER NOT NULL DEFAULT 0 CHECK (is_genesis_sample IN (0, 1)),  -- Pergamino Primordial (RF-01.3)
    created_at            TEXT NOT NULL,                  -- Nacimiento del conjuro (ISO 8601 UTC)
    updated_at            TEXT NOT NULL,                  -- Última modificación (ISO 8601 UTC, TASKS-04)
    validated_at          TEXT                            -- Fecha de validación (NULL si no validado)
);

-- ---------------------------------------------------------------------
-- Índices exigidos por el criterio "Hecho cuando" de la Tarea 1.1:
--   * slug: enlaces directos y búsquedas de ficha (RF-04.1, RF-06.2).
--   * magic_school: filtrado disyuntivo de escuelas (RF-03.5).
-- ---------------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_spells_slug ON spells (slug);
CREATE INDEX IF NOT EXISTS idx_spells_magic_school ON spells (magic_school);

-- Índices de optimización del ciclo de vida del creador (TASKS-04, Tarea 1.1):
--   * (author_id, status): cuota de 10 borradores y listas del autor (RF-05.1).
--   * (clan_id, status): catálogo por linaje y Moderación en 2 pasos (RF-08.2).
CREATE INDEX IF NOT EXISTS idx_spell_author_status ON spells (author_id, status);
CREATE INDEX IF NOT EXISTS idx_spell_clan_validated ON spells (clan_id, status);

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

-- =====================================================================
-- SPEC-03 — Autenticación, Sesiones y Control de Acceso (RBAC)
-- Tarea 1.1 (TASKS-03): DDL de usuarios, sesiones, intentos, historial
-- de linajes y bitácora de auditoría.
--
-- Cubre: RF-01.1, RF-02.1, RF-03.2, RF-06.1, RF-08.1, RNF-02, Art. III,
--        Artículo V (identificadores en inglés snake_case).
-- Notas de compatibilidad con el dialecto dual del esquema:
--   * BOOLEAN se emite como INTEGER CHECK (col IN (0, 1)): ambos
--     motores lo respetan y evita el dialecto TINYINT(1) de MySQL.
--   * Los autoincrementales evitan el par AUTOINCREMENT/AUTO_INCREMENT
--     mediante 'INTEGER PRIMARY KEY' (SQLite lo convierte en rowid
--     alias; MySQL acepta INTEGER PRIMARY KEY con NOT NULL explícito
--     solo en modo estricto, por lo que se documenta su equivalencia
--     con AUTO_INCREMENT en el comentario de cada tabla).
--   * Los TIMESTAMP se emiten como TEXT ISO 8601 UTC, igual que el
--     resto del esquema (contratos JSON del plan, sección 2).
-- =====================================================================

-- ---------------------------------------------------------------------
-- Tabla: users — Miembros consagrados del santuario [RF-01, RF-05]
--
-- Roles canónicos (RF-05.1, jerarquía sagrada): 'reader', 'editor',
-- 'master', 'supremeAdmin'. La restricción CHECK bloquea cualquier
-- rol fuera del canon. El hash de la frase de paso jamás viaja al
-- cliente (Tarea 1.2: la entidad User lo omite al serializar).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            TEXT PRIMARY KEY,                          -- Identificador textual (ej. 'usr_8f1a2b3c')
    alias         TEXT NOT NULL UNIQUE,                      -- Nombre de iniciado público (3 a 30 caracteres)
    email         TEXT NOT NULL UNIQUE,                      -- Correo electrónico validado
    password_hash TEXT NOT NULL,                             -- Frase de paso hasheada (BCRYPT coste 12)
    role          TEXT NOT NULL DEFAULT 'editor'
                  CHECK (role IN ('reader', 'editor', 'master', 'supremeAdmin')),  -- Jerarquía sagrada (RF-05.1)
    clan_id       TEXT NOT NULL REFERENCES clans (id),       -- FK: linaje de afiliación obligatorio (RF-01.1)
    recovery_token_hash         TEXT NOT NULL DEFAULT '',    -- SHA-256 del pergamino activo ('' = sin pergamino, RF-04.1)
    recovery_token_expires_at   TEXT,                        -- Vigencia de 60 minutos del pergamino (RF-04.1)
    created_at    TEXT NOT NULL,                             -- Alta del iniciado (ISO 8601 UTC)
    updated_at    TEXT NOT NULL                              -- Última modificación (ISO 8601 UTC)
);

-- Índice de búsqueda de identidad por correo o alias (login, RF-02).
CREATE INDEX IF NOT EXISTS idx_users_email ON users (email);
CREATE INDEX IF NOT EXISTS idx_users_clan_id ON users (clan_id);

-- ---------------------------------------------------------------------
-- Tabla: user_sessions — Vínculos activos multidispositivo [RF-02]
--
-- Ciclo de vida (RF-02.1/02.2): expires_at se renueva con cada
-- interacción (ventana de 14 días); absolute_expires_at es inmutable
-- (tope absoluto de 30 días) y fuerza la reautenticación solemne.
-- El token de sesión jamás se almacena en claro: solo su SHA-256.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_sessions (
    id                  TEXT PRIMARY KEY,                    -- Identificador textual de la sesión
    session_token_hash  TEXT NOT NULL UNIQUE,                -- SHA-256 del token (jamás el token en claro)
    user_id             TEXT NOT NULL REFERENCES users (id) ON DELETE CASCADE,  -- Titular del vínculo
    ip_address          TEXT NOT NULL,                       -- Procedencia (soporta IPv4 e IPv6, máx. 45)
    user_agent          TEXT NOT NULL DEFAULT '',            -- Cliente declarado (informativo)
    created_at          TEXT NOT NULL,                       -- Nacimiento del vínculo (ISO 8601 UTC)
    last_activity_at    TEXT NOT NULL,                       -- Renovado en cada acción (RF-02.2)
    expires_at          TEXT NOT NULL,                       -- Ventana renovable de 14 días
    absolute_expires_at TEXT NOT NULL                        -- Límite absoluto inmutable de 30 días
);

-- Depuración de sesiones caducadas y revocación global por usuario.
CREATE INDEX IF NOT EXISTS idx_sessions_user_id ON user_sessions (user_id);
CREATE INDEX IF NOT EXISTS idx_sessions_expires ON user_sessions (expires_at);

-- ---------------------------------------------------------------------
-- Tabla: login_attempts — Defensa anti-fuerza bruta y anti-DoS [RF-03]
--
-- Ventana de bloqueo (RF-03.2): 5 fallos por IP en 15 minutos
-- congelan la procedencia sin bloquear la cuenta legítima. El índice
-- (ip_address, attempted_at) sostiene la consulta de ventana.
-- id autoincremental: en MySQL declarar 'INT NOT NULL AUTO_INCREMENT
-- PRIMARY KEY' (equivalente dialectal de INTEGER PRIMARY KEY SQLite).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id                 INTEGER PRIMARY KEY,                  -- Rowid autoincremental (SQLite)
    ip_address         TEXT NOT NULL,                        -- Procedencia congelable (IPv4/IPv6)
    attempted_identity TEXT NOT NULL,                        -- Alias o correo intentado (no verificado)
    attempted_at       TEXT NOT NULL,                        -- Marca temporal del intento (ISO 8601 UTC)
    is_success         INTEGER NOT NULL DEFAULT 0 CHECK (is_success IN (0, 1))  -- 1 si el vínculo se renovó
);

-- Índice EXIGIDO por el criterio «Hecho cuando»: ventana anti-fuerza bruta.
CREATE INDEX IF NOT EXISTS idx_ip_attempt ON login_attempts (ip_address, attempted_at);

-- ---------------------------------------------------------------------
-- Tabla: clan_history — Historial de linajes del iniciado [RF-06, RF-07]
--
-- Incompatibilidad histórica de 30 días (RF-06.1, Artículo III): un
-- Maestro no juzga conjuros de linajes que habitó en el último mes.
-- left_at NULL indica el clan actualmente activo. El índice
-- (user_id, left_at) sostiene la consulta histórica del conflicto.
-- id autoincremental: en MySQL usar AUTO_INCREMENT (ver nota arriba).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clan_history (
    id        INTEGER PRIMARY KEY,                           -- Rowid autoincremental (SQLite)
    -- RF-09.2 (Art. III): el legado del linaje SOBREVIVE a la cuenta — sin
    -- CASCADE. Al borrar un usuario, su historia queda con la identidad
    -- técnica preservada (contribuciones y ventana de 30 días intactas);
    -- el borrado directo del usuario con historial exige baja explícita.
    user_id   TEXT NOT NULL REFERENCES users (id) ON DELETE RESTRICT,  -- Historia del iniciado (sobrevive al olvido)
    clan_id   TEXT NOT NULL REFERENCES clans (id),           -- Linaje habitado
    joined_at TEXT NOT NULL,                                 -- Ingreso al linaje (ISO 8601 UTC)
    left_at   TEXT                                           -- Salida (NULL = clan activo)
);

-- Índice EXIGIDO por el criterio «Hecho cuando»: conflicto de 30 días.
CREATE INDEX IF NOT EXISTS idx_user_clan_time ON clan_history (user_id, left_at);

-- Índice de apoyo para los puntos históricos por linaje (RF-07.2).
CREATE INDEX IF NOT EXISTS idx_clan_history_clan ON clan_history (clan_id);

-- ---------------------------------------------------------------------
-- Tabla: audit_log — Bitácora inmutable de auditoría arcana [RF-08]
--
-- Registro IMBORRABLE (RF-08.1, RNF-02, Artículo III): solo admite
-- INSERT; jamás UPDATE ni DELETE sobre esta tabla (se protege con
-- triggers en la Tarea 2.5 del plan). Motivo solemne obligatorio en
-- castellano noble. id autoincremental: en MySQL usar AUTO_INCREMENT.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id                  INTEGER PRIMARY KEY,                 -- Rowid autoincremental (SQLite)
    actor_user_id       TEXT NOT NULL,                       -- Identidad del actuante
    actor_alias         TEXT NOT NULL,                       -- Alias público en el instante de la acción
    actor_role          TEXT NOT NULL,                       -- Rol técnico activo en ese instante
    action_type         TEXT NOT NULL,                       -- 'SIGN_VALIDATE', 'SIGN_REJECT', 'ADMIN_VETO', 'PROMOTE_MASTER', 'DEMOTE_MASTER', 'CLAN_MODIFY'
    target_entity_type  TEXT NOT NULL,                       -- 'spell', 'clan', 'user'
    target_entity_id    TEXT NOT NULL,                       -- Identificador de la entidad afectada
    justification       TEXT NOT NULL,                       -- Motivo solemne obligatorio (castellano)
    created_at          TEXT NOT NULL                        -- Marca temporal UTC (ISO 8601)
);

-- Índices de consulta pública paginada de la bitácora (RF-08.2).
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log (created_at);
CREATE INDEX IF NOT EXISTS idx_audit_actor ON audit_log (actor_user_id);
CREATE INDEX IF NOT EXISTS idx_audit_target ON audit_log (target_entity_type, target_entity_id);

-- ---------------------------------------------------------------------
-- Inmutabilidad blindada de la bitácora (RF-08.1, RNF-02, Art. III,
-- Tarea 2.5): triggers que RECHAZAN cualquier UPDATE o DELETE sobre
-- audit_log. La bitácora solo admite INSERT; ni moderadores ni el
-- Admin Supremo pueden alterar o borrar un veredicto registrado.
-- (SQLite los aplica en cada conexión; MySQL usa la sintaxis
-- equivalente BEFORE UPDATE/DELETE con SIGNAL SQLSTATE '45000'.)
-- ---------------------------------------------------------------------
CREATE TRIGGER IF NOT EXISTS trg_audit_log_no_update
BEFORE UPDATE ON audit_log
BEGIN
    SELECT RAISE(ABORT, 'La Bitácora de Auditoría Arcana es inmutable: los veredictos jamás se alteran.');
END;

CREATE TRIGGER IF NOT EXISTS trg_audit_log_no_delete
BEFORE DELETE ON audit_log
BEGIN
    SELECT RAISE(ABORT, 'La Bitácora de Auditoría Arcana es inmutable: los veredictos jamás se borran.');
END;
