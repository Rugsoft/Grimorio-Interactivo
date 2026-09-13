<?php

/**
 * SpellNotFoundException.php — El pergamino no existe o es ajeno.
 *
 * Tarea 4.3 (TASKS-04): excepción de dominio que distingue, dentro del
 * ciclo de vida de conjuros, el caso «identificador inexistente o ajeno
 * al invocante» (HTTP 404) del caso «estado incompatible con la
 * operación» (HTTP 400, RuntimeException genérica).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro, sin jerarquías externas.
 *   - Artículo V: identificadores en inglés camelCase, leyendas y
 *     documentación en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Exceptions;

use RuntimeException;

/**
 * El conjuro solicitado no existe o no habita el grimorio del invocante.
 */
final class SpellNotFoundException extends RuntimeException
{
    /** Código HTTP canónico del contrato (404). */
    public const HTTP_STATUS_CODE = 404;

    /** Código canónico de error del contrato (SPELL_NOT_FOUND). */
    public const ERROR_CODE = 'SPELL_NOT_FOUND';

    public function getHttpStatusCode(): int
    {
        return self::HTTP_STATUS_CODE;
    }

    public function getErrorCode(): string
    {
        return self::ERROR_CODE;
    }

    /**
     * Sobre de error listo para Response::json() (contrato AGENTS.md 6.1).
     *
     * @return array{success: false, error: array{code: string, message: string, recoveryAction: string}}
     */
    public function toPayload(): array
    {
        return [
            'success' => false,
            'error'   => [
                'code'           => self::ERROR_CODE,
                'message'        => $this->getMessage(),
                'recoveryAction' => 'RETRY_WITH_VALID_ID',
            ],
        ];
    }
}
