<?php

/**
 * ClanConflictVerdict.php — Veredicto inmutable de la comprobación de conflicto.
 *
 * Tarea 2.4 (TASKS-03): valor de retorno de
 * ClanConflictService::canMasterSignSpell(). El motivo solemne viaja
 * siempre en castellano noble para alimentar tanto la advertencia de la
 * interfaz (RF-06.1) como el rechazo estricto del backend (RF-06.2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DTO PHP puro.
 *   - Artículo V: identificadores en inglés camelCase, motivo en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Services;

/**
 * Veredicto de incompatibilidad de linajes.
 */
final class ClanConflictVerdict
{
    /**
     * @param bool   $isAllowed Verdadero si la firma solemne queda aprobada.
     * @param string $reason    Motivo solemne del veto ('' si está aprobada).
     */
    public function __construct(
        public readonly bool $isAllowed,
        public readonly string $reason,
    ) {
    }
}
