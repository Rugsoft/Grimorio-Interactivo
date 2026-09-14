<?php

/**
 * DominionController.php — Endpoints REST del Dominio Semanal.
 *
 * Tarea 3.3 (TASKS-07): sirve los Endpoints 11 y 12 del plan 2.2 sobre
 * WeeklyDominionService (Tarea 2.5), que es la autoridad de la gloria y del
 * corte dominical:
 *
 *   - GET  /api/v1/dominion/leaderboard      → 200 (Salón del Dominio).
 *   - POST /api/v1/dominion/cron-cycle-close → 200 (proclamación y reinicio).
 *
 * Tarea 7.1 (TASKS-07): añade el Endpoint 14, que abre por HTTP la gloria que
 * el Simulador devenga al detonar una reacción de combo elemental (RF-03.2):
 * `POST /api/v1/dominion/simulator-combo`. El techo diario y su reinicio UTC no
 * se reimplementan aquí: el controlador resuelve adepto y casa y delega
 * ÍNTEGRAMENTE en el servicio (Artículo II).
 *
 * El Salón del Dominio comprende las CUATRO secciones del plan: la
 * clasificación semanal en vivo, el prestigio histórico, el Clan Regente
 * vigente y el Libro Mayor de Campeones. La lectura consume la salvaguarda
 * perezosa del corte (plan 5, Decisión 1) para que una semana vencida jamás
 * contamine la contienda en curso.
 *
 * El cierre dominical está vedado a quien no porte el sello del custodio en la
 * cabecera `X-Arcane-Cron-Secret`, cuya clave vive en el entorno
 * (`GRIMORIO_CRON_SECRET`). Sin clave declarada, la ruta FALLA CERRADA: nada de
 * claves por defecto que un tercero pudiera adivinar.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response nativos y `hash_equals`
 *     nativo para la comparación de secretos; cero librerías.
 *   - Artículo IV: toda leyenda viaja en noble castellano.
 *   - Artículo V: identificadores en inglés camelCase; documentación en castellano.
 *   - RNF-01: el cómputo es determinista y auditable; el corte deriva del
 *     último domingo concluido, no del capricho de quien lo invoca.
 */

declare(strict_types=1);

namespace Grimorio\Controllers;

use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Dto\DominionAwardDto;
use Grimorio\Exceptions\ClanGovernanceException;
use Grimorio\Models\User;
use Grimorio\Services\WeeklyDominionService;

/**
 * Controlador REST del Salón de los Linajes y del corte dominical.
 */
final class DominionController
{
    /** Cabecera ceremonial que porta el sello del custodio. */
    private const CRON_SECRET_HEADER = 'X-Arcane-Cron-Secret';

    /** Clave de entorno donde el santuario deposita el sello. */
    private const CRON_SECRET_ENV = 'GRIMORIO_CRON_SECRET';

    /** Autoridad de la gloria, del podio y del corte dominical. */
    private WeeklyDominionService $dominionService;

    /** Sello que autoriza el cierre; cadena vacía = ruta cerrada a cal y canto. */
    private string $cronSecret;

    /**
     * @param string|null $cronSecret Sello inyectable (arneses); si falta, se
     *                                lee del entorno del santuario.
     */
    public function __construct(WeeklyDominionService $dominionService, ?string $cronSecret = null)
    {
        $this->dominionService = $dominionService;
        $this->cronSecret = $cronSecret ?? (string) (getenv(self::CRON_SECRET_ENV) ?: '');
    }

    /**
     * GET /api/v1/dominion/leaderboard — Salón del Dominio (Endpoint 11).
     *
     * Respuesta 200: `{success, data:{weeklyRanking, historicalRanking,
     * currentRegentClan, hallOfFameWeeks}}`. El podio semanal llega ordenado
     * por PDA descendente y cada estandarte porta su censo de adeptos.
     *
     * La ruta es de lectura pública: el Salón de los Linajes se contempla sin
     * vínculo arcano (RF-06.1). Antes de servir la contienda se consuma la
     * salvaguarda perezosa del corte, de modo que una semana vencida quede
     * proclamada aunque el cron haya faltado a su cita.
     */
    public function leaderboard(Request $request): Response
    {
        // El parámetro $request queda reservado para futuras cabeceras de
        // linaje temático; el Salón no admite filtro alguno.
        return Response::json([
            'success' => true,
            'data'    => $this->dominionService->hallOfDominion(),
        ], 200);
    }

    /**
     * POST /api/v1/dominion/cron-cycle-close — Cierre y proclamación (Endpoint 12).
     *
     * Cabeceras: `X-Arcane-Cron-Secret: <systemSecret>`.
     *
     * Respuestas:
     *   - 200 OK: `{cycle, regentClan}` con el acta del corte recién
     *     proclamado —o la ya inmortalizada, si un segundo latido del cron
     *     vuelve a invocar la misma semana— y la casa que ciñe la corona.
     *   - 401 CRON_SECRET_REQUIRED: la cabecera del sello está ausente.
     *   - 403 CRON_SECRET_INVALID: el sello no es el del custodio, o el
     *     santuario no declaró ninguno (fallo cerrado).
     *
     * El cierre es idempotente (RNF-01): el repliegue de contadores es
     * irreversible y jamás se repite por un segundo latido.
     */
    public function closeCycle(Request $request): Response
    {
        $rejection = $this->rejectUnlessCronSealed($request);
        if ($rejection !== null) {
            return $rejection;
        }

        $cycle = $this->dominionService->closeWeeklyCycle();

        return Response::json([
            'success' => true,
            'data'    => [
                'cycle'      => $cycle,
                // Sin hermandades activas no hay corona que ceñir: el corte
                // queda como gesto vacío, pero jamás como error.
                'regentClan' => $cycle === null ? null : $this->dominionService->currentRegentClan(),
            ],
        ], 200);
    }

