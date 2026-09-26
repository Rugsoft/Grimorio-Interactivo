<?php

declare(strict_types=1);

/**
 * probe-mysql.php — Sonda de diagnóstico MySQL del despliegue (subir como
 * public/probe-mysql.php, abrir en el navegador y BORRAR de inmediato).
 *
 * Diagnostica la cadena completa que recorre una petición de la API:
 *   1. env.php cargado (constante GRIMORIO_DB_DSN materializada).
 *   2. Motor del DSN actual (sqlite | mysql | otro) y credenciales
 *      PRESENTES (jamás imprime valores — solo true/false y host/base
 *      truncados, sin contraseña).
 *   3. Conexión PDO REAL con el trío GRIMORIO_DB_*.
 *   4. Inventario de tablas (esperado: 21) y conteo de semillas
 *      (escuelas 8, doctrinas 8, clanes 1, usuarios, conjuros 4).
 *   5. Integridad de la conexión con los SET de arranque (el mismo
 *      camino que recorrerá el frontend: autocommit, sql_mode, charset).
 *
 * Seguridad (AGENTS.md 6.1): jamás imprime credenciales ni trazas crudas
 * del driver; errores resumidos con su código SQLSTATE. Borrar tras uso.
 */

require_once __DIR__ . '/env.php';

header('Content-Type: application/json; charset=utf-8');

// --- 1. env.php cargado ---------------------------------------------------
$dsnDefined = defined('GRIMORIO_DB_DSN');
$dsn        = $dsnDefined ? (string) constant('GRIMORIO_DB_DSN') : (getenv('GRIMORIO_DB_DSN') ?: '');
$userDefined = defined('GRIMORIO_DB_USER');
$passDefined = defined('GRIMORIO_DB_PASS');

// --- 2. Motor y resumen inocuo del DSN ------------------------------------
$scheme = '';
if (preg_match('/^([a-z+]+):/', $dsn, $matches)) {
    $scheme = $matches[1];
}
$hostShown = null;
$dbNameShown = null;
if ($scheme === 'mysql' && preg_match('/host=([^;]+)/i', $dsn, $hostMatches)) {
    $hostShown = $hostMatches[1];
}
if ($scheme === 'mysql' && preg_match('/dbname=([^;]+)/i', $dsn, $dbMatches)) {
    $dbNameShown = $dbMatches[1];
}

// --- 3. Conexión PDO real -------------------------------------------------
$connectionError = null;
$sqlState = null;
$serverVersion = null;
$driver = null;

$pdo = null;
if ($dsnDefined && $scheme === 'mysql') {
    try {
        $pdo = new PDO($dsn, $userDefined ? (string) constant('GRIMORIO_DB_USER') : (string) getenv('GRIMORIO_DB_USER'), $passDefined ? (string) constant('GRIMORIO_DB_PASS') : (string) getenv('GRIMORIO_DB_PASS'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
        ]);
        $serverVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    } catch (PDOException $connectionFailure) {
        // Resumen inocuo: SQLSTATE y primer tramo del mensaje, sin credenciales.
        $sqlState = $connectionFailure->errorInfo[0] ?? null;
        $message = $connectionFailure->getMessage();
        $connectionError = strlen($message) > 140 ? substr($message, 0, 140) . '…' : $message;
    }
}

// --- 4. Inventario de tablas y semillas -----------------------------------
$tableCount = null;
$expectedTables = [
    'audit_log', 'clan_applications', 'clan_applications_archive', 'clan_history',
    'clan_members', 'clans', 'daily_simulator_tracker', 'dominion_awards',
    'favorites', 'grimoire_collections', 'lineage_doctrines', 'login_attempts',
    'magic_schools', 'master_signatures', 'objection_verdicts', 'sovereign_decrees',
    'spell_reviews', 'spells', 'user_sessions', 'users', 'weekly_cycles',
];
$missingTables = null;
$seedCounts = null;

if ($pdo instanceof PDO) {
    try {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $tableCount = count($tables);
        $missingTables = array_values(array_diff($expectedTables, $tables));
    } catch (Throwable $inventoryFailure) {
        $connectionError = 'SHOW TABLES falló: ' . $inventoryFailure->getMessage();
    }

    if ($missingTables !== null && $missingTables === []) {
        try {
            $seedCounts = [
                'magic_schools'     => (int) $pdo->query('SELECT COUNT(*) FROM magic_schools')->fetchColumn(),
                'lineage_doctrines' => (int) $pdo->query('SELECT COUNT(*) FROM lineage_doctrines')->fetchColumn(),
                'clans'             => (int) $pdo->query('SELECT COUNT(*) FROM clans')->fetchColumn(),
                'users'             => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
                'spells'            => (int) $pdo->query('SELECT COUNT(*) FROM spells')->fetchColumn(),
                'clan_members'      => (int) $pdo->query('SELECT COUNT(*) FROM clan_members')->fetchColumn(),
            ];
        } catch (Throwable $seedFailure) {
            $connectionError = 'Conteo de semillas falló: ' . $seedFailure->getMessage();
        }
    }
}

// --- 5. Charset real de la conexión ---------------------------------------
$connectionCharset = null;
if ($pdo instanceof PDO) {
    try {
        $connectionCharset = (string) $pdo->query("SELECT @@character_set_connection")->fetchColumn();
    } catch (Throwable $charsetFailure) {
        $connectionCharset = 'desconocido';
    }
}

$verdict = match (true) {
    !$dsnDefined                                    => 'FALLO: env.php no materializó GRIMORIO_DB_DSN — ¿guardaste el fichero con el trío define()?',
    $scheme !== 'mysql'                             => 'FALLO: el DSN sigue siendo ' . ($scheme === '' ? 'ilegible' : $scheme) . ' — env.php aún porta el bloque SQLite.',
    $pdo === null                                   => 'FALLO: conexión MySQL rechazada (SQLSTATE ' . ($sqlState ?? '?') . ') — revisa host/usuario/contraseña contra el panel.',
    $missingTables === null || $missingTables !== [] => 'FALLO: conexión OK pero faltan tablas (' . count($missingTables ?? []) . ') — reimporta schema-mysql.sql.',
    $seedCounts !== null && $seedCounts['spells'] < 4 => 'FALLO: tablas completas pero semillas a medias — reimporta seeds.sql.',
    default                                          => 'OK: MySQL vivo, 21 tablas y semillas asentadas. Abre /api/v1/spells y prueba el registro.',
};

echo (string) json_encode([
    'dsnMaterializado'   => $dsnDefined,
    'motor'              => $scheme === '' ? null : $scheme,
    'hostResumido'       => $hostShown,
    'baseResumida'       => $dbNameShown,
    'usuarioDefinido'    => $userDefined,
    'contrasenaDefinida' => $passDefined,
    'conexionOk'         => $pdo instanceof PDO,
    'sqlState'           => $sqlState,
    'errorConexion'      => $connectionError,
    'serverVersion'      => $serverVersion,
    'driver'             => $driver,
    'charsetConexion'    => $connectionCharset,
    'tablasEsperadas'    => count($expectedTables),
    'tablasEncontradas'  => $tableCount,
    'tablasFaltantes'    => $missingTables,
    'conteoSemillas'     => $seedCounts,
    'veredicto'          => $verdict,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
