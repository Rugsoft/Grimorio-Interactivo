<?php

/**
 * MasterDeliberationService.php — Deliberación colegiada de la Torre:
 * firmas de consagración, retractaciones, dictámenes de objeción y la
 * consagración atómica en la tercera rúbrica (SPEC-08, Tarea 2.4).
 *
 * Cubre: RF-02.1 (tres firmas independientes de Maestros distintos y una sola
 * voz por hermandad), RF-02.2 (glosa ceremonial de hasta 250 caracteres),
 * RF-02.3 (consagración automática e inmediata en la 3ª firma, con
 * acreditación de PDA al clan originario), RF-02.4 (retractación antes de la
 * consagración, irrevocable después), RF-02.5 (Dictamen de Objeción con
 * justificación de al menos veinte caracteres), RF-02.6 (el veto cancela los
 * avales previos y devuelve la obra a la libreta) y RNF-02 (atomicidad y
 * bloqueo pesimista).
 *
 * Cada gesto abre UNA transacción, TOMA EL BLOQUEO de la fila del expediente
 * (`findAndLockById`) y solo entonces lee y escribe: la tercera firma y una
 * retractación simultánea no pueden consagrar una obra que quedó en dos avales,
 * ni el contador puede saltarse una rúbrica. El contador se CUENTA desde las
 * firmas vivas, jamás se incrementa a ciegas. El expediente es la única
 * autoridad y arrastra el espejo de `spells`.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP nativo y PDO; cero dependencias.
 *   - Artículo II: el balance sellado no se toca aquí; solo se juzga.
 *   - Artículo III: el veto ético decide quién puede juzgar.
 *   - Artículo IV: las glosas y los dictámenes viajan íntegros, en castellano.
 *   - Artículo V (Dualidad): identificadores en inglés camelCase, narrativa y
 *     comentarios en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use DateTimeImmutable;
use DateTimeZone;
use Grimorio\Dto\MasterSignatureDto;
use Grimorio\Dto\SpellReviewDto;
use Grimorio\Exceptions\ModerationWorkflowException;
use Grimorio\Exceptions\SpellNotFoundException;
use Grimorio\Models\User;
use Grimorio\Repositories\ClanMemberRepository;
use Grimorio\Repositories\MasterSignatureRepository;
use Grimorio\Repositories\ObjectionVerdictRepository;
use Grimorio\Repositories\SpellReviewRepository;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Cónclave de Maestros: firmas, objeciones y consagración en la tercera rúbrica.
 */
final class MasterDeliberationService
{
    /**
     * Rango con potestad judicial ORDINARIA (RF-02.1).
     *
     * La potestad suprema no firma por esta vía: su intervención es un decreto
     * con Edicto Imperial (RF-04.1), sujeto a otro protocolo y a otro veto.
     */
    public const JUDGE_ROLE = 'master';

    /** Firmas vivas que consuman una obra (RF-02.1, RF-02.3). */
    public const SIGNATURES_REQUIRED = SpellReviewRepository::MAX_SIGNATURES;

    /** Glosa ceremonial máxima, medida en caracteres (RF-02.2). */
    public const MAX_GLOSS_LENGTH = MasterSignatureRepository::MAX_GLOSS_LENGTH;

    /** Justificación mínima del Dictamen de Objeción (RF-02.5). */
    public const MIN_OBJECTION_LENGTH = ObjectionVerdictRepository::MIN_OBJECTION_REASON_LENGTH;

    /** Conexión PDO al plano arcano. */
    private PDO $pdo;

    /** Expediente de moderación: autoridad del estado y del contador. */
    private SpellReviewRepository $reviewRepository;

    /** Censo de firmas vivas, su revocación y su memoria. */
    private MasterSignatureRepository $signatureRepository;

    /** Dictámenes de Objeción Fundamentada. */
    private ObjectionVerdictRepository $verdictRepository;

    /** Historial de membresía: el linaje del firmante y su convalecencia. */
    private ClanMemberRepository $memberRepository;

    /** Veto ético constitucional y pluralidad de hermandades (Tarea 2.2). */
    private ConstitutionalEthicsValidator $ethicsValidator;

    /** Acreditación de la gloria al linaje originario (SPEC-07). */
    private WeeklyDominionService $dominionService;

    /** Único canal hacia la Bitácora de Auditoría pública (SPEC-03). */
    private AuditService $auditService;

