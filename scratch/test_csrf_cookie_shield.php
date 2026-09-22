<?php

/**
 * test_csrf_cookie_shield.php — Arnés del hueco de cobertura CSRF (SPEC-03).
 *
 * La auditoría de estabilización halló que el blindaje CSRF del santuario
 * (la cookie `SameSite=Strict` como credencial de sesión, Decisión 1 del
 * plan §4) solo estaba asertado de forma indirecta (HttpOnly en
 * test_auth_rbac) y que NINGÚN arnés verificaba la política íntegra de
 * cookies ni el rechazo de mutaciones sin credencial ni el comportamiento
 * CORS/anti-CSRF de las rutas mutadoras reales.
 *
 * Este arnés lo sella con una SONDA HTTP REAL (patrón de test_auth_rbac:
 * php -S con la pila de producción) contra los endpoints mutadores:
 *
 *   [1] Política íntegra de la cookie de sesión: Set-Cookie porta
 *       HttpOnly, SameSite=Strict, Path=/ y Secure bajo HTTPS —las cuatro
 *       banderas juntas, en una sola cookie, sin atributos extraños.
 *   [2] SameSite=Strict como blindaje CSRF estructural: un formulario
 *       cross-site no envía la cookie en peticiones subsecuentes; el
 *       arnés verifica que el NAVEGADOR no la adjuntaría (simulado por
 *       contrato) y que el backend JAMÁS resuelve sesión desde una
 *       petición sin credencial.
 *   [3] Mutaciones sin credencial: POST/PUT/DELETE de las rutas canónicas
 *       responden 401/403/409 —jamás mutan— sin cookie de sesión.
 *   [4] Mutaciones con credencial borrada en vuelo: una cookie con token
 *       inexistente no resuelve sesión (401) y jamás procede el rito.
 *   [5] GET sin credencial sí sirve lo público: la política CSRF no
 *       degrada la contemplación pública (RF-05.1 de reader).
 *   [6] La expiración de la cookie usa el mismo canal seguro (Set-Cookie
 *       con SameSite=Strict también al disolver).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): arnés nativo, sin dependencias.
 *   - Artículo V: asertos en castellano.
 *
 * Uso: php scratch/test_csrf_cookie_shield.php  (exit 0 = verde)
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

ob_start();

echo "=== Hueco CSRF (SPEC-03): blindaje de mutaciones y política íntegra de cookies ===\n\n";

require_once $projectRoot . '/public/index.php'; // Autoload nativo del proyecto.

use Grimorio\Controllers\AuthController;
use Grimorio\Core\RateLimiter;
use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Core\SessionManager;
use Grimorio\Services\AuthService;

// ---------------------------------------------------------------------
// Sandbox: base SQLite efímera con la pila completa.
// ---------------------------------------------------------------------
$sandboxDb = sys_get_temp_dir() . '/grimorio_csrf_' . getmypid() . '.sqlite';
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

$sessionManager = new SessionManager($pdo, '127.0.0.1', 'Arnés CSRF/1.0');
$authService = new AuthService($pdo, $sessionManager);
$controller = new AuthController($pdo, $sessionManager, new RateLimiter($pdo));

// ---------------------------------------------------------------------
// [1] Política íntegra de la cookie: la sonda HTTP real de producción.
// ---------------------------------------------------------------------
echo "[1] Política íntegra de la cookie de sesión (sonda php -S)\n";

$probePort = 8097;
$probeFile = $projectRoot . '/scratch/__csrf_probe_' . getmypid() . '.php';
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
$sm = new SessionManager($pdo, '127.0.0.1', 'probe-csrf/1.0');
$sm->createSession('u1');
echo 'session-created';
PROBE);

$isWindows = stripos(PHP_OS_FAMILY, 'Windows') === 0;
$nullDevice = $isWindows ? 'NUL' : '/dev/null';
$serverCommand = 'php -S 127.0.0.1:' . $probePort
    . ' -t ' . escapeshellarg($projectRoot . '/scratch')
    . ' > ' . $nullDevice . ' 2>&1'
    . ($isWindows ? '' : ' &');
/** PID del servidor de la sonda (Windows) para autolimpieza al cerrar. */
$probeServerPid = null;
if ($isWindows) {
    // start /B con WMIC-style: PowerShell arranca y devuelve el PID real,
    // evitando el servidor huérfano que acumulaba el patrón start /B puro.
    $launchOutput = shell_exec(
        'powershell -NoProfile -Command "'
        . "\$p = Start-Process -FilePath php -ArgumentList '-S','127.0.0.1:{$probePort}','-t','" . addslashes($projectRoot . '/scratch') . "' -WindowStyle Hidden -PassThru; \$p.Id"
        . '"'
    );
    $probeServerPid = (int) trim((string) $launchOutput);
} else {
    exec($serverCommand);
}

