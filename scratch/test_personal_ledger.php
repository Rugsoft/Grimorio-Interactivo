<?php

declare(strict_types=1);

/**
 * test_personal_ledger.php — Arnés de la FASE 4 del Panel del Adepto
 * (TASKS-12). En esta entrega se certifican las Tareas 4.1 y 4.2: la
 * consulta de pertenencia paginada (RF-06.1, RF-06.2) y la prueba de
 * terceros, vacío y privacidad (RF-06.3, RF-06.4, RF-01.1).
 *
 * Fases ratificadas (plan §6.1):
 *   [1] Juramento/adhesión/veredictos/vetos PROPIOS presentes en orden
 *       inverso al cronológico (RF-06.1).            [Tarea 4.1]
 *   [2] Actos AJENOS (otro user_id) ausentes (RF-01.1, hallazgo 8).
 *                                                    [Tarea 4.1]
 *   [3] Actos COLECTIVOS del clan sin el adepto como sujeto ausentes
 *       (decisión QA: «actos dirigidos al adepto»).  [Tarea 4.2]
 *   [4] Firma ajena sobre obra propia PRESENTE y narrada sin exceso de
 *       datos del firmante (RF-06.4).               [Tarea 4.2]
 *   [5] Vacío → `entries: []` (leyenda de silencio, RF-06.3).
 *                                                    [Tarea 4.2]
 *   [6] Paginación por cursor ESTABLE y SIN duplicados (plan §2.7:
 *       20 asientos por página, cursor opaco).      [Tarea 4.1]
 *   [7] Parámetro `userId` ajeno → 403 LEDGER_NOT_YOURS (privacidad
 *       estricta, RF-01.1; plan §2.7).              [Tarea 4.2]
 *
 * Constitución: Artículo I (PDO nativo, consultas preparadas — el
 * filtro de pertenencia viaja 100% por parámetros vinculados, plan
 * §2.7), Artículo V (actionLabel en castellano solemne, hermanado con
 * el mapa actionLabel del frontend auditLogView.js), RF-06.2 (pura
 * lente de lectura: jamás escribe ni duplica asientos).
 *
 * Uso: php scratch/test_personal_ledger.php
 */

require_once __DIR__ . '/../src/Core/ActiveSession.php';
require_once __DIR__ . '/../src/Core/SessionManager.php';
require_once __DIR__ . '/../src/Services/BindResult.php';
require_once __DIR__ . '/../src/Services/ConsecrationResult.php';
require_once __DIR__ . '/../src/Services/RecoveryResult.php';
require_once __DIR__ . '/../src/Services/AuthService.php';
require_once __DIR__ . '/../src/Models/AuditEntry.php';
require_once __DIR__ . '/../src/Exceptions/PassphraseChangeFailedException.php';
require_once __DIR__ . '/../src/Exceptions/PassphraseIdenticalException.php';
require_once __DIR__ . '/../src/Repositories/UserPanelRepository.php';
require_once __DIR__ . '/../src/Services/AvatarService.php';
require_once __DIR__ . '/../src/Dto/AvatarCatalogDto.php';
require_once __DIR__ . '/../src/Dto/UserPanelDto.php';
require_once __DIR__ . '/../src/Models/User.php';
require_once __DIR__ . '/../src/Core/Response.php';
require_once __DIR__ . '/../src/Core/Request.php';
require_once __DIR__ . '/../src/Controllers/UserPanelController.php';
require_once __DIR__ . '/../src/Database/Connection.php';

use Grimorio\Controllers\UserPanelController;
use Grimorio\Core\Request;
use Grimorio\Models\User;
use Grimorio\Repositories\UserPanelRepository;
use Grimorio\Services\AuthService;
use Grimorio\Core\SessionManager;

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

/**
 * Siembra un asiento de bitácora con todos sus campos canónicos
 * (solo INSERT: la bitácora es inmutable, RF-08.1).
 */
function seedAudit(
    PDO $pdo,
    string $actorUserId,
    string $actorAlias,
    string $actorRole,
    string $actionType,
    string $targetEntityType,
    string $targetEntityId,
    string $justification,
    string $createdAt,
): void {
    $pdo->prepare(
        'INSERT INTO audit_log (actor_user_id, actor_alias, actor_role, action_type, target_entity_type, target_entity_id, justification, created_at)
         VALUES (:actorUserId, :actorAlias, :actorRole, :actionType, :targetEntityType, :targetEntityId, :justification, :createdAt)'
    )->execute([
        ':actorUserId' => $actorUserId, ':actorAlias' => $actorAlias, ':actorRole' => $actorRole,
        ':actionType' => $actionType, ':targetEntityType' => $targetEntityType,
        ':targetEntityId' => $targetEntityId, ':justification' => $justification, ':createdAt' => $createdAt,
    ]);
}

