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

use Grimorio\Dto\CollectionEntryDto;
use Grimorio\Dto\CollectionPageDto;
use Grimorio\Dto\GrimoirePageDto;
use Grimorio\Models\User;
use Grimorio\Repositories\GrimoireCollectionRepository;
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

    /** Repositorio inyectado (opcional) antes de la forja perezosa. */
    private ?GrimoireCollectionRepository $injectedCollectionRepository = null;

    /** Conexión PDO del santuario (Singleton del front controller). */
    private PDO $pdo;

    /** El repositorio del tomo personal: lecturas de colección (RF-02).
     *  Perezoso: solo los listados de colección lo invocan, de modo que
     *  los consumidores canónicos jamás cargan la mesa íntima. */
    private ?GrimoireCollectionRepository $collectionRepository = null;

    public function __construct(PDO $pdo, ?GrimoireCollectionRepository $collectionRepository = null)
    {
        $this->pdo = $pdo;
        $this->injectedCollectionRepository = $collectionRepository;
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
     * autenticado en estado `draft`, `experimental` o `rejected`, con
     * aislamiento estricto por titular (nadie ajeno los consulta jamás).
     *
     * El quinto estado entró aquí con el canon de SPEC-08 (Tarea 1.5 de
     * TASKS-08): un Dictamen de Objeción devuelve la obra a la libreta de su
     * autor (RF-02.6), y sin `rejected` en esta lista el conjuro vetado
     * desaparecería de su propio grimorio —el autor no podría leer la
     * objeción ni subsanarla (RF-06.2)—. El destierro soberano (`archived`)
     * queda fuera a propósito: no hay enmienda para una obra desterrada.
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
            statusFilter: ['draft', 'experimental', 'rejected'],
            authorId: $author->getId(),
            circle: $circle,
            element: $element,
            page: $page,
            limit: $limit,
        );
    }

    /** El canal del tomo, forjado solo a la primera llamada de colección. */
    private function collectionRepository(): GrimoireCollectionRepository
    {
        return $this->collectionRepository ??= $this->injectedCollectionRepository
            ?? new GrimoireCollectionRepository($this->pdo);
    }

    /**
     * TOMO PERSONAL (SPEC-11, RF-02.1, Tarea 3.3): la tercera vía del
     * catálogo — el tomo íntimo del adepto autenticado, enriquecido.
     *
     * Cada entrada porta su ficha litúrgica completa, el instante del
     * sellado, la marca solemne del mapa único (RF-03.2, que vive en
     * `GrimoireCollectionService` — este servicio la CONSUME, jamás la
     * duplica) y el estado del homenaje (plan §2.2): `praised` lee la
     * mesa de votos del Dominio en un solo lote (una consulta por
     * página, no una por fila) y `allowed` juzga la militancia viva de
     * la casa del hechizo (RF-04.4) junto al juicio de sellabilidad
     * (RF-04.5).
     *
     * El filtro de afinidad y la paginación de 50 viven en el
     * repositorio del tomo (índice de latencia, RNF-01); aquí solo se
     * ensambla la hoja enriquecida.
     *
     * @param User $adepto El titular del tomo (la guardia de sesión
     *        vive en el controlador).
     * @param string|null $element Afinidad elemental canónica o null.
     * @param int $page Número de hoja (base 1; candados en el repositorio).
     */
    public function getCollection(User $adepto, ?string $element, int $page): CollectionPageDto
    {
        $rows = $this->collectionRepository()->pageForUser($adepto->getId(), $element, $page);
        $total = $this->collectionRepository()->countForUser($adepto->getId(), $element);
        $limit = CollectionPageDto::PAGE_LIMIT;
        $totalPages = max(1, (int) ceil($total / $limit));
        $safePage = max(1, $page);

        if ($rows === []) {
            return new CollectionPageDto(entries: [], total: $total, page: $safePage, limit: $limit, totalPages: $totalPages);
        }

        // --- Fichas litúrgicas de la hoja en UNA consulta ----------------
        $spellIds = array_map(static fn (array $row): string => (string) $row['spell_id'], $rows);
        $spellRows = $this->spellRowsByIds($spellIds);

        // --- Votos del adepto sobre la hoja en UNA consulta (RF-04.0) ----
        $praisedIds = $this->praisedSpellIdsFor($adepto->getId(), $spellIds);

        // --- Militancia viva del adepto (RF-04.4): una sola lectura ------
        $adeptClanId = $this->activeClanIdFor($adepto->getId());

        $entries = [];
        foreach ($rows as $row) {
            $spellId = (string) $row['spell_id'];
            $spellRow = $spellRows[$spellId] ?? null;
            if ($spellRow === null) {
                // Un fantasma en el tomo no detiene la lectura: la
                // entrada se omite y el total la conserva (la memoria
                // del adepto es perpetua, RF-03.2).
                continue;
            }

            $status = (string) $spellRow['status'];
            $entries[] = new CollectionEntryDto(
                spell: GrimoirePageDto::fromDatabaseRow($spellRow),
                addedAt: (string) $row['added_at'],
                tomeMark: GrimoireCollectionService::tomeMarkForStatus($status),
                praiseStatus: [
                    'praised' => in_array($spellId, $praisedIds, true),
                    'allowed' => $status === GrimoireCollectionService::SPELL_STATUS_VALIDATED
                        && ($adeptClanId === null || $adeptClanId !== (string) $spellRow['clan_id']),
                ],
            );
        }

        return new CollectionPageDto(entries: $entries, total: $total, page: $safePage, limit: $limit, totalPages: $totalPages);
    }

    /**
     * Enriquecimiento embebido de un listado canónico/ensayos con el
     * estado del adepto (RF-04.0, hallazgo 5, Tarea 3.3).
     *
     * Resuelve `collected` (tomo personal) y `praised` (mesa de votos
     * del Dominio) para TODA la hoja en dos consultas de lote — jamás
     * una por fila (RNF-01: el presupuesto de latencia es finito) — y
     * porta cada DTO con su `adeptState` camelCase. La guardia de sesión
     * vive en el controlador: aquí el lector ya es autenticado.
     *
     * @param User $reader El adepto autenticado de la sesión.
     * @param list<GrimoirePageDto> $pages La hoja a enriquecer.
     * @return list<GrimoirePageDto> La hoja con `adeptState` en cada página.
     */
    public function embedAdeptState(User $reader, array $pages): array
    {
        if ($pages === []) {
            return $pages;
        }

        $spellIds = array_map(static fn (GrimoirePageDto $page): string => $page->id, $pages);

        $collectedIds = [];
        foreach ($this->collectionRepository()->spellIdsForUser($reader->getId()) as $collectedId) {
            $collectedIds[(string) $collectedId] = true;
        }
        $praisedIds = $this->praisedSpellIdsFor($reader->getId(), $spellIds);

        return array_map(
            static fn (GrimoirePageDto $page): GrimoirePageDto => $page->withAdeptState(
                isset($collectedIds[$page->id]),
                in_array($page->id, $praisedIds, true),
            ),
            $pages,
        );
    }

    /**
     * Filas de `spells` de un lote de identificadores, indexadas por id
     * (con autoría y casa resueltas por JOIN, la misma proyección del
     * motor común): una consulta por hoja, jamás una por fila.
     *
     * @param list<string> $spellIds
     * @return array<string, array<string, null|int|string>>
     */
    private function spellRowsByIds(array $spellIds): array
    {
        // Los identificadores llegan de la propia base (hoja del tomo);
        // se vinculan por parámetro placeholder a placeholder (AGENTS.md
        // 6.1: jamás interpolados).
        $placeholders = [];
        $bindings = [];
        foreach (array_values($spellIds) as $index => $spellId) {
            $placeholder = ':spellId' . $index;
            $placeholders[] = $placeholder;
            $bindings[$placeholder] = $spellId;
        }

        $statement = $this->pdo->prepare(
            'SELECT s.*, u.alias AS author_alias, c.name AS clan_name
             FROM spells s
             INNER JOIN users u ON u.id = s.author_id
             INNER JOIN clans c ON c.id = s.clan_id
             WHERE s.id IN (' . implode(', ', $placeholders) . ')'
        );
        $statement->execute($bindings);

        $indexed = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $indexed[(string) $row['id']] = $row;
        }

        return $indexed;
    }

    /**
     * Identificadores de la hoja que el adepto ya elogió (RF-04.0):
     * una consulta sobre la mesa de votos del Dominio. LECTURA pura:
     * este servicio jamás inserta ni borra en `favorites` (RF-05.4,
     * frontera sagrada del tomo).
     *
     * @param list<string> $spellIds
     * @return list<string>
     */
    private function praisedSpellIdsFor(string $adeptId, array $spellIds): array
    {
        $placeholders = [];
        $bindings = [':userId' => $adeptId];
        foreach (array_values($spellIds) as $index => $spellId) {
            $placeholder = ':spellId' . $index;
            $placeholders[] = $placeholder;
            $bindings[$placeholder] = $spellId;
        }

        $statement = $this->pdo->prepare(
            'SELECT spell_id FROM favorites
              WHERE user_id = :userId AND spell_id IN (' . implode(', ', $placeholders) . ')'
        );
        $statement->execute($bindings);

        /** @var list<string> */
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Casa activa del adepto (RF-04.4): la militancia viva que veda el
     * elogio hacia la propia casa. null = sin militancia activa.
     */
    private function activeClanIdFor(string $adeptId): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT clan_id FROM clan_members
              WHERE user_id = :userId AND left_at IS NULL
              LIMIT 1'
        );
        $statement->execute([':userId' => $adeptId]);
        $clanId = $statement->fetchColumn();

        return $clanId === false || $clanId === null ? null : (string) $clanId;
    }

    /**
     * Detalle litúrgico individual (plan 2.1, Endpoint 2).
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
