<?php

/**
 * test_schema_auth.php — Arnés de la Tarea 1.1 de TASKS-03.
 *
 * Verifica el DDL de autenticación, sesiones y auditoría en
 * database/schema.sql: tablas users, user_sessions, login_attempts,
 * clan_history y audit_log con sus claves foráneas e índices exigidos.
 *
 * Estrategia TDD: este arnés se escribe ANTES de extender el esquema.
 * Fase roja = las tablas de la SPEC-03 no existen todavía.
 *
 * Escenarios verificados (criterio «Hecho cuando» de la tarea):
 *   1. La ejecución del script SQL crea las 5 tablas sin errores.
 *   2. El índice en (ip_address, attempted_at) queda activo
 *      (login_attempts).
 *   3. El índice en (user_id, left_at) queda activo (clan_history).
 *   4. Extras estructurales: roles canónicos, hash de sesión único,
 *      integridad referencial y restricciones de columna.
 *
 * Ejecución: php scratch/test_schema_auth.php  (exit 0 = verde)
 */

declare(strict_types=1);

$assertsPassed = 0;
$assertsFailed = 0;

/**
 * Asegura una condición y la reporta con el formato estándar del proyecto.
 */
function assertArcane(bool $condition, string $label): void
{
    global $assertsPassed, $assertsFailed;
    if ($condition) {
        $assertsPassed++;
        echo "  OK   {$label}\n";
    } else {
        $assertsFailed++;
        echo "  FALLA {$label}\n";
    }
}

$projectRoot = dirname(__DIR__);
$schemaPath  = $projectRoot . '/database/schema.sql';
$sandboxDb   = sys_get_temp_dir() . '/grimorio_schema_auth_' . getmypid() . '.sqlite';

echo "=== Tarea 1.1 (TASKS-03): Esquema DDL de autenticación, sesiones y auditoría ===\n\n";

// ---------------------------------------------------------------------
// 1. El esquema existe y las 5 tablas nuevas están declaradas en él.
// ---------------------------------------------------------------------
echo "[1] Declaración DDL en database/schema.sql\n";

assertArcane(file_exists($schemaPath), 'Existe database/schema.sql');

$schemaSql = (string) file_get_contents($schemaPath);

$expectedTables = ['users', 'user_sessions', 'login_attempts', 'clan_history', 'audit_log'];
foreach ($expectedTables as $tableName) {
    assertArcane(
        (bool) preg_match('/CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?' . preg_quote($tableName, '/') . '\s*\(/i', $schemaSql),
        "DDL declarado para la tabla '{$tableName}'"
    );
}

// ---------------------------------------------------------------------
// 2. Ejecución real del esquema sobre SQLite de arena: cero errores.
// ---------------------------------------------------------------------
echo "\n[2] Ejecución del script SQL (SQLite sandbox)\n";

if (file_exists($sandboxDb)) {
    unlink($sandboxDb);
}

$pdo = new PDO('sqlite:' . $sandboxDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

$executionOk = true;
try {
    $pdo->exec($schemaSql);
} catch (PDOException $schemaError) {
    $executionOk = false;
    echo '  Excepción SQL: ' . $schemaError->getMessage() . "\n";
}
assertArcane($executionOk, 'El script SQL completo se ejecuta sin errores');

// Las 5 tablas existen físicamente tras la ejecución.
foreach ($expectedTables as $tableName) {
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = '{$tableName}'"
    )->fetchColumn();
    assertArcane(((int) $exists) === 1, "Tabla '{$tableName}' creada físicamente en la base");
}

// Las tablas del legado (SPEC-01) siguen presentes: el esquema es aditivo.
foreach (['clans', 'magic_schools', 'spells'] as $legacyTable) {
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = '{$legacyTable}'"
    )->fetchColumn();
    assertArcane(((int) $exists) === 1, "Tabla de legado '{$legacyTable}' preservada (esquema aditivo)");
}

// ---------------------------------------------------------------------
// 3. Índices exigidos por el criterio «Hecho cuando».
// ---------------------------------------------------------------------
echo "\n[3] Índices activos (criterio «Hecho cuando»)\n";

/**
 * Recupera la definición SQL de los índices de una tabla en el sandbox.
 *
 * @return array<int, string>
 */
function indexSqlForTable(PDO $pdo, string $tableName): array
{
    $rows = $pdo->query(
        "SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = '{$tableName}' AND sql IS NOT NULL"
    )->fetchAll(PDO::FETCH_COLUMN);
    return array_map('strtolower', $rows);
}