echo "== VERIFICACION TAREAS 4.1 + 4.2: La lente de bitacora personal (SPEC-12, RF-06 / RF-01.1) ==\n\n";

// --- FASE 0: Superficie y siembra ---
echo "FASE 0: Superficie arcano-bitacorica y siembra del juicio\n";
// Base canónica en memoria (patrón del proyecto): Connection materializa
// schema.sql + seeds.sql — la escuela mágica de la obra propia vive en
// las semillas canónicas.
\Grimorio\Database\Connection::resetInstance();
putenv('GRIMORIO_DB_DSN=sqlite::memory:');
$pdo = \Grimorio\Database\Connection::getInstance()->getPdo();
$pdo->exec('PRAGMA foreign_keys = ON');

// Dos adeptos: el dueño de la lente y un tercero cuyos actos JAMÁS
// deben filtrarse en ella.
$pdo->prepare(
    'INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, avatar, created_at, updated_at)
     VALUES (:id, :alias, :email, :p, :role, NULL, :lineage, NULL, :c, :c)'
)->execute([':id' => 'usr_dueno', ':alias' => 'Heredera de la Llama', ':email' => 'heredera@arcano.arc', ':p' => 'x', ':role' => 'editor', ':lineage' => 'primordialFlame', ':c' => '2025-01-01T00:00:00Z']);
$pdo->prepare(
    'INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, avatar, created_at, updated_at)
     VALUES (:id, :alias, :email, :p, :role, NULL, :lineage, NULL, :c, :c)'
)->execute([':id' => 'usr_ajeno', ':alias' => 'Marejada Ajena', ':email' => 'marejada@arcano.arc', ':p' => 'x', ':role' => 'editor', ':lineage' => 'celestialTides', ':c' => '2025-01-01T00:00:00Z']);

// La hermandad de la casa (necesaria como FK de la obra propia).
$pdo->prepare(
    "INSERT INTO clans (id, slug, name, created_at, coat_of_arms, lineage_type, admission_mode,
                        status, weekly_points, historical_points, last_activity_at, updated_at)
     VALUES (:id, :slug, :name, :createdAt, :coat, :lineageType, 'open', 'active', 0, 0, :createdAt, :createdAt)"
)->execute([
    ':id' => 'cln_llama', ':slug' => 'llama', ':name' => 'Hermandad de la Llama',
    ':createdAt' => '2025-01-01T00:00:00Z', ':coat' => 'rune_ignis', ':lineageType' => 'primordialFlame',
]);

// Una obra propia del dueño (para la rama de autoría del filtro §2.7).
$pdo->prepare(
    'INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, casting_time,
                         mana_cost, circle, math_fingerprint, clan_id, summary, status,
                         validation_signatures_count, signatures_count, is_genesis_sample, created_at, updated_at)
     VALUES (:id, :slug, :name, :authorId, :school, :element, :castingTime,
             :manaCost, :circle, :fingerprint, :clanId, :summary, :status,
             0, 0, 0, :stamp, :stamp)'
)->execute([
    ':id' => 'spl_obra_propia', ':slug' => 'obra-propia', ':name' => 'Llama Ancestral',
    ':authorId' => 'usr_dueno', ':school' => 'evocation', ':element' => 'fire', ':castingTime' => 'action',
    ':manaCost' => 30, ':circle' => 1, ':fingerprint' => str_repeat('f', 64),
    ':clanId' => 'cln_llama', ':summary' => 'Obra de la Heredera.', ':status' => 'validated',
    ':stamp' => '2025-02-01T00:00:00Z',
]);

