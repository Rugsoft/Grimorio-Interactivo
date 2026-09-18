<?php

declare(strict_types=1);

/**
 * SovereignAdminService.php — Potestades soberanas del Administrador Supremo
 * (SPEC-08, Tarea 2.5).
 *
 * Cubre: RF-04.1 (Firma Soberana instantánea, EXCLUSIVAMENTE sobre obras ya
 * elevadas a deliberación), RF-04.2 (veto del propio linaje bajo el Artículo
 * III.2), RF-04.3 (rescate de una obra vetada: a `experimental` con 0/3 firmas,
 * o directo a `validated`), RF-04.4 (revocación y archivo póstumo de una obra
 * consagrada, con deducción retroactiva de los PDA de su linaje), RF-04.5
 * (Edicto Imperial obligatorio e inscripción automática e inmutable en la
 * Bitácora), y el Artículo II.3 (ninguna obra se valida sin su balance sellado:
 * los borradores privados quedan fuera de toda potestad).
 *
 * Cada acto es UN gesto atómico: abre transacción, TOMA EL BLOQUEO del
 * expediente (`findAndLockById`) y solo entonces juzga y escribe. Dentro de esa
 * misma transacción viajan el Decreto Imperial con su Edicto, la transición del
 * expediente —que arrastra el espejo de `spells`—, la anulación de los avales
 * que juzgaban la versión desterrada y la liquidación de la gloria. Nada de eso
 * puede observarse a medias: un decreto que no quedó inscrito no ha ocurrido
 * (RF-04.5).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP nativo y PDO; cero dependencias.
 *   - Artículo II: el maná sellado no se toca. Un borrador privado no se
 *     valida ni se inspecciona, jamás (spec §7.5).
 *   - Artículo III.2: el propio estandarte y la propia pluma quedan fuera del
 *     alcance de la potestad soberana. Sus obras van al juicio de tres Maestros
 *     independientes de clanes ajenos, como manda RF-04.2.
 *   - Artículo III.3: cada acto deja su edicto y el efecto de su edicto.
 *   - Artículo V (Dualidad): identificadores en inglés camelCase, narrativa y
 *     comentarios en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use DateTimeImmutable;
use DateTimeZone;
use Grimorio\Dto\DominionReversalDto;
use Grimorio\Dto\SpellReviewDto;
use Grimorio\Exceptions\LineageOathException;
use Grimorio\Repositories\LineageOathRepository;
use Grimorio\Exceptions\ModerationWorkflowException;
use Grimorio\Exceptions\SpellNotFoundException;
use Grimorio\Models\User;
use Grimorio\Repositories\ClanMemberRepository;
use Grimorio\Repositories\ImperialDecreeRepository;
use Grimorio\Repositories\MasterSignatureRepository;
use Grimorio\Repositories\SpellReviewRepository;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Cónclave Supremo: firma soberana, rescate, destierro y liquidación de gloria.
 */
final class SovereignAdminService
{
    /**
     * Rango con potestad soberana ORDINARIA (RF-04).
     *
     * El Maestro no decreta y el Administrador Supremo no firma por la vía
     * colegiada: cada potestad tiene su protocolo, y confundirlos permitiría
     * que un rango ejerciera las atribuciones del otro.
     */
    public const SOVEREIGN_ROLE = 'supremeAdmin';

    /** El oficio validador al que asciende el candidato (RF-05.2). */
    private const ROLE_MASTER = 'master';

    /** El rango del que solo se parte para el ascenso al oficio. */
    private const ROLE_EDITOR = 'editor';

    /** Destino canónico del rescate: devolver la obra a deliberación (RF-04.3). */
    public const RESCUE_TARGET_EXPERIMENTAL = SpellReviewRepository::STATUS_EXPERIMENTAL;

    /** Destino canónico del rescate: consagrarla de oficio (RF-04.3). */
    public const RESCUE_TARGET_VALIDATED = SpellReviewRepository::STATUS_VALIDATED;

    /** Los dos destinos que el rescate de oficio admite. */
    public const CANONICAL_RESCUE_TARGETS = [
        self::RESCUE_TARGET_EXPERIMENTAL,
        self::RESCUE_TARGET_VALIDATED,
    ];

