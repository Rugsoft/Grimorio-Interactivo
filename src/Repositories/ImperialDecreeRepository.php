<?php

/**
 * ImperialDecreeRepository.php — Persistencia PDO de los Decretos Imperiales
 * del Administrador Supremo y su inscripción en la Bitácora de Auditoría
 * (SPEC-08, Tarea 1.4).
 *
 * Cubre: RF-04.5 (todo acto soberano exige su Edicto Imperial y se inscribe de
 * forma automática e inmutable en la bitácora), RF-06.1 (los decretos de
 * gobierno entran en el censo de acciones de moderación) y RNF-01 (determinismo
 * auditable: el reloj lo pasa el llamante).
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): PDO nativo, 100% consultas preparadas con
 *     parameter binding; ningún valor del llamador se interpola jamás.
 *   - Art. III (Transparencia): el decreto y su memoria en la bitácora son un
 *     SOLO gesto. No existe decreto sin registro, ni registro sin decreto.
 *   - Art. IV (Velo Arcano): el edicto es narrativa y se guarda íntegro; el
 *     umbral de veinte caracteres es el mismo que rige el Dictamen de Objeción.
 *   - Art. V (Dualidad): identificadores en inglés camelCase, claves de base de
 *     datos en snake_case, documentación en noble castellano.
 *
 * Reparto de responsabilidades (Tarea 2.5): este repositorio MIDE y PERSISTE;
 * no juzga. Que el conjuro esté en `experimental`, que el Administrador no
 * pertenezca al clan del autor o que un rescate reinicie el contador a 0/3 son
 * veredictos de `SovereignAdminService`; aquí solo se inscribe el hecho.
 *
 * Canal único hacia la bitácora: este repositorio NO escribe en `audit_log`.
 * Delega en `AuditService` (Tarea 2.5 de SPEC-03), el único canal autorizado,
 * de modo que los catálogos de acciones y de entidades, y los disparadores del
 * esquema que prohíben alterar la bitácora, sigan siendo la única puerta. El
 * servicio de auditoría se recibe inyectado y NO es opcional: un decreto que no
 * ha sido inscrito en la bitácora no ha ocurrido (RF-04.5), y admitir una
 * construcción sin auditoría permitiría precisamente eso.
 */

declare(strict_types=1);

namespace Grimorio\Repositories;

use Grimorio\Services\AuditService;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Repositorio de los Decretos Imperiales: emisión, memoria y bitácora.
 */
final class ImperialDecreeRepository
{
    /** Firma Soberana instantánea sobre una obra en deliberación (RF-04.1). */
    public const TYPE_SOVEREIGN_VALIDATION = 'sovereignValidation';

    /** Rescate de una obra rechazada, devuelta a deliberación con 0/3 (RF-04.3). */
    public const TYPE_RESCUE_TO_EXPERIMENTAL = 'rescueToExperimental';

    /** Rescate de una obra rechazada, elevada de oficio a `validated` (RF-04.3). */
    public const TYPE_RESCUE_TO_VALIDATED = 'rescueToValidated';

    /** Revocación y archivo póstumo de una obra consagrada (RF-04.4). */
    public const TYPE_REVOKE_AND_ARCHIVE = 'revokeAndArchive';

    /**
     * Los cuatro decretos canónicos de RF-04.
     *
     * La base los acota con un `CHECK`, y el repositorio los acota antes de
     * tocar la base: una cuarta parte de la especificación soberana se apoya en
     * que este catálogo sea cerrado.
     */
    public const CANONICAL_DECREE_TYPES = [
        self::TYPE_SOVEREIGN_VALIDATION,
        self::TYPE_RESCUE_TO_EXPERIMENTAL,
        self::TYPE_RESCUE_TO_VALIDATED,
        self::TYPE_REVOKE_AND_ARCHIVE,
    ];

    /**
     * Longitud mínima del Edicto Imperial (RF-04.5).
     *
     * Mismo umbral que el Dictamen de Objeción de RF-02.5: toda intervención
     * unilateral del santuario exige veinte caracteres de justificación.
     */
    public const MIN_IMPERIAL_DECREE_LENGTH = 20;

