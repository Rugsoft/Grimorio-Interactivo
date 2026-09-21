<?php

/**
 * ClanService.php — Gobierno canónico de las hermandades del santuario.
 *
 * Tarea 2.4 (TASKS-07): fundación, gobernanza heráldica, cupo estricto de
 * treinta adeptos, admisión por régimen (`open` o `byApplication`), renuncias
 * y expulsiones con Convalecencia Arcana de catorce días, traspaso de la
 * corona y sucesión dinástica por inactividad del Patriarca.
 *
 * Cubre: RF-01.1 a RF-01.7, RF-01.9, RF-05.3, RF-05.4, RNF-01 y RNF-04.
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): PDO nativo, cero librerías; la autoridad y el
 *     espejo de la afiliación se mueven dentro de una sola transacción.
 *   - Art. III (Ética): ningún rechazo se relaja —el cupo, la convalecencia y
 *     la lealtad indivisible muerden en el backend— y toda partida conserva
 *     la memoria del adepto en lugar de borrarla.
 *   - Art. IV: las leyendas de rechazo viajan en noble castellano.
 *   - Art. V: identificadores en inglés camelCase; documentación en castellano.
 *
 * Disciplina temporal (RNF-01): este servicio JAMÁS lee el reloj del sistema.
 * El instante llega por parámetro (o se toma una única vez en UTC al abrir la
 * operación) y viaja idéntico a repositorios, DTOs y bitácora, de modo que
 * toda verificación sea reproducible aserto a aserto.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Grimorio\Dto\ClanApplicationDto;
use Grimorio\Dto\ClanDto;
use Grimorio\Dto\ClanMemberDto;
use Grimorio\Exceptions\ClanGovernanceException;
use Grimorio\Models\User;
use Grimorio\Repositories\ClanApplicationRepository;
use Grimorio\Repositories\ClanMemberRepository;
use Grimorio\Repositories\ClanRepository;
use PDO;
use Throwable;

/**
 * Orquestador del gobierno de hermandades: reglas de negocio y transacciones.
 */
final class ClanService
{
    /** Días naturales de meditación tras partir de una casa (RF-01.6). */
    public const CONVALESCENCE_DAYS = 14;

    /** Días de silencio que abren el velatorio dinástico (RF-01.9). */
    public const PATRIARCH_INACTIVITY_DAYS = 45;

    /** Tamaño de página por defecto del catálogo de hermandades (Endpoint 2). */
    public const DEFAULT_CATALOG_PER_PAGE = 20;

    /** Techo de página del catálogo: nadie abarca el santuario entero de una vez. */
    public const MAX_CATALOG_PER_PAGE = 50;

    /** Identidad del santuario cuando el acto lo dicta el canon, no una pluma. */
    private const SYSTEM_ACTOR_ID = 'sys_santuario';
    private const SYSTEM_ACTOR_ALIAS = 'El Santuario';
    private const SYSTEM_ACTOR_ROLE = 'system';

    /** Rango mínimo para fundar una casa o afiliarse (RF-01.1, RF-01.2). */
    private const ELIGIBLE_RANKS = ['editor', 'master', 'supremeAdmin'];

    /** Molde de la motivación escrita de la petición formal (SPEC-10, RF-03.1). */
    private const MOTIVATION_MIN_LENGTH = 20;
    private const MOTIVATION_MAX_LENGTH = 500;

    /**
     * Tolerancia (segundos) de la estampa de llegada declarada por el cliente
     * (plan §3.3, decisión 9): el criterio es la LLEGADA al santuario, no el
     * reloj del cliente; fuera de la ventana, manda el instante del servidor.
     */
    private const ARRIVAL_TOLERANCE_SECONDS = 30;

    /** Conexión PDO del santuario (Singleton del front controller). */
    private PDO $pdo;

    /** Autoridad y espejo de la afiliación de los magos. */
    private ClanMemberRepository $memberRepository;

    /** Hermandades: identidad heráldica, gobierno y contadores de Dominio. */
    private ClanRepository $clanRepository;

    /** Postulaciones formales de ingreso. */
    private ClanApplicationRepository $applicationRepository;

    /** Bitácora pública de auditoría; ausente en arneses aislados (RNF-04). */
    private ?AuditService $auditService;

    /** Catálogo de los ocho linajes canónicos (RF-02.1). */
    private LineageSynergyService $lineageService;

    /**
     * @param PDO|null $auditService Bitácora pública; si falta, los actos se
     *                               consuman sin inscribirse (arneses puros).
     */
    public function __construct(
        PDO $pdo,
        ?AuditService $auditService = null,
        ?LineageSynergyService $lineageService = null,
    ) {
        $this->pdo = $pdo;
        $this->memberRepository = new ClanMemberRepository($pdo);
        $this->clanRepository = new ClanRepository($pdo);
        $this->applicationRepository = new ClanApplicationRepository($pdo);
        $this->auditService = $auditService;
        $this->lineageService = $lineageService ?? new LineageSynergyService();
    }

    /**
     * Funda una hermandad y ciñe la corona a su fundador (RF-01.2, Endpoint 1).
     *
     * El fundador debe ostentar rango `editor` o superior, no militar ya en
     * otra casa, no hallarse en convalecencia y reclamar un Nombre Canónico
     * de 4 a 50 caracteres aún no inscrito —tampoco por una casa disuelta,
     * cuyo nombre queda reservado a perpetuidad (RF-05.4)—.
     *
     * La fundación y el ingreso del Patriarca constituyen una sola operación
     * atómica: jamás se observa una casa sin quien la gobierne.
     *
     * @throws ClanGovernanceException Si el canon se opone a la fundación.
     */
    public function foundClan(
        User $founder,
        string $name,
        string $motto,
        string $coatOfArms,
        string $lineageType,
        string $admissionMode = ClanDto::ADMISSION_OPEN,
        ?DateTimeImmutable $now = null,
    ): ClanDto {
        $instant = $this->instant($now);
        $nowUtc = $this->formatInstant($instant);

        $this->requireOathLineageForGesture($founder);
        $this->requireEligibleRank($founder);
        $canonicalName = $this->requireCanonicalName($name);
        $this->requireCanonicalLineage($lineageType);

        // El fundador solo puede alzar un estandarte de su propia sangre
        // arcana (SPEC-10, RF-04.1): SPEC-09 RF-04.2 ata fundación y
        // postulación al mismo filtro rector. Va tras el canon de linajes
        // (orden del plan §3.2) y antes de reservar el nombre.
        if ($founder->getLineage() !== $lineageType) {
            throw ClanGovernanceException::clanLineageMismatch();
        }

        if (!$this->clanRepository->isNameAvailable($canonicalName)) {
            throw ClanGovernanceException::nameAlreadyReserved($canonicalName);
        }

        $this->requireFreedomFromConvalescence($founder, $nowUtc);
        if ($this->memberRepository->findActiveMembership($founder->getId()) !== null) {
            throw ClanGovernanceException::alreadyAffiliated();
        }

        $clanId = $this->newIdentifier('cln');

        $founded = $this->runAtomically(function () use (
            $clanId,
            $canonicalName,
            $motto,
            $coatOfArms,
            $lineageType,
            $admissionMode,
            $founder,
            $nowUtc
        ): ?array {
            $inscribed = $this->clanRepository->createClan(
                $clanId,
                $this->canonicalSlug($canonicalName),
                $canonicalName,
                $motto,
                $coatOfArms,
                $lineageType,
                $admissionMode,
                $founder->getId(),
                $nowUtc,
            );

            if ($inscribed === null) {
                // Identidad reclamada en el mismo instante por otra pluma.
                throw ClanGovernanceException::nameAlreadyReserved($canonicalName);
            }

            return $this->memberRepository->addMember(
                $this->newIdentifier('clm'),
                $clanId,
                $founder->getId(),
                ClanMemberDto::ROLE_PATRIARCH,
                $nowUtc,
            );
        });

        if ($founded === null) {
            // La casa nación pero el fundador ya militaba: la transacción entera
            // se deshace y el canon dicta lealtad indivisible (RF-01.1).
            throw ClanGovernanceException::alreadyAffiliated();
        }

        $clan = $this->requireClan($clanId);

        $this->recordAudit(
            $founder->getId(),
            $founder->getAlias(),
            $founder->getRole(),
            'CLAN_FOUNDED',
            $clanId,
            "«{$canonicalName}» alza su estandarte bajo el linaje {$lineageType}.",
            $instant,
        );

        return $clan;
    }

