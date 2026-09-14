<?php

/**
 * test_elemental_matrix_service.php — Arnés TDD de la Tarea 1.2 (TASKS-06).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `src/Services/ElementalMatrixService.php`:
 *   [1] Superficie del servicio: clase final, métodos públicos exigidos
 *       (getMatrixGraph, getReactionsForElement, findReaction) e instancia
 *       repetible sin estado mutable visible.
 *   [2] El Códice completo (getMatrixGraph): 8 elementos canónicos con sus
 *       atributos heráldicos exactos del plan 2.1 y 8 aristas reactivas
 *       (7 duales + 1 catalizador) serializables a JSON sin advertencias.
 *   [3] La simetría canónica A+B = B+A (RF-03.1): los tres pares ordenados
 *       exigidos por el «Hecho cuando» y TODOS los pares de las 7 reacciones
 *       duales devuelven idéntico resultado (mismo id, mismo objeto JSON).
 *   [4] findReaction con el propio elemento, elementos incompatibles,
 *       elementos desconocidos y el catalizador unario (null en todos).
 *   [5] getReactionsForElement: solo 4 elementos del canon poseen aristas
 *       duales (fire, water, lightning, earth, wind, light, darkness) y cada
 *       ficha es la correcta; elementos sin aristas devuelven lista vacía.
 *   [6] Inmutabilidad y determinismo (RNF-01): dos instancias distintas del
 *       servicio generan Códices JSON idénticos byte a byte.
 *   [7] Cero advertencias de PHP durante toda la batería (Dogma Vanilla).
 *
 * Criterio «Hecho cuando» (Tarea 1.2): invocar findReaction('fire',
 * 'water') y findReaction('water', 'fire') devuelve idéntico resultado de
 * Vaporización Arcana, y buscar elementos incompatibles devuelve null.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): solo PHP nativo, sin librerías.
 *   - Artículo II: la tabla de reacciones viaja como dato leído del Códice,
 *     no como decisión del motor.
 *   - Artículo IV: nombres litúrgicos y descripciones en castellano solemne.
 *   - Artículo V: identificadores en inglés camelCase; documentación en
 *     castellano.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Dto/ElementalReactionDto.php';
require_once __DIR__ . '/../src/Dto/ElementalMatrixGraphDto.php';
require_once __DIR__ . '/../src/Dto/ComboResolutionResultDto.php';
require_once __DIR__ . '/../src/Services/ElementalMatrixService.php';

use Grimorio\Services\ElementalMatrixService;

/** Contadores de asertos. */
$assertionsPassed = 0;
$assertionsFailed = 0;
/** @var list<string> */
$failures = [];
/** @var list<string> */
$warnings = [];

/**
 * Convierte cada advertencia de PHP en materia de aserto (fase 7).
 */
set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$warnings): bool {
    $warnings[] = "{$message} (en {$file}:{$line})";
    return true;
});

/**
 * Aserto universal: acumula, no aborta, para ver la batería completa.
 */
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
 * Compara dos estructuras por su forma JSON canónica (determinismo).
 */
