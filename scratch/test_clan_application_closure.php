<?php

declare(strict_types=1);

/**
 * test_clan_application_closure.php — Verificación de la Tarea 2.3 (molde y
 * estampa de llegada) y de la Tarea 7.3 (arnés de la auditoría de
 * estabilización) de TASKS-10.
 *
 * Valida el molde de motivación y la estampa de llegada (SPEC-10) contra el
 * «Hecho cuando» de la tarea:
 *
 *   1. Motivaciones de 19, 20 y 501 caracteres producen 400 INVALID_MOTIVATION
 *      SOLO en casas `byApplication`; 20 y 500 remiten la petición.
 *   2. En casas `open` la motivación se ignora si llega (el rito no redacta):
 *      el ingreso inmediato prospera sin texto ni con texto desbordado.
 *   3. `receivedAt` dentro de la ventana de ±30 s se honra (cronología);
 *      fuera de ella (o ausente) el servidor fija la suya.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, base en memoria.
 *   - Artículo V (Dualidad): identificadores en inglés; narrativa en noble
 *     castellano.
 *
 * Uso: php scratch/test_clan_application_closure.php
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
        echo "  [PASA-FALSO] {$description}\n";
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

echo "== VERIFICACION TAREA 2.3: Molde de motivación y estampa de llegada ==\n\n";

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
require_once $projectRoot . '/src/Services/AuditRecorderInterface.php';
require_once $projectRoot . '/src/Services/AuditService.php';
require_once $projectRoot . '/src/Services/LineageSynergyService.php';
require_once $projectRoot . '/src/Services/ClanAdmissionResult.php';
require_once $projectRoot . '/src/Services/ClanCatalogPage.php';
require_once $projectRoot . '/src/Services/ClanService.php';
require_once $projectRoot . '/src/Models/User.php';

use Grimorio\Exceptions\ClanGovernanceException;
use Grimorio\Models\User;
use Grimorio\Services\AuditService;
use Grimorio\Services\ClanService;

// --- FASE 0: Reino de prueba ---
echo "FASE 0: Reino de prueba\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

$now = new DateTimeImmutable('2026-09-20T12:00:00Z');
$service = new ClanService($pdo, new AuditService($pdo));

// --- FASE 1: Los bordes del molde en casa `byApplication` ---
echo "\nFASE 1: El molde 20–500 en la casa de deliberación\n";
$deliberationFounder = oathUser($pdo, 'usr_delib', 'FundadoraDeliberante', 'editor');
$houseDeliberation = $service->foundClan(
    $deliberationFounder, 'Casa de la Pergamentería', 'Todo entra por escrito', 'rune_perga', 'primordialFlame', 'byApplication', $now
);

$aspirante19 = oathUser($pdo, 'usr_asp19', 'SusurradorBreve', 'editor');
$motivation19 = str_repeat('a', 19);
$e = captureException(static fn () => $service->applyToClan(
    $aspirante19, $houseDeliberation->id, $now, $motivation19
));
assertCondition($e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::INVALID_MOTIVATION, '19 caracteres: 400 INVALID_MOTIVATION (borde inferior)');
assertCondition($e !== null && $e->httpStatus === 400, 'El molde roto responde 400');
$left = captureException(static fn () => $service->applyToClan(
    $aspirante19, $houseDeliberation->id, $now, null
));
assertCondition($left instanceof ClanGovernanceException && $left->errorCode === ClanGovernanceException::INVALID_MOTIVATION, 'Sin motivación: también 400 (el molde exige cuerpo)');

$aspirante20 = oathUser($pdo, 'usr_asp20', 'PostulanteExacto', 'editor');
$motivation20 = str_repeat('b', 20);
$petition20 = $service->applyToClan($aspirante20, $houseDeliberation->id, $now, $motivation20);
assertCondition($petition20->isPending(), '20 caracteres exactos: la petición remite (borde inclusivo inferior)');

$aspirante500 = oathUser($pdo, 'usr_asp500', 'RedactorEspecial', 'editor');
$motivation500 = str_repeat('c', 500);
$petition500 = $service->applyToClan($aspirante500, $houseDeliberation->id, $now, $motivation500);
assertCondition($petition500->isPending(), '500 caracteres exactos: la petición remite (borde inclusivo superior)');

$aspirante501 = oathUser($pdo, 'usr_asp501', 'RedactorInfinito', 'editor');
$motivation501 = str_repeat('d', 501);
$e = captureException(static fn () => $service->applyToClan(
    $aspirante501, $houseDeliberation->id, $now, $motivation501
));
assertCondition($e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::INVALID_MOTIVATION, '501 caracteres: 400 INVALID_MOTIVATION (borde superior)');
assertCondition($e !== null && str_contains($e->getMessage(), '20') && str_contains($e->getMessage(), '500'), 'La leyenda nombra el molde canónico');

// Los espacios sobrantes se recortan ANTES de medir: 22 letras + 3 espacios
// miden 25 en crudo pero 22 tras el recorte — dentro del molde.
$trimmed = $service->applyToClan($aspirante501, $houseDeliberation->id, $now, str_repeat('e', 22) . '   ');
assertCondition($trimmed->isPending(), 'Los espacios sobrantes se recortan antes de medir (el canon recorta)');

// --- FASE 2: En casa `open` la pluma no redacta ---
echo "\nFASE 2: El molde no gobierna el rito de ingreso inmediato\n";
$openFounder = oathUser($pdo, 'usr_open', 'FundadoraAbierta', 'editor');
$houseOpen = $service->foundClan(
    $openFounder, 'Casa de las Puertas Abiertas', 'Entra quien es digno', 'rune_abierta', 'primordialFlame', 'open', $now
);

$ingresado = oathUser($pdo, 'usr_ingresado', 'VisitanteSinPergamino', 'editor');
$admission = $service->applyToClan($ingresado, $houseOpen->id, $now, null);
assertCondition($admission->isAdmitted(), 'Sin motivación alguna, el ingreso inmediato prospera (RF-03.1: solo rige en byApplication)');

$ingresadoConTexto = oathUser($pdo, 'usr_ingresado2', 'VisitanteVerborreico', 'editor');
$admission2 = $service->applyToClan($ingresadoConTexto, $houseOpen->id, $now, str_repeat('x', 900));
assertCondition($admission2->isAdmitted(), 'Con 900 caracteres de texto, el ingreso inmediato TAMBIÉN prospera (la motivación se ignora)');

// --- FASE 3: La estampa de llegada y su ventana ---
echo "\nFASE 3: receivedAt con tolerancia de ±30 segundos\n";
$aspirante = oathUser($pdo, 'usr_llegada', 'PostulantePuntual', 'editor');
$motivation = 'Pido entrar a servir bajo este estandarte.';

// Dentro de la ventana: la llegada declarada gobierna la cronología.
$onTime = $service->applyToClan(
    $aspirante, $houseDeliberation->id, $now, $motivation, '2026-09-20T11:59:45Z'
);
assertCondition($onTime->isPending() && $onTime->application?->createdAt === '2026-09-20T11:59:45Z', 'Dentro de ±30 s: la llegada declarada queda grabada (desempate por llegada)');

// Fuera de la ventana (2 minutos): manda el servidor.
$late = oathUser($pdo, 'usr_tarde', 'PostulanteTardío', 'editor');
$tooLate = $service->applyToClan(
    $late, $houseDeliberation->id, $now, $motivation, '2026-09-20T11:58:00Z'
);
assertCondition($tooLate->isPending() && $tooLate->application?->createdAt === '2026-09-20T12:00:00Z', 'A 2 minutos de retraso: el servidor fija su propia estampa');

// Manipulada hacia el futuro lejano: descartada.
$future = oathUser($pdo, 'usr_futuro', 'ViajeroTemporal', 'editor');
$fromFuture = $service->applyToClan(
    $future, $houseDeliberation->id, $now, $motivation, '2026-09-21T00:00:00Z'
);
assertCondition($fromFuture->isPending() && $fromFuture->application?->createdAt === '2026-09-20T12:00:00Z', 'Llegada «del futuro»: descartada, manda el servidor');

// Ausente: el servidor fija la suya.
$absent = oathUser($pdo, 'usr_silente', 'PostulanteSilente', 'editor');
$silent = $service->applyToClan($absent, $houseDeliberation->id, $now, $motivation, null);
assertCondition($silent->isPending() && $silent->application?->createdAt === '2026-09-20T12:00:00Z', 'Sin receivedAt: la estampa del servidor rige');

// --- FASE 4: Regresión de la batería de postulación existente ---
echo "\nFASE 4: Regresión — el tope de tres pendientes por postulante sigue canónico\n";
// Casas de deliberación adicionales del mismo linaje para un solo postulante.
$casasRegresion = [];
foreach ([1, 2, 3, 4] as $index) {
    $fundadora = oathUser($pdo, 'usr_regf' . $index, 'FundadoraRegresion' . $index, 'editor');
    $casasRegresion[$index] = $service->foundClan(
        $fundadora, 'Casa de Regresión ' . $index, 'Deliberación de regresión', 'rune_reg' . $index, 'primordialFlame', 'byApplication', $now
    );
}
$postulanteTenaz = oathUser($pdo, 'usr_reg4', 'PostulanteRegresion4', 'editor');
foreach ([1, 2, 3] as $index) {
    $service->applyToClan($postulanteTenaz, $casasRegresion[$index]->id, $now, 'Petición de regresión con cuerpo suficiente.');
}
$e = captureException(static fn () => $service->applyToClan(
    $postulanteTenaz, $casasRegresion[4]->id, $now, 'La cuarta petición del cupo de tres pendientes.'
));
assertCondition($e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::PENDING_APPLICATIONS_LIMIT, 'El tope de tres pendientes permanece canónico (400)');

// --- FASE 5: El rechazo clausura sin consumir cupo (RF-03.1, hallazgo 16) ---
echo "\nFASE 5: El rechazo clausura la casa y libera el cupo (RF-03.1)\n";
// Un postulante llena su cupo con una petición y recibe el dictamen
// desfavorable del Patriarca: la casa queda clausurada PARA ÉL, pero el
// cupo de pendientes vuelve a respirar (el rechazo no es una petición viva).
$coutureFounder = oathUser($pdo, 'usr_couture', 'FundadoraCouture', 'editor');
$houseCouture = $service->foundClan(
    $coutureFounder, 'Casa de la Aguja Fina', 'Se delibera con hilo de oro', 'rune_aguja', 'primordialFlame', 'byApplication', $now
);
$postulanteRechazado = oathUser($pdo, 'usr_rechazado', 'ElPostulanteRechazado', 'editor');
$peticion = $service->applyToClan($postulanteRechazado, $houseCouture->id, $now, 'Pido un lugar entre las agujas que bordan el destino.');
$service->resolveApplication($coutureFounder, $houseCouture->id, $peticion->application?->id ?? '', 'reject', $now, 'Tu vocación aún no ha florecido.');

// Re-postulación sobre la casa rechazada: clausura, no idempotencia.
$e = captureException(static fn () => $service->applyToClan(
    $postulanteRechazado, $houseCouture->id, $now, 'Insisto: la aguja me llama.'
));
assertCondition($e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::APPLICATION_HOUSE_CLOSED, 'Re-postulación tras RECHAZO: 403 APPLICATION_HOUSE_CLOSED (RF-03.1)');
assertCondition($e !== null && $e->httpStatus === 403, 'La clausura responde 403');

// El cupo: el rechazo no cuenta como pendiente; tres peticiones nuevas caben.
$casasCouture = [];
foreach ([1, 2, 3] as $index) {
    $fundadoraAux = oathUser($pdo, 'usr_aux' . $index, 'FundadoraAuxiliar' . $index, 'editor');
    $casasCouture[$index] = $service->foundClan(
        $fundadoraAux, 'Casa Auxiliar ' . $index, 'Lema', 'rune_aux' . $index, 'primordialFlame', 'byApplication', $now
    );
    $service->applyToClan($postulanteRechazado, $casasCouture[$index]->id, $now, 'Petición número ' . $index . ' tras el rechazo liberador.');
}
assertCondition(true, 'El cupo respira tras el rechazo: tres peticiones vivas coexisten con la casa clausurada');

// La fila persiste clausurada para siempre (el índice único la vela).
$statement = $pdo->prepare("SELECT COUNT(*) FROM clan_applications WHERE user_id = :userId AND clan_id = :clanId AND status = 'rejected'");
$statement->execute([':userId' => 'usr_rechazado', ':clanId' => $houseCouture->id]);
assertCondition((int) $statement->fetchColumn() === 1, 'La fila rechazada PERSISTE: la clausura es perpetua (caso límite 13)');

// --- Veredicto ---
echo "\n=============================\n";
echo "Asertos: {$assertsPassed} PASA / {$assertsFailed} FALLOS\n";
if ($assertsFailed > 0) {
    echo "El molde y la estampa de llegada quedan ROJOS: no cumplen aún su contrato.\n";
    exit(1);
}
echo "El molde 20–500 rige solo la petición formal y la llegada manda dentro de ±30 s.\n";
exit(0);
