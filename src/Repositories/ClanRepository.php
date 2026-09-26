<?php

/**
 * ClanRepository.php — Persistencia PDO de las hermandades del santuario.
 *
 * Tarea 1.2 (TASKS-07): canal exclusivo de lectura y mutación de la tabla
 * `clans` para el Sistema de Clanes, Linajes y Dominio Semanal.
 *
 * Cubre: RF-01.2 (fundación), RF-01.3 (gobernanza), RF-01.5 (admisión),
 * RF-03.1 (crédito de PDA), RF-04.3 (pliegue histórico en el cierre),
 * RF-05.3 (disolución), RF-05.4 (reserva del Nombre) y RNF-01 (determinismo).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, 100% consultas preparadas con
 *     parameter binding; cero concatenación de valores en SQL. Toda lectura
 *     atraviesa `prepare()`/`execute()`; ninguna sentencia se compone con
 *     variables.
 *   - Artículo III (Ética de Linajes): el historial de membresía y la
 *     disolución se preservan; jamás se borra una hermandad.
 *   - Artículo V (Dualidad): identificadores en inglés camelCase, claves de
 *     base de datos en snake_case, documentación en castellano.
 *
 * Decisiones de diseño:
 *   - Este repositorio habla el dialecto de la TABLA (columnas snake_case) y
 *     devuelve «arrays tipados» de PHP con los numéricos ya convertidos a
 *     `int`; la traducción al contrato JSON camelCase es competencia de la
 *     capa DTO (Tarea 2.1).
 *   - Los instantes se reciben SIEMPRE como parámetro (`$nowUtc`, ISO 8601
 *     UTC) en lugar de leerse del reloj del sistema: así el cómputo queda
 *     ciego y auditable (RNF-01) y las pruebas gobiernan el tiempo.
 *   - `createClan()` devuelve `null` cuando la identidad ya está reservada
 *     (Nombre Canónico o `slug`), de modo que el servicio pueda responder
 *     409 Conflict sin inspeccionar excepciones del motor de base de datos.
 *   - La validación de negocio (cupo de 30, convalecencia, permisos, linaje
 *     canónico) NO reside aquí: es competencia de `ClanService` (Tarea 2.4)
 *     y de `LineageSynergyService` (Tarea 2.2). Este repositorio solo vela
 *     por la integridad estructural del plano relacional.
 */

declare(strict_types=1);

namespace Grimorio\Repositories;

use InvalidArgumentException;
use PDO;
use PDOException;

/**
 * Repositorio de hermandades: lectura, gobernanza y contadores de Dominio.
 */
final class ClanRepository
{
    /** Estado canónico de una hermandad en contienda (RF-05.3). */
    private const STATUS_ACTIVE = 'active';

    /**
     * Estado canónico de una hermandad disuelta (Herencia Ancestral).
     *
     * Público desde SPEC-08 (RF-03.7): la acreditación de gloria necesita
     * distinguir una casa en contienda de una disuelta sin repetir el literal.
     */
    public const STATUS_ARCHIVED = 'archived';

    /** Regímenes de admisión canónicos (RF-01.5). */
    private const ADMISSION_MODES = ['open', 'byApplication'];

    /**
     * Proyección canónica de una hermandad: la forma fundacional de SPEC-01
     * (id, slug, name, motto, created_at) ampliada por el Sistema de Clanes
     * (Tarea 1.1). Se declara una sola vez para que toda lectura devuelva
     * exactamente el mismo contrato.
     *
     * `domain_points` ya no figura: era un tercer contador del mismo concepto
     * que `weekly_points` y sin escritor alguno (Tarea 2.6).
     */
    private const CLAN_COLUMNS = 'id, slug, name, motto, created_at, '
        . 'coat_of_arms, lineage_type, admission_mode, status, patriarch_id, '
        . 'weekly_points, historical_points, last_activity_at, updated_at';

    /**
     * Censo de adeptos ACTIVOS de cada casa (RF-01.4), resuelto en la MISMA
     * lectura que la fila: el estandarte del Salón de Linajes exhibe la
     * ocupación «X/30» sin una segunda consulta por hermandad.
     *
     * `left_at IS NULL` señala la afiliación vigente; las filas cerradas son
     * historial ético (Artículo III) y NO ocupan cupo.
     */
    private const ACTIVE_MEMBER_COUNT = '(SELECT COUNT(*) FROM clan_members active_members '
        . 'WHERE active_members.clan_id = clans.id AND active_members.left_at IS NULL)';

