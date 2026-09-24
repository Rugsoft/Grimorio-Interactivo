<?php

declare(strict_types=1);

/**
 * test_moderation_schema.php — Verificación de la Tarea 1.1 de TASKS-08.
 *
 * Valida contra el criterio «Hecho cuando» de
 * specs/08-moderation-two-step.tasks.md:
 *   «La ejecución del script SQL sobre SQLite crea las 4 tablas con sus
 *    restricciones e índices sin errores de sintaxis, y un intento de
 *    insertar dos firmas no revocadas del mismo usuario sobre un mismo
 *    conjuro lanza un error de unicidad.»
 *
 * Estrategia: se levanta una base SQLite efímera en memoria y se aplica la
 * secuencia canónica real del santuario —database/schema.sql, database/seeds.sql
 * y sql/08_moderation_schema.sql—, comprobando después el contrato del
 * cónclave: estructura, tipos estrictos, índices, unicidad condicional de la
 * firma, integridad referencial y cascada del conjuro.
 *
 * Fases:
 *   [0]  Superficie: los tres scripts SQL existen y el DDL es idempotente.
 *   [1]  La secuencia canónica completa se ejecuta sin errores; el guion de
 *        ascensión puede re-aplicarse sin daño.
 *   [2]  Las 4 tablas del cónclave existen, también sobre una base desnuda.
 *   [3]  Contrato de columnas: nombres, NOT NULL, DEFAULT y clave primaria.
 *   [4]  Tipos estrictos: los CHECK del dominio de la especificación muerden.
 *   [5]  Índices exigidos por el plan 2.1; el de firma es ÚNICO y PARCIAL.
 *   [6]  El criterio «Hecho cuando»: dos firmas activas del mismo Maestro
 *        sobre el mismo conjuro son rechazadas por el índice único.
 *   [7]  La unicidad es CONDICIONAL: revocada la firma, el Maestro puede
 *        volver a avalar (RF-02.4); tres Maestros distintos consagran.
 *   [8]  Integridad referencial a spells(id), users(id) y clans(id), y
 *        cascada del conjuro sobre todo su rastro de moderación.
 *   [9]  Anti-deriva: el DDL de las dos moradas coincide; Dogma Vanilla.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin librerías externas.
 *   - Artículo V (Dualidad): identificadores en inglés snake_case; narrativa
 *     y comentarios en noble castellano.
 *
 * Uso: php scratch/test_moderation_schema.php
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

/** Normaliza espacios en blanco y comentarios para comparar DDL. */
function normalizeSql(string $sql): string
{
    $withoutComments = (string) preg_replace('/--[^\n]*/', ' ', $sql);
    return (string) preg_replace('/\s+/', ' ', trim($withoutComments));
}

/** Extrae un `CREATE TABLE` completo de un fuente SQL. */
function extractCreateTable(string $source, string $tableName): string
{
    $pattern = '/CREATE TABLE IF NOT EXISTS\s+' . preg_quote($tableName, '/') . '\s*\(.*?\n\);/s';
    return preg_match($pattern, $source, $matches) === 1 ? $matches[0] : '';
}

/** Extrae un `CREATE INDEX` completo de un fuente SQL (puede ocupar dos líneas). */
function extractCreateIndex(string $source, string $indexName): string
{
    $pattern = '/CREATE UNIQUE INDEX IF NOT EXISTS\s+' . preg_quote($indexName, '/') . '.*?;/s';
    if (preg_match($pattern, $source, $matches) === 1) {
        return $matches[0];
    }
    $plain = '/CREATE INDEX IF NOT EXISTS\s+' . preg_quote($indexName, '/') . '.*?;/s';
    return preg_match($plain, $source, $matches) === 1 ? $matches[0] : '';
}

/** Describe una tabla como [columna => [notnull, default, pk]]. */
function tableContract(PDO $pdo, string $tableName): array
{
    $contract = [];
    foreach ($pdo->query("PRAGMA table_info({$tableName})")->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $contract[(string) $column['name']] = [
            'notnull' => (int) $column['notnull'],
            'default' => $column['dflt_value'],
            'pk' => (int) $column['pk'],
        ];
    }
    return $contract;
}

/** ¿Lanza este INSERT un error de la base? Devuelve el mensaje o null. */
function insertError(PDO $pdo, string $sql): ?string
{
    try {
        $pdo->exec($sql);
        return null;
    } catch (PDOException $exception) {
        return $exception->getMessage();
    }
}

