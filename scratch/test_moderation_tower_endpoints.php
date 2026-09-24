<?php

declare(strict_types=1);

/**
 * test_moderation_tower_endpoints.php — Verificación de la Tarea 3.2 de TASKS-08.
 *
 * Valida contra el criterio «Hecho cuando» de
 * specs/08-moderation-two-step.tasks.md:
 *   «La cola inyecta el flag `hasEthicalConflict` para el Maestro autenticado y
 *    las acciones de firma y objeción aplican las restricciones canónicas de
 *    longitud y permisos.»
 *
 * Estrategia: se despacha por la pila REAL de producción —`public/index.php` y
 * `buildRouter()`— sobre una base SQLite efímera en el directorio temporal del
 * sistema. Los linajes, las membresías y las obras se siembran con el esquema
 * canónico, y las obras llegan a su estado por los servicios de la Fase 2.
 *
 * Fases:
 *   [0] Superficie del controlador y registro de las cuatro rutas.
 *   [1] La cola de la Torre (RF-05.4, RF-03.1, RF-03.2): el flag ético ya
 *       resuelto, su causa canónica, los filtros y la paginación.
 *   [2] La firma de consagración (RF-02.1, RF-02.2): glosa de 250 y permisos.
 *   [3] La retractación (RF-02.4) y la liberación de plaza de hermandad.
 *   [4] El dictamen de objeción (RF-02.5, RF-02.6): umbral, cancelación de
 *       avales y retirada del Atrio.
 *   [5] Auditoría estática, Dogma Vanilla y Dualismo Lingüístico.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response/Router/PDO nativos.
 *   - Artículo V: identificadores en inglés camelCase; leyendas en castellano.
 *
 * Uso: php scratch/test_moderation_tower_endpoints.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$assertsPassed = 0;
$assertsFailed = 0;
/** @var list<string> */
$failures = [];

/** Aserta una condición y registra el resultado en la bitácora de consola. */
function assertCondition(bool $condition, string $description): void
{
    global $assertsPassed, $assertsFailed, $failures;
    if ($condition) {
        $assertsPassed++;
        echo "  [PASA] {$description}\n";
        return;
    }

    $assertsFailed++;
    $failures[] = $description;
    echo "  [FALLA] {$description}\n";
}

$projectRoot = dirname(__DIR__);
$controllerPath = $projectRoot . '/src/Controllers/MasterDeliberationController.php';
$frontControllerPath = $projectRoot . '/public/index.php';

echo "== VERIFICACION TAREA 3.2: La Torre de Deliberacion de Maestros ==\n\n";

// --- FASE 0: Superficie ---
echo "FASE 0: Superficie del controlador y registro de las rutas\n";
assertCondition(is_file($controllerPath), 'Existe src/Controllers/MasterDeliberationController.php');

if (!is_file($controllerPath)) {
    echo "\nRESULTADO: DENEGADO — falta el controlador de la Tarea 3.2 (fase roja del TDD).\n";
    exit(1);
}

$controllerSource = (string) file_get_contents($controllerPath);
$controllerHead = implode('', array_slice(file($controllerPath, FILE_IGNORE_NEW_LINES) ?: [], 0, 40));
assertCondition(str_contains($controllerHead, 'declare(strict_types=1);'), 'Declara strict_types en las primeras 40 lineas (AGENTS.md)');
/**
 * Raices de todos los imports de un fuente: el namespace propio y las clases
 * del estandar son las unicas admisibles (Articulo I).
 *
 * @return list<string>
 */
function importRootsOf(string $source): array
{
    preg_match_all('#^use ([A-Za-z_\\\\]+)#m', $source, $imports);

    $roots = [];
    foreach ($imports[1] as $importedSymbol) {
        $roots[] = explode('\\', $importedSymbol)[0];
    }

    return array_values(array_unique($roots));
}

assertCondition(str_contains($controllerSource, 'namespace Grimorio\\Controllers;'), 'Habita el espacio de nombres Grimorio\\Controllers');
$stdlibRoots = ['DateTimeImmutable', 'DateTimeZone', 'DateTime', 'PDO', 'PDOException', 'Throwable', 'InvalidArgumentException', 'RuntimeException', 'JsonSerializable', 'ArrayAccess'];
$foreignImports = array_diff(importRootsOf($controllerSource), array_merge(['Grimorio'], $stdlibRoots));
assertCondition(
    $foreignImports === []
    && preg_match('#https?://#', $controllerSource) !== 1
    && !str_contains($controllerSource, 'require_once '),
    'Todos sus imports son del propio proyecto o del estandar: cero dependencias externas (Articulo I)'
);

