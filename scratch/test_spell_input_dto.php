<?php

/**
 * test_spell_input_dto.php — Arnés TDD de la Tarea 1.2 (TASKS-04).
 *
 * Verifica el DTO inmutable de parámetros numéricos de entrada
 * (SpellCalculationInputDto): propiedades tipadas, validaciones de
 * dominio canónicas y constructor inmutable.
 *
 * Criterio «Hecho cuando» (Tarea 1.2): la clase rechaza valores
 * numéricos negativos o modificadores inexistentes mediante
 * InvalidArgumentException al ser instanciada.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP 8.2+ puro, sin librerías.
 *   - Artículo II: los parámetros alimentan la fórmula determinista.
 *   - Artículo V: identificadores en inglés camelCase, documentación
 *     en castellano.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Dto/SpellCalculationInputDto.php';

use Grimorio\Dto\SpellCalculationInputDto;

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
 * Ejecuta una función y reporta la excepción capturada (o null).
 */
function catchException(callable $fn): ?Throwable
{
    try {
        $fn();
        return null;
    } catch (Throwable $exception) {
        return $exception;
    }
}

echo "=== Tarea 1.2 (TASKS-04): SpellCalculationInputDto ===\n\n";

// =====================================================================
// [0] Superficie: la clase existe y es inmutable (readonly).
// =====================================================================
echo "[0] Superficie e inmutabilidad\n";

assertArcane(class_exists(SpellCalculationInputDto::class), 'La clase Grimorio\Dto\SpellCalculationInputDto existe');

$reflection  = new ReflectionClass(SpellCalculationInputDto::class);
$constructor = $reflection->getConstructor();
$expectedParams = [
    'damage', 'healing', 'barrier', 'crowdControlType',
    'rangeType', 'areaType', 'durationType',
    'hasVerbal', 'hasSomatic', 'hasMaterial',
];
$actualParams = $constructor !== null
    ? array_map(static fn (ReflectionParameter $p): string => $p->getName(), $constructor->getParameters())
    : [];
assertArcane($actualParams === $expectedParams, 'El constructor declara los 10 parámetros canónicos en orden del plan');
assertArcane($reflection->isReadOnly(), 'La clase es readonly (inmutabilidad estructural de PHP 8.2)');

// =====================================================================
// [1] Instanciación canónica y lectura de propiedades tipadas.
// =====================================================================
echo "\n[1] Instanciación canónica\n";

