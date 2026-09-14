<?php

/**
 * ComboResolutionResultDto.php — Veredicto determinista de un impacto elemental.
 *
 * Tarea 1.1 (TASKS-06): DTO inmutable que porta el resultado autoritativo de
 * resolver un impacto de conjuro contra el estado imbuido del objetivo
 * (SPEC-06, Tarea 1.3): si detonó reacción, con qué factor, qué Efecto
 * Táctico Canónico, cuánto duró, si consumió el aura y cómo quedó el blanco.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): serialización JSON nativa vía
 *     JsonSerializable + json_encode, sin librerías.
 *   - Artículo II: el veredicto viaja calculado por el servidor; el cliente
 *     (comboResolver, Tarea 2.2) lo espeja, jamás lo reinterpreta.
 *   - Artículo IV (El Velo Arcano): `reactionName` viaja en noble castellano.
 *   - Artículo V: claves técnicas en inglés camelCase, documentación en
 *     castellano.
 *
 * Contrato (plan 2.1, Endpoint 3): las claves raíz son EXACTAMENTE las once
 * de la respuesta canónica — isReaction, reactionId, reactionName,
 * effectiveDamage, damageMultiplierApplied, tacticalEffectApplied,
 * effectDurationMs, clearedAura, resultingAura, stunlockTriggered,
 * grantStunlockImmunity — y viajan SIEMPRE las once, con null explícito
 * cuando no procede (así lo declara la respuesta del plan).
 *
 * Barreras: la trituración (Fractura Basáltica) y la penetración (Colapso
 * Crepuscular) no añaden claves aquí: viajan como `tacticalEffectApplied`
 * (`barrierShatter`, `barrierPiercing`) y el daño de trituración se lee de
 * la ficha del Códice (ElementalReactionDto::barrierDamage), fuente única
 * de verdad compartida con el cliente.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Veredicto de un impacto elemental contra el estado imbuido del objetivo.
 */
final readonly class ComboResolutionResultDto implements JsonSerializable
{
    /**
     * Forja el veredicto del impacto.
     *
     * @param bool $isReaction ¿Detonó una reacción arcana? (RF-03.1, RF-03.2)
     * @param int $effectiveDamage Daño efectivo del conjuro detonador, ya amplificado.
     * @param float $damageMultiplierApplied Factor aplicado (1.5 dual, 1.25 catalizador, 1.0 neutro).
     * @param int $effectDurationMs Duración del efecto táctico aplicado, en milisegundos.
     * @param bool $clearedAura ¿Consumió por completo las energías elementales? (RF-03.4)
     * @param string|null $resultingAura Aura vigente tras el impacto; null en el estado neutral puro.
     * @param string|null $reactionId Identificador técnico del combo detonado.
     * @param string|null $reactionName Nombre litúrgico del combo, en castellano.
     * @param string|null $tacticalEffectApplied Efecto táctico canónico aplicado (RF-04.2).
     * @param bool $stunlockTriggered ¿Se suprimió un Hard CC por la salvaguarda? (RF-05.3)
     * @param bool $grantStunlockImmunity ¿Concede inmunidad rúnica de 3 s? (RF-05.2)
     *
     * @throws InvalidArgumentException Si el veredicto es tácticamente incoherente.
     */
    public function __construct(
        public bool $isReaction,
        public int $effectiveDamage,
        public float $damageMultiplierApplied,
        public int $effectDurationMs,
        public bool $clearedAura,
        public ?string $resultingAura,
        public ?string $reactionId = null,
        public ?string $reactionName = null,
        public ?string $tacticalEffectApplied = null,
        public bool $stunlockTriggered = false,
        public bool $grantStunlockImmunity = false,
    ) {
        if ($this->effectiveDamage < 0) {
            throw new InvalidArgumentException('El daño efectivo no admite valores negativos.');
        }
        if ($this->damageMultiplierApplied < 1.0) {
            throw new InvalidArgumentException('El factor aplicado nunca puede ser inferior a 1.0.');
        }
        if ($this->effectDurationMs < 0) {
            throw new InvalidArgumentException('La duración del efecto no admite valores negativos.');
        }

        // Coherencia de la reacción: quien detona combo lo identifica; quien no, lo omite.
        if ($this->isReaction) {
            if ($this->reactionId === null || trim($this->reactionId) === '') {
                throw new InvalidArgumentException('Una reacción detonada exige su identificador técnico.');
            }
            if ($this->reactionName === null || trim($this->reactionName) === '') {
                throw new InvalidArgumentException('Una reacción detonada exige su nombre litúrgico en castellano.');
            }
        } elseif ($this->reactionId !== null || $this->reactionName !== null) {
            throw new InvalidArgumentException('Sin reacción detonada no puede declararse un combo.');
        }

        // RF-03.4: toda reacción consume íntegramente las energías elementales.
        if ($this->isReaction && !$this->clearedAura) {
            throw new InvalidArgumentException('Toda reacción deja al objetivo en estado neutral puro (RF-03.4).');
        }
        if ($this->clearedAura && $this->resultingAura !== null) {
            throw new InvalidArgumentException('El aura consumida no puede coexistir con un aura resultante.');
        }

        // La salvaguarda anti-stunlock solo tiene sentido sobre una reacción de Hard CC.
        if ($this->stunlockTriggered && !$this->isReaction) {
            throw new InvalidArgumentException('La salvaguarda anti-stunlock exige una reacción detonada (RF-05.3).');
        }
    }

    /**
     * ¿Dejó el impacto al objetivo en estado neutral puro, sin auras residuales?
     */
    public function isNeutralOutcome(): bool
    {
        return $this->clearedAura && $this->resultingAura === null;
    }

    /**
     * Serialización JSON nativa: las once claves del contrato del plan 2.1
     * en su orden canónico, siempre presentes (null explícito cuando no
     * procede), sin transformación adicional.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'isReaction' => $this->isReaction,
            'reactionId' => $this->reactionId,
            'reactionName' => $this->reactionName,
            'effectiveDamage' => $this->effectiveDamage,
            'damageMultiplierApplied' => $this->damageMultiplierApplied,
            'tacticalEffectApplied' => $this->tacticalEffectApplied,
            'effectDurationMs' => $this->effectDurationMs,
            'clearedAura' => $this->clearedAura,
            'resultingAura' => $this->resultingAura,
            'stunlockTriggered' => $this->stunlockTriggered,
            'grantStunlockImmunity' => $this->grantStunlockImmunity,
        ];
    }
}
