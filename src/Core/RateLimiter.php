<?php

/**
 * RateLimiter.php — Defensa anti-DoS y anti-fuerza bruta por procedencia.
 *
 * Tarea 2.2 (TASKS-03): registro de intentos en la tabla login_attempts
 * (Tarea 1.1) y congelación de la IP que acumule 5 fallos en una ventana
 * deslizante de 15 minutos (RF-03.2), calculando los segundos restantes
 * de bloqueo.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): consultas SQL nativas sobre PDO, sin
 *     Redis/Memcached ni paquetes de rate-limiting externos (plan 4,
 *     Decisión 2).
 *   - Artículo III: el bloqueo castiga a la PROCEDENCIA, jamás a la
 *     cuenta legítima: un atacante no puede usar el login para negar el
 *     servicio a un administrador o maestro.
 *   - Artículo V: identificadores en inglés camelCase, documentación en
 *     castellano.
 *
 * Semántica de la ventana (plan 3.3):
 *   - Solo computan intentos con is_success = 0 (los éxitos jamás
 *     castigan ni desbloquean).
 *   - La ventana es deslizante: un fallo deja de contar 15 minutos
 *     después de su marca temporal.
 *   - El bloqueo expira cuando el ÚLTIMO fallo sale de la ventana;
 *     remainingSeconds es la distancia exacta hasta ese instante.
 */

declare(strict_types=1);

namespace Grimorio\Core;

use DateTimeImmutable;
use PDO;

/**
 * Registro y consulta del estado de congelación por procedencia.
 */
final class RateLimiter
{
    /** Fallos consecutivos tolerados antes de congelar la procedencia (RF-03.2). */
    private const MAX_ALLOWED_FAILURES = 5;

    /** Duración de la ventana deslizante de castigo, en minutos. */
    private const WINDOW_MINUTES = 15;

    /** Conexión PDO al plano arcano (inyectada para pruebas). */
    private PDO $pdo;

    /**
     * Veredicto de la consulta de bloqueo por procedencia.
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Registra un intento de acceso (éxito o fallo) para la procedencia.
     * Los fallos alimentan el contador de la ventana; los éxitos quedan
     * para trazabilidad sin efecto sobre el bloqueo.
     */
    public function recordAttempt(string $ipAddress, string $attemptedIdentity, bool $isSuccess, ?DateTimeImmutable $now = null): void
    {
        $instant = $now ?? new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $statement = $this->pdo->prepare(
            'INSERT INTO login_attempts (ip_address, attempted_identity, attempted_at, is_success)
             VALUES (:ipAddress, :attemptedIdentity, :attemptedAt, :isSuccess)'
        );
        $statement->execute([
            ':ipAddress'         => $ipAddress,
            ':attemptedIdentity' => $attemptedIdentity,
            ':attemptedAt'       => $instant->format('Y-m-d\TH:i:s\Z'),
            ':isSuccess'         => $isSuccess ? 1 : 0,
        ]);
    }

    /**
     * Comprueba si la procedencia está congelada y cuánto le resta.
     *
     * El veredicto encapsula la respuesta del plan 3.3: isBlocked a
     * verdadero junto con los segundos restantes de bloqueo (0 si la
     * procedencia está libre).
     */
    public function isBlocked(string $ipAddress, ?DateTimeImmutable $now = null): RateLimitVerdict
    {
        $instant = $now ?? new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Conteo de fallos DENTRO de la ventana deslizante (plan 3.3).
        $windowStart = $instant->modify('-' . self::WINDOW_MINUTES . ' minutes');
        $countStatement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = :ipAddress
               AND is_success = 0
               AND attempted_at >= :windowStart'
        );
        $countStatement->execute([
            ':ipAddress'   => $ipAddress,
            ':windowStart' => $windowStart->format('Y-m-d\TH:i:s\Z'),
        ]);
        $recentFailures = (int) $countStatement->fetchColumn();

        // Bajo el umbral: procedencia libre.
        if ($recentFailures < self::MAX_ALLOWED_FAILURES) {
            return new RateLimitVerdict(isBlocked: false, remainingSeconds: 0);
        }

        // Congelada: el castigo expira 15 minutos después del ÚLTIMO fallo
        // de la ventana. Los éxitos no desbloquean: se filtran igualmente.
        $lastFailureStatement = $this->pdo->prepare(
            'SELECT MAX(attempted_at) FROM login_attempts
             WHERE ip_address = :ipAddress
               AND is_success = 0
               AND attempted_at >= :windowStart'
        );
        $lastFailureStatement->execute([
            ':ipAddress'   => $ipAddress,
            ':windowStart' => $windowStart->format('Y-m-d\TH:i:s\Z'),
        ]);
        $lastFailureAt = new DateTimeImmutable((string) $lastFailureStatement->fetchColumn());
        $lockExpiresAt = $lastFailureAt->modify('+' . self::WINDOW_MINUTES . ' minutes');

        $remainingSeconds = $lockExpiresAt->getTimestamp() - $instant->getTimestamp();
        if ($remainingSeconds <= 0) {
            // El último fallo ya salió de la ventana: procedencia libre.
            return new RateLimitVerdict(isBlocked: false, remainingSeconds: 0);
        }

        return new RateLimitVerdict(isBlocked: true, remainingSeconds: $remainingSeconds);
    }
}
