<?php

/**
 * Connection.php — Singleton de conexión PDO nativa del Grimorio Interactivo.
 *
 * Tarea 1.2 (TASKS-01): canal único de acceso a la base de datos.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo puro, sin ORM ni capas externas.
 *   - Artículo V: identificadores en inglés, documentación en castellano.
 *
 * Diseño:
 *   - Patrón Singleton estricto (clase final, constructor privado, clonación
 *     y deserialización prohibidas) para garantizar un único canal PDO por
 *     proceso y evitar fugas de conexiones.
 *   - Configuración mediante variables de entorno (Dogma Vanilla: sin
 *     librerías de configuración externas):
 *       GRIMORIO_DB_DSN  (ej. 'sqlite::memory:' o 'mysql:host=localhost;dbname=grimorio;charset=utf8mb4')
 *       GRIMORIO_DB_USER / GRIMORIO_DB_PASS (solo MySQL)
 *     Si GRIMORIO_DB_DSN no está definida, se emplea SQLite en memoria
 *     (valor por defecto razonable para desarrollo y pruebas).
 *   - Atributos exigidos por el criterio "Hecho cuando" de la Tarea 1.2:
 *       ATTR_ERRMODE => ERRMODE_EXCEPTION
 *       ATTR_DEFAULT_FETCH_MODE => FETCH_ASSOC
 *   - Para SQLite se activa 'PRAGMA foreign_keys = ON' en cada conexión,
 *     pues SQLite no aplica la integridad referencial por defecto.
 */

declare(strict_types=1);

namespace Grimorio\Database;

use PDO;
use PDOException;
use RuntimeException;

final class Connection
{
    /** Instancia única del Singleton (canal exclusivo de la petición). */
    private static ?Connection $instance = null;

    /** Conexión PDO materializada de forma perezosa (lazy). */
    private ?PDO $pdo = null;

    /** DSN de la base de datos (inyectable para pruebas). */
    private string $dsn;

    /** Usuario de la base de datos (vacío en SQLite). */
    private string $dbUser;

    /** Contraseña de la base de datos (vacía en SQLite). */
    private string $dbPassword;

    /**
     * Constructor privado: la instanciación ocurre solo vía getInstance().
     * Resuelve la configuración desde el entorno y aplica un fallback seguro.
     */
    private function __construct()
    {
        // Las claves de configuración leen del entorno; sin parsear archivos ni usar extensiones extra.
        // Orden de resolución: constantes del bootstrap de despliegue
        // (deploy/infinityfree/env.php define GRIMORIO_DB_DSN vía `define`,
        // porque el sandbox de InfinityFree tiene `putenv` en
        // disable_functions y la vía de entorno muere en silencio) y,
        // como fallback, el entorno clásico; si nada existe, SQLite en
        // memoria (solo desarrollo).
        $this->dsn         = (string) (defined('GRIMORIO_DB_DSN') ? constant('GRIMORIO_DB_DSN') : (getenv('GRIMORIO_DB_DSN') ?: 'sqlite::memory:'));
        $this->dbUser      = (string) (defined('GRIMORIO_DB_USER') ? constant('GRIMORIO_DB_USER') : getenv('GRIMORIO_DB_USER'));
        $this->dbPassword  = (string) (defined('GRIMORIO_DB_PASS') ? constant('GRIMORIO_DB_PASS') : getenv('GRIMORIO_DB_PASS'));
    }

    /**
     * Retorna la instancia única de Connection.
     * Punto de entrada obligatorio del patrón Singleton.
     */
    public static function getInstance(): Connection
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Retorna la conexión PDO activa, materializándola si aún no existe.
     * Aplica los atributos de robustez exigidos por la Tarea 1.2.
     *
     * @throws RuntimeException si el motor subyacente rechaza la conexión.
     */
    public function getPdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        try {
            $this->pdo = new PDO($this->dsn, $this->dbUser, $this->dbPassword, [
                // Las fallos de SQL se elevan como excepciones (sin silencios).
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                // Las filas llegan como arrays asociativos por defecto.
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Emulación de prepares desactivada: prepared statements nativos (Art. I, seguridad).
                PDO::ATTR_EMULATE_PREPARES => false,
                // Conexiones persistentes desactivadas: ciclo de vida claro por petición.
                PDO::ATTR_PERSISTENT => false,
            ]);
        } catch (PDOException $e) {
            // Nunca se expone el DSN ni trazas internas (seguridad, AGENTS.md 6.1).
            throw new RuntimeException('La conexión al plano arcano (base de datos) no pudo establecerse.', 0, $e);
        }

        // SQLite no aplica integridad referencial salvo que se pida por conexión.
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON;');

            // Auto-bootstrap de desarrollo (Dogma Vanilla: SQL nativo, sin
            // herramientas externas). Si el plano SQLite todavía no tiene la
            // tabla raíz, se materializan schema.sql + seeds.sql una sola vez.
            // En MySQL el despliegue es responsabilidad del custodio (se asume
            // una base ya provisionada) y este paso no aplica.
            $tableCheck = $this->pdo->query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'spells'"
            );
            if ($tableCheck !== false && $tableCheck->fetch() === false) {
                $databaseDir = dirname(__DIR__, 2) . '/database';
                $this->pdo->exec((string) file_get_contents($databaseDir . '/schema.sql'));
                $this->pdo->exec((string) file_get_contents($databaseDir . '/seeds.sql'));
            }
        }

        return $this->pdo;
    }

    /** Prohibida la clonación: rompería la garantía de instancia única. */
    private function __clone()
    {
    }

    /**
     * Restablece la instancia única y cierra la conexión previa.
     * Pensado para el aislamiento de pruebas en CLI (bases en memoria por
     * ejecución) y para el reciclaje de procesos de larga vida; el flujo
     * normal de la aplicación jamás debe invocarlo.
     */
    public static function resetInstance(): void
    {
        // Liberar la referencia permite al recolector cerrar el socket PDO.
        self::$instance = null;
    }

    /** Prohibida la deserialización: protegería la identidad del Singleton. */
    public function __wakeup(): void
    {
        throw new RuntimeException('La deserialización del Singleton Connection está prohibida.');
    }
}
