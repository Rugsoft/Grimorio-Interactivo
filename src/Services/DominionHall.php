<?php

/**
 * DominionHall.php — Salón del Dominio: la vista completa del Salón de los Linajes.
 *
 * Tarea 3.3 (TASKS-07): valor de retorno de
 * WeeklyDominionService::hallOfDominion(), que sirve el Endpoint 11 del plan
 * 2.2. Sus cuatro secciones son las que el plan estipula EXACTAMENTE:
 *
 *   - `weeklyRanking`     · la contienda en curso, ordenada por PDA descendente.
 *   - `historicalRanking` · el prestigio perpetuo de todos los tiempos.
 *   - `currentRegentClan` · la casa que ciñe la corona tras el último corte.
 *   - `hallOfFameWeeks`   · el Libro Mayor de Campeones, semana a semana.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DTO PHP puro de serialización nativa; los
 *     `ClanDto` y `WeeklyCycleDto` que porta ya son JsonSerializable, de modo
 *     que `json_encode($hall)` emite el contrato REST sin transformación.
 *   - Artículo IV (El Velo Arcano): los títulos de las casas viajan en noble
 *     castellano; las claves, en inglés camelCase (Artículo V).
 */

declare(strict_types=1);

namespace Grimorio\Services;

use Grimorio\Dto\ClanDto;
use Grimorio\Dto\WeeklyCycleDto;
use JsonSerializable;

/**
 * Salón del Dominio: clasificación viva, prestigio perpetuo y Libro Mayor.
 */
final class DominionHall implements JsonSerializable
{
    /**
     * @param list<ClanDto>        $weeklyRanking     Casas activas por PDA semanal descendente.
     * @param list<ClanDto>        $historicalRanking Casas activas por gloria perpetua descendente.
     * @param ClanDto|null         $currentRegentClan Clan Regente vigente, o null si aún no hubo corte.
     * @param list<WeeklyCycleDto> $hallOfFameWeeks   Libro Mayor de Campeones, del corte más reciente al más antiguo.
     */
    public function __construct(
        public readonly array $weeklyRanking,
        public readonly array $historicalRanking,
        public readonly ?ClanDto $currentRegentClan,
        public readonly array $hallOfFameWeeks,
    ) {
    }

    /**
     * Serialización JSON nativa: las cuatro secciones del Endpoint 11 en su
     * orden canónico.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'weeklyRanking'     => $this->weeklyRanking,
            'historicalRanking' => $this->historicalRanking,
            'currentRegentClan' => $this->currentRegentClan,
            'hallOfFameWeeks'   => $this->hallOfFameWeeks,
        ];
    }
}
