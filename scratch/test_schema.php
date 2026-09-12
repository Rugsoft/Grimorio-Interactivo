<?php

/**
 * Script de verificación de la TAREA 1.1 — Esquema de base de datos y semillas de génesis.
 *
 * Estrategia TDD: este script se escribe ANTES que los archivos SQL que verifica.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   1. Al ejecutar schema.sql y seeds.sql (SQLite/MySQL), las tablas `clans`, `spells`
 *      y `magic_schools` se crean correctamente.
 *   2. Existen claves foráneas e índices sobre `slug` y `magic_school`.
 *   3. La base contiene exactamente los 3 Pergaminos Primordiales canónicos.
 *
 * Uso: php scratch/test_schema.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

declare(strict_types=1);

// Contadores de asertos para el resumen final.
$assertsPassed = 0;
$assertsFailed = 0;

/**
 * Aserta una condición y registra el resultado en la bitácora de consola.
 */
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

echo "== VERIFICACION TAREA 1.1: schema.sql y seeds.sql ==\n\n";

// Base de datos SQLite efímera en memoria: no contamina el directorio del proyecto.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// SQLite no aplica claves foráneas por defecto: hay que activarlas explícitamente.
$pdo->exec('PRAGMA foreign_keys = ON;');

$schemaPath = __DIR__ . '/../database/schema.sql';
$seedsPath = __DIR__ . '/../database/seeds.sql';

// --- FASE 1: Los archivos SQL deben existir y ejecutarse sin errores ---
echo "FASE 1: Ejecucion del esquema y las semillas\n";
assertCondition(file_exists($schemaPath), "Existe el archivo database/schema.sql");
assertCondition(file_exists($seedsPath), "Existe el archivo database/seeds.sql");

if (!file_exists($schemaPath) || !file_exists($seedsPath)) {
    echo "\nRESULTADO: FALLO — faltan archivos SQL por crear (fase roja del TDD).\n";
    exit(1);
}

$executionOk = true;
try {
    $pdo->exec((string) file_get_contents($schemaPath));
    $pdo->exec((string) file_get_contents($seedsPath));
} catch (PDOException $e) {
    $executionOk = false;
    echo "  [FALLA] Ejecucion SQL lanzo excepcion: " . $e->getMessage() . "\n";
}
assertCondition($executionOk, "schema.sql y seeds.sql se ejecutan sin errores SQL");

// --- FASE 2: Las tres tablas nucleares existen ---
echo "\nFASE 2: Tablas nucleares\n";
$existingTables = $pdo->query(
    "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
)->fetchAll(PDO::FETCH_COLUMN);

foreach (['clans', 'spells', 'magic_schools'] as $requiredTable) {
    assertCondition(
        in_array($requiredTable, $existingTables, true),
        "Existe la tabla '{$requiredTable}'"
    );
}

// --- FASE 3: Claves foráneas e índices sobre slug y magic_school ---
echo "\nFASE 3: Indices y claves foraneas\n";
$indexRows = $pdo->query(
    "SELECT name, tbl_name, sql FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL"
)->fetchAll(PDO::FETCH_ASSOC);
$indexDefinitions = strtolower(implode(' | ', array_column($indexRows, 'sql')));

// Indice unico sobre el slug de hechizos (enlaces directos #hechizo-slug, RF-04.1).
assertCondition(
    str_contains($indexDefinitions, 'spells') && str_contains($indexDefinitions, 'slug'),
    "Existe un indice sobre spells.slug"
);

// Indice sobre la escuela de magia (filtros OR de escuelas, RF-03.5).
assertCondition(
    str_contains($indexDefinitions, 'magic_school'),
    "Existe un indice sobre spells.magic_school"
);

