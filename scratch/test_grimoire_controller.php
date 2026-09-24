<?php

/**
 * test_grimoire_controller.php — Arnés TDD de la Tarea 1.3 (TASKS-05).
 *
 * Verifica el GrimoireController por HTTP real (despacho por el Router):
 *   - GET /api/v1/grimoire/spells        → 200 con el sobre del contrato.
 *   - GET .../spells?mode=essays anónimo → 401 UNAUTHENTICATED.
 *   - GET .../spells?mode=essays autenticado → 200 con ensayos propios.
 *   - GET .../spells/{id}                → 200 con la ficha litúrgica.
 *   - Filtros circle/element y saneado de parámetros (400 ante basura).
 *
 * Criterio «Hecho cuando» (Tarea 1.3): peticiones HTTP a
 * /api/v1/grimoire/spells devuelven HTTP 200 con la carga JSON esperada,
 * y peticiones con mode=essays sin sesión autenticada responden con
 * HTTP 401 Unauthorized.
 *
 * Constitución:
 *   - Artículo I: Request/Response/Router nativos del proyecto.
 *   - Artículo III: aislamiento estricto de ensayos por autor.
 *   - Artículo V: identificadores camelCase, leyendas castellanas.
 *
 * Uso: php scratch/test_grimoire_controller.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../src/Dto/GrimoirePageDto.php';
require __DIR__ . '/../src/Models/User.php';
require __DIR__ . '/../src/Models/Spell.php';
require __DIR__ . '/../src/Core/Response.php';
require __DIR__ . '/../src/Core/Request.php';
require __DIR__ . '/../src/Core/Router.php';
require __DIR__ . '/../src/Services/GrimoireQueryService.php';
require __DIR__ . '/../src/Repositories/GrimoireCollectionRepository.php';
require __DIR__ . '/../src/Services/GrimoireCollectionService.php';
require __DIR__ . '/../src/Dto/CollectionEntryDto.php';
require __DIR__ . '/../src/Dto/CollectionPageDto.php';
require __DIR__ . '/../src/Controllers/GrimoireController.php';

use Grimorio\Controllers\GrimoireController;
use Grimorio\Core\Request;
use Grimorio\Core\Router;
use Grimorio\Models\User;
use Grimorio\Services\GrimoireQueryService;

$assertsPassed = 0;
$assertsFailed = 0;

/** Aserta una condición y la registra en la bitácora solemne. */
function assertCondition(bool $condition, string $description): void
{
    global $assertsPassed, $assertsFailed;
    if ($condition) {
        $assertsPassed++;
        echo "  [OK]   {$description}" . PHP_EOL;
    } else {
        $assertsFailed++;
        echo "  [FALLA] {$description}" . PHP_EOL;
    }
}

/** Sobre decodificado de una Response (cuerpo JSON ya serializado). */
function decodePayload(object $response): array
{
    $decoded = json_decode($response->getBody(), true);
    return is_array($decoded) ? $decoded : [];
}

/** Código HTTP de una Response (getter público, sin emitir cabeceras en CLI). */
function statusCodeOf(object $response): int
{
    return $response->getStatusCode();
}

/** Despacha una petición GET por el Router real con cookies/usuario opcionales. */
function dispatchGet(Router $router, string $uri, ?User $user = null): object
{
    $_GET = [];
    $queryString = parse_url($uri, PHP_URL_QUERY);
    if (is_string($queryString) && $queryString !== '') {
        parse_str($queryString, $_GET);
    }
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $uri;
    $_COOKIE = [];

    $request = Request::fromGlobals();
    if ($user !== null) {
        $request->setUser($user);
    }
    return $router->dispatch($request);
}

echo '== ARNES TDD: GrimoireController (Tarea 1.3, TASKS-05) ==' . PHP_EOL;

// ---------------------------------------------------------------------
// Banco de datos en memoria con esquema y seeds reales + data de prueba.
// ---------------------------------------------------------------------
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));
$pdo->exec((string) file_get_contents(__DIR__ . '/../database/seeds.sql'));

/**
 * Siembra un conjuro de prueba (misma firma del arnés 1.2).
 * @param array<string, mixed> $overrides
 */
function seedSpell(PDO $pdo, string $id, string $name, string $status, array $overrides = []): void
{
    $defaults = [
        'slug'                => 'slug-' . $id,
        'author_id'           => 'usr_custodio_primordial',
        'magic_school'        => 'evocation',
        'elemental_affinity'  => 'fire',
        'casting_time'        => 'action',
        'mana_cost'           => 20,
        'circle'              => 2,
        'math_fingerprint'    => str_repeat('b', 64),
        'clan_id'             => 'cln_primordial',
        'summary'             => 'Resumen de ' . $name,
        'description'         => 'Descripción completa de ' . $name,
        'components_verbal'   => '¡Fórmula de ' . $name . '!',
        'components_somatic'  => '',
        'components_material' => '',
        'damage'              => 10,
        'healing'             => 0,
        'barrier'             => 0,
        'crowd_control_type'  => 'none',
        'range_type'          => 'medium',
        'area_type'           => 'singleTarget',
        'duration_type'       => 'instant',
        'has_verbal'          => 1,
        'has_somatic'         => 0,
        'has_material'        => 0,
        'status'              => $status,
        'created_at'          => '2026-09-01T00:00:00Z',
        'updated_at'          => '2026-09-01T00:00:00Z',
    ];
    $row = array_merge($defaults, ['id' => $id, 'name' => $name, 'status' => $status], $overrides);
    $columns = array_keys($row);
    $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
    $pdo->prepare('INSERT INTO spells (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')')
        ->execute($row);
}

