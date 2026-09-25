<?php

/**
 * UserPanelRepository.php — Persistencia PDO de la vitrina del Panel
 * del Adepto (SPEC-12, Tarea 1.2).
 *
 * Cubre: RF-02.1…RF-02.4 (lecturas de identidad, linaje, membresía y
 * convalecencia), RF-07.1…RF-07.3 (contadores del tomo, firmas del
 * Maestro y gloria semanal), RF-03.x (la ÚNICA escritura de `avatar`)
 * y RNF-02 (PDO nativo, 100% consultas preparadas).
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): PDO nativo, sin librerías externas.
 *   - Art. VI de AGENTS.md: 100% consultas preparadas con parameter
 *     binding; ningún valor del llamador se interpola jamás en el SQL.
 *   - Art. V (Dualidad): identificadores en inglés camelCase, claves de
 *     base en snake_case, documentación en noble castellano.
 *
 * Reparto de responsabilidades: este repositorio MIDE y PERSISTE; no
 * juzga. Los rótulos castellanos, las leyendas canónicas y la heráldica
 * los viste el DTO (`UserPanelDto`, Tarea 1.4) y la política de negocio
 * (retención del peregrino, asientos de bitácora) la dictan los
 * servicios; aquí solo viajan los hechos en crudo, por filas, para que
 * nadie inspeccione excepciones del motor de datos.
 *
 * Frontera sagrada (RF-01.1, privacidad estricta): toda lectura parte
 * SIEMPRE del `userId` del titular de la sesión que el controlador
 * entrega; jamás existe parámetro de identidad ajena. La escritura de
 * `avatar` es la ÚNICA de este repositorio y porta el guard
 * `WHERE id = :userId`: un adepto jamás viste la efigie de otro.
 */

declare(strict_types=1);

namespace Grimorio\Repositories;

use PDO;

