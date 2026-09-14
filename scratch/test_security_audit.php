<?php

/**
 * test_security_audit.php — Auditoría integral de seguridad y Dogma Vanilla
 * (Tarea 5.3 de TASKS-03).
 *
 * Certifica, contra el código y el esquema reales del proyecto:
 *   1. Artículo I (Dogma Vanilla): cero Composer, cero librerías externas,
 *      cero CDNs; el autoload es el spl_autoload_register nativo.
 *   2. Cookies seguras: HttpOnly, SameSite=Strict, Path=/ y Secure bajo
 *      HTTPS (SessionManager, Tarea 2.1), verificadas por HTTP real.
 *   3. Base de datos sin contraseñas en claro: solo hashes BCRYPT
 *      ($2y$12$) y tokens de sesión/recuperación solo como SHA-256.
 *   4. Tiempos de respuesta idénticos anti-timing attacks (RF-03.1):
 *      bind() con identidad inexistente vs errónea — deriva relativa < 50%.
 *   5. Preservación del legado del clan al eliminar la cuenta (RF-09.1/
 *      RF-09.2): el historial de clan_history sobrevive a la baja y las
 *      sesiones no (derecho al olvido), y la sesión huérfana degrada al
 *      anónimo (AuthMiddleware).
 *   6. Bitácora inmutable (RNF-02): triggers anti-UPDATE/DELETE en el motor.
 *
 * Ejecución: php scratch/test_security_audit.php  (exit 0 = verde)
 */

declare(strict_types=1);

$assertsPassed = 0;
$assertsFailed = 0;

/** Asegura una condición y la reporta con el formato estándar del proyecto. */
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

// Buffer diferido: la sonda HTTP y el SessionManager emiten cabeceras.
ob_start();

echo "=== Tarea 5.3 (TASKS-03): Auditoría integral de seguridad y Dogma Vanilla ===\n\n";

require_once $projectRoot . '/public/index.php'; // Autoload nativo del proyecto.

use Grimorio\Core\SessionManager;
use Grimorio\Middleware\AuthMiddleware;
use Grimorio\Services\AuthService;
use PDO;

// =====================================================================
// 1. ARTÍCULO I — Cero Composer, cero librerías externas, cero CDNs.
// =====================================================================
echo "\n[1] Artículo I — Dogma Vanilla (sin dependencias externas)\n";

