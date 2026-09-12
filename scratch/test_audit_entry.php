<?php

/**
 * test_audit_entry.php — Arnés de la Tarea 1.3 de TASKS-03.
 *
 * Verifica la entidad de dominio src/Models/AuditEntry.php: captura de
 * marcas temporales UTC, identidades del actuante, rol activo, tipo de
 * acción, entidad objetivo y motivo justificado; inmutabilidad y
 * normalización a array asociativo para el contrato JSON de la bitácora
 * (plan 2.2, Endpoint 6).
 *
 * Estrategia TDD: este arnés se escribe ANTES de implementar la entidad.
 * Fase roja = la clase Grimorio\Models\AuditEntry no existe todavía.
 *
 * Escenarios verificados (criterio «Hecho cuando» de la tarea):
 *   1. Se puede instanciar un AuditEntry válido.
 *   2. Se convierte a array asociativo normalizado (camelCase) para la
 *      respuesta JSON de la bitácora.
 *   3. Extras estructurales: validación de catálogos (actionType,
 *      targetEntityType), inmutabilidad real y reconstrucción desde
 *      fila de base de datos (snake_case).
 *
 * Ejecución: php scratch/test_audit_entry.php  (exit 0 = verde)
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

echo "=== Tarea 1.3 (TASKS-03): Entidad de dominio AuditEntry inmutable ===\n\n";

// ---------------------------------------------------------------------
// Carga de la entidad (autoload nativo del front controller).
// ---------------------------------------------------------------------
require_once $projectRoot . '/public/index.php';

use Grimorio\Models\AuditEntry;

echo "[0] Existencia y cargabilidad de la entidad\n";

assertArcane(class_exists(AuditEntry::class), 'La clase Grimorio\Models\AuditEntry existe y el autocompilador la resuelve');

if (!class_exists(AuditEntry::class)) {
    echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
    exit(1);
}

/**
 * Fábrica de entrada de auditoría válida para los escenarios.
 */
function forgeAuditEntry(string $actionType = 'SIGN_VALIDATE', string $targetType = 'spell'): AuditEntry
{
    return new AuditEntry(
        id: 142,
        actorUserId: 'usr_master_01',
        actorAlias: 'HeiterElSabio',
        actorRole: 'master',
        actionType: $actionType,
        targetEntityType: $targetType,
        targetEntityId: 'spl_llamas_frieren',
        justification: 'Composición matemática de maná equilibrada y componentes rigurosamente descritos.',
        createdAt: '2026-09-12T14:22:10Z',
    );
}

// ---------------------------------------------------------------------
// 1. Instanciación válida (criterio «Hecho cuando»).
// ---------------------------------------------------------------------
echo "\n[1] Instanciación válida y getters tipados\n";

$entry = null;
$instantiationFailed = false;
try {
    $entry = forgeAuditEntry();
} catch (Throwable $entryError) {
    $instantiationFailed = true;
    echo '  Excepción: ' . $entryError->getMessage() . "\n";
}
assertArcane(!$instantiationFailed && $entry !== null, 'Se instancia un AuditEntry válido');

if ($entry !== null) {
    assertArcane($entry->getId() === 142, 'getId() devuelve el identificador entero');
    assertArcane($entry->getActorUserId() === 'usr_master_01', 'getActorUserId() devuelve la identidad del actuante');
    assertArcane($entry->getActorAlias() === 'HeiterElSabio', 'getActorAlias() devuelve el alias público');
    assertArcane($entry->getActorRole() === 'master', 'getActorRole() devuelve el rol activo');
    assertArcane($entry->getActionType() === 'SIGN_VALIDATE', 'getActionType() devuelve el tipo de acción');
    assertArcane($entry->getTargetEntityType() === 'spell', 'getTargetEntityType() devuelve el tipo de entidad objetivo');
    assertArcane($entry->getTargetEntityId() === 'spl_llamas_frieren', 'getTargetEntityId() devuelve el objetivo');
    assertArcane($entry->getJustification() === 'Composición matemática de maná equilibrada y componentes rigurosamente descritos.', 'getJustification() devuelve el motivo solemne');
    assertArcane($entry->getCreatedAt() === '2026-09-12T14:22:10Z', 'getCreatedAt() devuelve la marca temporal UTC');
}

// ---------------------------------------------------------------------
// 2. Normalización a array asociativo para el JSON de la bitácora
//    (criterio «Hecho cuando»; contrato del plan 2.2, Endpoint 6).
// ---------------------------------------------------------------------
echo "\n[2] Normalización a array asociativo del contrato JSON\n";

