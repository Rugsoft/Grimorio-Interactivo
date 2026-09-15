<?php

/**
 * ModerationWorkflowService.php — Ciclo de vida del conjuro y cupo de la Torre
 * de Moderación (SPEC-08, Tarea 2.3).
 *
 * Cubre: RF-01.1 (los cinco estados y sus transiciones legales), RF-01.2 (el
 * cupo infranqueable de tres obras en deliberación por autor, con la huella
 * matemática sellada al entrar), RF-01.3 (inmutabilidad de lo evaluado y
 * anulación irrevocable de los avales al retirar), RF-01.4 (re-apertura de una
 * obra vetada conservando el dictamen a la vista), RF-01.5 (liberación
 * inmediata del cupo) y RF-01.6 (caducidad por letargo de noventa días), con
 * RNF-04 como requisito no funcional de la cuota.
 *
 * Cada transición escribe el expediente `spell_reviews` —la AUTORIDAD del
 * estado— y este arrastra el espejo `spells` en la MISMA transacción; el cupo
 * es una muralla (contar y elevar dentro de un solo gesto, con cerrojo sobre
 * la fila del autor) y la publicación reutiliza el núcleo sin transacción de
 * `SpellManagementService`, de modo que existe un único coste publicado.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP nativo y PDO; cero dependencias.
 *   - Artículos II, III y IV: la huella sellada, la memoria pública y las
 *     leyendas solemnes en noble castellano.
 *   - Artículo V (Dualidad): identificadores en inglés camelCase, narrativa y
 *     comentarios en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use DateTimeImmutable;
use DateTimeZone;
use Grimorio\Dto\ObjectionVerdictDto;
use Grimorio\Dto\SpellReviewDto;
use Grimorio\Exceptions\ModerationWorkflowException;
use Grimorio\Exceptions\SpellImmutableException;
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
 * Gobierno del ciclo de vida de los conjuros y de la cuota de la Torre.
 */
final class ModerationWorkflowService
{
    /** Cupo infranqueable de obras en deliberación por autor (RF-01.2, RNF-04). */
    public const MAX_CONCURRENT_REVIEWS = 3;

    /** Letargo arcano: días naturales sin resonancia antes de la caducidad (RF-01.6). */
    public const STALE_REVIEW_DAYS = 90;

    /** Rangos consagrados que elevan obras a la Torre (RF-01.2). */
    public const CANONICAL_SUBMITTER_ROLES = ['editor', 'master', 'supremeAdmin'];

    /** Leyenda ceremonial de la caducidad (RF-01.6, Art. IV). */
    public const EXPIRY_LEGEND = 'Letargo Arcano por Falta de Resonancia Colegiada';

    /** Conexión PDO al plano arcano. */
    private PDO $pdo;

    /** Expediente de moderación: autoridad del estado y del contador (Tarea 1.2). */
    private SpellReviewRepository $reviewRepository;

    /** Censo de firmas vivas y su revocación (Tarea 1.3). */
    private MasterSignatureRepository $signatureRepository;

    /** Memoria de los dictámenes de objeción (Tarea 1.3). */
    private ObjectionVerdictRepository $verdictRepository;

    /** Historial de membresía: la convalecencia arcana (SPEC-07, Tarea 1.3). */
    private ClanMemberRepository $memberRepository;

    /** Publicación del borrador con su matemática revalidada (SPEC-04). */
    private SpellManagementService $spellManagementService;

    /** Único canal hacia la Bitácora de Auditoría pública (SPEC-03). */
    private AuditService $auditService;

