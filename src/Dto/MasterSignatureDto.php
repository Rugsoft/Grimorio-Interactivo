<?php

/**
 * MasterSignatureDto.php — Firma de Consagración estampada por un Maestro:
 * su glosa litúrgica, el clan que ceñía al firmar y su eventual revocación
 * (SPEC-08, Tarea 2.1).
 *
 * Cubre: RF-02.1 (el techo de tres firmas vivas), RF-02.2 (la glosa
 * ceremonial de hasta 250 caracteres), RF-02.4 (la retractación voluntaria
 * conserva la firma con su motivo), RF-03.4 y RF-03.5 (anulación de oficio
 * por conflicto ético sobrevenido o pérdida de rango), RF-06.1 (la traza que
 * alimenta la Bitácora), RNF-01 (nada se borra: la firma revocada se
 * conserva) y RNF-05 (Dualismo Lingüístico).
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): entidad PHP pura, sin ORM ni librerías.
 *   - Art. III (Ética de Linajes): `masterClanId` conserva el clan que el
 *     firmante ceñía en el INSTANTE de firmar; es la memoria que sostiene el
 *     veto ético y la anulación de firmas sobrevenidas.
 *   - Art. IV (El Velo Arcano): la glosa viaja íntegra, con sus tildes y su
 *     puntuación, tal y como el Maestro la pronunció.
 *   - Art. V (Dualidad): propiedades en inglés camelCase, contrato JSON en
 *     camelCase, narrativa y comentarios en noble castellano.
 *
 * Inmutabilidad (RNF-01): la clase es `readonly` y no ofrece canal de
 * mutación alguno. Revocar una firma no la reescribe: el repositorio fija
 * `is_revoked` en la base y se forja un retrato nuevo.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Firma de Consagración de un Maestro sobre un conjuro en deliberación.
 */
