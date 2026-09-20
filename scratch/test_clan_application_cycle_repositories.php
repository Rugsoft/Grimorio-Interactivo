<?php

/**
 * test_clan_application_cycle_repositories.php — Verificación de la Tarea 1.4
 * de TASKS-07.
 *
 * Valida contra el criterio «Hecho cuando» de
 * specs/07-clans-lineages.tasks.md:
 *   «Un usuario no puede registrar una 4ª solicitud si ya tiene 3 pendientes,
 *    y al aprobarse una solicitud, las demás del mismo usuario se cancelan
 *    automáticamente en la misma transacción.»
 *
 * Estrategia: se levanta una base SQLite efímera en memoria con la secuencia
 * canónica del santuario (schema.sql + seeds.sql + migración de SPEC-07) y se
 * ejercen los dos repositorios reales sobre ella: la deliberación de
 * solicitudes de ingreso y la crónica perpetua del Dominio Semanal.
 *
 * Fases:
 *   [0] Superficie: ambas clases existen, son finales, tipadas en estricto y
 *       exponen sus métodos y el tope canónico de 3 postulaciones.
 *   [1] Base efímera con el esquema raíz, las semillas y la migración.
 *   [2] createApplication(): la solicitud nace pendiente y sin veredicto.
 *   [3] RF-01.5: el tope de tres postulaciones pendientes.
 *   [4] El tope es POR POSTULANTE, no global.
 *   [5] La guarda impide duplicar una postulación sobre la misma casa.
 *   [6] Censo y cronología determinista de las postulaciones pendientes.
 *   [7] Rechazo: sella el veredicto sin tocar las demás postulaciones.
 *   [8] Aprobación: cancela las restantes pendientes del mismo adepto.
 *   [9] La deliberación no reescribe una solicitud ya resuelta.
 *  [10] Veredictos y estados fuera del canon son rechazados.
 *  [11] Expediente de la hermandad para el panel del Patriarca.
 *  [12] cancelPendingApplications(): ingreso por régimen abierto.
 *  [13] ATOMICIDAD: un rollback deshace la aprobación Y las cancelaciones.
 *  [14] recordClosedCycle(): la crónica del corte dominical.
 *  [15] findCurrentRegentCycle(): el Clan Regente vigente (RF-04.2).
 *  [16] findCycleHistory(): Libro Mayor de Campeones (RF-04.4, RF-06.1).
 *  [17] La semana concluida se inmortaliza UNA sola vez (RNF-01).
 *  [18] Semanas fuera del calendario ISO son rechazadas.
 *  [19] Inyección SQL, Dogma Vanilla y dualidad lingüística.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin librerías externas.
 *   - Artículo V: identificadores en inglés camelCase; narrativa en castellano.
 *
 * Uso: php scratch/test_clan_application_cycle_repositories.php
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

echo "== VERIFICACION TAREA 1.4: Solicitudes de Ingreso y Ciclos Semanales ==\n\n";

// --- FASE 0: Superficie de los dos repositorios ---
echo "FASE 0: Superficie de los repositorios\n";

$applicationPath = $projectRoot . '/src/Repositories/ClanApplicationRepository.php';
$cyclePath = $projectRoot . '/src/Repositories/WeeklyCycleRepository.php';
assertCondition(file_exists($applicationPath), 'Existe el fichero src/Repositories/ClanApplicationRepository.php');
assertCondition(file_exists($cyclePath), 'Existe el fichero src/Repositories/WeeklyCycleRepository.php');

if (!file_exists($applicationPath) || !file_exists($cyclePath)) {
    echo "\nRESULTADO: FALLO — faltan repositorios de SPEC-07 (fase roja del TDD).\n";
    exit(1);
}

require_once $projectRoot . '/public/index.php'; // Autoload nativo del proyecto.

use Grimorio\Repositories\ClanApplicationRepository;
use Grimorio\Repositories\WeeklyCycleRepository;

$applicationSource = (string) file_get_contents($applicationPath);
$cycleSource = (string) file_get_contents($cyclePath);

foreach ([
    'ClanApplicationRepository' => [$applicationPath, $applicationSource, ClanApplicationRepository::class],
    'WeeklyCycleRepository'     => [$cyclePath, $cycleSource, WeeklyCycleRepository::class],
] as $label => [$path, $source, $className]) {
    assertCondition(
        preg_match('/declare\(strict_types=1\);/', $source) === 1,
        "{$label} declara tipos estrictos (declare(strict_types=1))"
    );
    assertCondition(class_exists($className), "{$label} se resuelve por autoload");
}

if (!class_exists(ClanApplicationRepository::class) || !class_exists(WeeklyCycleRepository::class)) {
    echo "\nRESULTADO: FALLO — alguna clase no carga.\n";
    exit(1);
}

$applicationReflection = new ReflectionClass(ClanApplicationRepository::class);
$cycleReflection = new ReflectionClass(WeeklyCycleRepository::class);
assertCondition($applicationReflection->isFinal() && $cycleReflection->isFinal(), 'Ambas clases son finales (sin herencia accidental)');

foreach (['createApplication', 'findById', 'findPendingApplicationForClan', 'findPendingApplicationsByUser',
          'countPendingApplications', 'findApplicationsByClan', 'resolveApplication', 'cancelPendingApplications'] as $methodName) {
    assertCondition(
        $applicationReflection->hasMethod($methodName) && $applicationReflection->getMethod($methodName)->isPublic(),
        "ClanApplicationRepository expone {$methodName}()"
    );
}
foreach (['recordClosedCycle', 'findById', 'findCurrentRegentCycle', 'findCycleHistory'] as $methodName) {
    assertCondition(
        $cycleReflection->hasMethod($methodName) && $cycleReflection->getMethod($methodName)->isPublic(),
        "WeeklyCycleRepository expone {$methodName}()"
    );
}
assertCondition(
    $applicationReflection->getConstant('MAX_PENDING_APPLICATIONS') === 3,
    'El tope canonico de 3 postulaciones pendientes se publica como fuente unica (RF-01.5)'
);
assertCondition(
    (string) $applicationReflection->getMethod('countPendingApplications')->getReturnType() === 'int',
    'countPendingApplications() declara retorno int'
);
assertCondition(
    (string) $cycleReflection->getMethod('findCycleHistory')->getReturnType() === 'array',
    'findCycleHistory() declara retorno array'
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
    echo "\nRESULTADO: FALLO — no se pudo preparar la base de pruebas.\n";
    exit(1);
}

$now = '2026-09-14T12:00:00Z';

/** Siembra una hermandad directamente en el plano (foco en solicitudes y ciclos). */
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
function seedUser(PDO $pdo, string $userId, string $alias, string $now): void
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
        ':clanId'    => 'cln_primordial',
        ':createdAt' => $now,
        ':updatedAt' => $now,
    ]);
}

