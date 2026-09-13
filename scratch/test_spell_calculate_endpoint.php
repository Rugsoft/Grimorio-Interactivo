<?php

/**
 * test_spell_calculate_endpoint.php — Arnés TDD de la Tarea 4.1 (TASKS-04).
 *
 * Verifica el Endpoint 1 del plan (POST /api/v1/spells/calculate)
 * materializado en SpellCreatorController::calculate(Request):
 *   - HTTP 200 con el desglose pedagógico EXACTO del contrato
 *     (success/data con baseEffectPoints, multipliers, grossMana,
 *     discounts, netMana, finalManaCost, circle, circleLabel,
 *     isOverloaded).
 *   - HTTP 400 ante Sobrecarga Arcana con el sobre literal del plan
 *     (error.code ARCANE_OVERLOAD + calculatedMana).
 *   - HTTP 400 ante payloads corruptos (JSON inválido, campos ausentes,
 *     tipos sucios, valores fuera del canon).
 *   - Latencia determinista por debajo del umbral de 50 ms (RNF del
 *     criterio «Hecho cuando»).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response nativos del Core,
 *     json_decode/json_encode nativos, sin librerías HTTP.
 *   - Artículo II: el desglose lo genera SIEMPRE SpellBalanceService;
 *     el cliente jamás dicta el coste.
 *   - Artículo V: identificadores en inglés camelCase, leyendas y
 *     comentarios en castellano.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Dto/SpellCalculationInputDto.php';
require __DIR__ . '/../src/Dto/SpellCalculationResultDto.php';
require __DIR__ . '/../src/Exceptions/ArcaneOverloadException.php';
require __DIR__ . '/../src/Services/SpellBalanceService.php';
require __DIR__ . '/../src/Core/Response.php';
require __DIR__ . '/../src/Core/Request.php';
require __DIR__ . '/../src/Core/Router.php';
require __DIR__ . '/../src/Models/User.php';
require __DIR__ . '/../src/Controllers/SpellCreatorController.php';

use Grimorio\Core\Router;

use Grimorio\Controllers\SpellCreatorController;
use Grimorio\Core\Request;

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
 * Construye una petición POST con cuerpo JSON crudo inyectado (SAPI CLI).
 *
 * @param array<string, mixed>|string $payload Cuerpo como array (se serializa) o texto crudo.
 */