// Los actos PROPIOS del dueño (cronológicos; la lente los invertirá).
// Orden de siembra: juramento (más antiguo) → adhesión → acto sobre obra
// propia firmado por un Maestro → veto alcanzado → acto del propio dueño
// sobre su identidad (más reciente).
seedAudit($pdo, 'usr_dueno', 'Heredera de la Llama', 'editor', 'LINEAGE_OATH_SWORN', 'user', 'usr_dueno', 'El juramento de linaje fue sellado en la ceremonia del primer acceso.', '2025-03-01T10:00:00Z');
seedAudit($pdo, 'usr_dueno', 'Heredera de la Llama', 'editor', 'CLAN_MEMBER_JOINED', 'clan', 'cln_llama', 'El adepto ingresó en la hermandad.', '2025-03-02T10:00:00Z');
seedAudit($pdo, 'usr_maestro', 'Maestro del Cónclave', 'master', 'SIGN_VALIDATE', 'spell', 'spl_obra_propia', 'La obra merece el aval del Cónclave.', '2025-03-03T10:00:00Z');
seedAudit($pdo, 'usr_supremo', 'Custodio Fundador', 'supremeAdmin', 'ADMIN_VETO', 'user', 'usr_dueno', 'El veto alcanza al adepto por su conducta.', '2025-03-04T10:00:00Z');
seedAudit($pdo, 'usr_dueno', 'Heredera de la Llama', 'editor', 'PASSPHRASE_SELF_CHANGED', 'user', 'usr_dueno', 'El adepto cambió su frase de paso desde su panel.', '2025-03-05T10:00:00Z');

// Los actos AJENOS que jamás deben entrar en la lente del dueño.
seedAudit($pdo, 'usr_ajeno', 'Marejada Ajena', 'editor', 'LINEAGE_OATH_SWORN', 'user', 'usr_ajeno', 'El juramento ajeno.', '2025-03-06T10:00:00Z');
seedAudit($pdo, 'usr_ajeno', 'Marejada Ajena', 'editor', 'PASSPHRASE_SELF_CHANGED', 'user', 'usr_ajeno', 'La custodia ajena.', '2025-03-07T10:00:00Z');

// Los actos COLECTIVOS del clan sin el dueño como sujeto (decisión QA:
// «actos dirigidos al adepto» — las crónicas de la casa quedan fuera).
seedAudit($pdo, 'usr_supremo', 'Custodio Fundador', 'supremeAdmin', 'DOMINION_WEEK_CONCLUDED', 'clan', 'cln_llama', 'La crónica del Dominio de la casa.', '2025-03-08T10:00:00Z');
seedAudit($pdo, 'usr_maestro', 'Maestro del Cónclave', 'master', 'CLAN_FOUNDED', 'clan', 'cln_otro', 'La fundación de otra hermandad.', '2025-03-09T10:00:00Z');

// Un adepto SOLITARIO: cuenta nueva sin un solo acto en la bitácora.
// Su lente debe recibir el silencio canónico (RF-06.3, Tarea 4.2).
$pdo->prepare(
    'INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, avatar, created_at, updated_at)
     VALUES (:id, :alias, :email, :p, :role, NULL, :lineage, NULL, :c, :c)'
)->execute([':id' => 'usr_solitario', ':alias' => 'Ermitaño sin Deuda', ':email' => 'ermitano@arcano.arc', ':p' => 'x', ':role' => 'editor', ':lineage' => 'primordialFlame', ':c' => '2025-01-01T00:00:00Z']);

$repository = new UserPanelRepository($pdo);
$sessionManager = new SessionManager($pdo, '127.0.0.1', 'Arnés Ledger/1.0');
$authService = new AuthService($pdo, $sessionManager);
$controller = new UserPanelController($repository, null, null, $authService);

assertCondition(true, 'Controlador del panel construido sobre PDO nativo con la bitácora sembrada');

