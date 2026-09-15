<?php

/**
 * ObjectionVerdictRepository.php — Persistencia PDO de los Dictámenes de
 * Objeción Fundamentada que los Maestros emiten contra los conjuros en
 * deliberación (SPEC-08, Tarea 1.3).
 *
 * Cubre: RF-02.5 (justificación obligatoria de al menos veinte caracteres),
 * RF-02.6 (retorno al autor con memoria del dictamen), RF-06.2 (el autor
 * consulta el texto íntegro de la objeción antes de subsanar su obra) y
 * RNF-01 (determinismo auditable: el reloj lo pasa el llamante).
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): PDO nativo, 100% consultas preparadas con
 *     parameter binding.
 *   - Art. IV (Velo Arcano): el dictamen es NARRATIVA, no metadato. Se guarda
 *     íntegro —sin truncar, sin resumir y sin normalizar— porque es el texto
 *     que el autor leerá en su libreta para enmendar la obra: un dictamen
 *     recortado convierte la subsanación en adivinación.
 *   - Art. V (Dualidad): identificadores en inglés camelCase, claves de base
 *     de datos en snake_case, documentación en noble castellano.
 *
 * Reparto de responsabilidades (Tareas 2.3 y 2.4): este repositorio MIDE y
 * PERSISTE; no juzga. Que el Maestro esté vetado por el Artículo III, que la
 * obra siga en deliberación o que el veto deba arrastrar la anulación de los
 * avales previos son veredictos de `MasterDeliberationService`, que inscribe
 * el dictamen y transiciona el expediente dentro de una MISMA transacción.
 * Aquí solo se guarda el hecho y se devuelve su letra.
 *
 * Inmutabilidad del dictamen: la tabla no conoce columnas de edición ni de
 * anulación, y este repositorio no ofrece método alguno para mutarla. Un
 * dictamen, una vez pronunciado, es historia del santuario: la enmienda de
 * RF-01.4 se logra con una nueva deliberación, jamás reescribiendo la
 * anterior.
 */

declare(strict_types=1);

namespace Grimorio\Repositories;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Repositorio de los Dictámenes de Objeción: emisión y memoria.
 */
final class ObjectionVerdictRepository
{
    /**
     * Justificación mínima exigible a un Dictamen de Objeción (RF-02.5).
     *
     * Fuente única de verdad del umbral de veinte: los servicios de
     * deliberación y el modal de la Torre (Tareas 2.4 y 6.1) lo consumen desde
     * aquí en lugar de repetir el número en cada capa. El mismo umbral rige el
     * Edicto Imperial de RF-04.5.
     */
    public const MIN_OBJECTION_REASON_LENGTH = 20;

    /**
     * Proyección canónica de un dictamen (columnas del esquema de SPEC-08,
     * Tarea 1.1).
     */
    private const VERDICT_COLUMNS = 'id, spell_id, master_id, objection_reason, objected_at';

    /** Conexión PDO del santuario (Singleton del front controller). */
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Inscribe un Dictamen de Objeción Fundamentada (RF-02.5, RF-02.6).
     *
     * La justificación se guarda tal como el Maestro la redactó, con su
     * puntuación y su ritmo: el texto es la prueba del veto y la instrucción de
     * subsanación del autor (RF-06.2). Antes de tocar la base se comprueba el
     * umbral de veinte caracteres, medido en caracteres y no en bytes para que
     * un texto breve en castellano no pase por su longitud aparente en un
     * motor y fracase en otro.
     *
     * Este método NO revoca las firmas previas ni transiciona el expediente:
     * esas dos escrituras pertenecen a la transacción de `MasterDeliberationService`
     * (Tarea 2.4), que las compone con esta para que la objeción y sus efectos
     * sean un solo gesto confirmable o reversible (RF-02.6).
     *
     * @param string $verdictId       Identificador textual del dictamen.
     * @param string $spellId         Conjuro objetado.
     * @param string $masterId        Maestro que emite el veto.
     * @param string $objectionReason Justificación solemne en castellano.
     * @param string $objectedAtUtc   Instante del dictamen (ISO 8601 UTC), inyectado por el llamante.
     *
     * @return array{
     *   id: string, spell_id: string, master_id: string,
     *   objection_reason: string, objected_at: string
     * }
     *
     * @throws InvalidArgumentException Si la justificación no alcanza los veinte caracteres.
     */
    public function insertVerdict(
        string $verdictId,
        string $spellId,
        string $masterId,
        string $objectionReason,
        string $objectedAtUtc
    ): array {
        $this->assertObjectionReason($objectionReason);

        $statement = $this->pdo->prepare(
            'INSERT INTO objection_verdicts (
                 id, spell_id, master_id, objection_reason, objected_at
             ) VALUES (
                 :verdictId, :spellId, :masterId, :objectionReason, :objectedAt
             )'
        );
        $statement->execute([
            ':verdictId'       => $verdictId,
            ':spellId'         => $spellId,
            ':masterId'        => $masterId,
            ':objectionReason' => $objectionReason,
            ':objectedAt'      => $objectedAtUtc,
        ]);