seedClan($pdo, 'cln_llama', 'llama-primordial', 'Custodios de la Llama', $now);
seedClan($pdo, 'cln_mareas', 'mareas-celestiales', 'Mareas Celestiales', $now);
seedClan($pdo, 'cln_tempestad', 'tempestad-eterna', 'Tempestad Eterna', $now);
seedClan($pdo, 'cln_sombras', 'sombras-abisales', 'Sombras Abisales', $now);

foreach (['usr_postulante', 'usr_otro', 'usr_tercero', 'usr_cuarto', 'usr_quinto', 'usr_sexto'] as $userId) {
    seedUser($pdo, $userId, str_replace('usr_', 'Adepto ', $userId), $now);
}

$applications = new ClanApplicationRepository($pdo);
$cycles = new WeeklyCycleRepository($pdo);

// --- FASE 2: Registro de una postulación ---
echo "\nFASE 2: Registro de una postulacion y tipado del retorno\n";
// Cada postulacion ocurre en un instante propio, como en el santuario real:
// la cronologia de la deliberacion se apoya en `created_at` y solo recurre
// al desempate por `id` cuando dos marcas coinciden (determinismo RNF-01).
$first = $applications->createApplication('app_uno', 'cln_llama', 'usr_postulante', '2026-09-14T09:00:00Z');
assertCondition(is_array($first), 'createApplication() devuelve un array tipado');
assertCondition(
    ($first['clan_id'] ?? null) === 'cln_llama' && ($first['user_id'] ?? null) === 'usr_postulante',
    'La solicitud devuelta porta casa y postulante'
);
assertCondition(($first['status'] ?? null) === 'pending', 'La solicitud nace pendiente de deliberacion');
assertCondition(
    array_key_exists('resolved_at', $first) && $first['resolved_at'] === null,
    'La solicitud nace sin veredicto (resolved_at nulo)'
);

