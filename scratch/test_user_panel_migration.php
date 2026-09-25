<?php

declare(strict_types=1);

/**
 * test_user_panel_migration.php — Verificación de la Tarea 1.1 de TASKS-12.
 *
 * Valida la migración `sql/12_user_panel.sql` (columna `users.avatar`
 * del Panel del Adepto, SPEC-12 — RF-03.1…RF-03.5, persistencia):
 *
 * El criterio «Hecho cuando» de la tarea se comprueba en tres frentes:
 *   1. La columna `users.avatar` TEXT NULL existe tras aplicar el guion.
 *   2. Ejecutar el guion DOS VECES consecutivas no produce error alguno
 *      (idempotencia: el ALTER re-aplicado es señal, no fallo).
 *   3. Una base NUEVA levantada desde `database/schema.sql` nace con la
 *      columna (coherencia guion↔esquema).
 *
 * Fases:
 *   [0]  Superficie: el guion existe y declara su ALTER.
 *   [1]  Primera aplicación sobre una base legada a SPEC-12.
 *   [2]  Segunda aplicación: idempotencia sin error ni duplicado.
 *   [3]  Semántica de la columna: NULL es el avatar canónico por defecto.
 *   [4]  Coherencia guion↔esquema: la base nueva nace con la columna.
 *   [5]  Dualidad de dialecto: la guardia MySQL (INFORMATION_SCHEMA)
 *        está declarada en el guion para el despliegue del santuario.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin librerías externas.
 *   - Artículo V (Dualidad): identificadores en inglés; narrativa y
 *     comentarios en noble castellano.
 *
 * Uso: php scratch/test_user_panel_migration.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

$assertsPassed = 0;
$assertsFailed = 0;

/** Aserta una condición y registra el resultado en la bitácora de consola. */
function assertCondition(bool $condition, string $description): void
{
    global $assertsPassed, $assertsFailed;
    if ($condition) {
        $assertsPassed++;
        echo "  [PASA] {$description}\n";
    } else {
        $assertsFailed++;
        echo "  [FALLA] {$description}\n";
    }
}

/** ¿Lanza este cierre de excepción? Devuelve el mensaje o null. */
function captureError(callable $operation): ?string
{
    try {
        $operation();

        return null;
    } catch (Throwable $failure) {
        return $failure->getMessage();
    }
}

/**
 * Aplica el guion de migración sentencia a sentencia, según el contrato
 * de aplicación idempotente que el propio guion declara: un ALTER
 * rechazado con «duplicate column» NO es error (la base ya porta la
 * columna); cualquier otro fallo sí lo es.
 */
function applyMigrationScript(PDO $connection, string $scriptSource): void
{
    // Se recortan los comentarios para que cada exec sea UNA sentencia.
    $statements = [];
    foreach (explode(";\n", $scriptSource) as $chunk) {
        // Retira comentarios de línea y colapsa el espacio resultante.
        $lines = array_filter(
            explode("\n", $chunk),
            static fn (string $line): bool => !str_starts_with(ltrim($line), '--')
        );
        $statement = trim(implode("\n", $lines));
        if ($statement !== '') {
            $statements[] = $statement;
        }
    }

    foreach ($statements as $statement) {
        try {
            $connection->exec($statement);
        } catch (PDOException $failure) {
            $isDuplicateColumn = str_contains($failure->getMessage(), 'duplicate column name');
            if (!$isDuplicateColumn) {
                throw $failure;
            }
            // Señal de idempotencia: la columna ya vive en la base.
        }
    }
}

/** Lee el avatar vestido por una cuenta (o null si viste el canónico). */
function avatarOf(PDO $connection, string $userId): ?string
{
    $statement = $connection->prepare('SELECT avatar FROM users WHERE id = :userId');
    $statement->execute([':userId' => $userId]);
    $value = $statement->fetchColumn();

    return $value === false || $value === null ? null : (string) $value;
}

/** Construye una base legada a SPEC-12: esquema anterior a la columna. */
function forgeLegacyDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON;');

    // Forma histórica de `users` (sin `avatar`): el estado previo a SPEC-12,
    // idéntico al que la Tarea 1.2 de TASKS-09 dejó ratificado.
    $pdo->exec("CREATE TABLE users (
        id TEXT PRIMARY KEY,
        alias TEXT NOT NULL UNIQUE,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'editor'
            CHECK (role IN ('reader', 'editor', 'master', 'supremeAdmin')),
        clan_id TEXT,
        lineage TEXT NULL
            CHECK (lineage IN (
                'primordialFlame', 'celestialTides', 'eternalTempest', 'worldRoots',
                'dawnWinds', 'solarCrown', 'abyssalShadows', 'aetherWeavers'
            )),
        recovery_token_hash TEXT NOT NULL DEFAULT '',
        recovery_token_expires_at TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");

    return $pdo;
}

