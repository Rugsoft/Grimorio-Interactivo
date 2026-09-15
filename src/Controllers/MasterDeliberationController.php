<?php

/**
 * MasterDeliberationController.php — Endpoints REST de la Torre de
 * Deliberación (SPEC-08, Tarea 3.2).
 *
 * Sirve los Endpoints 5 a 8 del plan 2.2 sobre `MasterDeliberationService`
 * (Tarea 2.4), que es la ÚNICA autoridad de la firma, la retractación y el
 * dictamen, y sobre `ConstitutionalEthicsValidator` (Tarea 2.2), que es la
 * única autoridad del veto del Artículo III:
 *
 *   - GET  /api/v1/moderation/queue                → RF-05.4 (la Torre).
 *   - POST /api/v1/moderation/spells/{id}/sign     → RF-02.1, RF-02.2.
 *   - POST /api/v1/moderation/spells/{id}/retract  → RF-02.4.
 *   - POST /api/v1/moderation/spells/{id}/object   → RF-02.5, RF-02.6.
 *
 * Convención de rechazos (plan 6.2):
 *   - 401 UNAUTHENTICATED        sin vínculo arcano activo (SPEC-03).
 *   - 403 INSUFFICIENT_RANK_TO_JUDGE  la Torre es exclusiva de Maestros.
 *   - 404 SPELL_NOT_FOUND        obra que no consta en la Torre.
 *   - 400/403/409/422            los dicta `ModerationWorkflowException`.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response nativos, cero librerías.
 *   - Artículo III: el controlador NO aritmetiza el veto ético: pregunta a la
 *     autoridad (`evaluationVetoCode()`) y traduce su veredicto. El indicador
 *     viaja ya resuelto porque el cliente no conoce el linaje del Maestro ni
 *     su historia de los últimos treinta días.
 *   - Artículo IV (El Velo Arcano): toda leyenda viaja en noble castellano.
 *   - Artículo V: identificadores en inglés camelCase, documentación en
 *     castellano.
 */

declare(strict_types=1);

namespace Grimorio\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Dto\ModerationQueueItemDto;
use Grimorio\Dto\ObjectionVerdictDto;
use Grimorio\Exceptions\ModerationWorkflowException;
use Grimorio\Exceptions\SpellNotFoundException;
use Grimorio\Models\User;
use Grimorio\Repositories\ObjectionVerdictRepository;
use Grimorio\Repositories\SpellReviewRepository;
use Grimorio\Services\ConstitutionalEthicsValidator;
use Grimorio\Services\MasterDeliberationService;

/**
 * Controlador REST de la Torre de Deliberación de Maestros.
 */
final class MasterDeliberationController
{
    /** Tamaño de página por defecto de la cola. */
    public const DEFAULT_QUEUE_PER_PAGE = 20;

    /** Tope de página: la Torre jamás entrega su cola entera de una vez. */
    public const MAX_QUEUE_PER_PAGE = 100;

    /** Autoridad de la firma, la retractación y el dictamen (RF-02). */
    private MasterDeliberationService $deliberationService;

    /** Lectura de la cola con el retrato de cada obra (RF-05.4). */
    private SpellReviewRepository $reviewRepository;

    /** Autoridad del veto del Artículo III (RF-03.1, RF-03.2). */
    private ConstitutionalEthicsValidator $ethicsValidator;

    /**
     * Memoria de los dictámenes de objeción (RF-06.2).
     *
     * El dictamen que el Maestro acaba de pronunciar se le devuelve ÍNTEGRO:
     * es la prueba de que quedó inscrito, y la misma memoria que el autor leerá
     * en su libreta para subsanar.
     */
    private ObjectionVerdictRepository $verdictRepository;

    public function __construct(
        MasterDeliberationService $deliberationService,
        SpellReviewRepository $reviewRepository,
        ConstitutionalEthicsValidator $ethicsValidator,
        ObjectionVerdictRepository $verdictRepository,
    ) {
        $this->deliberationService = $deliberationService;
        $this->reviewRepository = $reviewRepository;
        $this->ethicsValidator = $ethicsValidator;
        $this->verdictRepository = $verdictRepository;
    }

    // -----------------------------------------------------------------
    // Endpoint 5 — Cola de la Torre de Deliberación (RF-05.4)
    // -----------------------------------------------------------------

