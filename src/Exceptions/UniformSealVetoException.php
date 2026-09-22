<?php

/**
 * UniformSealVetoException.php — La leyenda UNIFORME del vedado de sellado
 * (SPEC-11, Tarea 2.2; RF-01.2, hallazgo 4 de la QA).
 *
 * Ante CUALQUIER estado no validado —`draft`, `experimental`, `rejected`,
 * `archived`— el rito del sellado responde con UNA sola leyenda: sin
 * nombrar el estado concreto ni filtrar por rol. El adepto no aprende
 * nada del ciclo de vida interno del catálogo; solo sabe que el tomo
 * exige obra consagrada.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro, sin jerarquías externas.
 *   - Artículo IV (Velo Arcano): la leyenda es solemne y no técnica.
 *   - Artículo V (Dualidad): identificadores en inglés camelCase;
 *     leyenda canónica del Anexo A del plan en noble castellano.
 */

declare(strict_types=1);

namespace Grimorio\Exceptions;

use RuntimeException;

/**
 * El tomo exige obra sellada por el Tribunal: respuesta uniforme e
 * invariable ante cualquier estado no validado.
 */
final class UniformSealVetoException extends RuntimeException
{
    /** Código HTTP canónico del contrato (403 Forbidden). */
    public const HTTP_STATUS_CODE = 403;

    /** Código canónico de error del contrato (ROBUSTO ante enumeración de estados). */
    public const ERROR_CODE = 'TOME_SEAL_VETO';

    /**
     * La leyenda UNIFORME — texto LITERAL del Anexo A del plan
     * (leyenda 5). Jamás se parametriza: es su uniformidad lo que
     * impide sondear el estado real del hechizo.
     */
    private const UNIFORM_LEGEND = 'Solo lo que el Tribunal ha sellado entra al tomo.';

    public function __construct()
    {
        parent::__construct(self::UNIFORM_LEGEND);
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
                'recoveryAction' => 'SEEK_VALIDATED_SPELLS',
            ],
        ];
    }
}
