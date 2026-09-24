<?php

/**
 * test_clan_member_repository.php — Verificación de la Tarea 1.3 de TASKS-07.
 *
 * Valida contra el criterio «Hecho cuando» de
 * specs/07-clans-lineages.tasks.md:
 *   «Invocar isUserInConvalescence() devuelve true si la fecha de expiración
 *    es futura, y countActiveMembers() refleja con precisión el cupo ocupado
 *    del clan.»
 *
 * Estrategia: se levanta una base SQLite efímera en memoria con la secuencia
 * canónica del santuario (schema.sql + seeds.sql + migración de SPEC-07) y se
 * ejerce el repositorio real sobre ella, comprobando la lealtad indivisible,
 * el censo exacto, la convalecencia y su frontera exacta, el historial que
 * sostiene el veto de 30 días y el orden de sucesión por antigüedad.
 *
 * Fases:
 *   [0] Superficie: la clase existe, es final, tipada en estricto, expone los
 *       8 métodos del plan y publica el cupo canónico de 30 adeptos.
 *   [1] Base efímera con el esquema raíz, las semillas y la migración.
 *   [2] addMember(): inscribe al adepto y devuelve el array tipado.
 *   [3] RF-01.1: la lealtad es indivisible (una sola membresía activa).
 *   [4] findActiveMembership(): afiliación vigente y nulo cuando es libre.
 *   [5] countActiveMembers(): censo exacto del cupo (0, 1 y la plenitud de 30).
 *   [6] removeMember(): cierra la afiliación y abre la convalecencia.
 *   [7] isUserInConvalescence(): futuro verdadero, pasado falso y frontera.
 *   [8] findPastMembershipsSince(): historial para el veto de 30 días (Art. III).
 *   [9] findMembersByClan(): censo activo, Patriarca primero y antigüedad (RF-01.9).
 *  [10] setRole(): corona y rango nobiliario sobre la afiliación vigente.
 *  [11] Art. III: reafiliarse tras la convalecencia preserva el historial.
 *  [12] Inyección SQL, Dogma Vanilla y dualidad lingüística.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin librerías externas.
 *   - Artículo V: identificadores en inglés camelCase; narrativa en castellano.
 *
 * Uso: php scratch/test_clan_member_repository.php
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

echo "== VERIFICACION TAREA 1.3: Repositorio de Membresias y Convalecencia ==\n\n";

// --- FASE 0: Superficie del repositorio ---
echo "FASE 0: Superficie del repositorio\n";

$repositoryPath = $projectRoot . '/src/Repositories/ClanMemberRepository.php';
assertCondition(file_exists($repositoryPath), 'Existe el fichero src/Repositories/ClanMemberRepository.php');

if (!file_exists($repositoryPath)) {
    echo "\nRESULTADO: DENEGADO — falta el repositorio de SPEC-07 (fase roja del TDD).\n";
    exit(1);
}

require_once $projectRoot . '/public/index.php'; // Autoload nativo del proyecto.

use Grimorio\Repositories\ClanMemberRepository;

$repositorySource = (string) file_get_contents($repositoryPath);
assertCondition(
    preg_match('/declare\(strict_types=1\);/', $repositorySource) === 1,
    'El repositorio declara tipos estrictos (declare(strict_types=1))'
);
assertCondition(class_exists(ClanMemberRepository::class), 'La clase Grimorio\Repositories\ClanMemberRepository se resuelve por autoload');

if (!class_exists(ClanMemberRepository::class)) {
    echo "\nRESULTADO: DENEGADO — la clase no carga.\n";
    exit(1);
}

$reflection = new ReflectionClass(ClanMemberRepository::class);
assertCondition($reflection->isFinal(), 'La clase es final (no admite herencia accidental)');

$requiredMethods = [
    'addMember', 'removeMember', 'findActiveMembership', 'findMembersByClan',
    'countActiveMembers', 'isUserInConvalescence', 'findPastMembershipsSince', 'setRole',
];
$missingMethods = [];
foreach ($requiredMethods as $methodName) {
    if (!$reflection->hasMethod($methodName) || !$reflection->getMethod($methodName)->isPublic()) {
        $missingMethods[] = $methodName;
    }
}
assertCondition(
    $missingMethods === [],
    'El repositorio expone los 8 metodos del plan' . ($missingMethods === [] ? '' : ' (faltan: ' . implode(', ', $missingMethods) . ')')
);
assertCondition(
    $reflection->hasConstant('MAX_ACTIVE_MEMBERS')
        && $reflection->getConstant('MAX_ACTIVE_MEMBERS') === 30,
    'El cupo canonico de 30 adeptos se publica como fuente unica (RF-01.4)'
);
assertCondition(
    (string) $reflection->getMethod('countActiveMembers')->getReturnType() === 'int',
    'countActiveMembers() declara retorno int'
);
assertCondition(
    (string) $reflection->getMethod('isUserInConvalescence')->getReturnType() === 'bool',
    'isUserInConvalescence() declara retorno bool'
);
assertCondition(
    (string) $reflection->getMethod('addMember')->getReturnType() === '?array',
    'addMember() declara retorno tipado ?array (array tipado o null)'
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

/** Siembra una hermandad de apoyo directamente en el plano (foco en membresías). */
function seedClan(PDO $pdo, string $clanId, string $slug, string $name, string $now): void
{
    $statement = $pdo->prepare(
        'INSERT INTO clans (id, slug, name, motto, created_at,
                            coat_of_arms, lineage_type, admission_mode, status, patriarch_id,
                            weekly_points, historical_points, last_activity_at, updated_at)
         VALUES (:id, :slug, :name, :motto, :createdAt,
                 :coatOfArms, :lineageType, :admissionMode, :status, NULL,
                 0, 0, :lastActivityAt, :updatedAt)'
    );
    $statement->execute([
        ':id'             => $clanId,
        ':slug'           => $slug,
        ':name'           => $name,
        ':motto'          => 'Lema de la casa.',
        ':createdAt'      => $now,
        ':coatOfArms'     => 'rune_test',
        ':lineageType'    => 'primordialFlame',
        ':admissionMode'  => 'open',
        ':status'         => 'active',
        ':lastActivityAt' => $now,
        ':updatedAt'      => $now,
    ]);
}

