<?php

/**
 * test_audit_service.php — Arnés de la Tarea 2.5 de TASKS-03.
 *
 * Verifica el servicio de registro inmutable de auditoría
 * src/Services/AuditService.php: cada acción solemne genera un registro
 * con marca UTC en audit_log exclusivamente mediante INSERT (sin UPDATE
 * ni DELETE, protegidos con triggers) y la bitácora se consulta con
 * paginación y filtros ordenada cronológicamente descendente.
 *
 * Estrategia TDD: este arnés se escribe ANTES de implementar el servicio.
 * Fase roja = la clase Grimorio\Services\AuditService no existe todavía.
 *
 * Escenarios verificados (criterio «Hecho cuando» de la tarea):
 *   1. Cada acción solemne genera un registro con marca UTC en audit_log.
 *   2. Recuperación paginada ordenada cronológicamente descendente.
 *   3. Extras estructurales: inmutabilidad real (UPDATE/DELETE rechazados
 *      por triggers), filtro por linaje objetivo, metadatos de paginación
 *      y entidades AuditEntry materializadas.
 *
 * Ejecución: php scratch/test_audit_service.php  (exit 0 = verde)
 */

declare(strict_types=1);

$assertsPassed = 0;
$assertsFailed = 0;

/**
 * Asegura una condición y la reporta con el formato estándar del proyecto.
 */
function assertArcane(bool $condition, string $label): void
{
    global $assertsPassed, $assertsFailed;
    if ($condition) {
        $assertsPassed++;
        echo "  OK   {$label}\n";
    } else {
        $assertsFailed++;
        echo "  FALLA {$label}\n";
    }
}

$projectRoot = dirname(__DIR__);

echo "=== Tarea 2.5 (TASKS-03): Registro inmutable de auditoría ===\n\n";

require_once $projectRoot . '/public/index.php'; // Autoload nativo del proyecto.

use Grimorio\Models\AuditEntry;
use Grimorio\Services\AuditService;

echo "[0] Existencia y cargabilidad del servicio\n";

assertArcane(class_exists(AuditService::class), 'La clase Grimorio\Services\AuditService existe y el autocompilador la resuelve');

if (!class_exists(AuditService::class)) {
    echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
    exit(1);
}

// ---------------------------------------------------------------------
// Sandbox: base SQLite efímera con el esquema completo.
// ---------------------------------------------------------------------
$sandboxDb = sys_get_temp_dir() . '/grimorio_audit_svc_' . getmypid() . '.sqlite';
if (file_exists($sandboxDb)) {
    @unlink($sandboxDb);
}

