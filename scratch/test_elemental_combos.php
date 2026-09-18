<?php

/**
 * test_elemental_combos.php — Script automatizado de pruebas backend (SPEC-06).
 *
 * Tarea 1.5 (TASKS-06): materializa el protocolo de la Sección 6.1 del plan
 * —los seis Tests canónicos de la alquimia arcana— contra la implementación
 * real de ElementalMatrixService (Tareas 1.2 y 1.3):
 *
 *   * Test 1: Simetría de las 7 Reacciones — resolve('fire','water') y
 *     resolve('water','fire') devuelven arcaneVaporization (y cada par dual
 *     en ambas orientaciones, con veredicto JSON idéntico).
 *   * Test 2: Catalizador Arcano Puro — pureArcane sobre cualquier aura
 *     devuelve pureArcaneResonance con amplificación del +25% (ceil) y el
 *     Códice declara la extensión de controles de +1 s (ccExtensionMs 1000).
 *   * Test 3: Sobreescritura No Reactiva — Luz sobre Fuego aplica daño
 *     íntegro y sobreescribe el aura a Luz sin detonar nada.
 *   * Test 4: Cálculo del +50% de daño — un conjuro de 40 inflije
 *     exactamente ceil(40 × 1.5) = 60; uno de 41, ceil(61.5) = 62 (el
 *     redondeo siempre favorece al impacto).
 *   * Test 5: Fractura Basáltica y Barreras — la trituración de 50 PV
 *     viaja como dato del Códice; el veredicto jamás añade daño por
 *     excedente (una barrera de 20 PV se anula sin transferir un punto a
 *     la salud) y la detonación deja neutral puro.
 *   * Test 6: Colapso Crepuscular — el daño penetra la barrera aplicando
 *     el +50% íntegro y deja la barrera intacta (la ficha del Códice no
 *     declara trituración alguna: el efecto es barrierPiercing).
 *
 * Criterio «Hecho cuando» (Tarea 1.5): la ejecución
 * `php scratch/test_elemental_combos.php` pasa el 100% de los asertos
 * matemáticos y tácticos con código de salida 0.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): solo PHP nativo, sin librerías.
 *   - Artículo II: los factores se LEEN del Códice; la única aritmética es
 *     ceil(base × factor). Sin probabilidad ni tiradas de dados (RNF-01).
 *   - Artículo IV: nombres litúrgicos en castellano solemne.
 *   - Artículo V: identificadores en inglés camelCase; documentación en
 *     castellano.
 *
 * Uso: php scratch/test_elemental_combos.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../src/Dto/ElementalReactionDto.php';
require __DIR__ . '/../src/Dto/ElementalMatrixGraphDto.php';
require __DIR__ . '/../src/Dto/ComboResolutionResultDto.php';
require __DIR__ . '/../src/Dto/SpellImpactData.php';
require __DIR__ . '/../src/Services/ElementalMatrixService.php';

use Grimorio\Dto\SpellImpactData;
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

function assert_same_json(mixed $expected, mixed $actual, string $label): void
{
    $expectedJson = json_encode($expected, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    $actualJson = json_encode($actual, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    assert_truthy($expectedJson === $actualJson, $label);
}

/** Conjuro entrante de prueba: elemento y daño base arbitrarios. */
function spellOf(string $element, int $baseDamage): SpellImpactData
{
    return new SpellImpactData(id: 'spl_combo_probe', element: $element, baseDamage: $baseDamage);
}

$service = new ElementalMatrixService();

echo "== PROTOCOLO AUTOMATIZADO BACKEND — ALQUIMIA ARCANA (Tarea 1.5, SPEC-06) ==\n";

// ===========================================================================
echo "\n[Test 1] Simetría de las 7 Reacciones (RF-03.1)\n";
// ===========================================================================

$canonicalPairs = [
    ['fire', 'water', 'arcaneVaporization'],
    ['water', 'lightning', 'fluidElectrocution'],
    ['fire', 'wind', 'vortexDeflagration'],
    ['earth', 'lightning', 'basalticFracture'],
    ['earth', 'water', 'petrifyingSwamp'],
    ['wind', 'water', 'glacialBlizzard'],
    ['light', 'darkness', 'twilightCollapse'],
];

