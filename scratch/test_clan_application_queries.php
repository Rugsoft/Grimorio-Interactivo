<?php

declare(strict_types=1);

/**
 * test_clan_application_queries.php — Verificación de la Tarea 1.2 de TASKS-10.
 *
 * Valida las cuatro consultas nuevas de `ClanApplicationRepository`
 * (SPEC-10) contra el «Hecho cuando» de la tarea:
 *
 *   1. `findApplicationsByUser()` devuelve TODOS los estados de la cuenta,
 *      en cronología determinista.
 *   2. `hasSealedHouse()` discernie la clausura por casa para cualquier
 *      estado histórico (pendiente, aprobada, rechazada o cancelada).
 *   3. `markVerdictSeen()` solo alcanza peticiones TERMINALES propias; un
 *      segundo acknowledge responde éxito sin mutar la columna
 *      (idempotencia por persistencia).
 *   4. `countUnreadVerdicts()` ignora las peticiones `pending` y solo
 *      cuenta terminales con `verdict_seen_at` nulo — el combustible del
 *      rótulo «Tienes dictámenes a la espera» (RF-01.1).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, 100% consultas preparadas.
 *   - Artículo V (Dualidad): identificadores en inglés; narrativa en
 *     noble castellano.
 *
 * Uso: php scratch/test_clan_application_queries.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

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

/** Construye una base sembrada con el estado canónico del Vestíbulo. */
function forgeSeededDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON;');

    // La tabla con las piezas de SPEC-10 ya incorporadas (schema.sql vigente).
    $pdo->exec('CREATE TABLE clan_applications (
        id TEXT PRIMARY KEY,
        clan_id TEXT NOT NULL,
        user_id TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT \'pending\'
            CHECK (status IN (\'pending\', \'approved\', \'rejected\', \'cancelled\')),
        created_at TEXT NOT NULL,
        resolved_at TEXT,
        verdict_seen_at TEXT,
        FOREIGN KEY (clan_id) REFERENCES clans (id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE TABLE users (
        id TEXT PRIMARY KEY,
        alias TEXT NOT NULL UNIQUE,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT \'editor\',
        lineage TEXT,
        clan_id TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    $pdo->exec('CREATE TABLE clans (
        id TEXT PRIMARY KEY,
        slug TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        lineage_type TEXT NOT NULL DEFAULT \'primordialFlame\',
        admission_mode TEXT NOT NULL DEFAULT \'open\',
        status TEXT NOT NULL DEFAULT \'active\'
    )');

    // Entidades madre primero (las claves foráneas velen el orden).
    $users = $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, created_at, updated_at)
         VALUES (:id, :alias, :email, \'x\', :now, :now)'
    );
    $users->execute([':id' => 'usr_1', ':alias' => 'Galaedriel', ':email' => 'galaedriel@santuario.test', ':now' => '2026-01-01T00:00:00Z']);
    $users->execute([':id' => 'usr_2', ':alias' => 'Boromil', ':email' => 'boromil@santuario.test', ':now' => '2026-01-01T00:00:00Z']);

    $clans = $pdo->prepare('INSERT INTO clans (id, slug, name) VALUES (:id, :slug, :name)');
    $clans->execute([':id' => 'cln_a', ':slug' => 'casa-a', ':name' => 'Casa A']);
    $clans->execute([':id' => 'cln_b', ':slug' => 'casa-b', ':name' => 'Casa B']);
    $clans->execute([':id' => 'cln_c', ':slug' => 'casa-c', ':name' => 'Casa C']);
    $clans->execute([':id' => 'cln_d', ':slug' => 'casa-d', ':name' => 'Casa D']);

    // El expediente canónico de usr_1: un poco de cada estado.
    $insert = $pdo->prepare(
        'INSERT INTO clan_applications (id, clan_id, user_id, status, created_at, resolved_at, verdict_seen_at)
         VALUES (:id, :clanId, :userId, :status, :createdAt, :resolvedAt, :seenAt)'
    );
    // Rechazada, veredicto SIN contemplar → cuenta para el rótulo.
    $insert->execute([':id' => 'app_rej', ':clanId' => 'cln_a', ':userId' => 'usr_1', ':status' => 'rejected', ':createdAt' => '2026-01-05T10:00:00Z', ':resolvedAt' => '2026-01-06T10:00:00Z', ':seenAt' => null]);
    // Pendiente, sin veredicto que leer → NO cuenta.
    $insert->execute([':id' => 'app_pen', ':clanId' => 'cln_b', ':userId' => 'usr_1', ':status' => 'pending', ':createdAt' => '2026-02-05T10:00:00Z', ':resolvedAt' => null, ':seenAt' => null]);
    // Aprobada, veredicto YA contemplado → no vuelve a contar.
    $insert->execute([':id' => 'app_apr', ':clanId' => 'cln_c', ':userId' => 'usr_1', ':status' => 'approved', ':createdAt' => '2026-03-05T10:00:00Z', ':resolvedAt' => '2026-03-06T10:00:00Z', ':seenAt' => '2026-03-07T12:00:00Z']);

    // usr_2: solo una retirada con veredicto sin contemplar.
    $insert->execute([':id' => 'app_can', ':clanId' => 'cln_a', ':userId' => 'usr_2', ':status' => 'cancelled', ':createdAt' => '2026-04-05T10:00:00Z', ':resolvedAt' => '2026-04-06T10:00:00Z', ':seenAt' => null]);

    return $pdo;
}

echo "== VERIFICACION TAREA 1.2: Consultas nuevas del ClanApplicationRepository ==\n\n";

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/src/Repositories/ClanApplicationRepository.php';

use Grimorio\Repositories\ClanApplicationRepository;

// --- FASE 0: Superficie ---
echo "FASE 0: Superficie del repositorio\n";
$source = (string) file_get_contents($projectRoot . '/src/Repositories/ClanApplicationRepository.php');
assertCondition(str_contains($source, 'function findApplicationsByUser('), 'Existe findApplicationsByUser()');
assertCondition(str_contains($source, 'function hasSealedHouse('), 'Existe hasSealedHouse()');
assertCondition(str_contains($source, 'function markVerdictSeen('), 'Existe markVerdictSeen()');
assertCondition(str_contains($source, 'function countUnreadVerdicts('), 'Existe countUnreadVerdicts()');
assertCondition(str_contains($source, 'declare(strict_types=1);'), 'Tipado estricto (Artículo I)');
assertCondition(!preg_match('/prepare\([^)]*"[^"]*\$/', $source) && !str_contains($source, 'WHERE user_id = \'"'), 'Sin interpolación de variables en SQL (parameter binding)');

// --- FASE 1: findApplicationsByUser (RF-03.8) ---
echo "\nFASE 1: findApplicationsByUser — el expediente íntegro\n";
$pdo = forgeSeededDatabase();
$repository = new ClanApplicationRepository($pdo);
$all = $repository->findApplicationsByUser('usr_1');
assertCondition(count($all) === 3, 'Devuelve TODOS los estados del postulante (3 filas, no solo pendientes)');
assertCondition(array_map(static fn (array $app): string => $app['id'], $all) === ['app_rej', 'app_pen', 'app_apr'], 'Cronología determinista (created_at ASC, id ASC)');
assertCondition(array_key_exists('verdict_seen_at', $all[0]), 'Cada fila porta verdict_seen_at (proyección canónica extendida)');
assertCondition($all[0]['verdict_seen_at'] === null && $all[2]['verdict_seen_at'] === '2026-03-07T12:00:00Z', 'El veredicto contemplado viaja con su instante');
assertCondition(count($repository->findApplicationsByUser('usr_desconocido')) === 0, 'Una cuenta sin expediente devuelve vacío, jamás error');

// --- FASE 2: hasSealedHouse (RF-03.1) ---
echo "\nFASE 2: hasSealedHouse — la clausura por casa, cualquier estado vela\n";
assertCondition($repository->hasSealedHouse('usr_1', 'cln_a') === true, 'Casa rechazada: clausurada');
assertCondition($repository->hasSealedHouse('usr_1', 'cln_b') === true, 'Casa con petición pendiente: clausurada también (no se re-postula)');
assertCondition($repository->hasSealedHouse('usr_1', 'cln_c') === true, 'Casa aprobada: clausurada (ya se es hermano)');
assertCondition($repository->hasSealedHouse('usr_2', 'cln_a') === true, 'Casa retirada: clausurada');
assertCondition($repository->hasSealedHouse('usr_2', 'cln_c') === false, 'Casa jamás cortejada: puertas abiertas');

// --- FASE 3: markVerdictSeen (RF-03.4) ---
echo "\nFASE 3: markVerdictSeen — solo terminales propios, idempotente\n";
$first = $repository->markVerdictSeen('app_rej', 'usr_1', '2026-09-20T10:00:00Z');
assertCondition($first === true, 'El primer acknowledge fija el instante');
$stored = $pdo->query("SELECT verdict_seen_at FROM clan_applications WHERE id = 'app_rej'")->fetchColumn();
assertCondition($stored === '2026-09-20T10:00:00Z', 'La columna queda con la estampa del contemplado');
$second = $repository->markVerdictSeen('app_rej', 'usr_1', '2026-09-20T11:00:00Z');
assertCondition($second === false, 'El segundo acknowledge no encuentra veredicto sin leer');
$stillStored = $pdo->query("SELECT verdict_seen_at FROM clan_applications WHERE id = 'app_rej'")->fetchColumn();
assertCondition($stillStored === '2026-09-20T10:00:00Z', 'La columna NO se muta por el reenvío (idempotencia)');

$forged = $repository->markVerdictSeen('app_can', 'usr_1', '2026-09-20T12:00:00Z');
assertCondition($forged === false, 'La petición AJENA jamás se contempla (guardia de propiedad)');
$pending = $repository->markVerdictSeen('app_pen', 'usr_1', '2026-09-20T12:00:00Z');
assertCondition($pending === false, 'Una petición PENDING jamás marca veredicto leído (nada hay que leer)');
$pendingStillNull = $pdo->query("SELECT verdict_seen_at FROM clan_applications WHERE id = 'app_pen'")->fetchColumn();
assertCondition($pendingStillNull === null, 'La pendiente conserva su veredicto inexistente (NULL)');

// --- FASE 4: countUnreadVerdicts (RF-01.1, RF-03.4) ---
echo "\nFASE 4: countUnreadVerdicts — el combustible del rótulo\n";
assertCondition($repository->countUnreadVerdicts('usr_1') === 0, 'Tras contemplar el rechazo: cero veredictos sin leer (la aprobada ya estaba leída)');
assertCondition($repository->countUnreadVerdicts('usr_2') === 1, 'La retirada sin contemplar de usr_2 cuenta (terminal con NULL)');
// Una pendiente nueva jamás suma al rótulo.
$repository->createApplication('app_pen2', 'cln_d', 'usr_2', '2026-09-20T09:00:00Z');
assertCondition($repository->countUnreadVerdicts('usr_2') === 1, 'La petición PENDING nueva NO suma al rótulo (solo terminales con NULL)');
assertCondition($repository->countUnreadVerdicts('usr_desconocido') === 0, 'Una cuenta sin expediente no tiene rótulo que encender');

// --- FASE 5: integración con la migración (Tarea 1.1) ---
echo "\nFASE 5: Las consultas respiran sobre la base migrada (regresión 1.1)\n";
$migrationPath = $projectRoot . '/sql/10_clan_vestibule.sql';
$migrated = new PDO('sqlite::memory:');
$migrated->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$migrated->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
$migratedRepository = new ClanApplicationRepository($migrated);
$created = $migratedRepository->createApplication('app_fresh', 'cln_a', 'usr_1', '2026-09-20T10:00:00Z');
assertCondition($created !== null && $created['verdict_seen_at'] === null, 'createApplication() nace con veredicto sin contemplar sobre el esquema vigente');
assertCondition($migratedRepository->hasSealedHouse('usr_1', 'cln_a') === true, 'La recién creada clausura su casa (índice único + consulta concuerdan)');

// --- Veredicto ---
echo "\n=============================\n";
echo "Asertos: {$assertsPassed} PASA / {$assertsFailed} FALLA\n";
if ($assertsFailed > 0) {
    echo "Las consultas del Vestíbulo NO cumplen aún su contrato.\n";
    exit(1);
}
echo "Las cuatro consultas nuevas cumplen su contrato: expediente íntegro, clausura fiel, veredicto contemplado idempotente y rótulo exacto.\n";
exit(0);
