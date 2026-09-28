<?php

/**
 * test_spec15_local.php — Arnés local aislado de SPEC-15 (Tarea 1.1).
 *
 * Estrategia TDD: este arnés se escribe ANTES de implementar las Fases 2
 * y 3 de TASKS-15. Fase roja esperada: los asertos marcados [SPEC-15]
 * FALLAN contra la conducta insegura actual del código:
 *   - Request::getClientIp() confía en el primer salto de X-Forwarded-For.
 *   - AuthService::dissolveAll() y renounceAccount() no expiran la cookie
 *     portadora (AuthController tampoco la emite expiratoria).
 *   - renounceAccount() no purga los borradores draft (RF-09.4).
 *   - renounceAccount() colisiona con users.alias UNIQUE en la 2.ª renuncia
 *     de otra cuenta (RF-09.3).
 *
 * Fuentes normativas: SPEC-15 RF-03/RF-04; SPEC-03 (enmiendas) RF-09.3/09.4;
 * PLAN-15 §3.1/§3.4/§6.1/§6.2.
 *
 * Seguridad del arnés (PLAN-15 §6.2):
 *   - Sandbox SQLite efímera en sys_get_temp_dir() con PID en el nombre.
 *   - Sonda HTTP local (php -S) en PUERTO LIBRE dinámico, con PID real
 *     capturado (PowerShell -PassThru en Windows) y register_shutdown_function
 *     que garantiza la limpieza incluso ante fallo o abort.
 *   - Sin llamadas a producción, sin credenciales reales (RNF-02: el token
 *     crudo jamás se imprime; solo atributos de cabecera).
 *   - La cookie se observa por HTTP real (cabecera Set-Cookie de la sonda);
 *     headers_list() bajo CLI está SIEMPRE vacío (PLAN-15 §9.6).
 *
 * Ejecución: php scratch/test_spec15_local.php  (exit 0 = verde;
 * en Fase Roja exit 1 es el resultado ESPERADO).
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

// Buffer diferido: las llamadas setcookie() de la pila de producción
// deben poder emitir cabeceras sin colisión con output del arnés.
ob_start();

echo "=== Tarea 1.1 (TASKS-15): arnés local aislado — Fase Roja esperada ===\n\n";

require_once $projectRoot . '/public/index.php'; // Autoload nativo del proyecto.

use Grimorio\Controllers\AuthController;
use Grimorio\Core\RateLimiter;
use Grimorio\Core\Request;
use Grimorio\Core\SessionManager;
use Grimorio\Services\AuthService;

// ---------------------------------------------------------------------
// Sandbox: base SQLite efímera con el esquema completo.
// ---------------------------------------------------------------------
$sandboxDb = sys_get_temp_dir() . '/grimorio_spec15_' . getmypid() . '.sqlite';
register_shutdown_function(static function () use ($sandboxDb): void {
    // Segunda barrera: si una excepción aborta el arnés antes de la
    // limpieza ordinaria, el shutdown libera la conexión y borra la sandbox.
    if (isset($GLOBALS['spec15Pdo']) && $GLOBALS['spec15Pdo'] instanceof PDO) {
        $GLOBALS['spec15Pdo'] = null;
        gc_collect_cycles();
    }
    if (file_exists($sandboxDb)) {
        @unlink($sandboxDb);
    }
});

if (file_exists($sandboxDb)) {
    @unlink($sandboxDb);
}

$pdo = new PDO('sqlite:' . $sandboxDb);
$GLOBALS['spec15Pdo'] = $pdo;
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

$now = '2026-09-12T12:00:00Z';
$pdo->exec(
    "INSERT INTO clans (id, slug, name, motto, created_at)
     VALUES ('cln_spec15', 'spec15-lineage', 'Linaje del Arnés 15', 'Ensayo', '{$now}')"
);

// La SAPI CLI no define REMOTE_ADDR: la conexión local del arnés es 127.0.0.1.
if (!isset($_SERVER['REMOTE_ADDR'])) {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

echo "[0] Existencia y cargabilidad de la pila de autenticación\n";

assertArcane(class_exists(Request::class), 'La clase Grimorio\Core\Request existe y el autoload la resuelve');
assertArcane(class_exists(SessionManager::class), 'La clase Grimorio\Core\SessionManager existe');
assertArcane(class_exists(AuthService::class), 'La clase Grimorio\Services\AuthService existe');
assertArcane(class_exists(AuthController::class), 'La clase Grimorio\Controllers\AuthController existe');

// ---------------------------------------------------------------------
// FASE A (SPEC-15 RF-03.1/03.3/03.4): procedencia usada por el limitador.
// ---------------------------------------------------------------------
echo "\n[1] Procedencia: X-Forwarded-For de cliente directo NO es autoridad (RF-03.3)\n";

// 1a. Conexión directa con cabecera falsificada: la procedencia debe ser
// REMOTE_ADDR (contrato del resolvedor de PLAN-15 §3.1, regla 2). Hoy el
// getClientIp() devuelve el primer salto de XFF → FALLA esperada.
$forgedRequest = new Request('POST', '/api/v1/auth/bind', [], [
    'X-Forwarded-For' => '203.0.113.66, 10.9.8.7',
]);
$resolvedIp = $forgedRequest->getClientIp();
assertArcane(
    $resolvedIp === $_SERVER['REMOTE_ADDR'],
    "[SPEC-15] La cabecera falsificada no altera la procedencia (esperado {$_SERVER['REMOTE_ADDR']}, obtenido {$resolvedIp})"
);

// 1b. Cabecera reenviada malformada: jamás determina la procedencia.
$malformedRequest = new Request('POST', '/api/v1/auth/bind', [], [
    'X-Forwarded-For' => 'no-es-una-ip, ip-puerto:8080, ',
]);
$resolvedMalformed = $malformedRequest->getClientIp();
assertArcane(
    $resolvedMalformed === $_SERVER['REMOTE_ADDR'],
    "[SPEC-15] Una cadena malformada no elige procedencia (esperado {$_SERVER['REMOTE_ADDR']}, obtenido {$resolvedMalformed})"
);

// 1c. Con lista de proxies confiables VACÍA (política de Tarea 0.3), NI
// SIQUIERA un par que se haga pasar por proxy mueve la autoridad.
assertArcane(
    (new Request('POST', '/x', [], ['X-Forwarded-For' => '198.51.100.9']))->getClientIp() === $_SERVER['REMOTE_ADDR'],
    '[SPEC-15] Sin proxies confiables declarados, toda cabecera reenviada se ignora'
);

// 1d. REMOTE_ADDR IPv6 válida: se respeta tal cual como procedencia.
$remoteAddrBackup = $_SERVER['REMOTE_ADDR'];
$_SERVER['REMOTE_ADDR'] = '2001:db8::7';
$directV6 = (new Request('GET', '/x', [], []))->getClientIp();
$_SERVER['REMOTE_ADDR'] = $remoteAddrBackup;
assertArcane(
    $directV6 === '2001:db8::7',
    "[SPEC-15] Una REMOTE_ADDR IPv6 válida se respeta como procedencia (obtenido {$directV6})"
);

// ---------------------------------------------------------------------
// FASE 1-M (Tarea 1.2): matriz completa de resolución de procedencia
// (PLAN-15 §6.1; SPEC-15 §8 casos 2–4, §10 pruebas 3–6). El contrato del
// resolvedor es el ratificado en PLAN-15 §3.1:
//   TrustedProxyResolver::resolveClientIp($remoteAddress, $forwardedChain, $trustedProxyAddresses)
// En Fase Roja la clase NO existe: cada caso se reporta FALLA expresando
// el resultado esperado de la Tarea 2.2 (resultados inequívocos por caso,
// criterio «Hecho cuando» de esta tarea).
// ---------------------------------------------------------------------
echo "\n[1-M] Matriz del resolvedor de procedencia (Tarea 1.2, PLAN-15 §6.1)\n";

assertArcane(
    class_exists(\Grimorio\Core\TrustedProxyResolver::class),
    '[SPEC-15] El resolvedor Grimorio\Core\TrustedProxyResolver existe (Tarea 2.2; FALLA hoy: no está implementado)'
);

/**
 * Evaluación segura de un caso de la matriz: si el resolvedor aún no
 * existe (o aborta), devuelve null y el caso se reporta FALLA sin
 * interrumpir el arnés.
 */