foreach ($canonicalPairs as [$auraElement, $spellElement, $reactionId]) {
    $forward = $service->resolveCombo($auraElement, spellOf($spellElement, 40), false);
    $reverse = $service->resolveCombo($spellElement, spellOf($auraElement, 40), false);

    assert_truthy($forward->isReaction && $forward->reactionId === $reactionId, "{$auraElement} + {$spellElement} detona {$reactionId}");
    assert_truthy($reverse->isReaction && $reverse->reactionId === $reactionId, "{$spellElement} + {$auraElement} detona la misma reacción");
    assert_same_json($forward, $reverse, "{$auraElement} + {$spellElement} = {$spellElement} + {$auraElement} (veredicto JSON idéntico)");
}

// ===========================================================================
echo "\n[Test 2] Catalizador Arcano Puro (RF-03.2)\n";
// ===========================================================================

// Sobre CUALQUIER aura previa: +25% con redondeo al alza y neutral puro.
$catalystAuras = ['fire', 'water', 'lightning', 'earth', 'wind', 'light', 'darkness'];
foreach ($catalystAuras as $auraElement) {
    $resonance = $service->resolveCombo($auraElement, spellOf('pureArcane', 40), false);
    assert_truthy(
        $resonance->isReaction && $resonance->reactionId === 'pureArcaneResonance' && $resonance->effectiveDamage === 50,
        "Arcano Puro sobre aura de {$auraElement}: Resonancia con amplificación ceil(40 × 1.25) = 50",
    );
    assert_truthy($resonance->clearedAura && $resonance->resultingAura === null, "La resonancia consume el aura de {$auraElement} (neutral puro)");
}

// RF-03.2 (Hallazgo 15): el veredicto del catalizador debe portar la señal de
// amplificación para que el cliente traduzca curación, barrera y +1 s de CC.
$resonanceSignal = $service->resolveCombo('fire', new SpellImpactData(
    id: 'spl_catalyst_signal',
    element: 'pureArcane',
    baseDamage: 40,
    baseHealing: 30,
    baseBarrier: 20,
    crowdControlType: 'slow',
), false);
assert_truthy($resonanceSignal->tacticalEffectApplied === 'amplification', 'El veredicto del catalizador porta la señal amplification para la traducción del cliente');
assert_truthy($resonanceSignal->damageMultiplierApplied === 1.25, 'El veredicto del catalizador porta el factor 1.25 para curación/barrera');
assert_truthy($resonanceSignal->effectiveDamage === 50, 'El daño del catalizador ya viaja amplificado en el veredicto: ceil(40 × 1.25) = 50');
foreach ($service->getMatrixGraph()->reactions as $reaction) {
    if ($reaction->id === 'pureArcaneResonance') {
        $catalystFicha = $reaction;
    }
}
assert_truthy($catalystFicha !== null && $catalystFicha->ccExtensionMs === 1000, 'El Códice declara la extensión de controles del catalizador en +1 s (ccExtensionMs = 1000)');
assert_truthy($catalystFicha !== null && $catalystFicha->amplificationFactor === 1.25, 'El Códice declara la amplificación del catalizador en ×1.25');

// ===========================================================================
echo "\n[Test 3] Sobreescritura No Reactiva (RF-03.3)\n";
// ===========================================================================

$overwritten = $service->resolveCombo('fire', spellOf('light', 40), false);
assert_truthy(!$overwritten->isReaction, 'Luz sobre Fuego no detona reacción alguna');
assert_truthy($overwritten->effectiveDamage === 40, 'El daño base se aplica íntegro, sin bonificación');
assert_truthy($overwritten->tacticalEffectApplied === 'auraOverwritten', 'El aura previa queda sobreescrita');
assert_truthy($overwritten->resultingAura === 'light', 'El aura resultante es la del conjuro entrante (Luz)');

// ===========================================================================
echo "\n[Test 4] Cálculo del +50% de daño (RF-04.1)\n";
// ===========================================================================