    /**
     * POST /api/v1/dominion/simulator-combo — Gloria del Simulador (Endpoint 14).
     *
     * Entrada: `{ "comboElement": "lightning" }`. El elemento es OPCIONAL: sin
     * él (o marcado `none`) el combo no devenga sinergia, pero tampoco es un
     * error —un conjuro sin afinidad es legítimo (RF-03.4)—.
     *
     * Respuestas:
     *   - 200 OK: `{clanId, comboElement, dailyCap, award}`. El recibo canónico
     *     (`award`) porta los PDA acreditados; con el techo diario colmado
     *     llega con `awardedPoints: 0` y `reason: DAILY_SIMULATOR_CAP_REACHED`
     *     —una agonía del cupo, jamás un error de la petición—.
     *   - 400 INVALID_REQUEST_BODY: el cuerpo no es un objeto JSON válido.
     *   - 401 UNAUTHENTICATED: sin vínculo arcano activo.
     *   - 404 NOT_A_MEMBER: la membresía vigente no es la que declara el espejo.
     *   - 409 NO_CLAN_AFFILIATION: el adepto no milita en hermandad alguna.
     *
     * El techo de 50 PDA por adepto y día UTC y su reinicio a las 00:00:00 UTC
     * residen en el servicio (RF-03.2); el controlador jamás los recalcula.
     */
    public function awardSimulatorCombo(Request $request): Response
    {
        $member = $this->requireAuthenticatedMember($request);
        if ($member === null) {
            return $this->unauthenticatedResponse();
        }

        $payload = $request->getJsonBody();
        if ($payload === null) {
            return $this->invalidBodyResponse();
        }

        $comboElement = $this->readComboElement($payload);

        // El espejo denormalizado declara la casa del adepto; el servicio la
        // contrasta acto seguido contra la AUTORIDAD (`clan_members`), de modo
        // que un espejo rancio jamás acredite gloria a una casa ajena.
        $clanId = (string) ($member->getClanId() ?? '');
        if ($clanId === '') {
            return $this->rejection(ClanGovernanceException::noClanAffiliation($member->getId()));
        }

        try {
            $award = $this->dominionService->awardSimulatorCombo($member, $clanId, $comboElement);
        } catch (ClanGovernanceException $veto) {
            return $this->rejection($veto);
        }

        return Response::json([
            'success' => true,
            'data'    => [
                'clanId'       => $clanId,
                'comboElement' => $comboElement,
                'dailyCap'     => DominionAwardDto::DAILY_SIMULATOR_CAP,
                'award'        => $award,
            ],
        ], 200);
    }

    /**
     * Verifica el sello del custodio; devuelve la Response de rechazo o null
     * si el corte queda autorizado.
     */
    private function rejectUnlessCronSealed(Request $request): ?Response
    {
        $presented = $request->getHeader(self::CRON_SECRET_HEADER);

        if ($presented === null || trim($presented) === '') {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'           => 'CRON_SECRET_REQUIRED',
                    'message'        => 'El corte dominical exige el sello del custodio en la cabecera X-Arcane-Cron-Secret.',
                    'recoveryAction' => 'PROVIDE_CRON_SECRET',
                ],
            ], 401);
        }

        // Comparación en tiempo constante y fallo cerrado: sin sello declarado
        // en el entorno NADIE invoca el corte, y el rechazo no delata cuál de
        // las dos causas —sello ajeno o sello ausente— lo motivó.
        if ($this->cronSecret === '' || hash_equals($this->cronSecret, trim($presented)) === false) {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'           => 'CRON_SECRET_INVALID',
                    'message'        => 'El sello presentado no autoriza esta invocación del corte dominical.',
                    'recoveryAction' => 'REVIEW_CRON_SECRET',
                ],
            ], 403);
        }

        return null;
    }

    /** Titular consagrado de la petición, o null si no hay vínculo activo. */
    private function requireAuthenticatedMember(Request $request): ?User
    {
        $member = $request->getUser();

        return $member === null || $member->getId() === '' ? null : $member;
    }

    /**
     * Elemento del combo declarado por el adepto, saneado y acotado.
     *
     * Un elemento ajeno al Códice Elemental no se rechaza: la sinergia es una
     * coincidencia estricta con la afinidad rectora del linaje, así que lo
     * desconocido simplemente no devenga bonificación (misma tolerancia que
     * `LineageSynergyService::hasSynergy`). El recorte evita que un payload
     * hostil infle el recibo.
     */
    private function readComboElement(array $payload): string
    {
        $declared = $payload['comboElement'] ?? null;
        $element = is_string($declared) ? trim($declared) : '';

        return mb_substr($element, 0, 32);
    }

    /** Sobre canónico del veto de gobernanza (AGENTS.md 6.1). */
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
                'message'        => 'El vínculo arcano no está activo: conságrate o vincula tu identidad antes de sumar gloria a una hermandad.',
                'recoveryAction' => 'BIND_OR_CONSECRATE',
            ],
        ], 401);
    }

    /** 400: el cuerpo de la petición no es un objeto JSON válido. */
    private function invalidBodyResponse(): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => 'INVALID_REQUEST_BODY',
                'message'        => 'El cuerpo de la petición debe ser un objeto JSON válido.',
                'recoveryAction' => 'CORRECT_THE_PAYLOAD',
            ],
        ], 400);
    }
}
