<?php

/**
 * test_clan_repository.php — Verificación de la Tarea 1.2 de TASKS-07.
 *
 * Valida contra el criterio «Hecho cuando» de
 * specs/07-clans-lineages.tasks.md:
 *   «Todos los métodos de consulta y actualización ejecutan sentencias PDO
 *    preparadas, protegen nombres reservados de clanes disueltos y retornan
 *    arrays tipados o null según corresponda.»
 *
 * Estrategia: se levanta una base SQLite efímera en memoria con la secuencia
 * canónica del santuario (schema.sql + seeds.sql + migración de SPEC-07) y se
 * ejerce el repositorio real sobre ella, comprobando tipos, nulos, reserva de
 * nombres, atomicidad de los contadores y resistencia a la inyección SQL.
 *
 * Fases:
 *   [0] Superficie: la clase existe, es final, tipada en estricto y expone
 *       los 12 métodos del plan.
 *   [1] Base efímera con el esquema raíz, las semillas y la migración.
 *   [2] createClan(): funda y devuelve el array tipado de la hermandad.
 *   [3] findById() / findByName(): arrays tipados y null ante lo inexistente.
 *   [4] isNameAvailable(): libre, ocupado y —clave— RESERVADO por disolución.
 *   [5] setStatusArchived(): disuelve la casa y libera la corona.
 *   [6] RF-05.4: el nombre de un clan disuelto no se puede usurpar.
 *   [7] createClan() devuelve null si el Nombre Canónico ya está reservado.
 *   [8] Gobernanza: lema/blasón, régimen de admisión y traspaso de corona.
 *   [9] addWeeklyAndHistoricalPoints(): acreditación atómica de Dominio.
 *  [10] Clasificaciones: solo hermandades activas y orden descendente estable.
 *  [11] resetAllWeeklyPointsToZero(): pliegue histórico determinista (RF-04.3).
 *  [12] Inyección SQL: un nombre malicioso se guarda literal sin dañar el plano.
 *  [13] Dogma Vanilla y dualidad: 100% prepare, cero dependencias externas.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin librerías externas.
 *   - Artículo V: identificadores en inglés camelCase; narrativa en castellano.
 *
 * Uso: php scratch/test_clan_repository.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

declare(strict_types=1);

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

$projectRoot = dirname(__DIR__);

echo "== VERIFICACION TAREA 1.2: Repositorio de Clanes (ClanRepository) ==\n\n";

// --- FASE 0: Superficie del repositorio ---
echo "FASE 0: Superficie del repositorio\n";

$repositoryPath = $projectRoot . '/src/Repositories/ClanRepository.php';
assertCondition(file_exists($repositoryPath), 'Existe el fichero src/Repositories/ClanRepository.php');

if (!file_exists($repositoryPath)) {
    echo "\nRESULTADO: DENEGADO — falta el repositorio de SPEC-07 (fase roja del TDD).\n";
    exit(1);
}

require_once $projectRoot . '/public/index.php'; // Autoload nativo del proyecto.

use Grimorio\Repositories\ClanRepository;

$repositorySource = (string) file_get_contents($repositoryPath);
assertCondition(
    preg_match('/declare\(strict_types=1\);/', $repositorySource) === 1,
    'El repositorio declara tipos estrictos (declare(strict_types=1))'
);
assertCondition(class_exists(ClanRepository::class), 'La clase Grimorio\Repositories\ClanRepository se resuelve por autoload');

if (!class_exists(ClanRepository::class)) {
    echo "\nRESULTADO: DENEGADO — la clase no carga.\n";
    exit(1);
}

$reflection = new ReflectionClass(ClanRepository::class);
assertCondition($reflection->isFinal(), 'La clase es final (no admite herencia accidental)');

$requiredMethods = [
    'createClan', 'findById', 'findByName', 'isNameAvailable',
    'updateMottoAndHeraldry', 'updateAdmissionMode', 'updatePatriarch', 'setStatusArchived',
    'addWeeklyAndHistoricalPoints', 'resetAllWeeklyPointsToZero',
    'findActiveOrderedByWeeklyPointsDesc', 'findActiveOrderedByHistoricalPointsDesc',
];
$missingMethods = [];
foreach ($requiredMethods as $methodName) {
    if (!$reflection->hasMethod($methodName) || !$reflection->getMethod($methodName)->isPublic()) {
        $missingMethods[] = $methodName;
    }
}
assertCondition(
    $missingMethods === [],
    'El repositorio expone los 12 metodos del plan' . ($missingMethods === [] ? '' : ' (faltan: ' . implode(', ', $missingMethods) . ')')
);
assertCondition(
    (string) $reflection->getMethod('createClan')->getReturnType() === '?array',
    'createClan() declara retorno tipado ?array (array tipado o null)'
);
assertCondition(
    $reflection->getMethod('isNameAvailable')->getReturnType() !== null
        && (string) $reflection->getMethod('isNameAvailable')->getReturnType() === 'bool',
    'isNameAvailable() declara retorno bool'
);

// --- FASE 1: Base efímera con el esquema canónico completo ---
echo "\nFASE 1: Base SQLite efimera (schema + seeds + migracion de SPEC-07)\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON;');

$bootstrapOk = true;
try {
    $pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
    $pdo->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));
    // El DDL canónico ya incorpora el dominio de SPEC-07: no hay migración
    // que aplicar en una base recién construida.
} catch (PDOException $e) {
    $bootstrapOk = false;
    echo '  [FALLA] La secuencia SQL lanzo excepcion: ' . $e->getMessage() . "\n";
}
assertCondition($bootstrapOk, 'El plano relacional se materializa sin errores');

if (!$bootstrapOk) {
    echo "\nRESULTADO: DENEGADO — no se pudo preparar la base de pruebas.\n";
    exit(1);
}

$now = '2026-09-14T12:00:00Z';

/** Siembra un iniciado consagrado para poder ceñir coronas (FK a users). */
function seedUser(PDO $pdo, string $userId, string $alias, string $clanId, string $now): void
{
    $statement = $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
         VALUES (:id, :alias, :email, :hash, :role, :clanId, :createdAt, :updatedAt)'
    );
    $statement->execute([
        ':id'        => $userId,
        ':alias'     => $alias,
        ':email'     => $userId . '@arcano.arc',
        ':hash'      => 'x',
        ':role'      => 'master',
        ':clanId'    => $clanId,
        ':createdAt' => $now,
        ':updatedAt' => $now,
    ]);
}

