<?php

/**
 * LineageController.php — Endpoint REST del Catálogo de Linajes Canónicos.
 *
 * Tarea 3.1 (TASKS-07): sirve el Endpoint 10 del plan 2.2, que expone el
 * array inmutable de los ocho (8) Linajes Mágicos Canónicos (RF-02.1) con
 * su título ceremonial en castellano, su afinidad elemental rectora, su
 * glifo rúnico, su color de estandarte y su marco heráldico (RF-02.2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response nativos del proyecto y
 *     cero librerías. El canon es un dato litúrgico en memoria: este
 *     controlador no toca base de datos ni sesión alguna.
 *   - Artículo II: la ficha jamás porta coste de maná; el Linaje no altera
 *     la fórmula universal de la forja (RF-02.3). Aquí solo se publica.
 *   - RNF-03 (Velo Arcano): títulos y prosa en noble castellano.
 *   - RNF-05 (Dogma Vanilla y Dualidad Lingüística): claves técnicas en
 *     inglés camelCase; la interfaz, en castellano ceremonial.
 *
 * Diseño: el controlador NO declara el canon — eso sería una segunda fuente
 * de verdad. Delega íntegramente en LineageSynergyService (Tarea 2.2), cuya
 * única autoridad es `CANONICAL_LINEAGES`.
 */

declare(strict_types=1);

namespace Grimorio\Controllers;

use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Services\LineageSynergyService;

/**
 * Sirve el catálogo inmutable de los ocho Linajes Mágicos Canónicos.
 */
final class LineageController
{
    /** Servicio del canon: fuente única de verdad de los ocho linajes. */
    private LineageSynergyService $lineageService;

    public function __construct(LineageSynergyService $lineageService)
    {
        $this->lineageService = $lineageService;
    }

    /**
     * GET /api/v1/lineages — Catálogo de los ocho Linajes (Endpoint 10).
     *
     * Respuesta 200: {success, data:[...8 fichas], count:8}. El orden es el
     * del canon (RF-02.1) y cada ficha declara su clave canónica en inglés
     * (`id`), su título ceremonial en castellano (`name`), su elemento
     * rector (`rulingElement`), su glifo rúnico (`glyph`), su estandarte
     * (`bannerColor`) y su marco heráldico (`heraldicFrame`).
     *
     * El catálogo es de lectura pública: ningún rol ni sesión lo restringe
     * (RF-02.1 es un requisito ubicuo del santuario).
     */
    public function index(Request $request): Response
    {
        // El parámetro $request queda reservado para futuras cabeceras de
        // linaje temático; el catálogo no admite filtro alguno.
        $lineages = $this->lineageService->listLineages();

        return Response::json([
            'success' => true,
            'data'    => $lineages,
            'count'   => count($lineages),
        ], 200);
    }
}