final readonly class MasterSignatureDto implements JsonSerializable
{
    /** Longitud máxima de la glosa ceremonial (RF-02.2). */
    public const CEREMONIAL_GLOSS_MAX_LENGTH = 250;

    /** Retractación voluntaria del propio Maestro (RF-02.4). */
    public const REASON_RETRACTED = 'retracted';

    /** Conflicto ético de clan sobrevenido antes de la 3ª firma (RF-03.4). */
    public const REASON_CLAN_CONFLICT_ARISEN = 'clan_conflict_arisen';

    /** Pérdida del rango de Maestro en tránsito (RF-03.5). */
    public const REASON_RANK_LOST = 'rank_lost';

    /** El autor retiró su obra a borrador: las firmas previas se anulan (RF-01.3). */
    public const REASON_AUTHOR_WITHDRAWN = 'author_withdrawn';

    /** Destierro póstumo del conjuro por decreto soberano (RF-04.4). */
    public const REASON_SOVEREIGN_ARCHIVE = 'sovereign_archive';

    /** La obra caducó por letargo de noventa días sin resonancia (RF-01.6). */
    public const REASON_REVIEW_EXPIRED = 'review_expired';

    /** Un Dictamen de Objeción canceló los avales previos (RF-02.6). */
    public const REASON_REVIEW_REJECTED = 'review_rejected';

    /**
     * Motivos canónicos de revocación.
     *
     * La base NO cierra esta enumeración a propósito (guion 08_moderation_schema.sql):
     * la especificación nombra cinco causas —retractación, conflicto sobrevenido,
     * pérdida de rango, retirada del autor y destierro soberano— y el esquema
     * deja la puerta abierta a motivos ceremoniales nuevos. La sexta, el letargo
     * arcano de RF-01.6, y la séptima, el Dictamen de Objeción de RF-02.6, las
     * estrena SPEC-08: aquí viajan como catálogo de consulta y no como una guarda
     * que pueda rechazar una causa legítima.
     */
    public const CANONICAL_REVOCATION_REASONS = [
        self::REASON_RETRACTED,
        self::REASON_CLAN_CONFLICT_ARISEN,
        self::REASON_RANK_LOST,
        self::REASON_AUTHOR_WITHDRAWN,
        self::REASON_SOVEREIGN_ARCHIVE,
        self::REASON_REVIEW_EXPIRED,
        self::REASON_REVIEW_REJECTED,
    ];

    /**
     * Retrata una firma de Maestro.
     *
     * @param string      $id               Identificador de la firma.
     * @param string      $spellId          Conjuro avalado.
     * @param string      $masterId         Maestro firmante.
     * @param string|null $masterClanId     Clan ceñido en el instante de firmar, o null si ermitaño.
     * @param string|null $ceremonialGloss  Glosa litúrgica (máx. 250 car.), o null si firmó sin glosa.
     * @param string      $signedAt         Marca ISO 8601 UTC de la firma.
     * @param bool        $isRevoked        ¿Fue retractada o anulada de oficio?
     * @param string|null $revokedAt        Marca ISO 8601 UTC de la revocación, o null si sigue viva.
     * @param string|null $revocationReason Motivo canónico de la revocación, o null si sigue viva.
     *
     * @throws InvalidArgumentException Si falta la identidad, la glosa excede el canon o la revocación es incoherente.
     */
    public function __construct(
        public string $id,
        public string $spellId,
        public string $masterId,
        public ?string $masterClanId = null,
        public ?string $ceremonialGloss = null,
        public string $signedAt = '',
        public bool $isRevoked = false,
        public ?string $revokedAt = null,
        public ?string $revocationReason = null,
    ) {
        foreach (['id' => $this->id, 'spellId' => $this->spellId, 'masterId' => $this->masterId] as $attributeName => $attributeValue) {
            if (trim($attributeValue) === '') {
                throw new InvalidArgumentException("Toda firma de consagración exige el atributo {$attributeName}.");
            }
        }

        if ($this->ceremonialGloss !== null
            && mb_strlen($this->ceremonialGloss, 'UTF-8') > self::CEREMONIAL_GLOSS_MAX_LENGTH
        ) {
            throw new InvalidArgumentException(
                'La glosa ceremonial no puede exceder los 250 caracteres: el Velo Arcano exige concisión (RF-02.2).'
            );
        }

        // Una firma viva no porta rastro de revocación; una revocada ha de
        // declarar su instante, para que la Bitácora pueda fechar el acto.
        if (!$this->isRevoked && ($this->revokedAt !== null || $this->revocationReason !== null)) {
            throw new InvalidArgumentException(
                'Ninguna firma viva puede portar fecha ni motivo de revocación.'
            );
        }

        if ($this->isRevoked && $this->revokedAt === null) {
            throw new InvalidArgumentException(
                'Toda firma revocada ha de declarar el instante de su revocación (RNF-01).'
            );
        }
    }

    /**
     * Forja el DTO desde una fila de base de datos (`master_signatures`).
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
            masterId: $readString('master_id'),
            masterClanId: $readNullableString('master_clan_id'),
            ceremonialGloss: $readNullableString('ceremonial_gloss'),
            signedAt: $readString('signed_at'),
            isRevoked: (int) ($databaseRow['is_revoked'] ?? 0) === 1,
            revokedAt: $readNullableString('revoked_at'),
            revocationReason: $readNullableString('revocation_reason'),
        );
    }

    /** ¿Sigue viva la firma, esto es, cuenta para el techo de tres? (RF-02.1) */
    public function isActive(): bool
    {
        return !$this->isRevoked;
    }

    /** ¿Firmó el Maestro en condición de ermitaño neutral? (caso límite 3). */
    public function isNeutralHermit(): bool
    {
        return $this->masterClanId === null;
    }

    /** ¿Porta la firma glosa litúrgica? (RF-02.2) */
    public function hasCeremonialGloss(): bool
    {
        return $this->ceremonialGloss !== null && trim($this->ceremonialGloss) !== '';
    }

    /**
     * Serialización JSON nativa: el mapa de claves camelCase del contrato.
     * json_encode() sobre este DTO produce EXACTAMENTE la estructura que
     * exhiben la Torre de Deliberación y la Bitácora de Auditoría.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'               => $this->id,
            'spellId'          => $this->spellId,
            'masterId'         => $this->masterId,
            'masterClanId'     => $this->masterClanId,
            'ceremonialGloss'  => $this->ceremonialGloss,
            'signedAt'         => $this->signedAt,
            'isRevoked'        => $this->isRevoked,
            'revokedAt'        => $this->revokedAt,
            'revocationReason' => $this->revocationReason,
            'isActive'         => $this->isActive(),
        ];
    }
}
