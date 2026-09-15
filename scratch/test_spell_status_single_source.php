<?php

declare(strict_types=1);

/**
 * test_spell_status_single_source.php — Verificación de la Tarea 1.5 de TASKS-08.
 *
 * Valida la reconciliación del ciclo de vida del conjuro: `spell_reviews` es
 * la ÚNICA autoridad de estado y de firmas (RF-01.1), y `spells.status` /
 * `spells.signatures_count` son su ESPEJO denormalizado, con un solo escritor
 * (`SpellReviewRepository`) y dentro de la misma transacción.
 *
 * El criterio «Hecho cuando» de la tarea se comprueba en dos frentes:
 *   1. Sobre una base CANÓNICA: el espejo sigue a la autoridad en cada
 *      operación, los cinco estados caben, y ninguna otra pluma de `src/`
 *      escribe las columnas espejo (auditoría estática del fuente).
 *   2. Sobre una base LEGADA —con el CHECK antiguo de tres estados y conjuros
 *      anteriores a SPEC-08—: el guion `sql/08_spell_status_single_source.sql`
 *      siembra el expediente, ensancha el CHECK y reconcilia el espejo sin
 *      perder una sola fila ni romper una sola clave foránea.
 *
 * Fases:
 *   [0]  Superficie: guion, DDL canónico y método espejo.
 *   [1]  El espejo sigue a la autoridad (createOrUpdateReview, updateStatus,
 *        updateSignaturesCount) y un borrador sin expediente conserva su
 *        estado embrionario.
 *   [2]  El ÚNICO escritor: auditoría estática de todo `src/`.
 *   [3]  Los cinco estados caben en el espejo.
 *   [4]  La libreta del autor admite `rejected`; el Tomo Canónico no.
 *   [5]  El guion de ascensión sobre una base legada.
 *   [6]  El guion declara su orden, su verificación y su motivo.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin librerías externas.
 *   - Artículo V (Dualidad): identificadores en inglés snake_case; narrativa y
 *     comentarios en noble castellano.
 *
 * Uso: php scratch/test_spell_status_single_source.php
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

/** Recorre los ficheros PHP de un directorio, ignorando binarios. */
function projectPhpFiles(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

echo "== VERIFICACION TAREA 1.5: Un solo contador de estado para el conjuro ==\n\n";

$projectRoot = dirname(__DIR__);
$scriptPath = $projectRoot . '/sql/08_spell_status_single_source.sql';
$schemaPath = $projectRoot . '/database/schema.sql';
$repositoryPath = $projectRoot . '/src/Repositories/SpellReviewRepository.php';

// --- FASE 0: Superficie ---
echo "FASE 0: Superficie del guion y del espejo\n";
assertCondition(file_exists($scriptPath), 'Existe sql/08_spell_status_single_source.sql');
if (!file_exists($scriptPath)) {
    echo "\nRESULTADO: FALLO — falta el guion de reconciliacion de la Tarea 1.5.\n";
    exit(1);
}
$scriptSource = (string) file_get_contents($scriptPath);
$schemaSource = (string) file_get_contents($schemaPath);
$repositorySource = (string) file_get_contents($repositoryPath);

assertCondition(str_contains($schemaSource, "CHECK (status IN ('draft', 'experimental', 'validated', 'rejected', 'archived'))"), 'El DDL canonico declara los cinco estados de RF-01.1 en `spells.status`');
assertCondition(
    str_contains($schemaSource, 'AUTORIDAD es `spell_reviews.status`'),
    'El DDL canonico declara que la AUTORIDAD es `spell_reviews.status` (Tarea 1.5)'
);
assertCondition(
    str_contains($schemaSource, 'ESPEJO denormalizado del contador de firmas vivas'),
    'El DDL canonico declara el espejo del contador de firmas'
);
assertCondition(
    str_contains($repositorySource, 'private function mirrorSpellLifecycle(')
    && str_contains($repositorySource, 'ESPEJO denormalizado'),
    'SpellReviewRepository declara el metodo que escribe el espejo'
);
assertCondition(substr_count($repositorySource, 'mirrorSpellLifecycle(') === 4, 'El espejo se escribe desde los tres caminos de la autoridad (y una sola declaracion)');

$legacyBackup = null; // El guion se lee antes de cualquier base, para las auditorías estáticas.

// --- FASE 1: El espejo sigue a la autoridad ---
echo "\nFASE 1: El espejo sigue a la autoridad\n";
require_once $projectRoot . '/src/Repositories/SpellReviewRepository.php';
require_once $projectRoot . '/src/Models/User.php';
require_once $projectRoot . '/src/Dto/GrimoirePageDto.php';
require_once $projectRoot . '/src/Services/GrimoireQueryService.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON;');
$pdo->exec((string) file_get_contents($schemaPath));
$pdo->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));

