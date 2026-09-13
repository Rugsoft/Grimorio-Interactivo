<?php

/**
 * test_spell_drafts_endpoints.php — Arnés TDD de la Tarea 4.2 (TASKS-04).
 *
 * Verifica los Endpoints 2 y 3 del plan (gestión de borradores privados)
 * materializados en SpellCreatorController:
 *   - POST   /api/v1/spells/drafts        → createDraft (201 / 400 / 403).
 *   - GET    /api/v1/spells/drafts        → listDrafts  (200).
 *   - PUT    /api/v1/spells/drafts/{id}   → updateDraft (200 / 403 / 404).
 *   - DELETE /api/v1/spells/drafts/{id}   → deleteDraft (200 / 403 / 404).
 *
 * Todos los endpoints exigen sesión autenticada (AuthMiddleware): sin
 * usuario activo la respuesta es 401 UNAUTHENTICATED (contrato SPEC-03).
 * Los códigos y sobres de error provienen de las excepciones de dominio
 * canónicas (DRAFT_QUOTA_EXCEEDED, SPELL_IMMUTABLE).
 *
 * Criterio «Hecho cuando» (Tarea 4.2): peticiones HTTP contra
 * /api/v1/spells/drafts permiten gestionar el ciclo de vida de
 * borradores privados devolviendo códigos HTTP 200, 201, 403 o 404
 * según la regla de negocio.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response/Router nativos del
 *     Core, PDO preparado, sin librerías HTTP.
 *   - Artículo II: el maná lo recalcula siempre SpellManagementService.
 *   - Artículo III: titularidad estricta y cuota de 10 borradores.
 *   - Artículo V: identificadores en inglés camelCase, leyendas y
 *     comentarios en castellano.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Dto/SpellCalculationInputDto.php';
require __DIR__ . '/../src/Dto/SpellCalculationResultDto.php';
require __DIR__ . '/../src/Dto/SpellCreateDto.php';
require __DIR__ . '/../src/Exceptions/ArcaneOverloadException.php';
require __DIR__ . '/../src/Exceptions/DraftQuotaExceededException.php';
require __DIR__ . '/../src/Exceptions/SpellImmutableException.php';
require __DIR__ . '/../src/Services/SpellBalanceService.php';
require __DIR__ . '/../src/Models/User.php';
require __DIR__ . '/../src/Models/AuditEntry.php';
require __DIR__ . '/../src/Services/AuditLogPage.php';
require __DIR__ . '/../src/Services/AuditService.php';
require __DIR__ . '/../src/Exceptions/SpellNotFoundException.php';
require __DIR__ . '/../src/Services/SpellManagementService.php';
require __DIR__ . '/../src/Core/Response.php';
require __DIR__ . '/../src/Core/Request.php';
require __DIR__ . '/../src/Core/Router.php';
require __DIR__ . '/../src/Controllers/SpellCreatorController.php';

use Grimorio\Controllers\SpellCreatorController;
use Grimorio\Core\Request;
use Grimorio\Core\Router;
use Grimorio\Models\User;

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
 * Payload narrativo + cuantitativo del Endpoint 2 (plan 2.2).
 *
 * @return array<string, mixed>
 */
function forgeDraftPayload(string $name, int $damage = 30): array
{
    return [
        'name'             => $name,
        'elementalAffinity' => 'fire',
        'magicSchool'      => 'evocation',
        'castingTime'      => 'action',
        'description'      => 'Concentra calor blanco en un núcleo denso que estalla a distancia media.',
        'damage'           => $damage,
        'healing'          => 0,
        'barrier'          => 0,
        'crowdControlType' => 'none',
        'rangeType'        => 'medium',
        'areaType'         => 'sphere',
        'durationType'     => 'instant',
        'hasVerbal'        => true,
        'hasSomatic'       => true,
        'hasMaterial'      => false,
    ];
}

/**
 * Petición autenticada con cuerpo JSON opcional y parámetros de ruta.
 *
 * @param array<string, string> $routeParams
 * @param array<string, string> $query
 */
