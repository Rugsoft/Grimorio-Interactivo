<?php

/**
 * DominionAwardDto.php — Desglose inmutable de un otorgamiento de PDA.
 *
 * Tarea 2.1 (TASKS-07): recibo tipado de cada acreditación de Puntos de
 * Dominio Arcano, con el valor base de la acción, la bonificación de
 * sinergia de linaje y el motivo de un eventual rechazo (plan 3.2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): serialización JSON nativa, sin librerías.
 *   - Artículo II: este recibo NO toca el maná. Acota exclusivamente gloria
 *     de clan; la fórmula universal de la forja permanece inviolable.
 *   - Artículo IV (El Velo Arcano): el `reason` canónico viaja como código
 *     técnico en inglés; la leyenda mostrada al adepto se redacta en la capa
 *     de presentación, en noble castellano.
 *   - Artículo V: claves técnicas en inglés camelCase, documentación en
 *     castellano.
 *
 * Contrato REST (plan 2.1 y 3.2). Claves raíz EXACTAMENTE: actionType,
 * basePoints, awardedPoints, hasSynergy, synergyBonus, awardedAt,
 * dailyQuotaRemaining, reason — json_encode($dto) genera esa estructura sin
 * transformación adicional.
 *
 * Nota de contrato: el pseudocódigo del plan (3.2) nombra `finalPoints` al
 * total acreditado en su rama de éxito, pero `awardedPoints` en la rama del
 * tope diario. Se unifica aquí bajo `awardedPoints`, en consonancia con la
 * columna `daily_simulator_tracker.points_awarded` y con la clave ratificada
 * en SPEC-07 (`dailySimulatorPoints`).
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Recibo de la gloria acreditada a una hermandad por una acción canónica.
 */
