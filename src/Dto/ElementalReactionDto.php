<?php

/**
 * ElementalReactionDto.php — Ficha litúrgica de una reacción arcana binaria.
 *
 * Tarea 1.1 (TASKS-06): DTO inmutable que porta una arista del Códice de
 * Afinidades (SPEC-06): las siete Reacciones Arcanas Duales de la matriz
 * canónica (RF-03.1) y la Resonancia Arcana Pura del catalizador universal
 * (RF-03.2), con su identificador técnico, su nombre mitológico en
 * castellano, su factor cuantitativo y su Efecto Táctico Canónico (RF-04.2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): serialización JSON nativa vía
 *     JsonSerializable + json_encode, sin librerías.
 *   - Artículo II: el factor `damageMultiplier` viaja como dato leído del
 *     Códice inmutable; el motor no decide nada por su cuenta.
 *   - Artículo IV (El Velo Arcano): `name` y `description` viajan en noble
 *     castellano, para la lámina ceremonial del Códice (RF-01.2).
 *   - Artículo V: claves técnicas en inglés camelCase, documentación en
 *     castellano.
 *
 * Contrato (plan 2.1): las claves raíz son EXACTAMENTE id, name, elements,
 * damageMultiplier, tacticalEffect, effectDurationMs, barrierDamage,
 * slowDurationMs, isCatalyst, amplificationFactor, ccExtensionMs,
 * description — emitiéndose SOLO las que la reacción declara (una dual no
 * porta claves de catalizador, y viceversa), de modo que json_encode($dto)
 * genera la estructura del plan sin campos nulos.
 *
 * Aritmética: las reacciones duales son binarias ($A + B$) y el catalizador
 * es unario (no tiene elemento opuesto). La simetría $A + B = B + A$ la
 * garantiza el servicio de la matriz (Tarea 1.2), no esta ficha.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Reacción arcana: una arista del Códice de Afinidades Elementales.
 */
