<?php

/**
 * ClanApplicationRepository.php — Persistencia PDO de las solicitudes de
 * ingreso a las hermandades del santuario.
 *
 * Tarea 1.4 (TASKS-07): canal exclusivo de lectura y mutación de la tabla
 * `clan_applications` para el régimen de admisión bajo petición.
 *
 * Cubre: RF-01.5 (régimen `byApplication`, deliberación del Patriarca y tope
 * de 3 solicitudes pendientes), RNF-01 (determinismo auditable).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, 100% consultas preparadas con
 *     parameter binding; ningún valor del exterior se interpola en el SQL.
 *   - Artículo III (Ética de Linajes): la solicitud jamás se borra; se
 *     resuelve (`approved`/`rejected`) o se cancela, preservando la memoria
 *     de la deliberación.
 *   - Artículo V (Dualidad): identificadores en inglés camelCase, claves de
 *     base de datos en snake_case, documentación en castellano.
 *
 * Decisiones de diseño:
 *   - El tope de TRES solicitudes pendientes se defiende con una guarda
 *     atómica dentro del propio INSERT (`INSERT ... SELECT ... WHERE`), no
 *     con un «leer y luego escribir»: así dos postulaciones simultáneas no
 *     pueden rebasar el canon por una condición de carrera (RNF-01).
 *   - La misma guarda impide DUPLICAR una solicitud pendiente sobre una casa
 *     ya postulada, pues el canon exige postular «a diferentes clanes».
 *   - `createApplication()` responde `null` cuando la guarda bloquea la
 *     escritura. Para discernir la causa exacta (tope alcanzado o solicitud
 *     duplicada) y emitir la leyenda ceremonial precisa, el servicio dispone
 *     de `countPendingApplications()` y `findPendingApplicationForClan()`.
 */

declare(strict_types=1);

namespace Grimorio\Repositories;

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Repositorio de solicitudes de ingreso: postulación y deliberación.
 */
final class ClanApplicationRepository
{
    /**
     * Tope canónico de solicitudes pendientes por postulante (RF-01.5).
     *
     * Fuente única de verdad del canon de 3: las capas de gobierno y las
     * interfaces (Tareas 2.4 y 6.2) lo consumen desde aquí.
     */
    public const MAX_PENDING_APPLICATIONS = 3;

    /** Estados canónicos de una solicitud (esquema de la Tarea 1.1). */
    private const STATUS_PENDING = 'pending';
    private const STATUS_APPROVED = 'approved';
    private const STATUS_REJECTED = 'rejected';
    private const STATUS_CANCELLED = 'cancelled';

    /** Veredictos que el Patriarca puede dictar (RF-01.5). */
    private const RESOLUTION_STATUSES = [self::STATUS_APPROVED, self::STATUS_REJECTED];

    /**
     * Proyección canónica de una solicitud (columnas del esquema de SPEC-07).
     * Declarada una sola vez para que toda lectura devuelva el mismo contrato.
     */
    private const APPLICATION_COLUMNS = 'id, clan_id, user_id, status, created_at, resolved_at';

    /** Conexión PDO del santuario (Singleton del front controller). */
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Registra una solicitud de ingreso bajo petición (RF-01.5).
     *
     * La solicitud nace `pending` y sin veredicto. La guarda atómica exige
     * simultáneamente: (a) que el postulante no haya alcanzado el tope de
     * tres pendientes y (b) que no exista ya una postulación pendiente suya
     * sobre esa misma casa.
     *
     * @param string $applicationId Identificador textual de la solicitud.
     * @param string $clanId        Hermandad a la que se postula.
     * @param string $userId        Postulante consagrado.
     * @param string $createdAt     Instante de la postulación (ISO 8601 UTC).
     *
     * @return array{
     *   id: string, clan_id: string, user_id: string, status: string,
     *   created_at: string, resolved_at: string|null
     * }|null La solicitud registrada, o null si la guarda del canon la bloqueó.
     */
    public function createApplication(
        string $applicationId,
        string $clanId,
        string $userId,
        string $createdAt
    ): ?array {
        $statement = $this->pdo->prepare(
            'INSERT INTO clan_applications (id, clan_id, user_id, status, created_at, resolved_at)
             SELECT :applicationId, :clanId, :userId, :pendingStatus, :createdAt, NULL
              WHERE (
                        SELECT COUNT(*)
                          FROM clan_applications
                         WHERE user_id = :userId
                           AND status = :pendingStatus
                    ) < :maxPending
                AND NOT EXISTS (
                        SELECT 1
                          FROM clan_applications
                         WHERE user_id = :userId
                           AND clan_id = :clanId
                           AND status = :pendingStatus
                    )'
        );
        $statement->bindValue(':applicationId', $applicationId);
        $statement->bindValue(':clanId', $clanId);
        $statement->bindValue(':userId', $userId);
        $statement->bindValue(':pendingStatus', self::STATUS_PENDING);
        $statement->bindValue(':createdAt', $createdAt);
        $statement->bindValue(':maxPending', self::MAX_PENDING_APPLICATIONS, PDO::PARAM_INT);
        $statement->execute();

        if ($statement->rowCount() === 0) {
            // El tope de tres o una postulación duplicada detuvieron la pluma.
            return null;
        }

        return $this->findById($applicationId);
    }

