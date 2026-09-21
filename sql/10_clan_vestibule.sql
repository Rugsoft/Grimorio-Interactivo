-- =====================================================================
-- 10_clan_vestibule.sql — Clausura por casa y veredicto contemplado
-- (SPEC-10, Tarea 1.1).
--
-- Dota a `clan_applications` de los dos pilares de persistencia que la
-- Ceremonia de Adhesión exige (plan §1.3):
--
--   * `uq_clan_application_house` — el índice UNIQUE sobre
--     `(user_id, clan_id)` que convierte la clausura perpetua por casa
--     (RF-03.1) en un INVARIANTE FÍSICO: una sola petición por casa en
--     la vida de la cuenta, con el estado terminal que sea. Ninguna
--     vía —API directa incluida— puede burlarlo, porque es la propia
--     base la que lo vela (plan §5.2).
--   * `verdict_seen_at` — el instante (ISO 8601 UTC) en que el
--     postulante contempló el veredicto (RF-03.4): NULL con estado
--     terminal = veredicto sin leer, el combustible del rótulo
--     «Tienes dictámenes a la espera» (RF-01.1).
--
-- Cubre: RF-03.1 (clausura), RF-03.4 (veredicto leído), RNF-05
--        (PDO/SQL nativo, sin procedimientos almacenados).
--
-- =====================================================================
-- NATURALEZA DE LA CLAUSURA
-- =====================================================================
-- Las filas de `clan_applications` JAMÁS se borran: `approved`,
-- `rejected` y `cancelled` persisten con su `resolved_at`. El índice
-- único no distingue estados: cualquier fila histórica de esa casa para
-- esa cuenta CLAUSURA la casa. El INSERT que lo viole recibe el error
-- de unicidad y el servicio discierne `APPLICATION_HOUSE_CLOSED`
-- (clausura) de `APPLICATION_ALREADY_PENDING` (petición viva, 409).
--
-- =====================================================================
-- CONTRATO DE APLICACIÓN IDEMPOTENTE
-- =====================================================================
-- SQLite NO admite `CREATE UNIQUE INDEX IF NOT EXISTS` sobre índices ya
-- existentes con otro nombre, ni `ADD COLUMN IF NOT EXISTS`. Este guion
-- adopta el mismo contrato VANILLA que sql/09_lineage_oath.sql:
--
--   * El aplicador ejecuta el guion SENTENCIA A SENTENCIA (PDO exec por
--     trozo, sin librerías). Un fallo de «duplicate column name:
--     verdict_seen_at» o de índice ya existente NO es un error: es la
--     señal de que la base ya porta la pieza y el guion se aplica por
--     segunda vez. Cualquier otro fallo sí es error de aplicación.
--   * El PREFLIGHT DE DEDUPLICACIÓN es idempotente POR DISEÑO: solo
--     mueve a la tabla archivo los duplicados que aún residen en la
--     tabla viva; tras la primera aplicación no queda ninguno y una
--     segunda pasada no mueve fila alguna.
--
-- Así, ejecutar el guion dos veces consecutivas no produce error ni
-- duplica el archivo, y una base legada queda en el estado exacto de
-- una base nueva levantada con `database/schema.sql` (coherencia
-- guion↔esquema, lección de SPEC-08 Tarea 1.5).
--
-- =====================================================================
-- ORDEN DE APLICACIÓN (secuencial, un solo paso)
-- =====================================================================
--   1) database/schema.sql  → esquema canónico completo (las bases
--                             nuevas nacen ya con índice y columna).
--   2) database/seeds.sql   → linajes, clanes y custodio fundacional.
--   3) ESTE script          → solo sobre bases legadas a SPEC-10.
--
-- Verificación: php scratch/test_vestibule_migration.php
-- (Tarea 7.6 de TASKS-10; la condición «Hecho cuando» de la Tarea 1.1
-- se comprueba con su fase de idempotencia e índice.)
--
-- =====================================================================
-- COMPATIBILIDAD DE DIALECTO
-- =====================================================================
--   * SQLite 3.35+: CREATE UNIQUE INDEX y ALTER TABLE ADD COLUMN son
--     antiguos; pragma_index_list sirve el preflight del índice.
--   * MySQL 8 / MariaDB 10.4+: la columna resultante es idéntica; en
--     MariaDB basta `ADD COLUMN IF NOT EXISTS` y en MySQL 8 se consulta
--     INFORMATION_SCHEMA.COLUMNS. El índice único viaja igual en ambos
--     motores.
-- =====================================================================

