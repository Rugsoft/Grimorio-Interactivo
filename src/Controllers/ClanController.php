<?php

/**
 * ClanController.php — Endpoints REST del gobierno de hermandades.
 *
 * Tarea 3.2 (TASKS-07): sirve los nueve endpoints del plan 2.2 sobre
 * ClanService (Tarea 2.4), que es la ÚNICA autoridad de las reglas del canon:
 * fundación (1), catálogo (2), ficha detallada (3), actualización heráldica
 * (4), postulación (5), deliberación (6), renuncia (7), expulsión (8) y
 * traspaso de la corona (9). Se conserva el Endpoint público de SPEC-01
 * (`/clans/preview`), que ya servía este controlador.
 *
 * Convención de rechazos (plan 6.2: «payloads maliciosos o incompletos»):
 *   - 400 INVALID_REQUEST_BODY   el cuerpo no es un objeto JSON válido.
 *   - 400 INVALID_QUERY_PARAMS   la paginación no es de enteros positivos.
 *   - 400 INVALID_CATALOG_FILTER estado ajeno a `active`/`archived`.
 *   - 422 INVALID_CLAN_PAYLOAD   JSON válido pero incompleto o de tipos sucios.
 *   - 401 UNAUTHENTICATED        sin vínculo arcano activo (SPEC-03).
 *   - 400/403/404/409            los dicta ClanGovernanceException, que porta
 *                                el código canónico y el estado de cada veto.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response nativos, cero librerías.
 *   - Artículo III: el controlador NO decide ética ni cupo: traduce el
 *     veredicto del servicio; los rechazos son estrictos e irreversibles.
 *   - Artículo IV (El Velo Arcano): toda leyenda viaja en noble castellano.
 *   - Artículo V: identificadores en inglés camelCase, documentación en
 *     castellano.
 */

declare(strict_types=1);

namespace Grimorio\Controllers;

use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Database\Connection;
use Grimorio\Dto\ClanDto;
use Grimorio\Exceptions\ClanGovernanceException;
use Grimorio\Models\User;
use Grimorio\Services\ClanService;
use Grimorio\Services\SpellDiscoveryService;

/**
 * Controlador REST de clanes, gobernanza y postulaciones.
 */
final class ClanController
{
    /** Conexión del santuario: sirve el catálogo público de SPEC-01. */
    private Connection $connection;

    /** Autoridad del gobierno de hermandades (RF-01). */
    private ClanService $clanService;

    /**
     * Descubrimiento arcano del catálogo: sirve el legado sellado de las
     * hermandades (Endpoint 13, RF-05.1/RF-05.3).
     */
    private SpellDiscoveryService $spellDiscoveryService;

    public function __construct(
        Connection $connection,
        ClanService $clanService,
        SpellDiscoveryService $spellDiscoveryService,
    ) {
        $this->connection = $connection;
        $this->clanService = $clanService;
        $this->spellDiscoveryService = $spellDiscoveryService;
    }

    /**
     * GET /api/v1/clans/preview — linajes con su Dominio semanal, solo lectura.
     *
     * Endpoint público de SPEC-01 (RF-02.2), servido desde el contador semanal
     * canónico de SPEC-07. La clave pública `domainPoints` conserva su nombre
     * de contrato: un solo contador de gloria por concepto.
     */
    public function preview(Request $request): Response
    {
        // El parámetro $request queda reservado para futuras cabeceras.
        $pdo = $this->connection->getPdo();

        // Consulta preparada: sin concatenación de datos del usuario (AGENTS.md 6.1).
        $clansStatement = $pdo->prepare(
            'SELECT id, slug, name, motto, weekly_points
             FROM clans
             ORDER BY weekly_points DESC, name ASC'
        );
        $clansStatement->execute();
        $clanRows = $clansStatement->fetchAll();

        // Mapeo explícito snake_case (DB) -> camelCase (contrato JSON).
        $clans = array_map(static fn (array $row): array => [
            'id'           => (string) $row['id'],
            'slug'         => (string) $row['slug'],
            'name'         => (string) $row['name'],
            'motto'        => (string) $row['motto'],
            'domainPoints' => (int) $row['weekly_points'],
        ], $clanRows);

        return Response::json([
            'success' => true,
            'data'    => $clans,
        ]);
    }

