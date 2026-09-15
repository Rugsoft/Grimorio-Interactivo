<?php

/**
 * SovereignAdminController.php — Endpoints REST de los decretos soberanos y
 * de la caducidad por letargo (SPEC-08, Tarea 3.3).
 *
 * Sirve los Endpoints 9 a 12 del plan 2.2 sobre `SovereignAdminService`
 * (Tarea 2.5), que es la ÚNICA autoridad de la potestad suprema, y sobre
 * `ModerationWorkflowService` (Tarea 2.3), que es la autoridad del letargo:
 *
 *   - POST /api/v1/moderation/sovereign/validate      → RF-04.1 (Firma Soberana).
 *   - POST /api/v1/moderation/sovereign/rescue        → RF-04.3 (rescate).
 *   - POST /api/v1/moderation/sovereign/archive       → RF-04.4 (destierro).
 *   - POST /api/v1/moderation/cron-check-expiry       → RF-01.6 (letargo).
 *
 * Convención de rechazos (plan 6.2):
 *   - 401 UNAUTHENTICATED / CRON_SECRET_REQUIRED.
 *   - 403 INSUFFICIENT_SOVEREIGN_RANK / CRON_SECRET_INVALID.
 *   - 404 SPELL_NOT_FOUND        la obra no tiene expediente.
 *   - 400 INVALID_REQUEST_BODY   el cuerpo omite el objetivo o el destino.
 *   - 400/403/422                los dicta `ModerationWorkflowException`.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response nativos, `hash_equals` nativo.
 *   - Artículo III: el controlador no aritmetiza el veto del propio linaje ni la
 *     deducción de gloria: traduce el veredicto del servicio.
 *   - Artículo IV (El Velo Arcano): toda leyenda viaja en noble castellano.
 *   - Artículo V: identificadores en inglés camelCase, documentación en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Controllers;

use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Dto\ImperialDecreeDto;
use Grimorio\Dto\SpellReviewDto;
use Grimorio\Exceptions\ModerationWorkflowException;
use Grimorio\Exceptions\SpellNotFoundException;
use Grimorio\Models\User;
use Grimorio\Repositories\ImperialDecreeRepository;
use Grimorio\Services\ModerationWorkflowService;
use Grimorio\Services\SovereignAdminService;

/**
 * Controlador REST del Cónclave Supremo y del letargo arcano.
 */
final class SovereignAdminController
{
    /** Cabecera ceremonial que porta el sello del custodio del letargo. */
    private const CRON_SECRET_HEADER = 'X-Arcane-Cron-Secret';

    /** Clave de entorno donde el santuario deposita el sello. */
    private const CRON_SECRET_ENV = 'GRIMORIO_CRON_SECRET';

    /** Autoridad de la potestad suprema (RF-04). */
    private SovereignAdminService $sovereignService;

    /** Autoridad del letargo arcano de noventa días (RF-01.6). */
    private ModerationWorkflowService $workflowService;

    /** Libro de decretos: el edicto recién dictado se devuelve ÍNTEGRO. */
    private ImperialDecreeRepository $decreeRepository;

    /** Sello que autoriza el letargo; cadena vacía = ruta cerrada a cal y canto. */
    private string $cronSecret;

    /**
     * @param string|null $cronSecret Sello inyectable (arneses); si falta, se
     *                                lee del entorno del santuario.
     */
    public function __construct(
        SovereignAdminService $sovereignService,
        ModerationWorkflowService $workflowService,
        ImperialDecreeRepository $decreeRepository,
        ?string $cronSecret = null,
    ) {
        $this->sovereignService = $sovereignService;
        $this->workflowService = $workflowService;
        $this->decreeRepository = $decreeRepository;
        $this->cronSecret = $cronSecret ?? (string) (getenv(self::CRON_SECRET_ENV) ?: '');
    }

