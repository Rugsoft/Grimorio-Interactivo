<?php

declare(strict_types=1);

/**
 * test_passphrase_change.php — Arnés de la FASE 3 del Panel del Adepto
 * (TASKS-12). En esta entrega se certifica la Tarea 3.1:
 * `changePassphraseAuthenticated` (éxito con disolución).
 *
 * Fases ratificadas en esta entrega (plan §6.1):
 *   [1] Éxito: hash cambiado, demás sesiones disueltas, sesión actual
 *       viva, asiento PASSPHRASE_SELF_CHANGED inscrito (RF-04.2/04.3).
 *   [6] La frase de paso JAMÁS aparece en la bitácora ni en el cuerpo
 *       de ningún asiento (RF-04.1, plan §2.6: cuerpo no registrado).
 *
 * Las fases [2]–[5] (fallo ciego, idéntica, idempotente, 401) llegan
 * con las Tareas 3.2–3.4 sobre este mismo arnés.
 *
 * Constitución: Artículo I (PDO nativo, password_hash/verify nativos,
 * BCRYPT coste 12), Artículo III (la disolución de las demás sesiones
 * es efecto inseparable del acto), Artículo V (identificadores en
 * inglés, comentarios en castellano).
 *
 * Uso: php scratch/test_passphrase_change.php
 */

require_once __DIR__ . '/../src/Core/ActiveSession.php';
require_once __DIR__ . '/../src/Core/SessionManager.php';
require_once __DIR__ . '/../src/Services/BindResult.php';
require_once __DIR__ . '/../src/Services/ConsecrationResult.php';
require_once __DIR__ . '/../src/Services/RecoveryResult.php';
require_once __DIR__ . '/../src/Services/AuthService.php';
require_once __DIR__ . '/../src/Models/AuditEntry.php';
require_once __DIR__ . '/../src/Exceptions/PassphraseChangeFailedException.php';
require_once __DIR__ . '/../src/Exceptions/PassphraseIdenticalException.php';
require_once __DIR__ . '/../src/Repositories/UserPanelRepository.php';
require_once __DIR__ . '/../src/Services/AvatarService.php';
require_once __DIR__ . '/../src/Dto/AvatarCatalogDto.php';
require_once __DIR__ . '/../src/Dto/UserPanelDto.php';
require_once __DIR__ . '/../src/Exceptions/AvatarIdenticalException.php';
require_once __DIR__ . '/../src/Core/Response.php';
require_once __DIR__ . '/../src/Core/Request.php';
require_once __DIR__ . '/../src/Models/User.php';
require_once __DIR__ . '/../src/Controllers/UserPanelController.php';

use Grimorio\Services\AuthService;
use Grimorio\Core\SessionManager;
use Grimorio\Core\Request;
use Grimorio\Exceptions\PassphraseChangeFailedException;
use Grimorio\Exceptions\PassphraseIdenticalException;
use Grimorio\Controllers\UserPanelController;
use Grimorio\Repositories\UserPanelRepository;
use Grimorio\Services\AvatarService;
use Grimorio\Models\User;

// Buffer diferido: setcookie() exige emitir cabeceras antes de output
// (patrón de la familia de arneses auth: test_auth_rbac.php).
ob_start();

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

echo "== VERIFICACION TAREA 3.1: changePassphraseAuthenticated — exito con disolucion (SPEC-12, RF-04.2/04.3) ==\n\n";

// --- FASE 0: Superficie y construcción ---
echo "FASE 0: Superficie arcano-sesional y construcción del servicio\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON;');
$pdo->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));

$sessionManager = new SessionManager($pdo, '127.0.0.1', 'Arnés Passphrase/1.0');
$authService = new AuthService($pdo, $sessionManager);

assertCondition(true, 'AuthService y SessionManager construidos sobre PDO nativo');

