<?php

/**
 * AvatarIdenticalException.php — El acto inocuo de la efigie
 * (SPEC-12, RF-03.6, caso límite 18; plan §2.4).
 *
 * Se lanza cuando el adepto re-envía la efigie ya vigente (re-elección
 * del catálogo o re-subida idéntica): el aviso noble específico — «la
 * imagen ya viste tu identidad» — sin asiento de bitácora y sin
 * mutación. Un acto sin efecto real no se inscribe.
 *
 * Constitución: Artículo IV (leyenda solemne en castellano), Artículo V
 * (código técnico en inglés, patrón de las excepciones del santuario).
 */

declare(strict_types=1);

namespace Grimorio\Exceptions;

final class AvatarIdenticalException extends \RuntimeException
{
    /** Código canónico del contrato (plan §2.4). */
    public const ERROR_CODE = 'AVATAR_IDENTICAL';

    /** Leyenda noble específica del acto inocuo (RF-03.6). */
    private const NOBLE_LEGEND = 'La imagen ya viste tu identidad: elige otra efigie si deseas cambiar.';

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