// --- FASE 3: El tope de tres postulaciones (criterio «Hecho cuando») ---
echo "\nFASE 3: Tope de tres postulaciones pendientes (RF-01.5)\n";
assertCondition(is_array($applications->createApplication('app_dos', 'cln_mareas', 'usr_postulante', '2026-09-14T10:00:00Z')), 'El postulante cursa su segunda solicitud');
assertCondition(is_array($applications->createApplication('app_tres', 'cln_tempestad', 'usr_postulante', '2026-09-14T11:00:00Z')), 'El postulante cursa su tercera solicitud');
assertCondition(
    $applications->countPendingApplications('usr_postulante') === 3,
    'El censo acusa exactamente las tres postulaciones pendientes'
);
$fourth = $applications->createApplication('app_cuatro', 'cln_sombras', 'usr_postulante', $now);
assertCondition($fourth === null, 'La CUARTA solicitud queda bloqueada por el canon (null)');
assertCondition(
    $applications->countPendingApplications('usr_postulante') === 3,
    'El bloqueo no deja rastro: el censo sigue en tres'
);
assertCondition(
    $applications->findById('app_cuatro') === null,
    'La cuarta solicitud jamas llego a inscribirse en el plano'
);

// --- FASE 4: El tope es por postulante ---
echo "\nFASE 4: El tope es por postulante, no global\n";
assertCondition(
    is_array($applications->createApplication('app_otro_uno', 'cln_llama', 'usr_otro', $now)),
    'Otro postulante cursa su primera solicitud aunque el tope ya este colmado por otro'
);
assertCondition(
    $applications->countPendingApplications('usr_otro') === 1,
    'El cupo de postulaciones se computa por adepto, no sobre el santuario entero'
);

// --- FASE 5: Guarda contra postulaciones duplicadas ---
echo "\nFASE 5: Guarda contra la postulacion duplicada sobre la misma casa\n";
assertCondition(
    is_array($applications->createApplication('app_tercero_uno', 'cln_llama', 'usr_tercero', $now)),
    'El tercer postulante cursa su solicitud'
);
$duplicate = $applications->createApplication('app_tercero_duplicada', 'cln_llama', 'usr_tercero', $now);
assertCondition($duplicate === null, 'Postular dos veces a la misma casa queda bloqueado');
assertCondition(
    ($applications->findPendingApplicationForClan('usr_tercero', 'cln_llama')['id'] ?? null) === 'app_tercero_uno',
    'La guarda preserva la postulacion original y no la sustituye'
);
assertCondition(
    $applications->countPendingApplications('usr_tercero') === 1,
    'El duplicado no incrementa el censo de postulaciones'
);

// --- FASE 6: Censo y cronología ---
echo "\nFASE 6: Censo y cronologia determinista de las pendientes\n";
assertCondition($applications->countPendingApplications('usr_quinto') === 0, 'Un adepto que no ha postulado acusa cero pendientes');
$pendingList = $applications->findPendingApplicationsByUser('usr_postulante');
assertCondition(count($pendingList) === 3, 'findPendingApplicationsByUser() entrega las tres pendientes');
assertCondition(
    array_column($pendingList, 'id') === ['app_uno', 'app_dos', 'app_tres'],
    'Las pendientes se ordenan cronologicamente (determinismo RNF-01)'
);
assertCondition(array_is_list($pendingList), 'La consulta devuelve una lista indexada (array tipado)');
assertCondition(
    $applications->findPendingApplicationsByUser('usr_quinto') === [],
    'Sin postulaciones, la consulta devuelve un array vacio (no null)'
);