    // -----------------------------------------------------------------
    // Endpoint 9 — Firma Soberana Instantánea (RF-04.1, RF-04.2)
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/moderation/sovereign/validate — Firma Soberana.
     *
     * Entrada: `{ "spellId": "spl_…", "imperialDecreeText": "…" }`.
     *
     * **El objetivo del decreto viaja en el CUERPO.** El plan 2.2 declaró los
     * tres decretos como rutas literales (`/sovereign/validate`, `/rescue`,
     * `/archive`) y puso en su `Entrada` el edicto, el destino y la orden de
     * deducción: el `spellId` los acompaña en el mismo objeto, y un cuerpo que
     * lo omita responde 400 antes de tocar el santuario. Añadir `{id}` a la ruta
     * habría sido una enmienda del contrato ratificado, no una decisión de este
     * controlador. El rescate y el destierro siguen el mismo contrato.
     *
     * Respuestas:
     *   - 200 OK: `{review, decree}`. La obra queda `validated` con su contador
     *     REAL conservado —el soberano no inventa avales—, la gloria acreditada
     *     al linaje originario (RF-05.3) y el Edicto Imperial inscrito íntegro
     *     e inmutable en la Bitácora (RF-04.5).
     *   - 400 CANNOT_SOVEREIGN_VALIDATE_NON_EXPERIMENTAL: el borrador privado y
     *     lo ya consagrado o desterrado están fuera de la facultad (Art. II.3).
     *   - 400 INVALID_REQUEST_BODY: el cuerpo omite el objetivo.
     *   - 401 UNAUTHENTICATED.
     *   - 403 INSUFFICIENT_SOVEREIGN_RANK, SOVEREIGN_OWN_CLAN_VETO (RF-04.2) o
     *     SELF_VALIDATION_PROHIBITED (RF-03.2).
     *   - 404 SPELL_NOT_FOUND.
     *   - 422 IMPERIAL_DECREE_TOO_SHORT: el edicto no alcanza los veinte
     *     caracteres (RF-04.5).
     */
    public function validate(Request $request): Response
    {
        $admin = $this->requireAuthenticatedUser($request);
        if ($admin === null) {
            return $this->unauthenticatedResponse();
        }

        if (!$this->isSovereign($admin)) {
            return $this->rejection(ModerationWorkflowException::insufficientSovereignRank());
        }

        $payload = $request->getJsonBody() ?? [];
        $spellId = $this->readRequiredTarget($payload);
        if ($spellId === null) {
            return $this->invalidBodyResponse('El decreto ha de nombrar el conjuro sobre el que se dicta.');
        }

        try {
            $review = $this->sovereignService->executeSovereignValidation(
                $spellId,
                $admin->getId(),
                $this->readPayloadText($payload, 'imperialDecreeText'),
            );
        } catch (ModerationWorkflowException $veto) {
            return $this->rejection($veto);
        } catch (SpellNotFoundException $missing) {
            return $this->missingSpellResponse($missing);
        }

        return Response::json($this->decreePayload($review, $spellId), 200);
    }

    // -----------------------------------------------------------------
    // Endpoint 10 — Rescate de Obra Vetada (RF-04.3)
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/moderation/sovereign/rescue — Rescate de oficio.
     *
     * Entrada: `{ "spellId": "spl_…", "targetStatus": "experimental"|"validated",
     * "imperialDecreeText": "…" }`.
     *
     * Respuestas:
     *   - 200 OK: `{review, decree}`. El rescate a `experimental` reinicia la
     *     deliberación con CERO firmas, anulando cualquier aval colado con su
     *     motivo canónico; el rescate directo al Tomo consagra y ACREDITA la
     *     gloria que el veto había negado.
     *   - 400 CANNOT_SOVEREIGN_RESCUE_NON_REJECTED, SOVEREIGN_RESCUE_INVALID_TARGET
     *     o INVALID_REQUEST_BODY.
     *   - 401 UNAUTHENTICATED.
     *   - 403 INSUFFICIENT_SOVEREIGN_RANK, SOVEREIGN_OWN_CLAN_VETO o
     *     SELF_VALIDATION_PROHIBITED.
     *   - 404 SPELL_NOT_FOUND.
     *   - 422 IMPERIAL_DECREE_TOO_SHORT.
     */
    public function rescue(Request $request): Response
    {
        $admin = $this->requireAuthenticatedUser($request);
        if ($admin === null) {
            return $this->unauthenticatedResponse();
        }

        if (!$this->isSovereign($admin)) {
            return $this->rejection(ModerationWorkflowException::insufficientSovereignRank());
        }

        $payload = $request->getJsonBody() ?? [];
        $spellId = $this->readRequiredTarget($payload);
        if ($spellId === null) {
            return $this->invalidBodyResponse('El rescate ha de nombrar el conjuro que se restituye.');
        }

        try {
            $review = $this->sovereignService->executeSovereignRescue(
                $spellId,
                $admin->getId(),
                $this->readPayloadText($payload, 'targetStatus'),
                $this->readPayloadText($payload, 'imperialDecreeText'),
            );
        } catch (ModerationWorkflowException $veto) {
            return $this->rejection($veto);
        } catch (SpellNotFoundException $missing) {
            return $this->missingSpellResponse($missing);
        }

        return Response::json($this->decreePayload($review, $spellId), 200);
    }

