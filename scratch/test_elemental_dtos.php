<?php

/**
 * test_elemental_dtos.php — Arnés TDD de la Tarea 1.1 (TASKS-06).
 *
 * Estrategia TDD: este script se escribe ANTES que los DTOs.
 * Verifica, sobre `src/Dto/ElementalReactionDto.php`,
 * `src/Dto/ElementalMatrixGraphDto.php` y
 * `src/Dto/ComboResolutionResultDto.php`:
 *   [1] Superficie: tipado estricto, namespace, clases `final readonly` y
 *       serialización JSON nativa (Artículo I: cero librerías).
 *   [2] ElementalReactionDto — reacción dual canónica (Vaporización Arcana)
 *       con sus claves del Códice y sin claves de catalizador.
 *   [3] ElementalReactionDto — campos opcionales (Fractura Basáltica,
 *       Ciénaga Petrificante) que solo viajan cuando existen.
 *   [4] ElementalReactionDto — catalizador universal (Resonancia Arcana
 *       Pura): isCatalyst, amplificación 1.25 y extensión de 1 s.
 *   [5] Validaciones de dominio: identificadores vacíos, magnitudes
 *       negativas y aridad elemental incoherente.
 *   [6] ElementalMatrixGraphDto — el grafo del Códice (8 elementos y sus
 *       aristas reactivas) con anidado fiel y validación de integridad.
 *   [7] ComboResolutionResultDto — las 11 claves del contrato del plan
 *       (Sec. 2.1, Endpoint 3), siempre presentes e íntegras.
 *   [8] ComboResolutionResultDto — los cuatro desenlaces tácticos:
 *       reacción, sobreescritura, refresco y salvaguarda anti-stunlock.
 *   [9] Coherencia táctica: reacción consumida deja estado neutral.
 *  [10] json_encode sin advertencias de tipo y fidelidad decimal.
 *
 * Criterio «Hecho cuando» (Tarea 1.1): los tres DTOs se instancian
 * correctamente, validan tipos estrictos y json_encode() genera la
 * estructura JSON normalizada del plan sin advertencias de tipo.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): json_encode nativo, sin librerías.
 *   - Artículo V: identificadores en inglés camelCase; documentación y
 *     leyendas en castellano.
 *
 * Uso: php scratch/test_elemental_dtos.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dtosRequired = [
    'ElementalReactionDto' => __DIR__ . '/../src/Dto/ElementalReactionDto.php',
    'ElementalMatrixGraphDto' => __DIR__ . '/../src/Dto/ElementalMatrixGraphDto.php',
    'ComboResolutionResultDto' => __DIR__ . '/../src/Dto/ComboResolutionResultDto.php',
];

foreach ($dtosRequired as $dtoName => $dtoPath) {
    if (!is_file($dtoPath)) {
        fwrite(STDERR, "[FATAL] Falta src/Dto/{$dtoName}.php — fase roja: aún no existe." . PHP_EOL);
        exit(1);
    }
}

require $dtosRequired['ElementalReactionDto'];
require $dtosRequired['ElementalMatrixGraphDto'];
require $dtosRequired['ComboResolutionResultDto'];

use Grimorio\Dto\ComboResolutionResultDto;
use Grimorio\Dto\ElementalMatrixGraphDto;
use Grimorio\Dto\ElementalReactionDto;

$assertsPassed = 0;
$assertsFailed = 0;

/** Advertencias y avisos de PHP capturados durante la prueba. */
$phpWarnings = [];

/** Convierte cualquier advertencia nativa en materia de aserto (no la silencia). */
set_error_handler(static function (int $severity, string $message): bool {
    global $phpWarnings;
    $phpWarnings[] = $message;
    return true;
});

/** Aserta una condición y la registra en la bitácora de consola. */
function assertCondition(bool $condition, string $description): void
{
    global $assertsPassed, $assertsFailed;
    if ($condition) {
        $assertsPassed++;
        echo "  [PASA] {$description}" . PHP_EOL;
    } else {
        $assertsFailed++;
        echo "  [FALLA] {$description}" . PHP_EOL;
    }
}