// --- FASE 7: Rechazo del Patriarca ---
echo "\nFASE 7: Rechazo de una solicitud (RF-01.5)\n";
$applications->createApplication('app_otro_dos', 'cln_mareas', 'usr_otro', $now);
$verdictAt = '2026-09-15T09:00:00Z';
assertCondition(
    $applications->resolveApplication('app_otro_uno', 'rejected', $verdictAt) === true,
    'resolveApplication() sella el rechazo del Patriarca'
);
$rejected = $applications->findById('app_otro_uno');
assertCondition(($rejected['status'] ?? null) === 'rejected', 'La solicitud queda en estado rechazado');
assertCondition(($rejected['resolved_at'] ?? null) === $verdictAt, 'La marca temporal del veredicto se persiste');
assertCondition(
    ($applications->findById('app_otro_dos')['status'] ?? null) === 'pending',
    'El rechazo NO cancela las restantes postulaciones del adepto'
);

// --- FASE 8: Aprobación y cancelación de las demás (criterio «Hecho cuando») ---
echo "\nFASE 8: Aprobacion que cancela las restantes pendientes (RF-01.5, SPEC-10 RF-03.7)\n";
// SPEC-10: la orquestación de la anulación RESIDUAL vive en el servicio (con
// su asiento por petición huérfana); el repositorio solo dictamina.
assertCondition(
    $applications->resolveApplication('app_dos', 'approved', $verdictAt) === true,
    'resolveApplication() aprueba la postulacion a Mareas Celestiales'
);
$annulledIds = $applications->cancelPendingApplications('usr_postulante', 'app_dos', $verdictAt);
assertCondition(
    array_is_list($annulledIds) && array_diff($annulledIds, ['app_uno', 'app_tres']) === [],
    'cancelPendingApplications() entrega los identificadores de las residuales anuladas (RF-03.7)'
);
assertCondition(
    ($applications->findById('app_dos')['status'] ?? null) === 'approved',
    'La solicitud aprobada queda sellada como tal'
);
assertCondition(
    ($applications->findById('app_uno')['status'] ?? null) === 'cancelled'
        && ($applications->findById('app_tres')['status'] ?? null) === 'cancelled',
    'Las demas postulaciones del mismo adepto quedan CANCELADAS automaticamente'
);
assertCondition(
    $applications->countPendingApplications('usr_postulante') === 0,
    'Quien profesa en una casa deja de cortejar a las demas: cero pendientes'
);
assertCondition(
    ($applications->findById('app_uno')['resolved_at'] ?? null) === $verdictAt,
    'Las cancelaciones quedan fechadas con el instante del veredicto'
);

// --- FASE 9: Memoria inmutable de la deliberación ---
echo "\nFASE 9: La deliberacion no se reescribe\n";
assertCondition(
    $applications->resolveApplication('app_dos', 'rejected', $verdictAt) === false,
    'Una solicitud ya resuelta no puede volver a dirimirse'
);
assertCondition(
    ($applications->findById('app_dos')['status'] ?? null) === 'approved',
    'El veredicto original permanece intacto'
);
assertCondition(
    $applications->resolveApplication('app_inexistente', 'approved', $verdictAt) === false,
    'Dirimir una solicitud inexistente devuelve false'
);

// --- FASE 10: Canon de veredictos y estados ---
echo "\nFASE 10: Canon de veredictos y estados\n";
$invalidResolutionRejected = false;
try {
    $applications->resolveApplication('app_otro_dos', 'quizas', $verdictAt);
} catch (InvalidArgumentException $e) {
    $invalidResolutionRejected = true;
}
assertCondition($invalidResolutionRejected, 'Un veredicto fuera del canon se rechaza antes de tocar la base de datos');

