<?php

/**
 * SpellDiscoveryService.php — Lógica de descubrimiento arcano del portal.
 *
 * Tarea 1.4 (TASKS-01): destacados con fallback de génesis, catálogo paginado
 * y ficha por slug, todo mediante PDO con 100% consultas preparadas (AGENTS.md 2.1).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): SQL nativo PDO, sin ORM.
 *   - Artículo III: el catálogo base solo expone 'validated'; los 'experimental'
 *     requieren la bandera includeExperimental (Archivos Experimentales, RF-03.2).
 *   - Artículo V: identificadores en inglés camelCase, documentación en castellano.
 *
 * Reglas del plan que implementa:
 *   - RF-01.2: destacados = 3 validados más recientes por validated_at DESC.
 *   - RF-01.3: si hay menos de 3 validados de usuarios, los huecos se completan
 *     con Pergaminos Primordiales (is_genesis_sample = 1), nunca editables.
 *   - RF-03.1: catálogo ordenado por validated_at DESC.
 *   - RF-03.3/3.4: búsqueda insensible a acentos/mayúsculas, mínimo 2 caracteres,
 *     truncada a 100 (plan 5.1).
 *   - RF-03.5: escuelas en unión OR (IN (...)).
 *   - RF-03.6: tope de maná inclusivo (<=).
 *   - RF-03.7: paginación offset/limit con hasMore.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use Grimorio\Database\Connection;
use Grimorio\Dto\ClanLegacySpellDto;
use Grimorio\Models\Spell;
use PDO;

final class SpellDiscoveryService
{
    /** Tope del término de búsqueda (RF-03.4: 100 caracteres). */
    private const QUERY_MAX_LENGTH = 100;

    /** Longitud mínima de la query para que filtre (plan 5.1). */
    private const QUERY_MIN_LENGTH = 2;

    /** Mapa de diacríticos castellanos -> letra base (normalización sin ext/intl). */
    private const DIACRITICS_MAP = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
        'ã' => 'a', 'õ' => 'o', 'ñ' => 'n', 'ç' => 'c',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u',
        'À' => 'a', 'È' => 'e', 'Ì' => 'i', 'Ò' => 'o', 'Ù' => 'u',
        'Â' => 'a', 'Ê' => 'e', 'Î' => 'i', 'Ô' => 'o', 'Û' => 'u',
        'Ã' => 'a', 'Õ' => 'o', 'Ñ' => 'n', 'Ç' => 'c',
    ];

    private Connection $connection;

    /** Indica si la función de normalización ya fue registrada en la conexión. */
    private bool $normalizationReady = false;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Registra la UDF nativa 'norm' en SQLite para normalizar textos en SQL
     * (búsqueda insensible a acentos/mayúsculas dentro del motor, RF-03.3).
     * Es una función de la propia API de PDO SQLite: Dogma Vanilla (Art. I).
     * En otros motores (MySQL) no se registra y la búsqueda usa LIKE directo.
     */
    private function ensureSearchNormalizationReady(): void
    {
        if ($this->normalizationReady) {
            return;
        }

        $pdo = $this->connection->getPdo();

        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            // UDF 'norm': delega en el normalizador estático unificado para que
            // AMBOS lados de la comparación (texto almacenado y query del usuario)
            // sufran la misma transformación: minúsculas + sin diacríticos.
            $pdo->sqliteCreateFunction(
                'norm',
                static fn (string $rawText): string => self::normalizeText($rawText),
                1
            );
        }

        $this->normalizationReady = true;
    }

    /**
     * Retorna los 3 hechizos destacados del portal (RF-01.1/01.2/01.3).
     *
     * Estrategia de génesis: los validados de usuarios más recientes llenan la
     * galería; los huecos restantes (hasta 3) se completan con Pergaminos
     * Primordiales canónicos marcados con isGenesisSample = 1.
     *
     * @return Spell[] Exactamente 3 entidades.
     */
    public function getFeaturedSpells(): array
    {
        $pdo = $this->connection->getPdo();

        // Los validados de usuarios más recientes (excluye la génesis canónica).
        $userSpellsStatement = $pdo->prepare(
            'SELECT s.*, c.name AS clan_name
             FROM spells s
             INNER JOIN clans c ON c.id = s.clan_id
             WHERE s.status = :statusValidated
               AND s.is_genesis_sample = 0
             ORDER BY s.validated_at DESC
             LIMIT :featuredLimit'
        );
        $userSpellsStatement->bindValue(':statusValidated', 'validated', PDO::PARAM_STR);
        $userSpellsStatement->bindValue(':featuredLimit', 3, PDO::PARAM_INT);
        $userSpellsStatement->execute();
        $userSpells = $userSpellsStatement->fetchAll();

        $featured = array_map(
            static fn (array $row): Spell => Spell::fromDatabaseRow($row),
            $userSpells
        );

        $missingCount = 3 - count($featured);
        if ($missingCount > 0) {
            // Huecos de la galería: pergaminos primordiales canónicos (RF-01.3).
            $genesisStatement = $pdo->prepare(
                'SELECT s.*, c.name AS clan_name
                 FROM spells s
                 INNER JOIN clans c ON c.id = s.clan_id
                 WHERE s.is_genesis_sample = 1
                 ORDER BY s.validated_at ASC
                 LIMIT :genesisLimit'
            );
            $genesisStatement->bindValue(':genesisLimit', $missingCount, PDO::PARAM_INT);
            $genesisStatement->execute();
            $genesisSpells = $genesisStatement->fetchAll();

            $featured = array_merge(
                $featured,
                array_map(static fn (array $row): Spell => Spell::fromDatabaseRow($row), $genesisSpells)
            );
        }

        return $featured;
    }

    /**
     * Catálogo paginado y filtrable del portal (RF-03).
     *
     * @param string|null $query               Texto libre (insensible a acentos/mayúsculas).
     * @param string[]    $schools             Escuelas con lógica de unión OR.
     * @param int|null    $maxMana             Tope inclusivo de coste de maná (<=).
     * @param bool        $includeExperimental Revelar los Archivos Experimentales (RF-03.2).
     * @param int         $offset              Desplazamiento de paginación.
     * @param int         $limit               Tamaño de bloque (50 en producción).
     *
     * @return array{items: Spell[], hasMore: bool}
     */
    public function getSpells(
        ?string $query = null,
        array $schools = [],
        ?int $maxMana = null,
        bool $includeExperimental = false,
        int $offset = 0,
        int $limit = 50
    ): array {
        $pdo = $this->connection->getPdo();

        // La UDF 'norm' debe existir antes de construir la consulta del catálogo.
        $this->ensureSearchNormalizationReady();

        // --- Normalización de la query (plan 5.1) ---
        $normalizedQuery = null;
        if ($query !== null) {
            // RF-03.4: truncado duro a 100 caracteres antes de normalizar.
            $normalizedQuery = $this->normalizeSearchText(mb_substr($query, 0, self::QUERY_MAX_LENGTH));
            // Menos de 2 caracteres útiles: la query no filtra (plan 5.1).
            if (mb_strlen($normalizedQuery) < self::QUERY_MIN_LENGTH) {
                $normalizedQuery = null;
            }
        }

        // --- Construcción incremental de la cláusula WHERE (siempre preparada) ---
        $whereConditions = [];
        $bindParams = [];

        // Estado: solo validados, salvo que se revelen los experimentales (RF-03.2).
        if ($includeExperimental) {
            $whereConditions[] = "s.status IN ('validated', 'experimental')";
        } else {
            $whereConditions[] = 's.status = :statusValidated';
            $bindParams[':statusValidated'] = 'validated';
        }

        if ($normalizedQuery !== null) {
            // La comparación ocurre sobre textos ya normalizados por la UDF 'norm'.
            $whereConditions[] = '(norm(s.name) LIKE :searchPattern OR norm(s.summary) LIKE :searchPattern)';
            $bindParams[':searchPattern'] = '%' . $normalizedQuery . '%';
        }

        if ($schools !== []) {
            // RF-03.5: unión OR entre escuelas, con placeholders individuales vinculados.
            $schoolPlaceholders = [];
            foreach (array_values($schools) as $index => $school) {
                $placeholder = ':school' . $index;
                $schoolPlaceholders[] = $placeholder;
                $bindParams[$placeholder] = $school;
            }
            $whereConditions[] = 's.magic_school IN (' . implode(', ', $schoolPlaceholders) . ')';
        }

        if ($maxMana !== null) {
            // RF-03.6: tope inclusivo de maná.
            $whereConditions[] = 's.mana_cost <= :maxMana';
            $bindParams[':maxMana'] = $maxMana;
        }

        $whereClause = $whereConditions === [] ? '' : 'WHERE ' . implode(' AND ', $whereConditions);

        // Interpolación SOLO de la cláusula construida con cadenas fijas y
        // placeholders vinculados: ningún dato del usuario entra por concatenación.
        $catalogStatement = $pdo->prepare(
            "SELECT s.*, c.name AS clan_name
             FROM spells s
             INNER JOIN clans c ON c.id = s.clan_id
             {$whereClause}
             ORDER BY s.validated_at DESC, s.slug ASC
             LIMIT :offsetLimit OFFSET :offsetValue"
        );

        foreach ($bindParams as $paramName => $paramValue) {
            $catalogStatement->bindValue(
                $paramName,
                $paramValue,
                is_int($paramValue) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
        $catalogStatement->bindValue(':offsetLimit', $limit + 1, PDO::PARAM_INT);
        $catalogStatement->bindValue(':offsetValue', $offset, PDO::PARAM_INT);
        $catalogStatement->execute();

        $rows = $catalogStatement->fetchAll();

        // Si vino una fila extra, hay más resultados allá adelante (RF-03.7).
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        return [
            'items'   => array_map(static fn (array $row): Spell => Spell::fromDatabaseRow($row), $rows),
            'hasMore' => $hasMore,
        ];
    }

    /**
     * Ficha completa por slug (RF-04.1, RF-06.2).
     * Retorna null si el pergamino está desterrado; el controlador traduce a 404 místico.
     */
    public function getSpellBySlug(string $slug): ?Spell
    {
        $pdo = $this->connection->getPdo();

        $detailStatement = $pdo->prepare(
            'SELECT s.*, c.name AS clan_name
             FROM spells s
             INNER JOIN clans c ON c.id = s.clan_id
             WHERE s.slug = :slugValue'
        );
        $detailStatement->bindValue(':slugValue', $slug, PDO::PARAM_STR);
        $detailStatement->execute();

        $row = $detailStatement->fetch();

        return $row === false ? null : Spell::fromDatabaseRow($row);
    }

    /**
     * El legado sellado de una hermandad (RF-05.1, RF-05.3, Endpoint 13).
     *
     * Devuelve los conjuros RATIFICADOS concebidos bajo el estandarte del clan,
     * del más reciente al más antiguo. La consulta filtra por `s.clan_id`, no
     * por la autoría: por eso el patrimonio sobrevive intacto aunque su autor
     * haya partido o sido expulsado (RF-05.1), y por eso una casa disuelta
     * conserva su «Herencia Ancestral» (RF-05.3). El alias del autor viaja
     * como crédito perpetuo, resuelto por JOIN con `users`.
     *
     * Se incluyen los Pergaminos Primordiales de génesis cuando pertenecen al
     * linaje consultado: son obras ratificadas de pleno derecho.
     *
     * @param string $clanId Clave canónica de la hermandad (cln_*).
     * @return list<ClanLegacySpellDto> Legado ordenado por ratificación descendente.
     */
    public function getValidatedSpellsByClan(string $clanId): array
    {
        $pdo = $this->connection->getPdo();

        $legacyStatement = $pdo->prepare(
            'SELECT s.*, c.name AS clan_name, u.alias AS author_alias
             FROM spells s
             INNER JOIN clans c ON c.id = s.clan_id
             INNER JOIN users u ON u.id = s.author_id
             WHERE s.clan_id = :clanId
               AND s.status = :statusValidated
             ORDER BY s.validated_at DESC, s.slug ASC'
        );
        $legacyStatement->bindValue(':clanId', $clanId, PDO::PARAM_STR);
        $legacyStatement->bindValue(':statusValidated', 'validated', PDO::PARAM_STR);
        $legacyStatement->execute();

        $rows = $legacyStatement->fetchAll();

        return array_map(
            static fn (array $row): ClanLegacySpellDto => ClanLegacySpellDto::fromSpell(
                Spell::fromDatabaseRow($row),
                (int) ($row['circle'] ?? 0),
                (string) ($row['author_alias'] ?? ''),
            ),
            $rows,
        );
    }

    /**
     * Normalizador unificado (plan 5.1): minúsculas, sin diacríticos, espacios
     * colapsados. Se aplica por igual a la query del usuario y, vía la UDF
     * 'norm' de SQLite, a los textos almacenados, garantizando que ambas
     * partes de la comparación vivan en el mismo espacio de búsqueda.
     * Implementación PHP pura (mapa de diacríticos castellanos), sin ext/intl.
     */
    private static function normalizeText(string $rawInput): string
    {
        $lowercased = mb_strtolower(trim($rawInput), 'UTF-8');
        $withoutAccents = strtr($lowercased, self::DIACRITICS_MAP);

        // Colapso de espacios en blanco múltiples a uno solo.
        return (string) preg_replace('/\s+/u', ' ', $withoutAccents);
    }

    /**
     * Normaliza el término de búsqueda entrante (lado usuario).
     */
    private function normalizeSearchText(string $rawInput): string
    {
        return self::normalizeText($rawInput);
    }
}
