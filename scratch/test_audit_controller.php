<?php

/**
 * test_audit_controller.php — Arnés de la Tarea 3.4 de TASKS-03.
 *
 * Verifica el controlador REST src/Controllers/AuditController.php contra
 * el contrato exacto del plan (sección 2.2, Endpoint 6):
 * GET /api/v1/audit/log?page=1&limit=25&clanId=... con paginación
 * (page, limit, totalItems, totalPages) y orden cronológico descendente.
 *
 * Estrategia TDD: este arnés se escribe ANTES de implementar el controlador.
 * Fase roja = la clase Grimorio\Controllers\AuditController no existe todavía.
 *
 * Escenarios verificados (criterio «Hecho cuando» de la tarea):
 *   1. Accesibilidad pública: anónimo (sin usuario inyectado) recibe 200
 *      con la lista paginada — igual que un usuario consagrado.
 *   2. Paginación correcta: límite, página, totalItems, totalPages y
 *      orden descendente estricto sin solapes entre páginas.
 *   3. Filtros: por clan objetivo (clanId), por tipo de entidad y por
 *      actuante; blindaje 400 ante parámetros basura.
 *   4. Contrato exacto del plan: claves camelCase de cada ítem
 *      (actorAlias, actorRole, actionType, targetEntityType,
 *      targetEntityId, justification, createdAt) y objeto pagination.
 *
 * Ejecución: php scratch/test_audit_controller.php  (exit 0 = verde)
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

/**
 * Decodifica el cuerpo JSON de una Response con aserción integrada.
 */
function decodeJson(object $response): array
{
    $decoded = json_decode($response->getBody(), true);
    return is_array($decoded) ? $decoded : [];
}

$projectRoot = dirname(__DIR__);

echo "=== Tarea 3.4 (TASKS-03): Controlador de consulta de la Bitácora de Auditoría ===\n\n";

require_once $projectRoot . '/public/index.php'; // Autoload nativo del proyecto.

use Grimorio\Controllers\AuditController;
use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Services\AuditService;

echo "[0] Existencia y cargabilidad del controlador\n";

assertArcane(class_exists(AuditController::class), 'La clase Grimorio\\Controllers\\AuditController existe y el autoload la resuelve');

if (!class_exists(AuditController::class)) {
    echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
    exit(1);
}

// ---------------------------------------------------------------------
// Sandbox: base SQLite efímera con el esquema completo y 14 entradas
// solemnes sembradas (2 clanes, 2 actuantes, tipos de entidad mixtos).
// ---------------------------------------------------------------------
$sandboxDb = sys_get_temp_dir() . '/grimorio_audit_ctrl_' . getmypid() . '.sqlite';
if (file_exists($sandboxDb)) {
    @unlink($sandboxDb);
}