    /**
     * Recupera la ficha heráldica de una hermandad (Endpoint 3).
     */
    public function findClanById(string $clanId): ?ClanDto
    {
        $row = $this->clanRepository->findById($clanId);

        return $row === null ? null : $this->toClanDto($row);
    }

    /**
     * Actualiza lema, blasón y régimen de admisión (RF-01.3, Endpoint 4).
     *
     * Cada parámetro nulo conserva el valor vigente, de modo que el Patriarca
     * pueda mudar solo aquello que desea.
     *
     * @throws ClanGovernanceException Si el actuante no ciñe la corona.
     */
    public function updateHeraldry(
        User $patriarch,
        string $clanId,
        ?string $motto = null,
        ?string $coatOfArms = null,
        ?string $admissionMode = null,
        ?DateTimeImmutable $now = null,
    ): ClanDto {
        $instant = $this->instant($now);
        $nowUtc = $this->formatInstant($instant);

        $clan = $this->requireClan($clanId);
        $this->requirePatriarch($patriarch, $clan, $clanId);

        if ($motto !== null || $coatOfArms !== null) {
            $this->clanRepository->updateMottoAndHeraldry(
                $clanId,
                $motto ?? $clan->motto,
                $coatOfArms ?? $clan->coatOfArms,
                $nowUtc,
            );
        }

        if ($admissionMode !== null) {
            $this->clanRepository->updateAdmissionMode($clanId, $admissionMode, $nowUtc);
        }

        // Acto de gobierno: el Patriarca vive y su velatorio se pospone (RF-01.9).
        $this->clanRepository->touchActivity($clanId, $nowUtc);

        $this->recordAudit(
            $patriarch->getId(),
            $patriarch->getAlias(),
            $patriarch->getRole(),
            'CLAN_MODIFY',
            $clanId,
            "El Patriarca muda la heráldica o el régimen de «{$clan->name}».",
            $instant,
        );

        return $this->requireClan($clanId);
    }

    /**
     * Postula el ingreso o lo consuma de inmediato (RF-01.5, Endpoint 5).
     *
     * En régimen `open` el mago ingresa al instante mientras haya vacantes; en
     * `byApplication` remite una solicitud formal. En ambos casos el cupo de
     * treinta adeptos y la convalecencia son barreras infranqueables.
     *
     * @param string|null $motivation Motivación escrita de la petición formal
     *                                (molde 20–500, RF-03.1 de SPEC-10); solo
     *                                se exige en régimen `byApplication`.
     * @param string|null $receivedAt Estampa de llegada del gesto declarada
     *                                por el cliente (opcional, ±30 s, plan
     *                                §3.3); el servidor fija la suya si falta
     *                                o desconfía.
     *
     * @throws ClanGovernanceException Si el canon se opone al ingreso.
     */
    public function applyToClan(
        User $applicant,
        string $clanId,
        ?DateTimeImmutable $now = null,
        ?string $motivation = null,
        ?string $receivedAt = null,
    ): ClanAdmissionResult {
        $instant = $this->instant($now);
        $nowUtc = $this->formatInstant($instant);

        $this->requireOathLineageForGesture($applicant);
        $this->requireEligibleRank($applicant);
        $clan = $this->requireClan($clanId);
        $this->requireActiveClan($clan, $clanId);
        $this->requireFreedomFromConvalescence($applicant, $nowUtc);

        if ($this->memberRepository->findActiveMembership($applicant->getId()) !== null) {
            // SPEC-10 (RF-02.3, caso límite 6): el militante que repite el
            // gesto sobre SU casa recibe éxito sin mutación alguna (doble
            // clic, reintento de red, segunda pestaña) — la idempotencia
            // devuelve la membresía vigente como desenlace admitted.
            $ownHouseId = (string) ($this->memberRepository
                ->findActiveMembership($applicant->getId())['clan_id'] ?? '');
            if ($ownHouseId === $clan->id) {
                $ownMembership = $this->memberRepository->findActiveMembership($applicant->getId());
                return ClanAdmissionResult::admitted($this->toMemberDto($ownMembership ?? []));
            }

            // SPEC-10 (RF-02.3, hallazgo 4): en la vía de adhesión el gesto
            // del militante hacia OTRA casa porta su propia leyenda con el
            // nombre de la suya. ALREADY_AFFILIATED permanece canónico SOLO
            // en la vía de fundación (enmienda declarada, plan §5.3).
            throw ClanGovernanceException::clanLoyaltyBound(
                $this->nameOfActiveMembership($applicant->getId())
            );
        }

        // La guardia del juramento en el SERVIDOR (SPEC-10, RF-01.2, RF-04.1):
        // el gesto hacia un estandarte de otro linaje jamás alcanza la
        // persistencia, venga de la interfaz o de una llamada directa a la
        // API. La lectura del catálogo jamás la dispara.
        if ($clan->lineageType !== $applicant->getLineage()) {
            throw ClanGovernanceException::clanLineageMismatch();
        }

        $this->assertVacancy($clanId);

        if ($clan->admissionMode === ClanDto::ADMISSION_BY_APPLICATION) {
            // El molde de la petición formal (SPEC-10, RF-03.1): en el rito de
            // ingreso la pluma no redacta, así que aquí no se exige.
            $canonicalMotivation = $this->requireCanonicalMotivation($motivation);
            $arrival = $this->resolveArrivalInstant($receivedAt, $instant);

            return $this->registerApplication($applicant, $clan, $arrival, $canonicalMotivation);
        }

        return $this->admitImmediately($applicant, $clan, $instant);
    }

    /**
     * Dirime una solicitud de ingreso pendiente (Endpoint 6).
     *
     * Aprobar incorpora al adepto y cancela sus restantes postulaciones como
     * un solo gesto confirmable; rechazar no toca ninguna otra solicitud.
     *
     * @param string $decision 'approve' o 'reject'.
     *
     * @throws ClanGovernanceException Si el actuante o la solicitud no son aptos.
     */
    public function resolveApplication(
        User $patriarch,
        string $clanId,
        string $applicationId,
        string $decision,
        ?DateTimeImmutable $now = null,
        ?string $motive = null,
    ): ClanAdmissionResult {
        $instant = $this->instant($now);
        $nowUtc = $this->formatInstant($instant);

        $clan = $this->requireClan($clanId);
        $this->requirePatriarch($patriarch, $clan, $clanId);

        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw ClanGovernanceException::invalidDecision($decision);
        }

