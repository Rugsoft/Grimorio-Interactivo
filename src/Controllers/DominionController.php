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
 *   - Artículo IV (El Velo Arcano): toda leyenda viaja en noble castellano.
 *   - Artículo V: identificadores en inglés camelCase, claves de entorno en
 *     mayúsculas, documentación en castellano.
 *   - RNF-01: el cómputo es determinista y auditable; el corte deriva del
 *     último domingo concluido, no del capricho de quien lo invoca.
 */

declare(strict_types=1);

namespace Grimorio\Controllers;

use Grimorio\Core\Request;
use Grimorio\Core\Response;
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
}
