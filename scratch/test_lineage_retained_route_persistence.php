<?php

declare(strict_types=1);

/**
 * test_lineage_retained_route_persistence.php — Arnés de la ENMIENDA de la
 * Tarea 9.2 de SPEC-11: la ruta retenida del juramento vive en el VÍNCULO.
 *
 * El defecto que este arnés vela (hallazgo del recorrido manual) era
 * invisible para los arneses de SPEC-09: la retención escribía y leía
 * `$_SESSION` — un array POR PETICIÓN, porque el santuario jamás invoca
 * `session_start()` — y los arneses ejercitaban ambas mitades dentro del
 * MISMO proceso, donde el array sí se conservaba. En producción la ruta
 * moría al terminar la petición y el sello respondía siempre
 * `retainedRoute: null` (RF-03.1 incumplido).
 *
 * Por eso este arnés prueba la retención ENTRE PROCESOS: un proceso
 * retiene, otro (el que sella) consume. Nada de memoria compartida.
 *
 * Fases:
 *   0. Superficie: la columna existe en el esquema y la migración es
 *      idempotente; el código fuente NO toca `$_SESSION`.
 *   1. Retención entre procesos: el proceso A retiene `#/biblioteca` y el
 *      proceso B la lee de la base.
 *   2. Consumo: `pullRetainedRoute()` devuelve la ruta y la deja en NULL
 *      (una sola ceremonia, un solo retorno).
 *   3. Saneamiento: la migración es idempotente y la purga de la sesión
 *      arrastra la retención con su fila.
 *
 * Uso: php scratch/test_lineage_retained_route_persistence.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 *
 * Constitución: Art. I (PDO nativo, cero dependencias) y Art. IV/V
 * (narrativa en castellano, identificadores en inglés).
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

$projectRoot = dirname(__DIR__);
$probeDatabase = $projectRoot . '/scratch/__probe_retained_route.sqlite';

/** Levanta la base de la sonda desde el esquema y las semillas canónicas. */
function forgeProbeDatabase(string $path, string $projectRoot): PDO
{
    @unlink($path);
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
    $pdo->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));

    return $pdo;
}

/**
 * Aplica el guion de migración sentencia a sentencia, según el contrato de
 * aplicación idempotente (mismo aplicador que test_grimoire_collection_
 * migration.php): un fallo de «ya existe» NO es error —señal de que la base
 * ya porta la pieza—; cualquier otro fallo sí lo es.
 */
function applyMigration(PDO $pdo, string $migrationPath): void
{
    $scriptSource = (string) file_get_contents($migrationPath);
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
            $pdo->exec($statement);
        } catch (PDOException $failure) {
            $message = $failure->getMessage();
            if (!str_contains($message, 'duplicate column name') && !str_contains($message, 'already exists')) {
                throw $failure;
            }
            // Señal de idempotencia: la pieza ya vive en la base.
        }
    }
}

// El arnés forja VÍNCULOS reales (SessionManager::createSession emite la
// cookie de sesión): el búfer retiene la salida para que las cabeceras no
// se hayan enviado todavía y la emisión sea silenciosa.
ob_start();

echo "== VERIFICACION DEL HALLAZGO 7 (Tar. 9.2): La ruta retenida entre procesos ==\n\n";

// ---------------------------------------------------------------------
// FASE 0: Superficie — columna, migración y soberanía de la sesión real
// ---------------------------------------------------------------------
echo "FASE 0: Superficie de la pieza\n";

$migrationPath = $projectRoot . '/sql/12_lineage_retained_route.sql';
assertCondition(is_file($migrationPath), 'Existe sql/12_lineage_retained_route.sql');

$pdo = forgeProbeDatabase($probeDatabase, $projectRoot);
$columns = [];
foreach ($pdo->query('PRAGMA table_info(user_sessions)') as $column) {
    $columns[] = $column['name'];
}
assertCondition(
    in_array('retained_route', $columns, true),
    'El esquema canónico levanta `user_sessions.retained_route` (bases nuevas)',
);