PRAGMA foreign_keys = OFF;

-- ---------------------------------------------------------------------
-- 1. EL VEREDICTO CONTEMPLADO (RF-03.4)
-- ---------------------------------------------------------------------
-- La columna ANTES de la deduplicación: el preflight copia todas las
-- columnas de las filas que archiva, y sobre una base legada esta aún
-- no existiría (lección capturada por el arnés de la Tarea 1.1).
--
-- Instante en que el postulante leyó el veredicto. NULL con estado
-- terminal = veredicto sin leer (rótulo del acceso, RF-01.1). Jamás se
-- escribe sobre peticiones `pending`: la guardia vive en
-- `markVerdictSeen()` (Tarea 1.2), que solo alcanza estados terminales
-- propios.
-- ---------------------------------------------------------------------
ALTER TABLE clan_applications ADD COLUMN verdict_seen_at TEXT NULL;

-- ---------------------------------------------------------------------
-- 1bis. LA MEMORIA DEL MOLDE (SPEC-10, Tarea 3.2)
-- ---------------------------------------------------------------------
-- El inventario consolidado del Vestíbulo (RF-03.8) y la sala de
-- deliberaciones del Patriarca exigen el TEXTO íntegro: sin la
-- motivación, el Patriarca delibera a ciegas; sin el motivo del
-- dictamen, el postulante no sabe por qué fue rechazado (Art. III.3).
-- Las tareas 2.3 y 2.6 ya aplicaban el molde 20–500; estas columnas
-- hacen que el texto sobreviva a su validación.
--
-- Idempotencia: sobre una base ya migrada el «duplicate column name»
-- se descarta por el mismo contrato del veredicto contemplado.
-- ---------------------------------------------------------------------
ALTER TABLE clan_applications ADD COLUMN motivation TEXT NULL;
ALTER TABLE clan_applications ADD COLUMN verdict_motive TEXT NULL;

-- ---------------------------------------------------------------------
-- 2. PREFLIGHT DE DEDUPLICACIÓN (plan §1.3, punto 1)
-- ---------------------------------------------------------------------
-- Los duplicados legados de `(user_id, clan_id)` NO son clausura: son
-- eco de escrituras previas a la regla. El índice único los bloquearía,
-- así que se ARCHIVAN fuera de la tabla — se mueven a la tabla espejo
-- `clan_applications_archive` conservando todas sus columnas. Para cada
-- par con más de una fila histórica se conserva la más antigua (la de
-- `created_at` menor, desempatada por `id` para el determinismo) y las
-- restantes emigran al archivo.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clan_applications_archive (
    id          TEXT PRIMARY KEY,
    clan_id     TEXT NOT NULL,
    user_id     TEXT NOT NULL,
    status      TEXT NOT NULL,
    motivation      TEXT,
    verdict_motive  TEXT,
    created_at  TEXT NOT NULL,
    resolved_at TEXT,
    verdict_seen_at TEXT,
    archived_at TEXT NOT NULL                                 -- Instante del archivo (ISO 8601 UTC)
);

INSERT INTO clan_applications_archive
       (id, clan_id, user_id, status, motivation, verdict_motive, created_at, resolved_at, verdict_seen_at, archived_at)
SELECT a.id, a.clan_id, a.user_id, a.status, a.motivation, a.verdict_motive, a.created_at, a.resolved_at, a.verdict_seen_at,
       strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
  FROM clan_applications a
 WHERE a.id NOT IN (
       -- La más antigua de cada casa y cuenta sobrevive en la tabla viva.
       SELECT MIN(keeper.id)
         FROM clan_applications keeper
        WHERE keeper.clan_id = a.clan_id
          AND keeper.user_id = a.user_id
       );

DELETE FROM clan_applications
 WHERE id IN (SELECT id FROM clan_applications_archive);

-- ---------------------------------------------------------------------
-- 3. LA CLAUSURA PERPETUA POR CASA (RF-03.1)
-- ---------------------------------------------------------------------
-- El índice UNIQUE convierte la regla «una sola petición por casa y
-- cuenta, para siempre» en invariante físico (plan §5.2). Consulta
-- previa `hasSealedHouse` quedaba en ventana de carrera; una columna
-- booleana sería una segunda verdad que divergiría de las filas. La
-- base es la muralla: ninguna vía la burla.
-- ---------------------------------------------------------------------
CREATE UNIQUE INDEX IF NOT EXISTS uq_clan_application_house
    ON clan_applications (user_id, clan_id);

PRAGMA foreign_keys = ON;
