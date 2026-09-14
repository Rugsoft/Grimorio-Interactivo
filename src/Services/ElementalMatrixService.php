<?php

/**
 * ElementalMatrixService.php — Servicio de la Matriz Elemental inmutable.
 *
 * Tarea 1.2 (TASKS-06): guarda el Códice de Afinidades Elementales (SPEC-06)
 * como tabla inmutable en memoria —los ocho elementos canónicos con su
 * heráldica y las siete Reacciones Arcanas Duales más la Resonancia Arcana
 * Pura del catalizador universal— y lo consulta mediante búsquedas
 * deterministas y simétricas (RF-01.1, RF-01.2, RF-03.1, RF-03.2, RNF-01).
 *
 * Tarea 1.3 (TASKS-06): añade la resolución táctica autoritativa
 * (`resolveCombo()`), el veredicto determinista de un impacto elemental
 * contra el estado imbuido del objetivo (Endpoint 3, plan 2.1): casos
 * neutral/refresco/catalizador/reacción dual/sobreescritura, reglas
 * especializadas de barrera (Fractura Basáltica y Colapso Crepuscular) y
 * salvaguarda anti-stunlock (RF-02 a RF-05, Artículo II).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): solo PHP nativo; la tabla es un dato del
 *     Códice, no una decisión del motor.
 *   - Artículo II: los factores cuantitativos (×1.5, ×1.25, +1 s) viajan
 *     LEÍDOS de la tabla; este servicio jamás calcula nada por su cuenta en
 *     esta tarea (la resolución táctica es la Tarea 1.3).
 *   - Artículo IV (El Velo Arcano): nombres litúrgicos y descripciones en
 *     noble castellano; identificadores técnicos en inglés camelCase.
 *   - Artículo V: métodos en inglés camelCase, documentación en castellano.
 *
 * Simetría (RF-03.1): las reacciones duales son por pares no ordenados, de
 * modo que findReaction('fire', 'water') y findReaction('water', 'fire')
 * devuelven exactamente la misma ficha. La clave de búsqueda se normaliza
 * ordenando alfabéticamente ambos componentes (canonicalKey()), lo que hace
 * de A + B = B + A una propiedad estructural de la matriz, no una convención.
 *
 * Determinismo (RNF-01): ninguna consulta tiene efecto lateral, no existe
 * probabilidad ni azar, y dos instancias del servicio forjan Códices
 * idénticos byte a byte.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use Grimorio\Dto\ComboResolutionResultDto;
use Grimorio\Dto\ElementalMatrixGraphDto;
use Grimorio\Dto\ElementalReactionDto;
use Grimorio\Dto\SpellImpactData;

/**
 * Códice de Afinidades Elementales: la matriz canónica de reacciones.
 */
final class ElementalMatrixService
{
    /**
     * Los ocho elementos canónicos del Códice (RF-01.1), con su nombre
     * litúrgico en castellano, su color heráldico y su glifo rúnico.
     *
     * @var list<array{id: string, name: string, color: string, glyph: string}>
     */
    private const CANONICAL_ELEMENTS = [
        ['id' => 'fire', 'name' => 'Fuego', 'color' => '#ff4500', 'glyph' => 'rune-ignis'],
        ['id' => 'water', 'name' => 'Agua / Escarcha', 'color' => '#00bfff', 'glyph' => 'rune-aqua'],
        ['id' => 'lightning', 'name' => 'Rayo', 'color' => '#9932cc', 'glyph' => 'rune-fulgur'],
        ['id' => 'earth', 'name' => 'Tierra', 'color' => '#8b4513', 'glyph' => 'rune-terra'],
        ['id' => 'wind', 'name' => 'Viento', 'color' => '#2e8b57', 'glyph' => 'rune-ventus'],
        ['id' => 'light', 'name' => 'Luz', 'color' => '#ffd700', 'glyph' => 'rune-lux'],
        ['id' => 'darkness', 'name' => 'Oscuridad', 'color' => '#4b0082', 'glyph' => 'rune-tenebrae'],
        ['id' => 'pureArcane', 'name' => 'Arcano Puro', 'color' => '#4169e1', 'glyph' => 'rune-arcana'],
    ];

