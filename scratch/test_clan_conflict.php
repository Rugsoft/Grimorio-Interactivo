<?php

/**
 * test_clan_conflict.php — Arnés de la Tarea 2.4 de TASKS-03.
 *
 * Verifica el servicio de conflicto de intereses entre linajes
 * src/Services/ClanConflictService.php: un Maestro no puede firmar un
 * conjuro propio, de su clan actual ni de un clan que habitó en los
 * últimos 30 días (RF-06.1, RF-06.2, RF-07.3, Artículo III).
 *
 * Estrategia TDD: este arnés se escribe ANTES de implementar el servicio.
 * Fase roja = la clase Grimorio\Services\ClanConflictService no existe
 * todavía.
 *
 * Escenarios verificados (criterio «Hecho cuando» de la tarea):
 *   1. Rechazo si el Maestro es del mismo clan (actual).
 *   2. Rechazo si perteneció a ese clan hace menos de 30 días.
 *   3. Motivo solemne de incompatibilidad en cada rechazo.
 *   4. Extras estructurales: auto-firma vetada, clan abandonado hace más
 *      de 30 días permitido, firma legítima aprobada, historial con
 *      múltiples clanes y NULL como clan activo.
 *
 * Ejecución: php scratch/test_clan_conflict.php  (exit 0 = verde)
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

echo "=== Tarea 2.4 (TASKS-03): Conflicto de intereses entre linajes ===\n\n";

require_once $projectRoot . '/public/index.php'; // Autoload nativo del proyecto.

use Grimorio\Models\User;
use Grimorio\Services\ClanConflictService;

echo "[0] Existencia y cargabilidad del servicio\n";

assertArcane(class_exists(ClanConflictService::class), 'La clase Grimorio\Services\ClanConflictService existe y el autocompilador la resuelve');

if (!class_exists(ClanConflictService::class)) {
    echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
    exit(1);
}

// ---------------------------------------------------------------------
// Sandbox: base SQLite efímera con el esquema completo.
// ---------------------------------------------------------------------
$sandboxDb = sys_get_temp_dir() . '/grimorio_clan_conflict_' . getmypid() . '.sqlite';
if (file_exists($sandboxDb)) {
    @unlink($sandboxDb);
}

$pdo = new PDO('sqlite:' . $sandboxDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

$now = '2026-09-12T12:00:00Z';

// Tres linajes de prueba: el actual del maestro, el abandonado reciente
// (hace 10 días) y el abandonado antiguo (hace 45 días).
foreach (['cln_astral' => 'Eruditos Astrales', 'cln_ember' => 'Heraldos de Brasas', 'cln_tide' => 'Mareas del Alba'] as $clanId => $clanName) {
    $slug = str_replace('cln_', '', $clanId);
    $pdo->exec(
        "INSERT INTO clans (id, slug, name, motto, created_at)
         VALUES ('{$clanId}', '{$slug}', '{$clanName}', 'Lema', '{$now}')"
    );
}

/**
 * Inserta un usuario de prueba y devuelve su identificador.
 */
function forgeUserRow(PDO $pdo, string $id, string $alias, string $clanId, string $now): string
{
    $pdo->exec(
        "INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
         VALUES ('{$id}', '{$alias}', '{$alias}@test.arc', '" . str_repeat('x', 60) . "', 'master', '{$clanId}', '{$now}', '{$now}')"
    );
    return $id;
}

// NOTA de diseño: el esquema de spells (SPEC-01) no porta author_id; el
// plan 3.1 modela el autor como atributo del conjuro en el contexto de
// moderación. El servicio recibe authorId/clanId como datos del conjuro,
// desacoplado de la fila física (la columna author_id se añadirá en la
// Tarea de firmas si el plan lo exige).

$conflictService = new ClanConflictService($pdo);

$masterId = forgeUserRow($pdo, 'usr_master_01', 'MaestroNeutral', 'cln_astral', $now);
$masterUser = new User(
    id: $masterId,
    alias: 'MaestroNeutral',
    email: 'neutral@test.arc',
    role: 'master',
    clanId: 'cln_astral',
    passwordHash: str_repeat('x', 60),
    createdAt: $now,
    updatedAt: $now,
);

// Historial de membresía del Maestro. La AUTORIDAD es `clan_members`
// (Tarea 2.3, TASKS-07): milita hoy en `cln_astral`, y habitó `cln_ember`
// hasta hace 10 días y `cln_tide` hasta hace 45 días. La tabla `clan_history`
// quedó retirada del camino crítico: ninguna clase de src/ la escribía.
$pdo->exec(
    "INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at, convalescence_expires_at) VALUES
     ('clm_m1_astral', 'cln_astral', '{$masterId}', 'adept', '2025-03-01T00:00:00Z', NULL, NULL),
     ('clm_m1_ember',  'cln_ember',  '{$masterId}', 'adept', '2026-05-01T00:00:00Z', '2026-09-02T00:00:00Z', NULL),
     ('clm_m1_tide',   'cln_tide',   '{$masterId}', 'adept', '2026-01-01T00:00:00Z', '2026-07-29T00:00:00Z', NULL)"
);

// ---------------------------------------------------------------------
// 1. Regla 1 (plan 3.1): no auto-aprobación.
// ---------------------------------------------------------------------
echo "\n[1] Veto de auto-firma (el Maestro es el autor)\n";