seedUser($pdo, 'usr_patriarca_uno', 'Patriarca Uno', 'cln_primordial', $now);
seedUser($pdo, 'usr_adepto_dos', 'Adepto Dos', 'cln_primordial', $now);

$repository = new ClanRepository($pdo);

// --- FASE 2: Fundación y tipado del array devuelto ---
echo "\nFASE 2: Fundacion de hermandades y tipado del retorno\n";
$flame = $repository->createClan(
    clanId: 'cln_llama',
    slug: 'llama-primordial',
    name: 'Custodios de la Llama',
    motto: 'Antes de la primera palabra, ya ardimos.',
    coatOfArms: 'rune_flame_shield',
    lineageType: 'primordialFlame',
    admissionMode: 'open',
    patriarchId: 'usr_patriarca_uno',
    nowUtc: $now,
);

assertCondition(is_array($flame), 'createClan() devuelve un array tipado (no null) al fundar');
assertCondition(($flame['id'] ?? null) === 'cln_llama', 'La hermandad devuelta porta su identificador');
assertCondition(($flame['status'] ?? null) === 'active', 'Toda hermandad nace en estado activo');
assertCondition(
    ($flame['lineage_type'] ?? null) === 'primordialFlame'
        && ($flame['admission_mode'] ?? null) === 'open'
        && ($flame['coat_of_arms'] ?? null) === 'rune_flame_shield',
    'La heraldica, el linaje y el regimen de admision se persisten integros'
);
assertCondition(($flame['patriarch_id'] ?? null) === 'usr_patriarca_uno', 'El fundador ciñe la corona de Patriarca');
assertCondition(
    ($flame['weekly_points'] ?? null) === 0 && ($flame['historical_points'] ?? null) === 0,
    'La casa nace sin puntos de Dominio'
);
assertCondition(
    is_int($flame['weekly_points'] ?? null) && is_int($flame['historical_points'] ?? null),
    'Los contadores viajan como enteros nativos (array tipado)'
);
// Un solo contador de gloria (Tarea 2.6): el vestigio `domain_points` ya no
// figura en la proyección canónica del repositorio.
assertCondition(
    array_key_exists('domain_points', $flame) === false,
    'La proyección del repositorio no expone el contador vestigial'
);
assertCondition(
    ($flame['created_at'] ?? null) === $now && ($flame['updated_at'] ?? null) === $now
        && ($flame['last_activity_at'] ?? null) === $now,
    'Las marcas temporales heredan el instante de fundacion (determinismo RNF-01)'
);

