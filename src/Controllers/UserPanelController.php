<?php

/**
 * UserPanelController.php — Endpoints REST del Panel del Adepto
 * (SPEC-12, Tareas 1.4 y 1.5 de TASKS-12).
 *
 * En esta entrega viven la vitrina y la guardia central de retención:
 *
 *   GET /api/v1/panel       — la vitrina de la identidad íntegra
 *                             (RF-01, RF-02, RF-07; plan §2.2).
 *   POST /api/v1/panel/avatar    — alta/elección de efigie (Tarea 1.5).
 *   DELETE /api/v1/panel/avatar  — retiro al canónico (Tarea 1.5).
 *
 * Las escrituras de avatar aceptan aquí la guardia CENTRAL de retención
 * (Tarea 1.5): si el vinculado es peregrino, toda escritura responde
 * 403 LINEAGE_OATH_REQUIRED — la retención refrendada por el backend,
 * no solo pintada por el frontend (RNF-06, hallazgo 12 del QA). El
 * ritual completo de la efigie (catálogo, validación, atómica) llega en
 * la FASE 2 sobre estas mismas puertas.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response nativos, PDO del
 *     repositorio; cero dependencias externas.
 *   - Artículo V (Dualidad): códigos técnicos en inglés, leyendas
 *     solemnes en noble castellano; jamás identificadores crudos
 *     impresos (RF-02.1).
 *   - RF-01.1 (privacidad estricta): toda lectura parte del titular de
 *     la sesión; jamás existe parámetro de identidad ajena.
 */

declare(strict_types=1);

namespace Grimorio\Controllers;

use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Dto\AvatarCatalogDto;
use Grimorio\Dto\UserPanelDto;
use Grimorio\Repositories\UserPanelRepository;
use Grimorio\Services\AvatarService;

/**
 * Controlador REST del Panel del Adepto.
 */
final class UserPanelController
{
    /** El canal de lecturas y la ÚNICA escritura de avatar. */
    private UserPanelRepository $panelRepository;

    /** El ciclo de vida de la efigie (Tarea 2.1); null solo en pruebas de la vitrina. */
    private ?AvatarService $avatarService;

    /** Instante «ahora» inyectable para la aritmética de la penitencia. */
    private ?\DateTimeImmutable $now;

    /** El custodio de la frase de paso (Tarea 3.4); null = cámara sin abrir. */
    private ?\Grimorio\Services\AuthService $authService;

    public function __construct(
        UserPanelRepository $panelRepository,
        ?AvatarService $avatarService = null,
        ?\DateTimeImmutable $now = null,
        ?\Grimorio\Services\AuthService $authService = null,
    ) {
        $this->panelRepository = $panelRepository;
        $this->avatarService = $avatarService;
        $this->now = $now;
        $this->authService = $authService;
    }

    // -----------------------------------------------------------------
    // La vitrina (RF-01, RF-02, RF-07; plan §2.2) — Tarea 1.4
    // -----------------------------------------------------------------

