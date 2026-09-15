<?php

/**
 * ModerationWorkflowException.php — Veredicto adverso del flujo de moderación.
 *
 * Tarea 2.3 (TASKS-08): excepción de dominio alzada por
 * ModerationWorkflowService cuando una regla del ciclo de vida del conjuro se
 * opone a la operación. Cada causa porta el código canónico del contrato (plan
 * 2.2) y el estado HTTP que la Fase 3 traducirá a su respuesta REST, de modo
 * que el controlador jamás inspeccione mensajes.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro, sin librerías.
 *   - Artículo III: los rechazos son estrictos en el backend.
 *   - Artículo IV: leyendas solemnes en castellano para cada rechazo.
 *   - Artículo V: identificadores en inglés camelCase.
 *
 * Tarea 2.5 (TASKS-08): la misma clase acoge la familia de la POTESTAD SOBERANA
 * (RF-04), porque el decreto imperial es una pieza más de la misma moderación en
 * dos pasos y sus veredictos han de viajar con el mismo contrato. El mapa de
 * códigos y estados HTTP se lee aquí entero, y el controlador de la Fase 3 no
 * inspecciona jamás un mensaje.
 */

declare(strict_types=1);

namespace Grimorio\Exceptions;

use RuntimeException;

/**
 * Una regla del ciclo de vida del conjuro impide consumar la operación.
 */
final class ModerationWorkflowException extends RuntimeException
{
    // ── Códigos canónicos de rechazo (plan 2.2, Endpoints 1 a 3 y 12) ─────
    public const SPELL_NOT_IN_DRAFT = 'SPELL_NOT_IN_DRAFT';
    public const SPELL_NOT_UNDER_REVIEW = 'SPELL_NOT_UNDER_REVIEW';
    public const SPELL_NOT_REJECTED = 'SPELL_NOT_REJECTED';
    public const TOWER_CAPACITY_EXCEEDED = 'TOWER_CAPACITY_EXCEEDED';
    public const INSUFFICIENT_RANK = 'INSUFFICIENT_RANK';
    public const CONVALESCENCE_ACTIVE = 'CONVALESCENCE_ACTIVE';
    public const UNDER_REVIEW_IMMUTABLE = 'UNDER_REVIEW_IMMUTABLE';
    public const SPELL_AWAITING_REOPEN = 'SPELL_AWAITING_REOPEN';

    // ── Códigos de la deliberación colegiada (Tarea 2.4, plan §3.2) ───────
    public const SPELL_NOT_IN_REVIEW = 'SPELL_NOT_IN_REVIEW';
    public const SELF_SIGNING_PROHIBITED = 'SELF_SIGNING_PROHIBITED';
    public const CONSTITUTIONAL_ETHICS_VETO = 'CONSTITUTIONAL_ETHICS_VETO';
    public const CLAN_PLURALITY_VIOLATION = 'CLAN_PLURALITY_VIOLATION';
    public const ALREADY_SIGNED = 'ALREADY_SIGNED';
    public const GLOSS_TOO_LONG = 'GLOSS_TOO_LONG';
    public const NO_ACTIVE_SIGNATURE = 'NO_ACTIVE_SIGNATURE';
    public const SIGNATURE_IRREVOCABLE = 'SIGNATURE_IRREVOCABLE';
    public const OBJECTION_TOO_BRIEF = 'OBJECTION_TOO_BRIEF';
    public const INSUFFICIENT_RANK_TO_JUDGE = 'INSUFFICIENT_RANK_TO_JUDGE';

    // ── Códigos de la potestad soberana (Tarea 2.5, plan §3.4) ────────────
    public const INSUFFICIENT_SOVEREIGN_RANK = 'INSUFFICIENT_SOVEREIGN_RANK';
    public const CANNOT_SOVEREIGN_VALIDATE_NON_EXPERIMENTAL = 'CANNOT_SOVEREIGN_VALIDATE_NON_EXPERIMENTAL';
    public const CANNOT_SOVEREIGN_RESCUE_NON_REJECTED = 'CANNOT_SOVEREIGN_RESCUE_NON_REJECTED';
    public const CANNOT_SOVEREIGN_ARCHIVE_NON_VALIDATED = 'CANNOT_SOVEREIGN_ARCHIVE_NON_VALIDATED';
    public const SOVEREIGN_RESCUE_INVALID_TARGET = 'SOVEREIGN_RESCUE_INVALID_TARGET';
    public const SELF_VALIDATION_PROHIBITED = 'SELF_VALIDATION_PROHIBITED';
    public const SOVEREIGN_OWN_CLAN_VETO = 'SOVEREIGN_OWN_CLAN_VETO';
    public const IMPERIAL_DECREE_TOO_SHORT = 'IMPERIAL_DECREE_TOO_SHORT';