// Siembra del adepto con su frase de paso REAL hasheada (BCRYPT coste 12).
$originalPassphrase = 'vella sombra del arcoiris';
$newPassphrase = 'grimoire de las tres lunas';
$originalHash = password_hash($originalPassphrase, PASSWORD_BCRYPT, ['cost' => 12]);
$pdo->prepare(
    'INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, avatar, created_at, updated_at)
     VALUES (:id, :alias, :email, :passwordHash, :role, NULL, NULL, NULL, :createdAt, :createdAt)'
)->execute([
    ':id' => 'usr_adepto', ':alias' => 'Heredera de la Llama', ':email' => 'heredera@arcano.arc',
    ':passwordHash' => $originalHash, ':role' => 'editor', ':createdAt' => '2025-01-01T00:00:00Z',
]);

// --- FASE 1: El éxito con disolución (RF-04.2, RF-04.3) ---
echo "\nFASE 1: Cambio consumado — hash cambiado, demás sesiones disueltas, actual conservada, asiento inscrito\n";

// Tres vínculos activos del mismo adepto: la actual y dos ajenas
// (otros dispositivos que la custodia debe vaciar).
$currentSession = $sessionManager->createSession('usr_adepto', new DateTimeImmutable('2025-06-01T10:00:00Z'));
$otherSessionA = $sessionManager->createSession('usr_adepto', new DateTimeImmutable('2025-06-01T10:05:00Z'));
$otherSessionB = $sessionManager->createSession('usr_adepto', new DateTimeImmutable('2025-06-01T10:10:00Z'));
assertCondition(
    $sessionManager->resolveSession($currentSession->getToken(), new DateTimeImmutable('2025-06-01T11:00:00Z')) !== null
        && $sessionManager->resolveSession($otherSessionA->getToken(), new DateTimeImmutable('2025-06-01T11:00:00Z')) !== null
        && $sessionManager->resolveSession($otherSessionB->getToken(), new DateTimeImmutable('2025-06-01T11:00:00Z')) !== null,
    'Preparación: los tres vínculos del adepto viven antes del acto'
);

$changeInstant = new DateTimeImmutable('2025-06-01T12:00:00Z');
$result = $authService->changePassphraseAuthenticated(
    'usr_adepto',
    $currentSession->getId(),
    $originalPassphrase,
    $newPassphrase,
    $newPassphrase,
    $changeInstant,
);

assertCondition($result['verdict'] === 'changed', 'El veredicto del acto consumado es «changed»');
assertCondition(
    is_int($result['othersDissolvedCount'] ?? null) && $result['othersDissolvedCount'] === 2,
    'El recibo anuncia las 2 demás sesiones disueltas (RF-04.2)'
);
assertCondition(($result['currentSessionPreserved'] ?? false) === true, 'El recibo anuncia la sesión actual conservada (RF-04.2)');

// El hash de la fila ya NO verifica la frase antigua y SÍ la nueva.
$rowStatement = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
$rowStatement->execute([':id' => 'usr_adepto']);
$storedHash = (string) $rowStatement->fetchColumn();
assertCondition(!password_verify($originalPassphrase, $storedHash), 'La frase antigua ya no abre la cuenta');
assertCondition(password_verify($newPassphrase, $storedHash), 'La nueva frase de paso gobierna el hash de la fila (hash cambiado)');

// Las demás sesiones cayeron; la actual persiste.
assertCondition(
    $sessionManager->resolveSession($otherSessionA->getToken(), $changeInstant) === null
        && $sessionManager->resolveSession($otherSessionB->getToken(), $changeInstant) === null,
    'Las demás sesiones activas del adepto quedan disueltas (RF-04.2)'
);
assertCondition(
    $sessionManager->resolveSession($currentSession->getToken(), $changeInstant) !== null,
    'La sesión actual persiste sin expulsión (RF-04.2: el dueño está presente)'
);

// El asiento PASSPHRASE_SELF_CHANGED vive en la bitácora, sin la frase.
$auditStatement = $pdo->prepare(
    "SELECT actor_user_id, actor_alias, action_type, target_entity_type, target_entity_id, justification
       FROM audit_log
      WHERE action_type = 'PASSPHRASE_SELF_CHANGED'
      ORDER BY id DESC
      LIMIT 1"
);
$auditStatement->execute();
$auditRow = $auditStatement->fetch(PDO::FETCH_ASSOC) ?: null;
assertCondition($auditRow !== null, 'El asiento PASSPHRASE_SELF_CHANGED vive en la bitácora (RF-04.3)');
assertCondition(
    is_array($auditRow)
        && $auditRow['actor_user_id'] === 'usr_adepto'
        && $auditRow['target_entity_type'] === 'user'
        && $auditRow['target_entity_id'] === 'usr_adepto',
    'El asiento declara actor = sujeto = el propio adepto (plan §5.1)'
);