    /** Longitud mínima del Edicto Imperial (RF-04.5). */
    public const MIN_IMPERIAL_DECREE_LENGTH = ImperialDecreeRepository::MIN_IMPERIAL_DECREE_LENGTH;

    /** Entidad objetivo de un decreto sobre una obra: el conjuro. */
    private const DECREE_TARGET_TYPE = 'spell';

    /** Entidad objetivo de la deducción de gloria: la hermandad que la pierde. */
    private const DEDUCTION_TARGET_TYPE = 'clan';

    /** Acto canónico de la bitácora que inscribe la deducción (Art. III.3). */
    private const DEDUCTION_AUDIT_ACTION = 'SOVEREIGN_POINTS_DEDUCTED';

    /** Conexión PDO al plano arcano. */
    private PDO $pdo;

    /** Expediente de moderación: autoridad del estado y del contador. */
    private SpellReviewRepository $reviewRepository;

    /** Censo de avales, su revocación y su motivo canónico. */
    private MasterSignatureRepository $signatureRepository;

    /** Decretos imperiales: el edicto y su memoria son un solo gesto (RF-04.5). */
    private ImperialDecreeRepository $decreeRepository;

    /** Historial de membresía: la autoridad del linaje vigente del soberano. */
    private ClanMemberRepository $memberRepository;

    /** Acreditación y deducción de la gloria de los linajes (SPEC-07). */
    private WeeklyDominionService $dominionService;

    /** Único canal hacia la Bitácora de Auditoría pública (SPEC-03). */
    private AuditService $auditService;

    /**
     * Consagra el servicio sobre el expediente, el libro de decretos y la gloria.
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->reviewRepository = new SpellReviewRepository($pdo);
        $this->signatureRepository = new MasterSignatureRepository($pdo);
        $this->memberRepository = new ClanMemberRepository($pdo);
        $this->auditService = new AuditService($pdo);
        $this->decreeRepository = new ImperialDecreeRepository($pdo, $this->auditService);
        $this->dominionService = new WeeklyDominionService($pdo, $this->auditService);
    }

    /**
     * FIRMA SOBERANA INSTANTÁNEA (RF-04.1): eleva de oficio una obra en
     * deliberación al Gran Tomo Canónico, acreditando su gloria al linaje
     * originario.
     *
     * El contador de firmas NO se inventa: se conserva el que la obra tenía.
     * Que la obra fuese avalada por dos Maestros y sellada por el soberano es un
     * hecho, y la Bitácora debe poder contarlo tal cual; escribirlo como tres
     * avales sería inscribir una mentira en el registro público.
     *
     * @param string                 $spellId           Obra en deliberación.
     * @param string                 $adminId           Administrador Supremo.
     * @param string                 $imperialDecreeText Edicto de justificación.
     * @param DateTimeImmutable|null $now               Instante del decreto.
     *
     * @throws ModerationWorkflowException Si la obra no está en deliberación, media veto o el edicto es breve.
     * @throws SpellNotFoundException      Si la obra no tiene expediente.
     * @throws Throwable                   Si la escritura atómica fracasa.
     */
    public function executeSovereignValidation(
        string $spellId,
        string $adminId,
        string $imperialDecreeText,
        ?DateTimeImmutable $now = null,
    ): SpellReviewDto {
        $admin = $this->loadSovereign($adminId);
        $instant = self::normalizeInstant($now);
        $timestamp = self::stamp($instant);

        $this->pdo->beginTransaction();

        try {
            $review = $this->lockReviewOrFail($spellId);
            $this->assertInStatus($review, SpellReviewRepository::STATUS_EXPERIMENTAL, 'validate');
            $this->assertSovereignMayActOn($admin, $review);
            $this->assertImperialDecree($imperialDecreeText);

            $this->decreeRepository->insertDecree(
                $this->newIdentifier('dec'),
                $spellId,
                $admin->getId(),
                ImperialDecreeRepository::TYPE_SOVEREIGN_VALIDATION,
                $imperialDecreeText,
                $timestamp,
            );

            $this->reviewRepository->updateStatus($spellId, SpellReviewRepository::STATUS_VALIDATED, $timestamp);

            // La gloria de RF-05.3 se acredita SOLO al alcanzar `validated`, y
            // va al linaje bajo cuyo estandarte se forjó la obra.
            $this->dominionService->awardValidatedSpell($spellId, $instant);

            $this->pdo->commit();
        } catch (Throwable $failure) {
            $this->rollBackIfNeeded();

            throw $failure;
        }

        return $this->reviewDtoOf($spellId);
    }

