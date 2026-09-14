<?php

/**
 * AuthController.php — Endpoints REST de autenticación, sesiones y recuperación.
 *
 * Tarea 3.3 (TASKS-03): traduce los contratos exactos del plan (sección
 * 2.2, Endpoints 1-5) a métodos del controlador, conectando el mundo
 * HTTP con AuthService (Tarea 2.3), SessionManager (Tarea 2.1) y
 * RateLimiter (Tarea 2.2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro; lectura de cuerpo JSON vía
 *     php://input nativo, sin librerías de deserialización.
 *   - Artículo III: la IP congelada no vincula ni con credenciales
 *     correctas; el restablecimiento revoca todas las sesiones previas.
 *   - Artículo IV (El Velo Arcano): todas las leyendas de error conservan
 *     la solemnidad de alta fantasía en castellano.
 *   - Artículo V: identificadores en inglés camelCase, leyendas en castellano.
 *
 * Seguridad:
 *   - Anti-enumeración (RF-01.3, RF-03.1, RF-04.1): 409 y 401 neutros,
 *     y la solicitud de recuperación responde 200 idéntico exista o no
 *     el correo; el token crudo jamás viaja por la API (va por correo,
 *     fuera de banda).
 *   - Cookies solo vía SessionManager (HttpOnly, SameSite=Strict).
 */

declare(strict_types=1);

namespace Grimorio\Controllers;

use DateTimeImmutable;
use Grimorio\Core\RateLimitVerdict;
use Grimorio\Core\RateLimiter;
use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Core\SessionManager;
use Grimorio\Models\User;
use Grimorio\Services\AuditService;
use Grimorio\Services\AuthService;
use PDO;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

/**
 * Controlador REST del ciclo de vida de las cuentas y sus vínculos.
 */
final class AuthController
{
    /** Conexión PDO para materializar usuarios (Endpoint 4) y limpiar sesiones. */
    private PDO $pdo;

    /** Servicio de autenticación (Tarea 2.3). */
    private AuthService $authService;

    /** Defensa anti-fuerza bruta por procedencia (Tarea 2.2, RF-03.2). */
    private RateLimiter $rateLimiter;

    /** Bitácora inmutable de auditoría (Tarea 2.5, RF-08.1). */
    private AuditService $auditService;

    public function __construct(PDO $pdo, SessionManager $sessionManager, RateLimiter $rateLimiter)
    {
        // El AuthService porta su propio SessionManager; este controlador
        // recibe ambos alineados sobre la misma conexión PDO.
        $this->pdo          = $pdo;
        $this->authService  = new AuthService($pdo, $sessionManager);
        $this->rateLimiter  = $rateLimiter;
        $this->auditService = new AuditService($pdo);
    }

    // -----------------------------------------------------------------
    // Endpoint 1: POST /api/v1/auth/consecrate (plan 2.2).
    // -----------------------------------------------------------------