// Dos casas mas para las clasificaciones posteriores.
$tides = $repository->createClan('cln_mareas', 'mareas-celestiales', 'Mareas Celestiales', 'Fluye lo eterno.',
    'rune_aqua_crest', 'celestialTides', 'byApplication', 'usr_patriarca_uno', $now);
$tempest = $repository->createClan('cln_tempestad', 'tempestad-eterna', 'Tempestad Eterna', 'El trueno jamas descansa.',
    'rune_fulgur_shield', 'eternalTempest', 'open', 'usr_patriarca_uno', $now);
$shadows = $repository->createClan('cln_sombras', 'sombras-abisales', 'Sombras Abisales', 'En el silencio, reinamos.',
    'rune_tenebrae_sigil', 'abyssalShadows', 'open', 'usr_patriarca_uno', $now);
assertCondition(
    is_array($tides) && is_array($tempest) && is_array($shadows),
    'Se fundan las hermandades de apoyo sin colision de identidad'
);

// --- FASE 3: Lecturas tipadas y nulos ---
echo "\nFASE 3: Lecturas tipadas y nulos coherentes\n";
$foundById = $repository->findById('cln_mareas');
assertCondition(($foundById['name'] ?? null) === 'Mareas Celestiales', 'findById() recupera la hermandad por identificador');
assertCondition(is_int($foundById['weekly_points'] ?? null), 'findById() entrega contadores como enteros');

$foundByName = $repository->findByName('Tempestad Eterna');
assertCondition(($foundByName['id'] ?? null) === 'cln_tempestad', 'findByName() recupera la hermandad por Nombre Canonico');
assertCondition($repository->findById('cln_inexistente') === null, 'findById() devuelve null ante un identificador inexistente');
assertCondition($repository->findByName('Hermandad Imaginaria') === null, 'findByName() devuelve null ante un nombre inexistente');

// --- FASE 4: Disponibilidad del Nombre Canónico ---
echo "\nFASE 4: Disponibilidad del Nombre Canonico\n";
assertCondition($repository->isNameAvailable('Heraldos del Alba') === true, 'Un nombre virgen esta disponible');
assertCondition($repository->isNameAvailable('Mareas Celestiales') === false, 'Un nombre ya reclamado no esta disponible');

// --- FASE 5: Disolución de la hermandad (RF-05.3) ---
echo "\nFASE 5: Disolucion hacia Herencia Ancestral (RF-05.3)\n";
assertCondition($repository->setStatusArchived('cln_sombras', $now) === true, 'setStatusArchived() confirma la disolucion');
$archived = $repository->findById('cln_sombras');
assertCondition(($archived['status'] ?? null) === 'archived', 'La hermandad queda en estado archived');
assertCondition(($archived['patriarch_id'] ?? null) === null, 'Una casa disuelta no conserva Patriarca en funciones (corona liberada)');
assertCondition(
    $repository->findById('cln_sombras') !== null,
    'La fila jamas se borra: la Herencia Ancestral persiste en el plano'
);
assertCondition($repository->setStatusArchived('cln_inexistente', $now) === false, 'setStatusArchived() devuelve false si la hermandad no existe');

