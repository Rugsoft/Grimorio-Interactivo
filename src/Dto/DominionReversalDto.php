<?php

declare(strict_types=1);

/**
 * DominionReversalDto.php — Recibo inmutable de una deducción retroactiva de
 * Puntos de Dominio Arcano (SPEC-08, Tarea 2.5).
 *
 * Cubre: RF-04.4 (en caso de fraude manifiesto, el sistema deduce
 * retroactivamente los PDA que el conjuro hubiera otorgado a su clan) y
 * Artículo III.3 (la consecuencia aritmética de un veredicto ha de poder
 * auditarse: cuánto se dedujo, de qué contador y de qué hermandad).
 *
 * Este recibo es el ÚNICO lugar donde la operación declara su aritmética. La
 * deducción no se inscribe como un asiento negativo del libro de méritos
 * (`dominion_awards` es un diario de MÉRITOS, y una sentencia no es un mérito):
 * el decreto vive en `sovereign_decrees`, su edicto y su efecto en la Bitácora,
 * y su aritmética en este recibo, que el servicio viaja a la justificación del
 * acto `SOVEREIGN_POINTS_DEDUCTED`.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): serialización JSON nativa, sin librerías.
 *   - Artículo II: este recibo NO toca el maná; acota exclusivamente gloria.
 *   - Artículo IV (El Velo Arcano): el `reason` canónico viaja como código
 *     técnico en inglés; la leyenda al lector se redacta en la presentación.
 *   - Artículo V: claves técnicas en inglés camelCase, documentación en
 *     castellano.
 *
 * Contrato REST. Claves raíz EXACTAMENTE: clanId, spellId, revokedPoints,
 * weeklyDebited, historicalDebited, outstandingPoints, revokedAt, reason —
 * json_encode($dto) genera esa estructura sin transformación adicional.
 */

namespace Grimorio\Dto;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Recibo de la gloria deducida retroactivamente a una hermandad.
 */
final readonly class DominionReversalDto implements JsonSerializable
{
    /** Contador semanal: la gloria disputa la contienda de la semana en curso. */
    public const COUNTER_WEEKLY = 'weekly';

    /** Contador histórico: la gloria ya fue plegada por un cierre dominical. */
    public const COUNTER_HISTORICAL = 'historical';

    /** No hubo mérito que deducir: el conjuro nunca pagó gloria a su linaje. */
    public const REASON_NO_MERIT = 'NO_MERIT_TO_REVERSE';

    /** El linaje de la obra no consta en los anales: nada que descontar. */
    public const REASON_CLAN_VANISHED = 'ORIGIN_CLAN_NOT_FOUND';

    /**
     * Firma el recibo de una deducción (o de su imposibilidad).
     *
     * @param string      $spellId          Conjuro desterrado cuya gloria se revoca.
     * @param string|null $clanId           Linaje que pierde la gloria; null si no hay ninguno.
     * @param int         $revokedPoints    Gloria efectivamente deducida.
     * @param int         $weeklyDebited    Parte deducida del marcador semanal.
     * @param int         $historicalDebited Parte deducida del haber perpetuo.
     * @param int         $outstandingPoints Gloria que ningún contador pudo cubrir.
     * @param string|null $revokedAt        Marca ISO 8601 UTC de la deducción.
     * @param string|null $reason           Motivo canónico cuando nada se dedujo.
     *
     * @throws InvalidArgumentException Si la aritmética del recibo es incoherente.
     */
    public function __construct(
        public string $spellId,
        public ?string $clanId = null,
        public int $revokedPoints = 0,
        public int $weeklyDebited = 0,
        public int $historicalDebited = 0,
        public int $outstandingPoints = 0,
        public ?string $revokedAt = null,
        public ?string $reason = null,
    ) {
        if (trim($this->spellId) === '') {
            throw new InvalidArgumentException('Toda deducción de gloria ha de declarar el conjuro que la motiva.');
        }

        foreach ([
            'revokedPoints'      => $this->revokedPoints,
            'weeklyDebited'      => $this->weeklyDebited,
            'historicalDebited'  => $this->historicalDebited,
            'outstandingPoints'  => $this->outstandingPoints,
        ] as $attributeName => $amount) {
            if ($amount < 0) {
                throw new InvalidArgumentException("El importe {$attributeName} de una deducción nunca es negativo.");
            }
        }

        // La gloria deducida es EXACTAMENTE la suma de sus dos contadores: un
        // recibo que no cuadre haría ilegible la Bitácora.
        if ($this->weeklyDebited + $this->historicalDebited !== $this->revokedPoints) {
            throw new InvalidArgumentException(
                'La gloria deducida ha de ser exactamente la suma de lo descontado al marcador semanal y al haber perpetuo.'
            );
        }

        // Nada se deduce sin linaje a quien deducirlo, y un recibo con gloria
        // deducida no puede declarar motivo alguno.
        if ($this->reason !== null && ($this->revokedPoints !== 0 || $this->clanId !== null)) {
            throw new InvalidArgumentException('Ningún recibo denegado puede declarar linaje ni gloria deducida.');
        }

        if ($this->revokedPoints > 0 && $this->clanId === null) {
            throw new InvalidArgumentException('Ninguna gloria puede deducirse sin declarar la hermandad que la pierde.');
        }
    }

    /** Recibo de una deducción consumada. */
    public static function performed(
        string $spellId,
        string $clanId,
        int $weeklyDebited,
        int $historicalDebited,
        int $outstandingPoints,
        string $revokedAt,
    ): self {
        return new self(
            spellId: $spellId,
            clanId: $clanId,
            revokedPoints: $weeklyDebited + $historicalDebited,
            weeklyDebited: $weeklyDebited,
            historicalDebited: $historicalDebited,
            outstandingPoints: $outstandingPoints,
            revokedAt: $revokedAt,
        );
    }

    /** Recibo de una deducción imposible: no había gloria que revocar. */
    public static function denied(string $spellId, string $reason, string $revokedAt): self
    {
        return new self(
            spellId: $spellId,
            clanId: null,
            revokedPoints: 0,
            weeklyDebited: 0,
            historicalDebited: 0,
            outstandingPoints: 0,
            revokedAt: $revokedAt,
            reason: $reason,
        );
    }

    /** ¿Se dedujo gloria realmente o el gesto no halló mérito que revocar? */
    public function wasRevoked(): bool
    {
        return $this->reason === null && $this->revokedPoints > 0;
    }

    /**
     * Contador que sostuvo la mayor parte de la deducción, para la Bitácora.
     *
     * @return string|null Uno de los dos contadores canónicos, o null si nada se dedujo.
     */
    public function primaryCounter(): ?string
    {
        if (!$this->wasRevoked()) {
            return null;
        }

        return $this->historicalDebited > $this->weeklyDebited
            ? self::COUNTER_HISTORICAL
            : self::COUNTER_WEEKLY;
    }

    /** ¿Quedó gloria sin cubrir porque ningún contador alcanzaba? */
    public function hadOutstanding(): bool
    {
        return $this->outstandingPoints > 0;
    }

    /**
     * Serialización JSON nativa en su orden canónico.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'clanId'            => $this->clanId,
            'spellId'           => $this->spellId,
            'revokedPoints'     => $this->revokedPoints,
            'weeklyDebited'     => $this->weeklyDebited,
            'historicalDebited' => $this->historicalDebited,
            'outstandingPoints' => $this->outstandingPoints,
            'revokedAt'         => $this->revokedAt,
            'reason'            => $this->reason,
        ];
    }
}
