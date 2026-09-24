<?php

/**
 * test_clan_ethics_validator.php — Verificación de la Tarea 2.3 de TASKS-07.
 *
 * Valida contra el criterio «Hecho cuando» de
 * specs/07-clans-lineages.tasks.md:
 *   «Un Maestro perteneciente al clan del conjuro o que haya salido de él
 *    hace 29 días es rechazado con excepción de conflicto de interés,
 *    mientras que uno retirado hace 31 días es admitido.»
 *
 * Estrategia: se levanta una base SQLite efímera con la secuencia canónica
 * del santuario —schema.sql + seeds.sql—, cuya forma canónica ya incorpora
 * se siembran hermandades y Maestros alternando las partidas mediante los
 * ESCRITORES REALES (ClanMemberRepository::addMember/removeMember) y se
 * consulta el veredicto del validador con el instante INYECTADO, jamás con
 * el reloj del sistema (RNF-01).
 *
 * Fases:
 *   [0] Superficie: el validador, la excepción y su contrato existen.
 *   [1] Regla 1 — el Maestro milita AHORA en el clan del conjuro: veto.
 *   [2] Regla 2 — partida hace 29 días: veto por incompatibilidad histórica.
 *   [3] Criterio — partida hace 31 días: admitido.
 *   [4] Frontera — partida hace exactamente 30 días: aún vetado.
 *   [5] Conjuro de mago ermitaño (`null` / cadena vacía): sin linaje, sin veto.
 *   [6] La ventana se mide contra el INSTANTE INYECTADO, no contra el reloj.
 *   [7] Historial múltiple y linajes ajenos al conjuro: no vetan.
 *   [8] La excepción de conflicto de interés: contrato y motivo solemne.
 *   [9] Determinismo y pureza: veredicto reproducible y sin azar (RNF-01).
 *  [10] Guardas de canon y Dogma Vanilla.
 *  [11] El historial es memoria inmutable: la fila cerrada jamás se borra.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin librerías ni npm.
 *   - Artículo III: el veto protege la deliberación de la sangre.
 *   - Artículo V: identificadores en inglés camelCase, narrativa en castellano.
 *
 * Uso: php scratch/test_clan_ethics_validator.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$filesRequired = [
    'ClanEthicsValidator'                => __DIR__ . '/../src/Services/ClanEthicsValidator.php',
    'ClanConflictOfInterestException'    => __DIR__ . '/../src/Exceptions/ClanConflictOfInterestException.php',
    'ClanMemberRepository'               => __DIR__ . '/../src/Repositories/ClanMemberRepository.php',
];

foreach ($filesRequired as $className => $filePath) {
    if (!is_file($filePath)) {
        fwrite(STDERR, "[FATAL] Falta src/.../{$className}.php — fase roja: aún no existe.\n");
        exit(1);
    }
}

foreach ($filesRequired as $filePath) {
    require_once $filePath;
}

use Grimorio\Exceptions\ClanConflictOfInterestException;
use Grimorio\Repositories\ClanMemberRepository;
use Grimorio\Services\ClanEthicsValidator;

$assertsPassed = 0;
$assertsFailed = 0;

/** Advertencias y avisos de PHP capturados durante la prueba. */
$phpWarnings = [];

/** Convierte cualquier advertencia nativa en materia de aserto (no la silencia). */
set_error_handler(static function (int $severity, string $message): bool {
    global $phpWarnings;
    $phpWarnings[] = $message;
    return true;
});

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

/** Aserta que la invocación alza un veto de conflicto de interés. */
function assertVeto(callable $invocation, string $description): ?ClanConflictOfInterestException
{
    try {
        $invocation();
        assertCondition(false, "{$description}: debía vetarse y no lo hizo");
        return null;
    } catch (ClanConflictOfInterestException $veto) {
        assertCondition(trim($veto->vetoReason()) !== '', "{$description}: vetado con motivo solemne");
        return $veto;
    } catch (Throwable $unexpected) {
        assertCondition(false, "{$description}: alzó " . $unexpected::class . ' en vez del veto');
        return null;
    }
}

