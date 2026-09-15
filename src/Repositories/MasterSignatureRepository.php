<?php

/**
 * MasterSignatureRepository.php — Persistencia PDO de las Firmas de
 * Consagración que los Maestros estampan sobre los conjuros en deliberación
 * (SPEC-08, Tarea 1.3).
 *
 * Cubre: RF-02.1 (las tres firmas independientes y su pluralidad), RF-02.2
 * (glosa ceremonial de 250 caracteres como máximo), RF-02.4 (retractación
 * voluntaria: una firma viva por Maestro y conjuro), RF-02.6 (anulación de
 * las firmas previas cuando la obra es objetada), RF-03.4 (nulidad
 * constitucional sobrevenida), RF-03.5 (pérdida del rango en tránsito) y
 * RNF-01 (determinismo auditable: el reloj lo pasa el llamante).
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): PDO nativo, 100% consultas preparadas con
 *     parameter binding. La única pieza ensamblada a mano son los nombres de
 *     columna elegidos por listas cerradas del propio repositorio.
 *   - Art. III (Ética de Linajes): la firma guarda el clan del Maestro EN EL
 *     INSTANTE DE FIRMAR (`master_clan_id`). Si el Maestro muda de casa
 *     después, la firma conserva la memoria del juicio tal como se pronunció:
 *     sin ese retrato, la anulación de oficio de RF-03.4 no podría distinguir
 *     un conflicto sobrevenido de un conflicto que ya existía.
 *   - Art. V (Dualidad): identificadores en inglés camelCase, claves de base
 *     de datos en snake_case, documentación en noble castellano.
 *
 * Autoridad de la firma viva: `is_revoked = 0`. La Tarea 1.1 grabó un índice
 * ÚNICO PARCIAL sobre `(spell_id, master_id)` con esa condición, de modo que
 * la base rechaza por sí misma una segunda firma viva del mismo Maestro sobre
 * el mismo conjuro; este repositorio traduce esa colisión a `null` —un
 * resultado de negocio, no un error de motor— y jamás a una excepción.
 */

declare(strict_types=1);

namespace Grimorio\Repositories;

use InvalidArgumentException;
use PDO;
use PDOException;

/**
 * Repositorio de las Firmas de Consagración: estampado, revocación y censo.
 *
 * Reparto de responsabilidades (Tareas 2.2 y 2.4): este repositorio MIDE y
 * PERSISTE; no juzga. Que un Maestro esté vetado por el Artículo III, que dos
 * firmantes compartan hermandad o que la obra haya alcanzado la consagración
 * son veredictos de `ConstitutionalEthicsValidator` y `MasterDeliberationService`;
 * aquí solo viajan los hechos —qué firmas viven, quién las estampó, con qué
 * glosa y desde qué clan— para que aquellos puedan dictar 403 Forbidden o 409
 * Conflict sin inspeccionar excepciones del motor de datos.
 */
final class MasterSignatureRepository
{
    /**
     * Glosa ceremonial máxima de RF-02.2, medida en caracteres y no en bytes:
     * una glosa en castellano con acentos no puede consumir su cupo a la mitad.
     */
    public const MAX_GLOSS_LENGTH = 250;

    /** El Maestro se retracta de su propio aval antes de la consagración (RF-02.4). */
    public const REVOCATION_RETRACTED = 'retracted';

    /** El autor se afilió al clan del firmante, o el firmante al del autor (RF-03.4). */
    public const REVOCATION_CLAN_CONFLICT_ARISEN = 'clan_conflict_arisen';

    /** El firmante fue degradado de rango o suspendido en tránsito (RF-03.5). */
    public const REVOCATION_RANK_LOST = 'rank_lost';

    /** El autor retiró su obra a la libreta privada, con anulación de avales. */
    public const REVOCATION_AUTHOR_WITHDRAWN = 'author_withdrawn';

    /** El Administrador Supremo desterró la obra del canon (RF-04.4). */
    public const REVOCATION_SOVEREIGN_ARCHIVE = 'sovereign_archive';

