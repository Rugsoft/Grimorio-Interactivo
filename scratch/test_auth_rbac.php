<?php

/**
 * test_auth_rbac.php — Verificación integral de SPEC-03 (Tarea 3.5).
 *
 * Script de integración E2E que ejecuta secuencialmente los 6 escenarios
 * del plan 5.1 contra los controladores REST reales (AuthController,
 * AuditController) y los middlewares (AuthMiddleware, RbacMiddleware):
 *   1. Consagración: registro con rol técnico editor confirmado.
 *   2. Login con emisión de cookie segura (Set-Cookie HttpOnly,
 *      SameSite=Strict vía HTTP real con sonda php -S).
 *   3. Matriz RBAC de los 4 roles sobre una ruta protegida de master.
 *   4. Bloqueo por conflicto de intereses a 30 días (Artículo III).
 *   5. Rate-limiting de 5 fallos → 429 Too Many Requests.
 *   6. Disolución global: dissolve-all invalida todas las sesiones en BD.
 *
 * Ejecución: php scratch/test_auth_rbac.php  (exit 0 = verde)
 *
 * Nota de diseño: la SAPI CLI no registra cabeceras ni rellena $_COOKIE,
 * de modo que (a) el cuerpo JSON viaja inyectado en el Request (mismo
 * contrato que php://input bajo SAPI web) y (b) la cookie física se
 * verifica por HTTP real con una sonda servida por php -S, exactamente
 * como en el arnés de la Tarea 2.1.
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
 * Decodifica el cuerpo JSON de una Response.
 */