// Comprobacion funcional de la clave foranea: insertar un hechizo con clan inexistente debe fallar.
$fkEnforced = false;
try {
    $pdo->prepare(
        'INSERT INTO spells (id, slug, name, magic_school, mana_cost, clan_id, summary, status, is_genesis_sample, validated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute(['spl_fk_test', 'fk-test', 'Conjuro Fantasma', 'evocation', 10, 'cln_inexistente', 'Prueba FK', 'experimental', 0, '2026-01-01T00:00:00Z']);
} catch (PDOException $e) {
    // Se espera una violacion de integridad referencial: la FK funciona.
    $fkEnforced = true;
}
assertCondition($fkEnforced, "La clave foranea spells.clan_id -> clans.id rechaza registros huerfanos");

// Comprobacion funcional de la clave foranea de escuela de magia.
$fkSchoolEnforced = false;
try {
    $pdo->prepare(
        'INSERT INTO spells (id, slug, name, magic_school, mana_cost, clan_id, summary, status, is_genesis_sample, validated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute(['spl_fk_test2', 'fk-test-2', 'Conjuro Fantasma II', 'escuela_inexistente', 10, 'cln_primordial', 'Prueba FK escuela', 'experimental', 0, '2026-01-01T00:00:00Z']);
} catch (PDOException $e) {
    $fkSchoolEnforced = true;
}
assertCondition($fkSchoolEnforced, "La clave foranea spells.magic_school -> magic_schools.slug rechaza escuelas invalidas");

// --- FASE 4: Los 3 Pergaminos Primordiales canonicos (RF-01.3) ---
echo "\nFASE 4: Pergaminos Primordiales de genesis\n";
$expectedGenesis = [
    'chispa-de-ignicion' => 'Chispa de Ignición',
    'manto-de-niebla' => 'Manto de Niebla',
    'susurro-del-viento' => 'Susurro del Viento',
];

$allSpells = $pdo->query('SELECT slug, name, is_genesis_sample, status FROM spells')->fetchAll(PDO::FETCH_ASSOC);
$spellsBySlug = [];
foreach ($allSpells as $spell) {
    $spellsBySlug[$spell['slug']] = $spell;
}

foreach ($expectedGenesis as $genesisSlug => $genesisName) {
    $found = $spellsBySlug[$genesisSlug] ?? null;
    assertCondition($found !== null, "Existe el pergamino primordial '{$genesisSlug}'");
    if ($found !== null) {
        assertCondition(
            (int) $found['is_genesis_sample'] === 1,
            "'{$genesisSlug}' esta marcado como muestra de genesis (is_genesis_sample = 1)"
        );
        assertCondition(
            $found['status'] === 'validated',
            "'{$genesisSlug}' nace en estado 'validated' (canonical, no experimental)"
        );
    }
}

assertCondition(
    count($allSpells) === 4,
    "La base contiene los 3 hechizos de genesis más 1 experimental de semilla (contiene: " . count($allSpells) . ")"
);

// --- FASE 5: Clanes fundacionales sembrados (Art. III y Salón de Linajes, RF-02.2) ---
echo "\nFASE 5: Clanes fundacionales\n";
$clans = $pdo->query('SELECT id, slug, name FROM clans')->fetchAll(PDO::FETCH_ASSOC);
$clanSlugs = array_column($clans, 'slug');

assertCondition(count($clans) >= 1, "Existe al menos un clan fundacional sembrado");
assertCondition(
    in_array('cln_primordial', array_column($clans, 'id'), true),
    "Existe el clan canonico con id 'cln_primordial' (Custodios del Fuego Primordial)"
);

// Cada pergamino primordial debe pertenecer al clan primordial (contrato del plan, seccion 2.3).
$primordialClanIds = $pdo->query(
    "SELECT DISTINCT clan_id FROM spells WHERE is_genesis_sample = 1"
)->fetchAll(PDO::FETCH_COLUMN);
assertCondition(
    count($primordialClanIds) === 1 && $primordialClanIds[0] === 'cln_primordial',
    "Todos los pergaminos primordialmente pertenecen al clan 'cln_primordial'"
);

// --- FASE 6: Escuelas de magia de referencia sembradas ---
echo "\nFASE 6: Escuelas de magia\n";
$schoolCount = (int) $pdo->query('SELECT COUNT(*) FROM magic_schools')->fetchColumn();
assertCondition($schoolCount >= 3, "Existen escuelas de magia de referencia (encontradas: {$schoolCount})");

$evocationExists = (bool) $pdo->query(
    "SELECT COUNT(*) FROM magic_schools WHERE slug = 'evocation'"
)->fetchColumn();
assertCondition($evocationExists, "Existe la escuela 'evocation' (usada por los contratos del plan)");

// --- Resumen final ---
echo "\n== RESUMEN ==\n";
echo "Asertos superados: {$assertsPassed}\n";
echo "Asertos fallidos:  {$assertsFailed}\n";

if ($assertsFailed === 0) {
    echo "\nRESULTADO: EXITO — La Tarea 1.1 cumple su criterio 'Hecho cuando'.\n";
    exit(0);
}

echo "\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].\n";
exit(1);
