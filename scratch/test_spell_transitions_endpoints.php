<?php

/**
 * test_spell_transitions_endpoints.php — Arnés TDD de la Tarea 4.3 (TASKS-04).
 *
 * Verifica los Endpoints 4, 5 y 6 del plan (transiciones de estado y
 * derivación) materializados en SpellCreatorController:
 *   - POST /api/v1/spells/publish/{id}      → publishToExperimental
 *     (200 con firmas 0/3, 404 inexistente/ajeno, 400 ya publicado).
 *   - PUT  /api/v1/spells/experimental/{id} → updateExperimental
 *     (200 con signaturesReset, 403 SPELL_IMMUTABLE sobre validado,
 *     404 inexistente/ajeno, 400 payload inválido).
 *   - POST /api/v1/spells/variant/{id}      → createVariant
 *     (201 con la variante nacida draft, 404/400 origen inválido,
 *     403 SPELL_IMMUTABLE no aplicable: solo el validado es origen).
 *
 * Criterio «Hecho cuando» (Tarea 4.3): cada endpoint ejecuta la
 * transición de estado correspondiente e inyecta las cabeceras de
 * respuesta JSON adecuadas bajo sesión autenticada.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response/Router nativos.
 *   - Artículo II: el maná lo recalcula siempre el servicio.
 *   - Artículo III: titularidad estricta; el validado es inmutable.
 *   - Artículo V: identificadores en inglés camelCase, leyendas en
 *     castellano.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Dto/SpellCalculationInputDto.php';
require __DIR__ . '/../src/Dto/SpellCalculationResultDto.php';
require __DIR__ . '/../src/Dto/SpellCreateDto.php';
require __DIR__ . '/../src/Exceptions/ArcaneOverloadException.php';
require __DIR__ . '/../src/Exceptions/DraftQuotaExceededException.php';
require __DIR__ . '/../src/Exceptions/SpellImmutableException.php';
require __DIR__ . '/../src/Exceptions/SpellNotFoundException.php';
require __DIR__ . '/../src/Services/SpellBalanceService.php';
require __DIR__ . '/../src/Models/User.php';
require __DIR__ . '/../src/Models/AuditEntry.php';
require __DIR__ . '/../src/Services/AuditLogPage.php';
require __DIR__ . '/../src/Services/AuditService.php';
require __DIR__ . '/../src/Services/SpellManagementService.php';
require __DIR__ . '/../src/Core/Response.php';
require __DIR__ . '/../src/Core/Request.php';
require __DIR__ . '/../src/Core/Router.php';
require __DIR__ . '/../src/Controllers/SpellCreatorController.php';

use Grimorio\Controllers\SpellCreatorController;
use Grimorio\Core\Request;
use Grimorio\Core\Router;
use Grimorio\Models\User;
use Grimorio\Services\SpellManagementService;

$assertsPassed = 0;
$assertsFailed = 0;

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
 * Payload narrativo + cuantitativo de creación/edición.
 *
 * @return array<string, mixed>
 */
function forgeDraftPayload(string $name, int $damage = 30, string $rangeType = 'medium'): array
{
    return [
        'name'              => $name,
        'elementalAffinity' => 'fire',
        'magicSchool'       => 'evocation',
        'castingTime'       => 'action',
        'description'       => 'Núcleo de calor blanco que estalla a distancia media.',
        'damage'            => $damage,
        'healing'           => 0,
        'barrier'           => 0,
        'crowdControlType'  => 'none',
        'rangeType'         => $rangeType,
        'areaType'          => 'sphere',
        'durationType'      => 'instant',
        'hasVerbal'         => true,
        'hasSomatic'        => true,
        'hasMaterial'       => false,
    ];
}

/**
 * Petición autenticada con cuerpo JSON opcional.
 *
 * @param array<string, string> $routeParams
 */
