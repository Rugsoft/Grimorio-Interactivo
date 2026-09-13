<?php

/**
 * GrimoireQueryService.php — Consulta y segmentación del catálogo del Tomo.
 *
 * Tarea 1.2 (TASKS-05): servicio de lectura del Simulador de Grimorio.
 * Segmenta el catálogo en dos tomos (SPEC-05, RF-01.2):
 *   - TOMO CANÓNICO (público): exclusivamente conjuros en estado
 *     `validated`, accesible para anónimos y autenticados.
 *   - MIS ENSAYOS ARCENOS (privado): los conjuros del autor autenticado
 *     en estado `draft` o `experimental`, con aislamiento estricto por
 *     titular (nadie hojea los ensayos ajenos, Artículo III).
 *
 * Ambos modos entregan GrimoirePageDto (Tarea 1.1) con autoría y linaje
 * resueltos por JOIN, filtrado determinista por Círculo Arcano (1-5) y
 * Afinidad Elemental, y paginación acotada con hasPrevious/hasNext.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, 100% consultas preparadas
 *     con parameter binding (AGENTS.md 6.1), cero concatenación de strings.
 *   - Artículo III: aislamiento estricto de ensayos por autor titular.
 *   - Artículo V: identificadores en inglés camelCase, documentación castellana.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use Grimorio\Dto\GrimoirePageDto;
use Grimorio\Models\User;
use PDO;

/**
 * Consulta paginada y filtrada del catálogo de páginas del grimorio.
 */
final class GrimoireQueryService
{
    /** Límite máximo de páginas por hoja (contrato del plan 2.1). */
    private const MAX_LIMIT = 50;

    /** Límite por defecto de páginas por hoja (contrato del plan 2.1). */
    private const DEFAULT_LIMIT = 10;

    /** Conexión PDO del santuario (Singleton del front controller). */
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * TOMO CANÓNICO (RF-01.2): devuelve exclusivamente los conjuros en
     * estado `validated`, accesible para cualquier visitante.
     *
     * @param int|null $circle  Círculo Arcano de filtrado (1-5) o null.
     * @param string|null $element Afinidad elemental canónica o null.
     * @param int $page  Número de hoja (base 1).
     * @param int $limit Tamaño de hoja (máx. 50).
     *
     * @return array{
     *     totalSpells: int, currentPage: int, totalPages: int,
     *     hasPrevious: bool, hasNext: bool,
     *     spells: list<GrimoirePageDto>
     * }
     */
    public function getCanonicalSpells(?int $circle, ?string $element, int $page, int $limit): array
    {
        return $this->queryPagedPages(
            statusFilter: 'validated',
            authorId: null,
            circle: $circle,
            element: $element,
            page: $page,
            limit: $limit,
        );
    }

    /**
     * MIS ENSAYOS ARCENOS (RF-01.4): los conjuros propios del autor
     * autenticado en estado `draft` o `experimental`, con aislamiento
     * estricto por titular (nadie ajeno los consulta jamás).
     *
     * @param int|null $circle  Círculo Arcano de filtrado (1-5) o null.
     * @param string|null $element Afinidad elemental canónica o null.
     * @param int $page  Número de hoja (base 1).
     * @param int $limit Tamaño de hoja (máx. 50).
     *
     * @return array{
     *     totalSpells: int, currentPage: int, totalPages: int,
     *     hasPrevious: bool, hasNext: bool,
     *     spells: list<GrimoirePageDto>
     * }
     */
    public function getAuthorEssays(User $author, ?int $circle, ?string $element, int $page, int $limit): array
    {
        return $this->queryPagedPages(
            statusFilter: ['draft', 'experimental'],
            authorId: $author->getId(),
            circle: $circle,
            element: $element,
            page: $page,
            limit: $limit,
        );
    }

