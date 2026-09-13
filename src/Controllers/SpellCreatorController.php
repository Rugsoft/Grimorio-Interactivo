<?php

/**
 * SpellCreatorController.php — Endpoints REST del Taller de Hechizos.
 *
 * Tarea 4.1 (TASKS-04): Endpoint 1 del plan, cálculo matemático
 * determinista en vivo (POST /api/v1/spells/calculate). Deserializa el
 * cuerpo JSON en SpellCalculationInputDto (coerción estricta anti-tipos
 * sucios), invoca a SpellBalanceService (Art. II: el desglose lo genera
 * SIEMPRE el motor del backend, jamás el cliente) y responde con el
 * desglose pedagógico del contrato, o con los sobres de error canónicos
 * del plan (ARCANE_OVERLOAD, INVALID_SPELL_INPUT).
 *
 * Este controlador crecerá con los endpoints de borradores (Tarea 4.2)
 * y de transiciones de estado (Tarea 4.3).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response del Core, JSON nativo,
 *     sin librerías HTTP ni validadores externos.
 *   - Artículo II (Ley Universal del Maná): cálculo 100% ciego en el
 *     backend; el payload del cliente solo porta parámetros, nunca costes.
 *   - Artículo V: identificadores en inglés camelCase, leyendas y
 *     documentación en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Controllers;

use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Dto\SpellCalculationInputDto;
use Grimorio\Dto\SpellCreateDto;
use Grimorio\Exceptions\ArcaneOverloadException;
use Grimorio\Exceptions\DraftQuotaExceededException;
use Grimorio\Exceptions\SpellImmutableException;
use Grimorio\Exceptions\SpellNotFoundException;
use Grimorio\Services\SpellBalanceService;
use Grimorio\Services\SpellManagementService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Controlador REST del Taller de Hechizos (creación, balanceo y
 * ciclo de vida de borradores).
 */
final class SpellCreatorController
{
    /** Motor matemático puro del maná (Art. II): única fuente del desglose. */
    private SpellBalanceService $balanceService;

    /** Servicio de ciclo de vida de borradores (Tarea 4.2). */
    private ?SpellManagementService $managementService;

    public function __construct(?SpellBalanceService $balanceService = null, ?SpellManagementService $managementService = null)
    {
        $this->balanceService     = $balanceService ?? new SpellBalanceService();
        $this->managementService  = $managementService;
    }