function resolveCase(string $remoteAddress, ?string $forwardedChain, array $trustedProxyAddresses): ?string
{
    if (!class_exists(\Grimorio\Core\TrustedProxyResolver::class)) {
        return null;
    }
    try {
        return \Grimorio\Core\TrustedProxyResolver::resolveClientIp($remoteAddress, $forwardedChain, $trustedProxyAddresses);
    } catch (Throwable) {
        return null;
    }
}

$localRemote = $_SERVER['REMOTE_ADDR']; // Par conectado del arnés.

// [M1] IPv4 directa sin cabeceras: el par conectado validado manda.
$ip = resolveCase('192.0.2.10', null, []);
assertArcane($ip === '192.0.2.10', '[M1] IPv4 directa sin cabeceras → 192.0.2.10 (obtenido ' . var_export($ip, true) . ')');

// [M2] IPv6 directa sin cabeceras.
$ip = resolveCase('2001:db8::5', null, []);
assertArcane($ip === '2001:db8::5', '[M2] IPv6 directa sin cabeceras → 2001:db8::5 (obtenido ' . var_export($ip, true) . ')');

// [M3] IPv4 directa con XFF falsificada y lista VACÍA (política Tarea 0.3):
// la cabecera del cliente jamás es autoridad en conexión directa.
$ip = resolveCase('192.0.2.10', '203.0.113.66, 10.9.8.7', []);
assertArcane($ip === '192.0.2.10', '[M3] XFF falsificada con lista vacía → par conectado (obtenido ' . var_export($ip, true) . ')');

// [M4] Par conectado NO incluido en la lista confiable: cabecera ignorada
// aunque la lista no esté vacía.
$ip = resolveCase('192.0.2.10', '203.0.113.66', ['198.51.100.1']);
assertArcane($ip === '192.0.2.10', '[M4] Par fuera de la lista confiable → par conectado (obtenido ' . var_export($ip, true) . ')');

// [M5] Cabeceras vacías, malformadas, con hostname, con puerto, duplicadas
// o con espacios impropios: sin proxies confiables, jamás eligen procedencia.
$malformedChains = [
    '',
    '   ',
    'no-es-una-ip',
    '203.0.113.66:8080',
    'ejemplo.santuario',
    '203.0.113.66, , 198.51.100.4',
    '999.999.999.999',
];
$allSafe = true;
foreach ($malformedChains as $chain) {
    if (resolveCase('192.0.2.10', $chain, []) !== '192.0.2.10') {
        $allSafe = false;
    }
}
assertArcane($allSafe, '[M5] ' . count($malformedChains) . ' cadenas vacías/malformadas con lista vacía → par conectado');

// [M6] Valores inválidos en la LISTA confiable no agregan confianza
// (PLAN-15 §4: tipos inesperados, vacíos o direcciones inválidas no confían).
$ip = resolveCase('192.0.2.10', '203.0.113.66', ['', 'ejemplo.santuario', '999.999.999.999', 12345]);
assertArcane($ip === '192.0.2.10', '[M6] Lista con entradas inválidas no agrega confianza (obtenido ' . var_export($ip, true) . ')');

// [M7] REMOTE_ADDR inválida: resultado controlado y estable ('0.0.0.0',
// el valor de fallback del contrato de almacenamiento existente), jamás
// un valor elegido por el cliente (PLAN-15 §3.1, regla 5).
$ip = resolveCase('no-es-una-ip', '203.0.113.66', []);
assertArcane($ip === '0.0.0.0', '[M7] REMOTE_ADDR inválida → procedencia controlada 0.0.0.0 (obtenido ' . var_export($ip, true) . ')');

// [M8] Par conectado = proxy confiable con cadena acorde: el salto no
// confiable se convierte en procedencia (Tarea 2.2; SOLO con evidencia
// del hosting — hoy la lista ratificada está vacía y este caso norma el
// contrato futuro sin activarlo).
$ip = resolveCase('198.51.100.1', '203.0.113.7', ['198.51.100.1']);
assertArcane($ip === '203.0.113.7', '[M8] Proxy confiable + cadena válida → salto no confiable (obtenido ' . var_export($ip, true) . ')');

// [M9] Cadena con varios saltos: se descartan los confiables desde el
// extremo conectado y se selecciona el primer salto no confiable.
$ip = resolveCase('198.51.100.2', '203.0.113.7, 198.51.100.1', ['198.51.100.2', '198.51.100.1']);
assertArcane($ip === '203.0.113.7', '[M9] Cadena de saltos: primer no confiable desde el extremo conectado (obtenido ' . var_export($ip, true) . ')');

