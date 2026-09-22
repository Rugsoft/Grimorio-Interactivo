<?php

/**
 * AuditRecorderInterface.php — El contrato de pluma de la Bitácora
 * (SPEC-11, Tarea 2.2).
 *
 * La Colección del Adepto necesita asentar sus dos actos (RF-06) sin
 * acoplarse a la implementación concreta de la Bitácora: así los
 * arneses pueden doblar la pluma (registrar llamadas sin base de
 * datos) y el servicio real queda `final`, intocado.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro, sin jerarquías externas.
 *   - Artículo V (Dualidad): identificadores en inglés camelCase;
 *     documentación en noble castellano.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use DateTimeImmutable;
use Grimorio\Models\AuditEntry;

/**
 * Cualquier pluma que sepa asentar un acto solemne en la Bitácora.
 * `AuditService` la implementa de forma natural; el contrato NO
 * autoriza a implementarlas a la inversa (nadie dobla la Bitácora
 * salvo los arneses).
 */
interface AuditRecorderInterface
{
    /**
     * Registra un acto solemne de forma imborrable (RF-08.1).
     * Firma idéntica a `AuditService::recordAction()`: el asiento con
     * su actor, su acto canónico, su objetivo y su motivo solemne.
     */
    public function recordAction(
        string $actorUserId,
        string $actorAlias,
        string $actorRole,
        string $actionType,
        string $targetEntityType,
        string $targetEntityId,
        string $justification,
        ?DateTimeImmutable $now = null,
    ): AuditEntry;
}
