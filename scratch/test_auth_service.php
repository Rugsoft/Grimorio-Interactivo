<?php

/**
 * test_auth_service.php — Arnés de la Tarea 2.3 de TASKS-03.
 *
 * Verifica el servicio de autenticación src/Services/AuthService.php:
 * consagración (registro), vínculo (login con verificación temporal
 * constante), disolución individual y global, y pergamino de
 * restablecimiento con revocación preventiva de sesiones.
 *
 * Estrategia TDD: este arnés se escribe ANTES de implementar el servicio.
 * Fase roja = la clase Grimorio\Services\AuthService no existe todavía.
 *
 * Escenarios verificados (criterio «Hecho cuando» de la tarea):
 *   1. Las contraseñas se almacenan hasheadas con BCRYPT (coste 12).
 *   2. Las credenciales erróneas tardan un tiempo uniforme en responder
 *      (hash señuelo contra ataques de enumeración temporal).
 *   3. dissolveAll() invalida todas las sesiones de un usuario en base
 *      de datos.
 *   4. Extras estructurales: consagración con rol editor y clan
 *      obligatorio, anti-enumeración (409 neutro), recuperación con
 *      token de 1 hora de un solo uso.
 *
 * Ejecución: php scratch/test_auth_service.php  (exit 0 = verde)
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

// Buffer diferido: setcookie() del SessionManager exige emitir cabeceras
// antes de cualquier output; los warnings cosméticos de la SAPI CLI no
// afectan a las aserciones de este arnés.
ob_start();

echo "=== Tarea 2.3 (TASKS-03): Servicio de autenticación, hashing y recuperación ===\n\n";

require_once $projectRoot . '/public/index.php'; // Autoload nativo del proyecto.

use Grimorio\Core\SessionManager;
use Grimorio\Database\Connection;
use Grimorio\Services\AuthService;

echo "[0] Existencia y cargabilidad del servicio\n";

assertArcane(class_exists(AuthService::class), 'La clase Grimorio\Services\AuthService existe y el autocompilador la resuelve');

if (!class_exists(AuthService::class)) {
    echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
    exit(1);
}

// ---------------------------------------------------------------------
// Sandbox: base SQLite efímera con el esquema completo y SessionManager.
// ---------------------------------------------------------------------
$sandboxDb = sys_get_temp_dir() . '/grimorio_auth_svc_' . getmypid() . '.sqlite';
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
     VALUES ('cln_test', 'test-lineage', 'Linaje de Prueba', 'Ensayo', '{$now}')"
);

$sessionManager = new SessionManager($pdo, '127.0.0.1', 'Arnés AuthService/1.0');
$authService = new AuthService($pdo, $sessionManager);

// ---------------------------------------------------------------------
// 1. Consagración (RF-01.1, RF-01.2).
// ---------------------------------------------------------------------
echo "\n[1] Consagración con clan obligatorio y rol editor\n";

$consecration = null;
$consecrationOk = true;
try {
    $consecration = $authService->consecrate(
        alias: 'FrikiArcano',
        email: 'friki@sanctuario.arc',
        passphrase: 'palabra-secreta-del-mago',
        clanId: 'cln_test',
        now: new DateTimeImmutable($now),
    );
} catch (Throwable $consecrationError) {
    $consecrationOk = false;
    echo '  Excepción: ' . $consecrationError->getMessage() . "\n";
}
assertArcane($consecrationOk && $consecration !== null, 'consecrate() registra al iniciado sin errores');

if ($consecration !== null) {
    assertArcane(str_starts_with($consecration->userId, 'usr_'), 'La consagración devuelve el identificador del usuario');

    $row = $pdo->query("SELECT role, clan_id, password_hash, email FROM users WHERE id = '" . $consecration->userId . "'")->fetch(PDO::FETCH_ASSOC);
    assertArcane($row !== false && $row['role'] === 'editor', 'El rol técnico asignado por defecto es editor (RF-01.2)');
    assertArcane($row !== false && $row['clan_id'] === 'cln_test', 'El iniciado queda vinculado al clan seleccionado');
    assertArcane($row !== false && $row['email'] === 'friki@sanctuario.arc', 'El correo queda registrado');
}

// 1b. Datos inválidos: alias corto y clan inexistente.
$shortAliasRejected = false;
try {
    $authService->consecrate('ab', 'x@y.arc', 'clave-larga-suficiente', 'cln_test', new DateTimeImmutable($now));
} catch (InvalidArgumentException) {
    $shortAliasRejected = true;
}
assertArcane($shortAliasRejected, 'Un alias fuera del rango de 3-30 caracteres es rechazado');

$ghostClanRejected = false;
try {
    $authService->consecrate('OtroIniciado', 'otro@sanctuario.arc', 'clave-larga-suficiente', 'cln_fantasma', new DateTimeImmutable($now));
} catch (InvalidArgumentException) {
    $ghostClanRejected = true;
}
assertArcane($ghostClanRejected, 'Un clan inexistente es rechazado (clan obligatorio RF-01.1)');

$shortPassRejected = false;
try {
    $authService->consecrate('TercerIniciado', 'tercero@sanctuario.arc', 'corta', 'cln_test', new DateTimeImmutable($now));
} catch (InvalidArgumentException) {
    $shortPassRejected = true;
}
assertArcane($shortPassRejected, 'Una frase de paso de menos de 8 caracteres es rechazada');

// ---------------------------------------------------------------------
// 2. Hashing BCRYPT coste 12 (criterio «Hecho cuando»).
// ---------------------------------------------------------------------
echo "\n[2] Almacenamiento con BCRYPT coste 12\n";

$storedHash = (string) $pdo->query("SELECT password_hash FROM users WHERE email = 'friki@sanctuario.arc'")->fetchColumn();
assertArcane(str_starts_with($storedHash, '$2y$12$'), 'El hash almacenado es BCRYPT con coste 12');
assertArcane(password_verify('palabra-secreta-del-mago', $storedHash), 'El hash almacenado verifica la frase de paso original');

// ---------------------------------------------------------------------
// 3. Vínculo (bind) con verificación temporal constante.
// ---------------------------------------------------------------------
echo "\n[3] Vínculo con tiempo de respuesta uniforme\n";

$bindResult = $authService->bind('friki@sanctuario.arc', 'palabra-secreta-del-mago', new DateTimeImmutable($now));
assertArcane($bindResult->success && $bindResult->userId !== null, 'bind() acepta credenciales válidas');
assertArcane($bindResult->session !== null && $bindResult->session->getToken() !== '', 'bind() inicia la sesión y entrega el token crudo');

$wrongResult = $authService->bind('friki@sanctuario.arc', 'frase-equivocada', new DateTimeImmutable($now));
assertArcane(!$wrongResult->success && $wrongResult->userId === null, 'bind() rechaza credenciales erróneas');

// Identidad inexistente: rechazo con el MISMO camino computacional (señuelo).
$ghostResult = $authService->bind('fantasma@sanctuario.arc', 'la-clave-que-no-es', new DateTimeImmutable($now));
assertArcane(!$ghostResult->success && $ghostResult->userId === null, 'bind() rechaza identidades inexistentes');

// Medición del tiempo uniforme: credenciales erróneas sobre usuario real
// vs usuario inexistente deben tardar lo mismo (± tolerancia holgada,
// pues el ruido del sistema es mayor que la diferencia de BCRYPT).
$timingIterations = 3;
$wrongDurations = [];
$ghostDurations = [];
for ($timingIndex = 0; $timingIndex < $timingIterations; $timingIndex++) {
    $startWrong = hrtime(true);
    $authService->bind('friki@sanctuario.arc', 'otra-frase-erronea-' . $timingIndex, new DateTimeImmutable($now));
    $wrongDurations[] = hrtime(true) - $startWrong;

    $startGhost = hrtime(true);
    $authService->bind('nadie-' . $timingIndex . '@sanctuario.arc', 'otra-frase-erronea-' . $timingIndex, new DateTimeImmutable($now));
    $ghostDurations[] = hrtime(true) - $startGhost;
}

$avgWrong = array_sum($wrongDurations) / $timingIterations;
$avgGhost = array_sum($ghostDurations) / $timingIterations;
$relativeDrift = abs($avgWrong - $avgGhost) / max($avgWrong, $avgGhost);
assertArcane(
    $relativeDrift < 0.5,
    'El tiempo de respuesta es uniforme (deriva relativa ' . round($relativeDrift * 100, 1) . '% < 50%)'
);

// ---------------------------------------------------------------------
// 4. Disolución individual y global (criterio «Hecho cuando»).
// ---------------------------------------------------------------------
echo "\n[4] Disolución individual y global\n";

// Tres dispositivos activos del mismo usuario (RF-02.3).
$bindA = $authService->bind('friki@sanctuario.arc', 'palabra-secreta-del-mago', new DateTimeImmutable($now));
$bindB = $authService->bind('friki@sanctuario.arc', 'palabra-secreta-del-mago', new DateTimeImmutable($now));
$bindC = $authService->bind('friki@sanctuario.arc', 'palabra-secreta-del-mago', new DateTimeImmutable($now));
assertArcane($bindA->success && $bindB->success && $bindC->success, 'Tres vínculos activos concurrentes del mismo usuario');

// Disolución individual: solo el dispositivo A cae.
$dissolvedOne = $authService->dissolve($bindA->session->getToken());
assertArcane($dissolvedOne, 'dissolve() disuelve el vínculo del dispositivo A');
$rowA = (int) $pdo->query("SELECT COUNT(*) FROM user_sessions WHERE session_token_hash = '" . hash('sha256', $bindA->session->getToken()) . "'")->fetchColumn();
$rowB = (int) $pdo->query("SELECT COUNT(*) FROM user_sessions WHERE session_token_hash = '" . hash('sha256', $bindB->session->getToken()) . "'")->fetchColumn();
assertArcane($rowA === 0 && $rowB === 1, 'Solo la sesión disuelta desaparece; las demás sobreviven');

// Disolución global: B y C caen a la vez.
$dissolvedAll = $authService->dissolveAll($bindB->session->getToken());
assertArcane($dissolvedAll, 'dissolveAll() invalida todas las sesiones del usuario');

$remainingSessions = (int) $pdo->query(
    "SELECT COUNT(*) FROM user_sessions WHERE user_id = '" . $bindB->userId . "'"
)->fetchColumn();
assertArcane($remainingSessions === 0, 'Tras dissolveAll(), cero sesiones activas del usuario en base de datos');

// El token B ya no resuelve nada.
assertArcane(
    $sessionManager->resolveSession($bindB->session->getToken(), new DateTimeImmutable($now)) === null,
    'El token previo ya no resuelve ninguna sesión tras la disolución global'
);

// Disolución de un token ajeno: devuelto como no disuelto.
assertArcane($authService->dissolve('token-inexistente-de-otro-reino') === false, 'dissolve() devuelve falso para un token ajeno');

// ---------------------------------------------------------------------
// 5. Pergamino de Restablecimiento (RF-04.1, RF-04.2).
// ---------------------------------------------------------------------
echo "\n[5] Recuperación de acceso\n";

// 5a. Solicitud con correo registrado.
$recovery = $authService->requestRecovery('friki@sanctuario.arc', new DateTimeImmutable($now));
assertArcane($recovery->tokenIssued && $recovery->recoveryToken !== null, 'requestRecovery() emite un pergamino de restablecimiento');

// 5b. Solicitud con correo ajeno: respuesta neutra SIN token (anti-enumeración).
$ghostRecovery = $authService->requestRecovery('nadie@sanctuario.arc', new DateTimeImmutable($now));
assertArcane(!$ghostRecovery->tokenIssued && $ghostRecovery->recoveryToken === null, 'Un correo ajeno jamás recibe token (anti-enumeración)');

// 5c. El token viaja hasheado en BD (jamás en claro).
$storedRecoveryHash = (string) $pdo->query("SELECT recovery_token_hash FROM users WHERE email = 'friki@sanctuario.arc'")->fetchColumn();
assertArcane($storedRecoveryHash === hash('sha256', $recovery->recoveryToken), 'El token de recuperación se persiste solo como SHA-256');

// 5d. Restablecimiento con el token válido: cambia la frase y revoca sesiones.
$pdo->exec(
    "INSERT INTO user_sessions (id, session_token_hash, user_id, ip_address, user_agent, created_at, last_activity_at, expires_at, absolute_expires_at)
     VALUES ('ses_pre_reset', '" . hash('sha256', 'token-sesion-previa-al-reset') . "', '" . $consecration->userId . "', '127.0.0.1', 'Arnés/1.0', '{$now}', '{$now}', '2026-09-26T12:00:00Z', '2026-10-12T12:00:00Z')"
);

$reset = $authService->resetPassword($recovery->recoveryToken, 'nueva-palabra-arcana-678', new DateTimeImmutable($now));
assertArcane($reset, 'resetPassword() acepta un token válido');

$newHash = (string) $pdo->query("SELECT password_hash FROM users WHERE email = 'friki@sanctuario.arc'")->fetchColumn();
assertArcane(password_verify('nueva-palabra-arcana-678', $newHash), 'La nueva frase de paso verifica contra el nuevo hash');
assertArcane(!password_verify('palabra-secreta-del-mago', $newHash), 'La frase antigua ya no verifica');

$preResetSessions = (int) $pdo->query(
    "SELECT COUNT(*) FROM user_sessions WHERE user_id = '" . $consecration->userId . "'"
)->fetchColumn();
assertArcane($preResetSessions === 0, 'El restablecimiento revoca preventivamente todas las sesiones previas (RF-04.2)');

$clearedHash = (string) $pdo->query("SELECT recovery_token_hash FROM users WHERE email = 'friki@sanctuario.arc'")->fetchColumn();
assertArcane($clearedHash === '', 'El pergamino usado queda invalidado (un solo uso)');

// 5e. Reutilización del token: rechazada.
$reuseRejected = !$authService->resetPassword($recovery->recoveryToken, 'otra-clave-99', new DateTimeImmutable($now));
assertArcane($reuseRejected, 'Un pergamino ya consumido es rechazado (un solo uso)');

// 5f. Token caducado (emitido hace 2 horas, vigencia de 1 hora).
$expiredRecovery = $authService->requestRecovery('friki@sanctuario.arc', new DateTimeImmutable($now));
$twoHoursLater = (new DateTimeImmutable($now))->modify('+2 hours');
$expiredRejected = !$authService->resetPassword($expiredRecovery->recoveryToken, 'clave-caducada-01', $twoHoursLater);
assertArcane($expiredRejected, 'Un pergamino con más de 60 minutos de vida es rechazado');

// ---------------------------------------------------------------------
// Limpieza del sandbox.
// ---------------------------------------------------------------------
$pdo = null;
gc_collect_cycles();
if (file_exists($sandboxDb)) {
    @unlink($sandboxDb);
}

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
ob_end_flush();
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);