$validDto = new SpellCalculationInputDto(
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

assertArcane($validDto->damage === 30, 'damage lee 30 (int)');
assertArcane($validDto->healing === 0 && $validDto->barrier === 0, 'healing y barrier leen 0 (int)');
assertArcane($validDto->crowdControlType === 'none', 'crowdControlType lee none');
assertArcane($validDto->rangeType === 'medium', 'rangeType lee medium');
assertArcane($validDto->areaType === 'sphere', 'areaType lee sphere');
assertArcane($validDto->durationType === 'instant', 'durationType lee instant');
assertArcane($validDto->hasVerbal === true && $validDto->hasSomatic === true && $validDto->hasMaterial === false, 'Componentes atenuadores leen true/true/false (bool)');

// Payload del Endpoint 1 del plan: los valores por defecto permiten omitir
// campos y el DTO replica exactamente el ejemplo 30/0/0 medium/sphere/instant.
$endpointDto = SpellCalculationInputDto::fromArray([
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
assertArcane($endpointDto->damage === 30 && $endpointDto->areaType === 'sphere', 'fromArray() materializa el payload canónico del Endpoint 1');

// =====================================================================
// [2] Rechazo de valores numéricos negativos (criterio «Hecho cuando»).
// =====================================================================
echo "\n[2] Rechazo de valores negativos (InvalidArgumentException)\n";

$negativeCases = [
    'damage negativo'   => static fn () => new SpellCalculationInputDto(damage: -1, healing: 0, barrier: 0, crowdControlType: 'none', rangeType: 'touch', areaType: 'singleTarget', durationType: 'instant', hasVerbal: false, hasSomatic: false, hasMaterial: false),
    'healing negativo'  => static fn () => new SpellCalculationInputDto(damage: 0, healing: -5, barrier: 0, crowdControlType: 'none', rangeType: 'touch', areaType: 'singleTarget', durationType: 'instant', hasVerbal: false, hasSomatic: false, hasMaterial: false),
    'barrier negativa'  => static fn () => new SpellCalculationInputDto(damage: 0, healing: 0, barrier: -12, crowdControlType: 'none', rangeType: 'touch', areaType: 'singleTarget', durationType: 'instant', hasVerbal: false, hasSomatic: false, hasMaterial: false),
];

foreach ($negativeCases as $legend => $case) {
    $exception = catchException($case);
    assertArcane(
        $exception instanceof InvalidArgumentException,
        "Rechaza {$legend} con InvalidArgumentException"
    );
}

// =====================================================================
// [3] Rechazo de modificadores inexistentes (criterio «Hecho cuando»).
// =====================================================================
echo "\n[3] Rechazo de modificadores fuera del canon\n";

$invalidModifierCases = [
    'crowdControlType' => static fn () => new SpellCalculationInputDto(damage: 0, healing: 0, barrier: 0, crowdControlType: 'polymorph', rangeType: 'touch', areaType: 'singleTarget', durationType: 'instant', hasVerbal: false, hasSomatic: false, hasMaterial: false),
    'rangeType'        => static fn () => new SpellCalculationInputDto(damage: 0, healing: 0, barrier: 0, crowdControlType: 'none', rangeType: 'continental', areaType: 'singleTarget', durationType: 'instant', hasVerbal: false, hasSomatic: false, hasMaterial: false),
    'areaType'         => static fn () => new SpellCalculationInputDto(damage: 0, healing: 0, barrier: 0, crowdControlType: 'none', rangeType: 'touch', areaType: 'vortex', durationType: 'instant', hasVerbal: false, hasSomatic: false, hasMaterial: false),
    'durationType'     => static fn () => new SpellCalculationInputDto(damage: 0, healing: 0, barrier: 0, crowdControlType: 'none', rangeType: 'touch', areaType: 'singleTarget', durationType: 'eternal', hasVerbal: false, hasSomatic: false, hasMaterial: false),
];

foreach ($invalidModifierCases as $field => $case) {
    $exception = catchException($case);
    assertArcane(
        $exception instanceof InvalidArgumentException,
        "Rechaza {$field} fuera del canon con InvalidArgumentException"
    );
}

// Modificadores dentro del canon: todos aceptados.
$canonAccepted = true;
foreach (['none', 'slow', 'root', 'stun'] as $cc) {
    try {
        new SpellCalculationInputDto(0, 0, 0, $cc, 'touch', 'singleTarget', 'instant', false, false, false);
    } catch (Throwable) {
        $canonAccepted = false;
    }
}
assertArcane($canonAccepted, 'Acepta los 4 crowdControlType canónicos (none, slow, root, stun)');

$canonAccepted = true;
foreach (['touch', 'short', 'medium', 'long'] as $range) {
    try {
        new SpellCalculationInputDto(0, 0, 0, 'none', $range, 'singleTarget', 'instant', false, false, false);
    } catch (Throwable) {
        $canonAccepted = false;
    }
}
assertArcane($canonAccepted, 'Acepta los 4 rangeType canónicos (touch, short, medium, long)');

$canonAccepted = true;
foreach (['singleTarget', 'cone', 'line', 'sphere'] as $area) {
    try {
        new SpellCalculationInputDto(0, 0, 0, 'none', 'touch', $area, 'instant', false, false, false);
    } catch (Throwable) {
        $canonAccepted = false;
    }
}
assertArcane($canonAccepted, 'Acepta las 4 areaType canónicas (singleTarget, cone, line, sphere)');

$canonAccepted = true;
foreach (['instant', 'concentration', 'sustained'] as $duration) {
    try {
        new SpellCalculationInputDto(0, 0, 0, 'none', 'touch', 'singleTarget', $duration, false, false, false);
    } catch (Throwable) {
        $canonAccepted = false;
    }
}
assertArcane($canonAccepted, 'Acepta los 3 durationType canónicos (instant, concentration, sustained)');

// =====================================================================
// [4] fromArray: defensas de la fábrica (falta de campos, tipos sucios).
// =====================================================================
echo "\n[4] Fábrica fromArray: defensas de deserialización\n";

$exception = catchException(static fn () => SpellCalculationInputDto::fromArray(['damage' => 10]));
assertArcane($exception instanceof InvalidArgumentException, 'fromArray sin campos completos rechaza con InvalidArgumentException');

$exception = catchException(static fn () => SpellCalculationInputDto::fromArray([
    'damage' => 'treinta', 'healing' => 0, 'barrier' => 0,
    'crowdControlType' => 'none', 'rangeType' => 'touch', 'areaType' => 'singleTarget',
    'durationType' => 'instant', 'hasVerbal' => false, 'hasSomatic' => false, 'hasMaterial' => false,
]));
assertArcane($exception instanceof InvalidArgumentException, 'fromArray con tipo sucio (damage "treinta") rechaza con InvalidArgumentException');

$exception = catchException(static fn () => SpellCalculationInputDto::fromArray([
    'damage' => 10.5, 'healing' => 0, 'barrier' => 0,
    'crowdControlType' => 'none', 'rangeType' => 'touch', 'areaType' => 'singleTarget',
    'durationType' => 'instant', 'hasVerbal' => false, 'hasSomatic' => false, 'hasMaterial' => false,
]));
assertArcane($exception instanceof InvalidArgumentException, 'fromArray con float no enterable (10.5) rechaza con InvalidArgumentException');

// fromArray valida el dominio con la misma leyenda que el constructor.
$exception = catchException(static fn () => SpellCalculationInputDto::fromArray([
    'damage' => -3, 'healing' => 0, 'barrier' => 0,
    'crowdControlType' => 'none', 'rangeType' => 'touch', 'areaType' => 'singleTarget',
    'durationType' => 'instant', 'hasVerbal' => false, 'hasSomatic' => false, 'hasMaterial' => false,
]));
assertArcane(
    $exception instanceof InvalidArgumentException && str_contains($exception->getMessage(), 'negativo'),
    'fromArray canaliza el rechazo de negativos con leyenda castellana'
);

// =====================================================================
// [5] Inmutabilidad estructural: mutación externa rechazada.
// =====================================================================
echo "\n[5] Inmutabilidad estructural\n";

$exception = catchException(static fn () => $validDto->damage = 999);
assertArcane($exception instanceof Error, 'La mutación externa de una propiedad readonly falla con Error');

// =====================================================================
// Resumen final.
// =====================================================================
echo "\n=== RESUMEN: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);
