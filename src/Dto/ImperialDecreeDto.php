<?php

/**
 * ImperialDecreeDto.php — Decreto del Administrador Supremo: la intervención
 * unilateral de la potestad máxima, siempre con su Edicto Imperial (SPEC-08,
 * Tarea 2.1).
 *
 * Cubre: RF-04.1 (Firma Soberana instantánea), RF-04.2 (rescate de obra
 * vetada), RF-04.3 y RF-04.4 (revocación y archivo póstumo), RF-04.5 (todo
 * decreto porta su edicto de al menos veinte caracteres), RF-06.1 (la memoria
 * pública del acto en la Bitácora) y RNF-05 (Dualismo Lingüístico).
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): entidad PHP pura, sin ORM ni librerías.
 *   - Art. II (Ley Universal del Maná): el decreto no sella ni recalcula
 *     huella alguna; solo ordena transiciones sobre el expediente.
 *   - Art. IV (El Velo Arcano): el edicto viaja íntegro a la Bitácora pública.
 *   - Art. V (Dualidad): propiedades en inglés camelCase, contrato JSON en
 *     camelCase, narrativa y comentarios en noble castellano.
 *
 * Inmutabilidad (RNF-01): la clase es `readonly`. Un decreto inscrito no se
 * enmienda: la potestad suprema es auditable o no es suprema.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Decreto de la Administración Suprema con su edicto imperial.
 */
final readonly class ImperialDecreeDto implements JsonSerializable
{
    /** Firma Soberana instantánea: eleva un conjuro experimental a `validated` (RF-04.1). */
    public const TYPE_SOVEREIGN_VALIDATION = 'sovereignValidation';

    /** Rescate de obra vetada, reiniciándola en deliberación con 0/3 firmas (RF-04.2). */
    public const TYPE_RESCUE_TO_EXPERIMENTAL = 'rescueToExperimental';

    /** Rescate de obra vetada, consagrándola directamente (RF-04.2). */
    public const TYPE_RESCUE_TO_VALIDATED = 'rescueToValidated';

    /** Revocación y archivo póstumo, con eventual resta de PDA (RF-04.3, RF-04.4). */
    public const TYPE_REVOKE_AND_ARCHIVE = 'revokeAndArchive';

    /**
     * Los cuatro decretos soberanos de RF-04, mutuamente excluyentes.
     *
     * Es el contrato público del acto supremo: coincide nombre a nombre con el
     * `CHECK` de `sovereign_decrees` en la base y con el catálogo del
     * repositorio, y un aserto automatizado vigila esa triple identidad.
     */
    public const CANONICAL_DECREE_TYPES = [
        self::TYPE_SOVEREIGN_VALIDATION,
        self::TYPE_RESCUE_TO_EXPERIMENTAL,
        self::TYPE_RESCUE_TO_VALIDATED,
        self::TYPE_REVOKE_AND_ARCHIVE,
    ];

    /** Longitud mínima del Edicto Imperial (RF-04.5). */
    public const IMPERIAL_DECREE_MIN_LENGTH = 20;

    /**
     * Retrata un decreto soberano.
     *
     * @param string $id                 Identificador del decreto.
     * @param string $spellId            Conjuro sobre el que se decretó.
     * @param string $adminId            Administrador Supremo actuante.
     * @param string $decreeType         Decreto canónico de RF-04.
     * @param string $imperialDecreeText Edicto imperial íntegro (mín. 20 car.).
     * @param string $decreedAt          Marca ISO 8601 UTC del decreto.
     *
     * @throws InvalidArgumentException Si falta la identidad, el decreto es ajeno al canon o el edicto no alcanza el umbral.
     */
    public function __construct(
        public string $id,
        public string $spellId,
        public string $adminId,
        public string $decreeType = self::TYPE_SOVEREIGN_VALIDATION,
        public string $imperialDecreeText = '',
        public string $decreedAt = '',
    ) {
        foreach (['id' => $this->id, 'spellId' => $this->spellId, 'adminId' => $this->adminId] as $attributeName => $attributeValue) {
            if (trim($attributeValue) === '') {
                throw new InvalidArgumentException("Todo decreto imperial exige el atributo {$attributeName}.");
            }
        }

        if (!in_array($this->decreeType, self::CANONICAL_DECREE_TYPES, true)) {
            throw new InvalidArgumentException(
                'Decreto ajeno al canon supremo «' . $this->decreeType . '»: se admite '
                . implode(', ', self::CANONICAL_DECREE_TYPES) . '.'
            );
        }

        if (mb_strlen(trim($this->imperialDecreeText), 'UTF-8') < self::IMPERIAL_DECREE_MIN_LENGTH) {
            throw new InvalidArgumentException(
                'Todo decreto exige un Edicto Imperial de al menos veinte caracteres: el Cónclave Supremo no actúa en silencio (RF-04.5).'
            );
        }
    }

    /**
     * Forja el DTO desde una fila de base de datos (`sovereign_decrees`).
     *
     * @param array<string, null|int|string> $databaseRow Fila cruda del motor de datos.
     */
    public static function fromDatabaseRow(array $databaseRow): self
    {
        $readString = static fn (string $key): string => isset($databaseRow[$key]) && is_scalar($databaseRow[$key])
            ? (string) $databaseRow[$key]
            : '';

        return new self(
            id: $readString('id'),
            spellId: $readString('spell_id'),
            adminId: $readString('admin_id'),
            decreeType: $readString('decree_type') !== '' ? $readString('decree_type') : self::TYPE_SOVEREIGN_VALIDATION,
            imperialDecreeText: $readString('imperial_decree_text'),
            decreedAt: $readString('decreed_at'),
        );
    }

    /** ¿Consagra la obra de oficio? (RF-04.1) */
    public function consecratesSpell(): bool
    {
        return $this->decreeType === self::TYPE_SOVEREIGN_VALIDATION;
    }

    /** ¿Desterra la obra del canon? (RF-04.3, RF-04.4) */
    public function archivesSpell(): bool
    {
        return $this->decreeType === self::TYPE_REVOKE_AND_ARCHIVE;
    }

    /** ¿Es un rescate de obra vetada? (RF-04.2) */
    public function rescuesSpell(): bool
    {
        return $this->decreeType === self::TYPE_RESCUE_TO_EXPERIMENTAL
            || $this->decreeType === self::TYPE_RESCUE_TO_VALIDATED;
    }

    /**
     * Serialización JSON nativa: el mapa de claves camelCase del contrato.
     * json_encode() sobre este DTO produce EXACTAMENTE la estructura que la
     * Bitácora de Auditoría pública exhibe junto al edicto imperial.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                 => $this->id,
            'spellId'            => $this->spellId,
            'adminId'            => $this->adminId,
            'decreeType'         => $this->decreeType,
            'imperialDecreeText' => $this->imperialDecreeText,
            'decreedAt'          => $this->decreedAt,
        ];
    }
}
