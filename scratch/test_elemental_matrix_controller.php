<?php

/**
 * test_elemental_matrix_controller.php — Arnés TDD de la Tarea 1.4 (TASKS-06).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica el ElementalMatrixController por HTTP real (despacho por el
 * Router nativo del proyecto), sin base de datos: la matriz es un dato
 * en memoria del servicio (Tarea 1.2 / 1.3):
 *
 *   [1] GET /api/v1/elements/matrix            → 200 con el sobre
 *       {success, data:{elements, reactions}} del Endpoint 1 (8 nodos +
 *       8 aristas), Content-Type JSON.
 *   [2] GET /api/v1/elements/reactions/fire    → 200 con las aristas de
 *       Fuego (Vaporización y Deflagración).
 *   [3] GET .../reactions/pureArcane           → 200 con lista vacía
 *       (catalizador unario sin aristas duales).
 *   [4] GET .../reactions/steam                → 404 ELEMENT_NOT_FOUND.
 *   [5] POST /api/v1/elements/resolve-combo    → 200 con las once claves
 *       del Endpoint 3 (ceil(40×1.5)=60), tanto fire+water como water+fire
 *       (simetría A+B = B+A atravesando el HTTP completo).
 *   [6] POST resolve-combo con payload corrupto/ausente → 400.
 *   [7] POST resolve-combo con campos inválidos (daño negativo, elemento
 *       vacío, tipos erróneos) → 400 con sobre de error controlado.
 *   [8] Verbos erróneos (PUT sobre matrix, GET sobre resolve-combo) → 405
 *       Method Not Allowed del Router (la ruta existe con otro verbo);
 *       rutas desconocidas → 404.
 *   [9] Cero advertencias de PHP en toda la batería.
 *
 * Criterio «Hecho cuando» (Tarea 1.4): las peticiones HTTP a
 * /api/v1/elements/matrix y /api/v1/elements/resolve-combo responden con
 * código HTTP 200 y el JSON del contrato técnico.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response/Router nativos del
 *     proyecto; cero librerías.
 *   - AGENTS.md 6.1: errores en sobres JSON controlados, jamás trazas.
 *   - Artículo V: identificadores camelCase; leyendas en noble castellano.
 *
 * Uso: php scratch/test_elemental_matrix_controller.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../src/Dto/ElementalReactionDto.php';
require __DIR__ . '/../src/Dto/ElementalMatrixGraphDto.php';
require __DIR__ . '/../src/Dto/ComboResolutionResultDto.php';
require __DIR__ . '/../src/Dto/SpellImpactData.php';
require __DIR__ . '/../src/Core/Response.php';
require __DIR__ . '/../src/Core/Request.php';
require __DIR__ . '/../src/Core/Router.php';
require __DIR__ . '/../src/Services/ElementalMatrixService.php';
require __DIR__ . '/../src/Controllers/ElementalMatrixController.php';

use Grimorio\Controllers\ElementalMatrixController;
use Grimorio\Core\Request;
use Grimorio\Core\Router;
use Grimorio\Services\ElementalMatrixService;

$assertionsPassed = 0;
$assertionsFailed = 0;
/** @var list<string> */
$failures = [];
/** @var list<string> */
$warnings = [];

set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$warnings): bool {
    $warnings[] = "{$message} (en {$file}:{$line})";
    return true;
});

function assert_truthy(bool $condition, string $label): void
{
    global $assertionsPassed, $assertionsFailed, $failures;
    if ($condition) {
        $assertionsPassed++;
        echo "  [OK]  {$label}\n";
        return;
    }
    $assertionsFailed++;
    $failures[] = $label;
    echo "  [FALLA] {$label}\n";
}

/**
 * Despacha una petición por el Router real del proyecto.
 *
 * @param array<string, string> $queryParams
 * @param array<string, string> $headers
 */
function dispatch(Router $router, string $method, string $uri, array $queryParams = [], array $headers = [], ?string $rawBody = null): object
{
    $path = (string) (parse_url($uri, PHP_URL_PATH) ?: $uri);
    $request = new Request($method, $path, $queryParams, $headers, $rawBody);

    return $router->dispatch($request);
}

/** Sobre JSON decodificado del cuerpo de una Response. */
function decodePayload(object $response): array
{
    $decoded = json_decode($response->getBody(), true);
    return is_array($decoded) ? $decoded : [];
}

