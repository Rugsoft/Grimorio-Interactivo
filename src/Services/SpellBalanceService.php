<?php

/**
 * SpellBalanceService.php — Algoritmo matemático puro de balanceo de maná.
 *
 * Tarea 2.2 (TASKS-04): motor determinista y ciego (Artículo II) que
 * computa el coste final de maná, el Círculo Arcano y la huella
 * matemática antifraude a partir de SpellCalculationInputDto (Tarea 1.2).
 * Es la ÚNICA fuente de verdad del coste: el cliente jamás dicta el maná.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): aritmética nativa PHP, cero librerías.
 *   - Artículo II (Ley Universal del Maná): cálculo 100% determinista,
 *     suelo mínimo de 5, techo de contención de 200 (Sobrecarga Arcana)
 *     y neutralidad elemental total: afinidad y escuela NO interfieren.
 *   - Artículo IV (El Velo Arcano): denominaciones de Círculos solemnes
 *     en castellano.
 *   - Artículo V: identificadores en inglés camelCase, comentarios y
 *     etiquetas en castellano.
 *
 * Fórmula (plan 3.1):
 *   basePoints = daño×1.0 + cura×1.5 + barrera×1.2 + CC_WEIGHTS[tipo]
 *   grossMana  = basePoints × rangeFactor × areaFactor × durationFactor
 *   discount   = min(0.30, Σ componentes 0.10)
 *   finalMana  = max(5, ceil(grossMana × (1 − discount)))
 *   finalMana > 200  →  ArcaneOverloadException (Tarea 2.1)
 *
 * Huella matemática (plan 3.2): SHA-256 de la concatenación canónica
 * "daño:cura:barrera:cc:alcance:área:duración:verbal:somático:material"
 * — los parámetros narrativos NO participan (antifraude de firmas).
 */

declare(strict_types=1);

namespace Grimorio\Services;

use Grimorio\Dto\SpellCalculationInputDto;
use Grimorio\Dto\SpellCalculationResultDto;
use Grimorio\Exceptions\ArcaneOverloadException;
use InvalidArgumentException;

/**
 * Motor matemático puro del coste de maná (sin estado, sin I/O).
 */
final class SpellBalanceService
{
    // -----------------------------------------------------------------
    // Ponderaciones de efectos base (plan 3.1, constantes universales).
    // -----------------------------------------------------------------

    /** Peso del daño directo o continuo. */
    public const WEIGHT_DAMAGE = 1.0;

    /** Peso de la curación (más costosa por unidad que el daño). */
    public const WEIGHT_HEALING = 1.5;

    /** Peso de la barrera/absorción. */
    public const WEIGHT_BARRIER = 1.2;

    /** Puntos base que añade cada modo de control de masas. */
    public const CC_WEIGHTS = [
        'none' => 0.0,
        'slow' => 8.0,
        'root' => 15.0,
        'stun' => 25.0,
    ];

    // -----------------------------------------------------------------
    // Multiplicadores geométricos y temporales.
    // -----------------------------------------------------------------

    /** Factores por alcance del conjuro. */
    public const RANGE_FACTORS = [
        'touch'  => 1.0,
        'short'  => 1.1,
        'medium' => 1.25,
        'long'   => 1.5,
    ];

    /** Factores por geometría de área. */
    public const AREA_FACTORS = [
        'singleTarget' => 1.0,
        'cone'         => 1.3,
        'line'         => 1.4,
        'sphere'       => 1.6,
    ];

    /** Factores por duración del efecto. */
    public const DURATION_FACTORS = [
        'instant'       => 1.0,
        'concentration' => 1.25,
        'sustained'     => 1.5,
    ];

    // -----------------------------------------------------------------
    // Descuentos de componentes y acotadores de la fórmula.
    // -----------------------------------------------------------------

    /** Descuento por componente atenuador (-10% cada uno). */
    public const COMPONENT_DISCOUNTS = [
        'verbal'   => 0.10,
        'somatic'  => 0.10,
        'material' => 0.10,
    ];

    /** Tope absoluto de descuento combinado (30%). */
    public const MAX_COMPONENT_DISCOUNT = 0.30;

    /** Suelo mínimo de maná (RF-02.2): todo conjuro cuesta al menos 5. */
    public const MANA_FLOOR = 5;

    /** Techo de contención (RF-03.2): superarlo es Sobrecarga Arcana. */
    public const MANA_OVERLOAD_CEILING = 200;

    // -----------------------------------------------------------------
    // Escala de Círculos Arcanos (RF-03.1 de la spec): techo → círculo.
    // -----------------------------------------------------------------

    /** Umbrales superiores de cada Círculo (inclusivos) con su etiqueta solemne. */
    private const CIRCLE_THRESHOLDS = [
        20  => [1, 'Círculo I (Iniciado)'],
        45  => [2, 'Círculo II (Adepto)'],
        80  => [3, 'Círculo III (Magister)'],
        130 => [4, 'Círculo IV (Maestro)'],
    ];

    /** Etiqueta del Círculo V (más allá del último umbral). */
    private const TOP_CIRCLE = [5, 'Círculo V (Archimago)'];