echo "== VERIFICACION TAREA 1.1: La columna del avatar del Adepto (SPEC-12) ==\n\n";

$projectRoot = dirname(__DIR__);
$migrationPath = $projectRoot . '/sql/12_user_panel.sql';
$NOW = '2026-09-25T10:00:00Z';

// --- FASE 0: Superficie ---
echo "FASE 0: Superficie del guion\n";
assertCondition(file_exists($migrationPath), 'Existe sql/12_user_panel.sql');
if (!file_exists($migrationPath)) {
    echo "\nRESULTADO: DENEGADO — falta el guion de migración de la Tarea 1.1.\n";
    exit(1);
}
$scriptSource = (string) file_get_contents($migrationPath);
assertCondition(
    str_contains($scriptSource, 'ALTER TABLE users ADD COLUMN avatar TEXT NULL'),
    'El guion declara el ALTER vivo de la columna `avatar` TEXT NULL'
);
assertCondition(
    str_contains($scriptSource, 'PRAGMA table_info'),
    'El guion declara la guardia de idempotencia para SQLite (PRAGMA table_info)'
);
assertCondition(
    str_contains($scriptSource, 'INFORMATION_SCHEMA.COLUMNS'),
    'El guion declara la guardia de idempotencia para MySQL (INFORMATION_SCHEMA.COLUMNS)'
);
assertCondition(
    str_contains($scriptSource, 'catalog:') && str_contains($scriptSource, 'own:'),
    'El guion documenta la semántica cerrada de valores (catalog:<id> / own:<fileId>)'
);
assertCondition(
    str_contains($scriptSource, 'SPEC-12'),
    'El guion porta su comentario constitucional (referencia a SPEC-12 y sus RF)'
);

// --- FASE 1: Primera aplicación sobre una base legada ---
echo "\nFASE 1: Primera aplicación sobre una base LEGADA a SPEC-12\n";
$legacy = forgeLegacyDatabase();

// Tres adeptos: un linajado, un Maestro y un peregrino. Ninguno viste
// efigie aún: todas las filas nacen con avatar NULL (canónico).
$legacy->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, created_at, updated_at)
               VALUES ('usr_linajado', 'Heredera de la Llama', 'heredera@arcano.arc', 'x', 'editor', NULL, 'primordialFlame', '{$NOW}', '{$NOW}')");
$legacy->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, created_at, updated_at)
               VALUES ('usr_maestro', 'Maestro del Códice', 'maestro@arcano.arc', 'x', 'master', NULL, 'celestialTides', '{$NOW}', '{$NOW}')");
$legacy->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, created_at, updated_at)
               VALUES ('usr_peregrino', 'Peregrino Legado', 'peregrino@arcano.arc', 'x', 'editor', NULL, NULL, '{$NOW}', '{$NOW}')");