    /**
     * Las siete Reacciones Arcanas Duales canónicas (RF-03.1) más la
     * Resonancia Arcana Pura del catalizador universal (RF-03.2), leídas
     * del Códice del plan 2.1. El orden es el del Endpoint 1.
     *
     * @var list<array<string, mixed>> Especificaciones literales de las fichas.
     */
    private const CANONICAL_REACTIONS = [
        [
            'id' => 'arcaneVaporization',
            'name' => 'Vaporización Arcana',
            'elements' => ['fire', 'water'],
            'damageMultiplier' => 1.5,
            'tacticalEffect' => 'blindnessMist',
            'effectDurationMs' => 3000,
            'description' => 'Emisión de vapor abrasador que reduce la precisión del objetivo durante 3 segundos.',
        ],
        [
            'id' => 'fluidElectrocution',
            'name' => 'Electrocución Fluida',
            'elements' => ['water', 'lightning'],
            'damageMultiplier' => 1.5,
            'tacticalEffect' => 'hardStun',
            'effectDurationMs' => 1500,
            'description' => 'Descarga en cadena que aturde fulgurantemente al blanco durante 1.5 segundos.',
        ],
        [
            'id' => 'vortexDeflagration',
            'name' => 'Deflagración en Vórtice',
            'elements' => ['fire', 'wind'],
            'damageMultiplier' => 1.5,
            'tacticalEffect' => 'areaExpansion',
            'effectDurationMs' => 0,
            'description' => 'Combustión violenta alimentada por viento que expande el impacto a radio esférico en área.',
        ],
        [
            'id' => 'basalticFracture',
            'name' => 'Fractura Basáltica',
            'elements' => ['earth', 'lightning'],
            'damageMultiplier' => 1.5,
            'tacticalEffect' => 'barrierShatter',
            'effectDurationMs' => 0,
            'barrierDamage' => 50,
            'description' => 'Descarga de choque que tritura hasta 50 puntos de barrera mágica del objetivo.',
        ],
        [
            'id' => 'petrifyingSwamp',
            'name' => 'Ciénaga Petrificante',
            'elements' => ['earth', 'water'],
            'damageMultiplier' => 1.5,
            'tacticalEffect' => 'rootAndSlow',
            'effectDurationMs' => 3000,
            'slowDurationMs' => 4000,
            'description' => 'Inmovilización por enraizamiento de 3 s y reducción de velocidad al 50% por 4 s.',
        ],
        [
            'id' => 'glacialBlizzard',
            'name' => 'Ventisca Helada',
            'elements' => ['wind', 'water'],
            'damageMultiplier' => 1.5,
            'tacticalEffect' => 'freezeParalysis',
            'effectDurationMs' => 2000,
            'description' => 'Congelación absoluta de 2 segundos que impone parálisis motora completa.',
        ],
        [
            'id' => 'twilightCollapse',
            'name' => 'Colapso Crepuscular',
            'elements' => ['light', 'darkness'],
            'damageMultiplier' => 1.5,
            'tacticalEffect' => 'barrierPiercing',
            'effectDurationMs' => 0,
            'description' => 'Daño puro que penetra el 100% de los escudos dañando directamente los puntos de salud.',
        ],
        [
            'id' => 'pureArcaneResonance',
            'name' => 'Resonancia Arcana Pura',
            'elements' => ['pureArcane'],
            'isCatalyst' => true,
            'amplificationFactor' => 1.25,
            'ccExtensionMs' => 1000,
            'description' => 'Catalizador universal que amplifica un 25% la magnitud y añade 1 s a la duración de controles.',
        ],
    ];

    /** Duración canónica de la ventana de resonancia del aura, en ms (RF-02.1). */
    private const RESONANCE_WINDOW_MS = 5000;

    /** Duración canónica de la Inmunidad Rúnica a Parálisis, en ms (RF-05.2). */
    private const STUNLOCK_IMMUNITY_MS = 3000;