$pdo->prepare("INSERT OR IGNORE INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
               VALUES ('usr_autor_uno', 'Autor Uno', 'uno@test.local', 'x', 'editor', 'cln_primordial', '2026-09-01T00:00:00Z', '2026-09-01T00:00:00Z')")
    ->execute();

seedSpell($pdo, 'spl_val_rayo', 'Chispa Fulgurante', 'validated', ['elemental_affinity' => 'lightning', 'circle' => 1]);
seedSpell($pdo, 'spl_val_tierra', 'Púas Basálticas', 'validated', ['elemental_affinity' => 'earth', 'circle' => 4]);
seedSpell($pdo, 'spl_draft_uno', 'Borrador Privado', 'draft', ['author_id' => 'usr_autor_uno', 'circle' => 1]);

$authorOne = new User(
    id: 'usr_autor_uno',
    alias: 'Autor Uno',
    email: 'uno@test.local',
    role: 'editor',
    clanId: 'cln_primordial',
    passwordHash: 'x',
    createdAt: '2026-09-01T00:00:00Z',
    updatedAt: '2026-09-01T00:00:00Z',
);

// ---------------------------------------------------------------------
// Router real con las rutas del controlador bajo prueba.
// ---------------------------------------------------------------------
$router = new Router();
$controller = new GrimoireController(new GrimoireQueryService($pdo));
$router->addRoute('GET', '/api/v1/grimoire/spells', fn (Request $request): object => $controller->listSpells($request));
$router->addRoute('GET', '/api/v1/grimoire/spells/{id}', fn (Request $request, array $routeParams): object => $controller->showSpell($request, $routeParams));

// ---------------------------------------------------------------------
// [1] Catálogo canónico anónimo → 200 con carga JSON esperada.
// ---------------------------------------------------------------------
echo PHP_EOL . '[1] Catálogo canónico anónimo (RF-01.2)' . PHP_EOL;

$response = dispatchGet($router, '/api/v1/grimoire/spells');
assertCondition(statusCodeOf($response) === 200, 'GET /grimoire/spells anónimo responde 200 OK');
$payload = decodePayload($response);
assertCondition(($payload['success'] ?? null) === true, 'El sobre porta success=true');
$data = $payload['data'] ?? [];
assertCondition(isset($data['totalSpells'], $data['currentPage'], $data['totalPages'], $data['hasPrevious'], $data['hasNext'], $data['spells']), 'data porta el sobre de paginación íntegro del contrato');
assertCondition($data['totalSpells'] >= 5, 'El tomo canónico incluye génesis + validados sembrados');

$allValidated = true;
foreach ($data['spells'] as $spellPage) {
    if (($spellPage['status'] ?? '') !== 'validated') {
        $allValidated = false;
    }
}
assertCondition($allValidated, 'Todas las páginas entregadas están en estado validated');
assertCondition(isset($data['spells'][0]['incantationFormula'], $data['spells'][0]['effects']['damage']), 'La carga JSON porta la ficha litúrgica del contrato (fórmula + effects)');

// ---------------------------------------------------------------------
// [2] mode=essays sin sesión → 401 Unauthorized (RF-01.4).
// ---------------------------------------------------------------------
echo PHP_EOL . '[2] Ensayos sin sesión → 401' . PHP_EOL;

$response = dispatchGet($router, '/api/v1/grimoire/spells?mode=essays');
assertCondition(statusCodeOf($response) === 401, 'GET mode=essays sin sesión responde 401 Unauthorized');
$payload = decodePayload($response);
assertCondition(($payload['success'] ?? null) === false && ($payload['error']['code'] ?? '') === 'UNAUTHENTICATED', 'El sobre 401 porta el código canónico UNAUTHENTICATED');

// ---------------------------------------------------------------------
// [3] mode=essays autenticado → 200 con ensayos propios aislados.
// ---------------------------------------------------------------------
echo PHP_EOL . '[3] Ensayos autenticados con aislamiento (RF-01.4)' . PHP_EOL;

$response = dispatchGet($router, '/api/v1/grimoire/spells?mode=essays', $authorOne);
assertCondition(statusCodeOf($response) === 200, 'GET mode=essays con sesión responde 200 OK');
$payload = decodePayload($response);
$essays = $payload['data']['spells'] ?? [];
assertCondition(count($essays) === 1 && ($essays[0]['name'] ?? '') === 'Borrador Privado', 'El autor ve exactamente su borrador privado');
assertCondition(($essays[0]['status'] ?? '') === 'draft', 'El ensayo entregado conserva su estado draft');
assertCondition(($essays[0]['authorAlias'] ?? '') === 'Autor Uno', 'El alias del autor viaja en la página');
assertCondition($payload['data']['totalSpells'] === 1, 'El total del tomo de ensayos es coherente con el aislamiento');

// mode desconocido degrada al canónico (no explota, no filtra ensayos).
$response = dispatchGet($router, '/api/v1/grimoire/spells?mode=arcaneUnknown');
$payload = decodePayload($response);
$unknownModeStatuses = array_map(static fn (array $page): string => $page['status'], $payload['data']['spells'] ?? []);
assertCondition(statusCodeOf($response) === 200 && (empty($unknownModeStatuses) || !in_array('draft', $unknownModeStatuses, true)), 'Un mode desconocido degrada al tomo canónico sin filtrar borradores');

// ---------------------------------------------------------------------
// [4] Detalle litúrgico individual (plan Endpoint 2).
// ---------------------------------------------------------------------
echo PHP_EOL . '[4] Detalle litúrgico /spells/{id}' . PHP_EOL;

$response = dispatchGet($router, '/api/v1/grimoire/spells/spl_val_rayo');
assertCondition(statusCodeOf($response) === 200, 'GET detalle de validado responde 200 OK');
$payload = decodePayload($response);
assertCondition(($payload['data']['name'] ?? '') === 'Chispa Fulgurante' && ($payload['data']['elementalAffinity'] ?? '') === 'lightning', 'El detalle porta el conjuro con su afinidad');

$response = dispatchGet($router, '/api/v1/grimoire/spells/spl_draft_uno', $authorOne);
$payload = decodePayload($response);
assertCondition(statusCodeOf($response) === 200 && ($payload['data']['name'] ?? '') === 'Borrador Privado', 'El propietario autenticado puede consultar su borrador por detalle');

$response = dispatchGet($router, '/api/v1/grimoire/spells/spl_inexistente');
assertCondition(statusCodeOf($response) === 404, 'Un identificador inexistente responde 404 Not Found');

// El borrador ajeno/anónimo: un visitante sin sesión recibe 404 (aislamiento).
$response = dispatchGet($router, '/api/v1/grimoire/spells/spl_draft_uno');
assertCondition(statusCodeOf($response) === 404, 'El borrador consultado sin sesión responde 404 (aislamiento del titular)');

// ---------------------------------------------------------------------
// [5] Filtros circle/element y saneado de parámetros.
// ---------------------------------------------------------------------
echo PHP_EOL . '[5] Filtros y saneado de query (plan Test 3)' . PHP_EOL;

$response = dispatchGet($router, '/api/v1/grimoire/spells?circle=4&element=earth');
$payload = decodePayload($response);
$filtered = $payload['data']['spells'] ?? [];
assertCondition(count($filtered) === 1 && ($filtered[0]['name'] ?? '') === 'Púas Basálticas', 'El filtro combinado circle=4&element=earth es determinista');

$response = dispatchGet($router, '/api/v1/grimoire/spells?circle=veinte');
assertCondition(statusCodeOf($response) === 400, 'Un circle no numérico responde 400 Bad Request');

$response = dispatchGet($router, '/api/v1/grimoire/spells?circle=9');
assertCondition(statusCodeOf($response) === 400, 'Un circle fuera del canon (1-5) responde 400');

$response = dispatchGet($router, '/api/v1/grimoire/spells?limit=999');
$payload = decodePayload($response);
assertCondition(statusCodeOf($response) === 200 && count($payload['data']['spells'] ?? []) <= 50, 'Un limit desbordado se acota al máximo del contrato sin error');

$response = dispatchGet($router, '/api/v1/grimoire/spells?page=0');
$payload = decodePayload($response);
assertCondition(statusCodeOf($response) === 200 && ($payload['data']['currentPage'] ?? 0) === 1, 'Una página 0 o negativa degrada a la hoja 1 sin error');

// ---------------------------------------------------------------------
// Resumen final.
// ---------------------------------------------------------------------
echo PHP_EOL . '== RESUMEN ==' . PHP_EOL;
echo "Asertos superados: {$assertsPassed}" . PHP_EOL;
echo "Asertos fallidos:  {$assertsFailed}" . PHP_EOL;

if ($assertsFailed > 0) {
    echo PHP_EOL . 'RESULTADO: DENEGADO' . PHP_EOL;
    exit(1);
}
echo PHP_EOL . 'RESULTADO: EXITO — GrimoireController listo para el front controller (Tarea 1.3).' . PHP_EOL;
exit(0);
