-- ---------------------------------------------------------------------
-- 07_weekly_dominion_ledger.sql — Libro de Gloria del Dominio Semanal
--
-- Tarea 2.5 (TASKS-07): levanta las dos tablas sin las cuales dos requisitos
-- del canon quedaban sin garantía estructural:
--
--   1. `favorites` (RF-03.3, RNF-02) — la libreta de elogios del santuario.
--      RNF-02 exige que «una misma cuenta no registre más de un voto
--      computable sobre el mismo conjuro»: la unicidad (user_id, spell_id)
--      lo convierte en imposible por construcción, no en una promesa del
--      código de aplicación.
--
--   2. `dominion_awards` (RF-03.1, RF-03.3, RNF-01) — el libro de
--      acreditaciones de PDA que no tienen otro registro. Cada validación de
--      conjuro y cada elogio comunitario se asienta UNA sola vez: la
--      unicidad (action_type, source_id) garantiza que una gloria jamás se
--      cobre dos veces.
--
-- La práctica del simulador NO se asienta aquí: su acumulador canónico, con
-- el techo diario de 50 PDA y su reinicio a las 00:00:00 UTC, es
-- `daily_simulator_tracker` (plan 2.1). Duplicarlo contaría dos veces la
-- misma gloria.
--
-- SÓLO PARA BASES LEGADAS: `database/schema.sql` ya declara hoy ambas tablas,
-- de modo que en una base nueva este script no tiene nada que levantar.
--
-- ORDEN DE APLICACIÓN SOBRE UNA BASE LEGADA (secuencial):
--   database/schema.sql  →  database/seeds.sql  →
--   sql/07_clans_lineages_schema.sql  →
--   sql/07_membership_single_source.sql  →
--   sql/07_weekly_dominion_ledger.sql
--
-- Nota de portabilidad: ambas sentencias exigen que `users` y `spells` ya
-- existan, por lo que este script se aplica SIEMPRE al final del ascenso —
-- ni SQLite ni MySQL/MariaDB admiten una clave foránea hacia una tabla que
-- aún no ha nacido (AGENTS.md §2.1). El script es reejecutable sin daño.
-- ---------------------------------------------------------------------

-- Libreta de Favoritos del santuario [RF-03.3, RNF-02].
--
-- El linaje beneficiario no se duplica: se deriva del `spells.clan_id` del
-- conjuro, que es patrimonio inviolable de su clan (RF-05.1) y por tanto el
-- único destino legítimo de los cinco PDA. Así el elogio jamás puede
-- desviarse hacia una casa ajena a la que forjó el conjuro.
CREATE TABLE IF NOT EXISTS favorites (
    id         TEXT PRIMARY KEY,                         -- UUID v4
    user_id    TEXT NOT NULL,                            -- Mago que elogia
    spell_id   TEXT NOT NULL,                            -- Conjuro sellado elogiado
    created_at TEXT NOT NULL,                            -- Instante del elogio (ISO 8601 UTC)
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (spell_id) REFERENCES spells (id) ON DELETE CASCADE,
    UNIQUE (user_id, spell_id)                           -- Un solo voto computable (RNF-02)
);

-- Libro de acreditaciones de PDA [RF-03.1, RF-03.3, RNF-01].
--
-- `source_id` porta el conjuro validado o la fila de favorito que motivó la
-- gloria; juntos con `action_type` forman la clave de unicidad que impide el
-- doble pago. `awarded_points` es siempre estrictamente positivo: una
-- denegación (techo diario colmado, elogio duplicado) no deja asiento.
CREATE TABLE IF NOT EXISTS dominion_awards (
    id             TEXT PRIMARY KEY,                     -- UUID v4
    clan_id        TEXT NOT NULL,                        -- Linaje acreditado
    user_id        TEXT NOT NULL,                        -- Adepto cuyo mérito acreditó la gloria
    action_type    TEXT NOT NULL
                   CHECK (action_type IN ('spellValidated', 'communityFavorite')),
    base_points    INTEGER NOT NULL DEFAULT 0 CHECK (base_points >= 0),
    awarded_points INTEGER NOT NULL DEFAULT 0 CHECK (awarded_points > 0),
    has_synergy    INTEGER NOT NULL DEFAULT 0 CHECK (has_synergy IN (0, 1)),
    source_id      TEXT NOT NULL,                        -- Conjuro validado o favorito
    awarded_at     TEXT NOT NULL,                        -- Marca temporal UTC (ISO 8601)
    FOREIGN KEY (clan_id) REFERENCES clans (id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    UNIQUE (action_type, source_id)                      -- Cada mérito paga exactamente una vez
);

-- Elogios por conjuro (RF-03.3) y libro de gloria por adepto (RF-01.9).
CREATE INDEX IF NOT EXISTS idx_favorites_spell ON favorites (spell_id);
CREATE INDEX IF NOT EXISTS idx_dominion_awards_clan ON dominion_awards (clan_id, awarded_at);
CREATE INDEX IF NOT EXISTS idx_dominion_awards_member ON dominion_awards (user_id, clan_id);
