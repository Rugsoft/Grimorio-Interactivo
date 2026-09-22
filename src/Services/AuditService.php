<?php

/**
 * AuditService.php — Registro inmutable de la Bitácora de Auditoría Arcana.
 *
 * Tarea 2.5 (TASKS-03): cada acción solemne de moderación o gobierno se
 * registra exclusivamente mediante INSERT (sin UPDATE ni DELETE, ambos
 * bloqueados por triggers del esquema) y la bitácora se consulta con
 * paginación y filtros en orden cronológico descendente.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin ORM ni librerías.
 *   - Artículo III (Transparencia y Auditoría Inmutable): toda acción de
 *     moderación queda registrada de forma inalterable con la identidad
 *     del moderador, la estampa temporal y el motivo del veredicto.
 *   - Artículo V: identificadores en inglés camelCase, motivos solemnes
 *     y documentación en castellano.
 *
 * Inmutabilidad (RF-08.1, RNF-02):
 *   - El servicio solo expone recordAction() (INSERT) y consultas de
 *     lectura. Ningún método de mutación existe en su API pública.
 *   - Los triggers trg_audit_log_no_update / trg_audit_log_no_delete
 *     del esquema (Tarea 1.1 ampliada) rechazan en el propio motor
 *     cualquier intento de alteración, incluso con SQL directo.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use DateTimeImmutable;
use Grimorio\Models\AuditEntry;
use InvalidArgumentException;
use PDO;

/**
 * Escritura y consulta de la bitácora de veredictos.
 */
final class AuditService implements AuditRecorderInterface
{
    /** Límite superior de entradas por página (blindaje anti-DoS de consulta). */
    private const MAX_PAGE_LIMIT = 100;

    /** Conexión PDO al plano arcano. */
    private PDO $pdo;

    /**
     * Página de la bitácora con sus metadatos de paginación.
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Registra una acción solemne de forma imborrable (RF-08.1).
     * La marca temporal es UTC y el motivo solemne es OBLIGATORIO:
     * un veredicto sin justificación no merece habituar la bitácora.
     *
     * @throws InvalidArgumentException Si el motivo solemne está vacío.
     */
    public function recordAction(
        string $actorUserId,
        string $actorAlias,
        string $actorRole,
        string $actionType,
        string $targetEntityType,
        string $targetEntityId,
        string $justification,
        ?DateTimeImmutable $now = null,
    ): AuditEntry {
        $instant = $now ?? new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // El motivo solemne es la esencia de la transparencia (Art. III):
        // sin él, el registro se rechaza antes de tocar la bitácora.
        if (trim($justification) === '') {
            throw new InvalidArgumentException('Todo veredicto exige un motivo solemne en la bitácora.');
        }

        // La entidad valida los catálogos de acciones y objetivos (Tarea 1.3).
        $entry = new AuditEntry(
            id: 0, // El id autoincremental lo asigna el motor al insertar.
            actorUserId: $actorUserId,
            actorAlias: $actorAlias,
            actorRole: $actorRole,
            actionType: $actionType,
            targetEntityType: $targetEntityType,
            targetEntityId: $targetEntityId,
            justification: $justification,
            createdAt: $instant->format('Y-m-d\TH:i:s\Z'),
        );

        // ÚNICO canal de escritura: INSERT puro. La fila jamás podrá ser
        // actualizada ni borrada (triggers del esquema).
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_log
                (actor_user_id, actor_alias, actor_role, action_type, target_entity_type, target_entity_id, justification, created_at)
             VALUES
                (:actorUserId, :actorAlias, :actorRole, :actionType, :targetEntityType, :targetEntityId, :justification, :createdAt)'
        );
        $statement->execute([
            ':actorUserId'      => $entry->getActorUserId(),
            ':actorAlias'       => $entry->getActorAlias(),
            ':actorRole'        => $entry->getActorRole(),
            ':actionType'       => $entry->getActionType(),
            ':targetEntityType' => $entry->getTargetEntityType(),
            ':targetEntityId'   => $entry->getTargetEntityId(),
            ':justification'    => $entry->getJustification(),
            ':createdAt'        => $entry->getCreatedAt(),
        ]);

        $assignedId = (int) $this->pdo->lastInsertId();