    /**
     * RESCATE DE OFICIO (RF-04.3): interviene sobre una obra vetada y la
     * restituye a la deliberación con CERO firmas, o la consagra directamente.
     *
     * La vuelta a `experimental` reinicia el contador a 0/3 para una evaluación
     * colegiada limpia e imparcial: ninguna firma puede sobrevivir a la versión
     * que el Cónclave declaró inadmisible, de modo que se anulan —si alguna
     * quedara viva— con el motivo canónico de la obra rechazada.
     *
     * @param string                 $targetStatus       Destino canónico del rescate.
     * @param string                 $imperialDecreeText Edicto de justificación.
     *
     * @throws ModerationWorkflowException Si el destino no es canónico, la obra no está vetada, media veto o el edicto es breve.
     * @throws SpellNotFoundException      Si la obra no tiene expediente.
     * @throws Throwable                   Si la escritura atómica fracasa.
     */
    public function executeSovereignRescue(
        string $spellId,
        string $adminId,
        string $targetStatus,
        string $imperialDecreeText,
        ?DateTimeImmutable $now = null,
    ): SpellReviewDto {
        $admin = $this->loadSovereign($adminId);
        $target = trim($targetStatus);
        if (!in_array($target, self::CANONICAL_RESCUE_TARGETS, true)) {
            throw ModerationWorkflowException::sovereignRescueInvalidTarget($targetStatus);
        }

        $instant = self::normalizeInstant($now);
        $timestamp = self::stamp($instant);

        $this->pdo->beginTransaction();

        try {
            $review = $this->lockReviewOrFail($spellId);
            $this->assertInStatus($review, SpellReviewRepository::STATUS_REJECTED, 'rescue');
            $this->assertSovereignMayActOn($admin, $review);
            $this->assertImperialDecree($imperialDecreeText);

            $this->decreeRepository->insertDecree(
                $this->newIdentifier('dec'),
                $spellId,
                $admin->getId(),
                $target === self::RESCUE_TARGET_EXPERIMENTAL
                    ? ImperialDecreeRepository::TYPE_RESCUE_TO_EXPERIMENTAL
                    : ImperialDecreeRepository::TYPE_RESCUE_TO_VALIDATED,
                $imperialDecreeText,
                $timestamp,
            );

            // El expediente transiciona PRIMERO —y arrastra el espejo de
            // `spells`—: la acreditación de gloria lee el estado del conjuro y
            // solo paga lo que ya está sellado (RF-05.3).
            $this->reviewRepository->updateStatus($spellId, $target, $timestamp);

            if ($target === self::RESCUE_TARGET_EXPERIMENTAL) {
                $this->refreshSignaturesAfterRejection($spellId, $timestamp);
            } else {
                // Consagración de oficio: la obra entra al Tomo y su linaje
                // cobra la gloria que el veto le había negado.
                $this->dominionService->awardValidatedSpell($spellId, $instant);
            }

            $this->pdo->commit();
        } catch (Throwable $failure) {
            $this->rollBackIfNeeded();

            throw $failure;
        }

        return $this->reviewDtoOf($spellId);
    }