    // -----------------------------------------------------------------
    // Endpoint 2 — Catálogo y filtro de clanes (RF-01.4, RF-05.3)
    // -----------------------------------------------------------------

    /**
     * GET /api/v1/clans?lineage={lineageType}&status={active|archived}
     *
     * Respuestas: 200 OK con `{items, pagination}` (mismo contrato de listado
     * paginado que la Bitácora), 400 INVALID_QUERY_PARAMS si la paginación no
     * es de enteros positivos, 400 INVALID_CATALOG_FILTER ante un estado ajeno
     * al canon y 400 UNKNOWN_LINEAGE ante un linaje que no es de los ocho.
     */
    public function index(Request $request): Response
    {
        $lineageType = $this->readQueryText($request, 'lineage');
        $status = $this->readQueryText($request, 'status');

        if ($lineageType !== null && !$this->clanService->hasCanonicalLineage($lineageType)) {
            return $this->rejection(ClanGovernanceException::unknownLineage($lineageType));
        }

        if ($status !== null && !in_array($status, [ClanDto::STATUS_ACTIVE, ClanDto::STATUS_ARCHIVED], true)) {
            return $this->badRequest(
                'INVALID_CATALOG_FILTER',
                'El estado del catálogo solo admite «active» o «archived».',
                'REVIEW_CATALOG_FILTERS',
            );
        }

        $page = $this->readPositiveInt($request, 'page', 1);
        $perPage = $this->readPositiveInt($request, 'perPage', ClanService::DEFAULT_CATALOG_PER_PAGE);
        if ($page === null || $perPage === null) {
            return $this->badRequest(
                'INVALID_QUERY_PARAMS',
                'La página y su tamaño deben ser números enteros positivos.',
                'REVIEW_CATALOG_FILTERS',
            );
        }

        $catalogPage = $this->clanService->browseClans($lineageType, $status, $page, $perPage);

        return Response::json([
            'success' => true,
            'data'    => [
                'items'      => $catalogPage->items,
                'pagination' => $catalogPage->pagination,
            ],
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoint 1 — Fundación de un Clan (RF-01.1, RF-01.2, RF-05.4)
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/clans — Fundación de una hermandad.
     *
     * Entrada: `{name, motto, coatOfArms, lineageType, admissionMode?}`.
     *
     * Respuestas: 201 Created (la corona ciñe al fundador), 400
     * INVALID_REQUEST_BODY / INVALID_NAME / UNKNOWN_LINEAGE, 401
     * UNAUTHENTICATED, 403 INSUFFICIENT_RANK o CONVALESCENCE_ACTIVE, 409
     * NAME_ALREADY_RESERVED o ALREADY_AFFILIATED y 422 INVALID_CLAN_PAYLOAD.
     */
    public function store(Request $request): Response
    {
        $founder = $this->requireAuthenticatedUser($request);
        if ($founder === null) {
            return $this->unauthenticatedResponse();
        }

        $payload = $request->getJsonBody();
        if ($payload === null) {
            return $this->invalidBodyResponse();
        }

        $name = $this->readPayloadText($payload, 'name');
        $motto = array_key_exists('motto', $payload) ? $this->readPayloadText($payload, 'motto') : '';
        $coatOfArms = array_key_exists('coatOfArms', $payload) ? $this->readPayloadText($payload, 'coatOfArms') : '';
        $lineageType = $this->readPayloadText($payload, 'lineageType');
        $admissionMode = array_key_exists('admissionMode', $payload)
            ? $this->readPayloadText($payload, 'admissionMode')
            : ClanDto::ADMISSION_OPEN;

        if (
            $name === null || $name === ''
            || $lineageType === null || $lineageType === ''
            || $motto === null || $coatOfArms === null
            || !in_array($admissionMode, [ClanDto::ADMISSION_OPEN, ClanDto::ADMISSION_BY_APPLICATION], true)
        ) {
            return $this->unprocessableResponse(
                'La fundación exige nombre canónico, linaje rector y un régimen de admisión de los dos canónicos.'
            );
        }

        try {
            $clan = $this->clanService->foundClan(
                $founder,
                $name,
                $motto,
                $coatOfArms,
                $lineageType,
                $admissionMode,
            );
        } catch (ClanGovernanceException $veto) {
            return $this->rejection($veto);
        }

        return Response::json([
            'success' => true,
            'data'    => $clan,
        ], 201);
    }

    // -----------------------------------------------------------------
    // Endpoint 3 — Ficha detallada de un Clan (RF-01.3, RF-01.7)
    // -----------------------------------------------------------------

    /**
     * GET /api/v1/clans/{id} — Ficha heráldica, censo y resumen de PDA.
     *
     * Respuestas: 200 OK con `{clan, patriarch, members, applications}` y 404
     * CLAN_NOT_FOUND. El censo es público; las postulaciones pendientes solo
     * las ve quien ciñe la corona (el resto recibe lista vacía), de modo que
     * la ficha no delate quién corteja a quién.
     */
    public function show(Request $request, array $routeParams): Response
    {
        $clanId = $this->readRouteValue($routeParams, 'id');

        try {
            $clan = $this->clanService->findClanById($clanId);
            if ($clan === null) {
                return $this->rejection(ClanGovernanceException::clanNotFound($clanId));
            }

            $members = $this->clanService->listClanMembers($clanId);

            $requester = $request->getUser();
            $applications = $requester === null || $requester->getId() === ''
                ? []
                : $this->clanService->listPendingApplications($requester, $clanId);
        } catch (ClanGovernanceException $veto) {
            return $this->rejection($veto);
        }

        $patriarch = null;
        foreach ($members as $member) {
            if ($member->isPatriarch()) {
                $patriarch = $member;
                break;
            }
        }

        return Response::json([
            'success' => true,
            'data'    => [
                'clan'         => $clan,
                'patriarch'    => $patriarch,
                'members'      => $members,
                'applications' => $applications,
            ],
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoint 13 — Legado Ancestral de un Clan (RF-05.1, RF-05.3)
    // -----------------------------------------------------------------

    /**
     * GET /api/v1/clans/{id}/spells — Conjuros sellados bajo el estandarte.
     *
     * Lectura pública: el patrimonio de una casa se contempla sin vínculo
     * arcano, incluso cuando yace disuelta como «Herencia Ancestral»
     * (RF-05.3). Las obras se resuelven por el clan de origen del conjuro, no
     * por la afiliación vigente de su autor, de modo que la partida de un
     * adepto jamás resta piezas al legado (RF-05.1).
     *
     * Respuestas: 200 OK con `{clan, spells, count}` y 404 CLAN_NOT_FOUND.
     */
    public function spells(Request $request, array $routeParams): Response
    {
        // El parámetro $request queda reservado para futuras cabeceras.
        $clanId = $this->readRouteValue($routeParams, 'id');

        $clan = $this->clanService->findClanById($clanId);
        if ($clan === null) {
            return $this->rejection(ClanGovernanceException::clanNotFound($clanId));
        }

        $spells = $this->spellDiscoveryService->getValidatedSpellsByClan($clanId);

        return Response::json([
            'success' => true,
            'data'    => [
                'clan'   => $clan,
                'spells' => $spells,
                'count'  => count($spells),
            ],
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoint 4 — Actualización de Hermandad por el Patriarca (RF-01.3)
    // -----------------------------------------------------------------

    /**
     * PATCH /api/v1/clans/{id} — Lema, blasón y régimen de admisión.
     *
     * Cada campo omitido conserva su valor vigente; un campo ausente del todo
     * en el payload se rechaza con 422 (una muda sin contenido no es una muda).
     *
     * Respuestas: 200 OK, 400 INVALID_REQUEST_BODY, 401 UNAUTHENTICATED, 403
     * NOT_PATRIARCH o CLAN_ARCHIVED, 404 CLAN_NOT_FOUND y 422
     * INVALID_CLAN_PAYLOAD.
     */
    public function update(Request $request, array $routeParams): Response
    {
        $patriarch = $this->requireAuthenticatedUser($request);
        if ($patriarch === null) {
            return $this->unauthenticatedResponse();
        }

        $payload = $request->getJsonBody();
        if ($payload === null) {
            return $this->invalidBodyResponse();
        }

        $fields = ['motto', 'coatOfArms', 'admissionMode'];
        $provided = array_values(array_filter($fields, static fn (string $field): bool => array_key_exists($field, $payload)));

        if ($provided === []) {
            return $this->unprocessableResponse(
                'La muda de heráldica exige al menos uno de estos campos: lema, blasón o régimen de admisión.'
            );
        }

        $motto = null;
        $coatOfArms = null;
        $admissionMode = null;

        foreach ($provided as $field) {
            $value = $this->readPayloadText($payload, $field);
            if ($value === null) {
                return $this->unprocessableResponse('Todo campo presente debe viajar como texto.');
            }

            if ($field === 'motto') {
                $motto = $value;
            } elseif ($field === 'coatOfArms') {
                $coatOfArms = $value;
            } else {
                $admissionMode = $value;
            }
        }

        if ($admissionMode !== null && !in_array($admissionMode, [ClanDto::ADMISSION_OPEN, ClanDto::ADMISSION_BY_APPLICATION], true)) {
            return $this->unprocessableResponse('El régimen de admisión solo admite «open» o «byApplication».');
        }

        try {
            $clan = $this->clanService->updateHeraldry(
                $patriarch,
                $this->readRouteValue($routeParams, 'id'),
                $motto,
                $coatOfArms,
                $admissionMode,
            );
        } catch (ClanGovernanceException $veto) {
            return $this->rejection($veto);
        }

        return Response::json([
            'success' => true,
            'data'    => $clan,
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoint 5 — Solicitud de Ingreso (RF-01.4, RF-01.5, RF-01.6)
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/clans/{id}/applications — Postulación o ingreso inmediato.
     *
     * Respuestas: 201 Created (solicitud `pending` o membresía `active` según
     * el régimen), 400 PENDING_APPLICATIONS_LIMIT, 401 UNAUTHENTICATED, 403
     * INSUFFICIENT_RANK o CONVALESCENCE_ACTIVE, 404 CLAN_NOT_FOUND y 409
     * CLAN_QUOTA_EXCEEDED, ALREADY_AFFILIATED, CLAN_ARCHIVED o
     * APPLICATION_ALREADY_PENDING.
     */
    public function apply(Request $request, array $routeParams): Response
    {
        $applicant = $this->requireAuthenticatedUser($request);
        if ($applicant === null) {
            return $this->unauthenticatedResponse();
        }

        // La motivación y la estampa de llegada solo atañen a la petición
        // formal (SPEC-10, RF-03.1, plan §3.3): el servicio las exige o
        // descarta según el régimen real de la casa en el instante del gesto.
        $payload = $request->getJsonBody();
        $motivation = is_array($payload) && isset($payload['motivation']) && is_string($payload['motivation'])
            ? $payload['motivation']
            : null;
        $receivedAt = is_array($payload) && isset($payload['receivedAt']) && is_string($payload['receivedAt'])
            ? $payload['receivedAt']
            : null;

        try {
            $admission = $this->clanService->applyToClan(
                $applicant,
                $this->readRouteValue($routeParams, 'id'),
                now: null,
                motivation: $motivation,
                receivedAt: $receivedAt,
            );
        } catch (ClanGovernanceException $veto) {
            return $this->rejection($veto);
        }

        return Response::json([
            'success' => true,
            'data'    => $admission,
        ], 201);
    }

    // -----------------------------------------------------------------
    // Endpoint 6 — Resolución de Solicitud por el Patriarca (RF-01.5)
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/clans/{id}/applications/{appId}/resolve
     *
     * Entrada: `{action: "approve"|"reject"}`.
     *
     * Respuestas: 200 OK con el desenlace (`rejected` o `active`, con las
     * demás postulaciones del adepto canceladas), 400 INVALID_REQUEST_BODY o
     * INVALID_DECISION, 401 UNAUTHENTICATED, 403 NOT_PATRIARCH, 404
     * APPLICATION_NOT_FOUND o CLAN_NOT_FOUND y 409 CLAN_QUOTA_EXCEEDED,
     * APPLICATION_ALREADY_RESOLVED o ALREADY_AFFILIATED.
     */
    public function resolveApplication(Request $request, array $routeParams): Response
    {
        $patriarch = $this->requireAuthenticatedUser($request);
        if ($patriarch === null) {
            return $this->unauthenticatedResponse();
        }

        $payload = $request->getJsonBody();
        if ($payload === null) {
            return $this->invalidBodyResponse();
        }

        $decision = $this->readPayloadText($payload, 'action');
        if ($decision === null || $decision === '') {
            return $this->unprocessableResponse('La deliberación exige el veredicto en el campo «action».');
        }

        // El motivo del rechazo (SPEC-10, Tarea 2.6): opcional en el payload
        // — el servicio lo EXIGE solo para `reject` (400 si falta o desborda
        // el molde); la aprobación no lo exige (el ingreso ES su motivo).
        $motive = array_key_exists('motive', $payload) ? $this->readPayloadText($payload, 'motive') : null;

        try {
            $resolution = $this->clanService->resolveApplication(
                $patriarch,
                $this->readRouteValue($routeParams, 'id'),
                $this->readRouteValue($routeParams, 'appId'),
                $decision,
                null,
                $motive,
            );
        } catch (ClanGovernanceException $veto) {
            return $this->rejection($veto);
        }

        return Response::json([
            'success' => true,
            'data'    => $resolution,
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoint 7 — Renuncia Voluntaria (RF-01.6, RF-05.3)
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/clans/{id}/leave — Partida del adepto o del Patriarca.
     *
     * Respuestas: 200 OK con la membresía cerrada y su convalecencia, 400
     * PATRIARCH_MUST_TRANSFER_CROWN, 401 UNAUTHENTICATED y 404 NOT_A_MEMBER o
     * CLAN_NOT_FOUND. Si el Patriarca era el último miembro, la casa queda
     * disuelta como Herencia Ancestral en el mismo gesto.
     */
    public function leave(Request $request, array $routeParams): Response
    {
        $member = $this->requireAuthenticatedUser($request);
        if ($member === null) {
            return $this->unauthenticatedResponse();
        }

        try {
            $membership = $this->clanService->leaveClan(
                $member,
                $this->readRouteValue($routeParams, 'id'),
            );
        } catch (ClanGovernanceException $veto) {
            return $this->rejection($veto);
        }

        return Response::json([
            'success' => true,
            'data'    => $membership,
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoint 8 — Expulsión por el Patriarca (RF-01.6, RF-01.3)
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/clans/{id}/expel/{userId} — Expulsión de un adepto.
     *
     * Respuestas: 200 OK con la membresía cerrada y su convalecencia, 401
     * UNAUTHENTICATED, 403 NOT_PATRIARCH o CANNOT_EXPEL_SELF y 404
     * NOT_A_MEMBER o CLAN_NOT_FOUND.
     */
    public function expel(Request $request, array $routeParams): Response
    {
        $patriarch = $this->requireAuthenticatedUser($request);
        if ($patriarch === null) {
            return $this->unauthenticatedResponse();
        }

        try {
            $membership = $this->clanService->expelMember(
                $patriarch,
                $this->readRouteValue($routeParams, 'id'),
                $this->readRouteValue($routeParams, 'userId'),
            );
        } catch (ClanGovernanceException $veto) {
            return $this->rejection($veto);
        }

        return Response::json([
            'success' => true,
            'data'    => $membership,
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoint 9 — Traspaso de la Corona de Patriarca (RF-01.3)
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/clans/{id}/transfer-leadership
     *
     * Entrada: `{newPatriarchId}`.
     *
     * Respuestas: 200 OK con la membresía del nuevo Patriarca; el anterior
     * desciende a `adept`. 400 INELIGIBLE_SUCCESSOR, 401 UNAUTHENTICATED, 403
     * NOT_PATRIARCH, 404 CLAN_NOT_FOUND y 422 INVALID_CLAN_PAYLOAD.
     */
    public function transferLeadership(Request $request, array $routeParams): Response
    {
        $patriarch = $this->requireAuthenticatedUser($request);
        if ($patriarch === null) {
            return $this->unauthenticatedResponse();
        }

        $payload = $request->getJsonBody();
        if ($payload === null) {
            return $this->invalidBodyResponse();
        }

        $successorId = $this->readPayloadText($payload, 'newPatriarchId');
        if ($successorId === null || $successorId === '') {
            return $this->unprocessableResponse('El traspaso exige el identificador del nuevo Patriarca.');
        }

        try {
            $crown = $this->clanService->transferLeadership(
                $patriarch,
                $this->readRouteValue($routeParams, 'id'),
                $successorId,
            );
        } catch (ClanGovernanceException $veto) {
            return $this->rejection($veto);
        }

        return Response::json([
            'success' => true,
            'data'    => $crown,
        ], 200);
    }

    // -----------------------------------------------------------------
    // Ayudantes privados: sesión, lectura de payload y sobres de error.
    // -----------------------------------------------------------------

    /**
     * Usuario activo del vínculo arcano, o null si la petición es anónima.
     *
     * El AuthMiddleware inyecta un visitante con identificador vacío cuando
     * no hay cookie válida: ese visitante NO puede gobernar hermandad alguna
     * (RF-05.1), de modo que aquí se le trata como no autenticado.
     */
    private function requireAuthenticatedUser(Request $request): ?User
    {
        $user = $request->getUser();

        return $user === null || $user->getId() === '' ? null : $user;
    }

    /** Valor textual saneado de una clave del payload; null si falta o su tipo es sucio. */
    private function readPayloadText(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? trim($value) : null;
    }

    /** Valor textual de un parámetro de consulta; null si falta o viene vacío. */
    private function readQueryText(Request $request, string $name): ?string
    {
        $value = $request->getQueryParam($name);
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * Entero positivo de un parámetro de consulta, o null si es basura.
     *
     * Un parámetro ausente adopta el valor por defecto del canon; uno presente
     * pero no numérico o no positivo se rechaza (mismo rigor que la Bitácora).
     */
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

    /** Valor textual de un parámetro de ruta (jamás nulo: la ruta lo exige). */
    private function readRouteValue(array $routeParams, string $key): string
    {
        $value = $routeParams[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** Traduce el veto del canon a su sobre HTTP canónico (400/403/404/409). */
    private function rejection(ClanGovernanceException $veto): Response
    {
        return Response::json($veto->toPayload(), $veto->httpStatus);
    }

    /** 401: sin vínculo arcano activo (contrato SPEC-03). */
    private function unauthenticatedResponse(): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => 'UNAUTHENTICATED',
                'message'        => 'El vínculo arcano no está activo: conságrate o vincula tu identidad antes de gobernar una hermandad.',
                'recoveryAction' => 'BIND_OR_CONSECRATE',
            ],
        ], 401);
    }

    /** 400: el cuerpo no es un objeto JSON válido. */
    private function invalidBodyResponse(): Response
    {
        return $this->badRequest(
            'INVALID_REQUEST_BODY',
            'El cuerpo de la petición debe ser un objeto JSON válido.',
            'CORRECT_THE_PAYLOAD',
        );
    }

    /** 422: JSON bien formado pero incompleto en su entidad (plan 6.2). */
    private function unprocessableResponse(string $message): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => 'INVALID_CLAN_PAYLOAD',
                'message'        => $message,
                'recoveryAction' => 'RESTATE_CLAN_PAYLOAD',
            ],
        ], 422);
    }

    /** 400 canónico del proyecto (AGENTS.md 6.1). */
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