foreach (['queue', 'sign', 'retract', 'object'] as $endpointMethod) {
    assertCondition(
        str_contains($controllerSource, "function {$endpointMethod}("),
        "Expone el metodo canonico {$endpointMethod}()"
    );
}

$frontSource = (string) file_get_contents($frontControllerPath);
foreach ([
    "/api/v1/moderation/queue",
    "/api/v1/moderation/spells/{id}/sign",
    "/api/v1/moderation/spells/{id}/retract",
    "/api/v1/moderation/spells/{id}/object",
] as $routePath) {
    assertCondition(str_contains($frontSource, $routePath), "Registra la ruta {$routePath}");
}

// --- Base efímera y pila de producción ---
$databasePath = sys_get_temp_dir() . '/grimorio_tower_endpoints_' . getmypid() . '.sqlite';
@unlink($databasePath);
putenv('GRIMORIO_DB_DSN=sqlite:' . $databasePath);

require_once $frontControllerPath;
$router = buildRouter();

use Grimorio\Core\Request;
use Grimorio\Database\Connection;
use Grimorio\Models\User;
use Grimorio\Services\ModerationWorkflowService;

$pdo = Connection::getInstance()->getPdo();
$pdo->exec((string) file_get_contents($projectRoot . '/sql/08_moderation_schema.sql'));

$CLOCK = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$STAMP = $CLOCK->format('Y-m-d\TH:i:s\Z');
$TEN_DAYS_AGO = $CLOCK->modify('-10 days')->format('Y-m-d\TH:i:s\Z');
$FORTY_FIVE_DAYS_AGO = $CLOCK->modify('-45 days')->format('Y-m-d\TH:i:s\Z');
$FINGERPRINT = str_repeat('a', 64);

/**
 * Despacha por el router de producción, con usuario y cuerpo inyectables.
 *
 * @param array<string, mixed>|null $payload
 */
function dispatch(string $method, string $uri, ?User $user = null, ?array $payload = null): object
{
    global $router;

    $path = (string) (parse_url($uri, PHP_URL_PATH) ?: $uri);
    $queryString = (string) (parse_url($uri, PHP_URL_QUERY) ?: '');
    $queryParams = [];
    if ($queryString !== '') {
        parse_str($queryString, $queryParams);
    }

    $rawBody = $payload === null ? null : (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    $headers = $rawBody === null ? [] : ['Content-Type' => 'application/json'];

    $request = new Request($method, $path, $queryParams, $headers, $rawBody);
    if ($user !== null) {
        $request->setUser($user);
    }

    return $router->dispatch($request);
}

/** @return array<string, mixed> */
function payloadOf(object $response): array
{
    $decoded = json_decode((string) $response->getBody(), true);

    return is_array($decoded) ? $decoded : [];
}

function statusOf(object $response): int
{
    return (int) $response->getStatusCode();
}

function errorCodeOf(object $response): string
{
    return (string) (payloadOf($response)['error']['code'] ?? '');
}

/** Inscribe un mago con su rango técnico y su espejo de linaje. */
function forgeUser(PDO $pdo, string $userId, string $alias, string $role, ?string $clanId, string $createdAt): void
{
    $statement = $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
         VALUES (:id, :alias, :email, :hash, :role, :clanId, :createdAt, :createdAt)'
    );
    $statement->execute([
        ':id'        => $userId,
        ':alias'     => $alias,
        ':email'     => $userId . '@arcano.arc',
        ':hash'      => 'x',
        ':role'      => $role,
        ':clanId'    => $clanId,
        ':createdAt' => $createdAt,
    ]);
}

/** Inscribe una hermandad con su linaje rector. */
function forgeClan(PDO $pdo, string $clanId, string $lineage, string $name): void
{
    $statement = $pdo->prepare(
        'INSERT INTO clans (id, slug, name, motto, created_at, coat_of_arms, lineage_type, admission_mode, status,
                            weekly_points, historical_points, last_activity_at, updated_at)
         VALUES (:id, :slug, :name, :motto, :createdAt, :arms, :lineage, :mode, :status, 0, 0, :createdAt, :createdAt)'
    );
    $statement->execute([
        ':id'        => $clanId,
        ':slug'      => strtolower(str_replace('_', '-', $clanId)),
        ':name'      => $name,
        ':motto'     => 'Lema de prueba del arnes de la Torre.',
        ':createdAt' => '2026-01-01T00:00:00Z',
        ':arms'      => 'rune_' . $clanId,
        ':lineage'   => $lineage,
        ':mode'      => 'open',
        ':status'    => 'active',
    ]);
}