/** Siembra un iniciado consagrado (FK obligatoria a clans). */
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
        ':role'      => 'editor',
        ':clanId'    => $clanId,
        ':createdAt' => $now,
        ':updatedAt' => $now,
    ]);
}

seedClan($pdo, 'cln_llama', 'llama-primordial', 'Custodios de la Llama', $now);
seedClan($pdo, 'cln_mareas', 'mareas-celestiales', 'Mareas Celestiales', $now);
seedClan($pdo, 'cln_plenitud', 'plenitud-rubrica', 'Plenitud Rubrica', $now);

seedUser($pdo, 'usr_patriarca', 'Patriarca Fundador', 'cln_primordial', $now);
seedUser($pdo, 'usr_adepto_uno', 'Adepto Uno', 'cln_primordial', $now);
seedUser($pdo, 'usr_adepto_dos', 'Adepto Dos', 'cln_primordial', $now);
seedUser($pdo, 'usr_adepto_tres', 'Adepto Tres', 'cln_primordial', $now);
seedUser($pdo, 'usr_errabundo', 'Errabundo Sin Casa', 'cln_primordial', $now);

$repository = new ClanMemberRepository($pdo);

// --- FASE 2: Inscripción de adeptos ---
echo "\nFASE 2: Inscripcion de adeptos y tipado del retorno\n";
$founder = $repository->addMember('clm_patriarca', 'cln_llama', 'usr_patriarca', 'patriarch', $now);
assertCondition(is_array($founder), 'addMember() devuelve un array tipado al inscribir');
assertCondition(
    ($founder['clan_id'] ?? null) === 'cln_llama'
        && ($founder['user_id'] ?? null) === 'usr_patriarca'
        && ($founder['role'] ?? null) === 'patriarch',
    'La membresia devuelta porta clan, adepto y rango'
);
// Ojo: `?? ` no sirve para distinguir «presente pero nulo», que es
// precisamente el contrato: las claves deben existir con valor null.
assertCondition(
    array_key_exists('left_at', $founder) && $founder['left_at'] === null
        && array_key_exists('convalescence_expires_at', $founder)
        && $founder['convalescence_expires_at'] === null,
    'La afiliacion nace ACTIVA y sin convalecencia (left_at y convalecencia nulos)'
);
assertCondition(($founder['joined_at'] ?? null) === $now, 'La fecha de ingreso se persiste integra');

$firstAdept = $repository->addMember('clm_adepto_uno', 'cln_llama', 'usr_adepto_uno', 'adept', '2026-09-10T09:00:00Z');
$secondAdept = $repository->addMember('clm_adepto_dos', 'cln_llama', 'usr_adepto_dos', 'adept', '2026-09-08T09:00:00Z');
$thirdAdept = $repository->addMember('clm_adepto_tres', 'cln_llama', 'usr_adepto_tres', 'adept', '2026-09-12T09:00:00Z');
assertCondition(
    is_array($firstAdept) && is_array($secondAdept) && is_array($thirdAdept),
    'Se inscriben los adeptos del linaje sin colision de identidad'
);