    /**
     * Consagra el servicio sobre el expediente, el censo de firmas y la bitácora.
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->reviewRepository = new SpellReviewRepository($pdo);
        $this->signatureRepository = new MasterSignatureRepository($pdo);
        $this->verdictRepository = new ObjectionVerdictRepository($pdo);
        $this->memberRepository = new ClanMemberRepository($pdo);
        $this->spellManagementService = new SpellManagementService($pdo);
        $this->auditService = new AuditService($pdo);
    }

    /**
     * Obras que el autor puede elevar aún a la Torre antes de agotar su cupo.
     *
     * Es la lectura con la que la interfaz decide si mostrar el sello de
     * elevación, y la que el controlador usa para anticipar el 409 (RF-01.2).
     */
    public function remainingCapacity(string $userId): int
    {
        return max(0, self::MAX_CONCURRENT_REVIEWS - $this->reviewRepository->countActiveReviewsByAuthor($userId));
    }

    /** ¿Cabe una obra más en la Torre para este autor? (RF-01.2, RNF-04) */
    public function hasReviewCapacity(string $userId): bool
    {
        return $this->remainingCapacity($userId) > 0;
    }

    /**
     * Paso 1: eleva un borrador a `experimental` con su huella sellada, previo
     * control del cupo de tres obras en deliberación (RF-01.2).
     *
     * @param string                 $spellId Conjuro del autor.
     * @param string                 $userId  Autor que eleva la plegaria.
     * @param DateTimeImmutable|null $now     Instante de la elevación.
     *
     * @throws ModerationWorkflowException Si el rango no alcanza, media convalecencia, la obra no es borrador o el cupo está colmado.
     * @throws SpellNotFoundException      Si el conjuro no existe o no es del autor.
     * @throws Throwable                   Si la escritura atómica fracasa; la elevación se deshace entera.
     */
    public function submitToModeration(
        string $spellId,
        string $userId,
        ?DateTimeImmutable $now = null,
    ): SpellReviewDto {
        $author = $this->loadAuthor($userId);
        $instant = self::normalizeInstant($now);

        // RF-01.2: solo los magos consagrados elevan plegarias a la Torre.
        if (!in_array($author->getRole(), self::CANONICAL_SUBMITTER_ROLES, true)) {
            throw ModerationWorkflowException::insufficientRankToSubmit();
        }

        // Convalecencia arcana: catorce días de meditación sin pluma (plan 2.2).
        if ($this->memberRepository->isUserInConvalescence($userId, self::stamp($instant))) {
            throw ModerationWorkflowException::convalescenceActive();
        }

        $spell = $this->requireOwnedSpell($spellId, $userId);
        if ((string) ($spell['status'] ?? '') !== SpellReviewRepository::STATUS_DRAFT) {
            throw ModerationWorkflowException::notInDraftState();
        }

        $this->pdo->beginTransaction();

        try {
            // El cerrojo del cupo: contar y elevar dentro del MISMO gesto.
            $this->lockAuthorQuota($userId);

            if ($this->reviewRepository->countActiveReviewsByAuthor($userId) >= self::MAX_CONCURRENT_REVIEWS) {
                throw ModerationWorkflowException::towerCapacityExceeded(self::MAX_CONCURRENT_REVIEWS);
            }

            $this->spellManagementService->publishDraftWithinTransaction($author, $spellId, $instant);

            $this->auditService->recordAction(
                actorUserId: $userId,
                actorAlias: $author->getAlias(),
                actorRole: $author->getRole(),
                actionType: 'MODERATION_SUBMITTED',
                targetEntityType: 'spell',
                targetEntityId: $spellId,
                justification: 'Elevación de una obra a la Torre de Moderación: huella matemática sellada y firmas iniciadas en 0/3.',
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
     * RF-01.3: retira la obra a la libreta privada, anulando irrevocablemente
     * todos los avales acumulados (las firmas caen con el motivo `author_withdrawn`).
     *
     * @throws ModerationWorkflowException Si la obra no está en deliberación.
     * @throws SpellNotFoundException      Si el conjuro no existe o no es del autor.
     * @throws Throwable                   Si la escritura atómica fracasa.
     */
    public function withdrawToDraft(
        string $spellId,
        string $userId,
        ?DateTimeImmutable $now = null,
    ): SpellReviewDto {
        $author = $this->loadAuthor($userId);
        $instant = self::normalizeInstant($now);
        $spell = $this->requireOwnedSpell($spellId, $userId);

        $review = $this->reviewRepository->findBySpellId($spellId);
        $currentStatus = $review === null
            ? (string) ($spell['status'] ?? '')
            : (string) ($review['status'] ?? '');

        if ($currentStatus !== SpellReviewRepository::STATUS_EXPERIMENTAL) {
            throw ModerationWorkflowException::notUnderReview($currentStatus);
        }

        $timestamp = self::stamp($instant);

        $this->pdo->beginTransaction();

        try {
            // Los avales caen ANTES de que la obra abandone el Atrio: ninguna
            // firma puede sobrevivir a la versión que juzgaba (RF-01.3).
            $this->signatureRepository->revokeActiveSignaturesForSpell(
                $spellId,
                MasterSignatureRepository::REVOCATION_AUTHOR_WITHDRAWN,
                $timestamp,
            );
            $this->reviewRepository->updateSignaturesCount($spellId, 0);
            $this->reviewRepository->updateStatus($spellId, SpellReviewRepository::STATUS_DRAFT, $timestamp);

            $this->auditService->recordAction(
                actorUserId: $userId,
                actorAlias: $author->getAlias(),
                actorRole: $author->getRole(),
                actionType: 'MODERATION_WITHDRAWN',
                targetEntityType: 'spell',
                targetEntityId: $spellId,
                justification: 'Retirada voluntaria de la deliberación: la obra retorna a la libreta y sus avales previos quedan anulados.',
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
     * RF-01.4: reabre una obra vetada como borrador, habilitando la edición
     * completa y conservando a la vista el dictamen anterior para su
     * subsanación (el dictamen jamás se borra: RNF-01).
     *
     * @throws ModerationWorkflowException Si la obra no está vetada.
     * @throws SpellNotFoundException      Si el conjuro no existe o no es del autor.
     * @throws Throwable                   Si la escritura atómica fracasa.
     */
    public function reopenAsDraft(
        string $spellId,
        string $userId,
        ?DateTimeImmutable $now = null,
    ): SpellReviewDto {
        $author = $this->loadAuthor($userId);
        $instant = self::normalizeInstant($now);
        $this->requireOwnedSpell($spellId, $userId);

        $review = $this->reviewRepository->findBySpellId($spellId);
        $currentStatus = $review === null ? '' : (string) ($review['status'] ?? '');

        if ($currentStatus !== SpellReviewRepository::STATUS_REJECTED) {
            throw ModerationWorkflowException::notRejectedState($currentStatus);
        }

        $timestamp = self::stamp($instant);

        $this->pdo->beginTransaction();

        try {
            // El cupo se libera SOLO: `rejected` jamás lo consumió (RF-01.5),
            // y el expediente vuelve a la libreta con su marca de re-apertura.
            $this->reviewRepository->updateStatus($spellId, SpellReviewRepository::STATUS_DRAFT, $timestamp);

            $this->auditService->recordAction(
                actorUserId: $userId,
                actorAlias: $author->getAlias(),
                actorRole: $author->getRole(),
                actionType: 'MODERATION_REOPENED',
                targetEntityType: 'spell',
                targetEntityId: $spellId,
                justification: 'Re-apertura de una obra vetada como borrador: el dictamen anterior permanece a la vista para su subsanación.',
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
     * Caducidad por letargo (RF-01.6): toda obra en deliberación que acumule
     * noventa días naturales sin nueva resonancia de los Maestros pasa a
     * `rejected` bajo la leyenda del Letargo Arcano, liberando el cupo de su
     * autor y dejando el camino expedito para reabrirla a borrador.
     *
     * Los avales viejos caen con la obra —motivo `review_expired`—: un aval
     * solo vive sobre una obra en deliberación, y el contador del expediente
     * debe seguir contando firmas VIVAS.
     *
     * @return list<SpellReviewDto> Expedientes caducados por este barrido.
     *
     * @throws Throwable Si la escritura atómica de alguna obra fracasa.
     */
    public function checkExpiryCron(?DateTimeImmutable $now = null): array
    {
        $instant = self::normalizeInstant($now);
        $timestamp = self::stamp($instant);
        $expired = [];

        foreach ($this->reviewRepository->findStaleReviews(self::STALE_REVIEW_DAYS, $instant) as $staleReview) {
            $spellId = (string) ($staleReview['spell_id'] ?? '');
            $author = $this->loadAuthor((string) ($staleReview['author_id'] ?? ''));

            $this->pdo->beginTransaction();

            try {
                $this->signatureRepository->revokeActiveSignaturesForSpell(
                    $spellId,
                    MasterSignatureRepository::REVOCATION_REVIEW_EXPIRED,
                    $timestamp,
                );
                $this->reviewRepository->updateSignaturesCount($spellId, 0);
                $this->reviewRepository->updateStatus($spellId, SpellReviewRepository::STATUS_REJECTED, $timestamp);

                $this->auditService->recordAction(
                    actorUserId: $author->getId(),
                    actorAlias: $author->getAlias(),
                    actorRole: $author->getRole(),
                    actionType: 'MODERATION_EXPIRED',
                    targetEntityType: 'spell',
                    targetEntityId: $spellId,
                    justification: self::EXPIRY_LEGEND
                        . ': la obra aguardó noventa días naturales sin nueva resonancia colegiada y retorna a la libreta de su autor.',
                    now: $instant,
                );

                $this->pdo->commit();
            } catch (Throwable $failure) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $failure;
            }

            $expired[] = $this->reviewDtoOf($spellId);
        }

        return $expired;
    }

    /**
     * RF-01.3: ¿admite la obra una edición del autor? Solo el borrador privado.
     *
     * Lo evaluado no se toca: en deliberación la obra es inmutable, vetada
     * aguarda su re-apertura, y la consagración y el destierro son patrimonio
     * intangible. Este guardián existe para que la ruta de edición tenga un
     * ÚNICO veredicto que consultar, en lugar de repetir los cinco estados.
     *
     * @throws ModerationWorkflowException Si la obra está en deliberación o vetada.
     * @throws SpellImmutableException     Si la obra está consagrada o desterrada.
     * @throws SpellNotFoundException      Si el conjuro no existe.
     */
    public function assertSpellIsEditableInDraft(string $spellId): void
    {
        $statement = $this->pdo->prepare('SELECT status FROM spells WHERE id = :spellId');
        $statement->execute([':spellId' => $spellId]);
        $status = $statement->fetchColumn();

        if ($status === false) {
            throw new SpellNotFoundException('Ese conjuro no existe o no habita tu grimorio.');
        }

        match ((string) $status) {
            SpellReviewRepository::STATUS_DRAFT => null,
            SpellReviewRepository::STATUS_EXPERIMENTAL => throw ModerationWorkflowException::underReviewImmutable($spellId),
            SpellReviewRepository::STATUS_REJECTED => throw ModerationWorkflowException::awaitingReopen($spellId),
            SpellReviewRepository::STATUS_VALIDATED => throw SpellImmutableException::forValidatedSpell($spellId),
            SpellReviewRepository::STATUS_ARCHIVED => throw SpellImmutableException::forArchivedSpell($spellId),
            default => throw new InvalidArgumentException(
                'Estado fuera del canon del santuario: ' . (string) $status . '.'
            ),
        };
    }

    /**
     * Memoria del veto (RF-01.4 y RF-06.2): el último dictamen de objeción de
     * una obra, íntegro y con sus tildes, o `null` si nunca fue objetada.
     *
     * Es lo que la libreta del autor exhibe tras reabrir la obra, y por eso no
     * existe —ni existirá— un método que borre o enmiende ese texto.
     */
    public function objectionArchiveFor(string $spellId): ?ObjectionVerdictDto
    {
        $verdict = $this->verdictRepository->findLatestVerdictBySpell($spellId);

        return $verdict === null ? null : ObjectionVerdictDto::fromDatabaseRow($verdict);
    }

    /**
     * Lee el expediente recién transicionado y lo retrata.
     *
     * @throws RuntimeException Si la transición no dejó expediente (nunca ocurre
     *         tras un commit consumado; es una guarda contra la corrupción).
     */
    private function reviewDtoOf(string $spellId): SpellReviewDto
    {
        $review = $this->reviewRepository->findBySpellId($spellId);
        if ($review === null) {
            throw new RuntimeException('La transición consumada no dejó expediente: el santuario está corrupto.');
        }

        return SpellReviewDto::fromDatabaseRow($review);
    }

    /**
     * Carga la entidad del autor desde la base (rol, alias y linaje).
     *
     * El servicio recibe un identificador, pero el rango que autoriza el acto
     * se resuelve en `users`: la identidad nunca se recibe del llamante.
     *
     * @throws InvalidArgumentException Si el usuario no consta en los anales.
     */
    private function loadAuthor(string $userId): User
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new InvalidArgumentException('La moderación exige conocer la identidad del mago que actúa.');
        }

        $statement = $this->pdo->prepare(
            'SELECT id, alias, email, role, clan_id, password_hash, created_at, updated_at
               FROM users WHERE id = :userId'
        );
        $statement->execute([':userId' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new InvalidArgumentException(
                'Solo un mago consagrado en los anales puede elevar obras a la Torre de Moderación.'
            );
        }

        return new User(
            id: (string) $row['id'],
            alias: (string) $row['alias'],
            email: (string) $row['email'],
            role: (string) $row['role'],
            clanId: $row['clan_id'] === null ? null : (string) $row['clan_id'],
            passwordHash: (string) $row['password_hash'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }

    /**
     * Recupera el conjuro EXIGIENDO que pertenezca al invocante.
     *
     * Inexistente o ajeno se responden por igual: distinguirlos permitiría
     * sondear la autoría ajena desde fuera (Art. III).
     *
     * @return array<string, mixed> Fila del conjuro.
     *
     * @throws SpellNotFoundException Si el conjuro no existe o no es del autor.
     */
    private function requireOwnedSpell(string $spellId, string $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, status, clan_id FROM spells WHERE id = :spellId AND author_id = :authorId'
        );
        $statement->execute([':spellId' => $spellId, ':authorId' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new SpellNotFoundException('Ese conjuro no existe o no habita tu grimorio.');
        }

        return $row;
    }

    /**
     * Adquiere el cerrojo del cupo tocando la fila del autor.
     *
     * En SQLite el primer enunciado de ESCRITURA de una transacción adquiere el
     * bloqueo del motor, de modo que dos elevaciones simultáneas del mismo
     * autor se serializan aquí. El toque es idempotente a propósito —no altera
     * dato alguno— y sobre MySQL y PostgreSQL basta con esta misma escritura
     * para tomar el bloqueo de fila.
     */
    private function lockAuthorQuota(string $userId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET updated_at = updated_at WHERE id = :userId'
        );
        $statement->execute([':userId' => $userId]);
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

    /**
     * Umbrales y leyendas del ciclo de vida, listos para la interfaz.
     *
     * @return array{maxConcurrentReviews: int, staleReviewDays: int, expiryLegend: string, submitters: list<string>}
     */
    public static function workflowCanon(): array
    {
        return [
            'maxConcurrentReviews' => self::MAX_CONCURRENT_REVIEWS,
            'staleReviewDays'      => self::STALE_REVIEW_DAYS,
            'expiryLegend'         => self::EXPIRY_LEGEND,
            'submitters'           => self::CANONICAL_SUBMITTER_ROLES,
        ];
    }

    /**
     * Motivos de revocación vigentes, para que la interfaz no invente rótulos.
     *
     * @return list<string>
     */
    public static function revocationReasons(): array
    {
        return MasterSignatureRepository::CANONICAL_REVOCATION_REASONS;
    }
}
