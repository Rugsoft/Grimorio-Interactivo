<?php

declare(strict_types=1);

/**
 * test_clan_verdict_motive.php — Verificación de la Tarea 2.6 de TASKS-10.
 *
 * Valida la enmienda del dictamen `resolveApplication()` (SPEC-10) contra el
 * «Hecho cuando» de la tarea:
 *
 *   1. Rechazar sin `motive` (o con texto fuera del molde 20–500) responde 400
 *      `INVALID_VERDICT_MOTIVE`.
 *   2. Con `motive` canónico, el dictamen inscribe el asiento
 *      `CLAN_APPLICATION_VERDICT` con identidad del Patriarca, estampa y
 *      motivo (Artículo III.3) — LADO DELIBERANTE (RF-04.4).
 *   3. La aprobación NO exige motivo, pero también inscribe su asiento y deja
 *      un asiento `CLAN_APPLICATION_RESIDUALS_ANNULLED` por cada residual
 *      anulada (RF-03.7).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, base en memoria.
 *   - Artículo V (Dualidad): identificadores en inglés; narrativa en noble
 *     castellano.
 *
 * Uso: php scratch/test_clan_verdict_motive.php
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
        echo "  [ROJA] {$description}\n";
    }
}

/** ¿Lanza este cierre de excepción? Devuelve la excepción o null. */
function captureException(callable $operation): ?Throwable
{
    try {
        $operation();

        return null;
    } catch (Throwable $failure) {
        return $failure;
    }
}

/** Consagra un mago en el plano y en la base, con linaje jurado. */
function oathUser(PDO $pdo, string $userId, string $alias, string $role = 'editor', string $lineage = 'primordialFlame'): Grimorio\Models\User
{
    $now = '2026-01-01T00:00:00Z';
    $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, lineage, clan_id, created_at, updated_at)
         VALUES (:id, :alias, :email, :passwordHash, :role, :lineage, NULL, :now, :now)'
    )->execute([
        ':id'           => $userId,
        ':alias'        => $alias,
        ':email'        => $userId . '@arcano.arc',
        ':passwordHash' => str_repeat('x', 60),
        ':role'         => $role,
        ':lineage'      => $role === 'reader' ? null : $lineage,
        ':now'          => $now,
    ]);

    return new Grimorio\Models\User(
        id: $userId,
        alias: $alias,
        email: $userId . '@arcano.arc',
        role: $role,
        clanId: null,
        passwordHash: str_repeat('x', 60),
        lineage: $role === 'reader' ? null : $lineage,
        createdAt: $now,
        updatedAt: $now,
    );
}

/** ¿Cuántos asientos de un acto hay en la Bitácora para una casa? */
function auditCount(PDO $pdo, string $actionType, string $clanId): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM audit_log WHERE action_type = :actionType AND target_entity_id = :clanId'
    );
    $statement->execute([':actionType' => $actionType, ':clanId' => $clanId]);

    return (int) $statement->fetchColumn();
}

echo "== VERIFICACION TAREA 2.6: Enmienda del dictamen (resolveApplication) ==\n\n";

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/src/Models/User.php';
require_once $projectRoot . '/src/Exceptions/ClanGovernanceException.php';
require_once $projectRoot . '/src/Models/AuditEntry.php';
require_once $projectRoot . '/src/Dto/LineageDto.php';
require_once $projectRoot . '/src/Dto/ClanDto.php';
require_once $projectRoot . '/src/Dto/ClanMemberDto.php';
require_once $projectRoot . '/src/Dto/ClanApplicationDto.php';
require_once $projectRoot . '/src/Repositories/ClanRepository.php';
require_once $projectRoot . '/src/Repositories/ClanMemberRepository.php';
require_once $projectRoot . '/src/Repositories/ClanApplicationRepository.php';
require_once $projectRoot . '/src/Services/AuditRecorderInterface.php';
require_once $projectRoot . '/src/Services/AuditService.php';
require_once $projectRoot . '/src/Services/LineageSynergyService.php';
require_once $projectRoot . '/src/Services/ClanAdmissionResult.php';
require_once $projectRoot . '/src/Services/ClanCatalogPage.php';
require_once $projectRoot . '/src/Services/ClanService.php';

