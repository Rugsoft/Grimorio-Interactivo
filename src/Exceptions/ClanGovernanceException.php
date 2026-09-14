<?php

/**
 * ClanGovernanceException.php — Veredicto adverso del gobierno de hermandades.
 *
 * Tarea 2.4 (TASKS-07): excepción de dominio alzada por ClanService cuando una
 * regla del canon de clanes se opone a la operación. Cada causa porta el código
 * canónico del contrato (plan 2.2) y el estado HTTP que la Tarea 3.2 traducirá
 * a su respuesta REST, de modo que el controlador jamás inspeccione mensajes.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro, sin librerías.
 *   - Artículo III: los rechazos son estrictos e irreversibles en el backend.
 *   - Artículo IV: leyendas solemnes en castellano para cada rechazo.
 *   - Artículo V: identificadores en inglés camelCase.
 */

declare(strict_types=1);

namespace Grimorio\Exceptions;

use RuntimeException;

/**
 * Una regla del canon de clanes impide consumar la operación.
 */
final class ClanGovernanceException extends RuntimeException
{
    // ── Códigos canónicos de rechazo (plan 2.2, Endpoints 1 a 9) ──────────
    public const INSUFFICIENT_RANK = 'INSUFFICIENT_RANK';
    public const INVALID_NAME = 'INVALID_NAME';
    public const NAME_ALREADY_RESERVED = 'NAME_ALREADY_RESERVED';
    public const UNKNOWN_LINEAGE = 'UNKNOWN_LINEAGE';
    public const CONVALESCENCE_ACTIVE = 'CONVALESCENCE_ACTIVE';
    public const ALREADY_AFFILIATED = 'ALREADY_AFFILIATED';
    public const CLAN_NOT_FOUND = 'CLAN_NOT_FOUND';
    public const CLAN_ARCHIVED = 'CLAN_ARCHIVED';
    public const CLAN_QUOTA_EXCEEDED = 'CLAN_QUOTA_EXCEEDED';
    public const PENDING_APPLICATIONS_LIMIT = 'PENDING_APPLICATIONS_LIMIT';
    public const APPLICATION_ALREADY_PENDING = 'APPLICATION_ALREADY_PENDING';
    public const APPLICATION_NOT_FOUND = 'APPLICATION_NOT_FOUND';
    public const APPLICATION_ALREADY_RESOLVED = 'APPLICATION_ALREADY_RESOLVED';
    public const NOT_PATRIARCH = 'NOT_PATRIARCH';
    public const NOT_A_MEMBER = 'NOT_A_MEMBER';
    public const NO_CLAN_AFFILIATION = 'NO_CLAN_AFFILIATION';
    public const PATRIARCH_MUST_TRANSFER_CROWN = 'PATRIARCH_MUST_TRANSFER_CROWN';
    public const CANNOT_EXPEL_SELF = 'CANNOT_EXPEL_SELF';
    public const INVALID_DECISION = 'INVALID_DECISION';
    public const INELIGIBLE_SUCCESSOR = 'INELIGIBLE_SUCCESSOR';

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

    /** El rango técnico no alcanza para el acto (RF-01.1, RF-01.2). */
    public static function insufficientRank(): self
    {
        return new self(
            self::INSUFFICIENT_RANK,
            403,
            'Los magos anónimos no pueden fundar ni afiliarse: el rango de editor es el umbral de la hermandad.',
            'REQUEST_EDITOR_RANK',
        );
    }

    /** El Nombre Canónico no cumple la extensión exigida (RF-01.2). */
    public static function invalidName(int $minLength, int $maxLength): self
    {
        return new self(
            self::INVALID_NAME,
            400,
            "El Nombre Canónico debe contar entre {$minLength} y {$maxLength} caracteres de noble castellano.",
            'RESTATE_CANONICAL_NAME',
        );
    }

    /** El nombre ya pertenece a otra casa, disuelta o en activo (RF-01.2, RF-05.4). */
    public static function nameAlreadyReserved(string $name): self
    {
        return new self(
            self::NAME_ALREADY_RESERVED,
            409,
            "El Nombre Canónico «{$name}» ya está inscrito en los anales: ni la disolución libera un nombre célebre.",
            'CHOOSE_ANOTHER_CANONICAL_NAME',
        );
    }

    /** El linaje rector no pertenece a los ocho canónicos (RF-02.1). */
    public static function unknownLineage(string $lineageType): self
    {
        return new self(
            self::UNKNOWN_LINEAGE,
            400,
            "El linaje «{$lineageType}» no figura entre los ocho artes canónicos del santuario.",
            'CHOOSE_CANONICAL_LINEAGE',
        );
    }

    /** Convalecencia Arcana vigente: ingreso y fundación vedados (RF-01.6). */
    public static function convalescenceActive(): self
    {
        return new self(
            self::CONVALESCENCE_ACTIVE,
            403,
            'En Convalecencia Arcana: la meditación de catorce días veda fundar una casa o ingresar en otra.',
            'AWAIT_CONVALESCENCE_END',
        );
    }

    /** Lealtad indivisible: ya milita en una casa (RF-01.1). */
    public static function alreadyAffiliated(): self
    {
        return new self(
            self::ALREADY_AFFILIATED,
            409,
            'La lealtad mágica es indivisible: este mago ya milita bajo otro estandarte.',
            'LEAVE_CURRENT_CLAN',
        );
    }

