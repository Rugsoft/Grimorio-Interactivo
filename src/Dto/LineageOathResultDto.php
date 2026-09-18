<?php

/**
 * LineageOathResultDto.php — Veredicto del juramento sellado (SPEC-09,
 * Tarea 2.2).
 *
 * Porta las claves del contrato del plan §2.2 (Endpoint 2, respuesta 200,
 * ambos caminos — sellado ahora o idempotencia): `sealedNow` distingue el
 * acto consumado de la idempotencia silenciosa, y `retainedRoute` conduce
 * al retorno (RF-03.1); su nulidad remite al portal de inicio.
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): serialización JSON nativa, sin librerías.
 *   - Art. V (Dualidad): claves técnicas en inglés camelCase; documentación
 *     en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use JsonSerializable;

/**
 * El desenlace solemne de un juramento aceptado por el canon.
 */
final readonly class LineageOathResultDto implements JsonSerializable
{
    /**
     * @param string      $lineage       El linaje que porta la cuenta.
     * @param bool        $sealedNow     ¿Se selló en esta petición? (false = idempotencia).
     * @param string|null $retainedRoute Ruta retenida saneada, o null si aterriza en el portal (RF-03.1).
     */
    public function __construct(
        public string $lineage,
        public bool $sealedNow,
        public ?string $retainedRoute,
    ) {
    }

    /**
     * El contrato exacto del plan §2.2: { lineage, sealedNow, retainedRoute }.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'lineage'       => $this->lineage,
            'sealedNow'     => $this->sealedNow,
            'retainedRoute' => $this->retainedRoute,
        ];
    }
}
