# SPEC-13 — Paridad de Dialecto MySQL/MariaDB: importación del esquema en producción

> Enmienda de despliegue. Nace del intento real de tránsito del santuario
> InfinityFree de SQLite persistente a MySQL/MariaDB: phpMyAdmin rechazó
> `database/schema.sql` con `#1170` (columna TEXT usada como clave sin
> tamaño). El hallazgo: la cabecera de `schema.sql` PROMETE compatibilidad
> dual («SQLite 3.35+ y MySQL 8 / MariaDB 10.4+») pero el DDL está en
> dialecto SQLite puro. Esta spec define la paridad real sin tocar el
> contrato de datos (tablas, columnas, invariantes y semillas quedan
> IDÉNTICOS en ambos motores).

---

## 1. Objetivo y alcance

**Objetivo:** permitir la importación del esquema completo (DDL + semillas)
en MySQL 8 / MariaDB 10.4+ sin errores y con idéntica semántica de
invariantes, y asegurar que el backend PHP es portable a ambos motores.

**Alcance:**
1. Nuevo guion `database/schema-mysql.sql`: gemelo dialectal canónico del
   esquema (misma tabla, columnas, CHECK, FK e índices que `schema.sql`).
2. Portabilidad de `src/Repositories/GrimoireCollectionRepository` (único
   dialectismo SQLite vivo fuera de `Connection.php`: `INSERT OR IGNORE`).
3. Actualización de `deploy/infinityfree/README.md` (variante MySQL).

**Fuera de alcance:**
- `database/schema.sql` y `database/seeds.sql` NO se modifican: siguen
  siendo la fuente del auto-bootstrap SQLite de `Connection.php`.
- Los guiones `sql/*.sql` siguen siendo vías de ascensión SQLite; sobre
  MySQL las bases nacen directamente del `schema-mysql.sql` vigente
  (incluye `users.avatar` de SPEC-12), sin migraciones previas.

## 2. Contexto de producción (hallazgos)

- InfinityFree ofrece **MariaDB 10.4 a 10.6** según antigüedad de cuenta
  (verificado en foros oficiales, 2024–2026). El guion debe compilar en
  10.4: PROHIBIDO depender de sintaxis de 10.8+ (índices funcionales).
- La conexión MySQL solo es posible DESDE el propio hosting (no remota);
  phpMyAdmin del panel es la única vía de importación.
- `putenv()` está en `disable_functions` del sandbox (hallazgo SPEC-12):
  el trío MySQL (DSN/usuario/contraseña) debe materializarse vía
  `define('GRIMORIO_DB_*', ...)` en `env.php`. `Connection.php` ya
  resuelve constantes primero y `getenv` como fallback (líneas 78–80).

## 3. Contratos: mapeo dialectal canónico

