<?php

/**
 * ObjectionVerdictDto.php — Dictamen de Objeción Fundamentada emitido por un
 * Maestro: el veto de calidad que devuelve una obra a la libreta de su autor
 * (SPEC-08, Tarea 2.1).
 *
 * Cubre: RF-02.5 (justificación obligatoria de al menos 20 caracteres), RF-02.6
 * (el veto pasa el conjuro a `rejected` de inmediato), RF-06.2 (el autor lee el
 * texto ÍNTEGRO de la objeción en su libreta privada), RNF-01 (memoria eterna:
 * el dictamen jamás se edita ni se borra) y RNF-05 (Dualismo Lingüístico).
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): entidad PHP pura, sin ORM ni librerías.
 *   - Art. IV (El Velo Arcano): el texto viaja íntegro, con sus tildes y su
 *     puntuación, carácter por carácter: la subsanación de RF-01.4 depende de
 *     que el autor lea exactamente lo que el Maestro pronunció.
 *   - Art. V (Dualidad): propiedades en inglés camelCase, contrato JSON en
 *     camelCase, narrativa y comentarios en noble castellano.
 *
 * Inmutabilidad (RNF-01): la clase es `readonly` y no expone canal de
 * mutación. No existen métodos de edición ni de borrado —igual que su tabla en
 * la base—, y la enmienda de una obra se logra con una deliberación nueva,
 * jamás reescribiendo la anterior.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Dictamen de objeción fundamentada sobre un conjuro en deliberación.
 */
final readonly class ObjectionVerdictDto implements JsonSerializable
{
    /** Longitud mínima de la justificación en castellano (RF-02.5, RF-04.5). */
    public const OBJECTION_REASON_MIN_LENGTH = 20;

    /**
     * Retrata un dictamen de objeción.
     *
     * @param string $id              Identificador del dictamen.
     * @param string $spellId         Conjuro objetado.
     * @param string $masterId        Maestro que emitió el veto.
     * @param string $objectionReason Justificación íntegra en castellano (mín. 20 car.).
     * @param string $objectedAt      Marca ISO 8601 UTC del dictamen.
     *
     * @throws InvalidArgumentException Si falta la identidad o la justificación no alcanza el canon.
     */
    public function __construct(
        public string $id,
        public string $spellId,
        public string $masterId,
        public string $objectionReason = '',
        public string $objectedAt = '',
    ) {
        foreach (['id' => $this->id, 'spellId' => $this->spellId, 'masterId' => $this->masterId] as $attributeName => $attributeValue) {
            if (trim($attributeValue) === '') {
                throw new InvalidArgumentException("Todo dictamen de objeción exige el atributo {$attributeName}.");
            }
        }

        // El umbral se mide sobre el texto TAL CUAL: una justificación de
        // veinte espacios no es una justificación, y por eso el recorte
        // antecede al cómputo de longitud.
        if (mb_strlen(trim($this->objectionReason), 'UTF-8') < self::OBJECTION_REASON_MIN_LENGTH) {
            throw new InvalidArgumentException(
                'Toda objeción exige una justificación de al menos veinte caracteres: el veto sin fundamento no es veto (RF-02.5).'
            );
        }
    }

    /**
     * Forja el DTO desde una fila de base de datos (`objection_verdicts`).
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
            masterId: $readString('master_id'),
            objectionReason: $readString('objection_reason'),
            objectedAt: $readString('objected_at'),
        );
    }

    /** Longitud en caracteres de la justificación, tal y como se leerá en la libreta. */
    public function reasonLength(): int
    {
        return mb_strlen($this->objectionReason, 'UTF-8');
    }

    /**
     * Serialización JSON nativa: el mapa de claves camelCase del contrato.
     * json_encode() sobre este DTO produce EXACTAMENTE la estructura que la
     * libreta privada del autor consume para subsanar su obra (RF-06.2).
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'              => $this->id,
            'spellId'         => $this->spellId,
            'masterId'        => $this->masterId,
            'objectionReason' => $this->objectionReason,
            'reasonLength'    => $this->reasonLength(),
            'objectedAt'      => $this->objectedAt,
        ];
    }
}