// [M10] Par confiable con cadena MALFORMADA: contrato de error cerrado —
// la cadena se ignora y manda el par conectado validado (conservador).
$ip = resolveCase('198.51.100.1', 'no-es-una-ip', ['198.51.100.1']);
assertArcane($ip === '198.51.100.1', '[M10] Cadena malformada tras proxy confiable → par conectado (obtenido ' . var_export($ip, true) . ')');

// [M11] Suplantación frustrada: una IP falsificada solo sería confiable
// si el PAR conectado fuera realmente ese proxy; un cliente directo cuyo
// par no está en la lista jamás mueve la autoridad a su cabecera.
$ip = resolveCase($localRemote, '203.0.113.66', ['203.0.113.66']);
assertArcane($ip === $localRemote, '[M11] La IP falsificada solo confía si el PAR conectado es ese proxy (obtenido ' . var_export($ip, true) . ')');

// ---------------------------------------------------------------------
// FASE B (SPEC-15 RF-03.1): el limitador usa la procedencia validada.
// ---------------------------------------------------------------------
echo "\n[2] El limitador usa la procedencia validada (RF-03.1)\n";

$sessionManager = new SessionManager($pdo, $_SERVER['REMOTE_ADDR'], 'Arnés SPEC-15/1.0');
$rateLimiter    = new RateLimiter($pdo);
$controller     = new AuthController($pdo, $sessionManager, $rateLimiter);

// Consagración de la cuenta de ensayo (sin credenciales reales).
$consecrateResponse = $controller->consecrate(new Request('POST', '/api/v1/auth/consecrate', [], ['Content-Type' => 'application/json'], (string) json_encode([
    'alias'      => 'ArnésSpec15',
    'email'      => 'arnes15@sanctuario.arc',
    'passphrase' => 'frase-de-paso-del-arnes-15',
])));
assertArcane($consecrateResponse->getStatusCode() === 201, 'La cuenta de ensayo queda consagrada (201)');

// Asedio: 5 fallos desde la conexión local (sin cabeceras falsificadas)
// más el 6.º intento que debe encontrar el umbral congelado.
$rateLimitedSeen = false;
for ($siegeIndex = 0; $siegeIndex < 6; $siegeIndex++) {
    $siegeResponse = $controller->bind(new Request('POST', '/api/v1/auth/bind', [], ['Content-Type' => 'application/json'], (string) json_encode([
        'identity'   => 'arnes15@sanctuario.arc',
        'passphrase' => 'frase-erronea-' . $siegeIndex,
    ])));
    if ($siegeResponse->getStatusCode() === 429) {
        $rateLimitedSeen = true;
        break;
    }
}
assertArcane($rateLimitedSeen, 'Tras 5 fallos locales, la 6.ª petición encuentra el umbral congelado (429)');

// La clave del bloqueo es la REMOTE_ADDR validada, no la falsificada:
// el registro del limitador vive bajo la IP de conexión.
$attemptedRows = (int) $pdo->query(
    "SELECT COUNT(*) FROM login_attempts WHERE ip_address = '{$_SERVER['REMOTE_ADDR']}'"
)->fetchColumn();
assertArcane(
    $attemptedRows >= 5,
    "Los intentos del asedio quedan registrados bajo la procedencia validada ({$_SERVER['REMOTE_ADDR']}: {$attemptedRows} filas)"
);

// Guarda de integración (Tarea 1.2): ninguna fila puede quedar registrada
// bajo la IP falsificada por el cliente — el registro del limitador usa
// exclusivamente la procedencia validada.
$forgedRows = (int) $pdo->query(
    "SELECT COUNT(*) FROM login_attempts WHERE ip_address = '203.0.113.99'"
)->fetchColumn();
assertArcane(
    $forgedRows === 0,
    'Ningún intento queda registrado bajo la IP falsificada por el cliente (203.0.113.99)'
);

// El cliente congelado NO elude el límite falsificando XFF: su petición
// sigue evaluada por la IP de conexión, no por la cabecera falsificada.
$forgedBind = $controller->bind(new Request('POST', '/api/v1/auth/bind', [], [
    'Content-Type'    => 'application/json',
    'X-Forwarded-For' => '203.0.113.99',
], (string) json_encode([
    'identity'   => 'arnes15@sanctuario.arc',
    'passphrase' => 'frase-de-paso-del-arnes-15',
])));
assertArcane(
    $forgedBind->getStatusCode() === 429,
    'Un cliente congelado NO elude el límite falsificando X-Forwarded-For (sigue 429)'
);

// ---------------------------------------------------------------------
// FASE C (SPEC-15 RF-04.1): disolución global caduca la cookie portadora.
// ---------------------------------------------------------------------
echo "\n[3] Disolución global: la respuesta expira la cookie portadora (RF-04.1)\n";

// Nueva cuenta de ensayo para no arrastrar el bloqueo del limitador.
$_SERVER['REMOTE_ADDR'] = '192.0.2.50';
$dissolverManager = new SessionManager($pdo, $_SERVER['REMOTE_ADDR'], 'Arnés SPEC-15/1.0');
$dissolverService = new AuthService($pdo, $dissolverManager);
$dissolver = new AuthController($pdo, $dissolverManager, $rateLimiter);
$dissolver->consecrate(new Request('POST', '/api/v1/auth/consecrate', [], ['Content-Type' => 'application/json'], (string) json_encode([
    'alias'      => 'Disolvente15',
    'email'      => 'disolvente15@sanctuario.arc',
    'passphrase' => 'frase-de-paso-disolvente-15',
])));

// Vínculo por vía de servicio: el token crudo solo existe aquí y viaja
// a la sonda HTTP como credencial de la cookie portadora (sin imprimirlo).
$bindForDissolve = $dissolverService->bind('disolvente15@sanctuario.arc', 'frase-de-paso-disolvente-15', new DateTimeImmutable($now));
assertArcane($bindForDissolve->success && $bindForDissolve->session !== null, 'El titular de la 2.ª cuenta vincula con credenciales correctas');
$rawTokenDissolver = $bindForDissolve->session->getToken();
$dissolverId = (string) $pdo->query("SELECT id FROM users WHERE alias = 'Disolvente15'")->fetchColumn();

