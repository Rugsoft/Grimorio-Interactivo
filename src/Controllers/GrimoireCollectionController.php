<?php

/**
 * GrimoireCollectionController.php — Endpoints REST del Tomo Personal y
 * la puerta del Elogio Popular (SPEC-11, Fases 3 y 4).
 *
 * Tarea 3.1 (TASKS-11): `praiseSpell()` — LA PUERTA REST DEL ELOGIO.
 * Orquesta las guardias propias (sesión → linaje → existencia → 409
 * sobre no validado, plan §3.3) e invoca el servicio vivo de SPEC-07
 * (`WeeklyDominionService::awardCommunityFavorite()`) SIN modificar su
 * maquinaria: la frontera de capa (plan §1.1) queda físicamente sellada
 * porque este controlador es el ÚNICO punto donde el tomo toca al Dominio.
 *
 * Traducción del recibo vivo (hallazgos 10-11):
 *   - Gloria nueva        → 200 { praised: true,  reason: 'AWARDED', awarded: {...} }
 *   - Voto ya rendido     → 200 { praised: true,  reason: 'ALREADY_PRAISED' }
 *   - Militancia propia   → 200 { praised: false, reason: 'OWN_CLAN_FAVORITE' }
 *     (recibo denegado ANTES de insertar fila: jamás error HTTP)
 *   - No validado forzado → 409 PRAISE_SPELL_NOT_VALIDATED (RF-04.5;
 *     el ÚNICO 409 nuevo de la spec: duplicado y militancia son estados)
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response nativos, PDO del
 *     servicio vivo; cero dependencias externas.
 *   - Artículo III (Transparencia): la Bitácora SOLO recibe asiento
 *     cuando la gloria nace (RF-06.2): el eco idempotente y el recibo
 *     denegado jamás se asientan (Tarea 3.2, plan §2.3).
 *   - Artículo IV/V (Velo y Dualidad): códigos técnicos en inglés
 *     camelCase; leyendas solemnes en noble castellano.
 */

declare(strict_types=1);

namespace Grimorio\Controllers;

use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Dto\CollectionPageDto;
use Grimorio\Dto\DominionAwardDto;
use Grimorio\Exceptions\LineageOathException;
use Grimorio\Exceptions\SpellNotFoundException;
use Grimorio\Exceptions\SpellNotInTomeException;
use Grimorio\Exceptions\SpellNotValidatedException;
use Grimorio\Models\User;
use Grimorio\Services\AuditRecorderInterface;
use Grimorio\Services\GrimoireCollectionService;
use Grimorio\Services\GrimoireQueryService;
use Grimorio\Services\WeeklyDominionService;

/**
 * Controlador REST del Tomo Personal y su puerta al Dominio.
 */
final class GrimoireCollectionController
{
    /** Afinidades elementales canónicas del santuario (SPEC-06, matriz). */
    private const CANONICAL_ELEMENTS = [
        'fire', 'water', 'lightning', 'earth', 'wind', 'light', 'darkness', 'pureArcane', 'none',
    ];

    /** Servicio del tomo: guardias de linaje y lectura viva de estados. */
    private GrimoireCollectionService $collectionService;

    /** Consulta del catálogo: la hoja enriquecida del tomo (Tarea 3.3). */
    private GrimoireQueryService $queryService;

    /** Servicio del Dominio (SPEC-07): la autoridad de la gloria, sin tocar. */
    private WeeklyDominionService $dominionService;

    /** La pluma de la Bitácora: constancia de quién movió la gloria (RF-06.2). */
    private AuditRecorderInterface $auditService;

    public function __construct(
        GrimoireCollectionService $collectionService,
        GrimoireQueryService $queryService,
        WeeklyDominionService $dominionService,
        AuditRecorderInterface $auditService,
    ) {
        $this->collectionService = $collectionService;
        $this->queryService = $queryService;
        $this->dominionService = $dominionService;
        $this->auditService = $auditService;
    }

    // -----------------------------------------------------------------
    // La lectura del tomo (RF-02.1, plan §2.2) — Tarea 4.1
    // -----------------------------------------------------------------