/**
 * Aserta que la llamada lanza InvalidArgumentException (validación de dominio).
 * @param callable(): mixed $forge
 */
function assertRejects(callable $forge, string $description): void
{
    try {
        $forge();
        assertCondition(false, $description . ' (no lanzó excepción)');
    } catch (InvalidArgumentException) {
        assertCondition(true, $description);
    } catch (Throwable $throwable) {
        assertCondition(false, $description . ' (lanzó ' . $throwable::class . ')');
    }
}

/**
 * Aserta que la llamada lanza TypeError (tipado estricto nativo de PHP).
 * @param callable(): mixed $forge
 */
function assertTypeRejected(callable $forge, string $description): void
{
    try {
        $forge();
        assertCondition(false, $description . ' (no lanzó excepción)');
    } catch (TypeError) {
        assertCondition(true, $description);
    } catch (Throwable $throwable) {
        assertCondition(false, $description . ' (lanzó ' . $throwable::class . ')');
    }
}

echo '== ARNÉS TDD: DTOs de la Matriz Elemental (Tarea 1.1, TASKS-06) ==' . PHP_EOL;

// =====================================================================
// [1] Superficie: tipado estricto, namespace, inmutabilidad y JSON nativo
// =====================================================================
echo PHP_EOL . '[1] Superficie de los tres DTOs' . PHP_EOL;

foreach ($dtosRequired as $dtoName => $dtoPath) {
    $source = (string) file_get_contents($dtoPath);
    assertCondition(
        str_contains($source, 'declare(strict_types=1);'),
        "{$dtoName} declara tipos estrictos (AGENTS.md 2.1)",
    );
    assertCondition(
        str_contains($source, 'namespace Grimorio\\Dto;'),
        "{$dtoName} vive en el namespace Grimorio\\Dto",
    );
    assertCondition(
        str_contains($source, 'final readonly class'),
        "{$dtoName} es final readonly (inmutable)",
    );
    assertCondition(
        str_contains($source, 'implements JsonSerializable'),
        "{$dtoName} serializa con la interfaz nativa JsonSerializable",
    );
}

// =====================================================================
// [2] Reacción dual canónica: Vaporización Arcana
// =====================================================================
echo PHP_EOL . '[2] ElementalReactionDto — reacción dual canónica' . PHP_EOL;

/** Vaporización Arcana: ficha canónica del plan 2.1. */
function buildArcaneVaporization(): ElementalReactionDto
{
    return new ElementalReactionDto(
        id: 'arcaneVaporization',
        name: 'Vaporización Arcana',
        elements: ['fire', 'water'],
        damageMultiplier: 1.5,
        tacticalEffect: 'blindnessMist',
        effectDurationMs: 3000,
        description: 'Emisión de vapor abrasador que reduce la precisión del objetivo durante 3 segundos.',
    );
}

$vaporization = buildArcaneVaporization();
assertCondition($vaporization->id === 'arcaneVaporization', 'id porta el identificador técnico camelCase (Artículo V)');
assertCondition($vaporization->name === 'Vaporización Arcana', 'name porta el nombre litúrgico en castellano (Artículo IV)');
assertCondition($vaporization->elements === ['fire', 'water'], 'elements porta los dos elementos de la reacción');
assertCondition($vaporization->damageMultiplier === 1.5, 'damageMultiplier porta el factor +50% como float (RF-04.1)');
assertCondition($vaporization->tacticalEffect === 'blindnessMist', 'tacticalEffect porta el efecto táctico canónico (RF-04.2)');
assertCondition($vaporization->effectDurationMs === 3000, 'effectDurationMs porta los 3 s de niebla abrasadora');
assertCondition($vaporization->barrierDamage === null, 'barrierDamage queda nulo cuando la reacción no tritura barrera');
assertCondition($vaporization->slowDurationMs === null, 'slowDurationMs queda nulo cuando la reacción no ralentiza');
assertCondition($vaporization->isCatalyst === false, 'isCatalyst queda falso en las reacciones duales');
assertCondition($vaporization->componentCount() === 2, 'componentCount() declara la aridad dual');

