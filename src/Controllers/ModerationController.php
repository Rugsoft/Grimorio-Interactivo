<?php

/**
 * ModerationController.php — Endpoints REST del flujo de dos pasos y del
 * Atrio de los Arcanos Experimentales (SPEC-08, Tarea 3.1).
 *
 * Sirve los Endpoints 1 a 4 del plan 2.2 sobre `ModerationWorkflowService`
 * (Tarea 2.3), que es la ÚNICA autoridad del ciclo de vida del conjuro:
 *
 *   - POST /api/v1/moderation/spells/{id}/submit   → RF-01.2 (Paso 1).
 *   - POST /api/v1/moderation/spells/{id}/withdraw → RF-01.3 (retirada).
 *   - POST /api/v1/moderation/spells/{id}/reopen   → RF-01.4 (re-apertura).
 *   - GET  /api/v1/moderation/experimental         → RF-05.1 (el Atrio).
 *
 * Convención de rechazos (plan 6.2):
 *   - 401 UNAUTHENTICATED        sin vínculo arcano activo (SPEC-03).
 *   - 404 SPELL_NOT_FOUND        conjuro inexistente o ajeno al invocante.
 *   - 400 INVALID_QUERY_PARAMS   la paginación no es de enteros positivos.
 *   - 400/403/409                los dicta `ModerationWorkflowException`, que
 *                                porta el código canónico y el estado de cada
 *                                veredicto (rango, convalecencia, cupo, estado).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response nativos, cero librerías.
 *   - Artículo II: la huella y el maná publicados los sella el backend.
 *   - Artículo III: el controlador no decide ética ni cupo: traduce el veredicto.
 *   - Artículo IV (El Velo Arcano): toda leyenda viaja en noble castellano.
 *   - Artículo V: identificadores en inglés camelCase, documentación en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Dto\ModerationQueueItemDto;
use Grimorio\Exceptions\ModerationWorkflowException;
use Grimorio\Exceptions\SpellNotFoundException;
use Grimorio\Models\User;
use Grimorio\Repositories\SpellReviewRepository;
use Grimorio\Services\ModerationWorkflowService;

/**
 * Controlador REST del ciclo de vida del conjuro y del Atrio de Pruebas.
 */
final class ModerationController
{
    /**
     * Leyenda ceremonial de advertencia del Atrio (RF-05.1, RNF-03).
     *
     * Es el marco rúnico que distingue la obra en deliberación del Gran Tomo
     * Canónico, y vive aquí como fuente única: el frontend la exhibe, jamás
     * la reescribe.
     */
    public const HALL_WARNING_LEGEND = 'En Deliberación Arcana — Obra en Fase de Prueba';

    /** Código canónico de la insignia de advertencia (contrato JSON). */
    public const HALL_WARNING_CODE = 'UNDER_ARCANE_DELIBERATION';

    /** Tamaño de página por defecto del catálogo público del Atrio. */
    public const DEFAULT_HALL_PER_PAGE = 12;

    /** Tope de página: un cliente jamás se lleva el Atrio entero de una vez. */
    public const MAX_HALL_PER_PAGE = 50;

    /** Autoridad del ciclo de vida, del cupo y del letargo (RF-01). */
    private ModerationWorkflowService $workflowService;

    /** Lectura de la cola del Atrio con el retrato de cada obra (RF-05.1). */
    private SpellReviewRepository $reviewRepository;

    public function __construct(
        ModerationWorkflowService $workflowService,
        SpellReviewRepository $reviewRepository,
    ) {
        $this->workflowService = $workflowService;
        $this->reviewRepository = $reviewRepository;
    }

