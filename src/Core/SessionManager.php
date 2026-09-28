<?php

/**
 * SessionManager.php — Gestor de sesiones nativas seguras del santuario.
 *
 * Tarea 2.1 (TASKS-03): ciclo de vida del vínculo activo respaldado en
 * la tabla user_sessions (Tarea 1.1), con cookies y funciones nativas
 * de PHP.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): sesiones y cookies nativas de PHP
 *     (setcookie, hash('sha256'), random_bytes), cero tokens JWT ni
 *     librerías externas.
 *   - Artículo V: identificadores en inglés camelCase, documentación en
 *     castellano.
 *
 * Seguridad (plan 4, Decisión 1):
 *   - El token de sesión jamás se persiste: solo su SHA-256.
 *   - La cookie es HttpOnly (inalcanzable por JavaScript), SameSite=Strict
 *     (blindaje CSRF) y Path=/.
 *   - Vigencia renovable de 14 días (RF-02.1/02.2) con tope absoluto e
 *     inmutable de 30 días (RF-02.2) que fuerza la reautenticación solemne.
 *   - Sesiones multidispositivo independientes (RF-02.3) y disolución
 *     individual (RF-02.4).
 */

declare(strict_types=1);

namespace Grimorio\Core;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Ciclo de vida del vínculo activo (sesión) respaldado en base de datos.
 */
final class SessionManager
{
    /** Nombre de la cookie de sesión del grimorio. */
    private const COOKIE_NAME = 'grimorio_session';

    /** Ventana renovable de inactividad: 14 días exactos (RF-02.1). */
    private const INACTIVITY_WINDOW_DAYS = 14;

    /** Tope absoluto e inmutable de vida: 30 días exactos (RF-02.2). */
    private const ABSOLUTE_LIFETIME_DAYS = 30;

    /** Longitud del token crudo en bytes (256 bits de entropía). */
    private const TOKEN_BYTES = 32;

    /** Conexión PDO al plano arcano (inyectada para pruebas). */
    private PDO $pdo;

    /** IP de la procedencia del cliente actual. */
    private string $ipAddress;

    /** User-Agent declarado por el cliente actual. */
    private string $userAgent;

    /**
     * @param PDO    $pdo       Conexión PDO (con user_sessions materializada).
     * @param string $ipAddress  Procedencia del cliente (IPv4/IPv6).
     * @param string $userAgent  Cliente declarado.
     */
    public function __construct(PDO $pdo, string $ipAddress = '0.0.0.0', string $userAgent = '')
    {
        $this->pdo = $pdo;
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
    }

    /**
     * Crea un nuevo vínculo activo para el iniciado: inserta la fila en
     * user_sessions, emite la cookie segura y devuelve la sesión con el
     * token crudo (que solo existe en este instante).
     *
     * La marca temporal «ahora» puede inyectarse para pruebas; por defecto
     * es el instante actual UTC.
     *
     * @throws RuntimeException Si la inserción del vínculo fracasa.
     */
    public function createSession(string $userId, ?DateTimeImmutable $now = null): ActiveSession
    {
        $instant = $now ?? new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Token crudo de 256 bits: viaja al cliente una única vez.
        $rawToken = bin2hex(random_bytes(self::TOKEN_BYTES));
        $tokenHash = hash('sha256', $rawToken);

        $expiresAt = $instant->modify('+' . self::INACTIVITY_WINDOW_DAYS . ' days');
        $absoluteExpiresAt = $instant->modify('+' . self::ABSOLUTE_LIFETIME_DAYS . ' days');

        $sessionId = 'ses_' . bin2hex(random_bytes(8));

        $statement = $this->pdo->prepare(
            'INSERT INTO user_sessions
                (id, session_token_hash, user_id, ip_address, user_agent, created_at, last_activity_at, expires_at, absolute_expires_at)
             VALUES
                (:id, :tokenHash, :userId, :ipAddress, :userAgent, :createdAt, :lastActivityAt, :expiresAt, :absoluteExpiresAt)'
        );
        $inserted = $statement->execute([
            ':id'                => $sessionId,
            ':tokenHash'         => $tokenHash,
            ':userId'            => $userId,
            ':ipAddress'         => $this->ipAddress,
            ':userAgent'         => $this->userAgent,
            ':createdAt'         => $instant->format('Y-m-d\TH:i:s\Z'),
            ':lastActivityAt'    => $instant->format('Y-m-d\TH:i:s\Z'),
            ':expiresAt'         => $expiresAt->format('Y-m-d\TH:i:s\Z'),
            ':absoluteExpiresAt' => $absoluteExpiresAt->format('Y-m-d\TH:i:s\Z'),
        ]);

        if (!$inserted) {
            throw new RuntimeException('El vínculo arcano no pudo inscribirse en el registro de sesiones.');
        }

        $this->emitSecureCookie($rawToken, $expiresAt);

        return new ActiveSession($sessionId, $rawToken, $userId);
    }

