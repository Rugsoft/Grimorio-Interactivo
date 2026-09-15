<?php

/**
 * SpellReviewRepository.php — Persistencia PDO del expediente de moderación
 * de cada conjuro (SPEC-08, Tarea 1.2).
 *
 * Cubre: RF-01.1 (los cinco estados), RF-01.2 (cupo de tres), RF-01.5
 * (liberación de cupo), RF-01.6 (letargo de 90 días), RF-02.1 (firmas 0/3),
 * RF-05.1 (cola del Atrio), RNF-01 (determinismo) y RNF-02 (sin carreras).
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): PDO nativo, 100% consultas preparadas con
 *     parameter binding; ningún valor del llamador se interpola jamás en el
 *     SQL. Las únicas piezas ensambladas a mano son nombres de columna
 *     elegidos de listas cerradas por el propio repositorio.
 *   - Art. II (Ley Universal del Maná): la huella del balance sellado
 *     (`math_fingerprint`, 64 caracteres) entra y permanece íntegra en la
 *     revisión: es lo que se juzga y jamás se recalcula aquí.
 *   - Art. V (Dualidad): identificadores en inglés camelCase, claves de base
 *     de datos en snake_case, documentación en noble castellano.
 *
 * Reparto de responsabilidades (Tareas 2.2 y 2.3): este repositorio MIDE y
 * PERSISTE; no juzga. Decidir si el autor agotó su cupo, si un Maestro está
 * vetado por el Artículo III o si un conjuro alcanzó la consagración son
 * reglas de negocio de los servicios; aquí solo viajan los hechos —cuántas
 * revisiones tiene un autor, qué firmas se estamparon y cuándo— para que
 * aquellos puedan dictar 409 Conflict o 403 Forbidden sin inspeccionar
 * excepciones del motor de datos.
 *
 * Autoridad del expediente: `spell_reviews` mantiene UNA fila por conjuro
 * (relación 1:1, `UNIQUE (spell_id)` en la Tarea 1.1). Toda la vida de la
 * obra —envío, retirada, objeción, re-apertura y destierro— se escribe
 * mutando esa única fila, de modo que la cola, el cupo del autor y el
 * contador de firmas jamás pueden leerse de dos lugares distintos. Este
 * repositorio es además el ÚNICO escritor del espejo `spells.status` /
 * `spells.signatures_count` (Tarea 1.5).
 */

declare(strict_types=1);

namespace Grimorio\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Repositorio del expediente de moderación: estado, firmas y cola.
 *
 * ESPEJO denormalizado de `spells` (Tarea 1.5): `spells.status` y
 * `spells.signatures_count` repiten el estado y el contador del expediente
 * para que el Tomo Canónico, el Atrio y la libreta del autor se consulten sin
 * cruzar `spell_reviews` en cada página. Este repositorio es su ÚNICO
 * escritor, y lo hace SIEMPRE dentro de la misma transacción que el
 * expediente: o ambas caras de la verdad cambian a la vez, o ninguna. Un
 * conjuro que nunca ha entrado a moderación no tiene expediente, y entonces
 * el espejo sostiene su estado embrionario (`draft`); en cuanto la autoridad
 * habla, el espejo la sigue sin voz propia.
 */
final class SpellReviewRepository
{
    /** Borrador privado: la libreta del autor, invisible al santuario. */
    public const STATUS_DRAFT = 'draft';

    /** Paso 1: obra en deliberación, expuesta en el Atrio de Pruebas. */
    public const STATUS_EXPERIMENTAL = 'experimental';

    /** Paso 2: obra consagrada e inscrita en el Gran Tomo Canónico. */
    public const STATUS_VALIDATED = 'validated';

    /** Obra vetada con observaciones y devuelta a la libreta del autor. */
    public const STATUS_REJECTED = 'rejected';

    /** Obra desterrada del canon o conservada como Herencia Ancestral. */
    public const STATUS_ARCHIVED = 'archived';

