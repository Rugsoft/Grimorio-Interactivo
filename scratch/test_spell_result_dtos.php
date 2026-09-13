<?php

/**
 * test_spell_result_dtos.php — Arnés TDD de la Tarea 1.3 (TASKS-04).
 *
 * Verifica los DTOs de resultado pedagógico y carga de creación:
 *   - SpellCalculationResultDto: desglose pedagógico del maná con
 *     serialización JSON nativa (JsonSerializable) y claves camelCase
 *     exactas del contrato del Endpoint 1 (plan 2.2).
 *   - SpellCreateDto: carga útil completa (narrativa + cuantitativa) para
 *     borradores y publicación (Endpooints 2 y 5 del plan).
 *
 * Criterio «Hecho cuando» (Tarea 1.3): json_encode($resultDto) genera la
 * estructura exacta esperada por el contrato de la API REST con todas
 * sus claves en inglés camelCase.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro, json_encode nativo.
 *   - Artículo II: el DTO de resultado porta el desglose determinista.
 *   - Artículo IV: circleLabel solemne en castellano.
 *   - Artículo V: claves técnicas en inglés camelCase.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Dto/SpellCalculationInputDto.php';
require __DIR__ . '/../src/Dto/SpellCalculationResultDto.php';
require __DIR__ . '/../src/Dto/SpellCreateDto.php';

use Grimorio\Dto\SpellCalculationInputDto;
use Grimorio\Dto\SpellCalculationResultDto;
use Grimorio\Dto\SpellCreateDto;

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

function catchException(callable $fn): ?Throwable
{
    try {
        $fn();
        return null;
    } catch (Throwable $exception) {
        return $exception;
    }
}

echo "=== Tarea 1.3 (TASKS-04): DTOs de resultado y de creación ===\n\n";

// Payload canónico del Endpoint 1 (plan 2.2): 30 daño, medium/sphere/
// instant, componentes verbal+somatic → desglose 30 × 2.0 = 60, −20% = 48.
$canonicalInput = new SpellCalculationInputDto(
    damage: 30,
    healing: 0,
    barrier: 0,
    crowdControlType: 'none',
    rangeType: 'medium',
    areaType: 'sphere',
    durationType: 'instant',
    hasVerbal: true,
    hasSomatic: true,
    hasMaterial: false,
);

// =====================================================================
// [0] Superficie: las clases existen y son readonly.
// =====================================================================
echo "[0] Superficie e inmutabilidad\n";

assertArcane(class_exists(SpellCalculationResultDto::class), 'La clase SpellCalculationResultDto existe');
assertArcane(class_exists(SpellCreateDto::class), 'La clase SpellCreateDto existe');
assertArcane(
    in_array(JsonSerializable::class, class_implements(SpellCalculationResultDto::class), true),
    'SpellCalculationResultDto implementa JsonSerializable (serialización JSON nativa)'
);
assertArcane((new ReflectionClass(SpellCalculationResultDto::class))->isReadOnly(), 'SpellCalculationResultDto es readonly');
assertArcane((new ReflectionClass(SpellCreateDto::class))->isReadOnly(), 'SpellCreateDto es readonly');

// =====================================================================
// [1] SpellCalculationResultDto: propiedades tipadas del desglose.
// =====================================================================
echo "\n[1] SpellCalculationResultDto: desglose pedagógico\n";

$resultDto = new SpellCalculationResultDto(
    baseEffectPoints: 30.0,
    multipliers: ['range' => 1.25, 'area' => 1.6, 'duration' => 1.0, 'combined' => 2.0],
    grossMana: 60.0,
    discounts: ['verbal' => 0.10, 'somatic' => 0.10, 'material' => 0.0, 'totalPercent' => 0.20, 'amountDeducted' => 12.0],
    netMana: 48.0,
    finalManaCost: 48,
    circle: 3,
    circleLabel: 'Círculo III (Magister)',
    isOverloaded: false,
);

assertArcane($resultDto->baseEffectPoints === 30.0, 'baseEffectPoints lee 30.0 (float)');
assertArcane($resultDto->multipliers['combined'] === 2.0, 'multipliers.combined lee 2.0');
assertArcane($resultDto->grossMana === 60.0, 'grossMana lee 60.0');
assertArcane($resultDto->discounts['totalPercent'] === 0.20, 'discounts.totalPercent lee 0.20');
assertArcane($resultDto->netMana === 48.0, 'netMana lee 48.0');
assertArcane($resultDto->finalManaCost === 48, 'finalManaCost lee 48 (int)');
assertArcane($resultDto->circle === 3, 'circle lee 3 (int)');
assertArcane($resultDto->circleLabel === 'Círculo III (Magister)', 'circleLabel porta la denominación solemne en castellano');
assertArcane($resultDto->isOverloaded === false, 'isOverloaded lee false');

// =====================================================================
// [2] Criterio «Hecho cuando»: json_encode genera la estructura exacta.
// =====================================================================
echo "\n[2] Contrato JSON del Endpoint 1 (json_encode nativo)\n";

$json = json_encode($resultDto, JSON_UNESCAPED_UNICODE);
$decoded = is_string($json) ? json_decode($json, true) : null;

assertArcane(is_array($decoded), 'json_encode($resultDto) produce JSON decodificable');

$expectedKeys = [
    'baseEffectPoints', 'multipliers', 'grossMana', 'discounts',
    'netMana', 'finalManaCost', 'circle', 'circleLabel', 'isOverloaded',
];
assertArcane(
    is_array($decoded) && array_keys($decoded) === $expectedKeys,
    'Las claves raíz coinciden EXACTAMENTE con el contrato del plan (orden y camelCase)'
);

assertArcane(
    is_array($decoded)
    && array_keys($decoded['multipliers'] ?? []) === ['range', 'area', 'duration', 'combined'],
    'multipliers porta las subclaves camelCase del contrato'
);
assertArcane(
    is_array($decoded)
    && array_keys($decoded['discounts'] ?? []) === ['verbal', 'somatic', 'material', 'totalPercent', 'amountDeducted'],
    'discounts porta las subclaves camelCase del contrato'
);

// Verificación numérica fiel: json_encode de PHP emite los floats enteros
// sin cola decimal (30.0 → 30) y json_decode los restaura como int; los
// enteros puros (finalManaCost, circle) permanecen intactos. El valor
// numérico es idéntico; el DTO interno conserva el tipo float original.
assertArcane(
    is_array($decoded)
    && $decoded['baseEffectPoints'] == 30.0
    && $decoded['finalManaCost'] === 48
    && $decoded['circle'] === 3,
    'Los valores sobreviven a la serialización (float entero normalizado por PHP, int, int)'
);

// Sobrecarga: el DTO puede portar el estado isOverloaded con el maná bruto.
$overloadedDto = new SpellCalculationResultDto(
    baseEffectPoints: 150.0,
    multipliers: ['range' => 1.5, 'area' => 1.6, 'duration' => 1.0, 'combined' => 2.4],
    grossMana: 360.0,
    discounts: ['verbal' => 0.0, 'somatic' => 0.0, 'material' => 0.0, 'totalPercent' => 0.0, 'amountDeducted' => 0.0],
    netMana: 360.0,
    finalManaCost: 360,
    circle: 5,
    circleLabel: 'Círculo V (Archimago)',
    isOverloaded: true,
);
$overloadedDecoded = json_decode((string) json_encode($overloadedDto, JSON_UNESCAPED_UNICODE), true);
assertArcane(
    is_array($overloadedDecoded) && $overloadedDecoded['isOverloaded'] === true && $overloadedDecoded['finalManaCost'] === 360,
    'El estado de Sobrecarga Arcana viaja íntegro en el JSON'
);

// =====================================================================
// [3] SpellCreateDto: carga completa narrativa + cuantitativa.
// =====================================================================
echo "\n[3] SpellCreateDto: carga útil de borradores y publicación\n";

$createDto = new SpellCreateDto(
    name: 'Esfera Ígnea de Frieren',
    elementalAffinity: 'fire',
    magicSchool: 'evocation',
    castingTime: 'action',
    description: 'Concentra calor blanco en un núcleo denso que estalla al alcanzar la distancia media.',
    calculationInput: $canonicalInput,
);

assertArcane($createDto->name === 'Esfera Ígnea de Frieren', 'name porta el nombre canónico');
assertArcane($createDto->elementalAffinity === 'fire', 'elementalAffinity lee fire');
assertArcane($createDto->magicSchool === 'evocation', 'magicSchool lee evocation');
assertArcane($createDto->castingTime === 'action', 'castingTime lee action');
assertArcane($createDto->description !== '', 'description porta la narrativa en castellano');
assertArcane($createDto->calculationInput === $canonicalInput, 'calculationInput porta el DTO de entrada cuantitativa (composición)');
assertArcane($createDto->calculationInput->damage === 30, 'El DTO compuesto expone los parámetros matemáticos');

// fromArray con el payload exacto del Endpoint 2 del plan.
$createDtoFromPayload = SpellCreateDto::fromArray([
    'name' => 'Esfera Ígnea de Frieren',
    'elementalAffinity' => 'fire',
    'magicSchool' => 'evocation',
    'castingTime' => 'action',
    'description' => 'Concentra calor blanco en un núcleo denso que estalla al alcanzar la distancia media.',
    'damage' => 30,
    'healing' => 0,
    'barrier' => 0,
    'crowdControlType' => 'none',
    'rangeType' => 'medium',
    'areaType' => 'sphere',
    'durationType' => 'instant',
    'hasVerbal' => true,
    'hasSomatic' => true,
    'hasMaterial' => false,
]);
assertArcane($createDtoFromPayload->name === 'Esfera Ígnea de Frieren', 'fromArray materializa el payload del Endpoint 2');
assertArcane($createDtoFromPayload->calculationInput instanceof SpellCalculationInputDto, 'fromArray compone el SpellCalculationInputDto interno');
assertArcane($createDtoFromPayload->calculationInput->areaType === 'sphere', 'Los parámetros cuantitativos viajan al DTO interno');

// =====================================================================
// [4] Defensas de SpellCreateDto (fábrica y constructor).
// =====================================================================
echo "\n[4] Defensas de validación\n";

$exception = catchException(static fn () => new SpellCreateDto(
    name: '   ',
    elementalAffinity: 'fire',
    magicSchool: 'evocation',
    castingTime: 'action',
    description: 'x',
    calculationInput: $canonicalInput,
));
assertArcane($exception instanceof InvalidArgumentException, 'El nombre vacío (o solo espacios) se rechaza con InvalidArgumentException');

$exception = catchException(static fn () => SpellCreateDto::fromArray([
    'name' => 'Sin cuantitativos',
    'elementalAffinity' => 'fire',
    'magicSchool' => 'evocation',
    'castingTime' => 'action',
    'description' => 'x',
]));
assertArcane($exception instanceof InvalidArgumentException, 'fromArray sin parámetros cuantitativos se rechaza');

$exception = catchException(static fn () => SpellCreateDto::fromArray([
    'name' => 'X', 'elementalAffinity' => 'fire', 'magicSchool' => 'evocation',
    'castingTime' => 'action', 'description' => 'x',
    'damage' => -1, 'healing' => 0, 'barrier' => 0,
    'crowdControlType' => 'none', 'rangeType' => 'touch', 'areaType' => 'singleTarget',
    'durationType' => 'instant', 'hasVerbal' => false, 'hasSomatic' => false, 'hasMaterial' => false,
]));
assertArcane($exception instanceof InvalidArgumentException, 'fromArray canaliza el rechazo de dominio del DTO interno (negativos)');

// Serialización JSON del SpellCreateDto: camelCase plano para los metadatos.
$createDecoded = json_decode((string) json_encode($createDto, JSON_UNESCAPED_UNICODE), true);
assertArcane(
    is_array($createDecoded)
    && array_keys($createDecoded) === ['name', 'elementalAffinity', 'magicSchool', 'castingTime', 'description', 'calculationInput'],
    'SpellCreateDto serializa con claves camelCase y el DTO interno anidado'
);

// =====================================================================
// Resumen final.
// =====================================================================
echo "\n=== RESUMEN: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);
