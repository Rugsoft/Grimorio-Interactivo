<?php

/**
 * AuditLogPage.php — Página de la bitácora con metadatos de paginación.
 *
 * Tarea 2.5 (TASKS-03): valor de retorno de AuditService::fetchLog(),
 * moldeado sobre el contrato JSON del plan 2.2 (Endpoint 6): lista de
 * entradas más objeto pagination (page, limit, totalItems, totalPages).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DTO PHP puro.
 *   - Artículo V: identificadores en inglés camelCase, documentación en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use Grimorio\Models\AuditEntry;

/**
 * Página de veredictos de la bitácora arcana.
 */
final class AuditLogPage
{
    /**
     * @param AuditEntry[]            $items      Entradas de la página (entidades inmutables).
     * @param array<string, int>      $pagination Metadatos: page, limit, totalItems, totalPages.
     */
    public function __construct(
        public readonly array $items,
        public readonly array $pagination,
    ) {
    }
}
