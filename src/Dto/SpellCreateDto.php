<?php

/**
 * SpellCreateDto.php — Carga útil completa para guardado y publicación de
 * conjuros.
 *
 * Tarea 1.3 (TASKS-04): DTO inmutable que compone los metadatos narrativos
 * (nombre, afinidad, escuela, tiempo de lanzamiento, descripción) con el
 * bloque cuantitativo completo vía SpellCalculationInputDto (Tarea 1.2).
 * Alimenta a SpellManagementService (Tarea 3.1): creación y edición de
 * borradores, publicación a experimental y actualización en moderación.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro, sin librerías.
 *   - Artículo II: los parámetros matemáticos viajan SIEMPRE dentro del
 *     SpellCalculationInputDto validado; el coste final jamás viaja en
 *     esta carga (lo computa el backend, determinismo ciego).
 *   - Artículo V: identificadores en inglés camelCase, documentación y
 *     narrativa en castellano.
 *
 * Diseño:
 *   - fromArray() deserializa el payload plano del Endpoint 2 del plan
 *     (plan 2.2) y compone el DTO cuantitativo interno; la validación de
 *     dominio matemático vive en el DTO interno (una sola fuente de verdad)
 *     y la afinidad elemental se valida contra el Códice aquí (RF-01.6).
 *   - El nombre es obligatorio y no puede quedar en blanco: la unicidad
 *     canónica la garantiza la base de datos (UNIQUE), no el DTO.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

/**
 * Carga útil completa de creación/edición de conjuros.
 */
final readonly class SpellCreateDto
{
    /**
     * Afinidades elementales canónicas del Códice (SPEC-04, RF-01.6
     * criterio ratificado; espejo de la matriz de SPEC-06). Toda afinidad
     * ajena a esta lista es rechazada con error de dominio: sin ella, el
     * cliente renderizaría un aura neutra blanca en vez del color
     * heráldico del elemento (SPEC-06, Hallazgo 12). `'shadow'` NO es
     * canónica: su valor correcto es `'darkness'`.
     *
     * @var list<string>
     */
    public const CANONICAL_AFFINITIES = [
        'fire', 'water', 'lightning', 'earth', 'wind', 'light', 'darkness', 'pureArcane',
    ];

    /**
     * Forja el DTO de creación validando los metadatos narrativos.
     *
     * @throws InvalidArgumentException Si el nombre queda en blanco o la
     *         afinidad elemental es ajena al Códice (RF-01.6).
     */
    public function __construct(
        public string $name,
        public string $elementalAffinity,
        public string $magicSchool,
        public string $castingTime,
        public string $description,
        public SpellCalculationInputDto $calculationInput,
    ) {
        // El nombre canónico es la identidad visible del conjuro (RF-01.1).
        if (trim($this->name) === '') {
            throw new \InvalidArgumentException('Todo conjuro exige un nombre digno de ser inscrito en el grimorio.');
        }

        // Afinidad canónica (RF-01.6): la validación de dominio vive aquí,
        // fuente única de verdad, antes de que el conjuro toque la base.
        if (!in_array($this->elementalAffinity, self::CANONICAL_AFFINITIES, true)) {
            throw new \InvalidArgumentException(
                'La afinidad elemental declarada es ajena al Códice: solo se admiten '
                . implode(', ', self::CANONICAL_AFFINITIES)
                . '.'
            );
        }
    }

    /**
     * Deserializa el payload plano del Endpoint 2 del plan (plan 2.2):
     * los metadatos narrativos viajan junto a los parámetros cuantitativos
     * en un único mapa, y estos últimos se componean dentro del
     * SpellCalculationInputDto validado.
     *
     * @param array<string, mixed> $payload Cuerpo decodificado de la petición.
     *
     * @throws InvalidArgumentException Si falta un campo narrativo o los
     *         parámetros cuantitativos violan el dominio del DTO interno.
     */
    public static function fromArray(array $payload): self
    {
        // Metadatos narrativos: presencia y texto no vacío.
        $name              = self::extractNonEmptyString($payload, 'name');
        $elementalAffinity = self::extractNonEmptyString($payload, 'elementalAffinity');
        $magicSchool       = self::extractNonEmptyString($payload, 'magicSchool');
        $castingTime       = self::extractNonEmptyString($payload, 'castingTime');
        $description       = self::extractNonEmptyString($payload, 'description');

        // El bloque cuantitativo completo se extrae y delega en el DTO
        // interno (Tarea 1.2), que porta las validaciones de dominio y
        // las leyendas canónicas castellanas.
        $calculationInput = SpellCalculationInputDto::fromArray($payload);

        return new self(
            name: $name,
            elementalAffinity: $elementalAffinity,
            magicSchool: $magicSchool,
            castingTime: $castingTime,
            description: $description,
            calculationInput: $calculationInput,
        );
    }

    /**
     * Serialización JSON nativa: claves camelCase con el DTO cuantitativo
     * anidado bajo «calculationInput».
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'name'              => $this->name,
            'elementalAffinity' => $this->elementalAffinity,
            'magicSchool'       => $this->magicSchool,
            'castingTime'       => $this->castingTime,
            'description'       => $this->description,
            'calculationInput'  => $this->calculationInput,
        ];
    }

    /**
     * Extrae una cadena no vacía del payload (el nombre puede contener
     * espacios, pero jamás quedar en blanco tras recortarlos).
     */
    private static function extractNonEmptyString(array $payload, string $field): string
    {
        if (!array_key_exists($field, $payload) || !is_string($payload[$field]) || trim($payload[$field]) === '') {
            throw new \InvalidArgumentException("El campo {$field} es obligatorio y debe ser texto no vacío.");
        }

        return $payload[$field];
    }
}
