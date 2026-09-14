<?php

/**
 * test_elemental_combo_resolution.php — Arnés TDD de la Tarea 1.3 (TASKS-06).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `src/Services/ElementalMatrixService.php::resolveCombo()`:
 *   [1] Superficie: método resolveCombo declarado y DTO SpellImpactData
 *       (payload del Endpoint 3 del plan 2.1) existente y validado.
 *   [2] Caso A — Blanco neutral (RF-02.1): el conjuro imbuye su aura
 *       (resultingAura = elemento); Arcano Puro sobre neutral no imbuye
 *       aura ni detona nada.
 *   [3] Caso B — Mismo elemento (RF-02.4): refresco del aura a 5 s con
 *       daño pleno, sin reacción.
 *   [4] Caso C — Catalizador (RF-03.2): Resonancia Arcana Pura, +25%
 *       (ceil), consume el aura y deja neutral puro.
 *   [5] Tests 1 y 4 del plan — Reacción dual simétrica y +50% (RF-03.1,
 *       RF-04.1): ceil(40 × 1.5) = 60 exactos en ambas orientaciones.
 *   [6] Test 5 del plan — Fractura Basáltica y barreras (RF-04.2): la
 *       trituración (50 PV) viaja como dato del Códice y el excedente no
 *       se transfiere al daño (el veredicto no añade daño alguno).
 *   [7] Test 6 del plan — Colapso Crepuscular (RF-04.2): penetración de
 *       barrera, daño pleno ×1.5 sin duración.
 *   [8] Test 3 del plan — Sobreescritura no reactiva (RF-03.3): Luz sobre
 *       Fuego aplica daño íntegro y sobreescribe el aura.
 *   [9] Salvaguarda Anti-Stunlock (RF-05.2, RF-05.3): Hard CC concede
 *       inmunidad de 3 s; con inmunidad activa el Hard CC se suprime pero
 *       el +50% de daño se aplica íntegro; el Soft CC pasa normal.
 *  [10] Contrato JSON del Endpoint 3: las once claves, en orden canónico,
 *       con null explícito cuando no procede.
 *  [11] Determinismo (RNF-01): llamadas repetidas → JSON idéntico; cero
 *       advertencias de PHP en toda la batería.
 *
 * Criterio «Hecho cuando» (Tarea 1.3): el método aplica exactamente las
 * reglas canónicas de daño, trituración de barrera y penetración sin tocar
 * la vida si la fractura tiene excedente, devolviendo el DTO tipado
 * correspondiente.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): solo PHP nativo.
 *   - Artículo II: los factores (×1.5, ×1.25, +1 s) se LEEN del Códice;
 *     la única aritmética permitida es ceil(base × factor).
 *   - Artículo IV: nombres litúrgicos en castellano solemne.
 *   - Artículo V: identificadores en inglés camelCase; documentación en
 *     castellano.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Dto/ElementalReactionDto.php';
require_once __DIR__ . '/../src/Dto/ElementalMatrixGraphDto.php';
require_once __DIR__ . '/../src/Dto/ComboResolutionResultDto.php';
require_once __DIR__ . '/../src/Dto/SpellImpactData.php';
require_once __DIR__ . '/../src/Services/ElementalMatrixService.php';

use Grimorio\Dto\ComboResolutionResultDto;
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

/** Fábrica de impactos de prueba: conjuro de Agua con 40 de daño base. */
function waterSpell(int $baseDamage = 40): SpellImpactData
{
    return new SpellImpactData(
        id: 'spl_water_01',
        element: 'water',
        baseDamage: $baseDamage,
    );
}

echo "== ARNÉS TDD — RESOLUCIÓN AUTORITATIVA DE COMBOS (Tarea 1.3, SPEC-06) ==\n";

// ---------------------------------------------------------------------------
echo "\n[FASE 1] Superficie del método y del payload\n";
// ---------------------------------------------------------------------------

assert_truthy(method_exists(ElementalMatrixService::class, 'resolveCombo'), 'resolveCombo() está declarado (fase roja si falta)');
assert_truthy(class_exists(SpellImpactData::class), 'El DTO SpellImpactData (payload del Endpoint 3) existe');

$service = new ElementalMatrixService();

// ---------------------------------------------------------------------------
echo "\n[FASE 2] Caso A — Blanco neutral (RF-02.1)\n";
// ---------------------------------------------------------------------------

