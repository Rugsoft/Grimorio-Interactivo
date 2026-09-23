<?php

/**
 * GrimoireController.php — Endpoints REST del Simulador de Grimorio.
 *
 * Tarea 1.3 (TASKS-05): sirve el catálogo de páginas del Tomo Arcano
 * (SPEC-05, RF-01) sobre GrimoireQueryService (Tarea 1.2).
 *
 * Contrato (plan 2.1, ampliado por SPEC-11 Tarea 3.3):
 *   - GET /api/v1/grimoire/spells          → 200 (tomo canónico, ensayos
 *     o COLECCIÓN). Parámetros query: circle (1-5), element, mode
 *     (canonical|essays|collection), page (≥1), limit (1-50).
 *     mode=essays exige sesión; anónimo → 401. mode=collection exige
 *     sesión (401) y LINAJE jurado (403 LINEAGE_OATH_REQUIRED, SPEC-09:
 *     el linaje manda, no el rol); entrega el tomo personal paginado
 *     de 50 (CollectionPageDto) y enriquece canonical/essays con el
 *     `adeptState` del adepto autenticado (RF-04.0).
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
use Grimorio\Exceptions\LineageOathException;
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
     * mode=collection (SPEC-11, RF-02.1): el tomo personal del adepto
     *   autenticado con linaje jurado, paginado de 50, con marca
     *   solemne y estado del homenaje por entrada (plan §2.2).
     *
     * Enriquecimiento embebido (RF-04.0): con sesión viva, los listados
     * canonical y essays portan el `adeptState` (collected/praised) de
     * cada página; anónimo recibe el listado SIN la clave.
     *
     * Respuestas: 200 OK (sobre de paginación), 400 Bad Request
     * (parámetros fuera del canon), 401 Unauthorized (essays/collection
     * sin sesión) y 403 LINEAGE_OATH_REQUIRED (collection sin juramento,
     * hallazgo 16: el linaje manda, no el rol).
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

        // --- Segmentación por modo y sesión (RF-01.2 / RF-01.4 / RF-02.1) ---
        $mode = $request->getQueryParam('mode') ?? 'canonical';

        if ($mode === 'collection') {
            // La tercera vía (SPEC-11): el tomo íntimo del adepto. La
            // hoja es de 50 fija (CollectionPageDto::PAGE_LIMIT), el
            // `limit` de canonical no aplica aquí.
            $adepto = $request->getUser();
            if ($adepto === null || $adepto->getId() === '') {
                return $this->unauthenticatedResponse();
            }
            if ($adepto->getLineage() === null) {
                return $this->oathRejection();
            }

            return Response::json([
                'success' => true,
                'data'    => $this->queryService->getCollection($adepto, $element, $page),
            ], 200);
        }

        if ($mode === 'essays') {
            $author = $request->getUser();
            if ($author === null || $author->getId() === '') {
                return $this->unauthenticatedResponse();
            }
            $pageData = $this->queryService->getAuthorEssays($author, $circle, $element, $page, $limit);
        } else {
            // Cualquier mode distinto de essays/collection degrada al
            // tomo canónico público (jamás filtra borradores por accidente).
            $pageData = $this->queryService->getCanonicalSpells($circle, $element, $page, $limit);
        }

        // Enriquecimiento embebido del adepto (RF-04.0, hallazgo 5): con
        // sesión viva, cada página porta su collected/praised real sin
        // peticiones extra; anónimo recibe el listado SIN la clave.
        $reader = $request->getUser();
        if ($reader !== null && $reader->getId() !== '') {
            $pageData['spells'] = $this->queryService->embedAdeptState($reader, $pageData['spells']);
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
     * Sobre 403 del peregrino sin juramento (SPEC-09 retención, hallazgo 16):
     * el linaje manda, no el rol — una sola voz para todos los roles.
     */
    private function oathRejection(): Response
    {
        $oath = LineageOathException::lineageOathRequired(
            'El santuario aguarda tu juramento: nadie pisa sus salas sin linaje jurado.'
        );

        return Response::json([
            'success' => false,
            'error'   => [
                'code'    => $oath->errorCode,
                'message' => $oath->getMessage(),
            ],
        ], $oath->httpStatus);
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