function forgeRequest(
    string $method,
    string $path,
    ?User $user,
    ?array $payload = null,
    array $routeParams = [],
    array $query = [],
): Request {
    $rawBody = $payload === null ? null : (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

    $request = new Request($method, $path, $query, ['Content-Type' => 'application/json'], $rawBody);
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

echo "=== Tarea 4.2 (TASKS-04): Endpoints CRUD de borradores privados ===\n\n";

// =====================================================================
// Escenario: SQLite en memoria con esquema real + Router con la ruta.
// =====================================================================
$projectRoot = dirname(__DIR__);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

$now = '2026-09-13T19:00:00Z';
$pdo->exec(
    "INSERT INTO clans (id, slug, name, motto, domain_points, created_at)
     VALUES ('cln_forja', 'forja-scholars', 'Eruditos de la Forja', 'Forjar', 0, '{$now}')"
);
$pdo->exec(
    "INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
     VALUES ('usr_editor', 'EditorForja', 'editor@sanctuario.arc', 'x', 'editor', 'cln_forja', '{$now}', '{$now}')"
);
$pdo->exec("INSERT INTO magic_schools (slug, name) VALUES ('evocation', 'Evocación')");

$author = new User('usr_editor', 'EditorForja', 'editor@sanctuario.arc', 'editor', 'cln_forja', 'x', $now, $now);

$controller = new SpellCreatorController(null, new Grimorio\Services\SpellManagementService($pdo));

$router = new Router();
$router->addRoute('POST', '/api/v1/spells/drafts', fn (Request $r): object => $controller->createDraft($r));
$router->addRoute('GET', '/api/v1/spells/drafts', fn (Request $r): object => $controller->listDrafts($r));
$router->addRoute('PUT', '/api/v1/spells/drafts/{id}', fn (Request $r, array $p): object => $controller->updateDraft($r, $p));
$router->addRoute('DELETE', '/api/v1/spells/drafts/{id}', fn (Request $r, array $p): object => $controller->deleteDraft($r, $p));

// =====================================================================
// [0] Superficie (fase roja): los métodos existen.
// =====================================================================
echo "[0] Superficie\n";
foreach (['createDraft', 'listDrafts', 'updateDraft', 'deleteDraft'] as $method) {
    assertArcane(method_exists(SpellCreatorController::class, $method), "SpellCreatorController expone {$method}()");
}

// =====================================================================
// [1] Autenticación: sin usuario activo, 401 en todas las rutas.
// =====================================================================
echo "\n[1] Autenticación: 401 sin sesión activa\n";

$unauthenticatedCreate = $controller->createDraft(forgeRequest('POST', '/api/v1/spells/drafts', null, forgeDraftPayload('Huérfano')));
assertArcane($unauthenticatedCreate->getStatusCode() === 401 && (decodeBody($unauthenticatedCreate)['error']['code'] ?? '') === 'UNAUTHENTICATED', 'POST sin sesión → 401 UNAUTHENTICATED');

$unauthenticatedList = $controller->listDrafts(forgeRequest('GET', '/api/v1/spells/drafts', null));
assertArcane($unauthenticatedList->getStatusCode() === 401, 'GET sin sesión → 401 UNAUTHENTICATED');

// =====================================================================
// [2] POST /api/v1/spells/drafts — 201 Created (Endpoint 2).
// =====================================================================
echo "\n[2] POST /api/v1/spells/drafts — guardar borrador (201)\n";

$createdResponse = $controller->createDraft(forgeRequest('POST', '/api/v1/spells/drafts', $author, forgeDraftPayload('Esfera Ígnea de Frieren')));
$createdBody     = decodeBody($createdResponse);

assertArcane($createdResponse->getStatusCode() === 201, 'La creación exitosa responde HTTP 201');
assertArcane(($createdBody['success'] ?? null) === true, 'El sobre porta success = true');
assertArcane(is_string($createdBody['data']['id'] ?? null) && str_starts_with((string) $createdBody['data']['id'], 'spl_'), 'Retorna el ID del borrador (spl_*)');
assertArcane(($createdBody['data']['slug'] ?? '') === 'esfera-ignea-de-frieren', 'Retorna el slug canónico generado');
assertArcane(($createdBody['data']['status'] ?? '') === 'draft', 'El borrador nace en estado draft');
assertArcane(($createdBody['data']['manaCost'] ?? 0) === 48, 'El maná viaja recalculado por el backend (30 × 2.0 − 20% = 48)');

// =====================================================================
// [3] POST con payloads corruptos — 400 (validación y duplicado).
// =====================================================================
echo "\n[3] POST con carga corrupta — 400\n";

$invalidResponse = $controller->createDraft(forgeRequest('POST', '/api/v1/spells/drafts', $author, array_diff_key(forgeDraftPayload('Sin Nombre'), ['name' => ''])));
assertArcane($invalidResponse->getStatusCode() === 400, 'Payload sin campos obligatorios → 400');
assertArcane((decodeBody($invalidResponse)['error']['code'] ?? '') === 'INVALID_SPELL_INPUT', 'El sobre porta INVALID_SPELL_INPUT');

$duplicateResponse = $controller->createDraft(forgeRequest('POST', '/api/v1/spells/drafts', $author, forgeDraftPayload('Esfera Ígnea de Frieren')));
assertArcane($duplicateResponse->getStatusCode() === 400, 'El nombre canónico duplicado responde 400');

// =====================================================================
// [4] GET /api/v1/spells/drafts — 200 con lista privada (Endpoint 3).
// =====================================================================
echo "\n[4] GET /api/v1/spells/drafts — listado del autor (200)\n";

$listResponse = $controller->listDrafts(forgeRequest('GET', '/api/v1/spells/drafts', $author));
$listBody     = decodeBody($listResponse);

assertArcane($listResponse->getStatusCode() === 200, 'El listado responde HTTP 200');
assertArcane(($listBody['success'] ?? null) === true, 'El sobre porta success = true');
assertArcane(count($listBody['data'] ?? []) === 1, 'El listado contiene exactamente los borradores del autor');
assertArcane(
    ($listBody['data'][0]['manaCost'] ?? 0) === 48
    && ($listBody['data'][0]['circleLabel'] ?? '') === 'Círculo III (Magister)'
    && isset($listBody['data'][0]['updatedAt']),
    'Cada borrador porta coste, círculo solemne y fecha de actualización'
);

// =====================================================================
// [5] PUT /api/v1/spells/drafts/{id} — 200 y defensas (404/400).
// =====================================================================
echo "\n[5] PUT /api/v1/spells/drafts/{id} — actualización (200/404)\n";

$draftId = (string) $createdBody['data']['id'];

$updatedResponse = $controller->updateDraft(forgeRequest('PUT', "/api/v1/spells/drafts/{$draftId}", $author, forgeDraftPayload('Esfera Ígnea Reforjada', damage: 40)), ['id' => $draftId]);
$updatedBody     = decodeBody($updatedResponse);

assertArcane($updatedResponse->getStatusCode() === 200, 'La actualización responde HTTP 200');
assertArcane(($updatedBody['data']['manaCost'] ?? 0) === 64, 'El maná se recalcula con la nueva matemática (40 × 2.0 − 20% = 64)');

$ghostUpdate = $controller->updateDraft(forgeRequest('PUT', '/api/v1/spells/drafts/spl_fantasma', $author, forgeDraftPayload('Fantasma')), ['id' => 'spl_fantasma']);
assertArcane($ghostUpdate->getStatusCode() === 404 && (decodeBody($ghostUpdate)['error']['code'] ?? '') === 'SPELL_NOT_FOUND', 'Actualizar un id inexistente o ajeno → 404 SPELL_NOT_FOUND');

$corruptUpdate = $controller->updateDraft(forgeRequest('PUT', "/api/v1/spells/drafts/{$draftId}", $author, ['name' => 'Solo nombre']), ['id' => $draftId]);
assertArcane($corruptUpdate->getStatusCode() === 400, 'La edición con payload incompleto → 400');

// =====================================================================
// [6] DELETE /api/v1/spells/drafts/{id} — 200 y 404.
// =====================================================================
echo "\n[6] DELETE /api/v1/spells/drafts/{id} — retirada (200/404)\n";

$deletedResponse = $controller->deleteDraft(forgeRequest('DELETE', "/api/v1/spells/drafts/{$draftId}", $author), ['id' => $draftId]);
assertArcane($deletedResponse->getStatusCode() === 200 && (decodeBody($deletedResponse)['success'] ?? null) === true, 'El borrador propio se retira con HTTP 200');

$ghostDelete = $controller->deleteDraft(forgeRequest('DELETE', '/api/v1/spells/drafts/spl_fantasma', $author), ['id' => 'spl_fantasma']);
assertArcane($ghostDelete->getStatusCode() === 404, 'Retirar un id inexistente o ajeno → 404');

$emptyListResponse = $controller->listDrafts(forgeRequest('GET', '/api/v1/spells/drafts', $author));
assertArcane(decodeBody($emptyListResponse)['data'] === [], 'Tras retirarlo, el listado del autor queda vacío');

// =====================================================================
// [7] Cuota de 10 — 403 DRAFT_QUOTA_EXCEEDED (RF-05.1).
// =====================================================================
echo "\n[7] Cuota de 10 borradores — 403 DRAFT_QUOTA_EXCEEDED\n";

// El hueco quedó libre en [6]: se llenan los 10 y el undécimo falla.
for ($i = 1; $i <= 10; $i++) {
    $controller->createDraft(forgeRequest('POST', '/api/v1/spells/drafts', $author, forgeDraftPayload("Borrador de Cuota {$i}")));
}
$quotaResponse = $controller->createDraft(forgeRequest('POST', '/api/v1/spells/drafts', $author, forgeDraftPayload('Borrador de Cuota 11')));
$quotaBody     = decodeBody($quotaResponse);

assertArcane($quotaResponse->getStatusCode() === 403, 'El undécimo borrador responde HTTP 403');
assertArcane(($quotaBody['error']['code'] ?? '') === 'DRAFT_QUOTA_EXCEEDED', 'El sobre porta DRAFT_QUOTA_EXCEEDED');
assertArcane(($quotaBody['error']['recoveryAction'] ?? '') === 'DELETE_OR_PUBLISH_DRAFT', 'La acción de recuperación guía al autor (DELETE_OR_PUBLISH_DRAFT)');

// =====================================================================
// [8] Despacho real por el Router (llamada HTTP end-to-end).
// =====================================================================
echo "\n[8] Despacho por el Router\n";

// La cuota está agotada: primero se libera un hueco eliminando un
// borrador de cuota, y la ruta POST despacha después la creación.
$freedDraft = $controller->listDrafts(forgeRequest('GET', '/api/v1/spells/drafts', $author));
$freedId    = (string) (decodeBody($freedDraft)['data'][0]['id'] ?? '');
$router->dispatch(forgeRequest('DELETE', "/api/v1/spells/drafts/{$freedId}", $author, null, ['id' => $freedId]));

$routedCreated = $router->dispatch(forgeRequest('POST', '/api/v1/spells/drafts', $author, forgeDraftPayload('Ruta en Vivo')));
assertArcane($routedCreated->getStatusCode() === 201, 'La ruta POST /api/v1/spells/drafts despacha la creación (201)');

$routedList = $router->dispatch(forgeRequest('GET', '/api/v1/spells/drafts', $author));
assertArcane($routedList->getStatusCode() === 200 && count(decodeBody($routedList)['data'] ?? []) === 10, 'La ruta GET /api/v1/spells/drafts despacha el listado (200)');

$routedId = (string) (decodeBody($routedCreated)['data']['id'] ?? '');
$routedDeleted = $router->dispatch(forgeRequest('DELETE', "/api/v1/spells/drafts/{$routedId}", $author, null, ['id' => $routedId]));
assertArcane($routedDeleted->getStatusCode() === 200, 'La ruta DELETE /api/v1/spells/drafts/{id} despacha la retirada (200)');

// =====================================================================
// Resumen final.
// =====================================================================
echo "\n=== RESUMEN: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);
