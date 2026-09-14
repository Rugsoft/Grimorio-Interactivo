<?php

/**
 * ClanMemberDto.php — Afiliación inmutable de un adepto a una hermandad.
 *
 * Tarea 2.1 (TASKS-07): retrato de una membresía, su rol nobiliario y su
 * eventual convalecencia arcana, según lo exigen el censo de la ficha
 * detallada (Endpoint 3), la sucesión dinástica (RF-01.9) y el aviso
 * público de convalecencia (RF-01.7).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): serialización JSON nativa, sin librerías.
 *   - Artículo III (Ética de los Clanes): la fila se preserva inmutable como
 *     memoria histórica; `leftAt` y `convalescenceExpiresAt` son el rastro
 *     que sostiene el veto ético de treinta días (RF-01.8).
 *   - Artículo IV (El Velo Arcano): `userAlias` viaja en noble castellano
 *     para el censo heráldico; el cómputo de días restantes es cosa del
 *     servidor, no del cliente.
 *   - Artículo V: claves técnicas en inglés camelCase, documentación en
 *     castellano.
 *
 * Contrato REST (plan 2.1, Endpoint 3). Claves raíz EXACTAMENTE: id, clanId,
 * userId, userAlias, role, joinedAt, leftAt, convalescenceExpiresAt, isActive
 * — json_encode($dto) genera esa estructura sin transformación adicional.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Membresía de un mago en una hermandad, con su rol y su convalecencia.
 */
final readonly class ClanMemberDto implements JsonSerializable
{
    /** Roles nobiliarios canónicos (RF-01.3). */
    public const ROLE_PATRIARCH = 'patriarch';
    public const ROLE_ADEPT = 'adept';

    /** Duración canónica de la convalecencia arcana, en días naturales (RF-01.6). */
    public const CONVALESCENCE_DAYS = 14;

    /**
     * Retrata una membresía.
     *
     * @param string      $id                      Identificador de la fila de membresía.
     * @param string      $clanId                  Hermandad de vinculación.
     * @param string      $userId                  Mago adepto.
     * @param string      $role                    Rol: 'patriarch' | 'adept'.
     * @param string      $userAlias               Nombre público del adepto (noble castellano).
     * @param string|null $joinedAt                Marca ISO 8601 UTC del ingreso formal.
     * @param string|null $leftAt                  Marca ISO 8601 UTC de la partida; `null` si la afiliación sigue vigente.
     * @param string|null $convalescenceExpiresAt  Marca ISO 8601 UTC del fin de los 14 días; `null` si no la cumple.
     *
     * @throws InvalidArgumentException Si el rol es ajeno al canon o falta la identidad.
     */
    public function __construct(
        public string $id,
        public string $clanId,
        public string $userId,
        public string $role = self::ROLE_ADEPT,
        public string $userAlias = '',
        public ?string $joinedAt = null,
        public ?string $leftAt = null,
        public ?string $convalescenceExpiresAt = null,
    ) {
        foreach (['id' => $this->id, 'clanId' => $this->clanId, 'userId' => $this->userId] as $attributeName => $attributeValue) {
            if (trim($attributeValue) === '') {
                throw new InvalidArgumentException("Toda membresía exige el atributo {$attributeName}.");
            }
        }

        if (!in_array($this->role, [self::ROLE_PATRIARCH, self::ROLE_ADEPT], true)) {
            throw new InvalidArgumentException(
                "Rol nobiliario inválido «{$this->role}»: se admite 'patriarch' o 'adept'."
            );
        }

        // Una afiliación vigente jamás porta fecha de partida; una cerrada
        // tampoco puede declarar convalecencia sin haber partido.
        if ($this->leftAt === null && $this->convalescenceExpiresAt !== null) {
            throw new InvalidArgumentException(
                'Ninguna convalecencia puede abrirse sobre una afiliación aún vigente.'
            );
        }
    }

    /**
     * Forja el DTO desde una fila de base de datos (`clan_members` con, si
     * procede, el alias del adepto resuelto por JOIN).
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
            role: $readString('role') !== '' ? $readString('role') : self::ROLE_ADEPT,
            userAlias: $readString('user_alias'),
            joinedAt: $readNullableString('joined_at'),
            leftAt: $readNullableString('left_at'),
            convalescenceExpiresAt: $readNullableString('convalescence_expires_at'),
        );
    }

    /** ¿Milita el adepto aún bajo el estandarte? */
    public function isActive(): bool
    {
        return $this->leftAt === null;
    }

    /** ¿Ciñe la corona de Patriarca o Matriarca? */
    public function isPatriarch(): bool
    {
        return $this->role === self::ROLE_PATRIARCH;
    }

    /**
     * Días naturales de convalecencia restantes, medidos contra el instante
     * inyectado (RNF-01: jamás se lee el reloj del sistema).
     *
     * El cómputo se alza al día entero inmediato superior, de modo que dos
     * horas de convalecencia restantes cuentan como un día de descanso y
     * `null` se reserva exclusivamente para quien no está convaleciente.
     *
     * @param DateTimeImmutable $now Instante de consulta.
     *
     * @return int|null Días restantes, o `null` si no media convalecencia.
     */
    public function convalescenceDaysRemaining(DateTimeImmutable $now): ?int
    {
        $expiry = $this->convalescenceExpiry();
        if ($expiry === null || $expiry <= $now) {
            return null;
        }

        $remainingSeconds = $expiry->getTimestamp() - $now->getTimestamp();

        return (int) ceil($remainingSeconds / 86400);
    }

    /** ¿Sigue el adepto purgando su convalecencia en el instante dado? (RF-01.6) */
    public function isInConvalescenceAt(DateTimeImmutable $now): bool
    {
        $expiry = $this->convalescenceExpiry();

        return $expiry !== null && $expiry > $now;
    }

    /**
     * Serialización JSON nativa: el contrato del Endpoint 3 en su orden
     * canónico. json_encode() sobre este DTO genera EXACTAMENTE el formato
     * estipulado en el plan, preservando los nulos canónicos que el plan
     * declara opcionales (afiliación vigente, adepto no convaleciente).
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                      => $this->id,
            'clanId'                  => $this->clanId,
            'userId'                  => $this->userId,
            'userAlias'               => $this->userAlias,
            'role'                    => $this->role,
            'joinedAt'                => $this->joinedAt,
            'leftAt'                  => $this->leftAt,
            'convalescenceExpiresAt'  => $this->convalescenceExpiresAt,
            'isActive'                => $this->isActive(),
        ];
    }

    /**
     * Interpreta la marca de fin de convalecencia como instante UTC.
     *
     * Se admiten las dos notaciones ISO 8601 que el santuario maneja: la
     * canónica `Y-m-d\TH:i:s\Z` que escriben los repositorios y la de
     * desplazamiento (`+00:00`) que emplean otros módulos.
     */
    private function convalescenceExpiry(): ?DateTimeImmutable
    {
        if ($this->convalescenceExpiresAt === null || trim($this->convalescenceExpiresAt) === '') {
            return null;
        }

        $utc = new DateTimeZone('UTC');

        foreach ([DateTimeInterface::ATOM, 'Y-m-d\TH:i:s\Z'] as $notation) {
            $expiry = DateTimeImmutable::createFromFormat($notation, $this->convalescenceExpiresAt, $utc);
            if ($expiry !== false) {
                return $expiry;
            }
        }

        return null;
    }
}