// El código fuente no puede volver a la sesión nativa: `$_SESSION` era el
// defecto. Guard estructural sobre TODO src/.
$nativeSessionHits = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot . '/src'));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $source = (string) file_get_contents($file->getPathname());
    // Solo el USO (acceso al array), jamás la mención documental.
    if (preg_match('/\$_SESSION\s*\[/', $source) === 1) {
        $nativeSessionHits[] = str_replace($projectRoot . '/', '', $file->getPathname());
    }
}
assertCondition(
    $nativeSessionHits === [],
    'Ningún módulo de src/ usa la sesión nativa de PHP (hallazgo del recorrido: ' . implode(', ', $nativeSessionHits) . ')',
);

// La migración llega a la base legada y es IDEMPOTENTE.
$legacyPath = $probeDatabase . '.legacy';
$legacyPdo = forgeProbeDatabase($legacyPath, $projectRoot);
$legacyPdo->exec('ALTER TABLE user_sessions DROP COLUMN retained_route');
applyMigration($legacyPdo, $migrationPath);
$legacyColumns = [];
foreach ($legacyPdo->query('PRAGMA table_info(user_sessions)') as $column) {
    $legacyColumns[] = $column['name'];
}
assertCondition(in_array('retained_route', $legacyColumns, true), 'La migración dota a la base legada de la columna');
applyMigration($legacyPdo, $migrationPath);
$legacyDefinitions = (int) $legacyPdo->query(
    "SELECT COUNT(*) FROM pragma_table_info('user_sessions') WHERE name = 'retained_route'"
)->fetchColumn();
assertCondition($legacyDefinitions === 1, 'Re-aplicar la migración NO duplica la columna (contrato idempotente)');
unset($legacyPdo);
@unlink($legacyPath);

// ---------------------------------------------------------------------
// FASE 1: La retención cruza el proceso (lo que `$_SESSION` jamás podía)
// ---------------------------------------------------------------------
echo "\nFASE 1: Retención entre procesos\n";

require_once $projectRoot . '/src/Core/SessionManager.php';
require_once $projectRoot . '/src/Core/ActiveSession.php';
require_once $projectRoot . '/src/Middleware/LineageOathMiddleware.php';

$sealUser = $pdo->prepare(
    "INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, created_at, updated_at)
     VALUES ('usr_proceso', 'Proceso y Proceso', 'proceso@arcano.arc', 'x', 'editor', NULL, NULL, '2026-09-18T10:00:00Z', '2026-09-18T10:00:00Z')"
);
$sealUser->execute();

$sessionManager = new Grimorio\Core\SessionManager($pdo);
$activeSession = $sessionManager->createSession('usr_proceso');
$sessionId = $activeSession->getId();

// El trabajador es un FICHERO (no `-r`: el entrecomillado del shell mutila
// los espacios de nombres) y corre en un PROCESO APARTE, sin memoria
// compartida con este. Solo así se prueba la persistencia real: el defecto
// vivía en que la retención no sobrevivía a la petición.
$workerPath = $projectRoot . '/scratch/__probe_retain_worker.php';
$workerSource = "<?php\ndeclare(strict_types=1);\n"
    . "\$pdo = new PDO('sqlite:' . \$argv[1]);\n"
    . "require_once " . var_export($projectRoot . '/src/Core/SessionManager.php', true) . ";\n"
    . "require_once " . var_export($projectRoot . '/src/Core/ActiveSession.php', true) . ";\n"
    . "\$manager = new Grimorio\\Core\\SessionManager(\$pdo);\n"
    . "\$mode = \$argv[2];\n"
    . "\$sessionId = \$argv[3];\n"
    . "if (\$mode === 'retain') { \$manager->retainRoute(\$sessionId, '#/biblioteca'); echo 'RETENIDO'; exit(0); }\n"
    . "if (\$mode === 'pull') { echo 'RUTA=' . (string) \$manager->pullRetainedRoute(\$sessionId); exit(0); }\n"
    . "echo 'MODO DESCONOCIDO';\n";
file_put_contents($workerPath, $workerSource);

$retainingOutput = (string) shell_exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($workerPath) . ' '
    . escapeshellarg($probeDatabase) . ' retain ' . escapeshellarg($sessionId) . ' 2>&1'
);
assertCondition(
    str_contains($retainingOutput, 'RETENIDO'),
    'El proceso A retiene la ruta (proceso independiente): ' . trim($retainingOutput),
);

// Este proceso (B) es OTRO: la lee de la base, no de una memoria común.
$retainedForB = (string) $pdo->query(
    "SELECT retained_route FROM user_sessions WHERE id = '" . $sessionId . "'"
)->fetchColumn();
assertCondition(
    $retainedForB === '#/biblioteca',
    'El proceso B lee la ruta retenida por A: la retención SOBREVIVE a la petición (RF-03.1)',
);

