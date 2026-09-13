<?php

/**
 * SpellCalculationResultDto.php — Desglose pedagógico del cálculo de maná.
 *
 * Tarea 1.3 (TASKS-04): DTO inmutable que porta el desglose completo del
 * resultado determinista de SpellBalanceService (Tarea 2.2): sumandos de
 * efectos base, factores multiplicadores, deducciones por componentes,
 * coste final, Círculo Arcano y estado de Sobrecarga Arcana.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): serialización JSON nativa vía
 *     JsonSerializable + json_encode, sin librerías.
 *   - Artículo II: el desglose expone la fórmula de forma pedagógica,
 *     jamás modificable desde el cliente.
 *   - Artículo IV (El Velo Arcano): circleLabel con denominación solemne
 *     en castellano (ej. «Círculo III (Magister)»).
 *   - Artículo V: claves técnicas en inglés camelCase, documentación en
 *     castellano.
 *
 * Contrato (plan 2.2, Endpoint 1): las claves raíz son EXACTAMENTE
 * baseEffectPoints, multipliers, grossMana, discounts, netMana,
 * finalManaCost, circle, circleLabel, isOverloaded — json_encode($dto)
 * debe generar esa estructura sin transformación adicional.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use JsonSerializable;

/**
 * Desglose pedagógico del resultado del balanceo de maná.
 */
final readonly class SpellCalculationResultDto implements JsonSerializable
{
    /**
     * Forja el DTO del desglose pedagógico.
     *
     * @param array{range: float, area: float, duration: float, combined: float} $multipliers
     * @param array{verbal: float, somatic: float, material: float, totalPercent: float, amountDeducted: float} $discounts
     */
    public function __construct(
        public float $baseEffectPoints,
        public array $multipliers,
        public float $grossMana,
        public array $discounts,
        public float $netMana,
        public int $finalManaCost,
        public int $circle,
        public string $circleLabel,
        public bool $isOverloaded,
    ) {
    }

    /**
     * Serialización JSON nativa: retorna el mapa de claves camelCase del
     * contrato del plan en el orden canónico. json_encode() sobre este
     * DTO genera EXACTAMENTE la estructura del Endpoint 1 (plan 2.2).
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'baseEffectPoints' => $this->baseEffectPoints,
            'multipliers'      => $this->multipliers,
            'grossMana'        => $this->grossMana,
            'discounts'        => $this->discounts,
            'netMana'          => $this->netMana,
            'finalManaCost'    => $this->finalManaCost,
            'circle'           => $this->circle,
            'circleLabel'      => $this->circleLabel,
            'isOverloaded'     => $this->isOverloaded,
        ];
    }
}