    /**
     * Los cinco motivos canónicos de revocación.
     *
     * La base no los acota con un `CHECK` —a diferencia del techo de firmas o
     * de la huella de 64 caracteres— porque un motivo nuevo es una decisión de
     * gobierno, no una corrupción del datos; pero el repositorio solo admite
     * los cinco que la especificación declara, de modo que una revocación
     * siempre puede contarse en la bitácora sin inventar taxonomía.
     */
    public const CANONICAL_REVOCATION_REASONS = [
        self::REVOCATION_RETRACTED,
        self::REVOCATION_CLAN_CONFLICT_ARISEN,
        self::REVOCATION_RANK_LOST,
        self::REVOCATION_AUTHOR_WITHDRAWN,
        self::REVOCATION_SOVEREIGN_ARCHIVE,
    ];

    /**
     * Proyección canónica de una firma (columnas del esquema de SPEC-08,
     * Tarea 1.1). Declarada una sola vez para que toda lectura devuelva
     * exactamente el mismo contrato.
     */
    private const SIGNATURE_COLUMNS = 'id, spell_id, master_id, master_clan_id, '
        . 'ceremonial_gloss, signed_at, is_revoked, revoked_at, revocation_reason';

    /** Conexión PDO del santuario (Singleton del front controller). */
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Estampa una Firma de Consagración (RF-02.1, RF-02.2).
     *
     * La firma nace viva: `is_revoked = 0`. El clan del Maestro se retrata en
     * el instante mismo del aval, y la glosa ceremonial es opcional (RF-02.2
     * la permite, no la exige), con techo de 250 caracteres. Una glosa en
     * blanco se normaliza a NULL: el silencio del Maestro no es una glosa
     * vacía, y guardarla como cadena vacía ensuciaría el censo de firmas
     * glosadas.
     *
     * Si el Maestro ya mantiene una firma viva sobre el mismo conjuro, la base
     * rechaza la segunda por su índice único parcial y el método devuelve
     * `null`: la lealtad de un aval es única, y repetirlo no es un error del
     * motor sino un conflicto que el servicio traducirá a 409 (RF-02.4).
     *
     * @param string      $signatureId      Identificador textual de la firma.
     * @param string      $spellId          Conjuro avalado.
     * @param string      $masterId         Maestro que estampa el aval.
     * @param string      $signedAtUtc      Instante de la firma (ISO 8601 UTC), inyectado por el llamante.
     * @param string|null $masterClanId     Clan del firmante en ese instante, o null si es ermitaño.
     * @param string|null $ceremonialGloss  Glosa de aprobación, o null si no la hubo.
     *
     * @return array{
     *   id: string, spell_id: string, master_id: string, master_clan_id: string|null,
     *   ceremonial_gloss: string|null, signed_at: string, is_revoked: bool,
     *   revoked_at: string|null, revocation_reason: string|null
     * }|null La firma estampada, o null si ya existía una firma viva del mismo Maestro.
     *
     * @throws InvalidArgumentException Si la glosa supera los 250 caracteres.
     */
    public function insertSignature(
        string $signatureId,
        string $spellId,
        string $masterId,
        string $signedAtUtc,
        ?string $masterClanId = null,
        ?string $ceremonialGloss = null
    ): ?array {
        $gloss = $this->normalizeGloss($ceremonialGloss);
        $this->assertGloss($gloss);

        $statement = $this->pdo->prepare(
            'INSERT INTO master_signatures (
                 id, spell_id, master_id, master_clan_id, ceremonial_gloss,
                 signed_at, is_revoked, revoked_at, revocation_reason
             ) VALUES (
                 :signatureId, :spellId, :masterId, :masterClanId, :ceremonialGloss,
                 :signedAt, 0, NULL, NULL
             )'
        );

        try {
            $statement->execute([
                ':signatureId'     => $signatureId,
                ':spellId'         => $spellId,
                ':masterId'        => $masterId,
                ':masterClanId'    => $masterClanId,
                ':ceremonialGloss' => $gloss,
                ':signedAt'        => $signedAtUtc,
            ]);
        } catch (PDOException $exception) {
            // Un solo aval vivo por Maestro y conjuro (RF-02.4). Cualquier otro
            // fallo de integridad —conjuro inexistente, clan fantasma— se
            // propaga: no es un conflicto de negocio, es una corrupción.
            if ($this->isUniqueConstraintViolation($exception)) {
                return null;
            }

            throw $exception;
        }

