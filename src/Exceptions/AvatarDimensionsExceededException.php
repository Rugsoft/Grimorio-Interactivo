<?php

/**
 * AvatarDimensionsExceededException.php — Lados sobre el techo de
 * 1024 px (SPEC-12, RF-03.2; plan §2.4 y §5.2).
 *
 * El aviso NOMBRA el motivo (RF-03.2) y el avatar vigente no se muta.
 * Constitución: Artículo IV (leyenda solemne en castellano), Artículo V
 * (código técnico en inglés).
 */

declare(strict_types=1);

namespace Grimorio\Exceptions;

final class AvatarDimensionsExceededException extends \RuntimeException
{
    /** Código canónico del contrato (plan §2.4). */
    public const ERROR_CODE = 'AVATAR_DIMENSIONS_EXCEEDED';

    /** Leyenda noble que NOMBRA el motivo (RF-03.2). */
    private const NOBLE_LEGEND = 'La imagen excede las dimensiones del marco: sus lados no pueden superar los 1024 píxeles.';

    /** El sobre canónico listo para el controlador (plan §2.8). */
    public function toPayload(): array
    {
        return [
            'success' => false,
            'error'   => [
                'code'    => self::ERROR_CODE,
                'message' => self::NOBLE_LEGEND,
            ],
        ];
    }
}
