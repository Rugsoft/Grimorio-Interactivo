<?php

/**
 * test_spell_publish.php — Arnés TDD de la Tarea 3.2 (TASKS-04).
 *
 * Verifica publishToExperimental(User, string): validación de autoría,
 * revalidación ciega de la fórmula matemática (SpellBalanceService),
 * transición draft → experimental e inicialización de firmas a 0/3
 * (RF-05.2, RNF-03).
 *
 * Criterio «Hecho cuando» (Tarea 3.2): el conjuro publicado transiciona
 * de draft a experimental, sus firmas quedan en 0/3 y el registro pasa a
 * ser visible públicamente en el santuario.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, SQLite en memoria.
 *   - Artículo II: el maná publicado es el del backend, no el del cliente.
 *   - Artículo III: solo el autor publica; el moderación nace a 0/3.
 *   - Artículo V: identificadores en inglés camelCase, comentarios en
 *     castellano.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Models/User.php';
require __DIR__ . '/../src/Dto/SpellCalculationInputDto.php';
require __DIR__ . '/../src/Dto/SpellCalculationResultDto.php';
require __DIR__ . '/../src/Dto/SpellCreateDto.php';
require __DIR__ . '/../src/Exceptions/ArcaneOverloadException.php';
require __DIR__ . '/../src/Exceptions/DraftQuotaExceededException.php';
require __DIR__ . '/../src/Models/AuditEntry.php';
require __DIR__ . '/../src/Services/AuditLogPage.php';
require __DIR__ . '/../src/Services/AuditService.php';
require __DIR__ . '/../src/Services/SpellBalanceService.php';
require __DIR__ . '/../src/Exceptions/SpellNotFoundException.php';
require __DIR__ . '/../src/Services/SpellManagementService.php';
require __DIR__ . '/../src/Models/Spell.php';
require __DIR__ . '/../src/Database/Connection.php';
require __DIR__ . '/../src/Services/SpellDiscoveryService.php';

use Grimorio\Dto\SpellCalculationInputDto;
use Grimorio\Dto\SpellCreateDto;
use Grimorio\Models\User;
use Grimorio\Services\SpellManagementService;

$assertsPassed = 0;
$assertsFailed = 0;

function assertArcane(bool $condition, string $legend): void
{
    global $assertsPassed, $assertsFailed;
    if ($condition) {
        $assertsPassed++;
        echo "  OK   {$legend}\n";
        return;
    }
    $assertsFailed++;
    echo "  FALLA {$legend}\n";
}

function catchException(callable $fn): ?Throwable
{
    try {
        $fn();
        return null;
    } catch (Throwable $exception) {
        return $exception;
    }
}

function forgeCreateDto(string $name, int $damage = 30): SpellCreateDto
{
    return new SpellCreateDto(
        name: $name,
        elementalAffinity: 'fire',
        magicSchool: 'evocation',
        castingTime: 'action',
        description: 'Conjuro del arnés de publicación.',
        calculationInput: new SpellCalculationInputDto(
            damage: $damage,
            healing: 0,
            barrier: 0,
            crowdControlType: 'none',
            rangeType: 'medium',
            areaType: 'sphere',
            durationType: 'instant',
            hasVerbal: true,
            hasSomatic: true,
            hasMaterial: false,
        ),
    );
}

echo "=== Tarea 3.2 (TASKS-04): publicación a experimental ===\n\n";

// =====================================================================
// Escenario: SQLite en memoria con el esquema real.
// =====================================================================
$projectRoot = dirname(__DIR__);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

$now = '2026-09-13T12:00:00Z';
$pdo->exec(
    "INSERT INTO clans (id, slug, name, motto, domain_points, created_at)
     VALUES ('cln_astral', 'astral-scholars', 'Eruditos Astrales', 'Saber', 0, '{$now}')"
);
$pdo->exec(
    "INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
     VALUES ('usr_autor', 'AutorDelBorrador', 'autor@sanctuario.arc', 'x', 'editor', 'cln_astral', '{$now}', '{$now}')"
);
$pdo->exec(
    "INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
     VALUES ('usr_ajeno', 'AjenoAlBorrador', 'ajeno@sanctuario.arc', 'x', 'editor', 'cln_astral', '{$now}', '{$now}')"
);
$pdo->exec("INSERT INTO magic_schools (slug, name) VALUES ('evocation', 'Evocación')");

$author = new User('usr_autor', 'AutorDelBorrador', 'autor@sanctuario.arc', 'editor', 'cln_astral', 'x', $now, $now);
$foreignAuthor = new User('usr_ajeno', 'AjenoAlBorrador', 'ajeno@sanctuario.arc', 'editor', 'cln_astral', 'x', $now, $now);

$service = new SpellManagementService($pdo);

// =====================================================================
// [0] Superficie (fase roja): el método existe.
// =====================================================================
echo "[0] Superficie\n";
assertArcane(
    method_exists(SpellManagementService::class, 'publishToExperimental'),
    'SpellManagementService expone publishToExperimental()'
);

// =====================================================================
// [1] Transición draft → experimental con revalidación ciega.
// =====================================================================
echo "\n[1] Transición draft → experimental (RF-05.2)\n";

$draft = $service->createDraft($author, forgeCreateDto('Llama del Umbral', damage: 30));
$draftId = $draft['id'];

// Simulación de manipulación del cliente: el maná del borrador se corrompe
// en BD; la publicación DEBE revalidar e imponer el cálculo verídico (48).
// (999 viola el CHECK del esquema, así que la corrupción usa 150: dentro
// del dominio físico pero ajeno al cálculo verídico.)
$pdo->prepare('UPDATE spells SET mana_cost = 150 WHERE id = :id')->execute([':id' => $draftId]);

$published = $service->publishToExperimental($author, $draftId);

assertArcane($published['status'] === 'experimental', 'El conjuro transiciona de draft a experimental');
assertArcane($published['manaCost'] === 48, 'La fórmula se revalida CIEGAMENTE: el maná corrupto (150) se sustituye por el verídico (48)');
assertArcane($published['signaturesCount'] === 0, 'Las firmas quedan inicializadas a 0/3');
assertArcane($published['mathFingerprint'] === hash('sha256', '30:0:0:none:medium:sphere:instant:1:1:0'), 'La huella matemática queda re-validada');

$rowStatement = $pdo->prepare('SELECT status, mana_cost, circle, signatures_count FROM spells WHERE id = :id');
$rowStatement->execute([':id' => $draftId]);
$publishedRow = $rowStatement->fetch(PDO::FETCH_ASSOC);
assertArcane(
    is_array($publishedRow) && $publishedRow['status'] === 'experimental' && (int) $publishedRow['mana_cost'] === 48 && (int) $publishedRow['circle'] === 3,
    'La fila persistida porta el estado experimental con el cálculo verídico del backend'
);

// =====================================================================
// [2] Visibilidad pública del registro publicado (criterio).
// =====================================================================
echo "\n[2] Visibilidad pública del experimental\n";

// El SpellDiscoveryService del santuario lista experimentales bajo la
// bandera includeExperimental: se verifica que la consulta pública con
// esa bandera lo encuentra y que el catálogo validado no lo ve. El
// Singleton Connection se apunta a la BD del arnés vía DSN de entorno.
putenv('GRIMORIO_DB_DSN=sqlite::memory:');
Grimorio\Database\Connection::resetInstance();
$discoveryConnection = Grimorio\Database\Connection::getInstance();
// El Singleton abre SU propia memoria: se reinyecta el esquema y fila.
$discoveryPdo = $discoveryConnection->getPdo();
$discoveryPdo->exec('PRAGMA foreign_keys = ON');
$discoveryPdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
$discoveryPdo->exec(
    "INSERT INTO clans (id, slug, name, motto, domain_points, created_at)
     VALUES ('cln_astral', 'astral-scholars', 'Eruditos Astrales', 'Saber', 0, '{$now}')"
);
$discoveryPdo->exec(
    "INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
     VALUES ('usr_autor', 'AutorDelBorrador', 'autor@sanctuario.arc', 'x', 'editor', 'cln_astral', '{$now}', '{$now}')"
);
$discoveryPdo->exec("INSERT OR IGNORE INTO clans (id, slug, name, motto, domain_points, created_at)
     VALUES ('cln_astral', 'astral-scholars', 'Eruditos Astrales', 'Saber', 0, '{$now}')");
$discoveryPdo->exec("INSERT OR IGNORE INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
     VALUES ('usr_autor', 'AutorDelBorrador', 'autor@sanctuario.arc', 'x', 'editor', 'cln_astral', '{$now}', '{$now}')");
$discoveryPdo->exec("INSERT OR IGNORE INTO magic_schools (slug, name) VALUES ('evocation', 'Evocación')");

// Réplica del conjuro ya publicado en la BD del servicio de descubrimiento.
$rowToReplicate = $pdo->prepare('SELECT * FROM spells WHERE id = :id');
$rowToReplicate->execute([':id' => $draftId]);
$replicaRow = $rowToReplicate->fetch(PDO::FETCH_ASSOC);
$replicaColumns = array_keys($replicaRow);
$replicaPlaceholders = array_map(static fn (string $column): string => ':' . $column, $replicaColumns);
$discoveryPdo->prepare(
    'INSERT INTO spells (' . implode(', ', $replicaColumns) . ') VALUES (' . implode(', ', $replicaPlaceholders) . ')'
)->execute(array_combine($replicaPlaceholders, array_values($replicaRow)));

$discovery = new Grimorio\Services\SpellDiscoveryService($discoveryConnection);

$catalogWithoutExperimental = $discovery->getSpells(includeExperimental: false);
$slugsValidated = array_map(
    static fn ($spell): string => $spell->getSlug(),
    $catalogWithoutExperimental['items'] ?? [],
);
assertArcane(!in_array('llama-del-umbral', $slugsValidated, true), 'El catálogo validado (lectores) NO muestra el experimental');

$catalogWithExperimental = $discovery->getSpells(includeExperimental: true);
$slugsAll = array_map(
    static fn ($spell): string => $spell->getSlug(),
    $catalogWithExperimental['items'] ?? [],
);
assertArcane(in_array('llama-del-umbral', $slugsAll, true), 'El santuario (con experimentales) ya lista el conjuro publicado');

// =====================================================================
// [3] Defensas: autoría, estado e inexistencia.
// =====================================================================
echo "\n[3] Defensas de autoría, estado e inexistencia\n";

$foreignPublish = catchException(static fn () => $service->publishToExperimental($foreignAuthor, $draftId));
assertArcane($foreignPublish instanceof RuntimeException, 'Un autor ajeno no puede publicar el borrador ajeno');

$doublePublish = catchException(static fn () => $service->publishToExperimental($author, $draftId));
assertArcane($doublePublish instanceof RuntimeException, 'Publicar un conjuro ya experimental se rechaza (solo drafts)');

$ghostPublish = catchException(static fn () => $service->publishToExperimental($author, 'spl_inexistente'));
assertArcane($ghostPublish instanceof RuntimeException, 'Publicar un identificador inexistente se rechaza');

// La cuota de borradores liberó un hueco al publicar (RF-05.1 coherente).
$freedSlotDraft = $service->createDraft($author, forgeCreateDto('Hueco Liberado', damage: 5));
assertArcane(str_starts_with($freedSlotDraft['id'], 'spl_'), 'Tras publicar, el hueco de cuota queda liberado para nuevos borradores');

// =====================================================================
// Resumen final.
// =====================================================================
echo "\n=== RESUMEN: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);