final class UserPanelRepository
{
    /** El canal PDO del santuario. */
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Fila de identidad del adepto (RF-02.1): alias, correo, rol técnico,
     * linaje jurado, efigie vigente y espejo de clan.
     *
     * El `password_hash` jamás se selecciona: la vitrina no lo necesita y
     * el dato no viaja (la custodia de la frase de paso es acto aparte).
     *
     * @return array{alias: string, email: string, role: string, clan_id: string|null, lineage: string|null, avatar: string|null, updated_at: string}|null
     */
    public function fetchUserVitals(string $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT alias, email, role, clan_id, lineage, avatar, updated_at
               FROM users
              WHERE id = :userId'
        );
        $statement->execute([':userId' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Membresía ACTIVA del adepto (RF-02.2): clan de vinculación, ingreso
     * formal y fin de la convalecencia si la hubiere (RF-05.1).
     *
     * La autoridad de la membresía es `clan_members` (SPEC-07); el espejo
     * `users.clan_id` es solo vía rápida y la vitrina lo lee por
     * coherencia, jamás como fuente.
     *
     * @return array{clan_id: string, user_id: string, role: string, joined_at: string, convalescence_expires_at: string|null}|null
     */
    public function fetchActiveMembership(string $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT clan_id, user_id, role, joined_at, convalescence_expires_at
               FROM clan_members
              WHERE user_id = :userId
                AND left_at IS NULL
              LIMIT 1'
        );
        $statement->execute([':userId' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * La gloria semanal del clan (RF-07.3): los puntos del Dominio
     * publicados en el ciclo vigente, con la semana de su publicación
     * (el rótulo «Semana N de Y» lo narra el DTO, RF-07.3).
     *
     * Devuelve null si el clan no existe o no publicó cómputo vigente:
     * la leyenda canónica sin cifras fantasma la pinta el DTO, no aquí.
     *
     * @return array{weekly_points: int, week_number: int, cycle_year: int}|null
     */
    public function fetchClanWeeklyGlory(string $clanId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.weekly_points, w.week_number, w.cycle_year
               FROM clans c
          LEFT JOIN weekly_cycles w ON w.regent_clan_id = c.id
              WHERE c.id = :clanId
              ORDER BY w.closed_at DESC
              LIMIT 1'
        );
        $statement->execute([':clanId' => $clanId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : [
            'weekly_points' => (int) $row['weekly_points'],
            'week_number'   => (int) ($row['week_number'] ?? 1),
            'cycle_year'    => (int) ($row['cycle_year'] ?? 0),
        ];
    }

    /**
     * Fila de la hermandad para la vitrina (RF-02.2): nombre canónico,
     * estado y blasón del clan de la membresía activa.
     *
     * @return array{id: string, name: string, status: string, coat_of_arms: string}|null
     */
    public function fetchClanForVitrina(string $clanId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, status, coat_of_arms
               FROM clans
              WHERE id = :clanId'
        );
        $statement->execute([':clanId' => $clanId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * El vínculo de sesión vivo para la vitrina (RF-02.1): nacimiento,
     * expiración y cliente declarado, sin el token ni su hash.
     *
     * @return array{created_at: string, expires_at: string, user_agent: string}|null
     */
    public function fetchSessionForVitrina(string $sessionId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT created_at, expires_at, user_agent
               FROM user_sessions
              WHERE id = :sessionId'
        );
        $statement->execute([':sessionId' => $sessionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Obras selladas en el tomo del adepto (RF-07.1): contador de
     * `grimoire_collections` (SPEC-11), dato existente sin cómputo nuevo.
     */
    public function countSealedSpells(string $userId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM grimoire_collections
              WHERE user_id = :userId'
        );
        $statement->execute([':userId' => $userId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Homenajes rendidos por el adepto (RF-07.1): votos de `favorites`
     * (SPEC-11), cuyo agregado existe por la unicidad
     * `UNIQUE(user_id, spell_id)`.
     */
    public function countPraiseGiven(string $userId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM favorites
              WHERE user_id = :userId'
        );
        $statement->execute([':userId' => $userId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Deberes del Maestro (RF-07.2): recuento de firmas prestadas por
     * estado, conforme al catálogo de SPEC-08 (pendiente, retirada,
     * anulada). Un no-Maestro recibe null: jamás un contador fantasma.
     *
     * @return array{pending: int, retracted: int, annulled: int}|null
     */
    public function fetchMasterSignatureDuties(string $userId): ?array
    {
        // La pertenencia al oficio de moderación se lee de `users.role`
        // (la mesa `master_signatures` solo habla de firmas, no de oficios).
        $roleStatement = $this->pdo->prepare('SELECT role FROM users WHERE id = :userId');
        $roleStatement->execute([':userId' => $userId]);
        $role = $roleStatement->fetchColumn();
        if ($role !== 'master' && $role !== 'supremeAdmin') {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT
                 SUM(CASE WHEN is_revoked = 0 THEN 1 ELSE 0 END) AS pending,
                 SUM(CASE WHEN is_revoked = 1 AND revocation_reason = \'retracted\' THEN 1 ELSE 0 END) AS retracted,
                 SUM(CASE WHEN is_revoked = 1 AND revocation_reason <> \'retracted\' THEN 1 ELSE 0 END) AS annulled
               FROM master_signatures
              WHERE master_id = :userId'
        );
        $statement->execute([':userId' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'pending'   => (int) ($row['pending'] ?? 0),
            'retracted' => (int) ($row['retracted'] ?? 0),
            'annulled'  => (int) ($row['annulled'] ?? 0),
        ];
    }

    /**
     * La estampa del sellado del juramento de linaje (RF-02.1, Tarea 1.3).
     *
     * Fuente ya ratificada: el asiento `LINEAGE_OATH_SWORN` del propio
     * adepto en la Bitácora inmutable (principio rector 2: el panel no
     * crea verdad nueva). Peregrino o adepto sin asiento → null.
     */
    public function fetchLineageOathSwornAt(string $userId): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT created_at
               FROM audit_log
              WHERE actor_user_id = :userId
                AND action_type = \'LINEAGE_OATH_SWORN\'
                AND target_entity_type = \'user\'
                AND target_entity_id = :userId
              ORDER BY created_at ASC
              LIMIT 1'
        );
        $statement->execute([':userId' => $userId]);
        $stamp = $statement->fetchColumn();

        return is_string($stamp) && $stamp !== '' ? $stamp : null;
    }

    /**
     * La ÚNICA escritura de este repositorio: viste (o desnuda) la efigie
     * del adepto (RF-03.1…RF-03.5).
     *
     * El guard `WHERE id = :userId` es la muralla de intimidad: un adepto
     * jamás viste la efigie de otro ni quedan filas huérfanas. `NULL`
     * significa «avatar canónico por defecto» (RF-03.5); la semántica
     * cerrada de valores (`catalog:<id>` / `own:<fileId>`) la custodia el
     * servicio, no la base.
     *
     * @param string|null $avatarReference NULL o la referencia del contrato cerrado.
     */
    public function updateAvatar(string $userId, ?string $avatarReference, string $updatedAtUtc): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE users
                SET avatar = :avatarReference,
                    updated_at = :updatedAt
              WHERE id = :userId'
        );
        $statement->execute([
            ':avatarReference' => $avatarReference,
            ':updatedAt' => $updatedAtUtc,
            ':userId' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * La Lente de Bitácora Personal (RF-06.1/06.2, Tarea 4.1; plan §2.7).
     *
     * Pura LECTURA sobre el registro inmutable con el filtro de
     * pertenencia canónico — «actos dirigidos al adepto» (decisión QA):
     *
     *   actor = yo  ∨  (target user = yo)  ∨  (target spell = obra propia)
     *
     * Los actos colectivos del clan sin el adepto como sujeto quedan
     * fuera: esa visión colectiva vive en las cámaras del clan. TODOS
     * los parámetros viajan vinculados (AGENTS.md §6.1): el filtro es
     * inmune a inyección aunque el cursor provenga del cliente.
     *
     * Paginación por CURSOR OPACO (hallazgo 1 del QA: 20 asientos por
     * página, sin límite histórico): el cursor es el id numérico del
     * último asiento servido; la página siguiente continúa ESTRICTAMENTE
     * por debajo de él (orden `id DESC`), lo que garantiza estabilidad y
     * ausencia de duplicados aun con asientos naciendo entre páginas.
     *
     * @param string $userId El titular de la lente (jamás identidad ajena).
     * @param string|null $cursor Cursor opaco (id del último asiento); null = primera página.
     * @param int $limit Asientos por página (techo constitucional 20).
     *
     * @return array{entries: array<int, array<string, mixed>>, nextCursor: string|null}
     */
    public function fetchPersonalLedger(string $userId, ?string $cursor = null, int $limit = 20): array
    {
        // Blindaje de cardinalidad: la página jamás excede 20 asientos.
        $safeLimit = min(max(1, $limit), 20);

        // El grupo completo de pertenencia viaja ENTRE PARÉNTESIS: sin
        // ellos, la precedencia SQL (AND liga más fuerte que OR) haría
        // que el corte del cursor (`AND id < :cursorId`) solo alcanzara
        // a la última rama y las páginas se repitieran (hallazgo del
        // arnés TDD: paginación estable exige el paréntesis externo).
        $baseWhere = "(
            (actor_user_id = :userId)
            OR (target_entity_type = 'user' AND target_entity_id = :userId)
            OR (target_entity_type = 'spell' AND target_entity_id IN (
                SELECT id FROM spells WHERE author_id = :userId
            ))
        )";

        $cursorWhere = '';
        $cursorId = 0;
        if ($cursor !== null && $cursor !== '' && ctype_digit($cursor)) {
            $cursorId = (int) $cursor;
            $cursorWhere = ' AND id < :cursorId';
        }

        // La página se pide con UN asiento de cortesía para conocer si
        // existe página siguiente sin una segunda consulta de recuento.
        $statement = $this->pdo->prepare(
            "SELECT id, actor_user_id, action_type, target_entity_type, justification, created_at
               FROM audit_log
              WHERE {$baseWhere}{$cursorWhere}
              ORDER BY id DESC
              LIMIT :limit"
        );
        $statement->bindValue(':userId', $userId, PDO::PARAM_STR);
        if ($cursorId > 0) {
            $statement->bindValue(':cursorId', $cursorId, PDO::PARAM_INT);
        }
        $statement->bindValue(':limit', $safeLimit + 1, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        // El asiento de cortesía se retira y se convierte en el cursor.
        $hasMore = count($rows) > $safeLimit;
        if ($hasMore) {
            array_pop($rows);
        }

        $entries = [];
        $lastId = null;
        foreach ($rows as $row) {
            $lastId = (int) $row['id'];
            $entries[] = [
                'actionType'   => (string) $row['action_type'],
                'targetKind'   => (string) $row['target_entity_type'],
                'narrative'    => (string) $row['justification'],
                'createdAt'    => (string) $row['created_at'],
            ];
        }

        return [
            'entries'    => $entries,
            'nextCursor' => $hasMore && $lastId !== null ? (string) $lastId : null,
        ];
    }
}
