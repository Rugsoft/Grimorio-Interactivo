<?php

/**
 * SpellImpactData.php — Payload del impacto de conjuro (Endpoint 3, SPEC-06).
 *
 * Tarea 1.3 (TASKS-06): DTO inmutable que materializa el `SpellImpactData`
 * del plan 2.1 — el payload de entrada de `POST /api/v1/elements/resolve-combo`
 * y la forma de datos del dominio que consume `ElementalMatrixService::
 * resolveCombo()` para resolver el veredicto autoritativo del impacto.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DTO PHP puro, sin librerías.
 *   - Artículo II: este transporte NO calcula nada; los factores de
 *     reacción viven en el Códice de la matriz (ElementalMatrixService).
 *   - Artículo V: claves técnicas en inglés camelCase, documentación en
 *     castellano.
 *
 * Contrato (plan 2.1, Endpoint 3): id, element, baseDamage, baseHealing,
 * baseBarrier y crowdControlType — exactamente las seis claves del payload.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use InvalidArgumentException;

/**
 * Datos del conjuro que impacta sobre el blanco, en el instante del impacto.
 */
final readonly class SpellImpactData
{
    /**
     * Forja el impacto desde el payload decodificado del Endpoint 3.
     *
     * @param string $id Identificador técnico del conjuro (ej. 'spl_water_01').
     * @param string $element Afinidad elemental del conjuro (uno de los ocho del Códice).
     * @param int $baseDamage Daño base del conjuro, sin bonificaciones.
     * @param int $baseHealing Curación base del conjuro, sin bonificaciones.
     * @param int $baseBarrier Barrera base que alza el conjuro, sin bonificaciones.
     * @param string $crowdControlType Tipo de control de masas del conjuro ('none' si carece).
     *
     * @throws InvalidArgumentException Si el impacto viaja incoherente.
     */
    public function __construct(
        public string $id,
        public string $element,
        public int $baseDamage = 0,
        public int $baseHealing = 0,
        public int $baseBarrier = 0,
        public string $crowdControlType = 'none',
    ) {
        if (trim($this->id) === '') {
            throw new InvalidArgumentException('Todo impacto exige el identificador técnico del conjuro.');
        }
        if (trim($this->element) === '') {
            throw new InvalidArgumentException('Todo impacto exige su afinidad elemental.');
        }
        if ($this->baseDamage < 0 || $this->baseHealing < 0 || $this->baseBarrier < 0) {
            throw new InvalidArgumentException('Las magnitudes base del impacto no admiten valores negativos.');
        }
        if (trim($this->crowdControlType) === '') {
            throw new InvalidArgumentException('El tipo de control de masas no puede quedar vacío (usa none).');
        }
    }

    /**
     * ¿Porta este conjuro control de masas propio? (los combos aplican sus
     * efectos por el Códice; este campo describe el conjuro en sí).
     */
    public function hasCrowdControl(): bool
    {
        return $this->crowdControlType !== 'none';
    }
}
