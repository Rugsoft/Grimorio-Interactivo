<?php

declare(strict_types=1);

/**
 * test_lineage_master_promotion.php — Verificación de la Tarea 2.4 de
 * TASKS-09 (guardia de designación de Maestro, RF-05.2).
 *
 * El criterio «Hecho cuando» de la tarea se comprueba en tres frentes:
 *   1. Intentar ascender a un peregrino fracasa con error controlado
 *      solemne (403 `MASTER_REQUIRES_LINEAGE`).
 *   2. La cuenta queda sin cambiar.
 *   3. El ascenso de un linajado sigue funcionando intacto.
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): PDO nativo en :memory:, sin librerías.
 *   - Art. III: el sujeto ético del conflicto de intereses queda siempre
 *     determinado: nadie valida obras sin linaje jurado.
 *   - Art. V: identificadores en inglés; narrativa en castellano.
 *
 * Uso: php scratch/test_lineage_master_promotion.php
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

/** Inscribe un adepto de prueba. */
function forgeAdept(PDO $pdo, string $id, string $alias, string $email, ?string $lineage, string $role = 'editor'): void
{
    $NOW = '2026-09-18T10:00:00Z';
    $statement = $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, created_at, updated_at)
         VALUES (:id, :alias, :email, \'x\', :role, NULL, :lineage, :now, :now)'
    );
    $statement->execute([':id' => $id, ':alias' => $alias, ':email' => $email, ':role' => $role, ':lineage' => $lineage, ':now' => $NOW]);
}

echo "== VERIFICACION TAREA 2.4: La designación de Maestro exige linaje ==\n\n";

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/src/Models/User.php';
require_once $projectRoot . '/src/Models/AuditEntry.php';
require_once $projectRoot . '/src/Repositories/LineageOathRepository.php';
// La pluma exige su contrato desde SPEC-11 (Fase 2): se carga antes del
// servicio, como hace el autoloader del front controller.
require_once $projectRoot . '/src/Services/AuditRecorderInterface.php';
require_once $projectRoot . '/src/Services/AuditService.php';
require_once $projectRoot . '/src/Exceptions/LineageOathException.php';
// SovereignAdminService carga sus dependencias propias vía autoload del
// arnés: se listan aquí para CLI sin composer.
foreach (['Dto/SpellReviewDto', 'Dto/DominionReversalDto', 'Dto/DominionAwardDto', 'Dto/ClanDto', 'Dto/ClanMemberDto', 'Dto/WeeklyCycleDto', 'Dto/ImperialDecreeDto', 'Exceptions/ModerationWorkflowException', 'Exceptions/SpellNotFoundException', 'Exceptions/ClanGovernanceException', 'Repositories/SpellReviewRepository', 'Repositories/MasterSignatureRepository', 'Repositories/ImperialDecreeRepository', 'Repositories/ClanMemberRepository', 'Repositories/ClanRepository', 'Repositories/WeeklyCycleRepository', 'Dto/LineageDto', 'Services/LineageSynergyService', 'Services/WeeklyDominionService'] as $dependency) {
    require_once $projectRoot . '/src/' . $dependency . '.php';
}
require_once $projectRoot . '/src/Services/SovereignAdminService.php';

$pdo = forgeSanctuary();
$service = new Grimorio\Services\SovereignAdminService($pdo);

// El soberano actuante (sin linaje: su exención es fundacional).
forgeAdept($pdo, 'usr_supremo', 'El Supremo', 'supremo@arcano.arc', null, 'supremeAdmin');
// Dos candidatos: peregrino y linajado.
forgeAdept($pdo, 'usr_peregrino', 'Peregrino del Velo', 'peregrino@arcano.arc', null, 'editor');
forgeAdept($pdo, 'usr_jurado', 'Jurado de la Marea', 'jurado@arcano.arc', 'celestialTides', 'editor');

$instante = new DateTimeImmutable('2026-09-18T12:00:00Z', new DateTimeZone('UTC'));

// --- Fase 1: El rechazo solemne al peregrino ---
echo "FASE 1: Ascenso de un peregrino — rechazo solemne (criterio 1)\n";
$rechazo = captureThrowable(static fn () => $service->promoteMaster('usr_supremo', 'usr_peregrino', $instante));
assertCondition(
    $rechazo instanceof Grimorio\Exceptions\LineageOathException
    && $rechazo->errorCode === 'MASTER_REQUIRES_LINEAGE' && $rechazo->httpStatus === 403,
    'El ascenso del peregrino fracasa con 403 `MASTER_REQUIRES_LINEAGE` (RF-05.2)'
);
assertCondition(
    $rechazo !== null && str_contains($rechazo->getMessage(), 'jurado'),
    'El rechazo porta leyenda solemne en castellano sin trazas internas'
);

// --- Fase 2: La cuenta queda sin cambiar ---
echo "\nFASE 2: La cuenta del peregrino queda intacta (criterio 2)\n";
$fila = $pdo->query("SELECT role, lineage FROM users WHERE id = 'usr_peregrino'")->fetch(PDO::FETCH_ASSOC);
assertCondition($fila['role'] === 'editor' && $fila['lineage'] === null, 'El peregrino sigue editor y sin linaje: sin mutación alguna');
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE target_entity_id = 'usr_peregrino'")->fetchColumn() === 0,
    'El rechazo no deja asiento: solo los actos felices habitan la Bitácora'
);

// --- Fase 3: El linajado asciende intacto ---
echo "\nFASE 3: El linajado asciende con el oficio y su edicto (criterio 3)\n";
$ascenso = captureThrowable(static fn () => $service->promoteMaster('usr_supremo', 'usr_jurado', $instante));
assertCondition($ascenso === null, 'El ascenso del linajado no encuentra obstáculo');
$filaJurado = $pdo->query("SELECT role, lineage FROM users WHERE id = 'usr_jurado'")->fetch(PDO::FETCH_ASSOC);
assertCondition($filaJurado['role'] === 'master' && $filaJurado['lineage'] === 'celestialTides', 'El linajado despierta Maestro conservando su linaje jurado');

$edicto = $pdo->query("SELECT actor_user_id, action_type, target_entity_id, justification FROM audit_log
                       WHERE action_type = 'PROMOTE_MASTER' AND target_entity_id = 'usr_jurado'")->fetch(PDO::FETCH_ASSOC);
assertCondition(
    $edicto !== false && $edicto['actor_user_id'] === 'usr_supremo' && str_contains((string) $edicto['justification'], 'linaje jurado'),
    'El ascenso queda asentado como `PROMOTE_MASTER` con su motivo solemne (Art. III.3)'
);

// --- Fase 4: Las murallas accesorias ---
echo "\nFASE 4: Murallas accesorias del oficio\n";
$soloEditor = captureThrowable(static fn () => $service->promoteMaster('usr_supremo', 'usr_jurado', $instante));
assertCondition(
    $soloEditor instanceof InvalidArgumentException,
    'Un Maestro ya designado no vuelve a ascender: solo un editor parte al oficio'
);
$noSoberano = captureThrowable(static fn () => $service->promoteMaster('usr_jurado', 'usr_peregrino', $instante));
assertCondition($noSoberano !== null, 'Un no-soberano jamás designa: la potestad se acredita primero');

echo "\n== RESUMEN == Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";
if ($assertsFailed === 0) {
    echo "RESULTADO: EXITO — El oficio de Maestro exige linaje jurado: peregrino rechazado, cuenta intacta y ascenso linajado intacto (Tarea 2.4).\n";
    exit(0);
}

echo "RESULTADO: DENEGADO — Corregir los asertos en rojo antes de continuar.\n";
exit(1);
