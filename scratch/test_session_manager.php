<?php

/**
 * test_session_manager.php — Arnés de la Tarea 2.1 de TASKS-03.
 *
 * Verifica el gestor de sesiones nativas seguras src/Core/SessionManager.php:
 * cookies con banderas HttpOnly/SameSite=Strict/Path=/, expiración por
 * inactividad de 14 días renovable y tope absoluto de 30 días contra la
 * tabla user_sessions del DDL (Tarea 1.1).
 *
 * Estrategia TDD: este arnés se escribe ANTES de implementar el gestor.
 * Fase roja = la clase Grimorio\Core\SessionManager no existe todavía.
 *
 * Escenarios verificados (criterio «Hecho cuando» de la tarea):
 *   1. Una sesión creada emite la cookie con las banderas de seguridad
 *      correctas (HttpOnly=true, SameSite=Strict, Path=/).
 *   2. Una sesión con más de 30 días de vida absoluta es rechazada
 *      forzando reautenticación.
 *   3. Extras estructurales: ventana de inactividad de 14 días, hash
 *      SHA-256 del token (jamás el token en claro), multidispositivo
 *      (RF-02.3), validez de sesión activa y persistencia en BD.
 *
 * Ejecución: php scratch/test_session_manager.php  (exit 0 = verde)
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

// CABECERAS: la cookie debe emitirse antes de CUALQUIER output. El arnés
// activa un buffer de salida diferido para que setcookie() pueda operar
// y headers_list() refleje la cabecera Set-Cookie en el veredicto.
ob_start();

echo "=== Tarea 2.1 (TASKS-03): Gestor de sesiones nativas seguras ===\n\n";

require_once $projectRoot . '/public/index.php'; // Autoload nativo del proyecto.

use Grimorio\Core\SessionManager;
use Grimorio\Database\Connection;

echo "[0] Existencia y cargabilidad del gestor\n";

assertArcane(class_exists(SessionManager::class), 'La clase Grimorio\Core\SessionManager existe y el autocompilador la resuelve');

if (!class_exists(SessionManager::class)) {
    echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
    exit(1);
}

// ---------------------------------------------------------------------
// Sandbox: base SQLite efímera con el esquema completo (Tarea 1.1) y
// un usuario de prueba para vincular las sesiones.
// ---------------------------------------------------------------------
$sandboxDb = sys_get_temp_dir() . '/grimorio_session_mgr_' . getmypid() . '.sqlite';
if (file_exists($sandboxDb)) {
    @unlink($sandboxDb);
}

$pdo = new PDO('sqlite:' . $sandboxDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

$now = '2026-09-12T12:00:00Z';

// Clan de prueba (la FK de users exige un linaje existente).
$pdo->exec(
    "INSERT INTO clans (id, slug, name, motto, created_at)
     VALUES ('cln_test', 'test-lineage', 'Linaje de Prueba', 'Ensayo', '{$now}')"
);
$insertUser = $pdo->prepare(
    "INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
     VALUES (:id, :alias, :email, :passwordHash, 'editor', 'cln_test', :createdAt, :updatedAt)"
);
$insertUser->execute([
    ':id' => 'usr_sm_01', ':alias' => 'sm_rider', ':email' => 'sm@test.arc',
    ':passwordHash' => str_repeat('a', 60), ':createdAt' => $now, ':updatedAt' => $now,
]);

/**
 * Captura la cabecera Set-Cookie emitida por setcookie() mediante un
 * truco de output buffering: headers_list() refleja las cabeceras del
 * proceso CLI en curso.
 *
 * @return array<int, string>
 */
function sentCookies(): array
{
    $cookies = [];
    foreach (headers_list() as $headerLine) {
        if (stripos($headerLine, 'Set-Cookie:') === 0) {
            $cookies[] = substr($headerLine, strlen('Set-Cookie:'));
        }
    }
    return $cookies;
}

/**
 * Verdad de referencia de la marca temporal «ahora» del gestor.
 */
function managerNow(): string
{
    return '2026-09-12T12:00:00Z';
}

$sessionManager = new SessionManager($pdo, '127.0.0.1', 'Arnés de Sesiones/1.0');

// ---------------------------------------------------------------------
// 1. Emisión de cookie con banderas de seguridad (criterio «Hecho cuando»).
// ---------------------------------------------------------------------
echo "\n[1] Cookie de sesión con banderas de seguridad\n";