$vaporizationJson = json_encode($vaporization);
$vaporizationData = json_decode((string) $vaporizationJson, true);
assertCondition(
    array_keys($vaporizationData) === ['id', 'name', 'elements', 'damageMultiplier', 'tacticalEffect', 'effectDurationMs', 'description'],
    'json_encode emite EXACTAMENTE las claves del plan 2.1, en su orden canónico',
);
assertCondition($vaporizationData['damageMultiplier'] === 1.5, 'el JSON conserva el factor 1.5 sin transformación');
assertCondition(!array_key_exists('barrierDamage', $vaporizationData), 'el JSON omite barrierDamage cuando es nulo (sin campos nulos)');

// =====================================================================
// [3] Campos opcionales: Fractura Basáltica y Ciénaga Petrificante
// =====================================================================
echo PHP_EOL . '[3] ElementalReactionDto — campos opcionales por reacción' . PHP_EOL;

$fracture = new ElementalReactionDto(
    id: 'basalticFracture',
    name: 'Fractura Basáltica',
    elements: ['earth', 'lightning'],
    damageMultiplier: 1.5,
    tacticalEffect: 'barrierShatter',
    effectDurationMs: 0,
    barrierDamage: 50,
    description: 'Descarga de choque que tritura hasta 50 puntos de barrera mágica del objetivo.',
);
$fractureData = json_decode((string) json_encode($fracture), true);
assertCondition($fracture->barrierDamage === 50, 'Fractura Basáltica porta la trituración de 50 PV (RF-04.2)');
assertCondition($fractureData['barrierDamage'] === 50, 'la trituración viaja en el JSON de la ficha');
assertCondition(
    array_search('barrierDamage', array_keys($fractureData), true) > array_search('effectDurationMs', array_keys($fractureData), true),
    'barrierDamage se sitúa tras effectDurationMs (orden canónico)',
);

$swamp = new ElementalReactionDto(
    id: 'petrifyingSwamp',
    name: 'Ciénaga Petrificante',
    elements: ['earth', 'water'],
    damageMultiplier: 1.5,
    tacticalEffect: 'rootAndSlow',
    effectDurationMs: 3000,
    slowDurationMs: 4000,
    description: 'Inmovilización por enraizamiento de 3 s y reducción de velocidad al 50% por 4 s.',
);
$swampData = json_decode((string) json_encode($swamp), true);
assertCondition($swamp->slowDurationMs === 4000, 'Ciénaga Petrificante porta la ralentización de 4 s');
assertCondition($swampData['slowDurationMs'] === 4000, 'la ralentización viaja en el JSON de la ficha');
assertCondition(!array_key_exists('barrierDamage', $swampData), 'la ciénaga no porta clave de trituración');

// =====================================================================
// [4] Catalizador universal: Resonancia Arcana Pura
// =====================================================================
echo PHP_EOL . '[4] ElementalReactionDto — Catalizador de Arcano Puro' . PHP_EOL;

$resonance = new ElementalReactionDto(
    id: 'pureArcaneResonance',
    name: 'Resonancia Arcana Pura',
    elements: ['pureArcane'],
    isCatalyst: true,
    amplificationFactor: 1.25,
    ccExtensionMs: 1000,
    description: 'Catalizador universal que amplifica un 25% la magnitud y añade 1 s a la duración de controles.',
);
$resonanceData = json_decode((string) json_encode($resonance), true);
assertCondition($resonance->isCatalyst === true, 'isCatalyst marca el catalizador universal (RF-03.2)');
assertCondition($resonance->amplificationFactor === 1.25, 'amplificationFactor porta el +25% como float');
assertCondition($resonance->ccExtensionMs === 1000, 'ccExtensionMs porta el segundo adicional de control');
assertCondition($resonance->componentCount() === 1, 'el catalizador es unario (no tiene elemento opuesto)');
assertCondition(
    array_keys($resonanceData) === ['id', 'name', 'elements', 'isCatalyst', 'amplificationFactor', 'ccExtensionMs', 'description'],
    'la ficha del catalizador emite sus claves propias, sin multiplicador ni efecto táctico',
);
assertCondition(!array_key_exists('damageMultiplier', $resonanceData), 'el catalizador no porta damageMultiplier');