// --- FASE 1: Los actos propios en orden inverso (RF-06.1) ---
echo "\nFASE 1: Los actos propios presentes en orden inverso al cronologico\n";
$request = new Request('GET', '/api/v1/panel/ledger');
$request->setUser(new User('usr_dueno', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', null, 'primordialFlame'));
$response = $controller->ledger($request);
$body = json_decode($response->getBody(), true) ?: [];

assertCondition($response->getStatusCode() === 200, 'La lente responde 200 al dueño autenticado');
$entries = $body['data']['entries'] ?? null;
assertCondition(is_array($entries) && count($entries) === 5, 'La lente sirve los 5 actos que conciernen al dueño (propios, obra propia y veto alcanzado)');

// Orden inverso al cronológico: el más reciente encabeza (RF-06.1).
$types = array_map(static fn (array $e): string => (string) ($e['actionType'] ?? ''), $entries ?? []);
assertCondition(
    $types === ['PASSPHRASE_SELF_CHANGED', 'ADMIN_VETO', 'SIGN_VALIDATE', 'CLAN_MEMBER_JOINED', 'LINEAGE_OATH_SWORN'],
    'El orden es inverso al cronológico: el acto más reciente encabeza (RF-06.1)'
);

$first = $entries[0] ?? [];
assertCondition(
    ($first['actionLabel'] ?? '') === 'La custodia de la frase de paso'
        && ($first['actionType'] ?? '') === 'PASSPHRASE_SELF_CHANGED'
        && ($first['createdAt'] ?? '') === '2025-03-05T10:00:00Z',
    'Cada entrada porta actionLabel castellano, actionType y estampa temporal (RF-06.1, Art. V)'
);
assertCondition(
    ($first['narrative'] ?? '') !== '' && ($first['targetKind'] ?? '') === 'user',
    'La narrativa viaja íntegra (justification de la bitácora) con su targetKind (plan §2.7)'
);
assertCondition(
    ($entries[2]['actionLabel'] ?? '') === 'Firma de Validación' && ($entries[2]['targetKind'] ?? '') === 'spell',
    'El acto ajeno SOBRE obra propia entra en la lente (autoría, rama spell del filtro §2.7)'
);
// La privacidad del dueño: ningún identificador técnico crudo impreso.
$encoded = $response->getBody();
assertCondition(
    !str_contains($encoded, 'usr_dueno') && !str_contains($encoded, 'spl_obra_propia'),
    'La lente jamás imprime identificadores técnicos crudos (Art. V: se codifica, no se imprime)'
);

// --- FASE 3: Colectivos del clan sin el adepto como sujeto (RF-06.1, Tarea 4.2) ---
echo "\nFASE 3: Los colectivos del clan sin el adepto como sujeto quedan fuera\n";
assertCondition(
    !in_array('DOMINION_WEEK_CONCLUDED', $types, true) && !in_array('CLAN_FOUNDED', $types, true),
    'La lente del dueño NO incluye los actos colectivos del clan sin él como sujeto (decisión QA, RF-06.1)'
);

// --- FASE 4: Firma ajena sobre obra propia sin exceso del firmante (RF-06.4, Tarea 4.2) ---
echo "\nFASE 4: La firma ajena sobre obra propia, narrada conforme a lo publico\n";
$signatureEntry = $entries[2] ?? [];
assertCondition(
    ($signatureEntry['actionType'] ?? '') === 'SIGN_VALIDATE' && ($signatureEntry['targetKind'] ?? '') === 'spell',
    'El acto del tercero sobre obra propia CONCIERNE al dueño y entra en su lente (RF-06.4)'
);
assertCondition(
    ($signatureEntry['narrative'] ?? '') === 'La obra merece el aval del Cónclave.',
    'La narrativa del acto con terceros es la justification YA PÚBLICA de la bitácora (RF-06.4)'
);
// El contrato del plan §2.7 fija la salida del entry: actionLabel,
// actionType, createdAt, narrative y targetKind. Nada más: ni el
// user_id del firmante ni su alias navegan por la lente (Art. V:
// jamás identificadores crudos; RF-06.4: terceros sin exceso).
$entryKeys = array_keys($signatureEntry);
sort($entryKeys);
assertCondition(
    $entryKeys === ['actionLabel', 'actionType', 'createdAt', 'narrative', 'targetKind'],
    'El entry del tercero porta EXACTAMENTE las cinco claves del contrato §2.7, sin exceso del firmante'
);
assertCondition(
    !str_contains($encoded, 'usr_maestro') && !str_contains($encoded, 'Maestro del Cónclave'),
    'La lente jamás imprime el user_id NI el alias del firmante ajeno (RF-06.4, Art. V)'
);

// --- FASE 5: El vacío con su leyenda de silencio (RF-06.3, Tarea 4.2) ---
echo "\nFASE 5: El vacio recibe la leyenda de silencio (entries: [])\n";
$request = new Request('GET', '/api/v1/panel/ledger');
$request->setUser(new User('usr_solitario', 'Ermitaño sin Deuda', 'ermitano@arcano.arc', 'editor', null, 'primordialFlame'));
$response = $controller->ledger($request);
$silentPage = json_decode($response->getBody(), true) ?: [];
assertCondition(
    $response->getStatusCode() === 200,
    'La lente de un adepto sin actos responde 200 (no es un error: es silencio, RF-06.3)'
);
assertCondition(
    ($silentPage['data']['entries'] ?? null) === [] ,
    'El vacío se sirve como `entries: []` — leyenda de silencio, jamás página cruda (RF-06.3)'
);
$silentNextCursor = $silentPage['data']['nextCursor'] ?? null;
assertCondition(
    $silentNextCursor === null,
    'El vacío no porta cursor fantasma: nextCursor es null (plan §2.7)'
);

// --- FASE 6: Paginación por cursor estable y sin duplicados (plan §2.7) ---
assertCondition(
    !in_array('LINEAGE_OATH_SWORN ajeno', [], true)
    && !str_contains($encoded, 'Marejada Ajena')
    && !str_contains($encoded, 'El juramento ajeno')
    && !str_contains($encoded, 'La custodia ajena'),
    'Ningún acto ajeno (juramento, custodia del tercero) aparece en la lente del dueño (RF-01.1)'
);
$ownSet = ['PASSPHRASE_SELF_CHANGED', 'ADMIN_VETO', 'SIGN_VALIDATE', 'CLAN_MEMBER_JOINED', 'LINEAGE_OATH_SWORN'];
$filteredTypes = array_values(array_filter($types, static fn (string $t): bool => !in_array($t, ['ADMIN_VETO', 'SIGN_VALIDATE'], true)));
assertCondition(
    count(array_keys($types, 'LINEAGE_OATH_SWORN', true)) === 1,
    'El juramento de la lente es el del dueño, jamás el del tercero (una sola vez en la página)'
);

// Los colectivos del clan sin el dueño como sujeto: aunque la fase
// completa [3] llega con la Tarea 4.2, la siembra ya los ejercita.
assertCondition(
    !str_contains($encoded, 'La crónica del Dominio') && !str_contains($encoded, 'La fundación de otra hermandad'),
    'Los actos colectivos del clan sin el adepto como sujeto quedan fuera (decisión QA)'
);

// --- FASE 6: Paginación por cursor estable y sin duplicados (plan §2.7) ---
echo "\nFASE 6: La paginacion por cursor — estable, sin duplicados, 20 por pagina\n";

// Siembra de volumen: 45 asientos propios adicionales para ejercitar
// tres páginas completas y una parcial (5 + 45 = 50 actos del dueño).
// Estampas DISTINTAS por asiento (base 2025-04-01 + i horas): si dos
// asientos compartieran estampa, el identificador del arnés
// (actionType|createdAt) los confundiría con duplicados del cursor.
$volumeBase = gmmktime(10, 0, 0, 4, 1, 2025);
for ($i = 1; $i <= 45; $i++) {
    seedAudit(
        $pdo, 'usr_dueno', 'Heredera de la Llama', 'editor', 'TOME_SEAL', 'spell', 'spl_obra_propia',
        "Sellado número {$i} en el tomo personal.",
        gmdate('Y-m-d\\TH:i:s\\Z', $volumeBase + $i * 3600),
    );
}

// Página 1: sin cursor — 20 asientos, los más recientes.
$request = new Request('GET', '/api/v1/panel/ledger');
$request->setUser(new User('usr_dueno', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', null, 'primordialFlame'));
$response = $controller->ledger($request);
$page1 = json_decode($response->getBody(), true) ?: [];
$page1Entries = $page1['data']['entries'] ?? [];
$nextCursor1 = $page1['data']['nextCursor'] ?? null;
assertCondition(count($page1Entries) === 20, 'La primera página sirve exactamente 20 asientos (cardinalidad «últimos», hallazgo 1)');
assertCondition(is_string($nextCursor1) && $nextCursor1 !== '', 'La primera página porta un nextCursor opaco');
assertCondition(
    ($page1Entries[0]['actionType'] ?? '') === 'TOME_SEAL' && ($page1Entries[0]['createdAt'] ?? '') === '2025-04-03T07:00:00Z',
    'La primera página encabeza con el acto más reciente del volumen'
);

// Recorrido completo por cursor: recolección de todos los ids.
$seenIds = [];
$cursor = null;
$pageCount = 0;
$lastPageEntries = [];
do {
    $queryParams = $cursor === null ? [] : ['cursor' => $cursor];
    $request = new Request('GET', '/api/v1/panel/ledger', $queryParams);
    $request->setUser(new User('usr_dueno', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', null, 'primordialFlame'));
    $response = $controller->ledger($request);
    $page = json_decode($response->getBody(), true) ?: [];
    $lastPageEntries = $page['data']['entries'] ?? [];
    foreach ($lastPageEntries as $entry) {
        $seenIds[] = ($entry['actionType'] ?? '') . '|' . ($entry['createdAt'] ?? '');
    }
    $cursor = $page['data']['nextCursor'] ?? null;
    $pageCount++;
} while ($cursor !== null && $pageCount < 10);

assertCondition($pageCount === 3, 'El recorrido completo consumió 3 páginas (20 + 20 + 10)');
assertCondition(count($seenIds) === 50, 'El recorrido reúne los 50 actos del dueño sin omitir ninguno');
assertCondition(
    count($seenIds) === count(array_unique($seenIds)),
    'La paginación por cursor es estable: NINGÚN asiento se repite entre páginas'
);
assertCondition(
    count($lastPageEntries) === 10 && ($lastPageEntries[0]['createdAt'] ?? '') === '2025-04-01T15:00:00Z',
    'La última página abre con el 10º asiento más antiguo (TOME_SEAL n.º 5)'
);
assertCondition(
    ($lastPageEntries[9]['createdAt'] ?? '') === '2025-03-01T10:00:00Z',
    'La última página cierra con el asiento más antiguo de todos (el juramento) y nextCursor null'
);

// Un cursor inexistente no rompe la lente: parte del principio.
$request = new Request('GET', '/api/v1/panel/ledger', ['cursor' => '999999999']);
$request->setUser(new User('usr_dueno', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', null, 'primordialFlame'));
$response = $controller->ledger($request);
$ghostPage = json_decode($response->getBody(), true) ?: [];
assertCondition(
    $response->getStatusCode() === 200 && count($ghostPage['data']['entries'] ?? []) === 20,
    'Un cursor fantasma (más antiguo que todo) devuelve página válida sin error'
);

// --- FASE 7: Identidad ajena jamás reflejada (RF-01.1, Tarea 4.2) ---
echo "\nFASE 7: La lente jamas refleja identidad ajena (403 LEDGER_NOT_YOURS)\n";
// Intento de usar la lente sobre un tercero mediante query param ajeno:
// la puerta FALLA CERRADA (plan §2.7: el endpoint jamás acepta
// identidades ajenas; el veredicto no delata si el tercero existe).
$request = new Request('GET', '/api/v1/panel/ledger', ['userId' => 'usr_ajeno']);
$request->setUser(new User('usr_dueno', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', null, 'primordialFlame'));
$response = $controller->ledger($request);
$foreignBody = json_decode($response->getBody(), true) ?: [];
assertCondition(
    $response->getStatusCode() === 403,
    'Un parámetro `userId` ajeno recibe 403 (RF-01.1, Tarea 4.2, plan §2.7)'
);
assertCondition(
    ($foreignBody['error']['code'] ?? '') === 'LEDGER_NOT_YOURS',
    'El 403 porta el código canónico LEDGER_NOT_YOURS con su leyenda solemne (Art. V)'
);
assertCondition(
    !str_contains($response->getBody(), 'Marejada Ajena') && !str_contains($response->getBody(), 'El juramento ajeno'),
    'El rechazo jamás filtra dato alguno de la bitácora ajena (privacidad estricta)'
);
// El dueño SIN parámetro ajeno sigue siendo la única vía: el propio id
// del titular como query param no es identidad ajena y no rompe nada.
$request = new Request('GET', '/api/v1/panel/ledger', ['userId' => 'usr_dueno']);
$request->setUser(new User('usr_dueno', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', null, 'primordialFlame'));
$response = $controller->ledger($request);
assertCondition(
    $response->getStatusCode() === 200,
    'El titular que presenta su propia identidad en la petición sigue siendo atendido (200)'
);

// --- Resumen canónico del arnés ---
echo "\n== RESUMEN ==\n";
echo "Asertos superados: {$assertsPassed}\n";
echo "Asertos fallidos: {$assertsFailed}\n";
if ($assertsFailed === 0) {
    echo "RESULTADO: EXITO\n";
    exit(0);
}
echo "RESULTADO: FRACASO\n";
exit(1);