    // -----------------------------------------------------------------
    // Endpoint 1 — Enviar Conjuro a Moderación (Paso 1, RF-01.2)
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/moderation/spells/{id}/submit — Elevación a la Torre.
     *
     * Respuestas:
     *   - 200 OK: la obra transiciona de `draft` a `experimental` con la huella
     *     matemática sellada, el contador de firmas en `0/3` y el cupo
     *     restante del autor, para que la interfaz refresque su medidor.
     *   - 400 SPELL_NOT_IN_DRAFT: la obra no descansa en la libreta.
     *   - 401 UNAUTHENTICATED: sin vínculo arcano activo.
     *   - 403 INSUFFICIENT_RANK o CONVALESCENCE_ACTIVE (RF-01.2).
     *   - 404 SPELL_NOT_FOUND: conjuro inexistente o ajeno.
     *   - 409 TOWER_CAPACITY_EXCEEDED: el autor ya custodia tres obras en
     *     deliberación (RF-01.2, RNF-04).
     */
    public function submit(Request $request, array $routeParams): Response
    {
        $author = $this->requireAuthenticatedUser($request);
        if ($author === null) {
            return $this->unauthenticatedResponse();
        }

        $spellId = $this->readRouteValue($routeParams, 'id');
        if ($spellId === '') {
            return $this->notFoundResponse();
        }

        try {
            $review = $this->workflowService->submitToModeration($spellId, $author->getId());
        } catch (ModerationWorkflowException $veto) {
            return $this->rejection($veto);
        } catch (SpellNotFoundException $missingOrForeign) {
            return $this->missingSpellResponse($missingOrForeign);
        }

        return Response::json([
            'success' => true,
            'data'    => [
                'review'            => $review,
                'remainingCapacity' => $this->workflowService->remainingCapacity($author->getId()),
            ],
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoint 2 — Retirar Conjuro a Borrador Privado (RF-01.3)
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/moderation/spells/{id}/withdraw — Retirada a la libreta.
     *
     * Respuestas:
     *   - 200 OK: la obra vuelve a `draft` y TODOS sus avales previos quedan
     *     revocados con el motivo `author_withdrawn` y el contador en `0/3`.
     *   - 401 UNAUTHENTICATED.
     *   - 404 SPELL_NOT_FOUND: conjuro inexistente o ajeno.
     *   - 409 SPELL_NOT_UNDER_REVIEW: la obra ya está consagrada o desterrada:
     *     la consagración es irrevocable.
     *
     * **La obra ajena responde 404, no 403.** El plan 2.2 anotaba 403 para la
     * retirada ajena, pero el servicio de la Fase 2 —ya verificado— distingue
     * una sola cosa: la obra no habita el grimorio del invocante
     * (`SpellNotFoundException`, 404). Se respeta el veredicto del dominio en
     * lugar de re-implementar aquí una titularidad paralela, y el 404 no delata
     * si la obra ajena existe (criterio de SPEC-04 y de la inviolabilidad de la
     * libreta privada, spec §7.5).
     */
    public function withdraw(Request $request, array $routeParams): Response
    {
        $author = $this->requireAuthenticatedUser($request);
        if ($author === null) {
            return $this->unauthenticatedResponse();
        }

        $spellId = $this->readRouteValue($routeParams, 'id');
        if ($spellId === '') {
            return $this->notFoundResponse();
        }

        try {
            $review = $this->workflowService->withdrawToDraft($spellId, $author->getId());
        } catch (ModerationWorkflowException $veto) {
            return $this->rejection($veto);
        } catch (SpellNotFoundException $missingOrForeign) {
            return $this->missingSpellResponse($missingOrForeign);
        }

        return Response::json([
            'success' => true,
            'data'    => [
                'review'            => $review,
                'remainingCapacity' => $this->workflowService->remainingCapacity($author->getId()),
            ],
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoint 3 — Reabrir Conjuro Rechazado (RF-01.4)
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/moderation/spells/{id}/reopen — «Reabrir como Borrador».
     *
     * Respuestas:
     *   - 200 OK: la obra vetada vuelve a `draft` con la edición habilitada. El
     *     dictamen anterior NO se borra: la libreta del autor lo conserva a la
     *     vista para su subsanación (RF-06.2), y viaja en `lastVerdict`
     *     —`null` si la obra nunca recibió dictamen, cosa imposible en el
     *     estado `rejected`—.
     *   - 400 SPELL_NOT_REJECTED: la obra no está vetada.
     *   - 401 UNAUTHENTICATED.
     *   - 404 SPELL_NOT_FOUND: conjuro inexistente o ajeno.
     */
    public function reopen(Request $request, array $routeParams): Response
    {
        $author = $this->requireAuthenticatedUser($request);
        if ($author === null) {
            return $this->unauthenticatedResponse();
        }

        $spellId = $this->readRouteValue($routeParams, 'id');
        if ($spellId === '') {
            return $this->notFoundResponse();
        }

        try {
            $review = $this->workflowService->reopenAsDraft($spellId, $author->getId());
        } catch (ModerationWorkflowException $veto) {
            return $this->rejection($veto);
        } catch (SpellNotFoundException $missingOrForeign) {
            return $this->missingSpellResponse($missingOrForeign);
        }

        return Response::json([
            'success' => true,
            'data'    => [
                'review'            => $review,
                'lastVerdict'       => $this->workflowService->objectionArchiveFor($spellId),
                'remainingCapacity' => $this->workflowService->remainingCapacity($author->getId()),
            ],
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoint 4 — Catálogo del Atrio de Pruebas (RF-05.1, RF-05.3)
    // -----------------------------------------------------------------

    /**
     * GET /api/v1/moderation/experimental?element={element}&school={school}
     *
     * Respuestas:
     *   - 200 OK: página de obras en deliberación, ordenadas por antigüedad
     *     ascendente (la más olvidada primero, ordenación determinista del
     *     repositorio), cada una con su autor, su linaje patrimonial, su
     *     indicador de firmas (`0/3`, `1/3`, `2/3`) y la insignia de
     *     advertencia litúrgica del Atrio.
     *   - 400 INVALID_QUERY_PARAMS: página o tamaño fuera de los enteros positivos.
     *
     * La ruta es de lectura pública (RF-05.1: cualquier visitante contempla el
     * Atrio); `draft` y `rejected` JAMÁS aparecen porque el estado de la
     * consulta es siempre `experimental`, no un parámetro del cliente.
     *
     * Los filtros de afinidad y escuela son una LENTE, no un contrato: un valor
     * ajeno al Códice Elemental devuelve una página vacía en lugar de un error
     * (mismo criterio que `DominionController::readComboElement()`: lo
     * desconocido simplemente no casa, y una cola que devuelve de menos se
     * delata sola con su censo en cero).
     *
     * **El veto ético no se calcula aquí.** `hasEthicalConflict` es un
     * indicador de la Torre —quien consulta es un Maestro con linaje e
     * historia—; el Atrio es público y un visitante anónimo no milita en
     * hermandad alguna, así que el indicador viaja en `false` (Art. III: la
     * memoria de clanes jamás se filtra al navegador).
     */
    public function experimental(Request $request): Response
    {
        $page = $this->readPositiveInt($request, 'page', 1);
        $perPage = $this->readPositiveInt($request, 'perPage', self::DEFAULT_HALL_PER_PAGE);
        if ($page === null || $perPage === null) {
            return $this->badRequest(
                'INVALID_QUERY_PARAMS',
                'La página y su tamaño deben ser números enteros positivos.',
                'REVIEW_HALL_FILTERS',
            );
        }

        $safePerPage = min($perPage, self::MAX_HALL_PER_PAGE);
        $offset = ($page - 1) * $safePerPage;

        $filters = [
            'status' => SpellReviewRepository::STATUS_EXPERIMENTAL,
            'limit'  => $safePerPage,
            'offset' => $offset,
        ];

        $element = $this->readQueryText($request, 'element');
        if ($element !== null) {
            $filters['elementalAffinity'] = $element;
        }

        $school = $this->readQueryText($request, 'school');
        if ($school !== null) {
            $filters['magicSchool'] = $school;
        }

        $instant = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $items = [];
        foreach ($this->reviewRepository->findQueueItems($filters) as $queueRow) {
            $items[] = ModerationQueueItemDto::fromDatabaseRow($queueRow, false, $instant);
        }

        $totalItems = $this->reviewRepository->countQueueItems($filters);

        return Response::json([
            'success' => true,
            'data'    => [
                // La insignia es del ATRIO, no de cada tarjeta: el marco rúnico
                // es idéntico en todas las obras y una copia por elemento sería
                // una mentira a punto de divergir.
                'hallWarning' => [
                    'code'         => self::HALL_WARNING_CODE,
                    'legend'       => self::HALL_WARNING_LEGEND,
                    'article'      => 'RF-05.3',
                    // Ninguna obra en deliberación devenga gloria: los PDA se
                    // reservan al instante de la consagración (RF-05.3).
                    'pointsBlocked' => true,
                ],
                'items'      => $items,
                'pagination' => [
                    'page'       => $page,
                    'limit'      => $safePerPage,
                    'totalItems' => $totalItems,
                    'totalPages' => max(1, (int) ceil($totalItems / $safePerPage)),
                ],
            ],
        ], 200);
    }

    // -----------------------------------------------------------------
    // Ayudantes privados.
    // -----------------------------------------------------------------

    /** Titular consagrado de la petición, o null si no hay vínculo activo. */
    private function requireAuthenticatedUser(Request $request): ?User
    {
        $user = $request->getUser();

        return $user === null || $user->getId() === '' ? null : $user;
    }

    /** Valor de un parámetro de ruta, saneado y recortado. */
    private function readRouteValue(array $routeParams, string $key): string
    {
        $value = $routeParams[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** Parámetro textual de consulta, o null si viene vacío. */
    private function readQueryText(Request $request, string $name): ?string
    {
        $value = $request->getQueryParam($name);
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /** Entero positivo de consulta, o null si no lo es. */
    private function readPositiveInt(Request $request, string $name, int $defaultValue): ?int
    {
        $value = $request->getQueryParam($name);
        if ($value === null || trim($value) === '') {
            return $defaultValue;
        }

        if (preg_match('/^\d+$/', trim($value)) !== 1) {
            return null;
        }

        $parsed = (int) trim($value);

        return $parsed >= 1 ? $parsed : null;
    }

    /** Sobre del veredicto canónico del flujo (código, leyenda y estado HTTP). */
    private function rejection(ModerationWorkflowException $veto): Response
    {
        return Response::json($veto->toPayload(), $veto->httpStatus);
    }

    /** Sobre 404 del conjuro inexistente o ajeno (contrato de SPEC-04). */
    private function missingSpellResponse(SpellNotFoundException $missingOrForeign): Response
    {
        return Response::json($missingOrForeign->toPayload(), $missingOrForeign->getHttpStatusCode());
    }

    /** Sobre 404 ante un identificador ausente en la propia ruta. */
    private function notFoundResponse(): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => SpellNotFoundException::ERROR_CODE,
                'message'        => 'Ese conjuro no existe o no habita tu grimorio.',
                'recoveryAction' => 'RETRY_WITH_VALID_ID',
            ],
        ], SpellNotFoundException::HTTP_STATUS_CODE);
    }

    /** 401: sin vínculo arcano activo (contrato SPEC-03). */
    private function unauthenticatedResponse(): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => 'UNAUTHENTICATED',
                'message'        => 'El vínculo arcano no está activo: conságrate o vincula tu identidad antes de elevar obras a la Torre.',
                'recoveryAction' => 'BIND_OR_CONSECRATE',
            ],
        ], 401);
    }

    /** Sobre 400 para los filtros de consulta fuera del contrato. */
    private function badRequest(string $errorCode, string $message, string $recoveryAction): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => $errorCode,
                'message'        => $message,
                'recoveryAction' => $recoveryAction,
            ],
        ], 400);
    }
}
