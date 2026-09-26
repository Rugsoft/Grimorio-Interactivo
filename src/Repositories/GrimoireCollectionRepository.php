<?php

/**
 * GrimoireCollectionRepository.php — Persistencia PDO del Tomo Personal
 * de cada adepto (SPEC-11, Tarea 1.2).
 *
 * Cubre: RF-05.4 (tabla nueva separada de `favorites`), RF-01.3 (sellado
 * único como invariante físico), RF-02.1 (orden por adición, el más
 * reciente primero), RF-02.3 (filtro por afinidad conservando el orden),
 * RNF-01 (índice de latencia, apertura < 100 ms) y RNF-02 (PDO nativo,
 * 100% consultas preparadas).
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): PDO nativo, 100% consultas preparadas con
 *     parameter binding; ningún valor del llamador se interpola jamás en
 *     el SQL. La única pieza ensamblada a mano es el límite de página,
 *     acotado por el propio repositorio a una lista cerrada (jamás entra
 *     un entero del llamador sin pasar por el candado).
 *   - Art. V (Dualidad): identificadores en inglés camelCase, claves de
 *     base de datos en snake_case, documentación en noble castellano.
 *
 * Reparto de responsabilidades: este repositorio MIDE y PERSISTE; no
 * juzga. Decidir si un hechizo es sellable (`validated`), si el adepto
 * tiene linaje jurado o qué marca solemne viste cada entrada son reglas
 * de negocio de `GrimoireCollectionService`; aquí solo viajan los hechos
 * —qué filas hay, en qué orden y cuántas son— para que aquel pueda dictar
 * 200 idempotente o 201 sin inspeccionar excepciones del motor de datos.
 *
 * Frontera sagrada (RF-05.4, hallazgo 13): esta mesa es la MEMORIA del
 * adepto. Jamás consulta ni toca `favorites` — la mesa de votos del
 * Dominio vive su vida y esta la suya: cada rito, su vida.
 */

declare(strict_types=1);

namespace Grimorio\Repositories;

use PDO;

/**
 * Repositorio del Tomo Personal: sellados, retiradas y páginas del tomo.
 *
 * La muralla de unicidad `(user_id, spell_id)` vive en la propia base
 * (Tarea 1.1): este repositorio no la replica, la honra. `add()` intenta
 * el INSERT y descifra la señal del motor para distinguir el sellado
 * nuevo (201) del ya presente (200 idempotente), sin carreras: aunque dos
 * pestañas compitan, la base solo admite una fila y la perdedora recibe
 * la señal de unicidad, no una excepción cruda.
 */
final class GrimoireCollectionRepository
{
    /** Único límite de página válido por ahora (caso límite 4). */
    private const PAGE_LIMIT = 50;

