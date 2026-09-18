-- =====================================================================
-- 09_lineage_oath.sql — Columna del Juramento de Linaje (SPEC-09).
--
-- Tarea 1.1 (TASKS-09): añade a `users` el vínculo de identidad arcana
-- `lineage` (RF-01.5, RF-04.1) y ejecuta el respaldo de legado que exime
-- del juramento a los adeptos con clan histórico (caso límite 7).
--
-- Cubre: RF-01.5 (despliegue/legado), RF-04.1 (linaje y clan son vínculos
--        independientes), Artículo V (Dualidad Lingüística: snake_case
--        técnico, narrativa en noble castellano).
--
-- =====================================================================
-- NATURALEZA DE LA COLUMNA
-- =====================================================================
-- `users.lineage` es ANULABLE a propósito: NULL significa «peregrino sin
-- linaje», la fase de vida que la ceremonia bloqueante del primer acceso
-- conduce al juramento (RF-01.2, RF-01.3). El vínculo es PERPETUO:
-- ninguna operación del sistema lo muta una vez sellado (RF-03.4) y
-- muere con la cuenta purgada (RF-03.4: la re-creación nace peregrina,
-- sin herencia alguna).
--
-- El linaje jurado y el clan son VÍNCULOS INDEPENDIENTES (RF-04.1):
-- `clan_members` sigue siendo la AUTORIDAD de la membresía (SPEC-07) y
-- el espejo `users.clan_id` no se toca en esta migración. El respaldo de
-- legado los alinea UNA SOLA VEZ; a partir de ahí viven separados.
--
-- =====================================================================
-- CONTRATO DE APLICACIÓN IDEMPOTENTE
-- =====================================================================
-- SQLite NO admite `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`: un ALTER
-- re-aplicado falla con «duplicate column name: lineage», tal como ya
-- documenta la advertencia de re-ejecución de sql/07_clans_lineages_
-- schema.sql. Este guion adopta el contrato de aplicación VANILLA que
-- la restricción impone y que su arnés ejercita:
--
--   * El aplicador ejecuta el guion SENTENCIA A SENTENCIA (PDO exec por
--     trozo, sin librerías). Un ALTER rechazado con «duplicate column»
--     NO es un error: es la señal de que la base ya porta la columna y
--     el guion se aplica por segunda vez. Cualquier otro fallo sí es un
--     error de aplicación.
--   * El RESPALDO DE LEGADO es idempotente POR DISEÑO: su guardia
--     `WHERE lineage IS NULL AND clan_id IS NOT NULL` solo alcanza a
--     peregrinos con clan histórico; tras la primera aplicación no queda
--     ninguno y una segunda pasada no muta fila alguna.
--
-- Así, ejecutar el guion dos veces consecutivas no produce error ni
-- duplica el respaldo, y una base legada queda en el estado exacto de
-- una base nueva levantada con `database/schema.sql` (Tarea 1.2).
--
-- =====================================================================
-- ORDEN DE APLICACIÓN (secuencial, un solo paso)
-- =====================================================================
--   1) database/schema.sql  → esquema canónico completo (las bases nuevas
--                             nacen ya con la columna —Tarea 1.2—; este
--                             script no es necesario).
--   2) database/seeds.sql   → linajes, clanes y custodio fundacional.
--   3) ESTE script          → solo sobre bases legadas a SPEC-09: añade
--                             la columna si falta y ejecuta el respaldo.
--
-- Verificación: php scratch/test_lineage_migration.php
-- (Tarea 1.1 de TASKS-09; la coherencia guion↔esquema se ratifica en la
-- Tarea 1.2, que inscribirá la columna en el DDL maestro.)
--
-- =====================================================================
-- COMPATIBILIDAD DE DIALECTO
-- =====================================================================
--   * SQLite 3.35+ (CHECK en ALTER y pragma_table_info son antiguos).
--   * MySQL 8 / MariaDB 10.4+: la columna resultante es idéntica; en
--     MariaDB basta `ADD COLUMN IF NOT EXISTS` y en MySQL 8 se consulta
--     antes INFORMATION_SCHEMA.COLUMNS. El CHECK del canon viaja igual
--     en ambos motores y `lineage` viaja como TEXT, consistente con
--     `clans.lineage_type`.
-- =====================================================================

PRAGMA foreign_keys = OFF;

-- ---------------------------------------------------------------------
-- 1. EL ALTER DEL JURAMENTO (RF-01.5)
-- ---------------------------------------------------------------------
-- Añade la columna con el CHECK del canon cerrado de los OCHO Linajes
-- Canónicos de SPEC-07 (RF-02.1): la base es la última muralla del canon
-- inmutable (SPEC-09, exclusión 5). SQLite admite el CHECK en el ALTER,
-- como ya lo usó la ascensión de `clans` en sql/07_clans_lineages_
-- schema.sql. No porta REFERENCES a propósito: el canon es de servicio
-- (inmutable, exclusión 5) y ninguna clave foránea lo gobierna.
-- ---------------------------------------------------------------------
ALTER TABLE users ADD COLUMN lineage TEXT NULL
    CHECK (lineage IN (
        'primordialFlame', 'celestialTides', 'eternalTempest', 'worldRoots',
        'dawnWinds', 'solarCrown', 'abyssalShadows', 'aetherWeavers'
    ));

-- ---------------------------------------------------------------------
-- 2. RESPALDO DE LEGADO (caso límite 7 — SPEC-09)
-- ---------------------------------------------------------------------
-- El adepto con clan histórico hereda el `lineage_type` de su clan y
-- queda EXENTO del juramento para siempre. La guardia solo alcanza a
-- peregrinos con clan: una segunda aplicación no muta fila alguna. Los
-- que quedan con `lineage IS NULL` (sin clan histórico) devienen
-- peregrinos: la ceremonia los espera en su próximo inicio de sesión
-- (RF-01.5).
-- ---------------------------------------------------------------------
UPDATE users
SET lineage = (
      SELECT c.lineage_type FROM clans c WHERE c.id = users.clan_id
    )
WHERE lineage IS NULL AND clan_id IS NOT NULL;

PRAGMA foreign_keys = ON;