// Disolución global vía REST con la cookie portadora.
$dissolveAllResponse = $dissolver->dissolveAll(new Request('POST', '/api/v1/auth/dissolve-all', [], [
    'Cookie' => 'grimorio_session=' . $rawTokenDissolver,
]));
assertArcane($dissolveAllResponse->getStatusCode() === 200, 'dissolve-all responde 200 con la cookie portadora');

// La autoridad es la base: cero sesiones del titular en user_sessions.
$remainingSessions = (int) $pdo->query(
    "SELECT COUNT(*) FROM user_sessions WHERE user_id = '{$dissolverId}'"
)->fetchColumn();
assertArcane($remainingSessions === 0, 'dissolve-all revoca todas las sesiones en base de datos');

// El token revocado ya no autentica peticiones posteriores (RF-04.4).
assertArcane(
    $dissolverManager->resolveSession($rawTokenDissolver, new DateTimeImmutable($now)) === null,
    'El token revocado ya no resuelve ninguna sesión'
);

// ---------------------------------------------------------------------
// FASE D (SPEC-15 RF-04.2 + SPEC-03 RF-09.3/09.4): renuncia completa.
// ---------------------------------------------------------------------
echo "\n[4] Renuncia: cookie expirada, borradores purgados, seudónimo común (RF-04.2, RF-09.3/09.4)\n";

$_SERVER['REMOTE_ADDR'] = '192.0.2.51';
$renouncerManager = new SessionManager($pdo, $_SERVER['REMOTE_ADDR'], 'Arnés SPEC-15/1.0');
$renouncerService = new AuthService($pdo, $renouncerManager);
$renouncer = new AuthController($pdo, $renouncerManager, $rateLimiter);
$renouncer->consecrate(new Request('POST', '/api/v1/auth/consecrate', [], ['Content-Type' => 'application/json'], (string) json_encode([
    'alias'      => 'Renunciante15',
    'email'      => 'renunciante15@sanctuario.arc',
    'passphrase' => 'frase-de-paso-renunciante-15',
])));

// Borrador privado del renunciante (RF-09.4: la renuncia debe purgarlo).
// Columnas y CHECK conforme al DDL canónico de `spells` (magic_school con
// FK real a magic_schools: se siembra la escuela de ensayo).
$renouncerId = (string) $pdo->query("SELECT id FROM users WHERE alias = 'Renunciante15'")->fetchColumn();
$pdo->exec(
    "INSERT INTO magic_schools (slug, name) VALUES ('evocation', 'Evocación de Ensayo')
     ON CONFLICT(slug) DO NOTHING"
);
// Huella matemática de 64 caracteres (CHECK length = 64 del DDL).
$draftFingerprint = 'arnes15chispa' . str_repeat('a', 51);
$draftInsert = $pdo->prepare(
    "INSERT INTO spells
        (id, slug, name, author_id, magic_school, elemental_affinity, casting_time, mana_cost, circle,
         math_fingerprint, clan_id, summary, description, components_verbal, components_somatic, components_material,
         damage, healing, barrier, crowd_control_type, range_type, area_type, duration_type,
         has_verbal, has_somatic, has_material, status, validation_signatures_count, signatures_count,
         is_genesis_sample, created_at, updated_at)
     VALUES
        ('spl_draft_15', 'chispa-de-ensayo-15', 'Chispa de Ensayo', :authorId, 'evocation', 'none', 'action', 5, 1,
         '{$draftFingerprint}', 'cln_spec15', 'Borrador de prueba del arnés', 'Borrador privado del renunciante de ensayo.',
         '', '', '', 0, 0, 0, 'none', 'touch', 'singleTarget', 'instant',
         0, 0, 0, 'draft', 0, 0, 0, :createdAt, :updatedAt)"
);
$draftInsert->execute([':authorId' => $renouncerId, ':createdAt' => $now, ':updatedAt' => $now]);
$draftCountBefore = (int) $pdo->query(
    "SELECT COUNT(*) FROM spells WHERE author_id = '{$renouncerId}' AND status = 'draft'"
)->fetchColumn();
assertArcane($draftCountBefore === 1, 'El borrador privado del renunciante existe antes de la renuncia');

// Vínculo por vía de servicio (token crudo solo en este instante).
$bindForRenounce = $renouncerService->bind('renunciante15@sanctuario.arc', 'frase-de-paso-renunciante-15', new DateTimeImmutable($now));
assertArcane($bindForRenounce->success && $bindForRenounce->session !== null, 'El renunciante vincula (vía de servicio)');
$rawTokenRenouncer = $bindForRenounce->session->getToken();

// Renuncia vía REST con la cookie portadora.
$renounceResponse = $renouncer->renounceAccount(new Request('POST', '/api/v1/auth/renounce', [], [
    'Cookie' => 'grimorio_session=' . $rawTokenRenouncer,
]));
assertArcane($renounceResponse->getStatusCode() === 200, 'La renuncia responde 200 con la cookie portadora');

// La autoridad es la base: anonimización y sesiones revocadas.
$renouncerRow = $pdo->query(
    "SELECT alias, email FROM users WHERE id = '{$renouncerId}'"
)->fetch(PDO::FETCH_ASSOC);
assertArcane(
    is_array($renouncerRow) && str_contains((string) $renouncerRow['alias'], 'Erudito Ancestral'),
    "La cuenta queda anonimizada bajo el seudónimo solemne (alias: {$renouncerRow['alias']})"
);
$renouncerSessions = (int) $pdo->query(
    "SELECT COUNT(*) FROM user_sessions WHERE user_id = '{$renouncerId}'"
)->fetchColumn();
assertArcane($renouncerSessions === 0, 'La renuncia revoca todas las sesiones en base de datos');

// [SPEC-15/RF-09.4] La purga de borradores (enmienda ratificada) FALLA hoy.
$draftCountAfter = (int) $pdo->query(
    "SELECT COUNT(*) FROM spells WHERE author_id = '{$renouncerId}' AND status = 'draft'"
)->fetchColumn();
assertArcane(
    $draftCountAfter === 0,
    '[SPEC-15/RF-09.4] La renuncia purga los borradores draft del renunciante (FALLA hoy: no hay purga)'
);

// El token de la cuenta renunciada ya no resuelve sesión (RF-04.4).
assertArcane(
    $renouncerManager->resolveSession($rawTokenRenouncer, new DateTimeImmutable($now)) === null,
    'El token de la cuenta renunciada ya no resuelve sesión'
);

// ---------------------------------------------------------------------
// FASE E (SPEC-03 RF-09.3): segunda renuncia sin colisión de alias.
// ---------------------------------------------------------------------
echo "\n[5] Segunda renuncia: sin colisión UNIQUE de users.alias (RF-09.3)\n";