    /**
     * Consagra el servicio sobre el expediente, el censo de firmas y la gloria.
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->reviewRepository = new SpellReviewRepository($pdo);
        $this->signatureRepository = new MasterSignatureRepository($pdo);
        $this->verdictRepository = new ObjectionVerdictRepository($pdo);
        $this->memberRepository = new ClanMemberRepository($pdo);
        $this->ethicsValidator = new ConstitutionalEthicsValidator($pdo);
        $this->auditService = new AuditService($pdo);
        $this->dominionService = new WeeklyDominionService($pdo, $this->auditService);
    }

    /**
     * Estampa una Firma de Consagración y, si es la tercera, CONSAGRA la obra
     * de forma atómica acreditando los PDA a su linaje originario (RF-02.3).
     *
     * @param string                 $spellId         Obra en deliberación.
     * @param string                 $masterId        Maestro del Cónclave.
     * @param string|null            $ceremonialGloss Glosa litúrgica opcional (máx. 250 car.).
     * @param DateTimeImmutable|null $now             Instante de la firma.
     *
     * @throws ModerationWorkflowException Si la obra no está en deliberación, media veto ético, falta pluralidad o ya había firmado.
     * @throws SpellNotFoundException      Si la obra no existe.
     * @throws Throwable                   Si la escritura atómica fracasa; la firma se deshace entera.
     */
    public function signSpell(
        string $spellId,
        string $masterId,
        ?string $ceremonialGloss = null,
        ?DateTimeImmutable $now = null,
    ): SpellReviewDto {
        $master = $this->loadMaster($masterId);
        $instant = self::normalizeInstant($now);
        $timestamp = self::stamp($instant);
        $gloss = self::normalizeGloss($ceremonialGloss);

        if ($gloss !== null && mb_strlen($gloss, 'UTF-8') > self::MAX_GLOSS_LENGTH) {
            throw ModerationWorkflowException::glossTooLong(
                self::MAX_GLOSS_LENGTH,
                mb_strlen($gloss, 'UTF-8'),
            );
        }

        $this->pdo->beginTransaction();

        try {
            $review = $this->lockReviewOrFail($spellId);
            $this->assertDeliberationIsOpen($review);
            $this->assertEthicsAllowEvaluation($masterId, $review, $instant);

            $masterClanId = $this->resolveSignatureClanId($masterId, $timestamp);
            $activeSignatures = $this->signatureRepository->findActiveSignatures($spellId);

            foreach ($activeSignatures as $activeSignature) {
                if ((string) $activeSignature['master_id'] === $masterId) {
                    throw ModerationWorkflowException::alreadySigned();
                }
            }

            if (!$this->ethicsValidator->validateClanPlurality($activeSignatures, $masterClanId)) {
                throw ModerationWorkflowException::clanPluralityViolation();
            }

            // La muralla de la unicidad vive en la base (índice único parcial):
            // si el Maestro reestampa, `insertSignature()` devuelve null.
            $signature = $this->signatureRepository->insertSignature(
                $this->newIdentifier('sig'),
                $spellId,
                $masterId,
                $timestamp,
                $masterClanId,
                $gloss,
            );
            if ($signature === null) {
                throw ModerationWorkflowException::alreadySigned();
            }

            // El contador se CUENTA desde las firmas VIVAS: jamás se incrementa
            // a ciegas ni puede divergir del censo.
            $signaturesCount = $this->signatureRepository->countActiveSignatures($spellId);
            $this->reviewRepository->updateSignaturesCount($spellId, $signaturesCount);

            $this->auditService->recordAction(
                actorUserId: $masterId,
                actorAlias: $master->getAlias(),
                actorRole: $master->getRole(),
                actionType: 'SIGN_VALIDATE',
                targetEntityType: 'spell',
                targetEntityId: $spellId,
                justification: $this->signatureJustification($signaturesCount, $masterClanId, $gloss),
                now: $instant,
            );

            if ($signaturesCount >= self::SIGNATURES_REQUIRED) {
                $this->consecrate($spellId, $master, $instant, $timestamp);
            }

            $this->pdo->commit();
        } catch (Throwable $failure) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $failure;
        }