if ($entry !== null) {
    $normalized = $entry->toNormalizedArray();

    assertArcane(is_array($normalized), 'toNormalizedArray() devuelve un array asociativo');

    $expectedKeys = ['id', 'actorAlias', 'actorRole', 'actionType', 'targetEntityType', 'targetEntityId', 'justification', 'createdAt'];
    $missingKeys = array_diff($expectedKeys, array_keys($normalized));
    assertArcane(
        $missingKeys === [],
        'Las claves normalizadas siguen el contrato camelCase de la API' . ($missingKeys === [] ? '' : ' (faltan: ' . implode(', ', $missingKeys) . ')')
    );

    assertArcane(
        ($normalized['actorAlias'] ?? null) === 'HeiterElSabio'
        && ($normalized['actionType'] ?? null) === 'SIGN_VALIDATE'
        && ($normalized['targetEntityId'] ?? null) === 'spl_llamas_frieren'
        && ($normalized['createdAt'] ?? null) === '2026-09-12T14:22:10Z',
        'Los valores normalizados coinciden con el contrato del plan (Endpoint 6)'
    );

    // La identidad técnica del actuante (actorUserId) queda disponible
    // para la capa de gobierno pero el contrato público opcionalmente la
    // incluye; verificamos que el array es directamente serializable.
    $json = json_encode($normalized);
    assertArcane($json !== false && str_contains((string) $json, 'SIGN_VALIDATE'), 'El array normalizado es directamente serializable a JSON');
}

// ---------------------------------------------------------------------
// 3. Catálogos válidos: tipos de acción y entidades objetivo.
// ---------------------------------------------------------------------
echo "\n[3] Catálogos de acciones y entidades\n";

foreach (['SIGN_VALIDATE', 'SIGN_REJECT', 'ADMIN_VETO', 'PROMOTE_MASTER', 'DEMOTE_MASTER', 'CLAN_MODIFY'] as $canonicalAction) {
    $accepted = true;
    try {
        forgeAuditEntry($canonicalAction);
    } catch (Throwable $actionError) {
        $accepted = false;
    }
    assertArcane($accepted, "Acepta el tipo de acción canónico '{$canonicalAction}'");
}

foreach (['sign_validate', 'SIGN_DESTROY', '', 'self_promotion'] as $forbiddenAction) {
    $rejected = false;
    try {
        forgeAuditEntry($forbiddenAction);
    } catch (Throwable $forbiddenActionError) {
        $rejected = true;
    }
    assertArcane($rejected, "Rechaza el tipo de acción fuera del catálogo ('{$forbiddenAction}')");
}

foreach (['spell', 'clan', 'user'] as $canonicalTarget) {
    $accepted = true;
    try {
        forgeAuditEntry('ADMIN_VETO', $canonicalTarget);
    } catch (Throwable $targetError) {
        $accepted = false;
    }
    assertArcane($accepted, "Acepta el tipo de entidad objetivo '{$canonicalTarget}'");
}

foreach (['artifact', '', 'SPELLS'] as $forbiddenTarget) {
    $rejected = false;
    try {
        forgeAuditEntry('ADMIN_VETO', $forbiddenTarget);
    } catch (Throwable $forbiddenTargetError) {
        $rejected = true;
    }
    assertArcane($rejected, "Rechaza el tipo de entidad fuera del catálogo ('{$forbiddenTarget}')");
}

// ---------------------------------------------------------------------
// 4. Inmutabilidad real.
// ---------------------------------------------------------------------
echo "\n[4] Inmutabilidad\n";

$immutableEntry = forgeAuditEntry();
$mutationBlocked = true;
try {
    $immutableEntry->justification = 'motivo alterado';
    $mutationBlocked = false;
} catch (Throwable $writeError) {
    // Esperado: la entidad impide la escritura.
}
assertArcane($mutationBlocked, 'La escritura directa de propiedades está bloqueada');

$publicMethods = get_class_methods($immutableEntry);
$setterMethods = array_filter($publicMethods, static fn (string $methodName): bool => str_starts_with($methodName, 'set'));
assertArcane($setterMethods === [], 'La entidad no declara ningún método set*');

// ---------------------------------------------------------------------
// 5. Reconstrucción desde fila de base de datos (snake_case del DDL).
// ---------------------------------------------------------------------
echo "\n[5] Reconstrucción desde fila de base de datos\n";

$databaseRow = [
    'id' => 7,
    'actor_user_id' => 'usr_admin_01',
    'actor_alias' => 'ElAdminSupremo',
    'actor_role' => 'supremeAdmin',
    'action_type' => 'CLAN_MODIFY',
    'target_entity_type' => 'clan',
    'target_entity_id' => 'cln_primordial',
    'justification' => 'Reorganización de linajes por tregua semanal.',
    'created_at' => '2026-09-11T22:05:00Z',
];

$rowEntry = null;
$rowRebuildOk = true;
try {
    $rowEntry = AuditEntry::fromDatabaseRow($databaseRow);
} catch (Throwable $rebuildError) {
    $rowRebuildOk = false;
    echo '  Excepción: ' . $rebuildError->getMessage() . "\n";
}
assertArcane($rowRebuildOk && $rowEntry !== null, 'fromDatabaseRow() reconstruye la entidad desde la fila snake_case');

if ($rowEntry !== null) {
    $normalizedRow = $rowEntry->toNormalizedArray();
    assertArcane(
        ($normalizedRow['actionType'] ?? null) === 'CLAN_MODIFY'
        && ($normalizedRow['targetEntityType'] ?? null) === 'clan',
        'La reconstrucción mapea action_type/target_entity_type (snake) a camelCase'
    );
}

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);
