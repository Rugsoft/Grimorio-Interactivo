-- =====================================================================
-- 08_moderation_schema.sql — Migración DDL del Sistema de Moderación
-- Solemne en Dos Pasos y Consecución de Firmas (SPEC-08).
--
-- Tarea 1.1 (TASKS-08): materializa el esquema relacional de la sección
-- 2.1 del PLAN-08.
--
-- Cubre: RF-01.1, RF-02.1, RF-02.4, RF-02.5, RF-04.4, RF-04.5,
--        RNF-02, RNF-05, Artículo II (huella del maná), Artículo III
--        (memoria de hermandad para el veto ético) y Artículo V
--        (Dualidad Lingüística: snake_case técnico).
--
-- =====================================================================
-- DOS VIDAS DEL MISMO DDL (canónico + ascensión)
-- =====================================================================
-- `database/schema.sql` es el DDL canónico del santuario: toda base nueva
-- —los arneses, el sembrador local y cualquier instalación— se levanta con
-- él. Sus cuatro tablas de moderación se declaran allí de forma directa.
--
-- ESTE script subsiste como VÍA DE ASCENSIÓN para bases construidas antes
-- de SPEC-08 y como copia de consulta para el linaje de migraciones `sql/`.
-- Es IDEMPOTENTE por completo (`CREATE TABLE IF NOT EXISTS`): a diferencia
-- de la migración de SPEC-07 —que ampliaba `clans` con `ALTER TABLE` y por
-- ello solo podía aplicarse una vez—, las cuatro tablas de SPEC-08 nacen
-- aquí enteras, de modo que aplicarlo sobre una base ya migrada no hace
-- nada y aplicarlo sobre una base nueva la deja completa.
--
-- Lección de SPEC-07 que este guion respeta: un esquema repartido entre el
-- DDL raíz y una migración opcional dejaba sin tablas a toda base levantada
-- solo con `database/schema.sql`. Por eso el DDL vive en los dos sitios y el
-- arnés de la tarea comprueba que ambos no divergen.
--
-- ---------------------------------------------------------------------
-- ORDEN DE APLICACIÓN (secuencial, un solo paso)
-- ---------------------------------------------------------------------
--   1) database/schema.sql  → esquema canónico completo (ya incluye estas
--                             cuatro tablas; este script no es necesario).
--   2) database/seeds.sql   → linaje fundacional neutro y su custodio.
--   3) ESTE script          → solo sobre bases legadas a SPEC-08.
--
-- ---------------------------------------------------------------------
-- COMPATIBILIDAD DE DIALECTO
-- ---------------------------------------------------------------------
--   * SQLite 3.35+ (índice único parcial: 3.8+) y MySQL 8 / MariaDB 10.4+.
--   * `TEXT` equivale a `VARCHAR(n)` e `INTEGER` a `INT` en MySQL: se usa
--     `TEXT` de forma consistente con database/schema.sql (SQLite es de
--     tipado dinámico y no aplica longitudes). El `VARCHAR(n)` del plan
--     viaja como `TEXT` y su acotación se impone con CHECK, que sí se
--     aplica en ambos motores.
--   * Los BOOLEAN viajan como INTEGER 0/1 con CHECK, igual que
--     `dominion_awards.has_synergy`.
--   * Los TIMESTAMP viajan como TEXT en formato ISO 8601 UTC: ordenan
--     lexicográficamente igual que cronológicamente y evitan el redondeo a
--     segundos de las columnas con afinidad NUMERIC.
--   * MySQL exige InnoDB para aplicar claves foráneas; los índices son de
--     la misma forma en ambos motores.
--
-- ---------------------------------------------------------------------
-- POR QUÉ RESTRICCIONES ESTRICTAS Y NO SOLO EL CONTRATO DESNUDO
-- ---------------------------------------------------------------------
-- El plan 2.1 declara tipos y claves; la tarea exige «tipos estrictos». Se
-- añaden CHECK sobre el dominio cerrado por la especificación —los cinco
-- estados de RF-01.1, el contador 0..3 de RF-02.1, la huella SHA-256 de 64
-- caracteres que ya vigila `spells.math_fingerprint`, la glosa de 250 de
-- RF-02.2, la justificación de 20 de RF-02.5 y RF-04.5 y los cuatro decretos
-- soberanos de RF-04— para que la base sea la última muralla de la
-- Constitución: la Ley Universal del Maná no admite huellas truncadas, ni
-- un conjuro admite una cuarta firma, ni un edicto imperial viaja sin
-- justificación. La enumeración de `revocation_reason` NO se cierra: la
-- especificación nombra retractación (RF-02.4), conflicto sobrevenido
-- (RF-03.4), pérdida de rango (RF-03.5), retirada del autor (RF-01.3) y
-- degradación póstuma (RF-04.4), y deja la puerta abierta a motivos
-- ceremoniales nuevos.
--
-- Constitución:
--   - Artículo I (Dogma Vanilla): SQL nativo, sin ORM ni migradores.
--   - Artículo II (Ley Universal del Maná): `math_fingerprint` inmutable de
--     64 caracteres es la huella del balance sellado al entrar en revisión.
--   - Artículo III (Ética de Linajes): `master_signatures.master_clan_id`
--     conserva el clan del firmante en el instante de firmar, memoria que
--     sostiene el veto de 30 días y la anulación de firmas sobrevenidas.
--   - Artículo V (Dualidad): identificadores en inglés snake_case;
--     comentarios y narrativa en noble castellano.
-- =====================================================================


