<?php

declare(strict_types=1);

/**
 * test_collection_latency.php — Arnés de latencia RNF-01 (Tarea 8.3 de
 * TASKS-11).
 *
 * Mide el lapso petición → primera respuesta de la hoja íntegra de
 * `mode=collection` (50 entradas, RNF-01) sobre la PILA REAL: servidor
 * PHP embebido sirviendo `public/index.php` con base SQLite sembrada,
 * sesión REAL (cookie emitida por SessionManager::createSession sobre
 * la misma base) y la ruta canónica GET /api/v1/grimoire/collection.
 *
 * Contrato del «Hecho cuando» (TASKS-11, Tarea 8.3):
 *   1. Cinco mediciones consecutivas, cada una bajo el presupuesto de
 *      100 ms de backend (RNF-01, mismo presupuesto que el RNF-02 de
 *      SPEC-06).
 *   2. La respuesta es válida: 200 con sobre camelCase íntegro de 50
 *      entradas — la latencia se mide sobre trabajo real, no sobre un
 *      401 vacío.
 *   3. El arnés no deja procesos en el puerto de la sonda: autolimpieza
 *      PowerShell -PassThru (Windows) + register_shutdown_function,
 *      patrón consolidado de test_auth_rbac/test_session_manager.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP 8.2+ estricto, PDO nativo, cero
 *     librerías externas.
 *   - Artículo V: identificadores en inglés; narrativa en castellano.
 *
 * Uso: php scratch/test_collection_latency.php
 * Salida: código 0 si las cinco mediciones cumplen el presupuesto;
 * código 1 en caso contrario.
 */

$assertsPassed = 0;
$assertsFailed = 0;

/** Aserta una condición y registra el veredicto en la consola. */
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

// =====================================================================
// [1] Base canónica real sembrada: un adepto linajado con 55 hechizos
// validados sellados a su tomo (cruza la página de 50 — el presupuesto
// se mide sobre la HOJA ÍNTEGRA, no sobre un tomo vacío).
// =====================================================================
$probePort = 8102;
$probeDbPath = __DIR__ . '/__probe_gc_latency.sqlite';
@unlink($probeDbPath);
$connection = new PDO('sqlite:' . $probeDbPath);
$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$connection->exec('PRAGMA foreign_keys = ON');
$connection->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
$connection->exec("INSERT OR IGNORE INTO magic_schools (slug, name) VALUES ('evocacion', 'Evocación')");