echo "== VERIFICACION TAREA 1.1: Esquema DDL del Conclave de Moderacion ==\n\n";

$projectRoot = dirname(__DIR__);
$schemaPath = $projectRoot . '/database/schema.sql';
$seedsPath = $projectRoot . '/database/seeds.sql';
$ascensionPath = $projectRoot . '/sql/08_moderation_schema.sql';

$MODERATION_TABLES = ['spell_reviews', 'master_signatures', 'objection_verdicts', 'sovereign_decrees'];
$MODERATION_INDEXES = ['idx_active_master_signature', 'idx_reviews_queue', 'idx_reviews_author_active', 'idx_signatures_spell_active'];

// --- FASE 0: Superficie de los scripts SQL ---
echo "FASE 0: Superficie de los scripts SQL\n";
assertCondition(file_exists($schemaPath), 'Existe el esquema canonico database/schema.sql');
assertCondition(file_exists($seedsPath), 'Existen las semillas database/seeds.sql');
assertCondition(file_exists($ascensionPath), 'Existe la migracion sql/08_moderation_schema.sql (entregable de la tarea)');

if (!file_exists($ascensionPath)) {
    echo "\nRESULTADO: DENEGADO — falta la migracion de SPEC-08 (fase roja del TDD).\n";
    exit(1);
}

$schemaSource = (string) file_get_contents($schemaPath);
$ascensionSource = (string) file_get_contents($ascensionPath);
// Las comprobaciones de dialecto miran las SENTENCIAS, no la narrativa: el
// comentario del guion nombra `ALTER TABLE` y `VARCHAR(n)` para explicar por
// qué no se usan.
$ascensionStatements = normalizeSql($ascensionSource);

foreach ($MODERATION_TABLES as $tableName) {
    assertCondition(
        str_contains($ascensionSource, "CREATE TABLE IF NOT EXISTS {$tableName}"),
        "El guion declara la tabla '{$tableName}' de forma IDEMPOTENTE (IF NOT EXISTS)"
    );
}
foreach ($MODERATION_INDEXES as $indexName) {
    assertCondition(
        str_contains($ascensionSource, "INDEX IF NOT EXISTS {$indexName}"),
        "El guion declara el indice '{$indexName}' de forma IDEMPOTENTE"
    );
}
assertCondition(
    str_contains(strtoupper($ascensionStatements), 'ALTER TABLE') === false,
    'El guion no muta columnas ajenas: nace con las cuatro tablas enteras (a diferencia de SPEC-07)'
);
assertCondition(
    preg_match('#https?://#', $ascensionSource) !== 1,
    'El DDL no arrastra recurso externo alguno (Articulo I, Dogma Vanilla)'
);
assertCondition(
    preg_match('/\bVARCHAR\s*\(/i', $ascensionStatements) !== 1,
    'El dialecto usa TEXT en lugar de VARCHAR(n): la acotacion viaja en CHECK, aplicable en SQLite y MySQL'
);

// Base de datos SQLite efímera en memoria: jamás contamina el santuario real.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON;');

// --- FASE 1: Ejecución de la secuencia canónica completa ---
echo "\nFASE 1: Ejecucion de la secuencia canonica (schema + seeds + migracion)\n";
$executionOk = true;
try {
    $pdo->exec($schemaSource);
    $pdo->exec((string) file_get_contents($seedsPath));
    // Retrato del patrimonio ANTES de la migracion: SPEC-08 no toca `spells`.
    $spellCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM spells')->fetchColumn();
    $genesisCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM spells WHERE is_genesis_sample = 1')->fetchColumn();
    $pdo->exec($ascensionSource);
} catch (PDOException $exception) {
    $executionOk = false;
    echo '  [FALLA] La secuencia SQL lanzo excepcion: ' . $exception->getMessage() . "\n";
}
assertCondition($executionOk, 'schema.sql + seeds.sql + sql/08_moderation_schema.sql se ejecutan sin errores de sintaxis');

if (!$executionOk) {
    echo "\nRESULTADO: DENEGADO — el DDL no se aplica limpiamente sobre SQLite.\n";
    exit(1);
}