$NOW = '2026-09-15T10:00:00Z';
$FINGERPRINT = str_repeat('d', 64);

$pdo->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
            VALUES ('usr_forjadora', 'Forjadora del Alba', 'forjadora@arcano.arc', 'x', 'editor', 'cln_primordial', '{$NOW}', '{$NOW}')");
$pdo->exec("INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, mana_cost,
                                clan_id, summary, math_fingerprint, status, created_at, updated_at)
            VALUES ('spl_espejo', 'obra-espejo', 'Obra del Espejo', 'usr_forjadora', 'evocation', 'fire', 20,
                    'cln_primordial', 'Obra para el arnes del espejo.', '{$FINGERPRINT}', 'draft', '{$NOW}', '{$NOW}')");
$pdo->exec("INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, mana_cost,
                                clan_id, summary, math_fingerprint, status, created_at, updated_at)
            VALUES ('spl_sin_expediente', 'obra-sin-expediente', 'Obra sin Expediente', 'usr_forjadora', 'evocation', 'fire', 20,
                    'cln_primordial', 'Borrador que nunca fue elevado.', '{$FINGERPRINT}', 'draft', '{$NOW}', '{$NOW}')");

$repository = new Grimorio\Repositories\SpellReviewRepository($pdo);
$mirrorState = static function (PDO $connection, string $spellId): array {
    $statement = $connection->prepare('SELECT status, signatures_count FROM spells WHERE id = :spellId');
    $statement->execute([':spellId' => $spellId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return $row === false ? [] : ['status' => (string) $row['status'], 'signatures_count' => (int) $row['signatures_count']];
};

assertCondition($mirrorState($pdo, 'spl_sin_expediente') === ['status' => 'draft', 'signatures_count' => 0], 'Un conjuro SIN expediente sostiene su estado embrionario: `draft`');

$review = $repository->createOrUpdateReview('rev_espejo', 'spl_espejo', 'usr_forjadora', 'experimental', $FINGERPRINT, 'cln_primordial', 0, $NOW);
assertCondition($review['status'] === 'experimental', 'El expediente nace en deliberacion');
assertCondition($mirrorState($pdo, 'spl_espejo') === ['status' => 'experimental', 'signatures_count' => 0], 'El espejo del conjuro se eleva a `experimental` con cero firmas en el mismo gesto');

$repository->updateSignaturesCount('spl_espejo', 2);
assertCondition($mirrorState($pdo, 'spl_espejo')['signatures_count'] === 2, 'Al subir el contador del expediente sube el espejo');
assertCondition((int) $pdo->query("SELECT signatures_count FROM spell_reviews WHERE spell_id = 'spl_espejo'")->fetchColumn() === 2, 'El contador del expediente y el del espejo no pueden separarse');

$repository->updateStatus('spl_espejo', 'rejected', '2026-09-16T10:00:00Z');
assertCondition($mirrorState($pdo, 'spl_espejo')['status'] === 'rejected', 'El veto del expediente se refleja en el conjuro: el quinto estado cabe en el espejo');
assertCondition((int) $pdo->query("SELECT signatures_count FROM spells WHERE id = 'spl_espejo'")->fetchColumn() === 2, 'Transicionar el estado no altera el contador del espejo');

$repository->updateStatus('spl_espejo', 'draft');
assertCondition($mirrorState($pdo, 'spl_espejo')['status'] === 'draft', 'La reapertura como borrador baja tambien el espejo');
assertCondition($repository->updateStatus('spl_inexistente', 'draft') === false, 'Transicionar un expediente inexistente devuelve false sin tocar el espejo');
assertCondition($repository->updateSignaturesCount('spl_inexistente', 1) === false, 'Contar firmas de un expediente inexistente devuelve false sin tocar el espejo');
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM spells WHERE id = 'spl_sin_expediente' AND status = 'draft' AND signatures_count = 0")->fetchColumn() === 1,
    'Ninguna operacion sobre un expediente ajeno alcanza al borrador sin expediente'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM spell_reviews WHERE spell_id = 'spl_sin_expediente'")->fetchColumn() === 0,
    'Un borrador sin expediente sigue sin tener fila en `spell_reviews`'
);

// --- FASE 2: El ÚNICO escritor ---
echo "\nFASE 2: Auditoria estatica del UNICO escritor (criterio)\n";
$sourceFiles = projectPhpFiles($projectRoot . '/src');
assertCondition(count($sourceFiles) >= 20, 'La auditoria recorre el arbol de fuentes del santuario (' . count($sourceFiles) . ' ficheros PHP)');

$mirrorWriters = [];
$embryoInserts = [];
foreach ($sourceFiles as $file) {
    $contents = (string) file_get_contents($file);
    // Solo la CLÁUSULA SET importa: `status = 'draft'` en un WHERE es una
    // lectura condicional, no una escritura del ciclo de vida. Se revisan las
    // dos formas que un escritor puede tomar: la asignación escrita en linea
    // dentro del SET, y la lista cerrada de asignaciones del repositorio
    // (`$assignments[] = 'status = ...'`), que es como el espejo se escribe.
    $assignsMirror = false;
    if (preg_match_all('/UPDATE spells\s+SET(.*?)\bWHERE\b/s', $contents, $setClauses) > 0) {
        foreach ($setClauses[1] as $setClause) {
            if (preg_match('/(^|[\s,])status\s*=|(^|[\s,])signatures_count\s*=/', $setClause) === 1) {
                $assignsMirror = true;
            }
        }
    }
    if (preg_match_all("/assignments\[\]\s*=\s*'(status|signatures_count)\s*=/", $contents) > 0) {
        $assignsMirror = true;
    }
    if ($assignsMirror) {
        $mirrorWriters[] = str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $file);
    }
    // El nacimiento legítimo: un INSERT de borrador que fija el estado embrionario.
    if (str_contains($contents, "INSERT INTO spells") && str_contains($contents, "':status'")) {
        $embryoInserts[] = str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $file);
    }
}
assertCondition(
    count($mirrorWriters) === 1 && str_ends_with($mirrorWriters[0], 'Repositories' . DIRECTORY_SEPARATOR . 'SpellReviewRepository.php'),
    'Ningun fichero de `src/` asigna `spells.status` ni `spells.signatures_count` fuera de SpellReviewRepository (escritores hallados: ' . implode(', ', $mirrorWriters) . ')'
);
assertCondition(
    count($embryoInserts) === 1,
    'El unico INSERT que fija estado es el nacimiento del borrador (estado embrionario `draft`)'
);
$serviceSource = (string) file_get_contents($projectRoot . '/src/Services/SpellManagementService.php');
assertCondition(
    str_contains($serviceSource, 'createOrUpdateReview(') && str_contains($serviceSource, 'updateSignaturesCount('),
    'SpellManagementService delega en la autoridad las dos transiciones que antes escribia en el espejo'
);
assertCondition(
    !str_contains($serviceSource, "SET status = 'experimental'") && !str_contains($serviceSource, 'signatures_count = :signaturesCount'),
    'La publicacion de SPEC-04 ya no escribe el ciclo de vida por su cuenta'
);

