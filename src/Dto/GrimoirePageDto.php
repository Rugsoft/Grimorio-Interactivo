<?php

/**
 * GrimoirePageDto.php — Carga útil de una página del Tomo Arcano.
 *
 * Tarea 1.1 (TASKS-05): DTO inmutable que porta todos los metadatos
 * litúrgicos del conjuro que el Simulador de Grimorio necesita para
 * iluminar la página izquierda del tomo y desatar la conjuración en la
 * Cámara de la página derecha (SPEC-05).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): serialización JSON nativa vía
 *     JsonSerializable + json_encode, sin librerías.
 *   - Artículo II: el coste de maná viaja como resultado determinista del
 *     backend, jamás recalculado ni alterado desde el cliente.
 *   - Artículo IV (El Velo Arcano): la fórmula litúrgica viaja en noble
 *     castellano para la declamación solemne (RF-04.1).
 *   - Artículo V: claves técnicas en inglés camelCase, documentación en
 *     castellano.
 *
 * Contrato (plan 2.1, Endpoint 1): las claves raíz son EXACTAMENTE id,
 * slug, name, magicSchool, elementalAffinity, circle, manaCost,
 * castingTime, incantationFormula, hasVerbal, hasSomatic, hasMaterial,
 * rangeType, areaType, durationType, effects, description, authorAlias,
 * clanName, status — json_encode($dto) genera esa estructura sin
 * transformación adicional y sin campos nulos.
 *
 * ENRIQUECIMIENTO EMBEBIDO (SPEC-11, RF-04.0, Tarea 3.3): cuando la
 * consulta nace de un adepto autenticado, `GrimoireQueryService` porta
 * el objeto `adeptState` (`collected`/`praised`, camelCase, Artículo V)
 * junto al resto de claves. Para anónimos la clave NO viaja — jamás se
 * serializa un estado vacío que mentiría sobre la lectura.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use JsonSerializable;

/**
 * Ficha litúrgica de una página del grimorio (conjuro íntegro y plano).
 */
