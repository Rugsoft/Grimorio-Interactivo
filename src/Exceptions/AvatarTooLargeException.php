<?php

/**
 * AvatarTooLargeException.php — Peso sobre el tope de 2 MiB
 * (SPEC-12, RF-03.2; plan §2.4 y §5.2).
 *
 * El aviso NOMBRA el motivo (RF-03.2) y el avatar vigente no se muta.
 * Constitución: Artículo IV (leyenda solemne en castellano), Artículo V
 * (código técnico en inglés).
 */

declare(strict_types=1);

namespace Grimorio\Exceptions;

final class AvatarTooLargeException extends \RuntimeException
{
    /** Código canónico del contrato (plan §2.4). */
    public const ERROR_CODE = 'AVATAR_TOO_LARGE';

    /** Leyenda noble que NOMBRA el motivo (RF-03.2). */
    private const NOBLE_LEGEND = 'La imagen pesa más de lo que el santuario admite: el tope es de 2 MiB.';

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
