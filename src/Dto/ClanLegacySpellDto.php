<?php

/**
 * ClanLegacySpellDto.php — Conjuro sellado bajo el estandarte de una hermandad.
 *
 * Tarea 6.4 (TASKS-07): retrato de una obra ratificada que pertenece
 * perpetuamente al clan bajo cuyo estandarte fue concebida (RF-05.1). Es la
 * pieza que sirve el Endpoint 13 del plan (`GET /api/v1/clans/{id}/spells`) y
 * que la ficha del clan exhibe, incluso cuando la casa yace disuelta como
 * «Herencia Ancestral» (RF-05.3).
 *
 * Nota de canon (RF-05.1): el crédito del autor original JAMÁS se pierde
 * aunque el mago haya partido o sido expulsado; por eso `authorAlias` viaja
 * aquí como campo propio y no como una relación viva con la membresía.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): serialización JSON nativa vía
 *     JsonSerializable + json_encode, sin librerías ni dependencias npm.
 *   - Artículo II: esta ficha LEE el coste de maná; jamás lo calcula ni lo
 *     altera (el cómputo determinista pertenece al servicio de balanceo).
 *   - Artículo IV (El Velo Arcano): la etiqueta de la escuela viaja en noble
 *     castellano (`magicSchoolLabel`); el crédito se rotula «Forjado por».
 *   - Artículo V: claves técnicas en inglés camelCase, comentarios castellanos.
 *
 * Contrato REST (plan 2.2, Endpoint 13). Claves raíz EXACTAMENTE: id, slug,
 * name, magicSchool, magicSchoolLabel, circle, manaCost, authorAlias,
 * validatedAt, summary, isGenesisSample — json_encode($dto) genera esa
 * estructura sin transformación adicional.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use Grimorio\Models\Spell;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Conjuro ratificado que compone el legado inviolable de una hermandad.
 */
final readonly class ClanLegacySpellDto implements JsonSerializable
{
    /**
     * Retrata un conjuro del legado.
     *
     * @param string      $id               Identificador del conjuro (spl_*).
     * @param string      $slug             Enlace directo estable (heredado de SPEC-01).
     * @param string      $name             Nombre visible del conjuro.
     * @param string      $magicSchool      Clave canónica de la escuela.
     * @param string      $magicSchoolLabel Etiqueta ceremonial en castellano.
     * @param int         $circle           Círculo Arcano (1 a 5) sellado en la forja.
     * @param int         $manaCost         Coste determinista de maná (leído, jamás recalculado).
     * @param string      $authorAlias      Alias del autor original (RF-05.1: crédito perpetuo).
     * @param string|null $validatedAt      Marca ISO 8601 UTC de la ratificación.
     * @param string      $summary          Resumen breve del conjuro.
     * @param bool        $isGenesisSample  ¿Es un Pergamino Primordial de génesis?
     *
     * @throws InvalidArgumentException Si la identidad o los contadores no son canónicos.
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public string $magicSchool,
        public string $magicSchoolLabel,
        public int $circle,
        public int $manaCost,
        public string $authorAlias = '',
        public ?string $validatedAt = null,
        public string $summary = '',
        public bool $isGenesisSample = false,
    ) {
        if (trim($this->id) === '') {
            throw new InvalidArgumentException('Todo conjuro del legado exige un identificador canónico.');
        }

        if (trim($this->name) === '') {
            throw new InvalidArgumentException("El conjuro {$this->id} exige un nombre visible.");
        }

        if ($this->circle < 1 || $this->circle > 5) {
            throw new InvalidArgumentException(
                "Círculo Arcano inválido «{$this->circle}»: el canon admite del 1 al 5."
            );
        }

        if ($this->manaCost < 0) {
            throw new InvalidArgumentException('El coste de maná jamás es negativo (Artículo II).');
        }
    }

    /**
     * Forja el DTO desde la entidad del conjuro y los dos campos que el
     * contrato del legado añade: el Círculo sellado en la forja y el alias del
     * autor original, que la tabla resuelve por JOIN con `users`.
     *
     * La etiqueta de la escuela se delega en la entidad Spell: una sola fuente
     * de verdad para los nombres castellanos de las escuelas canónicas.
     */
    public static function fromSpell(Spell $spell, int $circle, string $authorAlias): self
    {
        return new self(
            id: $spell->getId(),
            slug: $spell->getSlug(),
            name: $spell->getName(),
            magicSchool: $spell->getMagicSchool(),
            magicSchoolLabel: $spell->getMagicSchoolLabel(),
            circle: $circle,
            manaCost: $spell->getManaCost(),
            authorAlias: $authorAlias,
            validatedAt: $spell->getValidatedAt(),
            summary: $spell->getSummary(),
            isGenesisSample: $spell->isGenesisSample(),
        );
    }

    /**
     * Serialización JSON nativa: el contrato del Endpoint 13 en su orden
     * canónico. Preserva los nulos declarados por el plan (obra génesis sin
     * marca de ratificación de usuario).
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'               => $this->id,
            'slug'             => $this->slug,
            'name'             => $this->name,
            'magicSchool'      => $this->magicSchool,
            'magicSchoolLabel' => $this->magicSchoolLabel,
            'circle'           => $this->circle,
            'manaCost'         => $this->manaCost,
            'authorAlias'      => $this->authorAlias,
            'validatedAt'      => $this->validatedAt,
            'summary'          => $this->summary,
            'isGenesisSample'  => $this->isGenesisSample,
        ];
    }
}
