<?php

/**
 * SpellNotValidatedException.php — El homenaje exige obra sellada
 * (SPEC-11, Tarea 3.1; RF-04.5).
 *
 * El Elogio Popular jamás se presenta sobre un hechizo que no esté
 * `validado`: la gloria del Dominio solo nace de obra sellada por el
 * Tribunal (hallazgo 15 de la QA). Ante una llamada forzada sobre un
 * estado no validado, la puerta responde con este 409 solemne — el
 * ÚNICO 409 nuevo de la SPEC-11: duplicado y militancia no son
 * errores, son estados (plan §2.2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro, sin jerarquías externas.
 *   - Artículo IV (Velo Arcano): la leyenda es solemne, jamás técnica.
 *   - Artículo V (Dualidad): identificadores en inglés camelCase;
 *     leyenda en noble castellano.
 */

declare(strict_types=1);

namespace Grimorio\Exceptions;

use RuntimeException;

/**
 * El elogio no puede proceder: la obra aún no fue consagrada por el Tribunal.
 */
final class SpellNotValidatedException extends RuntimeException
{
    /** Código HTTP canónico del contrato (409 Conflict). */
    public const HTTP_STATUS_CODE = 409;

    /** Código canónico de error del contrato (plan §2.2). */
    public const ERROR_CODE = 'PRAISE_SPELL_NOT_VALIDATED';

    /** Leyenda canónica del vedado de homenaje (Anexo A del plan, leyenda 12). */
    public const PRAISE_FORBIDDEN_LEGEND = 'La gloria solo nace de obra sellada por el Tribunal.';

    public function __construct()
    {
        parent::__construct(self::PRAISE_FORBIDDEN_LEGEND);
    }

    /** Fábrica solemne para la guardia de estado del elogio (RF-04.5). */
    public static function praiseRequiresValidatedSpell(): self
    {
        return new self();
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
                'recoveryAction' => 'PRAISE_ONLY_VALIDATED',
            ],
        ];
    }
}