// --- FASE 3: Los cinco estados caben ---
echo "\nFASE 3: Los cinco estados canonicos caben en el espejo\n";
$pdo->exec("INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, mana_cost,
                                clan_id, summary, math_fingerprint, status, created_at, updated_at)
            VALUES ('spl_cinco', 'obra-cinco', 'Obra Cinco Estados', 'usr_forjadora', 'evocation', 'fire', 20,
                    'cln_primordial', 'Obra para recorrer los cinco estados.', '{$FINGERPRINT}', 'draft', '{$NOW}', '{$NOW}')");
$repository->createOrUpdateReview('rev_cinco', 'spl_cinco', 'usr_forjadora', 'experimental', $FINGERPRINT, 'cln_primordial', 0, $NOW);
$recorrido = [];
$secuencia = ['draft', 'experimental', 'rejected', 'experimental', 'validated', 'archived'];
foreach ($secuencia as $index => $status) {
    $repository->updateStatus('spl_cinco', $status, '2026-09-1' . ($index + 1) . 'T10:00:00Z');
    $recorrido[] = $mirrorState($pdo, 'spl_cinco')['status'];
}
assertCondition($recorrido === $secuencia, 'La obra recorre los cinco estados —incluidos `rejected` y `archived`— y el espejo la sigue paso a paso');
assertCondition(
    captureError(fn () => $pdo->exec("UPDATE spells SET status = 'inventado' WHERE id = 'spl_cinco'")) !== null,
    'Un estado fuera del canon es rechazado por la base: el CHECK sigue siendo la ultima muralla'
);
assertCondition(
    captureError(fn () => $pdo->exec("UPDATE spells SET signatures_count = 4 WHERE id = 'spl_cinco'")) !== null,
    'Un contador de firmas fuera de 0..3 es rechazado por la base'
);