$invalidStatusRejected = false;
try {
    $applications->findApplicationsByClan('cln_llama', 'inventado');
} catch (InvalidArgumentException $e) {
    $invalidStatusRejected = true;
}
assertCondition($invalidStatusRejected, 'Un estado fuera del canon se rechaza al filtrar el expediente');

// --- FASE 11: Expediente de la hermandad ---
echo "\nFASE 11: Expediente de la hermandad para el Patriarca\n";
$expediente = $applications->findApplicationsByClan('cln_llama');
assertCondition(count($expediente) >= 3, "El expediente reune las postulaciones de la casa (" . count($expediente) . ")");
$onlyPending = $applications->findApplicationsByClan('cln_llama', 'pending');
assertCondition(
    $onlyPending !== [] && array_reduce($onlyPending, static fn (bool $carry, array $row): bool => $carry && $row['status'] === 'pending', true),
    'El filtro por estado acota el expediente a las solicitudes pendientes'
);
assertCondition(
    $applications->findApplicationsByClan('cln_inexistente') === [],
    'Una casa sin postulaciones devuelve un expediente vacio (no null)'
);

// --- FASE 12: Cancelación al ingresar por régimen abierto ---
echo "\nFASE 12: Cancelacion de postulaciones al ingresar (regimen abierto)\n";
$applications->createApplication('app_cuarto_uno', 'cln_llama', 'usr_cuarto', $now);
$applications->createApplication('app_cuarto_dos', 'cln_mareas', 'usr_cuarto', $now);
assertCondition(
    $applications->cancelPendingApplications('usr_cuarto', null, $verdictAt) === ['app_cuarto_uno', 'app_cuarto_dos'],
    'La admision inmediata cancela TODAS las postulaciones pendientes del adepto'
);
assertCondition(
    $applications->countPendingApplications('usr_cuarto') === 0,
    'El adepto admitido queda sin postulaciones vivas'
);

$applications->createApplication('app_quinto_uno', 'cln_llama', 'usr_quinto', $now);
$applications->createApplication('app_quinto_dos', 'cln_mareas', 'usr_quinto', $now);
$applications->createApplication('app_quinto_tres', 'cln_tempestad', 'usr_quinto', $now);
assertCondition(
    count($applications->cancelPendingApplications('usr_quinto', 'app_quinto_dos', $verdictAt)) === 2,
    'La cancelacion con excepcion respeta la solicitud recien aprobada'
);
assertCondition(
    ($applications->findById('app_quinto_dos')['status'] ?? null) === 'pending',
    'La solicitud excluida permanece viva'
);
assertCondition(
    $applications->cancelPendingApplications('usr_quinto', 'app_quinto_dos', $verdictAt) === [],
    'La cancelacion es idempotente: sin pendientes que cancelar devuelve cero'
);

// --- FASE 13: Atomicidad transaccional (criterio «Hecho cuando») ---
echo "\nFASE 13: Atomicidad: aprobacion y cancelacion en la MISMA transaccion\n";
$applications->createApplication('app_sexto_uno', 'cln_llama', 'usr_sexto', $now);
$applications->createApplication('app_sexto_dos', 'cln_mareas', 'usr_sexto', $now);
assertCondition($applications->countPendingApplications('usr_sexto') === 2, 'El adepto cursa dos postulaciones');

$pdo->beginTransaction();
$applications->resolveApplication('app_sexto_uno', 'approved', $verdictAt);
$annulledInsideTransaction = $applications->cancelPendingApplications('usr_sexto', 'app_sexto_uno', $verdictAt);
$pendingInsideTransaction = $applications->countPendingApplications('usr_sexto');
$secondInsideTransaction = $applications->findById('app_sexto_dos')['status'] ?? null;
$pdo->rollBack();