    /** Efectos de Hard CC del Códice: parálisis total susceptible de salvaguarda (RF-05.3). */
    private const HARD_CROWD_CONTROL_EFFECTS = ['hardStun', 'freezeParalysis'];

    /** @var ElementalMatrixGraphDto|null El Códice forjado una sola vez por instancia (memoria transitiva). */
    private ?ElementalMatrixGraphDto $matrixGraph = null;

    /**
     * Índice de búsqueda por par canónico: la clave «elementoMenor|elementoMayor»
     * (orden alfabético) apunta a su ficha de reacción dual.
     *
     * @var array<string, ElementalReactionDto>|null
     */
    private ?array $reactionIndex = null;

    /**
     * Devuelve el grafo íntegro del Códice (RF-01.1, RF-01.2): los ocho
     * elementos con su heráldica y las ocho aristas reactivas (7 duales +
     * 1 catalizador), listo para serializarse en el Endpoint 1.
     */
    public function getMatrixGraph(): ElementalMatrixGraphDto
    {
        if ($this->matrixGraph === null) {
            $this->matrixGraph = new ElementalMatrixGraphDto(
                self::CANONICAL_ELEMENTS,
                $this->forgeReactions(),
            );
        }

        return $this->matrixGraph;
    }

    /**
     * Lista las fichas de reacción dual en las que participa el elemento
     * dado (RF-01.2: los filamentos que ilumina cada glifo en la Rueda
     * Rúnica). El catalizador unario no declara aristas duales propias.
     *
     * @param string $element Identificador técnico en inglés camelCase (ej. 'water').
     * @return list<ElementalReactionDto> Vacía si el elemento es desconocido o no reacciona.
     */
    public function getReactionsForElement(string $element): array
    {
        $element = trim($element);
        if ($element === '') {
            return [];
        }

        $reactions = [];
        foreach ($this->forgeReactions() as $reaction) {
            if (!$reaction->isCatalyst && $reaction->involves($element)) {
                $reactions[] = $reaction;
            }
        }

        return $reactions;
    }

    /**
     * Resuelve la reacción arcana del par A + B con simetría garantizada:
     * findReaction('fire', 'water') y findReaction('water', 'fire')
     * devuelven idéntica ficha de Vaporización Arcana (RF-03.1).
     *
     * @param string $elementA Primer componente del par.
     * @param string $elementB Segundo componente del par.
     * @return ElementalReactionDto|null null si los elementos coinciden, son
     *         desconocidos, están vacíos o no reaccionan entre sí (el
     *         catalizador unario jamás forma pares duales).
     */
    public function findReaction(string $elementA, string $elementB): ?ElementalReactionDto
    {
        $elementA = trim($elementA);
        $elementB = trim($elementB);

        // Un elemento no reacciona consigo mismo ni con la nada.
        if ($elementA === '' || $elementB === '' || $elementA === $elementB) {
            return null;
        }

        return $this->reactionIndex()[self::canonicalKey($elementA, $elementB)] ?? null;
    }