// Índice de defensa anti-fuerza bruta: (ip_address, attempted_at).
$loginAttemptIndexes = indexSqlForTable($pdo, 'login_attempts');
$ipAttemptIndexOk = false;
foreach ($loginAttemptIndexes as $indexSql) {
    if (str_contains($indexSql, 'ip_address') && str_contains($indexSql, 'attempted_at')) {
        $ipAttemptIndexOk = true;
    }
}
assertArcane($ipAttemptIndexOk, 'Índice activo en login_attempts (ip_address, attempted_at)');

// Índice de historial de linajes: (user_id, left_at).
$clanHistoryIndexes = indexSqlForTable($pdo, 'clan_history');
$userClanIndexOk = false;
foreach ($clanHistoryIndexes as $indexSql) {
    if (str_contains($indexSql, 'user_id') && str_contains($indexSql, 'left_at')) {
        $userClanIndexOk = true;
    }
}
assertArcane($userClanIndexOk, 'Índice activo en clan_history (user_id, left_at)');

// Índices de apoyo de la bitácora: cronología descendente y actor.
$auditIndexes = indexSqlForTable($pdo, 'audit_log');
$auditCreatedOk = false;
$auditActorOk = false;
foreach ($auditIndexes as $indexSql) {
    if (str_contains($indexSql, 'created_at')) {
        $auditCreatedOk = true;
    }
    if (str_contains($indexSql, 'actor_user_id')) {
        $auditActorOk = true;
    }
}
assertArcane($auditCreatedOk, 'Índice activo en audit_log (created_at DESC) para paginación');
assertArcane($auditActorOk, 'Índice activo en audit_log (actor_user_id)');

// ---------------------------------------------------------------------
// 4. Columnas canónicas y restricciones estructurales.
// ---------------------------------------------------------------------
echo "\n[4] Columnas canónicas y restricciones\n";

/**
 * Recupera los nombres de columna de una tabla del sandbox.
 *
 * @return array<int, string>
 */
function columnsOf(PDO $pdo, string $tableName): array
{
    // PRAGMA table_info devuelve filas de varias columnas; la segunda
    // (índice 1) es el nombre. Se extrae explícitamente porque
    // FETCH_COLUMN sin especificar tomaría 'cid' (índice 0).
    return $pdo->query("PRAGMA table_info({$tableName})")
        ->fetchAll(PDO::FETCH_COLUMN, 1);
}

$expectedColumns = [
    'users' => ['id', 'alias', 'email', 'password_hash', 'role', 'clan_id', 'created_at', 'updated_at'],
    'user_sessions' => ['id', 'session_token_hash', 'user_id', 'ip_address', 'user_agent', 'created_at', 'last_activity_at', 'expires_at', 'absolute_expires_at'],
    'login_attempts' => ['id', 'ip_address', 'attempted_identity', 'attempted_at', 'is_success'],
    'clan_history' => ['id', 'user_id', 'clan_id', 'joined_at', 'left_at'],
    'audit_log' => ['id', 'actor_user_id', 'actor_alias', 'actor_role', 'action_type', 'target_entity_type', 'target_entity_id', 'justification', 'created_at'],
];

foreach ($expectedColumns as $tableName => $columns) {
    $actualColumns = columnsOf($pdo, $tableName);
    $missing = array_diff($columns, $actualColumns);
    assertArcane(
        $missing === [],
        "Tabla '{$tableName}' porta todas sus columnas canónicas" . ($missing === [] ? '' : ' (faltan: ' . implode(', ', $missing) . ')')
    );
}

// ---------------------------------------------------------------------
// 5. Integridad referencial y semántica de roles (inserciones reales).
// ---------------------------------------------------------------------
echo "\n[5] Integridad referencial y semántica de datos\n";

// Semilla mínima de clan para las pruebas de vinculación.
$now = gmdate('Y-m-d H:i:s');
$pdo->exec(
    "INSERT INTO clans (id, slug, name, motto, domain_points, created_at)
     VALUES ('cln_test', 'test-lineage', 'Linaje de Prueba', 'Ensayo', 0, '{$now}')"
);