// --- FASE 4: La libreta del autor admite `rejected` ---
echo "\nFASE 4: La libreta del autor admite `rejected`; el Tomo no (RF-01.4, RF-06.2)\n";
// El relato real: la obra fue vetada por un Maestro y volvio a la libreta.
$repository->updateStatus('spl_espejo', 'rejected', '2026-09-20T10:00:00Z');
assertCondition($mirrorState($pdo, 'spl_espejo')['status'] === 'rejected', 'La obra vetada queda en `rejected` en ambas caras de la verdad');
$author = new Grimorio\Models\User(
    id: 'usr_forjadora',
    alias: 'Forjadora del Alba',
    email: 'forjadora@arcano.arc',
    role: 'editor',
    clanId: 'cln_primordial',
    passwordHash: 'x',
    createdAt: $NOW,
    updatedAt: $NOW,
);
$grimoire = new Grimorio\Services\GrimoireQueryService($pdo);
$ensayos = $grimoire->getAuthorEssays($author, null, null, 1, 50);
$idsEnsayos = array_map(static fn (object $dto): string => (string) $dto->id, $ensayos['spells']);
assertCondition(
    count($ensayos['spells']) === 2 && in_array('spl_espejo', $idsEnsayos, true) && in_array('spl_sin_expediente', $idsEnsayos, true),
    'La libreta del autor lista su obra VETADA junto a su borrador: sin ella no podria leer la objecion ni subsanarla (RF-06.2)'
);
assertCondition(
    !in_array('spl_cinco', $idsEnsayos, true),
    'La obra desterrada (`archived`) no vuelve a la libreta: para el destierro no hay enmienda'
);
$tomo = $grimoire->getCanonicalSpells(null, null, 1, 50);
$idsTomo = array_map(static fn (object $dto): string => (string) $dto->id, $tomo['spells']);
assertCondition(
    !in_array('spl_espejo', $idsTomo, true) && !in_array('spl_cinco', $idsTomo, true),
    'El Tomo Canonico jamas exhibe obras vetadas ni desterradas'
);
assertCondition(
    count(array_filter($idsTomo, static fn (string $id): bool => $id !== '')) > 0,
    'El Tomo sigue sirviendo los conjuros consagrados de la genesis'
);

// --- FASE 5: El guion de ascensión sobre una base legada ---
echo "\nFASE 5: El guion de ascension sobre una base LEGADA\n";
$legacy = new PDO('sqlite::memory:');
$legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacy->exec('PRAGMA foreign_keys = ON;');
// Base legada: el plano anterior a la Tarea 1.5, con el CHECK de TRES estados.
$legacy->exec('CREATE TABLE users (
    id TEXT PRIMARY KEY, alias TEXT NOT NULL UNIQUE, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT \'editor\', clan_id TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
    recovery_token_hash TEXT NOT NULL DEFAULT \'\', recovery_token_expires_at TEXT, clan_id_legacy_placeholder TEXT
)');
$legacy->exec('CREATE TABLE magic_schools (slug TEXT PRIMARY KEY, name TEXT NOT NULL)');
$legacy->exec('CREATE TABLE clans (id TEXT PRIMARY KEY, slug TEXT NOT NULL UNIQUE, name TEXT NOT NULL, motto TEXT NOT NULL,
    created_at TEXT NOT NULL, coat_of_arms TEXT NOT NULL, lineage_type TEXT NOT NULL, admission_mode TEXT NOT NULL,
    status TEXT NOT NULL, weekly_points INTEGER NOT NULL DEFAULT 0, historical_points INTEGER NOT NULL DEFAULT 0,
    last_activity_at TEXT, updated_at TEXT NOT NULL, patriarch_id TEXT)');
