<?php

declare(strict_types=1);

/**
 * test_tome_audit.php — Verificación de la Tarea 3.2 de TASKS-11.
 *
 * Valida LOS ASIENTOS DE BITÁCORA DEL TOMO (RF-06.1, RF-06.2; plan §2.3)
 * contra el «Hecho cuando» de la tarea:
 *
 *   1. Un sellado produce un `TOME_SEAL` con estampa temporal
 *      (justificación canónica: «{alias} selló {hechizo} en su tomo
 *      personal»).
 *   2. Un elogio con gloria produce un `TOME_PRAISE` que NOMBRA adepto,
 *      obra y clan («{alias} rindió homenaje a {hechizo}, granjeando
 *      gloria a {clan}» — hallazgo 20).
 *   3. El segundo elogio (eco idempotente) y el recibo denegado
 *      (OWN_CLAN_FAVORITE) NO añaden filas a la Bitácora.
 *
 * Extra: el catálogo de AuditEntry inscribe los dos actos (guard del
 * Artículo V y del RF-08.1) y la estampa temporal es la del instante
 * del rito, jamás posterior.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sondas desechables.
 *   - Artículo III: la Bitácora es imborrable; los asientos se cuentan
 *     leyendo audit_log directamente.
 *   - Artículo V (Dualidad): asertos en inglés, narrativa en castellano.
 *
 * Uso: php scratch/test_tome_audit.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../src/Models/AuditEntry.php';
require __DIR__ . '/../src/Models/User.php';
require __DIR__ . '/../src/Models/Spell.php';
require __DIR__ . '/../src/Dto/GrimoirePageDto.php';
require __DIR__ . '/../src/Core/Response.php';
require __DIR__ . '/../src/Core/Request.php';
require __DIR__ . '/../src/Core/Router.php';
require __DIR__ . '/../src/Repositories/ClanRepository.php';
require __DIR__ . '/../src/Repositories/GrimoireCollectionRepository.php';
require __DIR__ . '/../src/Repositories/ClanMemberRepository.php';
require __DIR__ . '/../src/Repositories/WeeklyCycleRepository.php';
require __DIR__ . '/../src/Dto/LineageDto.php';
require __DIR__ . '/../src/Dto/ClanDto.php';
require __DIR__ . '/../src/Dto/ClanMemberDto.php';
require __DIR__ . '/../src/Dto/DominionAwardDto.php';
require __DIR__ . '/../src/Dto/WeeklyCycleDto.php';
require __DIR__ . '/../src/Exceptions/ClanGovernanceException.php';
require __DIR__ . '/../src/Exceptions/LineageOathException.php';
require __DIR__ . '/../src/Exceptions/SpellNotFoundException.php';
require __DIR__ . '/../src/Exceptions/SpellNotInTomeException.php';
require __DIR__ . '/../src/Exceptions/SpellNotValidatedException.php';
require __DIR__ . '/../src/Exceptions/UniformSealVetoException.php';
require __DIR__ . '/../src/Services/AuditRecorderInterface.php';
require __DIR__ . '/../src/Services/AuditService.php';
require __DIR__ . '/../src/Services/LineageSynergyService.php';
require __DIR__ . '/../src/Services/WeeklyDominionService.php';
require __DIR__ . '/../src/Services/GrimoireCollectionService.php';
require __DIR__ . '/../src/Dto/CollectionEntryDto.php';
require __DIR__ . '/../src/Dto/CollectionPageDto.php';
require __DIR__ . '/../src/Services/GrimoireQueryService.php';
require __DIR__ . '/../src/Controllers/GrimoireCollectionController.php';

use Grimorio\Controllers\GrimoireCollectionController;
use Grimorio\Core\Request;
use Grimorio\Core\Router;
use Grimorio\Models\User;
use Grimorio\Repositories\GrimoireCollectionRepository;
use Grimorio\Services\AuditService;
use Grimorio\Services\GrimoireCollectionService;
use Grimorio\Services\GrimoireQueryService;
use Grimorio\Services\WeeklyDominionService;

$assertsPassed = 0;
$assertsFailed = 0;

/** Aserta una condición y registra el resultado en la bitácora de consola. */
function assertCondition(bool $condition, string $description): void
{
    global $assertsPassed, $assertsFailed;
    if ($condition) {
        $assertsPassed++;
        echo "  [PASA] {$description}\n";
    } else {
        $assertsFailed++;
        echo "  [FALLA] {$description}\n";
    }
}

/**
 * Despacha una petición POST por el Router real con usuario opcional.
 */
function dispatchPost(Router $router, string $path, ?User $user, ?string $rawBody): object
{
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = $path;
    $_COOKIE = [];

    $request = new Request('POST', $path, [], [], $rawBody);
    if ($user !== null) {
        $request->setUser($user);
    }

    return $router->dispatch($request);
}

