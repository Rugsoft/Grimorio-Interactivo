<?php

/**
 * ClanConflictService.php — Salvaguarda constitucional de conflicto de intereses.
 *
 * Tarea 2.4 (TASKS-03): un Maestro no puede emitir firmas solemnes sobre
 * conjuros propios, de su linaje actual ni de linajes que habitó en los
 * últimos 30 días (RF-06.1, RF-06.2, RF-07.3, Artículo III).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): consultas SQL nativas sobre PDO, sin
 *     capas externas.
 *   - Artículo III (Incompatibilidad por Conflicto de Intereses): doble
 *     barrera — la interfaz deshabilita el botón y este servicio rechaza
 *     de manera estricta e irreversible en el backend (RF-06.2).
 *   - Artículo V: identificadores en inglés camelCase, motivos solemnes
 *     y documentación en castellano noble.
 *
 * Algoritmo (plan 3.1):
 *   Regla 1: el Maestro no es el autor del conjuro.
 *   Regla 2: el clan actual del Maestro no es el del conjuro.
 *   Regla 3: el Maestro no habitó el clan del conjuro en los últimos
 *            30 días (clan_history, con left_at NULL como clan vivo).
 */

declare(strict_types=1);

namespace Grimorio\Services;

use DateTimeImmutable;
use Grimorio\Models\User;
use PDO;

/**
 * Verificación de incompatibilidad histórica de linajes.
 */
final class ClanConflictService
{
    /** Ventana histórica de incompatibilidad, en días (RF-06.1). */
    private const HISTORICAL_WINDOW_DAYS = 30;

    /** Conexión PDO al plano arcano. */
    private PDO $pdo;

    /**
     * Veredicto de la comprobación de conflicto de intereses.
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Comprueba si el Maestro puede firmar el conjuro según el algoritmo
     * del plan 3.1. El «ahora» es inyectable para verificación
     * determinista de la ventana de 30 días.
     *
     * @param User   $master       Entidad del Maestro que intenta firmar.
     * @param string $authorId     Identificador del autor del conjuro.
     * @param string $spellClanId  Linaje al que pertenece el conjuro.
     */
    public function canMasterSignSpell(User $master, string $authorId, string $spellClanId, ?DateTimeImmutable $now = null): ClanConflictVerdict
    {
        $instant = $now ?? new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Regla 1 (plan 3.1): un erudito no firma sus propias creaciones.
        if ($master->getId() === $authorId) {
            return new ClanConflictVerdict(
                isAllowed: false,
                reason: 'Un erudito no puede emitir firmas sobre sus propias creaciones.',
            );
        }

        // Regla 2 (plan 3.1): el vínculo de sangre nubla el juicio — el
        // linaje ACTUAL del Maestro coincide con el del conjuro.
        if ($master->getClanId() === $spellClanId) {
            return new ClanConflictVerdict(
                isAllowed: false,
                reason: 'El vínculo de sangre nubla el juicio: un Maestro no puede juzgar el trabajo de su propio linaje actual.',
            );
        }

        // Regla 3 (plan 3.1): incompatibilidad HISTÓRICA de 30 días.
        // Se consultan los linajes habitados por el Maestro cuyo abandono
        // sea nulo (clan aún activo en el historial) o posterior al inicio
        // de la ventana. El índice idx_user_clan_time (user_id, left_at)
        // sostiene esta consulta (Tarea 1.1).
        $windowStart = $instant->modify('-' . self::HISTORICAL_WINDOW_DAYS . ' days');
        $historyStatement = $this->pdo->prepare(
            'SELECT clan_id FROM clan_history
             WHERE user_id = :userId
               AND (left_at IS NULL OR left_at >= :windowStart)'
        );
        $historyStatement->execute([
            ':userId'      => $master->getId(),
            ':windowStart' => $windowStart->format('Y-m-d\TH:i:s\Z'),
        ]);

        foreach ($historyStatement->fetchAll(PDO::FETCH_COLUMN) as $historicalClanId) {
            if ((string) $historicalClanId === $spellClanId) {
                return new ClanConflictVerdict(
                    isAllowed: false,
                    reason: 'El Maestro ha pertenecido a este linaje en los últimos 30 días: firma vetada por incompatibilidad histórica.',
                );
            }
        }

        // Ninguna regla se activa: la firma solemne queda aprobada.
        return new ClanConflictVerdict(isAllowed: true, reason: '');
    }
}