/** Inscribe una membresía del historial (viva si `leftAt` es null). */
function forgeMembership(
    PDO $pdo,
    string $memberId,
    string $clanId,
    string $userId,
    string $joinedAt,
    ?string $leftAt = null,
): void {
    $statement = $pdo->prepare(
        'INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at)
         VALUES (:id, :clanId, :userId, :role, :joinedAt, :leftAt)'
    );
    $statement->execute([
        ':id'       => $memberId,
        ':clanId'   => $clanId,
        ':userId'   => $userId,
        ':role'     => 'adept',
        ':joinedAt' => $joinedAt,
        ':leftAt'   => $leftAt,
    ]);
}

/** Inscribe un conjuro en borrador. */
function forgeSpell(PDO $pdo, string $spellId, string $name, string $authorId, string $clanId, string $createdAt): void
{
    $statement = $pdo->prepare(
        'INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, mana_cost, circle,
                             math_fingerprint, clan_id, summary, created_at, updated_at, status, signatures_count,
                             damage, range_type, has_verbal)
         VALUES (:id, :slug, :name, :authorId, \'evocation\', \'fire\', 100, 1,
                 :fingerprint, :clanId, :summary, :createdAt, :createdAt, \'draft\', 0,
                 20, \'short\', 1)'
    );
    $statement->execute([
        ':id'          => $spellId,
        ':slug'        => $spellId,
        ':name'        => $name,
        ':authorId'    => $authorId,
        ':fingerprint' => str_repeat('a', 64),
        ':clanId'      => $clanId,
        ':summary'     => 'Obra de prueba del arnes de la Torre de Deliberacion.',
        ':createdAt'   => $createdAt,
    ]);
}

