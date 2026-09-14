<?php

/**
 * ClanEthicsValidator.php — Veto ético constitucional sobre los Maestros.
 *
 * Tarea 2.3 (TASKS-07): determina si un Maestro de la Torre puede deliberar
 * o firmar un conjuro, en estricto cumplimiento del Artículo III (RF-01.8):
 *
 *   1. No puede ser miembro ACTIVO del clan del conjuro: el vínculo de
 *      sangre nubla el juicio.
 *   2. No puede haber pertenecido a dicho clan en los últimos treinta (30)
 *      días naturales: la lealtad recién disuelta sigue contaminando.
 *
 * La autoridad es el HISTORIAL DE MEMBRESÍA (`clan_members`), como prescribe
 * el plan 3.5: `findActiveMembership()` resuelve la regla 1 y
 * `findPastMembershipsSince()` la regla 2. Las filas cerradas jamás se borran
 * (Tarea 1.3), de modo que el rastro que sostiene el veto es inmutable.
 *
 * Constitución:
 *   - Artículo I: solo PHP nativo y el repositorio, sin dependencias externas.
 *   - Artículo III: doble barrera — la interfaz advierte y este validador
 *     rechaza de forma estricta e irrevocable en el backend.
 *   - Artículo IV: los motivos del veto se declaran en noble castellano.
 *   - Artículo V: métodos en inglés camelCase, documentación en castellano.
 *
 * Determinismo (RNF-01): el instante se INYECTA (`$now`), de modo que el
 * veredicto es ciego, auditable y reproducible. La frontera de la ventana es
 * inclusiva: una partida de hace exactamente treinta días sigue vetada. Que
 * el llamante sea realmente un Maestro (`role = 'master'`) lo garantiza la
 * capa de moderación (SPEC-08); este validador juzga la incompatibilidad.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use DateTimeImmutable;
use DateTimeZone;
use Grimorio\Exceptions\ClanConflictOfInterestException;
use Grimorio\Repositories\ClanMemberRepository;
use InvalidArgumentException;

/**
 * Guardián del Artículo III: veta a los Maestros con conflicto de linaje.
 */
final class ClanEthicsValidator
{
    /** Ventana histórica de incompatibilidad, en días naturales (RF-01.8). */
    public const HISTORICAL_WINDOW_DAYS = 30;

    /** Canales de escritura y lectura del historial de membresía. */
    private ClanMemberRepository $memberRepository;

    /**
     * Consagra el validador sobre el historial de membresía del santuario.
     */
    public function __construct(ClanMemberRepository $memberRepository)
    {
        $this->memberRepository = $memberRepository;
    }

    /**
     * ¿Puede el Maestro deliberar o firmar un conjuro de este linaje?
     * (plan 3.5)
     *
     * @param string                $masterUserId Maestro de la Torre.
     * @param string|null           $spellClanId  Linaje del conjuro; `null` o cadena vacía para un mago ermitaño.
     * @param DateTimeImmutable|null $now         Instante de evaluación; por omisión, el reloj del sistema en UTC.
     *
     * @throws InvalidArgumentException Si el identificador del Maestro está vacío.
     */
    public function canMasterEvaluateSpell(
        string $masterUserId,
        ?string $spellClanId,
        ?DateTimeImmutable $now = null,
    ): bool {
        return $this->vetoReasonFor($masterUserId, $spellClanId, $now) === null;
    }

    /**
     * Exige que el Maestro pueda deliberar el conjuro, o alza el veto.
     *
     * Es la versión imperativa del veredicto: el criterio «Hecho cuando» de
     * esta tarea reclama que el rechazo se manifieste como excepción de
     * conflicto de interés, imposible de ignorar por descuido.
     *
     * @throws ClanConflictOfInterestException Si media incompatibilidad de linaje.
     * @throws InvalidArgumentException        Si el identificador del Maestro está vacío.
     */
    public function assertMasterCanEvaluateSpell(
        string $masterUserId,
        ?string $spellClanId,
        ?DateTimeImmutable $now = null,
    ): void {
        $vetoReason = $this->vetoReasonFor($masterUserId, $spellClanId, $now);
        if ($vetoReason !== null) {
            throw new ClanConflictOfInterestException(
                $masterUserId,
                (string) $spellClanId,
                $vetoReason,
            );
        }
    }

    /**
     * Motivo solemne del veto, o `null` si el Maestro es apto para juzgar.
     *
     * Expone el juicio como dato (no como excepción) para que las capas que
     * solo necesitan ADVERTIR —la deshabilitación del botón de firma en la
     * interfaz, o la inscripción en la Bitácora de Auditoría (RNF-04)— puedan
     * conocerlo sin capturar una excepción.
     *
     * @param string                 $masterUserId Maestro de la Torre.
     * @param string|null            $spellClanId  Linaje del conjuro; ajeno si es ermitaño.
     * @param DateTimeImmutable|null $now          Instante de evaluación.
     *
     * @throws InvalidArgumentException Si el identificador del Maestro está vacío.
     */
    public function vetoReasonFor(
        string $masterUserId,
        ?string $spellClanId,
        ?DateTimeImmutable $now = null,
    ): ?string {
        $masterUserId = trim($masterUserId);
        if ($masterUserId === '') {
            throw new InvalidArgumentException('El veto ético exige conocer la identidad del Maestro de la Torre.');
        }

        $spellClanId = $spellClanId === null ? '' : trim($spellClanId);
        if ($spellClanId === '') {
            // Conjuro de un mago ermitaño, sin estandarte al que pertenecer:
            // no existe linaje que pueda nublar su juicio (plan 3.5).
            return null;
        }

        $instant = $this->normalizeInstant($now);

        // Regla 1 (RF-01.8): el Maestro milita AHORA en el clan del conjuro.
        $activeMembership = $this->memberRepository->findActiveMembership($masterUserId);
        if ($activeMembership !== null && (string) ($activeMembership['clan_id'] ?? '') === $spellClanId) {
            return 'El vínculo de sangre nubla el juicio: un Maestro no puede deliberar ni firmar conjuros de su propio linaje actual.';
        }

        // Regla 2 (RF-01.8): el Maestro habitó el clan dentro de la ventana
        // de treinta días naturales que precede al instante de evaluación.
        $cutoffUtc = $instant
            ->modify('-' . self::HISTORICAL_WINDOW_DAYS . ' days')
            ->format('Y-m-d\TH:i:s\Z');

        foreach ($this->memberRepository->findPastMembershipsSince($masterUserId, $cutoffUtc) as $pastMembership) {
            if ((string) ($pastMembership['clan_id'] ?? '') === $spellClanId) {
                return 'El Maestro ha pertenecido a este linaje en los últimos treinta días naturales: deliberación y firma vetadas por incompatibilidad histórica (Artículo III).';
            }
        }

        // Ninguna regla se activa: el juicio del Maestro queda legitimado.
        return null;
    }

    /**
     * Normaliza el instante de evaluación a UTC. Si el llamante no lo inyecta,
     * se lee el reloj del sistema —único punto del servicio que lo hace—, en
     * coherencia con el resto del santuario.
     */
    private function normalizeInstant(?DateTimeImmutable $now): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        return $now === null
            ? new DateTimeImmutable('now', $utc)
            : $now->setTimezone($utc);
    }
}