/** Despacha una petición POST de sellado por el Router real. */
function dispatchCollect(Router $router, string $path, ?User $user, ?string $rawBody): object
{
    return dispatchPost($router, $path, $user, $rawBody);
}

/** Funda un mago contra el esquema canónico real. */
function seedWizard(PDO $connection, string $userId, string $alias, ?string $lineage): void
{
    $connection->prepare(
        'INSERT INTO users (id, alias, email, password_hash, lineage, created_at, updated_at)
         VALUES (:id, :alias, :email, :hash, :lineage, :created, :updated)'
    )->execute([
        ':id'      => $userId,
        ':alias'   => $alias,
        ':email'   => $alias . '@santuario.test',
        ':hash'    => str_repeat('a', 60),
        ':lineage' => $lineage,
        ':created' => '2026-09-22T00:00:00Z',
        ':updated' => '2026-09-22T00:00:00Z',
    ]);
}

/** Funda una hermandad con contadores de gloria arbitrarios. */
function seedHouse(PDO $connection, string $clanId, string $lineageType): void
{
    $connection->prepare(
        'INSERT INTO clans (id, slug, name, motto, created_at, coat_of_arms, lineage_type,
                            admission_mode, status, patriarch_id, weekly_points, historical_points,
                            last_activity_at, updated_at)
         VALUES (:id, :id, :id, :motto, :createdAt, :arms, :lineageType,
                 :mode, :status, NULL, 0, 0, :stamp, :stamp)'
    )->execute([
        ':id'          => $clanId,
        ':motto'       => 'Lema de prueba',
        ':createdAt'   => '2026-01-01T00:00:00Z',
        ':arms'        => 'rune_test',
        ':lineageType' => $lineageType,
        ':mode'        => 'open',
        ':status'      => 'active',
        ':stamp'       => '2026-09-01T00:00:00Z',
    ]);
}

/** Inscribe a un mago en una casa como adepto activo (militancia). */
function seedMembership(PDO $connection, string $memberId, string $clanId, string $userId): void
{
    $connection->prepare(
        'INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at, convalescence_expires_at)
         VALUES (:id, :clanId, :userId, :role, :joinedAt, NULL, NULL)'
    )->execute([
        ':id'       => $memberId,
        ':clanId'   => $clanId,
        ':userId'   => $userId,
        ':role'     => 'adept',
        ':joinedAt' => '2026-02-01T00:00:00Z',
    ]);
}

/** Forja un conjuro con su casa, círculo, afinidad y estado. */
function seedSpell(PDO $connection, string $spellId, string $clanId, string $authorId, string $element, string $status, string $name = 'Conjuro'): void
{
    $connection->prepare(
        'INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, casting_time,
                             mana_cost, circle, math_fingerprint, clan_id, summary, status,
                             validation_signatures_count, signatures_count, created_at, updated_at, validated_at)
         VALUES (:id, :id, :name, :authorId, :school, :element, :castingTime,
                 :manaCost, :circle, :fingerprint, :clanId, :summary, :status,
                 0, 0, :stamp, :stamp, :validatedAt)'
    )->execute([
        ':id'          => $spellId,
        ':name'        => $name,
        ':authorId'    => $authorId,
        ':school'      => 'evocation',
        ':element'     => $element,
        ':castingTime' => 'action',
        ':manaCost'    => 10,
        ':circle'      => 1,
        ':fingerprint' => str_repeat('f', 64),
        ':clanId'      => $clanId,
        ':summary'     => 'Resumen de prueba',
        ':status'      => $status,
        ':stamp'       => '2026-09-01T00:00:00Z',
        ':validatedAt' => $status === 'validated' ? '2026-09-10T10:00:00Z' : null,
    ]);
}

