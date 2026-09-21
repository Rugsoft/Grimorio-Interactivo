<?php

/**
 * ClanVestibuleService.php — El sobre único del Vestíbulo (SPEC-10, Tarea 3.2).
 *
 * Una sola lectura (Endpoint 1 del plan §2.2, RNF-04) entrega al adepto el
 * estado íntegro de la Ceremonia de Adhesión: su estado de adepto (linaje
 * jurado, casa, aptitud conjuntiva derivada del instante —jamás un flag
 * persistente—, veredictos sin contemplar), su casa legada divergente cuando
 * procede (excepción de RF-01.2), el catálogo de casas de SU linaje jurado
 * (RF-01.2: solo `active`, lo vedado no se exhibe) y su inventario
 * consolidado de peticiones (RF-03.8). El endpoint JAMÁS admite parámetro de
 * filtro: el linaje se deriva de la cuenta servida.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo con consultas preparadas; sin
 *     librerías. La aptitud es derivación pura del instante: ni columnas ni
 *     cachés.
 *   - Artículo III: las leyendas vedadas citan al Artículo Constitucional
 *     que las sustenta.
 *   - Artículo V: métodos en inglés camelCase; leyendas y rótulos en noble
 *     castellano.
 *
 * Fronteras:
 *   - La retención del peregrino (SPEC-09) corre en el middleware: aquí
 *     solo se levanta el aviso solemne si un peregrino alcanzara la capa
 *     de servicio por una vía que eludiera la guardia (defensa en
 *     profundidad, RF-04.3).
 *   - El Supremo exento del juramento pero sin linaje recibe su veredicto
 *     propio `ADMIN_LINEAGE_REQUIRED` (hallazgos 13/19): no hay linaje
 *     contra el que casar el catálogo.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use DateTimeImmutable;
use DateTimeZone;
use Grimorio\Dto\ClanDto;
use Grimorio\Dto\ClanMemberDto;
use Grimorio\Dto\ClanPetitionDto;
use Grimorio\Dto\VestibuleClanDto;
use Grimorio\Dto\VestibuleStateDto;
use Grimorio\Exceptions\ClanGovernanceException;
use Grimorio\Exceptions\LineageOathException;
use Grimorio\Models\User;
use Grimorio\Repositories\ClanMemberRepository;
use Grimorio\Repositories\ClanRepository;
use Grimorio\Repositories\ClanApplicationRepository;
use Grimorio\Repositories\LineageOathRepository;
use Grimorio\Repositories\WeeklyCycleRepository;
use PDO;

final class ClanVestibuleService
{
    /** Conexión PDO del santuario. */
    private PDO $pdo;

    /** Autoridad de la afiliación de los magos. */
    private ClanMemberRepository $memberRepository;

    /** Hermandades del catálogo. */
    private ClanRepository $clanRepository;

    /** Peticiones formales del inventario y del rótulo. */
    private ClanApplicationRepository $applicationRepository;

    /** Cronología de coronaciones: la corona vigente del Clan Regente. */
    private WeeklyCycleRepository $cycleRepository;

    /** El juramento de linaje: el vínculo perpetuo de la cuenta (SPEC-09). */
    private LineageOathRepository $oathRepository;

    /**
     * @param PDO $pdo Conexión del front controller (o de un arnés aislado).
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->memberRepository = new ClanMemberRepository($pdo);
        $this->clanRepository = new ClanRepository($pdo);
        $this->applicationRepository = new ClanApplicationRepository($pdo);
        $this->cycleRepository = new WeeklyCycleRepository($pdo);
        $this->oathRepository = new LineageOathRepository($pdo);
    }

    /**
     * El sobre único del Vestíbulo (RF-01.2, RF-01.7, RF-03.5, RF-03.8,
     * RNF-04): UNA sola carga con aptitud, catálogo, casa legada y peticiones.
     *
     * @param User                   $viewer El adepto que contempla la ceremonia.
     * @param DateTimeImmutable|null $now    Instante del juicio; por defecto el
     *                                       reloj UTC del servidor (RNF-01).
     *
     * @throws LineageOathException     LINEAGE_OATH_REQUIRED si un peregrino
     *                                  alcanzara la capa por una vía que
     *                                  eludiera la retención (RF-04.3).
     * @throws ClanGovernanceException  ADMIN_LINEAGE_REQUIRED si el Supremo
     *                                  exento carece de linaje jurado.
     */
    public function vestibuleStateFor(User $viewer, ?DateTimeImmutable $now = null): VestibuleStateDto
    {
        $instant = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Defensa en profundidad (RF-04.3): el peregrino jamás contempla la
        // ceremonia. El middleware lo retiene antes; esta guardia sella la
        // capa de servicio ante vías que eludieran la cadena HTTP.
        if ($viewer->getRole() !== 'supremeAdmin' && $this->oathRepository->findAccountLineage($viewer->getId()) === null) {
            // La leyenda es la MISMA del middleware de SPEC-09: una sola voz
            // para la retención, sea HTTP o de servicio.
            throw LineageOathException::lineageOathRequired(
                'El santuario aguarda tu juramento: nadie pisa sus salas sin linaje jurado.'
            );
        }

        // Hallazgos 13/19: el Supremo exento del juramento pero sin linaje
        // no tiene casas que contemplar — no hay linaje contra el que casar
        // el catálogo. Su aviso es solemne, no una lista vacía silenciosa.
        if ($viewer->getLineage() === null) {
            throw ClanGovernanceException::adminLineageRequired();
        }

        $lineageType = (string) $viewer->getLineage();
        $lineageLabel = $this->labelOfLineage($lineageType);

        // ---- Aptitud conjuntiva, derivada del instante (RF-01.7) --------
        // El orden del plan §3.1: lealtad primero, convalecencia después;
        // la aptitud es la conjunción del resto.
        $membership = $this->memberRepository->findActiveMembership($viewer->getId());
        $inConvalescence = $this->memberRepository->isUserInConvalescence(
            $viewer->getId(),
            $this->formatInstant($instant)
        );
        $pendingPetitionsCount = $this->applicationRepository->countPendingApplications($viewer->getId());

        $vedado = null;
        $convalescenceDaysRemaining = 0;

        if ($membership !== null) {
            // La lealtad indivisible veda TODO gesto de adhesión (RF-02.3).
            $vedado = 'loyalty';
        } elseif ($inConvalescence) {
            // El descanso de 14 días veda el ingreso y la fundación; los
            // días restantes se alzan al entero superior (plan §3.1),
            // réplica exacta de la aritmética de ClanMemberDto.
            $vedado = 'convalescence';
            $convalescenceDaysRemaining = $this->ceilDaysUntilConvalescenceEnd(
                $viewer->getId(),
                $instant
            );
        }

        $isApt = $membership === null && !$inConvalescence;
        $unreadVerdictsCount = $this->applicationRepository->countUnreadVerdicts($viewer->getId());

        // ---- Casa legada divergente (excepción de RF-01.2) --------------
        // Una PROYECCIÓN de la membresía vigente en `clan_members`, jamás
        // datos nuevos (plan §5, decisión 7). Solo viaja si la casa es de
        // OTRO linaje: la casa propia del linaje jurado ya vive en el catálogo.
        $myHouse = null;
        if ($membership !== null) {
            $membershipClanRow = $this->clanRepository->findById((string) $membership['clan_id']);
            if ($membershipClanRow !== null && (string) $membershipClanRow['lineage_type'] !== $lineageType) {
                $myHouse = [
                    'clanId'            => (string) $membershipClanRow['id'],
                    'clanName'          => (string) $membershipClanRow['name'],
                    'isLegacyDivergent' => true,
                    'state'             => (string) $membershipClanRow['status'],
                ];
            }
        }

        // ---- Catálogo del linaje jurado (RF-01.2) ------------------------
        // Solo las casas ACTIVAS del propio linaje; lo vedado no se exhibe.
        // Sin parámetro de filtro: la sesión es la única fuente de verdad.
        $clans = [];
        $pendingByClan = $this->pendingApplicationsByClan($viewer->getId());
        foreach ($this->clanRepository->searchClans($lineageType, ClanDto::STATUS_ACTIVE, PHP_INT_MAX, 0) as $clanRow) {
            $clan = ClanDto::fromDatabaseRow($clanRow);
            $clanId = (string) $clanRow['id'];

            $clans[] = $this->toVestibuleClanDto(
                $clan,
                $viewer,
                $membership,
                $pendingByClan[$clanId] ?? null,
                $isApt,
                $vedado,
                $convalescenceDaysRemaining,
                $instant,
                $this->applicationRepository->hasSealedHouse($viewer->getId(), $clanId)
            );
        }

        // ---- Inventario consolidado (RF-03.8) ----------------------------
        $petitions = [];
        foreach ($this->applicationRepository->findApplicationsByUser($viewer->getId()) as $row) {
            $petitions[] = $this->toPetitionDto($row);
        }

        return new VestibuleStateDto(
            lineage: $lineageType,
            lineageLabel: $lineageLabel,
            membershipClanId: $membership === null ? null : (string) $membership['clan_id'],
            membershipClanName: $membership === null ? null : $this->nameOfClan((string) $membership['clan_id']),
            isApt: $isApt,
            vedado: $vedado,
            convalescenceDaysRemaining: $convalescenceDaysRemaining,
            pendingPetitionsCount: $pendingPetitionsCount,
            pendingPetitionsLimit: VestibuleStateDto::PENDING_PETITIONS_LIMIT,
            unreadVerdictsCount: $unreadVerdictsCount,
            myHouse: $myHouse,
            clans: $clans,
            petitions: $petitions,
        );
    }

    /**
     * Traduce una casa del catálogo al retrato del Vestíbulo: identidad de
     * `ClanDto` más el estado del adepto ante ella, derivado del instante
     * (RF-01.7) y del régimen de admisión (RF-01.3).
     */
    private function toVestibuleClanDto(
        ClanDto $clan,
        User $viewer,
        ?array $membership,
        ?array $pendingApplication,
        bool $isApt,
        ?string $vedado,
        int $convalescenceDaysRemaining,
        DateTimeImmutable $instant,
        bool $houseSealed = false
    ): VestibuleClanDto {
        // La corona del Clan Regente (RF-01.3): la proclamación vigente.
        $currentRegent = $this->cycleRepository->findCurrentRegentCycle();
        $isRegent = $currentRegent !== null && (string) $currentRegent['regent_clan_id'] === $clan->id;

        // ---- Relación y gesto, derivados del instante (RF-01.7) ---------
        if ($membership !== null && (string) $membership['clan_id'] === $clan->id) {
            // La casa propia se declara «Tu hermandad»: sin gesto, porque no
            // cabe gesto alguno sobre la casa que ya habita (RF-03.5).
            return new VestibuleClanDto(
                clanId: $clan->id,
                name: $clan->name,
                motto: $clan->motto,
                coatOfArms: $clan->coatOfArms,
                lineageType: $clan->lineageType,
                memberCount: $clan->memberCount,
                memberLimit: ClanDto::MEMBER_LIMIT,
                admissionMode: $clan->admissionMode,
                isRegent: $isRegent,
                adeptRelation: VestibuleClanDto::RELATION_OWN_HOUSE,
                gesture: null,
                vedadoLegend: 'Ya habitas esta hermandad: tu lealtad vive en sus salas (Art. III.1).',
            );
        }

        if ($pendingApplication !== null) {
            // «Pendiente de dictamen»: el adepto ya remitió su palabra y el
            // Patriarca aún no ha deliberado; su único gesto es la retirada.
            return new VestibuleClanDto(
                clanId: $clan->id,
                name: $clan->name,
                motto: $clan->motto,
                coatOfArms: $clan->coatOfArms,
                lineageType: $clan->lineageType,
                memberCount: $clan->memberCount,
                memberLimit: ClanDto::MEMBER_LIMIT,
                admissionMode: $clan->admissionMode,
                isRegent: $isRegent,
                adeptRelation: VestibuleClanDto::RELATION_PENDING,
                gesture: VestibuleClanDto::GESTURE_WITHDRAW,
                vedadoLegend: null,
                petitionId: (string) $pendingApplication['id'],
            );
        }

        // Sin casa propia ni petición pendiente: el gesto depende de la
        // aptitud global y del régimen de la casa (RF-01.7, RF-03.5).
        $gesture = null;
        $vedadoLegend = null;

        if ($houseSealed) {
            // Clausura perpetua por casa (RF-03.1): cualquier fila histórica
            // —aprobada, rechazada o cancelada— veda la re-postulación.
            $vedadoLegend = 'Ya pronunciaste tu palabra ante esta casa: quedó clausurada para ti. Otras puertas aguardan (Art. III.2).';
        } elseif ($vedado === 'loyalty') {
            $vedadoLegend = 'Tu lealtad ya está empeñada: solo renunciar a ella —y sobrevivir la convalecencia— abre de nuevo estas puertas (Art. III.1).';
        } elseif ($vedado === 'convalescence') {
            $vedadoLegend = "Descansa en Convalecencia Arcana: tus puertas se abren en {$convalescenceDaysRemaining} "
                . ($convalescenceDaysRemaining === 1 ? 'día' : 'días') . ' (RF-01.6).';
        } elseif (!$isApt) {
            $vedadoLegend = 'El santuario no discierne hoy tu gesto: contempla, y vuelve cuando tu juramento esté completo.';
        } elseif (!$clan->hasVacancy()) {
            $vedadoLegend = sprintf(
                'La hermandad ha alcanzado su plenitud de %d adeptos activos: ningún ingreso cabe sin una partida.',
                ClanDto::MEMBER_LIMIT
            );
        } else {
            $gesture = $clan->isOpenAdmission()
                ? VestibuleClanDto::GESTURE_JOIN
                : VestibuleClanDto::GESTURE_PETITION;
        }

        return new VestibuleClanDto(
            clanId: $clan->id,
            name: $clan->name,
            motto: $clan->motto,
            coatOfArms: $clan->coatOfArms,
            lineageType: $clan->lineageType,
            memberCount: $clan->memberCount,
            memberLimit: ClanDto::MEMBER_LIMIT,
            admissionMode: $clan->admissionMode,
            isRegent: $isRegent,
            adeptRelation: VestibuleClanDto::RELATION_NONE,
            gesture: $gesture,
            vedadoLegend: $vedadoLegend,
        );
    }

    /**
     * Contador ligero de veredictos sin contemplar (RF-01.1, Endpoint 5 del
     * plan §2.2): alimenta el distintivo «Tienes dictámenes a la espera»
     * sin servir el sobre entero (RNF-04).
     *
     * @throws LineageOathException LINEAGE_OATH_REQUIRED si un peregrino
     *                              alcanzara la capa por una vía que
     *                              eludiera la retención (RF-04.3).
     */
    public function unreadVerdictsCountFor(User $viewer): int
    {
        // Misma guardia en profundidad que el sobre completo: el peregrino
        // jamás consulta el rótulo de una ceremonia que no puede pisar.
        if ($viewer->getRole() !== 'supremeAdmin' && $this->oathRepository->findAccountLineage($viewer->getId()) === null) {
            throw LineageOathException::lineageOathRequired(
                'El santuario aguarda tu juramento: nadie pisa sus salas sin linaje jurado.'
            );
        }

        return $this->applicationRepository->countUnreadVerdicts($viewer->getId());
    }

    /**
     * Traduce una fila de `clan_applications` al retrato del inventario
     * consolidado (RF-03.8), con el nombre canónico de la casa y el estado
     * derivado `verdictSeen` (RF-03.4).
     */
    private function toPetitionDto(array $row): ClanPetitionDto
    {
        $row['clan_name'] = $this->nameOfClan((string) $row['clan_id']);

        return ClanPetitionDto::fromDatabaseRow($row);
    }

    /**
     * Índice de las peticiones PENDIENTES del adepto, agrupadas por casa:
     * alimenta el gesto de retirada y el estado «Pendiente de dictamen».
     *
     * @return array<string, array{id: string, clan_id: string}>
     */
    private function pendingApplicationsByClan(string $userId): array
    {
        $index = [];
        foreach ($this->applicationRepository->findPendingApplicationsByUser($userId) as $row) {
            $index[(string) $row['clan_id']] = $row;
        }

        return $index;
    }

    /**
     * Días de convalecencia restantes, con alza al entero superior
     * (plan §3.1): réplica EXACTA de la aritmética de
     * `ClanMemberDto::convalescenceDaysRemaining`, para que el backend y el
     * banner jamás disientan sobre «restan X».
     */
    private function ceilDaysUntilConvalescenceEnd(string $userId, DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare(
            'SELECT convalescence_expires_at
               FROM clan_members
              WHERE user_id = :userId
                AND convalescence_expires_at IS NOT NULL
              ORDER BY convalescence_expires_at DESC
              LIMIT 1'
        );
        $statement->execute([':userId' => $userId]);
        $expiryRaw = $statement->fetchColumn();

        if (!is_string($expiryRaw) || $expiryRaw === '') {
            return 0;
        }

        $expiry = new DateTimeImmutable($expiryRaw, new DateTimeZone('UTC'));
        if ($expiry <= $now) {
            return 0;
        }

        return (int) ceil(($expiry->getTimestamp() - $now->getTimestamp()) / 86400);
    }

    /**
     * Nombre canónico de una casa, o su identificador si la casa ya no existe.
     */
    private function nameOfClan(string $clanId): string
    {
        $clanRow = $this->clanRepository->findById($clanId);

        return $clanRow === null ? $clanId : (string) $clanRow['name'];
    }

    /**
     * Rótulo castellano del linaje jurado, desde el canon inmutable de los
     * ocho (SPEC-09): el mismo catálogo que selló el juramento nombra ahora
     * al adepto en su ceremonia.
     */
    private function labelOfLineage(string $lineageType): ?string
    {
        $lineage = (new LineageSynergyService())->findLineage($lineageType);

        return $lineage === null ? null : $lineage->name;
    }

    /**
     * Estampa ISO 8601 UTC, idéntica en formato a la que siembran las semillas.
     */
    private function formatInstant(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d\TH:i:s\Z');
    }
}