// --- FASE 6: El silencio sobre la frase (RF-04.1, plan §2.6) ---
echo "\nFASE 6: La frase de paso jamás aparece en bitácora ni cuerpo de asiento\n";

// La justificación es leyenda fija del servicio (Art. IV): nunca narra
// la frase presentada. La Buscamos exacta y por fragmento.
$justification = is_array($auditRow) ? (string) $auditRow['justification'] : '';
assertCondition(
    $justification !== ''
        && !str_contains($justification, $originalPassphrase)
        && !str_contains($justification, $newPassphrase),
    'La justificación del asiento es leyenda fija: jamás narra la frase antigua ni la nueva'
);
$auditDumpStatement = $pdo->prepare('SELECT justification FROM audit_log');
$auditDumpStatement->execute();
$allJustifications = $auditDumpStatement->fetchAll(PDO::FETCH_COLUMN) ?: [];
$auditBlob = implode("\n", array_map('strval', $allJustifications));
assertCondition(
    !str_contains($auditBlob, $originalPassphrase) && !str_contains($auditBlob, $newPassphrase),
    'Ningún asiento de la bitácora contiene la frase de paso antigua ni la nueva (RF-04.1)'
);

// --- FASE 2: El fallo ciego (RF-04.1) ---
echo "\nFASE 2: El fallo ciego — un solo veredicto para tres causas, cuerpos byte a byte identicos\n";

// Las tres causas canónicas: frase actual errónea (con nueva DISTINTA
// de la vigente, para no confundir la causa con la salvedad honesta de
// la FASE 3), nuevas que difieren y solidez insuficiente (7 caracteres,
// por debajo del mínimo de 8).
$blindAttempts = [
    'frase actual erronea'    => static fn (): array => $authService->changePassphraseAuthenticated(
        'usr_adepto', $currentSession->getId(), 'esta frase no es la vigente', 'otra frase nueva y distinta', 'otra frase nueva y distinta', $changeInstant,
    ),
    'nuevas que difieren'     => static fn (): array => $authService->changePassphraseAuthenticated(
        'usr_adepto', $currentSession->getId(), $originalPassphrase, 'nueva frase de prueba', 'nueva frase distinta', $changeInstant,
    ),
    'solidez insuficiente'    => static fn (): array => $authService->changePassphraseAuthenticated(
        'usr_adepto', $currentSession->getId(), $originalPassphrase, 'corta', 'corta', $changeInstant,
    ),
];

$blindPayloads = [];
foreach ($blindAttempts as $causeLabel => $attempt) {
    try {
        $attempt();
        echo "  [FALLA] El intento «{$causeLabel}» debio ser rechazado y no lo fue\n";
        $assertsFailed++;
    } catch (PassphraseChangeFailedException $blindFailure) {
        $blindPayloads[$causeLabel] = json_encode($blindFailure->toPayload());
        echo "  [PASA] El intento «{$causeLabel}» fue rechazado con el veredicto ciego\n";
        $assertsPassed++;
    }
}

assertCondition(
    count($blindPayloads) === 3
        && $blindPayloads['frase actual erronea'] === $blindPayloads['nuevas que difieren']
        && $blindPayloads['nuevas que difieren'] === $blindPayloads['solidez insuficiente'],
    'Los TRES cuerpos de fallo son byte a byte identicos (RF-04.1: sin pistas)'
);
$blindDecoded = json_decode($blindPayloads['frase actual erronea'] ?? '{}', true) ?: [];
assertCondition(
    ($blindDecoded['error']['code'] ?? '') === 'PASSPHRASE_CHANGE_FAILED' && ($blindDecoded['success'] ?? true) === false,
    'El veredicto ciego responde PASSPHRASE_CHANGE_FAILED en el sobre canonico (plan §2.6)'
);
$blindLegend = (string) ($blindDecoded['error']['message'] ?? '');
assertCondition(
    $blindLegend !== ''
        && !str_contains($blindLegend, 'actual')
        && !str_contains($blindLegend, 'coincid')
        && !str_contains($blindLegend, 'caracteres')
        && !str_contains($blindLegend, 'sólida') && !str_contains($blindLegend, 'solida'),
    'La leyenda del fallo ciego no nombra ninguna de las tres causas (aviso sin pistas)'
);