    /**
     * Consagra un nuevo iniciado: 201 con contrato data.user, 400 ante
     * datos fuera del canon y 409 neutro si la identidad ya fue reclamada.
     */
    public function consecrate(Request $request): Response
    {
        $payload = $request->getJsonBody();
        if ($payload === null) {
            return $this->forgeBadRequest(
                'MALFORMED_JSON_BODY',
                'El pergamino de consagración no pudo ser leído: el cuerpo no es JSON válido.'
            );
        }

        $alias      = isset($payload['alias']) ? (string) $payload['alias'] : '';
        $email      = isset($payload['email']) ? (string) $payload['email'] : '';
        $passphrase = isset($payload['passphrase']) ? (string) $payload['passphrase'] : '';
        // El linaje electo es OPCIONAL (RF-01.2): un mago puede consagrarse sin
        // hermandad y fundar la suya más adelante.
        $clanId = isset($payload['clanId']) && trim((string) $payload['clanId']) !== ''
            ? (string) $payload['clanId']
            : null;

        if ($alias === '' || $email === '' || $passphrase === '') {
            return $this->forgeBadRequest(
                'INVALID_REGISTRATION_DATA',
                'La consagración exige alias, correo y frase de paso (el linaje es potestativo).'
            );
        }

        try {
            $consecration = $this->authService->consecrate($alias, $email, $passphrase, $clanId);
        } catch (InvalidArgumentException $invalidData) {
            return $this->forgeBadRequest('INVALID_REGISTRATION_DATA', $invalidData->getMessage());
        } catch (RuntimeException $alreadyClaimed) {
            // Anti-enumeración (RF-01.3): 409 neutro sin revelar qué campo choca.
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'           => 'IDENTITY_ALREADY_CLAIMED',
                    'message'        => 'Esa identidad ya fue reclamada por otro iniciado del santuario.',
                    'recoveryAction' => 'CHOOSE_IDENTITY',
                ],
            ], 409);
        }

        // El contrato del plan (Endpoint 1) declara clanId y clanName; ambos
        // viajan nulos para quien nace sin linaje.
        $clanName = $this->resolveClanName($clanId);

        // La consagración próspera vincula sesión automáticamente (RF-01.2).
        $this->authService->bind($email, $passphrase);

        return Response::json([
            'success' => true,
            'data'    => [
                'user' => [
                    'id'       => $consecration->userId,
                    'alias'    => $alias,
                    'role'     => 'editor',
                    'clanId'   => $clanId,
                    'clanName' => $clanName,
                ],
            ],
        ], 201);
    }

    // -----------------------------------------------------------------
    // Endpoint 2: POST /api/v1/auth/bind (plan 2.2).
    // -----------------------------------------------------------------

    /**
     * Renueva el vínculo (login): 200 con cookie segura y contrato
     * data.user, 401 neutro con credenciales erróneas y 429 cuando la
     * procedencia está congelada por fuerza bruta (RF-03.2).
     */
    public function bind(Request $request): Response
    {
        $clientIp = $request->getClientIp();

        // Primera muralla: la procedencia congelada ni siquiera consume
        // verificación BCRYPT (Art. III: defensa del servicio, no de la cuenta).
        $verdict = $this->rateLimiter->isBlocked($clientIp);
        if ($verdict->isBlocked) {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'            => 'RATE_LIMITED',
                    'message'         => 'El umbral permanecerá cerrado durante 15 minutos.',
                    'remainingSeconds' => $verdict->remainingSeconds,
                    'recoveryAction'  => 'WAIT',
                ],
            ], 429);
        }

        $payload = $request->getJsonBody();
        if ($payload === null) {
            return $this->forgeBadRequest(
                'MALFORMED_JSON_BODY',
                'El pergamino de vínculo no pudo ser leído: el cuerpo no es JSON válido.'
            );
        }

        $identity   = isset($payload['identity']) ? (string) $payload['identity'] : '';
        $passphrase = isset($payload['passphrase']) ? (string) $payload['passphrase'] : '';

        if ($identity === '' || $passphrase === '') {
            return $this->forgeBadRequest(
                'INVALID_BIND_DATA',
                'El vínculo exige identidad (alias o correo) y frase de paso.'
            );
        }

        $bindResult = $this->authService->bind($identity, $passphrase);

        // Registro del intento para la ventana deslizante (RF-03.2):
        // solo los fallos alimentan el castigo de la procedencia.
        $this->rateLimiter->recordAttempt($clientIp, $identity, $bindResult->success);

        if (!$bindResult->success || $bindResult->session === null) {
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'           => 'INVALID_CREDENTIALS',
                    'message'        => 'Las runas no reconocen este vínculo o la palabra secreta es errónea.',
                    'recoveryAction' => 'RETRY_OR_RECOVER',
                ],
            ], 401);
        }

        $userRow = $this->fetchUserRow($bindResult->userId);
        if ($userRow === null) {
            // Defensa en profundidad: vínculo sin titular materializable.
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'           => 'INVALID_CREDENTIALS',
                    'message'        => 'Las runas no reconocen este vínculo o la palabra secreta es errónea.',
                    'recoveryAction' => 'RETRY_OR_RECOVER',
                ],
            ], 401);
        }

        return Response::json([
            'success' => true,
            'data'    => [
                'user' => [
                    'id'       => $userRow['id'],
                    'alias'    => $userRow['alias'],
                    'role'     => $userRow['role'],
                    'clanId'   => $userRow['clan_id'],
                    'clanName' => $this->resolveClanName(
                        $userRow['clan_id'] === null ? null : (string) $userRow['clan_id']
                    ),
                ],
            ],
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoints 3: POST /api/v1/auth/dissolve y /dissolve-all (plan 2.2).
    // -----------------------------------------------------------------

    /**
     * Disuelve el vínculo del dispositivo actual (RF-02.4): revoca la
     * fila en base de datos y expira la cookie.
     */
    public function dissolve(Request $request): Response
    {
        $rawToken = $request->getCookie('grimorio_session');
        if ($rawToken === null) {
            return $this->forgeUnauthorized();
        }

        if (!$this->authService->dissolve($rawToken)) {
            return $this->forgeUnauthorized();
        }

        return Response::json([
            'success' => true,
            'data'    => [
                'message' => 'El vínculo ha sido disuelto en paz.',
            ],
        ], 200);
    }

    /**
     * Disuelve TODOS los vínculos del titular en todos sus dispositivos
     * (RF-02.4): revocación completa en base de datos.
     */
    public function dissolveAll(Request $request): Response
    {
        $rawToken = $request->getCookie('grimorio_session');
        if ($rawToken === null) {
            return $this->forgeUnauthorized();
        }

        if (!$this->authService->dissolveAll($rawToken)) {
            return $this->forgeUnauthorized();
        }

        return Response::json([
            'success' => true,
            'data'    => [
                'message' => 'Todos tus vínculos han sido disueltos en todos los reinos.',
            ],
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoint 4: GET /api/v1/auth/session (plan 2.2).
    // -----------------------------------------------------------------

    /**
     * Verificación de sesión activa: 200 en AMBOS caminos. El usuario
     * viene inyectado por el AuthMiddleware (Tarea 3.1), de modo que
     * anónimo significa data.authenticated false y user null.
     */
    public function session(Request $request): Response
    {
        $activeUser = $request->getUser();

        // Sin usuario inyectado (middleware no ejecutado) se trata como anónimo.
        if ($activeUser === null || $activeUser->getId() === '') {
            return Response::json([
                'success' => true,
                'data'    => [
                    'authenticated' => false,
                    'user'          => null,
                ],
            ], 200);
        }

        return Response::json([
            'success' => true,
            'data'    => [
                'authenticated' => true,
                'user'          => [
                    'id'       => $activeUser->getId(),
                    'alias'    => $activeUser->getAlias(),
                    'role'     => $activeUser->getRole(),
                    'clanId'   => $activeUser->getClanId(),
                    'clanName' => $this->resolveClanName($activeUser->getClanId()),
                ],
            ],
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoints 5: recuperación (plan 2.2, RF-04).
    // -----------------------------------------------------------------

    /**
     * Solicitud del Pergamino de Restablecimiento: 200 neutro e idéntico
     * exista o no el correo (anti-enumeración, RF-04.1). El token crudo
     * jamás viaja por la API: se remite por correo fuera de banda.
     */
    public function recoveryRequest(Request $request): Response
    {
        $payload = $request->getJsonBody();
        if ($payload === null) {
            return $this->forgeBadRequest(
                'MALFORMED_JSON_BODY',
                'La solicitud del pergamino no pudo ser leída: el cuerpo no es JSON válido.'
            );
        }

        $email = isset($payload['email']) ? (string) $payload['email'] : '';
        if ($email === '') {
            return $this->forgeBadRequest(
                'INVALID_RECOVERY_REQUEST',
                'La solicitud exige el correo del iniciado.'
            );
        }

        try {
            $this->authService->requestRecovery($email);
        } catch (Throwable) {
            // Correo malformado: neutro también (no se revela existencia).
        }

        return Response::json([
            'success' => true,
            'data'    => [
                'message' => 'Si ese correo habita el santuario, el pergamino de restablecimiento ha sido remitido.',
            ],
        ], 200);
    }

    /**
     * Restablece la frase de paso con el pergamino (RF-04.2): re-hashea
     * con BCRYPT 12, consume el token (un solo uso) y revoca todas las
     * sesiones previas de la cuenta.
     */
    public function recoveryReset(Request $request): Response
    {
        $payload = $request->getJsonBody();
        if ($payload === null) {
            return $this->forgeBadRequest(
                'MALFORMED_JSON_BODY',
                'El restablecimiento no pudo ser leído: el cuerpo no es JSON válido.'
            );
        }

        $token         = isset($payload['token']) ? (string) $payload['token'] : '';
        $newPassphrase = isset($payload['newPassphrase']) ? (string) $payload['newPassphrase'] : '';

        if ($token === '' || $newPassphrase === '') {
            return $this->forgeBadRequest(
                'INVALID_RECOVERY_RESET',
                'El restablecimiento exige el pergamino (token) y la nueva frase de paso.'
            );
        }

        try {
            $resetSucceeded = $this->authService->resetPassword($token, $newPassphrase);
        } catch (InvalidArgumentException $invalidPassphrase) {
            return $this->forgeBadRequest('INVALID_RECOVERY_RESET', $invalidPassphrase->getMessage());
        }

        if (!$resetSucceeded) {
            return $this->forgeBadRequest(
                'RECOVERY_TOKEN_INVALID',
                'El pergamino no es válido, ya fue consumido o su tinta se ha secado (caducó).'
            );
        }

        return Response::json([
            'success' => true,
            'data'    => [
                'message' => 'Tu nueva frase de paso ha sido sellada. Todos los vínculos previos fueron disueltos.',
            ],
        ], 200);
    }

    // -----------------------------------------------------------------
    // Endpoint de Renuncia al Vínculo (RF-09.1, cierre de SPEC-03).
    // -----------------------------------------------------------------

    /**
     * Renuncia al Vínculo (RF-09.1): derecho al olvido del titular de la
     * cookie portadora. La pila de producción (AuthMiddleware +
     * SessionManager) ya habría degradado a anónimo cualquier cookie
     * huérfana; como segunda muralla, este endpoint re-resuelve el
     * titular por la huella SHA-256 del token y rechaza con 401 si no
     * hay vínculo activo o la cuenta ya fue anonimizada. La renuncia
     * próspera queda registrada en la bitácora (ACC_LINK_RENOUNCED,
     * trazabilidad del Artículo III).
     */
    public function renounceAccount(Request $request): Response
    {
        $rawToken = $request->getCookie('grimorio_session');
        if ($rawToken === null) {
            return $this->forgeUnauthorized();
        }

        // Identidad del titular ANTES de la anonimización: la bitácora
        // debe registrar quién renunció, no el seudónimo posterior.
        $renouncer = $this->resolveSessionOwner($rawToken);
        if ($renouncer === null) {
            return $this->forgeUnauthorized();
        }

        if (!$this->authService->renounceAccount($rawToken)) {
            // Vínculo caducado entre la lectura y la escritura, o cuenta
            // ya anonimizada (doble renuncia): 409 explícito y no mutante.
            return Response::json([
                'success' => false,
                'error'   => [
                    'code'           => 'ACCOUNT_ALREADY_RENOUNCED',
                    'message'        => 'Esta cuenta ya habitó la renuncia: nada queda por disolver.',
                    'recoveryAction' => 'NONE',
                ],
            ], 409);
        }

        // Trazabilidad solemne (Art. III, RF-08.1): la renuncia queda
        // imborrable en la bitácora con la identidad previa a la purga.
        $this->auditService->recordAction(
            actorUserId: $renouncer['id'],
            actorAlias: $renouncer['alias'],
            actorRole: $renouncer['role'],
            actionType: 'ACC_LINK_RENOUNCED',
            targetEntityType: 'user',
            targetEntityId: $renouncer['id'],
            justification: 'Renuncia al Vínculo: el iniciado solicitó su derecho al olvido y su legado pasa al seudónimo «Erudito Ancestral».',
        );

        // Expiración de la cookie portadora: la sesión ya no existe en
        // BD; la cookie se marca caducada para el navegador vía el
        // SessionManager (expiración negativa). Sin ella, el vínculo
        // muerto viaja una última vez y el middleware degrada a anónimo.
        return Response::json([
            'success' => true,
            'data'    => [
                'message' => 'Tu vínculo ha sido renunciado en paz. Tus obras validadas habitarán el grimorio como legado del linaje (Erudito Ancestral).',
            ],
        ], 200);
    }

    // -----------------------------------------------------------------
    // Ayudantes privados.
    // -----------------------------------------------------------------

    /**
     * 400 canónico del proyecto (AGENTS.md 6.1).
     */
    private function forgeBadRequest(string $errorCode, string $legend): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => $errorCode,
                'message'        => $legend,
                'recoveryAction' => 'RETRY',
            ],
        ], 400);
    }

    /**
     * 401 neutro para operaciones de vínculo sin sesión portadora.
     */
    private function forgeUnauthorized(): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => 'NO_ACTIVE_SESSION',
                'message'        => 'No portas ningún vínculo activo que pueda ser disuelto.',
                'recoveryAction' => 'BIND_FIRST',
            ],
        ], 401);
    }

    /**
     * Resuelve el titular de un token de sesión por su huella SHA-256
     * (uso previo a mutaciones que invalidan el propio token).
     *
     * @return array{id: string, alias: string, role: string}|null
     */
    private function resolveSessionOwner(string $rawToken): ?array
    {
        $tokenHash = hash('sha256', $rawToken);
        $statement = $this->pdo->prepare(
            'SELECT u.id, u.alias, u.role
             FROM user_sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.session_token_hash = :tokenHash
             LIMIT 1'
        );
        $statement->execute([':tokenHash' => $tokenHash]);
        $ownerRow = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($ownerRow)
            ? ['id' => (string) $ownerRow['id'], 'alias' => (string) $ownerRow['alias'], 'role' => (string) $ownerRow['role']]
            : null;
    }

    /**
     * Materializa la fila del titular (consulta preparada, PDO nativo).
     *
     * @return array<string, mixed>|null
     */
    private function fetchUserRow(string $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, alias, email, role, clan_id, created_at, updated_at FROM users WHERE id = :userId'
        );
        $statement->execute([':userId' => $userId]);
        $userRow = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($userRow) ? $userRow : null;
    }

    /**
     * Nombre solemne del linaje para los contratos data.user (plan 2.2).
     */
    private function resolveClanName(?string $clanId): string
    {
        // Un mago sin hermandad (RF-01.2) no tiene nombre de linaje que
        // resolver: el contrato emite `clanId: null` y cadena vacía aquí.
        if ($clanId === null || trim($clanId) === '') {
            return '';
        }

        $statement = $this->pdo->prepare('SELECT name FROM clans WHERE id = :clanId');
        $statement->execute([':clanId' => $clanId]);
        $clanName = $statement->fetchColumn();

        return is_string($clanName) ? $clanName : '';
    }
}
