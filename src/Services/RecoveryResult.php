<?php

/**
 * RecoveryResult.php — Resultado de la solicitud del Pergamino de Restablecimiento.
 *
 * Tarea 2.3 (TASKS-03): valor de retorno de AuthService::requestRecovery().
 * Para correos no registrados no se emite token (anti-enumeración, RF-04.1):
 * la respuesta pública es neutra en ambos casos.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DTO PHP puro.
 *   - Artículo V: identificadores en inglés camelCase, documentación en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Services;

/**
 * Veredicto de la solicitud de recuperación con el token, si procede.
 */
final class RecoveryResult
{
    /**
     * @param bool        $tokenIssued   Verdadero si el pergamino fue emitido.
     * @param string|null $recoveryToken Token crudo (solo vive en este instante; en BD viaja como SHA-256).
     */
    public function __construct(
        public readonly bool $tokenIssued,
        public readonly ?string $recoveryToken,
    ) {
    }
}
