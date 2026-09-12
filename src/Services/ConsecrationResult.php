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
 * Identificador del iniciado recién consagrado.
 */
final class ConsecrationResult
{
    /**
     * @param string $userId Identificador textual del nuevo usuario.
     */
    public function __construct(
        public readonly string $userId,
    ) {
    }
}