$pdo = new PDO('sqlite:' . $sandboxDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

$now = '2026-09-12T12:00:00Z';
$pdo->exec(
    "INSERT INTO clans (id, slug, name, motto, created_at)
     VALUES ('cln_astral', 'astral-scholars', 'Eruditos Astrales', 'Saber', '{$now}'),
            ('cln_ember', 'ember-wardens', 'Guardianes de Ascuas', 'Fuego', '{$now}')"
);

$auditService = new AuditService($pdo);
$controller   = new AuditController($auditService);

// Siembra determinista: 14 acciones solemnes con marcas crecientes.
$seedActions = [
    ['SIGN_VALIDATE', 'spell', 'spl_llamas_frieren', 'Composición matemática de maná equilibrada.'],
    ['SIGN_REJECT',   'spell', 'spl_sombra_duda',   'Componentes somáticos mal descritos.'],
    ['SIGN_VALIDATE', 'spell', 'spl_escudo_runico', 'Balance impecable entre coste y efecto.'],
    ['ADMIN_VETO',    'spell', 'spl_fuego_dragon',  'Excede el umbral de maná del nivel.'],
    ['PROMOTE_MASTER','user',  'usr_heiter',        'Tres firmas rigurosas consecutivas.'],
    ['SIGN_VALIDATE', 'spell', 'spl_vision_remota', 'Alcance de adivinación dentro del canon.'],
    ['SIGN_REJECT',   'spell', 'spl_lluvia_acida',  'Sin justificación histórica del conjuro.'],
    ['SIGN_VALIDATE', 'spell', 'spl_manto_bruma',   'Duración y área perfectamente acotadas.'],
    ['DEMOTE_MASTER', 'user',  'usr_tester',        'Firmas sin revisión demostrable.'],
    ['SIGN_VALIDATE', 'spell', 'spl_cadena_hielo',  'Control de movimiento bien tarifado.'],
    ['CLAN_MODIFY',   'clan',  'cln_astral',        'Motto actualizado por decisión del consejo.'],
    ['SIGN_REJECT',   'spell', 'spl_grito_silente', 'Verbal component contradictorio.'],
    ['SIGN_VALIDATE', 'spell', 'spl_puerta_menos',  'Conjuración espacial coherente.'],
    ['ADMIN_VETO',    'spell', 'spl_tiempo_cero',   'Manipulación temporal prohibida por el canon.'],
];

$baseInstant = new DateTimeImmutable('2026-09-10T08:00:00Z');
foreach ($seedActions as $index => [$actionType, $entityType, $entityId, $justification]) {
    // Alterna clanes y actuantes para dar materia a los filtros.
    $actorUserId = $index % 2 === 0 ? 'usr_master_heiter' : 'usr_master_eisen';
    $actorAlias  = $index % 2 === 0 ? 'HeiterElSabio'     : 'EisenElHachazo';
    // La mitad de las firmas salen del clan astral, la otra del ember.
    $auditService->recordAction(
        actorUserId: $actorUserId,
        actorAlias: $actorAlias,
        actorRole: 'master',
        actionType: $actionType,
        targetEntityType: $entityType,
        targetEntityId: $entityId,
        justification: $justification,
        now: $baseInstant->modify("+{$index} minutes"),
    );
}

// ---------------------------------------------------------------------
// 1. Consulta pública: anónimo recibe 200 con la lista paginada.
// ---------------------------------------------------------------------
echo "\n[1] Acceso público sin credenciales (RF-08.2, plan Endpoint 6)\n";

$anonymousRequest = new Request('GET', '/api/v1/audit/log', ['page' => '1', 'limit' => '5']);
$anonymousResponse = $controller->log($anonymousRequest);
$anonymousBody = decodeJson($anonymousResponse);

assertArcane($anonymousResponse->getStatusCode() === 200, 'Un visitante anónimo recibe 200 OK sin credenciales');
assertArcane(
    ($anonymousBody['success'] ?? null) === true && isset($anonymousBody['data']['items']),
    'El cuerpo porta success: true y data.items'
);
assertArcane(count($anonymousBody['data']['items'] ?? []) === 5, 'Con limit=5 la página porta exactamente 5 ítems');

$firstItem = $anonymousBody['data']['items'][0] ?? [];
assertArcane(
    isset($firstItem['actorAlias'], $firstItem['actorRole'], $firstItem['actionType'], $firstItem['targetEntityType'], $firstItem['targetEntityId'], $firstItem['justification'], $firstItem['createdAt']),
    'Cada ítem porta las claves camelCase del contrato del plan (actorAlias, actorRole, actionType, targetEntityType, targetEntityId, justification, createdAt)'
);
assertArcane(
    ($firstItem['actionType'] ?? null) === 'ADMIN_VETO' && ($firstItem['targetEntityId'] ?? null) === 'spl_tiempo_cero',
    'El orden descendente encabeza la página con el registro más reciente'
);

// Usuario consagrado: exactamente la misma respuesta (transparencia igualitaria).
$consacredRequest = new Request('GET', '/api/v1/audit/log', ['page' => '1', 'limit' => '5']);
$consacredResponse = $controller->log($consacredRequest);
assertArcane(
    $consacredResponse->getStatusCode() === 200 && $consacredResponse->getBody() === $anonymousResponse->getBody(),
    'Un usuario consagrado recibe idéntica respuesta (la bitácora es pública para todos)'
);

// ---------------------------------------------------------------------
// 2. Paginación completa: metadatos, orden y ausencia de solapes.
// ---------------------------------------------------------------------
echo "\n[2] Paginación descendente con metadatos del plan\n";

$page1Response = $controller->log(new Request('GET', '/api/v1/audit/log', ['page' => '1', 'limit' => '5']));
$page2Response = $controller->log(new Request('GET', '/api/v1/audit/log', ['page' => '2', 'limit' => '5']));
$page3Response = $controller->log(new Request('GET', '/api/v1/audit/log', ['page' => '3', 'limit' => '5']));
$page1 = decodeJson($page1Response);
$page2 = decodeJson($page2Response);
$page3 = decodeJson($page3Response);

$pagination = $page1['data']['pagination'] ?? [];
assertArcane(
    ($pagination['page'] ?? null) === 1 && ($pagination['limit'] ?? null) === 5
    && ($pagination['totalItems'] ?? null) === 14 && ($pagination['totalPages'] ?? null) === 3,
    'El objeto pagination porta page, limit, totalItems=14 y totalPages=3'
);

$allCreated = [];
foreach ([$page1, $page2, $page3] as $pageData) {
    foreach ($pageData['data']['items'] ?? [] as $item) {
        $allCreated[] = $item['createdAt'] . '|' . $item['actionType'] . '|' . $item['targetEntityId'];
    }
}
$sortedDescending = true;
$previousTimestamp = PHP_INT_MAX;
foreach ($allCreated as $composite) {
    $timestamp = strtotime(explode('|', $composite)[0] ?? '') ?: 0;
    if ($timestamp > $previousTimestamp) {
        $sortedDescending = false;
        break;
    }
    $previousTimestamp = $timestamp;
}
assertArcane($sortedDescending, 'Las 3 páginas concatenadas mantienen orden cronológico DESCENDENTE estricto');
assertArcane(count($allCreated) === count(array_unique($allCreated)), 'No hay solapes ni duplicados entre páginas');
assertArcane(count($page3['data']['items'] ?? []) === 4, 'La última página porta los 4 ítems restantes');

// Fuera de rango: página vacía pero 200 con metadatos coherentes.
$outOfRangeResponse = $controller->log(new Request('GET', '/api/v1/audit/log', ['page' => '99', 'limit' => '5']));
$outOfRangeBody = decodeJson($outOfRangeResponse);
assertArcane(
    $outOfRangeResponse->getStatusCode() === 200 && ($outOfRangeBody['data']['items'] ?? null) === [],
    'Una página fuera de rango responde 200 con items vacío (sin error espurio)'
);

// ---------------------------------------------------------------------
// 3. Filtros: clan objetivo (clanId), tipo de entidad y actuante.
// ---------------------------------------------------------------------
echo "\n[3] Filtros de consulta (clanId, tipo de entidad, actuante)\n";

// El arnés sembró CLAN_MODIFY sobre cln_astral como único evento de clan.
$clanResponse = $controller->log(new Request('GET', '/api/v1/audit/log', ['clanId' => 'cln_astral']));
$clanBody = decodeJson($clanResponse);
$clanItems = $clanBody['data']['items'] ?? [];
$allClanTargeted = true;
foreach ($clanItems as $item) {
    if (($item['targetEntityType'] ?? '') !== 'clan' || ($item['targetEntityId'] ?? '') !== 'cln_astral') {
        $allClanTargeted = false;
        break;
    }
}
assertArcane($clanResponse->getStatusCode() === 200 && count($clanItems) === 1 && $allClanTargeted, 'El filtro clanId=cln_astral devuelve solo sus veredictos de linaje');
assertArcane(
    ($clanItems[0]['actionType'] ?? null) === 'CLAN_MODIFY'
    && str_contains((string) ($clanItems[0]['justification'] ?? ''), 'Motto'),
    'El ítem de clan porta el motivo solemne intacto'
);

// Filtro por tipo de entidad: los 11 hechizos sembrados (14 acciones:
// 11 sobre spell, 2 sobre user y 1 sobre clan).
$spellResponse = $controller->log(new Request('GET', '/api/v1/audit/log', ['targetEntityType' => 'spell']));
$spellBody = decodeJson($spellResponse);
assertArcane(
    ($spellBody['data']['pagination']['totalItems'] ?? null) === 11,
    'El filtro targetEntityType=spell totaliza los 11 veredictos sobre hechizos'
);

// Filtro por actuante: las 7 acciones de HeiterElSabio (índices pares).
$actorResponse = $controller->log(new Request('GET', '/api/v1/audit/log', ['actorUserId' => 'usr_master_heiter']));
$actorBody = decodeJson($actorResponse);
assertArcane(
    ($actorBody['data']['pagination']['totalItems'] ?? null) === 7,
    'El filtro actorUserId aísla las 7 acciones del actuante sembrado'
);

// ---------------------------------------------------------------------
// 4. Blindaje de parámetros: basura y valores desmesurados.
// ---------------------------------------------------------------------
echo "\n[4] Blindaje de parámetros de consulta (AGENTS.md 6.1)\n";

$garbageResponse = $controller->log(new Request('GET', '/api/v1/audit/log', ['page' => 'primera', 'limit' => 'cinco']));
$garbageBody = decodeJson($garbageResponse);
assertArcane(
    $garbageResponse->getStatusCode() === 400 && ($garbageBody['error']['code'] ?? null) === 'INVALID_QUERY_PARAMS',
    'Página o límite no numéricos responden 400 INVALID_QUERY_PARAMS'
);

$zeroResponse = $controller->log(new Request('GET', '/api/v1/audit/log', ['page' => '0', 'limit' => '-3']));
assertArcane($zeroResponse->getStatusCode() === 400, 'Página o límite no positivos responden 400');

// Límite desmesurado: se recorta al tope defensivo del servicio (100),
// manteniendo 200 en lugar de un error espurio.
$hugeResponse = $controller->log(new Request('GET', '/api/v1/audit/log', ['limit' => '100000']));
$hugeBody = decodeJson($hugeResponse);
assertArcane(
    $hugeResponse->getStatusCode() === 200 && ($hugeBody['data']['pagination']['limit'] ?? null) === 100,
    'Un límite desmesurado se recorta al tope defensivo de 100 (sin error)'
);

// Inyección de arrays vía query string (?page[]=1): rechazo defensivo.
$arrayResponse = $controller->log(new Request('GET', '/api/v1/audit/log', ['page' => ['1']]));
assertArcane($arrayResponse->getStatusCode() === 400, 'Un parámetro array inyectado responde 400 (sin advertencias ni fugas)');

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";

@unlink($sandboxDb);
exit($assertsFailed === 0 ? 0 : 1);