    /**
     * Resuelve el token crudo del cliente contra la tabla user_sessions
     * aplicando la máquina de estados del ciclo de vida (plan 3.2):
     * expiración por inactividad (14 días sin gestos) y tope absoluto
     * (30 días de vida total). Al renovar, avanza last_activity_at y
     * expires_at (sin rebasar jamás el tope absoluto).
     *
     * Devuelve null si el vínculo es inexistente, caducado o revocado;
     * en los casos de caducidad la fila se elimina (revocación real).
     */
    public function resolveSession(string $rawToken, ?DateTimeImmutable $now = null): ?ActiveSession
    {
        $instant = $now ?? new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $tokenHash = hash('sha256', $rawToken);

        $statement = $this->pdo->prepare(
            'SELECT id, user_id, created_at, expires_at, absolute_expires_at
             FROM user_sessions
             WHERE session_token_hash = :tokenHash'
        );
        $statement->execute([':tokenHash' => $tokenHash]);
        $sessionRow = $statement->fetch(PDO::FETCH_ASSOC);

        if ($sessionRow === false) {
            return null;
        }

        $createdAt = new DateTimeImmutable((string) $sessionRow['created_at']);
        $expiresAt = new DateTimeImmutable((string) $sessionRow['expires_at']);
        $absoluteExpiresAt = new DateTimeImmutable((string) $sessionRow['absolute_expires_at']);
        $sessionId = (string) $sessionRow['id'];
        $userId = (string) $sessionRow['user_id'];

        // Estado AbsoluteExpired: la vida total rebasa el tope inmutable
        // de 30 días → revocación inmediata y reautenticación solemne.
        if ($instant->getTimestamp() > $absoluteExpiresAt->getTimestamp()) {
            $this->purgeSessionById($sessionId);
            return null;
        }

        // Estado InactivityExpired: más de 14 días sin actividad → el
        // vínculo se disuelve y se exige renovar el vínculo (login).
        if ($instant->getTimestamp() > $expiresAt->getTimestamp()) {
            $this->purgeSessionById($sessionId);
            return null;
        }

        // Estado Valid: renovación continua (RF-02.2). La nueva ventana
        // avanza desde el último gesto pero jamás cruza el tope absoluto.
        $renewedExpires = $instant->modify('+' . self::INACTIVITY_WINDOW_DAYS . ' days');
        if ($renewedExpires->getTimestamp() > $absoluteExpiresAt->getTimestamp()) {
            $renewedExpires = $absoluteExpiresAt;
        }

        $renewal = $this->pdo->prepare(
            'UPDATE user_sessions SET last_activity_at = :lastActivityAt, expires_at = :expiresAt WHERE id = :id'
        );
        $renewal->execute([
            ':lastActivityAt' => $instant->format('Y-m-d\TH:i:s\Z'),
            ':expiresAt'      => $renewedExpires->format('Y-m-d\TH:i:s\Z'),
            ':id'             => $sessionId,
        ]);

        return new ActiveSession($sessionId, $rawToken, $userId);
    }

    /**
     * Disuelve el vínculo del dispositivo actual (RF-02.4): elimina la
     * fila de user_sessions y expira la cookie en el navegador.
     */
    public function dissolveSession(string $rawToken): bool
    {
        $tokenHash = hash('sha256', $rawToken);
        $statement = $this->pdo->prepare('DELETE FROM user_sessions WHERE session_token_hash = :tokenHash');
        $statement->execute([':tokenHash' => $tokenHash]);

        $dissolved = $statement->rowCount() > 0;
        if ($dissolved) {
            $this->expireCookie();
        }

        return $dissolved;
    }

    /**
     * Expira la cookie portadora de la petición actual en el navegador
     * (Tarea 3.2 de TASKS-15, SPEC-15 RF-04.1/RF-04.3): operación acotada
     * para que las revocaciones GLOBALES (disolución de todos los vínculos,
     * renuncia) cierren la cookie por el MISMO canal seguro que la emisión,
     * sin duplicar la política de atributos fuera del gestor.
     *
     * El plan §3.4 fija el orden: la expiración solo procede DESPUÉS de una
     * revocación exitosa en base de datos; una revocación fallida jamás
     * llega a invocarla (el controlador la llama tras confirmar el éxito).
     *
     * @return void Idempotente: caducar una cookie ausente no es error.
     */
    public function expireSessionCookie(): void
    {
        $this->expireCookie();
    }