echo "== ARNÉS TDD — CONTROLADOR REST DE LA MATRIZ ELEMENTAL (Tarea 1.4, SPEC-06) ==\n";

// --- Router real con las tres rutas del contrato (fase roja: la clase no existe) ---
$router = new Router();
$elementalMatrixController = new ElementalMatrixController(new ElementalMatrixService());
$router->addRoute('GET', '/api/v1/elements/matrix', fn ($request) => $elementalMatrixController->getMatrix($request));
$router->addRoute('GET', '/api/v1/elements/reactions/{element}', fn ($request, array $routeParams) => $elementalMatrixController->getElementReactions($request, $routeParams));
$router->addRoute('POST', '/api/v1/elements/resolve-combo', fn ($request) => $elementalMatrixController->resolveCombo($request));

// ---------------------------------------------------------------------------
echo "\n[FASE 1] GET /api/v1/elements/matrix — el Códice completo (Endpoint 1)\n";
// ---------------------------------------------------------------------------

$matrixResponse = dispatch($router, 'GET', '/api/v1/elements/matrix');
assert_truthy($matrixResponse->getStatusCode() === 200, 'GET /api/v1/elements/matrix responde 200 (fase roja si la clase no existe)');
assert_truthy(
    str_contains((string) $matrixResponse->getHeader('Content-Type'), 'application/json'),
    'El Content-Type es application/json; charset=utf-8',
);
$matrixPayload = decodePayload($matrixResponse);
assert_truthy(($matrixPayload['success'] ?? null) === true, 'El sobre porta success: true');
assert_truthy(
    isset($matrixPayload['data']['elements']) && is_array($matrixPayload['data']['elements']) && count($matrixPayload['data']['elements']) === 8,
    'El grafo porta los 8 elementos canónicos',
);
assert_truthy(
    isset($matrixPayload['data']['reactions']) && is_array($matrixPayload['data']['reactions']) && count($matrixPayload['data']['reactions']) === 8,
    'El grafo porta las 8 aristas reactivas (7 duales + catalizador)',
);
$fireNode = $matrixPayload['data']['elements'][0] ?? [];
assert_truthy(
    ($fireNode['id'] ?? '') === 'fire' && ($fireNode['name'] ?? '') === 'Fuego' && ($fireNode['color'] ?? '') === '#ff4500' && ($fireNode['glyph'] ?? '') === 'rune-ignis',
    'El nodo de Fuego porta su heráldica exacta (nombre litúrgico, color, glifo)',
);
$vaporization = null;
foreach ($matrixPayload['data']['reactions'] as $reaction) {
    if (($reaction['id'] ?? '') === 'arcaneVaporization') {
        $vaporization = $reaction;
    }
}
assert_truthy($vaporization !== null && ($vaporization['damageMultiplier'] ?? 0) === 1.5, 'La Vaporización Arcana viaja con su factor ×1.5 preservado (JSON_PRESERVE_ZERO_FRACTION)');
assert_truthy($vaporization !== null && ($vaporization['name'] ?? '') === 'Vaporización Arcana', 'Los nombres litúrgicos viajan en castellano, sin escapar (JSON_UNESCAPED_UNICODE)');

// ---------------------------------------------------------------------------
echo "\n[FASE 2] GET /api/v1/elements/reactions/{element} — aristas por glifo (Endpoint 2)\n";
// ---------------------------------------------------------------------------

$fireReactionsResponse = dispatch($router, 'GET', '/api/v1/elements/reactions/fire');
assert_truthy($fireReactionsResponse->getStatusCode() === 200, 'GET /api/v1/elements/reactions/fire responde 200');
$firePayload = decodePayload($fireReactionsResponse);
$fireIds = array_map(static fn (array $r): string => (string) ($r['id'] ?? ''), $firePayload['data'] ?? []);
sort($fireIds);
assert_truthy($fireIds === ['arcaneVaporization', 'vortexDeflagration'], 'Las aristas de Fuego son la Vaporización Arcana y la Deflagración en Vórtice');

$waterReactionsResponse = dispatch($router, 'GET', '/api/v1/elements/reactions/water');
$waterCount = count(decodePayload($waterReactionsResponse)['data'] ?? []);
assert_truthy($waterReactionsResponse->getStatusCode() === 200 && $waterCount === 4, 'Agua declara sus 4 aristas (Fuego, Rayo, Tierra y Viento)');