// 5a. Insert válido en users con rol canónico.
$stmt = null;
$userInsertOk = true;
try {
    $stmt = $pdo->prepare(
        "INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
         VALUES (:id, :alias, :email, :passwordHash, :role, :clanId, :createdAt, :updatedAt)"
    );
    $stmt->execute([
        ':id' => 'usr_test_01', ':alias' => 'friki_test', ':email' => 'friki@test.arc',
        ':passwordHash' => str_repeat('a', 60), ':role' => 'editor',
        ':clanId' => 'cln_test', ':createdAt' => $now, ':updatedAt' => $now,
    ]);
} catch (PDOException $userError) {
    $userInsertOk = false;
    echo '  Excepción: ' . $userError->getMessage() . "\n";
}
assertArcane($userInsertOk, 'INSERT válido en users con rol canónico editor');

// 5b. La restricción CHECK rechaza roles fuera del canon.
$invalidRoleRejected = false;
if ($stmt !== null) {
    try {
        $stmt->execute([
            ':id' => 'usr_test_02', ':alias' => 'hereje', ':email' => 'hereje@test.arc',
            ':passwordHash' => str_repeat('b', 60), ':role' => 'wizard_overlord',
            ':clanId' => 'cln_test', ':createdAt' => $now, ':updatedAt' => $now,
        ]);
    } catch (PDOException $roleError) {
        $invalidRoleRejected = true;
    }
}
assertArcane($invalidRoleRejected, "CHECK de users rechaza el rol fuera del canon ('wizard_overlord')");

// 5c. Sesión vinculada a usuario real.
$sessionInsertOk = true;
try {
    $pdo->exec(
        "INSERT INTO user_sessions (id, session_token_hash, user_id, ip_address, user_agent, created_at, last_activity_at, expires_at, absolute_expires_at)
         VALUES ('ses_test_01', '" . str_repeat('c', 64) . "', 'usr_test_01', '127.0.0.1', 'Arnés/1.0', '{$now}', '{$now}', '{$now}', '{$now}')"
    );
} catch (PDOException $sessionError) {
    $sessionInsertOk = false;
    echo '  Excepción: ' . $sessionError->getMessage() . "\n";
}
assertArcane($sessionInsertOk, 'INSERT válido en user_sessions para usuario existente');

// 5d. La sesión de un usuario inexistente cae por la clave foránea.
$orphanSessionRejected = false;
try {
    $pdo->exec(
        "INSERT INTO user_sessions (id, session_token_hash, user_id, ip_address, user_agent, created_at, last_activity_at, expires_at, absolute_expires_at)
         VALUES ('ses_orphan_01', '" . str_repeat('d', 64) . "', 'usr_fantasma', '127.0.0.1', 'Arnés/1.0', '{$now}', '{$now}', '{$now}', '{$now}')"
    );
} catch (PDOException $foreignKeyError) {
    $orphanSessionRejected = true;
}
assertArcane($orphanSessionRejected, 'FK de user_sessions rechaza sesiones de usuarios inexistentes');

// 5e. Historial de linaje con left_at NULL = clan activo.
$historyInsertOk = true;
try {
    $pdo->exec(
        "INSERT INTO clan_history (user_id, clan_id, joined_at, left_at)
         VALUES ('usr_test_01', 'cln_test', '{$now}', NULL)"
    );
} catch (PDOException $historyError) {
    $historyInsertOk = false;
    echo '  Excepción: ' . $historyError->getMessage() . "\n";
}
assertArcane($historyInsertOk, 'INSERT válido en clan_history con left_at NULL (clan activo)');

// 5f. Registro de auditoría inmutable con motivo solemne.
$auditInsertOk = true;
try {
    $pdo->exec(
        "INSERT INTO audit_log (actor_user_id, actor_alias, actor_role, action_type, target_entity_type, target_entity_id, justification, created_at)
         VALUES ('usr_test_01', 'friki_test', 'editor', 'SIGN_VALIDATE', 'spell', 'spl_test_01', 'Motivo solemne de prueba.', '{$now}')"
    );
} catch (PDOException $auditError) {
    $auditInsertOk = false;
    echo '  Excepción: ' . $auditError->getMessage() . "\n";
}
assertArcane($auditInsertOk, 'INSERT válido en audit_log con motivo solemne');

// ---------------------------------------------------------------------
// Limpieza del sandbox.
// ---------------------------------------------------------------------
$pdo = null;  // Cierra la conexión antes del unlink (Windows lo bloquea).
gc_collect_cycles();  // Libera cualquier handle residual del driver antes del unlink.
if (file_exists($sandboxDb)) {
    @unlink($sandboxDb);  // El fallo de borrado en Windows es cosmético: el temp se purga solo.
}

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);
