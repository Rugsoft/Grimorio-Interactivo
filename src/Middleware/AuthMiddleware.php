<?php

/**
 * AuthMiddleware.php — Autenticación e inyección de contexto de usuario.
 *
 * Tarea 3.1 (TASKS-03): lee la cookie de sesión del vínculo activo,
 * valida su vigencia contra user_sessions (vía SessionManager, Tarea 2.1)
 * e inyecta la entidad User activa en la petición. Sin vínculo válido,
 * inyecta un visitante anónimo con rol reader: NINGUNA petición avanza
 * sin usuario resuelto (RF-02.1, RF-02.2, RNF-04).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP nativo y PDO puro, sin framework.
 *   - Artículo III: la cuenta borrada (derecho al olvido, RF-09.1) degrada
 *     al anónimo de forma defensiva incluso si su sesión sobreviviera.
 *   - Artículo V: identificadores en inglés camelCase, documentación en
 *     castellano.
 *
 * Diseño:
 *   - injectContext() es el único punto de mutación de Request (setUser),
 *     invocado antes del despacho del router; después, la petición es
 *     solo-lectura para el resto de capas.
 *   - Un alias anónimo con id vacío evita colisiones con cuentas reales.
 */

declare(strict_types=1);

namespace Grimorio\Middleware;

use DateTimeImmutable;
use Grimorio\Core\Request;
use Grimorio\Core\SessionManager;
use Grimorio\Models\User;
use PDO;
use RuntimeException;

/**
 * Resolución del vínculo activo e inyección del usuario en la petición.
 */
final class AuthMiddleware
{
    /** Conexión PDO para materializar la entidad User del titular. */
    private PDO $pdo;

    /** Gestor del ciclo de vida de sesiones (Tarea 2.1). */
    private SessionManager $sessionManager;

    /**
     * Nombre de la cookie de sesión (coincidente con SessionManager).
     * Se duplica aquí porque PHP no expone la constante privada ajena.
     */
    private const COOKIE_NAME = 'grimorio_session';

    public function __construct(PDO $pdo, SessionManager $sessionManager)
    {
        $this->pdo = $pdo;
        $this->sessionManager = $sessionManager;
    }

    /**
     * Resuelve el vínculo activo de la petición e inyecta su usuario.
     *
     * @param Request                 $request Petición en curso (se muta una sola vez).
     * @param DateTimeImmutable|null $now     «Ahora» inyectable para verificación determinista.
     */
    public function injectContext(Request $request, ?DateTimeImmutable $now = null): void
    {
        $rawToken = $_COOKIE[self::COOKIE_NAME] ?? null;

        // Sin cookie: visitante anónimo con rol de lectura pública.
        if (!is_string($rawToken) || $rawToken === '') {
            $request->setUser($this->forgeAnonymousUser());
            return;
        }

        // Máquina de estados del vínculo (Tarea 2.1): válida, caducada por
        // inactividad o rebasada por el tope absoluto. En los dos últimos
        // casos el gestor purga la fila y devuelve null.
        $activeSession = $this->sessionManager->resolveSession($rawToken, $now);
        if ($activeSession === null) {
            $request->setUser($this->forgeAnonymousUser());
            return;
        }

        // Materialización del titular desde la base de datos.
        $userStatement = $this->pdo->prepare(
            'SELECT id, alias, email, password_hash, role, clan_id, lineage, created_at, updated_at
             FROM users WHERE id = :userId'
        );
        $userStatement->execute([':userId' => $activeSession->getUserId()]);
        $userRow = $userStatement->fetch(PDO::FETCH_ASSOC);

        // Defensa en profundidad (RF-09.1): sesión huérfana sin cuenta
        // titular (derecho al olvido) degrada al anónimo.
        if ($userRow === false) {
            $request->setUser($this->forgeAnonymousUser());
            return;
        }

        // Entidad User real e inmutable: el RbacMiddleware (Tarea 3.2)
        // consultará su rol y su clan desde aquí.
        $request->setUser(User::fromDatabaseRow($userRow));
    }

    /**
     * Entidad del visitante anónimo: rol reader, sin identificador de
     * cuenta ni clan (acceso público de lectura, RF-05.1).
     */
    private function forgeAnonymousUser(): User
    {
        return new User(
            id: '',
            alias: 'Visitante',
            email: '',
            role: 'reader',
            clanId: '',
            passwordHash: '',
            createdAt: '1970-01-01T00:00:00Z',
            updatedAt: '1970-01-01T00:00:00Z',
        );
    }
}