// ---------------------------------------------------------------------
// Base de datos efímera: la secuencia canónica completa del santuario.
// ---------------------------------------------------------------------
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON;');
$pdo->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));
$pdo->exec((string) file_get_contents(__DIR__ . '/../database/seeds.sql'));
// El DDL canónico ya incorpora el dominio de SPEC-07: no hay migración que
// aplicar en una base recién construida.

/** El instante canónico de evaluación: se inyecta, nunca se lee del reloj. */
$now = new DateTimeImmutable('2026-09-14T12:00:00Z');

/** Siembra una hermandad para las pruebas. */
function seedClan(PDO $pdo, string $id, string $slug, string $name): void
{
    $pdo->prepare(
        'INSERT INTO clans (id, slug, name, motto, created_at) VALUES (?, ?, ?, ?, ?)'
    )->execute([$id, $slug, $name, 'Lema de prueba', '2026-01-01T00:00:00Z']);
}

/** Siembra un Maestro de la Torre (o el rango que se indique). */
function seedUser(PDO $pdo, string $id, string $alias, string $clanId, string $role = 'master'): void
{
    $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$id, $alias, $alias . '@torre.arc', 'x', $role, $clanId, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z']);
}

$memberRepository = new ClanMemberRepository($pdo);
$validator = new ClanEthicsValidator($memberRepository);

// Dos linajes: el conjuro siempre nace bajo el estandarte primordial.
seedClan($pdo, 'cln_hielo', 'custodios-del-hielo-eterno', 'Custodios del Hielo Eterno');

// --- Maestros y su historial de membresía -----------------------------
// 1. Militante ACTIVO del linaje del conjuro.
seedUser($pdo, 'usr_sangre', 'Maestro de la Sangre', 'cln_primordial');
$memberRepository->addMember('mem_sangre', 'cln_primordial', 'usr_sangre', 'patriarch', '2026-01-02T00:00:00Z');

// 2. Partió hace 29 días (dentro de la ventana) y milita hoy en otro linaje.
seedUser($pdo, 'usr_reciente', 'Maestro Reciente', 'cln_hielo');
$memberRepository->addMember('mem_reciente_1', 'cln_primordial', 'usr_reciente', 'adept', '2026-02-01T00:00:00Z');
$memberRepository->removeMember(
    'usr_reciente',
    'cln_primordial',
    $now->modify('-29 days')->format('Y-m-d\TH:i:s\Z'),
    $now->modify('-15 days')->format('Y-m-d\TH:i:s\Z')
);
$memberRepository->addMember('mem_reciente_2', 'cln_hielo', 'usr_reciente', 'adept', '2026-08-17T00:00:00Z');

// 3. Partió hace 31 días (fuera de la ventana).
seedUser($pdo, 'usr_antiguo', 'Maestro Antiguo', 'cln_hielo');
$memberRepository->addMember('mem_antiguo_1', 'cln_primordial', 'usr_antiguo', 'adept', '2026-01-10T00:00:00Z');
$memberRepository->removeMember(
    'usr_antiguo',
    'cln_primordial',
    $now->modify('-31 days')->format('Y-m-d\TH:i:s\Z'),
    $now->modify('-17 days')->format('Y-m-d\TH:i:s\Z')
);

// 4. Partió hace exactamente 30 días: la frontera del canon.
seedUser($pdo, 'usr_frontera', 'Maestro de la Frontera', 'cln_hielo');
$memberRepository->addMember('mem_frontera', 'cln_primordial', 'usr_frontera', 'adept', '2026-01-15T00:00:00Z');
$memberRepository->removeMember(
    'usr_frontera',
    'cln_primordial',
    $now->modify('-30 days')->format('Y-m-d\TH:i:s\Z'),
    $now->modify('-16 days')->format('Y-m-d\TH:i:s\Z')
);

// 5. Jamás habitó el linaje del conjuro.
seedUser($pdo, 'usr_ajeno', 'Maestro Ajeno', 'cln_hielo');
$memberRepository->addMember('mem_ajeno', 'cln_hielo', 'usr_ajeno', 'patriarch', '2026-03-01T00:00:00Z');

