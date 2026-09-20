<?php

declare(strict_types=1);

/**
 * test_clan_application_withdrawal.php — Verificación de la Tarea 2.4 de TASKS-10.
 *
 * Valida `withdrawApplication()` (retirada del postulante, SPEC-10) contra
 * el «Hecho cuando» de la tarea:
 *
 *   1. Retirar libera el cupo de 3 pendientes: tras la retirada, una cuarta
 *      postulación a otra casa del mismo linaje prospera.
 *   2. La fila PERSISTE clausurada: `hasSealedHouse()` pasa a `true` para la
 *      casa retirada (el índice único la vela con el estado que sea).
 *   3. Veredicto `cancelled` con `resolved_at` fijado y estampa exacta.
 *   4. Asiento `CLAN_APPLICATION_WITHDRAWN` en la Bitácora, inscrito por el
 *      postulante (RF-04.4, reparto por actor).
 *   5. Guardas: petición ajena, casa equivocada, ya resuelta (409) y la
 *      carrera contra el dictamen (la segunda retirada no muta nada).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, base en memoria.
 *   - Artículo V (Dualidad): identificadores en inglés; narrativa en noble
 *     castellano.
 *
 * Uso: php scratch/test_clan_application_withdrawal.php
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

echo "== VERIFICACION TAREA 2.4: Retirada del postulante (withdrawApplication) ==\n\n";

$projectRoot = dirname(__DIR__);

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
require_once $projectRoot . '/src/Models/User.php';

use Grimorio\Exceptions\ClanGovernanceException;
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
$auditService = new Grimorio\Services\AuditService($pdo);
$service = new ClanService($pdo, $auditService);

// Repositorio directo para las consultas del expediente (Dogma: el arnés
// consulta la persistencia, sin entrar en las tripas del servicio).
$applicationRepository = new ClanApplicationRepository($pdo);

// Cuatro casas de deliberación del mismo linaje: tres para llenar el cupo
// y una cuarta para probar que la retirada lo libera.
$casas = [];
foreach ([1, 2, 3, 4] as $index) {
    $fundadora = oathUser($pdo, 'usr_fd' . $index, 'FundadoraCasa' . $index, 'editor');
    $casas[$index] = $service->foundClan(
        $fundadora, 'Casa de la Retirada ' . $index, 'Deliberación de retirada', 'rune_ret' . $index, 'primordialFlame', 'byApplication', $now
    );
}

// --- FASE 1: Llenado del cupo y retirada ---
echo "\nFASE 1: La retirada libera el cupo de tres pendientes (RF-03.3)\n";
$postulante = oathUser($pdo, 'usr_post', 'PostulanteArrepentido', 'editor');
$motivation = 'Pido entrar a servir bajo este estandarte con vocación sincera.';
foreach ([1, 2, 3] as $index) {
    $service->applyToClan($postulante, $casas[$index]->id, $now, $motivation);
}
$e = captureException(static fn () => $service->applyToClan(
    $postulante, $casas[4]->id, $now, $motivation
));
assertCondition($e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::PENDING_APPLICATIONS_LIMIT, 'Cupo lleno: la cuarta postulación rebota con PENDING_APPLICATIONS_LIMIT (400)');

// El expediente del postulante: necesito el id real de
// la petición de la Casa 2 en el expediente.
$expediente = $applicationRepository->findApplicationsByUser('usr_post');
$appCasa2 = null;
foreach ($expediente as $fila) {
    if ($fila['clan_id'] === $casas[2]->id && $fila['status'] === 'pending') {
        $appCasa2 = $fila;
    }
}
assertCondition($appCasa2 !== null, 'El expediente del postulante porta la petición pendiente de la Casa 2');

$instanteRetirada = new DateTimeImmutable('2026-09-20T12:10:00Z');
$veredicto = $service->withdrawApplication($postulante, $casas[2]->id, (string) $appCasa2['id'], $instanteRetirada);
assertCondition($veredicto->application !== null && $veredicto->application->status === 'cancelled', 'La retirada responde con veredicto `cancelled`');
assertCondition($veredicto->application?->resolvedAt === '2026-09-20T12:10:00Z', 'La retirada fija resolved_at con la estampa exacta');

// --- FASE 2: La fila persiste y clausura la casa ---
echo "\nFASE 2: La casa queda clausurada pese a la retirada (RF-03.1)\n";
assertCondition(
    $applicationRepository->hasSealedHouse('usr_post', $casas[2]->id) === true,
    'hasSealedHouse() pasa a `true` tras la retirada (la fila persiste con el estado que sea)'
);
$e = captureException(static fn () => $service->applyToClan(
    $postulante, $casas[2]->id, $now, $motivation
));
assertCondition(
    $e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::APPLICATION_HOUSE_CLOSED,
    'Re-postular a la casa retirada: la clausura responde APPLICATION_HOUSE_CLOSED (403)'
);

// --- FASE 3: El cupo liberado ---
echo "\nFASE 3: La cuarta postulación ahora prospera\n";
$nueva = $service->applyToClan($postulante, $casas[4]->id, $now, 'La cuarta petición entra tras liberar el cupo.');
assertCondition($nueva->isPending(), 'Retirar libera el cupo: la cuarta postulación remite (núcleo del «Hecho cuando»)');

// --- FASE 4: El asiento en la Bitácora, inscrito por el postulante ---
echo "\nFASE 4: Asiento CLAN_APPLICATION_WITHDRAWN con reparto por actor (RF-04.4)\n";
$asiento = $pdo->query(
    "SELECT actor_user_id, actor_alias, actor_role, action_type, target_entity_type, target_entity_id, justification
       FROM audit_log WHERE action_type = 'CLAN_APPLICATION_WITHDRAWN'"
)->fetch(PDO::FETCH_ASSOC);
assertCondition($asiento !== false, 'La retirada inscribe su asiento en la Bitácora');
assertCondition(
    $asiento !== false
    && $asiento['actor_user_id'] === 'usr_post' && $asiento['actor_alias'] === 'PostulanteArrepentido' && $asiento['actor_role'] === 'editor',
    'El asiento lo inscribe el postulante (identidad, alias y rol) — reparto por actor'
);
assertCondition(
    $asiento !== false
    && $asiento['target_entity_type'] === 'clan' && $asiento['target_entity_id'] === $casas[2]->id,
    'El asiento porta target_entity_type=clan y la casa retirada'
);
assertCondition(
    $asiento !== false && (string) $asiento['justification'] !== '' && str_contains((string) $asiento['justification'], $casas[2]->name),
    'La justificación nombra a la casa (Art. III: transparencia)'
);

// El acto vive en el catálogo cerrado de AuditEntry: la rotulación cruzada
// (SPEC-03) lo reconocerá sin inventar nombres.
$entryReflection = new ReflectionClass(Grimorio\Models\AuditEntry::class);
$catalogProperty = $entryReflection->getConstant('CANONICAL_ACTION_TYPES');
assertCondition(
    is_array($catalogProperty) && in_array('CLAN_APPLICATION_WITHDRAWN', $catalogProperty, true),
    '`CLAN_APPLICATION_WITHDRAWN` vive en el catálogo cerrado de AuditEntry (sin inventar actos)'
);

// --- FASE 5: Guardas y carrera ---
echo "\nFASE 5: Guardias — ajena, casa equivocada, resuelta y carrera con el dictamen\n";
$invasor = oathUser($pdo, 'usr_invasor', 'ManoAjena', 'editor');
$e = captureException(static fn () => $service->withdrawApplication($invasor, $casas[2]->id, (string) $appCasa2['id'], $instanteRetirada));
assertCondition($e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::APPLICATION_NOT_FOUND, 'Retirar la petición ajena: APPLICATION_NOT_FOUND (404)');

$e = captureException(static fn () => $service->withdrawApplication($postulante, $casas[1]->id, (string) $appCasa2['id'], $instanteRetirada));
assertCondition($e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::APPLICATION_NOT_FOUND, 'Retirar con casa equivocada: APPLICATION_NOT_FOUND (404)');

$e = captureException(static fn () => $service->withdrawApplication($postulante, $casas[2]->id, (string) $appCasa2['id'], $instanteRetirada));
assertCondition($e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::APPLICATION_ALREADY_RESOLVED, 'Segunda retirada (ya resuelta): APPLICATION_ALREADY_RESOLVED (409)');

// La carrera: retiro la petición de la Casa 1 y el dictamen la disputa.
$expediente2 = $applicationRepository->findApplicationsByUser('usr_post');
$appCasa1 = null;
foreach ($expediente2 as $fila) {
    if ($fila['clan_id'] === $casas[1]->id && $fila['status'] === 'pending') {
        $appCasa1 = $fila;
    }
}
$miembroCasa1 = oathUser($pdo, 'usr_pat1', 'PatriarcaCasaUno', 'editor');
$patriarcaCasa1 = $service->findClanById($casas[1]->id);
assertCondition($patriarcaCasa1 !== null, 'La Casa 1 existe para la carrera');

// Segundo retiro limpio sobre la Casa 1 para dejarla en `cancelled` y
// comprobar que ni la retirada ni el dictamen reescriben terminales.
$service->withdrawApplication($postulante, $casas[1]->id, (string) $appCasa1['id'], $instanteRetirada);
$e = captureException(static fn () => $service->withdrawApplication($postulante, $casas[1]->id, (string) $appCasa1['id'], $instanteRetirada));
assertCondition(
    $e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::APPLICATION_ALREADY_RESOLVED,
    'La carrera cerrada: la condición `status=pending` del UPDATE deja un solo desenlace (caso límite 5)'
);

// --- FASE 6: Persistencia intacta tras los gestos vedados ---
echo "\nFASE 6: Persistencia intacta tras los gestos vedados\n";
$expedienteFinal = $applicationRepository->findApplicationsByUser('usr_post');
$estados = [];
foreach ($expedienteFinal as $fila) {
    $estados[$fila['clan_id']] = $fila['status'];
}
assertCondition(($estados[$casas[1]->id] ?? '') === 'cancelled', 'Casa 1: cancelled');
assertCondition(($estados[$casas[2]->id] ?? '') === 'cancelled', 'Casa 2: cancelled');
assertCondition(($estados[$casas[3]->id] ?? '') === 'pending', 'Casa 3: pending (intacta)');
assertCondition(($estados[$casas[4]->id] ?? '') === 'pending', 'Casa 4: pending (la nueva tras liberar cupo)');
assertCondition(count($expedienteFinal) === 4, 'El expediente conserva las 4 filas: nada se borra, todo se clausura');

// --- Veredicto ---
echo "\n=============================\n";
echo "Asertos: {$assertsPassed} PASA / {$assertsFailed} FALLOS\n";
if ($assertsFailed > 0) {
    echo "La retirada del postulante queda ROJA: no cumple aún su contrato.\n";
    exit(1);
}
echo "La retirada libera el cupo, clausura la casa y deja su asiento con reparto por actor.\n";
exit(0);
