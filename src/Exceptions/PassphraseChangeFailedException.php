<?php

/**
 * PassphraseChangeFailedException.php — El fallo ciego de la custodia
 * (SPEC-12, RF-04.1, caso límite 3; plan §2.6, Tarea 3.2 de TASKS-12).
 *
 * UN SOLO veredicto para las tres causas posibles: frase actual errónea,
 * nuevas que difieren entre sí y solidez insuficiente. La leyenda jamás
 * revela cuál de las tres falló (aviso sin pistas): el ciego protege
 * ante terceros que ignoran la frase vigente, y el dueño legítimo nunca
 * necesita la pista porque conoce su propia frase.
 *
 * La asimetría razonada del plan (§2.4) es deliberada: la imagen del
 * avatar no es secreto y su rechazo NOMBRA el motivo
 * (INVALID_AVATAR_FORMAT...); la frase de paso sí lo es y su rechazo es
 * ciego. Excluye expresamente el caso «nueva idéntica a la vigente»,
 * que tiene salida propia (PASSPHRASE_IDENTICAL, Tarea 3.3).
 *
 * Constitución: Artículo I (sin dependencias), Artículo IV (leyenda
 * solemne en castellano), Artículo V (código técnico en inglés).
 */

declare(strict_types=1);

namespace Grimorio\Exceptions;

final class PassphraseChangeFailedException extends \RuntimeException
{
    /** Código canónico del contrato (plan §2.6). */
    public const ERROR_CODE = 'PASSPHRASE_CHANGE_FAILED';

    /** Leyenda solemne única: idéntica para las tres causas (RF-04.1). */
    private const NOBLE_LEGEND = 'El santuario no ha podido consumar el cambio de frase de paso: revisa tus datos y vuelve a intentarlo.';

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
