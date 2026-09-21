<?php

/**
 * VestibuleController.php — Endpoints REST del Vestíbulo de las Hermandades
 * (SPEC-10, Tarea 3.4).
 *
 * Sirve los tres endpoints nuevos del plan §2.2 sobre ClanVestibuleService
 * (Tarea 3.2) y ClanService (retirada, Tarea 2.4):
 *
 *   - GET  /api/v1/clans/vestibule                              → show()
 *   - POST /api/v1/clans/{id}/applications/{appId}/withdraw     → withdraw()
 *   - POST /api/v1/clans/applications/{appId}/verdict-acknowledge → acknowledgeVerdict()
 *   - GET  /api/v1/clans/verdicts/unread-count                  → unreadCount()
 *
 * Todas las rutas corren tras la cadena vigente del front controller
 * (AuthMiddleware → LineageOathMiddleware): el peregrino sin linaje jamás
 * alcanza el controlador — la retención de SPEC-09 precede (RF-04.3).
 *
 * Convención de rechazos (misma voz que ClanController):
 *   - 401 UNAUTHENTICATED  sin vínculo arcano activo (SPEC-03).
 *   - 400/403/404/409      los dicta ClanGovernanceException / LineageOathException,
 *                          que portan el código canónico y el estado de cada veto.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response nativos, cero librerías.
 *   - Artículo III: el controlador NO decide ética ni cupo: traduce el
 *     veredicto del servicio.
 *   - Artículo IV (El Velo Arcano): toda leyenda viaja en noble castellano.
 *   - Artículo V: identificadores en inglés camelCase, documentación en
 *     castellano.
 */

declare(strict_types=1);

namespace Grimorio\Controllers;

use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Exceptions\ClanGovernanceException;
use Grimorio\Exceptions\LineageOathException;
use Grimorio\Models\User;
use Grimorio\Services\ClanService;
use Grimorio\Services\ClanVestibuleService;

/**
 * Controlador REST del Vestíbulo: estado, retirada, veredicto y rótulo.
 */
final class VestibuleController
{
    /** El sobre único del Vestíbulo (Tarea 3.2). */
    private ClanVestibuleService $vestibuleService;

    /** Autoridad de la retirada de peticiones (Tarea 2.4). */
    private ClanService $clanService;

    public function __construct(ClanVestibuleService $vestibuleService, ClanService $clanService)
    {
        $this->vestibuleService = $vestibuleService;
        $this->clanService = $clanService;
    }

    // -----------------------------------------------------------------
    // Endpoint 1 — Estado del Vestíbulo (RF-01.2, RF-01.7, RNF-04)
    // -----------------------------------------------------------------

