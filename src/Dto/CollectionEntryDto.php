<?php

/**
 * CollectionEntryDto.php — Una entrada del Tomo Personal enriquecida
 * (SPEC-11, Tarea 3.3).
 *
 * Es la fila del tomo tal como la pinta la vista «Mi Grimorio»: la
 * ficha litúrgica del hechizo (GrimoirePageDto), el instante del
 * sellado, la marca solemne derivada del mapa único de estados
 * (RF-03.2, plan §3.1) y el estado del homenaje (praiseStatus,
 * plan §2.2) con su veredicto de permiso.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): serialización JSON nativa vía
 *     JsonSerializable + json_encode, sin librerías.
 *   - Artículo V: claves técnicas en inglés camelCase, documentación
 *     en noble castellano.
 *
 * Contrato (plan §2.2, GET /api/v1/grimoire/collection): las claves
 * raíz son EXACTAMENTE spell, addedAt, tomeMark, praiseStatus.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use JsonSerializable;

/**
 * Entrada del tomo: hechizo + adición + marca solemne + homenaje.
 */
final readonly class CollectionEntryDto implements JsonSerializable
{
    /**
     * Forja la entrada enriquecida del tomo.
     *
     * @param GrimoirePageDto $spell Ficha litúrgica completa del hechizo.
     * @param string $addedAt Instante del sellado (ISO 8601 UTC), servido
     *                        por el índice de latencia (RNF-01).
     * @param string $tomeMark Marca solemne del mapa único (RF-03.2):
     *                         'living' | 'gestation' | 'withdrawn'.
     * @param array{praised: bool, allowed: bool} $praiseStatus Estado del
     *        homenaje (RF-04.0): el voto ya rendido y si el gesto está
     *        permitido (veda la militancia de la casa, RF-04.4, o el
     *        estado no ser `validated`, RF-04.5).
     */
    public function __construct(
        public GrimoirePageDto $spell,
        public string $addedAt,
        public string $tomeMark,
        public array $praiseStatus,
    ) {
    }

    /**
     * Serialización JSON nativa: retorna el mapa de claves camelCase del
     * contrato del plan §2.2. json_encode() sobre este DTO genera
     * EXACTAMENTE esa estructura.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'spell'        => $this->spell,
            'addedAt'      => $this->addedAt,
            'tomeMark'     => $this->tomeMark,
            'praiseStatus' => $this->praiseStatus,
        ];
    }
}
