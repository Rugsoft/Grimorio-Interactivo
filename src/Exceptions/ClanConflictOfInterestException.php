<?php

/**
 * ClanConflictOfInterestException.php — Veto constitucional por conflicto de
 * intereses entre linajes.
 *
 * Tarea 2.3 (TASKS-07): excepción que porta el rechazo solemne de
 * `ClanEthicsValidator` cuando un Maestro de la Torre pretende deliberar o
 * firmar un conjuro de su propio linaje o de uno que habitó en los últimos
 * treinta días naturales (RF-01.8, Artículo III).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): solo SPL nativo (`RuntimeException`); sin
 *     librerías ni jerarquías de excepciones importadas.
 *   - Artículo III (Incompatibilidad por Conflicto de Intereses): el veto es
 *     estricto e irrevocable; la excepción obliga al llamante a tratar el
 *     rechazo, no puede ignorarse como un mero valor de retorno falso.
 *   - Artículo IV (El Velo Arcano): el motivo viaja en noble castellano para
 *     que la interfaz lo muestre y la Bitácora lo inscriba sin traducción.
 *   - Artículo V: identificadores en inglés camelCase, motivo en castellano.
 *
 * El código de error (`errorCode()`) es el que el controlador REST vuelca en
 * el contrato de error del proyecto (`{ success: false, error: { code } }`)
 * al responder 403 Forbidden, en coherencia con la Tarea 3.2.
 */

declare(strict_types=1);

namespace Grimorio\Exceptions;

use RuntimeException;

/**
 * Rechazo solemne por incompatibilidad entre el linaje del Maestro y el del
 * conjuro sometido a su deliberación.
 */
final class ClanConflictOfInterestException extends RuntimeException
{
    /** Código canónico del rechazo en el contrato de error de la API REST. */
    public const ERROR_CODE = 'CLAN_CONFLICT_OF_INTEREST';

    /** Código de estado HTTP con el que el controlador ha de responder. */
    public const HTTP_STATUS = 403;

    /** Maestro de la Torre cuyo juicio queda vetado. */
    private string $masterUserId;

    /** Linaje del conjuro que origina la incompatibilidad. */
    private string $spellClanId;

    /** Motivo solemne del veto, en noble castellano. */
    private string $vetoReason;

    /**
     * Forja el rechazo con su motivo ceremonial.
     *
     * @param string $masterUserId Maestro cuyo juicio se veta.
     * @param string $spellClanId  Linaje del conjuro sometido a deliberación.
     * @param string $vetoReason   Motivo solemne, en noble castellano (Art. IV).
     */
    public function __construct(string $masterUserId, string $spellClanId, string $vetoReason)
    {
        $this->masterUserId = $masterUserId;
        $this->spellClanId = $spellClanId;
        $this->vetoReason = $vetoReason;

        // El mensaje nativo transporta el motivo solemne: así una traza
        // capturada en desarrollo nunca queda muda ante el veto.
        parent::__construct($vetoReason);
    }

    /** El Maestro cuyo juicio queda vetado. */
    public function masterUserId(): string
    {
        return $this->masterUserId;
    }

    /** El linaje del conjuro que motiva la incompatibilidad. */
    public function spellClanId(): string
    {
        return $this->spellClanId;
    }

    /** El motivo solemne del veto, listo para la Bitácora (RNF-04). */
    public function vetoReason(): string
    {
        return $this->vetoReason;
    }

    /** El código canónico del rechazo en el contrato de error de la API. */
    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }
}
