<?php

/**
 * WeeklyCycleRepository.php — Persistencia PDO de los ciclos del Dominio
 * Semanal y del Libro Mayor de Campeones del santuario.
 *
 * Tarea 1.4 (TASKS-07): canal exclusivo de lectura y escritura de la tabla
 * `weekly_cycles`, que inmortaliza cada corte dominical.
 *
 * Cubre: RF-04.2 (proclamación del Clan Regente del Santuario), RF-04.4
 * (crónica perpetua en el Salón de los Linajes), RF-06.1 (Libro Mayor de
 * Campeones) y RNF-01, RNF-04 (determinismo y transparencia auditable).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, 100% consultas preparadas con
 *     parameter binding.
 *   - Artículo III (Transparencia): la crónica de campeones es INMUTABLE y
 *     solo admite escritura (cada corte semanal se inscribe una única vez);
 *     jamás se reescribe ni se borra una semana concluida.
 *   - Artículo V (Dualidad): identificadores en inglés camelCase, claves de
 *     base de datos en snake_case, documentación en castellano.
 *
 * Decisiones de diseño:
 *   - La guarda de unicidad semanal vive DENTRO del propio INSERT
 *     (`INSERT ... SELECT ... WHERE NOT EXISTS`): un corte ya inscrito no se
 *     puede duplicar ni siquiera bajo dos invocaciones simultáneas del
 *     proceso de cierre (RNF-01). `recordClosedCycle()` responde `null`
 *     cuando la semana ya estaba inmortalizada.
 *   - Los instantes se reciben por parámetro (`$closedAt`, ISO 8601 UTC) y
 *     jamás se leen del reloj del sistema: el corte es ciego y auditable.
 */

declare(strict_types=1);

namespace Grimorio\Repositories;

use InvalidArgumentException;
use PDO;

/**
 * Repositorio de ciclos semanales: coronación y Libro Mayor de Campeones.
 */
final class WeeklyCycleRepository
{
    /**
     * Semanas ISO que admite un ciclo anual (RF-04.1).
     *
     * El calendario ISO 8601 llega hasta la semana 53 en los años largos.
     */
    private const MIN_WEEK_NUMBER = 1;
    private const MAX_WEEK_NUMBER = 53;

    /**
     * Centinela de «sin recorte» para el Libro Mayor de Campeones.
     *
     * Se emplea un entero positivo enorme —y no `LIMIT -1`, propio de
     * SQLite— para que la sentencia siga siendo portable a MySQL/MariaDB.
     */
    private const NO_LIMIT_ROWS = 1000000;

    /**
     * Proyección canónica de un ciclo (columnas del esquema de SPEC-07).
     * Declarada una sola vez para que toda lectura devuelva el mismo
     * contrato.
     */
    private const CYCLE_COLUMNS = 'id, week_number, cycle_year, regent_clan_id, '
        . 'winning_points, winner_spell_count, closed_at';

    /** Conexión PDO del santuario (Singleton del front controller). */
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Inscribe el corte dominical de una semana concluida (RF-04.2, RF-04.4).
     *
     * Registra al Clan Regente proclamado, los PDA con los que se alzó con la
     * corona y el número de conjuros validados que aportó durante su
     * mandato. La guarda atómica impide inmortalizar dos veces la misma
     * semana: cada ciclo se cierra una única vez y su crónica es perpetua.
     *
     * @param string $cycleId          Identificador textual del ciclo.
     * @param int    $weekNumber       Semana ISO del ciclo (1 a 53).
     * @param int    $cycleYear        Año del ciclo (ej. 2026).
     * @param string $regentClanId     Hermandad proclamada soberana.
     * @param int    $winningPoints    PDA con los que se alzó con la corona.
     * @param int    $winnerSpellCount Conjuros validados aportados en la semana.
     * @param string $closedAt         Instante del corte (ISO 8601 UTC).
     *
     * @return array{
     *   id: string, week_number: int, cycle_year: int, regent_clan_id: string,
     *   winning_points: int, winner_spell_count: int, closed_at: string
     * }|null El ciclo inscrito, o null si esa semana ya estaba inmortalizada.
     *
     * @throws InvalidArgumentException Si el número de semana rompe el canon ISO.
     */
    public function recordClosedCycle(
        string $cycleId,
        int $weekNumber,
        int $cycleYear,
        string $regentClanId,
        int $winningPoints,
        int $winnerSpellCount,
        string $closedAt
    ): ?array {
        $this->assertWeekNumber($weekNumber);

        $statement = $this->pdo->prepare(
            'INSERT INTO weekly_cycles (
                 id, week_number, cycle_year, regent_clan_id,
                 winning_points, winner_spell_count, closed_at
             )
             SELECT :cycleId, :weekNumber, :cycleYear, :regentClanId,
                    :winningPoints, :winnerSpellCount, :closedAt
              WHERE NOT EXISTS (
                        SELECT 1
                          FROM weekly_cycles
                         WHERE cycle_year = :cycleYearGuard
                           AND week_number = :weekNumberGuard
                    )'
        );
        // Doble canal dialectal (SPEC-13 §8.6): los marcadores de la guardia
        // llevan nombre PROPIO porque MySQL con prepares nativos
        // (EMULATE_PREPARES = false) prohíbe reutilizar un parámetro
        // nombrado (HY093), mientras que SQLite lo tolera. Misma semántica
        // en ambos motores.
        $statement->bindValue(':cycleId', $cycleId);
        $statement->bindValue(':weekNumber', $weekNumber, PDO::PARAM_INT);
        $statement->bindValue(':cycleYear', $cycleYear, PDO::PARAM_INT);
        $statement->bindValue(':regentClanId', $regentClanId);
        $statement->bindValue(':winningPoints', $winningPoints, PDO::PARAM_INT);
        $statement->bindValue(':winnerSpellCount', $winnerSpellCount, PDO::PARAM_INT);
        $statement->bindValue(':closedAt', $closedAt);
        $statement->bindValue(':cycleYearGuard', $cycleYear, PDO::PARAM_INT);
        $statement->bindValue(':weekNumberGuard', $weekNumber, PDO::PARAM_INT);
        $statement->execute();