/** Recupera el usuario canónico del plano arcano. */
function loadUser(PDO $pdo, string $userId): User
{
    $statement = $pdo->prepare(
        'SELECT id, alias, email, role, clan_id, password_hash, created_at, updated_at FROM users WHERE id = :id'
    );
    $statement->execute([':id' => $userId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException("El arnes no encontro al mago {$userId}.");
    }

    return User::fromDatabaseRow($row);
}

// --- Semilla: hermandades, magos, historial de membresía y obras ---
forgeClan($pdo, 'cln_llama', 'primordialFlame', 'Hermandad de la Llama');
forgeClan($pdo, 'cln_marea', 'celestialTides', 'Hermandad de la Marea');

forgeUser($pdo, 'usr_autor_uno', 'Autor Primero', 'editor', 'cln_llama', $STAMP);
forgeUser($pdo, 'usr_autor_dos', 'Autor Segundo', 'editor', 'cln_llama', $STAMP);
forgeUser($pdo, 'usr_autor_tres', 'Autor Tercero', 'editor', 'cln_llama', $STAMP);
forgeUser($pdo, 'usr_autor_cuatro', 'Autor Cuarto', 'editor', 'cln_llama', $STAMP);
forgeUser($pdo, 'usr_lector', 'Lector del Arnes', 'reader', null, $STAMP);
forgeUser($pdo, 'usr_supremo', 'Administradora Suprema', 'supremeAdmin', null, $STAMP);

// Maestros: un ermitaño neutral, uno del linaje de la obra (vivo), uno que lo
// habitó hace diez días, dos de una hermandad ajena y otro ermitaño.
forgeUser($pdo, 'usr_neutral', 'Maestro Neutral', 'master', null, $STAMP);
forgeUser($pdo, 'usr_llama', 'Maestro de la Llama', 'master', 'cln_llama', $STAMP);
forgeUser($pdo, 'usr_hist', 'Maestra Historica', 'master', null, $STAMP);
forgeUser($pdo, 'usr_marea_uno', 'Maestro de la Marea', 'master', 'cln_marea', $STAMP);
forgeUser($pdo, 'usr_marea_dos', 'Maestra de la Marea', 'master', 'cln_marea', $STAMP);
forgeUser($pdo, 'usr_ermitano_dos', 'Ermitano Segundo', 'master', null, $STAMP);

forgeMembership($pdo, 'mem_llama_viva', 'cln_llama', 'usr_llama', '2026-01-01T00:00:00Z');
forgeMembership($pdo, 'mem_llama_hist', 'cln_llama', 'usr_hist', '2026-01-01T00:00:00Z', $TEN_DAYS_AGO);
forgeMembership($pdo, 'mem_marea_uno', 'cln_marea', 'usr_marea_uno', '2026-01-01T00:00:00Z');
forgeMembership($pdo, 'mem_marea_dos', 'cln_marea', 'usr_marea_dos', '2026-01-01T00:00:00Z');

forgeSpell($pdo, 'spl_firma', 'Ascua de la Firma', 'usr_autor_uno', 'cln_llama', '2026-09-01T08:00:00Z');
forgeSpell($pdo, 'spl_plural', 'Ascua de la Pluralidad', 'usr_autor_dos', 'cln_llama', '2026-09-02T08:00:00Z');
forgeSpell($pdo, 'spl_dictamen', 'Ascua del Dictamen', 'usr_autor_tres', 'cln_llama', '2026-09-03T08:00:00Z');
forgeSpell($pdo, 'spl_consagra', 'Ascua de la Consagracion', 'usr_autor_cuatro', 'cln_llama', '2026-09-04T08:00:00Z');
forgeSpell($pdo, 'spl_del_maestro', 'Ascua del Propio Maestro', 'usr_llama', 'cln_llama', '2026-09-05T08:00:00Z');
forgeSpell($pdo, 'spl_borrador', 'Ascua En Gestacion', 'usr_autor_uno', 'cln_llama', '2026-09-06T08:00:00Z');

$workflow = new ModerationWorkflowService($pdo);
$authorOf = static function (PDO $pdo, string $spellId): string {
    $statement = $pdo->prepare('SELECT author_id FROM spells WHERE id = :spellId');
    $statement->execute([':spellId' => $spellId]);

    return (string) $statement->fetchColumn();
};
foreach (['spl_firma', 'spl_plural', 'spl_dictamen', 'spl_consagra', 'spl_del_maestro'] as $spellId) {
    $workflow->submitToModeration($spellId, $authorOf($pdo, $spellId));
}

// Un borrador con expediente —obra creada antes de la Torre— para probar el 409
// de lo que no yace en deliberación: se devuelve a `draft` en sus DOS moradas
// (autoridad y espejo) para no dejar el ciclo de vida divergente.
$workflow->submitToModeration('spl_borrador', 'usr_autor_uno');
$pdo->exec("UPDATE spell_reviews SET status = 'draft' WHERE spell_id = 'spl_borrador'");
$pdo->exec("UPDATE spells SET status = 'draft' WHERE id = 'spl_borrador'");

$neutral = loadUser($pdo, 'usr_neutral');
$llama = loadUser($pdo, 'usr_llama');
$hist = loadUser($pdo, 'usr_hist');
$mareaUno = loadUser($pdo, 'usr_marea_uno');
$mareaDos = loadUser($pdo, 'usr_marea_dos');
$ermitanoDos = loadUser($pdo, 'usr_ermitano_dos');
$author = loadUser($pdo, 'usr_autor_uno');
$reader = loadUser($pdo, 'usr_lector');
$supreme = loadUser($pdo, 'usr_supremo');

// --- FASE 1: La cola de la Torre ---
echo "\nFASE 1: La cola de la Torre de Deliberacion (RF-05.4, RF-03.1, RF-03.2)\n";

assertCondition(statusOf(dispatch('GET', '/api/v1/moderation/queue')) === 401, 'Sin vinculo arcano la cola responde 401');
assertCondition(statusOf(dispatch('GET', '/api/v1/moderation/queue', $reader)) === 403, 'Un lector no entra en la Torre: 403');
assertCondition(errorCodeOf(dispatch('GET', '/api/v1/moderation/queue', $author)) === 'INSUFFICIENT_RANK_TO_JUDGE', 'Un editor recibe INSUFFICIENT_RANK_TO_JUDGE (RF-02.1)');

$neutralQueue = dispatch('GET', '/api/v1/moderation/queue', $neutral);
$neutralPayload = payloadOf($neutralQueue);
assertCondition(statusOf($neutralQueue) === 200, 'El Maestro recibe la cola con 200');
assertCondition(
    (int) ($neutralPayload['data']['canon']['maxGlossLength'] ?? 0) === 250
    && (int) ($neutralPayload['data']['canon']['minObjectionLength'] ?? 0) === 20
    && (int) ($neutralPayload['data']['canon']['signaturesRequired'] ?? 0) === 3,
    'Los umbrales del Conclave viajan declarados por su autoridad (RF-02.2, RF-02.5)'
);
assertCondition(statusOf(dispatch('GET', '/api/v1/moderation/queue', $supreme)) === 200, 'El Administrador Supremo contempla la cola (RF-03.5)');

$queueSpellIds = array_column($neutralPayload['data']['items'] ?? [], 'spellId');
assertCondition(in_array('spl_firma', $queueSpellIds, true) && in_array('spl_consagra', $queueSpellIds, true), 'La cola exhibe las obras en deliberacion');
assertCondition(!in_array('spl_borrador', $queueSpellIds, true), 'La cola excluye el borrador privado (RF-05.4)');
assertCondition(
    ($neutralPayload['data']['items'][0]['hasEthicalConflict'] ?? true) === false
    && array_key_exists('ethicalVeto', $neutralPayload['data']['items'][0] ?? [])
    && $neutralPayload['data']['items'][0]['ethicalVeto'] === null,
    'Un ermitano neutral no arrastra conflicto alguno (RF-03.1)'
);
$queueCenso = (int) $pdo->query("SELECT COUNT(*) FROM spell_reviews WHERE status = 'experimental'")->fetchColumn();
assertCondition(
    (int) ($neutralPayload['data']['pagination']['totalItems'] ?? -1) === $queueCenso,
    'El censo de la cola coincide con las obras en deliberacion'
);

$llamaPayload = payloadOf(dispatch('GET', '/api/v1/moderation/queue', $llama));
$llamaBySpell = [];
foreach ($llamaPayload['data']['items'] ?? [] as $queueItem) {
    $llamaBySpell[(string) $queueItem['spellId']] = $queueItem;
}
assertCondition(
    ($llamaBySpell['spl_firma']['hasEthicalConflict'] ?? false) === true
    && (string) ($llamaBySpell['spl_firma']['ethicalVeto']['code'] ?? '') === 'clanIncompatibility',
    'El Maestro del linaje de la obra recibe el flag con su causa canonica (RF-03.1)'
);
assertCondition(
    (string) ($llamaBySpell['spl_firma']['ethicalVeto']['legend'] ?? '') === 'Conflicto de intereses: No es lícito a un Maestro juzgar las obras nacidas bajo su propio estandarte, linajes recientes o propia pluma',
    'La leyenda ceremonial del veto viaja en noble castellano (RF-03.3, Art. IV)'
);
assertCondition(
    (string) ($llamaBySpell['spl_del_maestro']['ethicalVeto']['code'] ?? '') === 'ownAuthorship',
    'La propia pluma se distingue del conflicto de linaje (RF-03.2)'
);

$histPayload = payloadOf(dispatch('GET', '/api/v1/moderation/queue', $hist));
$histVetoes = array_column(array_column($histPayload['data']['items'] ?? [], 'ethicalVeto'), 'code');
assertCondition(
    in_array('clanIncompatibility', $histVetoes, true),
    'Quien habito el linaje hace diez dias tambien queda vetado (ventana de treinta, RF-03.1)'
);
$mareaPayload = payloadOf(dispatch('GET', '/api/v1/moderation/queue', $mareaUno));
assertCondition(
    ($mareaPayload['data']['items'][0]['hasEthicalConflict'] ?? true) === false,
    'Un Maestro de hermandad ajena juzga sin conflicto'
);

$filteredQueue = payloadOf(dispatch('GET', '/api/v1/moderation/queue?element=fire&school=evocation', $neutral));
assertCondition(count($filteredQueue['data']['items'] ?? []) === $queueCenso, 'Los filtros de afinidad y escuela acotan la cola (RF-05.4)');
$emptyQueue = payloadOf(dispatch('GET', '/api/v1/moderation/queue?element=plasma', $neutral));
assertCondition(($emptyQueue['data']['items'] ?? null) === [], 'Una afinidad ajena al Codice no casa con ninguna obra');
$signedQueue = payloadOf(dispatch('GET', '/api/v1/moderation/queue?minSignatures=1', $neutral));
assertCondition(
    (int) ($signedQueue['data']['pagination']['totalItems'] ?? -1) === 0,
    'El filtro de firmas minimas acota el censo a las obras ya avaladas'
);
$impossibleThreshold = dispatch('GET', '/api/v1/moderation/queue?minSignatures=99', $neutral);
assertCondition(
    statusOf($impossibleThreshold) === 400 && errorCodeOf($impossibleThreshold) === 'INVALID_QUERY_PARAMS',
    'Un umbral por encima del techo de tres firmas responde 400, jamas interrumpe la Torre'
);
assertCondition(statusOf(dispatch('GET', '/api/v1/moderation/queue?perPage=abc', $neutral)) === 400, 'Una paginacion no entera responde 400');

// --- FASE 2: La firma de consagración ---
echo "\nFASE 2: La firma de consagracion (RF-02.1, RF-02.2)\n";

assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_firma/sign', null, [])) === 401, 'Sin vinculo arcano la firma responde 401');
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_firma/sign', $author, [])) === 403, 'Un editor no firma: 403');
assertCondition(errorCodeOf(dispatch('POST', '/api/v1/moderation/spells/spl_firma/sign', $author, [])) === 'INSUFFICIENT_RANK_TO_JUDGE', 'El 403 declara INSUFFICIENT_RANK_TO_JUDGE');
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_fantasma/sign', $neutral, [])) === 404, 'Una obra que no consta en la Torre responde 404');
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_borrador/sign', $neutral, [])) === 409, 'Firmar un borrador responde 409 (solo se juzga lo que yace en deliberacion)');
assertCondition(errorCodeOf(dispatch('POST', '/api/v1/moderation/spells/spl_borrador/sign', $neutral, [])) === 'SPELL_NOT_IN_REVIEW', 'El 409 declara SPELL_NOT_IN_REVIEW');

$gloss251 = str_repeat('g', 251);
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_firma/sign', $neutral, ['ceremonialGloss' => $gloss251])) === 400, 'Una glosa de 251 caracteres responde 400 (RF-02.2)');
assertCondition(errorCodeOf(dispatch('POST', '/api/v1/moderation/spells/spl_firma/sign', $neutral, ['ceremonialGloss' => $gloss251])) === 'GLOSS_TOO_LONG', 'El 400 declara GLOSS_TOO_LONG');

$vetoVerdict = dispatch('POST', '/api/v1/moderation/spells/spl_firma/sign', $llama, []);
assertCondition(statusOf($vetoVerdict) === 403, 'El Maestro del linaje de la obra no puede firmarla: 403');
assertCondition(errorCodeOf($vetoVerdict) === 'CONSTITUTIONAL_ETHICS_VETO', 'El 403 declara CONSTITUTIONAL_ETHICS_VETO (Art. III)');
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_firma/sign', $hist, [])) === 403, 'Quien la habito hace diez dias tampoco firma: 403');

$gloss250 = str_repeat('g', 250);
$firstSign = dispatch('POST', '/api/v1/moderation/spells/spl_firma/sign', $neutral, ['ceremonialGloss' => $gloss250]);
$firstPayload = payloadOf($firstSign);
assertCondition(statusOf($firstSign) === 200, 'La firma legitima responde 200');
assertCondition(
    (int) ($firstPayload['data']['review']['signaturesCount'] ?? -1) === 1
    && (string) ($firstPayload['data']['review']['signaturesIndicator'] ?? '') === '1/3',
    'El contador se recuenta desde las firmas vivas y viaja como «1/3»'
);
assertCondition(($firstPayload['data']['consecrated'] ?? true) === false, 'La primera rubrica no consagra la obra');
assertCondition(
    (string) $pdo->query("SELECT ceremonial_gloss FROM master_signatures WHERE spell_id = 'spl_firma'")->fetchColumn() === $gloss250,
    'La glosa de 250 caracteres se conserva INTEGRA en la fila (RF-02.2)'
);
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_firma/sign', $neutral, [])) === 409, 'Reestampar el mismo aval responde 409');
assertCondition(errorCodeOf(dispatch('POST', '/api/v1/moderation/spells/spl_firma/sign', $neutral, [])) === 'ALREADY_SIGNED', 'El 409 declara ALREADY_SIGNED (RF-02.4)');
$signedNow = payloadOf(dispatch('GET', '/api/v1/moderation/queue?minSignatures=1', $neutral));
assertCondition(
    (int) ($signedNow['data']['pagination']['totalItems'] ?? -1) === 1
    && (string) ($signedNow['data']['items'][0]['spellId'] ?? '') === 'spl_firma',
    'El filtro de firmas minimas acota el censo a la obra ya avalada'
);

// La pluralidad de hermandades sobre la obra de la pluralidad.
$pluralFirst = dispatch('POST', '/api/v1/moderation/spells/spl_plural/sign', $mareaUno, []);
assertCondition(statusOf($pluralFirst) === 200, 'Un Maestro de hermandad ajena firma sin traba');
$pluralSecond = dispatch('POST', '/api/v1/moderation/spells/spl_plural/sign', $mareaDos, []);
assertCondition(statusOf($pluralSecond) === 409, 'Dos voces del mismo estandarte responden 409 (RF-02.1)');
assertCondition(errorCodeOf($pluralSecond) === 'CLAN_PLURALITY_VIOLATION', 'El 409 declara CLAN_PLURALITY_VIOLATION');

// --- FASE 3: La retractación ---
echo "\nFASE 3: La retractacion voluntaria (RF-02.4)\n";

assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_plural/retract', null, [])) === 401, 'Sin vinculo arcano la retractacion responde 401');
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_plural/retract', $author, [])) === 403, 'Un editor no retracta: 403');
$noSignature = dispatch('POST', '/api/v1/moderation/spells/spl_plural/retract', $neutral, []);
assertCondition(statusOf($noSignature) === 400, 'Retractar sin aval vivo responde 400');
assertCondition(errorCodeOf($noSignature) === 'NO_ACTIVE_SIGNATURE', 'El 400 declara NO_ACTIVE_SIGNATURE');

$retracted = dispatch('POST', '/api/v1/moderation/spells/spl_plural/retract', $mareaUno, ['reason' => 'Duda razonable sobre la resonancia elemental.']);
$retractedPayload = payloadOf($retracted);
assertCondition(statusOf($retracted) === 200, 'La retractacion legitima responde 200');
assertCondition(
    (int) ($retractedPayload['data']['signaturesCount'] ?? -1) === 0
    && (string) ($retractedPayload['data']['signaturesIndicator'] ?? '') === '0/3',
    'El contador desciende al censo real y viaja como «0/3» (RF-02.4)'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_plural' AND is_revoked = 1 AND revocation_reason = 'retracted'")->fetchColumn() === 1,
    'El aval cae con el motivo canonico `retracted` y su memoria'
);
assertCondition(
    statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_plural/sign', $mareaDos, [])) === 200,
    'La plaza de hermandad queda libre para otro Maestro (RF-02.4)'
);

// --- FASE 4: El dictamen de objeción ---
echo "\nFASE 4: El Dictamen de Objecion (RF-02.5, RF-02.6)\n";

assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_dictamen/object', null, [])) === 401, 'Sin vinculo arcano el dictamen responde 401');
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_dictamen/object', $author, [])) === 403, 'Un editor no dicta: 403');

$briefObjection = dispatch('POST', '/api/v1/moderation/spells/spl_dictamen/object', $neutral, ['objectionReason' => str_repeat('o', 19)]);
assertCondition(statusOf($briefObjection) === 422, 'Diecinueve caracteres responden 422 (RF-02.5)');
assertCondition(errorCodeOf($briefObjection) === 'OBJECTION_TOO_BRIEF', 'El 422 declara OBJECTION_TOO_BRIEF');
assertCondition(
    statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_dictamen/object', $neutral, ['objectionReason' => str_repeat(' ', 40)])) === 422,
    'Cuarenta espacios no son una justificacion: 422'
);
assertCondition(
    statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_dictamen/object', $neutral, ['objectionReason' => str_repeat('o', 20)])) === 200,
    'El umbral es inclusivo: veinte caracteres se admiten'
);

$selfObjection = dispatch('POST', '/api/v1/moderation/spells/spl_del_maestro/object', $llama, ['objectionReason' => str_repeat('o', 30)]);
assertCondition(statusOf($selfObjection) === 403, 'La propia pluma no se objeta: 403 (RF-03.2)');
assertCondition(errorCodeOf($selfObjection) === 'SELF_SIGNING_PROHIBITED', 'El 403 declara SELF_SIGNING_PROHIBITED');
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM objection_verdicts WHERE spell_id = 'spl_del_maestro'")->fetchColumn() === 0,
    'El veto a la propia pluma no inscribe dictamen alguno'
);

