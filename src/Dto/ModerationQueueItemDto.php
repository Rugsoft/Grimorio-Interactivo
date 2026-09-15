<?php

/**
 * ModerationQueueItemDto.php — Elemento resumido de la cola de deliberación:
 * la tarjeta que la Torre de Deliberación y el Atrio de Pruebas exhiben por
 * cada obra en revisión (SPEC-08, Tarea 2.1).
 *
 * Cubre: RF-05.1 (el Atrio público con su leyenda y el indicador 0/3), RF-05.4
 * (la Torre ordenada por antigüedad, filtrable por elemento y escuela, con el
 * aviso de incompatibilidad por conflicto de clanes), RF-03.1 (el indicador
 * `hasEthicalConflict` se calcula en servidor, jamás en el cliente) y RNF-05
 * (Dualismo Lingüístico).
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): entidad PHP pura, sin ORM ni librerías.
 *   - Art. III (Ética de Linajes): `hasEthicalConflict` viaja ya resuelto
 *     porque el cliente no conoce el clan del Maestro ni su historia de los
 *     últimos treinta días; exponer los datos crudos para que lo deduzca
 *     sería filtrar la memoria de hermandad al navegador.
 *   - Art. IV (El Velo Arcano): los campos narrativos (nombre de la obra,
 *     alias del autor, nombre del linaje) viajan en noble castellano.
 *   - Art. V (Dualidad): propiedades en inglés camelCase, contrato JSON en
 *     camelCase, narrativa y comentarios en noble castellano.
 *
 * Inmutabilidad (RNF-01): la clase es `readonly`; la cola se recompone, no se
 * muta elemento a elemento.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Elemento de la cola de deliberación: obra experimental y su contexto.
 */