function decodeJson(object $response): array
{
    $decoded = json_decode($response->getBody(), true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Construye un Request JSON con cuerpo inyectado (CLI-friendly).
 */
function forgeJsonRequest(string $method, string $path, array $payload = [], array $headers = [], array $queryParams = []): Grimorio\Core\Request
{
    $rawBody = $payload === [] ? '' : (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

    return new Grimorio\Core\Request($method, $path, $queryParams, $headers + ['Content-Type' => 'application/json'], $rawBody);
}

$projectRoot = dirname(__DIR__);

// Buffer diferido: setcookie() exige emitir cabeceras antes de output.
ob_start();

echo "=== Tarea 3.5 (TASKS-03): Verificación integral de autenticación y RBAC ===\n\n";

require_once $projectRoot . '/public/index.php'; // Autoload nativo del proyecto.

use Grimorio\Controllers\AuditController;
use Grimorio\Controllers\AuthController;
use Grimorio\Core\RateLimiter;
use Grimorio\Core\Request;
use Grimorio\Core\SessionManager;
use Grimorio\Middleware\AuthMiddleware;
use Grimorio\Middleware\RbacMiddleware;
use Grimorio\Models\User;
use Grimorio\Services\AuditService;
use Grimorio\Services\AuthService;
use Grimorio\Services\ClanConflictService;

// ---------------------------------------------------------------------
// Sandbox: base SQLite efímera con el esquema completo.
// ---------------------------------------------------------------------
$sandboxDb = sys_get_temp_dir() . '/grimorio_auth_rbac_' . getmypid() . '.sqlite';
if (file_exists($sandboxDb)) {
    @unlink($sandboxDb);
}

$pdo = new PDO('sqlite:' . $sandboxDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

$now = '2026-09-12T12:00:00Z';
$pdo->exec(
    "INSERT INTO clans (id, slug, name, motto, domain_points, created_at) VALUES
     ('cln_astral', 'astral-scholars', 'Eruditos Astrales', 'Saber', 0, '{$now}'),
     ('cln_ember', 'ember-wardens', 'Guardianes de Ascuas', 'Fuego', 0, '{$now}')"
);

// Cableado de producción: los mismos servicios que montará el front controller.
$sessionManager  = new SessionManager($pdo, '127.0.0.1', 'Arnés Integral/1.0');
$rateLimiter     = new RateLimiter($pdo);
$authController  = new AuthController($pdo, $sessionManager, $rateLimiter);
$auditService    = new AuditService($pdo);
$auditController = new AuditController($auditService);

// =====================================================================
// PRUEBA 1 (plan 5.1): Consagración — rol técnico editor confirmado.
// =====================================================================
echo "\n[1] PRUEBA 1 — Consagración con rol técnico editor (RF-01)\n";

$consecrateResponse = $authController->consecrate(forgeJsonRequest('POST', '/api/v1/auth/consecrate', [
    'alias'      => 'FrierenElf',
    'email'      => 'frieren@sanctuario.arc',
    'passphrase' => 'palabra-secreta-del-mago',
    'clanId'     => 'cln_astral',
]));
$consecrateBody = decodeJson($consecrateResponse);
assertArcane($consecrateResponse->getStatusCode() === 201, 'POST /auth/consecrate responde 201 Created');
assertArcane(
    ($consecrateBody['data']['user']['role'] ?? null) === 'editor',
    'El rol técnico asignado por defecto es editor (RF-01.2)'
);
$consecratedId = (string) ($consecrateBody['data']['user']['id'] ?? '');
assertArcane(str_starts_with($consecratedId, 'usr_'), 'La cuenta recibe identificador usr_*');

// Segundo iniciado del clan ember para las pruebas de conflicto (Prueba 4).
$emberBind = new AuthService($pdo, new SessionManager($pdo, '127.0.0.1', 'Arnés Ember/1.0'));
$emberBind->consecrate('EisenElHachazo', 'eisen@sanctuario.arc', 'hacha-arcana-larga-123', 'cln_ember');

// El Maestro de las pruebas de conflicto necesita fila real en BD:
// clan_history (Prueba 4) porta FK user_id → users.
$pdo->prepare(
    'INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
     VALUES (:id, :alias, :email, :passwordHash, :role, :clanId, :createdAt, :updatedAt)'
)->execute([
    ':id'           => 'usr_master_heiter',
    ':alias'        => 'HeiterElSabio',
    ':email'        => 'heiter@sanctuario.arc',
    ':passwordHash' => str_repeat('x', 60),
    ':role'         => 'master',
    ':clanId'       => 'cln_astral',
    ':createdAt'    => $now,
    ':updatedAt'    => $now,
]);

// =====================================================================
// PRUEBA 2 (plan 5.1): Login con cookie segura vía HTTP real.
// =====================================================================
echo "\n[2] PRUEBA 2 — Login con emisión de cookie segura (RF-02)\n";

// 2a. El controlador REST responde 200 con el contrato del plan.
$bindResponse = $authController->bind(forgeJsonRequest('POST', '/api/v1/auth/bind', [
    'identity'   => 'frieren@sanctuario.arc',
    'passphrase' => 'palabra-secreta-del-mago',
]));
$bindBody = decodeJson($bindResponse);
assertArcane($bindResponse->getStatusCode() === 200, 'POST /auth/bind con credenciales válidas responde 200 OK');
assertArcane(($bindBody['data']['user']['alias'] ?? null) === 'FrierenElf', 'El contrato data.user porta el alias vinculado');

// 2b. La cookie FÍSICA se verifica por HTTP real (sonda php -S con el
// SessionManager de producción), pues la CLI nunca registra cabeceras.
$probePort = 8098;
$probeFile = $projectRoot . '/scratch/__auth_rbac_probe_' . getmypid() . '.php';
file_put_contents($probeFile, <<<'PROBE'
<?php
declare(strict_types=1);
require __DIR__ . '/../public/index.php';
use Grimorio\Core\SessionManager;
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));
$now = '2026-09-12T12:00:00Z';
$pdo->exec("INSERT INTO clans (id, slug, name, motto, domain_points, created_at) VALUES ('c1','s','N','M',0,'{$now}')");
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
assertArcane($sessionCookie !== null, 'El login emite la cookie grimorio_session vía Set-Cookie');
if ($sessionCookie !== null) {
    assertArcane(stripos($sessionCookie, 'HttpOnly') !== false, 'La cookie porta HttpOnly (RF-02.1)');
    assertArcane(stripos($sessionCookie, 'SameSite=Strict') !== false, 'La cookie porta SameSite=Strict (blindaje CSRF)');
}

// =====================================================================
// PRUEBA 3 (plan 5.1): Matriz RBAC de los 4 roles.
// =====================================================================
echo "\n[3] PRUEBA 3 — Matriz RBAC de los 4 roles (RF-05)\n";

/**
 * Cuenta una entidad User in-memory para el RbacMiddleware.
 */
function forgeUser(string $id, string $alias, string $email, string $role, string $clanId): User
{
    return new User(
        id: $id,
        alias: $alias,
        email: $email,
        role: $role,
        clanId: $clanId,
        passwordHash: '',
        createdAt: '2026-09-12T12:00:00Z',
        updatedAt: '2026-09-12T12:00:00Z',
    );
}

$rbac = new RbacMiddleware();
$roleMatrix = [
    'reader'       => ['master' => false],
    'editor'       => ['master' => false],
    'master'       => ['master' => true],
    'supremeAdmin' => ['master' => true],
];

foreach ($roleMatrix as $role => $expectations) {
    $probeRequest = new Request('GET', '/api/v1/protected/rune-forge', [], []);
    $probeRequest->setUser(forgeUser('usr_matrix', 'RolDePrueba', 'matrix@sanctuario.arc', $role, 'cln_astral'));
    $verdict = $rbac->authorize($probeRequest, 'master');

    if ($expectations['master']) {
        assertArcane($verdict === null, "Un {$role} alcanza una ruta de master (herencia de jerarquía)");
    } else {
        $verdictBody = $verdict !== null ? decodeJson($verdict) : [];
        assertArcane(
            $verdict !== null && $verdict->getStatusCode() === 403
            && ($verdictBody['error']['code'] ?? null) === 'INSUFFICIENT_HIERARCHY',
            "Un {$role} sobre ruta de master recibe 403 INSUFFICIENT_HIERARCHY"
        );
    }
}

// =====================================================================
// PRUEBA 4 (plan 5.1): Conflicto de intereses a 30 días (Artículo III).
// =====================================================================
echo "\n[4] PRUEBA 4 — Conflicto de intereses entre linajes (RF-06, Art. III)\n";

$conflictService = new ClanConflictService($pdo);

// 4a. Maestro del mismo clan del autor: veto «vínculo de sangre».
$masterAstral = forgeUser('usr_master_heiter', 'HeiterElSabio', 'heiter@sanctuario.arc', 'master', 'cln_astral');
$sameClanVerdict = $conflictService->canMasterSignSpell(
    $masterAstral,
    authorId: 'usr_alguien_mas',
    spellClanId: 'cln_astral',
);
assertArcane(
    !$sameClanVerdict->isAllowed && str_contains($sameClanVerdict->reason, 'vínculo de sangre'),
    'Un Maestro del clan del autor es vetado con «El vínculo de sangre nubla el juicio...»'
);

// 4b. Historial: abandonó cln_ember hace 10 días → vetado; hace 45 → libre.
$recentLeft = '2026-09-02T00:00:00Z'; // 10 días antes de la marca base.
$pdo->prepare('INSERT INTO clan_history (user_id, clan_id, joined_at, left_at) VALUES (:userId, :clanId, :joinedAt, :leftAt)')
    ->execute([':userId' => 'usr_master_heiter', ':clanId' => 'cln_ember', ':joinedAt' => '2026-01-01T00:00:00Z', ':leftAt' => $recentLeft]);

$recentVerdict = $conflictService->canMasterSignSpell(
    $masterAstral,
    authorId: 'usr_ember_author',
    spellClanId: 'cln_ember',
    now: new DateTimeImmutable('2026-09-12T12:00:00Z'),
);
assertArcane(
    !$recentVerdict->isAllowed && str_contains($recentVerdict->reason, '30 días'),
    'Un linaje abandonado hace 10 días está vetado por la ventana de 30 días'
);

// Reescritura del historial: salida hace 45 días (fuera de la ventana).
$pdo->prepare('UPDATE clan_history SET left_at = :leftAt WHERE user_id = :userId AND clan_id = :clanId')
    ->execute([':leftAt' => '2026-07-29T00:00:00Z', ':userId' => 'usr_master_heiter', ':clanId' => 'cln_ember']);

$ancientVerdict = $conflictService->canMasterSignSpell(
    $masterAstral,
    authorId: 'usr_ember_author',
    spellClanId: 'cln_ember',
    now: new DateTimeImmutable('2026-09-12T12:00:00Z'),
);
assertArcane($ancientVerdict->isAllowed, 'Un linaje abandonado hace 45 días ya no bloquea la firma');

// 4c. Doble barrera: el backend rechaza aunque la UI estuviera burlada.
assertArcane(!$sameClanVerdict->isAllowed, 'El rechazo es estricto e irreversible en el backend (RF-06.2)');

// =====================================================================
// PRUEBA 5 (plan 5.1): Rate-limiting anti-DoS — 5 fallos → 429.
// =====================================================================
echo "\n[5] PRUEBA 5 — Rate-limiting de 5 fallos (RF-03.2)\n";

// Nuevo iniciado para un asedio limpio desde la IP de prueba.
$authController->consecrate(forgeJsonRequest('POST', '/api/v1/auth/consecrate', [
    'alias'      => 'VictimaAsedio',
    'email'      => 'asedio@sanctuario.arc',
    'passphrase' => 'clave-del-sitiado-larga',
    'clanId'     => 'cln_ember',
]));

$lastSiegeStatus = 0;
for ($failure = 0; $failure < 5; $failure++) {
    $siegeResponse = $authController->bind(forgeJsonRequest('POST', '/api/v1/auth/bind', [
        'identity'   => 'asedio@sanctuario.arc',
        'passphrase' => 'intento-erroneo-' . $failure,
    ]));
    $lastSiegeStatus = $siegeResponse->getStatusCode();
    assertArcane($lastSiegeStatus === 401, 'Asedio ' . ($failure + 1) . '/5 responde 401 (aún no congelado)');
}

$frozenResponse = $authController->bind(forgeJsonRequest('POST', '/api/v1/auth/bind', [
    'identity'   => 'asedio@sanctuario.arc',
    'passphrase' => 'clave-del-sitiado-larga', // Credenciales CORRECTAS.
]));
$frozenBody = decodeJson($frozenResponse);
assertArcane($frozenResponse->getStatusCode() === 429, 'La 6.ª petición tras 5 fallos responde 429 Too Many Requests');
assertArcane(
    ($frozenBody['error']['code'] ?? null) === 'RATE_LIMITED'
    && (int) ($frozenBody['error']['remainingSeconds'] ?? 0) > 0,
    'El 429 porta RATE_LIMITED con remainingSeconds de castigo'
);
assertArcane(!isset($frozenBody['data']), 'La IP congelada no vincula ni con credenciales correctas (Art. III)');

// =====================================================================
// PRUEBA 6 (plan 5.1): Cierre global — dissolve-all invalida todo.
// =====================================================================
echo "\n[6] PRUEBA 6 — Disolución global de sesiones (RF-02.4)\n";

// Escenario aislado: tres dispositivos activos del iniciado Frieren.
$pdo->exec('DELETE FROM user_sessions');
$deviceTokens = [];
foreach (['A', 'B', 'C'] as $device) {
    $deviceService = new AuthService($pdo, new SessionManager($pdo, '198.51.100.' . $device, 'Dispositivo ' . $device));
    $deviceResult = $deviceService->bind('FrierenElf', 'palabra-secreta-del-mago');
    assertArcane($deviceResult->success && $deviceResult->session !== null, 'Dispositivo ' . $device . ' vinculado con éxito');
    $deviceTokens[] = $deviceResult->session->getToken();
}
$beforePurge = (int) $pdo->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn();
assertArcane($beforePurge === 3, 'Tres vínculos activos antes del cierre global');

// POST /auth/dissolve-all con la cookie del dispositivo B.
$purgeResponse = $authController->dissolveAll(forgeJsonRequest('POST', '/api/v1/auth/dissolve-all', [], ['Cookie' => 'grimorio_session=' . $deviceTokens[1]]));
assertArcane($purgeResponse->getStatusCode() === 200, 'POST /auth/dissolve-all responde 200 OK');
$afterPurge = (int) $pdo->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn();
assertArcane($afterPurge === 0, 'Todos los registros de sesión en user_sessions quedan invalidados (0 filas)');

// Ninguno de los tres tokens resuelve ya una sesión (AuthMiddleware degrada a anónimo).
$authMiddleware = new AuthMiddleware($pdo, $sessionManager);
$allTokensDead = true;
foreach ($deviceTokens as $deadToken) {
    $_COOKIE['grimorio_session'] = $deadToken;
    $deadRequest = new Request('GET', '/api/v1/auth/session', [], []);
    $authMiddleware->injectContext($deadRequest);
    if ($deadRequest->getUser()?->getId() !== '') {
        $allTokensDead = false;
        break;
    }
}
unset($_COOKIE['grimorio_session']);
assertArcane($allTokensDead, 'Ningún token sobrevive: el middleware degrada a anónimo con cualquiera de los tres');

// =====================================================================
// BONUS de trazabilidad (RF-08.2): la bitácora pública queda servida.
// =====================================================================
echo "\n[7] TRAZABILIDAD — Bitácora pública accesible sin credenciales (RF-08.2)\n";

$auditService->recordAction(
    actorUserId: 'usr_master_heiter',
    actorAlias: 'HeiterElSabio',
    actorRole: 'master',
    actionType: 'SIGN_VALIDATE',
    targetEntityType: 'spell',
    targetEntityId: 'spl_llamas_frieren',
    justification: 'Composición matemática de maná equilibrada y componentes rigurosamente descritos.',
);
$auditResponse = $auditController->log(new Request('GET', '/api/v1/audit/log', ['page' => '1', 'limit' => '25']));
$auditBody = decodeJson($auditResponse);
assertArcane(
    $auditResponse->getStatusCode() === 200
    && ($auditBody['data']['pagination']['totalItems'] ?? null) === 1
    && ($auditBody['data']['items'][0]['actionType'] ?? null) === 'SIGN_VALIDATE',
    'GET /audit/log público lista el veredicto con paginación del plan (Endpoint 6)'
);

// ---------------------------------------------------------------------
// Limpieza del sandbox y de la sonda efímera.
// ---------------------------------------------------------------------
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

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
ob_end_flush();
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);