// Segunda obra vetada, ahora con un aval vivo que hay que cancelar.
$objectionReason = 'La descripcion lirica presenta anacronismos manifiestos que vulneran el Velo Arcano.';
$rejected = dispatch('POST', '/api/v1/moderation/spells/spl_plural/object', $ermitanoDos, ['objectionReason' => $objectionReason]);
$rejectedPayload = payloadOf($rejected);
assertCondition(statusOf($rejected) === 200, 'El dictamen legitimo responde 200');
assertCondition(($rejectedPayload['data']['review']['status'] ?? '') === 'rejected', 'La obra queda vetada de inmediato (RF-02.5)');
assertCondition(
    (int) ($rejectedPayload['data']['review']['signaturesCount'] ?? -1) === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_plural' AND is_revoked = 1 AND revocation_reason = 'review_rejected'")->fetchColumn() === 1,
    'El dictamen cancela TODOS los avales previos con su motivo canonico (RF-02.6)'
);
assertCondition(
    (string) ($rejectedPayload['data']['verdict']['objectionReason'] ?? '') === $objectionReason,
    'El dictamen viaja INTEGRO en la respuesta y queda inscrito (RF-06.2)'
);
$hallAfter = payloadOf(dispatch('GET', '/api/v1/moderation/experimental'));
assertCondition(
    !in_array('spl_plural', array_column($hallAfter['data']['items'] ?? [], 'spellId'), true),
    'La obra vetada se retira del Atrio de Pruebas en el mismo gesto (RF-02.5)'
);
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_plural/object', $ermitanoDos, ['objectionReason' => $objectionReason])) === 409, 'Objetar una obra ya vetada responde 409');
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_fantasma/object', $neutral, ['objectionReason' => $objectionReason])) === 404, 'Objetar una obra inexistente responde 404');

