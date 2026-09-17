<?php

/**
 * test_spell_affinity_validation.php — Arnés TDD del RF-01.6 (SPEC-04,
 * criterio ratificado).
 *
 * Verifica que SpellCreateDto valida la afinidad elemental contra los
 * ocho identificadores canónicos del Códice y rechaza con error de
 * dominio cualquier valor ajeno:
 *   [1] Las 8 afinidades canónicas forjan el DTO sin objeción.
 *   [2] Valores ajeno al Códice ('shadow', 'plasma', 'ice', 'air', '') son
 *       rechazados con InvalidArgumentException.
 *   [3] fromArray() delega la misma validación (flujo del endpoint).
 *   [4] El mensaje de error cita la lista canónica completa.
 *   [5] La constante pública expone exactamente los 8 IDs en orden del
 *       Códice (paridad byte a byte con ElementalMatrixService).
 *
 * Criterio «Hecho cuando» (RF-01.6): un payload con elementalAffinity
 * 'shadow' es rechazado con error de dominio antes de tocar la base de
 * datos, y los 8 valores canónicos pasan intactos.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro, sin librerías.
 *   - Artículo II: la validación no altera el cálculo de maná (RF-02.4,
 *     neutralidad elemental).
 *   - Artículo V: identificadores en inglés camelCase, comentarios en
 *     castellano.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Dto/SpellCalculationInputDto.php';
require __DIR__ . '/../src/Dto/SpellCreateDto.php';

use Grimorio\Dto\SpellCalculationInputDto;
use Grimorio\Dto\SpellCreateDto;
use Grimorio\Services\ElementalMatrixService;

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

/** DTO cuantitativo canónico: la afinidad no altera el cálculo (RF-02.4). */
function forgeCalculationInput(): SpellCalculationInputDto
{
    return new SpellCalculationInputDto(
        damage: 5,
        healing: 0,
        barrier: 0,
        crowdControlType: 'none',
        rangeType: 'touch',
        areaType: 'singleTarget',
        durationType: 'instant',
        hasVerbal: true,
        hasSomatic: true,
        hasMaterial: false,
    );
}

/** Payload canónico base: solo varía la afinidad en cada prueba. */
function forgePayload(string $elementalAffinity): array
{
    return [
        'name'              => 'Conjuro de Prueba del Arnés',
        'elementalAffinity' => $elementalAffinity,
        'magicSchool'       => 'evocation',
        'castingTime'       => 'action',
        'description'       => 'Descripción narrativa digna del santuario.',
        'damage'            => 5,
        'healing'           => 0,
        'barrier'           => 0,
        'crowdControlType'  => 'none',
        'rangeType'         => 'touch',
        'areaType'          => 'singleTarget',
        'durationType'      => 'instant',
        'hasVerbal'         => true,
        'hasSomatic'        => true,
        'hasMaterial'       => false,
    ];
}

echo '== ARNÉS TDD — VALIDACIÓN DE AFINIDAD CANÓNICA (RF-01.6, SPEC-04) ==' . "\n";

// ---------------------------------------------------------------------------
echo "\n[FASE 1] Las 8 afinidades canónicas pasan intactas" . "\n";
// ---------------------------------------------------------------------------

$canonical = ['fire', 'water', 'lightning', 'earth', 'wind', 'light', 'darkness', 'pureArcane'];
foreach ($canonical as $affinity) {
    try {
        $dto = new SpellCreateDto(
            'Conjuro de Prueba del Arnés',
            $affinity,
            'evocation',
            'action',
            'Descripción narrativa digna del santuario.',
            forgeCalculationInput(),
        );
        assertArcane($dto->elementalAffinity === $affinity, "La afinidad canónica '{$affinity}' forja el DTO sin objeción");
    } catch (InvalidArgumentException $error) {
        assertArcane(false, "La afinidad canónica '{$affinity}' forja el DTO sin objeción (rechazada: {$error->getMessage()})");
    }
}

