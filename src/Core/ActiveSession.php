<?php

/**
 * ActiveSession.php — Proyección inmutable de un vínculo activo.
 *
 * Tarea 2.1 (TASKS-03): valor de retorno de SessionManager::createSession()
 * y resolveSession(). El token crudo solo existe aquí durante el instante
 * de creación; después, únicamente su hash habita en la base de datos.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DTO PHP puro, sin framework.
 *   - Artículo V: identificadores en inglés camelCase, documentación en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Core;

/**
 * Vínculo activo resuelto o recién creado.
 */
final class ActiveSession
{
    /**
     * @param string $id      Identificador de la fila en user_sessions.
     * @param string $token   Token crudo (solo vive en este objeto y en la cookie).
     * @param string $userId  Titular del vínculo.
     */
    public function __construct(
        private readonly string $id,
        private readonly string $token,
        private readonly string $userId,
    ) {
    }

    /** Identificador de la sesión en base de datos. */
    public function getId(): string
    {
        return $this->id;
    }

    /** Token crudo del cliente (jamás se persiste en claro). */
    public function getToken(): string
    {
        return $this->token;
    }

    /** Identificador del titular del vínculo. */
    public function getUserId(): string
    {
        return $this->userId;
    }
}