    // -----------------------------------------------------------------
    // Endpoint 11 — Revocación y Archivo Póstumo (RF-04.4)
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/moderation/sovereign/archive — Destierro póstumo.
     *
     * Entrada: `{ "spellId": "spl_…", "imperialDecreeText": "…",
     * "deductPoints": true|false }`.
     *
     * Respuestas:
     *   - 200 OK: `{review, decree, gloryDeductionOrdered}`. La obra queda
     *     `archived`, sus avales caen con el motivo `sovereign_archive` y, si la
     *     orden viaja, la gloria se deduce retroactivamente del linaje
     *     originario dejando en la Bitácora la aritmética exacta (Art. III.3).
     *   - 400 CANNOT_SOVEREIGN_ARCHIVE_NON_VALIDATED o INVALID_REQUEST_BODY (el
     *     objetivo ausente o una orden de deducción que no es un booleano).
     *   - 401 UNAUTHENTICATED.
     *   - 403 INSUFFICIENT_SOVEREIGN_RANK, SOVEREIGN_OWN_CLAN_VETO o
     *     SELF_VALIDATION_PROHIBITED.
     *   - 404 SPELL_NOT_FOUND.
     *   - 422 IMPERIAL_DECREE_TOO_SHORT.
     */
    public function archive(Request $request): Response
    {
        $admin = $this->requireAuthenticatedUser($request);
        if ($admin === null) {
            return $this->unauthenticatedResponse();
        }

        if (!$this->isSovereign($admin)) {
            return $this->rejection(ModerationWorkflowException::insufficientSovereignRank());
        }

        $payload = $request->getJsonBody() ?? [];
        $spellId = $this->readRequiredTarget($payload);
        if ($spellId === null) {
            return $this->invalidBodyResponse('El destierro ha de nombrar el conjuro que cae del canon.');
        }

        // La orden de deducción es una LEY, no una preferencia: si viaja con un
        // tipo sucio no se coacciona a falso en silencio —una deducción que no
        // ocurre es tan grave como una que ocurre sin orden—, se rechaza.
        $deductPoints = false;
        if (array_key_exists('deductPoints', $payload)) {
            if (!is_bool($payload['deductPoints'])) {
                return $this->invalidBodyResponse('La orden de deducción ha de ser un booleano explícito.');
            }

            $deductPoints = $payload['deductPoints'];
        }

        try {
            $review = $this->sovereignService->executeSovereignArchive(
                $spellId,
                $admin->getId(),
                $this->readPayloadText($payload, 'imperialDecreeText'),
                $deductPoints,
            );
        } catch (ModerationWorkflowException $veto) {
            return $this->rejection($veto);
        } catch (SpellNotFoundException $missing) {
            return $this->missingSpellResponse($missing);
        }

        $decreePayload = $this->decreePayload($review, $spellId);
        $decreePayload['data']['gloryDeductionOrdered'] = $deductPoints;

        return Response::json($decreePayload, 200);
    }

    // -----------------------------------------------------------------
    // Endpoint 12 — Caducidad por Letargo (RF-01.6)
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/moderation/cron-check-expiry — Letargo arcano.
     *
     * Cabeceras: `X-Arcane-Cron-Secret: <systemSecret>`.
     *
     * Respuestas:
     *   - 200 OK: `{legend, expiredCount, expired}` con el acta del barrido: las
     *     obras que acumulaban noventa días naturales sin nueva resonancia han
     *     pasado a `rejected`, sus avales viejos cayeron con el motivo
     *     `review_expired` y el cupo de cada autor quedó libre.
     *   - 401 CRON_SECRET_REQUIRED: la cabecera del sello está ausente.
     *   - 403 CRON_SECRET_INVALID: el sello no es el del custodio, o el
     *     santuario no declaró ninguno (fallo cerrado).
     *
     * **El cron no porta sesión: porta el sello del custodio.** La caducidad la
     * invoca un planificador, no un mago, así que exigirle un vínculo arcano la
     * haría inejecutable. Se sella con la cabecera `X-Arcane-Cron-Secret` —mismo
     * protocolo que el corte dominical de SPEC-07, con `hash_equals` y FALLO
     * CERRADO si el santuario no declaró sello alguno—. El reloj lo pone el
     * SANTUARIO, jamás el cliente: aceptar un instante del exterior permitiría a
     * cualquiera caducar obras ajenas a voluntad.
     */
    public function cronCheckExpiry(Request $request): Response
    {
        $rejection = $this->rejectUnlessCronSealed($request);
        if ($rejection !== null) {
            return $rejection;
        }

        $expired = $this->workflowService->checkExpiryCron();

        return Response::json([
            'success' => true,
            'data'    => [
                'legend'       => ModerationWorkflowService::EXPIRY_LEGEND,
                'staleDays'    => ModerationWorkflowService::STALE_REVIEW_DAYS,
                'expiredCount' => count($expired),
                'expired'      => $expired,
            ],
        ], 200);
    }

