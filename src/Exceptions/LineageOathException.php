<?php

/**
 * LineageOathException.php — Veredicto adverso del Juramento de Linaje
 * (SPEC-09, Tarea 2.2).
 *
 * Cada causa porta el código canónico del contrato REST (plan §2.2,
 * Endpoint 2) y el estado HTTP que la Tarea 2.6 traducirá a su respuesta,
 * de modo que el controlador jamás inspeccione mensajes.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro, sin librerías.
 *   - Artículo IV: leyendas solemnes en castellano para cada rechazo.
 *   - Artículo V: identificadores en inglés camelCase.
 */

declare(strict_types=1);

namespace Grimorio\Exceptions;

use RuntimeException;

/**
 * Una ley del canon del juramento impide consumar la operación.
 */
final class LineageOathException extends RuntimeException
{
    // ── Códigos canónicos del contrato (plan §2.2, Endpoint 2) ────────────
    /** `lineageId` ausente, no cadena o ajeno al canon de 8 (RF-02.1, caso límite 5). */
    public const INVALID_LINEAGE = 'INVALID_LINEAGE';
    /** La cuenta ya porta un linaje DISTINTO: rechazo solemne sin mutación (RF-03.3, RF-03.4). */
    public const LINEAGE_OATH_CONFLICT = 'LINEAGE_OATH_CONFLICT';
    /** El actor está exento por privilegio y no puede jurar (Admin Supremo, RF-01.6). */
    public const OATH_FORBIDDEN_ROLE = 'OATH_FORBIDDEN_ROLE';

    /**
     * @param string $errorCode  Código canónico del contrato REST.
     * @param int    $httpStatus Estado HTTP que el controlador responderá.
     * @param string $message    Leyenda solemne en castellano.
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** El juramento invocado no figura en el canon inmutable de ocho. */
    public static function invalidLineage(string $lineageId): self
    {
        return new self(
            self::INVALID_LINEAGE,
            400,
            "El linaje «{$lineageId}» no figura entre los ocho linajes canónicos: la ceremonia solo conoce el canon.",
        );
    }

    /** La cuenta ya porta un linaje distinto: el vínculo es perpetuo (RF-03.3/03.4). */
    public static function oathConflict(string $heldLineage): self
    {
        return new self(
            self::LINEAGE_OATH_CONFLICT,
            403,
            'Tu palabra ya está dada: el juramento de linaje es perpetuo y el santuario no admite segunda consagración.',
        );
    }

    /** El Admin Supremo está exento por privilegio fundacional (RF-01.6). */
    public static function oathForbiddenRole(): self
    {
        return new self(
            self::OATH_FORBIDDEN_ROLE,
            403,
            'El Administrador Supremo navega exento por privilegio fundacional: su voz no requiere linaje jurado.',
        );
    }
}
