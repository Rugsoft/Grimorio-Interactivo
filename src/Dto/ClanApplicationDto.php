<?php

/**
 * ClanApplicationDto.php — Solicitud inmutable de ingreso a una hermandad.
 *
 * Tarea 2.1 (TASKS-07): retrato de una postulación formal y su veredicto,
 * según lo exige el régimen de admisión bajo petición (RF-01.5) y su
 * resolución por el Patriarca (plan 2.2, Endpoint 6).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): serialización JSON nativa, sin librerías.
 *   - Artículo III: la deliberación queda inscrita con su instante de
 *     resolución, memoria auditable del gobierno de la casa.
 *   - Artículo IV (El Velo Arcano): `clanName` y `userAlias` viajan en noble
 *     castellano para la sala de deliberaciones del Patriarca.
 *   - Artículo V: claves técnicas en inglés camelCase, documentación en
 *     castellano.
 *
 * Contrato REST (plan 2.1, Endpoints 5 y 6). Claves raíz EXACTAMENTE: id,
 * clanId, clanName, userId, userAlias, status, createdAt, resolvedAt,
 * isPending — json_encode($dto) genera esa estructura sin transformación
 * adicional.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Postulación formal de un mago al censo de una hermandad.
 */
final readonly class ClanApplicationDto implements JsonSerializable
{
    /** Estados canónicos de una solicitud (plan 2.1, tabla `clan_applications`). */
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    /** Tope estricto de postulaciones pendientes simultáneas (RF-01.5). */
    public const MAX_PENDING_APPLICATIONS = 3;

    /**
     * Retrata una solicitud de ingreso.
     *
     * @param string      $id         Identificador de la postulación.
     * @param string      $clanId     Hermandad solicitada.
     * @param string      $userId     Postulante.
     * @param string      $status     Estado: 'pending' | 'approved' | 'rejected' | 'cancelled'.
     * @param string      $clanName   Nombre Canónico de la casa (noble castellano).
     * @param string      $userAlias  Nombre público del postulante (noble castellano).
     * @param string|null $createdAt  Marca ISO 8601 UTC de la postulación.
     * @param string|null $resolvedAt Marca ISO 8601 UTC del veredicto; `null` mientras penda deliberación.
     *
     * @throws InvalidArgumentException Si falta la identidad o el estado es ajeno al canon.
     */
    public function __construct(
        public string $id,
        public string $clanId,
        public string $userId,
        public string $status = self::STATUS_PENDING,
        public string $clanName = '',
        public string $userAlias = '',
        public ?string $createdAt = null,
        public ?string $resolvedAt = null,
    ) {
        foreach (['id' => $this->id, 'clanId' => $this->clanId, 'userId' => $this->userId] as $attributeName => $attributeValue) {
            if (trim($attributeValue) === '') {
                throw new InvalidArgumentException("Toda solicitud de ingreso exige el atributo {$attributeName}.");
            }
        }

        if (!in_array($this->status, self::canonicalStatuses(), true)) {
            throw new InvalidArgumentException(
                "Estado de solicitud inválido «{$this->status}»: se admite 'pending', 'approved', 'rejected' o 'cancelled'."
            );
        }

        // Una solicitud pendiente no puede exhibir veredicto: la deliberación
        // y su marca temporal nacen juntas.
        if ($this->status === self::STATUS_PENDING && $this->resolvedAt !== null) {
            throw new InvalidArgumentException('Ninguna solicitud pendiente puede portar ya un veredicto.');
        }
    }

    /**
     * Forja el DTO desde una fila de base de datos (`clan_applications` con,
     * si procede, los alias de clan y postulante resueltos por JOIN).
     *
     * @param array<string, null|int|string> $databaseRow
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
            id: (string) ($databaseRow['id'] ?? ''),
            clanId: (string) ($databaseRow['clan_id'] ?? ''),
            userId: (string) ($databaseRow['user_id'] ?? ''),
            status: $readString('status') !== '' ? $readString('status') : self::STATUS_PENDING,
            clanName: $readString('clan_name'),
            userAlias: $readString('user_alias'),
            createdAt: $readNullableString('created_at'),
            resolvedAt: $readNullableString('resolved_at'),
        );
    }

    /** ¿Aguarda aún la deliberación del Patriarca? */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** ¿Fue admitido el postulante al censo? */
    public function wasApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Serialización JSON nativa: el contrato de los Endpoints 5 y 6 en su
     * orden canónico. json_encode() sobre este DTO genera EXACTAMENTE el
     * formato estipulado en el plan, preservando los nulos canónicos que el
     * plan declara opcionales (deliberación aún no dictada).
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'         => $this->id,
            'clanId'     => $this->clanId,
            'clanName'   => $this->clanName,
            'userId'     => $this->userId,
            'userAlias'  => $this->userAlias,
            'status'     => $this->status,
            'createdAt'  => $this->createdAt,
            'resolvedAt' => $this->resolvedAt,
            'isPending'  => $this->isPending(),
        ];
    }

    /**
     * Los cuatro estados canónicos de una solicitud.
     *
     * @return list<string>
     */
    private static function canonicalStatuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED];
    }
}