    // -----------------------------------------------------------------
    // Ayudantes privados.
    // -----------------------------------------------------------------

    /**
     * Sobre del decreto: el expediente y su Edicto Imperial inscrito.
     *
     * El edicto se devuelve ÍNTEGRO —no un resumen— porque es la prueba de que
     * quedó inscrito y la misma memoria que la Bitácora pública publicará
     * (RF-04.5, RNF-01).
     *
     * @return array<string, mixed> Cuerpo JSON del decreto.
     */
    private function decreePayload(SpellReviewDto $review, string $spellId): array
    {
        return [
            'success' => true,
            'data'    => [
                'review' => $review,
                'decree' => $this->latestDecreeOf($spellId),
            ],
        ];
    }

    /** Último decreto inscrito sobre la obra, o null si no consta. */
    private function latestDecreeOf(string $spellId): ?ImperialDecreeDto
    {
        $decree = $this->decreeRepository->findLatestDecreeBySpell($spellId);

        return $decree === null ? null : ImperialDecreeDto::fromDatabaseRow($decree);
    }

    /**
     * Objetivo del decreto declarado en el cuerpo, o null si falta.
     *
     * @param array<string, mixed> $payload
     */
    private function readRequiredTarget(array $payload): ?string
    {
        $spellId = $this->readPayloadText($payload, 'spellId');

        return $spellId === null || $spellId === '' ? null : $spellId;
    }

    /** Verifica el sello del custodio; devuelve la Response de rechazo o null. */
    private function rejectUnlessCronSealed(Request $request): ?Response
    {
        $presented = $request->getHeader(self::CRON_SECRET_HEADER);

        if ($presented === null || trim($presented) === '') {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'           => 'CRON_SECRET_REQUIRED',
                    'message'        => 'El barrido del letargo exige el sello del custodio en la cabecera X-Arcane-Cron-Secret.',
                    'recoveryAction' => 'PROVIDE_CRON_SECRET',
                ],
            ], 401);
        }

        // Comparación en tiempo constante y fallo cerrado: sin sello declarado
        // en el entorno NADIE invoca el letargo, y el rechazo no delata cuál de
        // las dos causas —sello ajeno o sello ausente— lo motivó.
        if ($this->cronSecret === '' || hash_equals($this->cronSecret, trim($presented)) === false) {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'           => 'CRON_SECRET_INVALID',
                    'message'        => 'El sello presentado no autoriza esta invocación del letargo arcano.',
                    'recoveryAction' => 'REVIEW_CRON_SECRET',
                ],
            ], 403);
        }

        return null;
    }

    /** ¿Ostenta el consultante la potestad suprema? (RF-04) */
    private function isSovereign(User $user): bool
    {
        return $user->getRole() === SovereignAdminService::SOVEREIGN_ROLE;
    }

    /** Titular de la petición, o null si no hay vínculo arcano activo. */
    private function requireAuthenticatedUser(Request $request): ?User
    {
        $user = $request->getUser();

        return $user === null || $user->getId() === '' ? null : $user;
    }

    /**
     * Campo textual de un payload, o vacío si falta o no es escalar textual.
     *
     * Un tipo sucio no se coacciona: viaja como ausencia para que el umbral del
     * dominio (los veinte caracteres del Edicto Imperial) sea el que dicte el
     * veredicto.
     *
     * @param array<string, mixed> $payload
     */
    private function readPayloadText(array $payload, string $key): string
    {
        if (!array_key_exists($key, $payload) || !is_string($payload[$key])) {
            return '';
        }

        return trim($payload[$key]);
    }

    /** Sobre del veredicto canónico del Cónclave Supremo. */
    private function rejection(ModerationWorkflowException $veto): Response
    {
        return Response::json($veto->toPayload(), $veto->httpStatus);
    }

    /** Sobre 404 de la obra sin expediente en la Torre. */
    private function missingSpellResponse(SpellNotFoundException $missing): Response
    {
        return Response::json($missing->toPayload(), $missing->getHttpStatusCode());
    }

    /** 401: sin vínculo arcano activo (contrato SPEC-03). */
    private function unauthenticatedResponse(): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => 'UNAUTHENTICATED',
                'message'        => 'El vínculo arcano no está activo: conságrate o vincula tu identidad antes de dictar un decreto imperial.',
                'recoveryAction' => 'BIND_OR_CONSECRATE',
            ],
        ], 401);
    }

    /** 400: el cuerpo de la petición no porta el contrato del decreto. */
    private function invalidBodyResponse(string $legend): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => 'INVALID_REQUEST_BODY',
                'message'        => $legend,
                'recoveryAction' => 'CORRECT_THE_PAYLOAD',
            ],
        ], 400);
    }
}
