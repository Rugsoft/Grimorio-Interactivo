<?php

/**
 * test_spell_creator_schema.php — Arnés TDD de la Tarea 1.1 (TASKS-04).
 *
 * Verifica que database/schema.sql crea la tabla `spells` con las columnas
 * cuantitativas, sus tipos, valores por defecto, constraints CHECK y los
 * índices de optimización por autor y clan (RF-01.1 a RF-01.5, RF-05.1),
 * ejecutando el DDL REAL contra SQLite en memoria (sin mocks de esquema).
 *
 * Criterio «Hecho cuando» (Tarea 1.1): la ejecución del script SQL amplía
 * o crea la tabla `spells` con todos los campos tipados, valores por
 * defecto e índices sin arrojar errores de sintaxis en SQLite (el dialecto
 * MySQL/MariaDB se verifica por paridad documental del DDL, Decisión del
 * plan 2.1: snake_case inglés y tipos portables).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, SQLite en memoria, cero ORM.
 *   - Artículo II: el maná queda acotado (CHECK 0..200) y determinista.
 *   - Artículo V: identificadores de columnas en inglés snake_case.
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$schemaSql = (string) file_get_contents($projectRoot . '/database/schema.sql');

$assertsPassed = 0;
$assertsFailed = 0;

/**
 * Aserto solemne del arnés.
 */
function assertArcane(bool $condition, string $legend): void
{
    global $assertsPassed, $assertsFailed;
    if ($condition) {
        $assertsPassed++;
        echo "  OK   {$legend}\n";
        return;
    }
    $assertsFailed++;
    echo "  FALLA {$legend}\n";
}

echo "=== Tarea 1.1 (TASKS-04): esquema cuantitativo de `spells` ===\n\n";

// =====================================================================
// [0] El DDL del plan está presente en schema.sql (superficie textual).
// =====================================================================
echo "[0] Superficie textual del DDL ampliado\n";

$expectedColumns = [
    'author_id', 'status', 'damage', 'healing', 'barrier',
    'crowd_control_type', 'range_type', 'area_type', 'duration_type',
    'has_verbal', 'has_somatic', 'has_material',
    'circle', 'math_fingerprint', 'signatures_count',
    'elemental_affinity', 'casting_time', 'updated_at',
];
$missingColumns = [];
foreach ($expectedColumns as $column) {
    if (preg_match('/^\s+' . preg_quote($column, '/') . '\s/m', $schemaSql) !== 1) {
        $missingColumns[] = $column;
    }
}
assertArcane($missingColumns === [], 'El DDL declara todas las columnas cuantitativas del plan (' . implode(', ', $missingColumns ?: ['completo']) . ')');

$expectedIndexes = ['idx_spell_author_status', 'idx_spell_clan_validated'];
$missingIndexes = [];
foreach ($expectedIndexes as $index) {
    if (!str_contains($schemaSql, $index)) {
        $missingIndexes[] = $index;
    }
}
assertArcane($missingIndexes === [], 'El DDL declara los índices de autor y clan del plan (' . implode(', ', $missingIndexes ?: ['completos']) . ')');

// =====================================================================
// [1] El DDL REAL se ejecuta sin errores de sintaxis (SQLite en memoria).
// =====================================================================
echo "\n[1] Ejecución del DDL real contra SQLite en memoria\n";

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

$schemaExecuted = false;
try {
    $pdo->exec($schemaSql);
    $schemaExecuted = true;
} catch (Throwable $schemaError) {
    echo '  ERROR DDL: ' . $schemaError->getMessage() . "\n";
}
assertArcane($schemaExecuted, 'database/schema.sql se ejecuta íntegro sin errores de sintaxis (SQLite)');

// =====================================================================
// [2] Estructura: columnas, tipos y valores por defecto.
// =====================================================================
echo "\n[2] Estructura de la tabla spells ampliada\n";

$columns = [];
foreach ($pdo->query('PRAGMA table_info(spells)') as $columnRow) {
    $columns[$columnRow['name']] = $columnRow;
}

