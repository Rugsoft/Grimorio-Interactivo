<?php

/**
 * WeeklyCycleDto.php — Corte semanal inmutable y su Clan Regente.
 *
 * Tarea 2.1 (TASKS-07): acta de un ciclo de Dominio concluido, base del
 * Libro Mayor de Campeones (`hallOfFameWeeks`) que exhibe el Salón de los
 * Linajes (RF-04.4, RF-06.1).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): serialización JSON nativa, sin librerías.
 *   - Artículo IV (El Velo Arcano): la crónica se narra en noble castellano
 *     (`regentClanName`, `label`); los números de semana y año son ISO 8601,
 *     ciegos y auditables.
 *   - Artículo V: claves técnicas en inglés camelCase, documentación en
 *     castellano.
 *
 * Contrato REST (plan 2.1, Endpoints 11 y 12). Claves raíz EXACTAMENTE: id,
 * weekNumber, cycleYear, regentClanId, regentClanName, winningPoints,
 * winnerSpellCount, closedAt, label — json_encode($dto) genera esa
 * estructura sin transformación adicional.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Acta de un corte dominical: qué casa ciñó la corona y con qué gloria.
 */
final readonly class WeeklyCycleDto implements JsonSerializable
{
    /**
     * Retrata el cierre de un ciclo semanal.
     *
     * @param string      $id                Identificador del ciclo archivado.
     * @param int         $weekNumber        Número de semana ISO 8601 (1 a 53).
     * @param int         $cycleYear         Año del ciclo (ej. 2026).
     * @param string      $regentClanId      Casa proclamada Clan Regente.
     * @param int         $winningPoints     PDA semanales con los que se alzó.
     * @param int         $winnerSpellCount  Conjuros validados aportados en la semana.
     * @param string|null $closedAt          Marca ISO 8601 UTC del corte (domingo 23:59:59).
     * @param string      $regentClanName    Nombre Canónico del regente (noble castellano).
     *
     * @throws InvalidArgumentException Si el identificador, la semana o el año no son canónicos.
     */
    public function __construct(
        public string $id,
        public int $weekNumber,
        public int $cycleYear,
        public string $regentClanId,
        public int $winningPoints = 0,
        public int $winnerSpellCount = 0,
        public ?string $closedAt = null,
        public string $regentClanName = '',
    ) {
        if (trim($this->id) === '') {
            throw new InvalidArgumentException('Todo ciclo semanal archivado exige un identificador.');
        }

        if (trim($this->regentClanId) === '') {
            throw new InvalidArgumentException('Todo ciclo concluido corona a un Clan Regente.');
        }

        if ($this->weekNumber < 1 || $this->weekNumber > 53) {
            throw new InvalidArgumentException(
                "Número de semana ISO inválido «{$this->weekNumber}»: se admite del 1 al 53."
            );
        }

        if ($this->cycleYear < 1) {
            throw new InvalidArgumentException('El año del ciclo debe ser un entero positivo.');
        }

        if ($this->winningPoints < 0 || $this->winnerSpellCount < 0) {
            throw new InvalidArgumentException('Ni la gloria ni el cómputo de conjuros del ciclo son negativos.');
        }
    }

    /**
     * Forja el DTO desde una fila de base de datos (`weekly_cycles` con, si
     * procede, el nombre del clan regente resuelto por JOIN).
     *
     * @param array<string, null|int|string> $databaseRow
     */
    public static function fromDatabaseRow(array $databaseRow): self
    {
        $readString = static fn (string $key): string => isset($databaseRow[$key]) && is_scalar($databaseRow[$key])
            ? (string) $databaseRow[$key]
            : '';
        $readNullableString = static fn (string $key): ?string => isset($databaseRow[$key]) && is_scalar($databaseRow[$key]) && (string) $databaseRow[$key] !== ''
            ? (string) $databaseRow[$key]
            : null;

        return new self(
            id: (string) ($databaseRow['id'] ?? ''),
            weekNumber: (int) ($databaseRow['week_number'] ?? 0),
            cycleYear: (int) ($databaseRow['cycle_year'] ?? 0),
            regentClanId: $readString('regent_clan_id'),
            winningPoints: (int) ($databaseRow['winning_points'] ?? 0),
            winnerSpellCount: (int) ($databaseRow['winner_spell_count'] ?? 0),
            closedAt: $readNullableString('closed_at'),
            regentClanName: $readString('regent_clan_name'),
        );
    }

    /**
     * Etiqueta ceremonial del ciclo para el Libro Mayor de Campeones:
     * «Año 2026 · Semana 36». El texto viaja en noble castellano (Artículo IV).
     */
    public function label(): string
    {
        return sprintf('Año %d · Semana %02d', $this->cycleYear, $this->weekNumber);
    }

    /**
     * ¿Se corresponde este acta con el ciclo (año, semana) indicado?
     *
     * Sirve a la guarda de idempotencia del cierre dominical (RF-04.2): un
     * mismo corte jamás debe inmortalizarse dos veces.
     */
    public function matchesCycle(int $cycleYear, int $weekNumber): bool
    {
        return $this->cycleYear === $cycleYear && $this->weekNumber === $weekNumber;
    }

    /**
     * Serialización JSON nativa: el contrato de los Endpoints 11 y 12 en su
     * orden canónico. json_encode() sobre este DTO genera EXACTAMENTE el
     * formato estipulado en el plan, preservando los nulos canónicos que el
     * plan declara opcionales (corte aún no sellado).
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'               => $this->id,
            'weekNumber'       => $this->weekNumber,
            'cycleYear'        => $this->cycleYear,
            'regentClanId'     => $this->regentClanId,
            'regentClanName'   => $this->regentClanName,
            'winningPoints'    => $this->winningPoints,
            'winnerSpellCount' => $this->winnerSpellCount,
            'closedAt'         => $this->closedAt,
            'label'            => $this->label(),
        ];
    }
}
