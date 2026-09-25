<?php

/**
 * PassphraseIdenticalException.php — La primera salvedad honesta de la
 * custodia (SPEC-12, RF-04.1, caso límite 17; plan §2.6, Tarea 3.3 de
 * TASKS-12).
 *
 * Se lanza cuando la nueva frase coincide con la vigente: aviso noble
 * específico — «La nueva frase coincide con la vigente» — sin asiento
 * de bitácora y sin mutación. Salida PROPIA del contrato (plan §2.6),
 * excluida expresamente del fallo ciego: la coincidencia de la nueva
 * con la vigente es observable por el dueño sin revelar nada a un
 * tercero que no conozca la frase vigente (plan §3.3).
 *
 * Constitución: Artículo I (sin dependencias), Artículo IV (leyenda
 * solemne en castellano), Artículo V (código técnico en inglés).
 */

declare(strict_types=1);

namespace Grimorio\Exceptions;

final class PassphraseIdenticalException extends \RuntimeException
{
    /** Código canónico del contrato (plan §2.6). */
    public const ERROR_CODE = 'PASSPHRASE_IDENTICAL';

    /** Leyenda noble específica del acto inocuo (RF-04.1, plan §8). */
    private const NOBLE_LEGEND = 'La nueva frase coincide con la vigente: elige una distinta si deseas cambiarla.';

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