// =====================================================================
// [5] Validaciones de dominio del ElementalReactionDto
// =====================================================================
echo PHP_EOL . '[5] ElementalReactionDto — validaciones de dominio' . PHP_EOL;

assertRejects(
    static fn (): ElementalReactionDto => new ElementalReactionDto(id: '', name: 'X', elements: ['fire', 'water']),
    'rechaza un identificador vacío',
);
assertRejects(
    static fn (): ElementalReactionDto => new ElementalReactionDto(id: 'x', name: '  ', elements: ['fire', 'water']),
    'rechaza un nombre litúrgico vacío',
);
assertRejects(
    static fn (): ElementalReactionDto => new ElementalReactionDto(id: 'x', name: 'X', elements: []),
    'rechaza una reacción sin elementos',
);
assertRejects(
    static fn (): ElementalReactionDto => new ElementalReactionDto(id: 'x', name: 'X', elements: ['fire', 'water', 'wind']),
    'rechaza una aridad superior a dos elementos',
);
assertRejects(
    static fn (): ElementalReactionDto => new ElementalReactionDto(id: 'x', name: 'X', elements: ['fire', 'water'], damageMultiplier: 0.5),
    'rechaza un multiplicador inferior a la unidad',
);
assertRejects(
    static fn (): ElementalReactionDto => new ElementalReactionDto(id: 'x', name: 'X', elements: ['fire', 'water'], damageMultiplier: 1.5, effectDurationMs: -1),
    'rechaza una duración negativa',
);
assertRejects(
    static fn (): ElementalReactionDto => new ElementalReactionDto(id: 'x', name: 'X', elements: ['fire', 'water'], damageMultiplier: 1.5, barrierDamage: -50),
    'rechaza una trituración de barrera negativa',
);
assertRejects(
    static fn (): ElementalReactionDto => new ElementalReactionDto(id: 'x', name: 'X', elements: ['fire', 'water'], isCatalyst: true),
    'rechaza marcar catalizador una reacción dual',
);
assertTypeRejected(
    static fn (): ElementalReactionDto => new ElementalReactionDto(id: 'x', name: 'X', elements: ['fire', 'water'], damageMultiplier: 'mucho'),
    'el tipado estricto rechaza un multiplicador no numérico',
);

// =====================================================================
// [6] Grafo del Códice: elementos y aristas reactivas
// =====================================================================
echo PHP_EOL . '[6] ElementalMatrixGraphDto — grafo del Códice' . PHP_EOL;

/** Los ocho elementos canónicos con su color heráldico y glifo (plan 2.1). */
function buildCanonicalElements(): array
{
    return [
        ['id' => 'fire', 'name' => 'Fuego', 'color' => '#ff4500', 'glyph' => 'rune-ignis'],
        ['id' => 'water', 'name' => 'Agua / Escarcha', 'color' => '#00bfff', 'glyph' => 'rune-aqua'],
        ['id' => 'lightning', 'name' => 'Rayo', 'color' => '#9932cc', 'glyph' => 'rune-fulgur'],
        ['id' => 'earth', 'name' => 'Tierra', 'color' => '#8b4513', 'glyph' => 'rune-terra'],
        ['id' => 'wind', 'name' => 'Viento', 'color' => '#2e8b57', 'glyph' => 'rune-ventus'],
        ['id' => 'light', 'name' => 'Luz', 'color' => '#ffd700', 'glyph' => 'rune-lux'],
        ['id' => 'darkness', 'name' => 'Oscuridad', 'color' => '#4b0082', 'glyph' => 'rune-tenebrae'],
        ['id' => 'pureArcane', 'name' => 'Arcano Puro', 'color' => '#4169e1', 'glyph' => 'rune-arcana'],
    ];
}

