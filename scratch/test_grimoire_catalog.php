<?php

/**
 * test_grimoire_catalog.php — Arnés de integración del catálogo del Tomo
 * Arcano (Tarea 1.4, TASKS-05; Plan Sec. 6.1).
 *
 * Ejecuta la batería canónica de los 4 tests del plan sobre el stack
 * completo del backend (Router → controlador → servicio → DTO) con la
 * resolución de sesión de AuthMiddleware:
 *   Test 1: Catálogo Canónico — GET /api/v1/grimoire/spells responde 200
 *           con conjuros `validated` exclusivamente.
 *   Test 2: Aislamiento de Ensayos — mode=essays sin sesión → 401; con
 *           sesión de autor → sus borradores/experimentales exactos.
 *   Test 3: Filtro por Círculo y Afinidad — ?circle=2&element=fire filtra
 *           con precisión determinista (además de variantes de borde).
 *   Test 4: Estructura de DTO Litúrgico — cada página porta su fórmula en
 *           castellano, componentes y geometría sin campos nulos.
 *
 * Criterio «Hecho cuando» (Tarea 1.4): la ejecución
 * `php scratch/test_grimoire_catalog.php` pasa el 100% de los asertos de
 * integración con código de salida 0.
 *
 * Constitución:
 *   - Artículo I: stack nativo del proyecto, SQLite en memoria con el
 *     esquema y seeds reales del santuario.
 *   - Artículo III: aislamiento de ensayos por titular verificado.
 *   - Artículo V: identificadores camelCase, leyendas castellanas.
 *
 * Uso: php scratch/test_grimoire_catalog.php
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
require __DIR__ . '/../src/Repositories/GrimoireCollectionRepository.php';
require __DIR__ . '/../src/Services/GrimoireQueryService.php';
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

/** Código HTTP de una Response (getter público). */
function statusCodeOf(object $response): int
{
    return $response->getStatusCode();
}

/** Despacha una petición GET por el Router real con usuario de sesión opcional. */
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