$legacy->exec('CREATE TABLE spells (
    id TEXT PRIMARY KEY, slug TEXT NOT NULL UNIQUE, name TEXT NOT NULL, author_id TEXT NOT NULL REFERENCES users (id),
    magic_school TEXT NOT NULL REFERENCES magic_schools (slug), elemental_affinity TEXT NOT NULL DEFAULT \'none\',
    casting_time TEXT NOT NULL DEFAULT \'action\', mana_cost INTEGER NOT NULL CHECK (mana_cost >= 0 AND mana_cost <= 200),
    circle INTEGER NOT NULL DEFAULT 1 CHECK (circle >= 1 AND circle <= 5),
    math_fingerprint TEXT NOT NULL DEFAULT \'\' CHECK (length(math_fingerprint) = 64),
    clan_id TEXT NOT NULL REFERENCES clans (id), summary TEXT NOT NULL, description TEXT NOT NULL DEFAULT \'\',
    components_verbal TEXT NOT NULL DEFAULT \'\', components_somatic TEXT NOT NULL DEFAULT \'\',
    components_material TEXT NOT NULL DEFAULT \'\', damage INTEGER NOT NULL DEFAULT 0 CHECK (damage >= 0),
    healing INTEGER NOT NULL DEFAULT 0 CHECK (healing >= 0), barrier INTEGER NOT NULL DEFAULT 0 CHECK (barrier >= 0),
    crowd_control_type TEXT NOT NULL DEFAULT \'none\' CHECK (crowd_control_type IN (\'none\', \'slow\', \'root\', \'stun\')),
    range_type TEXT NOT NULL DEFAULT \'touch\' CHECK (range_type IN (\'touch\', \'short\', \'medium\', \'long\')),
    area_type TEXT NOT NULL DEFAULT \'singleTarget\' CHECK (area_type IN (\'singleTarget\', \'cone\', \'line\', \'sphere\')),
    duration_type TEXT NOT NULL DEFAULT \'instant\' CHECK (duration_type IN (\'instant\', \'concentration\', \'sustained\')),
    has_verbal INTEGER NOT NULL DEFAULT 0 CHECK (has_verbal IN (0, 1)),
    has_somatic INTEGER NOT NULL DEFAULT 0 CHECK (has_somatic IN (0, 1)),
    has_material INTEGER NOT NULL DEFAULT 0 CHECK (has_material IN (0, 1)),
    status TEXT NOT NULL DEFAULT \'draft\' CHECK (status IN (\'draft\', \'experimental\', \'validated\')),
    validation_signatures_count INTEGER NOT NULL DEFAULT 0 CHECK (validation_signatures_count >= 0),
    signatures_count INTEGER NOT NULL DEFAULT 0 CHECK (signatures_count >= 0 AND signatures_count <= 3),
    is_genesis_sample INTEGER NOT NULL DEFAULT 0 CHECK (is_genesis_sample IN (0, 1)),
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL, validated_at TEXT
)');
$legacy->exec('CREATE INDEX idx_spells_slug ON spells (slug)');
$legacy->exec('CREATE INDEX idx_spell_author_status ON spells (author_id, status)');
$legacy->exec((string) file_get_contents($projectRoot . '/sql/08_moderation_schema.sql'));

$legacy->exec("INSERT INTO magic_schools (slug, name) VALUES ('evocation', 'Evocacion')");
$legacy->exec("INSERT INTO clans (id, slug, name, motto, created_at, coat_of_arms, lineage_type, admission_mode,
                                   status, weekly_points, historical_points, last_activity_at, updated_at)
               VALUES ('cln_primordial', 'custodios', 'Custodios', 'Antes de la primera palabra, ya ardimos.',
                       '{$NOW}', 'rune_flame_shield', 'primordialFlame', 'open', 'active', 0, 0, '{$NOW}', '{$NOW}')");