    /**
     * GET /api/v1/moderation/queue?element={e}&school={s}&minSignatures={n}&page={p}&perPage={n}
     *
     * Exclusivo de Maestros y del Administrador Supremo (403 para el resto).
     *
     * Respuestas:
     *   - 200 OK: `{canon, items, pagination}`. La cola llega ordenada por
     *     antigüedad ascendente y cada elemento porta el retrato de la obra, su
     *     indicador `N/3` y el veredicto del Artículo III **ya resuelto** para
     *     el Maestro consultante: `hasEthicalConflict` (RF-03.1) y, cuando media
     *     conflicto, `ethicalVeto` con su causa canónica (`ownAuthorship` o
     *     `clanIncompatibility`) y la leyenda ceremonial del veto (RF-03.3).
     *   - 400 INVALID_QUERY_PARAMS: paginación fuera del canon.
     *   - 401 UNAUTHENTICATED.
     *   - 403 INSUFFICIENT_RANK_TO_JUDGE: el rango no alcanza para juzgar.
     *
     * El veredicto se pide fila a fila a `ConstitutionalEthicsValidator` en
     * lugar de re-implementar aquí la ventana de treinta días: el veto tiene
     * UNA autoridad y una segunda aritmética podría discrepar de ella. La cola
     * es acotada, restringida a los Maestros y ordenada de lo más olvidado a lo
     * más reciente, de modo que el coste de preguntar es el precio de no
     * duplicar la ley.
     */
    public function queue(Request $request): Response
    {
        $master = $this->requireAuthenticatedUser($request);
        if ($master === null) {
            return $this->unauthenticatedResponse();
        }

        if (!$this->isCanonicalJudge($master)) {
            return $this->rejection(ModerationWorkflowException::insufficientRankToJudge());
        }

        $page = $this->readPositiveInt($request, 'page', 1);
        $perPage = $this->readPositiveInt($request, 'perPage', self::DEFAULT_QUEUE_PER_PAGE);
        if ($page === null || $perPage === null) {
            return $this->badRequest(
                'INVALID_QUERY_PARAMS',
                'La página y su tamaño deben ser números enteros positivos.',
                'REVIEW_TOWER_FILTERS',
            );
        }

        // El techo de firmas del Cónclave acota el filtro: un umbral por encima
        // de tres no describe ninguna obra posible, y dejarlo pasar hasta el
        // repositorio (que lo rechaza con excepción) convertiría una errata del
        // cliente en una interrupción del santuario.
        $minSignatures = $this->readPositiveInt($request, 'minSignatures', 0);
        if ($minSignatures === null || $minSignatures > MasterDeliberationService::SIGNATURES_REQUIRED) {
            return $this->badRequest(
                'INVALID_QUERY_PARAMS',
                'El filtro de firmas mínimas ha de ser un entero entre cero y el techo de tres firmas del Cónclave.',
                'REVIEW_TOWER_FILTERS',
            );
        }

        $safePerPage = min($perPage, self::MAX_QUEUE_PER_PAGE);
        $filters = [
            'status' => SpellReviewRepository::STATUS_EXPERIMENTAL,
            'limit'  => $safePerPage,
            'offset' => ($page - 1) * $safePerPage,
        ];

        if ($minSignatures > 0) {
            $filters['minSignatures'] = $minSignatures;
        }

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
            $items[] = $this->toTowerItem($queueRow, $master, $instant);
        }

        $totalItems = $this->reviewRepository->countQueueItems($filters);

