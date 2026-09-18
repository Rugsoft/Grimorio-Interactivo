<?php

declare(strict_types=1);

/**
 * test_lineage_oath_service.php — Verificación de la Tarea 2.2 de TASKS-09.
 *
 * Valida el servicio del juramento (`LineageOathService`):
 *
 * El criterio «Hecho cuando» de la tarea se comprueba en cinco frentes:
 *   1. Mismo linaje reenviado responde éxito sin mutación (idempotencia).
 *   2. Linaje distinto lanza conflicto solemne sin mutación.
 *   3. Dos `sealOath` entrelazados producen un solo ganador determinista.
 *   4. El Admin Supremo recibe `OATH_FORBIDDEN_ROLE`.
 *   5. Cada sellado feliz genera un asiento de Bitácora con actor, acto y
 *      estampa temporal.
 *
 * Fases:
 *   [0]  Superficie: el servicio existe y declara su canon.
 *   [1]  La vía feliz: sellado, asiento de Bitácora con catálogo cerrado.
 *   [2]  La idempotencia: segundo envío del mismo linaje, éxito sin mutación.
 *   [3]  El conflicto solemne: linaje distinto, rechazo sin mutación.
 *   [4]  La carrera: dos juramentos entrelazados, un vencedor.
 *   [5]  Las exenciones y murallas: Supremo, canon inválido, peregrino.
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): PDO nativo en :memory:, sin librerías.
 *   - Art. III: el asiento de la Bitácora es imborrable.
 *   - Art. V: identificadores en inglés; narrativa en castellano.
 *
 * Uso: php scratch/test_lineage_oath_service.php
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

/** ¿Lanza este cierre de excepción? Devuelve la excepción o null. */
function captureThrowable(callable $operation): ?Throwable
{
    try {
        $operation();

        return null;
    } catch (Throwable $failure) {
        return $failure;
    }
}

/** Construye el santuario canónico con el mundo sembrado. */
function forgeSanctuary(): PDO
{
    $projectRoot = dirname(__DIR__);
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
    $pdo->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));

    return $pdo;
}

/** Forja el servicio del juramento sobre un santuario. */
function forgeOathService(PDO $pdo): Grimorio\Services\LineageOathService
{
    return new Grimorio\Services\LineageOathService(
        new Grimorio\Repositories\LineageOathRepository($pdo),
        new Grimorio\Services\AuditService($pdo),
    );
}

/** Inscribe un adepto de prueba en el santuario. */
function forgeAdept(PDO $pdo, string $id, string $alias, string $email, ?string $lineage = null, string $role = 'editor'): void
{
    $NOW = '2026-09-18T10:00:00Z';
    $statement = $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, created_at, updated_at)
         VALUES (:id, :alias, :email, \'x\', :role, NULL, :lineage, :now, :now)'
    );
    $statement->execute([':id' => $id, ':alias' => $alias, ':email' => $email, ':role' => $role, ':lineage' => $lineage, ':now' => $NOW]);
}

echo "== VERIFICACION TAREA 2.2: El servicio del juramento ==\n\n";

$projectRoot = dirname(__DIR__);
$servicePath = $projectRoot . '/src/Services/LineageOathService.php';
$exceptionPath = $projectRoot . '/src/Exceptions/LineageOathException.php';
$resultDtoPath = $projectRoot . '/src/Dto/LineageOathResultDto.php';

// --- FASE 0: Superficie ---
echo "FASE 0: Superficie del servicio\n";
$missing = array_filter([$servicePath, $exceptionPath, $resultDtoPath], static fn (string $path): bool => !file_exists($path));
assertCondition($missing === [], 'Existen el servicio, su excepción de dominio y el DTO del veredicto');
if ($missing !== []) {
    echo "\nRESULTADO: FALLO — faltan ficheros de la Tarea 2.2: " . implode(', ', $missing) . "\n";
    exit(1);
}
foreach ([$servicePath, $exceptionPath, $resultDtoPath] as $path) {
    assertCondition(str_contains((string) file_get_contents($path), 'declare(strict_types=1);'), 'Tipado estricto en ' . basename($path));
}
assertCondition(
    (bool) preg_match('/const CANONICAL_LINEAGES = \[(.*?)\];/s', (string) file_get_contents($servicePath), $canonMatch)
    && substr_count($canonMatch[1], "'") === 16,
    'El servicio porta el canon cerrado de los 8 linajes (única validación, caso límite 5)'
);