$legacy->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
               VALUES ('usr_legado', 'Forjador Legado', 'legado@arcano.arc', 'x', 'editor', 'cln_primordial', '{$NOW}', '{$NOW}')");

/** Inscribe un conjuro legado con su estado y su contador. */
$forgeLegacySpell = static function (PDO $connection, string $spellId, string $status, int $signatures, ?string $validatedAt) use ($NOW, $FINGERPRINT): void {
    $statement = $connection->prepare(
        'INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, mana_cost, circle,
                             math_fingerprint, clan_id, summary, status, validation_signatures_count, signatures_count,
                             created_at, updated_at, validated_at)
         VALUES (:id, :slug, :name, \'usr_legado\', \'evocation\', \'fire\', 20, 1,
                 :fingerprint, \'cln_primordial\', :summary, :status, 3, :signatures, :now, :now, :validatedAt)'
    );
    $statement->execute([
        ':id'          => $spellId,
        ':slug'        => 'legado-' . $spellId,
        ':name'        => 'Obra Legada ' . $spellId,
        ':fingerprint' => $FINGERPRINT,
        ':summary'     => 'Obra anterior a SPEC-08.',
        ':status'      => $status,
        ':signatures'  => $signatures,
        ':now'         => $NOW,
        ':validatedAt' => $validatedAt,
    ]);
};

$forgeLegacySpell($legacy, 'spl_legado_validado', 'validated', 3, $NOW);
$forgeLegacySpell($legacy, 'spl_legado_deliberando', 'experimental', 2, null);
$forgeLegacySpell($legacy, 'spl_legado_borrador', 'draft', 0, null);

assertCondition(
    captureError(fn () => $legacy->exec("UPDATE spells SET status = 'rejected' WHERE id = 'spl_legado_deliberando'")) !== null,
    'La base legada NO admite aun el quinto estado: su CHECK solo conoce tres'
);
assertCondition((int) $legacy->query('SELECT COUNT(*) FROM spell_reviews')->fetchColumn() === 0, 'La base legada no tiene expediente alguno: es el estado previo a SPEC-08');

$scriptSource = (string) file_get_contents($scriptPath);
$legacy->exec($scriptSource);

assertCondition((int) $legacy->query('SELECT COUNT(*) FROM spell_reviews')->fetchColumn() === 3, 'El guion siembra el expediente de los TRES conjuros legados');
$seed = $legacy->query("SELECT * FROM spell_reviews WHERE spell_id = 'spl_legado_validado'")->fetch(PDO::FETCH_ASSOC);
assertCondition($seed['status'] === 'validated', 'El expediente sembrado hereda el estado del espejo legado');
assertCondition((int) $seed['signatures_count'] === 3, 'El expediente sembrado hereda el contador de firmas');
assertCondition($seed['math_fingerprint'] === $FINGERPRINT, 'El expediente sembrado hereda la huella del balance sellado (Art. II)');
assertCondition($seed['origin_clan_id'] === 'cln_primordial', 'El expediente sembrado retrata el clan de origen (Art. III)');
assertCondition($seed['validated_at'] === $NOW, 'El expediente sembrado deduce la consagracion del espejo legado');
assertCondition($seed['submitted_at'] === $NOW, 'El expediente sembrado deduce la entrada a la Torre');
$draftSeed = $legacy->query("SELECT * FROM spell_reviews WHERE spell_id = 'spl_legado_borrador'")->fetch(PDO::FETCH_ASSOC);
assertCondition($draftSeed['status'] === 'draft' && $draftSeed['submitted_at'] === null, 'Un borrador legado se siembra sin entrada a la Torre: jamas fue elevado');

