<?php

/**
 * Spell.php — Entidad inmutable de dominio del Grimorio Interactivo.
 *
 * Tarea 1.4 (TASKS-01): modelo tipado que materializa los contratos
 * SpellSummaryDto (plan 2.1) y SpellDetailDto (plan 2.2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): entidad PHP pura, sin ORM ni anotaciones externas.
 *   - Artículo II (Ley del Maná): el coste de maná se LEE aquí, nunca se calcula
 *     en la entidad; su cómputo determinista pertenece al servicio de balanceo (SPEC-04).
 *   - Artículo V: propiedades y métodos en inglés camelCase, documentación en castellano.
 *
 * Diseño:
 *   - Inmutable: todas las propiedades son privadas con solo getters.
 *   - La conversión de camelCase (contrato JSON) se resuelve en toSummaryDto()/toDetailDto().
 */

declare(strict_types=1);

namespace Grimorio\Models;

/**
 * Hechizo del santuario, con proyecciones de tarjeta y de ficha de detalle.
 */
final class Spell
{
    /**
     * Afinidades elementales canónicas con su etiqueta en castellano
     * (Códice de Afinidades, SPEC-06). El identificador técnico viaja
     * intacto en `elementalAffinity`; esta tabla es la ÚNICA fuente de la
     * etiqueta en el backend (precedente de MAGIC_SCHOOL_LABELS), de modo
     * que ninguna tarjeta vuelva a rotular «Arcano Puro» por defecto
     * (hallazgo H9 del recorrido manual, Tarea 9.2 de TASKS-11).
     *
     * Los nombres son los cortos canónicos que ya usan el Tomo, el filtro
     * de afinidad y el libro del Simulador (ELEMENT_NAMES del cliente).
     */
    private const ELEMENTAL_AFFINITY_LABELS = [
        'fire'       => 'Fuego',
        'water'      => 'Agua',
        'lightning'  => 'Rayo',
        'earth'      => 'Tierra',
        'wind'       => 'Viento',
        'light'      => 'Luz',
        'darkness'   => 'Oscuridad',
        'pureArcane' => 'Arcano Puro',
    ];

    /** Etiqueta del elemento neutro (afinidad 'none' o ajena al Códice). */
    private const ELEMENTAL_AFFINITY_FALLBACK = 'Arcano Puro';

    /** Escuelas de magia canónicas con su etiqueta en castellano (seeds.sql). */
    private const MAGIC_SCHOOL_LABELS = [
        'abjuration'    => 'Abjuración',
        'conjuration'   => 'Conjuración',
        'divination'    => 'Adivinación',
        'enchantment'   => 'Encantamiento',
        'evocation'     => 'Evocación',
        'illusion'      => 'Ilusión',
        'necromancy'    => 'Nigromancia',
        'transmutation' => 'Transmutación',
    ];

    private string $id;
    private string $slug;
    private string $name;
    private string $magicSchool;
    private string $elementalAffinity;
    private int $manaCost;
    private string $clanId;
    private string $clanName;
    private string $summary;
    private string $status;
    private bool $isGenesisSample;
    private ?string $validatedAt;

    // Campos exclusivos de la ficha de detalle (SpellDetailDto, plan 2.2).
    private string $description;
    private array $components;
    private int $validationSignaturesCount;

    /**
     * Constructor con promoción de propiedades: único punto de entrada.
     *
     * @param array<string,string> $components Componentes verbal/somatic/material.
     */
    public function __construct(
        string $id,
        string $slug,
        string $name,
        string $magicSchool,
        int $manaCost,
        string $clanId,
        string $clanName,
        string $summary,
        string $status,
        bool $isGenesisSample,
        ?string $validatedAt,
        string $description = '',
        array $components = [],
        int $validationSignaturesCount = 0,
        string $elementalAffinity = 'none'
    ) {
        $this->id = $id;
        $this->slug = $slug;
        $this->name = $name;
        $this->magicSchool = $magicSchool;
        $this->elementalAffinity = $elementalAffinity !== '' ? $elementalAffinity : 'none';
        $this->manaCost = $manaCost;
        $this->clanId = $clanId;
        $this->clanName = $clanName;
        $this->summary = $summary;
        $this->status = $status;
        $this->isGenesisSample = $isGenesisSample;
        $this->validatedAt = $validatedAt;
        $this->description = $description;
        $this->components = $components;
        $this->validationSignaturesCount = $validationSignaturesCount;
    }