    /** Conexión PDO del santuario (Singleton del front controller). */
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Funda una nueva hermandad (RF-01.2).
     *
     * La fila nace en estado `active`, sin puntos de Dominio y con el
     * fundador registrado como Patriarca o Matriarca. La vigencia de las
     * marcas temporales (`created_at`, `updated_at`, `last_activity_at`)
     * arranca en el mismo instante de la fundación.
     *
     * @param string $clanId       Identificador textual de la hermandad.
     * @param string $slug         Enlace público único (derivado del nombre).
     * @param string $name         Nombre Canónico único (4 a 50 caracteres).
     * @param string $motto        Lema heráldico en noble castellano.
     * @param string $coatOfArms   Blasón rúnico del estandarte.
     * @param string $lineageType  Uno de los 8 Linajes Canónicos (RF-02.1).
     * @param string $admissionMode Régimen de admisión ('open'|'byApplication').
     * @param string $patriarchId  Usuario consagrado que ciñe la corona.
     * @param string $nowUtc       Instante de la fundación (ISO 8601 UTC).
     *
     * @return array{
     *   id: string, slug: string, name: string, motto: string,
     *   coat_of_arms: string, lineage_type: string, admission_mode: string,
     *   status: string, patriarch_id: string|null,
     *   weekly_points: int, historical_points: int,
     *   last_activity_at: string, created_at: string, updated_at: string
     * }|null La hermandad fundada, o null si su identidad ya estaba reservada.
     *
     * @throws InvalidArgumentException Si el régimen de admisión rompe el canon.
     */
    public function createClan(
        string $clanId,
        string $slug,
        string $name,
        string $motto,
        string $coatOfArms,
        string $lineageType,
        string $admissionMode,
        string $patriarchId,
        string $nowUtc
    ): ?array {
        $this->assertAdmissionMode($admissionMode);

        $statement = $this->pdo->prepare(
            'INSERT INTO clans (
                 id, slug, name, motto, created_at,
                 coat_of_arms, lineage_type, admission_mode, status, patriarch_id,
                 weekly_points, historical_points, last_activity_at, updated_at
             ) VALUES (
                 :clanId, :slug, :name, :motto, :createdAt,
                 :coatOfArms, :lineageType, :admissionMode, :status, :patriarchId,
                 0, 0, :lastActivityAt, :updatedAt
             )'
        );

        try {
            $statement->execute([
                ':clanId'         => $clanId,
                ':slug'           => $slug,
                ':name'           => $name,
                ':motto'          => $motto,
                ':createdAt'      => $nowUtc,
                ':coatOfArms'     => $coatOfArms,
                ':lineageType'    => $lineageType,
                ':admissionMode'  => $admissionMode,
                ':status'         => self::STATUS_ACTIVE,
                ':patriarchId'    => $patriarchId,
                ':lastActivityAt' => $nowUtc,
                ':updatedAt'      => $nowUtc,
            ]);
        } catch (PDOException $exception) {
            // Identidad ya reclamada: el Nombre Canónico (incluidos los de
            // hermandades disueltas, RF-05.4) o el `slug` público. Se informa
            // con null en lugar de propagar el detalle del motor de datos.
            if ($this->isUniqueConstraintViolation($exception)) {
                return null;
            }

            throw $exception;
        }