function forgeRequest(string $method, string $path, ?User $user, ?array $payload = null, array $routeParams = []): Request
{
    $rawBody = $payload === null ? null : (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    $request = new Request($method, $path, [], ['Content-Type' => 'application/json'], $rawBody);
    if ($user !== null) {
        $request->setUser($user);
    }
    return $request;
}

/**
 * Deserializa el cuerpo de una Response a array asociativo.
 *
 * @return array<string, mixed>
 */
function decodeBody(object $response): array
{
    $decoded = json_decode($response->getBody(), true);
    return is_array($decoded) ? $decoded : [];
}

echo "=== Tarea 4.3 (TASKS-04): Endpoints de transición y variantes ===\n\n";

// =====================================================================
// Escenario: SQLite en memoria con esquema real + Router completo.
// =====================================================================
$projectRoot = dirname(__DIR__);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

$now = '2026-09-13T20:00:00Z';
$pdo->exec(
    "INSERT INTO clans (id, slug, name, motto, created_at)
     VALUES ('cln_trans', 'trans-scholars', 'Eruditos de la Transición', 'Transitar', '{$now}')"
);
$pdo->exec(
    "INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
     VALUES ('usr_editor', 'EditorTransito', 'editor@sanctuario.arc', 'x', 'editor', 'cln_trans', '{$now}', '{$now}')"
);
$pdo->exec("INSERT INTO magic_schools (slug, name) VALUES ('evocation', 'Evocación')");

$author = new User('usr_editor', 'EditorTransito', 'editor@sanctuario.arc', 'editor', 'cln_trans', 'x', $now, $now);

$controller = new SpellCreatorController(null, new SpellManagementService($pdo));

$router = new Router();
$router->addRoute('POST', '/api/v1/spells/drafts', fn (Request $r): object => $controller->createDraft($r));
$router->addRoute('POST', '/api/v1/spells/publish/{id}', fn (Request $r, array $p): object => $controller->publishSpell($r, $p));
$router->addRoute('PUT', '/api/v1/spells/experimental/{id}', fn (Request $r, array $p): object => $controller->updateExperimental($r, $p));
$router->addRoute('POST', '/api/v1/spells/variant/{id}', fn (Request $r, array $p): object => $controller->createVariant($r, $p));

// =====================================================================
// [0] Superficie (fase roja): los métodos existen.
// =====================================================================
echo "[0] Superficie\n";
foreach (['publishSpell', 'updateExperimental', 'createVariant'] as $method) {
    assertArcane(method_exists(SpellCreatorController::class, $method), "SpellCreatorController expone {$method}()");
}

// =====================================================================
// [1] Preparación: un borrador del autor (vía HTTP real).
// =====================================================================
$created = decodeBody($controller->createDraft(forgeRequest('POST', '/api/v1/spells/drafts', $author, forgeDraftPayload('Lanza del Alba'))));
$draftId = (string) ($created['data']['id'] ?? '');
assertArcane(str_starts_with($draftId, 'spl_'), 'El borrador de partida se crea por HTTP (spl_*)');

// =====================================================================
// [2] POST /api/v1/spells/publish/{id} — 200 (Endpoint 4).
// =====================================================================
echo "\n[2] POST /api/v1/spells/publish/{id} — publicación (200/404)\n";

$publishedResponse = $controller->publishSpell(forgeRequest('POST', "/api/v1/spells/publish/{$draftId}", $author), ['id' => $draftId]);
$publishedBody     = decodeBody($publishedResponse);

assertArcane($publishedResponse->getStatusCode() === 200, 'La publicación responde HTTP 200');
assertArcane(($publishedBody['success'] ?? null) === true, 'El sobre porta success = true');
assertArcane(($publishedBody['data']['status'] ?? '') === 'experimental', 'El conjuro transiciona a experimental');
assertArcane(($publishedBody['data']['signaturesCount'] ?? -1) === 0, 'Las firmas quedan inicializadas a 0/3');

$unauthPublish = $controller->publishSpell(forgeRequest('POST', "/api/v1/spells/publish/{$draftId}", null), ['id' => $draftId]);
assertArcane($unauthPublish->getStatusCode() === 401, 'Publicar sin sesión → 401 UNAUTHENTICATED');

$ghostPublish = $controller->publishSpell(forgeRequest('POST', '/api/v1/spells/publish/spl_fantasma', $author), ['id' => 'spl_fantasma']);
assertArcane($ghostPublish->getStatusCode() === 404 && (decodeBody($ghostPublish)['error']['code'] ?? '') === 'SPELL_NOT_FOUND', 'Publicar un id inexistente o ajeno → 404 SPELL_NOT_FOUND');

$alreadyPublished = $controller->publishSpell(forgeRequest('POST', "/api/v1/spells/publish/{$draftId}", $author), ['id' => $draftId]);
assertArcane($alreadyPublished->getStatusCode() === 400, 'Publicar un conjuro que ya no es draft → 400');

// =====================================================================
// [3] PUT /api/v1/spells/experimental/{id} — 200 con antifraude (Ep. 5).
// =====================================================================
echo "\n[3] PUT /api/v1/spells/experimental/{id} — edición en moderación (200/403)\n";

// Dos firmas de Maestros simuladas sobre el experimental.
$pdo->prepare('UPDATE spells SET signatures_count = 2 WHERE id = :id')->execute([':id' => $draftId]);

// Cambio MATEMÁTICO (alcance medium → long): reseteo de firmas.
$mathResponse = $controller->updateExperimental(
    forgeRequest('PUT', "/api/v1/spells/experimental/{$draftId}", $author, forgeDraftPayload('Lanza del Alba', damage: 30, rangeType: 'long')),
    ['id' => $draftId]
);
$mathBody = decodeBody($mathResponse);

assertArcane($mathResponse->getStatusCode() === 200, 'La edición del experimental responde HTTP 200');
assertArcane(($mathBody['data']['signaturesReset'] ?? null) === true, 'El cambio matemático incluye signaturesReset = true');
assertArcane(($mathBody['data']['signaturesCount'] ?? -1) === 0, 'Las firmas quedan restablecidas a 0/3 en la respuesta');
assertArcane(($mathBody['data']['manaCost'] ?? 0) === 58, 'El maná viaja recalculado (30 × 1.5 × 1.6 ≈ 72 − 20% → 58)');

// Cambio NARRATIVO (misma matemática): firmas preservadas.
$pdo->prepare('UPDATE spells SET signatures_count = 2 WHERE id = :id')->execute([':id' => $draftId]);
$narrativePayload = forgeDraftPayload('Lanza del Alba', damage: 30, rangeType: 'long');
$narrativePayload['description'] = 'Núcleo de calor blanco que estalla a distancia media, con la ortografía pulida.';
$narrativeResponse = $controller->updateExperimental(forgeRequest('PUT', "/api/v1/spells/experimental/{$draftId}", $author, $narrativePayload), ['id' => $draftId]);
$narrativeBody = decodeBody($narrativeResponse);

assertArcane(($narrativeBody['data']['signaturesReset'] ?? true) === false, 'El cambio narrativo incluye signaturesReset = false');
assertArcane(($narrativeBody['data']['signaturesCount'] ?? 0) === 2, 'Las 2 firmas se PRESERVAN ante la corrección narrativa');

$corruptUpdate = $controller->updateExperimental(forgeRequest('PUT', "/api/v1/spells/experimental/{$draftId}", $author, ['name' => 'Solo nombre']), ['id' => $draftId]);
assertArcane($corruptUpdate->getStatusCode() === 400, 'La edición con payload incompleto → 400');

$ghostUpdate = $controller->updateExperimental(forgeRequest('PUT', '/api/v1/spells/experimental/spl_fantasma', $author, forgeDraftPayload('Fantasma')), ['id' => 'spl_fantasma']);
assertArcane($ghostUpdate->getStatusCode() === 404, 'Editar un id inexistente o ajeno → 404');

// =====================================================================
// [4] Inviolabilidad por HTTP: el validado no admite edición (403).
// =====================================================================
echo "\n[4] 403 SPELL_IMMUTABLE sobre el validado (Endpoint 5)\n";

// Tres firmas: el motor legitima el estado validated directamente en BD.
$pdo->prepare("UPDATE spells SET status = 'validated', signatures_count = 3 WHERE id = :id")->execute([':id' => $draftId]);
$validatedId = $draftId;

$immutableResponse = $controller->updateExperimental(
    forgeRequest('PUT', "/api/v1/spells/experimental/{$validatedId}", $author, forgeDraftPayload('Lanza del Alba')),
    ['id' => $validatedId]
);
$immutableBody = decodeBody($immutableResponse);

assertArcane($immutableResponse->getStatusCode() === 403, 'Editar el validado por HTTP → 403');
assertArcane(($immutableBody['error']['code'] ?? '') === 'SPELL_IMMUTABLE', 'El sobre porta SPELL_IMMUTABLE');
assertArcane(($immutableBody['error']['recoveryAction'] ?? '') === 'CREATE_VARIANT', 'La recuperación guía hacia CREATE_VARIANT');

// =====================================================================
// [5] POST /api/v1/spells/variant/{id} — 201 (Endpoint 6).
// =====================================================================
echo "\n[5] POST /api/v1/spells/variant/{id} — derivación (201/404)\n";

$variantResponse = $controller->createVariant(forgeRequest('POST', "/api/v1/spells/variant/{$validatedId}", $author), ['id' => $validatedId]);
$variantBody     = decodeBody($variantResponse);

assertArcane($variantResponse->getStatusCode() === 201, 'La derivación de la variante responde HTTP 201');
assertArcane(($variantBody['success'] ?? null) === true, 'El sobre porta success = true');
assertArcane(is_string($variantBody['data']['id'] ?? null) && ($variantBody['data']['id']) !== $validatedId, 'La variante porta un identificador NUEVO');
assertArcane(($variantBody['data']['status'] ?? '') === 'draft', 'La variante nace en estado draft');
assertArcane(str_contains((string) ($variantBody['data']['name'] ?? ''), '(Variante)'), 'El nombre porta el sufijo solemne «(Variante)»');
assertArcane(($variantBody['data']['signaturesCount'] ?? -1) === 0, 'La variante nace con 0/3 firmas');

$variantFromDraft = $controller->createVariant(forgeRequest('POST', "/api/v1/spells/variant/{$variantBody['data']['id']}", $author), ['id' => (string) $variantBody['data']['id']]);
assertArcane($variantFromDraft->getStatusCode() === 400, 'Derivar desde un draft (no validado) → 400');

$variantGhost = $controller->createVariant(forgeRequest('POST', '/api/v1/spells/variant/spl_fantasma', $author), ['id' => 'spl_fantasma']);
assertArcane($variantGhost->getStatusCode() === 404, 'Derivar desde un id inexistente o ajeno → 404');

$variantUnauth = $controller->createVariant(forgeRequest('POST', "/api/v1/spells/variant/{$validatedId}", null), ['id' => $validatedId]);
assertArcane($variantUnauth->getStatusCode() === 401, 'Derivar sin sesión → 401 UNAUTHENTICATED');

// =====================================================================
// [6] Despacho real por el Router (llamada HTTP end-to-end).
// =====================================================================
echo "\n[6] Despacho por el Router\n";

// Ciclo completo en vivo: draft → publish → (firma) → variant.
$routedDraft = decodeBody($router->dispatch(forgeRequest('POST', '/api/v1/spells/drafts', $author, forgeDraftPayload('Ruta de Transición'))));
$routedDraftId = (string) ($routedDraft['data']['id'] ?? '');

$routedPublish = $router->dispatch(forgeRequest('POST', "/api/v1/spells/publish/{$routedDraftId}", $author, null, ['id' => $routedDraftId]));
assertArcane($routedPublish->getStatusCode() === 200, 'La ruta POST /api/v1/spells/publish/{id} despacha la publicación (200)');

$routedUpdate = $router->dispatch(forgeRequest('PUT', "/api/v1/spells/experimental/{$routedDraftId}", $author, forgeDraftPayload('Ruta de Transición', damage: 25, rangeType: 'short'), ['id' => $routedDraftId]));
assertArcane($routedUpdate->getStatusCode() === 200, 'La ruta PUT /api/v1/spells/experimental/{id} despacha la edición (200)');

$pdo->prepare("UPDATE spells SET status = 'validated', signatures_count = 3 WHERE id = :id")->execute([':id' => $routedDraftId]);
$routedVariant = $router->dispatch(forgeRequest('POST', "/api/v1/spells/variant/{$routedDraftId}", $author, null, ['id' => $routedDraftId]));
assertArcane($routedVariant->getStatusCode() === 201, 'La ruta POST /api/v1/spells/variant/{id} despacha la derivación (201)');

// =====================================================================
// Resumen final.
// =====================================================================
echo "\n=== RESUMEN: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);
