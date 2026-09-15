-- ---------------------------------------------------------------------
-- 08_spell_status_single_source.sql — Reconciliación del ciclo de vida del
-- conjuro: un solo contador de estado
--
-- Tarea 1.5 (TASKS-08): elimina la divergencia que convivía en el plano desde
-- la Tarea 1.1 de SPEC-08 — `spells.status` (SPEC-04, TRES estados) frente a
-- `spell_reviews.status` (SPEC-08, CINCO estados) — y su gemela en el contador
-- de firmas, `spells.signatures_count` frente a
-- `spell_reviews.signatures_count`.
--
-- DIAGNÓSTICO
--   Ninguna de las dos columnas de `spells` mentía: ambas eran ciertas y
--   ambas podían divergir. `spell_reviews` nace al enviar la obra a
--   deliberación, de modo que un conjuro ya consagrado antes de SPEC-08 tenía
--   estado sin expediente, y cualquier transición futura escribiría el
--   expediente dejando al espejo atrás. Con dos contadores del mismo concepto,
--   el Atrio podía exhibir 2/3 mientras la ficha del autor decía 0/3.
--
-- DECISIÓN RATIFICADA
--   `spell_reviews` es la ÚNICA autoridad del ciclo de vida (los cinco
--   estados de RF-01.1, el contador de firmas, las marcas de cada transición y
--   la huella del balance sellado). En consecuencia:
--
--     * `spells.status` pasa a ser ESPEJO denormalizado de la autoridad, con
--       los CINCO estados canónicos —se ensancha su CHECK—, mantenido en
--       exclusiva por `SpellReviewRepository` dentro de la misma transacción
--       que el expediente. Un conjuro que nunca ha entrado a moderación no
--       tiene expediente y el espejo sostiene su estado embrionario `draft`.
--     * `spells.signatures_count` es espejo del contador del expediente, con
--       el mismo ÚNICO escritor.
--     * `spells.validation_signatures_count` NO compite: es el vestigio
--       congelado del aforo génesis de TASKS-04 —jamás escrito tras las
--       semillas— y forma parte del contrato JSON público de SPEC-04; su
--       retirada es decisión de aquella especificación, no de ésta.
--
-- Pasos:
--   [1] Sembrar el expediente desde el espejo legado: todo conjuro sin
--       revisión recibe la suya, derivada de su fila.
--   [2] ENSANCHAR el CHECK de `spells.status` a los cinco estados canónicos
--       —reconstrucción de la tabla, única vía en SQLite—.
--   [3] Reconciliar el espejo: manda `spell_reviews`.
--
-- ORDEN DE APLICACIÓN (script SECUENCIAL; sus tres pasos son reejecutables por
-- construcción, salvo la reconstrucción del paso [2], que exige que `spells`
-- conserve el contrato de columnas de `database/schema.sql`):
--   database/schema.sql  →  database/seeds.sql  →  sql/08_moderation_schema.sql
--   →  ESTE script
--
-- SÓLO PARA BASES LEGADAS: `database/schema.sql` ya declara hoy `spells` con
-- el CHECK ancho y con ambos espejos documentados, de modo que sobre una base
-- nueva este script no tiene nada que reconciliar (su paso [2] reconstruiría
-- la tabla para dejarla idéntica).
--
-- Nota de portabilidad: la ampliación de un CHECK no admite `ALTER TABLE` en
-- SQLite (3.35+ sí admite ADD/DROP/RENAME COLUMN, pero no tocar restricciones),
-- así que el paso [2] sigue el procedimiento canónico de reconstrucción con
-- `PRAGMA foreign_keys = OFF`. En MySQL/MariaDB 8+ el equivalente es
-- `ALTER TABLE spells DROP CHECK spells_chk_status;` seguido de
-- `ALTER TABLE spells ADD CONSTRAINT spells_chk_status CHECK (...);` sobre la
-- MISMA tabla, sin reconstrucción; las claves foráneas de `master_signatures`,
-- `spell_reviews`, `sovereign_decrees` y `favorites` hacia `spells(id)` se
-- conservan intactas porque la tabla nunca desaparece del catálogo.
-- ---------------------------------------------------------------------