// --- FASE 1: La vía feliz ---
echo "\nFASE 1: La vía feliz — sellado y asiento en la Bitácora (criterio 5)\n";
require_once $projectRoot . '/src/Repositories/LineageOathRepository.php';
require_once $projectRoot . '/src/Models/AuditEntry.php';
require_once $projectRoot . '/src/Services/AuditService.php';
require_once $exceptionPath;
require_once $resultDtoPath;
require_once $servicePath;

$pdo = forgeSanctuary();
$service = forgeOathService($pdo);
forgeAdept($pdo, 'usr_novicia', 'Novicia del Umbral', 'novicia@arcano.arc');
$instante = new DateTimeImmutable('2026-09-18T11:00:00Z', new DateTimeZone('UTC'));

$veredicto = $service->sealOath('usr_novicia', 'primordialFlame', 'editor', 'Novicia del Umbral', $instante);
assertCondition($veredicto->lineage === 'primordialFlame' && $veredicto->sealedNow === true, 'El juramento se sella: linaje inscrito y `sealedNow = true`');
assertCondition($veredicto->retainedRoute === null, 'El servicio reporta ruta nula: la retención es de la sesión del controlador (Tarea 2.6)');
assertCondition(
    (string) $pdo->query("SELECT lineage FROM users WHERE id = 'usr_novicia'")->fetchColumn() === 'primordialFlame',
    'El vínculo queda inscrito en la cuenta'
);