    /** Los cinco estados mutuamente excluyentes de RF-01.1. */
    public const CANONICAL_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_EXPERIMENTAL,
        self::STATUS_VALIDATED,
        self::STATUS_REJECTED,
        self::STATUS_ARCHIVED,
    ];

    /**
     * Cupo máximo de conjuros en deliberación simultánea por autor (RF-01.2).
     *
     * Fuente única de verdad del canon de tres: `ModerationWorkflowService`
     * (Tarea 2.3) lo consume desde aquí en lugar de repetir el número, y con
     * él se redacta tanto el bloqueo del envío como la liberación de cupo al
     * rechazar o consagrar (RF-01.5).
     */
    public const MAX_ACTIVE_REVIEWS_PER_AUTHOR = 3;

    /** Techo de firmas de consagración (RF-02.1). */
    public const MAX_SIGNATURES = 3;

    /** Longitud exacta de la huella SHA-256 del balance (Artículo II). */
    public const FINGERPRINT_LENGTH = 64;

    /** Días naturales de letargo sin resonancia colegiada (RF-01.6). */
    public const STALE_REVIEW_DAYS = 90;

    /**
     * Columna del ciclo de vida que cada estado sella al transicionar.
     *
     * `draft` escribe `reopened_at` porque la única manera de ENTRAR en un
     * borrador por transición es la re-apertura de RF-01.4; el borrador con
     * el que la obra nace no lleva marca (lo inscribe createOrUpdateReview).
     */
    private const STATUS_TIMESTAMP_COLUMNS = [
        self::STATUS_DRAFT        => 'reopened_at',
        self::STATUS_EXPERIMENTAL => 'submitted_at',
        self::STATUS_VALIDATED    => 'validated_at',
        self::STATUS_REJECTED     => 'rejected_at',
        self::STATUS_ARCHIVED     => 'archived_at',
    ];

    /**
     * Claves admitidas por la cola de deliberación (lista blanca cerrada).
     *
     * Toda clave ajena se rechaza con excepción: un filtro que no se entiende
     * no se ignora en silencio, porque una cola que devuelve de más es tan
     * peligrosa como una que devuelve de menos.
     */
    private const QUEUE_FILTERS = [
        'status',
        'authorId',
        'originClanId',
        'elementalAffinity',
        'magicSchool',
        'minSignatures',
        'limit',
        'offset',
    ];

    /**
     * Retrato de la obra que acompaña al expediente en la cola del Atrio y de
     * la Torre (Tarea 3.1).
     *
     * Los nombres son EXACTAMENTE los que `ModerationQueueItemDto::fromDatabaseRow()`
     * consume: `name` y `slug` de `spells`, `elemental_affinity` y `magic_school`
     * del conjuro, `author_alias` del firmante y `origin_clan_name` del linaje
     * patrimonial. El linaje se une con `LEFT JOIN` porque la obra de un
     * ermitaño no tiene estandarte, y un `INNER JOIN` la haría desaparecer del
     * Atrio —justo la obra que ningún veto de clan puede alcanzar—.
     */
    private const CATALOG_PORTRAIT_COLUMNS = 's.name, s.slug, s.elemental_affinity, s.magic_school, '
        . 'u.alias AS author_alias, c.name AS origin_clan_name';

    /**
     * Proyección canónica de una revisión (columnas del esquema de SPEC-08,
     * Tarea 1.1). Declarada una sola vez para que toda lectura devuelva
     * exactamente el mismo contrato.
     */
    private const REVIEW_COLUMNS = 'id, spell_id, author_id, origin_clan_id, status, '
        . 'signatures_count, math_fingerprint, submitted_at, validated_at, '
        . 'rejected_at, reopened_at, archived_at';

    /**
     * La misma proyección, prefijada con el alias `r` que usan las consultas
     * que cruzan `spell_reviews` con `spells`.
     *
     * Se declara literal —y no se compone en tiempo de ejecución— porque la
     * única pieza que debe poder ensamblarse a mano en este repositorio es el
     * nombre de una columna elegida por el propio repositorio; así ni el
     * auditor de AGENTS.md ni un lector humano han de distinguir una proyección
     * constante de una concatenación sospechosa.
     */
    private const QUALIFIED_REVIEW_COLUMNS = 'r.id, r.spell_id, r.author_id, r.origin_clan_id, r.status, '
        . 'r.signatures_count, r.math_fingerprint, r.submitted_at, r.validated_at, '
        . 'r.rejected_at, r.reopened_at, r.archived_at';

    /** Conexión PDO del santuario (Singleton del front controller). */
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Inscribe el expediente de una obra, o lo actualiza si ya existía
     * (RF-01.1, RF-01.2).
     *
     * La relación 1:1 con `spells` convierte este método en el único punto de
     * entrada del expediente: el envío a la Torre (draft → experimental), la
     * retirada a la libreta, la objeción, la re-apertura y el destierro pasan
     * por aquí o por updateStatus(). Se resuelve con lectura + escritura
     * explícitas dentro de una transacción en lugar de un UPSERT del
     * dialecto, para que el mismo código sirva sobre SQLite y sobre MySQL.
     *
     * `submittedAt` solo se escribe si llega: en la rama de actualización un
     * `null` NO borra la fecha de entrada a la Torre ya conocida (una obra no
     * reenvía su historia porque el llamador omita un argumento).
     *
     * @param string      $reviewId         Identificador textual de la revisión.
     * @param string      $spellId          Conjuro cuyo expediente se inscribe.
     * @param string      $authorId         Mago creador de la obra.
     * @param string      $status           Estado canónico de partida.
     * @param string      $mathFingerprint  Huella SHA-256 del balance sellado.
     * @param string|null $originClanId     Clan patrimonial, o null si ermitaño.
     * @param int         $signaturesCount  Firmas vivas (0 a 3).
     * @param string|null $submittedAtUtc   Entrada a la Torre de Moderación, si ya ocurrió.
     *
     * @return array{
     *   id: string, spell_id: string, author_id: string, origin_clan_id: string|null,
     *   status: string, signatures_count: int, math_fingerprint: string,
     *   submitted_at: string|null, validated_at: string|null, rejected_at: string|null,
     *   reopened_at: string|null, archived_at: string|null
     * }
     *
     * @throws InvalidArgumentException Si el estado, el conteo o la huella rompen el canon.
     */
    public function createOrUpdateReview(
        string $reviewId,
        string $spellId,
        string $authorId,
        string $status,
        string $mathFingerprint,
        ?string $originClanId = null,
        int $signaturesCount = 0,
        ?string $submittedAtUtc = null
    ): array {
        $this->assertCanonicalStatus($status);
        $this->assertSignaturesCount($signaturesCount);
        $this->assertFingerprint($mathFingerprint);

        return $this->runAtomically(function () use (
            $reviewId,
            $spellId,
            $authorId,
            $status,
            $mathFingerprint,
            $originClanId,
            $signaturesCount,
            $submittedAtUtc
        ): array {
            $knownReviewId = $this->findReviewIdBySpellId($spellId);

            if ($knownReviewId === null) {
                $statement = $this->pdo->prepare(
                    'INSERT INTO spell_reviews (
                         id, spell_id, author_id, origin_clan_id, status,
                         signatures_count, math_fingerprint, submitted_at
                     ) VALUES (
                         :reviewId, :spellId, :authorId, :originClanId, :status,
                         :signaturesCount, :mathFingerprint, :submittedAt
                     )'
                );
                $statement->execute([
                    ':reviewId'        => $reviewId,
                    ':spellId'         => $spellId,
                    ':authorId'        => $authorId,
                    ':originClanId'    => $originClanId,
                    ':status'          => $status,
                    ':signaturesCount' => $signaturesCount,
                    ':mathFingerprint' => $mathFingerprint,
                    ':submittedAt'     => $submittedAtUtc,
                ]);
            } else {
                $statement = $this->pdo->prepare(
                    'UPDATE spell_reviews
                        SET author_id        = :authorId,
                            origin_clan_id   = :originClanId,
                            status           = :status,
                            signatures_count = :signaturesCount,
                            math_fingerprint = :mathFingerprint,
                            submitted_at     = COALESCE(:submittedAt, submitted_at)
                      WHERE spell_id = :spellId'
                );
                $statement->execute([
                    ':authorId'        => $authorId,
                    ':originClanId'    => $originClanId,
                    ':status'          => $status,
                    ':signaturesCount' => $signaturesCount,
                    ':mathFingerprint' => $mathFingerprint,
                    ':submittedAt'     => $submittedAtUtc,
                    ':spellId'         => $spellId,
                ]);
            }

            $review = $this->findBySpellId($spellId);
            if ($review === null) {
                throw new RuntimeException(
                    'La revisión recién escrita no pudo releerse: la base violó su propio contrato.'
                );
            }

            // El espejo sigue a la autoridad en el mismo aliento transaccional.
            $this->mirrorSpellLifecycle($spellId, $review['status'], $review['signatures_count']);

            return $review;
        });
    }

    /**
     * Recupera una revisión por su identificador propio.
     *
     * @return array{
     *   id: string, spell_id: string, author_id: string, origin_clan_id: string|null,
     *   status: string, signatures_count: int, math_fingerprint: string,
     *   submitted_at: string|null, validated_at: string|null, rejected_at: string|null,
     *   reopened_at: string|null, archived_at: string|null
     * }|null
     */
    public function findById(string $reviewId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::REVIEW_COLUMNS . '
               FROM spell_reviews
              WHERE id = :reviewId'
        );
        $statement->execute([':reviewId' => $reviewId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Recupera el expediente de un conjuro (relación 1:1).
     *
     * @return array{
     *   id: string, spell_id: string, author_id: string, origin_clan_id: string|null,
     *   status: string, signatures_count: int, math_fingerprint: string,
     *   submitted_at: string|null, validated_at: string|null, rejected_at: string|null,
     *   reopened_at: string|null, archived_at: string|null
     * }|null
     */
    public function findBySpellId(string $spellId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::REVIEW_COLUMNS . '
               FROM spell_reviews
              WHERE spell_id = :spellId'
        );
        $statement->execute([':spellId' => $spellId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === null || $row === false ? null : $this->hydrate($row);
    }

    /**
     * Recupera el expediente de un conjuro tomando el bloqueo de escritura
     * (RF-02.3: consagración atómica de la tercera firma).
     *
     * Es la puerta de la sección crítica: el servicio de deliberación (Tarea
     * 2.4) abre aquí la transacción, lee el expediente y solo entonces firma,
     * cuenta y consagra, de modo que dos Maestros que estampen su aval en la
     * misma milésima de segundo no puedan ambos ver `2/3` y escribir `3/3`.
     *
     * Sobre SQLite —que no conoce bloqueos de fila— el bloqueo se toma
     * abriendo la transacción y TOCCANDO la fila que va a ser juzgada con una
     * escritura idempotente (`status = status`): el primer enunciado de
     * escritura de una transacción adquiere el bloqueo RESERVED de la base, de
     * modo que la lectura y la escritura que le siguen quedan serializadas
     * frente a cualquier otro escritor. (No se usa `BEGIN IMMEDIATE` porque
     * PDO no lleva su contabilidad con enunciados SQL sueltos: tras él
     * `inTransaction()` responde falso y el `commit()` del servicio fallaría.)
     * Sobre MySQL y PostgreSQL se añade `FOR UPDATE` a la propia consulta,
     * que es el bloqueo de fila nativo de esos motores, y el toque de
     * escritura no estorba.
     *
     * La transacción queda ABIERTA a propósito: la cierra quien la abrió
     * —el servicio, con `commit()` o `rollBack()`—, porque el bloqueo solo
     * tiene sentido si cubre también la escritura que lo sigue. Si ya había
     * una transacción en curso, se respeta y no se anida.
     *
     * @return array{
     *   id: string, spell_id: string, author_id: string, origin_clan_id: string|null,
     *   status: string, signatures_count: int, math_fingerprint: string,
     *   submitted_at: string|null, validated_at: string|null, rejected_at: string|null,
     *   reopened_at: string|null, archived_at: string|null
     * }|null La revisión bloqueada, o null si el conjuro no tiene expediente.
     */
    public function findAndLockById(string $spellId): ?array
    {
        $this->acquireWriteLock($spellId);

        $forUpdate = in_array($this->driverName(), ['mysql', 'pgsql'], true) ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT ' . self::REVIEW_COLUMNS . '
               FROM spell_reviews
              WHERE spell_id = :spellId' . $forUpdate
        );
        $statement->execute([':spellId' => $spellId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Transiciona el estado del expediente y sella su marca temporal
     * (RF-01.1, RF-01.4, RF-01.6, RF-02.3, RF-04.4).
     *
     * La columna del ciclo de vida se elige de una lista cerrada del propio
     * repositorio: `submitted_at` para la entrada a la Torre, `validated_at`
     * para la consagración, `rejected_at` para el veto o el letargo,
     * `reopened_at` para la re-apertura como borrador y `archived_at` para el
     * destierro. Sin marca temporal la transición es solo de estado (útil
     * para reparar una fila sin reescribir su historia).
     *
     * @param string      $spellId       Conjuro cuyo expediente muda.
     * @param string      $status        Estado canónico de destino.
     * @param string|null $occurredAtUtc Instante de la transición, o null para no sellarlo.
     *
     * @return bool Cierto si existía expediente y quedó transicionado.
     *
     * @throws InvalidArgumentException Si el estado rompe el canon de RF-01.1.
     */
    public function updateStatus(string $spellId, string $status, ?string $occurredAtUtc = null): bool
    {
        $this->assertCanonicalStatus($status);

        $timestampColumn = self::STATUS_TIMESTAMP_COLUMNS[$status];

        return $this->runAtomically(function () use ($spellId, $status, $occurredAtUtc, $timestampColumn): bool {
            if ($occurredAtUtc === null) {
                $statement = $this->pdo->prepare(
                    'UPDATE spell_reviews
                        SET status = :status
                      WHERE spell_id = :spellId'
                );
                $statement->execute([':status' => $status, ':spellId' => $spellId]);
            } else {
                // El nombre de la columna sale de la lista cerrada de arriba,
                // jamás del llamador: ningún valor del exterior se interpola.
                $statement = $this->pdo->prepare(
                    'UPDATE spell_reviews
                        SET status = :status,
                            ' . $timestampColumn . ' = :occurredAt
                      WHERE spell_id = :spellId'
                );
                $statement->execute([
                    ':status'     => $status,
                    ':occurredAt' => $occurredAtUtc,
                    ':spellId'    => $spellId,
                ]);
            }

            if ($statement->rowCount() === 0) {
                return false;
            }

            // El espejo sigue a la autoridad: quien transiciona el expediente
            // transiciona el conjuro, y nadie más puede hacerlo. El instante
            // viaja para que la consagración quede FECHADA en el espejo: sin
            // esa fecha, el Libro de Oro ordenaría por un campo vacío.
            $this->mirrorSpellLifecycle($spellId, $status, null, $occurredAtUtc);

            return true;
        });
    }

    /**
     * Fija el contador de firmas vivas del expediente (RF-02.1, RF-02.4).
     *
     * El repositorio acota el valor al canon 0..3 antes de escribirlo: la
     * base lo impondría igualmente con su CHECK, pero una transacción que ya
     * estampó una firma merece saberlo ANTES de tocar el contador.
     *
     * @return bool Cierto si existía expediente y el contador quedó fijado.
     *
     * @throws InvalidArgumentException Si el conteo sale del rango 0..3.
     */
    public function updateSignaturesCount(string $spellId, int $signaturesCount): bool
    {
        $this->assertSignaturesCount($signaturesCount);

        return $this->runAtomically(function () use ($spellId, $signaturesCount): bool {
            $statement = $this->pdo->prepare(
                'UPDATE spell_reviews
                    SET signatures_count = :signaturesCount
                  WHERE spell_id = :spellId'
            );
            $statement->execute([
                ':signaturesCount' => $signaturesCount,
                ':spellId'         => $spellId,
            ]);

            if ($statement->rowCount() === 0) {
                return false;
            }

            $this->mirrorSpellLifecycle($spellId, null, $signaturesCount);

            return true;
        });
    }

    /**
     * Cuenta los conjuros que un autor mantiene EN DELIBERACIÓN (RF-01.2).
     *
     * Es el cupo anti-spam del santuario: solo las revisiones en estado
     * `experimental` consumen plaza, de modo que al ser rechazada o
     * consagrada una obra la plaza se libera sola (RF-01.5) sin que nadie
     * tenga que llevar la cuenta por separado.
     *
     * @return int Número de conjuros del autor en estado `experimental`.
     */
    public function countActiveReviewsByAuthor(string $authorId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS active_reviews
               FROM spell_reviews
              WHERE author_id = :authorId
                AND status = :status'
        );
        $statement->execute([
            ':authorId' => $authorId,
            ':status'   => self::STATUS_EXPERIMENTAL,
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Cola de deliberación del Atrio y de la Torre (RF-05.1, RF-05.4).
     *
     * Devuelve las obras más antiguas en deliberación primero —el orden es
     * determinista y estable (`submitted_at ASC, id ASC`) para que dos
     * consultas idénticas devuelvan la misma cola (RNF-01)—, cruzando con
     * `spells` cuando se filtra por afinidad elemental o escuela, que son
     * atributos del conjuro y no del expediente.
     *
     * Filtros admitidos (cualquier otro lanza excepción): `status`,
     * `authorId`, `originClanId`, `elementalAffinity`, `magicSchool`,
     * `minSignatures`, `limit` y `offset`. Sin `status` se asume la cola
     * misma, esto es, las obras en `experimental`.
     *
     * El retrato de la obra (nombre, slug, afinidad, escuela, alias del autor
     * y nombre del linaje) viaja desde la Tarea 3.1 en la MISMA fila, resuelto
     * por `JOIN`, para que el Atrio y la Torre compongan sus tarjetas sin una
     * segunda consulta por elemento ni un `N+1` silencioso.
     *
     * @param array<string, string|int> $filters Filtros de la consulta.
     *
     * @return list<array{
     *   id: string, spell_id: string, author_id: string, origin_clan_id: string|null,
     *   status: string, signatures_count: int, math_fingerprint: string,
     *   submitted_at: string|null, validated_at: string|null, rejected_at: string|null,
     *   reopened_at: string|null, archived_at: string|null,
     *   name: string, slug: string|null, elemental_affinity: string,
     *   magic_school: string, author_alias: string, origin_clan_name: string|null
     * }>
     *
     * @throws InvalidArgumentException Si llega un filtro desconocido o un valor fuera del canon.
     */
    public function findQueueItems(array $filters = []): array
    {
        [$conditions, $parameters] = $this->buildQueueConditions($filters);

        $limit = array_key_exists('limit', $filters) ? (int) $filters['limit'] : 0;
        $offset = array_key_exists('offset', $filters) ? (int) $filters['offset'] : 0;
        if ($limit < 0 || $offset < 0) {
            throw new InvalidArgumentException('La cola de deliberación no admite paginación negativa.');
        }

        $sql = 'SELECT ' . self::QUALIFIED_REVIEW_COLUMNS . ', ' . self::CATALOG_PORTRAIT_COLUMNS . '
                  FROM spell_reviews r
                  JOIN spells s ON s.id = r.spell_id
                  JOIN users u ON u.id = r.author_id
                  LEFT JOIN clans c ON c.id = r.origin_clan_id'
            . ' WHERE ' . implode(' AND ', $conditions)
            . ' ORDER BY r.submitted_at ASC, r.id ASC';

        if ($limit > 0) {
            $sql .= ' LIMIT :limit';
            $parameters[':limit'] = $limit;
        }
        if ($offset > 0) {
            $sql .= ' OFFSET :offset';
            $parameters[':offset'] = $offset;
        }

        $statement = $this->pdo->prepare($sql);
        foreach ($parameters as $placeholder => $value) {
            $statement->bindValue($placeholder, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();

        $queue = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $queue[] = $this->hydrateCatalogItem($row);
        }

        return $queue;
    }

    /**
     * Censo de la cola: cuántas obras casan con los filtros, sin paginar
     * (Tarea 3.1).
     *
     * Acompaña SIEMPRE a la página que `findQueueItems()` devuelve —el Atrio
     * declara cuántas obras aguardan y no solo las que caben en la pantalla—
     * y comparte con ella el mismo constructor de condiciones, para que el
     * total jamás describa un conjunto distinto del que se está exhibiendo.
     *
     * @param array<string, string|int> $filters Filtros de la consulta.
     *
     * @throws InvalidArgumentException Si llega un filtro desconocido o un valor fuera del canon.
     */
    public function countQueueItems(array $filters = []): int
    {
        [$conditions, $parameters] = $this->buildQueueConditions($filters);

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS queue_size'
            . ' FROM spell_reviews r'
            . ' JOIN spells s ON s.id = r.spell_id'
            . ' WHERE ' . implode(' AND ', $conditions)
        );
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /**
     * Expedientes en letargo: obras experimentales sin resonancia colegiada
     * durante el umbral de días naturales (RF-01.6).
     *
     * El reloj de la obra es la ÚLTIMA interacción de un Maestro, esto es,
     * la firma más reciente que haya recibido —retractada o vigente, porque
     * retractarse es también mirar la obra— y, si nunca la hubo, el instante
     * de su entrada a la Torre. La cola se ordena por ese reloj ascendente:
     * lo más olvidado primero, para que el servicio pueda caducarlo en
     * bloque sin recorrer el censo entero.
     *
     * El instante de referencia es inyectable (RNF-01): quien cronometra
     * pasa su reloj y el repositorio no inventa uno propio.
     *
     * @param int               $thresholdDays Días naturales de letargo tolerados.
     * @param DateTimeImmutable|null $nowUtc   Instante de referencia, o el presente UTC.
     *
     * @return list<array{
     *   id: string, spell_id: string, author_id: string, origin_clan_id: string|null,
     *   status: string, signatures_count: int, math_fingerprint: string,
     *   submitted_at: string|null, validated_at: string|null, rejected_at: string|null,
     *   reopened_at: string|null, archived_at: string|null
     * }>
     *
     * @throws InvalidArgumentException Si el umbral de días no es positivo.
     */
    public function findStaleReviews(int $thresholdDays, ?DateTimeImmutable $nowUtc = null): array
    {
        if ($thresholdDays <= 0) {
            throw new InvalidArgumentException('El letargo arcano se mide en días naturales positivos.');
        }

        $instant = $nowUtc ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $threshold = $instant
            ->modify("-{$thresholdDays} days")
            ->format('Y-m-d\TH:i:s\Z');

        $statement = $this->pdo->prepare(
            'SELECT ' . self::QUALIFIED_REVIEW_COLUMNS . '
               FROM spell_reviews r
               LEFT JOIN (
                     SELECT spell_id, MAX(signed_at) AS last_signed_at
                       FROM master_signatures
                      GROUP BY spell_id
                   ) s ON s.spell_id = r.spell_id
              WHERE r.status = :status
                AND r.submitted_at IS NOT NULL
                AND COALESCE(s.last_signed_at, r.submitted_at) <= :threshold
              ORDER BY COALESCE(s.last_signed_at, r.submitted_at) ASC, r.id ASC'
        );
        $statement->execute([
            ':status'    => self::STATUS_EXPERIMENTAL,
            ':threshold' => $threshold,
        ]);

        $stale = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stale[] = $this->hydrate($row);
        }

        return $stale;
    }

    /**
     * Escribe el espejo denormalizado de `spells` (Tarea 1.5).
     *
     * Es la ÚNICA pluma que toca `spells.status` y `spells.signatures_count`
     * en todo el santuario, y siempre se invoca dentro de la transacción del
     * expediente: el espejo no puede quedar a medio actualizar ni desviarse de
     * la autoridad. Se escribe solo lo que cambia —el estado, el contador o
     * ambos— para no reescribir `updated_at` ni columnas ajenas al ciclo de
     * vida; un conjuro inexistente se ignora en silencio, porque el expediente
     * manda y la clave foránea ya impide revisar una obra que no existe.
     *
     * Los nombres de columna salen de esta lista cerrada; los valores del
     * llamante viajan siempre como parámetros vinculados.
     *
     * @param string      $spellId         Conjuro cuyo espejo se sincroniza.
     * @param string|null $status          Estado canónico a reflejar, o null si no cambia.
     * @param int|null    $signaturesCount Contador de firmas a reflejar, o null si no cambia.
     */
    private function mirrorSpellLifecycle(
        string $spellId,
        ?string $status,
        ?int $signaturesCount,
        ?string $occurredAtUtc = null,
    ): void {
        $assignments = [];
        $parameters = [':spellId' => $spellId];

        if ($status !== null) {
            $assignments[] = 'status = :status';
            $parameters[':status'] = $status;
        }

        // La fecha de consagración se espeja con el estado: es la que ordena el
        // Libro de Oro del linaje (RF-02.3). Solo la consagración la fija; un
        // destierro posterior la conserva, porque la gloria no se desdice.
        if ($status === self::STATUS_VALIDATED && $occurredAtUtc !== null) {
            $assignments[] = 'validated_at = :validatedAt';
            $parameters[':validatedAt'] = $occurredAtUtc;
        }

        if ($signaturesCount !== null) {
            $assignments[] = 'signatures_count = :signaturesCount';
            $parameters[':signaturesCount'] = $signaturesCount;
        }

        if ($assignments === []) {
            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE spells SET ' . implode(', ', $assignments) . ' WHERE id = :spellId'
        );
        $statement->execute($parameters);
    }

    /** Identificador del expediente de un conjuro, o null si aún no lo tiene. */
    private function findReviewIdBySpellId(string $spellId): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM spell_reviews WHERE spell_id = :spellId'
        );
        $statement->execute([':spellId' => $spellId]);
        $reviewId = $statement->fetchColumn();

        return $reviewId === false ? null : (string) $reviewId;
    }

    /**
     * Toma el bloqueo de escritura de la sección crítica (RF-02.3).
     *
     * Abre la transacción —respetando la del llamante si ya hubiera una, pues
     * SQLite no admite transacciones anidadas— y toca la fila con una
     * escritura idempotente para que el motor adquiera de inmediato su
     * bloqueo de escritura. Sin ese toque, una transacción diferida solo
     * bloquearía a los demás escritores al llegar al primer cambio real, y dos
     * Maestros podrían leer `2/3` a la vez antes de que ninguno escribiera.
     *
     * @param string $spellId Conjuro cuya fila se toca para tomar el bloqueo.
     */
    private function acquireWriteLock(string $spellId): void
    {
        if ($this->pdo->inTransaction()) {
            return;
        }

        $this->pdo->beginTransaction();

        // El toque viaja parametrizado y no altera dato alguno: su único
        // oficio es que el motor abra el cursor de escritura.
        $statement = $this->pdo->prepare(
            'UPDATE spell_reviews SET status = status WHERE spell_id = :spellId'
        );
        $statement->execute([':spellId' => $spellId]);
    }

    /** Nombre del motor de datos, para elegir el dialecto del bloqueo. */
    private function driverName(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Ejecuta una operación como una sola transacción, plegándose a la del
     * llamante si ya hubiera una abierta (Tarea 1.4).
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
     * Vela por el canon de los cinco estados de RF-01.1.
     *
     * @throws InvalidArgumentException Si el estado no pertenece al canon.
     */
    private function assertCanonicalStatus(string $status): void
    {
        if (!in_array($status, self::CANONICAL_STATUSES, true)) {
            throw new InvalidArgumentException(
                'El conjuro solo transita por los cinco estados canónicos del santuario: '
                . implode(', ', self::CANONICAL_STATUSES) . '.'
            );
        }
    }

    /**
     * Vela por el techo de firmas de RF-02.1.
     *
     * @throws InvalidArgumentException Si el conteo sale del rango 0..3.
     */
    private function assertSignaturesCount(int $signaturesCount): void
    {
        if ($signaturesCount < 0 || $signaturesCount > self::MAX_SIGNATURES) {
            throw new InvalidArgumentException(
                'El contador de firmas vive entre cero y tres: la consagración no admite una cuarta rúbrica.'
            );
        }
    }

    /**
     * Vela por la huella del balance sellado (Artículo II).
     *
     * @throws InvalidArgumentException Si la huella no tiene 64 caracteres.
     */
    private function assertFingerprint(string $mathFingerprint): void
    {
        if (strlen($mathFingerprint) !== self::FINGERPRINT_LENGTH) {
            throw new InvalidArgumentException(
                'La huella del balance ha de ser un SHA-256 de 64 caracteres: sin huella no hay juicio.'
            );
        }
    }

    /**
     * Traduce los filtros de la cola a condiciones SQL con sus parámetros
     * vinculados (AGENTS.md 6.1: cero concatenación de datos del usuario).
     *
     * Se declara UNA sola vez para que la página y su censo no puedan
     * divergir: dos listas blancas distintas devolverían una página que no
     * corresponde al total que la acompaña. Las claves de paginación se
     * admiten pero NO se traducen aquí —acotan la lectura, jamás el censo—.
     *
     * @param array<string, string|int> $filters Filtros de la consulta.
     *
     * @return array{0: list<string>, 1: array<string, string|int>} Condiciones y parámetros.
     *
     * @throws InvalidArgumentException Si llega un filtro desconocido o un valor fuera del canon.
     */
    private function buildQueueConditions(array $filters): array
    {
        foreach (array_keys($filters) as $filterKey) {
            if (!in_array($filterKey, self::QUEUE_FILTERS, true)) {
                throw new InvalidArgumentException(
                    "La cola de deliberación no admite el filtro «{$filterKey}»."
                );
            }
        }

        $conditions = [];
        $parameters = [];

        $status = $filters['status'] ?? self::STATUS_EXPERIMENTAL;
        $this->assertCanonicalStatus((string) $status);
        $conditions[] = 'r.status = :status';
        $parameters[':status'] = (string) $status;

        if (array_key_exists('authorId', $filters)) {
            $conditions[] = 'r.author_id = :authorId';
            $parameters[':authorId'] = (string) $filters['authorId'];
        }

        if (array_key_exists('originClanId', $filters)) {
            $conditions[] = 'r.origin_clan_id = :originClanId';
            $parameters[':originClanId'] = (string) $filters['originClanId'];
        }

        // Los filtros de afinidad y escuela son atributos de la OBRA y no del
        // expediente: el JOIN con `spells` los resuelve.
        if (array_key_exists('elementalAffinity', $filters)) {
            $conditions[] = 's.elemental_affinity = :elementalAffinity';
            $parameters[':elementalAffinity'] = (string) $filters['elementalAffinity'];
        }

        if (array_key_exists('magicSchool', $filters)) {
            $conditions[] = 's.magic_school = :magicSchool';
            $parameters[':magicSchool'] = (string) $filters['magicSchool'];
        }

        if (array_key_exists('minSignatures', $filters)) {
            $minSignatures = (int) $filters['minSignatures'];
            $this->assertSignaturesCount($minSignatures);
            $conditions[] = 'r.signatures_count >= :minSignatures';
            $parameters[':minSignatures'] = $minSignatures;
        }

        return [$conditions, $parameters];
    }

    /**
     * Hidrata la tarjeta del Atrio y de la Torre: el expediente canónico más
     * el retrato de la obra que el JOIN acaba de resolver (Tarea 3.1).
     *
     * @param array<string, mixed> $row Fila de la cola con su retrato.
     *
     * @return array<string, mixed> Contrato de `ModerationQueueItemDto`.
     */
    private function hydrateCatalogItem(array $row): array
    {
        $item = $this->hydrate($row);

        $item['name']               = (string) ($row['name'] ?? '');
        $item['slug']               = isset($row['slug']) && $row['slug'] !== null ? (string) $row['slug'] : null;
        $item['author_alias']       = (string) ($row['author_alias'] ?? '');
        $item['origin_clan_name']   = isset($row['origin_clan_name']) && $row['origin_clan_name'] !== null
            ? (string) $row['origin_clan_name']
            : null;
        $item['elemental_affinity'] = (string) ($row['elemental_affinity'] ?? 'none');
        $item['magic_school']       = (string) ($row['magic_school'] ?? '');

        return $item;
    }

    /**
     * Proyecta una fila de `spell_reviews` al contrato canónico snake_case.
     *
     * Las columnas del retrato de la obra que el `JOIN` añade a la fila —nombre,
     * `slug`, afinidad, escuela, alias del autor y nombre del linaje— NO se
     * proyectan aquí: las absorbe `hydrateCatalogItem()`, de modo que este
     * contrato siga describiendo exactamente lo que el expediente declara.
     *
     * @param array<string, mixed> $row Fila cruda del motor de datos.
     *
     * @return array{
     *   id: string, spell_id: string, author_id: string, origin_clan_id: string|null,
     *   status: string, signatures_count: int, math_fingerprint: string,
     *   submitted_at: string|null, validated_at: string|null, rejected_at: string|null,
     *   reopened_at: string|null, archived_at: string|null
     * }
     */
    private function hydrate(array $row): array
    {
        return [
            'id'               => (string) $row['id'],
            'spell_id'         => (string) $row['spell_id'],
            'author_id'        => (string) $row['author_id'],
            'origin_clan_id'   => $row['origin_clan_id'] === null ? null : (string) $row['origin_clan_id'],
            'status'           => (string) $row['status'],
            'signatures_count' => (int) $row['signatures_count'],
            'math_fingerprint' => (string) $row['math_fingerprint'],
            'submitted_at'     => $row['submitted_at'] === null ? null : (string) $row['submitted_at'],
            'validated_at'     => $row['validated_at'] === null ? null : (string) $row['validated_at'],
            'rejected_at'      => $row['rejected_at'] === null ? null : (string) $row['rejected_at'],
            'reopened_at'      => $row['reopened_at'] === null ? null : (string) $row['reopened_at'],
            'archived_at'      => $row['archived_at'] === null ? null : (string) $row['archived_at'],
        ];
    }
}
