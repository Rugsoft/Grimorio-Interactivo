<?php

/**
 * SpellReviewDto.php — Expediente de moderación de un conjuro: su estado en el
 * ciclo de vida, su contador de firmas y la huella del balance sellado
 * (SPEC-08, Tarea 2.1).
 *
 * Cubre: RF-01.1 (los cinco estados canónicos), RF-01.2 y RF-01.5 (el cupo de
 * tres y su liberación), RF-02.1 (el techo de tres firmas y el indicador 0/3),
 * RNF-05 (Dualismo Lingüístico) y Artículo II (la huella jamás se recalcula
 * aquí: viaja como se selló).
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): entidad PHP pura, sin ORM ni librerías.
 *   - Art. V (Dualidad): propiedades en inglés camelCase, contrato JSON en
 *     camelCase, narrativa y comentarios en noble castellano.
 *
 * Inmutabilidad (RNF-01): la clase es `readonly` y jamás expone canal de
 * mutación. Un expediente se retrata; para cambiarlo, el repositorio escribe
 * en la base y se forja un retrato nuevo.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Retrato inmutable del expediente de moderación de una obra.
 */
final readonly class SpellReviewDto implements JsonSerializable
{
    /** Borrador privado: la libreta del autor, invisible al santuario. */
    public const STATUS_DRAFT = 'draft';

    /** Paso 1: obra en deliberación, expuesta en el Atrio de Pruebas. */
    public const STATUS_EXPERIMENTAL = 'experimental';

    /** Paso 2: obra consagrada e inscrita en el Gran Tomo Canónico. */
    public const STATUS_VALIDATED = 'validated';

    /** Obra vetada con observaciones y devuelta a la libreta del autor. */
    public const STATUS_REJECTED = 'rejected';

    /** Obra desterrada del canon o conservada como Herencia Ancestral. */
    public const STATUS_ARCHIVED = 'archived';

    /**
     * Los cinco estados mutuamente excluyentes de RF-01.1.
     *
     * Es el contrato público del ciclo de vida: los servicios y los
     * controladores lo consumen desde aquí en lugar de repetir la lista, y un
     * aserto automatizado vigila que siga siendo idéntico al canon del
     * repositorio y de la base.
     */
    public const CANONICAL_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_EXPERIMENTAL,
        self::STATUS_VALIDATED,
        self::STATUS_REJECTED,
        self::STATUS_ARCHIVED,
    ];

    /** Firmas vivas que consuman una obra (RF-02.1). */
    public const SIGNATURES_REQUIRED = 3;

    /** Longitud exacta de la huella SHA-256 del balance (Artículo II). */
    public const FINGERPRINT_LENGTH = 64;

    /**
     * Retrata un expediente de moderación.
     *
     * @param string      $id               Identificador del expediente.
     * @param string      $spellId          Conjuro custodiado (relación 1:1).
     * @param string      $authorId         Mago creador de la obra.
     * @param string      $status           Estado canónico del ciclo de vida.
     * @param int         $signaturesCount  Firmas vivas (0 a 3).
     * @param string      $mathFingerprint  Huella SHA-256 del balance sellado.
     * @param string|null $originClanId     Clan patrimonial, o null si ermitaño.
     * @param string|null $submittedAt      Entrada a la Torre, o null si aún es borrador.
     * @param string|null $validatedAt      Consagración, o null si no la alcanzó.
     * @param string|null $rejectedAt       Veto o letargo, o null si no los sufrió.
     * @param string|null $reopenedAt       Re-apertura como borrador (RF-01.4).
     * @param string|null $archivedAt       Destierro del canon (RF-04.4).
     *
     * @throws InvalidArgumentException Si el estado, el conteo o la huella rompen el canon.
     */
    public function __construct(
        public string $id,
        public string $spellId,
        public string $authorId,
        public string $status = self::STATUS_DRAFT,
        public int $signaturesCount = 0,
        public string $mathFingerprint = '',
        public ?string $originClanId = null,
        public ?string $submittedAt = null,
        public ?string $validatedAt = null,
        public ?string $rejectedAt = null,
        public ?string $reopenedAt = null,
        public ?string $archivedAt = null,
    ) {
        foreach (['id' => $this->id, 'spellId' => $this->spellId, 'authorId' => $this->authorId] as $attributeName => $attributeValue) {
            if (trim($attributeValue) === '') {
                throw new InvalidArgumentException("Todo expediente exige el atributo {$attributeName}.");
            }
        }

        if (!in_array($this->status, self::CANONICAL_STATUSES, true)) {
            throw new InvalidArgumentException(
                'Estado fuera del canon del santuario: se admite '
                . implode(', ', self::CANONICAL_STATUSES) . '.'
            );
        }

        if ($this->signaturesCount < 0 || $this->signaturesCount > self::SIGNATURES_REQUIRED) {
            throw new InvalidArgumentException(
                'El contador de firmas vive entre cero y tres: la consagración no admite una cuarta rúbrica.'
            );
        }

        if ($this->mathFingerprint !== '' && strlen($this->mathFingerprint) !== self::FINGERPRINT_LENGTH) {
            throw new InvalidArgumentException(
                'La huella del balance ha de ser un SHA-256 de 64 caracteres: sin huella no hay juicio (Art. II).'
            );
        }
    }

    /**
     * Forja el DTO desde una fila de base de datos (`spell_reviews`, con los
     * nombres snake_case del esquema).
     *
     * @param array<string, null|int|string> $databaseRow Fila cruda del motor de datos.
     */
    public static function fromDatabaseRow(array $databaseRow): self
    {
        $readString = static fn (string $key): string => isset($databaseRow[$key]) && is_scalar($databaseRow[$key])
            ? (string) $databaseRow[$key]
            : '';
        $readNullableString = static fn (string $key): ?string => isset($databaseRow[$key]) && is_scalar($databaseRow[$key]) && (string) $databaseRow[$key] !== ''
            ? (string) $databaseRow[$key]
            : null;

        return new self(
            id: $readString('id'),
            spellId: $readString('spell_id'),
            authorId: $readString('author_id'),
            status: $readString('status') !== '' ? $readString('status') : self::STATUS_DRAFT,
            signaturesCount: (int) ($databaseRow['signatures_count'] ?? 0),
            mathFingerprint: $readString('math_fingerprint'),
            originClanId: $readNullableString('origin_clan_id'),
            submittedAt: $readNullableString('submitted_at'),
            validatedAt: $readNullableString('validated_at'),
            rejectedAt: $readNullableString('rejected_at'),
            reopenedAt: $readNullableString('reopened_at'),
            archivedAt: $readNullableString('archived_at'),
        );
    }

    /**
     * Indicador ceremonial de firmas («0/3», «1/3», «2/3»), el que el Atrio de
     * Pruebas y la Torre de Deliberación exhiben tal cual (RF-05.1, RF-05.4).
     */
    public function signaturesIndicator(): string
    {
        return $this->signaturesCount . '/' . self::SIGNATURES_REQUIRED;
    }

    /** ¿Ha consumado la obra sus tres firmas? (RF-02.3). */
    public function isConsecrated(): bool
    {
        return $this->status === self::STATUS_VALIDATED
            && $this->signaturesCount >= self::SIGNATURES_REQUIRED;
    }

    /** ¿Sigue la obra en deliberación, esto es, admite firmas? (RF-05.4). */
    public function isUnderDeliberation(): bool
    {
        return $this->status === self::STATUS_EXPERIMENTAL;
    }

    /**
     * Serialización JSON nativa: el mapa de claves camelCase del contrato
     * (plan §2.2). `json_encode()` sobre este DTO produce EXACTAMENTE la
     * estructura que consume el Atrio de Pruebas y la Torre de Deliberación.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                => $this->id,
            'spellId'           => $this->spellId,
            'authorId'          => $this->authorId,
            'originClanId'      => $this->originClanId,
            'status'            => $this->status,
            'signaturesCount'   => $this->signaturesCount,
            'signaturesRequired' => self::SIGNATURES_REQUIRED,
            'signaturesIndicator' => $this->signaturesIndicator(),
            'mathFingerprint'   => $this->mathFingerprint,
            'submittedAt'       => $this->submittedAt,
            'validatedAt'       => $this->validatedAt,
            'rejectedAt'        => $this->rejectedAt,
            'reopenedAt'        => $this->reopenedAt,
            'archivedAt'        => $this->archivedAt,
        ];
    }
}
