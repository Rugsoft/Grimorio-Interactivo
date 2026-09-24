<?php

/**
 * test_membership_single_source.php — Verificación de la reconciliación de la
 * afiliación de clan (Tarea 2.3, TASKS-07).
 *
 * Antes convivían TRES fuentes de verdad sobre «el clan actual de un mago»:
 *   1. `users.clan_id`  — columna NOT NULL escrita por AuthService.
 *   2. `clan_history`   — historial que ninguna clase de src/ escribía.
 *   3. `clan_members`   — historial de SPEC-07, con escritor real.
 *
 * Este arnés demuestra que tras la reconciliación hay UNA sola autoridad
 * (`clan_members`) y que el Artículo III y la fundación de clanes funcionan
 * de verdad. Fases:
 *
 *   [0] El DDL canónico declara la afiliación anulable y con su autoridad.
 *   [1] El espejo `users.clan_id` sigue a la autoridad al contraer y al
 *       cerrar una membresía (sin divergencia observable).
 *   [2] Un mago puede consagrarse SIN linaje y quedar apto para fundar.
 *   [3] El juramento de SPEC-09 sella la identidad arcana; la afiliación
 *       al clan es acto de SPEC-07 (la consagración ya no afilia).
 *   [4] El veto ético juzga los datos VIVOS, no el espejo.
 *   [5] Los dos servicios de conflicto comparten autoridad y veredicto.
 *   [6] La vía de ascenso legada alimenta la autoridad sin perder memoria.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo; cero librerías.
 *   - Artículo III: el historial del linaje sostiene el veto y jamás se borra.
 *   - Artículo V: identificadores en inglés camelCase, narrativa en castellano.
 *
 * Uso: php scratch/test_membership_single_source.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$projectRoot = dirname(__DIR__);

$filesRequired = [
    $projectRoot . '/src/Core/SessionManager.php',
    $projectRoot . '/src/Core/ActiveSession.php',
    $projectRoot . '/src/Models/User.php',
    $projectRoot . '/src/Models/AuditEntry.php',
    $projectRoot . '/src/Exceptions/ClanConflictOfInterestException.php',
    $projectRoot . '/src/Repositories/ClanMemberRepository.php',
    $projectRoot . '/src/Services/AuditLogPage.php',
    // La pluma exige su contrato desde SPEC-11 (Fase 2).
    $projectRoot . '/src/Services/AuditRecorderInterface.php',
    $projectRoot . '/src/Services/AuditService.php',
    $projectRoot . '/src/Services/BindResult.php',
    $projectRoot . '/src/Services/ConsecrationResult.php',
    $projectRoot . '/src/Services/RecoveryResult.php',
    $projectRoot . '/src/Services/AuthService.php',
    $projectRoot . '/src/Services/ClanConflictService.php',
    $projectRoot . '/src/Services/ClanConflictVerdict.php',
    $projectRoot . '/src/Services/ClanEthicsValidator.php',
];
foreach ($filesRequired as $filePath) {
    if (!is_file($filePath)) {
        fwrite(STDERR, '[FATAL] Falta el fichero ' . $filePath . " — fase roja.\n");
        exit(1);
    }
    require_once $filePath;
}

use Grimorio\Core\SessionManager;
use Grimorio\Models\User;
use Grimorio\Repositories\ClanMemberRepository;
use Grimorio\Services\AuthService;
use Grimorio\Services\ClanConflictService;
use Grimorio\Services\ClanEthicsValidator;

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

/** El espejo `users.clan_id` de un mago, tal y como vive en la base. */
function mirroredClan(PDO $pdo, string $userId): ?string
{
    $statement = $pdo->prepare('SELECT clan_id FROM users WHERE id = :userId');
    $statement->execute([':userId' => $userId]);
    $value = $statement->fetchColumn();

    return $value === null || $value === false ? null : (string) $value;
}

/** El clan de la membresía ACTIVA según la autoridad, o null si no milita. */
function authoritativeClan(PDO $pdo, string $userId): ?string
{
    $statement = $pdo->prepare(
        'SELECT clan_id FROM clan_members WHERE user_id = :userId AND left_at IS NULL'
    );
    $statement->execute([':userId' => $userId]);
    $value = $statement->fetchColumn();

    return $value === null || $value === false ? null : (string) $value;
}