/** Lee los asientos de la Bitácora por tipo de acción. */
function auditRows(PDO $connection, string $actionType): array
{
    return $connection->prepare('SELECT actor_user_id, actor_alias, actor_role, action_type,
                                        target_entity_type, target_entity_id, justification, created_at
                                   FROM audit_log WHERE action_type = :type ORDER BY id')
        ->execute === null ? [] : [];
}

/** Versión ejecutable de la lectura de asientos (execute devuelve stmt). */
function auditEntries(PDO $connection, string $actionType): array
{
    $statement = $connection->prepare(
        'SELECT actor_user_id, actor_alias, actor_role, action_type,
                target_entity_type, target_entity_id, justification, created_at
           FROM audit_log
          WHERE action_type = :type
          ORDER BY id'
    );
    $statement->execute([':type' => $actionType]);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/** Cuenta TODOS los asientos de la Bitácora (cualquier acción). */
function auditTotal(PDO $connection): int
{
    return (int) $connection->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
}

echo "=== Asientos de Bitácora del Tomo — Tarea 3.2 de TASKS-11 ===\n";

echo "\n[FASE 0] Superficie: el catálogo inscribe los dos actos.\n";
$auditSource = (string) file_get_contents(__DIR__ . '/../src/Models/AuditEntry.php');
assertCondition(str_contains($auditSource, "'TOME_SEAL'"), 'El catálogo canónico inscribe TOME_SEAL (RF-06.1).');
assertCondition(str_contains($auditSource, "'TOME_PRAISE'"), 'El catálogo canónico inscribe TOME_PRAISE (RF-06.2).');
$controllerSource = (string) file_get_contents(__DIR__ . '/../src/Controllers/GrimoireCollectionController.php');
assertCondition(str_contains($controllerSource, 'TOME_PRAISE'), 'La puerta del elogio asienta TOME_PRAISE con gloria (plan §2.3).');
assertCondition(str_contains($controllerSource, 'wasAwarded()'), 'La puerta juzga el recibo con wasAwarded() (solo gloria nueva asienta).');

// ---------------------------------------------------------------------
// Base canónica real + Router con las dos rutas del tomo.
// ---------------------------------------------------------------------
$probePath = __DIR__ . '/__probe_gc_audit.sqlite';
@unlink($probePath);
$connection = new PDO('sqlite:' . $probePath);
$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$connection->exec('PRAGMA foreign_keys = ON');
$connection->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));
$connection->prepare("INSERT OR IGNORE INTO magic_schools (slug, name) VALUES ('evocation', 'Evocación')")->execute();

seedHouse($connection, 'cln-flame', 'primordialFlame');
$connection->prepare("UPDATE clans SET name = 'Casa de la Llama' WHERE id = 'cln-flame'")->execute();
seedHouse($connection, 'cln-tides', 'celestialTides');

seedWizard($connection, 'usr-author-flame', 'Autor Llama', 'primordialFlame');
seedWizard($connection, 'usr-visitor', 'Visitante Agua', 'celestialTides');
seedWizard($connection, 'usr-member-flame', 'Morador Llama', 'primordialFlame');

seedMembership($connection, 'mem-flame-1', 'cln-flame', 'usr-member-flame');

seedSpell($connection, 'spl-audit-fire', 'cln-flame', 'usr-author-flame', 'fire', 'validated', 'Llama Eterna');
seedSpell($connection, 'spl-audit-second', 'cln-flame', 'usr-author-flame', 'fire', 'validated', 'Brasa Durmiente');

$auditService = new AuditService($connection);
$weeklyDominion = new WeeklyDominionService($connection, $auditService);
$service = new GrimoireCollectionService(
    new GrimoireCollectionRepository($connection),
    $auditService,
    $connection,
);
$controller = new GrimoireCollectionController($service, new GrimoireQueryService($connection), $weeklyDominion, $auditService);

$router = new Router();
$router->addRoute('POST', '/api/v1/grimoire/praise', fn (Request $request): object => $controller->praiseSpell($request));

$visitor = new User('usr-visitor', 'Visitante Agua', 'agua@santuario.test', 'editor', null, 'celestialTides');
$memberFlame = new User('usr-member-flame', 'Morador Llama', 'morador@santuario.test', 'editor', 'cln-flame', 'primordialFlame');

echo "\n[FASE 1] El sellado produce un TOME_SEAL con estampa temporal (RF-06.1).\n";
assertCondition(auditTotal($connection) === 0, 'La Bitácora nace vacía: cero asientos previos.');
// El sellado vive aún en el servicio (su endpoint REST llega en la Tarea 4.1):
// la Tarea 2.2 ya lo puso en verde; aquí solo importa el ASIENTO que deja.
$collectResult = $service->collectSpell($visitor, 'spl-audit-fire', 'validated');
assertCondition(($collectResult['alreadyCollected'] ?? true) === false, 'El sellado feliz concluye como sellado nuevo.');
$seals = auditEntries($connection, 'TOME_SEAL');
assertCondition(count($seals) === 1, 'El sellado deja EXACTAMENTE un asiento TOME_SEAL.');
$seal = $seals[0] ?? [];
assertCondition(
    ($seal['actor_user_id'] ?? '') === 'usr-visitor' && ($seal['actor_alias'] ?? '') === 'Visitante Agua',
    'El asiento nombra al adepto que sella (id y alias).',
);
assertCondition(
    ($seal['target_entity_type'] ?? '') === 'spell' && ($seal['target_entity_id'] ?? '') === 'spl-audit-fire',
    'El objetivo del asiento es el hechizo sellado (spell + id).',
);
assertCondition(
    ($seal['created_at'] ?? '') !== '' && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', (string) $seal['created_at']) === 1,
    'El asiento porta estampa temporal ISO 8601.',
);