| Dialecto SQLite (schema.sql) | Dialecto MySQL (schema-mysql.sql) | Justificación |
|---|---|---|
| `id TEXT PRIMARY KEY` | `id VARCHAR(64) PRIMARY KEY` | MySQL #1170: TEXT no puede ser clave sin tamaño. Los identificadores textuales (≤ 36 UUID, prefijos cortos) caben en 64. |
| `slug TEXT NOT NULL UNIQUE` | `slug VARCHAR(191) NOT NULL UNIQUE` | 191 = límite histórico seguro de índice único con utf8mb4. |
| `alias/email/name TEXT ... UNIQUE` | `VARCHAR(191)` | Mismo motivo. |
| timestamps `TEXT` (ISO 8601 UTC) | `VARCHAR(32)` | «2026-01-01T00:00:00Z» = 20 chars; los contratos JSON exigen TEXT ISO, no DATETIME del motor. |
| enumeraciones con `CHECK (col IN (...))` | `VARCHAR(32)` + mismo CHECK | MariaDB 10.2+ y MySQL 8 SÍ aplican CHECK: el canon cerrado sobrevive. |
| BOOLEAN `INTEGER CHECK (IN (0,1))` | `TINYINT CHECK (IN (0,1))` | Misma semántica; conserva el CHECK. |
| texto libre (motto, description, doctrinas, glosas…) | `TEXT` | No participa en claves ni índices únicos. |
| `INTEGER PRIMARY KEY` (rowid: login_attempts, clan_history, audit_log) | `INT NOT NULL AUTO_INCREMENT PRIMARY KEY` | Equivalencia documentada ya en comentarios de schema.sql. |
| `REFERENCES` en línea | `FOREIGN KEY (...) REFERENCES ...` explícita | MySQL IGNORA las REFERENCES en línea de columna: dejarlas silenciosamente eliminaría todas las FK. |
| Orden de tablas con FKs hacia tablas aún no creadas | `SET FOREIGN_KEY_CHECKS=0` al inicio y `=1` al final | Misma doctrina que los `PRAGMA foreign_keys = OFF/ON` de los guiones sql/*.sql. |
| `CREATE UNIQUE INDEX ... WHERE left_at IS NULL` (clan_members) | Columna generada `membership_bucket VARCHAR(64) AS (CASE WHEN left_at IS NULL THEN '<<active>>' ELSE id END) STORED` + `UNIQUE (user_id, membership_bucket)` | MariaDB no admite índices parciales. Emulación de semántica EXACTA: dos membresías activas simultáneas chocan («<<active>>» repetido); las filas cerradas usan su `id` único y jamás colisionan. RF-01.1 queda garantizado estructuralmente. |
| `CREATE UNIQUE INDEX ... WHERE is_revoked = 0` (master_signatures) | Columna generada `signature_bucket` análoga + `UNIQUE (spell_id, master_id, signature_bucket)` | Ídem para RF-02.1 (firma única viva). |
| Triggers `RAISE(ABORT, ...)` de audit_log | **Anexo opcional** `database/schema-mysql-triggers.sql` (`BEFORE UPDATE/DELETE ... SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = ...`, cuerpo de una sola sentencia, sin BEGIN...END) | Inmutabilidad de la Bitácora (RF-08.1). AMENAZA REAL de despliegue (hallazgo en producción): el plan gratuito de InfinityFree NO concede el privilegio `TRIGGER` al usuario de la base (`#1142 - TRIGGER comando denegado`), de modo que los triggers NO pueden vivir en el guion principal (la importación entera moriría a mitad). En ese sandbox la inmutabilidad queda garantizada por la CAPA DE APLICACIÓN: verificado por búsqueda que ningún código de `src/` ejecuta UPDATE ni DELETE sobre `audit_log` (únicos escritores: `AuditService::record`, `AuthService` y `AvatarService`, todos por INSERT). El anexo es vía de blindaje DDL para anfitriones completos (MySQL propio, planes con privilegios). |
| Motor | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` | InnoDB aplica FK por defecto; utf8mb4 obligatorio (acentos y «comillas angulares»). |
| `DESC` en índices | Se conserva tal cual | MariaDB 10.4 lo acepta y ignora (sin error); MySQL 8 lo aplica. Sin cambio semántico observable. |

**Semillas:** `database/seeds.sql` es ya portable (INSERTs individuales,
sin dialectismos) y se usa IDÉNTICO en ambos motores. Los CHECK de
`math_fingerprint` (64 hex) y los valores sembrados son coherentes.

## 4. Contrato backend: portabilidad del repositorio

`GrimoireCollectionRepository::add()` debe interrogar al motor
(`PDO::ATTR_DRIVER_NAME`) y emitir `INSERT IGNORE` (MySQL) u
`INSERT OR IGNORE` (SQLite). La señal de idempotencia (`rowCount()`)
es idéntica en ambos. Ningún otro fichero de `src/` contiene
dialectismos SQLite (verificado por búsqueda: `Connection.php` solo
usa PRAGMA/sqlite_master dentro de su rama SQLite explícita).

## 5. Endpoints

Ninguno: el contrato REST queda intacto. Este cambio es de infraestructura
de datos y no altera peticiones ni respuestas.

## 6. Criterios de aceptación y casos de prueba

1. **Importación limpia:** `schema-mysql.sql` + `seeds.sql` se importan
   en una base MariaDB 10.4 vacía SIN errores (verificación local con
   la misma versión: 10.4.32).
2. **Paridad estructural:** tras importar ambos guiones en paralelo
   (SQLite y MySQL), las tablas y columnas coinciden en nombre, orden y
   nulabilidad (verificación programática de information_schema vs pragma).
3. **Canon cerrado:** un INSERT que viole un CHECK (p. ej.
   `lineage_type = 'dragonfire'`) es rechazado por MariaDB.
4. **Membresía única activa (RF-01.1):** dos filas activas del mismo
   usuario en `clan_members` → violación de unicidad; cerrando la
   primera (`left_at`), la segunda se admite.
5. **Firma única viva (RF-02.1):** dos firmas activas del mismo Maestro
   al mismo conjuro → violación; tras `is_revoked = 1`, se admite otra.
6. **Bitácora inmutable (RF-08.1):** `UPDATE audit_log` y `DELETE FROM
   audit_log` lanzan error 45000 con el mensaje solemne. **Criterio
   dual según anfitrión:** (a) con el anexo de triggers aplicado, la
   inmutabilidad es DDL (los triggers lo rechazan); (b) en anfitriones
   sin privilegio `TRIGGER` (InfinityFree), la garantía es de capa de
   aplicación: verificación estática de que `src/` jamás ejecuta UPDATE
   ni DELETE sobre `audit_log`. El arnés cubre ambas vías.
7. **Autoincremental:** tres INSERT en `login_attempts` producen ids 1,2,3.
8. **Semillas asentadas:** 8 escuelas, 8 doctrinas, 1 clan, 1 usuario
   tutor, 1 membresía, 4 conjuros.
9. **Portabilidad del repositorio:** el sellado del tomo es idempotente
   en ambos motores (`add()` → true la primera vez, false la segunda).
10. **Regresión SQLite:** `schema.sql` + `seeds.sql` siguen importando
    limpio en SQLite (auto-bootstrap intacto).

## 7. Procedimiento de despliegue (resumen, detalle en README)

Crear base en el panel → importar `schema-mysql.sql` y `seeds.sql` en
phpMyAdmin (en ese orden) → editar `env.php` con el trío `define()` →
Ctrl+F5 y verificación funcional. La columna `users.avatar` ya viene en
el guion: las bases MySQL nacen directamente vistiendo SPEC-12.

**Anexo de triggers (opcional):** el fichero
`database/schema-mysql-triggers.sql` NO se importa en anfitriones que
nieguen el privilegio `TRIGGER` (InfinityFree: la importación moriría
con `#1142`); se reserva para MySQL propio o planes completos. En su
ausencia, la inmutabilidad RF-08.1 queda garantizada por la capa de
aplicación (verificación estática de `src/`, criterio dual 6b).

## 8. LA REGLA DE LOS GEMELOS (norma permanente de mantenimiento)

> **Elevada a norma constitucional de proyecto en AGENTS.md §2.1.** Lo
> que esta especificación nació como enmienda de despliegue, permanece
> como regla vinculante para todo mantenimiento futuro del esquema.

**Enunciado:** `database/schema.sql` (SQLite) y
`database/schema-mysql.sql` (MySQL/MariaDB) son **gemelos dialectales
canónicos**: DOS guiones, UNA sola forma de datos. Tablas, columnas,
nombres, orden, CHECK, claves foráneas, índices e invariantes han de ser
IDÉNTICOS en ambos; solo el dialecto del DDL difiere.

**Obligaciones de todo cambio de DDL futuro (SPEC-14 en adelante o
mantenimiento):**

1. **Doble edición:** el cambio se aplica a AMBOS guiones en la MISMA
   tarea y commit. Un guion tocado sin su gemelo es un bug de
   especificación, aunque la aplicación funcione en un motor.
2. **Paridad estructural verificable:** tras el cambio, la comprobación
   de paridad (lista de tablas y columnas de `information_schema` vs
   `PRAGMA table_info`, excluidas las columnas generadas
   `membership_bucket` y `signature_bucket`, exclusivas del dialecto
   MySQL) debe pasar sin diferencias. El arnés de referencia es la sonda
   de verificación de SPEC-13 (patrón `scratch/verify-spec13.php`).
3. **Fuente de verdad semántica:** el comentario de cada tabla/columna
   del guion que se edite primero es la autoridad narrativa; el gemelo
   copia la semántica (puede remitir a ella) pero jamás diverge en forma.
4. **Semillas comunes:** `database/seeds.sql` es portable y común a
   ambos motores; los cambios de semillas también son de doble edición
   (verificando que los CHECK de ambos dialectos aceptan los valores).
5. **Índices parciales:** si un futuro índice parcial SQLite aparece, su
   gemelo MySQL lo emula con columnas generadas + índice único (patrón
   de `membership_bucket`/`signature_bucket`), conservando la semántica
   exacta; el patrón queda prohibido por encima de MariaDB 10.4 cuando
   exista alternativa nativa (los índices funcionales de 10.8 no
   aplican al sandbox actual de InfinityFree).
6. **Repositorios portables:** prohibido introducir dialectismos SQLite
   (o MySQL) en `src/` sin el doble canal motor-consciente (patrón de
   `GrimoireCollectionRepository::add()`, interrogando
   `PDO::ATTR_DRIVER_NAME`).

**Exclusión documental:** los registros históricos (`*.plan.md`,
`*.tasks.md`) y las notas de ratificación ya firmadas en otras specs NO
se reescriben para citar el gemelo: son actas, no norma viva. Esta
sección y AGENTS.md §2.1/§3/§5 son los lugares normativos.