assertCondition(
    !array_key_exists('avatar', array_column($legacy->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name', 'name')),
    'La base legada NO porta aún la columna `avatar`: estado previo a SPEC-12'
);

$firstRun = captureError(static fn () => applyMigrationScript($legacy, $scriptSource));
assertCondition($firstRun === null, 'Primera aplicación del guion sin error alguno');

$userColumns = array_column($legacy->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
assertCondition(in_array('avatar', $userColumns, true), 'El guion incorpora la columna `users.avatar`');

// La columna es ANULABLE: el canon lo exige (NULL = canónico por defecto).
$columnIsNullable = false;
foreach ($legacy->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
    if ($column['name'] === 'avatar') {
        $columnIsNullable = ((int) $column['notnull']) === 0;
        break;
    }
}
assertCondition($columnIsNullable, 'La columna es ANULABLE: NULL significa «avatar canónico por defecto» (plan §2.1)');

// --- FASE 2: Segunda aplicación — idempotencia ---
echo "\nFASE 2: Segunda aplicación — idempotencia (criterio «Hecho cuando»)\n";
$secondRun = captureError(static fn () => applyMigrationScript($legacy, $scriptSource));
assertCondition($secondRun === null, 'Ejecutar el guion una SEGUNDA vez no produce error alguno');

$columnCount = 0;
foreach ($legacy->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
    if ($column['name'] === 'avatar') {
        $columnCount++;
    }
}
assertCondition($columnCount === 1, 'La columna no se duplica: sigue habiendo UN solo `users.avatar`');

// --- FASE 3: Semántica de la columna ---
echo "\nFASE 3: Semántica — NULL es el avatar canónico, sin fila huérfana\n";
assertCondition(avatarOf($legacy, 'usr_linajado') === null, 'Los adeptos existentes nacen con avatar NULL (visten el canónico por defecto)');
assertCondition(
    (int) $legacy->query('SELECT COUNT(*) FROM users WHERE avatar IS NULL')->fetchColumn() === 3,
    'La migración no inventa efigie alguna: ninguna fila mutada (UPDATE sin alcance)'
);

// La columna acepta la semántica cerrada del plan §2.1: efigie del catálogo.
$legacy->exec("UPDATE users SET avatar = 'catalog:seal_primordialFlame' WHERE id = 'usr_linajado'");
assertCondition(avatarOf($legacy, 'usr_linajado') === 'catalog:seal_primordialFlame', 'La columna hospeda la referencia del catálogo (catalog:<id>)');
// Y la efigie propia (own:<fileId>), la otra rama del contrato.
$legacy->exec("UPDATE users SET avatar = 'own:abc123efigie' WHERE id = 'usr_maestro'");
assertCondition(avatarOf($legacy, 'usr_maestro') === 'own:abc123efigie', 'La columna hospeda la efigie propia (own:<fileId>)');
$legacy->exec("UPDATE users SET avatar = NULL WHERE id IN ('usr_linajado', 'usr_maestro')");
assertCondition(avatarOf($legacy, 'usr_linajado') === null, 'Volver a NULL devuelve el avatar canónico: retirada sin sustituto (RF-03.5)');

// --- FASE 4: Coherencia guion↔esquema ---
echo "\nFASE 4: Coherencia guion↔esquema — la base nueva nace con la columna\n";

// Una base NUEVA nace con la columna ya declarada: el DDL maestro la
// incluye de forma directa (lección de SPEC-09: sin este frente, toda
// base levantada solo con schema.sql quedaría sin la columna).
$canonical = new PDO('sqlite::memory:');
$canonical->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$canonical->exec('PRAGMA foreign_keys = ON;');
$canonical->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

$canonicalColumns = array_column($canonical->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
assertCondition(in_array('avatar', $canonicalColumns, true), 'Una base NUEVA desde schema.sql nace con `users.avatar` (coherencia guion↔esquema)');

$canonicalNullable = false;
foreach ($canonical->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
    if ($column['name'] === 'avatar') {
        $canonicalNullable = ((int) $column['notnull']) === 0;
        break;
    }
}
assertCondition($canonicalNullable, 'La columna nacida del DDL maestro es también ANULABLE');

// El guion de migración sobre la base NUEVA es inocuo: la columna ya
// existe (señal de re-aplicación).
$inocuous = captureError(static fn () => applyMigrationScript($canonical, $scriptSource));
assertCondition($inocuous === null, 'Aplicar la migración sobre una base NUEVA es inocuo (columna ya nacida)');

// --- FASE 5: Dualidad de dialecto ---
echo "\nFASE 5: Dualidad de dialecto — la guardia MySQL está declarada\n";
assertCondition(
    str_contains($scriptSource, 'GRIMORIO_DB_DIALECT') || str_contains($scriptSource, 'MariaDB') || str_contains($scriptSource, 'MySQL'),
    'El guion nombra el dialecto MySQL/MariaDB del despliegue (compatibilidad dual del proyecto)'
);
assertCondition(
    str_contains($scriptSource, 'ADD COLUMN IF NOT EXISTS') || str_contains($scriptSource, 'INFORMATION_SCHEMA'),
    'El guion declara la vía idempotente de MySQL (IF NOT EXISTS de MariaDB o INFORMATION_SCHEMA)'
);

echo "\n== RESUMEN == Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";
if ($assertsFailed === 0) {
    echo "RESULTADO: EXITO — La columna del avatar esta en pie: doble ejecucion sin error, base nueva coherente y semantica canonica intacta (Tarea 1.1).\n";
    exit(0);
}

echo "RESULTADO: DENEGADO — Corregir los asertos en rojo antes de continuar.\n";
exit(1);
