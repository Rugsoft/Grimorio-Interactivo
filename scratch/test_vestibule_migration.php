<?php

declare(strict_types=1);

/**
 * test_vestibule_migration.php — Verificación de la Tarea 1.1 de TASKS-10.
 *
 * Valida la migración `sql/10_clan_vestibule.sql` (clausura perpetua por
 * casa y veredicto contemplado) contra el «Hecho cuando» de la tarea:
 *
 *   1. Re-ejecutar la migración sobre una base ya migrada no falla ni
 *      duplica (idempotencia del contrato de aplicación).
 *   2. Un segundo INSERT de `(user_id, clan_id)` existente recibe la
 *      violación del índice único `uq_clan_application_house`.
 *   3. La deduplicación de legado archiva los duplicados históricos a la
 *      tabla espejo conservando la fila más antigua en la tabla viva.
 *   4. La columna `verdict_seen_at` existe y es anulable.
 *   5. Coherencia guion↔esquema: una base nueva desde `database/schema.sql`
 *      nace ya con índice, columna y tabla archivo.
 *   6. El acknowledge del veredicto (RF-03.4) es idempotente sobre la base
 *      migrada: el primer contemplado fija la columna, el reenvío responde
 *      sin mutación, las peticiones `pending` y ajenas jamás se escriben y
 *      el rótulo de dictámenes sin leer se apaga.
 *
 * Fases:
 *   [0]  Superficie: el guion existe y declara sus piezas.
 *   [1]  Primera aplicación sobre una base legada (deduplicación + índice
 *        + columna).
 *   [2]  Segunda aplicación: idempotencia sin error ni mutación.
 *   [3]  Muralla del índice: la clausura por casa es invariante físico.
 *   [4]  Coherencia: base nueva desde schema.sql ya porta las tres piezas.
 *   [5]  Acknowledge idempotente (RF-03.4) con el repositorio real.
 *
 * Verificación doble: Tarea 1.1 (la migración) y Tarea 7.6 (la auditoría
 * del arnés frente al «Hecho cuando» completo).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin librerías externas.
 *   - Artículo V (Dualidad): identificadores en inglés; narrativa y
 *     comentarios en noble castellano.
 *
 * Uso: php scratch/test_vestibule_migration.php
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

/**
 * Aplica el guion de migración sentencia a sentencia, según el contrato
 * de aplicación idempotente que el propio guion declara: un fallo de
 * «duplicate column» o de índice/columna ya existente NO es error (la
 * base ya porta la pieza); cualquier otro fallo sí lo es.
 */
function applyMigrationScript(PDO $connection, string $scriptSource): void
{
    // Se recortan los comentarios para que cada exec sea UNA sentencia.
    $statements = [];
    foreach (explode(";\n", $scriptSource) as $chunk) {
        $lines = array_filter(
            explode("\n", $chunk),
            static fn (string $line): bool => !str_starts_with(ltrim($line), '--')
        );
        $statement = trim(implode("\n", $lines));
        if ($statement !== '') {
            $statements[] = $statement;
        }
    }

    foreach ($statements as $statement) {
        try {
            $connection->exec($statement);
        } catch (PDOException $failure) {
            $message = $failure->getMessage();
            $isAlreadyThere = str_contains($message, 'duplicate column name')
                || str_contains($message, 'already exists');
            if (!$isAlreadyThere) {
                throw $failure;
            }
            // Señal de idempotencia: la pieza ya vive en la base.
        }
    }
}

/** ¿Lanza este cierre de excepción? Devuelve el mensaje o null. */
function captureError(callable $operation): ?string
{
    try {
        $operation();

        return null;
    } catch (Throwable $failure) {
        return $failure->getMessage();
    }
}

/** Nombres de índice de una tabla (SQLite). */
function indexNamesOf(PDO $connection, string $table): array
{
    $rows = $connection->query("PRAGMA index_list({$table})")->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static fn (array $row): string => (string) $row['name'], $rows);
}

/** ¿Existe una columna en la tabla (SQLite)? */
function columnExists(PDO $connection, string $table, string $column): bool
{
    $rows = $connection->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        if ($row['name'] === $column) {
            return true;
        }
    }

    return false;
}

/**
 * Construye una base legada a SPEC-10: `clan_applications` SIN el índice
 * único NI `verdict_seen_at`, con clanes y usuarios mínimos, sembrada
 * con duplicados históricos del mismo par `(user_id, clan_id)`.
 */
function forgeLegacyDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON;');

    // Forma histórica de `clan_applications` (antes de SPEC-10).
    $pdo->exec('CREATE TABLE clan_applications (
        id TEXT PRIMARY KEY,
        clan_id TEXT NOT NULL,
        user_id TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT \'pending\'
            CHECK (status IN (\'pending\', \'approved\', \'rejected\', \'cancelled\')),
        created_at TEXT NOT NULL,
        resolved_at TEXT,
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

    // Semilla: entidades madre primero (users, clans) y después las
    // peticiones, porque las claves foráneas velen el orden.
    $users = $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, created_at, updated_at)
         VALUES (:id, :alias, :email, \'x\', :now, :now)'
    );
    $users->execute([':id' => 'usr_1', ':alias' => 'Galaedriel', ':email' => 'galaedriel@santuario.test', ':now' => '2026-01-01T00:00:00Z']);
    $users->execute([':id' => 'usr_2', ':alias' => 'Boromil', ':email' => 'boromil@santuario.test', ':now' => '2026-01-01T00:00:00Z']);

    $clans = $pdo->prepare(
        'INSERT INTO clans (id, slug, name) VALUES (:id, :slug, :name)'
    );
    $clans->execute([':id' => 'cln_a', ':slug' => 'casa-a', ':name' => 'Casa A']);
    $clans->execute([':id' => 'cln_b', ':slug' => 'casa-b', ':name' => 'Casa B']);

    // Una cuenta con DOS peticiones históricas a la misma casa (eco de
    // escrituras previas a la clausura) y otra cuenta con una.
    $insert = $pdo->prepare(
        'INSERT INTO clan_applications (id, clan_id, user_id, status, created_at, resolved_at)
         VALUES (:id, :clanId, :userId, :status, :createdAt, :resolvedAt)'
    );
    $insert->execute([':id' => 'app_old_1', ':clanId' => 'cln_a', ':userId' => 'usr_1', ':status' => 'rejected', ':createdAt' => '2026-01-01T10:00:00Z', ':resolvedAt' => '2026-01-02T10:00:00Z']);
    $insert->execute([':id' => 'app_old_2', ':clanId' => 'cln_a', ':userId' => 'usr_1', ':status' => 'cancelled', ':createdAt' => '2026-02-01T10:00:00Z', ':resolvedAt' => '2026-02-02T10:00:00Z']);
    $insert->execute([':id' => 'app_solo', ':clanId' => 'cln_b', ':userId' => 'usr_2', ':status' => 'pending', ':createdAt' => '2026-03-01T10:00:00Z', ':resolvedAt' => null]);

    return $pdo;
}

echo "== VERIFICACION TAREA 1.1: Clausura por casa y veredicto contemplado ==\n\n";

$projectRoot = dirname(__DIR__);
$migrationPath = $projectRoot . '/sql/10_clan_vestibule.sql';

// --- FASE 0: Superficie ---
echo "FASE 0: Superficie del guion\n";
assertCondition(file_exists($migrationPath), 'Existe sql/10_clan_vestibule.sql');
if (!file_exists($migrationPath)) {
    fwrite(STDERR, "La migración no existe: no hay nada que verificar.\n");
    exit(1);
}
$scriptSource = (string) file_get_contents($migrationPath);
assertCondition(str_contains($scriptSource, 'uq_clan_application_house'), 'El guion declara el índice único de clausura');
assertCondition(str_contains($scriptSource, 'verdict_seen_at'), 'El guion declara la columna del veredicto contemplado');
assertCondition(str_contains($scriptSource, 'clan_applications_archive'), 'El guion declara la tabla archivo de duplicados legados');

// --- FASE 1: Primera aplicación sobre la base legada ---
echo "\nFASE 1: Primera aplicación (deduplicación + índice + columna)\n";
$legacy = forgeLegacyDatabase();
applyMigrationScript($legacy, $scriptSource);

$aliveRows = $legacy->query("SELECT COUNT(*) FROM clan_applications")->fetchColumn();
assertCondition($aliveRows === 2 || (int) $aliveRows === 2, 'Tras la deduplicación sobrevive UNA fila por casa y cuenta (2 en total)');
assertCondition((int) $legacy->query("SELECT COUNT(*) FROM clan_applications_archive")->fetchColumn() === 1, 'El duplicado legado emigra a clan_applications_archive (1 fila)');
$survivor = $legacy->query("SELECT id FROM clan_applications WHERE user_id = 'usr_1' AND clan_id = 'cln_a'")->fetchColumn();
assertCondition($survivor === 'app_old_1', 'La fila conservada es la más antigua (app_old_1, no el eco de febrero)');
$archived = $legacy->query("SELECT id FROM clan_applications_archive")->fetchColumn();
assertCondition($archived === 'app_old_2', 'El eco archivado conserva su identidad (app_old_2)');
assertCondition(in_array('uq_clan_application_house', indexNamesOf($legacy, 'clan_applications'), true), 'El índice único uq_clan_application_house queda erigido');
assertCondition(columnExists($legacy, 'clan_applications', 'verdict_seen_at'), 'La columna verdict_seen_at queda añadida');

$seenNull = $legacy->query("SELECT verdict_seen_at FROM clan_applications WHERE id = 'app_solo'")->fetchColumn();
assertCondition($seenNull === null, 'Las filas históricas nacen con veredicto sin contemplar (NULL)');

// --- FASE 2: Idempotencia ---
echo "\nFASE 2: Segunda aplicación — idempotencia sin error ni duplicación\n";
$rowsBefore = (int) $legacy->query("SELECT COUNT(*) FROM clan_applications")->fetchColumn();
$archiveBefore = (int) $legacy->query("SELECT COUNT(*) FROM clan_applications_archive")->fetchColumn();
$error = captureError(static fn () => applyMigrationScript($legacy, $scriptSource));
assertCondition($error === null, 'Re-ejecutar la migración sobre una base ya migrada NO falla');
assertCondition((int) $legacy->query("SELECT COUNT(*) FROM clan_applications")->fetchColumn() === $rowsBefore, 'La segunda pasada no muta la tabla viva');
assertCondition((int) $legacy->query("SELECT COUNT(*) FROM clan_applications_archive")->fetchColumn() === $archiveBefore, 'La segunda pasada no duplica el archivo');

// --- FASE 3: La muralla del índice ---
echo "\nFASE 3: La clausura por casa es invariante físico\n";
$violation = captureError(static function () use ($legacy): void {
    $legacy->prepare(
        'INSERT INTO clan_applications (id, clan_id, user_id, status, created_at)
         VALUES (:id, :clanId, :userId, :status, :createdAt)'
    )->execute([':id' => 'app_reborn', ':clanId' => 'cln_a', ':userId' => 'usr_1', ':status' => 'pending', ':createdAt' => '2026-09-20T10:00:00Z']);
});
assertCondition($violation !== null, 'Un segundo INSERT de (user_id, clan_id) existente recibe la violación del índice único');
assertCondition($violation !== null && (str_contains($violation, 'UNIQUE') || str_contains($violation, 'unique')), 'La violación es de unicidad (no de otra muralla)');
$stillRejected = $legacy->query("SELECT COUNT(*) FROM clan_applications WHERE id = 'app_reborn'")->fetchColumn();
assertCondition((int) $stillRejected === 0, 'La fila burlada jamás llega a nacer (0 filas con app_reborn)');

// La clausura vela TAMBIÉN sobre estados terminales: retirada y rechazo clausuran.
$withdraw = captureError(static function () use ($legacy): void {
    $legacy->prepare(
        'INSERT INTO clan_applications (id, clan_id, user_id, status, created_at)
         VALUES (:id, :clanId, :userId, :status, :createdAt)'
    )->execute([':id' => 'app_c solo', ':clanId' => 'cln_b', ':userId' => 'usr_2', ':status' => 'pending', ':createdAt' => '2026-09-20T11:00:00Z']);
});
assertCondition($withdraw !== null, 'La petición pendiente de usr_2 también clausura cln_b (cualquier estado vela)');

// --- FASE 4: Coherencia guion↔esquema ---
echo "\nFASE 4: Una base nueva desde database/schema.sql nace ya migrada\n";
$canonical = new PDO('sqlite::memory:');
$canonical->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$canonical->exec('PRAGMA foreign_keys = ON;');
$canonical->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
assertCondition(in_array('uq_clan_application_house', indexNamesOf($canonical, 'clan_applications'), true), 'schema.sql erige el índice único de clausura');
assertCondition(columnExists($canonical, 'clan_applications', 'verdict_seen_at'), 'schema.sql nace con la columna verdict_seen_at');
$schemaSource = (string) file_get_contents($projectRoot . '/database/schema.sql');
assertCondition(str_contains($schemaSource, 'CREATE TABLE IF NOT EXISTS clan_applications_archive'), 'schema.sql nace con la tabla archivo de duplicados legados');

// --- FASE 5: El veredicto contemplado sobre la base migrada (RF-03.4) ---
echo "\nFASE 5: El acknowledge es idempotente sobre la base migrada\n";

require_once $projectRoot . '/src/Models/User.php';
require_once $projectRoot . '/src/Repositories/ClanApplicationRepository.php';

// El postulante de la fila terminal rechazada (app_old_1, legado de la
// deduplicación) y la cuenta peticionaria pendiente (app_solo).
$instantOfOath = '2026-01-01T00:00:00Z';
$postulante = new Grimorio\Models\User(
    id: 'usr_1',
    alias: 'Galaedriel',
    email: 'galaedriel@santuario.test',
    role: 'editor',
    clanId: null,
    passwordHash: 'x',
    lineage: 'primordialFlame',
    createdAt: $instantOfOath,
    updatedAt: $instantOfOath,
);
$repository = new Grimorio\Repositories\ClanApplicationRepository($legacy);

// [1] El primer contemplado fija la columna con el instante narrado.
$primerInstante = '2026-09-20T12:00:00Z';
assertCondition(
    $repository->markVerdictSeen('app_old_1', 'usr_1', $primerInstante) === true,
    'El primer acknowledge sobre un veredicto terminal propio queda fijado'
);
assertCondition(
    $legacy->query("SELECT verdict_seen_at FROM clan_applications WHERE id = 'app_old_1'")->fetchColumn() === $primerInstante,
    'La columna verdict_seen_at registra el instante del primer contemplado'
);

// [2] El reenvío (doble clic, reintento de red) responde sin mutación.
assertCondition(
    $repository->markVerdictSeen('app_old_1', 'usr_1', '2026-09-20T13:00:00Z') === false,
    'El reenvío del acknowledge NO vuelve a mutar (idempotencia)'
);
assertCondition(
    $legacy->query("SELECT verdict_seen_at FROM clan_applications WHERE id = 'app_old_1'")->fetchColumn() === $primerInstante,
    'El historial conserva el PRIMER instante contemplado, no el último'
);

// [3] Una petición `pending` jamás se contempla: nada hay que leer.
assertCondition(
    $repository->markVerdictSeen('app_solo', 'usr_2', $primerInstante) === false,
    'El acknowledge sobre una petición PENDIENTE no escribe'
);
assertCondition(
    $legacy->query("SELECT verdict_seen_at FROM clan_applications WHERE id = 'app_solo'")->fetchColumn() === null,
    'La petición pendiente conserva su veredicto sin contemplar (NULL)'
);

// [4] La petición ajena jamás se escribe: la guardia vela por dueño.
assertCondition(
    $repository->markVerdictSeen('app_solo', 'usr_1', $primerInstante) === false,
    'El acknowledge sobre una petición AJENA no escribe'
);

// [5] El rótulo de dictámenes sin leer se apaga con el contemplado.
//     Se siembra un rechazo nuevo para usr_2 (la clausura solo vela por
//     casa repetida; usr_2 jamás peticionó a cln_a).
$legacy->prepare(
    'INSERT INTO clan_applications (id, clan_id, user_id, status, created_at, resolved_at)
     VALUES (:id, :clanId, :userId, :status, :createdAt, :resolvedAt)'
)->execute([':id' => 'app_rech', ':clanId' => 'cln_a', ':userId' => 'usr_2', ':status' => 'rejected', ':createdAt' => '2026-09-19T10:00:00Z', ':resolvedAt' => '2026-09-19T11:00:00Z']);
assertCondition($repository->countUnreadVerdicts('usr_2') === 1, 'El rótulo cuenta el rechazo sin contemplar de usr_2 (1)');
assertCondition($repository->countUnreadVerdicts('usr_1') === 0, 'El rótulo de usr_1 ya calla tras su contemplado (0)');
$repository->markVerdictSeen('app_rech', 'usr_2', $primerInstante);
assertCondition($repository->countUnreadVerdicts('usr_2') === 0, 'El acknowledge APAGA el rótulo de dictámenes a la espera');

// --- Veredicto ---
echo "\n=============================\n";
echo "Asertos: {$assertsPassed} PASA / {$assertsFailed} FALLOS\n";
if ($assertsFailed > 0) {
    echo "La migración del Vestíbulo queda ROJA: no cumple aún su contrato.\n";
    exit(1);
}
echo "La migración 10_clan_vestibule.sql cumple su contrato: idempotente, deduplicada y con la clausura como invariante físico.\n";
exit(0);
