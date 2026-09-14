<?php

/**
 * LineageDto.php — Definición inmutable de un Linaje Mágico Canónico.
 *
 * Tarea 2.1 (TASKS-07): ficha ceremonial de uno de los ocho Linajes del
 * santuario (RF-02.1), con su elemento rector, su glifo rúnico ancestral y
 * su heráldica de estandarte (RF-02.2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): serialización JSON nativa vía
 *     JsonSerializable + json_encode, sin librerías.
 *   - Artículo II (Ley Universal del Maná): esta ficha NO porta magnitud
 *     alguna de maná, descuento ni sobrecoste. El Linaje jamás altera la
 *     fórmula universal de la forja (RF-02.3); su influjo se limita a la
 *     sinergia de Dominio (+25% de PDA), que se computa en el servicio.
 *   - Artículo IV (El Velo Arcano): `name` y `description` viajan en noble
 *     castellano para la lámina del Salón de los Linajes.
 *   - Artículo V: claves técnicas en inglés camelCase, documentación en
 *     castellano.
 *
 * Contrato (plan 2.1, Endpoint 10): claves raíz EXACTAMENTE id, name,
 * rulingElement, glyph, bannerColor, heraldicFrame, description.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Linaje Mágico: una de las ocho tradiciones elementales del santuario.
 */
final readonly class LineageDto implements JsonSerializable
{
    /** Notación heráldica admitida para el estandarte (#rrggbb). */
    private const HERALDIC_COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    /**
     * Forja la ficha de un Linaje Canónico.
     *
     * @param string $id             Clave canónica en inglés (ej. 'primordialFlame').
     * @param string $name           Título ceremonial en castellano (ej. 'Linaje de la Llama Primordial').
     * @param string $rulingElement  Afinidad elemental rectora (ej. 'fire').
     * @param string $glyph          Glifo rúnico ancestral del linaje.
     * @param string $bannerColor    Color del estandarte ceremonial (#rrggbb).
     * @param string $heraldicFrame  Marco heráldico distintivo de la casa.
     * @param string $description    Prosa mitológica en castellano (Artículo IV).
     *
     * @throws InvalidArgumentException Si algún atributo heráldico es inválido.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $rulingElement,
        public string $glyph,
        public string $bannerColor,
        public string $heraldicFrame,
        public string $description = '',
    ) {
        foreach ([
            'id' => $this->id,
            'name' => $this->name,
            'rulingElement' => $this->rulingElement,
            'glyph' => $this->glyph,
            'heraldicFrame' => $this->heraldicFrame,
        ] as $attributeName => $attributeValue) {
            if (trim($attributeValue) === '') {
                throw new InvalidArgumentException("Todo Linaje Canónico exige el atributo {$attributeName}.");
            }
        }

        if (preg_match(self::HERALDIC_COLOR_PATTERN, $this->bannerColor) !== 1) {
            throw new InvalidArgumentException(
                "El estandarte del linaje {$this->id} debe declararse en notación heráldica #rrggbb."
            );
        }
    }

    /**
     * ¿Es este linaje el que rige la afinidad elemental dada? (RF-03.4)
     *
     * Base pura de la sinergia temática: el servicio de sinergia decide
     * cuánto vale la coincidencia, no esta ficha.
     *
     * @param string $element Afinidad elemental canónica (ej. 'fire').
     */
    public function hasRulingElement(string $element): bool
    {
        return $this->rulingElement === $element;
    }

    /**
     * Serialización JSON nativa: el contrato del Endpoint 10 en su orden
     * canónico. json_encode() sobre este DTO genera la ficha del linaje sin
     * transformación adicional.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'rulingElement' => $this->rulingElement,
            'glyph'         => $this->glyph,
            'bannerColor'   => $this->bannerColor,
            'heraldicFrame' => $this->heraldicFrame,
            'description'   => $this->description,
        ];
    }
}