        return $this->reviewDtoOf($spellId);
    }

    /**
     * Retracta la firma viva de un Maestro antes de la consagración (RF-02.4).
     *
     * Una vez alcanzada la tercera rúbrica, la consagración es irrevocable para
     * los Maestros: solo un decreto soberano podría deshacerla (RF-04.3).
     *
     * @param string $reason Motivo humano de la retractación, que viaja a la bitácora.
     *
     * @throws ModerationWorkflowException Si la obra no está en deliberación, ya fue consagrada o no hay firma viva.
     * @throws SpellNotFoundException      Si la obra no existe.
     * @throws Throwable                   Si la escritura atómica fracasa.
     */
    public function retractSignature(
        string $spellId,
        string $masterId,
        ?string $reason = null,
        ?DateTimeImmutable $now = null,
    ): SpellReviewDto {
        $master = $this->loadMaster($masterId);
        $instant = self::normalizeInstant($now);
        $timestamp = self::stamp($instant);

        $this->pdo->beginTransaction();

        try {
            $review = $this->lockReviewOrFail($spellId);

            if ((string) $review['status'] === SpellReviewRepository::STATUS_VALIDATED) {
                throw ModerationWorkflowException::signatureIrrevocable();
            }
            $this->assertDeliberationIsOpen($review);

            $signature = $this->signatureRepository->findActiveSignature($spellId, $masterId);
            if ($signature === null) {
                throw ModerationWorkflowException::noActiveSignature();
            }

            $this->signatureRepository->revokeSignature(
                (string) $signature['id'],
                MasterSignatureRepository::REVOCATION_RETRACTED,
                $timestamp,
            );

            $signaturesCount = $this->signatureRepository->countActiveSignatures($spellId);
            $this->reviewRepository->updateSignaturesCount($spellId, $signaturesCount);

            $this->auditService->recordAction(
                actorUserId: $masterId,
                actorAlias: $master->getAlias(),
                actorRole: $master->getRole(),
                actionType: 'SIGNATURE_RETRACTED',
                targetEntityType: 'spell',
                targetEntityId: $spellId,
                justification: $this->retractionJustification($reason, $signaturesCount),
                now: $instant,
            );

            $this->pdo->commit();
        } catch (Throwable $failure) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $failure;
        }

        return $this->reviewDtoOf($spellId);
    }

    /**
     * Emite un Dictamen de Objeción Fundamentada (RF-02.5): la obra pasa de
     * inmediato a `rejected`, sale del Atrio y regresa a la libreta de su autor,
     * CANCELANDO los avales previos (RF-02.6).
     *
     * @param string $objectionReason Justificación solemne en castellano (mín. 20 car.).
     *
     * @throws ModerationWorkflowException Si la justificación es breve, la obra no está en deliberación o media veto ético.
     * @throws SpellNotFoundException      Si la obra no existe.
     * @throws Throwable                   Si la escritura atómica fracasa.
     */
    public function objectSpell(
        string $spellId,
        string $masterId,
        string $objectionReason,
        ?DateTimeImmutable $now = null,
    ): SpellReviewDto {
        $master = $this->loadMaster($masterId);
        $instant = self::normalizeInstant($now);
        $timestamp = self::stamp($instant);
        $reason = trim($objectionReason);

        if (mb_strlen($reason, 'UTF-8') < self::MIN_OBJECTION_LENGTH) {
            throw ModerationWorkflowException::objectionTooBrief(
                self::MIN_OBJECTION_LENGTH,
                mb_strlen($reason, 'UTF-8'),
            );
        }

        $this->pdo->beginTransaction();

        try {
            $review = $this->lockReviewOrFail($spellId);
            $this->assertDeliberationIsOpen($review);
            $this->assertEthicsAllowEvaluation($masterId, $review, $instant);

            $this->verdictRepository->insertVerdict(
                $this->newIdentifier('obj'),
                $spellId,
                $masterId,
                $reason,
                $timestamp,
            );

            // RF-02.6: el veto cancela cualquier aval previo. Ninguna firma
            // puede sobrevivir a la versión que el Cónclave declaró inadmisible.
            $this->signatureRepository->revokeActiveSignaturesForSpell(
                $spellId,
                MasterSignatureRepository::REVOCATION_REVIEW_REJECTED,
                $timestamp,
            );
            $this->reviewRepository->updateSignaturesCount($spellId, 0);
            $this->reviewRepository->updateStatus($spellId, SpellReviewRepository::STATUS_REJECTED, $timestamp);

            $this->auditService->recordAction(
                actorUserId: $masterId,
                actorAlias: $master->getAlias(),
                actorRole: $master->getRole(),
                actionType: 'SIGN_REJECT',
                targetEntityType: 'spell',
                targetEntityId: $spellId,
                justification: $reason,
                now: $instant,
            );

            $this->pdo->commit();
        } catch (Throwable $failure) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $failure;
        }

        return $this->reviewDtoOf($spellId);
    }

    /**
     * Firmas VIVAS de una obra, en el orden en que fueron estampadas (RF-05.4).
     *
     * Es el censo que la Torre de Deliberación exhibe junto al indicador 0/3.
     *
     * @return list<MasterSignatureDto>
     */
    public function activeSignaturesFor(string $spellId): array
    {
        return array_map(
            static fn (array $row): MasterSignatureDto => MasterSignatureDto::fromDatabaseRow($row),
            $this->signatureRepository->findActiveSignatures($spellId),
        );
    }

    /**
     * Consagra la obra dentro de la transacción del llamante (RF-02.3): el
     * estado se eleva, la consagración se fecha para el Libro de Oro, se
     * inscribe en la Bitácora y se acredita la gloria al linaje originario.
     *
     * La acreditación ocurre SOLO aquí: mientras la obra permanece en
     * deliberación no genera PDA alguno (RF-05.3).
     */
    private function consecrate(
        string $spellId,
        User $master,
        DateTimeImmutable $instant,
        string $timestamp,
    ): void {
        $this->reviewRepository->updateStatus($spellId, SpellReviewRepository::STATUS_VALIDATED, $timestamp);

        $this->auditService->recordAction(
            actorUserId: $master->getId(),
            actorAlias: $master->getAlias(),
            actorRole: $master->getRole(),
            actionType: 'SPELL_CONSECRATED',
            targetEntityType: 'spell',
            targetEntityId: $spellId,
            justification: 'Consagración automática al alzarse la tercera firma: la obra queda sellada en el Gran Tomo Canónico y su gloria se acredita al linaje originario.',
            now: $instant,
        );

        // SPEC-07: la gloria va SIEMPRE al linaje bajo cuyo estandarte se forjó
        // la obra, aunque su autor haya partido. Una casa disuelta la recibe
        // como Herencia Ancestral (haber perpetuo, sin contienda semanal).
        $this->dominionService->awardValidatedSpell($spellId, $instant);
    }

    /**
     * Justificación solemne de la firma, con el clan del firmante y su glosa
     * íntegra (RF-06.1): la Bitácora pública debe poder leerse sin la base.
     */
    private function signatureJustification(int $signaturesCount, ?string $masterClanId, ?string $gloss): string
    {
        $lineage = $masterClanId === null
            ? 'como Maestro ermitaño neutral'
            : "bajo el estandarte «{$masterClanId}»";
        $justification = "Firma de Consagración estampada {$lineage}: la obra acumula {$signaturesCount}/"
            . self::SIGNATURES_REQUIRED . ' avales vivos.';

        return $gloss === null || $gloss === ''
            ? $justification
            : $justification . " Glosa litúrgica: «{$gloss}»";
    }

    /** Justificación de la retractación, con el motivo humano si lo hubo (RF-02.4). */
    private function retractionJustification(?string $reason, int $signaturesCount): string
    {
        $justification = 'Retractación voluntaria de la firma de consagración: '
            . "la obra desciende a {$signaturesCount}/" . self::SIGNATURES_REQUIRED . ' avales vivos.';
        $reason = $reason === null ? '' : trim($reason);

        return $reason === '' ? $justification : $justification . " Alegó el Maestro: «{$reason}»";
    }

    /**
     * Toma el bloqueo del expediente y lo devuelve (RNF-02).
     *
     * La transacción debe estar ABIERTA: el bloqueo solo sirve si cubre la
     * lectura y la escritura que le siguen.
     *
     * @return array<string, mixed>
     *
     * @throws SpellNotFoundException Si la obra no entró nunca a deliberación.
     */
    private function lockReviewOrFail(string $spellId): array
    {
        $review = $this->reviewRepository->findAndLockById($spellId);
        if ($review === null) {
            throw new SpellNotFoundException('Esa obra no consta en la Torre de Moderación.');
        }

        return $review;
    }

    /**
     * Solo se juzga lo que yace en deliberación: ni el borrador privado, ni la
     * obra vetada, ni la consagrada admiten firma ni dictamen.
     *
     * @param array<string, mixed> $review
     *
     * @throws ModerationWorkflowException Si el estado no es `experimental`.
     */
    private function assertDeliberationIsOpen(array $review): void
    {
        $status = (string) ($review['status'] ?? '');
        if ($status !== SpellReviewRepository::STATUS_EXPERIMENTAL) {
            throw ModerationWorkflowException::notInReview($status);
        }
    }

    /**
     * El veto constitucional del Artículo III, con su causa NOMBRADA: la propia
     * pluma (RF-03.2) y el linaje actual o reciente (RF-03.1).
     *
     * @param array<string, mixed> $review
     *
     * @throws ModerationWorkflowException Si media conflicto de intereses.
     */
    private function assertEthicsAllowEvaluation(
        string $masterId,
        array $review,
        DateTimeImmutable $instant,
    ): void {
        $vetoCode = $this->ethicsValidator->evaluationVetoCode(
            $masterId,
            $review['origin_clan_id'] === null ? null : (string) $review['origin_clan_id'],
            (string) ($review['author_id'] ?? ''),
            $instant,
        );

        if ($vetoCode === ConstitutionalEthicsValidator::VETO_OWN_AUTHORSHIP) {
            throw ModerationWorkflowException::selfSigningProhibited();
        }

        if ($vetoCode !== null) {
            throw ModerationWorkflowException::constitutionalEthicsVeto();
        }
    }

    /**
     * Linaje con el que se ESTAMPA la firma (RF-03.6).
     *
     * La autoridad es el historial de membresía (`clan_members`), no el espejo
     * `users.clan_id`: un Maestro en convalecencia arcana conserva su potestad
     * judicial pero firma como ermitaño neutral, de modo que su rúbrica no
     * ocupa plaza de hermandad alguna.
     */
    private function resolveSignatureClanId(string $masterId, string $timestampUtc): ?string
    {
        $membership = $this->memberRepository->findActiveMembership($masterId);
        $activeClanId = $membership === null ? null : (string) ($membership['clan_id'] ?? '');

        return $this->ethicsValidator->signatureClanIdFor(
            $activeClanId,
            $this->memberRepository->isUserInConvalescence($masterId, $timestampUtc),
        );
    }

    /**
     * Carga la entidad del Maestro y exige el rango que juzga (RF-02.1).
     *
     * @throws InvalidArgumentException    Si el usuario no consta en los anales.
     * @throws ModerationWorkflowException Si el rango no alcanza para juzgar.
     */
    private function loadMaster(string $masterId): User
    {
        $masterId = trim($masterId);
        if ($masterId === '') {
            throw new InvalidArgumentException('La deliberación exige conocer la identidad del Maestro que juzga.');
        }

        $statement = $this->pdo->prepare(
            'SELECT id, alias, email, role, clan_id, password_hash, created_at, updated_at
               FROM users WHERE id = :userId'
        );
        $statement->execute([':userId' => $masterId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new InvalidArgumentException('Solo un Maestro inscrito en los anales puede juzgar una obra.');
        }

        $master = new User(
            id: (string) $row['id'],
            alias: (string) $row['alias'],
            email: (string) $row['email'],
            role: (string) $row['role'],
            clanId: $row['clan_id'] === null ? null : (string) $row['clan_id'],
            passwordHash: (string) $row['password_hash'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );

        if ($master->getRole() !== self::JUDGE_ROLE) {
            throw ModerationWorkflowException::insufficientRankToJudge();
        }

        return $master;
    }

    /** Retrata el expediente recién transicionado. */
    private function reviewDtoOf(string $spellId): SpellReviewDto
    {
        $review = $this->reviewRepository->findBySpellId($spellId);
        if ($review === null) {
            throw new RuntimeException('La deliberación consumada no dejó expediente: el santuario está corrupto.');
        }

        return SpellReviewDto::fromDatabaseRow($review);
    }

    /** Glosa normalizada: el silencio del Maestro no es una glosa. */
    private static function normalizeGloss(?string $ceremonialGloss): ?string
    {
        if ($ceremonialGloss === null) {
            return null;
        }

        $gloss = trim($ceremonialGloss);

        return $gloss === '' ? null : $gloss;
    }

    /** Normaliza el instante a UTC; si el llamante no lo inyecta, se lee el reloj. */
    private static function normalizeInstant(?DateTimeImmutable $now): DateTimeImmutable
    {
        return $now === null
            ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : $now->setTimezone(new DateTimeZone('UTC'));
    }

    /** Marca temporal ISO 8601 UTC, la notación canónica del santuario. */
    private static function stamp(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d\TH:i:s\Z');
    }

    /** Identificador único de firma o de dictamen, forjado con entropía nativa. */
    private function newIdentifier(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(6));
    }

    /**
     * Umbrales del Cónclave, listos para la interfaz (RF-02.2, RF-02.5).
     *
     * @return array{signaturesRequired: int, maxGlossLength: int, minObjectionLength: int, judgeRole: string}
     */
    public static function deliberationCanon(): array
    {
        return [
            'signaturesRequired'   => self::SIGNATURES_REQUIRED,
            'maxGlossLength'       => self::MAX_GLOSS_LENGTH,
            'minObjectionLength'   => self::MIN_OBJECTION_LENGTH,
            'judgeRole'            => self::JUDGE_ROLE,
        ];
    }
}
