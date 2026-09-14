<?php

/**
 * ElementalMatrixController.php — Endpoints REST de la Matriz Elemental.
 *
 * Tarea 1.4 (TASKS-06): sirve el Códice de Afinidades Elementales (SPEC-06)
 * sobre ElementalMatrixService (Tareas 1.2 y 1.3), con los tres endpoints
 * del plan 2.1:
 *
 *   - GET  /api/v1/elements/matrix             → 200 (grafo del Códice).
 *   - GET  /api/v1/elements/reactions/{element} → 200 (aristas del glifo) | 404.
 *   - POST /api/v1/elements/resolve-combo      → 200 (veredicto) | 400.
 *
 * Constitución:
 *   - Artículo I: Request/Response/Router nativos del proyecto; cero
 *     librerías. La matriz es un dato en memoria: este controlador no
 *     toca base de datos.
 *   - Artículo II: jamás calcula factores; solo valida, sanea y delega
 *     en el servicio (fuente única de verdad del Códice).
 *   - AGENTS.md 6.1: errores en sobres JSON controlados, jamás trazas.
 *   - Artículo V: identificadores camelCase; leyendas en noble castellano.
 */

declare(strict_types=1);

namespace Grimorio\Controllers;

use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Dto\SpellImpactData;
use Grimorio\Services\ElementalMatrixService;
use InvalidArgumentException;

/**
 * Controlador REST del Códice de Afinidades Elementales.
 */
final class ElementalMatrixController
{
    /** Servicio del Códice: matriz inmutable y resolución autoritativa. */
    private ElementalMatrixService $matrixService;

    public function __construct(ElementalMatrixService $matrixService)
    {
        $this->matrixService = $matrixService;
    }

    /**
     * GET /api/v1/elements/matrix — Grafo íntegro del Códice (Endpoint 1).
     *
     * Respuesta 200: {success, data:{elements, reactions}} con los ocho
     * nodos elementales (id, name, color, glyph) y las ocho aristas
     * reactivas (7 duales + catalizador), tal cual el plan 2.1.
     */
    public function getMatrix(Request $request): Response
    {
        return Response::json([
            'success' => true,
            'data'    => $this->matrixService->getMatrixGraph(),
        ], 200);
    }