    /**
     * REVOCACIÓN Y ARCHIVO PÓSTUMO (RF-04.4): destierra una obra ya consagrada
     * ante un fraude manifiesto, anula los avales que la juzgaron y, si así se
     * ordena, deduce retroactivamente del linaje originario la gloria que la
     * obra le había otorgado.
     *
     * Los avales caen con el motivo canónico `sovereign_archive`: juzgaron una
     * versión que el Cónclave Supremo declara fraudulenta, y ningún aval
     * sobrevive a la versión declarada inadmisible. La deducción se inscribe en
     * la Bitácora con su aritmética exacta —cuánto se descontó, de qué contador
     * y de qué hermandad—, de modo que el efecto del veredicto sea tan inmortal
     * como el veredicto mismo (Artículo III.3).
     *
     * @param bool $deductPoints ¿Se deduce retroactivamente la gloria del linaje?
     *
     * @throws ModerationWorkflowException Si la obra no está consagrada, media veto o el edicto es breve.
     * @throws SpellNotFoundException      Si la obra no tiene expediente.
     * @throws Throwable                   Si la escritura atómica fracasa.
     */
    public function executeSovereignArchive(
        string $spellId,
        string $adminId,
        string $imperialDecreeText,
        bool $deductPoints = false,
        ?DateTimeImmutable $now = null,
    ): SpellReviewDto {
        $admin = $this->loadSovereign($adminId);
        $instant = self::normalizeInstant($now);
        $timestamp = self::stamp($instant);

        $this->pdo->beginTransaction();

        try {
            $review = $this->lockReviewOrFail($spellId);
            $this->assertInStatus($review, SpellReviewRepository::STATUS_VALIDATED, 'archive');
            $this->assertSovereignMayActOn($admin, $review);
            $this->assertImperialDecree($imperialDecreeText);

            $this->decreeRepository->insertDecree(
                $this->newIdentifier('dec'),
                $spellId,
                $admin->getId(),
                ImperialDecreeRepository::TYPE_REVOKE_AND_ARCHIVE,
                $imperialDecreeText,
                $timestamp,
            );

            // Los avales que juzgaron la versión fraudulenta caen de oficio.
            $this->signatureRepository->revokeActiveSignaturesForSpell(
                $spellId,
                MasterSignatureRepository::REVOCATION_SOVEREIGN_ARCHIVE,
                $timestamp,
            );
            $this->reviewRepository->updateSignaturesCount(
                $spellId,
                $this->signatureRepository->countActiveSignatures($spellId),
            );
            $this->reviewRepository->updateStatus($spellId, SpellReviewRepository::STATUS_ARCHIVED, $timestamp);

            if ($deductPoints) {
                $this->deductOriginGlory($spellId, $admin, $instant);
            }

            $this->pdo->commit();
        } catch (Throwable $failure) {
            $this->rollBackIfNeeded();

            throw $failure;
        }

        return $this->reviewDtoOf($spellId);
    }

    /**
     * Deduce la gloria del linaje originario y la inscribe en la Bitácora.
     *
     * Un conjuro que nunca pagó gloria —porque el linaje fue disuelto antes de
     * su consagración o porque jamás se acreditó nada— no deja memoria alguna:
     * no ocurrió nada que contar, y la deducción lo declara con su motivo.
     */
    private function deductOriginGlory(string $spellId, User $admin, DateTimeImmutable $instant): void
    {
        $reversal = $this->dominionService->revokeValidatedSpellGlory($spellId, $instant);

        if (!$reversal->wasRevoked()) {
            return;
        }

        $this->auditService->recordAction(
            actorUserId: $admin->getId(),
            actorAlias: $admin->getAlias(),
            actorRole: $admin->getRole(),
            actionType: self::DEDUCTION_AUDIT_ACTION,
            targetEntityType: self::DEDUCTION_TARGET_TYPE,
            targetEntityId: (string) $reversal->clanId,
            justification: $this->deductionJustification($spellId, $reversal),
            now: $instant,
        );
    }

    /**
     * Justificación solemne de la deducción, con su aritmética completa
     * (Artículo III.3): la Bitácora ha de poder leerse sin la base.
     */
    private function deductionJustification(string $spellId, DominionReversalDto $reversal): string
    {
        $justification = "Deducción retroactiva de gloria por el destierro del conjuro «{$spellId}»: "
            . "se descuentan {$reversal->revokedPoints} PDA al linaje «{$reversal->clanId}» "
            . "({$reversal->weeklyDebited} del marcador semanal y {$reversal->historicalDebited} del haber perpetuo).";

        if ($reversal->hadOutstanding()) {
            $justification .= " Quedaron {$reversal->outstandingPoints} PDA sin cubrir: ningún contador "
                . 'de la casa alcanzaba a responder de ellos, y la gloria jamás deja a un linaje en números rojos.';
        }

        return $justification;
    }