    /**
     * Detalle litúrgico individual (plan 2.1, Endpoint 2).
     *
     * Reglas de acceso: los validados son públicos; los draft/experimental
     * solo se entregan a su titular autenticado (Artículo III). El método
     * retorna null ante identificadores inexistentes o ajenos.
     *
     * @param string $spellId Identificador del conjuro (spl_*).
     * @param User|null $reader Usuario de sesión (null = visitante anónimo).
     */
    public function getSpellDetail(string $spellId, ?User $reader): ?GrimoirePageDto
    {
        $statement = $this->pdo->prepare(
            'SELECT s.*, u.alias AS author_alias, c.name AS clan_name
             FROM spells s
             INNER JOIN users u ON u.id = s.author_id
             INNER JOIN clans c ON c.id = s.clan_id
             WHERE s.id = :spellId'
        );
        $statement->execute([':spellId' => $spellId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $status = (string) $row['status'];
        if ($status !== 'validated') {
            // Estados no sellados: solo el titular autenticado los consulta.
            if ($reader === null || $reader->getId() !== (string) $row['author_id']) {
                return null;
            }
        }

        return GrimoirePageDto::fromDatabaseRow($row);
    }

    /**
     * Motor común de consulta paginada y filtrada de páginas del tomo.
     *
     * @param string|list<string> $statusFilter Estado canónico único o lista.
     * @param string|null $authorId Titular para aislamiento (null = tomo público).
     *
     * @return array{
     *     totalSpells: int, currentPage: int, totalPages: int,
     *     hasPrevious: bool, hasNext: bool,
     *     spells: list<GrimoirePageDto>
     * }
     */
    private function queryPagedPages(
        string|array $statusFilter,
        ?string $authorId,
        ?int $circle,
        ?string $element,
        int $page,
        int $limit,
    ): array {
        // Defensas de canon de la hoja: números fuera de rango degradan
        // a sus valores por defecto (nunca OFFSET negativo ni LIMIT 0).
        $safeLimit = max(1, min(self::MAX_LIMIT, $limit));
        $safePage = max(1, $page);
        $offset = ($safePage - 1) * $safeLimit;

        $statusList = is_array($statusFilter) ? $statusFilter : [$statusFilter];

        // Cláusulas dinámicas SOLO con placeholders: cada filtro se vincula
        // por parámetro (jamás interpolado — AGENTS.md 6.1).
        $whereClauses = [];
        $bindings = [];

        $statusPlaceholders = [];
        foreach ($statusList as $index => $status) {
            $placeholder = ':status' . $index;
            $statusPlaceholders[] = $placeholder;
            $bindings[$placeholder] = $status;
        }
        $whereClauses[] = 's.status IN (' . implode(', ', $statusPlaceholders) . ')';

        if ($authorId !== null) {
            $whereClauses[] = 's.author_id = :authorId';
            $bindings[':authorId'] = $authorId;
        }

        if ($circle !== null) {
            $whereClauses[] = 's.circle = :circle';
            $bindings[':circle'] = max(1, min(5, $circle));
        }

        if ($element !== null && $element !== '') {
            $whereClauses[] = 's.elemental_affinity = :element';
            $bindings[':element'] = $element;
        }

        $whereSql = implode(' AND ', $whereClauses);

        // Cómputo del total del tomo filtrado (para totalPages del contrato).
        $countStatement = $this->pdo->prepare(
            'SELECT COUNT(*) AS total
             FROM spells s
             WHERE ' . $whereSql
        );
        $countStatement->execute($bindings);
        $totalSpells = (int) ($countStatement->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        // Hoja de páginas con autoría y linaje resueltos por JOIN.
        $bindings[':limitValue'] = $safeLimit;
        $bindings[':offsetValue'] = $offset;

        $statement = $this->pdo->prepare(
            'SELECT s.*, u.alias AS author_alias, c.name AS clan_name
             FROM spells s
             INNER JOIN users u ON u.id = s.author_id
             INNER JOIN clans c ON c.id = s.clan_id
             WHERE ' . $whereSql . '
             ORDER BY s.circle ASC, s.name ASC
             LIMIT :limitValue OFFSET :offsetValue'
        );
        $statement->execute($bindings);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $pages = array_map(
            static fn (array $row): GrimoirePageDto => GrimoirePageDto::fromDatabaseRow($row),
            $rows,
        );

        $totalPages = max(1, (int) ceil($totalSpells / $safeLimit));

        return [
            'totalSpells' => $totalSpells,
            'currentPage' => $safePage,
            'totalPages'  => $totalPages,
            'hasPrevious' => $safePage > 1,
            'hasNext'     => $safePage < $totalPages && ($safePage * $safeLimit) < $totalSpells,
            'spells'      => $pages,
        ];
    }
}
