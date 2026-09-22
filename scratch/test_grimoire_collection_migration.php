<?php

declare(strict_types=1);

/**
 * test_grimoire_collection_migration.php — Verificación de la Tarea 1.1
 * de TASKS-11.
 *
 * Valida la migración `sql/11_grimoire_collections.sql` (la mesa del
 * Tomo Personal) contra el «Hecho cuando» de la tarea:
 *
 *   1. Re-ejecutar la migración sobre una base ya migrada no falla ni
 *      duplica (idempotencia del contrato de aplicación).
 *   2. Un segundo INSERT de `(user_id, spell_id)` existente recibe la
 *      violación del índice único (el sellado único de RF-01.3 es
 *      invariante físico; caso límite 5 de la SPEC-11).
 *   3. Borrar la fila de `users` arrastra su tomo por cascada
 *      (purga de cuenta, RF-05.3).
 *   4. Coherencia guion↔esquema: una base nueva desde
 *      `database/schema.sql` nace ya con tabla e índice (lección de
 *      SPEC-07: el DDL canónico no se reparte).
 *   5. El índice `(user_id, added_at DESC)` existe y sirve el orden
 *      de adición, el más reciente primero (RF-02.1, RNF-01).
 *   6. La tabla vive SEPARADA de `favorites`: sesgar la una jamás
 *      toca la otra (RF-05.4, ritos distintos).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin librerías externas.
 *   - Artículo V (Dualidad): identificadores en inglés; narrativa y
 *     comentarios en noble castellano.
 *
 * Uso: php scratch/test_grimoire_collection_migration.php
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

/**
 * Aplica el guion de migración sentencia a sentencia, según el contrato
 * de aplicación idempotente: un fallo de «ya existe» NO es error (la
 * base ya porta la pieza); cualquier otro fallo sí lo es.
 */
function applyMigrationScript(PDO $connection, string $scriptSource): void
{
    $statements = [];
    foreach (explode(";\n", $scriptSource) as $chunk) {
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
            $message = $failure->getMessage();
            $isAlreadyThere = str_contains($message, 'already exists');
            if (!$isAlreadyThere) {
                throw $failure;
            }
            // Señal de idempotencia: la pieza ya vive en la base.
        }
    }
}

/** ¿Lanza este cierre de excepción? Devuelve el mensaje o null. */
function captureError(callable $operation): ?string
{
    try {
        $operation();
        return null;
    } catch (PDOException $failure) {
        return $failure->getMessage();
    }
}

/** Crea una base SQLite de prueba con el núcleo mínimo (users + spells). */
function forgeProbeDatabase(string $probePath): PDO
{
    $connection = new PDO('sqlite:' . $probePath);
    $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $connection->exec('PRAGMA foreign_keys = ON');

    // Núcleo mínimo para sostener las claves foráneas del tomo.
    $connection->exec(<<<'SQL'
CREATE TABLE users (
    id       TEXT PRIMARY KEY,
    alias    TEXT NOT NULL UNIQUE,
    email    TEXT NOT NULL UNIQUE,
    role     TEXT NOT NULL DEFAULT 'editor',
    lineage  TEXT
);
CREATE TABLE spells (
    id     TEXT PRIMARY KEY,
    name   TEXT NOT NULL,
    clan_id TEXT
);
SQL);

    return $connection;
}