// 5a. Doble renuncia del MISMO titular: rechazo controlado sin explosión.
$secondRenounce = $renouncer->renounceAccount(new Request('POST', '/api/v1/auth/renounce', [], [
    'Cookie' => 'grimorio_session=' . $rawTokenRenouncer,
]));
assertArcane(
    in_array($secondRenounce->getStatusCode(), [401, 409], true),
    'Una segunda renuncia del mismo titular es rechazada sin explosión (401/409)'
);

// 5b. DOS cuentas distintas que renuncian: hoy la 2.ª asigna el mismo
// alias fijo «Erudito Ancestral» y users.alias UNIQUE explota → 500.
// La enmienda RF-09.3 exige identidades internas únicas con el mismo
// seudónimo público. Hoy el arnés registra la explosión como FALLA.
$_SERVER['REMOTE_ADDR'] = '192.0.2.52';
$secondRenouncerManager = new SessionManager($pdo, $_SERVER['REMOTE_ADDR'], 'Arnés SPEC-15/1.0');
$secondRenouncerService = new AuthService($pdo, $secondRenouncerManager);
$secondRenouncer = new AuthController($pdo, $secondRenouncerManager, $rateLimiter);
$consecratedOk = true;
try {
    $consecratedOk = $secondRenouncer->consecrate(new Request('POST', '/api/v1/auth/consecrate', [], ['Content-Type' => 'application/json'], (string) json_encode([
        'alias'      => 'SegundoRenunciante',
        'email'      => 'segundo@sanctuario.arc',
        'passphrase' => 'frase-de-paso-segundo-renunciante',
    ])))->getStatusCode() === 201;
} catch (Throwable) {
    $consecratedOk = false;
}
assertArcane($consecratedOk, 'La segunda cuenta de ensayo queda consagrada');

$bindSecond = $secondRenouncerService->bind('segundo@sanctuario.arc', 'frase-de-paso-segundo-renunciante', new DateTimeImmutable($now));
assertArcane($bindSecond->success && $bindSecond->session !== null, 'El segundo renunciante vincula (vía de servicio)');
$rawTokenSecond = $bindSecond->session->getToken();

$secondRenounceThrew = false;
try {
    $secondRenouncer->renounceAccount(new Request('POST', '/api/v1/auth/renounce', [], [
        'Cookie' => 'grimorio_session=' . $rawTokenSecond,
    ]));
} catch (Throwable) {
    // Hoy: PDOException por users.alias UNIQUE («Erudito Ancestral» repetido).
    $secondRenounceThrew = true;
}
assertArcane(
    !$secondRenounceThrew,
    '[SPEC-15/RF-09.3] La segunda renuncia de OTRA cuenta NO explota por colisión UNIQUE de alias (FALLA hoy)'
);

// Y la representación pública de ambas debe ser el seudónimo común.
$anonymousAliases = $pdo->query(
    "SELECT alias FROM users WHERE alias LIKE '%Erudito Ancestral%'"
)->fetchAll(PDO::FETCH_COLUMN);
assertArcane(
    count($anonymousAliases) === 2,
    '[SPEC-15/RF-09.3] Ambas cuentas renunciadas comparten el seudónimo público común («Erudito Ancestral»)'
);

// ---------------------------------------------------------------------
// FASE F (SPEC-15 RF-04.1/04.2, evidencia HTTP real): la sonda local
// comprueba que la respuesta HTTP de revocación viaja SIN cabecera
// Set-Cookie expiratoria HOY (Fase Roja) y la llevará tras las Tareas
// 3.2/3.3. Bajo CLI headers_list() está siempre vacío (PLAN-15 §9.6):
// la evidencia debe ser una cabecera HTTP real observada en la sonda.
// ---------------------------------------------------------------------
echo "\n[6] Evidencia HTTP real: cookies y revocación en sonda local (Tarea 1.3, RF-01/RF-04)\n";

// Puerto libre dinámico: el kernel elige; jamás colisiona (H-2 superado).
$probeSock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$probePort = (int) parse_url((string) stream_socket_get_name($probeSock, false), PHP_URL_PORT);
fclose($probeSock); // Ventana de carrera mínima y asumida (entorno local).

