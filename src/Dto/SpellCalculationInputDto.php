<?php

/**
 * SpellCalculationInputDto.php — Parámetros numéricos de entrada para la
 * fórmula de maná.
 *
 * Tarea 1.2 (TASKS-04): DTO inmutable (readonly, PHP 8.2+) con
 * validaciones de dominio en el constructor. Alimenta a
 * SpellBalanceService (Tarea 2.2) y materializa el payload del
 * Endpoint 1 del plan (POST /api/v1/spells/calculate).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro, sin librerías de validación.
 *   - Artículo II: los valores aquí contenidos son la única entrada de la
 *     fórmula determinista; el cliente jamás dicta el coste resultante.
 *   - Artículo V: identificadores en inglés camelCase, leyendas y
 *     documentación en castellano.
 *
 * Diseño:
 *   - readonly + promoción de constructor: la mutación externa falla con
 *     Error a nivel de lenguaje (defensa estructural, no convencional).
 *   - Las validaciones viven en el constructor: es imposible que exista
 *     una instancia fuera del dominio canónico (constructores «genius»).
 *   - fromArray() deserializa el cuerpo JSON del endpoint con coerción
 *     estricta y rechaza tipos sucios, campos ausentes y valores fuera
 *     de dominio con las mismas leyendas castellanas.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

/**
 * Parámetros numéricos de entrada de la fórmula de balanceo de maná.
 */
final readonly class SpellCalculationInputDto
{
    /** Modos canónicos de control de masas (plan 2.1, columna crowd_control_type). */
    private const CANONICAL_CROWD_CONTROL_TYPES = ['none', 'slow', 'root', 'stun'];

    /** Alcances canónicos del conjuro. */
    private const CANONICAL_RANGE_TYPES = ['touch', 'short', 'medium', 'long'];

    /** Geometrías de área canónicas. */
    private const CANONICAL_AREA_TYPES = ['singleTarget', 'cone', 'line', 'sphere'];

    /** Duraciones canónicas del efecto. */
    private const CANONICAL_DURATION_TYPES = ['instant', 'concentration', 'sustained'];

    /**
     * Forja el DTO validando el dominio completo de la entrada.
     *
     * @throws InvalidArgumentException Si algún valor numérico es negativo,
     *         algún modificador no pertenece al canon o algún booleano no
     *         lo es realmente.
     */
    public function __construct(
        public int $damage,
        public int $healing,
        public int $barrier,
        public string $crowdControlType,
        public string $rangeType,
        public string $areaType,
        public string $durationType,
        public bool $hasVerbal,
        public bool $hasSomatic,
        public bool $hasMaterial,
    ) {
        // Magnitudes de efecto: enteros no negativos (RF-01.2).
        if ($this->damage < 0) {
            throw new \InvalidArgumentException('El daño no puede ser un valor negativo.');
        }
        if ($this->healing < 0) {
            throw new \InvalidArgumentException('La curación no puede ser un valor negativo.');
        }
        if ($this->barrier < 0) {
            throw new \InvalidArgumentException('La barrera no puede ser un valor negativo.');
        }

        // Modificadores canónicos (RF-01.3 a RF-01.5): fuera del canon no
        // existe multiplicador posible, así que la entrada se rechaza.
        if (!in_array($this->crowdControlType, self::CANONICAL_CROWD_CONTROL_TYPES, true)) {
            throw new \InvalidArgumentException('El tipo de control de masas no pertenece al canon arcánico.');
        }
        if (!in_array($this->rangeType, self::CANONICAL_RANGE_TYPES, true)) {
            throw new \InvalidArgumentException('El tipo de alcance no pertenece al canon arcánico.');
        }
        if (!in_array($this->areaType, self::CANONICAL_AREA_TYPES, true)) {
            throw new \InvalidArgumentException('El tipo de área no pertenece al canon arcánico.');
        }
        if (!in_array($this->durationType, self::CANONICAL_DURATION_TYPES, true)) {
            throw new \InvalidArgumentException('El tipo de duración no pertenece al canon arcánico.');
        }
    }

    /**
     * Deserializa el cuerpo JSON del endpoint (Endpoint 1 del plan) hacia
     * el DTO, con coerción estricta: los enteros pueden llegar como int
     * o como string numérico entero (JSON bien formado), pero jamás como
     * texto no numérico o float con decimales.
     *
     * @param array<string, mixed> $payload Cuerpo decodificado de la petición.
     *
     * @throws InvalidArgumentException Si falta un campo, un tipo es sucio
     *         o algún valor queda fuera del dominio canónico.
     */
    public static function fromArray(array $payload): self
    {
        // Campos enteros: presencia obligatoria y coerción estricta.
        $damage  = self::extractInteger($payload, 'damage');
        $healing = self::extractInteger($payload, 'healing');
        $barrier = self::extractInteger($payload, 'barrier');

        // Campos de modificador: presencia obligatoria y texto puro.
        $crowdControlType = self::extractString($payload, 'crowdControlType');
        $rangeType        = self::extractString($payload, 'rangeType');
        $areaType         = self::extractString($payload, 'areaType');
        $durationType     = self::extractString($payload, 'durationType');

        // Componentes atenuadores: booleanos reales (is_bool, sin coerción).
        $hasVerbal  = self::extractBoolean($payload, 'hasVerbal');
        $hasSomatic = self::extractBoolean($payload, 'hasSomatic');
        $hasMaterial = self::extractBoolean($payload, 'hasMaterial');

        // El constructor revalida el dominio con sus leyendas canónicas.
        return new self(
            damage: $damage,
            healing: $healing,
            barrier: $barrier,
            crowdControlType: $crowdControlType,
            rangeType: $rangeType,
            areaType: $areaType,
            durationType: $durationType,
            hasVerbal: $hasVerbal,
            hasSomatic: $hasSomatic,
            hasMaterial: $hasMaterial,
        );
    }

    /**
     * Extrae un entero estricto del payload: se acepta int o string
     * numérico entero (coerción del JSON); se rechaza float con
     * decimales, texto no numérico o campo ausente.
     */
    private static function extractInteger(array $payload, string $field): int
    {
        if (!array_key_exists($field, $payload)) {
            throw new \InvalidArgumentException("El campo {$field} es obligatorio para el cálculo de maná.");
        }

        $value = $payload[$field];

        if (is_int($value)) {
            return $value;
        }

        // String numérico entero (p. ej. "30" de un formulario): válido.
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new \InvalidArgumentException("El campo {$field} debe ser un número entero.");
    }

    /**
     * Extrae una cadena no vacía del payload.
     */
    private static function extractString(array $payload, string $field): string
    {
        if (!array_key_exists($field, $payload) || !is_string($payload[$field]) || $payload[$field] === '') {
            throw new \InvalidArgumentException("El campo {$field} es obligatorio y debe ser texto.");
        }

        return $payload[$field];
    }

    /**
     * Extrae un booleano real del payload (sin coerción permisiva: el
     * JSON malintencionado con 1/0/"true" se rechaza).
     */
    private static function extractBoolean(array $payload, string $field): bool
    {
        if (!array_key_exists($field, $payload) || !is_bool($payload[$field])) {
            throw new \InvalidArgumentException("El campo {$field} debe ser un valor booleano.");
        }

        return $payload[$field];
    }
}
