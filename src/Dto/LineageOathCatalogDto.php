<?php

/**
 * LineageOathCatalogDto.php — Contrato del canon ceremonial servido a la
 * ceremonia del juramento (SPEC-09, Tarea 2.1).
 *
 * Cubre: RF-02.1 (canon ceremonial completo) y el `accountState` que la
 * vista usa para saber si la ceremonia le espera (RF-01.3) o si la
 * muestra un linajado curioso (que navega sin retención, RF-01.6).
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): serialización JSON nativa, sin librerías.
 *   - Art. V (Dualidad): claves técnicas en inglés camelCase (contrato
 *     del plan §2.2, Endpoint 1); documentación en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use JsonSerializable;

/**
 * Catálogo ceremonial: el estado de la cuenta llamadora y las ocho
 * fichas heráldicas del canon, en el orden de la rejilla solemne.
 */
final readonly class LineageOathCatalogDto implements JsonSerializable
{
    /** Estado de cuenta del adepto sin linaje (contrato del plan §2.2). */
    public const STATE_PILGRIM = 'pilgrim';

    /** Estado de cuenta del adepto que ya juró. */
    public const STATE_LINEAGED = 'lineaged';

    /**
     * @param string                   $accountState 'pilgrim' | 'lineaged'.
     * @param list<LineageProfileDto>  $lineages     Las fichas del canon.
     */
    public function __construct(
        public string $accountState,
        public array $lineages,
    ) {
    }

    /**
     * El contrato exacto del plan §2.2: { accountState, lineages[] } con
     * cada ficha serializada por su propio DTO.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'accountState' => $this->accountState,
            'lineages'     => $this->lineages,
        ];
    }
}