$neutral = $service->resolveCombo('', waterSpell(), false);
assert_truthy($neutral instanceof ComboResolutionResultDto, 'Devuelve el DTO tipado ComboResolutionResultDto');
assert_truthy($neutral->isReaction === false, 'Sobre blanco neutral no detona reacción');
assert_truthy($neutral->effectiveDamage === 40, 'El daño efectivo es el daño base íntegro (40)');
assert_truthy($neutral->damageMultiplierApplied === 1.0, 'El factor aplicado es la unidad (1.0)');
assert_truthy($neutral->resultingAura === 'water', 'El conjuro imbuye su aura elemental (Agua)');
assert_truthy($neutral->clearedAura === false, 'No consume energías: la aura nace, no muere');

$arcaneOnNeutral = $service->resolveCombo('', new SpellImpactData('spl_arcane_01', 'pureArcane', 20), false);
assert_truthy($arcaneOnNeutral->isReaction === false, 'Arcano Puro sobre blanco neutral no detona resonancia (no hay aura que consumir)');
assert_truthy($arcaneOnNeutral->resultingAura === null, 'Arcano Puro no imbuye aura (catalizador, no elemento de imbución)');

// ---------------------------------------------------------------------------
echo "\n[FASE 3] Caso B — Mismo elemento, refresco del aura (RF-02.4)\n";
// ---------------------------------------------------------------------------

$refreshed = $service->resolveCombo('water', waterSpell(), false);
assert_truthy($refreshed->isReaction === false, 'El mismo elemento no detona reacción consigo mismo');
assert_truthy($refreshed->tacticalEffectApplied === 'auraRefreshed', 'El efecto aplicado es el refresco del aura (auraRefreshed)');
assert_truthy($refreshed->resultingAura === 'water', 'El aura persiste con su elemento');
assert_truthy($refreshed->effectiveDamage === 40, 'El daño se aplica pleno, sin bonificación');

// ---------------------------------------------------------------------------
echo "\n[FASE 4] Caso C — Catalizador Arcano Puro (RF-03.2)\n";
// ---------------------------------------------------------------------------

$resonance = $service->resolveCombo('fire', new SpellImpactData('spl_arcane_02', 'pureArcane', 40), false);
assert_truthy($resonance->isReaction === true, 'Arcano Puro sobre aura activa detona reacción');
assert_truthy($resonance->reactionId === 'pureArcaneResonance', 'La reacción es la Resonancia Arcana Pura');
assert_truthy($resonance->reactionName === 'Resonancia Arcana Pura', 'El nombre litúrgico viaja en castellano (Artículo IV)');
assert_truthy($resonance->effectiveDamage === 50, 'La amplificación es ceil(40 × 1.25) = 50');
assert_truthy($resonance->damageMultiplierApplied === 1.25, 'El factor del catalizador es 1.25');
assert_truthy($resonance->tacticalEffectApplied === 'amplification', 'El efecto táctico es la amplificación');
assert_truthy($resonance->clearedAura === true && $resonance->resultingAura === null, 'Consume el aura y deja neutral puro (RF-03.4)');

// ---------------------------------------------------------------------------
echo "\n[FASE 5] Reacción dual simétrica y +50% (Tests 1 y 4 del plan, RF-03.1 / RF-04.1)\n";
// ---------------------------------------------------------------------------

$fireAuraWaterSpell = $service->resolveCombo('fire', waterSpell(), false);
$waterAuraFireSpell = $service->resolveCombo('water', new SpellImpactData('spl_fire_01', 'fire', 40), false);

assert_truthy($fireAuraWaterSpell->isReaction === true, 'Fuego + Agua detona reacción');
assert_truthy($fireAuraWaterSpell->reactionId === 'arcaneVaporization', 'La reacción es la Vaporización Arcana');
assert_truthy($waterAuraFireSpell->reactionId === 'arcaneVaporization', 'La orientación inversa (Agua + Fuego) resuelve la misma reacción');
assert_truthy($fireAuraWaterSpell->effectiveDamage === 60, 'La bonificación es ceil(40 × 1.5) = 60 exactos');
assert_truthy($fireAuraWaterSpell->damageMultiplierApplied === 1.5, 'El factor del combo es 1.5');
assert_truthy($fireAuraWaterSpell->tacticalEffectApplied === 'blindnessMist', 'El efecto táctico es la niebla cegadora');
assert_truthy($fireAuraWaterSpell->effectDurationMs === 3000, 'La niebla dura exactamente 3 segundos');
assert_truthy($fireAuraWaterSpell->clearedAura === true && $fireAuraWaterSpell->resultingAura === null, 'La detonación deja neutral puro (RF-03.4)');