    /** La hermandad no existe (Endpoint 3). */
    public static function clanNotFound(string $clanId): self
    {
        return new self(
            self::CLAN_NOT_FOUND,
            404,
            "No hay hermandad inscrita con el identificador «{$clanId}».",
            'REVIEW_CLAN_ID',
        );
    }

    /** La casa yace disuelta como Herencia Ancestral (RF-05.3). */
    public static function clanArchived(string $clanId): self
    {
        return new self(
            self::CLAN_ARCHIVED,
            409,
            "La hermandad «{$clanId}» yace disuelta como Herencia Ancestral: su estandarte ya no admite adeptos.",
            'CHOOSE_ACTIVE_CLAN',
        );
    }

    /** Cupo de treinta adeptos colmado (RF-01.4). */
    public static function clanQuotaExceeded(int $memberLimit): self
    {
        return new self(
            self::CLAN_QUOTA_EXCEEDED,
            409,
            "La hermandad ha alcanzado su plenitud de {$memberLimit} adeptos activos: ningún ingreso cabe sin una partida.",
            'SEEK_ANOTHER_CLAN',
        );
    }

    /** Tope de tres solicitudes pendientes simultáneas (RF-01.5). */
    public static function pendingApplicationsLimit(int $limit): self
    {
        return new self(
            self::PENDING_APPLICATIONS_LIMIT,
            400,
            "El postulante ya mantiene {$limit} solicitudes pendientes: debe aguardar veredicto antes de cortejar otra casa.",
            'AWAIT_APPLICATION_VERDICT',
        );
    }

    /** Ya existe una postulación pendiente sobre esa misma casa (RF-01.5). */
    public static function applicationAlreadyPending(): self
    {
        return new self(
            self::APPLICATION_ALREADY_PENDING,
            409,
            'Ya obra una solicitud pendiente sobre esta hermandad: no se admiten postulaciones duplicadas.',
            'AWAIT_APPLICATION_VERDICT',
        );
    }

    /** La solicitud no existe o no pertenece a esa casa (Endpoint 6). */
    public static function applicationNotFound(string $applicationId): self
    {
        return new self(
            self::APPLICATION_NOT_FOUND,
            404,
            "No obra solicitud alguna con el identificador «{$applicationId}» sobre esta hermandad.",
            'REVIEW_APPLICATION_ID',
        );
    }

    /** La solicitud ya recibió veredicto: no se delibera dos veces (Endpoint 6). */
    public static function applicationAlreadyResolved(): self
    {
        return new self(
            self::APPLICATION_ALREADY_RESOLVED,
            409,
            'La solicitud ya recibió veredicto: ninguna deliberación se dicta dos veces.',
            'REVIEW_APPLICATION_STATE',
        );
    }

    /** El actuante no ciñe la corona de esa casa (Endpoints 4, 6, 8, 9). */
    public static function notPatriarch(): self
    {
        return new self(
            self::NOT_PATRIARCH,
            403,
            'Solo el Patriarca o Matriarca en funciones puede dictar este acto de gobierno.',
            'REQUEST_PATRIARCH_RANK',
        );
    }

    /** El adepto no milita en esa casa (Endpoints 7, 8, 9). */
    public static function notAMember(string $userId): self
    {
        return new self(
            self::NOT_A_MEMBER,
            404,
            "El mago «{$userId}» no milita actualmente en esta hermandad.",
            'REVIEW_MEMBERSHIP',
        );
    }

    /**
     * El adepto no milita en casa alguna: sin linaje no hay gloria que
     * acreditar (Endpoint 14, RF-03.2).
     */
    public static function noClanAffiliation(string $userId): self
    {
        return new self(
            self::NO_CLAN_AFFILIATION,
            409,
            "El mago «{$userId}» no milita en hermandad alguna: el Dominio solo se acredita bajo un estandarte.",
            'JOIN_OR_FOUND_CLAN',
        );
    }

    /** El Patriarca no puede partir sin antes ceder el cetro (Endpoint 7). */
    public static function patriarchMustTransferCrown(): self
    {
        return new self(
            self::PATRIARCH_MUST_TRANSFER_CROWN,
            400,
            'El Patriarca no puede abandonar la hermandad sin transferir previamente la corona a otro adepto.',
            'TRANSFER_LEADERSHIP_FIRST',
        );
    }

    /** Nadie se expulsa a sí mismo de su propia casa (Endpoint 8). */
    public static function cannotExpelSelf(): self
    {
        return new self(
            self::CANNOT_EXPEL_SELF,
            403,
            'El Patriarca no puede expulsarse a sí mismo: para partir debe transferir la corona o disolver la casa.',
            'TRANSFER_LEADERSHIP_FIRST',
        );
    }

    /** Veredicto ajeno al canon: solo `approve` o `reject` (Endpoint 6). */
    public static function invalidDecision(string $decision): self
    {
        return new self(
            self::INVALID_DECISION,
            400,
            "El veredicto «{$decision}» no figura en el canon: solo se admite 'approve' o 'reject'.",
            'RESTATE_DECISION',
        );
    }

    /** El sucesor propuesto no es un adepto activo de la casa (Endpoint 9). */
    public static function ineligibleSuccessor(string $userId): self
    {
        return new self(
            self::INELIGIBLE_SUCCESSOR,
            400,
            "El mago «{$userId}» no es un adepto activo de esta hermandad y no puede ceñir la corona.",
            'CHOOSE_ACTIVE_ADEPT',
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