final readonly class DominionAwardDto implements JsonSerializable
{
    /** Acciones canónicas que otorgan PDA (plan 3.2). */
    public const ACTION_SPELL_VALIDATED = 'spellValidated';
    public const ACTION_SIMULATOR_COMBO = 'simulatorCombo';
    public const ACTION_COMMUNITY_FAVORITE = 'communityFavorite';

    /** Motivo canónico de denegación: el adepto colmó su techo diario (RF-03.2). */
    public const REASON_DAILY_SIMULATOR_CAP_REACHED = 'DAILY_SIMULATOR_CAP_REACHED';

    /** El mérito ya pagó su gloria: el canon jamás paga dos veces (RNF-01). */
    public const REASON_ALREADY_AWARDED = 'ALREADY_AWARDED';

    /** El mago ya había elogiado este conjuro: un solo voto computable (RNF-02). */
    public const REASON_DUPLICATE_FAVORITE = 'DUPLICATE_FAVORITE';

    /** El elogio proviene de un morador de la propia casa: no computa (RF-03.3). */
    public const REASON_OWN_CLAN_FAVORITE = 'OWN_CLAN_FAVORITE';

    /** Escala canónica por Círculo Arcano: PDA = 100 + (Círculo × 20) (RF-03.1). */
    public const CIRCLE_BASE_POINTS = 100;
    public const CIRCLE_POINTS_PER_CIRCLE = 20;

    /** Gloria de una reacción de combo y techo diario del simulador (RF-03.2). */
    public const SIMULATOR_COMBO_POINTS = 10;
    public const DAILY_SIMULATOR_CAP = 50;

    /** Gloria de un elogio comunitario (favorito) (RF-03.3). */
    public const COMMUNITY_FAVORITE_POINTS = 5;

    /** Bonificación de sinergia temática de linaje (RF-03.4). */
    public const SYNERGY_MULTIPLIER = 1.25;

    /**
     * Firma el recibo de un otorgamiento (o de su denegación).
     *
     * @param string      $actionType           Acción canónica que motiva el cómputo.
     * @param int         $basePoints           Valor base de la acción, antes de sinergia.
     * @param int         $awardedPoints        Gloria finalmente acreditada; 0 si fue denegada.
     * @param bool        $hasSynergy           Si la afinidad rectora del linaje coincidió.
     * @param string|null $awardedAt            Marca ISO 8601 UTC del cómputo.
     * @param int|null    $dailyQuotaRemaining  Cupo diario restante tras el cómputo (solo simulador).
     * @param string|null $reason               Motivo canónico cuando la gloria fue denegada.
     *
     * @throws InvalidArgumentException Si la acción es ajena al canon o los importes son negativos.
     */
    public function __construct(
        public string $actionType,
        public int $basePoints = 0,
        public int $awardedPoints = 0,
        public bool $hasSynergy = false,
        public ?string $awardedAt = null,
        public ?int $dailyQuotaRemaining = null,
        public ?string $reason = null,
    ) {
        if (!in_array($this->actionType, self::canonicalActionTypes(), true)) {
            throw new InvalidArgumentException(
                "Acción de dominio inválida «{$this->actionType}»: se admite 'spellValidated', 'simulatorCombo' o 'communityFavorite'."
            );
        }

        if ($this->basePoints < 0 || $this->awardedPoints < 0) {
            throw new InvalidArgumentException('Los importes de gloria nunca son negativos.');
        }

        if ($this->dailyQuotaRemaining !== null && $this->dailyQuotaRemaining < 0) {
            throw new InvalidArgumentException('El cupo diario restante nunca es negativo.');
        }

        // Un recibo denegado no acredita gloria, y un recibo acreditado no
        // puede exceder jamás el valor base bonificado por la sinergia.
        if ($this->reason !== null && $this->awardedPoints !== 0) {
            throw new InvalidArgumentException('Ningún recibo denegado puede acreditar gloria alguna.');
        }

        if ($this->awardedPoints > 0 && $this->basePoints === 0) {
            throw new InvalidArgumentException('Toda gloria acreditada debe declarar su valor base de origen.');
        }
    }

    /**
     * Gloria correspondiente a la validación de un conjuro del Círculo dado
     * (RF-03.1): PDA = 100 + (Círculo × 20).
     *
     * @param int $spellCircle Círculo Arcano (1 a 5).
     *
     * @throws InvalidArgumentException Si el Círculo queda fuera del canon.
     */
    public static function circleBasePoints(int $spellCircle): int
    {
        if ($spellCircle < 1 || $spellCircle > 5) {
            throw new InvalidArgumentException(
                "Círculo Arcano inválido «{$spellCircle}»: se admite del I al V."
            );
        }

        return self::CIRCLE_BASE_POINTS + ($spellCircle * self::CIRCLE_POINTS_PER_CIRCLE);
    }

    /**
     * Recibo de una denegación por techo diario del simulador colmado
     * (RF-03.2): cero gloria, cupo agotado y motivo canónico.
     */
    public static function capReached(string $awardedAt): self
    {
        return new self(
            actionType: self::ACTION_SIMULATOR_COMBO,
            basePoints: 0,
            awardedPoints: 0,
            hasSynergy: false,
            awardedAt: $awardedAt,
            dailyQuotaRemaining: 0,
            reason: self::REASON_DAILY_SIMULATOR_CAP_REACHED,
        );
    }

    /** ¿Se acreditó gloria realmente o fue denegada? */
    public function wasAwarded(): bool
    {
        return $this->reason === null && $this->awardedPoints > 0;
    }

    /** Gloria añadida por la sinergia temática de linaje (RF-03.4). */
    public function synergyBonus(): int
    {
        return $this->awardedPoints - $this->basePoints;
    }

    /**
     * Serialización JSON nativa: el contrato del plan 3.2 en su orden
     * canónico. json_encode() sobre este DTO genera EXACTAMENTE el formato
     * estipulado, sin transformación adicional.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'actionType'          => $this->actionType,
            'basePoints'          => $this->basePoints,
            'awardedPoints'       => $this->awardedPoints,
            'hasSynergy'          => $this->hasSynergy,
            'synergyBonus'        => $this->synergyBonus(),
            'awardedAt'           => $this->awardedAt,
            'dailyQuotaRemaining' => $this->dailyQuotaRemaining,
            'reason'              => $this->reason,
        ];
    }

    /**
     * Las tres acciones canónicas que otorgan PDA.
     *
     * @return list<string>
     */
    private static function canonicalActionTypes(): array
    {
        return [self::ACTION_SPELL_VALIDATED, self::ACTION_SIMULATOR_COMBO, self::ACTION_COMMUNITY_FAVORITE];
    }
}