$now = new DateTimeImmutable('2026-09-14T12:00:00Z');

// =====================================================================
// FASE 0 · El DDL canónico declara una sola autoridad
// =====================================================================
echo "═══ FASE 0 · El DDL canónico ═══\n";
$schemaSql = (string) file_get_contents($projectRoot . '/database/schema.sql');

$canonical = new PDO('sqlite::memory:');
$canonical->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$canonical->exec('PRAGMA foreign_keys = ON');
$canonical->exec($schemaSql);
$canonical->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));

$userColumns = $canonical->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC);
$clanIdColumn = null;
foreach ($userColumns as $column) {
    if ($column['name'] === 'clan_id') {
        $clanIdColumn = $column;
    }
}
assertCondition($clanIdColumn !== null, 'users.clan_id sigue existiendo (espejo denormalizado)');
assertCondition(
    $clanIdColumn !== null && (int) $clanIdColumn['notnull'] === 0,
    'users.clan_id es ANULABLE: un mago puede no pertenecer a ningún linaje (RF-01.2)'
);

$tables = $canonical->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
assertCondition(in_array('clan_members', $tables, true), 'clan_members nace en el DDL canónico, sin migración previa');

// El mundo sembrado es coherente: el tutor del linaje fundacional pertenece a él.
assertCondition(
    authoritativeClan($canonical, 'usr_custodio_primordial') === 'cln_primordial',
    'Las semillas inscriben al tutor en la AUTORIDAD (clan_members)'
);
assertCondition(
    mirroredClan($canonical, 'usr_custodio_primordial') === 'cln_primordial',
    'Y su espejo concuerda con la autoridad'
);
assertCondition(
    (string) $canonical->query("SELECT patriarch_id FROM clans WHERE id = 'cln_primordial'")->fetchColumn()
        === 'usr_custodio_primordial',
    'El linaje fundacional corona a su Patriarca en las semillas'
);