        return $this->findById($clanId);
    }

    /**
     * Recupera una hermandad por su identificador (RF-01.3).
     *
     * @return array{
     *   id: string, slug: string, name: string, motto: string,
     *   coat_of_arms: string, lineage_type: string, admission_mode: string,
     *   status: string, patriarch_id: string|null,
     *   weekly_points: int, historical_points: int,
     *   last_activity_at: string, created_at: string, updated_at: string
     * }|null
     */
    public function findById(string $clanId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::CLAN_COLUMNS . ' FROM clans WHERE id = :clanId'
        );
        $statement->execute([':clanId' => $clanId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Recupera una hermandad por su Nombre Canónico (RF-01.2).
     *
     * Busca sobre TODAS las hermandades, incluidas las disueltas: el nombre
     * de un clan archivado permanece inmortalizado (RF-05.4).
     *
     * @return array{
     *   id: string, slug: string, name: string, motto: string,
     *   coat_of_arms: string, lineage_type: string, admission_mode: string,
     *   status: string, patriarch_id: string|null,
     *   weekly_points: int, historical_points: int,
     *   last_activity_at: string, created_at: string, updated_at: string
     * }|null
     */
    public function findByName(string $name): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::CLAN_COLUMNS . ' FROM clans WHERE name = :name'
        );
        $statement->execute([':name' => $name]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Determina si un Nombre Canónico está disponible (RF-01.2, RF-05.4).
     *
     * La consulta NO filtra por estado a propósito: el nombre de una
     * hermandad disuelta queda reservado a perpetuidad y jamás puede ser
     * usurpado por una casa nueva.
     */
    public function isNameAvailable(string $name): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM clans WHERE name = :name');
        $statement->execute([':name' => $name]);

        return (int) $statement->fetchColumn() === 0;
    }

    /**
     * Actualiza el lema heráldico y el blasón rúnico (RF-01.3).
     *
     * @return bool Cierto si la hermandad existe y quedó modificada.
     */
    public function updateMottoAndHeraldry(
        string $clanId,
        string $motto,
        string $coatOfArms,
        string $nowUtc
    ): bool {
        $statement = $this->pdo->prepare(
            'UPDATE clans
                SET motto = :motto,
                    coat_of_arms = :coatOfArms,
                    updated_at = :updatedAt
              WHERE id = :clanId'
        );
        $statement->execute([
            ':motto'      => $motto,
            ':coatOfArms' => $coatOfArms,
            ':updatedAt'  => $nowUtc,
            ':clanId'     => $clanId,
        ]);

        return $this->wasApplied($clanId, $statement->rowCount());
    }

    /**
     * Refresca la marca de actividad viva de la hermandad (RF-01.9).
     *
     * El velatorio dinástico de cuarenta y cinco días se mide sobre
     * `last_activity_at`: todo acto de gobierno del Patriarca —admitir,
     * expulsar, mudar el lema o el régimen— es señal de vida y debe posponer
     * la sucesión. Sin esta estampa, un Patriarca diligentísimo que jamás
     * transfiere la corona parecería inactivo.
     *
     * @return bool Cierto si la hermandad existe y quedó estampada.
     */
    public function touchActivity(string $clanId, string $nowUtc): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE clans
                SET last_activity_at = :lastActivityAt,
                    updated_at = :updatedAt
              WHERE id = :clanId'
        );
        $statement->execute([
            ':lastActivityAt' => $nowUtc,
            ':updatedAt'      => $nowUtc,
            ':clanId'         => $clanId,
        ]);

        return $this->wasApplied($clanId, $statement->rowCount());
    }

    /**
     * Conmuta el régimen de admisión entre `open` y `byApplication` (RF-01.5).
     *
     * @throws InvalidArgumentException Si el régimen rompe el canon.
     */
    public function updateAdmissionMode(string $clanId, string $admissionMode, string $nowUtc): bool
    {
        $this->assertAdmissionMode($admissionMode);

        $statement = $this->pdo->prepare(
            'UPDATE clans
                SET admission_mode = :admissionMode,
                    updated_at = :updatedAt
              WHERE id = :clanId'
        );
        $statement->execute([
            ':admissionMode' => $admissionMode,
            ':updatedAt'     => $nowUtc,
            ':clanId'        => $clanId,
        ]);

        return $this->wasApplied($clanId, $statement->rowCount());
    }

    /**
     * Ciñe la corona de Patriarca a otro adepto de la hermandad (RF-01.3).
     *
     * Cierra también la ventana del Patriarca saliente: transferir el cetro
     * es señal de actividad viva de la casa (RF-01.9), por lo que se refresca
     * `last_activity_at` en el mismo movimiento.
     *
     * @throws InvalidArgumentException Si el identificador del nuevo líder es vacío.
     */
    public function updatePatriarch(string $clanId, string $patriarchId, string $nowUtc): bool
    {
        if (trim($patriarchId) === '') {
            throw new InvalidArgumentException(
                'La corona del Patriarca requiere un adepto consagrado que la ciña.'
            );
        }

        $statement = $this->pdo->prepare(
            'UPDATE clans
                SET patriarch_id = :patriarchId,
                    last_activity_at = :lastActivityAt,
                    updated_at = :updatedAt
              WHERE id = :clanId'
        );
        $statement->execute([
            ':patriarchId'    => $patriarchId,
            ':lastActivityAt' => $nowUtc,
            ':updatedAt'      => $nowUtc,
            ':clanId'         => $clanId,
        ]);

        return $this->wasApplied($clanId, $statement->rowCount());
    }

    /**
     * Disuelve una hermandad hacia el estado `archived` (RF-05.3).
     *
     * Un clan disuelto carece de Patriarca en funciones: la corona se libera
     * (por eso `patriarch_id` admite nulos) mientras el historial de quién la
     * ciñó permanece intacto en `clan_members`. Los conjuros validados y el
     * Nombre Canónico quedan preservados como Herencia Ancestral (RF-05.3,
     * RF-05.4): jamás se borra la fila.
     *
     * @return bool Cierto si la hermandad existe y quedó disuelta.
     */
    public function setStatusArchived(string $clanId, string $nowUtc): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE clans
                SET status = :status,
                    patriarch_id = NULL,
                    updated_at = :updatedAt
              WHERE id = :clanId'
        );
        $statement->execute([
            ':status'    => self::STATUS_ARCHIVED,
            ':updatedAt' => $nowUtc,
            ':clanId'    => $clanId,
        ]);

        return $this->wasApplied($clanId, $statement->rowCount());
    }

    /**
     * Acredita Puntos de Dominio Arcano a los contadores de la hermandad
     * (RF-03.1, RF-04.3, RNF-01).
     *
     * El incremento es UN solo `UPDATE` atómico: los contadores vigentes se
     * leen y se reescriben dentro de la misma sentencia, sin ventanas de
     * carrera. Los incrementos se reciben explícitos —durante la contienda
     * semanal solo se acredita `weeklyPoints` (`historicalPoints` viaja a 0)
     * y el pliegue perpetuo lo ejecuta `resetAllWeeklyPointsToZero()` en el
     * cierre dominical, evitando así contar dos veces la misma gloria.
     *
     * @return bool Cierto si la hermandad existe y quedó acreditada.
     */
    public function addWeeklyAndHistoricalPoints(
        string $clanId,
        int $weeklyPoints,
        int $historicalPoints,
        string $nowUtc
    ): bool {
        $statement = $this->pdo->prepare(
            'UPDATE clans
                SET weekly_points = weekly_points + :weeklyPoints,
                    historical_points = historical_points + :historicalPoints,
                    updated_at = :updatedAt
              WHERE id = :clanId'
        );
        $statement->execute([
            ':weeklyPoints'     => $weeklyPoints,
            ':historicalPoints' => $historicalPoints,
            ':updatedAt'        => $nowUtc,
            ':clanId'           => $clanId,
        ]);

        return $this->wasApplied($clanId, $statement->rowCount());
    }

    /**
     * Cierra la semana del santuario (RF-04.3).
     *
     * Pliega el marcador semanal de TODAS las hermandades sobre su Puntuación
     * Histórica Total y reinicia a cero los contadores semanales en una única
     * sentencia determinista, ciega e inviolable: no hay margen para la
     * alteración manual ni para el orden de ejecución (RNF-01). Las
     * hermandades disueltas también se pliegan para que su legado jamás
     * pierda gloria.
     *
     * @return int Número de hermandades alcanzadas por el pliegue.
     */
    public function resetAllWeeklyPointsToZero(string $nowUtc): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE clans
                SET historical_points = historical_points + weekly_points,
                    weekly_points = 0,
                    updated_at = :updatedAt'
        );
        $statement->execute([':updatedAt' => $nowUtc]);

        return $statement->rowCount();
    }

    /**
     * Clasificación semanal en vivo del Salón de los Linajes (RF-06.1).
     *
     * Solo hermandades activas; el orden desciende por PDA semanales y se
     * cierra con claves estables (`historical_points`, `created_at`, `id`)
     * para que dos consultas idénticas devuelvan el mismo orden (RNF-01).
     * El desempate canónico de RF-04.5 lo dirime WeeklyDominionService, que
     * dispone del censo de conjuros validados de la semana.
     *
     * @return list<array{
     *   id: string, slug: string, name: string, motto: string,
     *   coat_of_arms: string, lineage_type: string, admission_mode: string,
     *   status: string, patriarch_id: string|null,
     *   weekly_points: int, historical_points: int,
     *   last_activity_at: string, created_at: string, updated_at: string
     * }>
     */
    public function findActiveOrderedByWeeklyPointsDesc(): array
    {
        return $this->findActiveOrderedBy('weekly_points');
    }

    /**
     * Clasificación de Prestigio Histórico de todos los tiempos (RF-06.1).
     *
     * @return list<array{
     *   id: string, slug: string, name: string, motto: string,
     *   coat_of_arms: string, lineage_type: string, admission_mode: string,
     *   status: string, patriarch_id: string|null,
     *   weekly_points: int, historical_points: int,
     *   last_activity_at: string, created_at: string, updated_at: string
     * }>
     */
    public function findActiveOrderedByHistoricalPointsDesc(): array
    {
        return $this->findActiveOrderedBy('historical_points');
    }

    /**
     * Catálogo público filtrado de hermandades (plan 2.2, Endpoint 2).
     *
     * Ambos filtros son opcionales y llegan YA validados por el controlador
     * contra el canon (linaje de los ocho, estado `active`/`archived`). Viajan
     * SIEMPRE vinculados como parámetros —jamás interpolados—, de modo que la
     * sentencia permanece íntegramente preparada (AGENTS.md 6.1) y un filtro
     * nulo se traduce en «sin restricción» en el propio motor.
     *
     * El orden es determinista (RNF-01): gloria semanal descendente, gloria
     * perpetua como desempate, fundación más antigua y, en última instancia,
     * el identificador textual, para que dos lecturas idénticas devuelvan
     * idéntica página.
     *
     * @param string|null $lineageType Linaje rector exigido, o null para todos.
     * @param string|null $status      Estado exigido, o null para todos.
     * @param int         $limit       Filas de la página (> 0).
     * @param int         $offset      Filas omitidas (>= 0).
     *
     * @return list<array{
     *   id: string, slug: string, name: string, motto: string,
     *   coat_of_arms: string, lineage_type: string, admission_mode: string,
     *   status: string, patriarch_id: string|null,
     *   weekly_points: int, historical_points: int,
     *   last_activity_at: string, created_at: string, updated_at: string,
     *   member_count: int
     * }>
     *
     * @throws InvalidArgumentException Si la página solicitada no tiene sentido físico.
     */
    public function searchClans(?string $lineageType, ?string $status, int $limit, int $offset): array
    {
        if ($limit < 1 || $offset < 0) {
            throw new InvalidArgumentException(
                'El catálogo exige un límite positivo y un desplazamiento no negativo.'
            );
        }

        $statement = $this->pdo->prepare(
            'SELECT ' . self::CLAN_COLUMNS . ', '
            . self::ACTIVE_MEMBER_COUNT . ' AS member_count'
            . '
               FROM clans
              WHERE (:lineageType IS NULL OR lineage_type = :lineageTypeEq)
                AND (:status IS NULL OR status = :statusEq)
              ORDER BY weekly_points DESC, historical_points DESC, created_at ASC, id ASC
              LIMIT :limit OFFSET :offset'
        );

        // Marcadores con nombre PROPIO por uso (SPEC-13 §8.6): MySQL con
        // prepares nativos prohíbe reutilizar un parámetro nombrado (HY093).
        $statement->bindValue(
            ':lineageType',
            $lineageType,
            $lineageType === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue(
            ':lineageTypeEq',
            $lineageType,
            $lineageType === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue(
            ':status',
            $status,
            $status === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue(
            ':statusEq',
            $status,
            $status === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $clans = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $clans[] = $this->hydrate($row);
        }

        return $clans;
    }

    /**
     * Censo total de hermandades que satisfacen el filtro del catálogo.
     *
     * Es el metadato `totalItems` de la paginación: se cuenta con EXACTAMENTE
     * los mismos predicados que `searchClans()`, de modo que página y total
     * jamás puedan divergir.
     */
    public function countClans(?string $lineageType, ?string $status): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM clans
              WHERE (:lineageType IS NULL OR lineage_type = :lineageTypeEq)
                AND (:status IS NULL OR status = :statusEq)'        );
        // Marcadores con nombre PROPIO por uso (SPEC-13 §8.6).
        $statement->bindValue(
            ':lineageType',
            $lineageType,
            $lineageType === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue(
            ':lineageTypeEq',
            $lineageType,
            $lineageType === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue(
            ':status',
            $status,
            $status === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue(
            ':statusEq',
            $status,
            $status === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * Ejecuta la clasificación de hermandades activas por el contador dado.
     *
     * El nombre de la columna NO llega del exterior: solo se admite una de
     * las dos claves canónicas mediante una lista blanca estricta, de modo
     * que la sentencia sigue siendo íntegramente preparada.
     *
     * @return list<array{
     *   id: string, slug: string, name: string, motto: string,
     *   coat_of_arms: string, lineage_type: string, admission_mode: string,
     *   status: string, patriarch_id: string|null,
     *   weekly_points: int, historical_points: int,
     *   last_activity_at: string, created_at: string, updated_at: string
     * }>
     */
    private function findActiveOrderedBy(string $counterColumn): array
    {
        // Lista blanca: la columna de orden jamás se interpola desde fuera.
        if (!in_array($counterColumn, ['weekly_points', 'historical_points'], true)) {
            throw new InvalidArgumentException('Contador de Dominio no canónico para la clasificación.');
        }

        // El censo de adeptos viaja con la fila: el Salón de los Linajes lo
        // exhibe en cada estandarte de la clasificación (RF-06.1).
        $statement = $this->pdo->prepare(
            'SELECT ' . self::CLAN_COLUMNS . ', '
            . self::ACTIVE_MEMBER_COUNT . ' AS member_count'
            . '
               FROM clans
              WHERE status = :status
              ORDER BY ' . $counterColumn . ' DESC, historical_points DESC, created_at ASC, id ASC'
        );
        $statement->execute([':status' => self::STATUS_ACTIVE]);

        $clans = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $clans[] = $this->hydrate($row);
        }

        return $clans;
    }

    /**
     * Normaliza una fila del plano relacional a un array tipado de PHP.
     *
     * Los contadores se convierten a `int` y el Patriarca conserva su nulo
     * (hermandad disuelta sin corona), evitando que las cadenas del motor de
     * datos se filtren a las capas superiores.
     *
     * @param array<string, mixed> $row
     *
     * @return array{
     *   id: string, slug: string, name: string, motto: string,
     *   coat_of_arms: string, lineage_type: string, admission_mode: string,
     *   status: string, patriarch_id: string|null,
     *   weekly_points: int, historical_points: int,
     *   last_activity_at: string, created_at: string, updated_at: string
     * }
     */
    private function hydrate(array $row): array
    {
        $hydrated = [
            'id'                => (string) $row['id'],
            'slug'              => (string) $row['slug'],
            'name'              => (string) $row['name'],
            'motto'             => (string) $row['motto'],
            'coat_of_arms'      => (string) $row['coat_of_arms'],
            'lineage_type'      => (string) $row['lineage_type'],
            'admission_mode'    => (string) $row['admission_mode'],
            'status'            => (string) $row['status'],
            'patriarch_id'      => $row['patriarch_id'] === null ? null : (string) $row['patriarch_id'],
            'weekly_points'     => (int) $row['weekly_points'],
            'historical_points' => (int) $row['historical_points'],
            'last_activity_at'  => (string) $row['last_activity_at'],
            'created_at'        => (string) $row['created_at'],
            'updated_at'        => (string) $row['updated_at'],
        ];

        // El censo de adeptos solo aparece cuando la lectura lo resolvió
        // (catálogo paginado, Endpoint 2); las lecturas simples lo omiten y
        // el servicio lo completa por su cuenta (Endpoints 3, 6 y 9).
        if (array_key_exists('member_count', $row)) {
            $hydrated['member_count'] = (int) $row['member_count'];
        }

        return $hydrated;
    }

    /**
     * Confirma que una mutación alcanzó su objetivo.
     *
     * Algunos motores (MySQL sin CLIENT_FOUND_ROWS) reportan cero filas
     * afectadas cuando los valores enviados coinciden con los ya guardados;
     * para no mentir sobre el éxito se confirma la existencia de la
     * hermandad antes de declarar el fallo.
     */
    private function wasApplied(string $clanId, int $affectedRows): bool
    {
        if ($affectedRows > 0) {
            return true;
        }

        return $this->findById($clanId) !== null;
    }

    /**
     * Vela por el canon de los regímenes de admisión (RF-01.5).
     *
     * @throws InvalidArgumentException Si el régimen no pertenece al canon.
     */
    private function assertAdmissionMode(string $admissionMode): void
    {
        if (!in_array($admissionMode, self::ADMISSION_MODES, true)) {
            throw new InvalidArgumentException(
                'El régimen de admisión solo admite el canon abierto o bajo petición.'
            );
        }
    }

    /**
     * Distingue una colisión de unicidad de cualquier otro fallo del motor.
     *
     * SQLite y MySQL responden con SQLSTATE 23000 («integrity constraint
     * violation») acompañado de la leyenda de unicidad; PostgreSQL emplea
     * 23505. Se aísla esta condición para traducirla a un resultado de
     * negocio (identidad reservada) sin enmascarar el resto de errores.
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
