<?php

declare(strict_types=1);

/**
 * test_lineage_migration.php — Verificación de la Tarea 1.1 de TASKS-09.
 *
 * Valida la migración `sql/09_lineage_oath.sql` (columna `users.lineage`
 * del Juramento de Linaje y su respaldo de legado):
 *
 * El criterio «Hecho cuando» de la tarea se comprueba en tres frentes:
 *   1. Ejecutar el guion DOS VECES consecutivas sobre una base legada no
 *      produce error ni duplica el respaldo de legado.
 *   2. Un usuario con `clan_id` histórico despierta con el `lineage_type`
 *      de su clan heredado (caso límite 7: exención de legados).
 *   3. Los usuarios sin clan quedan con `lineage IS NULL` (peregrinos).
 *
 * Fases:
 *   [0]  Superficie: el guion existe y declara sus dos piezas.
 *   [1]  Primera aplicación sobre una base legada (columna + respaldo).
 *   [2]  Segunda aplicación: idempotencia sin error ni mutación.
 *   [3]  Muralla del CHECK: solo el canon de 8 linajes cabe.
 *   [4]  Los vínculos siguen separados: el espejo clan_id no se toca.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin librerías externas.
 *   - Artículo V (Dualidad): identificadores en inglés; narrativa y
 *     comentarios en noble castellano.
 *
 * Uso: php scratch/test_lineage_migration.php
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

/** Lee el linaje jurado de una cuenta (o null si es peregrina). */
function lineageOf(PDO $connection, string $userId): ?string
{
    $statement = $connection->prepare('SELECT lineage FROM users WHERE id = :userId');
    $statement->execute([':userId' => $userId]);
    $value = $statement->fetchColumn();

    return $value === false || $value === null ? null : (string) $value;
}

/** Construye una base legada a SPEC-09: esquema anterior a la columna. */
function forgeLegacyDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON;');

    // Forma histórica de `users` (sin `lineage`): el estado previo a SPEC-09.
    $pdo->exec('CREATE TABLE users (
        id TEXT PRIMARY KEY,
        alias TEXT NOT NULL UNIQUE,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT \'editor\',
        clan_id TEXT,
        recovery_token_hash TEXT NOT NULL DEFAULT \'\',
        recovery_token_expires_at TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE clans (
        id TEXT PRIMARY KEY,
        slug TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        motto TEXT NOT NULL DEFAULT \'\',
        created_at TEXT NOT NULL,
        coat_of_arms TEXT NOT NULL DEFAULT \'\',
        lineage_type TEXT NOT NULL DEFAULT \'primordialFlame\'
            CHECK (lineage_type IN (
                \'primordialFlame\', \'celestialTides\', \'eternalTempest\', \'worldRoots\',
                \'dawnWinds\', \'solarCrown\', \'abyssalShadows\', \'aetherWeavers\'
            )),
        admission_mode TEXT NOT NULL DEFAULT \'open\',
        status TEXT NOT NULL DEFAULT \'active\',
        patriarch_id TEXT,
        weekly_points INTEGER NOT NULL DEFAULT 0,
        historical_points INTEGER NOT NULL DEFAULT 0,
        last_activity_at TEXT NOT NULL DEFAULT \'\',
        updated_at TEXT NOT NULL DEFAULT \'\'
    )');

    return $pdo;
}

echo "== VERIFICACION TAREA 1.1: La columna del Juramento de Linaje ==\n\n";

$projectRoot = dirname(__DIR__);
$migrationPath = $projectRoot . '/sql/09_lineage_oath.sql';
$NOW = '2026-09-18T10:00:00Z';

// --- FASE 0: Superficie ---
echo "FASE 0: Superficie del guion\n";
assertCondition(file_exists($migrationPath), 'Existe sql/09_lineage_oath.sql');
if (!file_exists($migrationPath)) {
    echo "\nRESULTADO: FALLO — falta el guion de migracion de la Tarea 1.1.\n";
    exit(1);
}
$scriptSource = (string) file_get_contents($migrationPath);
assertCondition(
    str_contains($scriptSource, 'ALTER TABLE users ADD COLUMN lineage TEXT NULL'),
    'El guion declara el ALTER vivo de la columna `lineage`'
);
assertCondition(
    str_contains($scriptSource, "'aetherWeavers'"),
    'El CHECK del ALTER impone el canon cerrado de los 8 linajes de SPEC-07'
);
assertCondition(
    str_contains($scriptSource, 'WHERE lineage IS NULL AND clan_id IS NOT NULL'),
    'El respaldo de legado porta su guardia idempotente (solo peregrinos con clan)'
);
assertCondition(
    str_contains($scriptSource, 'SELECT c.lineage_type FROM clans c WHERE c.id = users.clan_id'),
    'El respaldo hereda el lineage_type del clan histórico (caso límite 7)'
);
assertCondition(
    str_contains((string) file_get_contents($projectRoot . '/database/schema.sql'), 'CREATE TABLE IF NOT EXISTS lineage_doctrines'),
    'El DDL canónico declara la tabla `lineage_doctrines` (Tarea 1.2)'
);

