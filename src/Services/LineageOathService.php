<?php

/**
 * LineageOathService.php — La lógica del Juramento de Linaje (SPEC-09,
 * Tarea 2.2).
 *
 * Cubre: RF-03.1 (vínculo perpetuo + asiento en la Bitácora), RF-03.3
 * (idempotencia y serialización con un solo ganador determinista), RF-03.4
 * (el vínculo jamás se reescribe ni se revoca), RF-01.6 (exención solemne
 * del Admin Supremo), caso límite 5 (canon inmutable: única validación) y
 * RNF-06 (cada sellado feliz deja asiento imborrable).
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): PDO preparado vía el repositorio; cero
 *     dependencias.
 *   - Art. II (Ley Universal del Maná): el juramento jamás toca magnitud
 *     alguna de maná: identidad, no hechicería.
 *   - Art. III (Ética de Linajes): el asiento de la Bitácora es imborrable
 *     (triggers del esquema) y porta actor, acto y estampa temporal.
 *   - Art. V (Dualidad): métodos en inglés camelCase; leyendas y asientos
 *     en noble castellano.
 *
 * LA REGLA DE ORO DEL CONCISO PLAN (§2.2): la única validación de canon
 * es la de este servicio contra su catálogo (la base solo es la última
 * muralla); el UPDATE guardado del repositorio es el punto de
 * serialización y `rowCount` el veredicto; el servicio re-evalúa tras una
 * carrera y resuelve por idempotencia o conflicto solemne. Nada de
 * `SELECT … FOR UPDATE`: la guardia es portable PDO.
 *
 * SOBRE LA RUTA RETENIDA: el veredicto la reporta como `null` y el
 * controlador (Tarea 2.6) la consume de la sesión al responder 200 — el
 * servicio es puro dominio y jamás toca `$_SESSION`.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use DateTimeImmutable;
use DateTimeZone;
use Grimorio\Dto\LineageOathResultDto;
use Grimorio\Exceptions\LineageOathException;
use Grimorio\Repositories\LineageOathRepository;

/**
 * El oficiante del juramento: valida, serializa y asienta. Sin pluma más
 * allá del vínculo perpetuo.
 */
final class LineageOathService
{
    /** El canon cerrado de los Ocho Linajes Canónicos (SPEC-07, RF-02.1). */
    private const CANONICAL_LINEAGES = [
        'primordialFlame', 'celestialTides', 'eternalTempest', 'worldRoots',
        'dawnWinds', 'solarCrown', 'abyssalShadows', 'aetherWeavers',
    ];

    /** Rol del actor exento por privilegio fundacional (RF-01.6). */
    private const ROLE_SUPREME_ADMIN = 'supremeAdmin';

    /** Repositorio del vínculo y su guardia atómica (Tarea 1.3). */
    private LineageOathRepository $repository;

    /** La Bitácora imborrable donde todo sellado feliz se asienta (RNF-06). */
    private AuditService $auditService;

    public function __construct(LineageOathRepository $repository, AuditService $auditService)
    {
        $this->repository = $repository;
        $this->auditService = $auditService;
    }

    /**
     * Sella el juramento del adepto sobre un linaje del canon.
     *
     * Flujo canónico del plan §2.2: exención de rol → validación de canon
     * → conflicto solemne → guardia atómica con re-evaluación de carrera →
     * asiento en la Bitácora → veredicto.
     *
     * @param string                 $userId     Cuenta que jura.
     * @param mixed                  $lineageId  El linaje invocado (debe llegar cadena del canon).
     * @param string                 $actorRole  Rol técnico del actor (exención del Supremo).
     * @param string                 $actorAlias Alias público del actor (para la Bitácora).
     * @param DateTimeImmutable|null $now        Instante del acto (tests y auditoría).
     *
     * @return LineageOathResultDto El veredicto con `sealedNow` y ruta nula (la retiene el controlador).
     *
     * @throws LineageOathException 400 `INVALID_LINEAGE`, 403 `OATH_FORBIDDEN_ROLE` o 403 `LINEAGE_OATH_CONFLICT`.
     */
    public function sealOath(
        string $userId,
        mixed $lineageId,
        string $actorRole,
        string $actorAlias,
        ?DateTimeImmutable $now = null,
    ): LineageOathResultDto {
        $instant = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // RF-01.6: el privilegio fundacional exime de jurar. Antes de
        // cualquier otra regla: un Supremo jamás porta linaje ni conflicto.
        if ($actorRole === self::ROLE_SUPREME_ADMIN) {
            throw LineageOathException::oathForbiddenRole();
        }

        // Caso límite 5 (canon inmutable): la ÚNICA validación de canon.
        // La base es solo la última muralla; nada de potestades previas.
        if (!is_string($lineageId) || !in_array($lineageId, self::CANONICAL_LINEAGES, true)) {
            throw LineageOathException::invalidLineage(is_string($lineageId) ? $lineageId : get_debug_type($lineageId));
        }

        // Lectura del estado actual: el desenlace de la re-evaluación y la
        // vía feliz comparten esta puerta.
        $heldLineage = $this->repository->findAccountLineage($userId);

        // RF-03.3/03.4: el vínculo ya forjado jamás se reescribe. La
        // idempotencia (mismo linaje) es éxito sin mutación; el linaje
        // distinto es rechazo solemne sin tocar una sola fila.
        if ($heldLineage !== null) {
            if ($heldLineage === $lineageId) {
                return new LineageOathResultDto($heldLineage, sealedNow: false, retainedRoute: null);
            }

            throw LineageOathException::oathConflict($heldLineage);
        }

        // La guardia atómica (Tarea 1.3): el punto de serialización real.
        // rowCount = 1 sella; rowCount = 0 delata una carrera y re-evalúa.
        if ($this->repository->sealOathGuarded($userId, $lineageId, $instant->format('Y-m-d\TH:i:s\Z')) === 0) {
            $heldLineage = $this->repository->findAccountLineage($userId);
            if ($heldLineage === $lineageId) {
                // Otro juramento ganó con el MISMO linaje: idempotencia.
                return new LineageOathResultDto($heldLineage, sealedNow: false, retainedRoute: null);
            }

            // Otro juramento ganó con linaje distinto: conflicto solemne.
            throw LineageOathException::oathConflict($heldLineage ?? '');
        }

        // RNF-06 / RF-03.1: el acto feliz queda asentado con actor, acto y
        // estampa temporal. La bitácora es imborrable (triggers del esquema).
        $this->auditService->recordAction(
            actorUserId: $userId,
            actorAlias: $actorAlias,
            actorRole: $actorRole,
            actionType: 'LINEAGE_OATH_SWORN',
            targetEntityType: 'user',
            targetEntityId: $userId,
            justification: 'Juramento del linaje «' . $lineageId . '» sellado en la ceremonia del primer acceso.',
            now: $instant,
        );

        return new LineageOathResultDto($lineageId, sealedNow: true, retainedRoute: null);
    }
}
