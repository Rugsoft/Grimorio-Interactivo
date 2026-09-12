<?php

/**
 * AuditController.php — Endpoint REST público de la Bitácora de Auditoría.
 *
 * Tarea 3.4 (TASKS-03): traduce el contrato exacto del plan (sección 2.2,
 * Endpoint 6) para GET /api/v1/audit/log, conectando el mundo HTTP con
 * AuditService (Tarea 2.5): paginación (page, limit) y filtros por clan
 * objetivo (clanId), tipo de entidad (targetEntityType) y actuante
 * (actorUserId).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro; validación y saneado manual
 *     de query params, PDO nativo detrás del servicio.
 *   - Artículo III (Transparencia): la bitácora es PÚBLICA — cualquier
 *     visitante, anónimo o consagrado, puede consultarla sin credenciales,
 *     con los motivos solemnes de cada veredicto a la vista.
 *   - Artículo V: identificadores en inglés camelCase, documentación y
 *     leyendas en castellano.
 *
 * Seguridad:
 *   - Solo lectura: el controlador jamás expone canales de escritura;
 *     la bitácora solo admite INSERT (triggers del esquema, Tarea 2.5).
 *   - Blindaje de parámetros: numéricos estrictos, tope de límite y
 *     rechazo de arrays inyectados (400 controlado, sin fugas).
 */

declare(strict_types=1);

namespace Grimorio\Controllers;

use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Services\AuditService;

/**
 * Consulta pública y paginada de los veredictos arcana.
 */
final class AuditController
{
    /** Límite por defecto de la página (plan Endpoint 6: limit=25). */
    private const DEFAULT_LIMIT = 25;

    /** Servicio de bitácora (Tarea 2.5), único canal hacia audit_log. */
    private AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * GET /api/v1/audit/log — lista paginada de veredictos en orden
     * cronológico descendente (RF-08.2), accesible sin credenciales.
     *
     * Query params aceptados (plan 2.2, Endpoint 6):
     *   page (entero >= 1, defecto 1), limit (entero >= 1, defecto 25),
     *   clanId (linaje objetivo), targetEntityType ('spell'|'clan'|'user'),
     *   actorUserId (identidad del actuante).
     */
    public function log(Request $request): Response
    {
        // --- Validación y saneado de parámetros (400 ante basura) ---
        // Defensa contra inyección de arrays vía query string ('?page[]=1'):
        // getQueryParam() neutraliza arrays devolviendo null, de modo que
        // un parámetro array sobre un filtro con valor esperado se detecta
        // aquí comparando la cruda superglobal ya saneada del request.
        foreach (['page', 'limit', 'clanId', 'targetEntityType', 'actorUserId'] as $guardedParam) {
            if (isset($request->getQueryParams()[$guardedParam]) && is_array($request->getQueryParams()[$guardedParam])) {
                return $this->forgeBadRequest();
            }
        }

        $page = 1;
        $rawPage = $request->getQueryParam('page');
        if ($rawPage !== null && $rawPage !== '') {
            if (!ctype_digit($rawPage) || (int) $rawPage < 1) {
                return $this->forgeBadRequest();
            }
            $page = (int) $rawPage;
        }

        $limit = self::DEFAULT_LIMIT;
        $rawLimit = $request->getQueryParam('limit');
        if ($rawLimit !== null && $rawLimit !== '') {
            if (!ctype_digit($rawLimit) || (int) $rawLimit < 1) {
                return $this->forgeBadRequest();
            }
            // El servicio recorta al tope defensivo de 100 (anti-DoS).
            $limit = (int) $rawLimit;
        }

        // Filtros opcionales: claves textuales simples, sin arrays.
        $clanId = $request->getQueryParam('clanId');
        $targetEntityType = $request->getQueryParam('targetEntityType');
        $actorUserId = $request->getQueryParam('actorUserId');

        // El plan nombra el filtro por linaje como clanId: la bitácora
        // indexa sus objetivos por targetEntityId, de modo que un filtro
        // de clan se traduce a la combinación exacta (tipo 'clan', id dado).
        if ($clanId !== null && $clanId !== '') {
            $targetEntityType = 'clan';
            $actorUserId = $actorUserId !== null && $actorUserId !== '' ? $actorUserId : null;
            $pageResult = $this->auditService->fetchFilteredByEntityId($page, $limit, 'clan', $clanId);
        } else {
            $pageResult = $this->auditService->fetchLog(
                $page,
                $limit,
                $targetEntityType !== null && $targetEntityType !== '' ? $targetEntityType : null,
                $actorUserId !== null && $actorUserId !== '' ? $actorUserId : null,
            );
        }

        // Contrato exacto del plan (Endpoint 6): items + pagination.
        $items = [];
        foreach ($pageResult->items as $entry) {
            $items[] = $entry->toNormalizedArray();
        }

        return Response::json([
            'success' => true,
            'data'    => [
                'items'      => $items,
                'pagination' => $pageResult->pagination,
            ],
        ], 200);
    }

    /**
     * 400 canónico del proyecto (AGENTS.md 6.1) para parámetros basura.
     */
    private function forgeBadRequest(): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => 'INVALID_QUERY_PARAMS',
                'message'        => 'Los parámetros de consulta de la bitácora deben ser números enteros positivos.',
                'recoveryAction' => 'RETRY',
            ],
        ], 400);
    }
}
