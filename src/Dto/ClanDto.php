<?php

/**
 * ClanDto.php — Objeto inmutable de datos de una hermandad arcana.
 *
 * Tarea 2.1 (TASKS-07): retrato ceremonial de un clan del santuario, tal y
 * como lo exigen el catálogo público (Endpoint 2), la ficha detallada
 * (Endpoint 3) y la clasificación del Salón de los Linajes (Endpoint 11).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): serialización JSON nativa vía
 *     JsonSerializable + json_encode, sin librerías ni dependencias npm.
 *   - Artículo II: esta ficha NO cifra ni recalcula maná alguno. Los PDA
 *     (`weeklyPoints`, `historicalPoints`) son meros contadores de gloria
 *     que el servicio de dominio acredita; jamás alcanzan la forja.
 *   - Artículo IV (El Velo Arcano): `name`, `motto` y, en su caso, el título
 *     del linaje viajan en noble castellano para el estandarte heráldico.
 *   - Artículo V: claves técnicas en inglés camelCase, documentación y
 *     narrativa en castellano.
 *
 * Contrato REST (plan 2.1, Endpoints 2, 3 y 11). Claves raíz EXACTAMENTE:
 * id, slug, name, motto, coatOfArms, lineageType, admissionMode, status,
 * patriarchId, weeklyPoints, historicalPoints, memberCount, memberLimit,
 * lastActivityAt, createdAt, updatedAt — json_encode($dto) genera esa
 * estructura sin transformación adicional.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Hermandad arcana: su identidad heráldica, su gobierno y su gloria.
 */
