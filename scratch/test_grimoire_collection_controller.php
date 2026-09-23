<?php

declare(strict_types=1);

/**
 * test_grimoire_collection_controller.php — Verificación de la Tarea 4.1
 * de TASKS-11.
 *
 * Valida EL REST ÍNTEGRO DEL TOMO (`listCollection` GET, `collectSpell`
 * POST, `discardSpell` DELETE y la puerta `praiseSpell` POST ya consagrada)
 * por HTTP real (Request/Router nativos) contra el «Hecho cuando»:
 *
 *   1. Las CUATRO rutas responden con los códigos exactos del plan §2.2:
 *      GET 200; POST 201 nuevo / 200 idempotente; DELETE 200 / 409.
 *   2. El peregrino recibe 403 LINEAGE_OATH_REQUIRED en las cuatro rutas
 *      (el linaje manda, no el rol — hallazgo 16).
 *   3. Anónimo → 401 UNAUTHENTICATED en las cuatro rutas.
 *   4. Ninguna clave JSON del contrato va en snake_case (guard del
 *      Artículo V, hallazgos 8/19): auditoría camelCase de los sobres.
 *   5. Errores con el patrón del santuario { success, error: { code,
 *      message } } (401/403/404/409).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response/Router nativos, PDO
 *     preparado, esquema canónico real, sondas desechables.
 *   - Artículo IV/V (Velo y Dualidad): reason técnico; leyendas y
 *     narrativa en noble castellano; asertos en inglés.
 *
 * Uso: php scratch/test_grimoire_collection_controller.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../src/Dto/GrimoirePageDto.php';
require __DIR__ . '/../src/Dto/CollectionEntryDto.php';
require __DIR__ . '/../src/Dto/CollectionPageDto.php';
require __DIR__ . '/../src/Models/User.php';
require __DIR__ . '/../src/Models/Spell.php';
require __DIR__ . '/../src/Core/Response.php';
require __DIR__ . '/../src/Core/Request.php';
require __DIR__ . '/../src/Core/Router.php';
require __DIR__ . '/../src/Models/AuditEntry.php';
require __DIR__ . '/../src/Repositories/GrimoireCollectionRepository.php';
require __DIR__ . '/../src/Repositories/ClanRepository.php';
require __DIR__ . '/../src/Repositories/ClanMemberRepository.php';
require __DIR__ . '/../src/Repositories/WeeklyCycleRepository.php';
require __DIR__ . '/../src/Dto/ClanDto.php';
require __DIR__ . '/../src/Dto/LineageDto.php';
require __DIR__ . '/../src/Dto/ClanMemberDto.php';
require __DIR__ . '/../src/Dto/DominionAwardDto.php';
require __DIR__ . '/../src/Dto/WeeklyCycleDto.php';
require __DIR__ . '/../src/Exceptions/ClanGovernanceException.php';
require __DIR__ . '/../src/Exceptions/LineageOathException.php';
require __DIR__ . '/../src/Exceptions/SpellNotFoundException.php';
require __DIR__ . '/../src/Exceptions/SpellNotInTomeException.php';
require __DIR__ . '/../src/Exceptions/SpellNotValidatedException.php';
require __DIR__ . '/../src/Exceptions/UniformSealVetoException.php';
require __DIR__ . '/../src/Services/AuditRecorderInterface.php';
require __DIR__ . '/../src/Services/AuditService.php';
require __DIR__ . '/../src/Services/LineageSynergyService.php';
require __DIR__ . '/../src/Services/WeeklyDominionService.php';
require __DIR__ . '/../src/Services/GrimoireCollectionService.php';
require __DIR__ . '/../src/Services/GrimoireQueryService.php';
require __DIR__ . '/../src/Controllers/GrimoireCollectionController.php';

use Grimorio\Controllers\GrimoireCollectionController;
use Grimorio\Core\Request;
use Grimorio\Core\Router;
use Grimorio\Models\User;
use Grimorio\Repositories\GrimoireCollectionRepository;
use Grimorio\Services\AuditService;
use Grimorio\Services\GrimoireCollectionService;
use Grimorio\Services\GrimoireQueryService;
use Grimorio\Services\WeeklyDominionService;

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

/** Despacha una petición por el Router real con usuario y cuerpo opcionales. */
function dispatchRoute(Router $router, string $method, string $path, ?User $user = null, ?string $rawBody = null, string $uriForGlobals = ''): object
{
    $uri = $uriForGlobals !== '' ? $uriForGlobals : $path;
    $_GET = [];
    $queryString = parse_url($uri, PHP_URL_QUERY);
    if (is_string($queryString) && $queryString !== '') {
        parse_str($queryString, $_GET);
    }
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $path;
    $_COOKIE = [];

    $request = new Request($method, $path, [], [], $rawBody);
    if ($user !== null) {
        $request->setUser($user);
    }

    return $router->dispatch($request);
}