    /**
     * Resolución autoritativa de un impacto elemental (Endpoint 3, plan 2.1,
     * Tarea 1.3): determina el veredicto completo del conjuro entrante contra
     * el estado imbuido del objetivo.
     *
     * Máquina de estados (plan 3.1):
     *   - Caso A (blanco neutral o aura expirada): el conjuro imbuye su aura
     *     para una ventana de resonancia de 5 s (RF-02.1). El catalizador no
     *     imbuye aura (no es elemento de imbución) ni detona nada.
     *   - Caso B (mismo elemento): refresco del aura a 5 s con daño pleno
     *     (RF-02.4), efecto `auraRefreshed`.
     *   - Caso C (catalizador sobre aura): Resonancia Arcana Pura, +25%
     *     (ceil), consume el aura y deja neutral puro (RF-03.2, RF-03.4).
     *   - Caso D (reacción dual): +50% (ceil), efecto táctico del Códice,
     *     neutral puro (RF-03.1, RF-03.4, RF-04.1), con reglas especializadas
     *     de barrera (trituración de 50 PV / penetración total) y salvaguarda
     *     anti-stunlock para Hard CC (RF-05.2, RF-05.3).
     *   - Caso E (elemento no reactivo): daño pleno y sobreescritura del aura
     *     con reinicio de la ventana (RF-03.3), efecto `auraOverwritten`.
     *
     * Determinismo (RNF-01, Artículo II): sin azar ni probabilidad; los
     * factores se LEEN del Códice y la única aritmética es `ceil(base × factor)`.
     *
     * @param string $activeAura Aura elemental vigente del blanco ('' si neutral).
     * @param SpellImpactData $incomingSpell El conjuro entrante en el instante del impacto.
     * @param bool $stunlockImmune ¿Goza el blanco de Inmunidad Rúnica a Parálisis vigente? (RF-05.3)
     * @return ComboResolutionResultDto El veredicto tipado con las once claves del contrato.
     */
    public function resolveCombo(
        string $activeAura,
        SpellImpactData $incomingSpell,
        bool $stunlockImmune,
    ): ComboResolutionResultDto {
        $activeAura = trim($activeAura);
        $incomingElement = trim($incomingSpell->element);

        // Caso A: blanco neutral (o con el aura expirada, lo que gobierna quien
        // posee el estado del objetivo). El catalizador no imbuye ni detona.
        if ($activeAura === '') {
            if ($incomingElement !== 'pureArcane') {
                return new ComboResolutionResultDto(
                    isReaction: false,
                    effectiveDamage: $incomingSpell->baseDamage,
                    damageMultiplierApplied: 1.0,
                    effectDurationMs: self::RESONANCE_WINDOW_MS,
                    clearedAura: false,
                    resultingAura: $incomingElement,
                );
            }

            return new ComboResolutionResultDto(
                isReaction: false,
                effectiveDamage: $incomingSpell->baseDamage,
                damageMultiplierApplied: 1.0,
                effectDurationMs: self::RESONANCE_WINDOW_MS,
                clearedAura: false,
                resultingAura: null,
            );
        }

        // Caso B: mismo elemento — refresco de la ventana de resonancia (RF-02.4).
        if ($activeAura === $incomingElement) {
            return new ComboResolutionResultDto(
                isReaction: false,
                effectiveDamage: $incomingSpell->baseDamage,
                damageMultiplierApplied: 1.0,
                effectDurationMs: self::RESONANCE_WINDOW_MS,
                clearedAura: false,
                resultingAura: $activeAura,
                tacticalEffectApplied: 'auraRefreshed',
            );
        }

        // Caso C: catalizador sobre aura activa — Resonancia Arcana Pura (RF-03.2).
        if ($incomingElement === 'pureArcane') {
            return new ComboResolutionResultDto(
                isReaction: true,
                reactionId: 'pureArcaneResonance',
                reactionName: 'Resonancia Arcana Pura',
                effectiveDamage: (int) ceil($incomingSpell->baseDamage * 1.25),
                damageMultiplierApplied: 1.25,
                effectDurationMs: 0,
                clearedAura: true,
                resultingAura: null,
                tacticalEffectApplied: 'amplification',
            );
        }

        // Caso D: búsqueda de la reacción dual simétrica (RF-03.1).
        $reaction = $this->findReaction($activeAura, $incomingElement);
        if ($reaction !== null) {
            return $this->resolveDualReaction($reaction, $incomingSpell, $stunlockImmune);
        }

        // Caso E: elemento no reactivo — daño pleno y sobreescritura (RF-03.3).
        return new ComboResolutionResultDto(
            isReaction: false,
            effectiveDamage: $incomingSpell->baseDamage,
            damageMultiplierApplied: 1.0,
            effectDurationMs: self::RESONANCE_WINDOW_MS,
            clearedAura: false,
            resultingAura: $incomingElement,
            tacticalEffectApplied: 'auraOverwritten',
        );
    }