        $application = $this->applicationRepository->findById($applicationId);
        if ($application === null || (string) $application['clan_id'] !== $clanId) {
            throw ClanGovernanceException::applicationNotFound($applicationId);
        }

        if ((string) $application['status'] !== ClanApplicationDto::STATUS_PENDING) {
            throw ClanGovernanceException::applicationAlreadyResolved();
        }

        $applicantId = (string) $application['user_id'];

        if ($decision === 'reject') {
            // El rechazo EXIGE su motivo solemne (SPEC-10, Tarea 2.6; Artículo
            // III.3, hallazgo 23): el mismo molde 20–500 de la petición formal,
            // pero con código PROPIO — el dictamen no es una petición. La
            // aprobación no lo exige: el ingreso ES su motivo.
            $canonicalMotive = $this->requireCanonicalVerdictMotive($motive);

            $this->applicationRepository->resolveApplication(
                $applicationId,
                ClanApplicationDto::STATUS_REJECTED,
                $nowUtc,
                $canonicalMotive,
            );

            // La deliberación es vida del Patriarca (RF-01.9).
            $this->clanRepository->touchActivity($clanId, $nowUtc);

            // Asiento del dictamen: LADO DELIBERANTE (SPEC-10, RF-04.4, plan
            // §2.3) — identidad del Patriarca, estampa y motivo (Art. III.3).
            // El lado del postulante jamás lo duplica.
            $this->recordAudit(
                $patriarch->getId(),
                $patriarch->getAlias(),
                $patriarch->getRole(),
                'CLAN_APPLICATION_VERDICT',
                $clanId,
                "Rechaza la petición de «{$this->aliasFor($applicantId)}» con motivo: {$canonicalMotive}",
                $instant,
            );

            $rejected = $this->applicationRepository->findById($applicationId);

            return ClanAdmissionResult::rejected(
                $this->toApplicationDto($rejected ?? $application)
            );
        }

        $this->assertVacancy($clanId);

        if ($this->memberRepository->findActiveMembership($applicantId) !== null) {
            throw ClanGovernanceException::alreadyAffiliated();
        }

        $membership = $this->runAtomically(function () use (
            $applicantId,
            $clanId,
            $applicationId,
            $nowUtc,
            $patriarch,
            $clan,
            $instant,
        ): ?array {
            $inscribed = $this->memberRepository->addMember(
                $this->newIdentifier('clm'),
                $clanId,
                $applicantId,
                ClanMemberDto::ROLE_ADEPT,
                $nowUtc,
            );

            if ($inscribed === null) {
                return null;
            }

            // La aprobación cancela las demás postulaciones del adepto: ya
            // milita en una casa y no debe seguir cortejando a otras.
            $this->applicationRepository->resolveApplication(
                $applicationId,
                ClanApplicationDto::STATUS_APPROVED,
                $nowUtc,
            );

            // Las residuales se anulan DE OFICIO (RF-03.7): la lealtad
            // indivisible absuelve las peticiones huérfanas. Cada anulación
            // deja SU asiento, inscrito por el postulante (RF-04.4).
            $residualIds = $this->applicationRepository->cancelPendingApplications(
                $applicantId,
                $applicationId,
                $nowUtc,
            );
            foreach ($residualIds as $residualId) {
                $this->recordAudit(
                    $patriarch->getId(),
                    $patriarch->getAlias(),
                    $patriarch->getRole(),
                    'CLAN_APPLICATION_RESIDUALS_ANNULLED',
                    $clanId,
                    "La lealtad indivisible absuelve la petición huérfana de «{$this->aliasFor($applicantId)}» al entrar en «{$clan->name}».",
                    $instant,
                );
            }

            $this->clanRepository->touchActivity($clanId, $nowUtc);

            return $inscribed;
        });

        if ($membership === null) {
            throw ClanGovernanceException::alreadyAffiliated();
        }

        // Asiento de la APROBACIÓN: LADO DELIBERANTE (SPEC-10, RF-04.4). El
        // ingreso ES su motivo: no exige texto (Tarea 2.6, plan §2.2 Ep. 6).
        $this->recordAudit(
            $patriarch->getId(),
            $patriarch->getAlias(),
            $patriarch->getRole(),
            'CLAN_APPLICATION_VERDICT',
            $clanId,
            "Aprueba la petición de «{$this->aliasFor($applicantId)}»: el ingreso a «{$clan->name}» es su propio motivo.",
            $instant,
        );

        // Asiento del INGRESO POR APROBACIÓN: lado del postulante (SPEC-10,
        // RF-04.4) — la membresía nace, sea cual fuere el rito que la trajo.
        // El postulante no está en sesión: se lee su identidad del plano.
        $postulantRow = $this->pdo->prepare('SELECT alias, role FROM users WHERE id = :userId');
        $postulantRow->execute([':userId' => $applicantId]);
        $postulantData = $postulantRow->fetch(PDO::FETCH_ASSOC) ?: ['alias' => $this->aliasFor($applicantId), 'role' => 'editor'];
        $this->recordAudit(
            $applicantId,
            (string) $postulantData['alias'],
            (string) $postulantData['role'],
            'CLAN_MEMBER_JOINED',
            $clanId,
            "«{$postulantData['alias']}» cruza las puertas de «{$clan->name}» por dictamen favorable.",
            $instant,
        );