$invalidRoleRejected = false;
try {
    $repository->addMember('clm_rey', 'cln_llama', 'usr_errabundo', 'rey', $now);
} catch (InvalidArgumentException $e) {
    $invalidRoleRejected = true;
}
assertCondition($invalidRoleRejected, 'Un rango fuera del canon se rechaza antes de tocar la base de datos');

// --- FASE 3: Lealtad indivisible (RF-01.1) ---
echo "\nFASE 3: Lealtad indivisible (RF-01.1)\n";
$traitor = $repository->addMember('clm_traidor', 'cln_mareas', 'usr_adepto_uno', 'adept', $now);
assertCondition($traitor === null, 'Un adepto ya afiliado no puede inscribirse en otra casa (null)');
assertCondition(
    $repository->countActiveMembers('cln_mareas') === 0,
    'La afiliacion rechazada no deja rastro alguno en la casa ajena'
);
assertCondition(
    $repository->countActiveMembers('cln_llama') === 4,
    'El censo de la casa original permanece intacto'
);

// --- FASE 4: Afiliación vigente ---
echo "\nFASE 4: Afiliacion vigente del adepto\n";
$active = $repository->findActiveMembership('usr_adepto_uno');
assertCondition(
    ($active['clan_id'] ?? null) === 'cln_llama'
        && array_key_exists('left_at', $active) && $active['left_at'] === null,
    'findActiveMembership() recupera la membresia vigente del adepto'
);
assertCondition(
    $repository->findActiveMembership('usr_errabundo') === null,
    'findActiveMembership() devuelve null para un adepto sin casa'
);

// --- FASE 5: Censo del cupo (RF-01.4) ---
echo "\nFASE 5: Censo exacto del cupo ocupado (RF-01.4)\n";
assertCondition($repository->countActiveMembers('cln_mareas') === 0, 'Una casa recien fundada acusa cero adeptos');
assertCondition($repository->countActiveMembers('cln_llama') === 4, 'El censo refleja exactamente los 4 adeptos inscritos');
assertCondition(
    $repository->countActiveMembers('cln_inexistente') === 0,
    'Una casa inexistente no inventa adeptos: el censo es cero'
);

// Plenitud canonica: se llena una casa hasta el cupo estricto de 30.
for ($index = 1; $index <= ClanMemberRepository::MAX_ACTIVE_MEMBERS; $index++) {
    $userId = sprintf('usr_plenitud_%02d', $index);
    seedUser($pdo, $userId, "Adepto Plenitud {$index}", 'cln_primordial', $now);
    $repository->addMember(sprintf('clm_plenitud_%02d', $index), 'cln_plenitud', $userId, 'adept', $now);
}
assertCondition(
    $repository->countActiveMembers('cln_plenitud') === ClanMemberRepository::MAX_ACTIVE_MEMBERS,
    'El censo acusa con precision la plenitud estricta de 30 adeptos'
);

// --- FASE 6: Cierre de afiliación y convalecencia (RF-01.6) ---
echo "\nFASE 6: Cierre de afiliacion y apertura de la convalecencia (RF-01.6)\n";
$departureAt = '2026-09-14T12:00:00Z';
$convalescenceEndsAt = '2026-09-28T12:00:00Z'; // 14 días naturales de meditación.
assertCondition(
    $repository->removeMember('usr_adepto_dos', 'cln_llama', $departureAt, $convalescenceEndsAt) === true,
    'removeMember() cierra la afiliacion activa'
);
assertCondition(
    $repository->countActiveMembers('cln_llama') === 3,
    'El cupo se libera de inmediato al partir el adepto'
);
assertCondition(
    $repository->findActiveMembership('usr_adepto_dos') === null,
    'El adepto partido ya no porta membresia activa'
);
$closedMembership = $pdo->query("SELECT left_at, convalescence_expires_at FROM clan_members WHERE id = 'clm_adepto_dos'")->fetch(PDO::FETCH_ASSOC);
assertCondition(
    ($closedMembership['left_at'] ?? null) === $departureAt
        && ($closedMembership['convalescence_expires_at'] ?? null) === $convalescenceEndsAt,
    'La fila cerrada conserva la partida y el fin de los 14 dias'
);
assertCondition(
    $repository->removeMember('usr_errabundo', 'cln_llama', $departureAt, $convalescenceEndsAt) === false,
    'removeMember() devuelve false si el adepto no tenia afiliacion activa'
);
assertCondition(
    $repository->removeMember('usr_adepto_dos', 'cln_llama', $departureAt, $convalescenceEndsAt) === false,
    'removeMember() es idempotente: una afiliacion ya cerrada no se reescribe'
);