final readonly class ModerationQueueItemDto implements JsonSerializable
{
    /** Firmas vivas que consuman una obra (RF-02.1). */
    public const SIGNATURES_REQUIRED = 3;

    /**
     * Retrata un elemento de la cola.
     *
     * @param string      $spellId            Conjuro en deliberación.
     * @param string      $spellName          Nombre visible de la obra.
     * @param string      $authorId           Mago autor de la obra.
     * @param string      $authorAlias        Alias público del autor.
     * @param int         $signaturesCount    Firmas vivas (0 a 3).
     * @param string      $elementalAffinity  Afinidad elemental (matriz SPEC-06).
     * @param string      $magicSchool        Escuela mágica (filtro de RF-05.4).
     * @param bool        $hasEthicalConflict ¿Media conflicto ético para el Maestro consultante?
     * @param string|null $originClanId       Clan patrimonial, o null si ermitaño.
     * @param string|null $originClanName     Nombre canónico del linaje, o null si ermitaño.
     * @param string|null $spellSlug          Enlace directo al conjuro, o null si no consta.
     * @param string|null $submittedAt        Entrada a la Torre (ISO 8601 UTC).
     * @param int|null    $waitingDays        Días naturales en espera, o null si no consta la entrada.
     *
     * @throws InvalidArgumentException Si falta la identidad o el conteo rompe el canon.
     */
    public function __construct(
        public string $spellId,
        public string $spellName,
        public string $authorId,
        public string $authorAlias = '',
        public int $signaturesCount = 0,
        public string $elementalAffinity = 'none',
        public string $magicSchool = '',
        public bool $hasEthicalConflict = false,
        public ?string $originClanId = null,
        public ?string $originClanName = null,
        public ?string $spellSlug = null,
        public ?string $submittedAt = null,
        public ?int $waitingDays = null,
    ) {
        foreach (['spellId' => $this->spellId, 'spellName' => $this->spellName, 'authorId' => $this->authorId] as $attributeName => $attributeValue) {
            if (trim($attributeValue) === '') {
                throw new InvalidArgumentException("Todo elemento de la cola exige el atributo {$attributeName}.");
            }
        }

        if ($this->signaturesCount < 0 || $this->signaturesCount > self::SIGNATURES_REQUIRED) {
            throw new InvalidArgumentException(
                'El contador de firmas de la cola vive entre cero y tres: ningún elemento puede exhibir una cuarta rúbrica.'
            );
        }

        if ($this->waitingDays !== null && $this->waitingDays < 0) {
            throw new InvalidArgumentException('Ninguna obra aguarda un número negativo de días.');
        }
    }

    /**
     * Forja el DTO desde una fila consolidada de `spell_reviews` y `spells`
     * (los nombres snake_case del esquema, con el alias del autor y el nombre
     * del linaje ya resueltos por JOIN).
     *
     * El indicador de conflicto ético NO se deduce aquí: lo entrega el
     * llamante, que es quien conoce al Maestro consultante (Art. III).
     *
     * @param array<string, null|int|string> $databaseRow      Fila cruda del motor de datos.
     * @param bool                           $hasEthicalConflict Veredicto del validador ético.
     * @param DateTimeImmutable|null         $now               Instante de consulta, o null para no medir espera.
     */
    public static function fromDatabaseRow(
        array $databaseRow,
        bool $hasEthicalConflict = false,
        ?DateTimeImmutable $now = null,
    ): self {
        $readString = static fn (string $key): string => isset($databaseRow[$key]) && is_scalar($databaseRow[$key])
            ? (string) $databaseRow[$key]
            : '';
        $readNullableString = static fn (string $key): ?string => isset($databaseRow[$key]) && is_scalar($databaseRow[$key]) && (string) $databaseRow[$key] !== ''
            ? (string) $databaseRow[$key]
            : null;

        $submittedAt = $readNullableString('submitted_at');

        return new self(
            spellId: $readString('spell_id'),
            spellName: $readString('name'),
            authorId: $readString('author_id'),
            authorAlias: $readString('author_alias'),
            signaturesCount: (int) ($databaseRow['signatures_count'] ?? 0),
            elementalAffinity: $readString('elemental_affinity') !== '' ? $readString('elemental_affinity') : 'none',
            magicSchool: $readString('magic_school'),
            hasEthicalConflict: $hasEthicalConflict,
            originClanId: $readNullableString('origin_clan_id'),
            originClanName: $readNullableString('origin_clan_name'),
            spellSlug: $readNullableString('slug'),
            submittedAt: $submittedAt,
            waitingDays: self::measureWaitingDays($submittedAt, $now),
        );
    }

    /**
     * Indicador ceremonial de firmas («0/3», «1/3», «2/3»), idéntico al que el
     * Atrio de Pruebas exhibe desde el expediente (RF-05.1).
     */
    public function signaturesIndicator(): string
    {
        return $this->signaturesCount . '/' . self::SIGNATURES_REQUIRED;
    }

    /** ¿Aguarda la obra firma alguna, o sigue huérfana en la Torre? */
    public function isUnsigned(): bool
    {
        return $this->signaturesCount === 0;
    }

    /** ¿Puede el Maestro consultante firmarla, o le veda el Artículo III? */
    public function isEligibleForSignature(): bool
    {
        return !$this->hasEthicalConflict;
    }

    /** ¿Pertenece la obra a un ermitaño sin estandarte? */
    public function isHermitWork(): bool
    {
        return $this->originClanId === null;
    }

    /**
     * Serialización JSON nativa: el mapa de claves camelCase del contrato.
     * json_encode() sobre este DTO produce EXACTAMENTE la estructura que la
     * Torre de Deliberación consume para ordenar la cola por antigüedad,
     * filtrarla y alertar del conflicto de clanes (RF-05.4).
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'spellId'             => $this->spellId,
            'spellSlug'           => $this->spellSlug,
            'spellName'           => $this->spellName,
            'authorId'            => $this->authorId,
            'authorAlias'         => $this->authorAlias,
            'originClanId'        => $this->originClanId,
            'originClanName'      => $this->originClanName,
            'elementalAffinity'   => $this->elementalAffinity,
            'magicSchool'         => $this->magicSchool,
            'signaturesCount'     => $this->signaturesCount,
            'signaturesRequired'  => self::SIGNATURES_REQUIRED,
            'signaturesIndicator' => $this->signaturesIndicator(),
            'hasEthicalConflict'  => $this->hasEthicalConflict,
            'submittedAt'         => $this->submittedAt,
            'waitingDays'         => $this->waitingDays,
        ];
    }

    /**
     * Días naturales transcurridos desde la entrada a la Torre, medidos contra
     * el instante inyectado (RNF-01: jamás se lee el reloj del sistema).
     */
    private static function measureWaitingDays(?string $submittedAt, ?DateTimeImmutable $now): ?int
    {
        if ($submittedAt === null || $now === null) {
            return null;
        }

        $enteredAt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $submittedAt, $now->getTimezone())
            ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $submittedAt, $now->getTimezone());
        if ($enteredAt === false) {
            return null;
        }

        $elapsedSeconds = $now->getTimestamp() - $enteredAt->getTimestamp();

        return $elapsedSeconds <= 0 ? 0 : (int) floor($elapsedSeconds / 86400);
    }
}