    /**
     * POST /api/v1/spells/calculate — Simulación y Desglose Pedagógico
     * de Maná (Endpoint 1 del plan).
     *
     * Cuerpo JSON: los 10 parámetros matemáticos (damage, healing,
     * barrier, crowdControlType, rangeType, areaType, durationType,
     * hasVerbal, hasSomatic, hasMaterial).
     *
     * Respuestas:
     *   - 200 OK:                success + data (desglose pedagógico íntegro).
     *   - 400 Bad Request:       ARCANE_OVERLOAD (maná > 200, con
     *                            calculatedMana) o INVALID_SPELL_INPUT
     *                            (JSON corrupto, campos ausentes, tipos
     *                            sucios o valores fuera del canon).
     *
     * RNF-01: la operación es pura y determinista (misma entrada → mismo
     * desglose), sin estado, sin I/O y con latencia muy inferior a 50 ms.
     */
    public function calculate(Request $request): Response
    {
        // El cuerpo crudo se valida ANTES de materializar el DTO: un
        // JSON corrupto o un payload escalar jamás alcanzan al motor.
        try {
            $inputDto = SpellCalculationInputDto::fromArray(
                $this->extractJsonPayload($request)
            );
        } catch (InvalidArgumentException $invalidInput) {
            return $this->invalidInputResponse($invalidInput->getMessage());
        }

        // El desglose lo determina el motor (Art. II): el controlador
        // solo traduce excepciones de dominio a sobres HTTP canónicos.
        try {
            $resultDto = $this->balanceService->calculate($inputDto);
        } catch (ArcaneOverloadException $overload) {
            return Response::json($overload->toPayload(), $overload->getHttpStatusCode());
        }

        return Response::json([
            'success' => true,
            'data'    => $resultDto,
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoints CRUD de borradores privados (Tarea 4.2, Endpoints 2-3).
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/spells/drafts — Guardar Borrador Privado (Endpoint 2).
     *
     * Exige sesión autenticada (401 UNAUTHENTICATED en su defecto).
     * Respuestas: 201 Created (ID y slug), 400 (payload inválido o nombre
     * duplicado), 403 DRAFT_QUOTA_EXCEEDED (RF-05.1) y 500 controlado
     * ante cualquier otra interrupción del servicio.
     */
    public function createDraft(Request $request): Response
    {
        $author = $this->requireAuthenticatedUser($request);
        if ($author === null) {
            return $this->unauthenticatedResponse();
        }

        try {
            $createDto = SpellCreateDto::fromArray(
                $this->extractJsonPayload($request)
            );
        } catch (InvalidArgumentException $invalidInput) {
            return $this->invalidInputResponse($invalidInput->getMessage());
        }

        try {
            $draft = $this->requireManagementService()->createDraft($author, $createDto);
        } catch (DraftQuotaExceededException $quota) {
            return Response::json($quota->toPayload(), $quota->getHttpStatusCode());
        } catch (RuntimeException $integrityViolation) {
            // Nombre canónico duplicado u otra violación del esquema:
            // el conflicto es responsabilidad del cliente (400).
            return $this->conflictResponse($integrityViolation->getMessage());
        }

        return Response::json([
            'success' => true,
            'data'    => $draft,
        ], 201);
    }

    /**
     * GET /api/v1/spells/drafts — Listar Borradores del Autor Activo
     * (Endpoint 3). Privacidad estricta: solo los del usuario autenticado.
     */
    public function listDrafts(Request $request): Response
    {
        $author = $this->requireAuthenticatedUser($request);
        if ($author === null) {
            return $this->unauthenticatedResponse();
        }

        return Response::json([
            'success' => true,
            'data'    => $this->requireManagementService()->listDrafts($author),
        ], 200);
    }

    /**
     * PUT /api/v1/spells/drafts/{id} — Actualizar borrador propio.
     *
     * Respuestas: 200 (maná recalculado), 400 (payload inválido),
     * 403 SPELL_IMMUTABLE (el id corresponde a un validado) y
     * 404 SPELL_NOT_FOUND (inexistente o ajeno).
     */
    public function updateDraft(Request $request, array $routeParams): Response
    {
        $author = $this->requireAuthenticatedUser($request);
        if ($author === null) {
            return $this->unauthenticatedResponse();
        }

        $spellId = (string) ($routeParams['id'] ?? '');
        if ($spellId === '') {
            return $this->notFoundResponse();
        }

        try {
            $createDto = SpellCreateDto::fromArray(
                $this->extractJsonPayload($request)
            );
        } catch (InvalidArgumentException $invalidInput) {
            return $this->invalidInputResponse($invalidInput->getMessage());
        }

        try {
            $draft = $this->requireManagementService()->updateDraft($author, $spellId, $createDto);
        } catch (SpellImmutableException $immutable) {
            return Response::json($immutable->toPayload(), $immutable->getHttpStatusCode());
        } catch (RuntimeException $notOwnedOrMissing) {
            return $this->notFoundResponse();
        }

        return Response::json([
            'success' => true,
            'data'    => $draft,
        ], 200);
    }

    /**
     * DELETE /api/v1/spells/drafts/{id} — Retirar borrador propio.
     *
     * Respuestas: 200 (retirado), 403 SPELL_IMMUTABLE (validado) y
     * 404 SPELL_NOT_FOUND (inexistente o ajeno).
     */
    public function deleteDraft(Request $request, array $routeParams): Response
    {
        $author = $this->requireAuthenticatedUser($request);
        if ($author === null) {
            return $this->unauthenticatedResponse();
        }

        $spellId = (string) ($routeParams['id'] ?? '');
        if ($spellId === '') {
            return $this->notFoundResponse();
        }

        try {
            $deleted = $this->requireManagementService()->deleteDraft($author, $spellId);
        } catch (SpellImmutableException $immutable) {
            return Response::json($immutable->toPayload(), $immutable->getHttpStatusCode());
        }

        if (!$deleted) {
            return $this->notFoundResponse();
        }

        return Response::json([
            'success' => true,
            'data'    => ['id' => $spellId, 'deleted' => true],
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoints de transición de estado y variantes (Tarea 4.3,
    // Endpoints 4, 5 y 6 del plan).
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/spells/publish/{id} — Publicación a Estado
     * Experimental (Endpoint 4).
     *
     * Respuestas: 200 OK (transición completada con firmas 0/3),
     * 400 Bad Request (el conjuro ya no está en borrador), 401
     * UNAUTHENTICATED y 404 SPELL_NOT_FOUND (inexistente o ajeno).
     */
    public function publishSpell(Request $request, array $routeParams): Response
    {
        $author = $this->requireAuthenticatedUser($request);
        if ($author === null) {
            return $this->unauthenticatedResponse();
        }

        $spellId = (string) ($routeParams['id'] ?? '');
        if ($spellId === '') {
            return $this->notFoundResponse();
        }

        try {
            $published = $this->requireManagementService()->publishToExperimental($author, $spellId);
        } catch (SpellNotFoundException $missingOrForeign) {
            return Response::json($missingOrForeign->toPayload(), $missingOrForeign->getHttpStatusCode());
        } catch (RuntimeException $notDraft) {
            // El conjuro existe pero ya no está en borrador: 400 del plan.
            return $this->invalidInputResponse($notDraft->getMessage());
        }

        return Response::json([
            'success' => true,
            'data'    => $published,
        ], 200);
    }

    /**
     * PUT /api/v1/spells/experimental/{id} — Edición de Hechizo
     * Experimental y Antifraude de Firmas (Endpoint 5).
     *
     * Respuestas: 200 OK (con signaturesReset si la matemática cambió),
     * 400 (payload inválido), 401 UNAUTHENTICATED, 403 SPELL_IMMUTABLE
     * (sobre el validado) y 404 SPELL_NOT_FOUND (inexistente o ajeno).
     */
    public function updateExperimental(Request $request, array $routeParams): Response
    {
        $author = $this->requireAuthenticatedUser($request);
        if ($author === null) {
            return $this->unauthenticatedResponse();
        }

        $spellId = (string) ($routeParams['id'] ?? '');
        if ($spellId === '') {
            return $this->notFoundResponse();
        }

        try {
            $createDto = SpellCreateDto::fromArray(
                $this->extractJsonPayload($request)
            );
        } catch (InvalidArgumentException $invalidInput) {
            return $this->invalidInputResponse($invalidInput->getMessage());
        }

        try {
            $updated = $this->requireManagementService()->updateExperimental($author, $spellId, $createDto);
        } catch (SpellImmutableException $immutable) {
            return Response::json($immutable->toPayload(), $immutable->getHttpStatusCode());
        } catch (SpellNotFoundException $missingOrForeign) {
            return Response::json($missingOrForeign->toPayload(), $missingOrForeign->getHttpStatusCode());
        }

        return Response::json([
            'success' => true,
            'data'    => $updated,
        ], 200);
    }

    /**
     * POST /api/v1/spells/variant/{id} — Clonación como Variante
     * independiente (Endpoint 6, RF-06.3).
     *
     * Respuestas: 201 Created (la variante nace draft con 0/3 firmas),
     * 400 (el origen no es un validado propio), 401 UNAUTHENTICATED y
     * 404 SPELL_NOT_FOUND (origen inexistente o ajeno).
     */
    public function createVariant(Request $request, array $routeParams): Response
    {
        $invoker = $this->requireAuthenticatedUser($request);
        if ($invoker === null) {
            return $this->unauthenticatedResponse();
        }

        $spellId = (string) ($routeParams['id'] ?? '');
        if ($spellId === '') {
            return $this->notFoundResponse();
        }

        try {
            $variant = $this->requireManagementService()->createVariant($invoker, $spellId);
        } catch (SpellNotFoundException $missingOrForeign) {
            return Response::json($missingOrForeign->toPayload(), $missingOrForeign->getHttpStatusCode());
        } catch (RuntimeException $notValidated) {
            // La ficha existe y es propia, pero no está validada: 400.
            return $this->conflictResponse($notValidated->getMessage());
        }

        return Response::json([
            'success' => true,
            'data'    => $variant,
        ], 201);
    }

    // -----------------------------------------------------------------
    // Ayudantes privados.
    // -----------------------------------------------------------------

    /**
     * Usuario activo de la sesión (inyectado por AuthMiddleware).
     */
    private function requireAuthenticatedUser(Request $request): ?\Grimorio\Models\User
    {
        return $request->getUser();
    }

    /**
     * Sobre 401 canónico del contrato SPEC-03.
     */
    private function unauthenticatedResponse(): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'    => 'UNAUTHENTICATED',
                'message' => 'El vínculo arcano no está activo: conságrate o vincula tu identidad antes de forjar conjuros.',
            ],
        ], 401);
    }

    /**
     * Sobre 404 canónico para conjuros inexistentes o ajenos.
     */
    private function notFoundResponse(): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => 'SPELL_NOT_FOUND',
                'message'        => 'Ese conjuro no existe o no habita tu grimorio.',
                'recoveryAction' => 'RETRY_WITH_VALID_ID',
            ],
        ], 404);
    }

    /**
     * Sobre 400 para conflictos de integridad (nombre duplicado, etc.).
     */
    private function conflictResponse(string $legend): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => 'SPELL_CONFLICT',
                'message'        => $legend,
                'recoveryAction' => 'RETRY_WITH_DIFFERENT_NAME',
            ],
        ], 400);
    }

    /**
     * Servicio de gestión exigido por las rutas de ciclo de vida.
     *
     * @throws RuntimeException Si el controlador se construyó sin él.
     */
    private function requireManagementService(): SpellManagementService
    {
        if ($this->managementService === null) {
            throw new RuntimeException('SpellCreatorController exige SpellManagementService para el ciclo de vida de borradores.');
        }

        return $this->managementService;
    }

    /**
     * Extrae y valida el cuerpo JSON de la petición.
     *
     * @return array<string, mixed> Payload asociativo del contrato.
     *
     * @throws InvalidArgumentException Si el cuerpo está vacío, no es
     *         JSON válido o no es un objeto con claves textuales.
     */
    private function extractJsonPayload(Request $request): array
    {
        $payload = $request->getJsonBody();

        if (!is_array($payload) || $payload === []) {
            throw new InvalidArgumentException(
                'El cuerpo de la petición debe ser un objeto JSON con los 10 parámetros del conjuro.'
            );
        }

        return $payload;
    }

    /**
     * Sobre 400 canónico para entradas fuera del contrato del Endpoint 1
     * (código INVALID_SPELL_INPUT, leyenda castellana de la causa y
     * acción de recuperación para el cliente).
     */
    private function invalidInputResponse(string $legend): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => 'INVALID_SPELL_INPUT',
                'message'        => $legend,
                'recoveryAction' => 'RETRY_WITH_VALID_PARAMS',
            ],
        ], 400);
    }
}
