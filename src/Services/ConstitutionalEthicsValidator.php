<?php

/**
 * ConstitutionalEthicsValidator.php — Veto ético constitucional y pluralidad
 * de hermandades sobre la moderación solemne (SPEC-08, Tarea 2.2).
 *
 * Cubre: RF-02.1 (una sola voz por estandarte), RF-03.1 (el Maestro no juzga
 * obras de su clan actual ni de los linajes que habitó en los últimos treinta
 * días naturales), RF-03.2 (prohibición absoluta de auto-firma para todos los
 * roles, incluido `supremeAdmin`), RF-03.3 (el bloqueo ceremonial en noble
 * castellano), RF-03.4 (anulación de oficio por conflicto sobrevenido),
 * RF-03.5 (anulación de oficio por pérdida del rango de Maestro en tránsito),
 * RF-03.6 (el Maestro en convalecencia arcana firma como ermitaño neutral) y
 * Artículo III íntegro.
 *
 * La autoridad del veto de linaje NO se duplica: se COMPONE. SPEC-07 ya
 * construyó `ClanEthicsValidator` sobre el historial de membresía, y repetir
 * aquí su aritmética crearía un segundo veredicto capaz de divergir del
 * primero —el defecto que este proyecto ya cerró dos veces—. El instante se
 * inyecta y cada anulación es un gesto atómico (RNF-01, RNF-02).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP nativo y PDO; cero dependencias.
 *   - Artículo III (Ética de Linajes): doble barrera, aquí en el backend.
 *   - Artículo IV (El Velo Arcano): los motivos del bloqueo se pronuncian en
 *     noble castellano, listos para la interfaz y para la bitácora.
 *   - Artículo V (Dualidad): identificadores en inglés camelCase, narrativa y
 *     comentarios en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use DateTimeImmutable;
use DateTimeZone;
use Grimorio\Repositories\ClanMemberRepository;
use Grimorio\Repositories\MasterSignatureRepository;
use Grimorio\Repositories\SpellReviewRepository;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Guardián del Artículo III para la moderación solemne en dos pasos.
 *
 * La autoridad del veto de linaje no se reimplementa: se COMPONE. SPEC-07 ya
 * construyó `ClanEthicsValidator` sobre el HISTORIAL DE MEMBRESÍA
 * (`clan_members`) y su ventana de treinta días está probada; repetir aquí esa
 * aritmética crearía un segundo veredicto capaz de divergir del primero. Este
 * validador compone aquella autoridad —igual que hizo `ClanConflictService` en
 * SPEC-03— y añade lo que es genuinamente suyo: la prohibición de auto-firma
 * (RF-03.2), la pluralidad de hermandades (RF-02.1) y la anulación de oficio
 * con su memoria en la Bitácora (RF-03.4, RF-03.5).
 *
 * Determinismo y memoria (RNF-01, RNF-02): el instante se inyecta, de modo que
 * el veredicto es reproducible y auditable; cada anulación es un gesto
 * ATÓMICO —firma revocada, contador del expediente recalculado desde las
 * firmas VIVAS y entrada en la Bitácora—, y el contador se cuenta desde las
 * firmas mismas en lugar de restarlo del valor almacenado, de modo que una
 * anulación SANA un espejo divergente en vez de heredar su error. La
 * operación es idempotente: revocar una firma ya revocada no reescribe su
 * motivo original.
 */
final class ConstitutionalEthicsValidator
{
    /** Rango con potestad judicial de firma. */
    public const ROLE_MASTER = 'master';

    /** La potestad suprema también juzga, pero jamás su propia pluma. */
    public const ROLE_SUPREME_ADMIN = 'supremeAdmin';

    /** Rangos que conservan potestad judicial (RF-03.5). */
    public const CANONICAL_JUDGE_ROLES = [
        self::ROLE_MASTER,
        self::ROLE_SUPREME_ADMIN,
    ];

    /** El Maestro pretende juzgar una obra de su propia pluma (RF-03.2). */
    public const VETO_OWN_AUTHORSHIP = 'ownAuthorship';

    /** El Maestro comparte —o compartió hace menos de treinta días— el linaje de la obra (RF-03.1). */
    public const VETO_CLAN_INCOMPATIBILITY = 'clanIncompatibility';

    /** Bloqueo ceremonial prescrito por la especificación (RF-03.3, Art. IV). */
    public const CONFLICT_OF_INTEREST_MESSAGE = 'Conflicto de intereses: No es lícito a un Maestro juzgar las obras nacidas bajo su propio estandarte, linajes recientes o propia pluma';

    /** Bloqueo ceremonial de la pluralidad de hermandades (RF-02.1). */
    public const PLURALITY_CONFLICT_MESSAGE = 'Pluralidad de hermandades: dos Maestros de un mismo linaje no pueden avalar la misma obra; cada estandarte aporta una sola voz ante la Torre';