$combo = $service->resolveCombo('fire', spellOf('water', 40), false);
assert_truthy($combo->effectiveDamage === 60, 'Un conjuro de 40 inflije exactamente ceil(40 × 1.5) = 60');
assert_truthy($combo->damageMultiplierApplied === 1.5, 'El factor aplicado es el comboDamageMultiplier 1.5');

$oddCombo = $service->resolveCombo('fire', spellOf('water', 41), false);
assert_truthy($oddCombo->effectiveDamage === 62, 'Un conjuro de 41 inflije ceil(61.5) = 62: el redondeo siempre favorece al impacto');

// ===========================================================================
echo "\n[Test 5] Fractura Basáltica y Barreras (RF-04.2)\n";
// ===========================================================================

$fracture = $service->resolveCombo('earth', spellOf('lightning', 40), false);
assert_truthy($fracture->isReaction && $fracture->reactionId === 'basalticFracture', 'Tierra + Rayo detona la Fractura Basáltica');
assert_truthy($fracture->tacticalEffectApplied === 'barrierShatter', 'El efecto táctico es la trituración de barrera (barrierShatter)');
assert_truthy($fracture->effectiveDamage === 60, 'El daño del combo es ceil(40 × 1.5) = 60, sin alteración alguna por la barrera');

$fractureFicha = null;
foreach ($service->getMatrixGraph()->reactions as $reaction) {
    if ($reaction->id === 'basalticFracture') {
        $fractureFicha = $reaction;
    }
}
assert_truthy($fractureFicha !== null && $fractureFicha->barrierDamage === 50, 'La trituración de hasta 50 PV viaja como dato del Códice (fuente única)');
assert_truthy($fracture->isNeutralOutcome(), 'La detonación deja neutral puro: sin auras residuales ni excedentes');
$fractureKeys = array_keys($fracture->jsonSerialize());
assert_truthy(
    !in_array('barrierDamage', $fractureKeys, true) && !in_array('barrierShatterAmount', $fractureKeys, true),
    'El veredicto no porta clave alguna de excedente: una barrera de 20 PV se anula sin transferir daño a la salud',
);

// ===========================================================================
echo "\n[Test 6] Colapso Crepuscular (RF-04.2)\n";
// ===========================================================================

$collapse = $service->resolveCombo('light', spellOf('darkness', 40), false);
assert_truthy($collapse->isReaction && $collapse->reactionId === 'twilightCollapse', 'Luz + Oscuridad detona el Colapso Crepuscular');
assert_truthy($collapse->tacticalEffectApplied === 'barrierPiercing', 'El efecto táctico es la penetración de barrera (barrierPiercing)');
assert_truthy($collapse->effectiveDamage === 60, 'El daño puro aplica el +50% íntegro (60): atraviesa los escudos y toca la salud');

$collapseFicha = null;
foreach ($service->getMatrixGraph()->reactions as $reaction) {
    if ($reaction->id === 'twilightCollapse') {
        $collapseFicha = $reaction;
    }
}
assert_truthy($collapseFicha !== null && $collapseFicha->barrierDamage === null, 'La ficha del Códice no declara trituración: la barrera del blanco queda INTACTA tras el colapso');

// ===========================================================================
echo "\n[Cierre] Determinismo (RNF-01) y cero advertencias (Dogma Vanilla)\n";
// ===========================================================================

$firstCall = json_encode($service->resolveCombo('fire', spellOf('water', 40), false), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
$secondCall = json_encode($service->resolveCombo('fire', spellOf('water', 40), false), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
assert_truthy($firstCall === $secondCall, 'Dos resoluciones idénticas producen veredictos JSON idénticos byte a byte');
assert_truthy($warnings === [], 'Todo el protocolo corre sin ninguna advertencia de PHP');

// ---------------------------------------------------------------------------
echo "\n== RESUMEN ==\n";
echo "Asertos superados: {$assertionsPassed}, fallidos: {$assertionsFailed}\n";

if ($assertionsFailed > 0) {
    echo "\nFALLOS:\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
    echo "\nRESULTADO: FRACASO — El protocolo 6.1 detecta desviaciones en la alquimia arcana.\n";
    exit(1);
}

echo "\nRESULTADO: ÉXITO — Los seis Tests canónicos del plan 6.1 pasan al 100% (Tarea 1.5).\n";
exit(0);