    /**
     * Fábrica desde una fila PDO (FETCH_ASSOC) de la tabla spells, con JOIN de clan.
     * Mantiene el mapeo snake_case (DB) -> camelCase (entidad) en un solo lugar.
     *
     * @param array<string, mixed> $row Fila asociativa del motor.
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            slug: (string) $row['slug'],
            name: (string) $row['name'],
            magicSchool: (string) $row['magic_school'],
            manaCost: (int) $row['mana_cost'],
            clanId: (string) $row['clan_id'],
            clanName: (string) ($row['clan_name'] ?? ''),
            summary: (string) $row['summary'],
            status: (string) $row['status'],
            isGenesisSample: (bool) $row['is_genesis_sample'],
            validatedAt: isset($row['validated_at']) ? (string) $row['validated_at'] : null,
            description: (string) ($row['description'] ?? ''),
            components: [
                'verbal'   => (string) ($row['components_verbal'] ?? ''),
                'somatic'  => (string) ($row['components_somatic'] ?? ''),
                'material' => (string) ($row['components_material'] ?? ''),
            ],
            validationSignaturesCount: (int) ($row['validation_signatures_count'] ?? 0),
            elementalAffinity: (string) ($row['elemental_affinity'] ?? 'none')
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMagicSchool(): string
    {
        return $this->magicSchool;
    }

    /**
     * Afinidad elemental canónica del conjuro ('none' = sin afinidad
     * declarada). El identificador es el del Códice (SPEC-06).
     */
    public function getElementalAffinity(): string
    {
        return $this->elementalAffinity;
    }

    /**
     * Etiqueta en castellano de la afinidad elemental (Artículo IV).
     *
     * Una afinidad ajena al Códice se rotula con el elemento neutro en
     * lugar de vomitar el identificador técnico en la tarjeta: el usuario
     * jamás ve un `pureArcane` crudo (RNF-03).
     */
    public function getElementalAffinityLabel(): string
    {
        return self::ELEMENTAL_AFFINITY_LABELS[$this->elementalAffinity] ?? self::ELEMENTAL_AFFINITY_FALLBACK;
    }

    public function getManaCost(): int
    {
        return $this->manaCost;
    }

    public function getClanId(): string
    {
        return $this->clanId;
    }

    public function getClanName(): string
    {
        return $this->clanName;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isGenesisSample(): bool
    {
        return $this->isGenesisSample;
    }

    public function getValidatedAt(): ?string
    {
        return $this->validatedAt;
    }

    /**
     * @return array<string,string> Componentes verbal/somatic/material.
     */
    public function getComponents(): array
    {
        return $this->components;
    }

    public function getValidationSignaturesCount(): int
    {
        return $this->validationSignaturesCount;
    }

    /**
     * Etiqueta en castellano de la escuela de magia (Artículo IV).
     * Desconocidas se devuelven tal cual para no ocultar datos al usuario.
     */
    public function getMagicSchoolLabel(): string
    {
        return self::MAGIC_SCHOOL_LABELS[$this->magicSchool] ?? $this->magicSchool;
    }

    /**
     * Proyección SpellSummaryDto (plan 2.1): tarjeta de catálogo.
     * Sin descripción ni componentes: la ficha de detalle los aporta.
     *
     * @return array<string, mixed>
     */
    public function toSummaryDto(): array
    {
        return [
            'id'               => $this->id,
            'slug'             => $this->slug,
            'name'             => $this->name,
            'magicSchool'      => $this->magicSchool,
            'magicSchoolLabel' => $this->getMagicSchoolLabel(),
            'elementalAffinity'      => $this->elementalAffinity,
            'elementalAffinityLabel' => $this->getElementalAffinityLabel(),
            'manaCost'         => $this->manaCost,
            'clanId'           => $this->clanId,
            'clanName'         => $this->clanName,
            'summary'          => $this->summary,
            'status'           => $this->status,
            'isGenesisSample'  => $this->isGenesisSample,
            'validatedAt'      => $this->validatedAt,
        ];
    }

    /**
     * Proyección SpellDetailDto (plan 2.2): ficha completa del panel superpuesto.
     *
     * @return array<string, mixed>
     */
    public function toDetailDto(): array
    {
        return $this->toSummaryDto() + [
            'description'               => $this->description,
            'components'                => $this->components,
            'validationSignaturesCount' => $this->validationSignaturesCount,
        ];
    }
}