/** Decodifica el sobre JSON de una Response a array asociativo. */
function bodyOf(object $response): array
{
    return json_decode($response->getBody(), true) ?: [];
}

/** Guard del Artículo V (hallazgos 8/19): cero claves snake_case en el sobre. */
function hasSnakeCaseKeys(array $payload): bool
{
    foreach ($payload as $key => $value) {
        if (is_string($key) && str_contains($key, '_')) {
            return true;
        }
        if (is_array($value) && hasSnakeCaseKeys($value)) {
            return true;
        }
    }

    return false;
}

/** Funda un mago contra el esquema canónico real. */
function seedWizard(PDO $connection, string $userId, string $alias, ?string $lineage): void
{
    $connection->prepare(
        'INSERT INTO users (id, alias, email, password_hash, lineage, created_at, updated_at)
         VALUES (:id, :alias, :email, :hash, :lineage, :created, :updated)'
    )->execute([
        ':id'      => $userId,
        ':alias'   => $alias,
        ':email'   => $alias . '@santuario.test',
        ':hash'    => str_repeat('a', 60),
        ':lineage' => $lineage,
        ':created' => '2026-09-22T00:00:00Z',
        ':updated' => '2026-09-22T00:00:00Z',
    ]);
}

/** Funda una hermandad. */
function seedHouse(PDO $connection, string $clanId, string $lineageType): void
{
    $connection->prepare(
        'INSERT INTO clans (id, slug, name, motto, created_at, coat_of_arms, lineage_type,
                            admission_mode, status, patriarch_id, weekly_points, historical_points,
                            last_activity_at, updated_at)
         VALUES (:id, :id, :id, :motto, :createdAt, :arms, :lineageType,
                 :mode, :status, NULL, 0, 0, :stamp, :stamp)'
    )->execute([
        ':id'          => $clanId,
        ':motto'       => 'Lema de prueba',
        ':createdAt'   => '2026-01-01T00:00:00Z',
        ':arms'        => 'rune_test',
        ':lineageType' => $lineageType,
        ':mode'        => 'open',
        ':status'      => 'active',
        ':stamp'       => '2026-09-01T00:00:00Z',
    ]);
}

/** Forja un conjuro con su casa, círculo, afinidad y estado. */
function seedSpell(PDO $connection, string $spellId, string $clanId, string $authorId, string $element, string $status): void
{
    $connection->prepare(
        'INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, casting_time,
                             mana_cost, circle, math_fingerprint, clan_id, summary, status,
                             validation_signatures_count, signatures_count, created_at, updated_at, validated_at)
         VALUES (:id, :id, :name, :authorId, :school, :element, :castingTime,
                 :manaCost, :circle, :fingerprint, :clanId, :summary, :status,
                 0, 0, :stamp, :stamp, :validatedAt)'
    )->execute([
        ':id'          => $spellId,
        ':name'        => 'Conjuro ' . $spellId,
        ':authorId'    => $authorId,
        ':school'      => 'evocation',
        ':element'     => $element,
        ':castingTime' => 'action',
        ':manaCost'    => 10,
        ':circle'      => 1,
        ':fingerprint' => str_repeat('f', 64),
        ':clanId'      => $clanId,
        ':summary'     => 'Resumen de prueba',
        ':status'      => $status,
        ':stamp'       => '2026-09-01T00:00:00Z',
        ':validatedAt' => $status === 'validated' ? '2026-09-10T10:00:00Z' : null,
    ]);
}

