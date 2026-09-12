<?php

/**
 * PortalController.php — Endpoints de portada y hechizos destacados.
 *
 * Tarea 1.5 (TASKS-01): controlador REST del portal (RF-01).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): controlador PHP puro sobre el núcleo HTTP propio.
 *   - Artículo V: identificadores en inglés camelCase, documentación en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Controllers;

use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Models\Spell;
use Grimorio\Services\SpellDiscoveryService;

/**
 * Sirve la galería de destacados del portal (RF-01.1 a RF-01.3).
 */
final class PortalController
{
    private SpellDiscoveryService $discoveryService;

    public function __construct(SpellDiscoveryService $discoveryService)
    {
        $this->discoveryService = $discoveryService;
    }

    /**
     * GET /api/v1/portal/featured — los 3 destacados o los Pergaminos Primordiales.
     */
    public function featured(Request $request): Response
    {
        // El parámetro $request queda reservado para futuras cabeceras (idioma, etc.).
        $featuredSpells = $this->discoveryService->getFeaturedSpells();

        return Response::json([
            'success' => true,
            'data'    => array_map(
                static fn (Spell $spell): array => $spell->toSummaryDto(),
                $featuredSpells
            ),
        ]);
    }
}
