<?php

/**
 * ClanMemberRepository.php — Persistencia PDO de las membresías y la
 * Convalecencia Arcana de los adeptos del santuario (TASK-07, Tarea 1.3).
 *
 * Cubre: RF-01.1 (lealtad indivisible), RF-01.4 (cupo de 30 adeptos),
 * RF-01.6 (convalecencia de 14 días), RF-01.8 (veto ético de 30 días),
 * RF-01.9 (sucesión por antigüedad), RNF-01 (determinismo auditable) y
 * Artículo III (historial de linajes preservado).
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): PDO nativo, 100% consultas preparadas con
 *     parameter binding; ningún valor se interpola en el SQL.
 *   - Art. III (Ética de Linajes): el historial de membresía JAMÁS se borra.
 *     Al cerrar una afiliación se fija `left_at` y se abre la convalecencia;
 *     la fila permanece como memoria del paso del adepto por el linaje,
 *     sosteniendo el veto de 30 días a los Maestros.
 *   - Art. V (Dualidad): identificadores en inglés camelCase, claves de base
 *     de datos en snake_case, documentación en castellano.
 *
 * Reparto con ClanService (Tarea 2.4): este repositorio MIDE y PERSISTE; no
 * juzga. Comprobar el cupo antes de admitir, bloquear al postulante en
 * convalecencia, impedir que el Patriarca abandone sin transferir la corona o
 * resolver la sucesión de los 45 días son reglas de negocio de ClanService.
 *
 * Reconciliación de la afiliación (Tarea 2.3): `clan_members` es la ÚNICA
 * autoridad de la afiliación de un mago. `users.clan_id` es un ESPEJO
 * denormalizado y anulable de la membresía activa, y este repositorio es su
 * ÚNICO escritor: lo inscribe al contraer una membresía y lo devuelve a NULL
 * al cerrarla, siempre dentro de la misma transacción que el asiento de
 * `clan_members`. Así el espejo no puede desviarse de la autoridad ni
 * observarse a medio actualizar.
 */

declare(strict_types=1);

namespace Grimorio\Repositories;

use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/**
 * Repositorio de membresías: afiliación, gobernanza, cupo y convalecencia.
 */
final class ClanMemberRepository
{
    /**
     * Cupo máximo de adeptos activos por hermandad (RF-01.4).
     *
     * Fuente única de verdad del canon de 30: las capas de gobierno y las
     * interfaces (Tareas 2.4, 5.3, 6.2) lo consumen desde aquí en lugar de
     * repetir el número en cada módulo.
     */
    public const MAX_ACTIVE_MEMBERS = 30;

    /** Rol canónico del líder único de la hermandad (RF-01.3). */
    private const ROLE_PATRIARCH = 'patriarch';

    /** Rol canónico del miembro pleno (RF-01.3). */
    private const ROLE_ADEPT = 'adept';

    /** Roles admitidos dentro del clan (RF-01.3). */
    private const CANONICAL_ROLES = [self::ROLE_PATRIARCH, self::ROLE_ADEPT];

    /**
     * Proyección canónica de una membresía (columnas del esquema de SPEC-07,
     * Tarea 1.1). Declarada una sola vez para que toda lectura devuelva
     * exactamente el mismo contrato.
     */
    private const MEMBER_COLUMNS = 'id, clan_id, user_id, role, joined_at, '
        . 'left_at, convalescence_expires_at';