echo "=== El REST íntegro del Tomo — Tarea 4.1 de TASKS-11 ===\n";

// ---------------------------------------------------------------------
// Base canónica real + Router con las cuatro rutas del contrato.
// ---------------------------------------------------------------------
$probePath = __DIR__ . '/__probe_gc_controller.sqlite';
@unlink($probePath);
$connection = new PDO('sqlite:' . $probePath);
$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$connection->exec('PRAGMA foreign_keys = ON');
$connection->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));
$connection->prepare("INSERT OR IGNORE INTO magic_schools (slug, name) VALUES ('evocation', 'Evocación')")->execute();

seedHouse($connection, 'cln-flame', 'primordialFlame');
seedHouse($connection, 'cln-tides', 'celestialTides');

seedWizard($connection, 'usr-adept', 'Adepto Marea', 'celestialTides');
seedWizard($connection, 'usr-pilgrim', 'Peregrino Sin Linaje', null);
seedWizard($connection, 'usr-author', 'Autor Llama', 'primordialFlame');

seedSpell($connection, 'spl-ctrl-fire', 'cln-flame', 'usr-author', 'fire', 'validated');
seedSpell($connection, 'spl-ctrl-water', 'cln-tides', 'usr-author', 'water', 'validated');
seedSpell($connection, 'spl-ctrl-experimental', 'cln-flame', 'usr-author', 'fire', 'experimental');

$auditService = new AuditService($connection);
$weeklyDominion = new WeeklyDominionService($connection, $auditService);
$controller = new GrimoireCollectionController(
    new GrimoireCollectionService(
        new GrimoireCollectionRepository($connection),
        $auditService,
        $connection,
    ),
    new GrimoireQueryService($connection),
    $weeklyDominion,
    $auditService,
);

$router = new Router();
$router->addRoute('GET', '/api/v1/grimoire/collection', fn (Request $request): object => $controller->listCollection($request));
$router->addRoute('POST', '/api/v1/grimoire/collection', fn (Request $request): object => $controller->collectSpell($request));
$router->addRoute('DELETE', '/api/v1/grimoire/collection/{spellId}', fn (Request $request, array $routeParams): object => $controller->discardSpell($request, $routeParams));
$router->addRoute('POST', '/api/v1/grimoire/praise', fn (Request $request): object => $controller->praiseSpell($request));

$adept = new User('usr-adept', 'Adepto Marea', 'adept@santuario.test', 'reader', null, 'celestialTides');
$pilgrim = new User('usr-pilgrim', 'Peregrino Sin Linaje', 'peregrino@santuario.test', 'reader', null, null);

echo "\n[FASE 1] Sellado: 201 nuevo, 200 idempotente, con eco camelCase (RF-01, plan §2.2).\n";
$response = dispatchRoute($router, 'POST', '/api/v1/grimoire/collection', $adept, '{"spellId":"spl-ctrl-fire"}');
assertCondition($response->getStatusCode() === 201, 'El sellado nuevo responde 201 (código exacto del plan).');
$body = bodyOf($response);
assertCondition(
    ($body['data']['alreadyCollected'] ?? null) === false && ($body['data']['addedAt'] ?? '') !== '',
    'El eco del sellado porta alreadyCollected false y addedAt ISO en camelCase.',
);
assertCondition(!hasSnakeCaseKeys($body), 'El sobre del sellado no porta NI UNA clave snake_case (guard Artículo V, hallazgos 8/19).');