$pureReactionsResponse = dispatch($router, 'GET', '/api/v1/elements/reactions/pureArcane');
$purePayload = decodePayload($pureReactionsResponse);
assert_truthy($pureReactionsResponse->getStatusCode() === 200 && ($purePayload['data'] ?? null) === [], 'Arcano Puro responde 200 con lista vacía (catalizador sin aristas duales)');

$unknownReactionsResponse = dispatch($router, 'GET', '/api/v1/elements/reactions/steam');
$unknownPayload = decodePayload($unknownReactionsResponse);
assert_truthy($unknownReactionsResponse->getStatusCode() === 404, 'Un elemento ajeno al canon responde 404');
assert_truthy(($unknownPayload['error']['code'] ?? '') === 'ELEMENT_NOT_FOUND', 'El 404 porta el código ELEMENT_NOT_FOUND');
assert_truthy(($unknownPayload['success'] ?? null) === false, 'El sobre de error declara success: false');

// ---------------------------------------------------------------------------
echo "\n[FASE 3] POST /api/v1/elements/resolve-combo — veredicto (Endpoint 3)\n";
// ---------------------------------------------------------------------------

$comboPayload = static fn (string $activeAura, string $element, int $baseDamage): string => (string) json_encode(
    ['activeAura' => $activeAura, 'incomingSpell' => ['id' => 'spl_probe_01', 'element' => $element, 'baseDamage' => $baseDamage, 'baseHealing' => 0, 'baseBarrier' => 0, 'crowdControlType' => 'none']],
    JSON_UNESCAPED_UNICODE,
);

$comboHeaders = ['Content-Type' => 'application/json'];

$comboResponse = dispatch($router, 'POST', '/api/v1/elements/resolve-combo', [], $comboHeaders, $comboPayload('fire', 'water', 40));
assert_truthy($comboResponse->getStatusCode() === 200, 'POST resolve-combo (fire + water) responde 200');
$comboData = decodePayload($comboResponse)['data'] ?? [];
assert_truthy(
    array_keys($comboData) === ['isReaction', 'reactionId', 'reactionName', 'effectiveDamage', 'damageMultiplierApplied', 'tacticalEffectApplied', 'effectDurationMs', 'clearedAura', 'resultingAura', 'stunlockTriggered', 'grantStunlockImmunity'],
    'El veredicto porta las once claves del Endpoint 3, en el orden canónico',
);
assert_truthy(($comboData['reactionId'] ?? '') === 'arcaneVaporization' && ($comboData['effectiveDamage'] ?? 0) === 60, 'ceil(40 × 1.5) = 60 con la Vaporización Arcana detonada');

$symmetricResponse = dispatch($router, 'POST', '/api/v1/elements/resolve-combo', [], $comboHeaders, $comboPayload('water', 'fire', 40));
$symmetricData = decodePayload($symmetricResponse)['data'] ?? [];
assert_truthy($symmetricResponse->getStatusCode() === 200 && ($symmetricData['reactionId'] ?? '') === 'arcaneVaporization' && ($symmetricData['effectiveDamage'] ?? 0) === 60, 'La orientación inversa (water + fire) responde idéntico veredicto (simetría A+B = B+A)');

$catalystResponse = dispatch($router, 'POST', '/api/v1/elements/resolve-combo', [], $comboHeaders, $comboPayload('earth', 'pureArcane', 40));
$catalystData = decodePayload($catalystResponse)['data'] ?? [];
assert_truthy(($catalystData['reactionId'] ?? '') === 'pureArcaneResonance' && ($catalystData['effectiveDamage'] ?? 0) === 50, 'El catalizador amplifica ceil(40 × 1.25) = 50 (Resonancia Arcana Pura)');

$neutralResponse = dispatch($router, 'POST', '/api/v1/elements/resolve-combo', [], $comboHeaders, $comboPayload('', 'light', 40));
$neutralData = decodePayload($neutralResponse)['data'] ?? [];
assert_truthy(($neutralData['resultingAura'] ?? '') === 'light' && ($neutralData['effectiveDamage'] ?? 0) === 40, 'Sobre blanco neutral, el conjuro imbuye su aura con daño íntegro');

