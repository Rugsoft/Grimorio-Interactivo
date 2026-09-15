<?php

/**
 * SignatureAnnulment.php — Anulación de oficio de una Firma de Consagración.
 *
 * Tarea 2.2 (TASKS-08): registro inmutable de UNA firma caída por nulidad
 * constitucional sobrevenida (RF-03.4) o por pérdida del rango de Maestro en
 * tránsito (RF-03.5). Es la unidad con la que el validador informa de su
 * trabajo, de modo que la capa que notifica al autor (RF-03.4) y la bitácora
 * puedan hablar de lo mismo sin deducirlo del estado de la base.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DTO PHP puro, sin dependencias externas.
 *   - Artículo III: `reason` es el motivo canónico de la nulidad —jamás un
 *     texto libre—, para que la Bitácora pueda contarse sin ambigüedad.
 *   - Artículo V: identificadores en inglés camelCase, documentación en
 *     castellano noble.
 */

declare(strict_types=1);

namespace Grimorio\Services;

/**
 * Firma anulada de oficio, con el conjuro que avalaba y el contador resultante.
 */
final class SignatureAnnulment
{
    /**
     * @param string $signatureId         Firma que quedó revocada.
     * @param string $spellId             Conjuro que esa firma avalaba.
     * @param string $masterId            Maestro que la había estampado.
     * @param string $authorId            Autor de la obra, destinatario de la notificación (RF-03.4).
     * @param string $reason              Motivo canónico: 'clan_conflict_arisen' | 'rank_lost'.
     * @param int    $newSignaturesCount  Firmas VIVAS del conjuro tras la anulación (N-1).
     */
    public function __construct(
        public readonly string $signatureId,
        public readonly string $spellId,
        public readonly string $masterId,
        public readonly string $authorId,
        public readonly string $reason,
        public readonly int $newSignaturesCount,
    ) {
    }
}