    /**
     * GET /api/v1/panel — la morada privada del vinculado.
     *
     * El peregrino CONTEMPLA su vitrina (200): la retención solo alcanza
     * a las escrituras sujetas al juramento (RF-01.3, Tarea 1.5). El
     * anónimo queda en el umbral (401, RF-01.2).
     *
     * Respuestas: 200 con el sobre UserPanelDto; 401 UNAUTHENTICATED;
     * 500 PANEL_UNAVAILABLE sin trazas (caso límite 11, plan §2.8).
     */
    public function show(Request $request): Response
    {
        // ---- Guardia 1: el umbral del anónimo (RF-01.2) ---------------
        $adept = $this->requireAuthenticatedUser($request);
        if ($adept === null) {
            return $this->unauthenticatedResponse();
        }

        try {
            return $this->vitrinaFor($request, $adept);
        } catch (\Throwable $panelFailure) {
            // El velo arcano jamás levanta trazas internas (plan §2.8).
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'    => 'PANEL_UNAVAILABLE',
                    'message' => 'El panel no puede iluminarse en este instante: inténtalo de nuevo en breve.',
                ],
            ], 500);
        }
    }

    // -----------------------------------------------------------------
    // Las puertas de la efigie con guard central (Tarea 1.5, RF-01.3)
    // -----------------------------------------------------------------

    /**
     * GET /api/v1/panel/avatars — el catálogo canónico (RF-03.1, plan
     * §2.3, Tarea 2.1).
     *
     * El peregrino LEE el catálogo (la lectura es parte de la lectura
     * pública de SPEC-09 RF-05.1) con `restricted:true`: su sección
     * declara la retención y conduce a la ceremonia; toda ESCRITURA le
     * responde 403 (hallazgo 12 del QA, Tarea 1.5).
     *
     * Respuestas: 200 con el sobre AvatarCatalogDto; 401 UNAUTHENTICATED;
     * 500 AVATAR_CATALOG_UNAVAILABLE sin trazas (caso límite 14).
     */
    public function avatarCatalog(Request $request): Response
    {
        // ---- Guardia 1: el umbral del anónimo (RF-01.2) ---------------
        $adept = $this->requireAuthenticatedUser($request);
        if ($adept === null) {
            return $this->unauthenticatedResponse();
        }

        if ($this->avatarService === null) {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'    => 'AVATAR_CATALOG_UNAVAILABLE',
                    'message' => 'El canon de efigies no puede contemplarse en este instante: inténtalo de nuevo en breve.',
                ],
            ], 500);
        }

        try {
            $state = $this->avatarService->catalogFor(
                $adept['id'],
                (string) $adept['role'],
                $adept['lineage'],
            );
        } catch (\Throwable $catalogFailure) {
            // El velo arcano jamás levanta trazas internas (plan §2.8,
            // caso límite 14: aviso solemne con reintento, sin mutar el
            // avatar vigente ni dejar la identidad sin efigie).
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'    => 'AVATAR_CATALOG_UNAVAILABLE',
                    'message' => 'El canon de efigies no responde en este instante: inténtalo de nuevo en breve.',
                ],
            ], 500);
        }

        return Response::json([
            'success' => true,
            'data'    => new AvatarCatalogDto(
                catalog: $state['catalog'],
                current: $state['current'],
                ownAvatar: $state['ownAvatar'],
                restricted: $state['restricted'],
            ),
        ], 200);
    }

    /**
     * POST /api/v1/panel/avatar — alta/elección de efigie (RF-03.1,
     * RF-03.6; Tarea 2.1: modo `catalog`).
     *
     * La guardia de retención (Tarea 1.5) va ANTES de cualquier lectura
     * del cuerpo: el peregrino jamás recibe señal alguna sobre el canon.
     * El acto se consume con efecto inmediato y su asiento SOLO si hay
     * efecto real (RF-03.6); la re-elección de la vigente responde 200
     * `AVATAR_IDENTICAL` sin asiento ni mutación (caso límite 18).
     */
    public function chooseAvatar(Request $request): Response
    {
        // ---- Guardia 1: el umbral del anónimo (RF-01.2) ---------------
        $adept = $this->requireAuthenticatedUser($request);
        if ($adept === null) {
            return $this->unauthenticatedResponse();
        }

        // ---- Guardia 2: la retención de sustancia (RF-01.3, Tarea 1.5) -
        $restriction = $this->lineageRestrictionFor($adept);
        if ($restriction !== null) {
            return $restriction;
        }

        // ---- Guardia 3: el servicio de la efigie (Tarea 2.1) ----------
        if ($this->avatarService === null) {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'    => 'AVATAR_CATALOG_UNAVAILABLE',
                    'message' => 'El canon de efigies no puede contemplarse en este instante: inténtalo de nuevo en breve.',
                ],
            ], 500);
        }

        $payload = $request->getJsonBody() ?? [];

        // ---- Modo `own` (Tarea 2.2): multipart/form-data con el fichero -
        if (($payload['mode'] ?? ($_POST['mode'] ?? null)) === 'own') {
            return $this->uploadOwnAvatar($adept, $request);
        }

        // ---- Modo `catalog` (Tarea 2.1) --------------------------------
        if (($payload['mode'] ?? '') !== 'catalog' || !is_string($payload['avatarId'] ?? null)) {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'    => 'INVALID_AVATAR_REQUEST',
                    'message' => 'Ese envío no viste identidad alguna: elige una efigie del canon.',
                ],
            ], 400);
        }

        try {
            $result = $this->avatarService->chooseFromCatalog(
                $adept['id'],
                (string) $payload['avatarId'],
                $this->nowUtc(),
            );
        } catch (\Grimorio\Exceptions\AvatarIdenticalException $identical) {
            // Caso límite 18 (RF-03.6): el aviso noble específico, sin
            // asiento y sin mutación.
            return Response::json($identical->toPayload(), 400);
        } catch (\InvalidArgumentException $unknownAvatar) {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'    => 'AVATAR_NOT_IN_CATALOG',
                    'message' => 'Esa efigie no pertenece al canon del santuario.',
                ],
            ], 400);
        } catch (\Throwable $storeFailure) {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'    => 'AVATAR_STORE_FAILED',
                    'message' => 'La efigie no pudo vestirse en este instante: tu identidad conserva su efigie anterior.',
                ],
            ], 500);
        }

        $isIdentical = $result['verdict'] === AvatarService::VERDICT_IDENTICAL;

        return Response::json([
            'success' => true,
            'data'    => [
                'avatar' => $result['avatar'],
                'auditRecorded' => !$isIdentical,
                'identical' => $isIdentical,
            ],
        ], 200);
    }

    /**
     * El modo `own` del alta (RF-03.2, Tarea 2.2; plan §2.4): multipart
     * con el fichero. Traduce las tres familias de rechazo a sus códigos
     * que NOMBRAN el motivo, el 413 del exceso de petición y el 500 sin
     * mutación del vigente (aceptación atómica, caso límite 16).
     */
    private function uploadOwnAvatar(array $adept, Request $request): Response
    {
        $uploaded = $_FILES['image'] ?? null;
        if (
            !is_array($uploaded)
            || !is_string($uploaded['tmp_name'] ?? null)
            || ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_file($uploaded['tmp_name'])
        ) {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'    => 'INVALID_AVATAR_REQUEST',
                    'message' => 'Ninguna imagen llegó al santuario: envía tu efigie con el envío.',
                ],
            ], 400);
        }

        // El formato lo declara la extensión del fichero recibido; la
        // validación profunda (decodificación GD) la hace el servicio.
        $declaredExtension = strtolower(pathinfo((string) ($uploaded['name'] ?? ''), PATHINFO_EXTENSION));

        try {
            $result = $this->avatarService->uploadOwn(
                $adept['id'],
                (string) $uploaded['tmp_name'],
                $declaredExtension,
                $this->nowUtc(),
            );
        } catch (\Grimorio\Exceptions\AvatarInvalidFormatException $formatVeto) {
            return Response::json($formatVeto->toPayload(), 400);
        } catch (\Grimorio\Exceptions\AvatarTooLargeException $weightVeto) {
            // El peso de la PETICIÓN excesivo viaja como 413 (plan §2.4);
            // el del FICHERO dentro del tope de petición, como 400 con su
            // código que nombra el motivo.
            $code = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 4 * 1024 * 1024 ? 413 : 400;

            return Response::json($weightVeto->toPayload(), $code);
        } catch (\Grimorio\Exceptions\AvatarDimensionsExceededException $sidesVeto) {
            return Response::json($sidesVeto->toPayload(), 400);
        } catch (\Grimorio\Exceptions\AvatarIdenticalException $identical) {
            return Response::json($identical->toPayload(), 400);
        } catch (\Throwable $storeFailure) {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'    => 'AVATAR_STORE_FAILED',
                    'message' => 'La efigie no pudo vestirse en este instante: tu identidad conserva su efigie anterior.',
                ],
            ], 500);
        }

        $isIdentical = $result['verdict'] === AvatarService::VERDICT_IDENTICAL;

        return Response::json([
            'success' => true,
            'data'    => [
                'avatar' => $result['avatar'],
                'auditRecorded' => !$isIdentical,
                'identical' => $isIdentical,
            ],
        ], 200);
    }

    /**
     * DELETE /api/v1/panel/avatar — retiro al canónico (RF-03.4,
     * RF-03.5; Tarea 2.1: retiro de la efigie propia con borrado).
     *
     * Misma guardia central que el alta (Tarea 1.5): el peregrino jamás
     * escribe identidad (RF-01.3). El retiro borra el fichero físico y
     * viste el avatar canónico por defecto: la identidad jamás queda
     * sin efigie (caso límite 5).
     */
    public function removeAvatar(Request $request): Response
    {
        $adept = $this->requireAuthenticatedUser($request);
        if ($adept === null) {
            return $this->unauthenticatedResponse();
        }

        $restriction = $this->lineageRestrictionFor($adept);
        if ($restriction !== null) {
            return $restriction;
        }

        if ($this->avatarService === null) {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'    => 'AVATAR_STORE_FAILED',
                    'message' => 'La efigie no pudo retirarse en este instante: inténtalo de nuevo en breve.',
                ],
            ], 500);
        }

        try {
            $result = $this->avatarService->removeOwn($adept['id'], $this->nowUtc());
        } catch (\Throwable $storeFailure) {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'    => 'AVATAR_STORE_FAILED',
                    'message' => 'La efigie no pudo retirarse en este instante: inténtalo de nuevo en breve.',
                ],
            ], 500);
        }

        $isIdentical = $result['verdict'] === AvatarService::VERDICT_IDENTICAL;

        return Response::json([
            'success' => true,
            'data'    => [
                'avatar' => $result['avatar'],
                'auditRecorded' => !$isIdentical,
                'identical' => $isIdentical,
            ],
        ], 200);
    }

    /** Instante canónico de ahora (inyectable en los arneses). */
    private function nowUtc(): string
    {
        return ($this->now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    // -----------------------------------------------------------------
    // La custodia de la frase de paso (RF-04; Tarea 3.4, plan §2.6)
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/panel/passphrase — La custodia (RF-04, cuatro salidas).
     *
     * Controlador puro: valida el umbral del anónimo (401 sin mutación
     * parcial — caso límite 2), lee el cuerpo JSON y delega la máquina
     * de estados completa en `AuthService::changePassphraseAuthenticated`
     * (Tareas 3.1–3.3). El cuerpo de la petición — con las frases en
     * claro — JAMÁS se registra en bitácora ni logs (plan §2.6); la
     * disolución de las demás sesiones y el asiento viven dentro de la
     * transacción del servicio (RNF-05, RF-04.2).
     *
     * Salidas (plan §2.6): 200 changed · 200 idempotentReceipt ·
     * 400 PASSPHRASE_CHANGE_FAILED (ciego) · 400 PASSPHRASE_IDENTICAL ·
     * 401 sin mutación parcial · 500 PANEL_UNAVAILABLE sin trazas.
     */
    public function changePassphrase(Request $request): Response
    {
        // ---- Guardia 1: el umbral del anónimo (RF-01.4, caso límite 2)
        // Sin vínculo vivo NO hay lectura del cuerpo NI examen del hash:
        // la petición muere antes de tocar el plano arcano.
        $adept = $this->requireAuthenticatedUser($request);
        if ($adept === null) {
            return $this->unauthenticatedResponse();
        }

        $sessionId = $request->getActiveSessionId();
        if ($sessionId === null || $sessionId === '') {
            return $this->unauthenticatedResponse();
        }

        $payload = $request->getJsonBody() ?? [];
        $currentPassphrase = isset($payload['currentPassphrase']) && is_string($payload['currentPassphrase'])
            ? $payload['currentPassphrase'] : '';
        $newPassphrase = isset($payload['newPassphrase']) && is_string($payload['newPassphrase'])
            ? $payload['newPassphrase'] : '';
        $newPassphraseRepeat = isset($payload['newPassphraseRepeat']) && is_string($payload['newPassphraseRepeat'])
            ? $payload['newPassphraseRepeat'] : '';

        // Guardia 2: la cámara de la custodia debe estar abierta (el
        // servicio inyectado por el front controller).
        if ($this->authService === null) {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'    => 'PANEL_UNAVAILABLE',
                    'message' => 'La custodia no pudo consumarse en este instante: inténtalo de nuevo en breve.',
                ],
            ], 500);
        }

        try {
            $result = $this->authService->changePassphraseAuthenticated(
                (string) $adept['id'],
                $sessionId,
                $currentPassphrase,
                $newPassphrase,
                $newPassphraseRepeat,
                $this->now,
            );
        } catch (\Grimorio\Exceptions\PassphraseIdenticalException $identical) {
            // Salida propia del contrato (caso límite 17): aviso noble
            // específico, sin asiento y sin mutación.
            return Response::json($identical->toPayload(), 400);
        } catch (\Grimorio\Exceptions\PassphraseChangeFailedException $blindFailure) {
            // El fallo ciego único (RF-04.1): una sola respuesta para
            // las tres causas, sin pistas del motivo.
            return Response::json($blindFailure->toPayload(), 400);
        } catch (\Throwable $custodyFailure) {
            // El velo arcano jamás levanta trazas internas (plan §2.8);
            // la transacción del servicio ya rueda atrás por su cuenta.
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'    => 'PANEL_UNAVAILABLE',
                    'message' => 'La custodia no pudo consumarse en este instante: inténtalo de nuevo en breve.',
                ],
            ], 500);
        }

        // Recibo del acto (200 changed / 200 idempotentReceipt).
        return Response::json(['success' => true, 'data' => $result], 200);
    }

    // -----------------------------------------------------------------
    // Piezas privadas compartidas (patrón del santuario)
    // -----------------------------------------------------------------

    /**
     * Compone y sirve la vitrina del adepto (RF-02, RF-07).
     *
     * @param array<string, mixed> $adept El titular de la sesión (id, alias, email, role, clanId, lineage).
     */
    private function vitrinaFor(Request $request, array $adept): Response
    {
        $userId = (string) $adept['id'];

        // --- Lecturas de vitrina (todas por el titular, RF-01.1) -------
        $vitals = $this->panelRepository->fetchUserVitals($userId);
        if ($vitals === null) {
            // El titular de la sesión viva no puede ser un fantasma: si
            // la cuenta purgó entre medio, la vitrina declara su velo.
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'    => 'PANEL_UNAVAILABLE',
                    'message' => 'El panel no puede iluminarse en este instante: inténtalo de nuevo en breve.',
                ],
            ], 500);
        }

        $membership = $this->panelRepository->fetchActiveMembership($userId);
        $clanRow = $membership !== null
            ? $this->panelRepository->fetchClanForVitrina((string) $membership['clan_id'])
            : null;
        $oathSwornAt = $this->panelRepository->fetchLineageOathSwornAt($userId);
        $masterDuties = $this->panelRepository->fetchMasterSignatureDuties($userId);
        $weeklyGlory = $membership !== null
            ? $this->panelRepository->fetchClanWeeklyGlory((string) $membership['clan_id'])
            : null;
        $sealedCount = $this->panelRepository->countSealedSpells($userId);
        $praiseCount = $this->panelRepository->countPraiseGiven($userId);

        // --- El vínculo de sesión vivo (RF-02.1) ------------------------
        $session = $request->getActiveSessionId() !== null
            ? $this->panelRepository->fetchSessionForVitrina($request->getActiveSessionId())
            : null;

        // --- La vitrina viste y narra (el DTO, jamás el controlador) ---
        // Tarea 2.5 (caso límite 15): si la referencia es propia y su
        // fichero resulta inaccesible, el DTO degrada al canónico con la
        // bandera discreta — la accesibilidad la dicta el servicio.
        $ownAvatarUnavailable = $this->avatarService?->isOwnAvatarFileAccessible($userId) === false;

        $panel = UserPanelDto::fromRows(
            vitalsRow: $vitals,
            membershipRow: $membership,
            clanRow: $clanRow,
            oathSwornAt: $oathSwornAt,
            masterDuties: $masterDuties,
            weeklyGloryRow: $weeklyGlory,
            sessionCreatedAt: is_array($session) ? (string) ($session['created_at'] ?? '') : '',
            sessionExpiresAt: is_array($session) ? (string) ($session['expires_at'] ?? '') : '',
            sessionDevice: is_array($session) ? (string) ($session['user_agent'] ?? '') : '',
            isCurrentSession: true,
            sealedCount: $sealedCount,
            praiseCount: $praiseCount,
            ownAvatarUnavailable: $ownAvatarUnavailable,
        );

        return Response::json([
            'success' => true,
            'data'    => ['panel' => $panel],
        ], 200);
    }

    /**
     * La guardia CENTRAL de retención del peregrino (Tarea 1.5, RF-01.3,
     * RNF-06): si el vinculado careciere de linaje jurado y no fuere el
     * Admin Supremo, toda ESCRITURA sujeta al juramento responde 403
     * LINEAGE_OATH_REQUIRED con el sobre canónico — la retención
     * refrendada por el backend, jamás solo pintada (hallazgo 12 del QA).
     *
     * Devuelve la Response del rechazo, o null si el vinculado puede
     * escribir (linajado o Supremo fundacional, RF-01.5).
     */
    private function lineageRestrictionFor(array $adept): ?Response
    {
        $lineage = $adept['lineage'] ?? null;
        $role = (string) ($adept['role'] ?? 'reader');
        if ($lineage !== null && $lineage !== '' || $role === 'supremeAdmin') {
            return null;
        }

        return Response::json([
            'success' => false,
            'error'   => [
                'code'    => 'LINEAGE_OATH_REQUIRED',
                'message' => 'Tu efigie aguarda al juramento: la ceremonia de linaje te espera antes de vestir identidad.',
            ],
        ], 403);
    }

    /** El titular de la sesión, o null si el vínculo no vive (RF-01.2). */
    private function requireAuthenticatedUser(Request $request): ?array
    {
        $user = $request->getUser();
        if ($user === null || $user->getId() === '') {
            return null;
        }

        return [
            'id'      => $user->getId(),
            'alias'   => $user->getAlias(),
            'email'   => $user->getEmail(),
            'role'    => $user->getRole(),
            'clanId'  => $user->getClanId(),
            'lineage' => $user->getLineage(),
        ];
    }

    /** Sobre 401 canónico del contrato SPEC-03 (vínculo no activo). */
    private function unauthenticatedResponse(): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => 'UNAUTHENTICATED',
                'message'        => 'El vínculo arcano no está activo: conságrate o vincula tu identidad para entrar en tu morada.',
                'recoveryAction' => 'BIND_OR_CONSECRATE',
            ],
        ], 401);
    }
}