$structureExpectations = [
    'author_id'          => ['notnull' => true,  'default' => null],
    'status'             => ['notnull' => true,  'default' => "'draft'"],
    'damage'             => ['notnull' => true,  'default' => '0'],
    'healing'            => ['notnull' => true,  'default' => '0'],
    'barrier'            => ['notnull' => true,  'default' => '0'],
    'crowd_control_type' => ['notnull' => true,  'default' => "'none'"],
    'range_type'         => ['notnull' => true,  'default' => "'touch'"],
    'area_type'          => ['notnull' => true,  'default' => "'singleTarget'"],
    'duration_type'      => ['notnull' => true,  'default' => "'instant'"],
    'has_verbal'         => ['notnull' => true,  'default' => '0'],
    'has_somatic'        => ['notnull' => true,  'default' => '0'],
    'has_material'       => ['notnull' => true,  'default' => '0'],
    'mana_cost'          => ['notnull' => true,  'default' => null],
    'circle'             => ['notnull' => true,  'default' => null],
    'math_fingerprint'   => ['notnull' => true,  'default' => null],
    'signatures_count'   => ['notnull' => true,  'default' => '0'],
    'elemental_affinity' => ['notnull' => true,  'default' => null],
    'casting_time'       => ['notnull' => true,  'default' => null],
    'updated_at'         => ['notnull' => true,  'default' => null],
];

foreach ($structureExpectations as $columnName => $expectation) {
    $columnMeta = $columns[$columnName] ?? null;
    assertArcane(
        is_array($columnMeta) && (int) $columnMeta['notnull'] === 1,
        "Columna {$columnName}: presente y NOT NULL"
    );
    if ($expectation['default'] !== null) {
        assertArcane(
            is_array($columnMeta) && $columnMeta['dflt_value'] === $expectation['default'],
            "Columna {$columnName}: valor por defecto {$expectation['default']}"
        );
    }
}

// El estado de moderación porta el canon ampliado ('draft' incluido).
$statusDefault = (string) ($columns['status']['dflt_value'] ?? '');
assertArcane($statusDefault === "'draft'", "El estado por defecto de un conjuro nuevo es 'draft' (ciclo de vida del plan)");

// =====================================================================
// [3] Constraints CHECK del dominio matemático (Artículo II).
// =====================================================================
echo "\n[3] Constraints CHECK del dominio matemático (Artículo II)\n";

$checks = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'spells'")->fetchColumn();
$checkSql = is_string($checks) ? $checks : '';

assertArcane(str_contains($checkSql, 'CHECK (mana_cost >= 0 AND mana_cost <= 200)'), 'CHECK mana_cost entre 0 y 200 (techo de Sobrecarga Arcana)');
assertArcane(str_contains($checkSql, 'CHECK (circle >= 1 AND circle <= 5)'), 'CHECK circle entre 1 y 5 (Círculos Arcanos)');
assertArcane(str_contains($checkSql, "CHECK (signatures_count >= 0 AND signatures_count <= 3)"), 'CHECK signatures_count entre 0 y 3 (moderación en 2 pasos)');
// El CHECK de estado se ensanchó con el canon de SPEC-08 (Tarea 1.5 de TASKS-08):
// `spells.status` es el ESPEJO de `spell_reviews.status`, que declara los cinco
// estados de RF-01.1. Los tres de TASKS-04 siguen ahí, en su orden original.
assertArcane(
    str_contains($checkSql, "status IN ('draft', 'experimental', 'validated', 'rejected', 'archived')"),
    "CHECK status porta los CINCO estados del canon: 'draft', 'experimental', 'validated', 'rejected', 'archived'"
);
// Autoridad y espejo han de hablar con UNA sola voz: si los dos CHECK se
// separaran, el espejo no podría repetir un estado que la autoridad declarase.
$reviewSql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'spell_reviews'")->fetchColumn();
assertArcane(
    str_contains($reviewSql, "status IN ('draft', 'experimental', 'validated', 'rejected', 'archived')"),
    'La AUTORIDAD (`spell_reviews.status`) declara exactamente el mismo canon de cinco estados que su espejo'
);
assertArcane(str_contains($checkSql, "crowd_control_type IN ('none', 'slow', 'root', 'stun')"), 'CHECK crowd_control_type con los 4 modos del plan');
assertArcane(str_contains($checkSql, "range_type IN ('touch', 'short', 'medium', 'long')"), 'CHECK range_type con los 4 alcances del plan');
assertArcane(str_contains($checkSql, "area_type IN ('singleTarget', 'cone', 'line', 'sphere')"), 'CHECK area_type con las 4 geometrías del plan');
assertArcane(str_contains($checkSql, "duration_type IN ('instant', 'concentration', 'sustained')"), 'CHECK duration_type con las 3 duraciones del plan');
assertArcane(str_contains($checkSql, 'length(math_fingerprint) = 64'), 'CHECK math_fingerprint exige SHA-256 hexadecimal de 64 caracteres');

