<?php

/**
 * LineageProfileDto.php — Ficha heráldica del canon ceremonial del
 * juramento (SPEC-09, Tarea 2.1).
 *
 * Cubre: RF-02.1 (tarjeta contraída: nombre, glifo, estandarte, elemento
 * rector y doctrina condensada), RF-02.2 (expansión: doctrina íntegra) y
 * la nota «Sin hermandades activas» (`hasActiveClans`).
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): serialización JSON nativa vía
 *     JsonSerializable + json_encode, sin librerías.
 *   - Art. II (Ley Universal del Maná): la ficha NO porta magnitud alguna
 *     de maná ni descuento de linaje: el juramento jamás altera la fórmula
 *     universal de la forja.
 *   - Art. IV (El Velo Arcano): `name` y las doctrinas viajan en noble
 *     castellano, tal como fueron ratificadas en el Anexo A del plan.
 *   - Art. V (Dualidad): claves técnicas en inglés camelCase (contrato
 *     del plan §2.2, Endpoint 1); documentación en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Ficha heráldica inmutable de uno de los Ocho Linajes Canónicos, tal
 * como la ceremonia la exhibe al peregrino.
 */
final readonly class LineageProfileDto implements JsonSerializable
{
    /** Notación heráldica admitida para el estandarte (#rrggbb). */
    private const HERALDIC_COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    /**
     * Forja la ficha heráldica de un Linaje Canónico.
     *
     * @param string $id                Clave canónica en inglés (ej. 'primordialFlame').
     * @param string $name              Nombre ceremonial en castellano.
     * @param string $glyph             Glifo rúnico ancestral (heráldica de SPEC-07).
     * @param string $bannerColor       Estandarte ceremonial (#rrggbb).
     * @param string $rulingElement     Afinidad elemental rectora (ej. 'fire').
     * @param string $doctrineCondensed Doctrina condensada (1-2 frases, RF-02.1).
     * @param string $doctrineFull      Doctrina íntegra (2-4 frases, RF-02.2).
     * @param bool   $hasActiveClans    ¿Guarda hermandades activas? (nota de RF-02.1).
     *
     * @throws InvalidArgumentException Si algún atributo heráldico es inválido.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $glyph,
        public string $bannerColor,
        public string $rulingElement,
        public string $doctrineCondensed,
        public string $doctrineFull,
        public bool $hasActiveClans,
    ) {
        if ($this->id === '' || $this->name === '' || $this->glyph === '') {
            throw new InvalidArgumentException('La ficha heráldica exige id, nombre y glifo.');
        }
        if (preg_match(self::HERALDIC_COLOR_PATTERN, $this->bannerColor) !== 1) {
            throw new InvalidArgumentException('El estandarte heráldico debe viajar en notación #rrggbb.');
        }
        if ($this->doctrineCondensed === '' || $this->doctrineFull === '') {
            throw new InvalidArgumentException('La doctrina canónica no puede llegar vacía en ninguna granularidad.');
        }
        if (!str_starts_with($this->doctrineFull, $this->doctrineCondensed)) {
            throw new InvalidArgumentException('La doctrina condensada debe ser el recorte de la íntegra: un solo texto canónico.');
        }
    }

    /**
     * El contrato exacto del plan §2.2 (Endpoint 1): claves raíz
     * EXACTAMENTE id, name, glyph, bannerColor, rulingElement,
     * doctrineCondensed, doctrineFull, hasActiveClans.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'glyph'             => $this->glyph,
            'bannerColor'       => $this->bannerColor,
            'rulingElement'     => $this->rulingElement,
            'doctrineCondensed' => $this->doctrineCondensed,
            'doctrineFull'      => $this->doctrineFull,
            'hasActiveClans'    => $this->hasActiveClans,
        ];
    }
}
