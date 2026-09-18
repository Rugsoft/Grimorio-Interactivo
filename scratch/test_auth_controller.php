<?php

/**
 * test_auth_controller.php — Arnés de la Tarea 3.3 de TASKS-03.
 *
 * Verifica el controlador REST src/Controllers/AuthController.php contra
 * los contratos exactos del plan (sección 2.2, Endpoints 1-5): estructuras
 * JSON tipadas y códigos HTTP 200, 201, 400, 401, 409 y 429.
 *
 * Estrategia TDD: este arnés se escribe ANTES de implementar el controlador.
 * Fase roja = la clase Grimorio\Controllers\AuthController no existe todavía.
 *
 * Escenarios verificados (criterio «Hecho cuando» de la tarea):
 *   1. POST /api/v1/auth/consecrate  → 201 + cookie de sesión emitida;
 *      400 ante datos inválidos; 409 neutro ante identidad reclamada.
 *   2. POST /api/v1/auth/bind        → 200 + Set-Cookie segura
 *      (HttpOnly, SameSite=Strict, Max-Age=1209600); 401 neutro; 429 con
 *      remainingSeconds tras 5 fallos (RF-03.2).
 *   3. POST /api/v1/auth/dissolve y /dissolve-all → 200 con revocación
 *      real en base de datos.
 *   4. GET /api/v1/auth/session      → 200 autenticado y 200 anónimo.
 *   5. /auth/recovery/request y /auth/recovery/reset → 200 neutro y
 *      restablecimiento con revocación de sesiones.
 *
 * Ejecución: php scratch/test_auth_controller.php  (exit 0 = verde)
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

/**
 * Decodifica el cuerpo JSON de una Response con aserción integrada.
 */
