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
-- Tabla: clans — Hermandades del santuario: identidad heráldica, gobierno
-- y gloria del Dominio Semanal (Artículo III; SPEC-01 y SPEC-07)
--
-- Conserva la forma fundacional de SPEC-01 (id, slug, name, motto,
-- created_at) y le suma las columnas del Sistema de Clanes, Linajes y
-- Dominio Semanal [RF-01.2, RF-01.3, RF-01.5, RF-01.9, RF-02.1, RF-05.3]:
--   * coat_of_arms      → blasón rúnico/icono SVG del estandarte.
--   * lineage_type      → uno de los 8 Linajes Canónicos (RF-02.1).
--   * admission_mode    → 'open' | 'byApplication' (RF-01.5).
--   * status            → 'active' | 'archived' (Herencia Ancestral, RF-05.3).
--   * patriarch_id      → Patriarca/Matriarca en funciones (RF-01.3);
--                         ANULABLE: una casa puede quedar acéfala y disolverse
--                         hacia `archived` sin Patriarca vivo (RF-05.3).
--   * weekly_points     → PDA de la semana en curso (RF-03, RF-04.3).
--   * historical_points → acumulado perpetuo de todos los tiempos.
--   * last_activity_at  → última actividad del Patriarca (RF-01.9).
--   * updated_at        → marca de la última modificación.
--
-- UN SOLO CONTADOR DE GLORIA (Tarea 2.6, TASKS-07): la columna
-- `domain_points` de SPEC-01 quedó RETIRADA del plano. Medía exactamente lo
-- mismo que `weekly_points` —el catálogo público rotulaba su valor como
-- «Dominio semanal»— y carecía de escritor alguno, de modo que era un tercer
-- contador condenado a divergir en silencio. Quedan, por tanto, DOS
-- contadores canónicos y uno por concepto: `weekly_points` (la contienda en
-- curso) y `historical_points` (la gloria perpetua). El contrato público
-- conserva su clave `domainPoints`, ahora servida desde `weekly_points`.
-- `slug` (enlace público único) sí se preserva: es carga estructural de los
-- enlaces directos del catálogo.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clans (
    id                TEXT PRIMARY KEY,                   -- Identificador textual (ej. 'cln_primordial')
    slug              TEXT NOT NULL UNIQUE,               -- Enlace público del linaje
    name              TEXT NOT NULL,                      -- Nombre Canónico Único (RF-01.2, RF-05.4)
    motto             TEXT NOT NULL DEFAULT '',            -- Lema heráldico en castellano
    created_at        TEXT NOT NULL,                      -- Fundación (ISO 8601 UTC)
    coat_of_arms      TEXT NOT NULL DEFAULT '',            -- Blasón rúnico identificador
    lineage_type      TEXT NOT NULL DEFAULT 'primordialFlame'
                      CHECK (lineage_type IN (
                          'primordialFlame', 'celestialTides', 'eternalTempest', 'worldRoots',
                          'dawnWinds', 'solarCrown', 'abyssalShadows', 'aetherWeavers'
                      )),                                  -- Linaje rector (RF-02.1)
    admission_mode    TEXT NOT NULL DEFAULT 'open'
                      CHECK (admission_mode IN ('open', 'byApplication')),  -- Régimen (RF-01.5)
    status            TEXT NOT NULL DEFAULT 'active'
                      CHECK (status IN ('active', 'archived')),  -- Herencia Ancestral (RF-05.3)
    patriarch_id      TEXT REFERENCES users (id) ON UPDATE CASCADE,  -- Corona (RF-01.3)
    weekly_points     INTEGER NOT NULL DEFAULT 0,          -- PDA de la semana (RF-03)
    historical_points INTEGER NOT NULL DEFAULT 0,          -- Gloria perpetua (RF-04.3)
    last_activity_at  TEXT NOT NULL DEFAULT '',            -- Actividad del Patriarca (RF-01.9)
    updated_at        TEXT NOT NULL DEFAULT ''             -- Última modificación
);