$secondRunOk = true;
try {
    $pdo->exec($ascensionSource);
} catch (PDOException $exception) {
    $secondRunOk = false;
    echo '  [FALLA] La re-aplicacion lanzo excepcion: ' . $exception->getMessage() . "\n";
}
assertCondition($secondRunOk, 'El guion de ascension se re-aplica sin dano (idempotencia plena)');

// --- FASE 2: Las cuatro tablas del cónclave ---
echo "\nFASE 2: Las cuatro tablas del Conclave de Moderacion\n";
$existingTables = $pdo->query(
    "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
)->fetchAll(PDO::FETCH_COLUMN);
foreach ($MODERATION_TABLES as $tableName) {
    assertCondition(in_array($tableName, $existingTables, true), "Existe la tabla '{$tableName}'");
}

// La migración de ascensión, sola, también deja la base completa: sobre una
// base desnuda (sin el DDL raíz) crea las cuatro tablas del cónclave.
$barePdo = new PDO('sqlite::memory:');
$barePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$bareRunOk = true;
try {
    $barePdo->exec($ascensionSource);
} catch (PDOException $exception) {
    $bareRunOk = false;
    echo '  [FALLA] La ascension sobre base desnuda fallo: ' . $exception->getMessage() . "\n";
}
assertCondition($bareRunOk, 'El guion se aplica solo, sin depender del DDL raiz (via de ascension real)');

// --- FASE 3: Contrato de columnas de cada tabla ---
echo "\nFASE 3: Contrato de columnas (nombres, NOT NULL y DEFAULT del plan 2.1)\n";
$expectedColumns = [
    'spell_reviews' => [
        'id', 'spell_id', 'author_id', 'origin_clan_id', 'status', 'signatures_count',
        'math_fingerprint', 'submitted_at', 'validated_at', 'rejected_at', 'reopened_at', 'archived_at',
    ],
    'master_signatures' => [
        'id', 'spell_id', 'master_id', 'master_clan_id', 'ceremonial_gloss', 'signed_at',
        'is_revoked', 'revoked_at', 'revocation_reason',
    ],
    'objection_verdicts' => ['id', 'spell_id', 'master_id', 'objection_reason', 'objected_at'],
    'sovereign_decrees' => ['id', 'spell_id', 'admin_id', 'decree_type', 'imperial_decree_text', 'decreed_at'],
];
foreach ($expectedColumns as $tableName => $columns) {
    $contract = tableContract($pdo, $tableName);
    assertCondition(
        array_keys($contract) === $columns,
        "La tabla '{$tableName}' declara exactamente su contrato de columnas, en orden canonico"
    );
}

$reviewContract = tableContract($pdo, 'spell_reviews');
assertCondition(
    $reviewContract['id']['pk'] === 1 && $reviewContract['spell_id']['pk'] === 0,
    'spell_reviews.id es la unica clave primaria de la tabla'
);
assertCondition(
    $reviewContract['status']['default'] === "'draft'" && $reviewContract['status']['notnull'] === 1,
    "spell_reviews.status nace en borrador privado ('draft') y no admite nulos (RF-01.1)"
);
assertCondition(
    $reviewContract['signatures_count']['default'] === '0',
    'spell_reviews.signatures_count arranca en cero firmas'
);
assertCondition(
    $reviewContract['origin_clan_id']['notnull'] === 0 && $reviewContract['submitted_at']['notnull'] === 0,
    'El clan de origen y las marcas del ciclo de vida admiten NULL (ermitanos y estados no alcanzados)'
);

$signatureContract = tableContract($pdo, 'master_signatures');
assertCondition(
    $signatureContract['is_revoked']['default'] === '0' && $signatureContract['is_revoked']['notnull'] === 1,
    'master_signatures.is_revoked nace en cero y no admite nulos'
);
assertCondition(
    $signatureContract['ceremonial_gloss']['notnull'] === 0,
    'La glosa liturgica es opcional (RF-02.2)'
);

// La relación 1:1 con `spells` que sostiene el cupo de tres y la cola del Atrio.
$reviewDdl = (string) $pdo->query(
    "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'spell_reviews'"
)->fetchColumn();
assertCondition(
    str_contains(normalizeSql($reviewDdl), 'spell_id TEXT NOT NULL UNIQUE'),
    "spell_reviews guarda una UNICA revision viva por conjuro (RF-01.2, RF-05.1)"
);