    /** Conexión PDO al plano arcano. */
    private PDO $pdo;

    /** Autoridad única del veto de linaje (SPEC-07, Tarea 2.3). */
    private ClanEthicsValidator $clanEthicsValidator;

    /** Censo de las firmas vivas y su revocación (SPEC-08, Tarea 1.3). */
    private MasterSignatureRepository $signatureRepository;

    /** Expediente de la obra: su estado y su contador espejo (SPEC-08, Tarea 1.2). */
    private SpellReviewRepository $reviewRepository;

    /** Único canal hacia la Bitácora de Auditoría pública (SPEC-03, Tarea 1.4). */
    private AuditService $auditService;

    /**
     * Consagra el validador sobre el historial de membresía, el censo de firmas
     * y la bitácora del santuario.
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->clanEthicsValidator = new ClanEthicsValidator(new ClanMemberRepository($pdo));
        $this->signatureRepository = new MasterSignatureRepository($pdo);
        $this->reviewRepository = new SpellReviewRepository($pdo);
        $this->auditService = new AuditService($pdo);
    }

    /**
     * ¿Puede el Maestro deliberar o firmar una obra de este linaje y autoría?
     * (RF-03.1 y RF-03.2)
     *
     * @param string                 $masterId      Maestro de la Torre.
     * @param string|null            $originClanId  Linaje patrimonial de la obra; `null` si es de un ermitaño.
     * @param string                 $authorId      Autor de la obra.
     * @param DateTimeImmutable|null $now           Instante de evaluación.
     *
     * @throws InvalidArgumentException Si el identificador del Maestro está vacío.
     */
    public function canMasterEvaluateSpell(
        string $masterId,
        ?string $originClanId,
        string $authorId,
        ?DateTimeImmutable $now = null,
    ): bool {
        return $this->evaluationVetoCode($masterId, $originClanId, $authorId, $now) === null;
    }

    /**
     * Código canónico del veto, o `null` si el juicio del Maestro es lícito.
     *
     * Expone el veredicto como DATO —no como excepción— para que la interfaz
     * deshabilite el sello de firma y la capa que responde 403 compartan un
     * mismo juicio sin capturar excepciones (Art. III).
     *
     * @throws InvalidArgumentException Si el identificador del Maestro está vacío.
     */
    public function evaluationVetoCode(
        string $masterId,
        ?string $originClanId,
        string $authorId,
        ?DateTimeImmutable $now = null,
    ): ?string {
        $masterId = trim($masterId);
        if ($masterId === '') {
            throw new InvalidArgumentException('El veto ético exige conocer la identidad del Maestro de la Torre.');
        }

        // RF-03.2: la propia pluma jamás se juzga, ni con la potestad suprema.
        // Se comprueba PRIMERO, porque es una prohibición absoluta que ningún
        // linaje compartido puede excusar.
        if (trim($authorId) !== '' && $masterId === trim($authorId)) {
            return self::VETO_OWN_AUTHORSHIP;
        }

        // RF-03.1: linaje actual y linajes habitados en los últimos treinta
        // días naturales. La autoridad y la ventana viven en ClanEthicsValidator.
        if ($this->clanEthicsValidator->vetoReasonFor($masterId, $originClanId, $now) !== null) {
            return self::VETO_CLAN_INCOMPATIBILITY;
        }

        return null;
    }

    /**
     * Bloqueo ceremonial del veto, en noble castellano (RF-03.3), o `null` si
     * nada impide el juicio.
     */
    public function evaluationVetoReason(
        string $masterId,
        ?string $originClanId,
        string $authorId,
        ?DateTimeImmutable $now = null,
    ): ?string {
        return $this->evaluationVetoCode($masterId, $originClanId, $authorId, $now) === null
            ? null
            : self::CONFLICT_OF_INTEREST_MESSAGE;
    }

    /**
     * ¿Respeta el candidato la pluralidad de hermandades? (RF-02.1)
     *
     * Ningún linaje aporta dos voces sobre una misma obra: si algún aval VIVO
     * procede del mismo clan que el candidato, el juicio se rechaza. Los
     * ermitaños quedan fuera del veto —su neutralidad no genera conflicto de
     * hermandad—, de modo que dos o tres Maestros sin estandarte sí pueden
     * avalar la misma obra (caso límite 3 de la especificación).
     *
     * Las firmas revocadas que aún figuren en el censo se ignoran: el aval
     * caído no ocupa plaza de hermandad.
     *
     * @param list<array<string, null|int|string>> $activeSignatures Firmas de la obra.
     * @param string|null                          $incomingMasterClanId Linaje del candidato; `null` si es ermitaño.
     */
    public function validateClanPlurality(array $activeSignatures, ?string $incomingMasterClanId): bool
    {
        $incomingClanId = self::normalizeClanId($incomingMasterClanId);
        if ($incomingClanId === null) {
            // Ermitaño neutral: no hay hermandad que pueda duplicarse.
            return true;
        }

        foreach ($activeSignatures as $signature) {
            if ((int) ($signature['is_revoked'] ?? 0) === 1) {
                continue;
            }

            $incumbentClanId = self::normalizeClanId($signature['master_clan_id'] ?? null);
            if ($incumbentClanId !== null && $incumbentClanId === $incomingClanId) {
                return false;
            }
        }

        return true;
    }