$ownSpellVerdict = $conflictService->canMasterSignSpell(
    $masterUser,
    authorId: $masterId,
    spellClanId: 'cln_tide',
    now: new DateTimeImmutable($now),
);
assertArcane($ownSpellVerdict->isAllowed === false, 'La firma sobre una creación propia queda VETADA');
assertArcane($ownSpellVerdict->reason !== '', 'El veto porta un motivo solemne');
assertArcane(
    str_contains($ownSpellVerdict->reason, 'propias'),
    'El motivo de auto-firma menciona las creaciones propias'
);

// ---------------------------------------------------------------------
// 2. Regla 2 (plan 3.1): clan actual del Maestro.
// ---------------------------------------------------------------------
echo "\n[2] Veto por clan actual\n";

$sameClanVerdict = $conflictService->canMasterSignSpell(
    $masterUser,
    authorId: 'usr_otro_autor',
    spellClanId: 'cln_astral',
    now: new DateTimeImmutable($now),
);
assertArcane($sameClanVerdict->isAllowed === false, 'La firma sobre un conjuro del clan ACTUAL del Maestro queda VETADA');
assertArcane(
    str_contains($sameClanVerdict->reason, 'vínculo de sangre'),
    'El motivo porta la advertencia del vínculo de sangre (RF-06.1)'
);

// ---------------------------------------------------------------------
// 3. Regla 3 (plan 3.1): incompatibilidad histórica de 30 días.
// ---------------------------------------------------------------------
echo "\n[3] Veto por linaje histórico de los últimos 30 días\n";

// (El historial del Maestro se sembró en el bloque de fixtures de arriba.)
$recentClanVerdict = $conflictService->canMasterSignSpell(
    $masterUser,
    authorId: 'usr_otro_autor',
    spellClanId: 'cln_ember',
    now: new DateTimeImmutable($now),
);
assertArcane($recentClanVerdict->isAllowed === false, 'La firma sobre un linaje abandonado hace 10 días queda VETADA');
assertArcane(
    str_contains($recentClanVerdict->reason, 'treinta días'),
    'El motivo menciona la ventana histórica de treinta días'
);

// Linaje abandonado hace 45 días: fuera de la ventana histórica → permitido.
$oldClanVerdict = $conflictService->canMasterSignSpell(
    $masterUser,
    authorId: 'usr_otro_autor',
    spellClanId: 'cln_tide',
    now: new DateTimeImmutable($now),
);
assertArcane($oldClanVerdict->isAllowed === true, 'Un linaje abandonado hace 45 días NO bloquea la firma (fuera de la ventana)');

// ---------------------------------------------------------------------
// 4. Camino feliz: firma legítima aprobada sin motivo.
// ---------------------------------------------------------------------
echo "\n[4] Firma legítima aprobada\n";

$cleanClan = 'cln_foraneo_' . uniqid();
$cleanVerdict = $conflictService->canMasterSignSpell(
    $masterUser,
    authorId: 'usr_otro_autor',
    spellClanId: 'cln_nunca_habitado',
    now: new DateTimeImmutable($now),
);
assertArcane($cleanVerdict->isAllowed === true, 'La firma sobre un linaje ajeno y nunca habitado queda APROBADA');
assertArcane($cleanVerdict->reason === '', 'La aprobación no porta motivo de veto');

// ---------------------------------------------------------------------
// 5. Membresía vigente (left_at NULL): el clan que habita también veta.
// ---------------------------------------------------------------------
echo "\n[5] Membresía vigente en la autoridad (left_at NULL)\n";

// Un segundo maestro que milita HOY en cln_ember: su membresía activa.
$secondMasterId = forgeUserRow($pdo, 'usr_master_02', 'MaestroDoble', 'cln_astral', $now);
$pdo->exec(
    "INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at, convalescence_expires_at)
     VALUES ('clm_m2_ember', 'cln_ember', '{$secondMasterId}', 'adept', '2026-05-01T00:00:00Z', NULL, NULL)"
);

$secondMasterUser = new User(
    id: $secondMasterId,
    alias: 'MaestroDoble',
    email: 'doble@test.arc',
    role: 'master',
    clanId: 'cln_astral',
    passwordHash: str_repeat('x', 60),
    createdAt: $now,
    updatedAt: $now,
);

$nullLeftVerdict = $conflictService->canMasterSignSpell(
    $secondMasterUser,
    authorId: 'usr_otro_autor',
    spellClanId: 'cln_ember',
    now: new DateTimeImmutable($now),
);
assertArcane($nullLeftVerdict->isAllowed === false, 'Una membresía vigente (left_at NULL) en el linaje del conjuro queda VETADA');

// ---------------------------------------------------------------------
// 6. La ventana se evalúa contra «ahora» inyectado (determinismo).
// ---------------------------------------------------------------------
echo "\n[6] Determinismo con reloj inyectado\n";

// El mismo linaje de hace 10 días, evaluado 25 días después: suma 35 días
// desde la salida → fuera de la ventana → permitido.
$futureNow = new DateTimeImmutable('2026-10-07T12:00:00Z');
$futureVerdict = $conflictService->canMasterSignSpell(
    $masterUser,
    authorId: 'usr_otro_autor',
    spellClanId: 'cln_ember',
    now: $futureNow,
);
assertArcane($futureVerdict->isAllowed === true, 'Con el reloj avanzado 25 días, el linaje antes vetado queda permitido');

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