final readonly class GrimoirePageDto implements JsonSerializable
{
    /**
     * Espejo EXACTO del mapa canónico `Spell::ELEMENTAL_AFFINITY_LABELS`
     * (hallazgo 13, §10.4 de la spec): el DTO es autocontenido —los arneses
     * lo cargan sin el autoload del front controller— y rota con la MISMA
     * voz que resumen y detalle. El arnés test_spec11_closure cruza ambos
     * mapas para prohibir la divergencia.
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

    /** Etiqueta del neutro (afinidad nula, 'none' o ajena al Códice). */
    private const ELEMENTAL_AFFINITY_FALLBACK = 'Arcano Puro';

    /** Resuelve el rótulo con el mapa espejo (única lógica de caída). */
    private static function resolveElementalAffinityLabel(string $elementalAffinity): string
    {
        if ($elementalAffinity === '' || $elementalAffinity === 'none') {
            return self::ELEMENTAL_AFFINITY_FALLBACK;
        }
        return self::ELEMENTAL_AFFINITY_LABELS[$elementalAffinity] ?? self::ELEMENTAL_AFFINITY_FALLBACK;
    }

    /**
     * Forja la página del tomo.
     *
     * @param array{damage: int, healing: int, barrier: int, crowdControlType: string} $effects
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public string $magicSchool,
        public string $elementalAffinity = 'none',
        /**
         * Etiqueta en castellano de la afinidad elemental (hallazgo 13,
         * §10.4 de la spec): resuelta con el mapa espejo del canónico
         * `Spell::ELEMENTAL_AFFINITY_LABELS`, para que la ficha del tomo
         * rote igual que Biblioteca y Simulador. Se deriva del constructor
         * cuando el llamador no la porta.
         */
        public ?string $elementalAffinityLabel = null,
        public int $circle = 1,
        public int $manaCost = 5,
        public string $castingTime = 'action',
        public string $incantationFormula = '',
        public bool $hasVerbal = false,
        public bool $hasSomatic = false,
        public bool $hasMaterial = false,
        public string $rangeType = 'touch',
        public string $areaType = 'singleTarget',
        public string $durationType = 'instant',
        public array $effects = ['damage' => 0, 'healing' => 0, 'barrier' => 0, 'crowdControlType' => 'none'],
        public string $description = '',
        public string $authorAlias = '',
        public string $clanName = '',
        public string $status = 'draft',
        /**
         * Estado embebido del adepto autenticado (RF-04.0): null para
         * anónimos (la clave jamás viaja) o el mapa camelCase
         * `collected`/`praised` para la sesión viva.
         *
         * @var array{collected: bool, praised: bool}|null
         */
        public ?array $adeptState = null,
    ) {
    }

    /**
     * Forja el DTO desde una fila de base de datos (PDO::FETCH_ASSOC de
     * la tabla `spells` con alias de autor y linaje resueltos por JOIN).
     *
     * Los componentes narrativos de la base (components_verbal/somatic/
     * material) son textos solemnes; la fórmula litúrgica para la
     * declamación (RF-04.2) es el componente verbal, único portador de
     * palabras de poder pronunciables.
     *
     * @param array<string, null|int|string> $databaseRow
     */
    public static function fromDatabaseRow(array $databaseRow): self
    {
        $readString = static fn (string $key): string => isset($databaseRow[$key]) && is_scalar($databaseRow[$key])
            ? (string) $databaseRow[$key]
            : '';
        $readInt = static fn (string $key, int $fallback): int => isset($databaseRow[$key]) && is_numeric($databaseRow[$key])
            ? (int) $databaseRow[$key]
            : $fallback;
        $readBool = static fn (string $key): bool => isset($databaseRow[$key]) && (int) $databaseRow[$key] === 1;

        return new self(
            id: $readString('id'),
            slug: $readString('slug'),
            name: $readString('name'),
            magicSchool: $readString('magic_school'),
            elementalAffinity: $readString('elemental_affinity') !== '' ? $readString('elemental_affinity') : 'none',
            // El rótulo elemental nace SIEMPRE del mapa espejo del canónico
            // (hallazgo 13, §10.4 de la spec): misma voz que resumen y detalle.
            elementalAffinityLabel: self::resolveElementalAffinityLabel(
                $readString('elemental_affinity') !== '' ? $readString('elemental_affinity') : 'none'
            ),
            circle: max(1, min(5, $readInt('circle', 1))),
            manaCost: max(0, $readInt('mana_cost', 5)),
            castingTime: $readString('casting_time') !== '' ? $readString('casting_time') : 'action',
            incantationFormula: $readString('components_verbal'),
            hasVerbal: $readBool('has_verbal'),
            hasSomatic: $readBool('has_somatic'),
            hasMaterial: $readBool('has_material'),
            rangeType: $readString('range_type') !== '' ? $readString('range_type') : 'touch',
            areaType: $readString('area_type') !== '' ? $readString('area_type') : 'singleTarget',
            durationType: $readString('duration_type') !== '' ? $readString('duration_type') : 'instant',
            effects: [
                'damage' => max(0, $readInt('damage', 0)),
                'healing' => max(0, $readInt('healing', 0)),
                'barrier' => max(0, $readInt('barrier', 0)),
                'crowdControlType' => $readString('crowd_control_type') !== '' ? $readString('crowd_control_type') : 'none',
            ],
            description: $readString('description'),
            authorAlias: $readString('author_alias'),
            clanName: $readString('clan_name'),
            status: $readString('status') !== '' ? $readString('status') : 'draft',
            // El estado del adepto jamás nace de la base: lo porta la
            // enriquecedora `withAdeptState()` tras la lectura (RF-04.0).
            adeptState: null,
        );
    }

    /**
     * Añade el estado embebido del adepto (RF-04.0, Tarea 3.3).
     *
     * El DTO es inmutable: la enriquecedora devuelve una copia nueva con
     * el mismo contenido litúrgico y el `adeptState` resuelto — la única
     * vía de llevar el estado a la serialización sin mutar la carga base.
     */
    public function withAdeptState(bool $collected, bool $praised): self
    {
        return new self(
            id: $this->id,
            slug: $this->slug,
            name: $this->name,
            magicSchool: $this->magicSchool,
            elementalAffinity: $this->elementalAffinity,
            elementalAffinityLabel: $this->elementalAffinityLabel,
            circle: $this->circle,
            manaCost: $this->manaCost,
            castingTime: $this->castingTime,
            incantationFormula: $this->incantationFormula,
            hasVerbal: $this->hasVerbal,
            hasSomatic: $this->hasSomatic,
            hasMaterial: $this->hasMaterial,
            rangeType: $this->rangeType,
            areaType: $this->areaType,
            durationType: $this->durationType,
            effects: $this->effects,
            description: $this->description,
            authorAlias: $this->authorAlias,
            clanName: $this->clanName,
            status: $this->status,
            adeptState: ['collected' => $collected, 'praised' => $praised],
        );
    }

    /**
     * Serialización JSON nativa: retorna el mapa de claves camelCase del
     * contrato del plan en su orden canónico. json_encode() sobre este
     * DTO genera EXACTAMENTE la estructura del Endpoint 1 (plan 2.1)
     * sin campos nulos.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $payload = [
            'id'                 => $this->id,
            'slug'               => $this->slug,
            'name'               => $this->name,
            'magicSchool'        => $this->magicSchool,
            'elementalAffinity'  => $this->elementalAffinity,
            'elementalAffinityLabel' => $this->elementalAffinityLabel
                ?? self::resolveElementalAffinityLabel($this->elementalAffinity),
            'circle'             => $this->circle,
            'manaCost'           => $this->manaCost,
            'castingTime'        => $this->castingTime,
            'incantationFormula' => $this->incantationFormula,
            'hasVerbal'          => $this->hasVerbal,
            'hasSomatic'         => $this->hasSomatic,
            'hasMaterial'        => $this->hasMaterial,
            'rangeType'          => $this->rangeType,
            'areaType'           => $this->areaType,
            'durationType'       => $this->durationType,
            'effects'            => $this->effects,
            'description'        => $this->description,
            'authorAlias'        => $this->authorAlias,
            'clanName'           => $this->clanName,
            'status'             => $this->status,
        ];

        // El estado del adepto solo viaja cuando existe (RF-04.0): el
        // anónimo jamás recibe un `adeptState` vacío que mentiría.
        if ($this->adeptState !== null) {
            $payload['adeptState'] = $this->adeptState;
        }

        return $payload;
    }
}