$response = dispatchRoute($router, 'POST', '/api/v1/grimoire/collection', $adept, '{"spellId":"spl-ctrl-fire"}');
assertCondition($response->getStatusCode() === 200, 'El re-sellado responde 200 idempotente (RF-01.3).');
assertCondition(
    (bodyOf($response)['data']['alreadyCollected'] ?? null) === true,
    'El eco idempotente porta alreadyCollected true conservando el instante original.',
);
assertCondition(
    (int) $connection->query("SELECT COUNT(*) FROM grimoire_collections WHERE user_id = 'usr-adept'")->fetchColumn() === 1,
    'Una sola fila en el tomo tras el doble sellado (la muralla UNIQUE viva).',
);

echo "\n[FASE 2] Sellado vedado: leyenda UNIFORME y fantasma (RF-01.2, hallazgos 4 y 16).\n";
$response = dispatchRoute($router, 'POST', '/api/v1/grimoire/collection', $adept, '{"spellId":"spl-ctrl-experimental"}');
assertCondition(
    $response->getStatusCode() === 403 && (bodyOf($response)['error']['code'] ?? '') === 'TOME_SEAL_VETO',
    'El no validado responde 403 TOME_SEAL_VETO con su leyenda UNIFORME (jamás revela el estado).',
);
$response = dispatchRoute($router, 'POST', '/api/v1/grimoire/collection', $adept, '{"spellId":"spl-ghost"}');
assertCondition(
    $response->getStatusCode() === 404 && (bodyOf($response)['error']['code'] ?? '') === 'SPELL_NOT_FOUND',
    'El fantasma responde 404 SPELL_NOT_FOUND (tras las guardias de identidad).',
);
assertCondition(!hasSnakeCaseKeys(bodyOf($response)), 'El sobre del error 404 tampoco porta claves snake_case.');

echo "\n[FASE 3] Lectura del tomo: 200 con sobre CollectionPageDto (RF-02.1).\n";
$response = dispatchRoute($router, 'GET', '/api/v1/grimoire/collection', $adept);
assertCondition($response->getStatusCode() === 200, 'La lectura del tomo responde 200.');
$collection = bodyOf($response)['data'];
assertCondition(
    ($collection['total'] ?? -1) === 1 && ($collection['page'] ?? -1) === 1
    && ($collection['limit'] ?? -1) === 50 && ($collection['totalPages'] ?? -1) === 1,
    'El sobre porta total, page, limit (50) y totalPages del contrato.',
);
assertCondition(
    count($collection['entries'] ?? []) === 1
    && ($collection['entries'][0]['spell']['id'] ?? '') === 'spl-ctrl-fire'
    && ($collection['entries'][0]['addedAt'] ?? '') !== ''
    && ($collection['entries'][0]['tomeMark'] ?? '') === 'living',
    'La entrada porta spell, addedAt, tomeMark living en camelCase (plan §2.2).',
);
assertCondition(
    ($collection['entries'][0]['praiseStatus']['praised'] ?? null) === false
    && ($collection['entries'][0]['praiseStatus']['allowed'] ?? null) === true,
    'El praiseStatus porta praised false y allowed true (obra validada de casa ajena).',
);
assertCondition(!hasSnakeCaseKeys(bodyOf($response)), 'El sobre de la lectura jamás porta snake_case (hallazgos 8/19).');