// --- FASE 4: Tipos estrictos: los CHECK de la especificación muerden ---
echo "\nFASE 4: Tipos estrictos del dominio cerrado por la especificacion\n";
$now = '2026-09-15T10:00:00Z';
$fingerprint = str_repeat('a', 64);
$authorFingerprint = str_repeat('b', 64);

// Semilla mínima: un autor con casa, su conjuro y un Maestro independiente.
$pdo->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
            VALUES ('usr_autor_08', 'Autora del Conclave', 'autora08@arcano.arc', 'x', 'editor', 'cln_primordial', '{$now}', '{$now}')");
$pdo->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
            VALUES ('usr_maestro_08', 'Maestro del Conclave', 'maestro08@arcano.arc', 'x', 'master', NULL, '{$now}', '{$now}')");
$pdo->exec("INSERT INTO spells (id, slug, name, author_id, magic_school, mana_cost, clan_id, summary, math_fingerprint, created_at, updated_at)
            VALUES ('spl_conclave', 'conclave-de-prueba', 'Conclave de Prueba', 'usr_autor_08', 'evocation', 20, 'cln_primordial', 'Obra en tela de juicio.', '{$fingerprint}', '{$now}', '{$now}')");

$validReview = "INSERT INTO spell_reviews (id, spell_id, author_id, origin_clan_id, status, signatures_count, math_fingerprint, submitted_at)
                VALUES ('rev_uno', 'spl_conclave', 'usr_autor_08', 'cln_primordial', 'experimental', 0, '{$authorFingerprint}', '{$now}')";
assertCondition(insertError($pdo, $validReview) === null, 'Una revision experimental con huella de 64 caracteres se inscribe (Art. II)');

assertCondition(
    insertError($pdo, "UPDATE spell_reviews SET status = 'enDeliberacion' WHERE id = 'rev_uno'") !== null,
    'Un estado fuera de los cinco canonicos de RF-01.1 es rechazado por la base'
);
assertCondition(
    insertError($pdo, "UPDATE spell_reviews SET signatures_count = 4 WHERE id = 'rev_uno'") !== null,
    'Una cuarta firma es rechazada por la base: el techo de RF-02.1 no depende del llamador'
);
assertCondition(
    insertError($pdo, "UPDATE spell_reviews SET math_fingerprint = '{$fingerprint}' || 'a' WHERE id = 'rev_uno'") !== null,
    'Una huella del balance truncada o inflada es rechazada (Art. II, huella SHA-256 de 64)'
);
assertCondition(
    insertError($pdo, "UPDATE spell_reviews SET signatures_count = 3 WHERE id = 'rev_uno'") === null,
    'El contador admite el techo exacto de tres firmas'
);
$pdo->exec("UPDATE spell_reviews SET signatures_count = 0 WHERE id = 'rev_uno'");

$signatureSql = static function (string $id, string $masterId, string $spellId, ?string $gloss, string $signedAt): string {
    $glossValue = $gloss === null ? 'NULL' : "'{$gloss}'";
    return "INSERT INTO master_signatures (id, spell_id, master_id, master_clan_id, ceremonial_gloss, signed_at)
            VALUES ('{$id}', '{$spellId}', '{$masterId}', NULL, {$glossValue}, '{$signedAt}')";
};

assertCondition(
    insertError($pdo, $signatureSql('sig_glosa_larga', 'usr_maestro_08', 'spl_conclave', str_repeat('g', 251), $now)) !== null,
    'Una glosa de 251 caracteres es rechazada: RF-02.2 acota la alabanza a 250'
);
assertCondition(
    insertError($pdo, $signatureSql('sig_glosa_justa', 'usr_maestro_08', 'spl_conclave', str_repeat('g', 250), $now)) === null,
    'Una glosa de 250 caracteres exactos es admitida'
);
assertCondition(
    insertError($pdo, "INSERT INTO objection_verdicts (id, spell_id, master_id, objection_reason, objected_at)
                       VALUES ('obj_corta', 'spl_conclave', 'usr_maestro_08', 'No me gusta.', '{$now}')") !== null,
    'Una objecion de menos de 20 caracteres es rechazada (RF-02.5)'
);
assertCondition(
    insertError($pdo, "INSERT INTO objection_verdicts (id, spell_id, master_id, objection_reason, objected_at)
                       VALUES ('obj_justa', 'spl_conclave', 'usr_maestro_08', 'El balance rompe la ley del mana.', '{$now}')") === null,
    'Una objecion fundamentada en castellano se inscribe'
);
assertCondition(
    insertError($pdo, "INSERT INTO sovereign_decrees (id, spell_id, admin_id, decree_type, imperial_decree_text, decreed_at)
                       VALUES ('dec_largo', 'spl_conclave', 'usr_maestro_08', 'soberanoPorDecreto', 'Edicto con tipo no canonico.', '{$now}')") !== null,
    'Un tipo de decreto ajeno a los cuatro soberanos de RF-04 es rechazado'
);
assertCondition(
    insertError($pdo, "INSERT INTO sovereign_decrees (id, spell_id, admin_id, decree_type, imperial_decree_text, decreed_at)
                       VALUES ('dec_corto', 'spl_conclave', 'usr_maestro_08', 'sovereignValidation', 'Sin razon.', '{$now}')") !== null,
    'Un edicto imperial sin justificacion de 20 caracteres es rechazado (RF-04.5)'
);
assertCondition(
    insertError($pdo, "INSERT INTO sovereign_decrees (id, spell_id, admin_id, decree_type, imperial_decree_text, decreed_at)
                       VALUES ('dec_justo', 'spl_conclave', 'usr_maestro_08', 'sovereignValidation', 'Se eleva por merito excepcional.', '{$now}')") === null,
    'Un edicto imperial razonado se asienta'
);
$pdo->exec("DELETE FROM sovereign_decrees WHERE id = 'dec_justo'");
$pdo->exec("DELETE FROM objection_verdicts WHERE id = 'obj_justa'");

// --- FASE 5: Índices exigidos por el plan 2.1 ---
echo "\nFASE 5: Indices exigidos por el plan 2.1\n";
$indexRows = $pdo->query(
    "SELECT name, tbl_name, sql FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL"
)->fetchAll(PDO::FETCH_ASSOC);
$indexNames = array_column($indexRows, 'name');
foreach ($MODERATION_INDEXES as $indexName) {
    assertCondition(in_array($indexName, $indexNames, true), "Existe el indice '{$indexName}'");
}

$activeSignatureIndex = null;
foreach ($pdo->query('PRAGMA index_list(master_signatures)')->fetchAll(PDO::FETCH_ASSOC) as $indexListRow) {
    if ($indexListRow['name'] === 'idx_active_master_signature') {
        $activeSignatureIndex = $indexListRow;
    }
}
assertCondition(
    $activeSignatureIndex !== null && (int) $activeSignatureIndex['unique'] === 1,
    'El indice idx_active_master_signature es UNICO'
);
assertCondition(
    $activeSignatureIndex !== null && (int) $activeSignatureIndex['partial'] === 1,
    'El indice idx_active_master_signature es PARCIAL (no plena unicidad del historial)'
);
$activeSignatureIndexSql = (string) $pdo->query(
    "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'idx_active_master_signature'"
)->fetchColumn();
assertCondition(
    str_contains(strtolower($activeSignatureIndexSql), 'where is_revoked = 0'),
    'La unicidad se acota a las firmas NO revocadas: retractarse devuelve el derecho a avalar (RF-02.4)'
);

// --- FASE 6: El criterio «Hecho cuando» ---
echo "\nFASE 6: El indice condicional impide dos firmas activas del mismo Maestro (criterio)\n";
$pdo->exec("DELETE FROM master_signatures");
assertCondition(
    insertError($pdo, $signatureSql('sig_uno', 'usr_maestro_08', 'spl_conclave', null, $now)) === null,
    'Se registra la primera firma activa del Maestro sobre el conjuro'
);
$duplicateError = insertError($pdo, $signatureSql('sig_dos', 'usr_maestro_08', 'spl_conclave', null, $now));
assertCondition(
    $duplicateError !== null && str_contains(strtoupper($duplicateError), 'UNIQUE') === true,
    'Una segunda firma NO revocada del mismo Maestro sobre el mismo conjuro lanza error de unicidad'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_conclave' AND is_revoked = 0")->fetchColumn() === 1,
    'La firma duplicada no llega a inscribirse: la base rechaza antes de contar'
);

// --- FASE 7: La unicidad es condicional, no plana (RF-02.1 y RF-02.4) ---
echo "\nFASE 7: La unicidad es condicional: retractacion, pluralidad y terceros\n";
$pdo->exec("UPDATE master_signatures SET is_revoked = 1, revoked_at = '{$now}',
            revocation_reason = 'retracted' WHERE id = 'sig_uno'");
assertCondition(
    insertError($pdo, $signatureSql('sig_tres', 'usr_maestro_08', 'spl_conclave', 'Reconsiderada y avalada.', '2026-09-15T11:00:00Z')) === null,
    'Revocada la firma previa, el Maestro puede volver a avalar la obra (RF-02.4)'
);

$pdo->exec("INSERT INTO spells (id, slug, name, author_id, magic_school, mana_cost, clan_id, summary, math_fingerprint, created_at, updated_at)
            VALUES ('spl_tercero', 'tercero-de-prueba', 'Tercero de Prueba', 'usr_autor_08', 'evocation', 30, 'cln_primordial', 'Otra obra.', '{$fingerprint}', '{$now}', '{$now}')");
assertCondition(
    insertError($pdo, $signatureSql('sig_cuarto', 'usr_maestro_08', 'spl_tercero', null, $now)) === null,
    'El mismo Maestro sí puede avalar un conjuro distinto: la unicidad mira el par (conjuro, Maestro)'
);

foreach (['usr_maestro_dos', 'usr_maestro_tres'] as $index => $masterId) {
    $pdo->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
                VALUES ('{$masterId}', 'Maestro Independiente {$index}', '{$masterId}@arcano.arc', 'x', 'master', NULL, '{$now}', '{$now}')");
}
assertCondition(
    insertError($pdo, $signatureSql('sig_cinco', 'usr_maestro_dos', 'spl_conclave', null, $now)) === null
    && insertError($pdo, $signatureSql('sig_seis', 'usr_maestro_tres', 'spl_conclave', null, $now)) === null,
    'Tres Maestros independientes conviven sobre la misma obra (RF-02.1: consagracion por pluralidad)'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_conclave' AND is_revoked = 0")->fetchColumn() === 3,
    'El conteo activo del conjuro alcanza las tres firmas exigidas'
);

// --- FASE 8: Integridad referencial y cascada ---
echo "\nFASE 8: Integridad referencial y cascada del conjuro\n";
assertCondition(
    insertError($pdo, "INSERT INTO spell_reviews (id, spell_id, author_id, status, signatures_count, math_fingerprint)
                       VALUES ('rev_huerfano', 'spl_inexistente', 'usr_autor_08', 'draft', 0, '{$fingerprint}')") !== null,
    'La FK spell_reviews.spell_id -> spells.id rechaza revisiones de conjuros inexistentes'
);
assertCondition(
    insertError($pdo, "INSERT INTO spell_reviews (id, spell_id, author_id, status, signatures_count, math_fingerprint)
                       VALUES ('rev_huerfano2', 'spl_tercero', 'usr_inexistente', 'draft', 0, '{$fingerprint}')") !== null,
    'La FK spell_reviews.author_id -> users.id rechaza autores huerfanos'
);
assertCondition(
    insertError($pdo, "INSERT INTO spell_reviews (id, spell_id, author_id, origin_clan_id, status, signatures_count, math_fingerprint)
                       VALUES ('rev_huerfano3', 'spl_tercero', 'usr_autor_08', 'cln_inexistente', 'draft', 0, '{$fingerprint}')") !== null,
    'La FK spell_reviews.origin_clan_id -> clans.id rechaza clanes inexistentes'
);
assertCondition(
    insertError($pdo, $signatureSql('sig_huerfano', 'usr_inexistente', 'spl_conclave', null, $now)) !== null,
    'La FK master_signatures.master_id -> users.id rechaza firmantes huerfanos'
);
assertCondition(
    insertError($pdo, "INSERT INTO master_signatures (id, spell_id, master_id, master_clan_id, signed_at)
                       VALUES ('sig_huerfano2', 'spl_conclave', 'usr_maestro_08', 'cln_inexistente', '{$now}')") !== null,
    'La FK master_signatures.master_clan_id -> clans.id ata la memoria del clan del firmante (Art. III)'
);

$pdo->exec("INSERT INTO objection_verdicts (id, spell_id, master_id, objection_reason, objected_at)
            VALUES ('obj_cascada', 'spl_tercero', 'usr_maestro_08', 'Objeción para probar la cascada.', '{$now}')");
$pdo->exec("INSERT INTO sovereign_decrees (id, spell_id, admin_id, decree_type, imperial_decree_text, decreed_at)
            VALUES ('dec_cascada', 'spl_tercero', 'usr_maestro_08', 'rescueToExperimental', 'Rescate para probar la cascada.', '{$now}')");
$pdo->exec("INSERT INTO spell_reviews (id, spell_id, author_id, status, signatures_count, math_fingerprint)
            VALUES ('rev_cascada', 'spl_tercero', 'usr_autor_08', 'experimental', 0, '{$fingerprint}')");
$pdo->exec("DELETE FROM spells WHERE id = 'spl_tercero'");
$cascadeVisible = true;
foreach ([
    'spell_reviews' => "spell_id = 'spl_tercero'",
    'master_signatures' => "spell_id = 'spl_tercero'",
    'objection_verdicts' => "spell_id = 'spl_tercero'",
    'sovereign_decrees' => "spell_id = 'spl_tercero'",
] as $tableName => $whereClause) {
    $rows = (int) $pdo->query("SELECT COUNT(*) FROM {$tableName} WHERE {$whereClause}")->fetchColumn();
    if ($rows !== 0) {
        $cascadeVisible = false;
    }
}
assertCondition(
    $cascadeVisible,
    'La desaparicion de un conjuro arrastra su revision, firmas, dictamenes y decretos (ON DELETE CASCADE)'
);

$orphanReviewError = insertError($pdo, "INSERT INTO spell_reviews (id, spell_id, author_id, status, signatures_count, math_fingerprint)
                                        VALUES ('rev_otro_huerfano', 'spl_conclave_x', 'usr_autor_08', 'draft', 0, '{$fingerprint}')");
assertCondition(
    $orphanReviewError !== null,
    'Ninguna revision sobrevive a su conjuro: la revision es 1:1 con la obra'
);

// --- FASE 9: Anti-deriva entre las dos moradas del DDL y Dogma Vanilla ---
echo "\nFASE 9: Anti-deriva del DDL y Dogma Vanilla\n";
foreach ($MODERATION_TABLES as $tableName) {
    $canonicalDdl = normalizeSql(extractCreateTable($schemaSource, $tableName));
    $ascensionDdl = normalizeSql(extractCreateTable($ascensionSource, $tableName));
    assertCondition(
        $canonicalDdl !== '' && $canonicalDdl === $ascensionDdl,
        "El DDL de '{$tableName}' es IDENTICO en database/schema.sql y en la migracion (sin deriva)"
    );
}
$canonicalUniqueIndex = normalizeSql(extractCreateIndex($schemaSource, 'idx_active_master_signature'));
$ascensionUniqueIndex = normalizeSql(extractCreateIndex($ascensionSource, 'idx_active_master_signature'));
assertCondition(
    $canonicalUniqueIndex !== '' && $canonicalUniqueIndex === $ascensionUniqueIndex,
    'El indice condicional de firma unica es identico en ambas moradas'
);
assertCondition(
    str_contains($schemaSource, 'CREATE TABLE IF NOT EXISTS spell_reviews'),
    'El DDL canonico (database/schema.sql) ya incorpora el conclave: ninguna base nueva nace sin el'
);

// El patrimonio del santuario queda intacto tras la migración: el cónclave
// añade tablas propias y no reescribe un solo conjuro.
$spellCount = (int) $pdo->query('SELECT COUNT(*) FROM spells')->fetchColumn();
$genesisCount = (int) $pdo->query('SELECT COUNT(*) FROM spells WHERE is_genesis_sample = 1')->fetchColumn();
assertCondition(
    $spellCount >= $spellCountBefore && $genesisCount === $genesisCountBefore,
    'El patrimonio del santuario permanece intacto tras la migracion (cero conjuros alterados)'
);

// --- Resumen ---
echo "\n== RESUMEN == Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";
if ($assertsFailed === 0) {
    echo "RESULTADO: EXITO — El Conclave de Moderacion se inscribe con tipos estrictos y firma unica activa (Tarea 1.1).\n";
    exit(0);
}
echo "RESULTADO: DENEGADO — El esquema del Conclave no cumple su criterio 'Hecho cuando'.\n";
exit(1);