-- =====================================================================
-- [1] Sembrar el expediente desde el espejo legado.
--     Todo conjuro sin revisión recibe la suya: su estado y su contador tal
--     como constan, su huella de balance, y las marcas temporales que el
--     espejo legado permite deducir —entrada a la Torre para cuanto no es
--     borrador; consagración para los validados—. Idempotente: la guarda
--     NOT EXISTS respeta el expediente que ya exista.
-- =====================================================================
INSERT INTO spell_reviews (
    id, spell_id, author_id, origin_clan_id, status,
    signatures_count, math_fingerprint,
    submitted_at, validated_at, rejected_at, reopened_at, archived_at
)
SELECT 'rev_legacy_' || s.id,
       s.id,
       s.author_id,
       s.clan_id,
       s.status,
       s.signatures_count,
       s.math_fingerprint,
       CASE WHEN s.status = 'draft' THEN NULL ELSE s.updated_at END,
       CASE WHEN s.status = 'validated' THEN COALESCE(s.validated_at, s.updated_at) ELSE NULL END,
       NULL,
       NULL,
       NULL
  FROM spells s
 WHERE NOT EXISTS (
       SELECT 1 FROM spell_reviews r WHERE r.spell_id = s.id
 );

-- =====================================================================
-- [2] Ensanchar el CHECK de `spells.status` a los cinco estados de RF-01.1.
--     La reconstrucción copia la tabla entera —columnas, restricciones y
--     claves foráneas propias— con el único cambio del catálogo de estados;
--     después se recrean sus seis índices. El contrato de columnas es
--     IDÉNTICO al de `database/schema.sql`: si una base legada hubiera
--     recibido columnas ajenas, esta reconstrucción las perdería, y por eso
--     el paso se declara exclusivo de las bases de esta generación.
-- =====================================================================
PRAGMA foreign_keys = OFF;

BEGIN;

DROP TABLE IF EXISTS spells_rebuilt;

CREATE TABLE spells_rebuilt (
    id                    TEXT PRIMARY KEY,
    slug                  TEXT NOT NULL UNIQUE,
    name                  TEXT NOT NULL,
    author_id             TEXT NOT NULL REFERENCES users (id),
    magic_school          TEXT NOT NULL REFERENCES magic_schools (slug),
    elemental_affinity    TEXT NOT NULL DEFAULT 'none',
    casting_time          TEXT NOT NULL DEFAULT 'action',
    mana_cost             INTEGER NOT NULL CHECK (mana_cost >= 0 AND mana_cost <= 200),
    circle                INTEGER NOT NULL DEFAULT 1 CHECK (circle >= 1 AND circle <= 5),
    math_fingerprint      TEXT NOT NULL DEFAULT '' CHECK (length(math_fingerprint) = 64),
    clan_id               TEXT NOT NULL REFERENCES clans (id),
    summary               TEXT NOT NULL,
    description           TEXT NOT NULL DEFAULT '',
    components_verbal     TEXT NOT NULL DEFAULT '',
    components_somatic    TEXT NOT NULL DEFAULT '',
    components_material   TEXT NOT NULL DEFAULT '',
    damage                INTEGER NOT NULL DEFAULT 0 CHECK (damage >= 0),
    healing               INTEGER NOT NULL DEFAULT 0 CHECK (healing >= 0),
    barrier               INTEGER NOT NULL DEFAULT 0 CHECK (barrier >= 0),
    crowd_control_type    TEXT NOT NULL DEFAULT 'none'
                          CHECK (crowd_control_type IN ('none', 'slow', 'root', 'stun')),
    range_type            TEXT NOT NULL DEFAULT 'touch'
                          CHECK (range_type IN ('touch', 'short', 'medium', 'long')),
    area_type             TEXT NOT NULL DEFAULT 'singleTarget'
                          CHECK (area_type IN ('singleTarget', 'cone', 'line', 'sphere')),
    duration_type         TEXT NOT NULL DEFAULT 'instant'
                          CHECK (duration_type IN ('instant', 'concentration', 'sustained')),
    has_verbal            INTEGER NOT NULL DEFAULT 0 CHECK (has_verbal IN (0, 1)),
    has_somatic           INTEGER NOT NULL DEFAULT 0 CHECK (has_somatic IN (0, 1)),
    has_material          INTEGER NOT NULL DEFAULT 0 CHECK (has_material IN (0, 1)),
    status                TEXT NOT NULL DEFAULT 'draft'
                          CHECK (status IN ('draft', 'experimental', 'validated', 'rejected', 'archived')),
    validation_signatures_count INTEGER NOT NULL DEFAULT 0 CHECK (validation_signatures_count >= 0),
    signatures_count      INTEGER NOT NULL DEFAULT 0 CHECK (signatures_count >= 0 AND signatures_count <= 3),
    is_genesis_sample     INTEGER NOT NULL DEFAULT 0 CHECK (is_genesis_sample IN (0, 1)),
    created_at            TEXT NOT NULL,
    updated_at            TEXT NOT NULL,
    validated_at          TEXT
);