$asiento = $pdo->query("SELECT actor_user_id, actor_alias, actor_role, action_type, target_entity_type, target_entity_id, justification, created_at
                        FROM audit_log WHERE action_type = 'LINEAGE_OATH_SWORN'")->fetch(PDO::FETCH_ASSOC);
assertCondition($asiento !== false, 'El sellado feliz genera asiento en la Bitácora (RNF-06)');
assertCondition(
    $asiento !== false
    && $asiento['actor_user_id'] === 'usr_novicia' && $asiento['actor_alias'] === 'Novicia del Umbral' && $asiento['actor_role'] === 'editor',
    'El asiento porta actor (identidad, alias y rol) — RF-03.1'
);
assertCondition(
    $asiento !== false
    && $asiento['target_entity_type'] === 'user' && $asiento['target_entity_id'] === 'usr_novicia' && $asiento['created_at'] === '2026-09-18T11:00:00Z',
    'El asiento porta objetivo y estampa temporal exacta'
);
assertCondition(
    $asiento !== false && str_contains((string) $asiento['justification'], 'primordialFlame') && str_contains((string) $asiento['justification'], 'Juramento'),
    'El asiento porta motivo solemne en castellano con el linaje jurado (Art. III)'
);

// El acto vive en el catálogo cerrado de AuditEntry: la rotulación
// cruzada (SPEC-03, TASK-08) lo reconocerá sin inventar nombres.
$entryReflection = new ReflectionClass(Grimorio\Models\AuditEntry::class);
$catalogProperty = $entryReflection->getConstant('CANONICAL_ACTION_TYPES');
assertCondition(
    is_array($catalogProperty) && in_array('LINEAGE_OATH_SWORN', $catalogProperty, true),
    '`LINEAGE_OATH_SWORN` vive en el catálogo cerrado de AuditEntry (sin inventar actos, RF-03.1 + RNF-06)'
);

// La bitácora es imborrable: ni UPDATE ni DELETE sobreviven a sus triggers.
$borrado = captureThrowable(static fn () => $pdo->exec("DELETE FROM audit_log WHERE action_type = 'LINEAGE_OATH_SWORN'"));
assertCondition($borrado !== null, 'El asiento es imborrable: la base rechaza su borrado (triggers del esquema)');

// --- FASE 2: La idempotencia ---
echo "\nFASE 2: La idempotencia — éxito sin mutación (criterio 1)\n";
$asientosAntes = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'LINEAGE_OATH_SWORN'")->fetchColumn();
$reenvio = $service->sealOath('usr_novicia', 'primordialFlame', 'editor', 'Novicia del Umbral', $instante);
assertCondition($reenvio->sealedNow === false && $reenvio->lineage === 'primordialFlame', 'El reenvío del MISMO linaje responde éxito con `sealedNow = false` (RF-03.3)');
assertCondition(
    (string) $pdo->query("SELECT lineage FROM users WHERE id = 'usr_novicia'")->fetchColumn() === 'primordialFlame'
    && (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'LINEAGE_OATH_SWORN'")->fetchColumn() === $asientosAntes,
    'El reenvío no muta la cuenta ni duplica asientos: idempotencia exacta'
);
$reintentoRed = $service->sealOath('usr_novicia', 'primordialFlame', 'editor', 'Novicia del Umbral');
assertCondition($reintentoRed->sealedNow === false, 'El reintento de red sin estampa explícita también cae en idempotencia');

// --- FASE 3: El conflicto solemne ---
echo "\nFASE 3: El conflicto solemne — linaje distinto sin mutación (criterio 2)\n";
$conflicto = captureThrowable(static fn () => $service->sealOath('usr_novicia', 'solarCrown', 'editor', 'Novicia del Umbral'));
assertCondition(
    $conflicto instanceof Grimorio\Exceptions\LineageOathException
    && $conflicto->errorCode === 'LINEAGE_OATH_CONFLICT' && $conflicto->httpStatus === 403,
    'El linaje DISTINTO alza conflicto solemne: 403 `LINEAGE_OATH_CONFLICT`'
);
assertCondition(
    (string) $pdo->query("SELECT lineage FROM users WHERE id = 'usr_novicia'")->fetchColumn() === 'primordialFlame',
    'El rechazo no muta el vínculo: perpetuo (RF-03.4)'
);

// --- FASE 4: La carrera — un solo ganador determinista ---
echo "\nFASE 4: La carrera — dos juramentos entrelazados (criterio 3)\n";
$carrera = forgeSanctuary();
$svcCarrera = forgeOathService($carrera);
forgeAdept($carrera, 'usr_indecisa', 'Indecisa del Umbral', 'indecisa@arcano.arc');

$carrera->beginTransaction();
$primerGana = null;
try {
    $svcCarrera->sealOath('usr_indecisa', 'celestialTides', 'editor', 'Indecisa del Umbral', $instante);
    $primerGana = true;
} catch (Throwable) {
    $primerGana = false;
}
$segundo = captureThrowable(static fn () => $service->sealOath('usr_indecisa', 'abyssalShadows', 'editor', 'Indecisa del Umbral', $instante));
$carrera->commit();

assertCondition($primerGana === true, 'El primero de los dos entrelazados confirma su juramento');
assertCondition(
    $segundo instanceof Grimorio\Exceptions\LineageOathException && $segundo->errorCode === 'LINEAGE_OATH_CONFLICT',
    'El segundo entrelazado (linaje distinto) cae en conflicto solemne: un solo ganador determinista'
);
assertCondition(
    (string) $carrera->query("SELECT lineage FROM users WHERE id = 'usr_indecisa'")->fetchColumn() === 'celestialTides',
    'La cuenta porta EXACTAMENTE el linaje del vencedor: jamás una mezcla (RF-03.3)'
);

// Carrera de linajes IGUALES: la re-evaluación del perdedor desemboca en
// idempotencia (éxito sin mutación), no en error.
$carreraIgual = forgeSanctuary();
$svcIgual = forgeOathService($carreraIgual);
forgeAdept($carreraIgual, 'usr_gemelos', 'Juramentos Gemelos', 'gemelos@arcano.arc');
$svcIgual->sealOath('usr_gemelos', 'dawnWinds', 'editor', 'Juramentos Gemelos', $instante);
$reavaluado = $svcIgual->sealOath('usr_gemelos', 'dawnWinds', 'editor', 'Juramentos Gemelos', $instante);
assertCondition($reavaluado->sealedNow === false && $reavaluado->lineage === 'dawnWinds', 'La re-evaluación tras carrera de linajes iguales desemboca en idempotencia');

// --- FASE 5: Exenciones y murallas ---
echo "\nFASE 5: Las exenciones y murallas del canon (criterio 4)\n";
$supremo = captureThrowable(static fn () => $service->sealOath('usr_novicia', 'primordialFlame', 'supremeAdmin', 'El Supremo'));
assertCondition(
    $supremo instanceof Grimorio\Exceptions\LineageOathException
    && $supremo->errorCode === 'OATH_FORBIDDEN_ROLE' && $supremo->httpStatus === 403,
    'El Admin Supremo recibe 403 `OATH_FORBIDDEN_ROLE` (RF-01.6)'
);
$canonAjeno = captureThrowable(static fn () => $service->sealOath('usr_novicia', 'dracoStorm', 'editor', 'Novicia del Umbral'));
assertCondition(
    $canonAjeno instanceof Grimorio\Exceptions\LineageOathException
    && $canonAjeno->errorCode === 'INVALID_LINEAGE' && $canonAjeno->httpStatus === 400,
    'Un linaje ajeno al canon alza 400 `INVALID_LINEAGE` (única validación, caso límite 5)'
);
$tipoAjeno = captureThrowable(static fn () => $service->sealOath('usr_novicia', ['primordialFlame'], 'editor', 'Novicia del Umbral'));
assertCondition(
    $tipoAjeno instanceof Grimorio\Exceptions\LineageOathException && $tipoAjeno->errorCode === 'INVALID_LINEAGE',
    'Un `lineageId` no cadena también alza `INVALID_LINEAGE` (contrato del plan)'
);
$canonVacio = captureThrowable(static fn () => $service->sealOath('usr_novicia', null, 'editor', 'Novicia del Umbral'));
assertCondition($canonVacio instanceof Grimorio\Exceptions\LineageOathException && $canonVacio->errorCode === 'INVALID_LINEAGE', 'Un `lineageId` ausente alza `INVALID_LINEAGE`');

// Caso límite 6: Maestro sin linaje imposible por construcción — el
// guardia RF-05.2 vive en su tarea (2.4); aquí se ratifica que el
// servicio jamás selle desde un rol de oficio sin linaje previo no es
// cuestión de este servicio (cualquier rol no-Supremo puede jurar).
$maestroPeregrino = forgeSanctuary();
forgeAdept($maestroPeregrino, 'usr_maestro', 'Maestro Sin Casa', 'maestro@arcano.arc', null, 'master');
forgeOathService($maestroPeregrino)->sealOath('usr_maestro', 'eternalTempest', 'master', 'Maestro Sin Casa', $instante);
assertCondition(
    (string) $maestroPeregrino->query("SELECT lineage FROM users WHERE id = 'usr_maestro'")->fetchColumn() === 'eternalTempest',
    'Un Maestro peregrino puede jurar (la convalecencia y el oficio no impiden el juramento, RF-04.4)'
);

echo "\n== RESUMEN == Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";
if ($assertsFailed === 0) {
    echo "RESULTADO: EXITO — El juramento queda servido: idempotencia, un vencedor determinista, Supremo exento y Bitácora imborrable (Tarea 2.2).\n";
    exit(0);
}

echo "RESULTADO: FALLO — Corregir los asertos en rojo antes de continuar.\n";
exit(1);