// --- FASE 6: Reserva perpetua del nombre (RF-05.4) ---
echo "\nFASE 6: El nombre de un clan disuelto permanece reservado (RF-05.4)\n";
assertCondition(
    $repository->isNameAvailable('Sombras Abisales') === false,
    'isNameAvailable() protege el nombre de una hermandad ARCHIVADA'
);
assertCondition(
    $repository->findByName('Sombras Abisales') !== null,
    'findByName() sigue hallando la hermandad disuelta'
);

// --- FASE 7: createClan() con identidad reservada ---
echo "\nFASE 7: createClan() ante identidad ya reservada\n";
$clanCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM clans')->fetchColumn();
$usurper = $repository->createClan('cln_usurpador', 'sombras-abisales-ii', 'Sombras Abisales', 'Lema usurpado.',
    'rune_tenebrae_sigil', 'abyssalShadows', 'open', 'usr_patriarca_uno', $now);
assertCondition($usurper === null, 'createClan() devuelve null si el Nombre Canonico esta reservado');
assertCondition(
    (int) $pdo->query('SELECT COUNT(*) FROM clans')->fetchColumn() === $clanCountBefore,
    'La usurpacion no deja rastro alguno en el plano'
);

// --- FASE 8: Gobernanza de la hermandad ---
echo "\nFASE 8: Gobernanza (lema, blason, regimen y corona)\n";
assertCondition(
    $repository->updateMottoAndHeraldry('cln_mareas', 'Herederos del fulgor que nunca muere', 'rune_solar_crest', $now) === true,
    'updateMottoAndHeraldry() consolida el cambio'
);
$updated = $repository->findById('cln_mareas');
assertCondition(
    ($updated['motto'] ?? null) === 'Herederos del fulgor que nunca muere'
        && ($updated['coat_of_arms'] ?? null) === 'rune_solar_crest',
    'El lema y el blason quedan persistidos'
);

assertCondition($repository->updateAdmissionMode('cln_tempestad', 'byApplication', $now) === true, 'updateAdmissionMode() conmuta el regimen');
assertCondition(
    ($repository->findById('cln_tempestad')['admission_mode'] ?? null) === 'byApplication',
    'El regimen bajo peticion queda persistido'
);

assertCondition($repository->updatePatriarch('cln_mareas', 'usr_adepto_dos', $now) === true, 'updatePatriarch() transfiere la corona');
$transferred = $repository->findById('cln_mareas');
assertCondition(($transferred['patriarch_id'] ?? null) === 'usr_adepto_dos', 'El nuevo Patriarca queda registrado');
assertCondition(($transferred['last_activity_at'] ?? null) === $now, 'El traspaso refresca la ultima actividad de la casa (RF-01.9)');

assertCondition($repository->updateMottoAndHeraldry('cln_inexistente', 'x', 'y', $now) === false, 'updateMottoAndHeraldry() devuelve false si la hermandad no existe');
assertCondition($repository->updateAdmissionMode('cln_inexistente', 'open', $now) === false, 'updateAdmissionMode() devuelve false si la hermandad no existe');
assertCondition($repository->updatePatriarch('cln_inexistente', 'usr_adepto_dos', $now) === false, 'updatePatriarch() devuelve false si la hermandad no existe');

$invalidModeRejected = false;
try {
    $repository->updateAdmissionMode('cln_mareas', 'regimenInventado', $now);
} catch (InvalidArgumentException $e) {
    $invalidModeRejected = true;
}
assertCondition($invalidModeRejected, 'Un regimen fuera del canon se rechaza antes de tocar la base de datos');