$session = null;
$creationOk = true;
try {
    $session = $sessionManager->createSession('usr_sm_01');
} catch (Throwable $creationError) {
    $creationOk = false;
    echo '  Excepción: ' . $creationError->getMessage() . "\n";
}
assertArcane($creationOk && $session !== null, 'createSession() crea un vínculo sin errores');

if ($session !== null) {
    // Token crudo devuelto al cliente solo en el instante de creación.
    assertArcane(is_string($session->getToken()) && $session->getToken() !== '', 'createSession() devuelve el token crudo para el cliente');

    // El hash persistido NO es el token en claro.
    $storedHash = (string) $pdo->query(
        "SELECT session_token_hash FROM user_sessions WHERE id = '" . $session->getId() . "'"
    )->fetchColumn();
    assertArcane($storedHash !== $session->getToken(), 'El token jamás se persiste en claro (solo su hash)');
    assertArcane($storedHash === hash('sha256', $session->getToken()), 'El hash persistido es el SHA-256 del token crudo');
}

// VERIFICACIÓN DE LA COOKIE: la SAPI CLI no registra cabeceras
// (headers_list() está siempre vacío bajo cli), así que la cookie se
// verifica contra una sonda HTTP real servida por el propio servidor
// nativo de PHP (php -S) con el SessionManager de producción.
$probePort = 8099;
$probePath = sys_get_temp_dir() . '/grimorio_cookie_probe_' . getmypid() . '.php';
$probeHtml = <<<'PROBE'
<?php
declare(strict_types=1);
require __DIR__ . '/../public/index.php';
use Grimorio\Core\SessionManager;
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));
$now = '2026-09-12T12:00:00Z';
$pdo->exec("INSERT INTO clans (id, slug, name, motto, created_at) VALUES ('c1','s','N','M','{$now}')");
$pdo->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at) VALUES ('u1','a','a@b.c','" . str_repeat('x', 60) . "','editor','c1','{$now}','{$now}')");
$sm = new SessionManager($pdo, '127.0.0.1', 'probe/1.0');
$sm->createSession('u1');
echo 'session-created';
PROBE;
// La sonda vive en scratch/ y resuelve ../public/index.php por ruta relativa.
$probeUrl = null;
$sessionCookie = null;

$probeFile = $projectRoot . '/scratch/__cookie_probe_' . getmypid() . '.php';
// El require relativo de la sonda apunta a ../public/index.php desde scratch/.
file_put_contents($probeFile, $probeHtml);

// Arranque NO bloqueante: en Windows, popen('r') sobre un servidor que
// nunca cierra stdout bloquea al arnés esperando EOF. Se delega el
// arranque al shell (start /B en Windows, & en Unix) y el arnés sondea
// el puerto hasta que responde.
$isWindows = stripos(PHP_OS_FAMILY, 'Windows') === 0;
$nullDevice = $isWindows ? 'NUL' : '/dev/null';
$serverCommand = 'php -S 127.0.0.1:' . $probePort
    . ' -t ' . escapeshellarg($projectRoot . '/scratch')
    . ' > ' . $nullDevice . ' 2>&1'
    . ($isWindows ? '' : ' &');
if ($isWindows) {
    // start /B lanza el proceso desacoplado del arnés (cmd /c abre el shell).
    pclose(popen('start /B cmd /C "' . $serverCommand . '"', 'r'));
} else {
    exec($serverCommand);
}

// Sondeo de arranque: hasta ~4 s esperando que el puerto responda.
$probeUrl = 'http://127.0.0.1:' . $probePort . '/' . basename($probeFile);
$probeReady = false;
for ($attempt = 0; $attempt < 20; $attempt++) {
    $probeContext = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]);
    $probeBody = @file_get_contents($probeUrl, false, $probeContext);
    if ($probeBody !== false) {
        $probeReady = true;
        break;
    }
    usleep(200000);
}

// La cabecera Set-Cookie viaja en $http_response_header (variable mágica).
foreach ($http_response_header ?? [] as $headerLine) {
    if (stripos($headerLine, 'Set-Cookie:') === 0 && str_contains($headerLine, 'grimorio_session=')) {
        $sessionCookie = substr($headerLine, strlen('Set-Cookie:'));
    }
}

assertArcane($probeReady && $probeBody !== false && str_contains((string) $probeBody, 'session-created'), 'La sonda HTTP ejecuta el SessionManager de producción');
assertArcane($sessionCookie !== null, 'Se emitió la cookie grimorio_session vía Set-Cookie');

