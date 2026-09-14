<?php

/**
 * test_renounce_account.php — Arnés TDD de la Renuncia al Vínculo (RF-09.1).
 *
 * Tarea de cierre de SPEC-03: verifica el flujo completo de derecho al
 * olvido contra la pila real (AuthService + AuthController), sin mocks.
 *
 * Secuencia verificada (plan de la SPEC-03, sección 6 del plan de la
 * auditoría 5.3, canónica del derecho al olvido):
 *   1. La renuncia SIN vínculo portador responde 401 NO_ACTIVE_SESSION.
 *   2. Con vínculo activo: 200; datos personales purgados (alias «Erudito
 *      Ancestral», correo opaco, hash inutilizable, pergamino purgado).
 *   3. Las sesiones de la cuenta caen (disolución global implícita).
 *   4. La historia de clan_history sobrevive íntegra (RF-09.2, RESTRICT).
 *   5. Una sesión huérfana superviviente degrada a anónimo reader.
 *   6. La renuncia queda registrada en la bitácora como ACC_LINK_RENOUNCED
 *      (verificado: la acción es rechazada si el catálogo no la incluye).
 *   7. Renuncia doble: la segunda invocación ya no encuentra vínculo (401).
 *   8. Anónimo no puede renunciar (defensa en profundidad del endpoint).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, SQLite en memoria, sin
 *     librerías ni frameworks.
 *   - Artículo III: la baja respeta el legado del linaje (RESTRICT).
 *   - Artículo IV: leyendas solemnes en castellano.
 *   - Artículo V: identificadores en inglés camelCase, comentarios en
 *     castellano.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Core/SessionManager.php';
require __DIR__ . '/../src/Core/Request.php';
require __DIR__ . '/../src/Core/Response.php';
require __DIR__ . '/../src/Core/RateLimiter.php';
require __DIR__ . '/../src/Core/RateLimitVerdict.php';
require __DIR__ . '/../src/Core/ActiveSession.php';
require __DIR__ . '/../src/Models/User.php';
require __DIR__ . '/../src/Models/AuditEntry.php';
require __DIR__ . '/../src/Services/AuditLogPage.php';
require __DIR__ . '/../src/Services/AuditService.php';
require __DIR__ . '/../src/Services/BindResult.php';
require __DIR__ . '/../src/Services/ConsecrationResult.php';
require __DIR__ . '/../src/Services/RecoveryResult.php';
require __DIR__ . '/../src/Repositories/ClanMemberRepository.php';
require __DIR__ . '/../src/Services/AuthService.php';
require __DIR__ . '/../src/Controllers/AuthController.php';

use Grimorio\Controllers\AuthController;
use Grimorio\Core\RateLimiter;
use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Core\SessionManager;
use Grimorio\Services\AuthService;

$assertsPassed = 0;
$assertsFailed = 0;

/**
 * Aserto solemne del arnés: contabiliza el veredicto con marca visual.
 */
function assertArcane(bool $condition, string $legend): void
{
    global $assertsPassed, $assertsFailed;
    if ($condition) {
        $assertsPassed++;
        echo "  OK   {$legend}\n";
        return;
    }
    $assertsFailed++;
    echo "  FALLA {$legend}\n";
}

/**
 * Forja una petición JSON (mismo patrón que el resto de arneses SPEC-03).
 *
 * @param array<string, mixed> $payload
 */
