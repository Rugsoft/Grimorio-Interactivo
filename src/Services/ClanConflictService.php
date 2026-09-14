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
 *   Regla 1: el Maestro no es el autor del conjuro (propia de SPEC-03).
 *   Reglas 2 y 3: el Maestro no milita ahora en el clan del conjuro ni lo
 *   habitó en los últimos 30 días. **Delegadas en ClanEthicsValidator**
 *   (Tarea 2.3, TASKS-07), cuya autoridad es el historial de membresía
 *   `clan_members` —el único que tiene escritor real—.
 *
 * Reconciliación de la afiliación (Tarea 2.3, TASKS-07): este servicio leía
 * antes `users.clan_id` para el clan actual y la tabla `clan_history` para el
 * historial; ambas vías resultaban inertes en producción, porque ninguna
 * clase de src/ escribía en `clan_history`. Hoy hay una sola autoridad y una
 * sola lógica de veto, de modo que el Artículo III muerde de verdad en lugar
 * de aprobar todo por falta de datos.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use DateTimeImmutable;
use Grimorio\Models\User;
use Grimorio\Repositories\ClanMemberRepository;
use PDO;

/**
 * Verificación de incompatibilidad histórica de linajes.
 */
final class ClanConflictService
{
    /** Conexión PDO al plano arcano. */
    private PDO $pdo;

    /** Veto ético sobre el historial de membresía (autoridad única). */
    private ClanEthicsValidator $ethicsValidator;

    /**
     * Veredicto de la comprobación de conflicto de intereses.
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ethicsValidator = new ClanEthicsValidator(new ClanMemberRepository($pdo));
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

        // Reglas 2 y 3 (plan 3.1): el linaje del conjuro no puede ser el
        // actual del Maestro ni uno que habitó en los últimos 30 días. La
        // lógica y la autoridad viven en ClanEthicsValidator (Tarea 2.3,
        // TASKS-07); aquí solo se traduce su veredicto al contrato de SPEC-03.
        $vetoReason = $this->ethicsValidator->vetoReasonFor($master->getId(), $spellClanId, $instant);
        if ($vetoReason !== null) {
            return new ClanConflictVerdict(isAllowed: false, reason: $vetoReason);
        }

        // Ninguna regla se activa: la firma solemne queda aprobada.
        return new ClanConflictVerdict(isAllowed: true, reason: '');
    }
}
