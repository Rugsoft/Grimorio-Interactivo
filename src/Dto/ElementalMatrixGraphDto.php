<?php

/**
 * ElementalMatrixGraphDto.php — Grafo del Códice de Afinidades Elementales.
 *
 * Tarea 1.1 (TASKS-06): DTO inmutable que porta el Códice íntegro para la
 * Rueda Rúnica (RF-01.1, RF-01.2): los ocho elementos canónicos con su
 * nombre litúrgico, su color heráldico y su glifo (distinguible también por
 * geometría, RNF-03) más las siete Reacciones Arcanas Duales y la resonancia
 * del catalizador universal como aristas reactivas.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): serialización JSON nativa vía
 *     JsonSerializable + json_encode, sin librerías.
 *   - Artículo IV (El Velo Arcano): los nombres visibles viajan en noble
 *     castellano; los identificadores técnicos, en inglés camelCase.
 *   - Artículo V: claves técnicas en inglés camelCase, documentación en
 *     castellano.
 *
 * Contrato (plan 2.1, Endpoint 1): las claves raíz son EXACTAMENTE
 * `elements` y `reactions` — json_encode($dto) genera el sobre `data` del
 * Endpoint 1 sin transformación adicional. Cada nodo elemental porta
 * exactamente { id, name, color, glyph }.
 *
 * Integridad: el grafo se valida al forjarse (identificadores únicos y
 * colores heráldicos en notación hexadecimal) y toda arista debe apuntar a
 * elementos presentes en el propio grafo: la Rueda Rúnica nunca recibe un
 * filamento que conecte con un glifo inexistente.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Grafo del Códice: nodos elementales y aristas de reacción.
 */
final readonly class ElementalMatrixGraphDto implements JsonSerializable
{
    /** Notación heráldica admitida para los colores elementales (#rrggbb). */
    private const HERALDIC_COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    /** @var list<array{id: string, name: string, color: string, glyph: string}> Nodos del Códice. */
    public array $elements;

    /** @var list<ElementalReactionDto> Aristas reactivas del Códice. */
    public array $reactions;

    /**
     * Forja el grafo del Códice.
     *
     * La normalización de los nodos exige asignarlos ya depurados, de modo
     * que las propiedades se declaran sin promoción (una propiedad readonly
     * solo admite una asignación, y ha de ser la definitiva).
     *
     * @param list<array{id: string, name: string, color: string, glyph: string}> $elements
     * @param list<ElementalReactionDto> $reactions
     *
     * @throws InvalidArgumentException Si el grafo carece de nodos o sus aristas son incoherentes.
     */
    public function __construct(array $elements, array $reactions = []) {
        if ($elements === []) {
            throw new InvalidArgumentException('El Códice exige al menos un elemento para trazar su rueda.');
        }

        $this->elements = array_values(array_map($this->guardElement(...), $elements));
        $this->reactions = array_values($reactions);

        $this->guardUniqueElementIds();
        $this->guardReactions();
    }

    /**
     * Valida y normaliza un nodo elemental a su forma canónica de cuatro claves.
     *
     * @param array<string, mixed> $element
     * @return array{id: string, name: string, color: string, glyph: string}
     *
     * @throws InvalidArgumentException Si el nodo carece de algún atributo heráldico.
     */
    private function guardElement(array $element): array
    {
        foreach (['id', 'name', 'color', 'glyph'] as $requiredKey) {
            if (!isset($element[$requiredKey]) || !is_string($element[$requiredKey]) || trim($element[$requiredKey]) === '') {
                throw new InvalidArgumentException("Todo elemento del Códice exige el atributo {$requiredKey}.");
            }
        }

        if (preg_match(self::HERALDIC_COLOR_PATTERN, $element['color']) !== 1) {
            throw new InvalidArgumentException("El color heráldico de {$element['id']} debe declararse en notación #rrggbb.");
        }

        return [
            'id' => $element['id'],
            'name' => $element['name'],
            'color' => $element['color'],
            'glyph' => $element['glyph'],
        ];
    }

    /**
     * Valida que ningún elemento se declare dos veces: la rueda octogonal
     * se traza con ocho glifos únicos.
     *
     * @throws InvalidArgumentException Si un identificador elemental se repite.
     */
    private function guardUniqueElementIds(): void
    {
        $seenElementIds = [];
        foreach ($this->elements as $element) {
            if (isset($seenElementIds[$element['id']])) {
                throw new InvalidArgumentException("El elemento {$element['id']} no puede declararse dos veces en el Códice.");
            }
            $seenElementIds[$element['id']] = true;
        }
    }

    /**
     * Valida las aristas: cada una debe ser una ficha de reacción y apuntar
     * a los elementos presentes en el grafo (los filamentos de la rueda no
     * pueden colgar de un glifo inexistente).
     *
     * @throws InvalidArgumentException Si una arista es ajena o apunta fuera del grafo.
     */
    private function guardReactions(): void
    {
        $knownElementIds = array_column($this->elements, 'id');

        foreach ($this->reactions as $reaction) {
            if (!$reaction instanceof ElementalReactionDto) {
                throw new InvalidArgumentException('Toda arista del Códice exige una ficha de reacción.');
            }

            foreach ($reaction->elements as $element) {
                if (!in_array($element, $knownElementIds, true)) {
                    throw new InvalidArgumentException(
                        "La reacción {$reaction->id} apunta al elemento {$element}, ausente del Códice.",
                    );
                }
            }
        }
    }

    /** Número de nodos elementales del Códice (ocho en el canon, RF-01.1). */
    public function elementCount(): int
    {
        return count($this->elements);
    }

    /** Número de aristas reactivas declaradas. */
    public function reactionCount(): int
    {
        return count($this->reactions);
    }

    /**
     * Resuelve un nodo elemental por su identificador técnico.
     *
     * @param string $elementId Identificador en inglés camelCase (ej. 'pureArcane').
     * @return array{id: string, name: string, color: string, glyph: string}|null
     */
    public function getElementById(string $elementId): ?array
    {
        foreach ($this->elements as $element) {
            if ($element['id'] === $elementId) {
                return $element;
            }
        }

        return null;
    }

    /**
     * Serialización JSON nativa: el sobre del Códice con sus nodos y aristas,
     * anidando las fichas de reacción por su propia interfaz JsonSerializable.
     *
     * @return array{elements: list<array{id: string, name: string, color: string, glyph: string}>, reactions: list<ElementalReactionDto>}
     */
    public function jsonSerialize(): array
    {
        return [
            'elements' => $this->elements,
            'reactions' => array_values($this->reactions),
        ];
    }
}