    /**
     * Retiene la ruta pedida por el peregrino en el VÍNCULO activo
     * (SPEC-09, RF-05.3; enmienda de la Tarea 9.2 de SPEC-11).
     *
     * La ruta vive en `user_sessions.retained_route`, no en `$_SESSION`:
     * el santuario jamás invoca `session_start()`, así que `$_SESSION` era
     * un array por petición y la ruta moría al terminar la petición que la
     * escribía. Aquí sobrevive al salto entre peticiones y caduca con el
     * vínculo (la purga de sesiones la arrastra con la fila).
     *
     * @param string $sessionId Identificador del vínculo activo.
     * @param string $route     Ruta interna ya saneada por la guardia.
     */
    public function retainRoute(string $sessionId, string $route): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE user_sessions SET retained_route = :route WHERE id = :id'
        );
        $statement->execute([':route' => $route, ':id' => $sessionId]);
    }

    /**
     * Consume la ruta retenida del vínculo (una sola ceremonia, un solo
     * retorno): la devuelve y la deja en NULL en el mismo acto.
     *
     * @param string $sessionId Identificador del vínculo activo.
     * @return string|null La ruta retenida, o null si no había ninguna.
     */
    public function pullRetainedRoute(string $sessionId): ?string
    {
        $reading = $this->pdo->prepare('SELECT retained_route FROM user_sessions WHERE id = :id');
        $reading->execute([':id' => $sessionId]);
        $retained = $reading->fetchColumn();
        if (!is_string($retained) || $retained === '') {
            return null;
        }

        $consuming = $this->pdo->prepare(
            'UPDATE user_sessions SET retained_route = NULL WHERE id = :id'
        );
        $consuming->execute([':id' => $sessionId]);

        return $retained;
    }

    /**
     * Elimina físicamente una sesión por su identificador.
     */
    private function purgeSessionById(string $sessionId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM user_sessions WHERE id = :id');
        $statement->execute([':id' => $sessionId]);
    }

    /**
     * Forja las opciones comunes de emisión y expiración de la cookie
     * (Tarea 3.1 de TASKS-15, SPEC-15 RF-01/RF-04.3): UN solo punto de
     * política para que el alcance de la cookie emitida y el de la
     * expirada jamás diverjan. Ambas llamadas nativas de cookie del gestor
     * (emisión y borrado) beben exclusivamente de este forjador.
     *
     * La bandera `Secure` se determina EXCLUSIVAMENTE por:
     *   1. Señal DIRECTA de servidor: `$_SERVER['HTTPS']` (Apache la
     *      rellena bajo TLS; en la topología verificada de InfinityFree
     *      es la señal canónica, Tarea 0.2 de TASKS-15).
     *   2. Refuerzo explícito de despliegue: `GRIMORIO_COOKIE_SECURE=true`
     *      (canal env.php de la Tarea 2.3), para topologías con offload
     *      que ocultara la señal. La constante JAMÁS se define a `false`
     *      en la configuración de producción aprobada (PLAN-15 §4).
     *
     * Ninguna cabecera controlable por el cliente (protocolo reenviado,
     * visitante de CDN, ...) participa JAMÁS en la decisión (RF-01.3,
     * §8 caso 2): este método no consulta cabeceras, luego un cliente no
     * puede activar ni degradar `Secure`.
     *
     * Sin atributo `Domain` (PLAN-15 §9.5): presente solo si un requisito
     * ratificado lo exigiera, y entonces idéntico al emitir y expirar.
     *
     * @return array<string, int|bool|string> Opciones nativas para la emisión de cookie.
     */
    private function forgeCookieOptions(int $expiresAt): array
    {
        // Señal directa de servidor (no vacía y distinta de 'off').
        $isHttps = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off';

        // Refuerzo de despliegue: solo el valor true explícito fuerza
        // Secure; cualquier otra ausencia/tipo deja la señal natural.
        if (defined('GRIMORIO_COOKIE_SECURE') && GRIMORIO_COOKIE_SECURE === true) {
            $isHttps = true;
        }

        return [
            'expires'  => $expiresAt,
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Strict',
        ];
    }

    /**
     * Emite la cookie de sesión con la política única del forjador:
     * HttpOnly, SameSite=Strict, Path=/, caducidad a 14 días y `Secure`
     * según la señal de servidor o el refuerzo de despliegue (en desarrollo
     * local sobre http la cookie nace sin `Secure`: excepción ratificada,
     * RF-01.4, que jamás afecta a la configuración de producción).
     */
    private function emitSecureCookie(string $rawToken, DateTimeImmutable $expiresAt): void
    {
        setcookie(self::COOKIE_NAME, $rawToken, $this->forgeCookieOptions($expiresAt->getTimestamp()));
    }

    /**
     * Expira la cookie en el navegador al disolver el vínculo, con el
     * MISMO alcance que la emisión (RF-04.3: mismo nombre, Path=/,
     * HttpOnly, SameSite=Strict y Secure determinado por la misma política).
     */
    private function expireCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', $this->forgeCookieOptions(time() - 3600));
    }
}