use Grimorio\Exceptions\ClanGovernanceException;
use Grimorio\Models\AuditEntry;
use Grimorio\Models\User;
use Grimorio\Repositories\ClanApplicationRepository;
use Grimorio\Services\ClanService;

// --- FASE 0: Reino de prueba ---
echo "FASE 0: Reino de prueba\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

$now = new DateTimeImmutable('2026-09-20T12:00:00Z');
$service = new ClanService($pdo, new Grimorio\Services\AuditService($pdo));
$applicationRepository = new ClanApplicationRepository($pdo);

$patriarca = oathUser($pdo, 'usr_pat', 'PatriarcaDeliberante', 'editor');
$casa = $service->foundClan(
    $patriarca, 'Casa del Dictamen con Motivo', 'Ningún veredicto sin su porqué', 'rune_motivo', 'primordialFlame', 'byApplication', $now
);

// --- FASE 1: El rechazo sin motivo se veda (400) ---
echo "\nFASE 1: «Hecho cuando» mitad 1 — rechazar sin `motive` responde 400\n";
$postulante = oathUser($pdo, 'usr_post', 'PostulanteMote', 'editor');
$remision = $service->applyToClan($postulante, $casa->id, $now, 'Pido entrar a servir bajo este estandarte con vocación.');
$applicationId = (string) $remision->application?->id;

$e = captureException(static fn () => $service->resolveApplication($patriarca, $casa->id, $applicationId, 'reject', $now));
assertCondition(
    $e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::INVALID_VERDICT_MOTIVE,
    'Rechazar sin `motive`: 400 INVALID_VERDICT_MOTIVE'
);
assertCondition($e !== null && $e->httpStatus === 400, 'El veredicto roto responde 400');

// Bordes del molde: 19 (corto) y 501 (largo) también se veden.
$e = captureException(static fn () => $service->resolveApplication($patriarca, $casa->id, $applicationId, 'reject', $now, str_repeat('a', 19)));
assertCondition(
    $e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::INVALID_VERDICT_MOTIVE,
    'Rechazar con 19 caracteres: 400 (borde inferior del molde)'
);
$e = captureException(static fn () => $service->resolveApplication($patriarca, $casa->id, $applicationId, 'reject', $now, str_repeat('b', 501)));
assertCondition(
    $e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::INVALID_VERDICT_MOTIVE,
    'Rechazar con 501 caracteres: 400 (borde superior del molde)'
);

// Ningún dictamen se consumió: la petición sigue pendiente.
$fila = $applicationRepository->findById($applicationId);
assertCondition($fila !== null && $fila['status'] === 'pending', 'El molde roto no consume el dictamen: la petición sigue `pending`');

// --- FASE 2: El rechazo con motivo inscribe su asiento ---
echo "\nFASE 2: «Hecho cuando» mitad 2 — el asiento CLAN_APPLICATION_VERDICT con su motivo\n";
$motive = 'La casa guarda plenitud de plumas: vuelve a otra luna con honor.';
$instanteDictamen = new DateTimeImmutable('2026-09-20T13:00:00Z');
$veredicto = $service->resolveApplication($patriarca, $casa->id, $applicationId, 'reject', $instanteDictamen, $motive);
assertCondition($veredicto->isRejected ?? false || ($veredicto->application?->status === 'rejected'), 'El rechazo con motivo canónico prospera (petición `rejected`)');

$asiento = $pdo->query(
    "SELECT actor_user_id, actor_alias, actor_role, action_type, target_entity_type, target_entity_id, justification, created_at
       FROM audit_log WHERE action_type = 'CLAN_APPLICATION_VERDICT' AND target_entity_id = '{$casa->id}'"
)->fetch(PDO::FETCH_ASSOC);
assertCondition($asiento !== false, 'El dictamen inscribe su asiento CLAN_APPLICATION_VERDICT en la Bitácora');
assertCondition(
    $asiento !== false
    && $asiento['actor_user_id'] === 'usr_pat' && $asiento['actor_role'] === 'editor',
    'El asiento lo inscribe el LADO DELIBERANTE (identidad del Patriarca) — RF-04.4'
);
assertCondition(
    $asiento !== false && str_contains((string) $asiento['justification'], $motive),
    'La justificación porta el motivo íntegro (Artículo III.3, hallazgo 23)'
);
assertCondition(
    $asiento !== false && (string) $asiento['created_at'] === '2026-09-20T13:00:00Z',
    'El asiento porta la estampa temporal del dictamen'
);

