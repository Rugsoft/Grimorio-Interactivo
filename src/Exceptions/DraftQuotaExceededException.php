<?php

/**
 * DraftQuotaExceededException.php — Cuota de borradores desbordada.
 *
 * Tarea 3.1 (TASKS-04): excepción de dominio lanzada por
 * SpellManagementService cuando un autor intenta crear el undécimo
 * borrador simultáneo (RF-05.1: cuota dura de 10 borradores privados
 * por autor, para prevenir la acumulación de datos zombis y spam sin
 * restringir la flexibilidad creativa — plan, Decisión 3).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro.
 *   - Artículo III: la cuota protege la base de datos de la acumulación.
 *   - Artículo IV (El Velo Arcano): leyenda solemne en castellano.
 *   - Artículo V: identificadores en inglés camelCase.
 */

declare(strict_types=1);

namespace Grimorio\Exceptions;

use RuntimeException;

/**
 * El autor ya posee 10 borradores activos: la forja exige despejar uno.
 */
final class DraftQuotaExceededException extends RuntimeException
{
    /** Código HTTP canónico de la cuota desbordada (Tarea 3.1): 403 Forbidden. */
    public const HTTP_STATUS_CODE = 403;

    /** Código canónico de respuesta del contrato del plan (Endpoint 2). */
    public const ERROR_CODE = 'DRAFT_QUOTA_EXCEEDED';

    /** Máximo de borradores simultáneos por autor (RF-05.1). */
    public const MAX_DRAFTS = 10;

    /** Plantilla de la leyenda solemne. */
    private const CEREMONIAL_LEGEND = 'La forja solo admite 10 borradores simultáneos: despeja o publica alguno antes de forjar otro.';

    /** Límite de cuota portado para trazabilidad (siempre 10). */
    private int $quotaLimit;

    public function __construct(string $message = self::CEREMONIAL_LEGEND, int $quotaLimit = self::MAX_DRAFTS)
    {
        parent::__construct($message);
        $this->quotaLimit = $quotaLimit;
    }

    /**
     * Código HTTP canónico de la excepción (403).
     */
    public function getHttpStatusCode(): int
    {
        return self::HTTP_STATUS_CODE;
    }

    /**
     * Código canónico de error del contrato (DRAFT_QUOTA_EXCEEDED).
     */
    public function getErrorCode(): string
    {
        return self::ERROR_CODE;
    }

    /**
     * Límite de borradores simultáneos que desencadenó la excepción.
     */
    public function getQuotaLimit(): int
    {
        return $this->quotaLimit;
    }

    /**
     * Sobre de error listo para Response::json() (AGENTS.md 6.1).
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
                'recoveryAction' => 'DELETE_OR_PUBLISH_DRAFT',
            ],
        ];
    }
}
