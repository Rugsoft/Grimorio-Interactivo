<?php

/**
 * ClanController.php — Vista previa pública del Salón de Linajes.
 *
 * Tarea 1.5 (TASKS-01): controlador REST de linajes en modo lectura (RF-02.2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo con consultas preparadas.
 *   - Artículo III: el clan fundacional 'cln_primordial' es neutro y no compite;
 *     el endpoint lo expone tal cual (la lógica de Dominio vive en SPEC-07).
 *   - Artículo V: identificadores en inglés camelCase, documentación en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Controllers;

use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Database\Connection;
use PDO;

/**
 * Sirve el listado público de linajes para lectura sin autenticación.
 */
final class ClanController
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * GET /api/v1/clans/preview — linajes con su Dominio semanal, solo lectura.
     */
    public function preview(Request $request): Response
    {
        // El parámetro $request queda reservado para futuras cabeceras.
        $pdo = $this->connection->getPdo();

        // Consulta preparada: sin concatenación de datos del usuario (AGENTS.md 6.1).
        $clansStatement = $pdo->prepare(
            'SELECT id, slug, name, motto, domain_points
             FROM clans
             ORDER BY domain_points DESC, name ASC'
        );
        $clansStatement->execute();
        $clanRows = $clansStatement->fetchAll();

        // Mapeo explícito snake_case (DB) -> camelCase (contrato JSON).
        $clans = array_map(static fn (array $row): array => [
            'id'           => (string) $row['id'],
            'slug'         => (string) $row['slug'],
            'name'         => (string) $row['name'],
            'motto'        => (string) $row['motto'],
            'domainPoints' => (int) $row['domain_points'],
        ], $clanRows);

        return Response::json([
            'success' => true,
            'data'    => $clans,
        ]);
    }
}