    /**
     * Resuelve una reacción dual detonada: daño ×1.5 (ceil), efecto táctico
     * del Códice, reglas especializadas de barrera y salvaguarda anti-stunlock
     * (RF-03.4, RF-04.1, RF-04.2, RF-05.2, RF-05.3).
     *
     * @param ElementalReactionDto $reaction Ficha del Códice de la reacción detonada.
     * @param SpellImpactData $incomingSpell El conjuro detonador.
     * @param bool $stunlockImmune ¿Goza el blanco de inmunidad vigente?
     */
    private function resolveDualReaction(
        ElementalReactionDto $reaction,
        SpellImpactData $incomingSpell,
        bool $stunlockImmune,
    ): ComboResolutionResultDto {
        // Bonificación canónica del +50% sobre el daño base (RF-04.1, Artículo II).
        $finalDamage = (int) ceil($incomingSpell->baseDamage * $reaction->damageMultiplier);

        // Evaluación del Hard CC y salvaguarda anti-stunlock (RF-05.2, RF-05.3):
        // el daño íntegro nunca se ve afectado; solo la parálisis se suprime.
        $isHardCrowdControl = in_array($reaction->tacticalEffect, self::HARD_CROWD_CONTROL_EFFECTS, true);
        $stunlockTriggered = $isHardCrowdControl && $stunlockImmune;
        $grantImmunity = $isHardCrowdControl && !$stunlockImmune;

        return new ComboResolutionResultDto(
            isReaction: true,
            reactionId: $reaction->id,
            reactionName: $reaction->name,
            effectiveDamage: $finalDamage,
            damageMultiplierApplied: $reaction->damageMultiplier,
            effectDurationMs: $reaction->effectDurationMs ?? 0,
            clearedAura: true,
            resultingAura: null,
            tacticalEffectApplied: $reaction->tacticalEffect,
            stunlockTriggered: $stunlockTriggered,
            grantStunlockImmunity: $grantImmunity,
        );
    }

    /**
     * Forja una vez por llamada las fichas de reacción a partir de las
     * especificaciones literales del Códice (Artículo II: los factores son
     * datos leídos, no cálculos). Devuelve instancias nuevas e independientes:
     * los DTO son inmutables, de modo que ningún llamador puede corromper
     * la matriz compartida.
     *
     * @return list<ElementalReactionDto>
     */
    private function forgeReactions(): array
    {
        $reactions = [];
        foreach (self::CANONICAL_REACTIONS as $specification) {
            $reactions[] = new ElementalReactionDto(
                id: $specification['id'],
                name: $specification['name'],
                elements: $specification['elements'],
                damageMultiplier: $specification['damageMultiplier'] ?? null,
                tacticalEffect: $specification['tacticalEffect'] ?? null,
                effectDurationMs: $specification['effectDurationMs'] ?? null,
                barrierDamage: $specification['barrierDamage'] ?? null,
                slowDurationMs: $specification['slowDurationMs'] ?? null,
                isCatalyst: $specification['isCatalyst'] ?? false,
                amplificationFactor: $specification['amplificationFactor'] ?? null,
                ccExtensionMs: $specification['ccExtensionMs'] ?? null,
                description: $specification['description'],
            );
        }

        return $reactions;
    }

    /**
     * Construye (una vez por instancia) el índice de búsqueda simétrica.
     *
     * @return array<string, ElementalReactionDto>
     */
    private function reactionIndex(): array
    {
        if ($this->reactionIndex === null) {
            $this->reactionIndex = [];
            foreach ($this->forgeReactions() as $reaction) {
                if ($reaction->isCatalyst || count($reaction->elements) !== 2) {
                    continue; // El catalizador unario no forma pares duales.
                }
                [$componentA, $componentB] = $reaction->elements;
                $this->reactionIndex[self::canonicalKey($componentA, $componentB)] = $reaction;
            }
        }

        return $this->reactionIndex;
    }

    /**
     * Clave canónica de un par elemental: ambos componentes ordenados
     * alfabéticamente y unidos con '|'. Es la garantía estructural de la
     * simetría A + B = B + A (RF-03.1, RNF-01).
     */
    private static function canonicalKey(string $elementA, string $elementB): string
    {
        $components = [$elementA, $elementB];
        sort($components, SORT_STRING);

        return $components[0] . '|' . $components[1];
    }
}