// ---------------------------------------------------------------------------
echo "\n[FASE 2] Afinidades ajenas al Códice: rechazo con error de dominio" . "\n";
// ---------------------------------------------------------------------------

$foreign = ['shadow', 'plasma', 'ice', 'air', 'arcane', 'FIRE', 'dark', 'none', ''];
foreach ($foreign as $affinity) {
    try {
        new SpellCreateDto(
            'Conjuro de Prueba del Arnés',
            $affinity,
            'evocation',
            'action',
            'Descripción narrativa digna del santuario.',
            forgeCalculationInput(),
        );
        $label = $affinity === '' ? '(cadena vacía)' : $affinity;
        assertArcane(false, "La afinidad ajena '{$label}' es rechazada");
    } catch (InvalidArgumentException) {
        $label = $affinity === '' ? '(cadena vacía)' : $affinity;
        assertArcane(true, "La afinidad ajena '{$label}' es rechazada");
    }
}

// ---------------------------------------------------------------------------
echo "\n[FASE 3] fromArray() delega la misma validación (flujo del endpoint)" . "\n";
// ---------------------------------------------------------------------------

try {
    SpellCreateDto::fromArray(forgePayload('shadow'));
    assertArcane(false, "fromArray() rechaza 'shadow' (la hallazga de la semilla primordial)");
} catch (InvalidArgumentException) {
    assertArcane(true, "fromArray() rechaza 'shadow' (la hallazga de la semilla primordial)");
}

try {
    $dto = SpellCreateDto::fromArray(forgePayload('darkness'));
    assertArcane($dto->elementalAffinity === 'darkness', "fromArray() acepta 'darkness', el valor canónico correcto");
} catch (InvalidArgumentException $error) {
    assertArcane(false, "fromArray() acepta 'darkness', el valor canónico correcto (rechazada: {$error->getMessage()})");
}

// ---------------------------------------------------------------------------
echo "\n[FASE 4] El mensaje de error cita la lista canónica completa" . "\n";
// ---------------------------------------------------------------------------

try {
    SpellCreateDto::fromArray(forgePayload('shadow'));
    assertArcane(false, 'El mensaje de error enumera los 8 identificadores canónicos');
} catch (InvalidArgumentException $error) {
    $message = $error->getMessage();
    $allQuoted = true;
    foreach ($canonical as $affinity) {
        if (!str_contains($message, $affinity)) {
            $allQuoted = false;
        }
    }
    assertArcane($allQuoted, 'El mensaje de error enumera los 8 identificadores canónicos');
}

// ---------------------------------------------------------------------------
echo "\n[FASE 5] Paridad byte a byte con la matriz del Códice (SPEC-06)" . "\n";
// ---------------------------------------------------------------------------

require __DIR__ . '/../src/Dto/ComboResolutionResultDto.php';
require __DIR__ . '/../src/Dto/ElementalReactionDto.php';
require __DIR__ . '/../src/Dto/ElementalMatrixGraphDto.php';
require __DIR__ . '/../src/Services/ElementalMatrixService.php';
$matrixGraph = (new ElementalMatrixService())->getMatrixGraph();
$matrixIds = array_column($matrixGraph->elements, 'id');
assertArcane(
    $matrixIds === SpellCreateDto::CANONICAL_AFFINITIES,
    'La lista del DTO es idéntica (orden y valores) a la matriz de ElementalMatrixService'
);

// ---------------------------------------------------------------------------
echo "\n== RESUMEN ==" . "\n";
echo "Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";

if ($assertsFailed > 0) {
    echo "\nRESULTADO: FRACASO — La validación de afinidad canónica no cumple el RF-01.6.\n";
    exit(1);
}

echo "\nRESULTADO: ÉXITO — El Códice custodia la puerta: solo afinidades canónicas imbuyen aura (RF-01.6).\n";
exit(0);
