<?php

declare(strict_types=1);

/**
 * test_clan_verdict_acknowledge.php — Verificación de la Tarea 2.5 de TASKS-10.
 *
 * Valida `acknowledgeVerdict()` (veredicto contemplado, SPEC-10) contra el
 * «Hecho cuando» de la tarea:
 *
 *   1. El primer acknowledge fija `verdict_seen_at` con la estampa exacta.
 *   2. El segundo responde ÉXITO sin cambiar la columna (idempotencia).
 *   3. Solo peticiones TERMINALES propias lo admiten: pendiente → 409,
 *      ajena/inexistente → 404.
 *   4. `countUnreadVerdicts()` se apaga al contemplar (RF-01.1) y las
 *      pendientes jamás suman al rótulo.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, base en memoria.
 *   - Artículo V (Dualidad): identificadores en inglés; narrativa en noble
 *     castellano.
 *
 * Uso: php scratch/test_clan_verdict_acknowledge.php
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

echo "== VERIFICACION TAREA 2.5: Veredicto contemplado (acknowledgeVerdict) ==\n\n";

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

$fundadora = oathUser($pdo, 'usr_f', 'FundadoraVisto', 'editor');
$casa = $service->foundClan(
    $fundadora, 'Casa del Contemplado', 'Leyóse el veredicto', 'rune_visto', 'primordialFlame', 'byApplication', $now
);

$postulante = oathUser($pdo, 'usr_post', 'PostulanteLector', 'editor');
$remision = $service->applyToClan($postulante, $casa->id, $now, 'Pido entrar a servir bajo este estandarte con vocación.');
$applicationId = (string) $remision->application?->id;
assertCondition($applicationId !== '', 'La petición remite con identificador propio');

// --- FASE 1: Pendiente no admite contemplado ---
echo "\nFASE 1: Nada hay que leer en una espera (RF-03.4)\n";
$e = captureException(static fn () => $service->acknowledgeVerdict($postulante, $applicationId, $now));
assertCondition(
    $e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::APPLICATION_ALREADY_PENDING,
    'Acknowledge sobre petición `pending`: APPLICATION_ALREADY_PENDING (409)'
);

// El Patriarca dictamina: la petición deviene terminal y legible.
$dictamen = $service->resolveApplication($fundadora, $casa->id, $applicationId, 'approve', $now);
assertCondition($dictamen->isAdmitted(), 'El dictamen aprueba: la petición deviene terminal');

// --- FASE 2: El primer contemplado fija la estampa ---
echo "\nFASE 2: El primer acknowledge fija el instante («Hecho cuando», mitad 1)\n";
$instanteContemplado = new DateTimeImmutable('2026-09-20T13:30:00Z');
$ack = $service->acknowledgeVerdict($postulante, $applicationId, $instanteContemplado);
assertCondition($ack->verdictSeenAt === '2026-09-20T13:30:00Z', 'El primer acknowledge fija verdict_seen_at con la estampa exacta');

$fila = $applicationRepository->findById($applicationId);
assertCondition($fila !== null && $fila['verdict_seen_at'] === '2026-09-20T13:30:00Z', 'La persistencia confirma la estampa grabada');

// --- FASE 3: El reenvío no muta («Hecho cuando», mitad 2) ---
echo "\nFASE 3: Idempotencia del contemplado (Endpoint 4)\n";
$reenvio = $service->acknowledgeVerdict($postulante, $applicationId, new DateTimeImmutable('2026-09-20T14:45:00Z'));
assertCondition($reenvio->verdictSeenAt === '2026-09-20T13:30:00Z', 'El reenvío responde éxito SIN cambiar la columna (la primera estampa prevalece)');

$filaTrasReenvio = $applicationRepository->findById($applicationId);
assertCondition($filaTrasReenvio !== null && $filaTrasReenvio['verdict_seen_at'] === '2026-09-20T13:30:00Z', 'La persistencia confirma: la columna no mutó tras el reenvío');

// --- FASE 4: Guardias de propiedad y existencia ---
echo "\nFASE 4: Petición ajena o inexistente → 404 (Endpoint 4)\n";
$invasor = oathUser($pdo, 'usr_invasor', 'OjosAjenos', 'editor');
$e = captureException(static fn () => $service->acknowledgeVerdict($invasor, $applicationId, $now));
assertCondition(
    $e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::APPLICATION_NOT_FOUND,
    'Contemplar la petición ajena: APPLICATION_NOT_FOUND (404)'
);
$e = captureException(static fn () => $service->acknowledgeVerdict($postulante, 'app_inexistente', $now));
assertCondition(
    $e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::APPLICATION_NOT_FOUND,
    'Contemplar una petición inexistente: APPLICATION_NOT_FOUND (404)'
);

// --- FASE 5: El rótulo se apaga al leer (RF-01.1) ---
echo "\nFASE 5: El rótulo «Tienes dictámenes a la espera» se apaga al contemplar\n";
// Segunda casa con un postulante NUEVO (el primero ya milita en Casa del
// Contemplado): su petición será RECHAZADA y quedará sin leer.
$otraFundadora = oathUser($pdo, 'usr_f2', 'FundadoraRechaza', 'editor');
$otraCasa = $service->foundClan(
    $otraFundadora, 'Casa del Rechazo Leído', 'Rechácese con nobleza', 'rune_rech', 'primordialFlame', 'byApplication', $now
);
$rechazado = oathUser($pdo, 'usr_rech', 'PostulanteRechazado', 'editor');
$remision2 = $service->applyToClan($rechazado, $otraCasa->id, $now, 'Postulación que será rechazada y dejará veredicto sin leer.');
$service->resolveApplication($otraFundadora, $otraCasa->id, (string) $remision2->application?->id, 'reject', $now, 'La casa guarda plenitud de plumas: vuelve a otra luna con honor.');

assertCondition($applicationRepository->countUnreadVerdicts('usr_rech') === 1, 'El rechazo sin contemplar enciende el rótulo (countUnreadVerdicts = 1)');

$service->acknowledgeVerdict($rechazado, (string) $remision2->application?->id, new DateTimeImmutable('2026-09-20T15:00:00Z'));
assertCondition(
    $applicationRepository->countUnreadVerdicts('usr_rech') === 0,
    'Al contemplar el rechazo, el rótulo se apaga (countUnreadVerdicts = 0)'
);

// Las pendientes de un tercer postulante jamás suman al rótulo.
$tercero = oathUser($pdo, 'usr_ter', 'PostulantePaciente', 'editor');
$service->applyToClan($tercero, $casa->id, $now, 'Petición pendiente que no debe encender rótulo alguno.');
assertCondition(
    $applicationRepository->countUnreadVerdicts('usr_ter') === 0,
    'Una petición `pending` jamás enciende el rótulo (nada hay que leer en una espera)'
);

// La petición retirada (terminal `cancelled`) sí es contemplable.
$cuarto = oathUser($pdo, 'usr_cuarto', 'PostulanteRetirado', 'editor');
$remision4 = $service->applyToClan($cuarto, $casa->id, $now, 'Petición que será retirada antes del dictamen.');
$service->withdrawApplication($cuarto, $casa->id, (string) $remision4->application?->id, $now);
$ack4 = $service->acknowledgeVerdict($cuarto, (string) $remision4->application?->id, new DateTimeImmutable('2026-09-20T16:00:00Z'));
assertCondition($ack4->verdictSeenAt === '2026-09-20T16:00:00Z', 'La retirada (terminal `cancelled`) también admite su contemplado');

// --- FASE 6: El DTO porta el contrato sin romper las claves históricas ---
echo "\nFASE 6: Contrato REST del DTO (verdictSeenAt nuevo, claves históricas intactas)\n";
$serializado = json_decode(json_encode($ack), true);
assertCondition(
    ($serializado['verdictSeenAt'] ?? null) === '2026-09-20T13:30:00Z',
    'jsonSerialize expone `verdictSeenAt` con la estampa contemplada'
);
assertCondition(
    array_keys($serializado) === ['id', 'clanId', 'clanName', 'userId', 'userAlias', 'status', 'createdAt', 'resolvedAt', 'verdictSeenAt', 'isPending'],
    'Las claves históricas del contrato permanecen, en orden, junto a la nueva'
);

// El acto vive en el catálogo cerrado de AuditEntry no es necesario aquí: el
// contemplado NO inscribe asiento (gesto de lectura, no de gobierno).

// --- Veredicto ---
echo "\n=============================\n";
echo "Asertos: {$assertsPassed} PASA / {$assertsFailed} FALLOS\n";
if ($assertsFailed > 0) {
    echo "El veredicto contemplado queda ROJO: no cumple aún su contrato.\n";
    exit(1);
}
echo "El contemplado fija su estampa una sola vez, es idempotente y apaga el rótulo.\n";
exit(0);