    /**
     * GET /api/v1/clans/vestibule
     *
     * Una sola carga (RNF-04) con aptitud, catálogo del linaje jurado,
     * casa legada divergente e inventario de peticiones. Sin parámetro de
     * filtro: el linaje se deriva de la sesión (RF-01.2).
     *
     * Respuestas: 200 OK con el sobre completo; 401 UNAUTHENTICATED;
     * 403 ADMIN_LINEAGE_REQUIRED (Supremo sin linaje, hallazgos 13/19).
     */
    public function show(Request $request): Response
    {
        $viewer = $this->requireAuthenticatedUser($request);
        if ($viewer === null) {
            return $this->unauthenticatedResponse();
        }

        try {
            $state = $this->vestibuleService->vestibuleStateFor($viewer);
        } catch (ClanGovernanceException $veto) {
            return $this->rejection($veto);
        } catch (LineageOathException $oath) {
            return $this->oathRejection($oath);
        }

        return Response::json([
            'success' => true,
            'data'    => $state,
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoint 3 — Retirada del postulante (RF-03.3)
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/clans/{id}/applications/{appId}/withdraw
     *
     * Retira una petición PENDIENTE propia. La fila persiste con estado
     * `cancelled`: la casa queda clausurada para la cuenta (RF-03.1) y el
     * cupo de pendientes se libera. Serializada frente al dictamen (caso
     * límite 5).
     *
     * Respuestas: 200 OK con la petición retirada; 401 UNAUTHENTICATED;
     * 404 APPLICATION_NOT_FOUND (ajena o inexistente); 409
     * APPLICATION_ALREADY_RESOLVED (carrera con el dictamen).
     */
    public function withdraw(Request $request, array $routeParams): Response
    {
        $postulant = $this->requireAuthenticatedUser($request);
        if ($postulant === null) {
            return $this->unauthenticatedResponse();
        }

        try {
            $withdrawn = $this->clanService->withdrawApplication(
                $postulant,
                $this->readRouteValue($routeParams, 'id'),
                $this->readRouteValue($routeParams, 'appId'),
            );
        } catch (ClanGovernanceException $veto) {
            return $this->rejection($veto);
        }

        return Response::json([
            'success' => true,
            'data'    => $withdrawn,
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoint 4 — Veredicto contemplado (RF-03.4)
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/clans/applications/{appId}/verdict-acknowledge
     *
     * Contempla el veredicto TERMINAL propio: fija `verdict_seen_at` y
     * apaga el rótulo «Tienes dictámenes a la espera» (RF-01.1).
     * Idempotente: el reenvío responde 200 sin mutación.
     *
     * Respuestas: 200 OK con la petición contemplada; 401 UNAUTHENTICATED;
     * 404 APPLICATION_NOT_FOUND (ajena o inexistente); 409
     * APPLICATION_ALREADY_PENDING (nada hay que leer en una espera).
     */
    public function acknowledgeVerdict(Request $request, array $routeParams): Response
    {
        $postulant = $this->requireAuthenticatedUser($request);
        if ($postulant === null) {
            return $this->unauthenticatedResponse();
        }

        try {
            $acknowledged = $this->clanService->acknowledgeVerdict(
                $postulant,
                $this->readRouteValue($routeParams, 'appId'),
            );
        } catch (ClanGovernanceException $veto) {
            return $this->rejection($veto);
        }

        return Response::json([
            'success' => true,
            'data'    => $acknowledged,
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoint 5 — Contador del rótulo de navegación (RF-01.1)
    // -----------------------------------------------------------------

    /**
     * GET /api/v1/clans/verdicts/unread-count
     *
     * Consulta ligera (RNF-04) para el distintivo del acceso al Vestíbulo:
     * «Tienes dictámenes a la espera».
     *
     * Respuestas: 200 OK con `{ unreadVerdictsCount: N }`; 401 UNAUTHENTICATED.
     */
    public function unreadCount(Request $request): Response
    {
        $viewer = $this->requireAuthenticatedUser($request);
        if ($viewer === null) {
            return $this->unauthenticatedResponse();
        }

        try {
            $unreadVerdictsCount = $this->vestibuleService->unreadVerdictsCountFor($viewer);
        } catch (LineageOathException $oath) {
            return $this->oathRejection($oath);
        }

        return Response::json([
            'success' => true,
            'data'    => [
                'unreadVerdictsCount' => $unreadVerdictsCount,
            ],
        ], 200);
    }

    // -----------------------------------------------------------------
    // Ayudantes privados (misma voz que ClanController)
    // -----------------------------------------------------------------

    /** Usuario autenticado de la petición, o null si el vínculo está inactivo. */
    private function requireAuthenticatedUser(Request $request): ?User
    {
        $user = $request->getUser();

        return $user === null || $user->getId() === '' ? null : $user;
    }

    /** Valor textual de un parámetro de ruta, o cadena vacía si falta. */
    private function readRouteValue(array $routeParams, string $key): string
    {
        $value = $routeParams[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /** Traduce el veto del gobierno de hermandades al contrato REST. */
    private function rejection(ClanGovernanceException $veto): Response
    {
        return Response::json($veto->toPayload(), $veto->httpStatus);
    }

    /** Traduce la retención del peregrino (defensa en profundidad, RF-04.3). */
    private function oathRejection(LineageOathException $oath): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'    => $oath->errorCode,
                'message' => $oath->getMessage(),
            ],
        ], $oath->httpStatus);
    }

    /** 401: sin vínculo arcano activo (contrato SPEC-03). */
    private function unauthenticatedResponse(): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => 'UNAUTHENTICATED',
                'message'        => 'El vínculo arcano no está activo: conságrate o vincula tu identidad antes de pisar el Vestíbulo.',
                'recoveryAction' => 'BIND_OR_CONSECRATE',
            ],
        ], 401);
    }
}