// --- FASE 7: Convalecencia Arcana y su frontera exacta ---
echo "\nFASE 7: Convalecencia Arcana y su frontera exacta\n";
assertCondition(
    $repository->isUserInConvalescence('usr_adepto_uno', $now) === false,
    'Un adepto en activo no esta en convalecencia'
);
assertCondition(
    $repository->isUserInConvalescence('usr_adepto_dos', $now) === true,
    'Recien partido, el adepto entra en convalecencia (expiracion futura)'
);
assertCondition(
    $repository->isUserInConvalescence('usr_adepto_dos', '2026-09-28T11:59:59Z') === true,
    'Un segundo antes del fin, la convalecencia sigue vigente'
);
assertCondition(
    $repository->isUserInConvalescence('usr_adepto_dos', '2026-09-28T12:00:00Z') === false,
    'En el instante exacto de expiracion, la convalecencia ya ha cesado'
);
assertCondition(
    $repository->isUserInConvalescence('usr_adepto_dos', '2026-10-05T00:00:00Z') === false,
    'Pasados los 14 dias, el adepto vuelve a ser libre'
);

// --- FASE 8: Historial para el veto de 30 días (RF-01.8, Art. III) ---
echo "\nFASE 8: Historial de linajes para el veto etico (RF-01.8, Art. III)\n";
$repository->addMember('clm_errabundo_uno', 'cln_mareas', 'usr_errabundo', 'adept', '2026-07-01T00:00:00Z');
$repository->removeMember('usr_errabundo', 'cln_mareas', '2026-08-20T00:00:00Z', '2026-09-03T00:00:00Z');
$repository->addMember('clm_errabundo_dos', 'cln_llama', 'usr_errabundo', 'adept', '2026-08-25T00:00:00Z');
$repository->removeMember('usr_errabundo', 'cln_llama', '2026-09-10T00:00:00Z', '2026-09-24T00:00:00Z');

$recentMemberships = $repository->findPastMembershipsSince('usr_errabundo', '2026-09-01T00:00:00Z');
assertCondition(
    count($recentMemberships) === 1 && ($recentMemberships[0]['clan_id'] ?? null) === 'cln_llama',
    'El historial acota las partidas posteriores a la fecha de corte (veto de 30 dias)'
);
$allMemberships = $repository->findPastMembershipsSince('usr_errabundo', '2026-01-01T00:00:00Z');
assertCondition(
    count($allMemberships) === 2 && ($allMemberships[0]['clan_id'] ?? null) === 'cln_llama',
    'El historial completo se ordena de la partida mas reciente a la mas antigua'
);
assertCondition(
    $repository->findPastMembershipsSince('usr_errabundo', '2026-09-11T00:00:00Z') === [],
    'Sin partidas desde el corte, el historial queda vacio (meta array vacio, no null)'
);

// --- FASE 9: Censo activo ordenado para la sucesión (RF-01.9) ---
echo "\nFASE 9: Censo activo, Patriarca primero y antiguedad (RF-01.9)\n";
$roster = $repository->findMembersByClan('cln_llama');
assertCondition(count($roster) === 3, 'El censo activo excluye a los adeptos partidos');
assertCondition(
    ($roster[0]['role'] ?? null) === 'patriarch' && ($roster[0]['user_id'] ?? null) === 'usr_patriarca',
    'El Patriarca encabeza siempre el censo de la casa'
);
$adeptOrder = array_values(array_map(
    static fn (array $member): string => (string) $member['user_id'],
    array_filter($roster, static fn (array $member): bool => $member['role'] === 'adept')
));
// Activos de la casa tras la partida de usr_adepto_dos: Uno ingresó el
// 2026-09-10 y Tres el 2026-09-12, de modo que la antiguedad los ordena
// Uno → Tres.
assertCondition(
    $adeptOrder === ['usr_adepto_uno', 'usr_adepto_tres'],
    'Los adeptos se alinean por antiguedad de ingreso: sucesion exacta de RF-01.9'
);
assertCondition(
    array_is_list($roster),
    'El censo devuelve una lista indexada (array tipado ordenado)'
);
assertCondition(
    $repository->findMembersByClan('cln_inexistente') === [],
    'Una casa inexistente devuelve un censo vacio (meta array vacio, no null)'
);