    /**
     * GET /api/v1/grimoire/collection?element={afinidad}&page={n}.
     *
     * El tomo íntimo del adepto autenticado con linaje jurado: hoja de
     * 50 entradas enriquecidas (ficha litúrgica + instante de sellado +
     * marca solemne del mapa único + estado del homenaje), ordenadas
     * por adición (la más reciente primero) y con los metadatos de
     * paginación del contrato (total, page, limit, totalPages).
     *
     * Respuestas: 200 con el sobre CollectionPageDto; 401
     * UNAUTHENTICATED; 403 LINEAGE_OATH_REQUIRED (hallazgo 16: el
     * linaje manda, no el rol); 400 ante parámetros fuera del canon.
     */
    public function listCollection(Request $request): Response
    {
        // ---- Guardia 1: sesión (401) ---------------------------------
        $adepto = $this->requireAuthenticatedUser($request);
        if ($adepto === null) {
            return $this->unauthenticatedResponse();
        }

        // ---- Guardia 2: el linaje manda, no el rol (hallazgo 16) -----
        if ($adepto->getLineage() === null) {
            return $this->oathRejection();
        }

        // ---- Saneado de parámetros (400 ante basura) -----------------
        $element = null;
        $rawElement = $request->getQueryParam('element');
        if ($rawElement !== null && $rawElement !== '') {
            if (!in_array($rawElement, self::CANONICAL_ELEMENTS, true)) {
                return Response::json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'INVALID_ELEMENT',
                        'message' => 'Esa afinidad elemental no pertenece al canon del santuario.',
                    ],
                ], 400);
            }
            $element = $rawElement;
        }

        $page = 1;
        $rawPage = $request->getQueryParam('page');
        if ($rawPage !== null && $rawPage !== '') {
            if (!ctype_digit($rawPage)) {
                return Response::json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'INVALID_PAGE',
                        'message' => 'La hoja del tomo debe ser un número entero positivo.',
                    ],
                ], 400);
            }
            $page = max(1, (int) $rawPage);
        }

        return Response::json([
            'success' => true,
            'data'    => $this->queryService->getCollection($adepto, $element, $page),
        ], 200);
    }

    // -----------------------------------------------------------------
    // El rito del sellado vía REST (RF-01.1, plan §3.2) — Tarea 4.1
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/grimoire/collection — cuerpo `{ spellId: "…" }`.
     *
     * Guardias en orden de contrato (plan §3.2): sesión (401) → linaje
     * jurado (403, hallazgo 16) → existencia (404; el peregrino ante un
     * fantasma recibe EL JURAMENTO, jamás el 404: nadie descubre
     * existencia) → idempotencia (200 ALREADY_IN_TOME sin segunda fila
     * ni segundo asiento, RF-01.3) → leyenda UNIFORME ante no validado
     * (403 TOME_SEAL_VETO, hallazgo 4). El sellado nuevo responde 201
     * con el eco del acto (alreadyCollected/addedAt, camelCase).
     */
    public function collectSpell(Request $request): Response
    {
        // ---- Guardia 1: sesión (401) ---------------------------------
        $adepto = $this->requireAuthenticatedUser($request);
        if ($adepto === null) {
            return $this->unauthenticatedResponse();
        }

        // ---- Guardia 2: el linaje manda, no el rol (hallazgo 16) -----
        if ($adepto->getLineage() === null) {
            return $this->oathRejection();
        }

        // ---- Guardias 3-6 del rito: el servicio juzga (plan §3.2) ----
        // La leyenda UNIFORME, el 404 del fantasma y la idempotencia
        // son VOZ del servicio (Tarea 2.2); aquí solo se traducen los
        // desenlaces a los códigos exactos del contrato.
        try {
            $spellId = $this->readSpellId($request);
            $spellStatus = $this->collectionService->spellStatusFor($spellId);
            $sealEcho = $this->collectionService->collectSpell($adepto, $spellId, $spellStatus);
        } catch (SpellNotFoundException $vanished) {
            return Response::json($vanished->toPayload(), $vanished->getHttpStatusCode());
        } catch (\Grimorio\Exceptions\UniformSealVetoException $veto) {
            return Response::json($veto->toPayload(), $veto->getHttpStatusCode());
        }

        return Response::json([
            'success' => true,
            'data'    => $sealEcho,
        ], ($sealEcho['alreadyCollected'] ?? false) ? 200 : 201);
    }

    // -----------------------------------------------------------------
    // La retirada del tomo vía REST (RF-02.4, plan §2.2) — Tarea 4.1
    // -----------------------------------------------------------------

    /**
     * DELETE /api/v1/grimoire/collection/{spellId}?element={afinidad}.
     *
     * La muralla de intimidad vive en el repositorio (fila propia);
     * aquí se traduce el veredicto: retirada consumada → 200 con el
     * total ACTUALIZADO bajo el filtro vigente (la vista refresca el
     * rótulo sin segunda petición); fila ausente → 409
     * SPELL_NOT_IN_TOME (el hechizo puede vivir en el catálogo: lo que
     * no existe es su entrada en ESTE tomo). Frontera sagrada del
     * hallazgo 13: la retirada JAMÁS toca `favorites`.
     *
     * Nota de guardias (hallazgo 16): la retirada es un acto íntimo
     * sobre la PROPIA fila — la muralla del repositorio es física — y
     * no juzga el estado del hechizo: se retira lo que se selló, viva
     * u apartada la obra. El anónimo recibe 401 y el peregrino, su
     * juramento.
     */
    public function discardSpell(Request $request, array $routeParams): Response
    {
        // ---- Guardia 1: sesión (401) ---------------------------------
        $adepto = $this->requireAuthenticatedUser($request);
        if ($adepto === null) {
            return $this->unauthenticatedResponse();
        }

        // ---- Guardia 2: el linaje manda, no el rol (hallazgo 16) -----
        if ($adepto->getLineage() === null) {
            return $this->oathRejection();
        }

        $spellId = (string) ($routeParams['spellId'] ?? '');
        if ($spellId === '') {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'    => 'SPELL_NOT_IN_TOME',
                    'message' => 'Solo se retira lo que se selló: ese hechizo no habita tu tomo.',
                ],
            ], 409);
        }

        // El filtro vigente de la vista viaja en la query: el total de
        // la respuesta debe describir el conjunto que se exhibe.
        $rawElement = $request->getQueryParam('element');
        $element = is_string($rawElement) && in_array($rawElement, self::CANONICAL_ELEMENTS, true)
            ? $rawElement
            : null;

        try {
            $discardEcho = $this->collectionService->discardSpell($adepto, $spellId, $element);
        } catch (SpellNotInTomeException $absent) {
            return Response::json($absent->toPayload(), $absent->getHttpStatusCode());
        }

        return Response::json([
            'success' => true,
            'data'    => $discardEcho,
        ], 200);
    }

    // -----------------------------------------------------------------
    // La puerta del Elogio Popular (RF-04, plan §3.3) — Tarea 3.1
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/grimoire/praise — cuerpo `{ spellId: "…" }`.
     *
     * Guardias en orden de contrato: sesión (401) → linaje jurado (403
     * `LINEAGE_OATH_REQUIRED`, el linaje manda no el rol — hallazgo 16)
     * → existencia (404) → estado `validated` (409, RF-04.5). Tras las
     * guardias, el servicio vivo de SPEC-07 decide con sus guardias
     * vivas (militancia, duplicado) y este controlador SOLO traduce el
     * recibo: jamás inserta el voto ni mueve PDA por su cuenta.
     *
     * Respuestas: 200 con praised/reason; 401 UNAUTHENTICATED;
     * 403 LINEAGE_OATH_REQUIRED; 404 SPELL_NOT_FOUND;
     * 409 PRAISE_SPELL_NOT_VALIDATED.
     */
    public function praiseSpell(Request $request): Response
    {
        // ---- Guardia 1: sesión (401) ---------------------------------
        $adepto = $this->requireAuthenticatedUser($request);
        if ($adepto === null) {
            return $this->unauthenticatedResponse();
        }

        // ---- Guardia 2: el linaje manda, no el rol (hallazgo 16) -----
        if ($adepto->getLineage() === null) {
            return $this->oathRejection(
                LineageOathException::lineageOathRequired(
                    'El santuario aguarda tu juramento: nadie pisa sus salas sin linaje jurado.'
                )
            );
        }

        // ---- Guardia 3-4: existencia (404) y estado (409, RF-04.5) ---
        try {
            $spellStatus = $this->collectionService->spellStatusFor($this->readSpellId($request));
        } catch (SpellNotFoundException $vanished) {
            return Response::json($vanished->toPayload(), $vanished->getHttpStatusCode());
        }

        if ($spellStatus !== GrimoireCollectionService::SPELL_STATUS_VALIDATED) {
            return Response::json(SpellNotValidatedException::praiseRequiresValidatedSpell()->toPayload(), 409);
        }

        // ---- La gloria: el servicio vivo de SPEC-07 decide (RF-04.2) -
        // SIN modificación: sus guardias de militancia (OWN_CLAN_FAVORITE,
        // deniega ANTES de insertar fila) y duplicado (DUPLICATE_FAVORITE)
        // hablan con su voz de siempre; aquí solo se traduce el recibo.
        $spellId = $this->readSpellId($request);
        $receipt = $this->dominionService->awardCommunityFavorite($adepto, $spellId);

        // ---- Asiento de Bitácora SOLO con gloria acreditada (RF-06.2) -
        // Un acto que mueve PDA exige constancia de quién movió la gloria
        // (plan §2.3): el eco idempotente (ALREADY_PRAISED) y el recibo
        // denegado (OWN_CLAN_FAVORITE) jamás dejan rastro en la Bitácora.
        if (!$receipt->wasAwarded()) {
            return Response::json([
                'success' => true,
                'data'    => $this->translateReceipt($receipt),
            ], 200);
        }

        $this->auditService->recordAction(
            actorUserId: $adepto->getId(),
            actorAlias: $adepto->getAlias(),
            actorRole: $adepto->getRole(),
            actionType: 'TOME_PRAISE',
            targetEntityType: 'spell',
            targetEntityId: $spellId,
            justification: $this->praiseJustification($adepto, $spellId),
        );

        return Response::json([
            'success' => true,
            'data'    => $this->translateReceipt($receipt),
        ], 200);
    }

    // -----------------------------------------------------------------
    // Asiento de Bitácora del homenaje (RF-06.2, plan §2.3) — Tarea 3.2
    // -----------------------------------------------------------------

    /**
     * Justificación canónica del asiento `TOME_PRAISE` (plan §2.3):
     * «{alias} rindió homenaje a {hechizo}, granjeando gloria a {clan}» —
     * nombra adepto, obra y casa destinataria (hallazgo 20).
     */
    private function praiseJustification(
        User $adepto,
        string $spellId,
    ): string {
        $heraldry = $this->collectionService->spellHeraldryFor($spellId);

        return sprintf(
            '%s rindió homenaje a «%s», granjeando gloria a «%s».',
            $adepto->getAlias(),
            $heraldry['spellName'],
            $heraldry['clanName'],
        );
    }

    // -----------------------------------------------------------------
    // Traducción del recibo vivo (plan §2.2, hallazgos 10-11)
    // -----------------------------------------------------------------

    /**
     * Traduce el recibo del Dominio al contrato de la interfaz: los tres
     * desenlaces son ESTADOS y viajan como 200 — solo el no validado
     * forzado es un 409 (resuelto antes, en las guardias propias).
     *
     * @return array{praised: bool, reason: string, awarded?: array{points: int, hasSynergy: bool}}
     */
    private function translateReceipt(DominionAwardDto $receipt): array
    {
        // Recibo denegado por militancia: 200 solemne, jamás error HTTP.
        if ($receipt->reason === DominionAwardDto::REASON_OWN_CLAN_FAVORITE) {
            return ['praised' => false, 'reason' => 'OWN_CLAN_FAVORITE'];
        }

        // El voto ya vive en favorites: eco idempotente sin segunda gloria.
        if ($receipt->reason === DominionAwardDto::REASON_DUPLICATE_FAVORITE) {
            return ['praised' => true, 'reason' => 'ALREADY_PRAISED'];
        }

        // Gloria nueva acreditada (reason null + puntos > 0).
        return [
            'praised' => true,
            'reason'  => 'AWARDED',
            'awarded' => [
                'points'     => $receipt->awardedPoints,
                'hasSynergy' => $receipt->hasSynergy,
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Piezas privadas compartidas por los cuatro endpoints (Tarea 4.1)
    // -----------------------------------------------------------------

    /** El usuario autenticado de la sesión, o null si el vínculo no vive. */
    private function requireAuthenticatedUser(Request $request): ?User
    {
        $user = $request->getUser();

        return $user === null || $user->getId() === '' ? null : $user;
    }

    /** Lee `spellId` del cuerpo JSON (o de la query para sondas simples). */
    private function readSpellId(Request $request): string
    {
        $payload = $request->getJsonBody();
        $spellId = is_array($payload) ? ($payload['spellId'] ?? null) : null;
        if (is_string($spellId) && $spellId !== '') {
            return $spellId;
        }

        return (string) ($request->getQueryParam('spellId') ?? '');
    }

    /** Sobre 401 canónico del contrato SPEC-03 (vínculo no activo). */
    private function unauthenticatedResponse(): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => 'UNAUTHENTICATED',
                'message'        => 'El vínculo arcano no está activo: conságrate o vincula tu identidad para rendir homenaje.',
                'recoveryAction' => 'BIND_OR_CONSECRATE',
            ],
        ], 401);
    }

    /** Traduce la retención del peregrino (defensa en profundidad, hallazgo 16). */
    private function oathRejection(?LineageOathException $oath = null): Response
    {
        $oath ??= LineageOathException::lineageOathRequired(
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
}