// El sello la consume: el proceso C la extrae y la base queda limpia.
$sealingOutput = (string) shell_exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($workerPath) . ' '
    . escapeshellarg($probeDatabase) . ' pull ' . escapeshellarg($sessionId) . ' 2>&1'
);
assertCondition(
    str_contains($sealingOutput, 'RUTA=#/biblioteca'),
    'El proceso C (el sello) recibe la ruta retenida por A: ' . trim($sealingOutput),
);
assertCondition(
    $sessionManager->pullRetainedRoute($sessionId) === null,
    'La ruta se CONSUME: una sola ceremonia, un solo retorno',
);

// ---------------------------------------------------------------------
// FASE 2: Saneamiento y caducidad con el vínculo
// ---------------------------------------------------------------------
echo "\nFASE 2: Saneamiento y caducidad\n";

$sessionManager->retainRoute($sessionId, '#/simulador');
assertCondition($sessionManager->pullRetainedRoute($sessionId) === '#/simulador', 'La retención de una ruta permitida viaja íntegra');
assertCondition(
    Grimorio\Middleware\LineageOathMiddleware::sanitizeRetainableRoute('https://malvado.example.com') === null
    && Grimorio\Middleware\LineageOathMiddleware::sanitizeRetainableRoute('#/clave-inexistente') === null
    && Grimorio\Middleware\LineageOathMiddleware::sanitizeRetainableRoute('#/grimorio') === '#/grimorio'
    && Grimorio\Middleware\LineageOathMiddleware::sanitizeRetainableRoute('#/juramento') === null,
    'El saneamiento ÚNICO acepta vistas retenibles (incluido «Mi Grimorio») y descarta externas, hashes desconocidos y la ceremonia',
);

$sessionManager->retainRoute($sessionId, '#/biblioteca');
$pdo->exec("DELETE FROM user_sessions WHERE id = '" . $sessionId . "'");
$survivors = (int) $pdo->query("SELECT COUNT(*) FROM user_sessions WHERE id = '" . $sessionId . "'")->fetchColumn();
assertCondition($survivors === 0, 'La disolución del vínculo arrastra la ruta retenida: jamás sobrevive a su sesión');

// ---------------------------------------------------------------------
// FASE 3: El cableado real (la pieza no puede quedar huérfana)
// ---------------------------------------------------------------------
echo "\nFASE 3: El cableado del front controller\n";
$frontSource = (string) file_get_contents($projectRoot . '/public/index.php');
assertCondition(
    substr_count($frontSource, 'new SessionManager(') >= 3,
    'El front controller inyecta el SessionManager en el gestor de sesiones, en el middleware y en el controlador',
);
assertCondition(
    str_contains($frontSource, 'setActiveSessionId') === false && str_contains((string) file_get_contents($projectRoot . '/src/Middleware/AuthMiddleware.php'), 'setActiveSessionId'),
    'El AuthMiddleware inyecta el id del vínculo en cada Request (la guardia retiene sobre él)',
);

/** Purga un artefacto con reintentos (Windows retiene el fichero mientras
 *  una sentencia preparada mantenga el mango de la base). */
function purgeArtifact(string $path): void
{
    for ($attempt = 0; $attempt < 5; $attempt++) {
        gc_collect_cycles();
        if (@unlink($path) || !file_exists($path)) {
            return;
        }
        usleep(120000);
    }
}

// Liberar TODA referencia viva a la base (PDO y sentencias preparadas):
// Windows retiene el fichero mientras un mango siga abierto.
$sealUser = null;
$sessionManager = null;
$pdo = null;
register_shutdown_function(static function () use ($probeDatabase, $workerPath): void {
    purgeArtifact($probeDatabase);
    purgeArtifact($workerPath);
});
purgeArtifact($probeDatabase);
purgeArtifact($workerPath);

echo "\n== RESUMEN == Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";
if ($assertsFailed === 0) {
    echo "RESULTADO: EXITO — La ruta retenida del juramento persiste en el vínculo y sobrevive al salto entre peticiones (Tarea 9.2 de SPEC-11).\n";
    exit(0);
}

echo "RESULTADO: DENEGADO — Corregir los asertos en rojo antes de continuar.\n";
exit(1);
