<?php

/**
 * ConsecrationResult.php — Resultado de la consagración de un iniciado.
 *
 * Tarea 2.3 (TASKS-03): valor de retorno de AuthService::consecrate().
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DTO PHP puro.
 *   - Artículo V: identificadores en inglés camelCase, documentación en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Services;

/**
 * Identificador del iniciado recién consagrado y su estado de linaje.
 */
final class ConsecrationResult
{
    /**
     * @param string      $userId  Identificador textual del nuevo usuario.
     * @param string|null $lineage Linaje jurado (siempre null al nacer: SPEC-09, RF-01.2).
     */
    public function __construct(
        public readonly string $userId,
        public readonly ?string $lineage = null,
    ) {
    }
}
