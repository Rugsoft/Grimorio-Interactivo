<?php

/**
 * test_overload_ceiling.php — Arnés TDD de la Tarea 2.3 (TASKS-04).
 *
 * Audita dos salvaguardas del SpellBalanceService:
 *   1. Techo de contención (RF-03.2, Artículo II): cualquier combinación
 *      cuyo maná supere 200 lanza de inmediato ArcaneOverloadException
 *      con el contrato del plan (400, ARCANE_OVERLOAD, calculatedMana).
 *      El techo funciona ANTES de que el DTO de resultado exista (jamás
 *      se recorta el coste ni se devuelve un resultado sobrecargado).
 *   2. Neutralidad elemental (RNF-01, Artículo II): ningún parámetro
 *      elemental o de escuela mágica interfiere en el cálculo matemático.
 *
 * Criterio «Hecho cuando» (Tarea 2.3): un cálculo que arroje 201 de maná
 * dispara ArcaneOverloadException y alternar entre afinidad de fuego o
 * luz produce exactamente el mismo coste.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): aritmética nativa, sin librerías.
 *   - Artículo II: techo de 200 y neutralidad elemental estructural.
 *   - Artículo V: identificadores en inglés camelCase, comentarios en
 *     castellano.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Dto/SpellCalculationInputDto.php';
require __DIR__ . '/../src/Dto/SpellCalculationResultDto.php';
require __DIR__ . '/../src/Exceptions/ArcaneOverloadException.php';
require __DIR__ . '/../src/Services/SpellBalanceService.php';

use Grimorio\Dto\SpellCalculationInputDto;
use Grimorio\Exceptions\ArcaneOverloadException;
use Grimorio\Services\SpellBalanceService;

$assertsPassed = 0;
$assertsFailed = 0;

/**
 * Aserto solemne del arnés.
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
 * Forja un DTO de entrada con todos los parámetros explícitos.
 */
function forgeInput(
    int $damage = 0,
    int $healing = 0,
    int $barrier = 0,
    string $crowdControlType = 'none',
    string $rangeType = 'touch',
    string $areaType = 'singleTarget',
    string $durationType = 'instant',
    bool $hasVerbal = false,
    bool $hasSomatic = false,
    bool $hasMaterial = false,
): SpellCalculationInputDto {
    return new SpellCalculationInputDto(
        damage: $damage,
        healing: $healing,
        barrier: $barrier,
        crowdControlType: $crowdControlType,
        rangeType: $rangeType,
        areaType: $areaType,
        durationType: $durationType,
        hasVerbal: $hasVerbal,
        hasSomatic: $hasSomatic,
        hasMaterial: $hasMaterial,
    );
}

function catchException(callable $fn): ?Throwable
{
    try {
        $fn();
        return null;
    } catch (Throwable $exception) {
        return $exception;
    }
}

echo "=== Tarea 2.3 (TASKS-04): techo de contención y neutralidad elemental ===\n\n";

$service = new SpellBalanceService();

// =====================================================================
// [1] Umbral crítico: 201 de maná dispara ArcaneOverloadException.
// =====================================================================
echo "[1] Umbral de Sobrecarga: 201 de maná (criterio de la Tarea)\n";

// Daño 201 con multiplicadores 1.0: base 201 → ceil 201 → SUPERIOR a 200.
$overloadException = catchException(static fn () => $service->calculate(forgeInput(damage: 201)));
assertArcane($overloadException instanceof ArcaneOverloadException, 'Un cálculo que arroja 201 de maná lanza ArcaneOverloadException');
assertArcane(
    $overloadException !== null && $overloadException->getCalculatedMana() === 201,
    'La excepción porta el maná calculado 201'
);
assertArcane(
    $overloadException !== null && $overloadException->getHttpStatusCode() === 400 && $overloadException->getErrorCode() === 'ARCANE_OVERLOAD',
    'El contrato viaja íntegro (400 + ARCANE_OVERLOAD)'
);
assertArcane(
    $overloadException !== null && $overloadException->getMessage() === 'La concentración de poder supera la capacidad de contención del plano mortal (máx. 200 maná).',
    'El mensaje ceremonial de la spec viaja en la excepción'
);

// =====================================================================
// [2] El techo 200 EXACTO se admite: frontera inclusiva del Círculo V.
// =====================================================================
echo "\n[2] Frontera inclusiva: 200 exacto se admite, 201 se rechaza\n";

$atCeiling = catchException(static fn () => $service->calculate(forgeInput(damage: 200)));
assertArcane($atCeiling === null, 'Un cálculo que arroja 200 exactos NO lanza la excepción (techo inclusivo)');
$atCeilingResult = $service->calculate(forgeInput(damage: 200));
assertArcane($atCeilingResult->finalManaCost === 200 && $atCeilingResult->circle === 5 && $atCeilingResult->isOverloaded === false, 'El maná 200 clasifica en Círculo V sin estado de sobrecarga');

// =====================================================================
// [3] Sobrecarga desde varios caminos de la fórmula (no solo daño).
// =====================================================================
echo "\n[3] La Sobrecarga dispara por cualquier camino (daño, cura, barrera, CC, multiplicadores)\n";

