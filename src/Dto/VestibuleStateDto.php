<?php

/**
 * VestibuleStateDto.php — El sobre único del Vestíbulo (SPEC-10, Tarea 3.1).
 *
 * Una sola carga del Endpoint 1 (plan §2.2, RNF-04) entrega al adepto el
 * estado íntegro de la ceremonia: su estado de adepto (linaje jurado, casa,
 * aptitud conjuntiva derivada del instante, veredictos sin contemplar), su
 * casa legada divergente cuando procede (excepción de RF-01.2), el catálogo
 * de casas de su linaje (RF-01.2: lo vedado no se exhibe) y su inventario
 * consolidado de peticiones (RF-03.8). La interfaz jamás ensambla: pinta el
 * sobre que el santuario sella.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): serialización JSON nativa, sin librerías.
 *   - Artículo IV (El Velo Arcano): el rótulo del linaje viaja en noble
 *     castellano.
 *   - Artículo V: claves técnicas en inglés camelCase, documentación en
 *     castellano.
 *
 * Contrato REST (plan §2.2, Endpoint 1 — clave `data`). Claves raíz
 * EXACTAMENTE: adeptState, myHouse, clans, petitions.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Estado íntegro del Vestíbulo servido en una sola carga (RNF-04).
 */
final readonly class VestibuleStateDto implements JsonSerializable
{
    /** Tope estricto de peticiones pendientes simultáneas (RF-01.5). */
    public const PENDING_PETITIONS_LIMIT = ClanApplicationDto::MAX_PENDING_APPLICATIONS;

    /**
     * Retrata el estado del Vestíbulo para un adepto.
     *
     * @param string|null           $lineage              Clave canónica del linaje jurado; `null` solo bajo el rótulo «Peregrino sin Linaje» (SPEC-09), que la retención jamás deja llegar aquí.
     * @param string|null           $lineageLabel         Rótulo castellano del linaje (noble castellano).
     * @param string|null           $membershipClanId     Casa del adepto con membresía vigente.
     * @param string|null           $membershipClanName   Nombre Canónico de la casa del adepto.
     * @param bool                  $isApt                Aptitud conjuntiva (RF-01.7): linajado + sin membresía + sin convalecencia (la vacante se juzga por casa).
     * @param string|null           $vedado               Causa solemne del vedado global: 'loyalty' | 'convalescence' | `null` (plan §3.1).
     * @param int                   $convalescenceDaysRemaining Días con alza al entero superior (plan §3.1); 0 si no media convalecencia.
     * @param int                   $pendingPetitionsCount    Peticiones pendientes del adepto.
     * @param int                   $pendingPetitionsLimit    Tope de pendientes (3, RF-01.5).
     * @param int                   $unreadVerdictsCount  Veredictos terminales sin contemplar (RF-03.4; rótulo del acceso, RF-01.1).
     * @param array<string,mixed>|null $myHouse           Casa legada divergente (excepción de RF-01.2): clanId, clanName, isLegacyDivergent, state.
     * @param list<VestibuleClanDto>   $clans             Catálogo de casas del linaje jurado, solo `active` (RF-01.2).
     * @param list<ClanPetitionDto>    $petitions         Inventario consolidado de peticiones propias (RF-03.8).
     *
     * @throws InvalidArgumentException Si la aptitud y su vedado contradicen el canon o el catálogo porta un tipo ajeno.
     */
    public function __construct(
        public ?string $lineage = null,
        public ?string $lineageLabel = null,
        public ?string $membershipClanId = null,
        public ?string $membershipClanName = null,
        public bool $isApt = false,
        public ?string $vedado = null,
        public int $convalescenceDaysRemaining = 0,
        public int $pendingPetitionsCount = 0,
        public int $pendingPetitionsLimit = self::PENDING_PETITIONS_LIMIT,
        public int $unreadVerdictsCount = 0,
        public ?array $myHouse = null,
        public array $clans = [],
        public array $petitions = [],
    ) {
        // La aptitud y su vedado son pareja indivisible (plan §3.1): el vedado
        // nombra la causa cuando la aptitud calla, y calla cuando procede.
        if ($this->isApt && $this->vedado !== null) {
            throw new InvalidArgumentException('Un adepto apto jamás porta causa de vedado.');
        }

        if (!$this->isApt && trim((string) $this->vedado) === '') {
            throw new InvalidArgumentException('La aptitud negada exige su causa solemne (RF-01.7): loyalty o convalescence.');
        }

        foreach ($this->clans as $clan) {
            if (!$clan instanceof VestibuleClanDto) {
                throw new InvalidArgumentException('El catálogo del Vestíbulo solo porta casas (VestibuleClanDto).');
            }
        }

        foreach ($this->petitions as $petition) {
            if (!$petition instanceof ClanPetitionDto) {
                throw new InvalidArgumentException('El inventario del Vestíbulo solo porta peticiones (ClanPetitionDto).');
            }
        }
    }

    /**
     * Serialización JSON nativa: el contrato del Endpoint 1 en su orden
     * canónico. `myHouse` aparece solo para el legado divergente (nulo
     * canónico, preservado por json_encode) y `adeptState` anida la aptitud
     * derivada del instante — jamás un flag persistente (RF-01.7).
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'adeptState' => [
                'lineage'    => $this->lineage,
                'lineageLabel' => $this->lineageLabel,
                'membership' => $this->membershipClanId !== null
                    ? ['clanId' => $this->membershipClanId, 'clanName' => $this->membershipClanName]
                    : null,
                'aptitude'   => [
                    'isApt'                      => $this->isApt,
                    'vedado'                     => $this->vedado,
                    'convalescenceDaysRemaining' => $this->convalescenceDaysRemaining,
                    'pendingPetitionsCount'      => $this->pendingPetitionsCount,
                    'pendingPetitionsLimit'      => $this->pendingPetitionsLimit,
                ],
                'unreadVerdictsCount' => $this->unreadVerdictsCount,
            ],
            'myHouse'   => $this->myHouse,
            'clans'     => $this->clans,
            'petitions' => $this->petitions,
        ];
    }
}