// La sonda NO requiere public/index.php: monta su autoload nativo y la
// pila de producción directamente. Así evita el despacho espurio del
// router bajo php -S -t (ROUTE_NOT_FOUND) y el singleflight de
// Connection::getInstance(), sin tocar ninguna vía del Front Controller.
$probeFile = $projectRoot . '/scratch/__spec15_probe_' . getmypid() . '.php';
file_put_contents($probeFile, <<<'PROBE'
<?php
declare(strict_types=1);
spl_autoload_register(static function (string $className): void {
    if (!str_starts_with($className, 'Grimorio\\')) {
        return;
    }
    $classFile = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($className, strlen('Grimorio\\'))) . '.php';
    if (is_file($classFile)) {
        require_once $classFile;
    }
});
use Grimorio\Controllers\AuthController;
use Grimorio\Core\RateLimiter;
use Grimorio\Core\Request;
use Grimorio\Core\SessionManager;
// Sandbox efímera propia de la sonda (aislada del arnés padre) y
// autolimpieza garantizada incluso si la petición muere a medias.
$probeDb = sys_get_temp_dir() . '/grimorio_spec15_probe_' . getmypid() . '.sqlite';
register_shutdown_function(static function () use ($probeDb): void {
    @unlink($probeDb);
});
$pdo = new PDO('sqlite:' . $probeDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));
$now = '2026-09-12T12:00:00Z';
$pdo->exec("INSERT OR IGNORE INTO clans (id, slug, name, motto, created_at) VALUES ('cln_spec15', 'spec15-lineage', 'Linaje del Arnés 15', 'Ensayo', '{$now}')");
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
// Modo ANTES de emitir: la señal directa de servidor (HTTPS simulado)
// debe estar presente cuando el SessionManager emite la cookie.
$probeMode = $_GET['mode'] ?? 'emit';
if ($probeMode === 'emit-https') {
    // Simulación de la señal DIRECTA de servidor (la variable $_SERVER que
    // rellenaría Apache con HTTPS=on), jamás una cabecera del cliente.
    $_SERVER['HTTPS'] = 'on';
}
$sessionManager = new SessionManager($pdo, '127.0.0.1', 'Sonda SPEC-15/1.0');
$controller = new AuthController($pdo, $sessionManager, new RateLimiter($pdo));
// Consagración idempotente: 201 la primera vez, 409 después (sin efecto).
$controller->consecrate(new Request('POST', '/api/v1/auth/consecrate', [], ['Content-Type' => 'application/json'], (string) json_encode([
    'alias' => 'SondaSpec15', 'email' => 'sonda15@sanctuario.arc', 'passphrase' => 'frase-de-paso-sonda-15',
])));
$authService = new \Grimorio\Services\AuthService($pdo, $sessionManager);
// Vínculo con marca ACTUAL en cada petición: la cookie nace vigente.
$bind = $authService->bind('sonda15@sanctuario.arc', 'frase-de-paso-sonda-15');
$rawToken = $bind->session->getToken();
if ($probeMode === 'verify') {
    // El cliente reenvía SU cookie capturada de la emisión: la resolución
    // usa la pila de producción. Solo se ecoa el veredicto, jamás el token.
    $probeRequest = new Request('GET', '/api/v1/auth/session', [], []);
    $presentedToken = $probeRequest->getCookie('grimorio_session');
    $resolved = $presentedToken !== null && $sessionManager->resolveSession($presentedToken) !== null;
    echo $resolved ? 'session-resolved' : 'session-rejected';
    return;
}
if ($probeMode === 'revoke' || $probeMode === 'renounce') {
    if ($probeMode === 'revoke') {
        // Disolución global: revoca TODAS las sesiones del titular, incluida
        // la del vínculo emitido en la petición emit previa del cliente.
        $response = $controller->dissolveAll(new Request('POST', '/api/v1/auth/dissolve-all', [], [
            'Cookie' => 'grimorio_session=' . $rawToken,
        ]));
    } else {
        // Renuncia: anonimización + revocación total (SPEC-03 RF-09).
        $response = $controller->renounceAccount(new Request('POST', '/api/v1/auth/renounce', [], [
            'Cookie' => 'grimorio_session=' . $rawToken,
        ]));
    }
    http_response_code($response->getStatusCode());
    header('Content-Type: application/json; charset=utf-8');
    echo $response->getBody();
    return;
}
if ($probeMode === 'renounce' || $probeMode === 'renounce-verify') {
    // Flujo autocontenido de renuncia con cuenta DEDICADA y alias único
    // por invocación (la cuenta compartida SondaSpec15 queda muerta tras
    // la primera renuncia: la anonimización destruye su hash de paso).
    // Solo viaja el veredicto; el token jamás abandona el proceso.
    $uniqueSuffix = bin2hex(random_bytes(4));
    $controller->consecrate(new Request('POST', '/api/v1/auth/consecrate', [], ['Content-Type' => 'application/json'], (string) json_encode([
        'alias' => 'SondaRen' . $uniqueSuffix,
        'email' => 'ren' . $uniqueSuffix . '@sanctuario.arc',
        'passphrase' => 'frase-de-paso-sonda-renuncia',
    ])));
    $renounceBind = $authService->bind('ren' . $uniqueSuffix . '@sanctuario.arc', 'frase-de-paso-sonda-renuncia');
    $renounceToken = $renounceBind->session->getToken();
    $renounceResponse = $controller->renounceAccount(new Request('POST', '/api/v1/auth/renounce', [], [
        'Cookie' => 'grimorio_session=' . $renounceToken,
    ]));
    if ($probeMode === 'renounce') {
        http_response_code($renounceResponse->getStatusCode());
        header('Content-Type: application/json; charset=utf-8');
        echo $renounceResponse->getBody();
        return;
    }
    // renounce-verify: tras la renuncia, el MISMO token ya no resuelve.
    // En Fase Roja la 2.ª renuncia explota por users.alias UNIQUE (bug
    // RF-09.3 que este arnés documenta): el fallo de la operación NO es
    // el sujeto de este aserto (la matriz RF-09.3 lo cubre) — el
    // veredicto es si el token sigue resolviendo sesión.
    $stillResolved = true;
    try {
        $stillResolved = $sessionManager->resolveSession($renounceToken) !== null;
    } catch (Throwable) {
        $stillResolved = true; // Excepción = el flujo ni siquiera llega sano.
    }
    echo $stillResolved ? 'session-resolved' : 'session-rejected';
    return;
}
// Modos emit / emit-https / emit-forged: el vínculo ya emitió la
// Set-Cookie vía setcookie (pila de producción, SAPI web real).
echo 'session-created';
PROBE);

$isWindows = stripos(PHP_OS_FAMILY, 'Windows') === 0;
$nullDevice = $isWindows ? 'NUL' : '/dev/null';
/** PID real del servidor de la sonda para autolimpieza garantizada. */
$probeServerPid = 0;
if ($isWindows) {
    $launchOutput = shell_exec(
        'powershell -NoProfile -Command "'
        . "\$p = Start-Process -FilePath php -ArgumentList '-S','127.0.0.1:{$probePort}','-t','" . addslashes($projectRoot . '/scratch') . "' -WindowStyle Hidden -PassThru; \$p.Id"
        . '"'
    );
    $probeServerPid = (int) trim((string) $launchOutput);
} else {
    // Unix: nohup + eco del PID real (sin limpieza por puerto, H-1 superado).
    $launchOutput = shell_exec(
        'nohup php -S 127.0.0.1:' . $probePort . ' -t ' . escapeshellarg($projectRoot . '/scratch')
        . ' > ' . $nullDevice . ' 2>&1 & echo $!'
    );
    $probeServerPid = (int) trim((string) $launchOutput);
}
register_shutdown_function(static function () use ($probeServerPid, $isWindows): void {
    if ($probeServerPid > 0) {
        if ($isWindows) {
            exec('taskkill /PID ' . $probeServerPid . ' /F 2>NUL');
        } else {
            exec('kill ' . $probeServerPid . ' 2>/dev/null');
        }
    }
});

