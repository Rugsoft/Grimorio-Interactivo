<?php

/**
 * CollectionPageDto.php — Hoja paginada del Tomo Personal
 * (SPEC-11, Tarea 3.3).
 *
 * Es el sobre de datos de la vista «Mi Grimorio»: las entradas
 * enriquecidas (CollectionEntryDto) con los metadatos de paginación
 * que alimentan el rótulo «N entradas · página X de Y» y la
 * paginación viva (plan §3.5, caso límite 10).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): serialización JSON nativa vía
 *     JsonSerializable + json_encode, sin librerías.
 *   - Artículo V: claves técnicas en inglés camelCase, documentación
 *     en noble castellano.
 *
 * Contrato (plan §2.2, GET /api/v1/grimoire/collection): las claves
 * raíz son EXACTAMENTE entries, total, page, limit, totalPages.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use JsonSerializable;

/**
 * Página del tomo: entradas enriquecidas + metadatos de paginación.
 */
final readonly class CollectionPageDto implements JsonSerializable
{
    /** Tamaño canónico de hoja del tomo (caso límite 4, plan §3.5). */
    public const PAGE_LIMIT = 50;

    /**
     * Forja la hoja del tomo.
     *
     * @param list<CollectionEntryDto> $entries Entradas de esta hoja,
     *        ordenadas por adición (la más reciente primero, RF-02.1).
     * @param int $total Total de entradas del tomo bajo el filtro vigente
     *        (comparte criterio con las entradas: jamás describe un
     *        conjunto distinto del exhibido).
     * @param int $page Hoja corriente (base 1).
     * @param int $limit Tamaño de hoja aplicado (candado cerrado en 50).
     * @param int $totalPages Total de hojas vivas del tomo filtrado.
     */
    public function __construct(
        public array $entries,
        public int $total,
        public int $page,
        public int $limit,
        public int $totalPages,
    ) {
    }

    /**
     * Serialización JSON nativa: retorna el mapa de claves camelCase del
     * contrato del plan §2.2. json_encode() sobre este DTO genera
     * EXACTAMENTE esa estructura.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'entries'    => $this->entries,
            'total'      => $this->total,
            'page'       => $this->page,
            'limit'      => $this->limit,
            'totalPages' => $this->totalPages,
        ];
    }
}