// --- FASE 9: Acreditación de Dominio ---
echo "\nFASE 9: Acreditacion atomica de Puntos de Dominio (RF-03.1)\n";
assertCondition($repository->addWeeklyAndHistoricalPoints('cln_llama', 10, 10, $now) === true, 'addWeeklyAndHistoricalPoints() acredita ambas magnitudes');
$credited = $repository->findById('cln_llama');
assertCondition(
    ($credited['weekly_points'] ?? null) === 10 && ($credited['historical_points'] ?? null) === 10,
    'Los contadores semanal e historico crecen por separado'
);
assertCondition($repository->addWeeklyAndHistoricalPoints('cln_llama', 5, 0, $now) === true, 'Una segunda acreditacion se acumula');
$creditedAgain = $repository->findById('cln_llama');
assertCondition(
    ($creditedAgain['weekly_points'] ?? null) === 15 && ($creditedAgain['historical_points'] ?? null) === 10,
    'Acumular semanal sin tocar el historico es posible (historicalPoints = 0)'
);
assertCondition($repository->addWeeklyAndHistoricalPoints('cln_inexistente', 10, 10, $now) === false, 'addWeeklyAndHistoricalPoints() devuelve false si la hermandad no existe');

// Puntos distintivos para verificar el orden de las clasificaciones.
$repository->addWeeklyAndHistoricalPoints('cln_mareas', 30, 20, $now);
$repository->addWeeklyAndHistoricalPoints('cln_tempestad', 30, 0, $now);

// --- FASE 10: Clasificaciones del Salón de los Linajes ---
echo "\nFASE 10: Clasificaciones semanal e historica (RF-06.1)\n";
$weeklyRanking = $repository->findActiveOrderedByWeeklyPointsDesc();
$weeklyIds = array_column($weeklyRanking, 'id');
assertCondition(
    !in_array('cln_sombras', $weeklyIds, true),
    'La clasificacion semanal excluye a las hermandades disueltas'
);
assertCondition(
    array_search('cln_mareas', $weeklyIds, true) < array_search('cln_tempestad', $weeklyIds, true),
    'El empate a 30 PDA se rompe por el historico mayor (Mareas antes que Tempestad)'
);
assertCondition(
    array_search('cln_tempestad', $weeklyIds, true) < array_search('cln_llama', $weeklyIds, true),
    'La clasificacion semanal desciende por PDA semanales'
);
assertCondition(
    array_is_list($weeklyRanking),
    'La clasificacion devuelve una lista indexada (array tipado ordenado)'
);

$historicalRanking = $repository->findActiveOrderedByHistoricalPointsDesc();
$historicalIds = array_column($historicalRanking, 'id');
assertCondition(
    array_search('cln_mareas', $historicalIds, true) < array_search('cln_llama', $historicalIds, true)
        && array_search('cln_llama', $historicalIds, true) < array_search('cln_tempestad', $historicalIds, true),
    'La clasificacion historica desciende por Prestigio Historico Total'
);
assertCondition(
    !in_array('cln_sombras', $historicalIds, true),
    'La clasificacion historica tambien excluye a las hermandades disueltas'
);
$firstWeekly = $repository->findActiveOrderedByWeeklyPointsDesc();
assertCondition(
    array_column($firstWeekly, 'id') === $weeklyIds,
    'Dos consultas identicas devuelven la misma cronica (determinismo RNF-01)'
);

// --- FASE 11: Cierre dominical (RF-04.3) ---
echo "\nFASE 11: Pliegue historico del cierre dominical (RF-04.3)\n";
$closeNow = '2026-09-20T23:59:59Z';
// Expectativa derivada del estado real: el pliegue suma el semanal vigente
// al historico acumulado, sea cual sea su magnitud.
$beforeClose = $repository->findById('cln_mareas');
$expectedHistorical = (int) $beforeClose['historical_points'] + (int) $beforeClose['weekly_points'];
assertCondition(
    $expectedHistorical === 50,
    "Estado previo al cierre coherente: Mareas acumula 20 historicos + 30 semanales ({$expectedHistorical})"
);