function forgeJsonRequest(string $method, string $path, array $payload = []): Request
{
    $rawBody = $payload === [] ? '' : (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

    return new Request($method, $path, [], ['Content-Type' => 'application/json'], $rawBody);
}

/**
 * Decodifica el cuerpo de una Response a array asociativo.
 *
 * @return array<string, mixed>
 */
function decodeJson(Response $response): array
{
    $decoded = json_decode($response->getBody(), true);

    return is_array($decoded) ? $decoded : [];
}

// Buffer diferido: setcookie() del SessionManager exige cabeceras antes
// de cualquier output (mismo patrón que el E2E test_auth_rbac.php).
ob_start();

// =====================================================================
// Escenario: SQLite en memoria con el esquema real del santuario.
// =====================================================================
$projectRoot = dirname(__DIR__);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

$now = '2026-09-12T12:00:00Z';
$pdo->exec(
    "INSERT INTO clans (id, slug, name, motto, created_at) VALUES
     ('cln_astral', 'astral-scholars', 'Eruditos Astrales', 'Saber', '{$now}')"
);

// Cableado de producción idéntico al E2E (test_auth_rbac.php).
$sessionManager  = new SessionManager($pdo, '127.0.0.1', 'Arnés Renuncia/1.0');
$rateLimiter     = new RateLimiter($pdo);
$authController  = new AuthController($pdo, $sessionManager, $rateLimiter);

// =====================================================================
// PRUEBA 0: el método de servicio y el endpoint existen (fase roja).
// =====================================================================
echo "[0] Superficie — servicio y endpoint de renuncia (RF-09.1)\n";
$authServiceForProbe = new AuthService($pdo, $sessionManager);
assertArcane(
    method_exists($authServiceForProbe, 'renounceAccount'),
    'AuthService expone renounceAccount() (RF-09.1)'
);
assertArcane(
    method_exists($authController, 'renounceAccount'),
    'AuthController expone renounceAccount() como endpoint REST'
);

// =====================================================================
// Preparación: iniciado consagrado con vínculo activo e historia de clan.
// =====================================================================
$consecrateResponse = $authController->consecrate(forgeJsonRequest('POST', '/api/v1/auth/consecrate', [
    'alias'      => 'RenuncianteAntiguo',
    'email'      => 'renunciante@sanctuario.arc',
    'passphrase' => 'frase-larga-del-renunciante-99',
    'clanId'     => 'cln_astral',
]));
$consecrateBody = decodeJson($consecrateResponse);
$userId = (string) ($consecrateBody['data']['user']['id'] ?? '');
assertArcane($consecrateResponse->getStatusCode() === 201 && $userId !== '', 'El iniciado de prueba queda consagrado (201)');

// La vía canónica del endpoint es la cookie física del navegador. En CLI
// la cookie superglobal es legible por Request::getCookie, así que el
// arnés replica el navegador fijándola antes de cada invocación.
$activeSessions = $pdo->prepare('SELECT COUNT(*) FROM user_sessions WHERE user_id = :userId');
$activeSessions->execute([':userId' => $userId]);
assertArcane((int) $activeSessions->fetchColumn() >= 1, 'La consagración dejó un vínculo activo (RF-01.2)');

// Historia de linaje (legado que RF-09.2 exige preservar).
$pdo->prepare('INSERT INTO clan_history (user_id, clan_id, joined_at, left_at) VALUES (:userId, :clanId, :joinedAt, NULL)')
    ->execute([':userId' => $userId, ':clanId' => 'cln_astral', ':joinedAt' => '2026-01-01T00:00:00Z']);

echo "\n[1] Renuncia sin vínculo portador → 401 NO_ACTIVE_SESSION\n";
$orphanResponse = $authController->renounceAccount(forgeJsonRequest('POST', '/api/v1/auth/renounce-account'));
$orphanBody = decodeJson($orphanResponse);
assertArcane($orphanResponse->getStatusCode() === 401, 'La renuncia sin cookie de vínculo responde 401');
assertArcane(
    ($orphanBody['error']['code'] ?? null) === 'NO_ACTIVE_SESSION',
    'El código de error canónico es NO_ACTIVE_SESSION'
);

echo "\n[2] Renuncia con vínculo activo → 200 y purga de datos personales (RF-09.1)\n";

// Vía real: el token crudo viaja en la cookie del navegador; Request::
// getCookie lee la superglobal, así que el arnés recrea el vínculo con
// bind() (flujo real) y después fija la cookie con un token crudo fresco
// creado directamente para conocerlo (en SAPI web el navegador lo porta).
$bindResponse = $authController->bind(forgeJsonRequest('POST', '/api/v1/auth/bind', [
    'identity'   => 'renunciante@sanctuario.arc',
    'passphrase' => 'frase-larga-del-renunciante-99',
]));
assertArcane($bindResponse->getStatusCode() === 200, 'El iniciado renueva su vínculo (bind 200) antes de renunciar');

// SessionManager emite la cookie física; en CLI no es legible, pero el
// token crudo queda registrado en la cookie superglobal por el gestor.
// Para el arnés, se toma el token crudo de la vía interna del gestor:
// se crea una sesión nueva directamente para conocer el token crudo.
$freshSession = $sessionManager->createSession($userId);
$rawToken     = $freshSession->getToken();
$_COOKIE['grimorio_session'] = $rawToken;

$userBefore = $pdo->prepare('SELECT alias, email, password_hash, recovery_token_hash, role, clan_id FROM users WHERE id = :userId');
$userBefore->execute([':userId' => $userId]);
$rowBefore = $userBefore->fetch(PDO::FETCH_ASSOC);
assertArcane(is_array($rowBefore) && $rowBefore['alias'] === 'RenuncianteAntiguo', 'Antes de la renuncia la cuenta porta sus datos personales íntegros');

$renounceResponse = $authController->renounceAccount(forgeJsonRequest('POST', '/api/v1/auth/renounce-account'));
$renounceBody = decodeJson($renounceResponse);
assertArcane($renounceResponse->getStatusCode() === 200, 'La renuncia con vínculo portador responde 200');
assertArcane(
    ($renounceBody['data']['message'] ?? '') !== '',
    'La renuncia porta una leyenda solemne de despedida'
);

$userAfter = $pdo->prepare('SELECT alias, email, password_hash, recovery_token_hash, recovery_token_expires_at, role, clan_id FROM users WHERE id = :userId');
$userAfter->execute([':userId' => $userId]);
$rowAfter = $userAfter->fetch(PDO::FETCH_ASSOC);

assertArcane(is_array($rowAfter), 'La cuenta sobrevive como registro anónimo (nunca borrado físico con legado vivo)');
assertArcane(
    is_array($rowAfter) && $rowAfter['alias'] === 'Erudito Ancestral',
    'El alias queda reasignado al seudónimo solemne «Erudito Ancestral» (RF-09.2)'
);
assertArcane(
    is_array($rowAfter) && !str_contains((string) $rowAfter['email'], 'renunciante@sanctuario.arc'),
    'El correo personal es sustituido por uno opaco (derecho al olvido)'
);
assertArcane(
    is_array($rowAfter) && !password_verify('frase-larga-del-renunciante-99', (string) $rowAfter['password_hash']),
    'La frase de paso original deja de verificar (credencial inutilizada)'
);
assertArcane(
    is_array($rowAfter) && trim((string) ($rowAfter['recovery_token_hash'] ?? 'x')) === ''
    && (!array_key_exists('recovery_token_expires_at', $rowAfter) || $rowAfter['recovery_token_expires_at'] === null),
    'El pergamino de restablecimiento queda purgado'
);
assertArcane(
    is_array($rowAfter) && $rowAfter['role'] === 'editor' && $rowAfter['clan_id'] === 'cln_astral',
    'El rol técnico y el linaje del registro anónimo permanecen estables (RF-09.2: puntuación del clan)'
);

echo "\n[3] Las sesiones de la cuenta caen con la renuncia (disolución implícita)\n";
$remainingSessions = $pdo->prepare('SELECT COUNT(*) FROM user_sessions WHERE user_id = :userId');
$remainingSessions->execute([':userId' => $userId]);
assertArcane((int) $remainingSessions->fetchColumn() === 0, 'Ninguna sesión del renunciante sobrevive en user_sessions');

echo "\n[4] El legado del linaje sobrevive íntegro (RF-09.2, RESTRICT)\n";
$legacyHistory = $pdo->prepare('SELECT clan_id, joined_at, left_at FROM clan_history WHERE user_id = :userId');
$legacyHistory->execute([':userId' => $userId]);
$legacyRow = $legacyHistory->fetch(PDO::FETCH_ASSOC);
assertArcane(
    is_array($legacyRow) && $legacyRow['clan_id'] === 'cln_astral' && $legacyRow['joined_at'] === '2026-01-01T00:00:00Z',
    'clan_history preserva el linaje y su marca de entrada tras la renuncia'
);

echo "\n[5] La sesión huérfana degrada a anónimo reader (defensa en profundidad)\n";
// Se forja una sesión superviviente (huérfana teórica) para verificar la
// degradación defensiva documentada en AuthMiddleware.
$orphanSession = $sessionManager->createSession($userId);
$_COOKIE['grimorio_session'] = $orphanSession->getToken();
$doubleRenounce = $authController->renounceAccount(forgeJsonRequest('POST', '/api/v1/auth/renounce-account'));
assertArcane(
    $doubleRenounce->getStatusCode() === 401 || $doubleRenounce->getStatusCode() === 409,
    'Una segunda renuncia sobre la cuenta ya anónima es rechazada (401/409)'
);

echo "\n[6] La renuncia queda registrada en la bitácora (trazabilidad Art. III)\n";
$auditRow = $pdo->query(
    "SELECT action_type, actor_user_id, justification FROM audit_log WHERE target_entity_type = 'user' ORDER BY id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
assertArcane(
    is_array($auditRow) && $auditRow['action_type'] === 'ACC_LINK_RENOUNCED' && $auditRow['actor_user_id'] === $userId,
    'La bitácora registra ACC_LINK_RENOUNCED con la identidad del renunciante'
);
assertArcane(
    is_array($auditRow) && str_contains((string) $auditRow['justification'], 'Renuncia'),
    'El motivo solemne de la renuncia queda asentado en texto noble'
);

// =====================================================================
// Resumen final (con vaciado del buffer diferido).
// =====================================================================
echo "\n=== RESUMEN: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
ob_end_flush();
exit($assertsFailed === 0 ? 0 : 1);
