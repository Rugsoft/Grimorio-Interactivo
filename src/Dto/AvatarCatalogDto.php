<?php

/**
 * AvatarCatalogDto.php — Carga útil del catálogo de avatares del
 * santuario (SPEC-12, Tarea 2.1; plan §2.3).
 *
 * DTO inmutable y AUTOCONTENIDO (patrón de GrimoirePageDto): se forja
 * desde el array canónico del servicio y las filas ya leídas, jamás
 * instancia el modelo User; los arneses lo cargan sin el autoload del
 * front controller.
 *
 * Contrato exacto (plan §2.3):
 *   { "catalog": [ { id, kind, label, heraldryKey } ],
 *     "current": { kind, reference },
 *     "ownAvatar": { reference } | null,
 *     "restricted": bool }
 *
 * Constitución:
 *   - Artículo I: serialización JSON nativa (JsonSerializable).
 *   - Artículo V: claves camelCase en inglés; los rótulos del canon
 *     viajan en noble castellano; jamás identificadores crudos del
 *     adepto en la carga útil.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use JsonSerializable;

final class AvatarCatalogDto implements JsonSerializable
{
    /**
     * @param list<array{id: string, kind: string, label: string, heraldryKey: string|null}> $catalog
     * @param array{kind: string, reference: string|null}                                     $current
     * @param array{reference: string}|null                                                   $ownAvatar
     */
    public function __construct(
        public readonly array $catalog,
        public readonly array $current,
        public readonly ?array $ownAvatar,
        public readonly bool $restricted,
    ) {
    }

    /**
     * Serialización nativa: EXACTAMENTE el contrato del plan §2.3.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'catalog' => $this->catalog,
            'current' => $this->current,
            'ownAvatar' => $this->ownAvatar,
            'restricted' => $this->restricted,
        ];
    }
}