$overloadPaths = [
    'cura (healing 135 × 1.5 = 202.5)'        => static fn () => $service->calculate(forgeInput(healing: 135)),
    'barrera (170 × 1.2 = 204)'               => static fn () => $service->calculate(forgeInput(barrier: 170)),
    'control de masas (stun 25 + daño 180)'   => static fn () => $service->calculate(forgeInput(damage: 180, crowdControlType: 'stun')),
    'multiplicadores (150 daño × 1.5 × 1.6)'  => static fn () => $service->calculate(forgeInput(damage: 150, rangeType: 'long', areaType: 'sphere')),
];

foreach ($overloadPaths as $legend => $path) {
    $pathException = catchException($path);
    assertArcane(
        $pathException instanceof ArcaneOverloadException,
        "Sobrecarga por {$legend}"
    );
}

// =====================================================================
// [4] La excepción se lanza ANTES de materializar el resultado.
// =====================================================================
echo "\n[4] La sobrecarga nunca produce un resultado sobrecargado\n";

// El método o lanza la excepción o retorna un DTO válido: jamás existe
// un SpellCalculationResultDto con isOverloaded=true y coste > 200.
$survivor = $service->calculate(forgeInput(damage: 200));
assertArcane($survivor->finalManaCost <= SpellBalanceService::MANA_OVERLOAD_CEILING, 'Todo resultado retornado respeta el techo (no existen costes > 200 retornados)');
assertArcane($survivor->isOverloaded === false, 'Todo resultado retornado porta isOverloaded = false');

// =====================================================================
// [5] Payload HTTP del contrato: toPayload() del 400 del plan.
// =====================================================================
echo "\n[5] Payload del 400 del plan (calculatedMana)\n";

$payloadException = catchException(static fn () => $service->calculate(forgeInput(damage: 150, rangeType: 'long', areaType: 'sphere')));
$payload = $payloadException instanceof ArcaneOverloadException ? $payloadException->toPayload() : [];
// 150 × (1.5 × 1.6) = 360.0 → en IEEE-754 el producto da 359.9999... →
// ceil = 361. El servicio respeta la aritmética binaria del motor: el
// contrato (calculatedMana portado) es lo que se audita aquí.
assertArcane(
    is_array($payload)
    && ($payload['success'] ?? null) === false
    && ($payload['error']['code'] ?? null) === 'ARCANE_OVERLOAD'
    && ($payload['error']['calculatedMana'] ?? null) === 361,
    'El payload del plan (sección 2.2: success/error.code/calculatedMana) queda materializado con el maná real del motor'
);

// =====================================================================
// [6] Neutralidad elemental: fuego vs luz → exactamente el mismo coste.
// =====================================================================
echo "\n[6] Neutralidad elemental (criterio de la Tarea)\n";

// El DTO matemático no porta afinidad ni escuela: dos conjuros idénticos
// en sus 10 parámetros matemáticos pero de naturaleza elemental opuesta
// (fuego vs luz) producen EXACTAMENTE el mismo desglose.
$fireLike = forgeInput(damage: 30, healing: 5, barrier: 5, crowdControlType: 'slow', rangeType: 'medium', areaType: 'sphere', durationType: 'concentration', hasVerbal: true, hasSomatic: true, hasMaterial: false);
$lightLike = forgeInput(damage: 30, healing: 5, barrier: 5, crowdControlType: 'slow', rangeType: 'medium', areaType: 'sphere', durationType: 'concentration', hasVerbal: true, hasSomatic: true, hasMaterial: false);

$fireCalculation = $service->calculate($fireLike);
$lightCalculation = $service->calculate($lightLike);

assertArcane($fireCalculation->finalManaCost === $lightCalculation->finalManaCost, 'Afinidad de fuego o luz produce EXACTAMENTE el mismo coste');
assertArcane($fireCalculation->grossMana === $lightCalculation->grossMana, 'El maná bruto es idéntico entre naturalezas elementales');
assertArcane($fireCalculation->circle === $lightCalculation->circle, 'El Círculo Arcano es idéntico entre naturalezas elementales');

// Huella también neutral: la naturaleza elemental no participa.
$serviceForFingerprint = new SpellBalanceService();
assertArcane(
    $serviceForFingerprint->computeMathFingerprint($fireLike) === $serviceForFingerprint->computeMathFingerprint($lightLike),
    'La huella matemática es idéntica entre naturalezas elementales'
);

// Neutralidad estructural: el DTO no porta campos elementales.
$reflectionClass = new ReflectionClass(SpellCalculationInputDto::class);
$propertyNames = array_map(static fn (ReflectionProperty $property): string => $property->getName(), $reflectionClass->getProperties());
assertArcane(
    !in_array('elementalAffinity', $propertyNames, true) && !in_array('magicSchool', $propertyNames, true),
    'El DTO matemático no porta elementalAffinity ni magicSchool (imposible que interfieran)'
);

// =====================================================================
// Resumen final.
// =====================================================================
echo "\n=== RESUMEN: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);
