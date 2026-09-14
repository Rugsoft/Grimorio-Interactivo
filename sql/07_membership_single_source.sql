-- ---------------------------------------------------------------------
-- 07_membership_single_source.sql — Reconciliación de la afiliación de clan
--
-- Tarea 2.3 (TASKS-07): elimina la triple fuente de verdad sobre «el clan
-- actual de un mago» que convivía en el plano:
--
--   1. `users.clan_id`  — columna NOT NULL escrita por AuthService.
--   2. `clan_history`   — historial que NINGUNA clase de src/ escribía jamás.
--   3. `clan_members`   — historial de membresía de SPEC-07, con escritor real.
--
-- Decisión ratificada: **`clan_members` es la ÚNICA autoridad** de la
-- afiliación (RF-01.1, RF-01.8, Artículo III). En consecuencia:
--
--   * `users.clan_id` pasa a ser ANULABLE y se redefine como ESPEJO
--     denormalizado de la membresía ACTIVA, mantenido en exclusiva por
--     ClanMemberRepository dentro de la misma transacción que la membresía.
--     NULL significa «sin linaje», estado imprescindible para fundar un clan
--     sin pertenecer a ninguno (RF-01.2).
--   * `clan_history` queda retirada del camino crítico: su contenido se
--     importa aquí a `clan_members` para no perder memoria histórica.
--
-- Pasos:
--   [1] Sembrar `clan_members` desde el espejo legado `users.clan_id`.
--   [2] Importar el historial de `clan_history` a `clan_members`.
--   [3] Relajar el NOT NULL de `users.clan_id` (columna ANULABLE).
--   [4] Reconciliar el espejo: manda `clan_members`.
--
-- SÓLO PARA BASES LEGADAS: `database/schema.sql` ya declara hoy
-- `users.clan_id` ANULABLE, de modo que en una base nueva este script no tiene
-- nada que reconciliar (y su paso [3] fallaría con «duplicate column name»).
--
-- ORDEN DE APLICACIÓN SOBRE UNA BASE LEGADA (script SECUENCIAL, NO reejecutable):
--   database/schema.sql  →  database/seeds.sql  →
--   sql/07_clans_lineages_schema.sql  →  sql/07_membership_single_source.sql
--
-- Nota de portabilidad: el paso [3] usa ALTER TABLE ADD/DROP/RENAME COLUMN,
-- admitidos por SQLite 3.35+ y por MySQL/MariaDB 8+ (AGENTS.md §2.1). El
-- procedimiento evita reconstruir la tabla entera y por tanto conserva
-- intactas las claves foráneas de `spells`, `clan_members` y `clans` hacia
-- `users(id)`. Efecto colateral inocuo: la columna `clan_id` pasa a ser la
-- última de la tabla (todo el código la direcciona por nombre).
-- ---------------------------------------------------------------------

-- [1] Sembrar la membresía desde el espejo legado.
--     Rol `patriarch` para quien ya figura como Patriarca del clan; `adept`
--     para el resto. Se respeta el índice único parcial `idx_active_member`
--     (un solo linaje activo por mago) mediante la guarda NOT EXISTS.
INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at, convalescence_expires_at)
SELECT 'mem_legacy_' || u.id,
       u.clan_id,
       u.id,
       CASE WHEN c.patriarch_id IS NOT NULL AND c.patriarch_id = u.id
            THEN 'patriarch' ELSE 'adept' END,
       u.created_at,
       NULL,
       NULL
  FROM users u
  LEFT JOIN clans c ON c.id = u.clan_id
 WHERE u.clan_id IS NOT NULL
   AND NOT EXISTS (
       SELECT 1 FROM clan_members m
        WHERE m.user_id = u.id AND m.left_at IS NULL
   );

-- [2] Importar el historial legado para que la ventana ética de 30 días
--     (RF-01.8) no se pierda con la retirada de `clan_history`. Se omiten
--     las filas ya presentes y las afiliaciones vigentes duplicadas.
INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at, convalescence_expires_at)
SELECT 'mem_history_' || h.id,
       h.clan_id,
       h.user_id,
       'adept',
       h.joined_at,
       h.left_at,
       NULL
  FROM clan_history h
 WHERE NOT EXISTS (
       SELECT 1 FROM clan_members m
        WHERE m.user_id = h.user_id
          AND m.clan_id = h.clan_id
          AND m.joined_at = h.joined_at
   )
   AND (
       h.left_at IS NOT NULL
       OR NOT EXISTS (
           SELECT 1 FROM clan_members a
            WHERE a.user_id = h.user_id AND a.left_at IS NULL
       )
   );

-- [3] Relajar el NOT NULL de `users.clan_id` conservando sus valores.
--     Se retira el índice antes de soltar la columna (SQLite lo exige) y se
--     reconstruye después con el mismo nombre.
ALTER TABLE users ADD COLUMN clan_id_mirror TEXT REFERENCES clans (id);
UPDATE users SET clan_id_mirror = clan_id;
DROP INDEX IF EXISTS idx_users_clan_id;
ALTER TABLE users DROP COLUMN clan_id;
ALTER TABLE users RENAME COLUMN clan_id_mirror TO clan_id;
CREATE INDEX IF NOT EXISTS idx_users_clan_id ON users (clan_id);

-- [4] Reconciliación final: la autoridad (`clan_members`) sobrescribe el
--     espejo. Quien no tenga membresía activa queda con `clan_id` NULL.
UPDATE users
   SET clan_id = (
       SELECT m.clan_id
         FROM clan_members m
        WHERE m.user_id = users.id
          AND m.left_at IS NULL
   );