$affectedClans = $repository->resetAllWeeklyPointsToZero($closeNow);
assertCondition($affectedClans >= 5, "El pliegue alcanza a todas las hermandades ({$affectedClans})");
$folded = $repository->findById('cln_mareas');
assertCondition(
    ($folded['weekly_points'] ?? null) === 0 && ($folded['historical_points'] ?? null) === $expectedHistorical,
    'El pliegue suma el semanal vigente al historico acumulado y reinicia el semanal a 0'
);
$foldedDisolved = $repository->findById('cln_sombras');
assertCondition(
    ($foldedDisolved['historical_points'] ?? null) === 0 && ($foldedDisolved['status'] ?? null) === 'archived',
    'Las hermandades disueltas tambien se pliegan y conservan su estado archivado'
);
$secondReset = $repository->resetAllWeeklyPointsToZero($closeNow);
assertCondition(
    $secondReset >= 5 && (($repository->findById('cln_mareas')['historical_points'] ?? null) === $expectedHistorical),
    'Un segundo pliegue con los semanales a 0 es idempotente (no infla la historia)'
);

// --- FASE 12: Resistencia a la inyección SQL ---
echo "\nFASE 12: Resistencia a la inyeccion SQL\n";
$maliciousName = "Ignis'); DROP TABLE clans;--";
$malicious = $repository->createClan(
    clanId: 'cln_veneno',
    slug: 'veneno-arcano',
    name: $maliciousName,
    motto: "Lema'); DELETE FROM clans;--",
    coatOfArms: 'rune_venom',
    lineageType: 'worldRoots',
    admissionMode: 'open',
    patriarchId: 'usr_patriarca_uno',
    nowUtc: $now,
);
assertCondition(is_array($malicious), 'Un nombre con carga maliciosa se admite como texto literario');
$clansTableStillExists = (bool) $pdo->query(
    "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'clans'"
)->fetchColumn();
assertCondition($clansTableStillExists, 'La tabla clans sobrevive intacta al intento de inyeccion');
$roundTripped = $repository->findByName($maliciousName);
assertCondition(
    ($roundTripped['name'] ?? null) === $maliciousName,
    'El nombre malicioso se recupera literal, byte a byte (parameter binding)'
);
assertCondition(
    ($roundTripped['motto'] ?? null) === "Lema'); DELETE FROM clans;--",
    'El lema malicioso tambien viaja como dato inerte'
);

// --- FASE 13: Dogma Vanilla y dualidad lingüística ---
echo "\nFASE 13: Dogma Vanilla y dualidad linguistica\n";
assertCondition(
    str_contains($repositorySource, '->prepare(') && !str_contains($repositorySource, '->query(')
        && !str_contains($repositorySource, '->exec('),
    'El 100% de los accesos usan sentencias preparadas (sin query()/exec() con datos)'
);
$externalImports = [];
preg_match_all('/^use\s+([^;]+);/m', $repositorySource, $importMatches);
foreach ($importMatches[1] as $importedNamespace) {
    $normalizedImport = trim($importedNamespace);
    // Solo se admiten built-ins de PHP (PDO y excepciones nativas): cero librerías.
    if (!in_array($normalizedImport, ['PDO', 'PDOException', 'InvalidArgumentException'], true)) {
        $externalImports[] = $normalizedImport;
    }
}
assertCondition($externalImports === [], 'Cero dependencias externas: solo se importan built-ins de PHP');
assertCondition(
    str_contains($repositorySource, 'namespace Grimorio\\Repositories;'),
    'El repositorio vive en el namespace canonico Grimorio\Repositories'
);

// --- Resumen final ---
echo "\n== RESUMEN ==\n";
echo "Asertos superados: {$assertsPassed}\n";
echo "Asertos fallidos:  {$assertsFailed}\n";

if ($assertsFailed === 0) {
    echo "\nRESULTADO: EXITO — La Tarea 1.2 cumple su criterio 'Hecho cuando'.\n";
    exit(0);
}

echo "\nRESULTADO: DENEGADO — Corregir los asertos marcados con [FALLA].\n";
exit(1);
