<?php

/**
 * SpellImmutableException.php — Inviolabilidad del conjuro validado.
 *
 * Tarea 2.1 (TASKS-04): excepción de dominio lanzada por
 * SpellManagementService (Tarea 3.4) ante cualquier intento de edición o
 * borrado de un conjuro con status = 'validated' (RF-06.2, Artículo III:
 * un conjuro validado es patrimonio inmutable de la biblioteca colectiva;
 * la evolución legitimate se canaliza por la derivación a Variantes).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro, sin jerarquías ajenas.
 *   - Artículo III: la inmutabilidad protege las firmas de los Maestros
 *     y la integridad del catálogo validado.
 *   - Artículo IV (El Velo Arcano): el mensaje canónico porta la
 *     solemnidad de la spec (patrimonio inmutable, no puede alterarse).
 *   - Artículo V: identificadores en inglés camelCase, leyendas en
 *     castellano.
 */

declare(strict_types=1);

namespace Grimorio\Exceptions;

use RuntimeException;

/**
 * Conjuro validado: patrimonio inmutable, no editable ni borrable.
 */
final class SpellImmutableException extends RuntimeException
{
    /** Código HTTP canónico de la inviolabilidad (Tarea 2.1): 403 Forbidden. */
    public const HTTP_STATUS_CODE = 403;

    /** Código canónico de respuesta del contrato del proyecto. */
    public const ERROR_CODE = 'SPELL_IMMUTABLE';

    /** Plantilla del mensaje solemne (RF-06.2). */
    private const CEREMONIAL_LEGEND = 'Un conjuro validado es patrimonio inmutable del grimorio y no puede alterarse: su evolución exige forjar una Variante propia.';

    /**
     * Leyenda de la obra desterrada: el archivo póstumo tampoco se enmienda
     * (SPEC-08, RF-04.4).
     */
    private const ARCHIVED_LEGEND = 'Una obra desterrada del canon duerme en el archivo póstumo y jamás se enmienda: solo un decreto soberano podría rescatarla.';

    /** Identificador del conjuro protegido (para trazabilidad y auditoría). */
    private string $spellId;

    public function __construct(string $message = self::CEREMONIAL_LEGEND, string $spellId = '')
    {
        parent::__construct($message);
        $this->spellId = $spellId;
    }

    /**
     * Fábrica canónica: mensaje solemne + identificador del conjuro protegido.
     */
    public static function forValidatedSpell(string $spellId): self
    {
        return new self(self::CEREMONIAL_LEGEND, $spellId);
    }

    /**
     * Obra desterrada del canon: el archivo póstumo es igual de inviolable
     * que la consagración (SPEC-08, RF-04.4).
     */
    public static function forArchivedSpell(string $spellId): self
    {
        return new self(self::ARCHIVED_LEGEND, $spellId);
    }

    /**
     * Identificador del conjuro cuyo patrimonio se intentó alterar.
     */
    public function getSpellId(): string
    {
        return $this->spellId;
    }

    /**
     * Código HTTP canónico de la excepción (403).
     */
    public function getHttpStatusCode(): int
    {
        return self::HTTP_STATUS_CODE;
    }

    /**
     * Código canónico de error del contrato (SPELL_IMMUTABLE).
     */
    public function getErrorCode(): string
    {
        return self::ERROR_CODE;
    }

    /**
     * Sobre de error listo para Response::json() (AGENTS.md 6.1), con la
     * acción de recuperación canónica: clonar como Variante (RF-06.3).
     *
     * @return array{success: false, error: array{code: string, message: string, recoveryAction: string}}
     */
    public function toPayload(): array
    {
        return [
            'success' => false,
            'error'   => [
                'code'           => self::ERROR_CODE,
                'message'        => $this->getMessage(),
                'recoveryAction' => 'CREATE_VARIANT',
            ],
        ];
    }
}