// =====================================================================
// FASE 1 · El espejo sigue a la autoridad
// =====================================================================
echo "\n═══ FASE 1 · Sincronía del espejo con la autoridad ═══\n";
$canonical->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
                  VALUES ('usr_espejo', 'Adepto del Espejo', 'espejo@arcano.arc', 'x', 'editor', NULL, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')");
$canonical->exec("INSERT INTO clans (id, slug, name, created_at) VALUES ('cln_espejo', 'casa-del-espejo', 'Casa del Espejo', '2026-01-01T00:00:00Z')");

$repository = new ClanMemberRepository($canonical);
assertCondition(mirroredClan($canonical, 'usr_espejo') === null, 'Nace sin linaje: el espejo está a NULL');

$membership = $repository->addMember('clm_espejo', 'cln_espejo', 'usr_espejo', 'adept', '2026-01-01T00:00:00Z');
assertCondition($membership !== null, 'El adepto contrae la membresía en la autoridad');
assertCondition(
    mirroredClan($canonical, 'usr_espejo') === authoritativeClan($canonical, 'usr_espejo'),
    'Tras ingresar, espejo y autoridad coinciden'
);
assertCondition(mirroredClan($canonical, 'usr_espejo') === 'cln_espejo', 'El espejo refleja el linaje contraído');

$repository->removeMember('usr_espejo', 'cln_espejo', '2026-09-10T00:00:00Z', '2026-09-24T00:00:00Z');
assertCondition(mirroredClan($canonical, 'usr_espejo') === null, 'Al partir, el espejo vuelve a «sin linaje»');
assertCondition(
    (int) $canonical->query("SELECT COUNT(*) FROM clan_members WHERE user_id = 'usr_espejo'")->fetchColumn() === 1,
    'La fila de membresía JAMÁS se borra: queda como memoria (Art. III)'
);
assertCondition(
    mirroredClan($canonical, 'usr_espejo') === authoritativeClan($canonical, 'usr_espejo'),
    'Espejo y autoridad siguen concordando tras la partida'
);

// =====================================================================
// FASE 2 · Consagración sin linaje (RF-01.2)
// =====================================================================
echo "\n═══ FASE 2 · Un mago puede nacer sin hermandad ═══\n";
$authService = new AuthService($canonical, new SessionManager($canonical, '127.0.0.1', 'Arnés Reconciliación/1.0'));

$clanless = $authService->consecrate(
    'MagoSinCasa',
    'sincasa@arcano.arc',
    'palabra-secreta-larga',
    null,
    $now,
);
assertCondition(str_starts_with($clanless->userId, 'usr_'), 'Se consagra un mago sin linaje electo');
assertCondition(mirroredClan($canonical, $clanless->userId) === null, 'El espejo nace a NULL');
assertCondition(
    authoritativeClan($canonical, $clanless->userId) === null,
    'No existe membresía: el mago está apto para fundar su propia casa (RF-01.2)'
);
assertCondition(
    (int) $canonical->query("SELECT COUNT(*) FROM clan_members WHERE user_id = '" . $clanless->userId . "'")->fetchColumn() === 0,
    'Cero filas de membresía para el recién consagrado'
);

// =====================================================================
// FASE 3 · Juramento de linaje (enmienda de SPEC-03 por SPEC-09)
// =====================================================================
echo "\n═══ FASE 3 · La identidad arcana se contrae jurando ═══\n";
// SPEC-09 movió el linaje de la consagración al juramento del primer
// acceso: la consagración ignora cualquier clanId legado en silencio y
// solo LineageOathService sella users.lineage. users.clan_id queda a
// NULL al jurar: la afiliación a clanes es acto posterior e independiente
// de SPEC-07 (membresía en la autoridad), no efecto del juramento.
require_once $projectRoot . '/src/Repositories/LineageOathRepository.php';
require_once $projectRoot . '/src/Dto/LineageOathResultDto.php';
require_once $projectRoot . '/src/Services/LineageOathService.php';
require_once $projectRoot . '/src/Exceptions/LineageOathException.php';

$canonical->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, created_at, updated_at)
                  VALUES ('usr_jurado', 'MagoConCasa', 'concasa@arcano.arc', 'x', 'editor', NULL, NULL, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z'),
                          ('usr_jurado_dos', 'MagoFantasma', 'fantasma@arcano.arc', 'x', 'editor', NULL, NULL, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')");

$lineageOath = new \Grimorio\Services\LineageOathService(
    new \Grimorio\Repositories\LineageOathRepository($canonical),
    new \Grimorio\Services\AuditService($canonical),
);

$sealed = $lineageOath->sealOath('usr_jurado', 'primordialFlame', 'editor', 'MagoConCasa', $now);
assertCondition($sealed->sealedNow === true, 'El juramento sella el linaje canónico al primer acceso');
assertCondition(
    (string) $canonical->query("SELECT lineage FROM users WHERE id = 'usr_jurado'")->fetchColumn() === 'primordialFlame',
    'La identidad arcana vive en users.lineage (SPEC-09)'
);
assertCondition(
    mirroredClan($canonical, 'usr_jurado') === null && authoritativeClan($canonical, 'usr_jurado') === null,
    'Jurar NO afilia a clan alguno: ni espejo ni autoridad se tocan (la membresía es acto de SPEC-07)'
);

$ghostLineageRejected = false;
try {
    $lineageOath->sealOath('usr_jurado_dos', 'cln_fantasma', 'editor', 'MagoFantasma', $now);
} catch (\Grimorio\Exceptions\LineageOathException) {
    $ghostLineageRejected = true;
}
assertCondition($ghostLineageRejected, 'Un linaje fuera del canon de los 8 sigue siendo rechazado al jurar');

$swornConflictRejected = false;
try {
    $lineageOath->sealOath('usr_jurado', 'celestialTides', 'editor', 'MagoConCasa', $now);
} catch (\Grimorio\Exceptions\LineageOathException) {
    $swornConflictRejected = true;
}
assertCondition($swornConflictRejected, 'El vínculo ya forjado es perpetuo: jurar otro linaje alza el conflicto solemne');
assertCondition(
    (string) $canonical->query("SELECT lineage FROM users WHERE id = 'usr_jurado'")->fetchColumn() === 'primordialFlame',
    'El conflicto solemne no muta una sola fila (users.lineage intacto)'
);
assertCondition(
    (int) $canonical->query("SELECT COUNT(*) FROM users WHERE id LIKE 'usr_%' AND clan_id IS NULL")->fetchColumn() >= 1,
    'La base convive con magos sin clan y con magos de linaje jurado'
);

// =====================================================================
// FASE 4 · El veto juzga los datos VIVOS
// =====================================================================
echo "\n═══ FASE 4 · El veto lee la autoridad, no el espejo ═══\n";
$validator = new ClanEthicsValidator($repository);
// La afiliación al clan es acto de SPEC-07: el adepto ya juró su linaje
// (FASE 3) y ahora milita en la Casa del Espejo vía el único escritor.
$repository->addMember('clm_jurado', 'cln_espejo', 'usr_jurado', 'adept', '2026-01-01T00:00:00Z');
assertCondition(
    $validator->canMasterEvaluateSpell('usr_jurado', 'cln_espejo', $now) === false,
    'Quien milita en el linaje del conjuro queda vetado'
);
// Se desvanece la afiliación en la autoridad, sin tocar el espejo.
$canonical->exec("UPDATE clan_members SET left_at = '2026-09-13T00:00:00Z' WHERE user_id = 'usr_jurado'");
assertCondition(
    $validator->canMasterEvaluateSpell('usr_jurado', 'cln_espejo', $now) === false,
    'Recién salido (ayer), el veto histórico sigue vigente'
);
assertCondition(
    $validator->canMasterEvaluateSpell('usr_jurado', 'cln_espejo', $now->modify('+31 days')) === true,
    'Un mes después, el mismo Maestro queda admitido'
);
// Corromper el espejo NO cambia el veredicto: no es la autoridad. Se apunta
// el espejo a un linaje REAL en el que no hay membresía alguna; si el espejo
// mandase, el Maestro quedaría vetado por habitar el linaje del conjuro.
$canonical->exec("UPDATE users SET clan_id = 'cln_primordial' WHERE id = 'usr_jurado'");
assertCondition(
    $validator->canMasterEvaluateSpell('usr_jurado', 'cln_primordial', $now) === true,
    'El veto ignora un espejo manipulado: manda el historial de membresía'
);
assertCondition(
    $validator->canMasterEvaluateSpell('usr_jurado', 'cln_espejo', $now) === false,
    'Y el veto histórico sigue mordiendo pese al espejo manipulado'
);

// =====================================================================
// FASE 5 · Los dos servicios comparten autoridad y veredicto
// =====================================================================
echo "\n═══ FASE 5 · Una sola lógica de veto ═══\n";
$conflictService = new ClanConflictService($canonical);
$master = new User(
    id: 'usr_master_unico',
    alias: 'MaestroUnico',
    email: 'unico@arcano.arc',
    role: 'master',
    clanId: null,
    passwordHash: str_repeat('x', 60),
    createdAt: '2026-01-01T00:00:00Z',
    updatedAt: '2026-01-01T00:00:00Z',
);
$canonical->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
                  VALUES ('usr_master_unico', 'MaestroUnico', 'unico@arcano.arc', '" . str_repeat('x', 60) . "', 'master', NULL, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')");
$repository->addMember('clm_master_unico', 'cln_primordial', 'usr_master_unico', 'adept', '2026-01-01T00:00:00Z');

$serviceVerdict = $conflictService->canMasterSignSpell(
    $master,
    'usr_otro_autor',
    'cln_primordial',
    $now,
);
assertCondition($serviceVerdict->isAllowed === false, 'ClanConflictService veta el linaje que el Maestro habita');
assertCondition(
    $serviceVerdict->reason === $validator->vetoReasonFor('usr_master_unico', 'cln_primordial', $now),
    'Ambos servicios emiten el MISMO motivo: una sola lógica, una sola autoridad'
);
assertCondition(
    $conflictService->canMasterSignSpell($master, 'usr_otro_autor', 'cln_tercero', $now)->isAllowed === true,
    'Un linaje ajeno sigue aprobando la firma'
);
assertCondition(
    $conflictService->canMasterSignSpell($master, 'usr_master_unico', 'cln_tercero', $now)->isAllowed === false,
    'La auto-firma sigue vetada (regla propia de SPEC-03)'
);

// =====================================================================
// FASE 6 · La vía de ascenso legada conserva la memoria
// =====================================================================
echo "\n═══ FASE 6 · Ascenso de una base legada ═══\n";
$legacy = new PDO('sqlite::memory:');
$legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacy->exec('PRAGMA foreign_keys = ON');

// Forma CONGELADA del plano anterior a la reconciliación: `clan_id` NOT NULL
// y el historial en `clan_history`.
$legacy->exec("
    CREATE TABLE clans (
        id TEXT PRIMARY KEY, slug TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
        motto TEXT NOT NULL DEFAULT '', domain_points INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL
    );
    CREATE TABLE users (
        id TEXT PRIMARY KEY, alias TEXT NOT NULL UNIQUE, email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'editor',
        clan_id TEXT NOT NULL REFERENCES clans (id),
        recovery_token_hash TEXT NOT NULL DEFAULT '', recovery_token_expires_at TEXT,
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL
    );
    CREATE INDEX idx_users_clan_id ON users (clan_id);
    CREATE TABLE clan_history (
        id INTEGER PRIMARY KEY,
        user_id TEXT NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
        clan_id TEXT NOT NULL REFERENCES clans (id),
        joined_at TEXT NOT NULL, left_at TEXT
    );
    CREATE INDEX idx_user_clan_time ON clan_history (user_id, left_at);
    CREATE TABLE spells (
        id TEXT PRIMARY KEY, author_id TEXT NOT NULL REFERENCES users (id)
    );
");
// La Casa Alfa porta gloria legada en `domain_points`, contador de SPEC-01
// que la Tarea 2.6 retira plegándolo al semanal canónico.
$legacy->exec("INSERT INTO clans (id, slug, name, domain_points, created_at) VALUES
    ('cln_alfa', 'casa-alfa', 'Casa Alfa', 250, '2026-01-01T00:00:00Z'),
    ('cln_beta', 'casa-beta', 'Casa Beta', 0, '2026-01-01T00:00:00Z')");
$legacy->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at) VALUES
    ('usr_legado_uno', 'Legado Uno', 'uno@legado.arc', 'x', 'master', 'cln_alfa', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z'),
    ('usr_legado_dos', 'Legado Dos', 'dos@legado.arc', 'x', 'editor', 'cln_beta', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')");
// Memoria histórica del maestro: salió de cln_beta hace 10 días.
$legacy->exec("INSERT INTO clan_history (user_id, clan_id, joined_at, left_at)
               VALUES ('usr_legado_uno', 'cln_beta', '2026-05-01T00:00:00Z', '2026-09-04T00:00:00Z')");
$legacy->exec("INSERT INTO spells (id, author_id) VALUES ('spl_legado', 'usr_legado_uno')");

$legacyBlocks = 0;
try {
    $legacy->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
                   VALUES ('usr_sin_clan', 'Sin Clan', 'sc@legado.arc', 'x', 'editor', NULL, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')");
} catch (PDOException) {
    $legacyBlocks++; // La base legada exigía un linaje: he aquí el bloqueo.
}
assertCondition($legacyBlocks === 1, 'La base legada rechaza a un mago sin linaje (el problema a resolver)');

$migrationOk = true;
try {
    $legacy->exec((string) file_get_contents($projectRoot . '/sql/07_clans_lineages_schema.sql'));
    $legacy->exec((string) file_get_contents($projectRoot . '/sql/07_membership_single_source.sql'));
    $legacy->exec((string) file_get_contents($projectRoot . '/sql/07_weekly_dominion_ledger.sql'));
    $legacy->exec((string) file_get_contents($projectRoot . '/sql/07_retire_domain_points.sql'));
} catch (PDOException $migrationFailure) {
    $migrationOk = false;
    echo '  [FALLA] La migración alzó: ' . $migrationFailure->getMessage() . "\n";
}
assertCondition($migrationOk, 'La vía de ascenso se aplica limpiamente sobre la base legada');

$legacyColumn = null;
foreach ($legacy->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
    if ($column['name'] === 'clan_id') {
        $legacyColumn = $column;
    }
}
assertCondition(
    $legacyColumn !== null && (int) $legacyColumn['notnull'] === 0,
    'Tras migrar, el linaje es anulable: se puede fundar sin pertenecer a otro (RF-01.2)'
);
assertCondition(
    (int) $legacy->query("SELECT COUNT(*) FROM users WHERE id = 'usr_legado_uno' AND clan_id = 'cln_alfa'")->fetchColumn() === 1,
    'Los valores del espejo sobreviven a la migración'
);
assertCondition(
    authoritativeClan($legacy, 'usr_legado_uno') === 'cln_alfa',
    'La autoridad recibe la afiliación que antes vivía en el espejo'
);
assertCondition(
    mirroredClan($legacy, 'usr_legado_dos') === authoritativeClan($legacy, 'usr_legado_dos'),
    'Espejo y autoridad quedan reconciliados tras migrar'
);
assertCondition(
    (int) $legacy->query("SELECT COUNT(*) FROM clan_members WHERE clan_id = 'cln_beta' AND user_id = 'usr_legado_uno'")->fetchColumn() === 1,
    'El historial de clan_history se importa: no se pierde la memoria del linaje'
);

// Un solo contador de gloria (Tarea 2.6): la columna vestigial se retira y su
// valor legado se pliega al contador semanal canónico sin perderse.
$legacyClanColumns = array_column(
    $legacy->query('PRAGMA table_info(clans)')->fetchAll(PDO::FETCH_ASSOC),
    'name'
);
assertCondition(
    in_array('domain_points', $legacyClanColumns, true) === false,
    'Tras ascender, el plano legado ya no porta el contador duplicado'
);
assertCondition(
    (int) $legacy->query("SELECT weekly_points FROM clans WHERE id = 'cln_alfa'")->fetchColumn() === 250,
    'La gloria legada de domain_points se pliega al contador semanal en vez de perderse'
);
assertCondition(
    (int) $legacy->query("SELECT weekly_points FROM clans WHERE id = 'cln_beta'")->fetchColumn() === 0,
    'Las casas sin gloria legada quedan a cero: el pliegue no inventa gloria'
);

$legacyValidator = new ClanEthicsValidator(new ClanMemberRepository($legacy));
assertCondition(
    $legacyValidator->canMasterEvaluateSpell('usr_legado_uno', 'cln_beta', $now) === false,
    'El veto ético sigue mordiendo sobre la memoria importada'
);
assertCondition(
    $legacyValidator->canMasterEvaluateSpell('usr_legado_uno', 'cln_alfa', $now) === false,
    'Y sigue vetando el linaje que el maestro habita tras el ascenso'
);
assertCondition(
    (int) $legacy->query('SELECT COUNT(*) FROM spells')->fetchColumn() === 1,
    'El patrimonio del santuario (spells) sobrevive a la reconstrucción foránea'
);

// Ya se puede consagrar sin linaje en la base ascendida.
$legacy->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
               VALUES ('usr_sin_clan', 'Sin Clan', 'sc@legado.arc', 'x', 'editor', NULL, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')");
assertCondition(
    mirroredClan($legacy, 'usr_sin_clan') === null && authoritativeClan($legacy, 'usr_sin_clan') === null,
    'Un mago sin linaje ya cabe en la base ascendida'
);

echo "\n" . str_repeat('─', 72) . "\n";
echo "Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";

if ($assertsFailed > 0) {
    echo "RESULTADO: DENEGADO — la reconciliación de la afiliación no está completa.\n";
    exit(1);
}

echo "RESULTADO: EXITO — clan_members es la única autoridad de la afiliación.\n";
exit(0);