-- ---------------------------------------------------------------------
-- Tabla: lineage_doctrines — El canon ceremonial de los Ocho Linajes
-- [SPEC-09, Tarea 1.2 — RF-02.1, RF-02.2]
--
-- FUENTE DE VERDAD de las doctrinas canónicas del juramento (Anexo A del
-- plan, [RATIFICADAS]): un solo texto canónico del que viajan las dos
-- granularidades de la ceremonia — la condensada (1-2 frases, tarjeta
-- contraída, RF-02.1) y la íntegra (2-4 frases, expansión y modal,
-- RF-02.2). La heráldica (name, glyph, banner_color, ruling_element) es
-- la misma del canon de SPEC-07 (LineageSynergyService, Endpoint 10).
--
-- ¿Por qué tabla y no constantes de servicio? La coherencia
-- guion↔esquema (lección de SPEC-08) y el Artículo III: las doctrinas
-- son memoria de hermandad inscribed en la base, legibles por cualquier
-- arnés y por la vista de ceremonia sin acoplar el backend a un array
-- PHP. El canon sigue siendo INMUTABLE (exclusión 5): ninguna operación
-- del sistema altera estas filas; la tabla solo nace aquí y en semillas.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lineage_doctrines (
    id                 TEXT PRIMARY KEY,                    -- Clave canónica del linaje (ej. 'primordialFlame')
    name               TEXT NOT NULL,                       -- Nombre ceremonial en castellano (RF-04.2)
    glyph              TEXT NOT NULL,                       -- Glifo rúnico ancestral (heráldica de SPEC-07)
    banner_color       TEXT NOT NULL,                       -- Estandarte ceremonial #rrggbb
    ruling_element     TEXT NOT NULL,                       -- Afinidad elemental rectora (ej. 'fire')
    doctrine_condensed TEXT NOT NULL,                       -- Doctrina condensada: 1-2 frases (RF-02.1)
    doctrine_full      TEXT NOT NULL,                       -- Doctrina íntegra: 2-4 frases (RF-02.2)
    position           INTEGER NOT NULL DEFAULT 0           -- Orden ceremonial de la rejilla
);