        return Response::json([
            'success' => true,
            'data'    => [
                // Los umbrales del Cónclave, declarados por la autoridad que
                // los custodia: la Torre no los recalcula (Art. II).
                'canon'      => MasterDeliberationService::deliberationCanon(),
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
    // Endpoint 6 — Estampar Firma de Consagración (RF-02.1, RF-02.2)
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/moderation/spells/{id}/sign — Firma de un Maestro.
     *
     * Entrada: `{ "ceremonialGloss": "…" }`, OPCIONAL y de 250 caracteres como
     * máximo (RF-02.2).
     *
     * Respuestas:
     *   - 200 OK: `{review, consecrated}`. El expediente llega con su contador
     *     RECONTADO desde las firmas vivas, y `consecrated` declara si la
     *     tercera rúbrica consumó la obra (RF-02.3).
     *   - 400 GLOSS_TOO_LONG: la glosa excede el canon de 250.
     *   - 401 UNAUTHENTICATED.
     *   - 403 INSUFFICIENT_RANK_TO_JUDGE, SELF_SIGNING_PROHIBITED o
     *     CONSTITUTIONAL_ETHICS_VETO (RF-03.1, RF-03.2).
     *   - 404 SPELL_NOT_FOUND: la obra no consta en la Torre.
     *   - 409 SPELL_NOT_IN_REVIEW, ALREADY_SIGNED o CLAN_PLURALITY_VIOLATION.
     */
    public function sign(Request $request, array $routeParams): Response
    {
        $master = $this->requireAuthenticatedUser($request);
        if ($master === null) {
            return $this->unauthenticatedResponse();
        }

        $spellId = $this->readRouteValue($routeParams, 'id');
        if ($spellId === '') {
            return $this->notFoundResponse();
        }

        $payload = $request->getJsonBody() ?? [];
        $gloss = $this->readPayloadText($payload, 'ceremonialGloss');

        try {
            $review = $this->deliberationService->signSpell($spellId, $master->getId(), $gloss);
        } catch (ModerationWorkflowException $veto) {
            return $this->rejection($veto);
        } catch (SpellNotFoundException $missing) {
            return $this->missingSpellResponse($missing);
        }

        return Response::json([
            'success' => true,
            'data'    => [
                'review'      => $review,
                'consecrated' => $review->isConsecrated(),
            ],
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoint 7 — Retractar Firma de Maestro (RF-02.4)
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/moderation/spells/{id}/retract — Retractación voluntaria.
     *
     * Entrada: `{ "reason": "…" }`, OPCIONAL: el motivo engrosa la justificación
     * pública del acto, jamás lo condiciona.
     *
     * Respuestas:
     *   - 200 OK: `{review}` con el contador descendido al censo REAL de firmas
     *     vivas (RF-02.4), que libera plaza de hermandad para otro Maestro.
     *   - 400 NO_ACTIVE_SIGNATURE: el Maestro no avalaba esta obra.
     *   - 401 UNAUTHENTICATED.
     *   - 403 INSUFFICIENT_RANK_TO_JUDGE.
     *   - 404 SPELL_NOT_FOUND.
     *   - 409 SIGNATURE_IRREVOCABLE: la obra ya fue consagrada.
     */
    public function retract(Request $request, array $routeParams): Response
    {
        $master = $this->requireAuthenticatedUser($request);
        if ($master === null) {
            return $this->unauthenticatedResponse();
        }

        $spellId = $this->readRouteValue($routeParams, 'id');
        if ($spellId === '') {
            return $this->notFoundResponse();
        }

        $payload = $request->getJsonBody() ?? [];
        $reason = $this->readPayloadText($payload, 'reason');

        try {
            $review = $this->deliberationService->retractSignature($spellId, $master->getId(), $reason);
        } catch (ModerationWorkflowException $veto) {
            return $this->rejection($veto);
        } catch (SpellNotFoundException $missing) {
            return $this->missingSpellResponse($missing);
        }

        return Response::json([
            'success' => true,
            'data'    => [
                'review'             => $review,
                'signaturesCount'    => $review->signaturesCount,
                'signaturesIndicator' => $review->signaturesIndicator(),
            ],
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoint 8 — Emitir Dictamen de Objeción (RF-02.5, RF-02.6)
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/moderation/spells/{id}/object — Veto de calidad.
     *
     * Entrada: `{ "objectionReason": "…" }`, obligatoria y de veinte caracteres
     * como mínimo (RF-02.5).
     *
     * Respuestas:
     *   - 200 OK: `{review, verdict}`. La obra queda `rejected` de inmediato,
     *     retirada del Atrio, con TODOS sus avales previos anulados (RF-02.6) y
     *     el dictamen conservado íntegro para la subsanación del autor.
     *   - 401 UNAUTHENTICATED.
     *   - 403 INSUFFICIENT_RANK_TO_JUDGE, SELF_SIGNING_PROHIBITED o
     *     CONSTITUTIONAL_ETHICS_VETO.
     *   - 404 SPELL_NOT_FOUND.
     *   - 409 SPELL_NOT_IN_REVIEW: la obra no está en deliberación.
     *   - 422 OBJECTION_TOO_BRIEF: la justificación no alcanza el umbral.
     */
    public function object(Request $request, array $routeParams): Response
    {
        $master = $this->requireAuthenticatedUser($request);
        if ($master === null) {
            return $this->unauthenticatedResponse();
        }

        $spellId = $this->readRouteValue($routeParams, 'id');
        if ($spellId === '') {
            return $this->notFoundResponse();
        }

        $payload = $request->getJsonBody() ?? [];
        $reason = $this->readPayloadText($payload, 'objectionReason') ?? '';

        try {
            $review = $this->deliberationService->objectSpell($spellId, $master->getId(), $reason);
        } catch (ModerationWorkflowException $veto) {
            return $this->rejection($veto);
        } catch (SpellNotFoundException $missing) {
            return $this->missingSpellResponse($missing);
        }

        return Response::json([
            'success' => true,
            'data'    => [
                'review'  => $review,
                'verdict' => $this->latestVerdictOf($spellId),
            ],
        ], 200);
    }

    // -----------------------------------------------------------------
    // Ayudantes privados.
    // -----------------------------------------------------------------

    /**
     * Retrata una fila de la cola para la Torre: el expediente canónico, su
     * retrato y el veredicto del Artículo III para el Maestro consultante.
     *
     * @param array<string, mixed> $queueRow Fila de la cola con su retrato.
     *
     * @return array<string, mixed> Contrato del elemento más el veredicto ético.
     */
    private function toTowerItem(array $queueRow, User $master, DateTimeImmutable $instant): array
    {
        $originClanId = isset($queueRow['origin_clan_id']) && $queueRow['origin_clan_id'] !== null
            ? (string) $queueRow['origin_clan_id']
            : null;

        // El veto se PREGUNTA a su autoridad: la propia pluma (RF-03.2) y el
        // linaje actual o reciente (RF-03.1) los dicta el validador compuesto.
        $vetoCode = $this->ethicsValidator->evaluationVetoCode(
            $master->getId(),
            $originClanId,
            (string) ($queueRow['author_id'] ?? ''),
            $instant,
        );

        $item = ModerationQueueItemDto::fromDatabaseRow($queueRow, $vetoCode !== null, $instant);
        $itemPayload = $item->jsonSerialize();
        $itemPayload['ethicalVeto'] = $vetoCode === null
            ? null
            : [
                'code'   => $vetoCode,
                'legend' => ConstitutionalEthicsValidator::CONFLICT_OF_INTEREST_MESSAGE,
            ];

        return $itemPayload;
    }

    /**
     * Último dictamen inscrito sobre la obra, o null si no consta.
     *
     * Se hidrata con la fábrica del propio DTO: el repositorio mide y el DTO
     * retrata, igual que en el resto del santuario.
     */
    private function latestVerdictOf(string $spellId): ?ObjectionVerdictDto
    {
        $verdict = $this->verdictRepository->findLatestVerdictBySpell($spellId);

        return $verdict === null ? null : ObjectionVerdictDto::fromDatabaseRow($verdict);
    }

    /** ¿Ostenta el consultante rango con potestad judicial? (RF-03.5) */
    private function isCanonicalJudge(User $user): bool
    {
        return in_array($user->getRole(), ConstitutionalEthicsValidator::CANONICAL_JUDGE_ROLES, true);
    }

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

    /** Entero positivo de consulta (0 admite el valor neutro), o null si no lo es. */
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

        return $parsed >= 0 ? $parsed : null;
    }

    /**
     * Campo textual de un payload, o null si falta o no es escalar.
     *
     * Un tipo sucio no se coacciona: viaja como ausencia para que el umbral del
     * dominio sea el que dicte el veredicto (RF-02.2, RF-02.5).
     *
     * @param array<string, mixed> $payload
     */
    private function readPayloadText(array $payload, string $key): ?string
    {
        if (!array_key_exists($key, $payload)) {
            return null;
        }

        $value = $payload[$key];
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** Sobre del veredicto canónico del Cónclave (código, leyenda y estado). */
    private function rejection(ModerationWorkflowException $veto): Response
    {
        return Response::json($veto->toPayload(), $veto->httpStatus);
    }

    /** Sobre 404 de la obra que no consta en la Torre. */
    private function missingSpellResponse(SpellNotFoundException $missing): Response
    {
        return Response::json($missing->toPayload(), $missing->getHttpStatusCode());
    }

    /** Sobre 404 ante un identificador ausente en la propia ruta. */
    private function notFoundResponse(): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => SpellNotFoundException::ERROR_CODE,
                'message'        => 'Ese conjuro no existe o no consta en la Torre de Moderación.',
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
                'message'        => 'El vínculo arcano no está activo: conságrate o vincula tu identidad antes de entrar en la Torre de Deliberación.',
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