// Los tres fallos son inocuos: el hash queda intacto y la bitácora muda.
$rowStatement->execute([':id' => 'usr_adepto']);
assertCondition(
    (string) $rowStatement->fetchColumn() === $storedHash,
    'Ningún fallo ciego mutó el hash vigente de la fila'
);
$auditCountStatement = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE action_type = 'PASSPHRASE_SELF_CHANGED'");
$auditCountStatement->execute();
assertCondition(
    (int) $auditCountStatement->fetchColumn() === 1,
    'Ningún fallo ciego inscribió asiento alguno (la bitácora sigue con el asiento único de la FASE 1)'
);

// --- FASE 3: La frase idéntica (caso límite 17, RF-04.1) ---
echo "\nFASE 3: La frase identica — aviso noble especifico, sin asiento ni mutacion\n";

// El adepto pretendería «cambiar» su frase a la misma, pero su doble
// entrada se traiciona: la nueva ES la vigente y por tanto la salvedad
// honesta precede al fallo ciego (plan §3.3). Si la doble entrada
// coincidiera Y la actual presentada fuera la vigente, sería el reenvío
// idempotente de la FASE 4 — salida propia de ese caso.
try {
    $authService->changePassphraseAuthenticated(
        'usr_adepto', $currentSession->getId(), $newPassphrase, $newPassphrase, 'esta repetición se traiciona', $changeInstant,
    );
    echo "  [FALLA] El envio de la frase identica debio ser rechazado y no lo fue\n";
    $assertsFailed++;
} catch (PassphraseIdenticalException $identical) {
    $identicalPayload = json_encode($identical->toPayload());
    $identicalDecoded = $identical->toPayload();
    assertCondition(true, 'La frase idéntica fue rechazada con el aviso noble específico');
    assertCondition(
        ($identicalDecoded['error']['code'] ?? '') === 'PASSPHRASE_IDENTICAL' && ($identicalDecoded['success'] ?? true) === false,
        'El rechazo responde PASSPHRASE_IDENTICAL en el sobre canonico (plan §2.6)'
    );
    assertCondition(
        str_contains((string) ($identicalDecoded['error']['message'] ?? ''), 'coincide con la vigente'),
        'La leyenda declara «coincide con la vigente» (RF-04.1, plan §8)'
    );
}

// El rechazo de la idéntica es inocuo: ni hash ni asientos.
$rowStatement->execute([':id' => 'usr_adepto']);
assertCondition(
    (string) $rowStatement->fetchColumn() === $storedHash,
    'El rechazo de la idéntica no mutó el hash vigente (sin mutación, caso límite 17)'
);
$auditCountStatement->execute();
assertCondition(
    (int) $auditCountStatement->fetchColumn() === 1,
    'El rechazo de la idéntica no inscribió asiento alguno'
);

// El fallo ciego NO puede mascarar la idéntica: un tercero que ignora
// la frase actual pero envía la vigente como nueva recibe la salvedad
// honesta — jamás una pista de que su «nueva» es la vigente (plan §3.3:
// quien no conoce la frase vigente no puede fabricar una nueva que la
// alcance, así que la salvedad no revela nada a ese tercero).
try {
    $authService->changePassphraseAuthenticated(
        'usr_adepto', $currentSession->getId(), 'esta frase no es la vigente', $newPassphrase, $newPassphrase, $changeInstant,
    );
    echo "  [FALLA] El envio con nueva identica debio ser rechazado\n";
    $assertsFailed++;
} catch (PassphraseIdenticalException $salvage) {
    assertCondition(
        $salvage->toPayload()['error']['code'] === 'PASSPHRASE_IDENTICAL',
        'La idéntica se comprueba ANTES del fallo ciego: aviso noble incluso con frase actual errónea (plan §3.3)'
    );
}