-- ---------------------------------------------------------------------
-- Tabla: clan_members — Membresías, roles y Convalecencia Arcana
-- [RF-01.1, RF-01.3, RF-01.4, RF-01.6, RF-01.8, RF-01.9]
--
-- AUTORIDAD ÚNICA de la afiliación de un mago. `left_at` NULL señala la
-- afiliación ACTIVA; `convalescence_expires_at` fija el fin de los 14 días
-- naturales de meditación (RF-01.6). El índice único parcial
-- `idx_active_member` garantiza la pertenencia única simultánea (RF-01.1) y
-- las filas cerradas constituyen el historial que sostiene el veto de 30
-- días a los Maestros (RF-01.8, Artículo III). Jamás se borra una fila.
-- `users.clan_id` es solo su espejo denormalizado.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clan_members (
    id                       TEXT PRIMARY KEY,                       -- UUID v4 (ej. 'clm_01928a3b')
    clan_id                  TEXT NOT NULL,                          -- Clan de vinculación
    user_id                  TEXT NOT NULL,                          -- Usuario adepto
    role                     TEXT NOT NULL DEFAULT 'adept'
                             CHECK (role IN ('patriarch', 'adept')), -- Rol canónico (RF-01.3)
    joined_at                TEXT NOT NULL,                          -- Ingreso formal (ISO 8601 UTC)
    left_at                  TEXT,                                   -- Partida o expulsión (NULL = activo)
    convalescence_expires_at TEXT,                                   -- Fin de los 14 días (RF-01.6)
    FOREIGN KEY (clan_id) REFERENCES clans (id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

-- ---------------------------------------------------------------------
-- Tabla: clan_applications — Solicitudes de ingreso [RF-01.5]
--
-- El régimen `byApplication` exige deliberación del Patriarca; un mismo
-- usuario no puede acumular más de 3 solicitudes `pending` (el tope lo
-- refuerza ClanApplicationRepository).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clan_applications (
    id          TEXT PRIMARY KEY,                                    -- UUID v4
    clan_id     TEXT NOT NULL,                                       -- Clan al que se postula
    user_id     TEXT NOT NULL,                                       -- Usuario postulante
    status      TEXT NOT NULL DEFAULT 'pending'
                CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled')),
    created_at  TEXT NOT NULL,                                       -- Emisión (ISO 8601 UTC)
    resolved_at TEXT,                                                -- Veredicto (NULL = en deliberación)
    FOREIGN KEY (clan_id) REFERENCES clans (id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

-- ---------------------------------------------------------------------
-- Tabla: weekly_cycles — Registro histórico de ciclos y campeones
-- [RF-04.1, RF-04.2, RF-04.4, RF-06.1]
--
-- Cada fila es una semana concluida (corte dominical a las 23:59:59 UTC) y
-- alimenta el Libro Mayor de Campeones del Salón de los Linajes.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS weekly_cycles (
    id                 TEXT PRIMARY KEY,                             -- UUID v4
    week_number        INTEGER NOT NULL,                             -- Número ISO de semana (1 a 53)
    cycle_year         INTEGER NOT NULL,                             -- Año del ciclo (ej. 2026)
    regent_clan_id     TEXT NOT NULL,                                -- Clan proclamado soberano
    winning_points     INTEGER NOT NULL,                             -- PDA de la coronación
    winner_spell_count INTEGER NOT NULL,                             -- Conjuros validados de la semana
    closed_at          TEXT NOT NULL,                                -- Corte dominical (ISO 8601 UTC)
    FOREIGN KEY (regent_clan_id) REFERENCES clans (id) ON UPDATE CASCADE
);

-- ---------------------------------------------------------------------
-- Tabla: daily_simulator_tracker — Techo diario de 50 PDA [RF-03.2, RNF-02]
--
-- Acumulador diario por adepto y clan; se reinicia a las 00:00:00 UTC porque
-- `cycle_date` es la fecha UTC del día en curso.
-- ---------------------------------------------------------------------
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

-- Índices del Sistema de Clanes y Dominio Semanal (SPEC-07).
--
-- `idx_active_member` es la GARANTÍA ESTRUCTURAL de RF-01.1: pertenencia
-- única simultánea (la unicidad ignora las filas con `left_at` cerrado, que
-- son precisamente el historial del Artículo III).
CREATE UNIQUE INDEX IF NOT EXISTS idx_active_member
    ON clan_members (user_id) WHERE left_at IS NULL;

-- RF-05.4: el Nombre Canónico queda reservado a perpetuidad, incluso para
-- clanes disueltos; por eso la unicidad abarca TODAS las filas.
CREATE UNIQUE INDEX IF NOT EXISTS idx_clans_name_reserved
    ON clans (name);

-- Rankings del Salón de los Linajes (RF-06.1): semanal e histórico.
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

-- Acumulado diario del simulador (RF-03.2) y crónica del Libro Mayor (RF-06.1).
CREATE INDEX IF NOT EXISTS idx_daily_tracker_lookup
    ON daily_simulator_tracker (user_id, cycle_date);
CREATE INDEX IF NOT EXISTS idx_weekly_cycles_chronicle
    ON weekly_cycles (cycle_year DESC, week_number DESC);
CREATE INDEX IF NOT EXISTS idx_weekly_cycles_regent
    ON weekly_cycles (regent_clan_id);

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
    -- ESPEJO denormalizado del ciclo de vida (TASKS-08, Tarea 1.5). La
    -- AUTORIDAD es `spell_reviews.status`, que declara los cinco estados
    -- canónicos de SPEC-08 RF-01.1; esta columna los repite para que el Tomo
    -- Canónico, el Atrio y la libreta del autor se consulten sin cruzar el
    -- expediente en cada página. Su ÚNICO escritor es
    -- `SpellReviewRepository`, dentro de la misma transacción que el
    -- expediente, de modo que el espejo no puede desviarse de la autoridad.
    -- Un conjuro que aún no ha entrado a moderación —un borrador— no tiene
    -- expediente y esta columna sostiene su estado embrionario.
    status                TEXT NOT NULL DEFAULT 'draft'
                          CHECK (status IN ('draft', 'experimental', 'validated', 'rejected', 'archived')),  -- Ciclo de vida completo (TASKS-08, RF-01.1)
    validation_signatures_count INTEGER NOT NULL DEFAULT 0 CHECK (validation_signatures_count >= 0),
    -- ESPEJO denormalizado del contador de firmas vivas (TASKS-08, Tarea 1.5):
    -- la AUTORIDAD es `spell_reviews.signatures_count`, mantenida por
    -- `SpellReviewRepository` y verificable contra las firmas reales de
    -- `master_signatures`. Mismo ÚNICO escritor que la columna `status`.
    -- `validation_signatures_count` NO compite con este contador: es el
    -- vestigio congelado del aforo génesis de TASKS-04, jamás escrito tras
    -- las semillas, y forma parte del contrato JSON público de SPEC-04.
    signatures_count      INTEGER NOT NULL DEFAULT 0 CHECK (signatures_count >= 0 AND signatures_count <= 3), -- Firmas de Maestros 0/3 (TASKS-08, RF-02.1)
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
    -- ANULABLE a propósito: un mago consagrado puede no pertenecer a ningún
    -- linaje (RF-01.2 exige fundar uno sin pertenecer a otro). La AUTORIDAD
    -- de la afiliación es `clan_members` (SPEC-07); esta columna es un
    -- ESPEJO denormalizado de la membresía ACTIVA, mantenido en exclusiva
    -- por ClanMemberRepository y reconciliado por la migración
    -- sql/07_membership_single_source.sql. NULL = sin linaje.
    clan_id       TEXT REFERENCES clans (id),
    -- EL VÍNCULO DEL JURAMENTO DE LINAJE [SPEC-09, Tarea 1.2 — RF-01.5,
    -- RF-01.2, RF-03.4, RF-04.1]. ANULABLE a propósito: NULL significa
    -- «peregrino sin linaje», la fase de vida que la ceremonia bloqueante
    -- del primer acceso conduce al juramento (RF-01.2/01.3). El vínculo
    -- es PERPETUO: solo el juramento (SPEC-09) lo escribe, jamás se muta
    -- una vez sellado (RF-03.4) y muere con la cuenta purgada. El CHECK
    -- impone el canon cerrado de los OCHO Linajes Canónicos de SPEC-07:
    -- la base es la última muralla del canon inmutable (exclusión 5).
    -- Sin REFERENCES a propósito: el canon vive como servicio. Es
    -- VÍNCULO INDEPENDIENTE del espejo `clan_id` (RF-04.1): la AUTORIDAD
    -- de la membresía sigue siendo `clan_members` (SPEC-07); la migración
    -- sql/09_lineage_oath.sql alineó el legado UNA SOLA VEZ (caso límite
    -- 7). Coherente con el ALTER de esa migración (guion↔esquema).
    lineage       TEXT NULL
                  CHECK (lineage IN (
                      'primordialFlame', 'celestialTides', 'eternalTempest', 'worldRoots',
                      'dawnWinds', 'solarCrown', 'abyssalShadows', 'aetherWeavers'
                  )),
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
-- Tabla: clan_history — LEGADO, superada por `clan_members` [RF-06, RF-07]
--
-- RETIRADA DEL CAMINO CRÍTICO. Ninguna clase de src/ escribe ni lee ya en
-- esta tabla: la afiliación y su historia viven en `clan_members` (SPEC-07),
-- que el ClanMemberRepository materializa y jamás borra. Se conserva
-- únicamente porque los arneses de SPEC-01/03 la siembran y para no romper
-- bases ya construidas; la migración sql/07_membership_single_source.sql
-- importa su contenido hacia `clan_members`.
--
-- Incompatibilidad histórica de 30 días (RF-06.1, Artículo III): un Maestro
-- no juzga conjuros de linajes que habitó en el último mes. left_at NULL
-- indicaba el clan activo. id autoincremental: en MySQL usar AUTO_INCREMENT.
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

-- ---------------------------------------------------------------------
-- Libro de Gloria del Dominio Semanal (SPEC-07, Tarea 2.5)
--
-- Se declara al final del plano porque sus claves foráneas apuntan a `users`
-- y a `spells`, ambas ya creadas: así la sentencia sigue siendo válida en
-- MySQL/MariaDB, que exige que la tabla referenciada exista antes.
-- ---------------------------------------------------------------------

-- Libreta de Favoritos del santuario [RF-03.3, RNF-02].
--
-- La UNICIDAD (user_id, spell_id) es la garantía ESTRUCTURAL de RNF-02: una
-- misma cuenta jamás podrá registrar más de un voto computable sobre el
-- mismo conjuro, por más que pulse el elogio repetidamente o desde varias
-- pestañas. El linaje beneficiario NO se duplica aquí: se deriva del
-- `spells.clan_id` del conjuro, que es patrimonio inviolable de su clan
-- (RF-05.1) y por tanto el único destino legítimo de los cinco PDA.
CREATE TABLE IF NOT EXISTS favorites (
    id         TEXT PRIMARY KEY,                         -- UUID v4
    user_id    TEXT NOT NULL,                            -- Mago que elogia (autor del voto)
    spell_id   TEXT NOT NULL,                            -- Conjuro sellado elogiado
    created_at TEXT NOT NULL,                            -- Instante del elogio (ISO 8601 UTC)
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (spell_id) REFERENCES spells (id) ON DELETE CASCADE,
    UNIQUE (user_id, spell_id)                           -- Un solo voto computable (RNF-02)
);

-- Libro de acreditaciones de PDA que no tienen otro registro [RF-03.1, RF-03.3].
--
-- Cada validación de conjuro y cada elogio comunitario se asienta UNA sola
-- vez: la unicidad (action_type, source_id) es la garantía estructural de que
-- una gloria jamás se cobra dos veces (RNF-01), con `source_id` = el conjuro
-- validado o la fila de favorito que la motivó.
--
-- La práctica del simulador NO se asienta aquí: su acumulador canónico con el
-- techo diario de 50 PDA es `daily_simulator_tracker` (plan 2.1), y duplicarlo
-- contaría dos veces la misma gloria.
CREATE TABLE IF NOT EXISTS dominion_awards (
    id             TEXT PRIMARY KEY,                     -- UUID v4
    clan_id        TEXT NOT NULL,                        -- Linaje acreditado (patrimonio del conjuro)
    user_id        TEXT NOT NULL,                        -- Adepto cuyo mérito acreditó la gloria
    action_type    TEXT NOT NULL
                   CHECK (action_type IN ('spellValidated', 'communityFavorite')),
    base_points    INTEGER NOT NULL DEFAULT 0 CHECK (base_points >= 0),    -- Valor base de la acción
    awarded_points INTEGER NOT NULL DEFAULT 0 CHECK (awarded_points > 0),  -- Gloria realmente acreditada
    has_synergy    INTEGER NOT NULL DEFAULT 0 CHECK (has_synergy IN (0, 1)), -- Bonificación de linaje (RF-03.4)
    source_id      TEXT NOT NULL,                        -- Conjuro validado o favorito que la motiva
    awarded_at     TEXT NOT NULL,                        -- Marca temporal UTC (ISO 8601)
    FOREIGN KEY (clan_id) REFERENCES clans (id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    UNIQUE (action_type, source_id)                      -- Cada mérito paga exactamente una vez
);

-- Elogios por conjuro (RF-03.3) y libro de gloria por adepto (RF-01.9).
CREATE INDEX IF NOT EXISTS idx_favorites_spell ON favorites (spell_id);
CREATE INDEX IF NOT EXISTS idx_dominion_awards_clan ON dominion_awards (clan_id, awarded_at);
CREATE INDEX IF NOT EXISTS idx_dominion_awards_member ON dominion_awards (user_id, clan_id);


-- =====================================================================
-- SISTEMA DE MODERACIÓN SOLEMNE EN DOS PASOS (SPEC-08, Tareas 1.1)
--
-- Las cuatro tablas del cónclave viven AQUÍ, en el DDL canónico, y no solo
-- en `sql/08_moderation_schema.sql`: un esquema repartido entre el DDL raíz
-- y una migración opcional dejaba sin tablas a toda base levantada solo con
-- este archivo —fue la fuga que SPEC-07 hubo de cerrar a posteriori—.
-- Aquel script subsiste como vía de ascensión para bases legadas y su DDL
-- ha de permanecer idéntico al de esta sección.
--
-- Dominio cerrado por la especificación: los cinco estados de RF-01.1, el
-- techo de tres firmas de RF-02.1, la huella SHA-256 del balance sellado
-- (Artículo II), la glosa de 250 caracteres de RF-02.2, la justificación
-- de 20 caracteres de RF-02.5 y RF-04.5 y los cuatro decretos soberanos
-- de RF-04. La enumeración de `revocation_reason` queda abierta a motivos
-- ceremoniales nuevos (RF-02.4, RF-03.4, RF-03.5, RF-01.3, RF-04.4).
-- =====================================================================

-- 1. Seguimiento del estado de moderación (relación 1:1 con `spells`).
CREATE TABLE IF NOT EXISTS spell_reviews (
    id                TEXT PRIMARY KEY,                     -- UUID v4 de la revisión
    spell_id          TEXT NOT NULL UNIQUE,                 -- Relación 1:1 con `spells`
    author_id         TEXT NOT NULL,                        -- Mago creador de la obra
    origin_clan_id    TEXT NULL,                            -- Clan patrimonial (o NULL si ermitaño)
    status            TEXT NOT NULL DEFAULT 'draft'
                      CHECK (status IN ('draft', 'experimental', 'validated', 'rejected', 'archived')),
    signatures_count  INTEGER NOT NULL DEFAULT 0
                      CHECK (signatures_count >= 0 AND signatures_count <= 3),
    math_fingerprint  TEXT NOT NULL
                      CHECK (length(math_fingerprint) = 64),  -- SHA-256 hex del balance sellado (Art. II)
    submitted_at      TEXT NULL,                            -- Entrada a la Torre de Moderación (ISO 8601 UTC)
    validated_at      TEXT NULL,                            -- Consagración solemne
    rejected_at       TEXT NULL,                            -- Objeción o caducidad
    reopened_at       TEXT NULL,                            -- Re-apertura como borrador (RF-01.4)
    archived_at       TEXT NULL,                            -- Degradación o destierro póstumo (RF-04.4)
    FOREIGN KEY (spell_id) REFERENCES spells (id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (origin_clan_id) REFERENCES clans (id) ON UPDATE CASCADE
);

-- 2. Firmas de Maestros y sus glosas litúrgicas (RF-02.1, RF-02.2).
CREATE TABLE IF NOT EXISTS master_signatures (
    id                 TEXT PRIMARY KEY,                    -- UUID v4 de la firma
    spell_id           TEXT NOT NULL,                       -- Conjuro avalado
    master_id          TEXT NOT NULL,                       -- Maestro firmante
    master_clan_id     TEXT NULL,                           -- Clan del firmante en el instante de firmar
    ceremonial_gloss   TEXT NULL
                       CHECK (ceremonial_gloss IS NULL OR length(ceremonial_gloss) <= 250),  -- Glosa de RF-02.2
    signed_at          TEXT NOT NULL,                       -- Marca temporal de la firma
    is_revoked         INTEGER NOT NULL DEFAULT 0
                       CHECK (is_revoked IN (0, 1)),        -- 1 si fue retractada o anulada de oficio
    revoked_at         TEXT NULL,                           -- Fecha de revocación
    revocation_reason  TEXT NULL,                           -- 'retracted' | 'clan_conflict_arisen' | 'rank_lost' | 'author_withdrawn' | 'sovereign_archive' | 'review_expired' (letargo de RF-01.6) | 'review_rejected' (veto de RF-02.6)
    FOREIGN KEY (spell_id) REFERENCES spells (id) ON DELETE CASCADE,
    FOREIGN KEY (master_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (master_clan_id) REFERENCES clans (id) ON UPDATE CASCADE
);

-- 3. Dictámenes de objeción fundamentada (RF-02.5, RF-02.6).
CREATE TABLE IF NOT EXISTS objection_verdicts (
    id                TEXT PRIMARY KEY,                     -- UUID v4 del dictamen
    spell_id          TEXT NOT NULL,                        -- Conjuro objetado
    master_id         TEXT NOT NULL,                        -- Maestro que emitió el veto
    objection_reason  TEXT NOT NULL
                      CHECK (length(objection_reason) >= 20),  -- Justificación obligatoria (RF-02.5)
    objected_at       TEXT NOT NULL,                        -- Marca temporal del dictamen
    FOREIGN KEY (spell_id) REFERENCES spells (id) ON DELETE CASCADE,
    FOREIGN KEY (master_id) REFERENCES users (id) ON DELETE CASCADE
);

-- 4. Decretos del Administrador Supremo (RF-04.1, RF-04.3 a RF-04.5).
CREATE TABLE IF NOT EXISTS sovereign_decrees (
    id                    TEXT PRIMARY KEY,                 -- UUID v4 del decreto
    spell_id              TEXT NOT NULL,                    -- Conjuro sobre el que se decretó
    admin_id              TEXT NOT NULL,                    -- Administrador Supremo actuante
    decree_type           TEXT NOT NULL
                          CHECK (decree_type IN ('sovereignValidation', 'rescueToExperimental', 'rescueToValidated', 'revokeAndArchive')),
    imperial_decree_text  TEXT NOT NULL
                          CHECK (length(imperial_decree_text) >= 20),  -- Edicto obligatorio (RF-04.5)
    decreed_at            TEXT NOT NULL,                    -- Marca temporal del decreto
    FOREIGN KEY (spell_id) REFERENCES spells (id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES users (id) ON DELETE CASCADE
);

-- La muralla de RF-02.1 (firma única activa), la cola del Atrio (RF-05.1),
-- el cupo anti-spam del autor (RF-01.2) y el recuento de firmas vivas.
CREATE UNIQUE INDEX IF NOT EXISTS idx_active_master_signature
    ON master_signatures (spell_id, master_id)
    WHERE is_revoked = 0;
CREATE INDEX IF NOT EXISTS idx_reviews_queue ON spell_reviews (status, submitted_at ASC);
CREATE INDEX IF NOT EXISTS idx_reviews_author_active ON spell_reviews (author_id, status);
CREATE INDEX IF NOT EXISTS idx_signatures_spell_active ON master_signatures (spell_id, is_revoked);