    /**
     * Linaje con el que se ha de ESTAMPAR la firma de un Maestro (RF-03.6).
     *
     * El Maestro en convalecencia arcana conserva su potestad judicial, pero
     * firma como Maestro independiente ERMITAÑO: su firma no ocupa plaza de
     * hermandad alguna, y el veto de treinta días respecto a su linaje previo
     * sigue en pleno vigor por la vía de `ClanEthicsValidator`.
     */
    public function signatureClanIdFor(?string $activeClanId, bool $isInArcaneConvalescence): ?string
    {
        return $isInArcaneConvalescence ? null : self::normalizeClanId($activeClanId);
    }

    /**
     * Anula de oficio las firmas vivas de un Maestro que ha mudado de linaje o
     * ha perdido su rango antes de la tercera rúbrica (RF-03.4 y RF-03.5).
     *
     * Solo cae lo que aún puede caer: las firmas sobre obras en `experimental`.
     * Una obra ya consagrada es irrevocable (RF-02.7), y una retirada a la
     * libreta no necesita anulación —sus avales ya fueron revocados con el
     * motivo `author_withdrawn`—. La identidad del actuante —alias público y
     * rol técnico— se resuelve en `users` dentro de la misma transacción, para
     * que la bitácora no pueda ser retratada con datos de presentación
     * falseables por el llamante.
     *
     * El llamante debe haber INSCRITO antes el cambio en `users` (el nuevo
     * rango o la nueva afiliación) cuando quiera que la bitácora lo retrate;
     * el veredicto, en cambio, se dicta con lo que trae el evento.
     *
     * @param string                 $userId    Maestro cuya situación ha cambiado.
     * @param string|null            $newClanId Nueva afiliación; `null` si no mudó de linaje.
     * @param string|null            $newRole   Nuevo rango; `null` si no perdió el suyo.
     * @param DateTimeImmutable|null $now       Instante de la anulación.
     *
     * @throws InvalidArgumentException Si el usuario es desconocido.
     * @throws Throwable                Si la escritura atómica fracasa; el gesto se deshace.
     */
    public function revokeConflictedSignatures(
        string $userId,
        ?string $newClanId = null,
        ?string $newRole = null,
        ?DateTimeImmutable $now = null,
    ): SignatureAnnulmentResult {
        $userId = trim($userId);
        if ($userId === '') {
            throw new InvalidArgumentException('La anulación de oficio exige conocer al Maestro cuya situación ha cambiado.');
        }

        $instant = $now === null
            ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : $now->setTimezone(new DateTimeZone('UTC'));
        $timestampUtc = $instant->format('Y-m-d\TH:i:s\Z');

        $annulments = [];
        foreach ($this->signatureRepository->findActiveSignaturesByMaster($userId) as $signature) {
            $spellId = (string) ($signature['spell_id'] ?? '');
            $review = $this->reviewRepository->findBySpellId($spellId);

            // Solo se anula sobre obras aún en deliberación (RF-02.7).
            if ($review === null || (string) ($review['status'] ?? '') !== SpellReviewRepository::STATUS_EXPERIMENTAL) {
                continue;
            }

            $reason = $this->annulmentReasonFor($review, $newClanId, $newRole);
            if ($reason === null) {
                continue;
            }

            $annulments[] = $this->annulSignature(
                signatureId: (string) ($signature['id'] ?? ''),
                spellId: $spellId,
                authorId: (string) ($review['author_id'] ?? ''),
                masterId: $userId,
                reason: $reason,
                timestampUtc: $timestampUtc,
            );
        }

        return new SignatureAnnulmentResult($annulments);
    }

