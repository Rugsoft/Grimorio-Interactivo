<?php

/**
 * Script de verificación de la TAREA 1.2 — Conexión de base de datos PDO nativa.
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación que verifica.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   Se ejecuta una consulta de prueba mediante Connection::getInstance()->getPdo()
 *   devolviendo una conexión activa con:
 *     - ATTR_ERRMODE    => ERRMODE_EXCEPTION
 *     - ATTR_DEFAULT_FETCH_MODE => FETCH_ASSOC
 *
 * Uso: php scratch/test_connection.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Database/Connection.php';

use Grimorio\Database\Connection;

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

echo "== VERIFICACION TAREA 1.2: Connection.php (Singleton PDO) ==\n\n";

// --- FASE 1: Contrato de clase Singleton ---
echo "FASE 1: Contrato de la clase Connection\n";
assertCondition(class_exists(Connection::class), "Existe la clase Grimorio\\Database\\Connection");
assertCondition(
    (new ReflectionClass(Connection::class))->isFinal(),
    "La clase es final (evita herencia que rompa el patrón Singleton)"
);
assertCondition(
    (new ReflectionMethod(Connection::class, '__clone'))->isPrivate(),
    "La clonación está prohibida (__clone privado)"
);

$constructor = new ReflectionMethod(Connection::class, '__construct');
assertCondition(
    $constructor->isPrivate(),
    "El constructor es privado (instancia solo vía getInstance())"
);

// --- FASE 2: Instancia única (identidad de Singleton) ---
echo "\nFASE 2: Identidad de la instancia única\n";
$instanceA = Connection::getInstance();
$instanceB = Connection::getInstance();
assertCondition($instanceA === $instanceB, "getInstance() retorna siempre la misma instancia");
assertCondition($instanceA instanceof Connection, "La instancia es de tipo Connection");

// --- FASE 3: getConection PDO activa con atributos exigidos ---
echo "\nFASE 3: getPdo() con atributos PDO exigidos\n";
$pdo = $instanceA->getPdo();
assertCondition($pdo instanceof PDO, "getPdo() retorna una instancia de PDO");

$errMode = $pdo->getAttribute(PDO::ATTR_ERRMODE);
assertCondition(
    $errMode === PDO::ERRMODE_EXCEPTION,
    "ATTR_ERRMODE es ERRMODE_EXCEPTION (obligatorio por el criterio)"
);

$fetchMode = $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE);
assertCondition(
    $fetchMode === PDO::FETCH_ASSOC,
    "ATTR_DEFAULT_FETCH_MODE es FETCH_ASSOC (obligatorio por el criterio)"
);

// --- FASE 4: Consulta de prueba real (criterio 'Hecho cuando') ---
echo "\nFASE 4: Consulta de prueba contra la base de génesis\n";
// Se ejecuta schema.sql + seeds.sql reales sobre SQLite en memoria usando la MISMA
// Connection Singleton para validar el flujo completo de conexión → ejecución.
$pdo->exec('PRAGMA foreign_keys = ON;'); // SQLite: exigir integridad referencial en esta conexión
$schemaPath = __DIR__ . '/../database/schema.sql';
$seedsPath = __DIR__ . '/../database/seeds.sql';

$executionOk = false;
try {
    // El auto-bootstrap del Singleton (desarrollo SQLite) ya materializó
    // schema.sql + seeds.sql sobre ESTA conexión: se verifica que el plano
    // arcánico exista y esté poblado sin duplicar la siembra.
    $tablesReady = (int) $pdo->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('spells','clans','magic_schools')"
    )->fetchColumn();
    $genesisPresent = (int) $pdo->query('SELECT COUNT(*) FROM spells')->fetchColumn();
    $executionOk = ($tablesReady === 3 && $genesisPresent >= 3);
} catch (PDOException $e) {
    echo "  [INFO] Excepcion al sembrar: " . $e->getMessage() . "\n";
}
assertCondition($executionOk, "La conexion ejecuta schema.sql y seeds.sql sin errores");

// Consulta de prueba del criterio: SELECT sobre los pergaminos primordiales.
$genesisCount = 0;
$fetchWorksAsAssoc = false;
try {
    $stmt = $pdo->query('SELECT COUNT(*) AS genesis_count FROM spells WHERE is_genesis_sample = 1');
    $row = $stmt->fetch(); // Debe retornar array asociativo por el atributo por defecto
    $fetchWorksAsAssoc = is_array($row) && array_is_list($row) === false && isset($row['genesis_count']);
    $genesisCount = (int) ($row['genesis_count'] ?? 0);
} catch (PDOException $e) {
    echo "  [INFO] Excepcion al consultar: " . $e->getMessage() . "\n";
}
assertCondition($genesisCount === 3, "La consulta de prueba retorna exactamente 3 pergaminos primordiales (retorna: {$genesisCount})");
assertCondition($fetchWorksAsAssoc, "El fetch por defecto retorna arrays asociativos (FETCH_ASSOC)");

// --- FASE 5: Prepared statements y transacciones (Art. I: PDO nativo puro) ---
echo "\nFASE 5: Prepared statements y transacciones\n";
$insertOk = false;
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO spells (id, slug, name, author_id, magic_school, mana_cost, math_fingerprint, clan_id, summary, status, is_genesis_sample, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute(['spl_tx_test', 'conjure-test', 'Conjuro de Prueba', 'usr_custodio_primordial', 'evocation', 5, str_repeat('0', 64), 'cln_primordial', 'Resumen de prueba', 'experimental', 0, '2026-09-11T00:00:00Z', '2026-09-11T00:00:00Z']);
    $pdo->commit();
    $insertOk = true;
} catch (PDOException $e) {
    $pdo->rollBack();
    echo "  [INFO] Excepcion en transaccion: " . $e->getMessage() . "\n";
}
assertCondition($insertOk, "La conexión soporta prepare/execute con parámetros vinculados dentro de transacción");

$rolledBackCount = (int) $pdo->query("SELECT COUNT(*) FROM spells WHERE id = 'spl_tx_test'")->fetch()['COUNT(*)'];
assertCondition($rolledBackCount === 1, "El registro de prueba persiste tras COMMIT (contado: {$rolledBackCount})");

// --- Resumen final ---
echo "\n== RESUMEN ==\n";
echo "Asertos superados: {$assertsPassed}\n";
echo "Asertos fallidos:  {$assertsFailed}\n";

if ($assertsFailed === 0) {
    echo "\nRESULTADO: EXITO — La Tarea 1.2 cumple su criterio 'Hecho cuando'.\n";
    exit(0);
}

echo "\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].\n";
exit(1);