    /**
     * Devuelve una obra vetada a una deliberación limpia (RF-04.3, RF-02.6).
     *
     * Ninguna firma sobrevive a la versión declarada inadmisible, y el contador
     * se CUENTA desde las firmas vivas —que tras el veto son ninguna— en vez de
     * fijarse a ciegas en cero: así el contador no puede divergir del censo.
     */
    private function refreshSignaturesAfterRejection(string $spellId, string $timestamp): void
    {
        $this->signatureRepository->revokeActiveSignaturesForSpell(
            $spellId,
            MasterSignatureRepository::REVOCATION_REVIEW_REJECTED,
            $timestamp,
        );
        $this->reviewRepository->updateSignaturesCount(
            $spellId,
            $this->signatureRepository->countActiveSignatures($spellId),
        );
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
     * Exige el estado que la potestad reclama, con el veredicto propio de cada
     * acto soberano.
     *
     * El borrador privado se nombra sin retratar su contenido: la libreta del
     * autor es inviolable incluso para el Cónclave Supremo (Art. II.3, spec
     * §7.5).
     *
     * @param array<string, mixed> $review
     * @param string               $act uno de 'validate', 'rescue' o 'archive'
     *
     * @throws ModerationWorkflowException Si el estado no es el requerido.
     */
    private function assertInStatus(array $review, string $expectedStatus, string $act): void
    {
        $status = (string) ($review['status'] ?? '');
        if ($status === $expectedStatus) {
            return;
        }

        throw match ($act) {
            'validate' => ModerationWorkflowException::cannotSovereignValidateNonExperimental($status),
            'rescue'   => ModerationWorkflowException::cannotSovereignRescueNonRejected($status),
            default    => ModerationWorkflowException::cannotSovereignArchiveNonValidated($status),
        };
    }

    /**
     * Veto constitucional del Artículo III sobre la potestad soberana.
     *
     * Se compone de DOS prohibiciones absolutas, y ambas alcanzan los tres
     * actos sin excepción:
     *
     *   1. La PLUMA PROPIA (RF-03.2): quien ha escrito la obra no puede además
     *      juzgarla, «independientemente de que ostente el rango de `master` o
     *      `supremeAdmin`». El Administrador que además es autor dispone de su
     *      propia vía para recuperar una obra vetada —la re-apertura como
     *      borrador—, de modo que el veto no le deja sin salida.
     *   2. El PROPIO ESTANDARTE (RF-04.2): las obras forjadas bajo el linaje del
     *      Administrador «deberán someterse obligatoriamente al juicio imparcial
     *      de 3 Maestros independientes de clanes ajenos». El veto alcanza
     *      también el rescate y el destierro, porque la potestad es discrecional
     *      y su OMISIÓN también favorece a la propia casa: archivar la obra de
     *      un rival y perdonar la de la propia hermandad es exactamente el abuso
     *      que el artículo previene.
     *
     * El linaje se lee del HISTORIAL DE MEMBRESÍA (`clan_members`), no del
     * espejo `users.clan_id`: la autoridad de la afiliación es una sola.
     *
     * @param array<string, mixed> $review
     *
     * @throws ModerationWorkflowException Si media la pluma propia o el propio estandarte.
     */
    private function assertSovereignMayActOn(User $admin, array $review): void
    {
        if ((string) ($review['author_id'] ?? '') === $admin->getId()) {
            throw ModerationWorkflowException::selfValidationProhibited();
        }

        $adminClanId = $this->activeClanIdOf($admin->getId());
        $originClanId = $review['origin_clan_id'] === null ? null : (string) $review['origin_clan_id'];

        if ($adminClanId !== null && $originClanId !== null && $adminClanId === $originClanId) {
            throw ModerationWorkflowException::sovereignOwnClanVeto();
        }
    }

    /**
     * Linaje VIGENTE de un mago, o null si es ermitaño.
     *
     * La autoridad es el historial de membresía: un Administrador en
     * convalecencia arcana conserva su rango técnico, pero su afiliación vive
     * donde siempre vivió (SPEC-07, Tarea 2.6).
     */
    private function activeClanIdOf(string $userId): ?string
    {
        $membership = $this->memberRepository->findActiveMembership($userId);
        if ($membership === null) {
            return null;
        }

        $clanId = (string) ($membership['clan_id'] ?? '');

        return $clanId === '' ? null : $clanId;
    }

    /**
     * Vela por la solemnidad del Edicto Imperial (RF-04.5).
     *
     * Se mide sobre el texto recortado de espacios extremos y en caracteres
     * —no en bytes— porque el edicto se redacta en castellano. El mismo umbral
     * de veinte caracteres que rige el Dictamen de Objeción y el propio
     * repositorio de decretos: aquí se comprueba ANTES de tocar la base, para
     * responder con el veredicto del dominio en vez de con la excepción de
     * argumento del repositorio.
     *
     * @throws ModerationWorkflowException Si el edicto no alcanza el umbral.
     */
    private function assertImperialDecree(string $imperialDecreeText): void
    {
        $edictLength = mb_strlen(trim($imperialDecreeText), 'UTF-8');
        if ($edictLength < self::MIN_IMPERIAL_DECREE_LENGTH) {
            throw ModerationWorkflowException::imperialDecreeTooShort(
                self::MIN_IMPERIAL_DECREE_LENGTH,
                $edictLength,
            );
        }
    }

    /**
     * Carga la entidad del Administrador Supremo y exige su rango (RF-04).
     *
     * @throws InvalidArgumentException    Si el usuario no consta en los anales.
     * @throws ModerationWorkflowException Si el rango no alcanza para decretar.
     */
    private function loadSovereign(string $adminId): User
    {
        $adminId = trim($adminId);
        if ($adminId === '') {
            throw new InvalidArgumentException('Todo decreto imperial exige conocer la identidad del Administrador Supremo.');
        }

        $statement = $this->pdo->prepare(
            'SELECT id, alias, email, role, clan_id, password_hash, created_at, updated_at
               FROM users WHERE id = :userId'
        );
        $statement->execute([':userId' => $adminId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new InvalidArgumentException('Solo un Administrador Supremo inscrito en los anales puede decretar.');
        }

        $admin = new User(
            id: (string) $row['id'],
            alias: (string) $row['alias'],
            email: (string) $row['email'],
            role: (string) $row['role'],
            clanId: $row['clan_id'] === null ? null : (string) $row['clan_id'],
            passwordHash: (string) $row['password_hash'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );

        if ($admin->getRole() !== self::SOVEREIGN_ROLE) {
            throw ModerationWorkflowException::insufficientSovereignRank();
        }

        return $admin;
    }

    /** Retrata el expediente recién transicionado. */
    private function reviewDtoOf(string $spellId): SpellReviewDto
    {
        $review = $this->reviewRepository->findBySpellId($spellId);
        if ($review === null) {
            throw new RuntimeException('El decreto consumado no dejó expediente: el santuario está corrupto.');
        }

        return SpellReviewDto::fromDatabaseRow($review);
    }

    /** Deshace el gesto entero si la transacción sigue viva. */
    private function rollBackIfNeeded(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
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

    /** Identificador único de decreto, forjado con entropía nativa. */
    private function newIdentifier(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(6));
    }

    /**
     * Canon soberano, listo para la interfaz (RF-04).
     *
     * @return array{
     *   sovereignRole: string,
     *   rescueTargets: list<string>,
     *   minImperialDecreeLength: int,
     *   decreeTargetType: string,
     *   deductionAuditAction: string
     * }
     */
    public static function sovereignCanon(): array
    {
        return [
            'sovereignRole'           => self::SOVEREIGN_ROLE,
            'rescueTargets'           => self::CANONICAL_RESCUE_TARGETS,
            'minImperialDecreeLength' => self::MIN_IMPERIAL_DECREE_LENGTH,
            'decreeTargetType'        => self::DECREE_TARGET_TYPE,
            'deductionAuditAction'    => self::DEDUCTION_AUDIT_ACTION,
        ];
    }

    /**
     * DESIGNACIÓN DE MAESTRO (SPEC-09, Tarea 2.4 — RF-05.2, Art. III).
     *
     * Eleva a un editor al oficio validador de Maestro. LA GUARDIA DE
     * LINAJE es ineludible y va primero: nadie asciende desde la ventana
     * sin linaje jurado, garantizando que el conflicto de intereses del
     * Artículo III tenga siempre un sujeto ético determinado (SPEC-09,
     * RF-05.2). El caso es imposible por construcción: la ceremonia
     * bloqueante del primer acceso retiene a todo peregrino antes de que
     * pueda hacerse visible y operar en el santuario.
     *
     * El acto queda asentado en la Bitácora como 'PROMOTE_MASTER'
     * (catálogo cerrado de SPEC-03), imborrable por los triggers del
     * esquema (Art. III.3: cada acto deja su edicto).
     *
     * @param string                 $adminId    Administrador Supremo actuante.
     * @param string                 $candidateId Cuenta candidata al oficio.
     * @param DateTimeImmutable|null $now        Instante del decreto.
     *
     * @throws LineageOathException    Si el candidato aún no ha jurado linaje (403 `MASTER_REQUIRES_LINEAGE`).
     * @throws InvalidArgumentException Si el soberano no existe o no lo es, o el candidato no existe.
     */
    public function promoteMaster(
        string $adminId,
        string $candidateId,
        ?DateTimeImmutable $now = null,
    ): User {
        $instant = self::normalizeInstant($now);

        // La potestad soberana se acredita antes de tocar a nadie: solo un
        // Admin Supremo inscrito designa Maestros.
        $admin = $this->loadSovereign($adminId);

        // El candidato debe existir y ser un editor real.
        $candidate = $this->loadCandidate($candidateId);

        // LA GUARDIA DE RF-05.2: el vínculo se lee de la base, jamás de una
        // afirmación del llamador. Un peregrino no puede ser sujeto ético
        // determinado del Artículo III: rechazo solemne sin mutación.
        $lineageRepository = new LineageOathRepository($this->pdo);
        if ($lineageRepository->findAccountLineage($candidate->getId()) === null) {
            throw LineageOathException::masterRequiresLineage($candidate->getAlias());
        }

        // El ascenso, en una sola escritura preparada.
        $statement = $this->pdo->prepare(
            'UPDATE users SET role = :newRole, updated_at = :now WHERE id = :userId'
        );
        $statement->execute([
            ':newRole' => self::ROLE_MASTER,
            ':now'     => self::stamp($instant),
            ':userId'  => $candidate->getId(),
        ]);

        // Art. III.3: el edicto y su efecto son un solo gesto.
        $this->auditService->recordAction(
            actorUserId: $admin->getId(),
            actorAlias: $admin->getAlias(),
            actorRole: $admin->getRole(),
            actionType: 'PROMOTE_MASTER',
            targetEntityType: 'user',
            targetEntityId: $candidate->getId(),
            justification: 'Designación de Maestro: «' . $candidate->getAlias() . '» porta linaje jurado y asciende al oficio validador.',
            now: $instant,
        );

        // La entidad se rematerializa desde la fila recién escrita: la
        // verdad vive en la base, no en el objeto.
        return $this->loadAnyUser($candidateId, self::ROLE_MASTER);
    }

    /** Carga una cuenta por id, acreditándola con el rol esperado. */
    private function loadAnyUser(string $userId, string $expectedRole): User
    {
        $statement = $this->pdo->prepare(
            'SELECT id, alias, email, role, clan_id, password_hash, created_at, updated_at
               FROM users WHERE id = :userId'
        );
        $statement->execute([':userId' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new InvalidArgumentException('La cuenta no figura en los anales del santuario.');
        }

        $user = new User(
            id: (string) $row['id'],
            alias: (string) $row['alias'],
            email: (string) $row['email'],
            role: (string) $row['role'],
            clanId: $row['clan_id'] === null ? null : (string) $row['clan_id'],
            passwordHash: (string) $row['password_hash'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );

        if ($user->getRole() !== $expectedRole) {
            throw new InvalidArgumentException('La cuenta no porta el rango que se le atribuye.');
        }

        return $user;
    }

    /** Carga y acredita al candidato a Maestro (editor vivo, jamás el soberano). */
    private function loadCandidate(string $candidateId): User
    {
        $candidateId = trim($candidateId);
        if ($candidateId === '') {
            throw new InvalidArgumentException('Toda designación exige conocer la identidad del candidato.');
        }

        $statement = $this->pdo->prepare(
            'SELECT id, alias, email, role, clan_id, password_hash, created_at, updated_at
               FROM users WHERE id = :userId'
        );
        $statement->execute([':userId' => $candidateId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new InvalidArgumentException('El candidato a Maestro no figura en los anales del santuario.');
        }

        $candidate = new User(
            id: (string) $row['id'],
            alias: (string) $row['alias'],
            email: (string) $row['email'],
            role: (string) $row['role'],
            clanId: $row['clan_id'] === null ? null : (string) $row['clan_id'],
            passwordHash: (string) $row['password_hash'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );

        if ($candidate->getRole() !== self::ROLE_EDITOR) {
            throw new InvalidArgumentException('Solo un editor puede ser elevado al oficio de Maestro.');
        }

        return $candidate;
    }
}