$graph = new ElementalMatrixGraphDto(
    elements: buildCanonicalElements(),
    reactions: [$vaporization, $fracture, $resonance],
);
assertCondition($graph->elementCount() === 8, 'el grafo porta los ocho elementos canónicos (RF-01.1)');
assertCondition($graph->reactionCount() === 3, 'el grafo porta sus aristas reactivas');
assertCondition($graph->getElementById('pureArcane')['name'] === 'Arcano Puro', 'getElementById resuelve el nodo elemental');
assertCondition($graph->getElementById('aether') === null, 'getElementById devuelve null ante un elemento ajeno');

$graphData = json_decode((string) json_encode($graph), true);
assertCondition(array_keys($graphData) === ['elements', 'reactions'], 'el grafo serializa exactamente elements y reactions');
assertCondition($graphData['elements'][0] === ['id' => 'fire', 'name' => 'Fuego', 'color' => '#ff4500', 'glyph' => 'rune-ignis'], 'el nodo elemental porta id, name, color y glyph');
assertCondition($graphData['reactions'][0]['id'] === 'arcaneVaporization', 'las fichas de reacción anidan serializadas por JsonSerializable');
assertCondition($graphData['reactions'][2]['isCatalyst'] === true, 'el catalizador viaja anidado con su marca propia');

assertRejects(
    static fn (): ElementalMatrixGraphDto => new ElementalMatrixGraphDto(elements: [], reactions: []),
    'rechaza un grafo sin elementos',
);
assertRejects(
    static fn (): ElementalMatrixGraphDto => new ElementalMatrixGraphDto(
        elements: [buildCanonicalElements()[0], buildCanonicalElements()[0]],
        reactions: [],
    ),
    'rechaza identificadores elementales duplicados',
);
assertRejects(
    static fn (): ElementalMatrixGraphDto => new ElementalMatrixGraphDto(
        elements: buildCanonicalElements(),
        reactions: [new ElementalReactionDto(id: 'x', name: 'X', elements: ['fire', 'aether'])],
    ),
    'rechaza una reacción que apunta a un elemento ausente del grafo',
);
assertRejects(
    static fn (): ElementalMatrixGraphDto => new ElementalMatrixGraphDto(
        elements: [['id' => 'fire', 'name' => 'Fuego', 'color' => 'rojo', 'glyph' => 'rune-ignis']],
        reactions: [],
    ),
    'rechaza un color heráldico que no es notación hexadecimal',
);
assertRejects(
    static fn (): ElementalMatrixGraphDto => new ElementalMatrixGraphDto(
        elements: [['id' => 'fire', 'name' => 'Fuego', 'color' => '#ff4500', 'glyph' => '']],
        reactions: [],
    ),
    'rechaza un nodo sin glifo rúnico',
);

// =====================================================================
// [7] Resultado de la resolución: las 11 claves del contrato
// =====================================================================
echo PHP_EOL . '[7] ComboResolutionResultDto — contrato del plan 2.1' . PHP_EOL;

/** Reacción de Vaporización: 40 de daño base elevados a 60 (RF-04.1). */
function buildVaporizationResult(): ComboResolutionResultDto
{
    return new ComboResolutionResultDto(
        isReaction: true,
        reactionId: 'arcaneVaporization',
        reactionName: 'Vaporización Arcana',
        effectiveDamage: 60,
        damageMultiplierApplied: 1.5,
        tacticalEffectApplied: 'blindnessMist',
        effectDurationMs: 3000,
        clearedAura: true,
        resultingAura: null,
        stunlockTriggered: false,
        grantStunlockImmunity: false,
    );
}