/**
 * Siembra un conjuro de prueba (fila mínima viable del esquema real).
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
        'math_fingerprint'    => str_repeat('c', 64),
        'clan_id'             => 'cln_primordial',
        'summary'             => 'Resumen de ' . $name,
        'description'         => 'Descripción completa de ' . $name,
        'components_verbal'   => '¡Fórmula de ' . $name . '!',
        'components_somatic'  => 'Gesto solemne de ' . $name,
        'components_material' => '',
        'damage'              => 10,
        'healing'             => 0,
        'barrier'             => 0,
        'crowd_control_type'  => 'none',
        'range_type'          => 'medium',
        'area_type'           => 'singleTarget',
        'duration_type'       => 'instant',
        'has_verbal'          => 1,
        'has_somatic'         => 1,
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

echo '== ARNES DE INTEGRACION: catalogo del Tomo Arcano (Tarea 1.4, TASKS-05) ==' . PHP_EOL;

// ---------------------------------------------------------------------
// Banco de datos en memoria con esquema y seeds reales + data de prueba.
// ---------------------------------------------------------------------
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));
$pdo->exec((string) file_get_contents(__DIR__ . '/../database/seeds.sql'));

$pdo->prepare("INSERT OR IGNORE INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
               VALUES ('usr_autor_fuego', 'Archimago Ignis', 'ignis@test.local', 'x', 'editor', 'cln_primordial', '2026-09-01T00:00:00Z', '2026-09-01T00:00:00Z')")
    ->execute();
$pdo->prepare("INSERT OR IGNORE INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
               VALUES ('usr_autor_agua', 'Dama Marea', 'marea@test.local', 'x', 'editor', 'cln_primordial', '2026-09-01T00:00:00Z', '2026-09-01T00:00:00Z')")
    ->execute();

seedSpell($pdo, 'spl_canon_fuego_a', 'Llamas del Alba', 'validated', ['elemental_affinity' => 'fire', 'circle' => 2, 'author_id' => 'usr_autor_fuego']);
seedSpell($pdo, 'spl_canon_fuego_b', 'Brazas del Ocaso', 'validated', ['elemental_affinity' => 'fire', 'circle' => 2, 'author_id' => 'usr_autor_fuego']);
seedSpell($pdo, 'spl_canon_agua', 'Marea Nocturna', 'validated', ['elemental_affinity' => 'water', 'circle' => 3, 'author_id' => 'usr_autor_agua']);
seedSpell($pdo, 'spl_essay_draft', 'Boceto de Ascua', 'draft', ['author_id' => 'usr_autor_fuego', 'circle' => 1]);
seedSpell($pdo, 'spl_essay_exp', 'Ensayo Ígneo', 'experimental', ['author_id' => 'usr_autor_fuego', 'circle' => 1, 'elemental_affinity' => 'fire']);
seedSpell($pdo, 'spl_essay_ajeno', 'Boceto Ajeno', 'draft', ['author_id' => 'usr_autor_agua', 'circle' => 1]);

$authorFuego = new User(
    id: 'usr_autor_fuego',
    alias: 'Archimago Ignis',
    email: 'ignis@test.local',
    role: 'editor',
    clanId: 'cln_primordial',
    passwordHash: 'x',
    createdAt: '2026-09-01T00:00:00Z',
    updatedAt: '2026-09-01T00:00:00Z',
);

$router = new Router();
$controller = new GrimoireController(new GrimoireQueryService($pdo));
$router->addRoute('GET', '/api/v1/grimoire/spells', fn (Request $request): object => $controller->listSpells($request));
$router->addRoute('GET', '/api/v1/grimoire/spells/{id}', fn (Request $request, array $routeParams): object => $controller->showSpell($request, $routeParams));

// ---------------------------------------------------------------------
// TEST 1 (plan 6.1): Catálogo Canónico — 200 con `validated` exclusivamente.
// ---------------------------------------------------------------------
echo PHP_EOL . 'TEST 1: Catalogo Canonico' . PHP_EOL;

$response = dispatchGet($router, '/api/v1/grimoire/spells');
assertCondition(statusCodeOf($response) === 200, 'GET /api/v1/grimoire/spells responde HTTP 200 (plan Test 1)');
$payload = decodePayload($response);
assertCondition(($payload['success'] ?? null) === true, 'El sobre porta success=true');
$data = $payload['data'] ?? [];

$allValidated = true;
foreach ($data['spells'] ?? [] as $spellPage) {
    if (($spellPage['status'] ?? '') !== 'validated') {
        $allValidated = false;
    }
}
assertCondition($allValidated && count($data['spells'] ?? []) > 0, 'Todas las páginas entregadas son `validated` exclusivamente (plan Test 1)');
assertCondition($data['totalSpells'] === count($data['spells'] ?? []), 'El total del sobre coincide con las hojas de la primera página');

$publicNames = array_map(static fn (array $page): string => $page['name'], $data['spells'] ?? []);
assertCondition(!in_array('Boceto de Ascua', $publicNames, true) && !in_array('Ensayo Ígneo', $publicNames, true) && !in_array('Boceto Ajeno', $publicNames, true), 'Ningún ensayo (ni propio ni ajeno) se filtra al tomo público');

// ---------------------------------------------------------------------
// TEST 2 (plan 6.1): Aislamiento de Ensayos — 401 anónimo, 200 titular.
// ---------------------------------------------------------------------
echo PHP_EOL . 'TEST 2: Aislamiento de Ensayos' . PHP_EOL;

$response = dispatchGet($router, '/api/v1/grimoire/spells?mode=essays');
assertCondition(statusCodeOf($response) === 401, 'mode=essays sin sesión de usuario devuelve HTTP 401 (plan Test 2)');
$payload = decodePayload($response);
assertCondition(($payload['error']['code'] ?? '') === 'UNAUTHENTICATED', 'El 401 porta el código canónico UNAUTHENTICATED');

$response = dispatchGet($router, '/api/v1/grimoire/spells?mode=essays', $authorFuego);
assertCondition(statusCodeOf($response) === 200, 'mode=essays con sesión de autor responde 200');
$payload = decodePayload($response);
$essays = $payload['data']['spells'] ?? [];
$essayNames = array_map(static fn (array $page): string => $page['name'], $essays);
assertCondition(count($essays) === 2 && in_array('Boceto de Ascua', $essayNames, true) && in_array('Ensayo Ígneo', $essayNames, true), 'El autor ve exactamente sus ensayos draft + experimental');
assertCondition(!in_array('Boceto Ajeno', $essayNames, true), 'El borrador del autor ajeno jamás cruza el aislamiento (Artículo III)');
$essayStatuses = array_map(static fn (array $page): string => $page['status'], $essays);
assertCondition($essayStatuses === ['draft', 'experimental'], 'Los estados entregados son draft y experimental exclusivamente');

// ---------------------------------------------------------------------
// TEST 3 (plan 6.1): Filtro por Círculo y Afinidad — determinismo exacto.
// ---------------------------------------------------------------------
echo PHP_EOL . 'TEST 3: Filtro por Circulo y Afinidad' . PHP_EOL;

$response = dispatchGet($router, '/api/v1/grimoire/spells?circle=2&element=fire');
$payload = decodePayload($response);
$filtered = $payload['data']['spells'] ?? [];
assertCondition(statusCodeOf($response) === 200 && $payload['data']['totalSpells'] === 2, '?circle=2&element=fire filtra con precisión determinista (plan Test 3)');
$filteredNames = array_map(static fn (array $page): string => $page['name'], $filtered);
assertCondition(in_array('Llamas del Alba', $filteredNames, true) && in_array('Brazas del Ocaso', $filteredNames, true), 'El filtro combinado entrega exactamente los 2 validados ígneos de Círculo II');

$filteredCircles = array_map(static fn (array $page): int => $page['circle'], $filtered);
$filteredElements = array_map(static fn (array $page): string => $page['elementalAffinity'], $filtered);
assertCondition(array_unique($filteredCircles) === [2] && array_unique($filteredElements) === ['fire'], 'Todas las páginas filtradas respetan círculo y afinidad');

$response = dispatchGet($router, '/api/v1/grimoire/spells?circle=2&element=water');
$payload = decodePayload($response);
assertCondition(statusCodeOf($response) === 200 && ($payload['data']['totalSpells'] ?? -1) === 0, 'Un filtro sin resultados entrega pergamino vacío con totalSpells=0');

$response = dispatchGet($router, '/api/v1/grimoire/spells?circle=7');
assertCondition(statusCodeOf($response) === 400, 'Un círculo fuera del canon 1-5 responde 400 Bad Request');

// ---------------------------------------------------------------------
// TEST 4 (plan 6.1): Estructura de DTO Litúrgico — sin campos nulos.
// ---------------------------------------------------------------------
echo PHP_EOL . 'TEST 4: Estructura de DTO Liturgico' . PHP_EOL;

$response = dispatchGet($router, '/api/v1/grimoire/spells?limit=50');
$payload = decodePayload($response);
$allPages = $payload['data']['spells'] ?? [];
assertCondition(count($allPages) >= 5, 'La batería litúrgica cubre todo el tomo canónico sembrado');

$contractKeys = [
    'id', 'slug', 'name', 'magicSchool', 'elementalAffinity', 'circle',
    'manaCost', 'castingTime', 'incantationFormula',
    'hasVerbal', 'hasSomatic', 'hasMaterial',
    'rangeType', 'areaType', 'durationType',
    'effects', 'description', 'authorAlias', 'clanName', 'status',
];
$structureOk = true;
$noNulls = true;
$incantationInSpanish = true;
foreach ($allPages as $spellPage) {
    if (array_keys($spellPage) !== $contractKeys) {
        $structureOk = false;
    }
    foreach ($spellPage as $value) {
        if ($value === null) {
            $noNulls = false;
        }
    }
    if (!isset($spellPage['effects']['damage'], $spellPage['effects']['healing'], $spellPage['effects']['barrier'], $spellPage['effects']['crowdControlType'])) {
        $structureOk = false;
    }
    if (!is_string($spellPage['incantationFormula'] ?? null)) {
        $incantationInSpanish = false;
    }
}
assertCondition($structureOk, 'Cada página porta EXACTAMENTE las 20 claves camelCase del contrato (con effects anidado)');
assertCondition($noNulls, 'Ninguna página porta campos nulos (plan Test 4)');
assertCondition($incantationInSpanish, 'La fórmula litúrgica viaja como texto en cada página (declamación RF-04.2)');

$geometryOk = true;
foreach ($allPages as $spellPage) {
    if (!in_array($spellPage['areaType'], ['singleTarget', 'cone', 'line', 'sphere'], true)
        || !in_array($spellPage['rangeType'], ['touch', 'short', 'medium', 'long'], true)) {
        $geometryOk = false;
    }
}
assertCondition($geometryOk, 'Componentes y geometría viajan dentro del canon del santuario');

// La autoría y el linaje se resuelven en todas las páginas (JOIN del servicio).
$authorshipOk = true;
foreach ($allPages as $spellPage) {
    if ($spellPage['authorAlias'] === '' || $spellPage['clanName'] === '') {
        $authorshipOk = false;
    }
}
assertCondition($authorshipOk, 'Autoría y linaje resueltos en todas las páginas (sin ghost authors)');

// ---------------------------------------------------------------------
// Resumen final (criterio: 100% de asertos, exit 0).
// ---------------------------------------------------------------------
echo PHP_EOL . '== RESUMEN ==' . PHP_EOL;
echo "Asertos superados: {$assertsPassed}" . PHP_EOL;
echo "Asertos fallidos:  {$assertsFailed}" . PHP_EOL;

if ($assertsFailed > 0) {
    echo PHP_EOL . 'RESULTADO: DENEGADO' . PHP_EOL;
    exit(1);
}
echo PHP_EOL . 'RESULTADO: EXITO — Los 4 tests del plan 6.1 pasan al 100% (Tarea 1.4).' . PHP_EOL;
exit(0);
