<?php

/**
 * ClanAdmissionResult.php — Veredicto del intento de ingreso en una casa.
 *
 * Tarea 2.4 (TASKS-07): valor de retorno de ClanService::applyToClan() y de
 * ClanService::resolveApplication(). El canon admite dos desenlaces legítimos
 * (plan 2.2, Endpoints 5 y 6) y ninguno más:
 *
 *   - `pending`  · régimen `byApplication`: la solicitud aguarda deliberación.
 *   - `active`   · régimen `open` o solicitud aprobada: la membresía ya está
 *                  inscrita en la autoridad y el adepto milita en la casa.
 *   - `rejected` · el Patriarca dictó rechazo: el postulante no ingresa y sus
 *                  restantes postulaciones permanecen intactas.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DTO PHP puro.
 *   - Artículo V: identificadores en inglés camelCase, claves JSON camelCase,
 *     documentación y leyendas en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use Grimorio\Dto\ClanApplicationDto;
use Grimorio\Dto\ClanMemberDto;
use InvalidArgumentException;

/**
 * Desenlace del ingreso: solicitud pendiente o membresía vigente.
 */
final class ClanAdmissionResult
{
    /** El postulante aguarda el veredicto del Patriarca. */
    public const MODE_PENDING = 'pending';

    /** El adepto ya milita en la hermandad. */
    public const MODE_ACTIVE = 'active';

    /** El Patriarca dictó rechazo: el postulante no ingresa. */
    public const MODE_REJECTED = 'rejected';

    /**
     * @param string                   $mode        'pending' | 'active'.
     * @param ClanApplicationDto|null  $application Solicitud registrada (solo en `pending`).
     * @param ClanMemberDto|null       $membership  Membresía inscrita (solo en `active`).
     */
    private function __construct(
        public readonly string $mode,
        public readonly ?ClanApplicationDto $application,
        public readonly ?ClanMemberDto $membership,
    ) {
        if (!in_array($mode, [self::MODE_PENDING, self::MODE_ACTIVE, self::MODE_REJECTED], true)) {
            throw new InvalidArgumentException("Desenlace de ingreso ajeno al canon: «{$mode}».");
        }
    }

    /** La postulación quedó inscrita y aguarda deliberación. */
    public static function pending(ClanApplicationDto $application): self
    {
        return new self(self::MODE_PENDING, $application, null);
    }

    /** El adepto ingresó de inmediato y su membresía es vigente. */
    public static function admitted(ClanMemberDto $membership): self
    {
        return new self(self::MODE_ACTIVE, null, $membership);
    }

    /** La deliberación fue adversa: la solicitud queda rechazada. */
    public static function rejected(ClanApplicationDto $application): self
    {
        return new self(self::MODE_REJECTED, $application, null);
    }

    /** ¿Aguarda el desenlace la pluma del Patriarca? */
    public function isPending(): bool
    {
        return $this->mode === self::MODE_PENDING;
    }

    /** ¿Militan ya el adepto y la casa bajo el mismo estandarte? */
    public function isAdmitted(): bool
    {
        return $this->mode === self::MODE_ACTIVE;
    }

    /** ¿Dictó el Patriarca rechazo sobre la postulación? */
    public function wasRejected(): bool
    {
        return $this->mode === self::MODE_REJECTED;
    }

    /**
     * Contrato JSON del desenlace (plan 2.2, Endpoints 5 y 6).
     *
     * @return array{mode: string, application: array<string, mixed>|null, membership: array<string, mixed>|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'mode'        => $this->mode,
            'application' => $this->application?->jsonSerialize(),
            'membership'  => $this->membership?->jsonSerialize(),
        ];
    }
}