        // Entidad materializada con el id real asignado por el motor.
        return new AuditEntry(
            id: $assignedId,
            actorUserId: $entry->getActorUserId(),
            actorAlias: $entry->getActorAlias(),
            actorRole: $entry->getActorRole(),
            actionType: $entry->getActionType(),
            targetEntityType: $entry->getTargetEntityType(),
            targetEntityId: $entry->getTargetEntityId(),
            justification: $entry->getJustification(),
            createdAt: $entry->getCreatedAt(),
        );
    }

    /**
     * Consulta la bitácora paginada en orden cronológico DESCENDENTE
     * (RF-08.2): lo más reciente encabeza cada página. Admite filtros por
     * tipo de entidad objetivo e identidad del actuante.
     *
     * @param string|null $targetEntityType Filtro opcional ('spell', 'clan', 'user').
     * @param string|null $actorUserId      Filtro opcional por actuante.
     */
    public function fetchLog(int $page = 1, int $limit = 25, ?string $targetEntityType = null, ?string $actorUserId = null): AuditLogPage
    {
        // Blindaje de consulta: límites razonables siempre.
        $safePage = max(1, $page);
        $safeLimit = min(max(1, $limit), self::MAX_PAGE_LIMIT);
        $offset = ($safePage - 1) * $safeLimit;

        $whereClauses = [];
        $bindParams = [];

        if ($targetEntityType !== null) {
            $whereClauses[] = 'target_entity_type = :targetEntityType';
            $bindParams[':targetEntityType'] = $targetEntityType;
        }
        if ($actorUserId !== null) {
            $whereClauses[] = 'actor_user_id = :actorUserId';
            $bindParams[':actorUserId'] = $actorUserId;
        }
        $whereSql = $whereClauses === [] ? '' : 'WHERE ' . implode(' AND ', $whereClauses);

        // Total de entradas que satisfacen el filtro (metadatos de paginación).
        $countStatement = $this->pdo->prepare("SELECT COUNT(*) FROM audit_log {$whereSql}");
        $countStatement->execute($bindParams);
        $totalItems = (int) $countStatement->fetchColumn();

        // Página de entradas, ordenadas cronológicamente descendente.
        $itemsStatement = $this->pdo->prepare(
            "SELECT * FROM audit_log {$whereSql}
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($bindParams as $paramName => $paramValue) {
            $itemsStatement->bindValue($paramName, $paramValue);
        }
        $itemsStatement->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $itemsStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $itemsStatement->execute();

        $entries = [];
        foreach ($itemsStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entries[] = AuditEntry::fromDatabaseRow($row);
        }

        $totalPages = max(1, (int) ceil($totalItems / $safeLimit));

        return new AuditLogPage(
            items: $entries,
            pagination: [
                'page'       => $safePage,
                'limit'      => $safeLimit,
                'totalItems' => $totalItems,
                'totalPages' => $totalPages,
            ],
        );
    }

    /**
     * Consulta la bitácora filtrada por identidad de entidad objetivo
     * (targetEntityId), complementando fetchLog() para el parámetro
     * `clanId` del plan (Endpoint 6): los veredictos de un linaje son
     * exactamente los registros con target_entity_type = 'clan' y
     * target_entity_id = entidad dada. Mismo orden descendente y
     * blindaje de límites que la consulta general.
     */
    public function fetchFilteredByEntityId(int $page = 1, int $limit = 25, string $targetEntityType = 'clan', string $targetEntityId = ''): AuditLogPage
    {
        // Blindaje de consulta: límites razonables siempre.
        $safePage = max(1, $page);
        $safeLimit = min(max(1, $limit), self::MAX_PAGE_LIMIT);
        $offset = ($safePage - 1) * $safeLimit;

        $countStatement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM audit_log WHERE target_entity_type = :targetEntityType AND target_entity_id = :targetEntityId'
        );
        $countStatement->execute([':targetEntityType' => $targetEntityType, ':targetEntityId' => $targetEntityId]);
        $totalItems = (int) $countStatement->fetchColumn();

        $itemsStatement = $this->pdo->prepare(
            "SELECT * FROM audit_log
             WHERE target_entity_type = :targetEntityType AND target_entity_id = :targetEntityId
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset"
        );
        $itemsStatement->bindValue(':targetEntityType', $targetEntityType);
        $itemsStatement->bindValue(':targetEntityId', $targetEntityId);
        $itemsStatement->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $itemsStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $itemsStatement->execute();

        $entries = [];
        foreach ($itemsStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entries[] = AuditEntry::fromDatabaseRow($row);
        }

        $totalPages = max(1, (int) ceil($totalItems / $safeLimit));

        return new AuditLogPage(
            items: $entries,
            pagination: [
                'page'       => $safePage,
                'limit'      => $safeLimit,
                'totalItems' => $totalItems,
                'totalPages' => $totalPages,
            ],
        );
    }
}