    /** El canal PDO de la mesa del tomo. */
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Sella un hechizo en el tomo del adepto (RF-01.1).
     *
     * Idempotente por diseño (RF-01.3): si el par `(userId, spellId)` ya
     * vive en la mesa, la base responde con su señal de unicidad y el
     * método devuelve `false` — «ya está en tu tomo» — sin duplicar fila
     * ni lanzar excepción cruda. Devuelve `true` solo cuando la fila es
     * nueva (el sellado original, digno de 201).
     *
     * @return bool `true` si el sellado es nuevo; `false` si ya estaba.
     */
    public function add(string $userId, string $spellId, string $addedAtUtc): bool
    {
        // Doble canal dialectal (SPEC-13): la cláusula de "ignorar si ya
        // vive" cambia de nombre entre motores — 'INSERT OR IGNORE' en
        // SQLite e 'INSERT IGNORE' en MySQL/MariaDB. La señal de
        // idempotencia (rowCount()) es idéntica en ambos.
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $insertClause = $driver === 'mysql' ? 'INSERT IGNORE INTO' : 'INSERT OR IGNORE INTO';

        $statement = $this->pdo->prepare(
            $insertClause . " grimoire_collections (id, user_id, spell_id, added_at)
             VALUES (:id, :userId, :spellId, :addedAt)"
        );
        $statement->execute([
            ':id' => $this->newIdentifier('tme'),
            ':userId' => $userId,
            ':spellId' => $spellId,
            ':addedAt' => $addedAtUtc,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * Retira una entrada del tomo del adepto (RF-02.4).
     *
     * Solo toca la fila del PROPIO adepto: el `WHERE` doble es la muralla
     * de intimidad del tomo (ningún adepto retira del tomo ajeno).
     * Devuelve `true` si había fila que retirar; `false` si el tomo no
     * la conocía (el servicio dictará el 409 solemne).
     */
    public function remove(string $userId, string $spellId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM grimoire_collections WHERE user_id = :userId AND spell_id = :spellId'
        );
        $statement->execute([':userId' => $userId, ':spellId' => $spellId]);

        return $statement->rowCount() === 1;
    }

    /**
     * Página del tomo del adepto (RF-02.1, RF-02.3, caso límite 4).
     *
     * Orden por adición, el más reciente primero, servido por el índice
     * de latencia `(user_id, added_at DESC)` (RNF-01). El desempate por
     * `id` garantiza paginación DETERMINISTA ante sellados de instante
     * idéntico (dos entradas del mismo segundo no pueden alternar entre
     * páginas): un orden total estable es parte del contrato de lectura.
     * El filtro de
     * afinidad (RF-02.3) cruza con `spells` — la afinidad vive en el
     * catálogo, no en el tomo — conservando el orden de adición.
     *
     * Cada fila entrega el mínimo que la vista necesita; el servicio
     * enriquece con las marcas solemnes (RF-03.2) y el estado del
     * homenaje (RF-04.0) — aquí no se juzga, solo se mide.
     *
     * @return list<array{id: string, spell_id: string, added_at: string}> Filas de la página.
     */
    public function pageForUser(string $userId, ?string $elementalAffinity, int $page): array
    {
        // Candado del límite: jamás entra un entero del llamador al SQL.
        $limit = self::PAGE_LIMIT;
        // Candado de página: bajo el mínimo 1, sobre el techo razonable.
        $safePage = max(1, min($page, 1000));
        $offset = ($safePage - 1) * $limit;

        if ($elementalAffinity !== null && $elementalAffinity !== '') {
            $statement = $this->pdo->prepare(
                'SELECT gc.id, gc.spell_id, gc.added_at
                 FROM grimoire_collections gc
                 JOIN spells s ON s.id = gc.spell_id
                 WHERE gc.user_id = :userId AND s.elemental_affinity = :element
                 ORDER BY gc.added_at DESC, gc.id DESC
                 LIMIT ' . $limit . ' OFFSET ' . $offset
            );
            $statement->execute([':userId' => $userId, ':element' => $elementalAffinity]);

            return $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        $statement = $this->pdo->prepare(
            'SELECT gc.id, gc.spell_id, gc.added_at
             FROM grimoire_collections gc
             WHERE gc.user_id = :userId
             ORDER BY gc.added_at DESC, gc.id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $statement->execute([':userId' => $userId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Total de entradas del tomo del adepto (RF-02.1, rótulo de conteo).
     *
     * Comparte el mismo criterio de filtro que `pageForUser()` para que
     * el total jamás describa un conjunto distinto del que se exhibe.
     */
    public function countForUser(string $userId, ?string $elementalAffinity = null): int
    {
        if ($elementalAffinity !== null && $elementalAffinity !== '') {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*)
                 FROM grimoire_collections gc
                 JOIN spells s ON s.id = gc.spell_id
                 WHERE gc.user_id = :userId AND s.elemental_affinity = :element'
            );
            $statement->execute([':userId' => $userId, ':element' => $elementalAffinity]);

            return (int) $statement->fetchColumn();
        }

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM grimoire_collections WHERE user_id = :userId'
        );
        $statement->execute([':userId' => $userId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * ¿Vive ya este hechizo en el tomo del adepto? (RF-01.3, RF-04.0)
     *
     * La consulta puntual que alimenta el estado embebido de los
     * listados y la guardia idempotente del servicio.
     */
    public function existsForUser(string $userId, string $spellId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM grimoire_collections WHERE user_id = :userId AND spell_id = :spellId'
        );
        $statement->execute([':userId' => $userId, ':spellId' => $spellId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Identificadores de todos los hechizos del tomo del adepto.
     *
     * La lectura en bloque con la que el servicio y la consulta del
     * catálogo calculan los estados embebidos (`collected`) sin una
     * consulta por fila.
     *
     * @return list<string> Identificadores de hechizo, sin orden garantizado.
     */
    public function spellIdsForUser(string $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT spell_id FROM grimoire_collections WHERE user_id = :userId'
        );
        $statement->execute([':userId' => $userId]);

        /** @var list<string> */
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Identificador textual nuevo para una fila del tomo (patrón del santuario). */
    private function newIdentifier(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(6));
    }
}
