<?php

/**
 * VestibuleClanDto.php — Casa del Vestíbulo con su estado ante el adepto
 * (SPEC-10, Tarea 3.1).
 *
 * Envuelve la identidad heráldica de `ClanDto` (plan §1.1: el catálogo del
 * Vestíbulo REUTILIZA la identidad de las casas) con el estado derivado de
 * la relación del adepto ante ella (RF-01.3, RF-01.7, RF-03.5): el gesto
 * disponible (`join` | `petition` | `withdraw` | `null` = vedado) y la
 * leyenda solemne cuando el gesto está vedado. La interfaz pinta lo que el
 * santuario declara — este DTO — jamás decide por su cuenta.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): serialización JSON nativa, sin librerías.
 *   - Artículo IV (El Velo Arcano): `name`, `motto` y `vedadoLegend` viajan
 *     en noble castellano.
 *   - Artículo V: claves técnicas en inglés camelCase, documentación en
 *     castellano.
 *
 * Contrato REST (plan §2.2, Endpoint 1 — array `clans[]`). Claves raíz
 * EXACTAMENTE: clanId, name, motto, coatOfArms, lineageType, memberCount,
 * memberLimit, admissionMode, admissionModeLabel, isRegent, adeptRelation,
 * gesture, vedadoLegend — más `petitionId` cuando media petición pendiente.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Tarjeta de hermandad del Vestíbulo, con la relación del adepto derivada.
 */
final readonly class VestibuleClanDto implements JsonSerializable
{
    /**
     * Relaciones canónicas del adepto ante la casa (RF-01.7): derivadas del
     * instante, jamás un flag persistente.
     */
    public const RELATION_NONE = 'none';
    public const RELATION_PENDING = 'pending';
    public const RELATION_OWN_HOUSE = 'ownHouse';

    /**
     * Gestos canónicos disponibles (plan §2.2): `null` significa gesto vedado,
     * y entonces `vedadoLegend` porta la leyenda solemne (RF-03.5).
     */
    public const GESTURE_JOIN = 'join';
    public const GESTURE_PETITION = 'petition';
    public const GESTURE_WITHDRAW = 'withdraw';

    /** Rótulos castellanos de los regímenes de admisión (RF-01.3). */
    public const ADMISSION_LABELS = [
        ClanDto::ADMISSION_OPEN => 'Admisión abierta',
        ClanDto::ADMISSION_BY_APPLICATION => 'Requiere petición formal',
    ];

    /**
     * Retrata una casa del catálogo del Vestíbulo.
     *
     * @param string      $clanId             Identificador de la hermandad.
     * @param string      $name               Nombre Canónico Único (noble castellano).
     * @param string      $motto              Lema heráldico solemne.
     * @param string      $coatOfArms         Identificador del blasón rúnico (se codifica, jamás se imprime — SPEC-02 RF-07.3).
     * @param string      $lineageType        Clave canónica del Linaje rector.
     * @param int         $memberCount        Adeptos activos en el censo.
     * @param int         $memberLimit        Plenitud de la casa (30, SPEC-07).
     * @param string      $admissionMode      Régimen: 'open' | 'byApplication'.
     * @param bool        $isRegent           ¿Bija la corona del Clan Regente de la semana?
     * @param string      $adeptRelation      Relación del adepto: 'none' | 'pending' | 'ownHouse'.
     * @param string|null $gesture            Gesto disponible: 'join' | 'petition' | 'withdraw' | `null` = vedado.
     * @param string|null $vedadoLegend       Leyenda solemne cuando el gesto está vedado (RF-03.5); `null` si el gesto procede.
     * @param string|null $petitionId         Identificador de la petición pendiente (solo con `gesture: 'withdraw'`).
     *
     * @throws InvalidArgumentException Si falta la identidad, un enumerado canónico es inválido o la pareja gesto/leyenda contradice el canon.
     */
    public function __construct(
        public string $clanId,
        public string $name,
        public string $motto = '',
        public string $coatOfArms = '',
        public string $lineageType = '',
        public int $memberCount = 0,
        public int $memberLimit = ClanDto::MEMBER_LIMIT,
        public string $admissionMode = ClanDto::ADMISSION_OPEN,
        public bool $isRegent = false,
        public string $adeptRelation = self::RELATION_NONE,
        public ?string $gesture = null,
        public ?string $vedadoLegend = null,
        public ?string $petitionId = null,
    ) {
        if (trim($this->clanId) === '' || trim($this->name) === '') {
            throw new InvalidArgumentException('Toda casa del Vestíbulo exige su identificador y su Nombre Canónico.');
        }

        if (!in_array($this->admissionMode, [ClanDto::ADMISSION_OPEN, ClanDto::ADMISSION_BY_APPLICATION], true)) {
            throw new InvalidArgumentException(
                "Régimen de admisión inválido «{$this->admissionMode}»: se admite 'open' o 'byApplication'."
            );
        }

        if (!in_array($this->adeptRelation, [self::RELATION_NONE, self::RELATION_PENDING, self::RELATION_OWN_HOUSE], true)) {
            throw new InvalidArgumentException(
                "Relación del adepto inválida «{$this->adeptRelation}»: se admite 'none', 'pending' o 'ownHouse'."
            );
        }

        // El gesto y su leyenda son pareja indivisible: sin gesto, leyenda;
        // con gesto, la leyenda calla. La interfaz jamás improvisa el vedado.
        if ($this->gesture === null && trim((string) $this->vedadoLegend) === '') {
            throw new InvalidArgumentException('Un gesto vedado exige su leyenda solemne (RF-03.5).');
        }

        if ($this->gesture !== null && $this->vedadoLegend !== null) {
            throw new InvalidArgumentException('Un gesto disponible jamás porta leyenda vedada.');
        }
    }

    /**
     * Serialización JSON nativa: el contrato del array `clans[]` del
     * Endpoint 1 en su orden canónico. El rótulo del régimen viaja servido
     * (no lo improvisa el cliente) y `petitionId` solo aparece cuando media
     * petición pendiente — nulo canónico, preservado por json_encode.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'clanId'             => $this->clanId,
            'name'               => $this->name,
            'motto'              => $this->motto,
            'coatOfArms'         => $this->coatOfArms,
            'lineageType'        => $this->lineageType,
            'memberCount'        => $this->memberCount,
            'memberLimit'        => $this->memberLimit,
            'admissionMode'      => $this->admissionMode,
            'admissionModeLabel' => self::ADMISSION_LABELS[$this->admissionMode] ?? $this->admissionMode,
            'isRegent'           => $this->isRegent,
            'adeptRelation'      => $this->adeptRelation,
            'gesture'            => $this->gesture,
            'vedadoLegend'       => $this->vedadoLegend,
            'petitionId'         => $this->petitionId,
        ];
    }
}
