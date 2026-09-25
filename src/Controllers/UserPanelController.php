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
use Grimorio\Dto\UserPanelDto;
use Grimorio\Repositories\UserPanelRepository;

/**
 * Controlador REST del Panel del Adepto.
 */
final class UserPanelController
{
    /** El canal de lecturas y la ÚNICA escritura de avatar. */
    private UserPanelRepository $panelRepository;

    /** Instante «ahora» inyectable para la aritmética de la penitencia. */
    private ?\DateTimeImmutable $now;

    public function __construct(UserPanelRepository $panelRepository, ?\DateTimeImmutable $now = null)
    {
        $this->panelRepository = $panelRepository;
        $this->now = $now;
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
     * POST /api/v1/panel/avatar — alta/elección de efigie (puerta).
     *
     * En esta tarea responde SOLO la guardia de retención: el peregrino
     * recibe 403 LINEAGE_OATH_REQUIRED con el sobre canónico, sin
     * escritura alguna; el ritual completo de la efigie llega en la
     * FASE 2 (Tareas 2.1–2.4) sobre esta misma puerta.
     */
    public function chooseAvatar(Request $request): Response
    {
        // ---- Guardia 1: el umbral del anónimo (RF-01.2) ---------------
        $adept = $this->requireAuthenticatedUser($request);
        if ($adept === null) {
            return $this->unauthenticatedResponse();
        }

        // ---- Guardia 2: la retención de sustancia (RF-01.3) -----------
        $restriction = $this->lineageRestrictionFor($adept);
        if ($restriction !== null) {
            return $restriction;
        }

        return Response::json([
            'success' => true,
            'data'    => [
                'avatar' => ['kind' => 'default', 'reference' => null, 'isOwn' => false],
                'notice' => 'La cámara de la efigie aún no ha sido erigida: llega con la Fase 2 del panel.',
            ],
        ], 200);
    }

    /**
     * DELETE /api/v1/panel/avatar — retiro al canónico (puerta).
     *
     * Misma guardia central que el alta: el peregrino jamás escribe
     * identidad (RF-01.3); el retiro solemne llega en la Tarea 2.4.
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

        // La única escritura del repositorio refrenda el retiro al
        // canónico (RF-03.5): la identidad jamás queda sin efigie.
        $this->panelRepository->updateAvatar(
            $adept->getId(),
            null,
            ($this->now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z')
        );

        return Response::json([
            'success' => true,
            'data'    => [
                'avatar' => ['kind' => 'default', 'reference' => null, 'isOwn' => false],
            ],
        ], 200);
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