$resolution = buildVaporizationResult();
assertCondition($resolution->isReaction === true, 'isReaction declara la detonación');
assertCondition($resolution->effectiveDamage === 60, 'effectiveDamage porta el daño amplificado (int)');
assertCondition($resolution->damageMultiplierApplied === 1.5, 'damageMultiplierApplied porta el factor aplicado');
assertCondition($resolution->clearedAura === true, 'clearedAura declara el consumo del aura (RF-03.4)');
assertCondition($resolution->resultingAura === null, 'resultingAura queda nula en el estado neutral puro');

$resolutionData = json_decode((string) json_encode($resolution), true);
assertCondition(
    array_keys($resolutionData) === [
        'isReaction',
        'reactionId',
        'reactionName',
        'effectiveDamage',
        'damageMultiplierApplied',
        'tacticalEffectApplied',
        'effectDurationMs',
        'clearedAura',
        'resultingAura',
        'stunlockTriggered',
        'grantStunlockImmunity',
    ],
    'json_encode emite EXACTAMENTE las 11 claves del Endpoint 3, en su orden canónico',
);
assertCondition(array_key_exists('resultingAura', $resolutionData) && $resolutionData['resultingAura'] === null, 'resultingAura viaja explícitamente como null (contrato del plan)');

// =====================================================================
// [8] Los cuatro desenlaces tácticos
// =====================================================================
echo PHP_EOL . '[8] ComboResolutionResultDto — desenlaces tácticos' . PHP_EOL;

$overwrite = new ComboResolutionResultDto(
    isReaction: false,
    effectiveDamage: 25,
    damageMultiplierApplied: 1.0,
    tacticalEffectApplied: 'auraOverwritten',
    effectDurationMs: 0,
    clearedAura: false,
    resultingAura: 'light',
);
assertCondition($overwrite->isReaction === false && $overwrite->resultingAura === 'light', 'la sobreescritura íntegra el daño y siembra el nuevo aura (RF-03.3)');
assertCondition($overwrite->reactionId === null, 'sin reacción no hay identificador de combo');

$refresh = new ComboResolutionResultDto(
    isReaction: false,
    effectiveDamage: 25,
    damageMultiplierApplied: 1.0,
    tacticalEffectApplied: 'auraRefreshed',
    effectDurationMs: 5000,
    clearedAura: false,
    resultingAura: 'fire',
);
assertCondition($refresh->tacticalEffectApplied === 'auraRefreshed', 'el mismo elemento refresca la ventana sin detonar combo (RF-02.4)');

$stunlocked = new ComboResolutionResultDto(
    isReaction: true,
    reactionId: 'fluidElectrocution',
    reactionName: 'Electrocución Fluida',
    effectiveDamage: 60,
    damageMultiplierApplied: 1.5,
    tacticalEffectApplied: 'hardStun',
    effectDurationMs: 1500,
    clearedAura: true,
    resultingAura: null,
    stunlockTriggered: true,
    grantStunlockImmunity: false,
);
assertCondition($stunlocked->stunlockTriggered === true, 'la salvaguarda anota el Hard CC suprimido durante la inmunidad (RF-05.3)');
assertCondition($stunlocked->damageMultiplierApplied === 1.5, 'el Hard CC suprimido conserva el +50% de daño (RF-05.3)');

$hardStun = new ComboResolutionResultDto(
    isReaction: true,
    reactionId: 'glacialBlizzard',
    reactionName: 'Ventisca Helada',
    effectiveDamage: 45,
    damageMultiplierApplied: 1.5,
    tacticalEffectApplied: 'freezeParalysis',
    effectDurationMs: 2000,
    clearedAura: true,
    resultingAura: null,
    stunlockTriggered: false,
    grantStunlockImmunity: true,
);
assertCondition($hardStun->grantStunlockImmunity === true, 'el Hard CC efectivo concede la inmunidad de 3 s (RF-05.2)');

// =====================================================================
// [9] Validaciones de coherencia táctica
// =====================================================================
echo PHP_EOL . '[9] ComboResolutionResultDto — coherencia táctica' . PHP_EOL;