    /** Conexión PDO del santuario (Singleton del front controller). */
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Inscribe a un adepto en una hermandad (RF-01.1, RF-01.3).
     *
     * La membresía nace ACTIVA (`left_at` nulo) y sin convalecencia. El
     * índice único parcial `idx_active_member` de la Tarea 1.1 es la
     * garantía estructural de la lealtad indivisible: si el adepto ya
     * pertenece a otra casa, el motor rechaza la escritura y este método
     * responde `null` para que el servicio pueda dictar 409 Conflict sin
     * inspeccionar excepciones del motor de datos.
     *
     * @param string $memberId  Identificador textual de la membresía.
     * @param string $clanId    Hermandad que acoge al adepto.
     * @param string $userId    Usuario consagrado que se incorpora.
     * @param string $role      Rol canónico ('patriarch'|'adept').
     * @param string $joinedAt  Instante del ingreso (ISO 8601 UTC).
     *
     * @return array{
     *   id: string, clan_id: string, user_id: string, role: string,
     *   joined_at: string, left_at: string|null, convalescence_expires_at: string|null
     * }|null La membresía inscrita, o null si el adepto ya pertenece a un clan.
     *
     * @throws InvalidArgumentException Si el rol rompe el canon de RF-01.3.
     */
    public function addMember(
        string $memberId,
        string $clanId,
        string $userId,
        string $role,
        string $joinedAt
    ): ?array {
        $this->assertRole($role);

        // El asiento de la membresía y el reflejo en el espejo constituyen una
        // sola operación atómica: o el adepto queda inscrito con su linaje
        // vigente, o nada de ello se observa.
        return $this->runAtomically(function () use ($memberId, $clanId, $userId, $role, $joinedAt): ?array {
            $statement = $this->pdo->prepare(
                'INSERT INTO clan_members (
                     id, clan_id, user_id, role, joined_at, left_at, convalescence_expires_at
                 ) VALUES (
                     :memberId, :clanId, :userId, :role, :joinedAt, NULL, NULL
                 )'
            );

            try {
                $statement->execute([
                    ':memberId' => $memberId,
                    ':clanId'   => $clanId,
                    ':userId'   => $userId,
                    ':role'     => $role,
                    ':joinedAt' => $joinedAt,
                ]);
            } catch (PDOException $exception) {
                // Lealtad indivisible (RF-01.1): el adepto ya milita en una casa.
                if ($this->isUniqueConstraintViolation($exception)) {
                    return null;
                }

                throw $exception;
            }

            // La autoridad manda: el espejo `users.clan_id` la sigue.
            $this->mirrorActiveClan($userId, $clanId);

            return $this->findMembershipById($memberId);
        });
    }

    /**
     * Cierra una afiliación por renuncia o expulsión (RF-01.6).
     *
     * Fija `left_at` sobre la membresía ACTIVA y abre la ventana de
     * convalecencia de catorce días naturales. La fila JAMÁS se elimina:
     * es la memoria que sostiene el veto ético de 30 días a los Maestros
     * (RF-01.8, Artículo III).
     *
     * La convalecencia es OPCIONAL: RF-01.6 la impone a la renuncia y a la
     * expulsión, pero la disolución de una casa (RF-05.3) no es una pena y
     * cierra la afiliación sin meditación forzosa. Se pasa `null` para ese
     * caso y la columna queda vacía.
     *
     * @param string      $userId                 Adepto que parte.
     * @param string      $clanId                 Hermandad abandonada.
     * @param string      $leftAtUtc              Instante de la partida (ISO 8601 UTC).
     * @param string|null $convalescenceExpiresAt Fin de los 14 días de meditación, o null si no la hay.
     *
     * @return bool Cierto si existía una membresía activa y quedó cerrada.
     */
    public function removeMember(
        string $userId,
        string $clanId,
        string $leftAtUtc,
        ?string $convalescenceExpiresAt = null
    ): bool {
        // La partida y la vuelta del espejo a «sin linaje» son indivisibles.
        return $this->runAtomically(function () use ($userId, $clanId, $leftAtUtc, $convalescenceExpiresAt): bool {
            $statement = $this->pdo->prepare(
                'UPDATE clan_members
                    SET left_at = :leftAt,
                        convalescence_expires_at = :convalescenceExpiresAt
                  WHERE user_id = :userId
                    AND clan_id = :clanId
                    AND left_at IS NULL'
            );
            $statement->execute([
                ':leftAt'                   => $leftAtUtc,
                ':convalescenceExpiresAt'   => $convalescenceExpiresAt,
                ':userId'                   => $userId,
                ':clanId'                   => $clanId,
            ]);

            if ($statement->rowCount() === 0) {
                return false;
            }

            $this->clearMirroredClan($userId, $clanId);

            return true;
        });
    }

    /**
     * Recupera la membresía ACTIVA de un adepto (RF-01.1).
     *
     * @return array{
     *   id: string, clan_id: string, user_id: string, role: string,
     *   joined_at: string, left_at: string|null, convalescence_expires_at: string|null
     * }|null La afiliación vigente, o null si el adepto es libre o convaleciente.
     */
    public function findActiveMembership(string $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::MEMBER_COLUMNS . '
               FROM clan_members
              WHERE user_id = :userId
                AND left_at IS NULL
              LIMIT 1'
        );
        $statement->execute([':userId' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Censo de adeptos ACTIVOS excluyendo a un mago (RF-01.9).
     *
     * Es la consulta del velatorio dinástico: `ClanService` excluye al
     * Patriarca cuya inactividad se juzga y recibe, ya alineados por
     * antigüedad de ingreso ascendente, a los candidatos a ceñir la corona.
     * El desempate por PDA aportados no se resuelve aquí —requiere el libro
     * de contribuciones—, así que ClanService lo dirime sobre esta lista.
     *
     * @return list<array{
     *   id: string, clan_id: string, user_id: string, role: string,
     *   joined_at: string, left_at: string|null, convalescence_expires_at: string|null
     * }>
     */
    public function findActiveMembersExcluding(string $clanId, string $excludedUserId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::MEMBER_COLUMNS . '
               FROM clan_members
              WHERE clan_id = :clanId
                AND left_at IS NULL
                AND user_id <> :excludedUserId
              ORDER BY joined_at ASC, id ASC'
        );
        $statement->execute([
            ':clanId'          => $clanId,
            ':excludedUserId'  => $excludedUserId,
        ]);

        $members = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $members[] = $this->hydrate($row);
        }

        return $members;
    }

    /**
     * Censo de adeptos ACTIVOS de una hermandad (RF-01.4, RF-01.9).
     *
     * El orden es determinista y responde a las dos necesidades canónicas:
     * el Patriarca encabeza siempre el censo (gobierno, RF-01.3) y, a
     * continuación, los adeptos se alinean por antigüedad de ingreso
     * (`joined_at` ascendente, desempate por `id`). Esa segunda clave es
     * precisamente lo que exige la sucesión dinástica de RF-01.9 —«el
     * Adepto activo con mayor antigüedad»—; el desempate por PDA aportados
     * compete a ClanService, que sí dispone de los contadores del clan.
     *
     * El censo histórico (Herencia Ancestral) no se sirve aquí: vive en las
     * filas cerradas de `clan_members` y se consulta por adepto mediante
     * `findPastMembershipsSince()`.
     *
     * @return list<array{
     *   id: string, clan_id: string, user_id: string, role: string,
     *   joined_at: string, left_at: string|null, convalescence_expires_at: string|null
     * }>
     */
    public function findMembersByClan(string $clanId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::MEMBER_COLUMNS . '
               FROM clan_members
              WHERE clan_id = :clanId
                AND left_at IS NULL
              ORDER BY CASE role WHEN :patriarchRole THEN 0 ELSE 1 END ASC,
                       joined_at ASC,
                       id ASC'
        );
        $statement->execute([
            ':clanId'         => $clanId,
            ':patriarchRole'  => self::ROLE_PATRIARCH,
        ]);

        $members = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $members[] = $this->hydrate($row);
        }

        return $members;
    }

    /**
     * Cuenta los adeptos ACTIVOS de una hermandad (RF-01.4).
     *
     * Es la medida exacta del cupo ocupado: ClanService la contrasta con
     * `MAX_ACTIVE_MEMBERS` para bloquear cualquier nuevo ingreso cuando la
     * casa alcanza su plenitud de treinta hermanos.
     */
    public function countActiveMembers(string $clanId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM clan_members
              WHERE clan_id = :clanId
                AND left_at IS NULL'
        );
        $statement->execute([':clanId' => $clanId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Determina si un adepto se halla en Convalecencia Arcana (RF-01.6).
     *
     * Cierto si CUALQUIER ventana de convalecencia sigue abierta en el
     * instante dado, esto es, si su fecha de expiración es futura. Las
     * marcas temporales viajan en ISO 8601 UTC con idéntico formato, de
     * modo que la comparación es determinista y auditable (RNF-01); el
     * instante se recibe por parámetro y jamás se lee del reloj del sistema.
     */
    public function isUserInConvalescence(string $userId, string $nowUtc): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM clan_members
              WHERE user_id = :userId
                AND convalescence_expires_at IS NOT NULL
                AND convalescence_expires_at > :nowUtc'
        );
        $statement->execute([
            ':userId' => $userId,
            ':nowUtc' => $nowUtc,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * Historial de afiliaciones CERRADAS desde una fecha de corte (RF-01.8).
     *
     * Es la consulta que sostiene el veto constitucional del Artículo III:
     * un Maestro no juzga conjuros del clan que habitó en los últimos
     * treinta días naturales. Se recorre de la partida más reciente a la
     * más antigua para que el validador pueda detenerse en la primera
     * coincidencia.
     *
     * @param string $userId        Maestro o adepto cuyo historial se examina.
     * @param string $cutoffDateUtc Fecha de corte (ISO 8601 UTC): se devuelven
     *                              las partidas posteriores o iguales a ella.
     *
     * @return list<array{
     *   id: string, clan_id: string, user_id: string, role: string,
     *   joined_at: string, left_at: string|null, convalescence_expires_at: string|null
     * }>
     */
    public function findPastMembershipsSince(string $userId, string $cutoffDateUtc): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::MEMBER_COLUMNS . '
               FROM clan_members
              WHERE user_id = :userId
                AND left_at IS NOT NULL
                AND left_at >= :cutoffDate
              ORDER BY left_at DESC, id ASC'
        );
        $statement->execute([
            ':userId'     => $userId,
            ':cutoffDate' => $cutoffDateUtc,
        ]);

        $memberships = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $memberships[] = $this->hydrate($row);
        }

        return $memberships;
    }

    /**
     * Confiere un rol canónico a una membresía ACTIVA (RF-01.3).
     *
     * Sirve al traspaso de la corona (promover al nuevo Patriarca y degradar
     * al anterior a Adepto del Linaje) y a cualquier ajuste de gobierno de la
     * casa. Solo alcanza a la afiliación vigente: el historial cerrado es
     * memoria inmutable y no se reescribe (Artículo III).
     *
     * @return bool Cierto si existía una membresía activa y quedó actualizada.
     *
     * @throws InvalidArgumentException Si el rol rompe el canon de RF-01.3.
     */
    public function setRole(string $userId, string $clanId, string $role): bool
    {
        $this->assertRole($role);

        $statement = $this->pdo->prepare(
            'UPDATE clan_members
                SET role = :role
              WHERE user_id = :userId
                AND clan_id = :clanId
                AND left_at IS NULL'
        );
        $statement->execute([
            ':role'   => $role,
            ':userId' => $userId,
            ':clanId' => $clanId,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Recupera una membresía por su identificador.
     *
     * Sirve tanto a la afiliación vigente como a la memoria de una ya
     * cerrada: al sellar `left_at` la fila no se borra, de modo que
     * `ClanService` pueda retratar la partida con su convalecencia dentro de
     * la misma transacción en que se consumó.
     *
     * @return array{
     *   id: string, clan_id: string, user_id: string, role: string,
     *   joined_at: string, left_at: string|null, convalescence_expires_at: string|null
     * }|null
     */
    public function findMembershipById(string $memberId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::MEMBER_COLUMNS . ' FROM clan_members WHERE id = :memberId'
        );
        $statement->execute([':memberId' => $memberId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Normaliza una fila del plano relacional a un array tipado de PHP.
     *
     * La membresía activa (`left_at` nulo → `null`) y la ausencia de
     * convalecencia se preservan como nulos explícitos, evitando que las
     * cadenas del motor de datos se filtren a las capas superiores.
     *
     * @param array<string, mixed> $row
     *
     * @return array{
     *   id: string, clan_id: string, user_id: string, role: string,
     *   joined_at: string, left_at: string|null, convalescence_expires_at: string|null
     * }
     */
    private function hydrate(array $row): array
    {
        return [
            'id'                       => (string) $row['id'],
            'clan_id'                  => (string) $row['clan_id'],
            'user_id'                  => (string) $row['user_id'],
            'role'                     => (string) $row['role'],
            'joined_at'                => (string) $row['joined_at'],
            'left_at'                  => $row['left_at'] === null ? null : (string) $row['left_at'],
            'convalescence_expires_at' => $row['convalescence_expires_at'] === null
                ? null
                : (string) $row['convalescence_expires_at'],
        ];
    }

    /**
     * Vela por el canon de los roles nobiliarios (RF-01.3).
     *
     * @throws InvalidArgumentException Si el rol no es Patriarch ni Adepto.
     */
    private function assertRole(string $role): void
    {
        if (!in_array($role, self::CANONICAL_ROLES, true)) {
            throw new InvalidArgumentException(
                'El clan solo admite los rangos canónicos de Patriarca o Adepto del Linaje.'
            );
        }
    }

    /**
     * Ejecuta una operación como una sola transacción, plegándose a la del
     * llamante si ya hubiera una abierta (Tarea 1.4).
     *
     * Permite que ClanService componga «admitir + cancelar postulaciones» o
     * «fundar + inscribir al Patriarca» como un único gesto confirmable o
     * reversible, sin anidar transacciones que SQLite no admite.
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
            $this->pdo->rollBack();

            throw $failure;
        }
    }

    /**
     * Inscribe el espejo denormalizado `users.clan_id` con el linaje recién
     * contraído. Es la única escritura de `users` que realiza este
     * repositorio, y siempre acompaña al asiento de la membresía.
     */
    private function mirrorActiveClan(string $userId, string $clanId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET clan_id = :clanId WHERE id = :userId'
        );
        $statement->execute([':clanId' => $clanId, ':userId' => $userId]);
    }

    /**
     * Devuelve el espejo a «sin linaje» al cerrar la afiliación, y solo si
     * apuntaba al linaje abandonado: un adepto no puede pertenecer a dos
     * casas, pero el espejo jamás debe pisar un vínculo ajeno.
     */
    private function clearMirroredClan(string $userId, string $clanId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET clan_id = NULL WHERE id = :userId AND clan_id = :clanId'
        );
        $statement->execute([':userId' => $userId, ':clanId' => $clanId]);
    }

    /**
     * Distingue una colisión de unicidad de cualquier otro fallo del motor.
     *
     * SQLite y MySQL responden con SQLSTATE 23000 («integrity constraint
     * violation») acompañado de la leyenda de unicidad; PostgreSQL emplea
     * 23505. Se aísla esta condición para traducirla a un resultado de
     * negocio (lealtad indivisible) sin enmascarar el resto de errores.
     */
    private function isUniqueConstraintViolation(PDOException $exception): bool
    {
        $sqlState = (string) $exception->getCode();

        if ($sqlState === '23505') {
            return true;
        }

        return str_starts_with($sqlState, '23')
            && preg_match('/unique|duplicate/i', $exception->getMessage()) === 1;
    }
}