// La consagracion: tercera firma, y la retractacion posterior es irrevocable.
echo "\n";
$dispatchSign = static fn (User $master): object => dispatch('POST', '/api/v1/moderation/spells/spl_consagra/sign', $master, []);

assertCondition(statusOf($dispatchSign($neutral)) === 200, 'Primera rubrica de la consagracion estampada');
assertCondition(statusOf($dispatchSign($mareaUno)) === 200, 'Segunda rubrica de un linaje distinto');
$consecrating = $dispatchSign($ermitanoDos);
$consecratingPayload = payloadOf($consecrating);
assertCondition(statusOf($consecrating) === 200, 'Tercera rubrica estampada');
assertCondition(
    ($consecratingPayload['data']['consecrated'] ?? false) === true
    && (string) ($consecratingPayload['data']['review']['status'] ?? '') === 'validated'
    && (int) ($consecratingPayload['data']['review']['signaturesCount'] ?? -1) === 3,
    'La tercera firma consagra la obra en 3/3 (RF-02.1, RF-02.3)'
);
$irrevocable = dispatch('POST', '/api/v1/moderation/spells/spl_consagra/retract', $neutral, []);
assertCondition(statusOf($irrevocable) === 409, 'Retractarse tras la consagracion responde 409 (RF-02.4)');
assertCondition(errorCodeOf($irrevocable) === 'SIGNATURE_IRREVOCABLE', 'El 409 declara SIGNATURE_IRREVOCABLE');

