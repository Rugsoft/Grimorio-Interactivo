<?php

/**
 * ClanPetitionDto.php — Petición del inventario consolidado del Vestíbulo
 * (SPEC-10, Tarea 3.1).
 *
 * Retrato ligero de una postulación formal del propio adepto, tal como la
 * exhibe el inventario consolidado «Tus peticiones pendientes: N de 3»
 * (RF-03.8) y los avisos solemnes de veredicto (RF-03.4). A diferencia de
 * `ClanApplicationDto` —el expediente íntegro de la sala de deliberaciones—,
 * este retrato omite al postulante (es siempre el propio adepto) y porta el
 * estado derivado `verdictSeen` que gobierna el rótulo de la Bitácora.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): serialización JSON nativa, sin librerías.
 *   - Artículo IV (El Velo Arcano): `clanName` viaja en noble castellano.
 *   - Artículo V: claves técnicas en inglés camelCase, documentación en
 *     castellano.
 *
 * Contrato REST (plan §2.2, Endpoint 1 — array `petitions[]`). Claves raíz
 * EXACTAMENTE: applicationId, clanId, clanName, status, motivation,
 * verdictMotive, verdictSeen, createdAt — json_encode($dto) genera esa
 * estructura sin transformación adicional.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Petición formal del propio adepto, en el inventario consolidado.
 */
final readonly class ClanPetitionDto implements JsonSerializable
{
    /**
     * Estados canónicos compartidos con `ClanApplicationDto` (plan §2.2,
     * tabla `clan_applications`): la fuente única de verdad del canon.
     */
    public const STATUS_PENDING = ClanApplicationDto::STATUS_PENDING;
    public const STATUS_APPROVED = ClanApplicationDto::STATUS_APPROVED;
    public const STATUS_REJECTED = ClanApplicationDto::STATUS_REJECTED;
    public const STATUS_CANCELLED = ClanApplicationDto::STATUS_CANCELLED;

    /**
     * Retrata una petición del inventario del adepto.
     *
     * @param string      $applicationId Identificador de la postulación.
     * @param string      $clanId        Hermandad solicitada.
     * @param string      $clanName      Nombre Canónico de la casa (noble castellano).
     * @param string      $status        Estado: 'pending' | 'approved' | 'rejected' | 'cancelled'.
     * @param string      $motivation    Motivación escrita de la petición (molde 20–500).
     * @param string|null $verdictMotive Motivo solemne del dictamen (solo en `rejected`).
     * @param string|null $verdictSeenAt Marca ISO 8601 UTC del contemplado (RF-03.4); `null` = veredicto sin leer o pendiente.
     * @param string|null $createdAt     Marca ISO 8601 UTC de la postulación.
     *
     * @throws InvalidArgumentException Si falta la identidad o el estado es ajeno al canon.
     */
    public function __construct(
        public string $applicationId,
        public string $clanId,
        public string $clanName = '',
        public string $status = self::STATUS_PENDING,
        public string $motivation = '',
        public ?string $verdictMotive = null,
        public ?string $verdictSeenAt = null,
        public ?string $createdAt = null,
    ) {
        foreach (['applicationId' => $this->applicationId, 'clanId' => $this->clanId] as $attributeName => $attributeValue) {
            if (trim($attributeValue) === '') {
                throw new InvalidArgumentException("Toda petición del inventario exige el atributo {$attributeName}.");
            }
        }

        if (!in_array($this->status, self::canonicalStatuses(), true)) {
            throw new InvalidArgumentException(
                "Estado de petición inválido «{$this->status}»: se admite 'pending', 'approved', 'rejected' o 'cancelled'."
            );
        }
    }

    /**
     * Traduce una fila de `clan_applications` (snake_case, con las columnas
     * de contexto que el servicio añada al hydrate) al retrato del inventario.
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
            applicationId: (string) ($databaseRow['id'] ?? ''),
            clanId: (string) ($databaseRow['clan_id'] ?? ''),
            clanName: $readString('clan_name'),
            status: $readString('status') !== '' ? $readString('status') : self::STATUS_PENDING,
            motivation: $readString('motivation'),
            verdictMotive: $readNullableString('verdict_motive'),
            verdictSeenAt: $readNullableString('verdict_seen_at'),
            createdAt: $readNullableString('created_at'),
        );
    }

    /**
     * Serialización JSON nativa: el contrato del array `petitions[]` del
     * Endpoint 1 en su orden canónico. `verdictSeen` es estado DERIVADO —
     * jamás una columna: nace de la marca del contemplado (RF-03.4).
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'applicationId' => $this->applicationId,
            'clanId'        => $this->clanId,
            'clanName'      => $this->clanName,
            'status'        => $this->status,
            'motivation'    => $this->motivation,
            'verdictMotive' => $this->verdictMotive,
            'verdictSeen'   => $this->verdictSeenAt !== null,
            'createdAt'     => $this->createdAt,
        ];
    }

    /**
     * Los cuatro estados canónicos de una petición.
     *
     * @return list<string>
     */
    private static function canonicalStatuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED];
    }
}
