<?php

/**
 * RateLimitVerdict.php — Veredicto inmutable de la consulta de bloqueo.
 *
 * Tarea 2.2 (TASKS-03): valor de retorno de RateLimiter::isBlocked().
 * Encapsula la respuesta del plan 3.3: si la procedencia está congelada
 * y cuántos segundos le restan de castigo (0 si está libre).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DTO PHP puro, sin framework.
 *   - Artículo V: identificadores en inglés camelCase, documentación en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Core;

/**
 * Estado de congelación de una procedencia.
 */
final class RateLimitVerdict
{
    /**
     * @param bool $isBlocked         Verdadero si la IP sigue congelada.
     * @param int  $remainingSeconds  Segundos restantes de bloqueo (0 si está libre).
     */
    public function __construct(
        public readonly bool $isBlocked,
        public readonly int $remainingSeconds,
    ) {
    }
}