assertRejects(
    static fn (): ComboResolutionResultDto => new ComboResolutionResultDto(
        isReaction: true, effectiveDamage: 60, damageMultiplierApplied: 1.5, effectDurationMs: 0,
        clearedAura: true, resultingAura: null,
    ),
    'rechaza una reacción sin identificador de combo',
);
assertRejects(
    static fn (): ComboResolutionResultDto => new ComboResolutionResultDto(
        isReaction: false, reactionId: 'arcaneVaporization', effectiveDamage: 60, damageMultiplierApplied: 1.5,
        effectDurationMs: 0, clearedAura: false, resultingAura: 'fire',
    ),
    'rechaza un identificador de combo sin reacción detonada',
);
assertRejects(
    static fn (): ComboResolutionResultDto => new ComboResolutionResultDto(
        isReaction: false, effectiveDamage: -5, damageMultiplierApplied: 1.0, effectDurationMs: 0,
        clearedAura: false, resultingAura: 'fire',
    ),
    'rechaza un daño efectivo negativo',
);
assertRejects(
    static fn (): ComboResolutionResultDto => new ComboResolutionResultDto(
        isReaction: false, effectiveDamage: 10, damageMultiplierApplied: 0.5, effectDurationMs: 0,
        clearedAura: false, resultingAura: 'fire',
    ),
    'rechaza un multiplicador inferior a la unidad',
);
assertRejects(
    static fn (): ComboResolutionResultDto => new ComboResolutionResultDto(
        isReaction: true, reactionId: 'twilightCollapse', reactionName: 'Colapso Crepuscular',
        effectiveDamage: 90, damageMultiplierApplied: 1.5, effectDurationMs: 0,
        clearedAura: true, resultingAura: 'fire',
    ),
    'rechaza declarar el aura consumida y conservar un aura resultante (RF-03.4)',
);
assertRejects(
    static fn (): ComboResolutionResultDto => new ComboResolutionResultDto(
        isReaction: false, effectiveDamage: 10, damageMultiplierApplied: 1.0, effectDurationMs: 0,
        clearedAura: false, resultingAura: 'fire', stunlockTriggered: true,
    ),
    'rechaza una salvaguarda anti-stunlock sin reacción detonada',
);

// =====================================================================
// [10] json_encode sin advertencias de tipo y fidelidad decimal
// =====================================================================
echo PHP_EOL . '[10] Serialización nativa sin advertencias' . PHP_EOL;

assertCondition(json_last_error() === JSON_ERROR_NONE, 'json_encode cierra sin error (JSON_ERROR_NONE)');
assertCondition($phpWarnings === [], 'ninguna codificación emitió advertencias ni avisos de tipo de PHP');
assertCondition(
    str_contains((string) json_encode($overwrite, JSON_PRESERVE_ZERO_FRACTION), '"damageMultiplierApplied":1.0'),
    'con JSON_PRESERVE_ZERO_FRACTION el factor neutro conserva su naturaleza decimal (nota para el controlador, Tarea 1.4)',
);
assertCondition(
    $vaporizationData['description'] === $vaporization->description
    && $vaporizationData['name'] === 'Vaporización Arcana',
    'la prosa litúrgica en castellano sobrevive íntegra al viaje JSON (Artículo IV)',
);

// =====================================================================
// Resumen final.
// =====================================================================
echo PHP_EOL . '== RESUMEN ==' . PHP_EOL;
echo "Asertos superados: {$assertsPassed}" . PHP_EOL;
echo "Asertos fallidos:  {$assertsFailed}" . PHP_EOL;

if ($assertsFailed > 0) {
    echo PHP_EOL . 'RESULTADO: DENEGADO' . PHP_EOL;
    exit(1);
}
echo PHP_EOL . 'RESULTADO: ÉXITO — Los DTOs de la Matriz Elemental cumplen el contrato del plan (Tarea 1.1).' . PHP_EOL;
exit(0);