        return $this->findSignatureById($signatureId);
    }

    /**
     * Firmas VIVAS de un conjuro, en el orden en que fueron estampadas
     * (RF-02.1, RF-05.4).
     *
     * Es la lectura que sostiene el contador 0/3 del Atrio y de la Torre y la
     * que alimenta la revisión de pluralidad de clanes: el llamante compara
     * los `master_clan_id` de la lista para saber si un candidato comparte
     * hermandad con algún firmante previo. El orden es determinista
     * (`signed_at ASC, id ASC`) para que dos consultas idénticas devuelvan el
     * mismo censo y el tercer firmante sea siempre el mismo (RNF-01).
     *
     * @return list<array{
     *   id: string, spell_id: string, master_id: string, master_clan_id: string|null,
     *   ceremonial_gloss: string|null, signed_at: string, is_revoked: bool,
     *   revoked_at: string|null, revocation_reason: string|null
     * }>
     */
    public function findActiveSignatures(string $spellId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::SIGNATURE_COLUMNS . '
               FROM master_signatures
              WHERE spell_id = :spellId
                AND is_revoked = 0
              ORDER BY signed_at ASC, id ASC'
        );
        $statement->execute([':spellId' => $spellId]);

        return $this->hydrateAll($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Firmas vivas estampadas por un Maestro, sobre cualquier conjuro
     * (RF-03.4, RF-03.5).
     *
     * Es la lectura del suscriptor de eventos: al cambiar la afiliación o el
     * rango de un usuario, el servicio recorre sus avales vivos y decide
     * cuáles han quedado nulos de oficio. El repositorio no filtra por estado
     * del conjuro —eso exige cruzar con `spell_reviews`, que es del
     * expediente y no de la firma—, de modo que el llamante recibe el censo
     * completo y descarta por sí mismo los ya consagrados.
     *
     * @return list<array{
     *   id: string, spell_id: string, master_id: string, master_clan_id: string|null,
     *   ceremonial_gloss: string|null, signed_at: string, is_revoked: bool,
     *   revoked_at: string|null, revocation_reason: string|null
     * }>
     */
    public function findActiveSignaturesByMaster(string $masterId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::SIGNATURE_COLUMNS . '
               FROM master_signatures
              WHERE master_id = :masterId
                AND is_revoked = 0
              ORDER BY signed_at ASC, id ASC'
        );
        $statement->execute([':masterId' => $masterId]);

        return $this->hydrateAll($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Recupera una firma por su identificador, revocada o viva.
     *
     * @return array{
     *   id: string, spell_id: string, master_id: string, master_clan_id: string|null,
     *   ceremonial_gloss: string|null, signed_at: string, is_revoked: bool,
     *   revoked_at: string|null, revocation_reason: string|null
     * }|null
     */
    public function findSignatureById(string $signatureId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::SIGNATURE_COLUMNS . '
               FROM master_signatures
              WHERE id = :signatureId'
        );
        $statement->execute([':signatureId' => $signatureId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Firma viva de un Maestro sobre un conjuro concreto, o null si no la hay.
     *
     * Es la puerta de la retractación (RF-02.4): el servicio necesita saber
     * QUÉ firma revocar antes de revocarla, y bajo qué clan se estampó para
     * juzgar un conflicto sobrevenido.
     *
     * @return array{
     *   id: string, spell_id: string, master_id: string, master_clan_id: string|null,
     *   ceremonial_gloss: string|null, signed_at: string, is_revoked: bool,
     *   revoked_at: string|null, revocation_reason: string|null
     * }|null
     */
    public function findActiveSignature(string $spellId, string $masterId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::SIGNATURE_COLUMNS . '
               FROM master_signatures
              WHERE spell_id = :spellId
                AND master_id = :masterId
                AND is_revoked = 0'
        );
        $statement->execute([':spellId' => $spellId, ':masterId' => $masterId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Revoca una firma dejando memoria del motivo (RF-02.4, RF-03.4, RF-03.5).
     *
     * La revocación JAMÁS borra la fila: fija `is_revoked = 1`, su instante y
     * su motivo. Esa memoria es lo que permite a RF-02.4 admitir un nuevo aval
     * del mismo Maestro sobre la misma obra —el índice único es PARCIAL, solo
     * alcanza a las firmas vivas— y lo que permite a la bitácora contar
     * cuántas anulaciones de oficio hubo y por qué.
     *
     * La condición `is_revoked = 0` es la guarda de idempotencia: revocar dos
     * veces la misma firma devuelve `false` en la segunda, jamás reescribe el
     * motivo original de la primera.
     *
     * @param string $signatureId  Firma a retirar.
     * @param string $reason       Motivo canónico de la revocación.
     * @param string $revokedAtUtc Instante de la revocación (ISO 8601 UTC).
     *
     * @return bool Cierto si la firma estaba viva y quedó revocada.
     *
     * @throws InvalidArgumentException Si el motivo no pertenece al canon.
     */
    public function revokeSignature(string $signatureId, string $reason, string $revokedAtUtc): bool
    {
        $this->assertRevocationReason($reason);

        $statement = $this->pdo->prepare(
            'UPDATE master_signatures
                SET is_revoked = 1,
                    revoked_at = :revokedAt,
                    revocation_reason = :reason
              WHERE id = :signatureId
                AND is_revoked = 0'
        );
        $statement->execute([
            ':revokedAt'   => $revokedAtUtc,
            ':reason'      => $reason,
            ':signatureId' => $signatureId,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Revoca de un golpe todas las firmas vivas de un conjuro (RF-02.6).
     *
     * Un Dictamen de Objeción «cancela cualquier otra firma previa», y la
     * retirada a la libreta privada anula los avales con el motivo
     * `author_withdrawn`. Ambas operaciones son un mismo gesto —la obra deja
     * de estar avalada—, y hacerlo con una sola sentencia y no con un bucle de
     * revocaciones mantiene el censo coherente ante cualquier fallo a mitad de
     * camino. Devuelve cuántas firmas cayeron, para que la bitácora pueda
     * registrar la magnitud de la anulación (N → 0).
     *
     * @param string $spellId      Conjuro cuyos avales se anulan.
     * @param string $reason       Motivo canónico de la anulación.
     * @param string $revokedAtUtc Instante de la anulación (ISO 8601 UTC).
     *
     * @return int Número de firmas vivas que quedaron revocadas.
     *
     * @throws InvalidArgumentException Si el motivo no pertenece al canon.
     */
    public function revokeActiveSignaturesForSpell(string $spellId, string $reason, string $revokedAtUtc): int
    {
        $this->assertRevocationReason($reason);

        $statement = $this->pdo->prepare(
            'UPDATE master_signatures
                SET is_revoked = 1,
                    revoked_at = :revokedAt,
                    revocation_reason = :reason
              WHERE spell_id = :spellId
                AND is_revoked = 0'
        );
        $statement->execute([
            ':revokedAt' => $revokedAtUtc,
            ':reason'    => $reason,
            ':spellId'   => $spellId,
        ]);

        return $statement->rowCount();
    }

    /**
     * Cuenta las firmas VIVAS de un conjuro (RF-02.1).
     *
     * Fuente única del contador 0/3, y el número que el expediente debe
     * reconciliar con su columna denormalizada `signatures_count`: contarlas
     * desde las firmas mismas —y no desde el contador— es lo que permite
     * detectar una divergencia en lugar de heredarla.
     *
     * @return int Firmas vivas sobre el conjuro.
     */
    public function countActiveSignatures(string $spellId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS active_signatures
               FROM master_signatures
              WHERE spell_id = :spellId
                AND is_revoked = 0'
        );
        $statement->execute([':spellId' => $spellId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Normaliza la glosa ceremonial: el silencio se guarda como ausencia.
     */
    private function normalizeGloss(?string $ceremonialGloss): ?string
    {
        if ($ceremonialGloss === null) {
            return null;
        }

        $gloss = trim($ceremonialGloss);

        return $gloss === '' ? null : $gloss;
    }

    /**
     * Vela por el techo de la glosa ceremonial (RF-02.2).
     *
     * Se mide en caracteres —no en bytes— porque la glosa se redacta en
     * castellano y la especificación habla de caracteres: la `ñ` de «añejo»
     * no puede consumir dos tercios de una letra.
     *
     * @throws InvalidArgumentException Si la glosa supera los 250 caracteres.
     */
    private function assertGloss(?string $gloss): void
    {
        if ($gloss !== null && mb_strlen($gloss) > self::MAX_GLOSS_LENGTH) {
            throw new InvalidArgumentException(
                'La glosa ceremonial no puede exceder los ' . self::MAX_GLOSS_LENGTH
                . ' caracteres: el pergamino del Atrio no admite tratados.'
            );
        }
    }

    /**
     * Vela por el canon de los cinco motivos de revocación.
     *
     * @throws InvalidArgumentException Si el motivo no pertenece al canon.
     */
    private function assertRevocationReason(string $reason): void
    {
        if (!in_array($reason, self::CANONICAL_REVOCATION_REASONS, true)) {
            throw new InvalidArgumentException(
                'La revocación exige un motivo canónico del santuario: '
                . implode(', ', self::CANONICAL_REVOCATION_REASONS) . '.'
            );
        }
    }

    /**
     * Distingue una colisión de UNICIDAD de cualquier otro fallo de
     * integridad del motor.
     *
     * La distinción es delicada y por eso se hace por la leyenda del error y
     * no por su SQLSTATE: SQLite y MySQL responden con 23000 tanto a la
     * violación de unicidad como a la de clave foránea, de modo que confiar en
     * el código silenciaría una firma inscrita contra un conjuro inexistente o
     * contra un clan fantasma —una corrupción real— devolviendo `null` como si
     * el Maestro ya hubiera avalado la obra. Se reconoce, pues, la unicidad
     * por su nombre: la leyenda «UNIQUE constraint failed» de SQLite, el
     * «Duplicate entry» de MySQL y el SQLSTATE 23505 de PostgreSQL; cualquier
     * otro error del motor se propaga intacto.
     */
    private function isUniqueConstraintViolation(PDOException $exception): bool
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'UNIQUE')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'duplicate key')
        ) {
            return true;
        }

        // PostgreSQL nombra la unicidad solo por su código (unique_violation).
        return (string) $exception->getCode() === '23505';
    }

    /**
     * Proyecta una colección de filas al contrato canónico snake_case.
     *
     * @param list<array<string, mixed>> $rows Filas crudas del motor de datos.
     *
     * @return list<array{
     *   id: string, spell_id: string, master_id: string, master_clan_id: string|null,
     *   ceremonial_gloss: string|null, signed_at: string, is_revoked: bool,
     *   revoked_at: string|null, revocation_reason: string|null
     * }>
     */
    private function hydrateAll(array $rows): array
    {
        $signatures = [];
        foreach ($rows as $row) {
            $signatures[] = $this->hydrate($row);
        }

        return $signatures;
    }

    /**
     * Proyecta una fila de `master_signatures` al contrato canónico.
     *
     * `is_revoked` viaja como booleano —y no como el 0/1 del motor— porque el
     * llamante juzga con él: «¿vive esta firma?» es una pregunta de sí o no, y
     * una comparación contra el entero 0 es una invitación al descuido.
     *
     * @param array<string, mixed> $row Fila cruda del motor de datos.
     *
     * @return array{
     *   id: string, spell_id: string, master_id: string, master_clan_id: string|null,
     *   ceremonial_gloss: string|null, signed_at: string, is_revoked: bool,
     *   revoked_at: string|null, revocation_reason: string|null
     * }
     */
    private function hydrate(array $row): array
    {
        return [
            'id'                => (string) $row['id'],
            'spell_id'          => (string) $row['spell_id'],
            'master_id'         => (string) $row['master_id'],
            'master_clan_id'    => $row['master_clan_id'] === null ? null : (string) $row['master_clan_id'],
            'ceremonial_gloss'  => $row['ceremonial_gloss'] === null ? null : (string) $row['ceremonial_gloss'],
            'signed_at'         => (string) $row['signed_at'],
            'is_revoked'        => (int) $row['is_revoked'] === 1,
            'revoked_at'        => $row['revoked_at'] === null ? null : (string) $row['revoked_at'],
            'revocation_reason' => $row['revocation_reason'] === null ? null : (string) $row['revocation_reason'],
        ];
    }
}