// 6. Historial múltiple: partió del linaje del conjuro hace 45 días.
seedUser($pdo, 'usr_veterano', 'Maestro Veterano', 'cln_hielo');
$memberRepository->addMember('mem_veterano_1', 'cln_hielo', 'usr_veterano', 'adept', '2026-03-01T00:00:00Z');
$memberRepository->removeMember('usr_veterano', 'cln_hielo', $now->modify('-90 days')->format('Y-m-d\TH:i:s\Z'), $now->modify('-76 days')->format('Y-m-d\TH:i:s\Z'));
$memberRepository->addMember('mem_veterano_2', 'cln_primordial', 'usr_veterano', 'adept', '2026-04-01T00:00:00Z');
$memberRepository->removeMember(
    'usr_veterano',
    'cln_primordial',
    $now->modify('-45 days')->format('Y-m-d\TH:i:s\Z'),
    $now->modify('-31 days')->format('Y-m-d\TH:i:s\Z')
);
$memberRepository->addMember('mem_veterano_3', 'cln_hielo', 'usr_veterano', 'adept', '2026-08-02T00:00:00Z');

$spellClan = 'cln_primordial';

echo "═══ FASE 0 · Superficie del validador y de su contrato ═══\n";
$validatorSource = (string) file_get_contents($filesRequired['ClanEthicsValidator']);
assertCondition(str_contains($validatorSource, 'declare(strict_types=1);'), 'El validador declara tipado estricto');
assertCondition((new ReflectionClass(ClanEthicsValidator::class))->isFinal(), 'El validador es final');
assertCondition(ClanEthicsValidator::HISTORICAL_WINDOW_DAYS === 30, 'La ventana de incompatibilidad es de 30 días (RF-01.8)');
assertCondition(
    is_subclass_of(ClanConflictOfInterestException::class, RuntimeException::class),
    'La excepción hereda de RuntimeException, como el resto de src/Exceptions (Art. I)'
);
assertCondition(ClanConflictOfInterestException::HTTP_STATUS === 403, 'El veto se traduce en HTTP 403 Forbidden');
assertCondition(ClanConflictOfInterestException::ERROR_CODE === 'CLAN_CONFLICT_OF_INTEREST', 'El código de error del contrato REST es canónico');

echo "\n═══ FASE 1 · Regla 1: militancia activa en el linaje del conjuro ═══\n";
assertCondition($validator->canMasterEvaluateSpell('usr_sangre', $spellClan, $now) === false, 'El Patriarca del propio linaje queda vetado');
assertCondition($validator->vetoReasonFor('usr_sangre', $spellClan, $now) !== null, 'El veto se manifiesta como motivo solemne');
$vetoReason = (string) $validator->vetoReasonFor('usr_sangre', $spellClan, $now);
assertCondition(str_contains($vetoReason, 'vínculo de sangre'), 'El motivo invoca el vínculo de sangre (Art. IV)');
assertVeto(
    static fn () => $validator->assertMasterCanEvaluateSpell('usr_sangre', $spellClan, $now),
    'assertMasterCanEvaluateSpell() alza el veto del propio linaje'
);
assertCondition($validator->canMasterEvaluateSpell('usr_ajeno', 'cln_hielo', $now) === false, 'Regla 1 también rige en otro linaje');

echo "\n═══ FASE 2 · Regla 2: partida hace 29 días (dentro de la ventana) ═══\n";
assertCondition($validator->canMasterEvaluateSpell('usr_reciente', $spellClan, $now) === false, 'Quien partió hace 29 días queda vetado');
$historicalReason = (string) $validator->vetoReasonFor('usr_reciente', $spellClan, $now);
assertCondition(str_contains($historicalReason, 'treinta días'), 'El motivo invoca la ventana de treinta días');
assertCondition(str_contains($historicalReason, 'Artículo III'), 'El motivo cita el Artículo III de la Constitución');
$historicalVeto = assertVeto(
    static fn () => $validator->assertMasterCanEvaluateSpell('usr_reciente', $spellClan, $now),
    'El criterio «Hecho cuando»: rechazo con excepción de conflicto de interés'
);
assertCondition($historicalVeto !== null && $historicalVeto->masterUserId() === 'usr_reciente', 'La excepción porta la identidad del Maestro');
assertCondition($historicalVeto !== null && $historicalVeto->spellClanId() === $spellClan, 'La excepción porta el linaje del conjuro');
assertCondition($historicalVeto !== null && $historicalVeto->errorCode() === ClanConflictOfInterestException::ERROR_CODE, 'La excepción porta el código canónico del rechazo');