$connection->prepare(
    "INSERT INTO clans (id, slug, name, motto, created_at, coat_of_arms, lineage_type, weekly_points, historical_points)
     VALUES ('cln-latency', 'casa-latencia', 'Casa de la Latencia', 'Velocitas arcana', '2026-01-01T00:00:00Z', '⚔', 'primordialFlame', 0, 0)"
)->execute();
$connection->prepare(
    "INSERT INTO users (id, alias, email, password_hash, lineage, created_at, updated_at)
     VALUES ('usr-latency-adept', 'Adepto Latencia', 'latencia@santuario.test', :hash, 'primordialFlame', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')"
)->execute([':hash' => str_repeat('a', 60)]);
$connection->prepare(
    "INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at, convalescence_expires_at)
     VALUES ('clm-latency-1', 'cln-latency', 'usr-latency-adept', 'adept', '2026-01-01T00:00:00Z', NULL, NULL)"
)->execute();

$insertSpell = $connection->prepare(
    'INSERT INTO spells (id, slug, name, author_id, magic_school, clan_id, summary, mana_cost, elemental_affinity, status, created_at, updated_at, math_fingerprint)
     VALUES (:id, :slug, :name, :author, :school, :clan, :summary, 5, :element, :status, :created, :updated, :fingerprint)'
);
$insertCollection = $connection->prepare(
    'INSERT INTO grimoire_collections (id, user_id, spell_id, added_at) VALUES (:id, :userId, :spellId, :addedAt)'
);
for ($index = 0; $index < 55; $index++) {
    $spellId = sprintf('spl-latency-%03d', $index);
    $insertSpell->execute([
        ':id' => $spellId,
        ':slug' => "conjuro-latencia-{$index}",
        ':name' => "Conjuro de Latencia {$index}",
        ':author' => 'usr-latency-adept',
        ':school' => 'evocacion',
        ':clan' => 'cln-latency',
        ':summary' => 'Obra de siembra para la medición del presupuesto RNF-01.',
        ':element' => ($index % 2 === 0) ? 'fire' : 'water',
        ':status' => 'validated',
        ':created' => '2026-01-01T00:00:00Z',
        ':updated' => '2026-01-01T00:00:00Z',
        ':fingerprint' => str_repeat('f', 64),
    ]);
    $insertCollection->execute([
        ':id' => 'gc-latency-' . $index,
        ':userId' => 'usr-latency-adept',
        ':spellId' => $spellId,
        ':addedAt' => sprintf('2026-09-01T%02d:%02d:00Z', intdiv($index, 60), $index % 60),
    ]);
}

// =====================================================================
// [2] Sesión REAL sobre la MISMA base: la cookie que viajará por HTTP
// nace de SessionManager::createSession (user_sessions), la vía canónica
// de SPEC-03 — la sonda autentica como producción, sin atajos.
// =====================================================================
require_once $projectRoot . '/src/Core/SessionManager.php';
require_once $projectRoot . '/src/Core/ActiveSession.php';
$sessionManager = new Grimorio\Core\SessionManager($connection, '127.0.0.1', 'latency-harness/1.0');
$activeSession = $sessionManager->createSession('usr-latency-adept');
$rawSessionToken = $activeSession->getToken();
assertCondition(is_string($rawSessionToken) && $rawSessionToken !== '', 'La sesión real nació sobre la base sembrada (cookie canónica de SPEC-03).');
// Toda referencia viva al PDO ha de soltarse (gestor, sentencias
// preparadas de la siembra) o Windows mantendría la base bloqueada y la
// purga final no la borraría: el token crudo ya vive en su variable.
unset($insertSpell, $insertCollection, $activeSession, $sessionManager, $connection);

// =====================================================================
// [3] Sonda HTTP: servidor PHP embebido sirviendo public/index.php con
// GRIMORIO_DB_DSN apuntando a la base sembrada. Autolimpieza garantizada
// (PowerShell -PassThru en Windows + register_shutdown_function).
// =====================================================================
$probeFile = __DIR__ . '/__probe_gc_latency.php';
// El DSN queda ESCRITO en la propia sonda (patrón de la sonda de
// test_auth_rbac): el entorno del padre no propaga putenv a un proceso
// lanzado por PowerShell Start-Process, así que la sonda apunta sola a
// la base sembrada sin tocar una línea de producción.
$probeFileContent = '<?php

declare(strict_types=1);

// Sonda del arnés de latencia RNF-01 (TASKS-11, Tarea 8.3): la pila
// completa de producción (middleware de sesión real, guardias, consultas
// de lote del tomo y serialización JSON) sobre la base sembrada.
putenv(\'GRIMORIO_DB_DSN=sqlite:' . $probeDbPath . '\');

// El front controller enruta por REQUEST_URI: la sonda se anuncia como
// la ruta canónica del tomo ANTES del require (el despacho debe medir
// el trabajo REAL de mode=collection, no un ROUTE_NOT_FOUND).
$_SERVER[\'REQUEST_METHOD\'] = \'GET\';
$_SERVER[\'REQUEST_URI\'] = \'/api/v1/grimoire/collection?mode=collection\';

require __DIR__ . \'/../public/index.php\';
';
file_put_contents($probeFile, $probeFileContent);

$isWindows = stripos(PHP_OS_FAMILY, 'Windows') === 0;
$nullDevice = $isWindows ? 'NUL' : '/dev/null';

/** PID del servidor de la sonda (Windows) para autolimpieza garantizada. */
$probeServerPid = null;
if ($isWindows) {
    // PowerShell Start-Process -PassThru devuelve el PID real del php -S:
    // el patrón start /B puro deja el servidor huérfano si el arnés muere
    // antes de llegar a la limpieza (patrón consolidado, hallazgo CSRF).
    // El comando viaja por un guion .ps1 TEMPORAL (borrado tras el lanzamiento):
    // interpolar $p dentro de -Command choca con el escapado doble de las
    // dos shells (cmd → powershell), y el guion en fichero es inequívoco.
    $launchScript = __DIR__ . '/__probe_gc_latency_launch_' . getmypid() . '.ps1';
    file_put_contents($launchScript, "\$p = Start-Process -FilePath php -ArgumentList '-S','127.0.0.1:{$probePort}','-t','" . addslashes($projectRoot . '/scratch') . "' -WindowStyle Hidden -PassThru\n\$p.Id\n");
    $launchOutput = shell_exec('powershell -NoProfile -ExecutionPolicy Bypass -File ' . escapeshellarg($launchScript));
    unlink($launchScript);
    $probeServerPid = (int) trim((string) $launchOutput);
} else {
    exec('php -S 127.0.0.1:' . $probePort . ' -t ' . escapeshellarg($projectRoot . '/scratch') . ' > ' . $nullDevice . ' 2>&1 &');
}

/** Autolimpieza del servidor de la sonda (corre también en fallo/abort). */
function stopLatencyProbeServer(?int $probeServerPid, bool $isWindows): void
{
    if ($probeServerPid !== null && $probeServerPid > 0) {
        if ($isWindows) {
            exec('taskkill /PID ' . $probeServerPid . ' /F 2>NUL');
        } else {
            exec('kill ' . $probeServerPid . ' 2>/dev/null');
        }
    }
}
register_shutdown_function(fn (): bool => stopLatencyProbeServer($probeServerPid, $isWindows) ?? true);

// El DSN viaja escrito dentro de la propia sonda: la base sembrada es
// la misma que emitió la cookie de sesión real.

$probeUrl = 'http://127.0.0.1:' . $probePort . '/' . basename($probeFile);
// El sondeo de disponibilidad envía la MISMA cookie de sesión real: sin
// ella la pila responde 401 UNAUTHENTICATED (contrato correcto, prueba
// equivocada) y el arnés nunca alcanzaría las mediciones.
$probeReady = false;
for ($attempt = 0; $attempt < 30; $attempt++) {
    $readyContext = stream_context_create([
        'http' => [
            'ignore_errors' => true,
            'timeout' => 2,
            'header' => "Cookie: grimorio_session={$rawSessionToken}\r\n",
        ],
    ]);
    $readyBody = @file_get_contents($probeUrl, false, $readyContext);
    if ($readyBody !== false && $readyBody !== '' && !str_contains((string) $readyBody, 'Fatal error') && str_contains((string) ($http_response_header[0] ?? ''), '200')) {
        $probeReady = true;
        break;
    }
    usleep(200_000);
}
assertCondition($probeReady, 'La sonda de la pila real responde 200 autenticada en el puerto ' . $probePort . '.');

// =====================================================================
// [4] Las CINCO mediciones: petición → primera respuesta con cookie de
// sesión real, hoja íntegra de 50 entradas. El presupuesto de RNF-01
// (100 ms) juzga CADA medición; el arnés reporta la peor de la ronda.
// =====================================================================
$budgetMilliseconds = 100.0;
$measurements = [];
$responseValid = false;
$entriesCount = 0;

if ($probeReady) {
    for ($run = 1; $run <= 5; $run++) {
        $startedAt = hrtime(true);
        $measureContext = stream_context_create([
            'http' => [
                'ignore_errors' => true,
                'timeout' => 5,
                'header' => "Cookie: grimorio_session={$rawSessionToken}\r\n",
            ],
        ]);
        $measureBody = @file_get_contents($probeUrl, false, $measureContext);
        $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1e6;
        $measurements[] = $elapsedMilliseconds;

        $statusLine = (string) ($http_response_header[0] ?? '');
        $decoded = json_decode((string) $measureBody, true);
        $entriesCount = count($decoded['data']['entries'] ?? []);
        $responseValid = str_contains($statusLine, '200') && is_array($decoded) && $entriesCount === 50;

        assertCondition(
            $responseValid,
            sprintf('Medición %d: 200 con sobre camelCase de 50 entradas (trabajo real, no 401 vacío).', $run),
        );
        assertCondition(
            $elapsedMilliseconds < $budgetMilliseconds,
            sprintf('Medición %d: %.1f ms < %.0f ms de presupuesto (RNF-01).', $run, $elapsedMilliseconds, $budgetMilliseconds),
        );
    }
} else {
    $assertsFailed += 10; // Las diez asertos de medición quedan en rojo por sonda caída.
}

// =====================================================================
// [5] Autolimpieza explícita + verificación de puerto libre: el arnés
// no deja procesos escuchando (contrato del «Hecho cuando»).
// =====================================================================
stopLatencyProbeServer($probeServerPid, $isWindows);
@unlink($probeFile);
if (isset($launchScript)) {
    @unlink($launchScript);
}
// El servidor detenido puede soltar el handle de la base unos instantes
// después en Windows: la purga reintenta brevemente (la memoria del arnés
// ha de quedar prístina, sin sonda ni base residuales).
for ($purgeAttempt = 0; $purgeAttempt < 10 && is_file($probeDbPath); $purgeAttempt++) {
    @unlink($probeDbPath);
    if (is_file($probeDbPath)) {
        usleep(100_000);
    }
}

if ($measurements !== []) {
    $worst = max($measurements);
    $average = array_sum($measurements) / count($measurements);
    printf(
        "\n=== Ronda: media %.1f ms · peor %.1f ms · presupuesto %.0f ms ===\n",
        $average,
        $worst,
        $budgetMilliseconds,
    );
}

$portFree = true;
$portCheck = @fsockopen('127.0.0.1', $probePort, $errno, $errstr, 1);
if (is_resource($portCheck)) {
    fclose($portCheck);
    $portFree = false;
}
assertCondition($portFree, 'El puerto de la sonda queda LIBRE: cero procesos huérfanos (autolimpieza verificada).');

echo "\n=== RESULTADO: {$assertsPassed} pasan, {$assertsFailed} fallan ===\n";
exit($assertsFailed === 0 ? 0 : 1);
