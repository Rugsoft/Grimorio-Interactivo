<?php

/**
 * LineageCatalogService.php — El servicio del canon inmutable de los
 * Ocho Linajes (SPEC-09, Tarea 2.1).
 *
 * Cubre: RF-02.1 (canon ceremonial con `hasActiveClans` derivado), RF-02.2
 * (doctrina condensada e íntegra desde un solo texto canónico), caso
 * límite 5 y exclusión 5 (canon INMUTABLE: no existe tabla administrable
 * ni potestad de edición) y RNF-02 (rótulos en noble castellano).
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): PDO preparado vía el repositorio; cero
 *     dependencias.
 *   - Art. V (Dualidad): métodos en inglés camelCase; documentación en
 *     castellano.
 *
 * DECISIÓN DE DISEÑO (plan §5.3): el canon vive en `lineage_doctrines`
 * (semillas del Anexo A ratificado, Tarea 1.2) y este servicio lo LEE;
 * jamás lo escribe. Su API pública no declara método alguno de mutación:
 * la inmutabilidad no es una promesa, es la forma del contrato. La
 * heráldica es la misma de SPEC-07 (LineageSynergyService), jamás
 * divergente; el CHECK de `users.lineage` y la tabla sellan el mismo
 * conjunto cerrado de 8 claves.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use Grimorio\Dto\LineageOathCatalogDto;
use Grimorio\Dto\LineageProfileDto;
use Grimorio\Repositories\LineageOathRepository;

/**
 * Oráculo del canon: lee las fichas y las viste con el estado de la
 * cuenta llamadora. Sin pluma.
 */
final class LineageCatalogService
{
    /** Repositorio de lectura del canon y del estado de cuenta. */
    private LineageOathRepository $repository;

    public function __construct(LineageOathRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * El canon ceremonial completo para la vista del juramento: las ocho
     * fichas heráldicas (contrato camelCase del plan §2.2) más el estado
     * de la cuenta llamadora (`pilgrim` | `lineaged`).
     *
     * @param string $userId Identificador de la cuenta llamadora.
     *
     * @return LineageOathCatalogDto El catálogo listo para el Endpoint 1.
     */
    public function getOathCatalog(string $userId): LineageOathCatalogDto
    {
        $accountState = $this->repository->findAccountLineage($userId) === null
            ? LineageOathCatalogDto::STATE_PILGRIM
            : LineageOathCatalogDto::STATE_LINEAGED;

        $profiles = array_map(
            static fn (array $row): LineageProfileDto => new LineageProfileDto(
                id: (string) $row['id'],
                name: (string) $row['name'],
                glyph: (string) $row['glyph'],
                bannerColor: (string) $row['banner_color'],
                rulingElement: (string) $row['ruling_element'],
                doctrineCondensed: (string) $row['doctrine_condensed'],
                doctrineFull: (string) $row['doctrine_full'],
                hasActiveClans: (bool) $row['has_active_clans'],
            ),
            $this->repository->findOathCatalog(),
        );

        return new LineageOathCatalogDto($accountState, $profiles);
    }
}