final readonly class ElementalReactionDto implements JsonSerializable
{
    /**
     * Forja la ficha de una reacción del Códice.
     *
     * @param list<string> $elements Elementos implicados (dual: dos; catalizador: Arcano Puro).
     * @param float|null $damageMultiplier Factor de daño de la reacción dual (1.5 = +50%).
     * @param string|null $tacticalEffect Efecto táctico canónico (blindnessMist, hardStun, ...).
     * @param int|null $effectDurationMs Duración del efecto táctico en milisegundos.
     * @param int|null $barrierDamage Trituración de barrera en PV (Fractura Basáltica).
     * @param int|null $slowDurationMs Ralentización posterior en ms (Ciénaga Petrificante).
     * @param bool $isCatalyst Marca del catalizador universal (Arcano Puro).
     * @param float|null $amplificationFactor Factor de amplificación del catalizador (1.25 = +25%).
     * @param int|null $ccExtensionMs Extensión añadida a los controles de masas, en ms.
     * @param string $description Prosa mitológica en castellano (Artículo IV).
     *
     * @throws InvalidArgumentException Si la aridad elemental o las magnitudes son incoherentes.
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $elements,
        public ?float $damageMultiplier = null,
        public ?string $tacticalEffect = null,
        public ?int $effectDurationMs = null,
        public ?int $barrierDamage = null,
        public ?int $slowDurationMs = null,
        public bool $isCatalyst = false,
        public ?float $amplificationFactor = null,
        public ?int $ccExtensionMs = null,
        public string $description = '',
    ) {
        if (trim($this->id) === '') {
            throw new InvalidArgumentException('Toda reacción arcana exige un identificador técnico.');
        }
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('Toda reacción arcana exige un nombre litúrgico en castellano.');
        }

        $this->guardElements($this->elements);

        // Aridad canónica: el catalizador es unario; las reacciones duales, binarias.
        $expectedComponents = $this->isCatalyst ? 1 : 2;
        if (count($this->elements) !== $expectedComponents) {
            throw new InvalidArgumentException(
                $this->isCatalyst
                    ? 'El catalizador universal se define sobre un único elemento.'
                    : 'Toda reacción dual se define sobre exactamente dos elementos.',
            );
        }

        // Las magnitudes jamás penalizan: ninguna puede rebajar la unidad.
        if ($this->damageMultiplier !== null && $this->damageMultiplier < 1.0) {
            throw new InvalidArgumentException('El multiplicador de daño nunca puede ser inferior a 1.0.');
        }
        if ($this->amplificationFactor !== null && $this->amplificationFactor < 1.0) {
            throw new InvalidArgumentException('La amplificación del catalizador nunca puede ser inferior a 1.0.');
        }

        foreach ([
            'effectDurationMs' => $this->effectDurationMs,
            'barrierDamage' => $this->barrierDamage,
            'slowDurationMs' => $this->slowDurationMs,
            'ccExtensionMs' => $this->ccExtensionMs,
        ] as $magnitudeName => $magnitudeValue) {
            if ($magnitudeValue !== null && $magnitudeValue < 0) {
                throw new InvalidArgumentException("La magnitud {$magnitudeName} no admite valores negativos.");
            }
        }

        // Coherencia de catalizador: sus facultades propias y ninguna ajena.
        if ($this->isCatalyst) {
            if ($this->amplificationFactor === null || $this->ccExtensionMs === null) {
                throw new InvalidArgumentException('El catalizador exige amplificación y extensión de control declaradas.');
            }
        } elseif ($this->amplificationFactor !== null || $this->ccExtensionMs !== null) {
            throw new InvalidArgumentException('Solo el catalizador universal porta facultades de amplificación.');
        }
    }

    /**
     * Valida la lista de elementos implicados: no vacía, sin repetir y con
     * identificadores técnicos presentes.
     *
     * @param list<string> $elements
     *
     * @throws InvalidArgumentException Si algún componente elemental es inválido.
     */
    private function guardElements(array $elements): void
    {
        if ($elements === []) {
            throw new InvalidArgumentException('La reacción exige al menos un elemento implicado.');
        }

        $seen = [];
        foreach ($elements as $element) {
            if (!is_string($element) || trim($element) === '') {
                throw new InvalidArgumentException('Todo elemento implicado exige un identificador técnico.');
            }
            if (isset($seen[$element])) {
                throw new InvalidArgumentException("El elemento {$element} no puede repetirse en la misma reacción.");
            }
            $seen[$element] = true;
        }
    }

    /**
     * Número de componentes elementales de la reacción (dos en las duales).
     */
    public function componentCount(): int
    {
        return count($this->elements);
    }

    /**
     * ¿Participa el elemento dado en esta reacción? (base de la simetría A+B = B+A)
     *
     * @param string $element Identificador técnico del elemento.
     */
    public function involves(string $element): bool
    {
        return in_array($element, $this->elements, true);
    }

    /**
     * Serialización JSON nativa: emite solo las claves que la reacción
     * declara, en el orden canónico del plan 2.1. json_encode() sobre este
     * DTO genera la estructura del Códice sin campos nulos.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $payload = [
            'id' => $this->id,
            'name' => $this->name,
            'elements' => array_values($this->elements),
        ];

        if ($this->damageMultiplier !== null) {
            $payload['damageMultiplier'] = $this->damageMultiplier;
        }
        if ($this->tacticalEffect !== null) {
            $payload['tacticalEffect'] = $this->tacticalEffect;
        }
        if ($this->effectDurationMs !== null) {
            $payload['effectDurationMs'] = $this->effectDurationMs;
        }
        if ($this->barrierDamage !== null) {
            $payload['barrierDamage'] = $this->barrierDamage;
        }
        if ($this->slowDurationMs !== null) {
            $payload['slowDurationMs'] = $this->slowDurationMs;
        }
        if ($this->isCatalyst) {
            $payload['isCatalyst'] = true;
        }
        if ($this->amplificationFactor !== null) {
            $payload['amplificationFactor'] = $this->amplificationFactor;
        }
        if ($this->ccExtensionMs !== null) {
            $payload['ccExtensionMs'] = $this->ccExtensionMs;
        }

        $payload['description'] = $this->description;

        return $payload;
    }
}