// --- FASE 10: Rangos nobiliarios y traspaso de la corona ---
echo "\nFASE 10: Rangos nobiliarios y traspaso de la corona (RF-01.3)\n";
assertCondition(
    $repository->setRole('usr_adepto_tres', 'cln_llama', 'patriarch') === true,
    'setRole() corona a un nuevo Patriarca'
);
assertCondition(
    ($repository->findActiveMembership('usr_adepto_tres')['role'] ?? null) === 'patriarch',
    'El nuevo rango queda persistido en la afiliacion vigente'
);
assertCondition(
    $repository->setRole('usr_patriarca', 'cln_llama', 'adept') === true,
    'setRole() degrada al Patriarca saliente a Adepto del Linaje'
);
assertCondition(
    ($repository->findActiveMembership('usr_patriarca')['role'] ?? null) === 'adept',
    'La degradacion queda persistida'
);
assertCondition(
    $repository->setRole('usr_errabundo', 'cln_llama', 'patriarch') === false,
    'setRole() devuelve false si el adepto no tiene afiliacion activa en la casa'
);
$closedRolePreserved = $pdo->query("SELECT role FROM clan_members WHERE id = 'clm_adepto_dos'")->fetchColumn();
assertCondition(
    $closedRolePreserved === 'adept',
    'El historial cerrado jamas se reescribe: conserva su rango original (Art. III)'
);
$invalidSetRoleRejected = false;
try {
    $repository->setRole('usr_patriarca', 'cln_llama', 'supremo');
} catch (InvalidArgumentException $e) {
    $invalidSetRoleRejected = true;
}
assertCondition($invalidSetRoleRejected, 'setRole() rechaza rangos fuera del canon');

// --- FASE 11: Reafiliación tras la convalecencia (Art. III) ---
echo "\nFASE 11: Reafiliacion preservando el historial (Art. III)\n";
$rejoined = $repository->addMember('clm_adepto_dos_ii', 'cln_mareas', 'usr_adepto_dos', 'adept', '2026-10-01T00:00:00Z');
assertCondition(is_array($rejoined), 'Expirada la convalecencia, el adepto puede profesar en otra casa');
assertCondition(
    $repository->countActiveMembers('cln_mareas') === 1,
    'La nueva casa acusa su incorporacion'
);
$historyRows = (int) $pdo->query(
    "SELECT COUNT(*) FROM clan_members WHERE user_id = 'usr_adepto_dos'"
)->fetchColumn();
assertCondition($historyRows === 2, 'El adepto conserva DOS registros: la partida y el nuevo juramento');
$departedStillClosed = $pdo->query(
    "SELECT left_at FROM clan_members WHERE id = 'clm_adepto_dos'"
)->fetchColumn();
assertCondition(
    $departedStillClosed !== null,
    'La vetusta membresia sigue cerrada y fechada: nada se borra ni se reutiliza'
);

// --- FASE 12: Inyección, Dogma Vanilla y dualidad ---
echo "\nFASE 12: Inyeccion, Dogma Vanilla y dualidad linguistica\n";
$maliciousUserId = "usr'); DROP TABLE clan_members;--";
seedUser($pdo, $maliciousUserId, 'Veneno Arcano', 'cln_primordial', $now);
$maliciousMembership = $repository->addMember('clm_veneno', 'cln_mareas', $maliciousUserId, 'adept', $now);
assertCondition(is_array($maliciousMembership), 'Un identificador con carga maliciosa se admite como texto literario');
$tableStillExists = (bool) $pdo->query(
    "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'clan_members'"
)->fetchColumn();
assertCondition($tableStillExists, 'La tabla clan_members sobrevive intacta al intento de inyeccion');
assertCondition(
    ($repository->findActiveMembership($maliciousUserId)['user_id'] ?? null) === $maliciousUserId,
    'El identificador malicioso se recupera literal, byte a byte (parameter binding)'
);

assertCondition(
    str_contains($repositorySource, '->prepare(') && !str_contains($repositorySource, '->query(')
        && !str_contains($repositorySource, '->exec('),
    'El 100% de los accesos usan sentencias preparadas (sin query()/exec() con datos)'
);
$externalImports = [];
preg_match_all('/^use\s+([^;]+);/m', $repositorySource, $importMatches);
foreach ($importMatches[1] as $importedNamespace) {
    $normalizedImport = trim($importedNamespace);
    // Solo se admiten built-ins de PHP (PDO y las jerarquías nativas de
    // excepciones, Throwable incluido): cero librerías.
    if (!in_array($normalizedImport, ['PDO', 'PDOException', 'InvalidArgumentException', 'Throwable'], true)) {
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
    echo "\nRESULTADO: EXITO — La Tarea 1.3 cumple su criterio 'Hecho cuando'.\n";
    exit(0);
}

echo "\nRESULTADO: DENEGADO — Corregir los asertos marcados con [FALLA].\n";
exit(1);