        $verdict = $this->findVerdictById($verdictId);
        if ($verdict === null) {
            throw new RuntimeException(
                'El dictamen recién inscrito no pudo releerse: la base violó su propio contrato.'
            );
        }

        return $verdict;
    }

    /**
     * Recupera un dictamen por su identificador.
     *
     * @return array{
     *   id: string, spell_id: string, master_id: string,
     *   objection_reason: string, objected_at: string
     * }|null
     */
    public function findVerdictById(string $verdictId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::VERDICT_COLUMNS . '
               FROM objection_verdicts
              WHERE id = :verdictId'
        );
        $statement->execute([':verdictId' => $verdictId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Última objeción formulada contra un conjuro, con su texto ÍNTEGRO
     * (RF-06.2).
     *
     * Es la lectura de la libreta del autor: al reabrir una obra rechazada
     * (RF-01.4) el creador ha de leer por qué fue vetada. Devuelve el texto
     * completo, sin recortar, porque sobre él se subsana.
     *
     * El orden es determinista: por fecha descendente y, a igualdad de
     * milésima —dos Maestros pueden objetar en el mismo instante—, por
     * identificador descendente. Así «la última» es siempre la misma fila y no
     * la que el motor decida devolver primero (RNF-01).
     *
     * @return array{
     *   id: string, spell_id: string, master_id: string,
     *   objection_reason: string, objected_at: string
     * }|null
     */
    public function findLatestVerdictBySpell(string $spellId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::VERDICT_COLUMNS . '
               FROM objection_verdicts
              WHERE spell_id = :spellId
              ORDER BY objected_at DESC, id DESC
              LIMIT 1'
        );
        $statement->execute([':spellId' => $spellId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Historial completo de objeciones de un conjuro, de la más antigua a la
     * más reciente (RF-06.2).
     *
     * La obra puede haber sido objetada, enmendada y vuelta a objetar: el
     * historial entero es lo que permite al autor ver qué corrigió y qué se le
     * señala ahora, y al santuario medir si una obra reincide en el mismo
     * defecto. Se ordena ascendente a propósito, en el orden en que la obra
     * vivió su historia.
     *
     * @return list<array{
     *   id: string, spell_id: string, master_id: string,
     *   objection_reason: string, objected_at: string
     * }>
     */
    public function findVerdictsBySpell(string $spellId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::VERDICT_COLUMNS . '
               FROM objection_verdicts
              WHERE spell_id = :spellId
              ORDER BY objected_at ASC, id ASC'
        );
        $statement->execute([':spellId' => $spellId]);

        $verdicts = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $verdicts[] = $this->hydrate($row);
        }

        return $verdicts;
    }

    /**
     * Dictámenes emitidos por un Maestro, del más reciente al más antiguo.
     *
     * Sostiene la bitácora de la Torre: cuántas obras ha vetado cada Maestro y
     * con qué fundamento, lectura que la revisión de gobierno necesita para
     * distinguir a un censor severo de uno caprichoso (RF-06.1).
     *
     * @return list<array{
     *   id: string, spell_id: string, master_id: string,
     *   objection_reason: string, objected_at: string
     * }>
     */
    public function findVerdictsByMaster(string $masterId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::VERDICT_COLUMNS . '
               FROM objection_verdicts
              WHERE master_id = :masterId
              ORDER BY objected_at DESC, id DESC'
        );
        $statement->execute([':masterId' => $masterId]);

        $verdicts = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $verdicts[] = $this->hydrate($row);
        }

        return $verdicts;
    }

    /**
     * Vela por el rigor del Dictamen de Objeción (RF-02.5).
     *
     * El umbral se mide sobre el texto ya recortado de espacios extremos: una
     * justificación de veinte espacios no es una justificación. Se cuentan
     * caracteres —no bytes— porque el dictamen se redacta en castellano y la
     * especificación habla de caracteres.
     *
     * @throws InvalidArgumentException Si la justificación no alcanza el umbral.
     */
    private function assertObjectionReason(string $objectionReason): void
    {
        if (mb_strlen(trim($objectionReason)) < self::MIN_OBJECTION_REASON_LENGTH) {
            throw new InvalidArgumentException(
                'El Dictamen de Objeción exige una justificación solemne de al menos '
                . self::MIN_OBJECTION_REASON_LENGTH . ' caracteres: un veto sin fundamento es un capricho.'
            );
        }
    }

    /**
     * Proyecta una fila de `objection_verdicts` al contrato canónico.
     *
     * @param array<string, mixed> $row Fila cruda del motor de datos.
     *
     * @return array{
     *   id: string, spell_id: string, master_id: string,
     *   objection_reason: string, objected_at: string
     * }
     */
    private function hydrate(array $row): array
    {
        return [
            'id'               => (string) $row['id'],
            'spell_id'         => (string) $row['spell_id'],
            'master_id'        => (string) $row['master_id'],
            'objection_reason' => (string) $row['objection_reason'],
            'objected_at'      => (string) $row['objected_at'],
        ];
    }
}
