<?php

/**
 * SpellNotInTomeException.php — El hechizo no habita el tomo del adepto
 * (SPEC-11, Tarea 2.3; RF-02.4).
 *
 * La retirada del tomo exige que la fila exista y sea PROPIA: el
 * repositorio ya veda la intimidad con su `WHERE` doble (Tarea 1.2);
 * esta excepción traduce el «no lo encontré» del retiro a un 409
 * solemne del contrato REST — conflicto de estado, no recurso
 * inexistente (el hechizo puede existir en el catálogo; lo que no
 * existe es su entrada en el tomo del adepto).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro, sin jerarquías externas.
 *   - Artículo IV (Velo Arcano): la leyenda es solemne y no técnica.
 *   - Artículo V (Dualidad): identificadores en inglés camelCase;
 *     leyenda en noble castellano.
 */

declare(strict_types=1);

namespace Grimorio\Exceptions;

use RuntimeException;

/**
 * La retirada no puede proceder: el tomo del adepto no conoce esa obra.
 */
final class SpellNotInTomeException extends RuntimeException
{
    /** Código HTTP canónico del contrato (409 Conflict). */
    public const HTTP_STATUS_CODE = 409;

    /** Código canónico de error del contrato (plan §2.2). */
    public const ERROR_CODE = 'SPELL_NOT_IN_TOME';

    public function __construct(string $spellId)
    {
        parent::__construct(
            "El conjuro «{$spellId}» no figura en tu tomo: solo se retira lo que se selló."
        );
    }

    /** Fábrica solemne para la guardia de pertenencia de la retirada (SPEC-11, Tarea 2.3). */
    public static function forSpell(string $spellId): self
    {
        return new self($spellId);
    }

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
                'recoveryAction' => 'RETURN_TO_TOME',
            ],
        ];
    }
}