// =====================================================================
// [4] Índices de optimización por autor y clan.
// =====================================================================
echo "\n[4] Índices de optimización por autor y clan\n";

$indexes = [];
foreach ($pdo->query("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'spells'") as $indexRow) {
    $indexes[$indexRow['name']] = (string) $indexRow['sql'];
}

assertArcane(isset($indexes['idx_spell_author_status']), 'Existe idx_spell_author_status');
assertArcane(str_contains($indexes['idx_spell_author_status'] ?? '', 'author_id') && str_contains($indexes['idx_spell_author_status'] ?? '', 'status'), 'idx_spell_author_status cubre (author_id, status)');
assertArcane(isset($indexes['idx_spell_clan_validated']), 'Existe idx_spell_clan_validated');
assertArcane(str_contains($indexes['idx_spell_clan_validated'] ?? '', 'clan_id') && str_contains($indexes['idx_spell_clan_validated'] ?? '', 'status'), 'idx_spell_clan_validated cubre (clan_id, status)');

// =====================================================================
// [5] Inserciones de sonda: válidas aceptadas, fuera de dominio rechazadas.
// =====================================================================
echo "\n[5] Sondas de inserción: dominio aceptado y rechazado\n";

$now = '2026-09-13T12:00:00Z';
$pdo->exec(
    "INSERT INTO clans (id, slug, name, motto, created_at)
     VALUES ('cln_astral', 'astral-scholars', 'Eruditos Astrales', 'Saber', '{$now}')"
);
$pdo->exec(
    "INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
     VALUES ('usr_forjador', 'ForjadorDePrueba', 'forjador@sanctuario.arc', 'x', 'editor', 'cln_astral', '{$now}', '{$now}')"
);
$pdo->exec(
    "INSERT INTO magic_schools (slug, name) VALUES ('evocation', 'Evocación')"
);

$validFingerprint = str_repeat('a', 64);
$insertValid = $pdo->prepare(
    "INSERT INTO spells (id, slug, name, author_id, clan_id, status, magic_school, elemental_affinity, casting_time,
                         summary, description, damage, healing, barrier, crowd_control_type, range_type, area_type, duration_type,
                         has_verbal, has_somatic, has_material, mana_cost, circle, math_fingerprint, signatures_count,
                         created_at, updated_at)
     VALUES ('spl_probe_ok', 'sonda-valida', 'Sonda Válida', 'usr_forjador', 'cln_astral', 'draft', 'evocation', 'fire', 'action',
             'Resumen de la sonda.', 'Conjuro de prueba del arnés.', 30, 0, 0, 'none', 'medium', 'sphere', 'instant',
             1, 1, 0, 48, 3, :fingerprint, 0, '{$now}', '{$now}')"
);
$insertValid->execute([':fingerprint' => $validFingerprint]);
assertArcane(true === true, 'Inserción válida (draft con cuantitativos completos) aceptada');