final readonly class ClanDto implements JsonSerializable
{
    /** Estados canónicos del ciclo de vida de una casa (RF-05.3). */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    /** Regímenes de admisión canónicos (RF-01.5). */
    public const ADMISSION_OPEN = 'open';
    public const ADMISSION_BY_APPLICATION = 'byApplication';

    /**
     * Cupo estricto de adeptos activos por hermandad (RF-01.4).
     *
     * Fuente única de verdad compartida con `ClanMemberRepository::MAX_ACTIVE_MEMBERS`
     * y `ClanService` (Tarea 2.4); viaja al cliente para que el estandarte
     * pueda exhibir la ocupación «X/30» sin conocer la constante.
     */
    public const MEMBER_LIMIT = 30;

    /** Cota canónica del Nombre Único de una hermandad (RF-01.2). */
    public const NAME_MIN_LENGTH = 4;
    public const NAME_MAX_LENGTH = 50;

    /**
     * Retrata una hermandad del santuario.
     *
     * @param string      $id               Identificador (ej. 'cln_01928a3b-4c5d').
     * @param string      $slug             Enlace público estable del clan (heredado de SPEC-01).
     * @param string      $name             Nombre Canónico Único, en noble castellano.
     * @param string      $motto            Lema heráldico solemne.
     * @param string      $coatOfArms       Identificador del blasón rúnico.
     * @param string      $lineageType      Clave canónica del Linaje rector (ej. 'primordialFlame').
     * @param string      $admissionMode    Régimen: 'open' | 'byApplication'.
     * @param string      $status           Estado: 'active' | 'archived'.
     * @param string|null $patriarchId      Clave del Patriarca en funciones; `null` si la casa quedó acéfala o disuelta.
     * @param int         $weeklyPoints     PDA de la semana en curso (RF-03).
     * @param int         $historicalPoints Acumulado histórico perpetuo (RF-04.3).
     * @param int         $memberCount      Adeptos activos en el censo (RF-01.4).
     * @param string|null $lastActivityAt   Marca ISO 8601 UTC de la última actividad del Patriarca (RF-01.9).
     * @param string|null $createdAt        Marca ISO 8601 UTC de la fundación.
     * @param string|null $updatedAt        Marca ISO 8601 UTC de la última mutación.
     *
     * @throws InvalidArgumentException Si la identidad, el nombre o un enumerado canónico son inválidos.
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public string $motto = '',
        public string $coatOfArms = '',
        public string $lineageType = '',
        public string $admissionMode = self::ADMISSION_OPEN,
        public string $status = self::STATUS_ACTIVE,
        public ?string $patriarchId = null,
        public int $weeklyPoints = 0,
        public int $historicalPoints = 0,
        public int $memberCount = 0,
        public ?string $lastActivityAt = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {
        if (trim($this->id) === '') {
            throw new InvalidArgumentException('Toda hermandad exige un identificador canónico.');
        }

        $canonicalName = trim($this->name);
        if ($canonicalName === '') {
            throw new InvalidArgumentException("La hermandad {$this->id} exige un Nombre Canónico.");
        }

        $nameLength = mb_strlen($canonicalName);
        if ($nameLength < self::NAME_MIN_LENGTH || $nameLength > self::NAME_MAX_LENGTH) {
            throw new InvalidArgumentException(
                'El Nombre Canónico debe contar entre ' . self::NAME_MIN_LENGTH . ' y ' . self::NAME_MAX_LENGTH . ' caracteres.'
            );
        }

        // Guardas de canon: un enumerado inválido jamás debe alcanzar la base de datos.
        if (!in_array($this->admissionMode, [self::ADMISSION_OPEN, self::ADMISSION_BY_APPLICATION], true)) {
            throw new InvalidArgumentException(
                "Régimen de admisión inválido «{$this->admissionMode}»: se admite 'open' o 'byApplication'."
            );
        }

        if (!in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_ARCHIVED], true)) {
            throw new InvalidArgumentException(
                "Estado de hermandad inválido «{$this->status}»: se admite 'active' o 'archived'."
            );
        }

        if ($this->weeklyPoints < 0 || $this->historicalPoints < 0 || $this->memberCount < 0) {
            throw new InvalidArgumentException('Los contadores de gloria y el censo de adeptos nunca son negativos.');
        }
    }

    /**
     * Forja el DTO desde una fila de base de datos (`clans` con, si procede,
     * el recuento de adeptos resuelto por JOIN/COUNT en el repositorio).
     *
     * Las claves de la tabla viajan en `snake_case` (Artículo V); esta
     * factoría es la única frontera que las traduce al contrato camelCase.
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
        $readInt = static fn (string $key, int $fallback = 0): int => isset($databaseRow[$key]) && is_numeric($databaseRow[$key])
            ? (int) $databaseRow[$key]
            : $fallback;

        return new self(
            id: (string) ($databaseRow['id'] ?? ''),
            slug: $readString('slug'),
            name: $readString('name'),
            motto: $readString('motto'),
            coatOfArms: $readString('coat_of_arms'),
            lineageType: $readString('lineage_type'),
            admissionMode: $readString('admission_mode') !== '' ? $readString('admission_mode') : self::ADMISSION_OPEN,
            status: $readString('status') !== '' ? $readString('status') : self::STATUS_ACTIVE,
            patriarchId: $readNullableString('patriarch_id'),
            weeklyPoints: $readInt('weekly_points'),
            historicalPoints: $readInt('historical_points'),
            memberCount: $readInt('member_count'),
            lastActivityAt: $readNullableString('last_activity_at'),
            createdAt: $readNullableString('created_at'),
            updatedAt: $readNullableString('updated_at'),
        );
    }

    /** ¿Ostenta la casa su estandarte en activo? */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** ¿Yace la casa disuelta como Herencia Ancestral? (RF-05.3) */
    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /** ¿Quedan vacantes antes de colmar el cupo de treinta adeptos? (RF-01.4) */
    public function hasVacancy(): bool
    {
        return $this->memberCount < self::MEMBER_LIMIT;
    }

    /** Vacantes restantes antes del cupo canónico. */
    public function remainingVacancies(): int
    {
        return max(0, self::MEMBER_LIMIT - $this->memberCount);
    }

    /** ¿Se ingresa de inmediato o media deliberación del Patriarca? (RF-01.5) */
    public function isOpenAdmission(): bool
    {
        return $this->admissionMode === self::ADMISSION_OPEN;
    }

    /** Gloria total acreditada a la casa: semana en curso y perpetuo sumados. */
    public function dominionPoints(): int
    {
        return $this->weeklyPoints + $this->historicalPoints;
    }

    /**
     * Serialización JSON nativa: el contrato de los Endpoints 2, 3 y 11 en su
     * orden canónico. json_encode() sobre este DTO genera EXACTAMENTE el
     * formato estipulado en el plan, preservando los nulos canónicos que el
     * plan declara opcionales (casa acéfala, instante aún no sellado).
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'               => $this->id,
            'slug'             => $this->slug,
            'name'             => $this->name,
            'motto'            => $this->motto,
            'coatOfArms'       => $this->coatOfArms,
            'lineageType'      => $this->lineageType,
            'admissionMode'    => $this->admissionMode,
            'status'           => $this->status,
            'patriarchId'      => $this->patriarchId,
            'weeklyPoints'     => $this->weeklyPoints,
            'historicalPoints' => $this->historicalPoints,
            'memberCount'      => $this->memberCount,
            'memberLimit'      => self::MEMBER_LIMIT,
            'lastActivityAt'   => $this->lastActivityAt,
            'createdAt'        => $this->createdAt,
            'updatedAt'        => $this->updatedAt,
        ];
    }
}