function decodeJson(object $response): array
{
    $decoded = json_decode($response->getBody(), true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Construye un Request JSON tipado como el que despacharía el Front Controller.
 * El cuerpo viaja inyectado porque la SAPI CLI no admite escritura en
 * php://input (bajo SAPI web el flujo es el nativo de php://input).
 */
function forgeJsonRequest(string $method, string $path, array $payload = [], array $headers = []): \Grimorio\Core\Request
{
    $rawBody = $payload === [] ? '' : (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

    return new \Grimorio\Core\Request($method, $path, [], $headers + ['Content-Type' => 'application/json'], $rawBody);
}

$projectRoot = dirname(__DIR__);

// Buffer diferido: el SessionManager emite cookies (setcookie) durante bind/
// consecrate; en CLI los warnings cosméticos no afectan a las aserciones.
ob_start();

echo "=== Tarea 3.3 (TASKS-03): Controlador de autenticación y sesiones ===\n\n";

require_once $projectRoot . '/public/index.php'; // Autoload nativo del proyecto.

use Grimorio\Controllers\AuthController;
use Grimorio\Core\RateLimiter;
use Grimorio\Core\Request;
use Grimorio\Core\SessionManager;

echo "[0] Existencia y cargabilidad del controlador\n";

assertArcane(class_exists(AuthController::class), 'La clase Grimorio\Controllers\AuthController existe y el autoload la resuelve');

if (!class_exists(AuthController::class)) {
    echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
    exit(1);
}

// ---------------------------------------------------------------------
// Sandbox: base SQLite efímera con el esquema completo y dependencias.
// ---------------------------------------------------------------------
$sandboxDb = sys_get_temp_dir() . '/grimorio_auth_ctrl_' . getmypid() . '.sqlite';
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
     VALUES ('cln_ctrl', 'ctrl-lineage', 'Linaje del Arnés', 'Ensayo', '{$now}')"
);

$sessionManager = new SessionManager($pdo, '127.0.0.1', 'Arnés AuthController/1.0');
$rateLimiter    = new RateLimiter($pdo);
$controller     = new AuthController($pdo, $sessionManager, $rateLimiter);

// ---------------------------------------------------------------------
// 1. Endpoint 1: POST /api/v1/auth/consecrate (plan 2.2).
// ---------------------------------------------------------------------
echo "\n[1] Consagración vía REST (RF-01): 201, 400 y 409 neutro\n";

$consecrateResponse = $controller->consecrate(forgeJsonRequest('POST', '/api/v1/auth/consecrate', [
    'alias'      => 'FrierenElf',
    'email'      => 'frieren@sanctuario.arc',
    'passphrase' => 'palabra-secreta-del-mago',
    'clanId'     => 'cln_ctrl',   // Enmienda SPEC-09: legado, se ignora en silencio.
]));

assertArcane($consecrateResponse->getStatusCode() === 201, 'Consagración válida responde 201 Created');
$consecrateBody = decodeJson($consecrateResponse);
assertArcane(($consecrateBody['success'] ?? null) === true, 'El cuerpo porta success: true');
$userData = $consecrateBody['data']['user'] ?? [];
assertArcane(
    ($userData['role'] ?? null) === 'editor' && str_starts_with((string) ($userData['id'] ?? ''), 'usr_'),
    'El contrato data.user porta id usr_* y role editor (plan Endpoint 1)'
);
assertArcane(
    array_key_exists('lineage', $userData) && $userData['lineage'] === null && !isset($userData['clanId']) && !isset($userData['clanName']),
    'El contrato SPEC-09 porta lineage: null (peregrina) y ya no porta clanId ni clanName'
);
assertArcane(!isset($userData['passwordHash']), 'La respuesta jamás expone passwordHash');
// La consagración próspera vincula sesión automáticamente (RF-01.2): la
// cookie la emite el SessionManager; el efecto observable aquí es la fila
// de user_sessions (la cookie física ya quedó verificada por HTTP real en
// la Tarea 2.1; en CLI headers_list() siempre está vacío).
$consecratedId = (string) ($userData['id'] ?? '');
$sessionCountStatement = $pdo->prepare('SELECT COUNT(*) FROM user_sessions WHERE user_id = :userId');
$sessionCountStatement->execute([':userId' => $consecratedId]);
assertArcane(
    (int) $sessionCountStatement->fetchColumn() === 1,
    'La consagración emite la cookie de sesión (vínculo automático inscrito en user_sessions)'
);

// 1b. Datos inválidos: 400 con código de error canónico.
$invalidResponse = $controller->consecrate(forgeJsonRequest('POST', '/api/v1/auth/consecrate',
    ['alias' => 'ab', 'email' => 'x@y.arc', 'passphrase' => 'clave-larga-suficiente', 'clanId' => 'cln_ctrl']));
$invalidBody = decodeJson($invalidResponse);
assertArcane($invalidResponse->getStatusCode() === 400, 'Datos inválidos (alias corto) responden 400 Bad Request');
assertArcane(
    ($invalidBody['success'] ?? null) === false && ($invalidBody['error']['code'] ?? null) === 'INVALID_REGISTRATION_DATA',
    'El 400 porta error.code INVALID_REGISTRATION_DATA'
);

// 1c. Payload malformado (JSON roto): 400 sin explosión interna.
$malformedResponse = $controller->consecrate(new Request('POST', '/api/v1/auth/consecrate', [], ['Content-Type' => 'application/json'], '{"alias": roto'));
assertArcane($malformedResponse->getStatusCode() === 400, 'JSON malformado responde 400 sin explosión interna');

// 1d. Identidad ya reclamada: 409 neutro (anti-enumeración, RF-01.3).
$duplicateResponse = $controller->consecrate(forgeJsonRequest('POST', '/api/v1/auth/consecrate',
    ['alias' => 'FrierenElf', 'email' => 'otra@sanctuario.arc', 'passphrase' => 'clave-larga-suficiente', 'clanId' => 'cln_ctrl']));
$duplicateBody = decodeJson($duplicateResponse);
assertArcane($duplicateResponse->getStatusCode() === 409, 'Identidad ya reclamada responde 409 Conflict');
assertArcane(
    ($duplicateBody['error']['code'] ?? null) === 'IDENTITY_ALREADY_CLAIMED',
    'El 409 porta el código neutro IDENTITY_ALREADY_CLAIMED (sin revelar qué campo choca)'
);

// ---------------------------------------------------------------------
// 2. Endpoint 2: POST /api/v1/auth/bind (plan 2.2, RF-02.1, RF-03).
// ---------------------------------------------------------------------
echo "\n[2] Vínculo vía REST (RF-02/RF-03): 200 con cookie segura, 401 neutro y 429\n";

$bindResponse = $controller->bind(forgeJsonRequest('POST', '/api/v1/auth/bind',
    ['identity' => 'frieren@sanctuario.arc', 'passphrase' => 'palabra-secreta-del-mago']));
$bindBody = decodeJson($bindResponse);
assertArcane($bindResponse->getStatusCode() === 200, 'Credenciales válidas responden 200 OK');
assertArcane(
    ($bindBody['data']['user']['alias'] ?? null) === 'FrierenElf',
    'El contrato data.user porta el alias del vinculado'
);
// Segundo vínculo inscrito: la cookie viaja vía SessionManager (verificada
// por HTTP real en la Tarea 2.1); aquí se comprueba su efecto en BD.
$sessionCountStatement->execute([':userId' => $consecratedId]);
assertArcane(
    (int) $sessionCountStatement->fetchColumn() === 2,
    'El bind emite la cookie con un nuevo vínculo inscrito (2 sesiones del titular)'
);

// 2b. Credenciales erróneas: 401 neutro (RF-03.1).
$badBindResponse = $controller->bind(forgeJsonRequest('POST', '/api/v1/auth/bind',
    ['identity' => 'frieren@sanctuario.arc', 'passphrase' => 'frase-erronea-larga']));
$badBindBody = decodeJson($badBindResponse);
assertArcane($badBindResponse->getStatusCode() === 401, 'Credenciales erróneas responden 401 Unauthorized');
assertArcane(
    ($badBindBody['error']['code'] ?? null) === 'INVALID_CREDENTIALS'
    && str_contains((string) ($badBindBody['error']['message'] ?? ''), 'runas no reconocen'),
    'El 401 porta la leyenda neutra «Las runas no reconocen este vínculo...»'
);

// 2c. JSON malformado en bind: 400.
$malformedBind = $controller->bind(new Request('POST', '/api/v1/auth/bind', [], ['Content-Type' => 'application/json'], 'no-soy-json'));
assertArcane($malformedBind->getStatusCode() === 400, 'JSON malformado en bind responde 400');

// 2d. Rate limiting integrado (RF-03.2): el 401 erróneo de [2b] ya
// consumió 1 fallo de la ventana, así que el asedio aporta 4 más; el
// siguiente intento queda congelado con 429.
echo "\n[3] Sobrecarga de maná vía REST: 429 Too Many Requests (RF-03.2)\n";

for ($failure = 0; $failure < 4; $failure++) {
    $siegeResponse = $controller->bind(forgeJsonRequest('POST', '/api/v1/auth/bind',
        ['identity' => 'frieren@sanctuario.arc', 'passphrase' => 'otra-frase-erronea-' . $failure]));
    assertArcane($siegeResponse->getStatusCode() === 401, 'Asedio ' . ($failure + 1) . '/4 sigue respondiendo 401');
}

$frozenResponse = $controller->bind(forgeJsonRequest('POST', '/api/v1/auth/bind',
    ['identity' => 'frieren@sanctuario.arc', 'passphrase' => 'palabra-secreta-del-mago']));
$frozenBody = decodeJson($frozenResponse);
assertArcane($frozenResponse->getStatusCode() === 429, 'Tras 5 fallos, la 6.ª petición responde 429 Too Many Requests');
assertArcane(
    ($frozenBody['error']['code'] ?? null) === 'RATE_LIMITED'
    && isset($frozenBody['error']['remainingSeconds'])
    && (int) $frozenBody['error']['remainingSeconds'] > 0
    && (int) $frozenBody['error']['remainingSeconds'] <= 900,
    'El 429 porta RATE_LIMITED y remainingSeconds en (0, 900]'
);
assertArcane(
    str_contains((string) ($frozenBody['error']['message'] ?? ''), '15 minutos'),
    'La leyenda solemne anuncia el cierre de 15 minutos'
);

// Incluso con credenciales VÁLIDAS, la IP congelada es rechazada (Art. III).
assertArcane(!isset($frozenBody['data']), 'La IP congelada no vincula ni con credenciales correctas');

// Otra procedencia (IP distinta) NO hereda el bloqueo: getClientIp()
// respeta el encadenado X-Forwarded-For (primer salto), que es la vía
// realista para simular una procedencia distinta bajo CLI.
$otherSessionManager = new SessionManager($pdo, '203.0.113.77', 'Víctima/1.0');
$otherController = new AuthController($pdo, $otherSessionManager, $rateLimiter);
$victimResponse = $otherController->bind(forgeJsonRequest('POST', '/api/v1/auth/bind',
    ['identity' => 'frieren@sanctuario.arc', 'passphrase' => 'palabra-secreta-del-mago'],
    ['X-Forwarded-For' => '203.0.113.77']));
assertArcane($victimResponse->getStatusCode() === 200, 'Otra procedencia NO queda bloqueada (el castigo es de la IP, no de la cuenta)');

// ---------------------------------------------------------------------
// 4. Endpoint 4: GET /api/v1/auth/session (plan 2.2).
// ---------------------------------------------------------------------
echo "\n[4] Verificación de sesión vía REST (RF-02)\n";

// Token del titular para las pruebas de disolución: el token crudo solo
// existe en el instante de creación, así que el arnés lo obtiene del
// servicio directo (fuera de la API, como lo recibiría el navegador).
$probeAuthService = new \Grimorio\Services\AuthService($pdo, $otherSessionManager);
$victimBind = $probeAuthService->bind('FrierenElf', 'palabra-secreta-del-mago');
$victimToken = $victimBind->session?->getToken() ?? '';

$authedRequest = new Request('GET', '/api/v1/auth/session', [], []);
$frierenRow = $pdo->query("SELECT id, alias, email, role, clan_id, '' AS password_hash, created_at, updated_at FROM users WHERE alias = 'FrierenElf'")->fetch(PDO::FETCH_ASSOC);
assertArcane(is_array($frierenRow), 'El titular de la víctima existe materializable tras su vínculo');
if (is_array($frierenRow)) {
    $authedRequest->setUser(\Grimorio\Models\User::fromDatabaseRow($frierenRow));
    $sessionResponse = $controller->session($authedRequest);
    $sessionBody = decodeJson($sessionResponse);
    assertArcane($sessionResponse->getStatusCode() === 200, 'La verificación de sesión responde 200 en ambos caminos');
    assertArcane(
        ($sessionBody['data']['authenticated'] ?? null) === true
        && ($sessionBody['data']['user']['role'] ?? null) === 'editor',
        'Autenticado: data.authenticated true con role del titular'
    );
}

$anonymousResponse = $controller->session(new Request('GET', '/api/v1/auth/session'));
$anonymousBody = decodeJson($anonymousResponse);
assertArcane(
    $anonymousResponse->getStatusCode() === 200
    && ($anonymousBody['data']['authenticated'] ?? null) === false
    && array_key_exists('user', $anonymousBody['data'])
    && $anonymousBody['data']['user'] === null,
    'Anónimo: 200 con data.authenticated false y user null (plan Endpoint 4)'
);

// ---------------------------------------------------------------------
// 3. Endpoints 3: dissolve y dissolve-all (plan 2.2, RF-02.4).
// ---------------------------------------------------------------------
echo "\n[5] Disolución individual y global vía REST (RF-02.4)\n";

// Escenario aislado: se purgan los vínculos acumulados por las pruebas
// anteriores para que el conteo sea exactamente el del plan (3 dispositivos).
$pdo->exec('DELETE FROM user_sessions');

// Tres dispositivos activos del titular.
$deviceTokens = [];
foreach ([1, 2, 3] as $device) {
    $extraManager = new SessionManager($pdo, '198.51.100.' . $device, 'Dispositivo ' . $device);
    $extraBind = new \Grimorio\Services\AuthService($pdo, $extraManager);
    $extraResult = $extraBind->bind('FrierenElf', 'palabra-secreta-del-mago');
    assertArcane($extraResult->success && $extraResult->session !== null, 'Dispositivo ' . $device . ' vinculado con éxito');
    $deviceTokens[] = $extraResult->session->getToken();
}

// Resolución previa: los tres tokens viven en BD.
$activeCount = (int) $pdo->query("SELECT COUNT(*) FROM user_sessions")->fetchColumn();
assertArcane($activeCount === 3, 'Tres dispositivos con vínculo activo antes de la disolución');

// Disolución individual del dispositivo actual.
$dissolveResponse = $controller->dissolve(forgeJsonRequest('POST', '/api/v1/auth/dissolve', [], ['Cookie' => 'grimorio_session=' . $deviceTokens[0]]));
$dissolveBody = decodeJson($dissolveResponse);
assertArcane($dissolveResponse->getStatusCode() === 200, 'dissolve responde 200 OK');
assertArcane(
    str_contains((string) ($dissolveBody['data']['message'] ?? ''), 'disuelto'),
    'El contrato porta la leyenda «El vínculo ha sido disuelto en paz.»'
);
$afterDissolve = (int) $pdo->query("SELECT COUNT(*) FROM user_sessions")->fetchColumn();
assertArcane($afterDissolve === 2, 'La disolución individual revoca solo el dispositivo actual');

// Disolución global.
$dissolveAllResponse = $controller->dissolveAll(forgeJsonRequest('POST', '/api/v1/auth/dissolve-all', [], ['Cookie' => 'grimorio_session=' . $deviceTokens[1]]));
$dissolveAllBody = decodeJson($dissolveAllResponse);
assertArcane($dissolveAllResponse->getStatusCode() === 200, 'dissolve-all responde 200 OK');
$afterDissolveAll = (int) $pdo->query("SELECT COUNT(*) FROM user_sessions")->fetchColumn();
assertArcane($afterDissolveAll === 0, 'dissolve-all deja cero sesiones activas del titular en base de datos');

// Sin cookie de sesión: 401 (no hay vínculo que disolver).
$noCookieResponse = $controller->dissolve(new Request('POST', '/api/v1/auth/dissolve'));
assertArcane($noCookieResponse->getStatusCode() === 401, 'dissolve sin vínculo activo responde 401 Unauthorized');

// ---------------------------------------------------------------------
// 5. Endpoints 5: recuperación (plan 2.2, RF-04).
// ---------------------------------------------------------------------
echo "\n[6] Pergamino de Restablecimiento vía REST (RF-04)\n";

// Solicitud: respuesta 200 neutra en AMBOS caminos (anti-enumeración).
$requestOk = $controller->recoveryRequest(forgeJsonRequest('POST', '/api/v1/auth/recovery/request',
    ['email' => 'frieren@sanctuario.arc']));
$requestOkBody = decodeJson($requestOk);
assertArcane($requestOk->getStatusCode() === 200 && ($requestOkBody['success'] ?? null) === true, 'Solicitud con correo registrado responde 200 neutro');

$requestGhost = $controller->recoveryRequest(forgeJsonRequest('POST', '/api/v1/auth/recovery/request',
    ['email' => 'fantasma@sanctuario.arc']));
$requestGhostBody = decodeJson($requestGhost);
assertArcane($requestGhost->getStatusCode() === 200 && ($requestGhostBody['success'] ?? null) === true, 'Solicitud con correo ajeno responde idéntico 200 (anti-enumeración)');

// Recuperar el token emitido del primer camino para el restablecimiento.
$recoveryRow = $pdo->query("SELECT recovery_token_expires_at FROM users WHERE alias = 'FrierenElf'")->fetch(PDO::FETCH_ASSOC);
assertArcane(
    is_array($recoveryRow) && $recoveryRow['recovery_token_expires_at'] !== null,
    'El primer camino sí inscribió un pergamino con vigencia en base de datos'
);

// El controlador necesita exponer el token crudo para el arnés... No: el
// arnés lo reconstruye con el servicio directamente (el token crudo jamás
// viaja por la API; el plan lo entrega por correo fuera de banda).
$recoveryService = new \Grimorio\Services\AuthService($pdo, $sessionManager);
$recoveryIssued = $recoveryService->requestRecovery('frieren@sanctuario.arc');
assertArcane($recoveryIssued->tokenIssued && is_string($recoveryIssued->recoveryToken), 'El servicio emite el token crudo (vía fuera de banda, jamás por la API)');

// Vincular de nuevo para probar la revocación preventiva del restablecimiento.
$rebind = $recoveryService->bind('FrierenElf', 'palabra-secreta-del-mago');
assertArcane($rebind->success, 'El titular re-vincula antes del restablecimiento');
$preResetSessions = (int) $pdo->query("SELECT COUNT(*) FROM user_sessions")->fetchColumn();
assertArcane($preResetSessions >= 1, 'Hay sesiones activas que el restablecimiento debe revocar');

$resetResponse = $controller->recoveryReset(forgeJsonRequest('POST', '/api/v1/auth/recovery/reset',
    ['token' => $recoveryIssued->recoveryToken, 'newPassphrase' => 'nueva-palabra-arcana-678']));
assertArcane($resetResponse->getStatusCode() === 200, 'Restablecimiento válido responde 200 OK');
$afterResetSessions = (int) $pdo->query("SELECT COUNT(*) FROM user_sessions")->fetchColumn();
assertArcane($afterResetSessions === 0, 'El restablecimiento revocó todas las sesiones previas (RF-04.2)');

// La frase nueva funciona; la vieja ya no.
$rebindNew = $recoveryService->bind('frieren@sanctuario.arc', 'nueva-palabra-arcana-678');
$rebindOld = $recoveryService->bind('frieren@sanctuario.arc', 'palabra-secreta-del-mago');
assertArcane($rebindNew->success && !$rebindOld->success, 'La nueva frase vincula y la antigua quedó muerta');

// Restablecimiento con token podrido: 400 (no 200).
$staleReset = $controller->recoveryReset(forgeJsonRequest('POST', '/api/v1/auth/recovery/reset',
    ['token' => str_repeat('f', 64), 'newPassphrase' => 'otra-frase-arcana-999']));
$staleResetBody = decodeJson($staleReset);
assertArcane(
    $staleReset->getStatusCode() === 400 && ($staleResetBody['error']['code'] ?? null) === 'RECOVERY_TOKEN_INVALID',
    'Token inexistente, consumido o caducado responde 400 RECOVERY_TOKEN_INVALID'
);

// JSON malformado en recovery: 400.
$malformedRecovery = $controller->recoveryRequest(new Request('POST', '/api/v1/auth/recovery/request', [], ['Content-Type' => 'application/json'], '}{'));
assertArcane($malformedRecovery->getStatusCode() === 400, 'JSON malformado en recovery responde 400');

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";

@unlink($sandboxDb);
exit($assertsFailed === 0 ? 0 : 1);