if ($sessionCookie !== null) {
    assertArcane(stripos($sessionCookie, 'HttpOnly') !== false, 'La cookie porta HttpOnly (inalcanzable por JS)');
    assertArcane(stripos($sessionCookie, 'SameSite=Strict') !== false, 'La cookie porta SameSite=Strict');
    assertArcane(stripos($sessionCookie, 'Path=/') !== false, 'La cookie porta Path=/');
    // La expiración de la cookie sigue la ventana renovable de 14 días.
    assertArcane(stripos($sessionCookie, 'Max-Age=1209600') !== false, 'La cookie caduca a los 14 días (Max-Age=1209600)');
}

// ---------------------------------------------------------------------
// 2. Persistencia correcta de la vigencia en base de datos.
// ---------------------------------------------------------------------
echo "\n[2] Vigencia persistida (14 días renovables / 30 días absolutos)\n";

if ($session !== null) {
    $row = $pdo->query(
        "SELECT created_at, last_activity_at, expires_at, absolute_expires_at FROM user_sessions WHERE id = '" . $session->getId() . "'"
    )->fetch(PDO::FETCH_ASSOC);

    $createdAt = new DateTimeImmutable((string) $row['created_at']);
    $expiresAt = new DateTimeImmutable((string) $row['expires_at']);
    $absoluteExpiresAt = new DateTimeImmutable((string) $row['absolute_expires_at']);

    $inactivityDays = (int) round(($expiresAt->getTimestamp() - $createdAt->getTimestamp()) / 86400);
    $absoluteDays = (int) round(($absoluteExpiresAt->getTimestamp() - $createdAt->getTimestamp()) / 86400);

    assertArcane($inactivityDays === 14, "La ventana de inactividad es de 14 días exactos ({$inactivityDays})");
    assertArcane($absoluteDays === 30, "El tope absoluto es de 30 días exactos ({$absoluteDays})");
    assertArcane($row['last_activity_at'] === $row['created_at'], 'last_activity_at nace sincronizado con created_at');
}

// ---------------------------------------------------------------------
// 3. Validación de sesión activa (camino feliz).
// ---------------------------------------------------------------------
echo "\n[3] Resolución de sesión activa\n";

if ($session !== null) {
    $resolved = $sessionManager->resolveSession($session->getToken(), new DateTimeImmutable(managerNow()));
    assertArcane($resolved !== null, 'resolveSession() acepta una sesión reciente y válida');
    assertArcane($resolved !== null && $resolved->getUserId() === 'usr_sm_01', 'La sesión resuelta vincula al usuario correcto');

    // Token ajeno: no resuelve nada.
    $forgedToken = str_repeat('f', 64);
    assertArcane($sessionManager->resolveSession($forgedToken, new DateTimeImmutable(managerNow())) === null, 'resolveSession() rechaza un token inexistente');

    // Renovación de inactividad: last_activity_at y expires_at avanzan.
    $laterNow = new DateTimeImmutable('2026-09-15T12:00:00Z'); // 3 días después.
    $resolvedLater = $sessionManager->resolveSession($session->getToken(), $laterNow);
    assertArcane($resolvedLater !== null, 'La sesión sigue válida 3 días después con actividad');

    if ($resolvedLater !== null) {
        $rowAfter = $pdo->query(
            "SELECT last_activity_at, expires_at FROM user_sessions WHERE id = '" . $session->getId() . "'"
        )->fetch(PDO::FETCH_ASSOC);
        assertArcane($rowAfter['last_activity_at'] === '2026-09-15T12:00:00Z', 'La actividad renueva last_activity_at');
        $newExpires = new DateTimeImmutable((string) $rowAfter['expires_at']);
        $expectedExpires = $laterNow->modify('+14 days');
        assertArcane($newExpires->getTimestamp() === $expectedExpires->getTimestamp(), 'La actividad renueva la ventana a 14 días desde el último gesto');
    }
}

// ---------------------------------------------------------------------
// 4. Tope absoluto de 30 días (criterio «Hecho cuando»).
// ---------------------------------------------------------------------
echo "\n[4] Rechazo del tope absoluto de 30 días\n";

