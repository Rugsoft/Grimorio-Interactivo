<?php

/**
 * SignatureAnnulmentResult.php — Veredicto de la anulación de oficio.
 *
 * Tarea 2.2 (TASKS-08): valor de retorno de
 * ConstitutionalEthicsValidator::revokeConflictedSignatures(). Reúne las
 * firmas caídas por nulidad constitucional sobrevenida (RF-03.4) o por
 * pérdida de rango (RF-03.5), de modo que el llamante sepa a quién notificar
 * sin volver a consultar la base.
 *
 * Inmutabilidad (RNF-01): el veredicto es un retrato; no se enmienda.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DTO PHP puro.
 *   - Artículo III: el veredicto deja rastro de cada firma caída y de su
 *     motivo, jamás un simple «ocurrieron N hechos».
 *   - Artículo V: identificadores en inglés camelCase, documentación en
 *     castellano noble.
 */

declare(strict_types=1);

namespace Grimorio\Services;

/**
 * Censo de las firmas anuladas de oficio en un mismo gesto.
 */
final class SignatureAnnulmentResult
{
    /**
     * @param list<SignatureAnnulment> $annulments Firmas caídas, en el orden en que se anularon.
     */
    public function __construct(
        public readonly array $annulments = [],
    ) {
    }

    /** ¿Se anuló firma alguna? La respuesta que la bitácora y el autor esperan. */
    public function hasAnnulments(): bool
    {
        return $this->annulments !== [];
    }

    /** Cuántas firmas cayeron: la magnitud de la anulación (N → N-1). */
    public function count(): int
    {
        return count($this->annulments);
    }

    /**
     * Conjuros tocados por la anulación, sin repetición.
     *
     * @return list<string>
     */
    public function spellIds(): array
    {
        return array_values(array_unique(array_map(
            static fn (SignatureAnnulment $annulment): string => $annulment->spellId,
            $this->annulments,)
        ));
    }

    /**
     * Autores a los que hay que dar noticia (RF-03.4), sin repetición.
     *
     * @return list<string>
     */
    public function authorIds(): array
    {
        return array_values(array_unique(array_map(
            static fn (SignatureAnnulment $annulment): string => $annulment->authorId,
            $this->annulments,)
        ));
    }

    /**
     * Motivos que concurrieron en la anulación, sin repetición.
     *
     * @return list<string>
     */
    public function reasons(): array
    {
        return array_values(array_unique(array_map(
            static fn (SignatureAnnulment $annulment): string => $annulment->reason,
            $this->annulments,)
        ));
    }
}