// --- FASE 1: Primera aplicación sobre una base legada ---
echo "\nFASE 1: Primera aplicacion sobre una base LEGADA\n";
$legacy = forgeLegacyDatabase();

// Dos clanes de linajes distintos y tres adeptos: uno con clan, otro con
// clan de otro linaje y un tercero sin clan (futuro peregrino).
$legacy->exec("INSERT INTO clans (id, slug, name, motto, created_at, coat_of_arms, lineage_type, admission_mode,
                               status, weekly_points, historical_points, last_activity_at, updated_at)
               VALUES ('cln_llama', 'custodios', 'Custodios de la Llama', 'Antes de la primera palabra, ya ardimos.',
                       '{$NOW}', 'rune_flame_shield', 'primordialFlame', 'open', 'active', 0, 0, '{$NOW}', '{$NOW}')");
$legacy->exec("INSERT INTO clans (id, slug, name, motto, created_at, coat_of_arms, lineage_type, admission_mode,
                               status, weekly_points, historical_points, last_activity_at, updated_at)
               VALUES ('cln_marea', 'marejantes', 'Marejantes', 'Todo lo que cede, retorna.',
                       '{$NOW}', 'rune_aqua_shield', 'celestialTides', 'open', 'active', 0, 0, '{$NOW}', '{$NOW}')");
$legacy->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
               VALUES ('usr_heredera', 'Heredera de la Llama', 'heredera@arcano.arc', 'x', 'editor', 'cln_llama', '{$NOW}', '{$NOW}')");
$legacy->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
               VALUES ('usr_marejante', 'Marejante', 'marejante@arcano.arc', 'x', 'editor', 'cln_marea', '{$NOW}', '{$NOW}')");
$legacy->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
               VALUES ('usr_peregrino', 'Peregrino Legado', 'peregrino@arcano.arc', 'x', 'editor', NULL, '{$NOW}', '{$NOW}')");