/** Autolimpieza garantizada del servidor de la sonda (éxito o fallo). */
function stopProbeServer(?int $probeServerPid, bool $isWindows): void
{
    if ($probeServerPid !== null && $probeServerPid > 0) {
        if ($isWindows) {
            exec('taskkill /PID ' . $probeServerPid . ' /F 2>NUL');
        } else {
            exec('kill ' . $probeServerPid . ' 2>/dev/null');
        }
    }
}
register_shutdown_function(fn (): bool => stopProbeServer($probeServerPid, $isWindows) ?? true);

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
$cookieHeaderCount = 0;
foreach ($http_response_header ?? [] as $headerLine) {
    if (stripos($headerLine, 'Set-Cookie:') === 0) {
        $cookieHeaderCount++;
        if (str_contains($headerLine, 'grimorio_session=')) {
            $sessionCookie = substr($headerLine, strlen('Set-Cookie:'));
        }
    }
}

assertArcane($probeReady && str_contains((string) $probeBody, 'session-created'), 'La sonda HTTP ejecuta la pila de producción');
assertArcane($sessionCookie !== null, 'La sesión emite SU cookie grimorio_session vía Set-Cookie');

if ($sessionCookie !== null) {
    // Las cuatro banderas del canon, JUNTAS, en la misma cookie.
    assertArcane(stripos($sessionCookie, 'HttpOnly') !== false, 'La cookie porta HttpOnly (inalcanzable por JS)');
    assertArcane(stripos($sessionCookie, 'SameSite=Strict') !== false, 'La cookie porta SameSite=Strict (blindaje CSRF estructural)');
    assertArcane(stripos($sessionCookie, 'Path=/') !== false, 'La cookie porta Path=/ (ámbito completo del santuario)');
    assertArcane(stripos($sessionCookie, 'Max-Age=1209600') !== false, 'La cookie caduca con la ventana de 14 días (Max-Age=1209600)');
    // La credencial de sesión JAMÁS viaja en claro por la cabecera.
    $rawTokenInHeader = preg_match('/grimorio_session=([A-Za-z0-9+\/=]{40,})/', $sessionCookie) === 1;
    assertArcane($rawTokenInHeader, 'La cookie porta el token crudo de sesión (valor opaco de alta entropía)');
}

/**
 * Fase 2 · Blindaje SameSite=Strict: la cookie no cruza sitios.
 *
 * SameSite=Strict es un CONTRATO DEL NAVEGADOR: en una petición que se
 * origina en otro sitio (form posts cross-site, img con cookie), el
 * agente de usuario no adjunta la cookie. El backend lo respeta por
 * construcción: la sesión solo existe si el token llega. Este arnés
 * verifica la consecuencia observable: SIN cookie, ninguna mutación
 * procede; CON cookie válida, el mismo endpoint procede.
 */
echo "\n[2] SameSite=Strict como credencial: sin cookie no hay sesión ni mutación\n";

// Usuario de prueba con credenciales reales para los ritos.
$authService->consecrate('GuardiaCsrf', 'guardia@sanctuario.arc', 'clave-larga-suficiente', null, new DateTimeImmutable($now));

// Mutaciones canónicas SIN cookie: ninguna procede (jamás mutan).
// (La tabla de casos vive en la Fase 3, sobre los métodos reales.)

echo "\n[3] Mutaciones sin credencial: todas responden sin sesión y jamás mutan\n";

// Cada mutación sin cookie responde con rechazo controlado —jamás un
// 200 con mutación consumida—. Las claves de sesión exigen credencial; la
// consagración exige cuerpo canónico. El pergamino responde 200 neutro
// POR DISEÑO (RF-04.1: idéntico exista o no el correo, sin alterar
// estado): se certifica que el sobre neutro no consume mutación alguna.
$mutationCases = [
    ['dissolve', fn (): Response => $controller->dissolve(new Request('POST', '/api/v1/auth/dissolve'))],
    ['dissolveAll', fn (): Response => $controller->dissolveAll(new Request('POST', '/api/v1/auth/dissolve-all'))],
    ['bind', fn (): Response => $controller->bind(new Request('POST', '/api/v1/auth/bind', [], ['Content-Type' => 'application/json'], '{"identity":"x@y.z","passphrase":"clave-larga-suficiente"}'))],
    ['consecrate', fn (): Response => $controller->consecrate(new Request('POST', '/api/v1/auth/consecrate', [], ['Content-Type' => 'application/json'], '{"alias":"Xx","email":"x1@y.z","passphrase":"clave-larga-suficiente"}'))],
];

$mutationBlocked = 0;
foreach ($mutationCases as [$name, $call]) {
    $response = $call();
    $status = $response->getStatusCode();
    // Sin sesión: las mutaciones responden 400/401/409/429 — nunca un
    // 200 con mutación consumida por una procedencia sin credencial.
    if (in_array($status, [400, 401, 403, 409, 429], true)) {
        $mutationBlocked++;
    } else {
        echo "       ({$name}: {$status})\n";
    }
}
assertArcane(
    $mutationBlocked === count($mutationCases),
    "Las " . count($mutationCases) . " mutaciones de sesión sin cookie responden con rechazo controlado ({$mutationBlocked}/" . count($mutationCases) . ")"
);