INSERT INTO spells_rebuilt (
    id, slug, name, author_id, magic_school, elemental_affinity, casting_time,
    mana_cost, circle, math_fingerprint, clan_id,
    summary, description, components_verbal, components_somatic, components_material,
    damage, healing, barrier, crowd_control_type, range_type, area_type, duration_type,
    has_verbal, has_somatic, has_material,
    status, validation_signatures_count, signatures_count, is_genesis_sample,
    created_at, updated_at, validated_at
)
SELECT id, slug, name, author_id, magic_school, elemental_affinity, casting_time,
       mana_cost, circle, math_fingerprint, clan_id,
       summary, description, components_verbal, components_somatic, components_material,
       damage, healing, barrier, crowd_control_type, range_type, area_type, duration_type,
       has_verbal, has_somatic, has_material,
       status, validation_signatures_count, signatures_count, is_genesis_sample,
       created_at, updated_at, validated_at
  FROM spells;

DROP TABLE spells;

ALTER TABLE spells_rebuilt RENAME TO spells;

CREATE INDEX IF NOT EXISTS idx_spells_slug ON spells (slug);
CREATE INDEX IF NOT EXISTS idx_spells_magic_school ON spells (magic_school);
CREATE INDEX IF NOT EXISTS idx_spell_author_status ON spells (author_id, status);
CREATE INDEX IF NOT EXISTS idx_spell_clan_validated ON spells (clan_id, status);
CREATE INDEX IF NOT EXISTS idx_spells_status_validated_at ON spells (status, validated_at);
CREATE INDEX IF NOT EXISTS idx_spells_clan_id ON spells (clan_id);

COMMIT;

PRAGMA foreign_keys = ON;
PRAGMA foreign_key_check;

-- =====================================================================
-- [3] Reconciliación final: la autoridad (`spell_reviews`) sobrescribe el
--     espejo. Un conjuro sin expediente —un borrador que nunca fue
--     elevado— conserva su estado embrionario y no se toca.
-- =====================================================================
UPDATE spells
   SET status = (
           SELECT r.status FROM spell_reviews r WHERE r.spell_id = spells.id
       ),
       signatures_count = (
           SELECT r.signatures_count FROM spell_reviews r WHERE r.spell_id = spells.id
       )
 WHERE EXISTS (
       SELECT 1 FROM spell_reviews r WHERE r.spell_id = spells.id
   );

-- =====================================================================
-- VERIFICACIÓN (consulta de guardia, no modifica nada; debe devolver CERO
-- filas). Es el aserto que cualquier auditor puede repetir a mano:
--
--   SELECT r.spell_id, r.status AS authority_status, s.status AS mirror_status,
--          r.signatures_count AS authority_signatures,
--          s.signatures_count AS mirror_signatures
--     FROM spell_reviews r
--     JOIN spells s ON s.id = r.spell_id
--    WHERE r.status <> s.status
--       OR r.signatures_count <> s.signatures_count;
-- =====================================================================