echo "\n═══ FASE 3 · Criterio: partida hace 31 días (fuera de la ventana) ═══\n";
assertCondition($validator->canMasterEvaluateSpell('usr_antiguo', $spellClan, $now) === true, 'Quien partió hace 31 días queda admitido');
assertCondition($validator->vetoReasonFor('usr_antiguo', $spellClan, $now) === null, 'Sin motivo de veto: el juicio está legitimado');
$admittedWithoutThrow = true;
try {
    $validator->assertMasterCanEvaluateSpell('usr_antiguo', $spellClan, $now);
} catch (Throwable) {
    $admittedWithoutThrow = false;
}
assertCondition($admittedWithoutThrow, 'assertMasterCanEvaluateSpell() no alza nada para el Maestro admitido');

echo "\n═══ FASE 4 · Frontera exacta de los treinta días naturales ═══\n";
assertCondition($validator->canMasterEvaluateSpell('usr_frontera', $spellClan, $now) === false, 'Partida hace exactamente 30 días: aún vetado (ventana inclusiva)');
assertCondition(
    $validator->canMasterEvaluateSpell('usr_frontera', $spellClan, $now->modify('+1 day')) === true,
    'Un día después (31 días de partida) queda admitido: la frontera se cruza'
);
assertCondition(
    $validator->canMasterEvaluateSpell('usr_antiguo', $spellClan, $now->modify('-1 day')) === false,
    'Retrocediendo un día (30 días de partida) vuelve a quedar vetado'
);

echo "\n═══ FASE 5 · Conjuro de mago ermitaño: sin linaje, sin conflicto ═══\n";
assertCondition($validator->canMasterEvaluateSpell('usr_sangre', null, $now) === true, 'Sin linaje (`null`), el Maestro más comprometido es apto');
assertCondition($validator->canMasterEvaluateSpell('usr_sangre', '', $now) === true, 'Cadena vacía equivale a conjuro sin estandarte');
assertCondition($validator->canMasterEvaluateSpell('usr_sangre', '   ', $now) === true, 'Espacios sobrantes también equivalen a sin estandarte');

echo "\n═══ FASE 6 · La ventana se mide contra el instante INYECTADO (RNF-01) ═══\n";
assertCondition($validator->canMasterEvaluateSpell('usr_reciente', $spellClan, $now) === false, 'A día 0 del corte, el reciente está vetado');
assertCondition(
    $validator->canMasterEvaluateSpell('usr_reciente', $spellClan, $now->modify('+2 days')) === true,
    'El MISMO Maestro queda admitido dos días después: el cómputo depende del instante inyectado'
);
assertCondition(
    $validator->canMasterEvaluateSpell('usr_reciente', $spellClan, $now->modify('-1 day')) === false,
    'Un día antes seguía vetado: la frontera se mueve con el instante'
);
assertCondition(
    $validator->canMasterEvaluateSpell('usr_antiguo', $spellClan, $now) === true
    && $validator->canMasterEvaluateSpell('usr_antiguo', $spellClan, new DateTimeImmutable('2026-09-14T12:00:00+00:00')) === true,
    'Instantes equivalentes en distinta notación producen idéntico veredicto'
);

echo "\n═══ FASE 7 · Historial múltiple y linajes ajenos al conjuro ═══\n";
assertCondition($validator->canMasterEvaluateSpell('usr_ajeno', $spellClan, $now) === true, 'Un linaje ajeno jamás veta el juicio');
assertCondition($validator->canMasterEvaluateSpell('usr_veterano', $spellClan, $now) === true, 'Historial múltiple: la partida de hace 45 días no veta');
assertCondition($validator->canMasterEvaluateSpell('usr_veterano', 'cln_hielo', $now) === false, 'El mismo Maestro sí está vetado sobre el linaje que habita hoy');
assertCondition($validator->canMasterEvaluateSpell('usr_ajeno', 'cln_inexistente', $now) === true, 'El veto no juzga la existencia del linaje, sino la incompatibilidad');

