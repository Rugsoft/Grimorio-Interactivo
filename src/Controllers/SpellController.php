<?php

/**
 * SpellController.php — Endpoints de consulta y detalle de hechizos.
 *
 * Tarea 1.5 (TASKS-01): controlador REST del catálogo (RF-03, RF-04, RF-06.2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro; validación y saneado manual de query params.
 *   - Artículo III: los experimentales solo se revelan con la bandera explícita.
 *   - Artículo V: identificadores en inglés camelCase, documentación en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Controllers;

use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Services\SpellDiscoveryService;

/**
 * Sirve el catálogo paginado y las fichas de detalle del compendio.
 */
final class SpellController
{
    /** Límites de paginación defendibles (RF-03.7: bloques de 50 en producción). */
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 100;

    private SpellDiscoveryService $discoveryService;

    public function __construct(SpellDiscoveryService $discoveryService)
    {
        $this->discoveryService = $discoveryService;
    }

    /**
     * GET /api/v1/spells — catálogo paginado con filtros acumulativos.
     *
     * Query params aceptados (plan 3):
     *   query, schools (csv), maxMana, includeExperimental (0/1), offset, limit.
     */
    public function index(Request $request): Response
    {
        // --- Validación y saneado de parámetros (400 ante basura) ---
        $maxMana = null;
        $rawMaxMana = $request->getQueryParam('maxMana');
        if ($rawMaxMana !== null && $rawMaxMana !== '') {
            if (!ctype_digit($rawMaxMana)) {
                return $this->badRequest(
                    'INVALID_MAX_MANA',
                    'El umbral de maná debe ser un número entero positivo.'
                );
            }
            $maxMana = (int) $rawMaxMana;
        }

        $offset = 0;
        $rawOffset = $request->getQueryParam('offset');
        if ($rawOffset !== null && $rawOffset !== '') {
            if (!ctype_digit($rawOffset)) {
                return $this->badRequest(
                    'INVALID_OFFSET',
                    'El desplazamiento del catálogo debe ser un número entero no negativo.'
                );
            }
            $offset = (int) $rawOffset;
        }

        $limit = self::DEFAULT_LIMIT;
        $rawLimit = $request->getQueryParam('limit');
        if ($rawLimit !== null && $rawLimit !== '') {
            if (!ctype_digit($rawLimit) || (int) $rawLimit < 1) {
                return $this->badRequest(
                    'INVALID_LIMIT',
                    'El tamaño de bloque debe ser un número entero positivo.'
                );
            }
            // Tope duro de bloque para evitar respuestas desmesuradas.
            $limit = min((int) $rawLimit, self::MAX_LIMIT);
        }

        // schools llega como CSV (plan 3): 'evocation,abjuration'.
        $rawSchools = $request->getQueryParam('schools');
        $schools = [];
        if ($rawSchools !== null && $rawSchools !== '') {
            foreach (explode(',', $rawSchools) as $school) {
                $school = trim($school);
                if ($school !== '') {
                    $schools[] = $school;
                }
            }
        }

        // La query cruda se entrega intacta: el servicio la trunca a 100 y la normaliza.
        $query = $request->getQueryParam('query');

        // Bandera de Archivos Experimentales (RF-03.2, Artículo III).
        $includeExperimental = in_array($request->getQueryParam('includeExperimental'), ['1', 'true'], true);

        $catalogPage = $this->discoveryService->getSpells(
            query: $query,
            schools: $schools,
            maxMana: $maxMana,
            includeExperimental: $includeExperimental,
            offset: $offset,
            limit: $limit
        );

        return Response::json([
            'success' => true,
            'data'    => [
                'items'   => array_map(
                    static fn ($spell): array => $spell->toSummaryDto(),
                    $catalogPage['items']
                ),
                'hasMore' => $catalogPage['hasMore'],
                'offset'  => $offset,
                'limit'   => $limit,
            ],
        ]);
    }

    /**
     * GET /api/v1/spells/{slug} — ficha técnica completa o 404 místico (plan 2.4).
     *
     * @param array<string, string> $routeParams Parámetros extraídos por el Router.
     */
    public function show(Request $request, array $routeParams): Response
    {
        // El parámetro $request queda reservado para futuras cabeceras.
        $slug = (string) ($routeParams['slug'] ?? '');

        $spell = $this->discoveryService->getSpellBySlug($slug);

        // Pergamino desterrado: contrato místico con acción de rescate (RF-06.2).
        if ($spell === null) {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'           => 'SCROLL_LOST_IN_AETHER',
                    'message'        => 'El pergamino que buscas se ha desvanecido en el éter.',
                    'details'        => "No se encontró ningún conjuro activo registrado bajo el identificador '{$slug}'.",
                    'recoveryAction' => 'RETURN_TO_LIBRARY',
                ],
            ], 404);
        }

        return Response::json([
            'success' => true,
            'data'    => $spell->toDetailDto(),
        ]);
    }

    /**
     * Sobre de error 400 homogéneo con el contrato místico del plan.
     */
    private function badRequest(string $errorCode, string $message): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => $errorCode,
                'message'        => $message,
                'recoveryAction' => 'RETRY_WITH_VALID_PARAMS',
            ],
        ], 400);
    }
}