        return ClanAdmissionResult::admitted($this->toMemberDto($membership));
    }

    /**
     * Retira una petición pendiente de la propia cuenta (SPEC-10, RF-03.3).
     *
     * El arrepentimiento antes del dictamen libera el cupo de pendientes, PERO
     * la casa queda CLAUSURADA para la cuenta: la fila persiste con estado
     * `cancelled` y el índice único `uq_clan_application_house` (Tarea 1.1)
     * vela que jamás se vuelva a postular ante la misma casa — el veredicto
     * del hallazgo 16: cada casa, una sola vez, con el estado que sea.
     *
     * La operación es transaccional y serializable: la carrera con el
     * dictamen del Patriarca deja un solo desenlace (caso límite 5).
     *
     * @throws ClanGovernanceException Si la petición no existe, es ajena o
     *                                  ya fue resuelta.
     */
    public function withdrawApplication(
        User $postulant,
        string $clanId,
        string $applicationId,
        ?DateTimeImmutable $now = null,
    ): ClanAdmissionResult {
        $instant = $this->instant($now);
        $nowUtc = $this->formatInstant($instant);

        $clan = $this->requireClan($clanId);

        $application = $this->applicationRepository->findById($applicationId);
        if (
            $application === null
            || (string) $application['clan_id'] !== $clanId
            || (string) $application['user_id'] !== $postulant->getId()
        ) {
            throw ClanGovernanceException::applicationNotFound($applicationId);
        }

        if ((string) $application['status'] !== ClanApplicationDto::STATUS_PENDING) {
            throw ClanGovernanceException::applicationAlreadyResolved();
        }

        $withdrawn = $this->runAtomically(function () use ($applicationId, $nowUtc): bool {
            // La condición `status = pending` dentro del UPDATE serializa la
            // carrera con el dictamen: quien llega segundo no muta nada.
            return $this->applicationRepository->withdrawApplication(
                $applicationId,
                $nowUtc
            );
        });

        if (!$withdrawn) {
            // El dictamen ganó la carrera: la petición ya no está pendiente.
            throw ClanGovernanceException::applicationAlreadyResolved();
        }

        // La retirada es acto del postulante (RF-04.4, reparto por actor).
        $this->recordAudit(
            $postulant->getId(),
            $postulant->getAlias(),
            $postulant->getRole(),
            'CLAN_APPLICATION_WITHDRAWN',
            $clanId,
            "Retira su petición formal ante «{$clan->name}»: la casa queda clausurada para su cuenta.",
            $instant,
        );

        $updated = $this->applicationRepository->findById($applicationId);

        return ClanAdmissionResult::rejected(
            $this->toApplicationDto($updated ?? $application)
        );
    }

    /**
     * Contempla el veredicto de una petición TERMINAL propia (SPEC-10, RF-03.4,
     * Endpoint 4): fija `verdict_seen_at` y apaga el rótulo «Tienes dictámenes
     * a la espera» (RF-01.1).
     *
     * Idempotente por diseño: un reenvío responde éxito SIN mutar la columna —
     * el primer contemplado es el único instante que la historia registra.
     * Sobre peticiones `pending` no actúa: nada hay que leer en una espera.
     *
     * @return ClanApplicationDto La petición con su veredicto ya contemplado.
     *
     * @throws ClanGovernanceException Si la petición no existe, es ajena o
     *                                  aún pende de dictamen.
     */
    public function acknowledgeVerdict(
        User $postulant,
        string $applicationId,
        ?DateTimeImmutable $now = null,
    ): ClanApplicationDto {
        $instant = $this->instant($now);
        $nowUtc = $this->formatInstant($instant);

        $application = $this->applicationRepository->findById($applicationId);
        if (
            $application === null
            || (string) $application['user_id'] !== $postulant->getId()
        ) {
            throw ClanGovernanceException::applicationNotFound($applicationId);
        }

        if ((string) $application['status'] === ClanApplicationDto::STATUS_PENDING) {
            throw ClanGovernanceException::applicationAlreadyPending();
        }

        // La triple guardia vive en el propio UPDATE (terminal + propia + sin
        // leer): si la fila ya estaba contemplada, rowCount es 0 y el reenvío
        // se responde con éxito sin mutación (idempotencia del Endpoint 4).
        $this->applicationRepository->markVerdictSeen($applicationId, $postulant->getId(), $nowUtc);

        $updated = $this->applicationRepository->findById($applicationId);

        return $this->toApplicationDto($updated ?? $application);
    }

    /**
     * Renuncia voluntaria de un adepto (RF-01.6, Endpoint 7).
     *
     * El Patriarca no puede marcharse sin ceder antes la corona; si es el
     * único miembro, la casa se disuelve como Herencia Ancestral (RF-05.3).
     * Cualquier otra partida abre los catorce días de convalecencia.
     *
     * @throws ClanGovernanceException Si la partida rompe el canon.
     */
    public function leaveClan(
        User $member,
        string $clanId,
        ?DateTimeImmutable $now = null,
    ): ClanMemberDto {
        $instant = $this->instant($now);
        $nowUtc = $this->formatInstant($instant);

        $clan = $this->requireClan($clanId);
        $membership = $this->memberRepository->findActiveMembership($member->getId());
        if ($membership === null || (string) $membership['clan_id'] !== $clanId) {
            throw ClanGovernanceException::notAMember($member->getId());
        }

        $membershipId = (string) $membership['id'];
        $isPatriarch = $clan->patriarchId === $member->getId();
        $othersRemain = $this->memberRepository->countActiveMembers($clanId) > 1;

        if ($isPatriarch && $othersRemain) {
            throw ClanGovernanceException::patriarchMustTransferCrown();
        }

        // Sin adeptos que hereden, la corona sobra: la casa se disuelve y su
        // estandarte pasa a Herencia Ancestral (RF-05.3). La disolución no es
        // una pena, de modo que NO abre convalecencia.
        $dissolving = $isPatriarch && !$othersRemain;

        $closed = $this->runAtomically(function () use ($member, $clanId, $membershipId, $nowUtc, $dissolving): ?array {
            $this->memberRepository->removeMember(
                $member->getId(),
                $clanId,
                $nowUtc,
                $dissolving ? null : $this->convalescenceExpiry($nowUtc),
            );

            if ($dissolving) {
                $this->clanRepository->setStatusArchived($clanId, $nowUtc);
            }

            return $this->memberRepository->findMembershipById($membershipId);
        });

        if ($closed === null) {
            throw ClanGovernanceException::notAMember($member->getId());
        }

        $this->recordAudit(
            $member->getId(),
            $member->getAlias(),
            $member->getRole(),
            $dissolving ? 'CLAN_ARCHIVED_BY_PATRIARCH' : 'CLAN_MEMBER_LEFT',
            $clanId,
            $dissolving
                ? "«{$clan->name}» se disuelve: su último morador partió y su memoria queda como Herencia Ancestral."
                : "El adepto abandona «{$clan->name}» y medita catorce días de Convalecencia Arcana.",
            $instant,
        );

        return $this->toMemberDto($closed);
    }

    /**
     * Expulsión de un adepto por el Patriarca (RF-01.6, Endpoint 8).
     *
     * La expulsión abre la misma convalecencia de catorce días que la
     * renuncia, porque RF-01.6 las equipara. El Patriarca no puede expulsarse
     * a sí mismo: para partir debe transferir la corona.
     *
     * @throws ClanGovernanceException Si el acto rompe el canon.
     */
    public function expelMember(
        User $patriarch,
        string $clanId,
        string $userId,
        ?DateTimeImmutable $now = null,
    ): ClanMemberDto {
        $instant = $this->instant($now);
        $nowUtc = $this->formatInstant($instant);

        $clan = $this->requireClan($clanId);
        $this->requirePatriarch($patriarch, $clan, $clanId);

        if ($userId === $patriarch->getId()) {
            throw ClanGovernanceException::cannotExpelSelf();
        }

        $membership = $this->memberRepository->findActiveMembership($userId);
        if ($membership === null || (string) $membership['clan_id'] !== $clanId) {
            throw ClanGovernanceException::notAMember($userId);
        }

        $membershipId = (string) $membership['id'];

        $closed = $this->runAtomically(function () use ($userId, $clanId, $membershipId, $nowUtc): ?array {
            $this->memberRepository->removeMember(
                $userId,
                $clanId,
                $nowUtc,
                $this->convalescenceExpiry($nowUtc),
            );

            return $this->memberRepository->findMembershipById($membershipId);
        });

        if ($closed === null) {
            throw ClanGovernanceException::notAMember($userId);
        }

        $this->clanRepository->touchActivity($clanId, $nowUtc);

        $this->recordAudit(
            $patriarch->getId(),
            $patriarch->getAlias(),
            $patriarch->getRole(),
            'CLAN_MEMBER_EXPELLED',
            $clanId,
            "El Patriarca expulsa a «{$userId}» de «{$clan->name}»: catorce días de Convalecencia Arcana.",
            $instant,
        );

        return $this->toMemberDto($closed);
    }

    /**
     * Traspaso voluntario de la corona a otro adepto (RF-01.3, Endpoint 9).
     *
     * El acto se consuma entero: el Patriarca saliente desciende a `adept`, el
     * sucesor ciñe `patriarch` y el clan declara su nueva cabeza. Jamás se
     * observa una casa con dos coronas o con ninguna.
     *
     * @throws ClanGovernanceException Si el sucesor no es adepto activo.
     */
    public function transferLeadership(
        User $patriarch,
        string $clanId,
        string $newPatriarchId,
        ?DateTimeImmutable $now = null,
    ): ClanMemberDto {
        $instant = $this->instant($now);
        $nowUtc = $this->formatInstant($instant);

        $clan = $this->requireClan($clanId);
        $this->requirePatriarch($patriarch, $clan, $clanId);

        $successor = $this->memberRepository->findActiveMembership($newPatriarchId);
        if ($successor === null || (string) $successor['clan_id'] !== $clanId) {
            throw ClanGovernanceException::ineligibleSuccessor($newPatriarchId);
        }

        $this->runAtomically(function () use ($patriarch, $clanId, $newPatriarchId, $nowUtc): void {
            $this->memberRepository->setRole(
                $patriarch->getId(),
                $clanId,
                ClanMemberDto::ROLE_ADEPT,
            );
            $this->memberRepository->setRole(
                $newPatriarchId,
                $clanId,
                ClanMemberDto::ROLE_PATRIARCH,
            );
            // Ciñe la corona y refresca la actividad viva de la casa (RF-01.9).
            $this->clanRepository->updatePatriarch($clanId, $newPatriarchId, $nowUtc);
        });

        $this->recordAudit(
            $patriarch->getId(),
            $patriarch->getAlias(),
            $patriarch->getRole(),
            'PATRIARCH_TRANSFERRED',
            $clanId,
            "La corona de «{$clan->name}» pasa de «{$patriarch->getAlias()}» a «{$newPatriarchId}».",
            $instant,
        );

        $crowned = $this->memberRepository->findActiveMembership($newPatriarchId);

        if ($crowned === null) {
            throw ClanGovernanceException::ineligibleSuccessor($newPatriarchId);
        }

        return $this->toMemberDto($crowned);
    }

    /**
     * Velatorio dinástico: sucesión tras 45 días de silencio (RF-01.9).
     *
     * Ejecuta el algoritmo 3.4 del plan. La antigüedad de ingreso ordena a los
     * candidatos y el volumen acumulado de PDA aportados dirime los empates;
     * un último desempate por identificador garantiza el determinismo (RNF-01).
     * Sin más adeptos activos, la casa se disuelve.
     */
    public function evaluatePatriarchSuccession(
        string $clanId,
        ?DateTimeImmutable $now = null,
    ): PatriarchSuccessionResult {
        $instant = $this->instant($now);
        $nowUtc = $this->formatInstant($instant);

        $clan = $this->clanRepository->findById($clanId);
        if ($clan === null || (string) $clan['status'] !== ClanDto::STATUS_ACTIVE) {
            return PatriarchSuccessionResult::untouched(0);
        }

        $patriarchId = isset($clan['patriarch_id']) && is_scalar($clan['patriarch_id'])
            ? (string) $clan['patriarch_id']
            : '';
        if ($patriarchId === '') {
            // Casa acéfala por disolución previa: nada que heredar.
            return PatriarchSuccessionResult::untouched(0);
        }

        $inactivityDays = $this->inactivityDays($clan, $instant);
        if ($inactivityDays < self::PATRIARCH_INACTIVITY_DAYS) {
            return PatriarchSuccessionResult::untouched($inactivityDays);
        }

        $candidates = $this->memberRepository->findActiveMembersExcluding($clanId, $patriarchId);
        $successorId = $this->selectSuccessor($candidates, $clanId);

        if ($successorId === null) {
            // Orfandad de adeptos: la casa se archiva y su último morador queda
            // libre. La disolución no es pena, así que no abre convalecencia.
            $this->runAtomically(function () use ($clanId, $patriarchId, $nowUtc): void {
                $this->clanRepository->setStatusArchived($clanId, $nowUtc);
                $this->memberRepository->removeMember($patriarchId, $clanId, $nowUtc, null);
            });

            $this->recordAudit(
                self::SYSTEM_ACTOR_ID,
                self::SYSTEM_ACTOR_ALIAS,
                self::SYSTEM_ACTOR_ROLE,
                'CLAN_ARCHIVED_EMPTY_SUCCESSION',
                $clanId,
                'El Patriarca calló cuarenta y cinco días y ningún adepto queda para heredar: la casa pasa a Herencia Ancestral.',
                $instant,
            );

            return PatriarchSuccessionResult::archived($patriarchId, $inactivityDays);
        }

        $this->runAtomically(function () use ($patriarchId, $clanId, $successorId, $nowUtc): void {
            $this->memberRepository->setRole($patriarchId, $clanId, ClanMemberDto::ROLE_ADEPT);
            $this->memberRepository->setRole($successorId, $clanId, ClanMemberDto::ROLE_PATRIARCH);
            $this->clanRepository->updatePatriarch($clanId, $successorId, $nowUtc);
        });

        $this->recordAudit(
            self::SYSTEM_ACTOR_ID,
            self::SYSTEM_ACTOR_ALIAS,
            self::SYSTEM_ACTOR_ROLE,
            'PATRIARCH_INACTIVITY_SUCCESSION',
            $clanId,
            "Cuarenta y cinco días de silencio: la corona recae en «{$successorId}» por antigüedad y mérito.",
            $instant,
        );

        return PatriarchSuccessionResult::transferred($patriarchId, $successorId, $inactivityDays);
    }

    // ── Lectura pública del catálogo y de la ficha (Tarea 3.2) ──────────

    /**
     * Catálogo filtrado y paginado de hermandades (plan 2.2, Endpoint 2).
     *
     * Ni el linaje ni el estado llegan aquí sin canon: el controlador valida
     * el linaje contra los ocho canónicos y el estado contra `active` y
     * `archived` antes de invocar; esta firma se limita a componer la página
     * con su censo de adeptos y sus metadatos.
     *
     * La página y el tamaño solicitados se acotan SIEMPRE (RNF-02: un cliente
     * no puede pedir el santuario entero de una vez), de modo que el catálogo
     * responde en tiempo constante.
     *
     * @param string|null $lineageType Linaje rector exigido, o null para todos.
     * @param string|null $status      Estado exigido, o null para todos.
     * @param int         $page        Página deseada (>= 1).
     * @param int         $perPage     Tamaño de página solicitado (1 a 50).
     */
    public function browseClans(
        ?string $lineageType = null,
        ?string $status = null,
        int $page = 1,
        int $perPage = self::DEFAULT_CATALOG_PER_PAGE,
    ): ClanCatalogPage {
        $safePage = max(1, $page);
        $safePerPage = min(max(1, $perPage), self::MAX_CATALOG_PER_PAGE);
        $offset = ($safePage - 1) * $safePerPage;

        $rows = $this->clanRepository->searchClans($lineageType, $status, $safePerPage, $offset);
        $totalItems = $this->clanRepository->countClans($lineageType, $status);

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->toClanDto($row);
        }

        return new ClanCatalogPage(
            items: $items,
            pagination: [
                'page'       => $safePage,
                'limit'      => $safePerPage,
                'totalItems' => $totalItems,
                'totalPages' => max(1, (int) ceil($totalItems / $safePerPage)),
            ],
        );
    }

    /**
     * ¿Pertenece el linaje dado al canon de los ocho Linajes Mágicos? (RF-02.1)
     *
     * Proyección de la autoridad del catálogo (LineageSynergyService, Tarea
     * 2.2): el controlador la usa para responder 400 ante un filtro ajeno al
     * canon en lugar de devolver un catálogo vacío que oculte la errata.
     */
    public function hasCanonicalLineage(string $lineageType): bool
    {
        return $this->lineageService->hasLineage(trim($lineageType));
    }

    /**
     * El censo de adeptos ACTIVOS de una hermandad, con su alias público y su
     * rol nobiliario, para la ficha detallada (plan 2.2, Endpoint 3).
     *
     * El Patriarca encabeza siempre la lista; el resto por antigüedad de
     * ingreso, como manda el orden del canon (RF-01.3).
     *
     * @return list<ClanMemberDto>
     *
     * @throws ClanGovernanceException Si la hermandad no existe.
     */
    public function listClanMembers(string $clanId): array
    {
        $this->requireClan($clanId);

        $members = [];
        foreach ($this->memberRepository->findMembersByClan($clanId) as $row) {
            $members[] = $this->toMemberDto($row);
        }

        return $members;
    }

    /**
     * Las postulaciones PENDIENTES de una casa, reservadas a la deliberación
     * del Patriarca (Endpoints 5 y 6).
     *
     * Quien no ciñe la corona recibe una lista vacía en lugar de un rechazo:
     * la ficha pública del clan no debe delatar quién corteja a quién, y el
     * contrato permanece estable para todo lector. El Patriarca solo necesita
     * conocer el identificador que el Endpoint 6 exige para dictar veredicto.
     *
     * @return list<ClanApplicationDto>
     *
     * @throws ClanGovernanceException Si la hermandad no existe.
     */
    public function listPendingApplications(User $requester, string $clanId): array
    {
        $clan = $this->requireClan($clanId);

        if ($clan->patriarchId === null || $clan->patriarchId !== $requester->getId()) {
            return [];
        }

        $applications = [];
        foreach ($this->applicationRepository->findApplicationsByClan($clanId, ClanApplicationDto::STATUS_PENDING) as $row) {
            $applications[] = $this->toApplicationDto($row);
        }

        return $applications;
    }

    // ── Gobierno interno ─────────────────────────────────────────────────

    /**
     * Inscribe la postulación formal de un adepto (RF-01.5, RF-03.1 de SPEC-10).
     *
     * `$instant` es la estampa de LLEGADA ya resuelta (cliente acotada o
     * servidor): alimenta la cronología y el desempate de la última vacante.
     */
    private function registerApplication(
        User $applicant,
        ClanDto $clan,
        DateTimeImmutable $instant,
        string $motivation = '',
    ): ClanAdmissionResult
    {
        $nowUtc = $this->formatInstant($instant);
        $applicationId = $this->newIdentifier('app');

        $registered = $this->applicationRepository->createApplication(
            $applicationId,
            $clan->id,
            $applicant->getId(),
            $nowUtc,
            $motivation,
        );

        if ($registered === null) {
            // La guarda atómica bloqueó la pluma: se discierne la causa exacta
            // para emitir la leyenda que el canon exige. Primero la pendiente
            // viva de ESTA casa (idempotencia, caso límite 6: la petición ya
            // remitida responde por idempotencia sin duplicar), después la
            // clausura de la casa (SPEC-10, RF-03.1): CUALQUIER fila histórica
            // —aprobada, rechazada o cancelada— veda la re-postulación, sin
            // consumir cupo (hallazgo 16).
            if ($this->applicationRepository->findPendingApplicationForClan($applicant->getId(), $clan->id) !== null) {
                throw ClanGovernanceException::applicationAlreadyPending();
            }

            if ($this->applicationRepository->hasSealedHouse($applicant->getId(), $clan->id)) {
                throw ClanGovernanceException::applicationHouseClosed();
            }

            throw ClanGovernanceException::pendingApplicationsLimit(
                ClanApplicationDto::MAX_PENDING_APPLICATIONS
            );
        }

        // Asiento de la REMISIÓN: lado del postulante (SPEC-10, RF-04.4,
        // plan §2.3) — su palabra queda registrada ante la casa.
        $this->recordAudit(
            $applicant->getId(),
            $applicant->getAlias(),
            $applicant->getRole(),
            'CLAN_APPLICATION_SUBMITTED',
            $clan->id,
            "«{$applicant->getAlias()}» remite su petición de ingreso ante «{$clan->name}».",
            $instant,
        );

        return ClanAdmissionResult::pending($this->toApplicationDto($registered));
    }

    /**
     * Admite de inmediato en régimen abierto (RF-01.5).
     */
    private function admitImmediately(User $applicant, ClanDto $clan, DateTimeImmutable $instant): ClanAdmissionResult
    {
        $nowUtc = $this->formatInstant($instant);

        $membership = $this->runAtomically(function () use ($applicant, $clan, $nowUtc, $instant): ?array {
            $inscribed = $this->memberRepository->addMember(
                $this->newIdentifier('clm'),
                $clan->id,
                $applicant->getId(),
                ClanMemberDto::ROLE_ADEPT,
                $nowUtc,
            );

            if ($inscribed === null) {
                return null;
            }

            // Quien ingresa deja de cortejar a otras casas (RF-01.5). Cada
            // petición huérfana deja SU asiento (SPEC-10, RF-03.7), inscrito
            // por el postulante (lado postulante del reparto por actor).
            $residualIds = $this->applicationRepository->cancelPendingApplications(
                $applicant->getId(),
                null,
                $nowUtc,
            );
            foreach ($residualIds as $residualId) {
                $this->recordAudit(
                    $applicant->getId(),
                    $applicant->getAlias(),
                    $applicant->getRole(),
                    'CLAN_APPLICATION_RESIDUALS_ANNULLED',
                    $clan->id,
                    "La lealtad indivisible absuelve la petición huérfana de «{$applicant->getAlias()}» al entrar en «{$clan->name}».",
                    $instant,
                );
            }

            return $inscribed;
        });

        if ($membership === null) {
            throw ClanGovernanceException::alreadyAffiliated();
        }

        // Asiento del INGRESO INMEDIATO: lado del postulante (SPEC-10,
        // RF-04.4, plan §2.3) — la membresía nace y la Bitácora lo recuerda.
        $this->recordAudit(
            $applicant->getId(),
            $applicant->getAlias(),
            $applicant->getRole(),
            'CLAN_MEMBER_JOINED',
            $clan->id,
            "«{$applicant->getAlias()}» cruza las puertas de «{$clan->name}» por admisión abierta.",
            $instant,
        );

        return ClanAdmissionResult::admitted($this->toMemberDto($membership));
    }

    /**
     * Valida y normaliza la motivación escrita de la petición formal
     * (SPEC-10, RF-03.1): 20 a 500 caracteres.
     *
     * El Patriarca es la ÚNICA guardia del tono (plan §5.6): el molde es la
     * única regla mecánica, determinista, que el backend aplica.
     *
     * @throws ClanGovernanceException INVALID_MOTIVATION si el texto falta o
     *                                  desborda el molde.
     */
    private function requireCanonicalMotivation(?string $motivation): string
    {
        $canonicalMotivation = trim((string) $motivation);
        $length = mb_strlen($canonicalMotivation);

        if ($length < self::MOTIVATION_MIN_LENGTH || $length > self::MOTIVATION_MAX_LENGTH) {
            throw ClanGovernanceException::invalidMotivation(
                self::MOTIVATION_MIN_LENGTH,
                self::MOTIVATION_MAX_LENGTH
            );
        }

        return $canonicalMotivation;
    }

    /**
     * El molde del MOTIVO DEL RECHAZO (SPEC-10, Tarea 2.6; Artículo III.3):
     * el mismo acotamiento 20–500 que la petición formal, pero con código
     * canónico propio (`INVALID_VERDICT_MOTIVE`) — el asiento del dictamen es
     * acto del lado deliberante, no una petición del postulante.
     *
     * @throws ClanGovernanceException INVALID_VERDICT_MOTIVE si el motivo
     *                                  falta o desborda el molde.
     */
    private function requireCanonicalVerdictMotive(?string $motive): string
    {
        $canonicalMotive = trim((string) $motive);
        $length = mb_strlen($canonicalMotive);

        if ($length < self::MOTIVATION_MIN_LENGTH || $length > self::MOTIVATION_MAX_LENGTH) {
            throw ClanGovernanceException::invalidVerdictMotive(
                self::MOTIVATION_MIN_LENGTH,
                self::MOTIVATION_MAX_LENGTH
            );
        }

        return $canonicalMotive;
    }

    /**
     * Resuelve la estampa de llegada del gesto (plan §3.3, decisión 9).
     *
     * La llegada declarada por el cliente solo se honra dentro de una ventana
     * de tolerancia de ±30 s respecto al instante del servidor; fuera de ella
     * (o si falta) manda el instante del servidor: el criterio es la LLEGADA
     * al santuario, jamás el reloj del cliente.
     */
    private function resolveArrivalInstant(?string $receivedAt, DateTimeImmutable $serverInstant): DateTimeImmutable
    {
        $declared = $this->parseInstant($receivedAt);
        if ($declared === null) {
            return $serverInstant;
        }

        $tolerance = new DateInterval('PT' . self::ARRIVAL_TOLERANCE_SECONDS . 'S');
        $windowStart = $serverInstant->sub($tolerance);
        $windowEnd = $serverInstant->add($tolerance);

        if ($declared < $windowStart || $declared > $windowEnd) {
            return $serverInstant;
        }

        return $declared;
    }

    /**
     * Exige que la casa exista y siga en contienda (RF-05.3).
     */
    private function requireActiveClan(ClanDto $clan, string $clanId): void
    {
        if (!$clan->isActive()) {
            throw ClanGovernanceException::clanArchived($clanId);
        }
    }

    /**
     * Exige que haya vacante en el cupo de treinta adeptos (RF-01.4).
     *
     * Es la barrera que bloquea al miembro número treinta y uno.
     */
    private function assertVacancy(string $clanId): void
    {
        $limit = ClanMemberRepository::MAX_ACTIVE_MEMBERS;

        if ($this->memberRepository->countActiveMembers($clanId) >= $limit) {
            throw ClanGovernanceException::clanQuotaExceeded($limit);
        }
    }

    /**
     * Exige rango `editor` o superior (RF-01.1).
     */
    private function requireEligibleRank(User $actor): void
    {
        if (!in_array($actor->getRole(), self::ELIGIBLE_RANKS, true)) {
            throw ClanGovernanceException::insufficientRank();
        }
    }

    /**
     * Exige que el actuante ciña la corona de la casa (RF-01.3).
     */
    private function requirePatriarch(User $actor, ClanDto $clan, string $clanId): void
    {
        $this->requireActiveClan($clan, $clanId);

        if ($clan->patriarchId === null || $clan->patriarchId !== $actor->getId()) {
            throw ClanGovernanceException::notPatriarch();
        }
    }

    /**
     * Exige que el mago no esté meditando su Convalecencia Arcana (RF-01.6).
     */
    private function requireFreedomFromConvalescence(User $actor, string $nowUtc): void
    {
        if ($this->memberRepository->isUserInConvalescence($actor->getId(), $nowUtc)) {
            throw ClanGovernanceException::convalescenceActive();
        }
    }

    /**
     * Exige el Privilegio Fundacional del Admin Supremo: sin linaje jurado no
     * hay hermandades que contemplar ni gestos que consumar (SPEC-10, RF-01.1).
     *
     * El Supremo sin linaje está EXENTO de la retención de SPEC-09, de modo
     * que jamás llegaría a LINEAGE_OATH_REQUIRED: esta guardia es su veredicto
     * propio y solemne. El peregrino común no pasa por aquí: la retención de
     * SPEC-09 lo detiene antes en producción (RF-04.3 de SPEC-10), y en el
     * servidor su `lineage` null queda vedado por el guardia de mismidad
     * contra el linaje de la casa (null ≠ lineageType).
     */
    private function requireOathLineageForGesture(User $actor): void
    {
        if ($actor->getRole() === 'supremeAdmin' && $actor->getLineage() === null) {
            throw ClanGovernanceException::adminLineageRequired();
        }
    }

    /**
     * Resuelve el nombre solemne de la casa donde el adepto tiene su lealtad
     * empeñada (SPEC-10, hallazgo 4): la leyenda nombra a la casa, no al id.
     */
    private function nameOfActiveMembership(string $userId): string
    {
        $membership = $this->memberRepository->findActiveMembership($userId);
        if ($membership === null) {
            return 'tu casa';
        }

        // La membresía llega como fila hidratada del repositorio (array).
        $membershipClanId = is_array($membership)
            ? (string) $membership['clan_id']
            : $membership->clanId;

        $clanRow = $this->clanRepository->findById($membershipClanId);

        return $clanRow === null ? $membershipClanId : (string) $clanRow['name'];
    }

    /**
     * Valida y normaliza el Nombre Canónico (RF-01.2).
     *
     * @throws ClanGovernanceException Si su extensión rompe el canon.
     */
    private function requireCanonicalName(string $name): string
    {
        $canonicalName = trim($name);
        $length = mb_strlen($canonicalName);

        if ($length < ClanDto::NAME_MIN_LENGTH || $length > ClanDto::NAME_MAX_LENGTH) {
            throw ClanGovernanceException::invalidName(
                ClanDto::NAME_MIN_LENGTH,
                ClanDto::NAME_MAX_LENGTH
            );
        }

        return $canonicalName;
    }

    /**
     * Exige que el linaje rector figure entre los ocho canónicos (RF-02.1).
     */
    private function requireCanonicalLineage(string $lineageType): void
    {
        if (!$this->lineageService->hasLineage($lineageType)) {
            throw ClanGovernanceException::unknownLineage($lineageType);
        }
    }

    /**
     * Recupera la ficha de una casa o alza la ausencia (Endpoint 3).
     */
    private function requireClan(string $clanId): ClanDto
    {
        $clan = $this->findClanById($clanId);

        if ($clan === null) {
            throw ClanGovernanceException::clanNotFound($clanId);
        }

        return $clan;
    }

    /**
     * Escoge al sucesor: mayor antigüedad, dirimiendo empates por PDA (RF-01.9).
     *
     * El orden del canon es `(joinedAt ASC, contributedPoints DESC)`: la
     * antigüedad MANDA y el volumen de PDA aportados solo desempata entre
     * adeptos ingresados en el mismo instante. Las filas llegan ya alineadas
     * por antigüedad ascendente, así que basta con examinar el primer grupo
     * de ingreso idéntico y quedarse con su mayor contribuyente.
     *
     * @param list<array<string, mixed>> $candidates Adeptos activos por antigüedad.
     */
    private function selectSuccessor(array $candidates, string $clanId): ?string
    {
        if ($candidates === []) {
            return null;
        }

        $mostAncient = $candidates[0];
        $ancientSince = (string) $mostAncient['joined_at'];
        $bestUserId = (string) $mostAncient['user_id'];
        $bestContribution = $this->contributedPoints($bestUserId, $clanId);

        foreach ($candidates as $candidate) {
            if ((string) $candidate['joined_at'] !== $ancientSince) {
                // Ingresó después: la antigüedad ya dictó el veredicto.
                break;
            }

            $userId = (string) $candidate['user_id'];
            $contributed = $this->contributedPoints($userId, $clanId);

            if ($contributed > $bestContribution) {
                $bestUserId = $userId;
                $bestContribution = $contributed;
            }
        }

        return $bestUserId;
    }

    /**
     * PDA acumulados que un adepto aportó a su casa.
     *
     * Se suman los DOS libros de gloria que el plano conserva por adepto, sin
     * solaparse entre sí: el acumulador diario del simulador (RF-03.2) y el
     * libro de acreditaciones de conjuros validados y elogios comunitarios
     * (Tarea 2.5). El total es determinista y auditable, y basta como criterio
     * de desempate en la sucesión dinástica (RNF-01).
     */
    private function contributedPoints(string $userId, string $clanId): int
    {
        $practice = $this->pdo->prepare(
            'SELECT COALESCE(SUM(points_awarded), 0)
               FROM daily_simulator_tracker
              WHERE user_id = :userId
                AND clan_id = :clanId'
        );
        $practice->execute([':userId' => $userId, ':clanId' => $clanId]);

        $merits = $this->pdo->prepare(
            'SELECT COALESCE(SUM(awarded_points), 0)
               FROM dominion_awards
              WHERE user_id = :userId
                AND clan_id = :clanId'
        );
        $merits->execute([':userId' => $userId, ':clanId' => $clanId]);

        return (int) $practice->fetchColumn() + (int) $merits->fetchColumn();
    }

    /**
     * Días naturales de silencio del Patriarca (RF-01.9).
     *
     * @param array<string, mixed> $clan Fila cruda de la hermandad.
     */
    private function inactivityDays(array $clan, DateTimeImmutable $instant): int
    {
        $lastActivity = $this->parseInstant($clan['last_activity_at'] ?? null)
            ?? $this->parseInstant($clan['created_at'] ?? null);

        if ($lastActivity === null) {
            // Sin marca fiable no se depone a nadie: el silencio no se presume.
            return 0;
        }

        return (int) $lastActivity->diff($instant)->days;
    }

    /**
     * Forja la ficha heráldica con el censo de adeptos resuelto (RF-01.4).
     *
     * @param array<string, mixed> $row Fila cruda de `clans`.
     */
    private function toClanDto(array $row): ClanDto
    {
        $row['member_count'] = $this->memberRepository->countActiveMembers((string) $row['id']);

        return ClanDto::fromDatabaseRow($row);
    }

    /**
     * Forja la membresía con el alias público del adepto.
     *
     * @param array<string, mixed> $row Fila cruda de `clan_members`.
     */
    private function toMemberDto(array $row): ClanMemberDto
    {
        $row['user_alias'] = $this->aliasFor((string) $row['user_id']);

        return ClanMemberDto::fromDatabaseRow($row);
    }

    /**
     * Forja la solicitud con los alias de casa y postulante.
     *
     * @param array<string, mixed> $row Fila cruda de `clan_applications`.
     */
    private function toApplicationDto(array $row): ClanApplicationDto
    {
        $row['user_alias'] = $this->aliasFor((string) $row['user_id']);

        $clanRow = $this->clanRepository->findById((string) $row['clan_id']);
        $row['clan_name'] = $clanRow === null ? '' : (string) $clanRow['name'];

        return ClanApplicationDto::fromDatabaseRow($row);
    }

    /**
     * Alias público de un mago, o cadena vacía si su cuenta ya no existe.
     */
    private function aliasFor(string $userId): string
    {
        $statement = $this->pdo->prepare('SELECT alias FROM users WHERE id = :userId');
        $statement->execute([':userId' => $userId]);
        $alias = $statement->fetchColumn();

        return is_string($alias) ? $alias : '';
    }

    /**
     * Inscribe un acto de gobierno en la Bitácora pública (RNF-04).
     *
     * Si el servicio se invoca sin bitácora (arneses puros), el acto se
     * consuma igualmente y no se inscribe: la ausencia de pluma no puede
     * impedir el gobierno.
     */
    private function recordAudit(
        string $actorUserId,
        string $actorAlias,
        string $actorRole,
        string $actionType,
        string $clanId,
        string $justification,
        DateTimeImmutable $instant,
    ): void {
        $this->auditService?->recordAction(
            $actorUserId,
            $actorAlias,
            $actorRole,
            $actionType,
            'clan',
            $clanId,
            $justification,
            $instant,
        );
    }

    /**
     * Ejecuta una operación como un solo gesto confirmable o reversible.
     *
     * Se pliega a una transacción ajena si ya la hubiere, de modo que el
     * llamante pueda componer operaciones mayores (RNF-01).
     */
    private function runAtomically(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $operation();
        }

        $this->pdo->beginTransaction();

        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $failure) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $failure;
        }
    }

    /**
     * Instante canónico de la operación: el inyectado, o un único latido UTC.
     */
    private function instant(?DateTimeImmutable $now): DateTimeImmutable
    {
        return $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Estampa ISO 8601 UTC, idéntica en formato a la que siembran las semillas.
     */
    private function formatInstant(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Fin de la Convalecencia Arcana: catorce días naturales (RF-01.6).
     */
    private function convalescenceExpiry(string $nowUtc): string
    {
        $departure = $this->parseInstant($nowUtc) ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $this->formatInstant($departure->modify('+' . self::CONVALESCENCE_DAYS . ' days'));
    }

    /**
     * Lee una marca ISO 8601 UTC, o null si no es interpretable.
     */
    private function parseInstant(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Identificador textual canónico (`cln_`, `clm_`, `app_`).
     */
    private function newIdentifier(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(6));
    }

    /**
     * Enlace público estable derivado del Nombre Canónico.
     */
    private function canonicalSlug(string $name): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $asciiName = is_string($transliterated) ? $transliterated : $name;

        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $asciiName));
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'hermandad-sin-nombre';
    }
}