$probeUrl = 'http://127.0.0.1:' . $probePort . '/' . basename($probeFile);
$probeReady = false;
$probeBody = false;
$responseHeaders = [];
for ($attempt = 0; $attempt < 20; $attempt++) {
    $probeContext = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]);
    $probeBody = @file_get_contents($probeUrl . '?mode=emit', false, $probeContext);
    $responseHeaders = $http_response_header ?? [];
    if ($probeBody !== false) {
        $probeReady = true;
        break;
    }
    usleep(200000);
}
$emitSetCookie = null;
foreach ($responseHeaders as $headerLine) {
    if (stripos($headerLine, 'Set-Cookie:') === 0 && str_contains($headerLine, 'grimorio_session=')) {
        $emitSetCookie = substr($headerLine, strlen('Set-Cookie:'));
    }
}
assertArcane($probeReady && str_contains((string) $probeBody, 'session-created'), 'La sonda HTTP local ejecuta la pila de producción');
assertArcane($emitSetCookie !== null, 'El vínculo emite Set-Cookie de sesión con la cookie grimorio_session (evidencia HTTP real)');

// ---------------------------------------------------------------------
// Matriz de cookies (Tarea 1.3, PLAN-15 §6.2): emisión, HTTPS simulado,
// señal falsificada y rechazo del token revocado, siempre por HTTP real.
// ---------------------------------------------------------------------
echo "\n[6.1] Emisión HTTP local: atributos de la cookie (RF-01)\n";

if ($emitSetCookie !== null) {
    assertArcane(str_contains($emitSetCookie, 'grimorio_session='), 'El nombre de la cookie es exactamente grimorio_session');
    assertArcane(stripos($emitSetCookie, 'path=/') !== false, 'La cookie de emisión porta Path=/ (RF-04.3: mismo alcance)');
    assertArcane(stripos($emitSetCookie, 'httponly') !== false, 'La cookie de emisión porta HttpOnly');
    assertArcane(stripos($emitSetCookie, 'samesite=strict') !== false, 'La cookie de emisión porta SameSite=Strict');
    assertArcane(stripos($emitSetCookie, 'max-age=1209600') !== false, 'La cookie de emisión caduca a los 14 días (Max-Age=1209600, SPEC-03)');
    // Sobre HTTP local (sin señal HTTPS) NO debe llevar Secure: es la
    // excepción de desarrollo ratificada (SPEC-15 RF-01.4), jamás confunde
    // con producción (esa se verifica en el modo emit-https).
    assertArcane(stripos($emitSetCookie, '; secure') === false, 'Sobre HTTP local la cookie NO porta Secure (excepción de desarrollo, RF-01.4)');
}

echo "\n[6.2] HTTPS simulado por señal directa de servidor (RF-01.1, RF-02.2)\n";

// El modo emit-https activa $_SERVER['HTTPS']='on' ANTES de emitir: es la
// variable que rellena Apache (señal de servidor, jamás una cabecera del
// cliente). Es la vía que la Tarea 3.1 debe preservar.
$httpsContext = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 3]]);
$httpsBody = @file_get_contents($probeUrl . '?mode=emit-https', false, $httpsContext);
$httpsHeaders = $http_response_header ?? [];
$httpsSetCookie = null;
foreach ($httpsHeaders as $headerLine) {
    if (stripos($headerLine, 'Set-Cookie:') === 0 && str_contains($headerLine, 'grimorio_session=')) {
        $httpsSetCookie = substr($headerLine, strlen('Set-Cookie:'));
    }
}
assertArcane($httpsBody !== false && str_contains((string) $httpsBody, 'session-created'), 'La sonda emite el vínculo con HTTPS simulado');
if ($httpsSetCookie !== null) {
    // [SPEC-15/RF-01.1] HOY el SessionManager SÍ detecta $_SERVER['HTTPS']
    // en emisión, de modo que este aserto ya pasa; Tarea 3.1 debe
    // PRESERVARLO al unificar la política con la expiración.
    assertArcane(stripos($httpsSetCookie, 'secure') !== false, '[SPEC-15/RF-01.1] Con señal directa de servidor, la cookie porta Secure');
    assertArcane(stripos($httpsSetCookie, 'httponly') !== false, 'La cookie HTTPS porta HttpOnly');
    assertArcane(stripos($httpsSetCookie, 'samesite=strict') !== false, 'La cookie HTTPS porta SameSite=Strict');
    assertArcane(stripos($httpsSetCookie, 'path=/') !== false, 'La cookie HTTPS porta Path=/');
}

echo "\n[6.3] Señal HTTPS falsificada por cabecera de cliente (RF-01.3, §8 caso 2)\n";

// Una cabecera controlable por el cliente JAMÁS activa Secure en una
// conexión HTTP local: se envía X-Forwarded-Proto: https sin señal real
// de servidor y la cookie resultante NO debe llevar Secure.
$forgedContext = stream_context_create(['http' => [
    'ignore_errors' => true,
    'timeout' => 3,
    'header' => "X-Forwarded-Proto: https\r\n",
]]);
$forgedBody = @file_get_contents($probeUrl . '?mode=emit', false, $forgedContext);
$forgedHeaders = $http_response_header ?? [];
$forgedSetCookie = null;
foreach ($forgedHeaders as $headerLine) {
    if (stripos($headerLine, 'Set-Cookie:') === 0 && str_contains($headerLine, 'grimorio_session=')) {
        $forgedSetCookie = substr($headerLine, strlen('Set-Cookie:'));
    }
}
assertArcane($forgedBody !== false, 'La sonda procesa la petición con cabecera falsificada');
if ($forgedSetCookie !== null) {
    assertArcane(
        stripos($forgedSetCookie, '; secure') === false,
        '[SPEC-15/RF-01.3] La cabecera X-Forwarded-Proto falsificada NO activa Secure sobre HTTP (§8 caso 2)'
    );
}

echo "\n[6.4] Disolución global: expiración y rechazo del token (RF-04.1, RF-04.4)\n";

// Captura el token de la EMISIÓN desde su cabecera (el navegador lo
// recibiría de la misma vía; el arnés no lo imprime jamás, RNF-02).
$emittedToken = null;
if ($emitSetCookie !== null && preg_match('/grimorio_session=([A-Za-z0-9+\\/=]+)/', $emitSetCookie, $m)) {
    $emittedToken = $m[1];
}
assertArcane(is_string($emittedToken) && $emittedToken !== '', 'El token de la emisión queda capturado de la cabecera (sin imprimirlo)');

// Control positivo ANTES de revocar: el token presentado por el cliente
// resuelve sesión vía la pila de producción.
$verifyContext = stream_context_create(['http' => [
    'ignore_errors' => true,
    'timeout' => 5,
    'header' => "Cookie: grimorio_session=" . $emittedToken . "\r\n",
]]);
$verifyBody = @file_get_contents($probeUrl . '?mode=verify', false, $verifyContext);
assertArcane(
    str_contains((string) $verifyBody, 'session-resolved'),
    'Control positivo: el token vigente autentica una petición posterior (modo verify por HTTP real)'
);