// --- FASE 5: Auditoría estática y Dogma ---
echo "\nFASE 5: Auditoria estatica, Dogma Vanilla y Dualismo Linguistico\n";

assertCondition(
    !str_contains($controllerSource, 'new PDO')
    && preg_match('/\b(INSERT INTO|UPDATE |DELETE FROM)\b/', $controllerSource) !== 1,
    'El controlador no abre conexiones ni escribe SQL: delega en los servicios (Art. I)'
);
assertCondition(
    !str_contains($controllerSource, '30 days')
    && !str_contains($controllerSource, 'clan_members')
    && str_contains($controllerSource, 'evaluationVetoCode'),
    'El veto del Articulo III se PREGUNTA a su autoridad, no se reimplementa (Art. III)'
);
assertCondition(
    str_contains($controllerSource, 'CONFLICT_OF_INTEREST_MESSAGE'),
    'La leyenda del veto se toma de la fuente canonica (RF-03.3)'
);
assertCondition(
    !file_exists($projectRoot . '/package.json') && !file_exists($projectRoot . '/composer.json'),
    'El santuario no declara dependencias npm ni Composer (RNF-05)'
);
assertCondition(
    !file_exists($projectRoot . '/scratch/moderation_tower.sqlite'),
    'Cero artefactos efimeros dentro del repositorio'
);

// --- Cierre ---
@unlink($databasePath);

echo "\n=================================================\n";
echo "Asertos superados: {$assertsPassed}\n";
echo "Asertos fallidos : {$assertsFailed}\n";
if ($assertsFailed > 0) {
    echo "\nFallos:\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
    echo "\nRESULTADO: DENEGADO — la Tarea 3.2 no cumple su criterio «Hecho cuando».\n";
    exit(1);
}

echo "\nRESULTADO: EXITO — la cola inyecta el veredicto del Articulo III y las\n";
echo "acciones de firma y objecion aplican sus umbrales y permisos (Tarea 3.2).\n";
exit(0);