/** Siembra un adepto y un hechizo, adaptándose a la base disponible. */
function seedAdeptAndSpell(PDO $connection, string $suffix): array
{
    $userId = 'usr-' . $suffix;
    $spellId = 'spl-' . $suffix;
    // La FASE 1 porta el núcleo mínimo del arnés; la FASE 6 porta el
    // schema canónico real. La siembra discierne por la columna que los
    // distingue: password_hash solo existe en el esquema real.
    $usersColumns = $connection->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
    $hasPasswordHash = false;
    foreach ($usersColumns as $column) {
        if (($column['name'] ?? '') === 'password_hash') {
            $hasPasswordHash = true;
            break;
        }
    }

    if ($hasPasswordHash) {
        // Esquema real: users exige password_hash y spells exige autor,
        // escuela, clan y resumen. Se siembran las semillas de las que
        // pende el conjuro.
        $connection->prepare('INSERT INTO users (id, alias, email, password_hash, created_at, updated_at) VALUES (:id, :alias, :email, :hash, :created, :updated)')
            ->execute([':id' => $userId, ':alias' => 'Adepto ' . $suffix, ':email' => $suffix . '@santuario.test', ':hash' => str_repeat('a', 60), ':created' => '2026-09-22T00:00:00Z', ':updated' => '2026-09-22T00:00:00Z']);
        $connection->prepare("INSERT OR IGNORE INTO magic_schools (slug, name) VALUES ('evocacion', 'Evocación')")
            ->execute();
        $connection->prepare('INSERT INTO clans (id, slug, name, created_at, lineage_type) VALUES (:id, :slug, :name, :created, :lineageType)')
            ->execute([':id' => 'cln-' . $suffix, ':slug' => 'casa-prueba-' . $suffix, ':name' => 'Casa de prueba ' . $suffix, ':created' => '2026-09-22T00:00:00Z', ':lineageType' => 'primordialFlame']);
        $connection->prepare(
            'INSERT INTO spells (id, slug, name, author_id, magic_school, clan_id, summary, mana_cost, created_at, updated_at, math_fingerprint)'
            . " VALUES (:id, :slug, :name, :author, 'evocacion', :clan, 'Resumen de prueba', 5, :created, :created, :fingerprint)"
        )->execute([':id' => $spellId, ':slug' => 'conjuro-prueba-' . $suffix, ':name' => 'Conjuro de prueba ' . $suffix, ':author' => $userId, ':clan' => 'cln-' . $suffix, ':created' => '2026-09-22T00:00:00Z', ':fingerprint' => str_repeat('0', 64)]);

        return [$userId, $spellId];
    }

    // Núcleo mínimo del arnés (FASE 1).
    $connection->prepare('INSERT INTO users (id, alias, email) VALUES (:id, :alias, :email)')
        ->execute([':id' => $userId, ':alias' => 'Adepto ' . $suffix, ':email' => $suffix . '@santuario.test']);
    $connection->prepare('INSERT INTO spells (id, name) VALUES (:id, :name)')
        ->execute([':id' => $spellId, ':name' => 'Conjuro de prueba ' . $suffix]);

    return [$userId, $spellId];
}

echo "=== Migración 11_grimoire_collections.sql — Tarea 1.1 de TASKS-11 ===\n";

$migrationSource = (string) file_get_contents(__DIR__ . '/../sql/11_grimoire_collections.sql');
$schemaSource = (string) file_get_contents(__DIR__ . '/../database/schema.sql');
$probePaths = [];

echo "\n[FASE 0] Superficie: el guion existe y declara sus piezas.\n";
assertCondition($migrationSource !== '', 'El guion sql/11_grimoire_collections.sql existe y no está vacío.');
assertCondition(str_contains($migrationSource, 'CREATE TABLE IF NOT EXISTS grimoire_collections'), 'El guion declara la tabla con idempotencia nativa (IF NOT EXISTS).');
assertCondition(str_contains($migrationSource, 'UNIQUE (user_id, spell_id)'), 'El guion consagra la muralla del sellado único (RF-01.3).');
assertCondition(str_contains($migrationSource, 'idx_grimoire_collections_user_added'), 'El guion erige el índice de latencia (RNF-01).');

// --- FASE 1: primera aplicación sobre una base nueva -----------------------
$probePaths[1] = __DIR__ . '/__probe_gc_migration_1.sqlite';
@unlink($probePaths[1]);
$connection1 = forgeProbeDatabase($probePaths[1]);

echo "\n[FASE 1] Primera aplicación: tabla e índice nacen.\n";
applyMigrationScript($connection1, $migrationSource);

$tableRow = $connection1->query(
    "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'grimoire_collections'"
)->fetch(PDO::FETCH_ASSOC);
assertCondition(is_array($tableRow), 'La tabla grimoire_collections existe tras la primera aplicación.');

[$adeptId, $spellId] = seedAdeptAndSpell($connection1, 'a1');
$connection1->prepare('INSERT INTO grimoire_collections (id, user_id, spell_id, added_at) VALUES (:id, :u, :s, :t)')
    ->execute([':id' => 'gc-a1', ':u' => $adeptId, ':s' => $spellId, ':t' => '2026-09-22T10:00:00Z']);
