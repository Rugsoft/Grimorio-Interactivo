<?php

/**
 * AvatarInvalidFormatException.php — Formato fuera del canon de la
 * subida (SPEC-12, RF-03.2; plan §2.4).
 *
 * El aviso NOMBRA el motivo (asimetría razonada del plan: la imagen no
 * es secreto, la frase sí) y el avatar vigente no se muta.
 *
 * Constitución: Artículo IV (leyenda solemne en castellano), Artículo V
 * (código técnico en inglés).
 */

declare(strict_types=1);

namespace Grimorio\Exceptions;

final class AvatarInvalidFormatException extends \RuntimeException
{
    /** Código canónico del contrato (plan §2.4). */
    public const ERROR_CODE = 'INVALID_AVATAR_FORMAT';

    /** Leyenda noble que NOMBRA el motivo (RF-03.2). */
    private const NOBLE_LEGEND = 'Ese formato no pertenece al canon de efigies: solo png, jpg o webp.';

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