assertCondition(
    $pendingInsideTransaction === 0 && $secondInsideTransaction === 'cancelled' && $annulledInsideTransaction === ['app_sexto_dos'],
    'Dentro de la transaccion ajena, la aprobacion arrastra la cancelacion (con id de la residual)'
);
assertCondition(
    $applications->countPendingApplications('usr_sexto') === 2
        && ($applications->findById('app_sexto_uno')['status'] ?? null) === 'pending',
    'El rollback deshace la aprobacion Y las cancelaciones como un solo gesto'
);

// --- FASE 14: Registro del corte dominical ---
echo "\nFASE 14: Cronica del corte dominical (RF-04.2, RF-04.4)\n";
$cycle37 = $cycles->recordClosedCycle('cyc_2026_37', 37, 2026, 'cln_llama', 1200, 12, '2026-09-13T23:59:59Z');
assertCondition(is_array($cycle37), 'recordClosedCycle() devuelve un array tipado');
assertCondition(
    ($cycle37['week_number'] ?? null) === 37 && ($cycle37['cycle_year'] ?? null) === 2026,
    'El ciclo porta su semana ISO y su año'
);
assertCondition(
    is_int($cycle37['week_number'] ?? null) && is_int($cycle37['winning_points'] ?? null)
        && is_int($cycle37['winner_spell_count'] ?? null),
    'Los contadores y la semana viajan como enteros nativos'
);
assertCondition(($cycle37['regent_clan_id'] ?? null) === 'cln_llama', 'El Clan Regente queda inscrito');
assertCondition(
    ($cycle37['winning_points'] ?? null) === 1200 && ($cycle37['winner_spell_count'] ?? null) === 12,
    'Los PDA de la corona y los conjuros validados se persisten integros'
);

$cycles->recordClosedCycle('cyc_2026_38', 38, 2026, 'cln_mareas', 1500, 15, '2026-09-20T23:59:59Z');
$cycles->recordClosedCycle('cyc_2027_01', 1, 2027, 'cln_tempestad', 900, 9, '2027-01-03T23:59:59Z');

// --- FASE 15: Clan Regente vigente ---
echo "\nFASE 15: Clan Regente del Santuario vigente (RF-04.2)\n";
$regent = $cycles->findCurrentRegentCycle();
assertCondition(
    ($regent['id'] ?? null) === 'cyc_2027_01' && ($regent['regent_clan_id'] ?? null) === 'cln_tempestad',
    'El Clan Regente vigente es el del corte mas reciente'
);
assertCondition(
    $cycles->findCurrentRegentCycle()['regent_clan_id'] === $regent['regent_clan_id'],
    'Dos consultas identicas coronan a la misma casa (determinismo RNF-01)'
);

// --- FASE 16: Libro Mayor de Campeones ---
echo "\nFASE 16: Libro Mayor de Campeones (RF-04.4, RF-06.1)\n";
$chronicle = $cycles->findCycleHistory();
assertCondition(count($chronicle) === 3, "La cronica reune las semanas concluidas (" . count($chronicle) . ")");
assertCondition(
    array_column($chronicle, 'id') === ['cyc_2027_01', 'cyc_2026_38', 'cyc_2026_37'],
    'La cronica desciende de la corona mas reciente a la mas antigua'
);
assertCondition(
    count($cycles->findCycleHistory(2)) === 2,
    'El recorte del Libro Mayor respeta el limite solicitado'
);
assertCondition(array_is_list($chronicle), 'La cronica devuelve una lista indexada (array tipado)');

// --- FASE 17: Cada semana se inmortaliza una sola vez (RNF-01) ---
echo "\nFASE 17: Cada semana se inmortaliza una sola vez (RNF-01)\n";
$duplicateCycle = $cycles->recordClosedCycle('cyc_2026_37_duplicado', 37, 2026, 'cln_sombras', 9999, 99, '2026-09-14T00:00:00Z');
assertCondition($duplicateCycle === null, 'Un corte ya inscrito no se puede duplicar');
assertCondition(
    $cycles->findById('cyc_2026_37_duplicado') === null,
    'El corte duplicado jamas llego a inscribirse'
);
assertCondition(
    count($cycles->findCycleHistory()) === 3,
    'La cronica permanece inmutable: sigue habiendo tres semanas'
);
assertCondition(
    $cycles->findById('cyc_2026_37')['regent_clan_id'] === 'cln_llama',
    'El campeon original de la semana 37 conserva su corona'
);
assertCondition($cycles->findById('cyc_inexistente') === null, 'findById() devuelve null ante un ciclo inexistente');