// ---------------------------------------------------------------------------
echo "\n[FASE 6] Fractura Basáltica y barreras (Test 5 del plan, RF-04.2)\n";
// ---------------------------------------------------------------------------

$fracture = $service->resolveCombo('earth', new SpellImpactData('spl_lightning_01', 'lightning', 40), false);
assert_truthy($fracture->reactionId === 'basalticFracture', 'Tierra + Rayo detona la Fractura Basáltica');
assert_truthy($fracture->tacticalEffectApplied === 'barrierShatter', 'El efecto táctico es la trituración de barrera');
assert_truthy($fracture->effectiveDamage === 60, 'El daño del combo es ceil(40 × 1.5) = 60, sin alteración por la barrera');

// El excedente jamás se transfiere: la trituración (50) viaja como dato del
// Códice y el veredicto no añade daño alguno, tenga la barrera 20 o 500 PV.
$codice = $service->getMatrixGraph();
$fractureFicha = null;
foreach ($codice->reactions as $reaction) {
    if ($reaction->id === 'basalticFracture') {
        $fractureFicha = $reaction;
    }
}
assert_truthy($fractureFicha !== null && $fractureFicha->barrierDamage === 50, 'La trituración de 50 PV se lee de la ficha del Códice (fuente única)');
$verdictKeys = array_keys($fracture->jsonSerialize());
assert_truthy($verdictKeys === [
    'isReaction', 'reactionId', 'reactionName', 'effectiveDamage', 'damageMultiplierApplied',
    'tacticalEffectApplied', 'effectDurationMs', 'clearedAura', 'resultingAura',
    'stunlockTriggered', 'grantStunlockImmunity',
], 'El veredicto porta exactamente las once claves del contrato (ninguna de excedente)');

// ---------------------------------------------------------------------------
echo "\n[FASE 7] Colapso Crepuscular (Test 6 del plan, RF-04.2)\n";
// ---------------------------------------------------------------------------

$collapse = $service->resolveCombo('light', new SpellImpactData('spl_dark_01', 'darkness', 40), false);
assert_truthy($collapse->reactionId === 'twilightCollapse', 'Luz + Oscuridad detona el Colapso Crepuscular');
assert_truthy($collapse->tacticalEffectApplied === 'barrierPiercing', 'El efecto táctico es la penetración de barrera');
assert_truthy($collapse->effectiveDamage === 60, 'El daño puro aplica el +50% íntegro (60)');
assert_truthy($collapse->effectDurationMs === 0, 'La penetración no añade duración propia');

// ---------------------------------------------------------------------------
echo "\n[FASE 8] Sobreescritura no reactiva (Test 3 del plan, RF-03.3)\n";
// ---------------------------------------------------------------------------

$overwritten = $service->resolveCombo('fire', new SpellImpactData('spl_light_01', 'light', 40), false);
assert_truthy($overwritten->isReaction === false, 'Luz sobre Fuego no detona reacción (no son compatibles)');
assert_truthy($overwritten->effectiveDamage === 40, 'El daño base se aplica íntegro, sin bonificación');
assert_truthy($overwritten->tacticalEffectApplied === 'auraOverwritten', 'El aura previa queda sobreescrita');
assert_truthy($overwritten->resultingAura === 'light', 'El aura nueva es la del conjuro entrante (Luz)');
assert_truthy($overwritten->clearedAura === false, 'La sobreescritura no es consumo de energías');

// ---------------------------------------------------------------------------
echo "\n[FASE 9] Salvaguarda Anti-Stunlock (RF-05.2, RF-05.3)\n";
// ---------------------------------------------------------------------------

// Hard CC sin inmunidad: aturde y concede inmunidad rúnica de 3 s.
$stunApplied = $service->resolveCombo('water', new SpellImpactData('spl_lightning_02', 'lightning', 40), false);
assert_truthy($stunApplied->reactionId === 'fluidElectrocution', 'Agua + Rayo detona la Electrocución Fluida');
assert_truthy($stunApplied->tacticalEffectApplied === 'hardStun', 'El Hard CC es el aturdimiento fulgurante');
assert_truthy($stunApplied->stunlockTriggered === false, 'Sin inmunidad previa, el aturdimiento se aplica (no se suprime)');
assert_truthy($stunApplied->grantStunlockImmunity === true, 'Tras el Hard CC se concede la Inmunidad Rúnica (RF-05.2)');
assert_truthy($stunApplied->effectiveDamage === 60, 'El daño del combo se aplica con la bonificación plena');

