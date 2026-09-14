-- ---------------------------------------------------------------------
-- 07_retire_domain_points.sql — Un solo contador de gloria por hermandad
--
-- Tarea 2.6 (TASKS-07): resuelve la divergencia que arrastraba el plano
-- desde la Tarea 1.1 — `domain_points` (SPEC-01) frente a `weekly_points` y
-- `historical_points` (SPEC-07) —.
--
-- DIAGNÓSTICO
--   `domain_points` medía EXACTAMENTE lo mismo que `weekly_points`: el
--   catálogo público de SPEC-01 rotula su valor como «Dominio semanal»
--   (`clansPreviewView.js`) y SPEC-01 declara fuera de alcance «el cómputo
--   semanal de puntos de Dominio (SPEC-07)». Es decir: era un tercer
--   contador del mismo concepto, y además SIN ESCRITOR — ninguno de los
--   servicios de SPEC-07 lo acreditaba, de modo que toda casa fundada tras
--   la Tarea 1.1 nacía con él a cero y solo podía divergir en silencio.
--
-- DECISIÓN RATIFICADA
--   Quedan DOS contadores canónicos, uno por concepto, y ninguno duplicado:
--     * `weekly_points`     → la contienda de la semana en curso (RF-03, RF-04.3).
--     * `historical_points` → la gloria perpetua de todos los tiempos (RF-04.3).
--   El contrato público de SPEC-01 CONSERVA su clave `domainPoints`, ahora
--   servida desde `weekly_points`: la interfaz no cambia, la autoridad sí.
--
-- Pasos:
--   [1] Plegar la gloria legada al contador semanal —solo donde el semanal
--       aún no acredite nada, para no inflar dos veces la misma gloria—.
--   [2] Retirar la columna condenada.
--
-- SÓLO PARA BASES LEGADAS: `database/schema.sql` ya declara hoy `clans` sin
-- `domain_points`, de modo que sobre una base nueva este script no tiene
-- nada que plegar ni que retirar (su paso [2] fallaría con «no such column»).
--
-- ORDEN DE APLICACIÓN SOBRE UNA BASE LEGADA (script SECUENCIAL, NO reejecutable):
--   database/schema.sql  →  database/seeds.sql  →
--   sql/07_clans_lineages_schema.sql  →
--   sql/07_membership_single_source.sql  →
--   sql/07_weekly_dominion_ledger.sql  →  ESTE script
--
-- Nota de portabilidad: `ALTER TABLE ... DROP COLUMN` está admitido por
-- SQLite 3.35+ y por MySQL/MariaDB 8+ (AGENTS.md §2.1). La columna no
-- participaba en índice, disparador ni clave foránea alguna, de modo que su
-- retirada no arrastra dependencias.
-- ---------------------------------------------------------------------

-- [1] La gloria legada de SPEC-01 se pliega al contador semanal canónico,
--     pero únicamente donde este aún no acredite nada: si una casa ya
--     contendió bajo SPEC-07, su marcador semanal manda y no se le suma un
--     vestigio que nadie mantenía.
UPDATE clans
   SET weekly_points = domain_points
 WHERE weekly_points = 0
   AND domain_points > 0;

-- [2] Retirada de la columna condenada: a partir de aquí NO EXISTE un
--     segundo contador semanal que pueda divergir del canónico.
ALTER TABLE clans DROP COLUMN domain_points;