// Disolución global real y observación de la cabecera de respuesta.
$revokeContext = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
$revokeBody = @file_get_contents($probeUrl . '?mode=revoke', false, $revokeContext);
$revokeHeaders = $http_response_header ?? [];
$revokeSetCookie = null;
foreach ($revokeHeaders as $headerLine) {
    if (stripos($headerLine, 'Set-Cookie:') === 0 && str_contains($headerLine, 'grimorio_session=')) {
        $revokeSetCookie = substr($headerLine, strlen('Set-Cookie:'));
    }
}
assertArcane($revokeBody !== false && str_contains((string) $revokeBody, '"success":true'), 'La sonda ejecuta dissolve-all real con la cookie portadora');
// [SPEC-15/RF-04.1] HOY la respuesta NO porta Set-Cookie expiratoria → FALLA.
$expiresCookieSeen = $revokeSetCookie !== null && (
    str_contains($revokeSetCookie, 'Max-Age=0')
    || preg_match('/expires=[A-Za-z]{3}, 0[1-9] [A-Za-z]{3} 19[0-9]{2}/', $revokeSetCookie) === 1
    || preg_match('/expires=[A-Za-z]{3}, [0-2][0-9]-[A-Za-z]{3}-19[0-9]{2}/', $revokeSetCookie) === 1
);
assertArcane(
    $expiresCookieSeen,
    '[SPEC-15/RF-04.1] La respuesta de dissolve-all porta Set-Cookie expiratorio de grimorio_session (FALLA hoy: cabecera ausente)'
);

// [SPEC-15/RF-04.3] La expiración debe conservar el alcance de la emisión.
if ($revokeSetCookie !== null) {
    assertArcane(stripos($revokeSetCookie, 'path=/') !== false, 'La cookie de expiración porta el mismo Path=/ que la emisión (RF-04.3)');
    assertArcane(stripos($revokeSetCookie, 'httponly') !== false, 'La cookie de expiración porta HttpOnly (mismo canal seguro, RF-04.3)');
    assertArcane(stripos($revokeSetCookie, 'samesite=strict') !== false, 'La cookie de expiración porta SameSite=Strict (mismo canal seguro, RF-04.3)');
}

// [SPEC-15/RF-04.4] Rechazo HTTP real: el token revocado, reenviado por el
// cliente, ya no autentica aunque el navegador (aquí el arnés) lo conserve.
$staleContext = stream_context_create(['http' => [
    'ignore_errors' => true,
    'timeout' => 5,
    'header' => "Cookie: grimorio_session=" . $emittedToken . "\r\n",
]]);
$staleBody = @file_get_contents($probeUrl . '?mode=verify', false, $staleContext);
assertArcane(
    str_contains((string) $staleBody, 'session-rejected'),
    '[SPEC-15/RF-04.4] El token revocado NO autentica una petición posterior (§9 criterio 8, por HTTP real)'
);

echo "\n[6.5] Renuncia: expiración y rechazo del token (RF-04.2, RF-04.4, §8 caso 6)\n";

// La renuncia (SPEC-03 RF-09) usa la misma sonda: el modo 'renounce'
// vincula y renuncia en una petición real. La cuenta de ensayo queda
// anonimizada (el sandbox de la sonda es efímero y perece con el servidor).
$renounceContext = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 8]]);
$renounceBody = @file_get_contents($probeUrl . '?mode=renounce', false, $renounceContext);
$renounceHeaders = $http_response_header ?? [];
$renounceSetCookie = null;
foreach ($renounceHeaders as $headerLine) {
    if (stripos($headerLine, 'Set-Cookie:') === 0 && str_contains($headerLine, 'grimorio_session=')) {
        $renounceSetCookie = substr($headerLine, strlen('Set-Cookie:'));
    }
}
assertArcane($renounceBody !== false && str_contains((string) $renounceBody, '"success":true'), 'La sonda ejecuta la renuncia real con la cookie portadora');
// [SPEC-15/RF-04.2] HOY la respuesta NO porta Set-Cookie expiratoria → FALLA.
$renounceExpiresSeen = $renounceSetCookie !== null && (
    str_contains($renounceSetCookie, 'Max-Age=0')
    || preg_match('/expires=[A-Za-z]{3}, 0[1-9] [A-Za-z]{3} 19[0-9]{2}/', $renounceSetCookie) === 1
    || preg_match('/expires=[A-Za-z]{3}, [0-2][0-9]-[A-Za-z]{3}-19[0-9]{2}/', $renounceSetCookie) === 1
);
assertArcane(
    $renounceExpiresSeen,
    '[SPEC-15/RF-04.2] La respuesta de renuncia porta Set-Cookie expiratorio de grimorio_session (FALLA hoy: cabecera ausente)'
);

// Captura del token renunciado desde la emisión previa del mismo flujo:
// el modo renounce re-vincula, así que capturamos SU token desde una
// petición emit inmediatamente posterior... No: la sonda renuncia con un
// token interno. Para el rechazo, reutilizamos el modo verify con un
// token recién emitido y revocado por la renuncia en la propia sonda:
// el modo 'renounce-verify' crea vínculo, lo renuncia y verifica en
// una sola petición, ecoando solo el veredicto.
$renounceVerifyContext = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 8]]);
$renounceVerifyBody = @file_get_contents($probeUrl . '?mode=renounce-verify', false, $renounceVerifyContext);
assertArcane(
    str_contains((string) $renounceVerifyBody, 'session-rejected'),
    '[SPEC-15/RF-04.4] El token de la cuenta renunciada NO autentica peticiones posteriores (por HTTP real)'
);

// Limpieza del fichero de la sonda (el servidor lo mata el shutdown).
if (file_exists($probeFile)) {
    @unlink($probeFile);
}

// ---------------------------------------------------------------------
// Limpieza ordinaria del sandbox.
// ---------------------------------------------------------------------
$pdo = null;
$GLOBALS['spec15Pdo'] = null;
gc_collect_cycles();
if (file_exists($sandboxDb)) {
    @unlink($sandboxDb);
}

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
ob_end_flush();
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
echo "NOTA FASE ROJA: los asertos marcados [SPEC-15] DEBEN fallar ahora y\npasar tras implementar las Tareas 2.1–2.2 y 3.2–3.3 de TASKS-15.\n";
exit($assertsFailed === 0 ? 0 : 1);