// La sonda se deshace a proposito: comprueba que el CHECK admite el quinto
// estado sin dejar su marca en la base.
$legacy->beginTransaction();
$probe = captureError(fn () => $legacy->exec("UPDATE spells SET status = 'rejected' WHERE id = 'spl_legado_deliberando'"));
$legacy->rollBack();
assertCondition($probe === null, 'Tras el guion, la base admite el quinto estado: el CHECK se ensancho de verdad');
assertCondition(
    (int) $legacy->query("SELECT COUNT(*) FROM spells WHERE id = 'spl_legado_deliberando' AND status = 'experimental'")->fetchColumn() === 1,
    'El espejo se reconcilia con la autoridad: el estado legado se conserva'
);
assertCondition(
    (int) $legacy->query("SELECT COUNT(*) FROM spells WHERE id = 'spl_legado_validado' AND signatures_count = 3")->fetchColumn() === 1,
    'El contador del espejo sigue fiel al de su expediente'
);
assertCondition(
    (int) $legacy->query('SELECT COUNT(*) FROM spells')->fetchColumn() === 3,
    'Ninguna fila se pierde en la reconstruccion de la tabla'
);
assertCondition(
    (int) $legacy->query('SELECT COUNT(*) FROM spells WHERE validation_signatures_count = 3')->fetchColumn() === 3,
    'El vestigio genesis `validation_signatures_count` sobrevive: no es un contador rival y el guion no lo toca'
);
assertCondition(
    $legacy->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC) === [],
    'Las claves foraneas quedan intactas tras la reconstruccion (PRAGMA foreign_key_check limpio)'
);
assertCondition(
    captureError(fn () => $legacy->exec("INSERT INTO spell_reviews (id, spell_id, author_id, status, signatures_count, math_fingerprint)
                                          VALUES ('rev_huerfana', 'spl_fantasma', 'usr_legado', 'draft', 0, '{$FINGERPRINT}')")) !== null,
    'La tabla reconstruida sigue rechazando expedientes huerfanos: la clave foranea vive'
);
assertCondition(
    (int) $legacy->query("SELECT COUNT(*) FROM spell_reviews WHERE spell_id = 'spl_legado_borrador'")->fetchColumn() === 1,
    'El expediente sembrado sobrevive a la reconstruccion de su tabla padre'
);

$legacy->exec($scriptSource);
assertCondition((int) $legacy->query('SELECT COUNT(*) FROM spell_reviews')->fetchColumn() === 3, 'Reaplicar el guion no duplica expedientes: es idempotente');
assertCondition((int) $legacy->query('SELECT COUNT(*) FROM spells')->fetchColumn() === 3, 'Reaplicar el guion no duplica conjuros');

// --- FASE 6: El guion declara su motivo ---
echo "\nFASE 6: El guion declara su orden, su motivo y su verificacion\n";
assertCondition(str_contains($scriptSource, 'DIAGNÓSTICO') && str_contains($scriptSource, 'DECISIÓN RATIFICADA'), 'El guion documenta su diagnostico y su decision ratificada');
assertCondition(
    str_contains($scriptSource, '`spell_reviews` es la ÚNICA autoridad del ciclo de vida'),
    'El guion declara quien es la autoridad y quien el espejo'
);
assertCondition(
    str_contains($scriptSource, 'database/schema.sql  →  database/seeds.sql  →  sql/08_moderation_schema.sql'),
    'El guion declara su orden de aplicacion sobre una base legada'
);
assertCondition(
    str_contains($scriptSource, 'WHERE r.status <> s.status')
    && str_contains($scriptSource, 'OR r.signatures_count <> s.signatures_count'),
    'El guion incluye la consulta de guardia que cualquier auditor puede repetir para detectar divergencia'
);
assertCondition(
    str_contains($scriptSource, 'PRAGMA foreign_keys = OFF') && str_contains($scriptSource, 'PRAGMA foreign_key_check'),
    'El guion blinda las claves foraneas durante la reconstruccion y las verifica despues'
);
assertCondition(
    str_contains($scriptSource, 'MySQL/MariaDB 8+') && str_contains($scriptSource, 'ALTER TABLE spells DROP CHECK'),
    'El guion documenta el dialecto alternativo para MySQL/MariaDB'
);
assertCondition(
    str_contains($scriptSource, 'validation_signatures_count') && str_contains($scriptSource, 'NO compite'),
    'El guion declara por que el vestigio genesis no se retira aqui'
);

echo "\n== RESUMEN == Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";
if ($assertsFailed === 0) {
    echo "RESULTADO: EXITO — El ciclo de vida del conjuro tiene un solo contador: `spell_reviews` manda y el espejo de `spells` la sigue (Tarea 1.5).\n";
    exit(0);
}

echo "RESULTADO: FALLO — Corregir los asertos en rojo antes de continuar.\n";
exit(1);