// --- FASE 4: El reenvío idempotente (caso límite 12, hallazgo 16) ---
echo "\nFASE 4: El reenvio legitimo — recibo idempotente con estampa previa, sin asiento\n";

// El envío legítimo de reenvío: la frase presentada como actual YA ES
// la nueva (ambas coinciden con la vigente) y la doble entrada también.
$auditCountStatement->execute();
$seatsBeforeIdempotent = (int) $auditCountStatement->fetchColumn();
$rowStatement->execute([':id' => 'usr_adepto']);
$hashBeforeIdempotent = (string) $rowStatement->fetchColumn();

$idempotentReceipt = $authService->changePassphraseAuthenticated(
    'usr_adepto', $currentSession->getId(), $newPassphrase, $newPassphrase, $newPassphrase, $changeInstant,
);
assertCondition(
    ($idempotentReceipt['verdict'] ?? '') === 'idempotentReceipt',
    'El reenvío legítimo responde con el veredicto «idempotentReceipt» (hallazgo 16)'
);
assertCondition(
    isset($idempotentReceipt['changedAt']) && is_string($idempotentReceipt['changedAt']) && $idempotentReceipt['changedAt'] !== '',
    'El recibo idempotente porta la estampa del cambio previo (changedAt, plan §2.6)'
);
assertCondition(
    ($idempotentReceipt['changedAt'] ?? null) === '2025-06-01T12:00:00Z',
    'La estampa coincide con el instante del cambio consumado en la FASE 1'
);
$rowStatement->execute([':id' => 'usr_adepto']);
assertCondition(
    (string) $rowStatement->fetchColumn() === $hashBeforeIdempotent,
    'El reenvío idempotente no re-hasheó la fila (el hash previo permanece)'
);
$auditCountStatement->execute();
assertCondition(
    (int) $auditCountStatement->fetchColumn() === $seatsBeforeIdempotent,
    'El reenvío idempotente no inscribió asiento nuevo (sin doble bitácora)'
);

// --- FASE 5: El endpoint del panel y la sesión caducada (RF-01.4, caso límite 2) ---
echo "\nFASE 5: El endpoint POST /api/v1/panel/passphrase — 401 sin mutacion parcial\n";

// El controlador del panel construido sobre el MISMO PDO de la siembra.
$panelRepository = new UserPanelRepository($pdo);
$panelController = new UserPanelController($panelRepository, null, $changeInstant, $authService);

// El portador caducado: una petición SIN usuario inyectado — el
// AuthMiddleware solo viste al portador de un vínculo vivo.
$request = new Request('POST', '/api/v1/panel/passphrase', [], [], json_encode([
    'currentPassphrase' => $newPassphrase,
    'newPassphrase'     => 'una frase renovada',
    'newPassphraseRepeat' => 'una frase renovada',
]));
$response = $panelController->changePassphrase($request);
$body = json_decode($response->getBody(), true) ?: [];
assertCondition($response->getStatusCode() === 401, 'La petición sin vínculo vivo responde 401 (RF-01.4)');
assertCondition(
    ($body['error']['code'] ?? '') === 'UNAUTHENTICATED' && ($body['success'] ?? true) === false,
    'El 401 viaja en el sobre canonico UNAUTHENTICATED (SPEC-03)'
);

// Sin mutación parcial: el hash previo y el único asiento permanecen.
$rowStatement->execute([':id' => 'usr_adepto']);
assertCondition(
    (string) $rowStatement->fetchColumn() === $hashBeforeIdempotent,
    'El 401 no mutó el hash de la fila (sin mutación parcial, caso límite 2)'
);
$auditCountStatement->execute();
assertCondition(
    (int) $auditCountStatement->fetchColumn() === $seatsBeforeIdempotent,
    'El 401 no inscribió asiento alguno'
);

// --- FASE 5b: Las cuatro salidas por la superficie REST real (plan §2.6) ---
echo "\nFASE 5b: Las cuatro salidas por el endpoint, con el cuerpo jamas registrado\n";

$forgeAuthenticatedRequest = static function (array $payload) use ($pdo, $currentSession): Request {
    $request = new Request('POST', '/api/v1/panel/passphrase', [], [], json_encode($payload));
    $request->setUser(new User(
        'usr_adepto', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', null, null,
    ));
    $request->setActiveSessionId($currentSession->getId());

    return $request;
};