    /**
     * @param string $errorCode      Código canónico del contrato REST.
     * @param int    $httpStatus     Estado HTTP que el controlador responderá.
     * @param string $message        Leyenda solemne en castellano.
     * @param string $recoveryAction Gesto que el frontend ofrecerá al mago.
     */
    private function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
        public readonly string $recoveryAction,
    ) {
        parent::__construct($message);
    }

    /** Solo un borrador privado se eleva a deliberación (RF-01.2). */
    public static function notInDraftState(): self
    {
        return new self(
            self::SPELL_NOT_IN_DRAFT,
            400,
            'Solo los borradores privados se elevan a la Torre: la obra ya no descansa en tu libreta.',
            'WITHDRAW_BEFORE_RESUBMIT',
        );
    }

    /** La obra no está en deliberación: nada hay que retirar (RF-01.3). */
    public static function notUnderReview(string $currentStatus): self
    {
        return new self(
            self::SPELL_NOT_UNDER_REVIEW,
            409,
            "La obra no se halla en deliberación —su estado es «{$currentStatus}»—: ningún aval hay que anular.",
            'REVIEW_WORKFLOW_STATE',
        );
    }

    /** Solo una obra vetada se reabre como borrador (RF-01.4). */
    public static function notRejectedState(string $currentStatus): self
    {
        return new self(
            self::SPELL_NOT_REJECTED,
            400,
            "Solo una obra vetada se reabre como borrador: el estado actual es «{$currentStatus}».",
            'REVIEW_WORKFLOW_STATE',
        );
    }

    /** La Torre ya custodia tres obras del autor (RF-01.2, RNF-04). */
    public static function towerCapacityExceeded(int $limit): self
    {
        return new self(
            self::TOWER_CAPACITY_EXCEEDED,
            409,
            "La Torre de Moderación ya custodia {$limit} de tus obras en deliberación. "
            . 'Aguarda su resolución antes de elevar nuevas plegarias.',
            'AWAIT_TOWER_VERDICT',
        );
    }

    /** El rango técnico no alcanza para elevar obras a la Torre (RF-01.2). */
    public static function insufficientRankToSubmit(): self
    {
        return new self(
            self::INSUFFICIENT_RANK,
            403,
            'Solo los magos consagrados —rango de editor o superior— elevan plegarias a la Torre de Moderación.',
            'REQUEST_EDITOR_RANK',
        );
    }

    /** Convalecencia Arcana vigente: la pluma calla (plan 2.2, Endpoint 1). */
    public static function convalescenceActive(): self
    {
        return new self(
            self::CONVALESCENCE_ACTIVE,
            403,
            'En Convalecencia Arcana la pluma calla: aguarda el fin de los catorce días antes de elevar obras a la Torre.',
            'AWAIT_CONVALESCENCE_END',
        );
    }

    /** La obra en deliberación es inmutable (RF-01.3). */
    public static function underReviewImmutable(string $spellId): self
    {
        return new self(
            self::UNDER_REVIEW_IMMUTABLE,
            403,
            "La obra «{$spellId}» permanece en deliberación y es inmutable: "
            . 'retírala a tu libreta para enmendar sus parámetros, asumiendo el reinicio de sus firmas.',
            'WITHDRAW_TO_EDIT',
        );
    }

    /** La obra vetada yace inmutable hasta su re-apertura (RF-01.4). */
    public static function awaitingReopen(string $spellId): self
    {
        return new self(
            self::SPELL_AWAITING_REOPEN,
            403,
            "La obra «{$spellId}» fue vetada y permanece inmutable en tu libreta: "
            . 'reábrela como borrador para enmendar cuanto la objeción señaló.',
            'REOPEN_AS_DRAFT',
        );
    }

    /** La obra no está en deliberación: nadie la juzga (plan §3.2, paso 1). */
    public static function notInReview(string $currentStatus): self
    {
        return new self(
            self::SPELL_NOT_IN_REVIEW,
            409,
            "Solo se juzga lo que yace en deliberación: el estado de la obra es «{$currentStatus}».",
            'REVIEW_WORKFLOW_STATE',
        );
    }

    /** La propia pluma jamás se firma (RF-03.2, plan §3.2, paso 2). */
    public static function selfSigningProhibited(): self
    {
        return new self(
            self::SELF_SIGNING_PROHIBITED,
            403,
            'Ningún Maestro avala su propia obra: el juicio de la propia pluma no es juicio (Artículo III).',
            'WITHDRAW_OR_AWAIT_OTHER_MASTER',
        );
    }

    /** El veto constitucional veda el juicio (RF-03.1, Art. III). */
    public static function constitutionalEthicsVeto(): self
    {
        return new self(
            self::CONSTITUTIONAL_ETHICS_VETO,
            403,
            'Conflicto de intereses: no es lícito a un Maestro juzgar las obras nacidas bajo su propio estandarte, linajes recientes o propia pluma.',
            'ABSTAIN_FROM_DELIBERATION',
        );
    }

    /** Dos voces del mismo estandarte sobre una misma obra (RF-02.1). */
    public static function clanPluralityViolation(): self
    {
        return new self(
            self::CLAN_PLURALITY_VIOLATION,
            409,
            'Pluralidad de hermandades: un mismo linaje no puede aportar dos voces sobre la misma obra; cada estandarte consagra una sola vez.',
            'AWAIT_ANOTHER_LINEAGE_MASTER',
        );
    }

    /** El Maestro ya había avalado esta obra (RF-02.4). */
    public static function alreadySigned(): self
    {
        return new self(
            self::ALREADY_SIGNED,
            409,
            'Ese Maestro ya estampó su firma de consagración sobre esta obra: un aval vivo por Maestro y conjuro.',
            'RETRACT_BEFORE_SIGNING_AGAIN',
        );
    }

    /** La glosa ceremonial excede el canon de 250 caracteres (RF-02.2). */
    public static function glossTooLong(int $maxLength, int $givenLength): self
    {
        return new self(
            self::GLOSS_TOO_LONG,
            400,
            "La glosa ceremonial no puede exceder los {$maxLength} caracteres: se pronunciaron {$givenLength}.",
            'SHORTEN_CEREMONIAL_GLOSS',
        );
    }

    /** El Maestro no tiene firma viva que retractar (RF-02.4). */
    public static function noActiveSignature(): self
    {
        return new self(
            self::NO_ACTIVE_SIGNATURE,
            400,
            'No obra firma viva de ese Maestro sobre esta obra: nada hay que retractar.',
            'REVIEW_SIGNATURE_STATE',
        );
    }

    /** La consagración alcanzada es irrevocable para los Maestros (RF-02.4). */
    public static function signatureIrrevocable(): self
    {
        return new self(
            self::SIGNATURE_IRREVOCABLE,
            409,
            'La obra ya alcanzó la consagración: la tercera rúbrica es irrevocable para los Maestros.',
            'FORGE_A_VARIANT_INSTEAD',
        );
    }

    /** La justificación de la objeción no alcanza los veinte caracteres (RF-02.5). */
    public static function objectionTooBrief(int $minLength, int $givenLength): self
    {
        return new self(
            self::OBJECTION_TOO_BRIEF,
            422,
            "El Dictamen de Objeción exige una justificación en castellano de al menos {$minLength} caracteres: se redactaron {$givenLength}.",
            'RESTATE_OBJECTION_REASON',
        );
    }

    /** El rango no alcanza para juzgar: solo los Maestros deliberan (RF-02.1). */
    public static function insufficientRankToJudge(): self
    {
        return new self(
            self::INSUFFICIENT_RANK_TO_JUDGE,
            403,
            'Solo los Maestros del Cónclave pueden firmar, objetar o retractarse: el rango no alcanza para juzgar.',
            'REQUEST_MASTER_RANK',
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Potestad soberana del Administrador Supremo (RF-04, plan §3.4)
    // ─────────────────────────────────────────────────────────────────────

    /** El rango no alcanza para decretar: solo el Administrador Supremo (RF-04). */
    public static function insufficientSovereignRank(): self
    {
        return new self(
            self::INSUFFICIENT_SOVEREIGN_RANK,
            403,
            'Solo el Administrador Supremo puede dictar decretos imperiales: la Firma Soberana no se delega.',
            'REQUEST_SUPREME_ADMIN_RANK',
        );
    }

    /**
     * La Firma Soberana solo alcanza obras YA elevadas a deliberación (RF-04.1).
     *
     * El estado del borrador se nombra sin retratar su contenido: la libreta
     * privada es inviolable incluso para el Cónclave Supremo (spec §7.5).
     */
    public static function cannotSovereignValidateNonExperimental(string $currentStatus): self
    {
        return new self(
            self::CANNOT_SOVEREIGN_VALIDATE_NON_EXPERIMENTAL,
            400,
            "La Firma Soberana solo alcanza obras en deliberación: el estado de la obra es «{$currentStatus}» (Artículo II.3 y spec §7.5).",
            'AWAIT_AUTHOR_SUBMISSION',
        );
    }

    /** El rescate de oficio solo alcanza obras vetadas (RF-04.3). */
    public static function cannotSovereignRescueNonRejected(string $currentStatus): self
    {
        return new self(
            self::CANNOT_SOVEREIGN_RESCUE_NON_REJECTED,
            400,
            "El rescate de oficio solo alcanza obras en estado rejected: el estado de la obra es «{$currentStatus}».",
            'INSPECT_SPELL_STATE',
        );
    }

    /** La degradación póstuma solo alcanza obras consagradas (RF-04.4). */
    public static function cannotSovereignArchiveNonValidated(string $currentStatus): self
    {
        return new self(
            self::CANNOT_SOVEREIGN_ARCHIVE_NON_VALIDATED,
            400,
            "Solo se destierra lo que fue consagrado: el estado de la obra es «{$currentStatus}».",
            'INSPECT_SPELL_STATE',
        );
    }

    /** El rescate solo admite los dos destinos canónicos (RF-04.3). */
    public static function sovereignRescueInvalidTarget(string $targetStatus): self
    {
        return new self(
            self::SOVEREIGN_RESCUE_INVALID_TARGET,
            400,
            "El rescate de oficio solo admite dos destinos —experimental o validated—: se pidió «{$targetStatus}».",
            'CHOOSE_CANONICAL_TARGET',
        );
    }

    /** La pluma propia no se juzga, ni con la potestad suprema (RF-03.2). */
    public static function selfValidationProhibited(): self
    {
        return new self(
            self::SELF_VALIDATION_PROHIBITED,
            403,
            'La potestad suprema no absuelve la propia pluma: ningún Administrador decreta sobre una obra de su autoría (Artículo III.2).',
            'ABSTAIN_FROM_DECREE',
        );
    }

    /** El propio estandarte queda fuera de la potestad soberana (RF-04.2). */
    public static function sovereignOwnClanVeto(): self
    {
        return new self(
            self::SOVEREIGN_OWN_CLAN_VETO,
            403,
            'Veto del Artículo III.2: las obras forjadas bajo el estandarte del Administrador Supremo se someten obligatoriamente al juicio de tres Maestros independientes de clanes ajenos.',
            'AWAIT_INDEPENDENT_MASTERS',
        );
    }

    /** El Edicto Imperial no alcanza la solemnidad exigida (RF-04.5). */
    public static function imperialDecreeTooShort(int $minLength, int $givenLength): self
    {
        return new self(
            self::IMPERIAL_DECREE_TOO_SHORT,
            422,
            "Todo Decreto Imperial exige un edicto de justificación de al menos {$minLength} caracteres en castellano: se redactaron {$givenLength}.",
            'RESTATE_IMPERIAL_DECREE',
        );
    }

    /**
     * Sobre de error listo para Response::json() (AGENTS.md 6.1).
     *
     * @return array{success: false, error: array{code: string, message: string, recoveryAction: string}}
     */
    public function toPayload(): array
    {
        return [
            'success' => false,
            'error'   => [
                'code'           => $this->errorCode,
                'message'        => $this->getMessage(),
                'recoveryAction' => $this->recoveryAction,
            ],
        ];
    }
}
