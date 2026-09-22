-- =====================================================================
-- 11_grimoire_collections.sql — La mesa del Tomo Personal
-- (SPEC-11, Tarea 1.1).
--
-- Erige `grimoire_collections`, la tabla NUEVA de la colección del
-- adepto (plan §1.3), separada a propósito de `favorites`: la colección
-- y el elogio son ritos distintos y cada invariante vive en su mesa
-- (RF-05.4). `favorites` queda como mesa de votos del Dominio,
-- INTOCADA; esta tabla no guarda gloria ni estado de homenaje — eso
-- se deriva en lectura (RF-04.0, estado embebido en los listados).
--
--   * `UNIQUE (user_id, spell_id)` — el sellado único (RF-01.3) como
--     INVARIANTE FÍSICO: una sola fila por hechizo y adepto en la vida
--     del tomo. Ninguna carrera de doble pestaña puede burlarlo
--     (caso límite 5): es la propia base la que vela.
--   * `idx_grimoire_collections_user_added` — el índice de latencia
--     (RNF-01): la apertura del tomo responde en menos de 100 ms,
--     con el mismo presupuesto que el RNF-02 de SPEC-06.
--   * Cascadas — la purga de cuenta arrastra el tomo (RF-05.3) y la
--     cascada hacia `spells` es defensa de profundidad: la retirada
--     del autor se materializa como `archived`, JAMÁS borrado físico
--     (RF-05.5), así que en operación regular jamás dispara.
--
-- Cubre: RF-05.4 (tabla nueva separada de favorites), RF-05.3 (purga
--        de cuenta), RNF-01 (índice de latencia), RNF-02 (PDO/SQL
--        nativo, sin procedimientos almacenados).
--
-- =====================================================================
-- NATURALEZA DE LA MEMORIA
-- =====================================================================
-- Las filas del tomo JAMÁS se borran por operación del sistema: solo
-- el adepto retira (Tarea 2.3) y solo la purga de cuenta arrasa
-- (RF-05.3). Un hechizo que cae del estado `validated` conserva su
-- fila con la marca solemne que derive el mapa de estados (RF-03.2);
-- la colección es memoria del adepto, no espejo del catálogo
-- (caso límite 2, hallazgos 12 y 21 de la QA).
-- =====================================================================

CREATE TABLE IF NOT EXISTS grimoire_collections (
    id         TEXT PRIMARY KEY,                            -- UUID v4
    user_id    TEXT NOT NULL REFERENCES users (id) ON DELETE CASCADE,  -- El tomo muere con su adepto (RF-05.3)
    spell_id   TEXT NOT NULL REFERENCES spells (id) ON DELETE CASCADE, -- Defensa de profundidad: la retirada es archived, jamás DELETE (RF-05.5)
    added_at   TEXT NOT NULL,                               -- Instante del sellado (ISO 8601 UTC)
    UNIQUE (user_id, spell_id)                              -- Un solo sellado por hechizo y adepto (RF-01.3, caso límite 5)
);

-- El índice de latencia del tomo (RNF-01): orden por adición, el más
-- reciente primero (RF-02.1), filtrable por afinidad (RF-02.3).
CREATE INDEX IF NOT EXISTS idx_grimoire_collections_user_added
    ON grimoire_collections (user_id, added_at DESC);