echo "  [PASA] Sellado inicial insertado (fila de referencia sembrada).\n";
$assertsPassed++;

// --- FASE 2: segunda aplicación — idempotencia -----------------------------
echo "\n[FASE 2] Re-ejecutar la migración sobre una base ya migrada: no falla ni duplica.\n";
$secondApplicationError = captureError(
    static fn () => applyMigrationScript($connection1, $migrationSource)
);
assertCondition($secondApplicationError === null, 'La segunda aplicación del guion no lanza error alguno.');
$tableCount = (int) $connection1->query(
    "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'grimoire_collections'"
)->fetchColumn();
assertCondition($tableCount === 1, 'La tabla no se duplica (una sola pieza en la base).');
$rowCount = (int) $connection1->query('SELECT COUNT(*) FROM grimoire_collections')->fetchColumn();
assertCondition($rowCount === 1, 'Los datos sembrados sobreviven intactos a la re-migración.');

// --- FASE 3: la muralla del índice único -----------------------------------
echo "\n[FASE 3] Muralla del sellado único: el segundo INSERT de (user_id, spell_id) cae.\n";
$duplicateError = captureError(static function () use ($connection1, $adeptId, $spellId): void {
    $connection1->prepare('INSERT INTO grimoire_collections (id, user_id, spell_id, added_at) VALUES (:id, :u, :s, :t)')
        ->execute([':id' => 'gc-a1-dup', ':u' => $adeptId, ':s' => $spellId, ':t' => '2026-09-22T10:01:00Z']);
});
assertCondition($duplicateError !== null, 'El segundo sellado del mismo (adepto, hechizo) recibe violación de unicidad.');
assertCondition($duplicateError !== null && str_contains($duplicateError, 'UNIQUE'), 'La violación es la del índice UNIQUE (user_id, spell_id).');
// Un hechizo DISTINTO sí entra: la muralla no veda el tomo, solo el duplicado.
$connection1->prepare('INSERT INTO spells (id, name) VALUES (:id, :name)')
    ->execute([':id' => 'spl-a1-b', ':name' => 'Segundo conjuro de prueba']);
$secondSpellError = captureError(static function () use ($connection1, $adeptId): void {
    $connection1->prepare('INSERT INTO grimoire_collections (id, user_id, spell_id, added_at) VALUES (:id, :u, :s, :t)')
        ->execute([':id' => 'gc-a1-b', ':u' => $adeptId, ':s' => 'spl-a1-b', ':t' => '2026-09-22T10:02:00Z']);
});
assertCondition($secondSpellError === null, 'Un hechizo distinto del mismo adepto entra sin fricción (la muralla solo veda el duplicado).');

// --- FASE 4: cascada de purga de cuenta ------------------------------------
echo "\n[FASE 4] Purga de cuenta: borrar la fila de users arrastra su tomo (RF-05.3).\n";
$connection1->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $adeptId]);
$orphanRows = (int) $connection1->query('SELECT COUNT(*) FROM grimoire_collections')->fetchColumn();
assertCondition($orphanRows === 0, 'El tomo completo cae con su adepto: cero filas huérfanas.');

// --- FASE 5: coherencia guion↔esquema --------------------------------------
echo "\n[FASE 5] Coherencia: una base nueva desde schema.sql nace ya con la mesa.\n";
$probePaths[5] = __DIR__ . '/__probe_gc_migration_5.sqlite';
@unlink($probePaths[5]);
$connection5 = new PDO('sqlite:' . $probePaths[5]);
$connection5->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$connection5->exec('PRAGMA foreign_keys = ON');

// El esquema canónico se aplica ENTERO en un solo exec: SQLite despausa
// las sentencias por su cuenta y el partidor simple del arnés rompería
// con los «;» internos de CHECK y triggers del DDL real.
$schemaError = captureError(static fn () => $connection5->exec($schemaSource));
assertCondition($schemaError === null, 'El schema.sql canónico se aplica entero sin errores sobre una base nueva.'
    . ($schemaError !== null ? ' — Error: ' . $schemaError : ''));