    /** Entidad objetivo de todo decreto: la bitácora lo imputa al conjuro. */
    private const TARGET_ENTITY_TYPE = 'spell';

    /**
     * Acto canónico de la bitácora que corresponde a cada decreto (RF-06.1).
     *
     * El decreto NO elige su acto: lo recibe de este mapa cerrado. Así el
     * llamante no puede inscribir un acto soberano sin que la bitácora lo
     * nombre con precisión, ni inventar un acto que el catálogo de SPEC-03 no
     * reconozca.
     */
    private const AUDIT_ACTION_BY_DECREE_TYPE = [
        self::TYPE_SOVEREIGN_VALIDATION     => 'SOVEREIGN_VALIDATION',
        self::TYPE_RESCUE_TO_EXPERIMENTAL   => 'SOVEREIGN_RESCUE',
        self::TYPE_RESCUE_TO_VALIDATED      => 'SOVEREIGN_RESCUE',
        self::TYPE_REVOKE_AND_ARCHIVE       => 'SOVEREIGN_ARCHIVE',
    ];

    /**
     * Proyección canónica de un decreto (columnas del esquema de SPEC-08,
     * Tarea 1.1).
     */
    private const DECREE_COLUMNS = 'id, spell_id, admin_id, decree_type, '
        . 'imperial_decree_text, decreed_at';

    /** Conexión PDO del santuario (Singleton del front controller). */
    private PDO $pdo;

    /** Único canal autorizado hacia la Bitácora de Auditoría (SPEC-03). */
    private AuditService $auditService;

    public function __construct(PDO $pdo, AuditService $auditService)
    {
        $this->pdo = $pdo;
        $this->auditService = $auditService;
    }