    /**
     * Motivo canónico de la anulación, o `null` si ninguna causa concurre.
     *
     * El rango se juzga PRIMERO: un Maestro degradado arrastra toda su obra, y
     * el motivo que la bitácora debe conservar es la pérdida del rango y no un
     * conflicto de hermandad que quizá ni exista.
     *
     * @param array<string, null|int|string> $review    Expediente de la obra.
     * @param string|null                    $newClanId Nueva afiliación del Maestro.
     * @param string|null                    $newRole   Nuevo rango del Maestro.
     */
    private function annulmentReasonFor(array $review, ?string $newClanId, ?string $newRole): ?string
    {
        if ($newRole !== null && !in_array(trim($newRole), self::CANONICAL_JUDGE_ROLES, true)) {
            return MasterSignatureRepository::REVOCATION_RANK_LOST;
        }

        $declaredClanId = self::normalizeClanId($newClanId);
        $originClanId = self::normalizeClanId($review['origin_clan_id'] ?? null);

        // El conflicto sobreviene cuando el Maestro entra en el linaje de la
        // obra que ya había avalado. Un conjuro de ermitaño no tiene estandarte
        // con el que chocar.
        if ($declaredClanId !== null && $originClanId !== null && $declaredClanId === $originClanId) {
            return MasterSignatureRepository::REVOCATION_CLAN_CONFLICT_ARISEN;
        }

        return null;
    }

    /**
     * Ejecuta la anulación de UNA firma como gesto atómico: cae el aval, se
     * recalcula el contador del expediente y se inscribe la memoria pública.
     *
     * El orden no es accidental: primero la FIRMA —la autoridad del contador—,
     * después el ESPEJO del expediente, y por último la bitácora; si esta
     * última fracasa, todo el gesto se deshace y ninguna de las tres obras
     * queda a medias (RNF-02).
     *
     * @throws InvalidArgumentException Si el Maestro no consta en `users`.
     * @throws Throwable                Si alguna escritura fracasa; la transacción se deshace.
     */
    private function annulSignature(
        string $signatureId,
        string $spellId,
        string $authorId,
        string $masterId,
        string $reason,
        string $timestampUtc,
    ): SignatureAnnulment {
        // La identidad del actuante se resuelve en la base, no en el llamante.
        $statement = $this->pdo->prepare('SELECT alias, role FROM users WHERE id = :userId');
        $statement->execute([':userId' => $masterId]);
        $actor = $statement->fetch(PDO::FETCH_ASSOC);
        if ($actor === false) {
            throw new InvalidArgumentException(
                'La anulación de oficio exige un Maestro inscrito en los anales del santuario.'
            );
        }
        $actorAlias = (string) $actor['alias'];
        $actorRole = (string) $actor['role'];

        $this->pdo->beginTransaction();
        try {
            $this->signatureRepository->revokeSignature($signatureId, $reason, $timestampUtc);

            // El contador se cuenta desde las firmas VIVAS, no se resta del
            // valor almacenado: así la anulación sana cualquier divergencia
            // previa del espejo en lugar de heredarla.
            $newSignaturesCount = $this->signatureRepository->countActiveSignatures($spellId);
            $this->reviewRepository->updateSignaturesCount($spellId, $newSignaturesCount);

            $this->auditService->recordAction(
                actorUserId: $masterId,
                actorAlias: $actorAlias,
                actorRole: $actorRole,
                actionType: 'SIGNATURE_ANNULMENT',
                targetEntityType: 'spell',
                targetEntityId: $spellId,
                justification: self::annulmentJustification($reason, $actorAlias),
                now: new DateTimeImmutable($timestampUtc),
            );

            $this->pdo->commit();
        } catch (Throwable $failure) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $failure;
        }

        return new SignatureAnnulment(
            signatureId: $signatureId,
            spellId: $spellId,
            masterId: $masterId,
            authorId: $authorId,
            reason: $reason,
            newSignaturesCount: $newSignaturesCount,
        );
    }

    /**
     * Justificación solemne que la Bitácora de Auditoría pública exhibe junto
     * al acto de anulación (Art. IV). El motivo viaja NOMBRADO, no como un
     * código técnico, para que el pueblo lea por qué cayó un aval.
     */
    public static function annulmentJustification(string $reason, string $masterAlias): string
    {
        $cause = match ($reason) {
            MasterSignatureRepository::REVOCATION_RANK_LOST =>
                'la pérdida del rango de Maestro en tránsito',
            MasterSignatureRepository::REVOCATION_CLAN_CONFLICT_ARISEN =>
                'el conflicto de hermandad sobrevenido con el linaje de la obra',
            default =>
                'la nulidad constitucional de sus circunstancias',
        };

        return "Anulación de oficio: la firma del Maestro {$masterAlias} cae por {$cause}, "
            . 'y la obra exige un nuevo aval de un Maestro activo (Artículo III).';
    }

    /**
     * Normaliza un linaje: la cadena vacía y los espacios en blanco son, para
     * la Constitución, la condición de ERMITAÑO —sin estandarte—, jamás un
     * linaje llamado «».
     */
    private static function normalizeClanId(?string $clanId): ?string
    {
        if ($clanId === null) {
            return null;
        }

        $clanId = trim($clanId);

        return $clanId === '' ? null : $clanId;
    }
}