// ---------------------------------------------------------------------------
echo "\n[FASE 4] Saneado del payload — 400 ante basura\n";
// ---------------------------------------------------------------------------

$corruptResponse = dispatch($router, 'POST', '/api/v1/elements/resolve-combo', [], $comboHeaders, '{corrupto');
$corruptPayload = decodePayload($corruptResponse);
assert_truthy($corruptResponse->getStatusCode() === 400, 'Un cuerpo JSON corrupto responde 400');
assert_truthy(($corruptPayload['error']['code'] ?? '') === 'INVALID_REQUEST_BODY', 'El sobre 400 porta el código INVALID_REQUEST_BODY');

$missingResponse = dispatch($router, 'POST', '/api/v1/elements/resolve-combo', [], $comboHeaders, null);
assert_truthy($missingResponse->getStatusCode() === 400, 'Un cuerpo ausente responde 400');

$negativeResponse = dispatch($router, 'POST', '/api/v1/elements/resolve-combo', [], $comboHeaders, (string) json_encode(['activeAura' => 'fire', 'incomingSpell' => ['id' => 'x', 'element' => 'water', 'baseDamage' => -5]], JSON_UNESCAPED_UNICODE));
assert_truthy($negativeResponse->getStatusCode() === 400, 'El daño base negativo responde 400');

$badElementResponse = dispatch($router, 'POST', '/api/v1/elements/resolve-combo', [], $comboHeaders, (string) json_encode(['activeAura' => 'fire', 'incomingSpell' => ['id' => 'x', 'element' => 'steam', 'baseDamage' => 10]], JSON_UNESCAPED_UNICODE));
assert_truthy($badElementResponse->getStatusCode() === 400, 'Un elemento ajeno al canon responde 400');

$badAuraResponse = dispatch($router, 'POST', '/api/v1/elements/resolve-combo', [], $comboHeaders, (string) json_encode(['activeAura' => 'steam', 'incomingSpell' => ['id' => 'x', 'element' => 'water', 'baseDamage' => 10]], JSON_UNESCAPED_UNICODE));
assert_truthy($badAuraResponse->getStatusCode() === 400, 'Una aura activa ajena al canon responde 400');

$badStunlockResponse = dispatch($router, 'POST', '/api/v1/elements/resolve-combo', [], $comboHeaders, (string) json_encode(['activeAura' => 'fire', 'stunlockImmune' => 'sí', 'incomingSpell' => ['id' => 'x', 'element' => 'water', 'baseDamage' => 10]], JSON_UNESCAPED_UNICODE));
assert_truthy($badStunlockResponse->getStatusCode() === 400, 'Un stunlockImmune no booleano responde 400');

// ---------------------------------------------------------------------------
echo "\n[FASE 5] Verbos erróneos y cero advertencias (Dogma Vanilla)\n";
// ---------------------------------------------------------------------------

$wrongVerbResponse = dispatch($router, 'GET', '/api/v1/elements/resolve-combo');
assert_truthy($wrongVerbResponse->getStatusCode() === 405, 'GET sobre resolve-combo responde 405 Method Not Allowed (la ruta existe con otro verbo)');
assert_truthy((decodePayload($wrongVerbResponse)['error']['code'] ?? '') === 'METHOD_NOT_ALLOWED', 'El 405 porta el código METHOD_NOT_ALLOWED');

$unknownRouteResponse = dispatch($router, 'GET', '/api/v1/elements/unknown');
assert_truthy($unknownRouteResponse->getStatusCode() === 404, 'Una ruta desconocida responde 404');

assert_truthy($warnings === [], 'La batería completa corre sin ninguna advertencia de PHP');

// ---------------------------------------------------------------------------
echo "\n== RESUMEN ==\n";
echo "Asertos superados: {$assertionsPassed}, fallidos: {$assertionsFailed}\n";

if ($assertionsFailed > 0) {
    echo "\nFALLOS:\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
    echo "\nRESULTADO: FRACASO — El controlador REST aún no cumple el contrato de la Tarea 1.4.\n";
    exit(1);
}

echo "\nRESULTADO: ÉXITO — El controlador REST sirve el Códice y el veredicto según el contrato del plan (Tarea 1.4).\n";
exit(0);