    /**
     * Calcula el desglose pedagógico completo del coste de maná.
     *
     * @throws InvalidArgumentException Si el conjuro carece de efectos base
     *         (basePoints <= 0: un conjuro sin efectos carece de forma arcana).
     * @throws ArcaneOverloadException Si el maná final supera el techo de 200.
     */
    public function calculate(SpellCalculationInputDto $input): SpellCalculationResultDto
    {
        // 1. Suma de puntos de efectos base ponderados (plan 3.1, paso 1).
        $basePoints = ($input->damage * self::WEIGHT_DAMAGE)
            + ($input->healing * self::WEIGHT_HEALING)
            + ($input->barrier * self::WEIGHT_BARRIER)
            + self::CC_WEIGHTS[$input->crowdControlType];

        if ($basePoints <= 0) {
            throw new InvalidArgumentException('Un conjuro sin efectos carece de forma arcana: necesita daño, cura, barrera o control de masas.');
        }

        // 2. Multiplicadores geométricos combinados (plan 3.1, paso 2).
        $rangeMultiplier    = self::RANGE_FACTORS[$input->rangeType];
        $areaMultiplier     = self::AREA_FACTORS[$input->areaType];
        $durationMultiplier = self::DURATION_FACTORS[$input->durationType];
        $combinedMultiplier = $rangeMultiplier * $areaMultiplier * $durationMultiplier;

        $grossMana = $basePoints * $combinedMultiplier;

        // 3. Descuento acotado por componentes (plan 3.1, paso 3).
        $discountPercent = 0.0;
        if ($input->hasVerbal) {
            $discountPercent += self::COMPONENT_DISCOUNTS['verbal'];
        }
        if ($input->hasSomatic) {
            $discountPercent += self::COMPONENT_DISCOUNTS['somatic'];
        }
        if ($input->hasMaterial) {
            $discountPercent += self::COMPONENT_DISCOUNTS['material'];
        }
        $discountPercent = min($discountPercent, self::MAX_COMPONENT_DISCOUNT);

        // 4. Redondeo ceil y suelo mínimo de 5 (plan 3.1, paso 4).
        $netMana = $grossMana * (1.0 - $discountPercent);
        $finalManaCost = max(self::MANA_FLOOR, (int) ceil($netMana));

        // 5. Evaluación de Sobrecarga Arcana (plan 3.1, paso 5, Art. II).
        $isOverloaded = $finalManaCost > self::MANA_OVERLOAD_CEILING;
        if ($isOverloaded) {
            throw ArcaneOverloadException::forMana($finalManaCost);
        }

        // 6. Asignación automática del Círculo Arcano (plan 3.1, paso 6).
        [$circle, $circleLabel] = $this->determineCircle($finalManaCost);

        return new SpellCalculationResultDto(
            baseEffectPoints: $basePoints,
            multipliers: [
                'range'    => $rangeMultiplier,
                'area'     => $areaMultiplier,
                'duration' => $durationMultiplier,
                'combined' => $combinedMultiplier,
            ],
            grossMana: $grossMana,
            discounts: [
                'verbal'         => $input->hasVerbal ? self::COMPONENT_DISCOUNTS['verbal'] : 0.0,
                'somatic'        => $input->hasSomatic ? self::COMPONENT_DISCOUNTS['somatic'] : 0.0,
                'material'       => $input->hasMaterial ? self::COMPONENT_DISCOUNTS['material'] : 0.0,
                'totalPercent'   => $discountPercent,
                'amountDeducted' => $grossMana - $netMana,
            ],
            netMana: $netMana,
            finalManaCost: $finalManaCost,
            circle: $circle,
            circleLabel: $circleLabel,
            isOverloaded: $isOverloaded,
        );
    }

    /**
     * Huella digital matemática (plan 3.2, antifraude de firmas): SHA-256
     * de la concatenación canónica de los 10 parámetros matemáticos.
     * Determinista: los mismos parámetros producen exactamente la misma
     * huella; cualquier cambio en ellos la altera; la narrativa no cuenta.
     */
    public function computeMathFingerprint(SpellCalculationInputDto $input): string
    {
        $mathPayload = implode(':', [
            (string) $input->damage,
            (string) $input->healing,
            (string) $input->barrier,
            $input->crowdControlType,
            $input->rangeType,
            $input->areaType,
            $input->durationType,
            $input->hasVerbal ? '1' : '0',
            $input->hasSomatic ? '1' : '0',
            $input->hasMaterial ? '1' : '0',
        ]);

        return hash('sha256', $mathPayload);
    }

    /**
     * Etiqueta solemne en castellano de un círculo dado (Art. IV).
     * Utilidad estática de presentación para DTOs y vistas que ya
     * conocen el círculo numérico (p. ej. listados de borradores).
     */
    public static function circleLabel(int $circle): string
    {
        return match ($circle) {
            1 => 'Círculo I (Iniciado)',
            2 => 'Círculo II (Adepto)',
            3 => 'Círculo III (Magister)',
            4 => 'Círculo IV (Maestro)',
            default => 'Círculo V (Archimago)',
        };
    }

    /**
     * Clasifica el maná final en su Círculo Arcano (RF-03.1 de la spec):
     *   Círculo I (Iniciado):   5 a 20.
     *   Círculo II (Adepto):    21 a 45.
     *   Círculo III (Magister): 46 a 80.
     *   Círculo IV (Maestro):   81 a 130.
     *   Círculo V (Archimago):  131 a 200.
     *
     * @return array{0: int, 1: string} [círculo, etiqueta solemne en castellano]
     */
    private function determineCircle(int $finalManaCost): array
    {
        foreach (self::CIRCLE_THRESHOLDS as $threshold => [$circle, $label]) {
            if ($finalManaCost <= $threshold) {
                return [$circle, $label];
            }
        }

        // Más allá del último umbral: el Círculo V (hasta el techo de 200).
        return self::TOP_CIRCLE;
    }
}