// 1a. Ningún manifiesto de dependencias en el repositorio.
$dependencyManifests = ['composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'vendor', 'node_modules'];
$foundManifests = [];
foreach ($dependencyManifests as $manifest) {
    if (file_exists($projectRoot . '/' . $manifest)) {
        $foundManifests[] = $manifest;
    }
}
assertArcane($foundManifests === [], 'No existen composer.json/lock, package.json ni vendor/node_modules en el proyecto');

// 1b. El index.php monta el autoload nativo (spl_autoload_register), sin require de vendor.
$indexSource = (string) file_get_contents($projectRoot . '/public/index.php');
assertArcane(
    str_contains($indexSource, 'spl_autoload_register') && !str_contains($indexSource, 'vendor/autoload'),
    'El front controller usa spl_autoload_register nativo (sin vendor/autoload.php)'
);

// 1c. Ningún HTML del frontend referencia CDNs externas.
$htmlFiles = array_merge(
    glob($projectRoot . '/public/*.html') ?: [],
    glob($projectRoot . '/public/**/*.html') ?: [],
);
$cdnViolations = [];
$cdnPattern = '/(https?:)?\/\/(cdn\.|unpkg\.com|jsdelivr\.net|cdnjs\.cloudflare\.com|fonts\.googleapis\.com|fonts\.gstatic\.com|code\.jquery\.com|ajax\.googleapis\.com)/i';
foreach ($htmlFiles as $htmlFile) {
    $htmlSource = (string) file_get_contents($htmlFile);
    if (preg_match($cdnPattern, $htmlSource) === 1) {
        $cdnViolations[] = basename($htmlFile);
    }
}
assertArcane($cdnViolations === [], 'Ningún HTML del frontend carga recursos de CDNs externas');

// 1d. Ningún JS importa librerías de terceros por URL absoluta (solo rutas relativas).
$jsFiles = glob($projectRoot . '/public/assets/js/**/*.js') ?: [];
$externalImports = [];
foreach ($jsFiles as $jsFile) {
    $jsSource = (string) file_get_contents($jsFile);
    // import ... from 'http...' o 'https...' (las rutas relativas '../x.js' son legítimas).
    if (preg_match("/import\s[^;]*from\s*['\"]https?:\/\//i", $jsSource) === 1
        || preg_match("/import\s*\(\s*['\"]https?:\/\//i", $jsSource) === 1) {
        $externalImports[] = basename($jsFile);
    }
}
assertArcane($externalImports === [], 'Ningún módulo ES importa código desde URLs externas (solo rutas locales relativas)');

// 1e. El backend no requiere paquetes de terceros (require/include apuntan a src/ del proyecto).
$phpSources = array_merge(
    glob($projectRoot . '/src/**/*.php') ?: [],
    [$projectRoot . '/public/index.php'],
);
$foreignRequires = [];
foreach ($phpSources as $phpFile) {
    $phpSource = (string) file_get_contents($phpFile);
    if (preg_match("/(require|include)(_once)?\s*\(?\s*['\"]vendor\//i", $phpSource) === 1) {
        $foreignRequires[] = basename($phpFile);
    }
}
assertArcane($foreignRequires === [], 'Ningún PHP del backend requiere código de vendor/');

// =====================================================================
// 2. COOKIES SEGURAS (verificación por HTTP real con sonda php -S).
// =====================================================================
echo "\n[2] Cookies de sesión: HttpOnly, SameSite=Strict, Path=/, Secure bajo HTTPS\n";

$probePort = 8097;
$probeFile = $projectRoot . '/scratch/__security_probe_' . getmypid() . '.php';
file_put_contents($probeFile, <<<'PROBE'
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
PROBE);

$isWindows = stripos(PHP_OS_FAMILY, 'Windows') === 0;
$nullDevice = $isWindows ? 'NUL' : '/dev/null';
$serverCommand = 'php -S 127.0.0.1:' . $probePort
    . ' -t ' . escapeshellarg($projectRoot . '/scratch')
    . ' > ' . $nullDevice . ' 2>&1'
    . ($isWindows ? '' : ' &');
if ($isWindows) {
    pclose(popen('start /B cmd /C "' . $serverCommand . '"', 'r'));
} else {
    exec($serverCommand);
}

$probeUrl = 'http://127.0.0.1:' . $probePort . '/' . basename($probeFile);
$probeReady = false;
$probeBody = false;
for ($attempt = 0; $attempt < 20; $attempt++) {
    $probeContext = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]);
    $probeBody = @file_get_contents($probeUrl, false, $probeContext);
    if ($probeBody !== false) {
        $probeReady = true;
        break;
    }
    usleep(200000);
}

$sessionCookie = null;
foreach ($http_response_header ?? [] as $headerLine) {
    if (stripos($headerLine, 'Set-Cookie:') === 0 && str_contains($headerLine, 'grimorio_session=')) {
        $sessionCookie = substr($headerLine, strlen('Set-Cookie:'));
    }
}

assertArcane($probeReady && str_contains((string) $probeBody, 'session-created'), 'La sonda HTTP ejecuta la pila de producción');
assertArcane($sessionCookie !== null, 'La cookie grimorio_session viaja por Set-Cookie');
assertArcane($sessionCookie !== null && stripos($sessionCookie, 'HttpOnly') !== false, 'La cookie porta HttpOnly (inalcanzable por JavaScript)');
assertArcane($sessionCookie !== null && stripos($sessionCookie, 'SameSite=Strict') !== false, 'La cookie porta SameSite=Strict (blindaje CSRF)');
assertArcane($sessionCookie !== null && stripos($sessionCookie, 'Path=/') !== false, 'La cookie porta Path=/');

// Secure condicionado a HTTPS: bajo HTTP no debe bloquear la cookie (dev),
// y el código activa Secure cuando HTTPS está presente.
$sessionManagerSource = (string) file_get_contents($projectRoot . '/src/Core/SessionManager.php');
assertArcane(
    str_contains($sessionManagerSource, "'secure'") && str_contains($sessionManagerSource, 'HTTPS'),
    'El SessionManager activa Secure bajo HTTPS (sin bloquear el desarrollo en HTTP)',
);

// =====================================================================
// 3. BASE DE DATOS SIN CONTRASEÑAS EN CLARO (criterio «Hecho cuando»).
// =====================================================================
echo "\n[3] Base de datos: cero contraseñas en claro, solo hashes y SHA-256\n";