        if ($statement->rowCount() === 0) {
            // La semana ya fue inmortalizada: la crónica es inmutable.
            return null;
        }

        return $this->findById($cycleId);
    }

    /**
     * Recupera un ciclo por su identificador.
     *
     * @return array{
     *   id: string, week_number: int, cycle_year: int, regent_clan_id: string,
     *   winning_points: int, winner_spell_count: int, closed_at: string
     * }|null
     */
    public function findById(string $cycleId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::CYCLE_COLUMNS . '
               FROM weekly_cycles
              WHERE id = :cycleId'
        );
        $statement->execute([':cycleId' => $cycleId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Recupera el ciclo de una semana ISO concreta (RF-04.1, RF-04.2).
     *
     * Es el espejo de la guarda de `recordClosedCycle()`: permite a
     * WeeklyDominionService saber si la semana que acaba de concluir ya fue
     * proclamada ANTES de plegar contadores, de modo que un segundo latido
     * del cron sea inofensivo y no vuelva a recorrer el Libro Mayor.
     *
     * @return array{
     *   id: string, week_number: int, cycle_year: int, regent_clan_id: string,
     *   winning_points: int, winner_spell_count: int, closed_at: string
     * }|null
     */
    public function findCycleForWeek(int $weekNumber, int $cycleYear): ?array
    {
        $this->assertWeekNumber($weekNumber);

        $statement = $this->pdo->prepare(
            'SELECT ' . self::CYCLE_COLUMNS . '
               FROM weekly_cycles
              WHERE week_number = :weekNumber
                AND cycle_year = :cycleYear
              ORDER BY closed_at DESC
              LIMIT 1'
        );
        $statement->execute([
            ':weekNumber' => $weekNumber,
            ':cycleYear'  => $cycleYear,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Clan Regente del Santuario vigente (RF-04.2).
     *
     * Devuelve la crónica del ciclo concluido más recientemente: la casa
     * coronada cuyo mandato cubre la semana en curso. La ordenación desciende
     * por año y semana ISO, con el corte como desempate, de modo que la
     * respuesta es determinista (RNF-01). Devuelve `null` mientras el
     * santuario no haya celebrado todavía su primer corte dominical.
     *
     * @return array{
     *   id: string, week_number: int, cycle_year: int, regent_clan_id: string,
     *   winning_points: int, winner_spell_count: int, closed_at: string
     * }|null
     */
    public function findCurrentRegentCycle(): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::CYCLE_COLUMNS . '
               FROM weekly_cycles
              ORDER BY cycle_year DESC, week_number DESC, closed_at DESC
              LIMIT 1'
        );
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Libro Mayor de Campeones del Salón de los Linajes (RF-04.4, RF-06.1).
     *
     * Crónica cronológica inversa de todas las semanas concluidas: de la
     * corona más reciente a la más antigua, para que el Gran Tomo exhiba
     * primero el mandato vigente.
     *
     * @param int $limit Máximo de semanas a servir; 0 devuelve la crónica íntegra.
     *
     * @return list<array{
     *   id: string, week_number: int, cycle_year: int, regent_clan_id: string,
     *   winning_points: int, winner_spell_count: int, closed_at: string
     * }>
     */
    public function findCycleHistory(int $limit = 0): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::CYCLE_COLUMNS . '
               FROM weekly_cycles
              ORDER BY cycle_year DESC, week_number DESC, closed_at DESC
              LIMIT :maxRows'
        );
        $statement->bindValue(
            ':maxRows',
            $limit > 0 ? $limit : self::NO_LIMIT_ROWS,
            PDO::PARAM_INT
        );
        $statement->execute();

        $cycles = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cycles[] = $this->hydrate($row);
        }

        return $cycles;
    }

    /**
     * Normaliza una fila del plano relacional a un array tipado de PHP.
     *
     * @param array<string, mixed> $row
     *
     * @return array{
     *   id: string, week_number: int, cycle_year: int, regent_clan_id: string,
     *   winning_points: int, winner_spell_count: int, closed_at: string
     * }
     */
    private function hydrate(array $row): array
    {
        return [
            'id'                 => (string) $row['id'],
            'week_number'        => (int) $row['week_number'],
            'cycle_year'         => (int) $row['cycle_year'],
            'regent_clan_id'     => (string) $row['regent_clan_id'],
            'winning_points'     => (int) $row['winning_points'],
            'winner_spell_count' => (int) $row['winner_spell_count'],
            'closed_at'          => (string) $row['closed_at'],
        ];
    }

    /**
     * Vela por el canon ISO 8601 del número de semana (RF-04.1).
     *
     * @throws InvalidArgumentException Si la semana cae fuera del calendario.
     */
    private function assertWeekNumber(int $weekNumber): void
    {
        if ($weekNumber < self::MIN_WEEK_NUMBER || $weekNumber > self::MAX_WEEK_NUMBER) {
            throw new InvalidArgumentException(
                'El ciclo semanal solo admite las semanas ISO del 1 al 53.'
            );
        }
    }
}