    /**
     * GET /api/v1/elements/reactions/{element} — Aristas reactivas de un
     * glifo (Endpoint 2, RF-01.2).
     *
     * Respuestas: 200 OK con la lista de fichas de reacción dual del
     * elemento, o 404 Not Found (ELEMENT_NOT_FOUND) si el identificador
     * no pertenece al canon de los ocho elementos.
     */
    public function getElementReactions(Request $request, array $routeParams): Response
    {
        $element = trim((string) ($routeParams['element'] ?? ''));

        // El canon de ocho elementos vive en el propio grafo del Códice.
        if ($this->matrixService->getMatrixGraph()->getElementById($element) === null) {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'           => 'ELEMENT_NOT_FOUND',
                    'message'        => 'Esa afinidad no pertenece al canon de los ocho elementos del santuario.',
                    'recoveryAction' => 'RETRY_WITH_CANONICAL_ELEMENT',
                ],
            ], 404);
        }

        return Response::json([
            'success' => true,
            'data'    => $this->matrixService->getReactionsForElement($element),
        ], 200);
    }

    /**
     * POST /api/v1/elements/resolve-combo — Resolución autoritativa del
     * impacto elemental (Endpoint 3, Tarea 1.3).
     *
     * Payload (plan 2.1): {activeAura, incomingSpell:{id, element,
     * baseDamage, baseHealing, baseBarrier, crowdControlType}, stunlockImmune}.
     *
     * Respuestas: 200 OK con el veredicto de once claves, o 400 Bad
     * Request (INVALID_REQUEST_BODY / INVALID_ACTIVE_AURA / INVALID_ELEMENT /
     * INVALID_SPELL_ID / INVALID_MAGNITUDE / INVALID_STUNLOCK_FLAG) con
     * sobre de error controlado.
     */
    public function resolveCombo(Request $request): Response
    {
        $payload = $request->getJsonBody();
        if ($payload === null) {
            return $this->badRequest('INVALID_REQUEST_BODY', 'El cuerpo de la petición debe ser un objeto JSON válido.');
        }

        // --- Saneado del aura activa (cadena vacía = blanco neutral) ---
        $activeAura = $payload['activeAura'] ?? '';
        if (!is_string($activeAura)) {
            return $this->badRequest('INVALID_ACTIVE_AURA', 'El aura activa debe ser una cadena (o vacía para blanco neutral).');
        }
        $activeAura = trim($activeAura);
        if ($activeAura !== '' && $this->matrixService->getMatrixGraph()->getElementById($activeAura) === null) {
            return $this->badRequest('INVALID_ACTIVE_AURA', 'El aura activa no pertenece al canon de los ocho elementos.');
        }

        // --- Saneado del banderín de inmunidad (por defecto, false) ---
        $stunlockImmune = $payload['stunlockImmune'] ?? false;
        if (!is_bool($stunlockImmune)) {
            return $this->badRequest('INVALID_STUNLOCK_FLAG', 'El banderín stunlockImmune debe ser un booleano.');
        }

        // --- Saneado del conjuro entrante (objeto anidado obligatorio) ---
        $incomingSpell = $payload['incomingSpell'] ?? null;
        if (!is_array($incomingSpell)) {
            return $this->badRequest('INVALID_REQUEST_BODY', 'El payload exige el objeto incomingSpell con el conjuro entrante.');
        }

        $spellId = $incomingSpell['id'] ?? null;
        if (!is_string($spellId) || trim($spellId) === '') {
            return $this->badRequest('INVALID_SPELL_ID', 'Todo conjuro entrante exige su identificador técnico.');
        }

        $spellElement = $incomingSpell['element'] ?? null;
        if (!is_string($spellElement) || trim($spellElement) === '') {
            return $this->badRequest('INVALID_ELEMENT', 'Todo conjuro entrante exige su afinidad elemental.');
        }
        $spellElement = trim($spellElement);
        if ($this->matrixService->getMatrixGraph()->getElementById($spellElement) === null) {
            return $this->badRequest('INVALID_ELEMENT', 'La afinidad del conjuro entrante no pertenece al canon de los ocho elementos.');
        }

        $baseDamage = $incomingSpell['baseDamage'] ?? 0;
        $baseHealing = $incomingSpell['baseHealing'] ?? 0;
        $baseBarrier = $incomingSpell['baseBarrier'] ?? 0;
        if (!is_int($baseDamage) || !is_int($baseHealing) || !is_int($baseBarrier)) {
            return $this->badRequest('INVALID_MAGNITUDE', 'Las magnitudes del conjuro deben ser enteros.');
        }
        if ($baseDamage < 0 || $baseHealing < 0 || $baseBarrier < 0) {
            return $this->badRequest('INVALID_MAGNITUDE', 'Las magnitudes del conjuro no admiten valores negativos.');
        }

        $crowdControlType = $incomingSpell['crowdControlType'] ?? 'none';
        if (!is_string($crowdControlType) || trim($crowdControlType) === '') {
            return $this->badRequest('INVALID_CROWD_CONTROL_TYPE', 'El tipo de control de masas debe ser una cadena no vacía (usa none).');
        }

        try {
            $verdict = $this->matrixService->resolveCombo(
                activeAura: $activeAura,
                incomingSpell: new SpellImpactData(
                    id: $spellId,
                    element: $spellElement,
                    baseDamage: $baseDamage,
                    baseHealing: $baseHealing,
                    baseBarrier: $baseBarrier,
                    crowdControlType: trim($crowdControlType),
                ),
                stunlockImmune: $stunlockImmune,
            );
        } catch (InvalidArgumentException) {
            // El DTO rechaza incoherencias de dominio: 400 controlado, jamás trazas.
            return $this->badRequest('INVALID_MAGNITUDE', 'El conjuro entrante viaja tácticamente incoherente.');
        }

        return Response::json([
            'success' => true,
            'data'    => $verdict,
        ], 200);
    }

    /**
     * Sobre de error 400 homogéneo con el contrato místico del proyecto.
     */
    private function badRequest(string $errorCode, string $message): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => $errorCode,
                'message'        => $message,
                'recoveryAction' => 'CORRECT_THE_PAYLOAD',
            ],
        ], 400);
    }
}