echo "\n═══ FASE 8 · Contrato de la excepción de conflicto de interés ═══\n";
$veto = new ClanConflictOfInterestException('usr_x', 'cln_y', 'Motivo solemne de prueba.');
assertCondition($veto->getMessage() === 'Motivo solemne de prueba.', 'El mensaje nativo transporta el motivo solemne');
assertCondition($veto->vetoReason() === 'Motivo solemne de prueba.', 'vetoReason() expone el motivo para la Bitácora (RNF-04)');
assertCondition($veto->masterUserId() === 'usr_x' && $veto->spellClanId() === 'cln_y', 'Porta Maestro y linaje del conflicto');
assertCondition($veto instanceof RuntimeException, 'Es capturable como RuntimeException (SPL nativa)');

echo "\n═══ FASE 9 · Determinismo y pureza del veredicto ═══\n";
$twinValidator = new ClanEthicsValidator(new ClanMemberRepository($pdo));
$firstPass = $validator->canMasterEvaluateSpell('usr_reciente', $spellClan, $now);
$secondPass = $twinValidator->canMasterEvaluateSpell('usr_reciente', $spellClan, $now);
assertCondition($firstPass === $secondPass, 'Dos instancias distintas emiten el mismo veredicto');
assertCondition(
    $validator->vetoReasonFor('usr_reciente', $spellClan, $now) === $validator->vetoReasonFor('usr_reciente', $spellClan, $now),
    'El motivo del veto es estable entre invocaciones'
);
assertCondition($validator->canMasterEvaluateSpell('  usr_ajeno  ', $spellClan, $now) === true, 'El identificador tolera espacios sobrantes');
assertCondition(preg_match_all('/new DateTimeImmutable\(\'now\'/', $validatorSource) === 1, 'Una sola lectura del reloj: el resto del cómputo es inyectado (RNF-01)');
assertCondition(preg_match('/\b(mt_rand|random_int|rand|shuffle)\s*\(/', $validatorSource) !== 1, 'Sin azar: el veredicto es ciego');

echo "\n═══ FASE 10 · Guardas de canon y Dogma Vanilla ═══\n";
try {
    $validator->vetoReasonFor('', $spellClan, $now);
    assertCondition(false, 'Identificador vacío de Maestro: debía rechazarse');
} catch (InvalidArgumentException) {
    assertCondition(true, 'Identificador vacío de Maestro: rechazado con leyenda');
} catch (Throwable $unexpected) {
    assertCondition(false, 'Identificador vacío de Maestro: alzó ' . $unexpected::class);
}
assertCondition(!preg_match('/^\s*use\s+Vendor\\\\/m', $validatorSource), 'El validador no importa dependencias de terceros');
assertCondition(!str_contains($validatorSource, 'PDO'), 'El validador no habla SQL directo: delega en el repositorio');
assertCondition(
    preg_match('/^\s*use\s+Grimorio\\\\Repositories\\\\ClanMemberRepository;/m', $validatorSource) === 1,
    'La autoridad es el historial de membresía (clan_members), como prescribe el plan 3.5'
);
assertCondition($phpWarnings === [], 'Ninguna advertencia de PHP emitida' . ($phpWarnings !== [] ? ': ' . implode(' | ', $phpWarnings) : ''));

echo "\n═══ FASE 11 · El historial es memoria inmutable (Art. III) ═══\n";
$past = $memberRepository->findPastMembershipsSince('usr_reciente', $now->modify('-60 days')->format('Y-m-d\TH:i:s\Z'));
assertCondition(count($past) === 1 && $past[0]['clan_id'] === 'cln_primordial', 'La membresía cerrada sobrevive al paso del Maestro a otro linaje');
assertCondition($past[0]['left_at'] !== null && trim((string) $past[0]['left_at']) !== '', 'La fila cerrada conserva su marca de partida');
assertCondition(
    count($memberRepository->findPastMembershipsSince('usr_reciente', $now->modify('-10 days')->format('Y-m-d\TH:i:s\Z'))) === 0,
    'Fuera de la ventana, el historial deja de estar a la vista del veto'
);

echo "\n" . str_repeat('─', 72) . "\n";
echo "Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";

if ($assertsFailed > 0) {
    echo "RESULTADO: DENEGADO — la Tarea 2.3 no cumple aún su criterio 'Hecho cuando'.\n";
    exit(1);
}

echo "RESULTADO: EXITO — La Tarea 2.3 cumple su criterio 'Hecho cuando'.\n";
exit(0);