echo "\n[FASE 4] Retirada: 200 con total actualizado, 409 ante ausente (RF-02.4).\n";
$response = dispatchRoute($router, 'DELETE', '/api/v1/grimoire/collection/spl-ctrl-fire', $adept);
assertCondition($response->getStatusCode() === 200, 'La retirada consumada responde 200.');
assertCondition(
    (bodyOf($response)['data']['removed'] ?? null) === true && (bodyOf($response)['data']['total'] ?? -1) === 0,
    'El eco porta removed true y el total ACTUALIZADO (0) en la misma respuesta.',
);
$response = dispatchRoute($router, 'DELETE', '/api/v1/grimoire/collection/spl-ctrl-fire', $adept);
assertCondition(
    $response->getStatusCode() === 409 && (bodyOf($response)['error']['code'] ?? '') === 'SPELL_NOT_IN_TOME',
    'La re-retirada responde 409 SPELL_NOT_IN_TOME con el patrón del santuario.',
);
assertCondition(
    str_contains((string) (bodyOf($response)['error']['message'] ?? ''), 'solo se retira lo que se selló'),
    'La leyenda del 409 porta la voz solemne de la excepción (plan Anexo A).',
);
assertCondition(!hasSnakeCaseKeys(bodyOf($response)), 'El sobre del 409 tampoco porta claves snake_case.');

echo "\n[FASE 5] Puerta del elogio en su ruta REST (RF-04, ya consagrada).\n";
$response = dispatchRoute($router, 'POST', '/api/v1/grimoire/praise', $adept, '{"spellId":"spl-ctrl-water"}');
assertCondition($response->getStatusCode() === 200, 'La gloria nueva responde 200 por la ruta /grimoire/praise.');
assertCondition(
    (bodyOf($response)['data']['praised'] ?? null) === true && (bodyOf($response)['data']['reason'] ?? '') === 'AWARDED',
    'El sobre porta praised true con reason AWARDED.',
);

echo "\n[FASE 6] El peregrino y el anónimo en las CUATRO rutas (hallazgo 16, SPEC-03).\n";
foreach (['GET /api/v1/grimoire/collection', 'POST /api/v1/grimoire/collection', 'DELETE /api/v1/grimoire/collection/spl-ctrl-water', 'POST /api/v1/grimoire/praise'] as $route) {
    [$method, $path] = explode(' ', $route, 2);
    $body = $method === 'POST' ? '{"spellId":"spl-ctrl-water"}' : null;
    $response = dispatchRoute($router, $method, $path, $pilgrim, $body);
    $okCode = $response->getStatusCode() === 403
        && (bodyOf($response)['error']['code'] ?? '') === 'LINEAGE_OATH_REQUIRED';
    assertCondition($okCode, "El peregrino recibe 403 LINEAGE_OATH_REQUIRED en {$method} {$path}.");

    $response = dispatchRoute($router, $method, $path, null, $body);
    $okCode = $response->getStatusCode() === 401
        && (bodyOf($response)['error']['code'] ?? '') === 'UNAUTHENTICATED';
    assertCondition($okCode, "El anónimo recibe 401 UNAUTHENTICATED en {$method} {$path}.");
}

echo "\n[FASE 7] Patrón de error del santuario en TODOS los fallos (RF-05.1).\n";
foreach (['POST /api/v1/grimoire/collection', 'DELETE /api/v1/grimoire/collection/spl-ghost', 'POST /api/v1/grimoire/praise'] as $route) {
    [$method, $path] = explode(' ', $route, 2);
    $body = $method === 'DELETE' ? null : '{"spellId":"spl-ghost"}';
    $response = dispatchRoute($router, $method, $path, $adept, $body);
    $error = bodyOf($response)['error'] ?? [];
    assertCondition(
        isset($error['code'], $error['message']) && (bodyOf($response)['success'] ?? true) === false,
        "El fallo de {$method} {$path} viaja como { success, error: { code, message } }.",
    );
}

// Limpieza de sondas desechables.
@unlink($probePath);
@unlink(str_replace('.sqlite', '-wal', $probePath));
@unlink(str_replace('.sqlite', '-shm', $probePath));

echo "\n=== RESULTADO: {$assertsPassed} pasan, {$assertsFailed} fallan ===\n";
exit($assertsFailed === 0 ? 0 : 1);