    /**
     * Recupera una solicitud por su identificador.
     *
     * @return array{
     *   id: string, clan_id: string, user_id: string, status: string,
     *   created_at: string, resolved_at: string|null
     * }|null
     */
    public function findById(string $applicationId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::APPLICATION_COLUMNS . '
               FROM clan_applications
              WHERE id = :applicationId'
        );
        $statement->execute([':applicationId' => $applicationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Localiza la postulación PENDIENTE de un adepto sobre una casa concreta.
     *
     * Permite al servicio discernir con precisión que el bloqueo de
     * `createApplication()` se debió a una solicitud duplicada y no al tope.
     *
     * @return array{
     *   id: string, clan_id: string, user_id: string, status: string,
     *   created_at: string, resolved_at: string|null
     * }|null
     */
    public function findPendingApplicationForClan(string $userId, string $clanId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::APPLICATION_COLUMNS . '
               FROM clan_applications
              WHERE user_id = :userId
                AND clan_id = :clanId
                AND status = :pendingStatus
              LIMIT 1'
        );
        $statement->execute([
            ':userId'        => $userId,
            ':clanId'        => $clanId,
            ':pendingStatus' => self::STATUS_PENDING,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Solicitudes PENDIENTES de un postulante (RF-01.5).
     *
     * Se ordenan de la más antigua a la más reciente para que la cronología
     * de la deliberación sea determinista (RNF-01).
     *
     * @return list<array{
     *   id: string, clan_id: string, user_id: string, status: string,
     *   created_at: string, resolved_at: string|null
     * }>
     */
    public function findPendingApplicationsByUser(string $userId): array
    {
        return $this->findApplications(
            'SELECT ' . self::APPLICATION_COLUMNS . '
               FROM clan_applications
              WHERE user_id = :userId
                AND status = :pendingStatus
              ORDER BY created_at ASC, id ASC',
            [':userId' => $userId, ':pendingStatus' => self::STATUS_PENDING]
        );
    }

    /**
     * Cuenta las solicitudes PENDIENTES de un postulante (RF-01.5).
     *
     * Es la medida exacta del cupo de postulaciones: el servicio la contrasta
     * con `MAX_PENDING_APPLICATIONS` para denegar la cuarta con la leyenda
     * ceremonial correspondiente.
     */
    public function countPendingApplications(string $userId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM clan_applications
              WHERE user_id = :userId
                AND status = :pendingStatus'
        );
        $statement->execute([
            ':userId'        => $userId,
            ':pendingStatus' => self::STATUS_PENDING,
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Expediente de solicitudes de una hermandad para el panel del Patriarca.
     *
     * @param string      $clanId Hermandad cuyo expediente se consulta.
     * @param string|null $status Estado a filtrar (`pending`, `approved`,
     *                            `rejected`, `cancelled`) o null para todas.
     *
     * @return list<array{
     *   id: string, clan_id: string, user_id: string, status: string,
     *   created_at: string, resolved_at: string|null
     * }>
     *
     * @throws InvalidArgumentException Si el estado filtrado rompe el canon.
     */
    public function findApplicationsByClan(string $clanId, ?string $status = null): array
    {
        if ($status !== null) {
            $this->assertCanonicalStatus($status);
        }

        return $this->findApplications(
            'SELECT ' . self::APPLICATION_COLUMNS . '
               FROM clan_applications
              WHERE clan_id = :clanId
                AND (:status IS NULL OR status = :status)
              ORDER BY created_at ASC, id ASC',
            [':clanId' => $clanId, ':status' => $status]
        );
    }

    /**
     * Dicta el veredicto del Patriarca sobre una solicitud (RF-01.5).
     *
     * Al APROBAR una solicitud, las restantes postulaciones pendientes del
     * mismo adepto se cancelan automáticamente DENTRO DE LA MISMA
     * TRANSACCIÓN: quien profesa en una casa no puede seguir cortejando a
     * otras. El veredicto solo alcanza a solicitudes `pending`; una ya
     * resuelta no se reescribe (memoria inmutable de la deliberación).
     *
     * Si el llamante ya gobierna una transacción, este método se pliega a
     * ella en lugar de abrir una anidada, de modo que el alta del adepto
     * (`ClanMemberRepository::addMember`) y la cancelación de sus restantes
     * postulaciones puedan confirmarse o deshacerse como un solo gesto.
     *
     * @param string $applicationId Solicitud a dirimir.
     * @param string $decision      Veredicto canónico ('approved'|'rejected').
     * @param string $resolvedAt    Instante del veredicto (ISO 8601 UTC).
     *
     * @return bool Cierto si la solicitud estaba pendiente y quedó resuelta.
     *
     * @throws InvalidArgumentException Si el veredicto rompe el canon.
     */
    public function resolveApplication(string $applicationId, string $decision, string $resolvedAt): bool
    {
        $this->assertResolution($decision);

        $application = $this->findById($applicationId);
        if ($application === null || $application['status'] !== self::STATUS_PENDING) {
            return false;
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            // La condición `status = pending` impide que dos deliberaciones
            // simultáneas dicten veredicto sobre la misma solicitud.
            $statement = $this->pdo->prepare(
                'UPDATE clan_applications
                    SET status = :decision,
                        resolved_at = :resolvedAt
                  WHERE id = :applicationId
                    AND status = :pendingStatus'
            );
            $statement->execute([
                ':decision'      => $decision,
                ':resolvedAt'    => $resolvedAt,
                ':applicationId' => $applicationId,
                ':pendingStatus' => self::STATUS_PENDING,
            ]);
            $resolved = $statement->rowCount() > 0;

            if ($resolved && $decision === self::STATUS_APPROVED) {
                $this->cancelPendingApplications(
                    (string) $application['user_id'],
                    $applicationId,
                    $resolvedAt
                );
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $resolved;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Cancela las postulaciones pendientes de un adepto (RF-01.5).
     *
     * Se invoca al ingresar en una hermandad —tanto por aprobación de
     * solicitud como por admisión inmediata en régimen `open`— para que el
     * adepto no siga cortejando a otras casas. La solicitud recién aprobada
     * se excluye mediante `$exceptApplicationId`; en el ingreso por régimen
     * abierto se pasa null y se cancelan todas las pendientes.
     *
     * @return int Número de solicitudes canceladas.
     */
    public function cancelPendingApplications(
        string $userId,
        ?string $exceptApplicationId,
        string $resolvedAt
    ): int {
        $statement = $this->pdo->prepare(
            'UPDATE clan_applications
                SET status = :cancelledStatus,
                    resolved_at = :resolvedAt
              WHERE user_id = :userId
                AND status = :pendingStatus
                AND (:exceptApplicationId IS NULL OR id <> :exceptApplicationId)'
        );
        $statement->execute([
            ':cancelledStatus'     => self::STATUS_CANCELLED,
            ':resolvedAt'          => $resolvedAt,
            ':userId'              => $userId,
            ':pendingStatus'       => self::STATUS_PENDING,
            ':exceptApplicationId' => $exceptApplicationId,
        ]);

        return $statement->rowCount();
    }

    /**
     * Ejecuta una consulta preparada y normaliza sus filas a arrays tipados.
     *
     * @param string                $sql         Sentencia con placeholders nombrados.
     * @param array<string, mixed>  $parameters  Parámetros vinculados.
     *
     * @return list<array{
     *   id: string, clan_id: string, user_id: string, status: string,
     *   created_at: string, resolved_at: string|null
     * }>
     */
    private function findApplications(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        $applications = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $applications[] = $this->hydrate($row);
        }

        return $applications;
    }

    /**
     * Normaliza una fila del plano relacional a un array tipado de PHP.
     *
     * @param array<string, mixed> $row
     *
     * @return array{
     *   id: string, clan_id: string, user_id: string, status: string,
     *   created_at: string, resolved_at: string|null
     * }
     */
    private function hydrate(array $row): array
    {
        return [
            'id'          => (string) $row['id'],
            'clan_id'     => (string) $row['clan_id'],
            'user_id'     => (string) $row['user_id'],
            'status'      => (string) $row['status'],
            'created_at'  => (string) $row['created_at'],
            'resolved_at' => $row['resolved_at'] === null ? null : (string) $row['resolved_at'],
        ];
    }

    /**
     * Vela por el canon de los veredictos del Patriarca (RF-01.5).
     *
     * @throws InvalidArgumentException Si el veredicto no es aprobar o rechazar.
     */
    private function assertResolution(string $decision): void
    {
        if (!in_array($decision, self::RESOLUTION_STATUSES, true)) {
            throw new InvalidArgumentException(
                'El Patriarca solo puede aprobar o rechazar una solicitud de ingreso.'
            );
        }
    }

    /**
     * Vela por el canon de los estados de una solicitud.
     *
     * @throws InvalidArgumentException Si el estado filtrado no existe.
     */
    private function assertCanonicalStatus(string $status): void
    {
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED], true)) {
            throw new InvalidArgumentException(
                'El expediente solo admite los estados pendiente, aprobada, rechazada o cancelada.'
            );
        }
    }
}