$pdo = new PDO('sqlite:' . $sandboxDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

$auditService = new AuditService($pdo);

// ---------------------------------------------------------------------
// 1. Registro de acciones solemnes con marca UTC (criterio «Hecho cuando»).
// ---------------------------------------------------------------------
echo "\n[1] Registro de acciones solemnes con marca UTC\n";

$entryCount = 12;
for ($entryIndex = 1; $entryIndex <= $entryCount; $entryIndex++) {
    // Marcas temporales deliberadamente en desorden para probar el orden
    // descendente de la consulta posterior.
    $minute = str_pad((string) ((($entryIndex * 7) % 60)), 2, '0', STR_PAD_LEFT);
    $loggedAt = '2026-09-12T10:' . $minute . ':00Z';
    $auditService->recordAction(
        actorUserId: 'usr_master_' . ($entryIndex % 3),
        actorAlias: 'Maestro' . ($entryIndex % 3),
        actorRole: 'master',
        actionType: $entryIndex % 3 === 0 ? 'SIGN_VALIDATE' : 'SIGN_REJECT',
        targetEntityType: 'spell',
        targetEntityId: 'spl_hechizo_' . str_pad((string) $entryIndex, 3, '0', STR_PAD_LEFT),
        justification: 'Veredicto solemne número ' . $entryIndex . ' con composición de maná auditada.',
        now: new DateTimeImmutable($loggedAt),
    );
}

$totalRows = (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
assertArcane($totalRows === $entryCount, "Las {$entryCount} acciones solemnes quedaron registradas ({$totalRows} filas)");

$sampleRow = $pdo->query(
    "SELECT actor_alias, actor_role, action_type, target_entity_type, target_entity_id, justification, created_at
     FROM audit_log ORDER BY id LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
assertArcane(
    is_array($sampleRow)
    && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $sampleRow['created_at']) === 1,
    'Cada registro porta marca temporal UTC ISO 8601'
);
assertArcane(
    is_array($sampleRow) && $sampleRow['actor_role'] === 'master' && str_starts_with((string) $sampleRow['action_type'], 'SIGN_'),
    'El registro porta identidad, rol y acción canónica'
);

// ---------------------------------------------------------------------
// 2. Inmutabilidad REAL: UPDATE y DELETE rechazados por triggers.
// ---------------------------------------------------------------------
echo "\n[2] Inmutabilidad inquebrantable (Artículo III)\n";

$updateBlocked = false;
try {
    $pdo->exec("UPDATE audit_log SET justification = 'motivo falsificado' WHERE id = 1");
} catch (Throwable $updateError) {
    $updateBlocked = true;
}
assertArcane($updateBlocked, 'UPDATE sobre audit_log es RECHAZADO (triggers anti-mutación)');

$deleteBlocked = false;
try {
    $pdo->exec('DELETE FROM audit_log WHERE id = 1');
} catch (Throwable $deleteError) {
    $deleteBlocked = true;
}
assertArcane($deleteBlocked, 'DELETE sobre audit_log es RECHAZADO (triggers anti-mutación)');

$rowsAfterTampering = (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
assertArcane($rowsAfterTampering === $entryCount, 'Ninguna fila fue alterada ni borrada tras los intentos');

$untouchedJustification = (string) $pdo->query('SELECT justification FROM audit_log WHERE id = 1')->fetchColumn();
assertArcane(
    str_contains($untouchedJustification, 'Veredicto solemne'),
    'La justificación original permanece intacta'
);

// ---------------------------------------------------------------------
// 3. Consulta paginada en orden cronológico descendente (criterio).
// ---------------------------------------------------------------------
echo "\n[3] Bitácora paginada descendente\n";

$pageOne = $auditService->fetchLog(page: 1, limit: 5);
assertArcane(count($pageOne->items) === 5, 'La primera página porta el límite de 5 entradas');
assertArcane($pageOne->pagination['totalItems'] === $entryCount, "Los metadatos reportan el total real ({$entryCount})");
assertArcane($pageOne->pagination['totalPages'] === 3, 'Con 12 entradas y límite 5, hay 3 páginas');
assertArcane($pageOne->pagination['page'] === 1 && $pageOne->pagination['limit'] === 5, 'La paginación ecoa página y límite solicitados');

// Orden descendente estricto: cada created_at debe ser <= al anterior.
$isStrictlyDescending = true;
$previousTimestamp = null;
foreach ($pageOne->items as $auditEntry) {
    $currentTimestamp = strtotime($auditEntry->getCreatedAt());
    if ($previousTimestamp !== null && $currentTimestamp > $previousTimestamp) {
        $isStrictlyDescending = false;
    }
    $previousTimestamp = $currentTimestamp;
}
assertArcane($isStrictlyDescending, 'Las entradas vienen ordenadas cronológicamente DESCENDENTE');

// Continuidad de paginación: la página 2 trae las 5 siguientes, sin solaparse.
$pageTwo = $auditService->fetchLog(page: 2, limit: 5);
$pageOneIds = array_map(static fn (AuditEntry $entry): int => $entry->getId(), $pageOne->items);
$pageTwoIds = array_map(static fn (AuditEntry $entry): int => $entry->getId(), $pageTwo->items);
assertArcane(count($pageTwo->items) === 5, 'La segunda página también porta 5 entradas');
assertArcane(count(array_intersect($pageOneIds, $pageTwoIds)) === 0, 'Las páginas no se solapan');

// La última página porta el resto (12 - 10 = 2).
$pageThree = $auditService->fetchLog(page: 3, limit: 5);
assertArcane(count($pageThree->items) === 2, 'La última página porta las 2 entradas restantes');

// Página fuera de rango: vacía pero sin error.
$beyondRange = $auditService->fetchLog(page: 9, limit: 5);
assertArcane(count($beyondRange->items) === 0, 'Una página fuera de rango devuelve una lista vacía');

// ---------------------------------------------------------------------
// 4. Filtro por entidad objetivo (clan objetivo del plan 3.4).
// ---------------------------------------------------------------------
echo "\n[4] Filtro de consulta\n";

// Registradas sobre hechizos: todas. Filtrado por entidad objetivo específica.
$filteredBySpell = $auditService->fetchLog(page: 1, limit: 25, targetEntityType: 'spell');
assertArcane(count($filteredBySpell->items) === $entryCount, 'El filtro por tipo de entidad spell recupera todas las firmas');

$filteredByClan = $auditService->fetchLog(page: 1, limit: 25, targetEntityType: 'clan');
assertArcane(count($filteredByClan->items) === 0, 'El filtro por tipo de entidad clan no arrastra firmas de hechizos');

// Filtro por identidad del actuante.
$filteredByActor = $auditService->fetchLog(page: 1, limit: 25, actorUserId: 'usr_master_1');
$allFromActorOne = true;
foreach ($filteredByActor->items as $auditEntry) {
    if ($auditEntry->getActorUserId() !== 'usr_master_1') {
        $allFromActorOne = false;
    }
}
assertArcane($allFromActorOne && count($filteredByActor->items) > 0, 'El filtro por actuante recupera solo sus veredictos');

// ---------------------------------------------------------------------
// 5. Registro de acción sobre clan con filtro combinado.
// ---------------------------------------------------------------------
echo "\n[5] Acción sobre linaje y filtro combinado\n";

$auditService->recordAction(
    actorUserId: 'usr_supreme',
    actorAlias: 'ElAdminSupremo',
    actorRole: 'supremeAdmin',
    actionType: 'CLAN_MODIFY',
    targetEntityType: 'clan',
    targetEntityId: 'cln_primordial',
    justification: 'Reorganización de linajes por la tregua semanal del Dominio.',
    now: new DateTimeImmutable('2026-09-12T23:00:00Z'),
);

$clanLog = $auditService->fetchLog(page: 1, limit: 25, targetEntityType: 'clan');
assertArcane(count($clanLog->items) === 1, 'La acción CLAN_MODIFY aparece bajo el filtro de entidad clan');
assertArcane(
    $clanLog->items[0]->getActionType() === 'CLAN_MODIFY'
    && $clanLog->items[0]->getTargetEntityId() === 'cln_primordial'
    && $clanLog->items[0]->getActorRole() === 'supremeAdmin',
    'La entrada de linaje porta acción, objetivo y rol del Admin Supremo'
);

// La nueva entrada es la más reciente: encabeza el orden descendente global.
$latestPage = $auditService->fetchLog(page: 1, limit: 1);
assertArcane(
    $latestPage->items[0]->getActionType() === 'CLAN_MODIFY',
    'La entrada más reciente encabeza la bitácora descendente'
);

// ---------------------------------------------------------------------
// Limpieza del sandbox.
// ---------------------------------------------------------------------
$pdo = null;
gc_collect_cycles();
if (file_exists($sandboxDb)) {
    @unlink($sandboxDb);
}

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);
