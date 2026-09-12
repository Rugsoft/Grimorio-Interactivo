<?php

/**
 * BindResult.php — Resultado del intento de renovación de vínculo (login).
 *
 * Tarea 2.3 (TASKS-03): valor de retorno de AuthService::bind(). El
 * resultado es deliberadamente neutro en el fracaso: no revela si falló
 * la identidad o la frase de paso (RF-03.1, anti-enumeración).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DTO PHP puro.
 *   - Artículo V: identificadores en inglés camelCase, documentación en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use Grimorio\Core\ActiveSession;

/**
 * Veredicto del vínculo con su sesión activa, si prosperó.
 */
final class BindResult
{
    /**
     * @param bool               $success Verdadero si las credenciales fueron aceptadas.
     * @param string|null        $userId  Titular del vínculo (null si fracasó).
     * @param ActiveSession|null $session Sesión creada (null si fracasó).
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $userId,
        public readonly ?ActiveSession $session,
    ) {
    }
}