// El acto vive en el catálogo cerrado de AuditEntry.
$entryReflection = new ReflectionClass(AuditEntry::class);
$catalogProperty = $entryReflection->getConstant('CANONICAL_ACTION_TYPES');
assertCondition(
    is_array($catalogProperty) && in_array('CLAN_APPLICATION_VERDICT', $catalogProperty, true),
    '`CLAN_APPLICATION_VERDICT` vive en el catálogo cerrado de AuditEntry'
);

// --- FASE 3: La aprobación no exige motivo y anula residuales con asiento ---
echo "\nFASE 3: La aprobación no exige motivo (el ingreso ES su motivo) y anula residuales\n";
$otroPostulante = oathUser($pdo, 'usr_post2', 'PostulanteAprobado', 'editor');
// Dos peticiones: la que será aprobada y una residual en otra casa.
$remisionAprobable = $service->applyToClan($otroPostulante, $casa->id, $now, 'Pido entrar con devoción y estudio sereno.');
$otraFundadora = oathUser($pdo, 'usr_f3', 'FundadoraResidual', 'editor');
$otraCasa = $service->foundClan(
    $otraFundadora, 'Casa de la Residual', 'Otra puerta cortejada', 'rune_res', 'primordialFlame', 'byApplication', $now
);
$remisionResidual = $service->applyToClan($otroPostulante, $otraCasa->id, $now, 'Petición que quedará huérfana al aprobarse la otra.');

$asientosVeredictoAntes = auditCount($pdo, 'CLAN_APPLICATION_VERDICT', $casa->id);
$aprobacion = $service->resolveApplication($patriarca, $casa->id, (string) $remisionAprobable->application?->id, 'approve', $now);
assertCondition($aprobacion->isAdmitted(), 'La aprobación SIN `motive` prospera (el ingreso ES su motivo)');
assertCondition(
    auditCount($pdo, 'CLAN_APPLICATION_VERDICT', $casa->id) === $asientosVeredictoAntes + 1,
    'La aprobación también inscribe su asiento CLAN_APPLICATION_VERDICT (lado deliberante)'
);

$filaResidual = $applicationRepository->findById((string) $remisionResidual->application?->id);
assertCondition($filaResidual !== null && $filaResidual['status'] === 'cancelled', 'La petición residual queda anulada de oficio (RF-03.7)');

$asientosResiduales = auditCount($pdo, 'CLAN_APPLICATION_RESIDUALS_ANNULLED', $casa->id);
assertCondition($asientosResiduales === 1, 'Un asiento CLAN_APPLICATION_RESIDUALS_ANNULLED por la residual anulada, inscrito desde la casa aprobadora');

// --- FASE 4: Regresión del canon ---
echo "\nFASE 4: Regresión — decisión ajena al canon y petición ya resuelta\n";
$e = captureException(static fn () => $service->resolveApplication($patriarca, $casa->id, $applicationId, 'dudar', $now));
assertCondition(
    $e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::INVALID_DECISION,
    'La decisión «dudar» sigue vedada (INVALID_DECISION, 400)'
);
$e = captureException(static fn () => $service->resolveApplication($patriarca, $casa->id, $applicationId, 'reject', $now, $motive));
assertCondition(
    $e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::APPLICATION_ALREADY_RESOLVED,
    'Dictaminar dos veces sobre la misma petición: APPLICATION_ALREADY_RESOLVED (409)'
);

// --- Veredicto ---
echo "\n=============================\n";
echo "Asertos: {$assertsPassed} PASA / {$assertsFailed} FALLOS\n";
if ($assertsFailed > 0) {
    echo "La enmienda del dictamen queda ROJA: no cumple aún su contrato.\n";
    exit(1);
}
echo "El rechazo exige su motivo, el asiento lo inscribe el lado deliberante y la aprobación absuelve huérfanas.\n";
exit(0);