-- =====================================================================
-- 1. TABLA `spell_reviews` — Seguimiento del estado de moderación
--    (RF-01.1, RF-01.2, RF-01.4, RF-01.6, RF-05.1)
--
--    Relación 1:1 con `spells` (UNIQUE sobre spell_id): cada obra tiene UNA
--    sola revisión viva, de modo que el cupo de tres conjuros en
--    deliberación (RF-01.2) y la cola del Atrio (RF-05.1) se resuelven sin
--    ambigüedad.
-- =====================================================================
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

-- =====================================================================
-- 2. TABLA `master_signatures` — Firmas de Maestros y sus glosas
--    (RF-02.1, RF-02.2, RF-02.4, RF-03.4, RF-03.5)
--
--    El índice único parcial de más abajo impone la unicidad de la firma
--    ACTIVA: un mismo Maestro no puede avalar dos veces la misma obra,
--    pero conserva su historial de firmas retractadas (RF-02.4) y anuladas
--    por conflicto ético (RF-03.4) o pérdida de rango (RF-03.5).
-- =====================================================================
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

-- =====================================================================
-- 3. TABLA `objection_verdicts` — Dictámenes de Objeción Fundamentada
--    (RF-02.5, RF-02.6, RF-06.2)
--
--    El veto de calidad exige justificación solemne en castellano de al
--    menos veinte caracteres: la base lo impone, no la buena voluntad del
--    llamador.
-- =====================================================================
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

-- =====================================================================
-- 4. TABLA `sovereign_decrees` — Decretos del Administrador Supremo
--    (RF-04.1, RF-04.3, RF-04.4, RF-04.5)
--
--    Cada intervención unilateral deja aquí su edicto imperial: la potestad
--    suprema es auditable o no es suprema.
-- =====================================================================
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

-- =====================================================================
-- 5. ÍNDICES — Cola de deliberación, cupo del autor y unicidad de firma
-- =====================================================================

-- La muralla de RF-02.1: un Maestro, una firma ACTIVA por conjuro. Parcial,
-- para que retractarse (RF-02.4) no le cierre la puerta a volver a avalar.
CREATE UNIQUE INDEX IF NOT EXISTS idx_active_master_signature
    ON master_signatures (spell_id, master_id)
    WHERE is_revoked = 0;

-- La cola del Atrio: las obras más antiguas en deliberación se examinan
-- primero (RF-05.1) y la caducidad de 90 días (RF-01.6) se resuelve con ella.
CREATE INDEX IF NOT EXISTS idx_reviews_queue ON spell_reviews (status, submitted_at ASC);

-- El cupo anti-spam del autor (RF-01.2): tres conjuros en deliberación.
CREATE INDEX IF NOT EXISTS idx_reviews_author_active ON spell_reviews (author_id, status);

-- El recuento de firmas vivas de una obra (RF-02.1, RF-02.3).
CREATE INDEX IF NOT EXISTS idx_signatures_spell_active ON master_signatures (spell_id, is_revoked);