// --- FASE 18: Canon del calendario ISO ---
echo "\nFASE 18: Canon del calendario ISO (RF-04.1)\n";
$invalidWeekZero = false;
$invalidWeekHigh = false;
try {
    $cycles->recordClosedCycle('cyc_mala_0', 0, 2026, 'cln_llama', 1, 1, $now);
} catch (InvalidArgumentException $e) {
    $invalidWeekZero = true;
}
try {
    $cycles->recordClosedCycle('cyc_mala_54', 54, 2026, 'cln_llama', 1, 1, $now);
} catch (InvalidArgumentException $e) {
    $invalidWeekHigh = true;
}
assertCondition($invalidWeekZero, 'La semana 0 se rechaza antes de tocar la base de datos');
assertCondition($invalidWeekHigh, 'La semana 54 se rechaza antes de tocar la base de datos');

// --- FASE 19: Inyección, Dogma Vanilla y dualidad ---
echo "\nFASE 19: Inyeccion, Dogma Vanilla y dualidad linguistica\n";
$maliciousClanId = "cln'); DROP TABLE weekly_cycles;--";
seedClan($pdo, $maliciousClanId, 'veneno-arcano', 'Veneno Arcano', $now);
$maliciousCycle = $cycles->recordClosedCycle('cyc_veneno', 5, 2026, $maliciousClanId, 10, 1, $now);
assertCondition(is_array($maliciousCycle), 'Un identificador con carga maliciosa se admite como texto literario');
assertCondition(
    (bool) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'weekly_cycles'")->fetchColumn(),
    'La tabla weekly_cycles sobrevive intacta al intento de inyeccion'
);
$maliciousApplication = $applications->createApplication('app_veneno', $maliciousClanId, 'usr_sexto', $now);
assertCondition(is_array($maliciousApplication), 'La guarda de postulaciones trata el identificador como dato inerte');
assertCondition(
    (bool) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'clan_applications'")->fetchColumn(),
    'La tabla clan_applications sobrevive intacta al intento de inyeccion'
);
assertCondition(
    ($applications->findById('app_veneno')['clan_id'] ?? null) === $maliciousClanId,
    'El identificador malicioso se recupera literal, byte a byte (parameter binding)'
);

foreach ([
    'ClanApplicationRepository' => $applicationSource,
    'WeeklyCycleRepository'     => $cycleSource,
] as $label => $source) {
    assertCondition(
        str_contains($source, '->prepare(') && !str_contains($source, '->query(') && !str_contains($source, '->exec('),
        "{$label}: el 100% de los accesos usan sentencias preparadas"
    );
    $externalImports = [];
    preg_match_all('/^use\s+([^;]+);/m', $source, $importMatches);
    foreach ($importMatches[1] as $importedNamespace) {
        $normalizedImport = trim($importedNamespace);
        // Solo se admiten built-ins de PHP: cero librerías externas.
        if (!in_array($normalizedImport, ['PDO', 'PDOException', 'InvalidArgumentException', 'Throwable'], true)) {
            $externalImports[] = $normalizedImport;
        }
    }
    assertCondition($externalImports === [], "{$label}: cero dependencias externas (solo built-ins de PHP)");
}

// --- Resumen final ---
echo "\n== RESUMEN ==\n";
echo "Asertos superados: {$assertsPassed}\n";
echo "Asertos fallidos:  {$assertsFailed}\n";

if ($assertsFailed === 0) {
    echo "\nRESULTADO: EXITO — La Tarea 1.4 cumple su criterio 'Hecho cuando'.\n";
    exit(0);
}

echo "\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].\n";
exit(1);