// Hard CC con inmunidad activa: daño +50% íntegro, parálisis suprimida.
$stunSuppressed = $service->resolveCombo('water', new SpellImpactData('spl_lightning_03', 'lightning', 40), true);
assert_truthy($stunSuppressed->stunlockTriggered === true, 'Con inmunidad activa, el Hard CC queda suprimido (RF-05.3)');
assert_truthy($stunSuppressed->grantStunlockImmunity === false, 'No se re-concede inmunidad (no se apila)');
assert_truthy($stunSuppressed->effectiveDamage === 60, 'El +50% de daño se aplica íntegro pese a la salvaguarda');

// Congelación de la Ventisca Helada: también es Hard CC.
$freezeSuppressed = $service->resolveCombo('wind', new SpellImpactData('spl_water_02', 'water', 40), true);
assert_truthy($freezeSuppressed->reactionId === 'glacialBlizzard', 'Viento + Agua detona la Ventisca Helada');
assert_truthy($freezeSuppressed->stunlockTriggered === true, 'La congelación también queda suprimida por la inmunidad');

// Soft CC con inmunidad: se aplica con normalidad (RF-05.3).
$softCc = $service->resolveCombo('fire', waterSpell(), true);
assert_truthy($softCc->tacticalEffectApplied === 'blindnessMist', 'El Soft CC (niebla) se aplica con normalidad bajo inmunidad');
assert_truthy($softCc->stunlockTriggered === false && $softCc->grantStunlockImmunity === false, 'El Soft CC ni se suprime ni concede inmunidad');

// ---------------------------------------------------------------------------
echo "\n[FASE 10] Contrato JSON del Endpoint 3 (plan 2.1)\n";
// ---------------------------------------------------------------------------

$sanctuary = $service->resolveCombo('fire', waterSpell(), false);
$sanctuaryJson = json_encode($sanctuary, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
$sanctuaryDecoded = json_decode($sanctuaryJson, true, 512, JSON_THROW_ON_ERROR);
assert_truthy(
    array_keys($sanctuaryDecoded) === ['isReaction', 'reactionId', 'reactionName', 'effectiveDamage', 'damageMultiplierApplied', 'tacticalEffectApplied', 'effectDurationMs', 'clearedAura', 'resultingAura', 'stunlockTriggered', 'grantStunlockImmunity'],
    'Las once claves viajan SIEMPRE, en el orden canónico del plan',
);
assert_truthy(
    array_key_exists('reactionId', $sanctuaryDecoded) && $sanctuaryDecoded['reactionId'] === 'arcaneVaporization'
    && $sanctuaryDecoded['reactionName'] === 'Vaporización Arcana',
    'El combo identificado porta su identificador y su nombre litúrgico',
);
$neutralJson = json_decode(json_encode($service->resolveCombo('', waterSpell(), false), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
assert_truthy($neutralJson['reactionId'] === null && $neutralJson['tacticalEffectApplied'] === null, 'Sin reacción, los campos opcionales viajan como null explícito');

// ---------------------------------------------------------------------------
echo "\n[FASE 11] Determinismo y cero advertencias (RNF-01, Dogma Vanilla)\n";
// ---------------------------------------------------------------------------

$firstCall = json_encode($service->resolveCombo('fire', waterSpell(), false), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
$secondCall = json_encode($service->resolveCombo('fire', waterSpell(), false), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
assert_truthy($firstCall === $secondCall, 'Dos llamadas idénticas producen veredictos JSON idénticos byte a byte');
assert_truthy($warnings === [], 'La batería completa corre sin ninguna advertencia de PHP');

// ---------------------------------------------------------------------------
echo "\n== RESUMEN ==\n";
echo "Asertos superados: {$assertionsPassed}, fallidos: {$assertionsFailed}\n";

if ($assertionsFailed > 0) {
    echo "\nFALLOS:\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
    echo "\nRESULTADO: FRACASO — La resolución de combos aún no cumple el contrato de la Tarea 1.3.\n";
    exit(1);
}

echo "\nRESULTADO: ÉXITO — La resolución autoritativa de combos cumple las reglas canónicas del plan (Tarea 1.3).\n";
exit(0);
