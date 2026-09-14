<?php

/**
 * PatriarchSuccessionResult.php — Desenlace de la sucesión dinástica.
 *
 * Tarea 2.4 (TASKS-07): valor de retorno de
 * ClanService::evaluatePatriarchSuccession(), que ejecuta el algoritmo 3.4 del
 * plan técnico (RF-01.9, RF-05.3). El velatorio de los cuarenta y cinco días
 * admite tres desenlaces y ninguno más:
 *
 *   - `none`        · el Patriarca sigue activo: nada se altera.
 *   - `transferred` · la corona pasa al adepto activo de mayor antigüedad.
 *   - `archived`    · sin adeptos restantes, la casa se disuelve conservando
 *                     su memoria como Herencia Ancestral.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DTO PHP puro.
 *   - Artículo V: identificadores en inglés camelCase, documentación en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use InvalidArgumentException;

/**
 * Veredicto del velatorio de inactividad del Patriarca (RF-01.9).
 */
final class PatriarchSuccessionResult
{
    /** El trono no se mueve: el Patriarca registra actividad viva. */
    public const OUTCOME_NONE = 'none';

    /** La corona ciñe a un nuevo Patriarca. */
    public const OUTCOME_TRANSFERRED = 'transferred';

    /** La casa quedó disuelta por falta de adeptos. */
    public const OUTCOME_ARCHIVED = 'archived';

    /**
     * @param string      $outcome            'none' | 'transferred' | 'archived'.
     * @param int         $inactivityDays     Días naturales de silencio computados.
     * @param string|null $previousPatriarchId Quien ceñía la corona al abrirse el velatorio.
     * @param string|null $newPatriarchId      Quien la ciñe ahora (solo en `transferred`).
     */
    private function __construct(
        public readonly string $outcome,
        public readonly int $inactivityDays,
        public readonly ?string $previousPatriarchId = null,
        public readonly ?string $newPatriarchId = null,
    ) {
        if (!in_array($outcome, [self::OUTCOME_NONE, self::OUTCOME_TRANSFERRED, self::OUTCOME_ARCHIVED], true)) {
            throw new InvalidArgumentException("Desenlace de sucesión ajeno al canon: «{$outcome}».");
        }
    }

    /** El Patriarca sigue vivo en el santuario: el trono no se mueve. */
    public static function untouched(int $inactivityDays): self
    {
        return new self(self::OUTCOME_NONE, $inactivityDays);
    }

    /** La corona ciñe ya al adepto activo de mayor antigüedad. */
    public static function transferred(string $previousPatriarchId, string $newPatriarchId, int $inactivityDays): self
    {
        return new self(self::OUTCOME_TRANSFERRED, $inactivityDays, $previousPatriarchId, $newPatriarchId);
    }

    /** Sin adeptos que hereden, la casa se disuelve como Herencia Ancestral. */
    public static function archived(string $previousPatriarchId, int $inactivityDays): self
    {
        return new self(self::OUTCOME_ARCHIVED, $inactivityDays, $previousPatriarchId);
    }

    /** ¿Venció el velatorio de los cuarenta y cinco días? */
    public function isDue(): bool
    {
        return $this->outcome !== self::OUTCOME_NONE;
    }

    /** ¿Cambió la corona de manos? */
    public function wasTransferred(): bool
    {
        return $this->outcome === self::OUTCOME_TRANSFERRED;
    }

    /** ¿Quedó la casa disuelta por orfandad de adeptos? */
    public function wasArchived(): bool
    {
        return $this->outcome === self::OUTCOME_ARCHIVED;
    }

    /**
     * Contrato JSON del desenlace.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'outcome'             => $this->outcome,
            'inactivityDays'      => $this->inactivityDays,
            'previousPatriarchId' => $this->previousPatriarchId,
            'newPatriarchId'      => $this->newPatriarchId,
        ];
    }
}