function assert_same_json(mixed $expected, mixed $actual, string $label): void
{
    $expectedJson = json_encode($expected, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    $actualJson = json_encode($actual, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    assert_truthy($expectedJson === $actualJson, $label);
}

echo "== ARNÉS TDD — SERVICIO DE LA MATRIZ ELEMENTAL (Tarea 1.2, SPEC-06) ==\n";

// ---------------------------------------------------------------------------
echo "\n[FASE 1] Superficie del servicio\n";
// ---------------------------------------------------------------------------

assert_truthy(class_exists(ElementalMatrixService::class), 'La clase ElementalMatrixService existe (fase roja si falta)');
assert_truthy(method_exists(ElementalMatrixService::class, 'getMatrixGraph'), 'getMatrixGraph() está declarado');
assert_truthy(method_exists(ElementalMatrixService::class, 'getReactionsForElement'), 'getReactionsForElement() está declarado');
assert_truthy(method_exists(ElementalMatrixService::class, 'findReaction'), 'findReaction() está declarado');

$service = new ElementalMatrixService();
assert_truthy($service instanceof ElementalMatrixService, 'El servicio se instancia sin dependencias');

// ---------------------------------------------------------------------------
echo "\n[FASE 2] El Códice completo (getMatrixGraph)\n";
// ---------------------------------------------------------------------------

$matrixGraph = $service->getMatrixGraph();
assert_truthy($matrixGraph instanceof \Grimorio\Dto\ElementalMatrixGraphDto, 'getMatrixGraph() devuelve el DTO del grafo');

$serializedGraph = $matrixGraph->jsonSerialize();
assert_truthy(array_keys($serializedGraph) === ['elements', 'reactions'], 'El grafo serializa exactamente {elements, reactions}');

// Los 8 elementos canónicos, en el orden del plan 2.1, con atributos exactos.
assert_same_json(
    [
        ['id' => 'fire', 'name' => 'Fuego', 'color' => '#ff4500', 'glyph' => 'rune-ignis'],
        ['id' => 'water', 'name' => 'Agua / Escarcha', 'color' => '#00bfff', 'glyph' => 'rune-aqua'],
        ['id' => 'lightning', 'name' => 'Rayo', 'color' => '#9932cc', 'glyph' => 'rune-fulgur'],
        ['id' => 'earth', 'name' => 'Tierra', 'color' => '#8b4513', 'glyph' => 'rune-terra'],
        ['id' => 'wind', 'name' => 'Viento', 'color' => '#2e8b57', 'glyph' => 'rune-ventus'],
        ['id' => 'light', 'name' => 'Luz', 'color' => '#ffd700', 'glyph' => 'rune-lux'],
        ['id' => 'darkness', 'name' => 'Oscuridad', 'color' => '#4b0082', 'glyph' => 'rune-tenebrae'],
        ['id' => 'pureArcane', 'name' => 'Arcano Puro', 'color' => '#4169e1', 'glyph' => 'rune-arcana'],
    ],
    $serializedGraph['elements'],
    'Los 8 elementos portan su nombre litúrgico, color heráldico y glifo exactos del Códice',
);

// ---------------------------------------------------------------------------
echo "\n[FASE 3] Simetría canónica A+B = B+A (RF-03.1)\n";
// ---------------------------------------------------------------------------

$fireWater = $service->findReaction('fire', 'water');
$waterFire = $service->findReaction('water', 'fire');

assert_truthy($fireWater instanceof \Grimorio\Dto\ElementalReactionDto, "findReaction('fire', 'water') devuelva Vaporización Arcana");
assert_truthy($waterFire instanceof \Grimorio\Dto\ElementalReactionDto, "findReaction('water', 'fire') también la devuelve");

assert_truthy(
    $fireWater !== null && $fireWater->id === 'arcaneVaporization',
    "Ambas orientaciones resuelven la reacción arcaneVaporization",
);
assert_truthy($fireWater !== null && $fireWater->name === 'Vaporización Arcana', 'El nombre litúrgico es Vaporización Arcana (Artículo IV)');
assert_truthy($fireWater !== null && $fireWater->damageMultiplier === 1.5, 'El factor de daño del Códice es ×1.5 (+50%)');
assert_truthy($fireWater !== null && $fireWater->tacticalEffect === 'blindnessMist', 'El efecto táctico es la niebla cegadora (blindnessMist)');

// Toda reacción dual es simétrica: se recorren los 7 pares en ambas orientaciones.
$expectedDualPairs = [
    ['fire', 'water', 'arcaneVaporization'],
    ['water', 'lightning', 'fluidElectrocution'],
    ['fire', 'wind', 'vortexDeflagration'],
    ['earth', 'lightning', 'basalticFracture'],
    ['earth', 'water', 'petrifyingSwamp'],
    ['wind', 'water', 'glacialBlizzard'],
    ['light', 'darkness', 'twilightCollapse'],
];
foreach ($expectedDualPairs as [$elementA, $elementB, $reactionId]) {
    $forward = $service->findReaction($elementA, $elementB);
    $reverse = $service->findReaction($elementB, $elementA);
    assert_truthy($forward !== null && $reverse !== null, "{$elementA} + {$elementB} resuelve una reacción en ambas orientaciones");
    assert_truthy(
        $forward !== null && $reverse !== null && $forward->id === $reactionId && $reverse->id === $reactionId,
        "{$elementA} + {$elementB} y {$elementB} + {$elementA} resuelven {$reactionId}",
    );
    assert_same_json($forward, $reverse, "{$elementA} + {$elementB} = {$elementB} + {$elementA} (JSON idéntico)");
}

// ---------------------------------------------------------------------------
echo "\n[FASE 4] Fronteras de findReaction\n";
// ---------------------------------------------------------------------------

assert_truthy($service->findReaction('fire', 'fire') === null, 'Un elemento contra sí mismo no detona reacción (null)');
assert_truthy($service->findReaction('light', 'fire') === null, "Elementos incompatibles (Luz + Fuego) devuelven null");
assert_truthy($service->findReaction('darkness', 'wind') === null, "Elementos incompatibles (Oscuridad + Viento) devuelven null");
assert_truthy($service->findReaction('steam', 'fire') === null, 'Un elemento desconocido jamás detona reacción');
assert_truthy($service->findReaction('', 'fire') === null, 'Una cadena vacía jamás detona reacción');
assert_truthy($service->findReaction('pureArcane', 'fire') === null, 'El catalizador unario no forma pares duales (null)');

// ---------------------------------------------------------------------------
echo "\n[FASE 5] getReactionsForElement\n";
// ---------------------------------------------------------------------------

$fireReactions = $service->getReactionsForElement('fire');
assert_truthy(is_array($fireReactions) && count($fireReactions) === 2, 'Fuego reacciona con exactamente 2 elementos (Agua y Viento)');

$waterReactions = $service->getReactionsForElement('water');
assert_truthy(is_array($waterReactions) && count($waterReactions) === 4, 'Agua reacciona con exactamente 4 elementos (Fuego, Rayo, Tierra y Viento)');

$fireReactionIds = array_map(static fn ($r) => $r->id, $fireReactions);
sort($fireReactionIds);
assert_truthy(
    $fireReactionIds === ['arcaneVaporization', 'vortexDeflagration'],
    'Las aristas de Fuego son Vaporización Arcana y Deflagración en Vórtice',
);

assert_truthy($service->getReactionsForElement('pureArcane') === [], 'Arcano Puro no declara aristas duales propias');
assert_truthy($service->getReactionsForElement('steam') === [], 'Un elemento desconocido devuelve lista vacía (nunca null)');
$lightReactions = $service->getReactionsForElement('light');
assert_truthy(is_array($lightReactions) && count($lightReactions) === 1 && $lightReactions[0]->id === 'twilightCollapse', 'La arista de Luz es el Colapso Crepuscular');

// ---------------------------------------------------------------------------
echo "\n[FASE 6] Determinismo e inmutabilidad (RNF-01)\n";
// ---------------------------------------------------------------------------

$firstInstance = new ElementalMatrixService();
$secondInstance = new ElementalMatrixService();
assert_same_json($firstInstance->getMatrixGraph(), $secondInstance->getMatrixGraph(), 'Dos instancias forjan Códices JSON idénticos byte a byte');

$before = json_encode($service->getMatrixGraph(), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
$service->getMatrixGraph();
$service->findReaction('fire', 'water');
$service->getReactionsForElement('water');
$after = json_encode($service->getMatrixGraph(), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
assert_truthy($before === $after, 'Consultas repetidas no mutan el Códice (inmutabilidad)');

// ---------------------------------------------------------------------------
echo "\n[FASE 7] Cero advertencias de PHP (Dogma Vanilla)\n";
// ---------------------------------------------------------------------------

assert_truthy($warnings === [], 'La batería completa corre sin ninguna advertencia de PHP');

// ---------------------------------------------------------------------------
echo "\n== RESUMEN ==\n";
echo "Asertos superados: {$assertionsPassed}, fallidos: {$assertionsFailed}\n";

if ($assertionsFailed > 0) {
    echo "\nFALLOS:\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
    echo "\nRESULTADO: FRACASO — El servicio de la Matriz Elemental aún no cumple el contrato de la Tarea 1.2.\n";
    exit(1);
}

echo "\nRESULTADO: ÉXITO — El servicio de la Matriz Elemental cumple la simetría A+B = B+A y el contrato del Códice (Tarea 1.2).\n";
exit(0);
