<?php

/**
 * AuditEntry.php — Entidad inmutable de la Bitácora de Auditoría Arcana.
 *
 * Tarea 1.3 (TASKS-03): modelo tipado que materializa la tabla
 * `audit_log` del esquema DDL (Tarea 1.1) y el contrato JSON público
 * de la bitácora (plan 2.2, Endpoint 6).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): entidad PHP pura, sin ORM ni librerías.
 *   - Artículo III (Transparencia y Auditoría Inmutable): cada acción de
 *     moderación queda registrada con identidad, rol, objetivo y motivo;
 *     la entidad nace inmutable y jamás expone canales de mutación.
 *   - Artículo V: propiedades y métodos en inglés camelCase,
 *     documentación y motivos solemnes en castellano.
 *
 * Seguridad:
 *   - Los catálogos de acciones y entidades objetivo se validan en el
 *     nacimiento: la bitácora jamás acepta un registro fuera del canon.
 *   - Inmutabilidad total: sin setters, propiedades privadas solo-lectura.
 */

declare(strict_types=1);

namespace Grimorio\Models;

use InvalidArgumentException;

/**
 * Registro inmutable de una acción solemne de moderación o gobierno.
 */
final class AuditEntry
{
    /** Tipos de acción canónicos de la bitácora (RF-08.1, plan 2.1). */
    private const CANONICAL_ACTION_TYPES = [
        'SIGN_VALIDATE',
        'SIGN_REJECT',
        'ADMIN_VETO',
        'PROMOTE_MASTER',
        'DEMOTE_MASTER',
        'CLAN_MODIFY',
        'ACC_LINK_RENOUNCED',
        'RESET_SIGNATURES_MATH_CHANGE',
        'UPDATE_DESCRIPTION_INTACT_SIGNATURES',
        'CREATE_VARIANT_FROM_VALIDATED',
    ];

    /** Tipos de entidad objetivo canónicos (RF-08.1). */
    private const CANONICAL_TARGET_TYPES = ['spell', 'clan', 'user'];

    /**
     * @param int    $id                Identificador autoincremental del registro.
     * @param string $actorUserId       Identidad técnica del actuante.
     * @param string $actorAlias        Alias público en el instante de la acción.
     * @param string $actorRole         Rol técnico activo en ese instante.
     * @param string $actionType        Acción canónica (SIGN_VALIDATE, ADMIN_VETO, ...).
     * @param string $targetEntityType  Tipo de entidad afectada ('spell', 'clan', 'user').
     * @param string $targetEntityId    Identificador de la entidad afectada.
     * @param string $justification     Motivo solemne obligatorio en castellano.
     * @param string $createdAt         Marca temporal UTC (ISO 8601).
     *
     * @throws InvalidArgumentException Si la acción o el objetivo están fuera del catálogo.
     */
    public function __construct(
        private readonly int $id,
        private readonly string $actorUserId,
        private readonly string $actorAlias,
        private readonly string $actorRole,
        private readonly string $actionType,
        private readonly string $targetEntityType,
        private readonly string $targetEntityId,
        private readonly string $justification,
        private readonly string $createdAt,
    ) {
        // Validación estricta en el nacimiento: la bitácora jamás acepta
        // acciones u objetivos fuera del canon (RF-08.1).
        if (!in_array($this->actionType, self::CANONICAL_ACTION_TYPES, true)) {
            throw new InvalidArgumentException(
                "Tipo de acción fuera del catálogo de la bitácora: '{$this->actionType}'."
            );
        }
        if (!in_array($this->targetEntityType, self::CANONICAL_TARGET_TYPES, true)) {
            throw new InvalidArgumentException(
                "Tipo de entidad objetivo fuera del catálogo de la bitácora: '{$this->targetEntityType}'."
            );
        }
    }

    /** Identificador autoincremental del registro. */
    public function getId(): int
    {
        return $this->id;
    }

    /** Identidad técnica del actuante. */
    public function getActorUserId(): string
    {
        return $this->actorUserId;
    }

    /** Alias público del actuante en el instante de la acción. */
    public function getActorAlias(): string
    {
        return $this->actorAlias;
    }

    /** Rol técnico activo del actuante en ese instante. */
    public function getActorRole(): string
    {
        return $this->actorRole;
    }

    /** Acción canónica ejecutada. */
    public function getActionType(): string
    {
        return $this->actionType;
    }

    /** Tipo de entidad afectada ('spell', 'clan' o 'user'). */
    public function getTargetEntityType(): string
    {
        return $this->targetEntityType;
    }

    /** Identificador de la entidad afectada. */
    public function getTargetEntityId(): string
    {
        return $this->targetEntityId;
    }

    /** Motivo solemne de la decisión (castellano noble). */
    public function getJustification(): string
    {
        return $this->justification;
    }

    /** Marca temporal UTC de la acción (ISO 8601). */
    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    /**
     * Normaliza la entrada al array asociativo del contrato JSON público
     * de la bitácora (plan 2.2, Endpoint 6): claves camelCase listas para
     * la respuesta de `GET /api/v1/audit/log`.
     *
     * @return array<string, int|string>
     */
    public function toNormalizedArray(): array
    {
        return [
            'id'                => $this->id,
            'actorAlias'        => $this->actorAlias,
            'actorRole'         => $this->actorRole,
            'actionType'        => $this->actionType,
            'targetEntityType'  => $this->targetEntityType,
            'targetEntityId'    => $this->targetEntityId,
            'justification'     => $this->justification,
            'createdAt'         => $this->createdAt,
        ];
    }

    /**
     * Reconstruye la entidad desde una fila de base de datos
     * (snake_case de schema.sql, Tarea 1.1) mapeando a camelCase.
     *
     * @param array<string, int|null|string> $databaseRow Fila de la tabla audit_log.
     */
    public static function fromDatabaseRow(array $databaseRow): self
    {
        return new self(
            id: (int) ($databaseRow['id'] ?? 0),
            actorUserId: (string) ($databaseRow['actor_user_id'] ?? ''),
            actorAlias: (string) ($databaseRow['actor_alias'] ?? ''),
            actorRole: (string) ($databaseRow['actor_role'] ?? ''),
            actionType: (string) ($databaseRow['action_type'] ?? ''),
            targetEntityType: (string) ($databaseRow['target_entity_type'] ?? ''),
            targetEntityId: (string) ($databaseRow['target_entity_id'] ?? ''),
            justification: (string) ($databaseRow['justification'] ?? ''),
            createdAt: (string) ($databaseRow['created_at'] ?? ''),
        );
    }
}
