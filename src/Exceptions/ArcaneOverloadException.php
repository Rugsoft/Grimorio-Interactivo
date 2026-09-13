<?php

/**
 * ArcaneOverloadException.php — Sobrecarga Arcana del techo de contención.
 *
 * Tarea 2.1 (TASKS-04): excepción de dominio lanzada por
 * SpellBalanceService (Tarea 2.2/2.3) cuando la combinación de efectos
 * arroja un maná superior al techo de contención de 200 puntos
 * (RF-03.2, Artículo II: Anti-Power-Creep).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro, sin jerarquías ajenas.
 *   - Artículo II: el techo de 200 de maná se defiende lanzando esta
 *     excepción de forma inmediata, jamás recortando el coste.
 *   - Artículo IV (El Velo Arcano): el mensaje canónico es el ceremonial
 *     exacto de la spec (sección RF-03.2).
 *   - Artículo V: identificadores en inglés camelCase, leyendas en
 *     castellano.
 *
 * Contrato (plan 2.2, Endpoint 1 — respuesta 400):
 *   {
 *     "success": false,
 *     "error": {
 *       "code": "ARCANE_OVERLOAD",
 *       "message": "La concentración de poder supera la capacidad de
 *                   contención del plano mortal (máx. 200 maná).",
 *       "calculatedMana": 240
 *     }
 *   }
 */

declare(strict_types=1);

namespace Grimorio\Exceptions;

use RuntimeException;

/**
 * Sobrecarga Arcana: el maná calculado excede el techo de contención.
 */
final class ArcaneOverloadException extends RuntimeException
{
    /** Código HTTP canónico de la sobrecarga (Tarea 2.1): 400 Bad Request. */
    public const HTTP_STATUS_CODE = 400;

    /** Código canónico de respuesta del contrato del plan (Endpoint 1). */
    public const ERROR_CODE = 'ARCANE_OVERLOAD';

    /** Mensaje ceremonial EXACTO del RF-03.2 de la spec. */
    private const CEREMONIAL_LEGEND = 'La concentración de poder supera la capacidad de contención del plano mortal (máx. 200 maná).';

    /** Maná bruto calculado que disparó la sobrecarga (contrato: calculatedMana). */
    private int $calculatedMana;

    public function __construct(string $message = self::CEREMONIAL_LEGEND, int $calculatedMana = 0)
    {
        parent::__construct($message);
        $this->calculatedMana = $calculatedMana;
    }

    /**
     * Fábrica canónica: mensaje ceremonial de la spec + maná que la disparó.
     */
    public static function forMana(int $calculatedMana): self
    {
        return new self(self::CEREMONIAL_LEGEND, $calculatedMana);
    }

    /**
     * Maná bruto calculado que excedió el techo (campo calculatedMana).
     */
    public function getCalculatedMana(): int
    {
        return $this->calculatedMana;
    }

    /**
     * Código HTTP canónico de la excepción (400).
     */
    public function getHttpStatusCode(): int
    {
        return self::HTTP_STATUS_CODE;
    }

    /**
     * Código canónico de error del contrato (ARCANE_OVERLOAD).
     */
    public function getErrorCode(): string
    {
        return self::ERROR_CODE;
    }

    /**
     * Sobre de error listo para Response::json() (contrato del plan 2.2).
     *
     * @return array{success: false, error: array{code: string, message: string, calculatedMana: int}}
     */
    public function toPayload(): array
    {
        return [
            'success' => false,
            'error'   => [
                'code'           => self::ERROR_CODE,
                'message'        => $this->getMessage(),
                'calculatedMana' => $this->calculatedMana,
            ],
        ];
    }
}