function forgeCalculateRequest(array|string $payload): Request
{
    $rawBody = is_string($payload) ? $payload : (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

    return new Request('POST', '/api/v1/spells/calculate', [], ['Content-Type' => 'application/json'], $rawBody);
}

/**
 * Payload canónico del plan (30/0/0, medium/sphere/instant, v+s).
 *
 * @return array<string, mixed>
 */
function canonicalPayload(): array
{
    return [
        'damage'           => 30,
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
 * Deserializa el cuerpo de una Response a array asociativo.
 *
 * @return array<string, mixed>
 */
function decodeBody(object $response): array
{
    $decoded = json_decode($response->getBody(), true);
    return is_array($decoded) ? $decoded : [];
}

echo "=== Tarea 4.1 (TASKS-04): Endpoint POST /api/v1/spells/calculate ===\n\n";

$controller = new SpellCreatorController();

// =====================================================================
// [0] Superficie (fase roja): controlador y método existen.
// =====================================================================
echo "[0] Superficie\n";
assertArcane(class_exists(SpellCreatorController::class), 'SpellCreatorController existe');
assertArcane(
    class_exists(SpellCreatorController::class) && method_exists(SpellCreatorController::class, 'calculate'),
    'SpellCreatorController expone calculate(Request)'
);

// =====================================================================
// [1] Camino feliz: HTTP 200 con el desglose EXACTO del contrato.
// =====================================================================
echo "\n[1] HTTP 200 — desglose pedagógico del Endpoint 1\n";

$validResponse = $controller->calculate(forgeCalculateRequest(canonicalPayload()));
$validBody     = decodeBody($validResponse);

assertArcane($validResponse->getStatusCode() === 200, 'Una petición válida responde HTTP 200');
assertArcane(($validBody['success'] ?? null) === true, 'El sobre porta success = true');

$data = $validBody['data'] ?? [];
assertArcane(($data['baseEffectPoints'] ?? 0) == 30.0, 'baseEffectPoints = 30.0 (daño 30 × peso 1.0)');
assertArcane(($data['multipliers']['range'] ?? 0) == 1.25, 'multipliers.range = 1.25 (medium)');
assertArcane(($data['multipliers']['area'] ?? 0) == 1.6, 'multipliers.area = 1.6 (sphere)');
assertArcane(($data['multipliers']['duration'] ?? 0) == 1.0, 'multipliers.duration = 1.0 (instant)');
assertArcane(($data['multipliers']['combined'] ?? 0) == 2.0, 'multipliers.combined = 2.0');
assertArcane(($data['grossMana'] ?? 0) == 60.0, 'grossMana = 60.0');
assertArcane(($data['discounts']['verbal'] ?? 0) == 0.10, 'discounts.verbal = 0.10');
assertArcane(($data['discounts']['somatic'] ?? 0) == 0.10, 'discounts.somatic = 0.10');
assertArcane(($data['discounts']['material'] ?? 0) == 0.0, 'discounts.material = 0.0');
assertArcane(($data['discounts']['totalPercent'] ?? 0) == 0.20, 'discounts.totalPercent = 0.20');
assertArcane(($data['discounts']['amountDeducted'] ?? 0) == 12.0, 'discounts.amountDeducted = 12.0');
assertArcane(($data['netMana'] ?? 0) == 48.0, 'netMana = 48.0');
assertArcane(($data['finalManaCost'] ?? 0) === 48, 'finalManaCost = 48 (entero)');
assertArcane(($data['circle'] ?? 0) === 3, 'circle = 3');
assertArcane(($data['circleLabel'] ?? '') === 'Círculo III (Magister)', 'circleLabel solemne en castellano');
assertArcane(($data['isOverloaded'] ?? true) === false, 'isOverloaded = false');

// Las claves del contrato viajan completas y en camelCase (plan 2.2).
$expectedKeys = ['baseEffectPoints', 'multipliers', 'grossMana', 'discounts', 'netMana', 'finalManaCost', 'circle', 'circleLabel', 'isOverloaded'];
assertArcane(
    array_keys($data) === $expectedKeys,
    'Las claves raíz del desglose coinciden EXACTAMENTE con el contrato (orden y camelCase)'
);

// =====================================================================
// [2] Sobrecarga Arcana: HTTP 400 con el sobre literal del plan.
// =====================================================================
echo "\n[2] HTTP 400 — Sobrecarga Arcana (> 200 de maná)\n";

$overloadPayload = canonicalPayload();
$overloadPayload['damage'] = 150;
$overloadPayload['rangeType'] = 'long';
$overloadResponse = $controller->calculate(forgeCalculateRequest($overloadPayload));
$overloadBody     = decodeBody($overloadResponse);

assertArcane($overloadResponse->getStatusCode() === 400, 'El coste > 200 responde HTTP 400');
assertArcane(($overloadBody['success'] ?? true) === false, 'El sobre porta success = false');
assertArcane(($overloadBody['error']['code'] ?? '') === 'ARCANE_OVERLOAD', 'error.code = ARCANE_OVERLOAD');
assertArcane(
    ($overloadBody['error']['message'] ?? '') === 'La concentración de poder supera la capacidad de contención del plano mortal (máx. 200 maná).',
    'error.message con la leyenda ceremonial literal'
);
assertArcane(
    is_int($overloadBody['error']['calculatedMana'] ?? null) && ($overloadBody['error']['calculatedMana']) > 200,
    'error.calculatedMana es entero > 200'
);

// =====================================================================
// [3] Payloads corruptos: HTTP 400 controlado, jamás un 500.
// =====================================================================
echo "\n[3] Defensas del contrato de entrada (400 con código canónico)\n";

$corruptBodies = [
    'JSON malformado'          => forgeCalculateRequest('{damage: 30,,}'),
    'Cuerpo vacío'             => forgeCalculateRequest(''),
    'Payload escalar'          => forgeCalculateRequest('42'),
    'Campo damage ausente'     => forgeCalculateRequest(array_diff_key(canonicalPayload(), ['damage' => 0])),
    'Modificador fuera de canon' => forgeCalculateRequest(array_merge(canonicalPayload(), ['rangeType' => 'galactic'])),
    'Daño negativo'            => forgeCalculateRequest(array_merge(canonicalPayload(), ['damage' => -5])),
    'Entero con decimales'     => forgeCalculateRequest(array_merge(canonicalPayload(), ['damage' => 10.5])),
    'Booleano sucio'           => forgeCalculateRequest(array_merge(canonicalPayload(), ['hasVerbal' => 'true'])),
];

$allCorruptHandled = true;
foreach ($corruptBodies as $legend => $corruptRequest) {
    $corruptResponse = $controller->calculate($corruptRequest);
    $corruptDecoded  = decodeBody($corruptResponse);
    $isControlled    = $corruptResponse->getStatusCode() === 400
        && ($corruptDecoded['success'] ?? true) === false
        && isset($corruptDecoded['error']['code'], $corruptDecoded['error']['message']);
    if (!$isControlled) {
        $allCorruptHandled = false;
        echo "       ↳ sin contrato en: {$legend} (HTTP {$corruptResponse->getStatusCode()})\n";
    }
}
assertArcane($allCorruptHandled, 'Los 8 payloads corruptos responden 400 con success/error.code/error.message');

// Muestra representativa de los códigos canónicos.
$missingResponse = $controller->calculate(forgeCalculateRequest(array_diff_key(canonicalPayload(), ['healing' => 0])));
$missingBody     = decodeBody($missingResponse);
assertArcane(
    ($missingBody['error']['code'] ?? '') === 'INVALID_SPELL_INPUT' && str_contains((string) ($missingBody['error']['message'] ?? ''), 'healing'),
    'Campo ausente → INVALID_SPELL_INPUT con leyenda castellana del campo'
);

// =====================================================================
// [4] Latencia: el cálculo determinista responde en < 50 ms.
// =====================================================================
echo "\n[4] Latencia del cálculo (RNF del criterio «Hecho cuando»)\n";

$latencyStart    = hrtime(true);
$warmupResponse  = $controller->calculate(forgeCalculateRequest(canonicalPayload()));
$latencyStart    = hrtime(true); // Se re-mide tras el calentamiento de autoload/opcache.
$iterations      = 100;
for ($i = 0; $i < $iterations; $i++) {
    $controller->calculate(forgeCalculateRequest(canonicalPayload()));
}
$averageMs = ((hrtime(true) - $latencyStart) / 1e6) / $iterations;
$firstMs   = null;

assertArcane($warmupResponse->getStatusCode() === 200, 'El calentamiento responde 200');
assertArcane($averageMs < 50.0, sprintf('Latencia media de %.3f ms por cálculo (< 50 ms, %d iteraciones)', $averageMs, $iterations));

// =====================================================================
// [5] Despacho HTTP real: la ruta registrada en el Router responde el
//     contrato íntegro (llamada end-to-end al Front Controller).
// =====================================================================
echo "\n[5] Despacho por el Router (ruta POST /api/v1/spells/calculate)\n";

$router = new Router();
$spellCreatorController = new SpellCreatorController();
$router->addRoute('POST', '/api/v1/spells/calculate', fn (Request $request): object => $spellCreatorController->calculate($request));

$routedResponse = $router->dispatch(forgeCalculateRequest(canonicalPayload()));
$routedBody = decodeBody($routedResponse);
assertArcane($routedResponse->getStatusCode() === 200 && ($routedBody['data']['finalManaCost'] ?? 0) === 48, 'La ruta POST /api/v1/spells/calculate despacha el desglose canónico (200, maná 48)');

$overloadPayload['damage'] = 150;
$overloadPayload['rangeType'] = 'long';
$routedOverloadResponse = $router->dispatch(forgeCalculateRequest($overloadPayload));
assertArcane($routedOverloadResponse->getStatusCode() === 400, 'Por el Router, la sobrecarga también responde 400 (ARCANE_OVERLOAD)');

// =====================================================================
// Resumen final.
// =====================================================================
echo "\n=== RESUMEN: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);