    /**
     * Inscribe un Decreto Imperial, y con él su memoria en la bitácora
     * (RF-04.5, RF-06.1).
     *
     * Las dos escrituras —la fila de `sovereign_decrees` y la entrada de
     * `audit_log`— ocurren dentro de UNA sola transacción: o el decreto queda
     * inscrito con su edicto público, o nada de él se observa. La entrada de la
     * bitácora toma la identidad del Administrador tal como está grabada en
     * `users` en ese instante —su alias público y su rol técnico—, porque el
     * llamante solo aporta el identificador y la bitácora ha de poder leerse
     * sin cruces posteriores.
     *
     * El edicto se guarda íntegro y se comprueba antes de tocar la base, con el
     * mismo umbral de veinte caracteres que rige el Dictamen de Objeción. La
     * fecha es la que pasa el llamante, y es también la de la entrada de la
     * bitácora: el decreto y su memoria no pueden fecharse en instantes
     * distintos (RNF-01).
     *
     * @param string $decreeId           Identificador textual del decreto.
     * @param string $spellId            Conjuro sobre el que se decreta.
     * @param string $adminId            Administrador Supremo actuante.
     * @param string $decreeType         Decreto canónico de RF-04.
     * @param string $imperialDecreeText Edicto de justificación en castellano.
     * @param string $decreedAtUtc       Instante del decreto (ISO 8601 UTC).
     *
     * @return array{
     *   id: string, spell_id: string, admin_id: string, decree_type: string,
     *   imperial_decree_text: string, decreed_at: string
     * }
     *
     * @throws InvalidArgumentException Si el decreto o el edicto rompen el canon.
     * @throws RuntimeException         Si el actuante no existe en el santuario.
     */
    public function insertDecree(
        string $decreeId,
        string $spellId,
        string $adminId,
        string $decreeType,
        string $imperialDecreeText,
        string $decreedAtUtc
    ): array {
        $this->assertDecreeType($decreeType);
        $this->assertImperialDecreeText($imperialDecreeText);

        return $this->runAtomically(function () use (
            $decreeId,
            $spellId,
            $adminId,
            $decreeType,
            $imperialDecreeText,
            $decreedAtUtc
        ): array {
            // El actuante ha de existir: sin identidad no hay transparencia, y
            // la bitácora exige su alias y su rol.
            $actor = $this->findActor($adminId);

            $statement = $this->pdo->prepare(
                'INSERT INTO sovereign_decrees (
                     id, spell_id, admin_id, decree_type, imperial_decree_text, decreed_at
                 ) VALUES (
                     :decreeId, :spellId, :adminId, :decreeType, :imperialDecreeText, :decreedAt
                 )'
            );
            $statement->execute([
                ':decreeId'           => $decreeId,
                ':spellId'            => $spellId,
                ':adminId'            => $adminId,
                ':decreeType'         => $decreeType,
                ':imperialDecreeText' => $imperialDecreeText,
                ':decreedAt'          => $decreedAtUtc,
            ]);

            // La memoria pública del decreto, por el único canal autorizado.
            $this->auditService->recordAction(
                $actor['id'],
                $actor['alias'],
                $actor['role'],
                self::AUDIT_ACTION_BY_DECREE_TYPE[$decreeType],
                self::TARGET_ENTITY_TYPE,
                $spellId,
                $imperialDecreeText,
                new DateTimeImmutable($decreedAtUtc)
            );

            $decree = $this->findDecreeById($decreeId);
            if ($decree === null) {
                throw new RuntimeException(
                    'El decreto recién inscrito no pudo releerse: la base violó su propio contrato.'
                );
            }

            return $decree;
        });
    }

    /**
     * Recupera un decreto por su identificador.
     *
     * @return array{
     *   id: string, spell_id: string, admin_id: string, decree_type: string,
     *   imperial_decree_text: string, decreed_at: string
     * }|null
     */
    public function findDecreeById(string $decreeId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::DECREE_COLUMNS . '
               FROM sovereign_decrees
              WHERE id = :decreeId'
        );
        $statement->execute([':decreeId' => $decreeId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Historial de decretos de un conjuro, del más antiguo al más reciente.
     *
     * Una obra puede ser rescatada, consagrada de oficio y finalmente
     * desterrada: el historial entero es lo que permite leer la intervención
     * del Administrador como lo que fue —una secuencia de actos razonados— y
     * no como un veredicto sin pasado (RF-04.5).
     *
     * @return list<array{
     *   id: string, spell_id: string, admin_id: string, decree_type: string,
     *   imperial_decree_text: string, decreed_at: string
     * }>
     */
    public function findDecreesBySpell(string $spellId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::DECREE_COLUMNS . '
               FROM sovereign_decrees
              WHERE spell_id = :spellId
              ORDER BY decreed_at ASC, id ASC'
        );
        $statement->execute([':spellId' => $spellId]);

        $decrees = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decrees[] = $this->hydrate($row);
        }

        return $decrees;
    }

    /**
     * Último decreto dictado sobre un conjuro, con su edicto íntegro.
     *
     * Es la lectura del pergamino soberano que la ficha pública exhibe junto a
     * la obra. El desempate por identificador descendente garantiza que «el
     * último» sea siempre la misma fila cuando dos decretos comparten milésima
     * (RNF-01).
     *
     * @return array{
     *   id: string, spell_id: string, admin_id: string, decree_type: string,
     *   imperial_decree_text: string, decreed_at: string
     * }|null
     */
    public function findLatestDecreeBySpell(string $spellId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::DECREE_COLUMNS . '
               FROM sovereign_decrees
              WHERE spell_id = :spellId
              ORDER BY decreed_at DESC, id DESC
              LIMIT 1'
        );
        $statement->execute([':spellId' => $spellId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Decretos dictados por un Administrador, del más reciente al más antiguo.
     *
     * Sostiene el censo de la potestad soberana: cuántas veces intervino el
     * Cónclave Supremo y con qué fundamento (RF-06.1).
     *
     * @return list<array{
     *   id: string, spell_id: string, admin_id: string, decree_type: string,
     *   imperial_decree_text: string, decreed_at: string
     * }>
     */
    public function findDecreesByAdmin(string $adminId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::DECREE_COLUMNS . '
               FROM sovereign_decrees
              WHERE admin_id = :adminId
              ORDER BY decreed_at DESC, id DESC'
        );
        $statement->execute([':adminId' => $adminId]);

        $decrees = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decrees[] = $this->hydrate($row);
        }

        return $decrees;
    }

    /**
     * Cuenta las intervenciones soberanas que ha sufrido un conjuro.
     *
     * @return int Decretos inscritos sobre la obra.
     */
    public function countDecreesBySpell(string $spellId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS decrees
               FROM sovereign_decrees
              WHERE spell_id = :spellId'
        );
        $statement->execute([':spellId' => $spellId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Identidad del actuante en el instante del decreto.
     *
     * La bitácora guarda el alias público y el rol técnico del momento; el
     * repositorio solo recibe el identificador, así que los resuelve aquí,
     * dentro de la misma transacción, para que la firma del llamante no
     * arrastre datos de presentación que podrían llegar falseados.
     *
     * @return array{id: string, alias: string, role: string}
     *
     * @throws RuntimeException Si el actuante no existe en el santuario.
     */
    private function findActor(string $adminId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, alias, role FROM users WHERE id = :adminId'
        );
        $statement->execute([':adminId' => $adminId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new RuntimeException(
                'Todo decreto imperial exige un Administrador Supremo inscrito en el santuario.'
            );
        }

        return [
            'id'    => (string) $row['id'],
            'alias' => (string) $row['alias'],
            'role'  => (string) $row['role'],
        ];
    }

    /**
     * Ejecuta las dos escrituras del decreto como una sola operación atómica,
     * plegándose a la transacción del llamante si ya hubiera una abierta.
     *
     * Permite que `SovereignAdminService` (Tarea 2.5) componga «decretar +
     * transicionar el expediente + liquidar los PDA» como un único gesto
     * confirmable o reversible, sin anidar transacciones que SQLite no admite.
     */
    private function runAtomically(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $operation();
        }

        $this->pdo->beginTransaction();

        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $failure) {
            $this->pdo->rollBack();

            throw $failure;
        }
    }

    /**
     * Vela por el canon de los cuatro decretos de RF-04.
     *
     * @throws InvalidArgumentException Si el decreto no pertenece al canon.
     */
    private function assertDecreeType(string $decreeType): void
    {
        if (!in_array($decreeType, self::CANONICAL_DECREE_TYPES, true)) {
            throw new InvalidArgumentException(
                'El Administrador Supremo solo dicta los cuatro decretos canónicos: '
                . implode(', ', self::CANONICAL_DECREE_TYPES) . '.'
            );
        }
    }

    /**
     * Vela por la solemnidad del Edicto Imperial (RF-04.5).
     *
     * Se mide sobre el texto recortado de espacios extremos y en caracteres
     * —no en bytes— porque el edicto se redacta en castellano.
     *
     * @throws InvalidArgumentException Si el edicto no alcanza el umbral.
     */
    private function assertImperialDecreeText(string $imperialDecreeText): void
    {
        if (mb_strlen(trim($imperialDecreeText)) < self::MIN_IMPERIAL_DECREE_LENGTH) {
            throw new InvalidArgumentException(
                'Todo Decreto Imperial exige un edicto de justificación de al menos '
                . self::MIN_IMPERIAL_DECREE_LENGTH . ' caracteres: el Cónclave Supremo no actúa en silencio.'
            );
        }
    }

    /**
     * Proyecta una fila de `sovereign_decrees` al contrato canónico.
     *
     * @param array<string, mixed> $row Fila cruda del motor de datos.
     *
     * @return array{
     *   id: string, spell_id: string, admin_id: string, decree_type: string,
     *   imperial_decree_text: string, decreed_at: string
     * }
     */
    private function hydrate(array $row): array
    {
        return [
            'id'                  => (string) $row['id'],
            'spell_id'            => (string) $row['spell_id'],
            'admin_id'            => (string) $row['admin_id'],
            'decree_type'         => (string) $row['decree_type'],
            'imperial_decree_text' => (string) $row['imperial_decree_text'],
            'decreed_at'          => (string) $row['decreed_at'],
        ];
    }
}