// Salida 1 — fallo ciego (400).
$response = $panelController->changePassphrase($forgeAuthenticatedRequest([
    'currentPassphrase' => 'esta frase no es la vigente',
    'newPassphrase'     => 'otra frase nueva y distinta',
    'newPassphraseRepeat' => 'otra frase nueva y distinta',
]));
$body = json_decode($response->getBody(), true) ?: [];
assertCondition(
    $response->getStatusCode() === 400 && ($body['error']['code'] ?? '') === 'PASSPHRASE_CHANGE_FAILED',
    'Salida 1: el fallo ciego responde 400 PASSPHRASE_CHANGE_FAILED por el endpoint'
);

// Salida 2 — idéntica (400).
$response = $panelController->changePassphrase($forgeAuthenticatedRequest([
    'currentPassphrase' => $newPassphrase,
    'newPassphrase'     => $newPassphrase,
    'newPassphraseRepeat' => 'esta repeticion se traiciona',
]));
$body = json_decode($response->getBody(), true) ?: [];
assertCondition(
    $response->getStatusCode() === 400 && ($body['error']['code'] ?? '') === 'PASSPHRASE_IDENTICAL',
    'Salida 2: la idéntica responde 400 PASSPHRASE_IDENTICAL por el endpoint'
);

// Salida 3 — reenvío idempotente (200).
$response = $panelController->changePassphrase($forgeAuthenticatedRequest([
    'currentPassphrase' => $newPassphrase,
    'newPassphrase'     => $newPassphrase,
    'newPassphraseRepeat' => $newPassphrase,
]));
$body = json_decode($response->getBody(), true) ?: [];
assertCondition(
    $response->getStatusCode() === 200 && ($body['data']['verdict'] ?? '') === 'idempotentReceipt'
        && isset($body['data']['changedAt']),
    'Salida 3: el reenvío legítimo responde 200 idempotentReceipt con estampa por el endpoint'
);

// Salida 4 — éxito (200): se cambia a una frase nueva y distinta.
$thirdPassphrase = 'custodia de la tercera luna';
$response = $panelController->changePassphrase($forgeAuthenticatedRequest([
    'currentPassphrase' => $newPassphrase,
    'newPassphrase'     => $thirdPassphrase,
    'newPassphraseRepeat' => $thirdPassphrase,
]));
$body = json_decode($response->getBody(), true) ?: [];
assertCondition(
    $response->getStatusCode() === 200 && ($body['data']['verdict'] ?? '') === 'changed'
        && ($body['data']['currentSessionPreserved'] ?? false) === true
        && isset($body['data']['othersDissolvedCount']) && $body['data']['othersDissolvedCount'] === 0,
    'Salida 4: el éxito responde 200 changed con recibo completo por el endpoint'
);
$rowStatement->execute([':id' => 'usr_adepto']);
$thirdHash = (string) $rowStatement->fetchColumn();
assertCondition(password_verify($thirdPassphrase, $thirdHash), 'La frase de la Salida 4 gobierna el hash de la fila');

// La petición con las frases en claro jamás deja rastro en la bitácora.
$auditDumpStatement = $pdo->prepare('SELECT justification FROM audit_log');
$auditDumpStatement->execute();
$allJustifications = $auditDumpStatement->fetchAll(PDO::FETCH_COLUMN) ?: [];
$auditBlob = implode("\n", array_map('strval', $allJustifications));
assertCondition(
    !str_contains($auditBlob, $newPassphrase) && !str_contains($auditBlob, $thirdPassphrase) && !str_contains($auditBlob, 'una frase renovada'),
    'Ningún asiento contiene las frases viajadas por el endpoint (cuerpo jamás registrado, plan §2.6)'
);

// --- Resumen canónico del arnés ---
echo "\n== RESUMEN ==\n";
echo "Asertos superados: {$assertsPassed}\n";
echo "Asertos fallidos: {$assertsFailed}\n";
if ($assertsFailed === 0) {
    echo "RESULTADO: EXITO\n";
    exit(0);
}
echo "RESULTADO: FRACASO\n";
exit(1);
