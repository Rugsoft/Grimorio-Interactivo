<?php

/**
 * GrimoireController.php — Endpoints REST del Simulador de Grimorio.
 *
 * Tarea 1.3 (TASKS-05): sirve el catálogo de páginas del Tomo Arcano
 * (SPEC-05, RF-01) sobre GrimoireQueryService (Tarea 1.2).
 *
 * Contrato (plan 2.1):
 *   - GET /api/v1/grimoire/spells          → 200 (tomo canónico o ensayos).
 *     Parámetros query: circle (1-5), element, mode (canonical|essays),
 *     page (≥1), limit (1-50). mode=essays exige sesión; anónimo → 401.
 *   - GET /api/v1/grimoire/spells/{id}     → 200 (ficha litúrgica) | 404.
 *
 * Constitución:
 *   - Artículo I: Request/Response/Router nativos del proyecto, PDO preparado.
 *   - Artículo III: los ensayos ajenos jamás se consultan (aislamiento por
 *     titular resuelto en el servicio a partir del usuario de sesión).
 *   - AGENTS.md 6.1: errores en sobres JSON controlados, jamás trazas.
 *   - Artículo V: identificadores camelCase, leyendas en noble castellano.
 */

declare(strict_types=1);

namespace Grimorio\Controllers;

use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Models\User;
use Grimorio\Services\GrimoireQueryService;
use RuntimeException;

/**
 * Controlador REST del Tomo Arcano del simulador.
 */
final class GrimoireController
{
    /** Afinidades elementales canónicas del santuario (SPEC-06, matriz). */
    private const CANONICAL_ELEMENTS = [
        'fire', 'water', 'lightning', 'earth', 'wind', 'light', 'darkness', 'pureArcane', 'none',
    ];

    /** Servicio de consulta y segmentación del catálogo (Tarea 1.2). */
    private GrimoireQueryService $queryService;

    public function __construct(GrimoireQueryService $queryService)
    {
        $this->queryService = $queryService;
    }

    /**
     * GET /api/v1/grimoire/spells — Catálogo de páginas del tomo.
     *
     * mode=canonical (por defecto): tomo público de validados.
     * mode=essays: ensayos del autor autenticado (401 si es anónimo).
     *
     * Respuestas: 200 OK (sobre de paginación con GrimoirePageDto),
     * 400 Bad Request (parámetros fuera del canon) y 401 Unauthorized
     * (essays sin sesión).
     */
    public function listSpells(Request $request): Response
    {
        // --- Saneado de parámetros (400 ante basura, degradación ante límites) ---
        $circle = null;
        $rawCircle = $request->getQueryParam('circle');
        if ($rawCircle !== null && $rawCircle !== '') {
            if (!ctype_digit($rawCircle)) {
                return $this->badRequest('INVALID_CIRCLE', 'El Círculo Arcano debe ser un número entero entre 1 y 5.');
            }
            $parsedCircle = (int) $rawCircle;
            if ($parsedCircle < 1 || $parsedCircle > 5) {
                return $this->badRequest('INVALID_CIRCLE', 'El Círculo Arcano pertenece al canon de 1 a 5.');
            }
            $circle = $parsedCircle;
        }

        $element = null;
        $rawElement = $request->getQueryParam('element');
        if ($rawElement !== null && $rawElement !== '') {
            if (!in_array($rawElement, self::CANONICAL_ELEMENTS, true)) {
                return $this->badRequest('INVALID_ELEMENT', 'Esa afinidad elemental no pertenece al canon del santuario.');
            }
            $element = $rawElement;
        }

        $page = 1;
        $rawPage = $request->getQueryParam('page');
        if ($rawPage !== null && $rawPage !== '') {
            if (!ctype_digit($rawPage)) {
                return $this->badRequest('INVALID_PAGE', 'La hoja del tomo debe ser un número entero positivo.');
            }
            $page = max(1, (int) $rawPage);
        }

        $limit = 10;
        $rawLimit = $request->getQueryParam('limit');
        if ($rawLimit !== null && $rawLimit !== '') {
            if (!ctype_digit($rawLimit)) {
                return $this->badRequest('INVALID_LIMIT', 'El tamaño de hoja debe ser un número entero positivo.');
            }
            $limit = max(1, min(50, (int) $rawLimit));
        }

        // --- Segmentación por modo y sesión (RF-01.2 / RF-01.4) ---
        $mode = $request->getQueryParam('mode') ?? 'canonical';
        if ($mode === 'essays') {
            $author = $request->getUser();
            if ($author === null || $author->getId() === '') {
                return $this->unauthenticatedResponse();
            }
            $pageData = $this->queryService->getAuthorEssays($author, $circle, $element, $page, $limit);
        } else {
            // Cualquier mode distinto de essays degrada al tomo canónico
            // público (jamás filtra borradores por accidente).
            $pageData = $this->queryService->getCanonicalSpells($circle, $element, $page, $limit);
        }

        return Response::json([
            'success' => true,
            'data'    => $pageData,
        ], 200);
    }

    /**
     * GET /api/v1/grimoire/spells/{id} — Detalle litúrgico individual.
     *
     * Reglas de acceso: los validados son públicos; los draft/experimental
     * solo se entregan a su titular autenticado (Artículo III).
     *
     * Respuestas: 200 OK (ficha litúrgica) | 404 Not Found.
     */
    public function showSpell(Request $request, array $routeParams): Response
    {
        $spellId = (string) ($routeParams['id'] ?? '');
        if ($spellId === '') {
            return $this->notFoundResponse();
        }

        try {
            $detailPage = $this->queryService->getSpellDetail($spellId, $request->getUser());
        } catch (RuntimeException) {
            return $this->notFoundResponse();
        }

        if ($detailPage === null) {
            return $this->notFoundResponse();
        }

        return Response::json([
            'success' => true,
            'data'    => $detailPage,
        ], 200);
    }

    /**
     * Sobre de error 400 homogéneo con el contrato místico del proyecto.
     */
    private function badRequest(string $errorCode, string $message): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => $errorCode,
                'message'        => $message,
                'recoveryAction' => 'CORRECT_THE_QUERY',
            ],
        ], 400);
    }

    /**
     * Sobre 401 canónico del contrato SPEC-03 (vínculo no activo).
     */
    private function unauthenticatedResponse(): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'    => 'UNAUTHENTICATED',
                'message' => 'El vínculo arcano no está activo: conságrate o vincula tu identidad para hojear tus ensayos.',
            ],
        ], 401);
    }

    /**
     * Sobre 404 canónico para páginas desvanecidas del tomo.
     */
    private function notFoundResponse(): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => 'SPELL_NOT_FOUND',
                'message'        => 'Ese conjuro no existe o no habita tu grimorio.',
                'recoveryAction' => 'RETRY_WITH_VALID_ID',
            ],
        ], 404);
    }
}