$tableRow5 = $connection5->query(
    "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'grimoire_collections'"
)->fetch(PDO::FETCH_ASSOC);
assertCondition(is_array($tableRow5), 'La tabla vive en el DDL canónico (lección de SPEC-07: el esquema no se reparte).');
$indexRow5 = $connection5->query(
    "SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'idx_grimoire_collections_user_added'"
)->fetch(PDO::FETCH_ASSOC);
assertCondition(is_array($indexRow5), 'El índice de latencia vive en el DDL canónico.');

// --- FASE 6: el índice sirve el orden y la separación de mesas --------------
echo "\n[FASE 6] El índice sirve el orden (RF-02.1) y la mesa vive aparte (RF-05.4).\n";
[$adept2, ] = seedAdeptAndSpell($connection5, 'b1');
// Segundo hechizo del mismo adepto, sembrado con el insert del esquema real:
$connection5->prepare(
    'INSERT INTO spells (id, slug, name, author_id, magic_school, clan_id, summary, mana_cost, created_at, updated_at, math_fingerprint)'
    . " VALUES ('spl-b1-b', 'conjuro-prueba-b1-b', 'Conjuro segundo del adepto b1', :author, 'evocacion', :clan, 'Resumen de prueba', 5, :created, :created, :fingerprint)"
)->execute([':author' => $adept2, ':clan' => 'cln-b1', ':created' => '2026-09-22T00:00:00Z', ':fingerprint' => str_repeat('0', 64)]);
$insertTome = $connection5->prepare(
    'INSERT INTO grimoire_collections (id, user_id, spell_id, added_at) VALUES (:id, :u, :s, :t)'
);
$insertTome->execute([':id' => 'gc-b1-old', ':u' => $adept2, ':s' => 'spl-b1', ':t' => '2026-09-22T09:00:00Z']);
$insertTome->execute([':id' => 'gc-b1-new', ':u' => $adept2, ':s' => 'spl-b1-b', ':t' => '2026-09-22T11:00:00Z']);
$orderedSpells = $connection5->prepare(
    'SELECT spell_id FROM grimoire_collections WHERE user_id = :u ORDER BY added_at DESC'
);
$orderedSpells->execute([':u' => $adept2]);
$firstRow = (string) $orderedSpells->fetchColumn();
assertCondition($firstRow === 'spl-b1-b', 'El orden por adición DESC sirve el más reciente primero (RF-02.1).');

// La muralla de la colección es ajena a la mesa del Dominio: el schema
// canónico ya porta `favorites` (RF-05.4), así que se usa la real.
$connection5->prepare('INSERT INTO favorites (id, user_id, spell_id, created_at) VALUES (:id, :u, :s, :t)')
    ->execute([':id' => 'fav-b1', ':u' => $adept2, ':s' => 'spl-b1', ':t' => '2026-09-22T09:30:00Z']);
// El mismo (adepto, hechizo) YA está en favorites: la UNIQUE de favorites
// amarraría un voto repetido, pero el tomo es OTRA mesa con OTRA muralla.
$tomeDuplicate = captureError(static function () use ($connection5, $adept2): void {
    $connection5->prepare('INSERT INTO grimoire_collections (id, user_id, spell_id, added_at) VALUES (:id, :u, :s, :t)')
        ->execute([':id' => 'gc-b1-old-dup', ':u' => $adept2, ':s' => 'spl-b1', ':t' => '2026-09-22T12:00:00Z']);
});
assertCondition($tomeDuplicate !== null, 'La muralla del tomo es propia: el duplicado cae aunque favorites no se entere.');
$connection5->prepare('DELETE FROM grimoire_collections WHERE id = :id')->execute([':id' => 'gc-b1-old']);
$favoritesSurvivors = (int) $connection5->query('SELECT COUNT(*) FROM favorites')->fetchColumn();
assertCondition($favoritesSurvivors === 1, 'Retirar del tomo jamás toca favorites: cada rito vive su vida (caso límite 9).');

// --- Limpieza ---------------------------------------------------------------
$connection1 = null;
$connection5 = null;
foreach ($probePaths as $probePath) {
    @unlink($probePath);
}

echo "\n=== RESULTADO: {$assertsPassed} asertos en verde, {$assertsFailed} en rojo ===\n";
if ($assertsFailed > 0) {
    exit(1);
}
echo "Tarea 1.1 verificada: la mesa del Tomo Personal está erigida.\n";
