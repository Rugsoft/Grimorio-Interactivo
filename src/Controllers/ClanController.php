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
 *
 * Un solo contador de gloria (Tarea 2.6, TASKS-07): la clave pública
 * `domainPoints` CONSERVA su nombre canónico de SPEC-01, pero su valor se
 * sirve ya desde `clans.weekly_points` —el único contador semanal del
 * santuario (SPEC-07, RF-03/RF-04)—. La columna `domain_points` quedó
 * retirada del plano por ser un tercer contador del MISMO concepto y sin
 * escritor alguno: el contrato no cambia, la autoridad sí.
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
        // El orden y el valor proceden del contador semanal canónico de SPEC-07.
        $clansStatement = $pdo->prepare(
            'SELECT id, slug, name, motto, weekly_points
             FROM clans
             ORDER BY weekly_points DESC, name ASC'
        );
        $clansStatement->execute();
        $clanRows = $clansStatement->fetchAll();

        // Mapeo explícito snake_case (DB) -> camelCase (contrato JSON).
        // `domainPoints` es el alias público del contador semanal de SPEC-07:
        // así la interfaz de SPEC-01 se preserva sin duplicar la gloria.
        $clans = array_map(static fn (array $row): array => [
            'id'           => (string) $row['id'],
            'slug'         => (string) $row['slug'],
            'name'         => (string) $row['name'],
            'motto'        => (string) $row['motto'],
            'domainPoints' => (int) $row['weekly_points'],
        ], $clanRows);

        return Response::json([
            'success' => true,
            'data'    => $clans,
        ]);
    }
}