if ($session !== null) {
    // 25 días después: dentro del tope absoluto pero sin actividad desde
    // el día 3 → supera los 14 días de inactividad → rechazo por inactividad.
    $dayTwentyFive = new DateTimeImmutable('2026-10-07T12:00:00Z');
    $expiredByInactivity = $sessionManager->resolveSession($session->getToken(), $dayTwentyFive);
    assertArcane($expiredByInactivity === null, 'Una sesión sin actividad superados los 14 días es rechazada (expiración por inactividad)');

    // Escenario de tope absoluto: sesión forjada directamente en BD con
    // created_at hace 31 días y actividad continua (nunca inactiva),
    // pero absolute_expires_at rebasado → rechazo forzoso.
    $oldCreated = '2026-08-01T12:00:00Z';
    $absoluteLimit = '2026-08-31T12:00:00Z';
    $stillActive = '2026-09-01T00:00:00Z';
    $oldToken = str_repeat('a', 64);
    $pdo->exec(
        "INSERT INTO user_sessions (id, session_token_hash, user_id, ip_address, user_agent, created_at, last_activity_at, expires_at, absolute_expires_at)
         VALUES ('ses_old_01', '" . hash('sha256', $oldToken) . "', 'usr_sm_01', '127.0.0.1', 'Arnés/1.0', '{$oldCreated}', '{$stillActive}', '{$stillActive}', '{$absoluteLimit}')"
    );

    // El día 29 de vida (actividad el día 28): dentro del tope, válida.
    $dayTwentyNine = new DateTimeImmutable('2026-08-30T00:00:00Z');
    assertArcane(
        $sessionManager->resolveSession($oldToken, $dayTwentyNine) !== null,
        'Una sesión con actividad continua a 29 días de vida sigue válida'
    );

    // El día 31 de vida (actividad el día 30, rebasando el tope): rechazo.
    $dayThirtyOne = new DateTimeImmutable('2026-09-01T12:00:00Z');
    $rejectedByAbsolute = $sessionManager->resolveSession($oldToken, $dayThirtyOne);
    assertArcane($rejectedByAbsolute === null, 'Una sesión con más de 30 días de vida absoluta es rechazada forzando reautenticación');

    // La sesión rebasada queda eliminada de la tabla (revocación real).
    $oldRowAfter = $pdo->query("SELECT COUNT(*) FROM user_sessions WHERE id = 'ses_old_01'")->fetchColumn();
    assertArcane((int) $oldRowAfter === 0, 'La sesión rebasada por el tope absoluto queda revocada en base de datos');
}

// ---------------------------------------------------------------------
// 5. Multidispositivo y disolución (RF-02.3, RF-02.4).
// ---------------------------------------------------------------------
echo "\n[5] Multidispositivo y disolución individual\n";

$deviceA = $sessionManager->createSession('usr_sm_01');
$deviceB = $sessionManager->createSession('usr_sm_01');
assertArcane($deviceA !== null && $deviceB !== null && $deviceA->getId() !== $deviceB->getId(), 'Dos dispositivos mantienen sesiones concurrentes independientes');

if ($deviceA !== null && $deviceB !== null) {
    $dissolved = $sessionManager->dissolveSession($deviceA->getToken());
    assertArcane($dissolved, 'dissolveSession() revoca la sesión del dispositivo A');

    $rowA = (int) $pdo->query("SELECT COUNT(*) FROM user_sessions WHERE id = '" . $deviceA->getId() . "'")->fetchColumn();
    $rowB = (int) $pdo->query("SELECT COUNT(*) FROM user_sessions WHERE id = '" . $deviceB->getId() . "'")->fetchColumn();
    assertArcane($rowA === 0, 'La sesión disuelta desaparece de user_sessions');
    assertArcane($rowB === 1, 'La sesión del dispositivo B sobrevive (multidispositivo sin invalidación cruzada)');
    assertArcane(
        $sessionManager->resolveSession($deviceA->getToken(), new DateTimeImmutable(managerNow())) === null,
        'El token disuelto ya no resuelve ninguna sesión'
    );
}

// ---------------------------------------------------------------------
// Limpieza del sandbox y de la sonda efímera.
// ---------------------------------------------------------------------
if (isset($probeFile) && file_exists($probeFile)) {
    @unlink($probeFile);
}
// Apaga el servidor de la sonda (puerto dedicado 8099) por si quedó vivo.
if ($isWindows) {
    exec('for /f "tokens=5" %a in (\'netstat -ano ^| findstr :8099\') do taskkill /F /PID %a > NUL 2>&1');
} else {
    exec("fuser -k 8099/tcp > /dev/null 2>&1 || true");
}
$pdo = null;
gc_collect_cycles();
if (file_exists($sandboxDb)) {
    @unlink($sandboxDb);
}

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
ob_end_flush();  // Vuelca el buffer diferido (cabeceras ya evaluadas).
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);