echo "\n[FASE 2] El eco idempotente del sellado NO añade asiento (RF-06.1).\n";
$recollect = $service->collectSpell($visitor, 'spl-audit-fire', 'validated');
assertCondition(($recollect['alreadyCollected'] ?? false) === true, 'El re-sellado responde idempotente (Ya está en tu tomo).');
assertCondition(count(auditEntries($connection, 'TOME_SEAL')) === 1, 'El eco idempotente no duplica el TOME_SEAL.');

echo "\n[FASE 3] El elogio con gloria produce un TOME_PRAISE que nombra adepto, obra y clan (RF-06.2).\n";
$response = dispatchPost($router, '/api/v1/grimoire/praise', $visitor, '{"spellId":"spl-audit-fire"}');
assertCondition($response->getStatusCode() === 200, 'El elogio feliz concluye en 200.');
$praises = auditEntries($connection, 'TOME_PRAISE');
assertCondition(count($praises) === 1, 'El elogio con gloria deja EXACTAMENTE un asiento TOME_PRAISE.');
$praise = $praises[0] ?? [];
assertCondition(
    ($praise['actor_user_id'] ?? '') === 'usr-visitor' && ($praise['actor_alias'] ?? '') === 'Visitante Agua',
    'El asiento nombra al adepto que elogia (id y alias).',
);
assertCondition(
    ($praise['target_entity_type'] ?? '') === 'spell' && ($praise['target_entity_id'] ?? '') === 'spl-audit-fire',
    'El objetivo del asiento es el hechizo elogiado (spell + id).',
);
$justification = (string) ($praise['justification'] ?? '');
assertCondition(
    str_contains($justification, 'Visitante Agua') && str_contains($justification, 'Llama Eterna')
    && str_contains($justification, 'Casa de la Llama') !== false && str_contains($justification, 'granjeando gloria'),
    'La justificación nombra adepto, obra y casa (hallazgo 20, plan §2.3).',
);
assertCondition(
    ($praise['created_at'] ?? '') !== '' && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', (string) $praise['created_at']) === 1,
    'El asiento porta estampa temporal ISO 8601.',
);

echo "\n[FASE 4] El eco idempotente del elogio NO añade asiento (RF-06.2).\n";
$response = dispatchPost($router, '/api/v1/grimoire/praise', $visitor, '{"spellId":"spl-audit-fire"}');
assertCondition($response->getStatusCode() === 200, 'El segundo elogio responde 200 (ALREADY_PRAISED).');
assertCondition(count(auditEntries($connection, 'TOME_PRAISE')) === 1, 'El eco idempotente no duplica el TOME_PRAISE.');
assertCondition(auditTotal($connection) === 2, 'La Bitácora conserva exactamente los dos asientos de los dos actos.');

echo "\n[FASE 5] El recibo denegado (militancia) NO añade asiento (RF-06.2, hallazgo 20).\n";
$response = dispatchPost($router, '/api/v1/grimoire/praise', $memberFlame, '{"spellId":"spl-audit-second"}');
assertCondition($response->getStatusCode() === 200, 'El militante recibe su recibo denegado 200 (OWN_CLAN_FAVORITE).');
assertCondition(
    (json_decode($response->getBody(), true)['data']['reason'] ?? '') === 'OWN_CLAN_FAVORITE',
    'El sobre porta la denegación de militancia.',
);
assertCondition(count(auditEntries($connection, 'TOME_PRAISE')) === 1, 'El recibo denegado no asienta acto alguno.');
assertCondition(auditTotal($connection) === 2, 'La Bitácora sigue en dos asientos: el denegado jamás deja rastro.');

echo "\n[FASE 6] Un segundo elogio de otro adepto SÍ asienta su propio TOME_PRAISE.\n";
seedWizard($connection, 'usr-second-praiser', 'Segundo Homenaje', 'celestialTides');
$second = new User('usr-second-praiser', 'Segundo Homenaje', 'segundo@santuario.test', 'editor', null, 'celestialTides');
$response = dispatchPost($router, '/api/v1/grimoire/praise', $second, '{"spellId":"spl-audit-second"}');
assertCondition($response->getStatusCode() === 200, 'El segundo adepto elogia con gloria (200 AWARDED).');
assertCondition(count(auditEntries($connection, 'TOME_PRAISE')) === 2, 'Cada elogio con gloria asienta su PROPIO asiento (uno por acto).');

// Limpieza de sondas desechables.
@unlink($probePath);
@unlink(str_replace('.sqlite', '-wal', $probePath));
@unlink(str_replace('.sqlite', '-shm', $probePath));

echo "\n=== RESULTADO: {$assertsPassed} pasan, {$assertsFailed} fallan ===\n";
exit($assertsFailed === 0 ? 0 : 1);