assertCondition(!array_key_exists('lineage', array_column($legacy->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name', 'name')), 'La base legada NO porta aun la columna `lineage`: estado previo a SPEC-09');

$firstRun = captureError(static fn () => applyMigrationScript($legacy, $scriptSource));
assertCondition($firstRun === null, 'Primera aplicacion del guion sin error alguno');

$userColumns = array_column($legacy->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
assertCondition(in_array('lineage', $userColumns, true), 'El guion incorpora la columna `users.lineage`');

assertCondition(lineageOf($legacy, 'usr_heredera') === 'primordialFlame', 'La adeptа con clan historico hereda el lineage_type de su clan (primordialFlame)');
assertCondition(lineageOf($legacy, 'usr_marejante') === 'celestialTides', 'El adeptо de clanes distintos hereda SU linaje, no uno unico (celestialTides)');
assertCondition(lineageOf($legacy, 'usr_peregrino') === null, 'El adeptо sin clan queda con lineage IS NULL: peregrino (la ceremonia lo espera)');

$nonNullCount = (int) $legacy->query('SELECT COUNT(*) FROM users WHERE lineage IS NOT NULL')->fetchColumn();
assertCondition($nonNullCount === 2, 'El respaldo alcanza exactamente a los dos adeptos con clan historico');

// --- FASE 2: Segunda aplicación — idempotencia ---
echo "\nFASE 2: Segunda aplicacion — idempotencia (criterio «Hecho cuando»)\n";
$secondRun = captureError(static fn () => applyMigrationScript($legacy, $scriptSource));
assertCondition($secondRun === null, 'Ejecutar el guion una SEGUNDA vez no produce error alguno');

$columnCount = 0;
foreach ($legacy->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
    if ($column['name'] === 'lineage') {
        $columnCount++;
    }
}
assertCondition($columnCount === 1, 'La columna no se duplica: sigue habiendo UN solo `users.lineage`');

$nonNullAfter = (int) $legacy->query('SELECT COUNT(*) FROM users WHERE lineage IS NOT NULL')->fetchColumn();
assertCondition($nonNullAfter === $nonNullCount, 'La segunda pasada no duplica el respaldo: los linajes heredados no cambian');
assertCondition(lineageOf($legacy, 'usr_heredera') === 'primordialFlame' && lineageOf($legacy, 'usr_marejante') === 'celestialTides', 'Los linajes heredados permanecen intactos tras la re-aplicacion');
assertCondition(lineageOf($legacy, 'usr_peregrino') === null, 'El peregrino sigue peregrino: ninguna pasada lo inventa linaje');

// --- FASE 3: Muralla del CHECK ---
echo "\nFASE 3: Muralla del CHECK — el canon inmutable\n";
assertCondition(
    captureError(static fn () => $legacy->exec("UPDATE users SET lineage = 'dracoStorm' WHERE id = 'usr_peregrino'")) !== null,
    'Un linaje ajeno al canon de 8 es rechazado por la base: el CHECK es la ultima muralla (exclusion 5)'
);
$legacy->beginTransaction();
$canonProbe = captureError(static fn () => $legacy->exec("UPDATE users SET lineage = 'solarCrown' WHERE id = 'usr_peregrino'"));
$legacy->rollBack();
assertCondition($canonProbe === null, 'Cualquiera de los 8 linajes del canon cabe (sonda revertida: el peregrino no se consagra aqui)');
assertCondition(lineageOf($legacy, 'usr_peregrino') === null, 'La sonda no dejo huella: el juramento pertenece a la ceremonia, no a la migracion');

// --- FASE 5: Coherencia guion↔esquema y canon sembrado (Tarea 1.2) ---
echo "\nFASE 4: Coherencia guion↔esquema y canon sembrado (Tarea 1.2)\n";

// Una base NUEVA nace con la columna y su CHECK: el DDL maestro la
// declara de forma directa (lección de SPEC-08: sin este frente, toda
// base levantada solo con schema.sql quedaría sin la columna).
$canonical = new PDO('sqlite::memory:');
$canonical->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$canonical->exec('PRAGMA foreign_keys = ON;');
$canonical->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
$canonical->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));

$canonicalColumns = array_column($canonical->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
assertCondition(in_array('lineage', $canonicalColumns, true), 'Una base NUEVA desde schema.sql nace con `users.lineage` (coherencia guion↔esquema)');

$sqlCanonical = (string) file_get_contents($projectRoot . '/database/schema.sql');
$sqlSeeds = (string) file_get_contents($projectRoot . '/database/seeds.sql');
$sqlMigration = $scriptSource;

// El mismo CHECK del canon en DDL y migración: muralla idéntica en ambos.
// Se extrae por delimitadores estables («lineage IN (» … «)») en vez de
// por regex de forma, para que el aserto mida el CONTENIDO y no el layout.
$extractCanon = static function (string $sql): string {
    $marker = 'lineage IN (';
    $start = strpos($sql, $marker);
    if ($start === false) {
        return '';
    }
    $start += strlen($marker);
    $end = strpos($sql, ')', $start);

    return $end === false ? '' : preg_replace('/\s+/', ' ', trim(substr($sql, $start, $end - $start)));
};
assertCondition($extractCanon($sqlCanonical) !== '' && $extractCanon($sqlCanonical) === $extractCanon($sqlMigration), 'El CHECK del canon es IDÉNTICO en el DDL maestro y en la migración');

// El mundo sembrado es coherente con la reconciliación: el Custodio, con
// clan histórico, porta ya su linaje jurado (espejo del respaldo).
assertCondition(
    (int) $canonical->query("SELECT COUNT(*) FROM lineage_doctrines")->fetchColumn() === 8,
    'El catálogo sembrado sirve las OCHO doctrinas canónicas'
);
assertCondition(
    (int) $canonical->query("SELECT COUNT(*) FROM lineage_doctrines WHERE doctrine_condensed <> '' AND doctrine_full <> ''")->fetchColumn() === 8,
    'Las 8 doctrinas viajan en AMBAS granularidades (condensada e íntegra)'
);
$condensedIsPrefix = true;
foreach ($canonical->query('SELECT id, doctrine_condensed, doctrine_full FROM lineage_doctrines ORDER BY position') as $doctrine) {
    if (!str_starts_with($doctrine['doctrine_full'], $doctrine['doctrine_condensed'])) {
        $condensedIsPrefix = false;
        break;
    }
}
assertCondition($condensedIsPrefix, 'La condensada es el recorte de la íntegra: un solo texto canónico derivado (plan §2.1)');
assertCondition(
    $canonical->query("SELECT ruling_element FROM lineage_doctrines WHERE id = 'aetherWeavers'")->fetchColumn() === 'pureArcane',
    'La heráldica sembrada es la del canon de SPEC-07 (mismo elemento rector)'
);
$heraldryAligned = true;
$canonicalLineages = ['primordialFlame' => ['rune-ignis', '#ff4500', 'fire'], 'celestialTides' => ['rune-aqua', '#00bfff', 'water'], 'eternalTempest' => ['rune-fulgur', '#9932cc', 'lightning'], 'worldRoots' => ['rune-terra', '#8b4513', 'earth'], 'dawnWinds' => ['rune-ventus', '#2e8b57', 'wind'], 'solarCrown' => ['rune-lux', '#ffd700', 'light'], 'abyssalShadows' => ['rune-tenebrae', '#4b0082', 'darkness'], 'aetherWeavers' => ['rune-arcana', '#4169e1', 'pureArcane']];
foreach ($canonical->query('SELECT id, glyph, banner_color, ruling_element FROM lineage_doctrines') as $doctrine) {
    $expected = $canonicalLineages[$doctrine['id']] ?? null;
    if ($expected === null || [$doctrine['glyph'], $doctrine['banner_color'], $doctrine['ruling_element']] !== $expected) {
        $heraldryAligned = false;
        break;
    }
}
assertCondition($heraldryAligned, 'Las 8 heráldicas sembradas coinciden con LineageSynergyService (fuente única, jamás divergente)');
assertCondition(
    lineageOf($canonical, 'usr_custodio_primordial') === 'primordialFlame',
    'El Custodio sembrado porta ya su linaje jurado: mundo coherente con el respaldo de legado'
);
assertCondition(
    (int) $canonical->query("SELECT COUNT(*) FROM clans WHERE lineage_type = 'primordialFlame'")->fetchColumn() >= 1,
    'El linaje fundacional de las semillas sigue sirviendo al Salón de Linajes (RF-02.2 de SPEC-01)'
);

// La muralla del canon también vive en el DDL maestro: una base nueva
// rechaza el linaje ajeno igual que la migrada.
assertCondition(
    captureError(static fn () => $canonical->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, created_at, updated_at)
                                                   VALUES ('usr_fantasma', 'Fantasma', 'fantasma@arcano.arc', 'x', 'editor', NULL, 'dracoStorm', '{$NOW}', '{$NOW}')")) !== null,
    'La base NUEVA también rechaza un linaje fuera del canon: misma muralla que la migrada'
);

// El guion de migración sobre la base NUEVA es inocuo: la columna ya
// existe (señal de re-aplicación) y el respaldo no altera a nadie.
$inocuous = captureError(static fn () => applyMigrationScript($canonical, $sqlMigration));
assertCondition($inocuous === null, 'Aplicar la migración sobre una base NUEVA es inocuo (columna ya nacida, respaldo sin filas que alcanzar)');

// --- FASE 5: Los vínculos siguen separados ---
echo "\nFASE 5: Linaje jurado y clan son vinculos independientes (RF-04.1)\n";
$mirror = $legacy->query("SELECT clan_id FROM users WHERE id = 'usr_heredera'")->fetchColumn();
assertCondition($mirror === 'cln_llama', 'El espejo `users.clan_id` no se toca: la migracion no altera la membresia (autoridad SPEC-07)');
$legacy->beginTransaction();
$legacy->exec("UPDATE users SET clan_id = NULL WHERE id = 'usr_heredera'");
$untouched = lineageOf($legacy, 'usr_heredera');
$legacy->rollBack();
assertCondition($untouched === 'primordialFlame', 'Cambiar el clan no altera el linaje jurado: identidad perpetua, membresia mutable');

echo "\n== RESUMEN == Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";
if ($assertsFailed === 0) {
    echo "RESULTADO: EXITO — La columna del juramento esta en pie: doble ejecucion sin error, herencia de legado fiel y peregrinos intactos (Tarea 1.1).\n";
    exit(0);
}

echo "RESULTADO: FALLO — Corregir los asertos en rojo antes de continuar.\n";
exit(1);
