<?php

/**
 * ClanCatalogPage.php — Página del catálogo de hermandades con su paginación.
 *
 * Tarea 3.2 (TASKS-07): valor de retorno de ClanService::browseClans(), que
 * sirve el Endpoint 2 del plan 2.2 (catálogo y filtro de clanes). Su forma
 * replica la de AuditLogPage (Tarea 2.5 de TASKS-03) para que todo listado
 * paginado del santuario hable el mismo contrato: `items` más un objeto
 * `pagination` con `page`, `limit`, `totalItems` y `totalPages`.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DTO PHP puro; los `ClanDto` que porta son
 *     JsonSerializable, de modo que `json_encode($page)` basta para emitir el
 *     contrato REST sin transformación adicional.
 *   - Artículo V: identificadores en inglés camelCase, documentación en
 *     castellano.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use Grimorio\Dto\ClanDto;

/**
 * Página del catálogo de hermandades del santuario.
 */
final class ClanCatalogPage
{
    /**
     * @param list<ClanDto>       $items      Hermandades de la página, en el orden del canon.
     * @param array<string, int>  $pagination Metadatos: page, limit, totalItems, totalPages.
     */
    public function __construct(
        public readonly array $items,
        public readonly array $pagination,
    ) {
    }
}