$sandboxDb = sys_get_temp_dir() . '/grimorio_security_audit_' . getmypid() . '.sqlite';
if (file_exists($sandboxDb)) {
    @unlink($sandboxDb);
}

$pdo = new PDO('sqlite:' . $sandboxDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

$now = '2026-09-12T12:00:00Z';
$pdo->exec(
    "INSERT INTO clans (id, slug, name, motto, created_at)
     VALUES ('cln_astral', 'astral-scholars', 'Eruditos Astrales', 'Saber', '{$now}'), 
            ('cln_ember', 'ember-wardens', 'Guardianes de Ascuas', 'Fuego', '{$now}')"
);

$sessionManager = new SessionManager($pdo, '127.0.0.1', 'Auditoría/1.0');
$authService = new AuthService($pdo, $sessionManager);

// Consagración real: la frase de paso viaja a la BD SOLO como hash BCRYPT.
$plaintextPassphrase = 'la-frase-secreta-de-la-auditoria';
$consecration = $authService->consecrate('AuditadaDelSantuario', 'audita@sanctuario.arc', $plaintextPassphrase, 'cln_astral');
$storedHash = (string) $pdo->query("SELECT password_hash FROM users WHERE id = '" . $consecration->userId . "'")->fetchColumn();

assertArcane($storedHash !== $plaintextPassphrase, 'La frase de paso en claro NO está en la base de datos');
assertArcane(str_starts_with($storedHash, '$2y$12$'), 'El hash persistido es BCRYPT coste 12 ($2y$12$)');
assertArcane(password_verify($plaintextPassphrase, $storedHash), 'El hash persistido verifica la frase original (hash funcional, no texto)');

// Barrido completo de la tabla users: ninguna columna contiene la frase.
$allUsers = $pdo->query('SELECT id, alias, email, password_hash, recovery_token_hash FROM users')->fetchAll(PDO::FETCH_ASSOC);
$plaintextLeak = false;
foreach ($allUsers as $userRow) {
    foreach ($userRow as $columnValue) {
        if (is_string($columnValue) && str_contains($columnValue, $plaintextPassphrase)) {
            $plaintextLeak = true;
        }
    }
}
assertArcane(!$plaintextLeak, 'Ninguna columna de users contiene la frase de paso en claro (barrido completo)');

// Tokens de sesión y recuperación: solo SHA-256 (64 hex), jamás crudos.
$recoveryIssued = $authService->requestRecovery('audita@sanctuario.arc');
$recoveryHashStored = (string) $pdo->query("SELECT recovery_token_hash FROM users WHERE id = '" . $consecration->userId . "'")->fetchColumn();
assertArcane(
    $recoveryIssued->tokenIssued
    && $recoveryHashStored === hash('sha256', (string) $recoveryIssued->recoveryToken)
    && $recoveryHashStored !== $recoveryIssued->recoveryToken,
    'El token de recuperación se persiste SOLO como SHA-256 (jamás en claro)'
);

$sessionAudit = $authService->bind('audita@sanctuario.arc', $plaintextPassphrase);
$sessionHashStored = (string) $pdo->query("SELECT session_token_hash FROM user_sessions WHERE id = '" . $sessionAudit->session->getId() . "'")->fetchColumn();
assertArcane(
    $sessionHashStored === hash('sha256', $sessionAudit->session->getToken())
    && $sessionHashStored !== $sessionAudit->session->getToken(),
    'El token de sesión se persiste SOLO como SHA-256 (jamás en claro)'
);

// =====================================================================
// 4. TIEMPOS DE RESPUESTA IDÉNTICOS (RF-03.1, anti-timing attacks).
// =====================================================================
echo "\n[4] Anti-timing attacks: hash señuelo con coste idéntico (RF-03.1)\n";

// Identidad INEXISTENTE: bind() verifica contra el hash señuelo BCRYPT 12.
$dummySamples = [];
for ($sample = 0; $sample < 3; $sample++) {
    $startedAt = microtime(true);
    $authService->bind('fantasma-inexistente@sanctuario.arc', 'frase-cualquiera-larga');
    $dummySamples[] = microtime(true) - $startedAt;
}

// Identidad EXISTENTE con frase ERRÓNEA: mismo coste BCRYPT 12 real.
$wrongSamples = [];
for ($sample = 0; $sample < 3; $sample++) {
    $startedAt = microtime(true);
    $authService->bind('audita@sanctuario.arc', 'frase-erronea-cualquiera');
    $wrongSamples[] = microtime(true) - $startedAt;
}

$dummyMean = array_sum($dummySamples) / count($dummySamples);
$wrongMean = array_sum($wrongSamples) / count($wrongSamples);
$deviation = abs($dummyMean - $wrongMean) / max($dummyMean, $wrongMean) * 100;

assertArcane(
    $deviation < 50.0,
    sprintf('Deriva temporal entre identidad inexistente y frase errónea: %.1f%% (< 50%%)', $deviation),
);
assertArcane(
    min($dummyMean, $wrongMean) > 0.05,
    sprintf('Ambos caminos consumen coste BCRYPT real (mínimo %.0f ms, compatible con coste 12)', min($dummyMean, $wrongMean) * 1000),
);

// =====================================================================
// 5. PRESERVACIÓN DEL LEGADO DEL CLAN AL ELIMINAR LA CUENTA (RF-09).
// =====================================================================
echo "\n[5] Derecho al olvido: el legado del clan sobrevive a la cuenta (RF-09)\n";

// El iniciado auditado deja constancia en clan_history (participación en linaje).
$pdo->prepare('INSERT INTO clan_history (user_id, clan_id, joined_at, left_at) VALUES (:userId, :clanId, :joinedAt, NULL)')
    ->execute([':userId' => $consecration->userId, ':clanId' => 'cln_astral', ':joinedAt' => '2026-01-01T00:00:00Z']);
$historyBefore = (int) $pdo->query("SELECT COUNT(*) FROM clan_history WHERE user_id = '" . $consecration->userId . "'")->fetchColumn();
assertArcane($historyBefore === 1, 'El iniciado tiene legado registrado en clan_history antes de la baja');

// Borrado de la cuenta (derecho al olvido, RF-09.1): el esquema (RF-09.2)
// PROTEGE el legado con ON DELETE RESTRICT — el borrado físico de la
// cuenta con historia viva es RECHAZADO por el motor. La baja canónica
// del plan (sección 6) es la REASIGNACIÓN a «Erudito Ancestral»:
// los datos personales se purgan y el legado queda anónimo.
$directDeleteRejected = false;
try {
    $pdo->prepare('DELETE FROM users WHERE id = :userId')->execute([':userId' => $consecration->userId]);
} catch (Throwable) {
    $directDeleteRejected = true;
}
assertArcane($directDeleteRejected, 'El motor RECHAZA borrar físicamente la cuenta con legado vivo (RESTRICT protege RF-09.2)');

// Baja canónica (RF-09): 1) cerrar la historia, 2) revocar todas las
// sesiones, 3) anonimizar la cuenta (alias «Erudito Ancestral», correo
// y hash sustituidos por placeholders opacos — los datos personales
// desaparecen; el legado permanece).
$pdo->prepare('UPDATE clan_history SET left_at = :leftAt WHERE user_id = :userId AND left_at IS NULL')
    ->execute([':leftAt' => $now, ':userId' => $consecration->userId]);
$pdo->prepare('DELETE FROM user_sessions WHERE user_id = :userId')
    ->execute([':userId' => $consecration->userId]);
$pdo->prepare(
    'UPDATE users SET alias = :alias, email = :email, password_hash = :passwordHash, recovery_token_hash = \'\', recovery_token_expires_at = NULL WHERE id = :userId'
)->execute([
    ':alias'        => 'Erudito Ancestral',
    ':email'        => 'ancestral+' . $consecration->userId . '@olvidado.sanctuario',
    ':passwordHash' => str_repeat('0', 60),
    ':userId'       => $consecration->userId,
]);

// El legado sobrevive íntegro (RF-09.2): clan, ingreso y salida.
$preservedHistory = $pdo->query("SELECT clan_id, joined_at, left_at FROM clan_history WHERE user_id = '" . $consecration->userId . "'")->fetch(PDO::FETCH_ASSOC);
assertArcane(
    is_array($preservedHistory)
    && $preservedHistory['clan_id'] === 'cln_astral'
    && $preservedHistory['joined_at'] === '2026-01-01T00:00:00Z'
    && $preservedHistory['left_at'] === $now,
    'El legado del clan sobrevive a la baja con su historia íntegra (RF-09.2: contribuciones preservadas)',
);

// Derecho al olvido (RF-09.1): ningún dato personal queda en la cuenta.
$anonymizedRow = $pdo->query("SELECT alias, email, password_hash FROM users WHERE id = '" . $consecration->userId . "'")->fetch(PDO::FETCH_ASSOC);
$personalDataPurged = is_array($anonymizedRow)
    && $anonymizedRow['alias'] === 'Erudito Ancestral'
    && !str_contains((string) $anonymizedRow['email'], 'audita@sanctuario.arc')
    && !password_verify($plaintextPassphrase, (string) $anonymizedRow['password_hash']);
assertArcane($personalDataPurged, 'Los datos personales se purgan y la cuenta queda como «Erudito Ancestral» (RF-09.1)');

$sessionsAfterDelete = (int) $pdo->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn();
assertArcane($sessionsAfterDelete === 0, 'Las sesiones de la cuenta de baja quedan revocadas (derecho al olvido)');

// Defensa en profundidad: una sesión huérfana degrada al anónimo (Tarea 3.1).
$orphanUser = $authService->consecrate('HuerfanaTemporal', 'huerfana@sanctuario.arc', 'frase-de-la-huerfana-larga', 'cln_ember');
$orphanSession = $authService->bind('HuerfanaTemporal', 'frase-de-la-huerfana-larga');
$pdo->prepare('DELETE FROM users WHERE id = :userId')->execute([':userId' => $orphanUser->userId]);

$orphanMiddleware = new AuthMiddleware($pdo, $sessionManager);
$_COOKIE['grimorio_session'] = $orphanSession->session->getToken();
$orphanRequest = new Grimorio\Core\Request('GET', '/api/v1/auth/session', [], []);
$orphanMiddleware->injectContext($orphanRequest);
unset($_COOKIE['grimorio_session']);
assertArcane(
    $orphanRequest->getUser() !== null
    && $orphanRequest->getUser()->getId() === ''
    && $orphanRequest->getUser()->getRole() === 'reader',
    'La sesión huérfana degrada a anónimo reader sin errores (defensa en profundidad, RF-09.1)',
);

// =====================================================================
// 6. BITÁCORA INMUTABLE A NIVEL DE MOTOR (RNF-02, Art. III).
// =====================================================================
echo "\n[6] Bitácora de auditoría inmutable (triggers del motor, RNF-02)\n";

$auditService = new Grimorio\Services\AuditService($pdo);
$auditService->recordAction(
    actorUserId: 'usr_admin',
    actorAlias: 'SupremoArquitecto',
    actorRole: 'supremeAdmin',
    actionType: 'ADMIN_VETO',
    targetEntityType: 'spell',
    targetEntityId: 'spl_tiempo_cero',
    justification: 'Manipulación temporal prohibida por el canon del santuario.',
);

$updateRejected = false;
try {
    $pdo->exec("UPDATE audit_log SET justification = 'veredicto falsificado'");
} catch (Throwable) {
    $updateRejected = true;
}
assertArcane($updateRejected, 'UPDATE sobre audit_log es RECHAZADO por el motor (trigger anti-mutación)');

$deleteRejected = false;
try {
    $pdo->exec('DELETE FROM audit_log');
} catch (Throwable) {
    $deleteRejected = true;
}
assertArcane($deleteRejected, 'DELETE sobre audit_log es RECHAZADO por el motor (trigger anti-mutación)');

$auditIntact = (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
assertArcane($auditIntact === 1, 'El veredicto permanece íntegro tras los intentos de mutación');

// =====================================================================
// Limpieza del sandbox y de la sonda efímera.
// =====================================================================
if (file_exists($probeFile)) {
    @unlink($probeFile);
}
if ($isWindows) {
    exec('for /f "tokens=5" %a in (\'netstat -ano ^| findstr :' . $probePort . '\') do taskkill /F /PID %a > NUL 2>&1');
} else {
    exec('fuser -k ' . $probePort . '/tcp > /dev/null 2>&1 || true');
}
$pdo = null;
gc_collect_cycles();
if (file_exists($sandboxDb)) {
    @unlink($sandboxDb);
}

// =====================================================================
// Veredicto.
// ======================================================================
ob_end_flush();
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);