$rejectedCases = [
    'maná 201 (Sobrecarga)'      => "INSERT INTO spells (id, slug, name, author_id, clan_id, status, magic_school, elemental_affinity, casting_time, description, mana_cost, circle, math_fingerprint, created_at, updated_at) VALUES ('spl_bad1', 's1', 'S1', 'usr_forjador', 'cln_astral', 'draft', 'evocation', 'fire', 'action', 'x', 201, 3, '{$validFingerprint}', '{$now}', '{$now}')",
    'maná -1 (negativo)'         => "INSERT INTO spells (id, slug, name, author_id, clan_id, status, magic_school, elemental_affinity, casting_time, description, mana_cost, circle, math_fingerprint, created_at, updated_at) VALUES ('spl_bad2', 's2', 'S2', 'usr_forjador', 'cln_astral', 'draft', 'evocation', 'fire', 'action', 'x', -1, 1, '{$validFingerprint}', '{$now}', '{$now}')",
    'círculo 6 (fuera de canon)' => "INSERT INTO spells (id, slug, name, author_id, clan_id, status, magic_school, elemental_affinity, casting_time, description, mana_cost, circle, math_fingerprint, created_at, updated_at) VALUES ('spl_bad3', 's3', 'S3', 'usr_forjador', 'cln_astral', 'draft', 'evocation', 'fire', 'action', 'x', 50, 6, '{$validFingerprint}', '{$now}', '{$now}')",
    'firmas 4 (fuera de 0..3)'   => "INSERT INTO spells (id, slug, name, author_id, clan_id, status, magic_school, elemental_affinity, casting_time, description, mana_cost, circle, math_fingerprint, signatures_count, created_at, updated_at) VALUES ('spl_bad4', 's4', 'S4', 'usr_forjador', 'cln_astral', 'draft', 'evocation', 'fire', 'action', 'x', 50, 3, '{$validFingerprint}', 4, '{$now}', '{$now}')",
    'daño -5 (negativo)'         => "INSERT INTO spells (id, slug, name, author_id, clan_id, status, magic_school, elemental_affinity, casting_time, description, damage, mana_cost, circle, math_fingerprint, created_at, updated_at) VALUES ('spl_bad5', 's5', 'S5', 'usr_forjador', 'cln_astral', 'draft', 'evocation', 'fire', 'action', 'x', -5, 50, 3, '{$validFingerprint}', '{$now}', '{$now}')",
    'huella corta (no SHA-256)'  => "INSERT INTO spells (id, slug, name, author_id, clan_id, status, magic_school, elemental_affinity, casting_time, description, mana_cost, circle, math_fingerprint, created_at, updated_at) VALUES ('spl_bad6', 's6', 'S6', 'usr_forjador', 'cln_astral', 'draft', 'evocation', 'fire', 'action', 'x', 50, 3, 'abc123', '{$now}', '{$now}')",
    'estado inválido'            => "INSERT INTO spells (id, slug, name, author_id, clan_id, status, magic_school, elemental_affinity, casting_time, description, mana_cost, circle, math_fingerprint, created_at, updated_at) VALUES ('spl_bad7', 's7', 'S7', 'usr_forjador', 'cln_astral', 'archived', 'evocation', 'fire', 'action', 'x', 50, 3, '{$validFingerprint}', '{$now}', '{$now}')",
    'alcance fuera de canon'     => "INSERT INTO spells (id, slug, name, author_id, clan_id, status, magic_school, elemental_affinity, casting_time, description, range_type, mana_cost, circle, math_fingerprint, created_at, updated_at) VALUES ('spl_bad8', 's8', 'S8', 'usr_forjador', 'cln_astral', 'draft', 'evocation', 'fire', 'action', 'x', 'continental', 50, 3, '{$validFingerprint}', '{$now}', '{$now}')",
];

foreach ($rejectedCases as $legend => $rejectedSql) {
    $wasRejected = false;
    try {
        $pdo->exec($rejectedSql);
    } catch (Throwable) {
        $wasRejected = true;
    }
    assertArcane($wasRejected, "El motor rechaza: {$legend}");
}

// =====================================================================
// [6] Paridad MySQL/MariaDB: sin cláusulas exclusivas de SQLite en el
// bloque de spells (Artículo I: portabilidad sin ORM).
// =====================================================================
echo "\n[6] Portabilidad del dialecto (SQLite / MySQL / MariaDB)\n";

$spellsBlockStart = (int) strpos($schemaSql, 'CREATE TABLE IF NOT EXISTS spells');
$spellsBlockEnd   = (int) strpos($schemaSql, ';', $spellsBlockStart);
$spellsBlock      = substr($schemaSql, $spellsBlockStart, $spellsBlockEnd - $spellsBlockStart);

assertArcane(!preg_match('/AUTOINCREMENT|PRAGMA|BEGIN\s|COMMIT/i', $spellsBlock), 'El bloque de spells no usa cláusulas exclusivas de SQLite');
assertArcane(!str_contains($spellsBlock, 'ENGINE=InnoDB'), 'El bloque de spells no usa cláusulas exclusivas de MySQL (nota de compatibilidad aparte)');

// =====================================================================
// Resumen final.
// =====================================================================
echo "\n=== RESUMEN: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);