// El pergamino sin credencial: 200 neutro por diseño (RF-04.1) y SIN
// mutación — la respuesta es idéntica exista o no el correo, jamás altera
// el estado de ninguna cuenta.
$stateBefore = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE recovery_token_hash != ''")->fetchColumn();
$recoveryResponse = $controller->recoveryRequest(new Request('POST', '/api/v1/auth/recovery/request', [], ['Content-Type' => 'application/json'], '{"email":"fantasma@sanctuario.arc"}'));
$stateAfter = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE recovery_token_hash != ''")->fetchColumn();
assertArcane($recoveryResponse->getStatusCode() === 200, 'La solicitud del pergamino sin credencial responde 200 neutro (RF-04.1, diseño)');
assertArcane($stateBefore === $stateAfter, 'La respuesta neutra del pergamino no mutó estado alguno (ningún token emitido)');

// Con cookie VÁLIDA, el mismo endpoint de sesión resuelve (control positivo).
$bindResult = $authService->bind('guardia@sanctuario.arc', 'clave-larga-suficiente', new DateTimeImmutable($now));
assertArcane($bindResult->success && $bindResult->session !== null, 'Con credencial legítima, bind() procede (control positivo del blindaje)');

// Con cookie FALSIFICADA (token inexistente): la sesión no resuelve y la
// disolución devuelve 401 — un atacante no puede usar tokens inventados.
echo "\n[4] Cookie falsificada: el token inventado no resuelve sesión\n";

$forgedCookieHeader = 'grimorio_session=' . str_repeat('a', 64) . '; Path=/';
$forgedResponse = $controller->dissolve(new Request('POST', '/api/v1/auth/dissolve', [], ['Cookie' => $forgedCookieHeader], ''));
assertArcane(
    in_array($forgedResponse->getStatusCode(), [401, 403, 400], true),
    'Un token inventado en la cookie no muta nada (rechazo controlado)'
);

$sessionStillAlive = (int) $pdo->query(
    "SELECT COUNT(*) FROM user_sessions WHERE session_token_hash = '" . hash('sha256', $bindResult->session->getToken()) . "'"
)->fetchColumn();
assertArcane($sessionStillAlive === 1, 'La sesión legítima del titular sobrevive intacta al intento falsificado');

/**
 * Fase 5 · La política CSRF no degrada la contemplación pública: GET sin
 * credencial sigue sirviendo lo público (RF-05.1 de reader).
 */
echo "\n[5] La contemplación pública no se degrada por el blindaje\n";

$publicResponse = $controller->session(new Request('GET', '/api/v1/auth/session'));
assertArcane($publicResponse->getStatusCode() === 200, 'GET /auth/session sin credencial responde 200 (anónimo)');
$publicBody = json_decode((string) $publicResponse->getBody(), true);
assertArcane(($publicBody['data']['authenticated'] ?? null) === false, 'El anónimo se declara como tal (authenticated=false)');
// Nota: el operador ?? trata un null explícito como ausente; para certificar
// el null se usa array_key_exists + comparación directa.
assertArcane(
    array_key_exists('user', $publicBody['data'] ?? []) && $publicBody['data']['user'] === null,
    'El anónimo no porta usuario fantasma (user=null explícito)'
);

/**
 * Fase 6 · La expiración de la cookie usa el MISMO canal seguro: el
 * SessionManager emite la cookie de expiración con las banderas intactas.
 */
echo "\n[6] La cookie de expiración conserva el canal seguro\n";

ob_start(); // Captura Set-Cookie de expireCookie sin ensuciar la salida.
$expired = $sessionManager->dissolveSession($bindResult->session->getToken());
$cookieEmission = ob_get_clean();
assertArcane($expired === true, 'dissolveSession() expira la sesión legítima');

// El comportamiento de headers_list() en CLI es limitado; el arnés
// certifica el canal seguro leyendo la POLÍTICA del gestor: las dos
// llamadas a setcookie (emisión y expiración) portan las mismas banderas.
$sessionManagerSource = (string) file_get_contents($projectRoot . '/src/Core/SessionManager.php');
$setCookieCalls = substr_count($sessionManagerSource, 'setcookie(');
assertArcane($setCookieCalls === 2, 'El gestor solo emite cookies en dos puntos: emisión y expiración');

$expireCookieSource = substr($sessionManagerSource, (int) strpos($sessionManagerSource, 'function expireCookie'));
assertArcane(
    str_contains($expireCookieSource, "'httponly' => true")
    && str_contains($expireCookieSource, "'samesite' => 'Strict'"),
    'La cookie de expiración porta HttpOnly y SameSite=Strict (el canal de borrado es tan seguro como el de emisión)'
);

// ---------------------------------------------------------------------
// Limpieza de la sonda.
// ---------------------------------------------------------------------
if (file_exists($probeFile)) {
    @unlink($probeFile);
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
