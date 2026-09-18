<?php

/**
 * LineageOathRepository.php — Persistencia PDO del Juramento de Linaje
 * (SPEC-09, Tarea 1.3).
 *
 * Cubre: RF-03.1 (vínculo perpetuo y su consulta), RF-03.3 (idempotencia y
 * serialización de juramentos concurrentes), RF-02.1 (lectura del canon con
 * `hasActiveClans` derivado) y RNF-06 (hechos para la Bitácora, que escribe
 * el servicio).
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): PDO nativo, 100% consultas preparadas con
 *     parameter binding; ningún valor del llamador se interpola jamás en el
 *     SQL. Cero dependencias.
 *   - Art. III (Ética de Linajes): este repositorio MIDE y PERSISTE, no
 *     juzga. Decidir si un juramento es idempotente, si hay conflicto
 *     solemne o si el canon admite una clave son reglas de negocio del
 *     servicio; aquí solo viajan los hechos —qué linaje porta una cuenta,
 *     cuántas filas venció una guardia, qué clanes están activos—.
 *   - Art. V (Dualidad): métodos en inglés camelCase, columnas en
 *     snake_case, documentación en noble castellano.
 *
 * EL CORAZÓN DE LA SERIALIZACIÓN (plan §2.2, RF-03.3): `sealOathGuarded`
 * escribe con la guardia atómica `WHERE lineage IS NULL`. Bajo
 * concurrencia, solo la primera escritura vence (`rowCount = 1`); el
 * perdedor obtiene `rowCount = 0` y re-evalúa — idempotencia si porta ya
 * ESE mismo linaje, conflicto solemne si porta otro. La guardia es
 * portable PDO: ni `SELECT … FOR UPDATE` (SQLite no lo soporta igual) ni
 * reintentos de aplicación sin guardia.
 */

declare(strict_types=1);

namespace Grimorio\Repositories;

use PDO;

/**
 * Repositorio del juramento: cuenta del adepto y canon ceremonial.
 */
final class LineageOathRepository
{
    /** Instancia PDO compartida del santuario. */
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Estado del vínculo de linaje de una cuenta.
     *
     * @return string|null El linaje jurado, o null si la cuenta es
     *                     peregrina (o no existe: el servicio distingue).
     */
    public function findAccountLineage(string $userId): ?string
    {
        $statement = $this->pdo->prepare('SELECT lineage FROM users WHERE id = :userId');
        $statement->execute([':userId' => $userId]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * ¿Existe la cuenta? Distingue «peregrino» de «inexistente»: ambas
     * leen null, pero el servicio solo debe retener a los primeros.
     */
    public function accountExists(string $userId): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE id = :userId');
        $statement->execute([':userId' => $userId]);

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * Escribe el juramento con la GUARDIA ATÓMICA de serialización.
     *
     * `WHERE lineage IS NULL` convierte el UPDATE en el punto de
     * serialización del plan (§2.2): solo vence quien llega a una cuenta
     * aún peregrina. La sentencia es idempotente en el mal sentido
     * contrario al deseado si se llamara dos veces seguidas, y por eso
     * el servicio jamás la llama sin leer antes el estado actual y sin
     * re-evaluar tras un rowCount de cero.
     *
     * @return int Filas mutadas: 1 = juramento sellado ahora; 0 = la
     *             cuenta ya portaba linaje (carrera u otro juramento).
     */
    public function sealOathGuarded(string $userId, string $lineage, string $sealedAtUtc): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE users
             SET lineage = :lineage, updated_at = :sealedAt
             WHERE id = :userId AND lineage IS NULL'
        );
        $statement->execute([
            ':lineage'  => $lineage,
            ':sealedAt' => $sealedAtUtc,
            ':userId'   => $userId,
        ]);

        return $statement->rowCount();
    }

    /**
     * Las fichas heráldicas del canon ceremonial, con su bandera
     * `hasActiveClans` derivada de los clanes activos de `clans`.
     *
     * El canon vive en `lineage_doctrines` (Tarea 1.2, semillas del Anexo
     * A ratificado) y las banderas se computan con una subconsulta
     * EXISTS: un linaje sin hermandades activas no pierde su lugar en la
     * ceremonia, solo viste la nota «Sin hermandades activas» (RF-02.1).
     *
     * @return list<array<string, mixed>> Fichas ordenadas ceremonialmente
     *                                     (position ASC, id ASC).
     */
    public function findOathCatalog(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT d.id, d.name, d.glyph, d.banner_color, d.ruling_element,
                    d.doctrine_condensed, d.doctrine_full,
                    CASE WHEN EXISTS (
                        SELECT 1 FROM clans c
                        WHERE c.lineage_type = d.id AND c.status = \'active\'
                    ) THEN 1 ELSE 0 END AS has_active_clans
             FROM lineage_doctrines d
             ORDER BY d.position ASC, d.id ASC'
        );
        $statement->execute();

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * ¿Guarda clanes activos este linaje? (lectura aislada, para el
     * servicio que ya porte una ficha en memoria).
     */
    public function hasActiveClans(string $lineageId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM clans
             WHERE lineage_type = :lineageId AND status = 'active'"
        );
        $statement->execute([':lineageId' => $lineageId]);

        return (int) $statement->fetchColumn() > 0;
    }
}
