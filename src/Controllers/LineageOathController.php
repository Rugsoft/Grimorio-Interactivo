<?php

/**
 * LineageOathController.php — Los endpoints REST del Juramento de Linaje
 * (SPEC-09, Tarea 2.6).
 *
 * Cubre: RF-02.1 (canon ceremonial), RF-03.1/03.2 (sellado con veredicto y
 * fallo solemne), RF-03.3 (idempotencia vía el servicio), RF-05.1 (la
 * retención ya denegó lo demás: este controlador solo existe para las
 * rutas permitidas) y RF-05.3 (la ruta retenida vive en la sesión).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response nativos, PDO vía los
 *     servicios; cero dependencias.
 *   - Artículo IV: las leyendas de fallo viajan en noble castellano, sin
 *     trazas internas jamás (AGENTS.md 6.1).
 *   - Artículo V: métodos en inglés camelCase; códigos técnicos en inglés.
 *
 * Contratos (plan §2.2):
 *   GET  /api/v1/lineage/oath-catalog  → 200 { accountState, lineages[8] }.
 *   POST /api/v1/lineage/oath          → 200 sellado/idempotente, 400/401/403.
 *   POST /api/v1/lineage/retained-route → 204 (saneada) | 401 sin sesión.
 *
 * La cadena completa de la ruta es AuthMiddleware → RbacMiddleware →
 * LineageOathMiddleware → este controlador: cuando aquí llega una
 * petición, el llamador ya está autenticado y la retención de sustancia
 * ya concedió el paso.
 */

declare(strict_types=1);

namespace Grimorio\Controllers;

use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Core\SessionManager;
use Grimorio\Exceptions\LineageOathException;
use Grimorio\Middleware\LineageOathMiddleware;
use Grimorio\Repositories\LineageOathRepository;
use Grimorio\Services\LineageCatalogService;
use Grimorio\Services\LineageOathService;

/**
 * El portal de la ceremonia: canon, juramento y retorno.
 */
final class LineageOathController
{
    /** El canon inmutable de las ocho fichas heráldicas. */
    private LineageCatalogService $catalogService;

    /** El oficiante del juramento (validación, serialización, Bitácora). */
    private LineageOathService $oathService;

    /**
     * El gestor de sesiones: la ruta retenida vive en la fila del vínculo
     * (enmienda de la Tarea 9.2 de SPEC-11). Sin él, la retención es
     * inocua — la ceremonia sigue respondiendo con `retainedRoute: null`.
     */
    private ?SessionManager $sessionManager;

    public function __construct(
        LineageCatalogService $catalogService,
        LineageOathService $oathService,
        ?SessionManager $sessionManager = null
    ) {
        $this->catalogService = $catalogService;
        $this->oathService = $oathService;
        $this->sessionManager = $sessionManager;
    }

    // -----------------------------------------------------------------
    // Endpoint 1: GET /api/v1/lineage/oath-catalog (plan §2.2).
    // -----------------------------------------------------------------

    /**
     * Sirve el canon ceremonial completo con el estado de la cuenta.
     */
    public function oathCatalog(Request $request): Response
    {
        $user = $request->getUser();
        if ($user === null || $user->getId() === '') {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'           => 'SESSION_EXPIRED',
                    'message'        => 'Tu vínculo con el santuario ha expirado: vuelve a consagrarte para entrar.',
                    'recoveryAction' => 'REAUTHENTICATE',
                ],
            ], 401);
        }

        return Response::json([
            'success' => true,
            'data'    => $this->catalogService->getOathCatalog($user->getId()),
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoint 2: POST /api/v1/lineage/oath (plan §2.2).
    // -----------------------------------------------------------------

    /**
     * Sella el juramento: 200 (sellado o idempotencia), 400 `INVALID_
     * LINEAGE`, 401 `SESSION_EXPIRED`, 403 `OATH_FORBIDDEN_ROLE` o
     * `LINEAGE_OATH_CONFLICT`. La ruta retenida de la sesión viaja en el
     * veredicto y se consume (RF-03.1).
     */
    public function sealOath(Request $request): Response
    {
        $user = $request->getUser();
        if ($user === null || $user->getId() === '') {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'           => 'SESSION_EXPIRED',
                    'message'        => 'Tu vínculo con el santuario ha expirado: vuelve a consagrarte para entrar.',
                    'recoveryAction' => 'REAUTHENTICATE',
                ],
            ], 401);
        }

        $payload = $request->getJsonBody();
        $lineageId = is_array($payload) ? ($payload['lineageId'] ?? null) : null;

        try {
            $verdict = $this->oathService->sealOath(
                userId: $user->getId(),
                lineageId: $lineageId,
                actorRole: $user->getRole(),
                actorAlias: $user->getAlias(),
            );
        } catch (LineageOathException $solemnRejection) {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'           => $solemnRejection->errorCode,
                    'message'        => $solemnRejection->getMessage(),
                    'recoveryAction' => $solemnRejection->errorCode === LineageOathException::INVALID_LINEAGE
                        ? 'CHOOSE_CANONICAL_LINEAGE'
                        : 'RETURN_TO_PORTAL',
                ],
            ], $solemnRejection->httpStatus);
        }

        // RF-03.1: la ruta que la retención guardó en el VÍNCULO conduce el
        // retorno. Se consume aquí: una sola ceremonia, un solo retorno.
        // (Persistencia en `user_sessions.retained_route`: enmienda de la
        // Tarea 9.2 de SPEC-11 — antes vivía en `$_SESSION`, que moría con
        // cada petición porque el santuario no usa sesiones nativas.)
        $sessionId = $request->getActiveSessionId();
        $retainedRoute = $sessionId !== null ? $this->sessionManager?->pullRetainedRoute($sessionId) : null;

        return Response::json([
            'success' => true,
            'data'    => [
                'lineage'       => $verdict->lineage,
                'sealedNow'     => $verdict->sealedNow,
                'retainedRoute' => $retainedRoute,
            ],
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoint 3: POST /api/v1/lineage/retained-route (plan §2.2).
    // -----------------------------------------------------------------

    /**
     * Registra la intención de ruta del interceptor frontend: 204 con la
     * ruta saneada (las externas se descartan en silencio), 401 sin sesión.
     */
    public function retainRoute(Request $request): Response
    {
        $user = $request->getUser();
        if ($user === null || $user->getId() === '') {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'           => 'SESSION_EXPIRED',
                    'message'        => 'Tu vínculo con el santuario ha expirado: vuelve a consagrarte para entrar.',
                    'recoveryAction' => 'REAUTHENTICATE',
                ],
            ], 401);
        }

        $payload = $request->getJsonBody();
        $requestedRoute = is_array($payload) ? (string) ($payload['route'] ?? '') : '';

        // Saneamiento ÚNICO, compartido con la guardia (RF-05.3): solo
        // hashes internos del mapa canónico; lo demás, silencio. La ruta se
        // persiste en el vínculo del solicitante (enmienda de la Tarea 9.2).
        $retained = LineageOathMiddleware::sanitizeRetainableRoute($requestedRoute);
        $sessionId = $request->getActiveSessionId();
        if ($retained !== null && $sessionId !== null) {
            $this->sessionManager?->retainRoute($sessionId, $retained);
        }

        // 204 No Content: retenida o descartada, sin cuerpo que negociar.
        return Response::json(null, 204);
    }
}
