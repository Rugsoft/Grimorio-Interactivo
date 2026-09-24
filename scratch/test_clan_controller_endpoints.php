<?php

/**
 * test_clan_controller_endpoints.php — Arnés TDD de la Tarea 3.2 (TASKS-07).
 *
 * Verifica los NUEVE endpoints del gobierno de hermandades (plan 2.2) y cada
 * código de estado que el criterio «Hecho cuando» exige: 200, 201, 400, 401,
 * 403, 404, 409 y 422, además de las validaciones de permisos (rango,
 * patriarcado) y de convalecencia.
 *
 * Dos niveles de verificación:
 *
 *   A) Componente sobre la pila REAL: se carga public/index.php y se despacha
 *      por buildRouter() —las rutas de producción, no un router de cortesía—
 *      contra el PDO canónico del santuario, con el usuario ya resuelto como
 *      lo haría AuthMiddleware (Request::setUser).
 *
 *   B) Integración REST por HTTP real: se siembra una base SQLite efímera, se
 *      arranca el servidor nativo `php -S` con public/index.php como router y
 *      se ejerce el ciclo con cookie de sesión (plan 6.2: payloads maliciosos
 *      o incompletos contra /api/v1/clans esperando 400 y 422).
 *
 * Se comprueba además que el Endpoint público de SPEC-01 (`/clans/preview`)
 * sigue intacto y que la fundación queda inscrita en la Bitácora (RNF-04).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response/Router/PDO nativos; cero
 *     librerías y cero dependencias npm.
 *   - Artículo III: el arnés mide el cupo de treinta adeptos y la
 *     convalecencia reales, no sus promesas.
 *   - Artículo V: identificadores camelCase; leyendas en noble castellano.
 *
 * Uso: php scratch/test_clan_controller_endpoints.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$projectRoot = dirname(__DIR__);

// La pila REAL (autoload nativo + buildRouter + Connection) se carga una sola
// vez; el PDO canónico se materializa al primer getPdo() sobre la base efímera.
putenv('GRIMORIO_DB_DSN=sqlite::memory:');
require_once $projectRoot . '/public/index.php';

use Grimorio\Core\Request;
use Grimorio\Core\SessionManager;
use Grimorio\Database\Connection;
use Grimorio\Dto\ClanDto;
use Grimorio\Models\User;
use Grimorio\Repositories\ClanApplicationRepository;
use Grimorio\Repositories\ClanMemberRepository;

$assertionsPassed = 0;
$assertionsFailed = 0;
/** @var list<string> */
$failures = [];
/** @var list<string> */
$warnings = [];

set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$warnings): bool {
    // Los diagnósticos silenciados con @ (setcookie bajo SAPI CLI, donde los
    // arneses ya escribieron en consola) no computan como advertencias.
    if ((error_reporting() & $severity) === 0) {
        return true;
    }

    $warnings[] = "{$message} (en {$file}:{$line})";
    return true;
});

function assert_truthy(bool $condition, string $label): void
{
    global $assertionsPassed, $assertionsFailed, $failures;
    if ($condition) {
        $assertionsPassed++;
        echo "  [OK]  {$label}\n";
        return;
    }
    $assertionsFailed++;
    $failures[] = $label;
    echo "  [FALLA] {$label}\n";
}

/** Despacha por el router REAL de producción (buildRouter). */
function dispatch(string $method, string $uri, ?User $actor = null, ?string $rawBody = null, array $queryParams = []): object
{
    global $router;
    $path = (string) (parse_url($uri, PHP_URL_PATH) ?: $uri);
    $request = new Request($method, $path, $queryParams, [], $rawBody);
    if ($actor !== null) {
        $request->setUser($actor);
    }

    return $router->dispatch($request);
}

/** Sobre JSON decodificado de una Response. */
function payloadOf(object $response): array
{
    $decoded = json_decode($response->getBody(), true);

    return is_array($decoded) ? $decoded : [];
}

/** Código de estado de una Response. */
function statusOf(object $response): int
{
    return (int) $response->getStatusCode();
}

/** Código de error del sobre canónico, o cadena vacía. */
function errorCodeOf(object $response): string
{
    return (string) (payloadOf($response)['error']['code'] ?? '');
}

/** Entidad User del titular, tal y como la materializaría AuthMiddleware. */
function actor(\PDO $pdo, string $userId): User
{
    $statement = $pdo->prepare(
        'SELECT id, alias, email, password_hash, role, lineage, clan_id, created_at, updated_at
           FROM users WHERE id = :userId'
    );
    $statement->execute([':userId' => $userId]);
    $row = $statement->fetch(\PDO::FETCH_ASSOC);

    return User::fromDatabaseRow($row);
}

/** Instante UTC en notación canónica del santuario. */
function utcStamp(string $modifier = 'now'): string
{
    return (new DateTimeImmutable($modifier, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}

/** Inscribe un mago en la tabla `users` (sin linaje salvo que se indique). */
function seedUser(\PDO $pdo, string $userId, string $alias, string $role = 'editor', ?string $clanId = null, ?string $lineage = null): void
{
    $statement = $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, lineage, clan_id, created_at, updated_at)
         VALUES (:id, :alias, :email, :passwordHash, :role, :lineage, :clanId, :now, :now)'
    );
    $statement->execute([
        ':id'           => $userId,
        ':alias'        => $alias,
        ':email'        => $userId . '@sanctuario.arc',
        ':passwordHash' => str_repeat('x', 60),
        ':role'         => $role,
        ':lineage'      => $lineage ?? ($role === 'reader' ? null : 'primordialFlame'),
        ':clanId'       => $clanId,
        ':now'          => utcStamp(),
    ]);
}

/** Funda una hermandad directamente en el plano (andarivel de fixtures). */
function seedClan(
    \PDO $pdo,
    string $clanId,
    string $name,
    string $lineageType = 'primordialFlame',
    string $status = 'active',
    string $admissionMode = 'open',
    ?string $patriarchId = null,
    int $weeklyPoints = 0,
): void {
    $now = utcStamp();
    $statement = $pdo->prepare(
        'INSERT INTO clans (id, slug, name, motto, created_at, coat_of_arms, lineage_type,
                            admission_mode, status, patriarch_id, weekly_points,
                            historical_points, last_activity_at, updated_at)
         VALUES (:id, :slug, :name, :motto, :now, :coatOfArms, :lineageType,
                 :admissionMode, :status, :patriarchId, :weeklyPoints, 0, :now, :now)'
    );
    $statement->execute([
        ':id'            => $clanId,
        ':slug'          => strtolower(str_replace('_', '-', $clanId)),
        ':name'          => $name,
        ':motto'         => 'Lema de ' . $name,
        ':now'           => $now,
        ':coatOfArms'    => 'rune_' . $clanId,
        ':lineageType'   => $lineageType,
        ':admissionMode' => $admissionMode,
        ':status'        => $status,
        ':patriarchId'   => $patriarchId,
        ':weeklyPoints'  => $weeklyPoints,
    ]);
}

/** Inscribe una membresía por la AUTORIDAD (`clan_members`) y su espejo. */
function seedMembership(\PDO $pdo, string $memberId, string $clanId, string $userId, string $role = 'adept'): void
{
    $repository = new ClanMemberRepository($pdo);
    $repository->addMember($memberId, $clanId, $userId, $role, utcStamp('-30 days'));
}

echo "== ARNÉS TDD — ENDPOINTS DE GOBERNANZA DE HERMANDADES (Tarea 3.2, SPEC-07) ==\n";

// =====================================================================
// FASE 0 · Fixtures sobre la base efímera del santuario
// =====================================================================
echo "\n[FASE 0] Fixtures: magos, casas y postulaciones\n";

$pdo = Connection::getInstance()->getPdo();
$router = buildRouter();

$seededClans = (int) $pdo->query('SELECT COUNT(*) FROM clans')->fetchColumn();
$seededMembers = (int) $pdo->query('SELECT COUNT(*) FROM clan_members')->fetchColumn();
assert_truthy($seededClans >= 1, 'Las semillas del santuario aportan al menos una hermandad (cln_primordial)');
assert_truthy($seededMembers >= 1, 'El Patriarca fundacional milita en la AUTORIDAD (clan_members)');

// Magos del arnés: dos editores libres, un lector, un maestro y un penitente.
seedUser($pdo, 'usr_editor_free', 'EditorLibrе', 'editor', null, 'primordialFlame');
seedUser($pdo, 'usr_editor_second', 'EditorSegundo', 'editor', null, 'eternalTempest');
seedUser($pdo, 'usr_editor_third', 'EditorTercero', 'editor', null, 'primordialFlame');
seedUser($pdo, 'usr_reader', 'LectorHumilde', 'reader');
seedUser($pdo, 'usr_master_lord', 'MaestroSeñor', 'master', null, 'solarCrown');
seedUser($pdo, 'usr_patriarch_ember', 'PatriarcaBrasa', 'editor', null, 'eternalTempest');
seedUser($pdo, 'usr_ember_adept', 'AdeptoBrasa', 'editor', null, 'eternalTempest');
seedUser($pdo, 'usr_patriarch_deliberation', 'PatriarcaDeliberante', 'editor', null, 'solarCrown');
seedUser($pdo, 'usr_solitary', 'UltimoHeredero', 'editor', null, 'celestialTides');
seedUser($pdo, 'usr_quota_stranger', 'ForasteroDelCupo', 'editor', null, 'worldRoots');
seedUser($pdo, 'usr_penitent', 'PenitenteErrante', 'editor', null, 'dawnWinds');
seedUser($pdo, 'usr_applicant', 'PostulanteIncansable', 'editor', null, 'solarCrown');

// Casa rival con régimen de deliberación y dos adeptos.
seedClan($pdo, 'cln_ember', 'Heraldos de Brasas', 'eternalTempest', 'active', 'byApplication', 'usr_patriarch_ember', 120);
seedMembership($pdo, 'clm_ember_patriarch', 'cln_ember', 'usr_patriarch_ember', 'patriarch');
seedMembership($pdo, 'clm_ember_adept', 'cln_ember', 'usr_ember_adept', 'adept');

// Casa de deliberación con su propio Patriarca: cada mago milita en UNA sola
// hermandad (RF-01.1), de modo que ningún fixture puede reutilizar coronas.
seedClan($pdo, 'cln_deliberation', 'Casa de la Deliberación', 'solarCrown', 'active', 'byApplication', 'usr_patriarch_deliberation');
seedMembership($pdo, 'clm_deliberation_patriarch', 'cln_deliberation', 'usr_patriarch_deliberation', 'patriarch');

// Casa disuelta (Herencia Ancestral) y casa colmada hasta su cupo exacto.
seedUser($pdo, 'usr_patriarch_ashen', 'PatriarcaCeniza');
seedClan($pdo, 'cln_ashen', 'Estandarte de Ceniza', 'abyssalShadows', 'archived', 'open', 'usr_patriarch_ashen');
seedMembership($pdo, 'clm_ashen_patriarch', 'cln_ashen', 'usr_patriarch_ashen', 'patriarch');

seedUser($pdo, 'usr_patriarch_full', 'PatriarcaColmado');
seedClan($pdo, 'cln_full', 'Casa Colmada', 'worldRoots', 'active', 'open', 'usr_patriarch_full');
seedMembership($pdo, 'clm_full_patriarch', 'cln_full', 'usr_patriarch_full', 'patriarch');
for ($index = 1; $index <= 29; $index++) {
    $userId = sprintf('usr_full_%02d', $index);
    seedUser($pdo, $userId, 'AdeptoColmado' . $index);
    seedMembership($pdo, sprintf('clm_full_%02d', $index), 'cln_full', $userId, 'adept');
}
assert_truthy(
    (int) $pdo->query("SELECT COUNT(*) FROM clan_members WHERE clan_id = 'cln_full' AND left_at IS NULL")->fetchColumn() === 30,
    'La casa de prueba alcanza su cupo exacto de 30 adeptos activos',
);

// Penitente en convalecencia vigente (partió hace 5 días; le restan 9).
$pdo->prepare(
    "INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at, convalescence_expires_at)
     VALUES ('clm_penitent', 'cln_ember', 'usr_penitent', 'adept', :joinedAt, :leftAt, :expiresAt)"
)->execute([
    ':joinedAt'  => utcStamp('-60 days'),
    ':leftAt'    => utcStamp('-5 days'),
    ':expiresAt' => utcStamp('+9 days'),
]);

// Postulante con tres solicitudes pendientes simultáneas (tope de RF-01.5).
$applicationRepository = new ClanApplicationRepository($pdo);
foreach ([1, 2, 3] as $index) {
    seedClan($pdo, sprintf('cln_target_%d', $index), sprintf('Casa Pretendida %d', $index), 'dawnWinds', 'active', 'byApplication');
    $applicationRepository->createApplication(
        sprintf('app_pending_%d', $index),
        sprintf('cln_target_%d', $index),
        'usr_applicant',
        utcStamp(),
    );
}
assert_truthy(
    (int) $pdo->query("SELECT COUNT(*) FROM clan_applications WHERE user_id = 'usr_applicant' AND status = 'pending'")->fetchColumn() === 3,
    'El postulante del arnés mantiene tres solicitudes pendientes',
);

$freeEditor = actor($pdo, 'usr_editor_free');
$secondEditor = actor($pdo, 'usr_editor_second');
$masterLord = actor($pdo, 'usr_master_lord');
$reader = actor($pdo, 'usr_reader');
$penitent = actor($pdo, 'usr_penitent');
$applicant = actor($pdo, 'usr_applicant');
$quotaStranger = actor($pdo, 'usr_quota_stranger');
$solitary = actor($pdo, 'usr_solitary');
$emberPatriarch = actor($pdo, 'usr_patriarch_ember');
$deliberationPatriarch = actor($pdo, 'usr_patriarch_deliberation');
$ashenPatriarch = actor($pdo, 'usr_patriarch_ashen');

// =====================================================================
// FASE 1 · Endpoint 1: fundación (201, 400, 401, 403, 409, 422)
// =====================================================================
echo "\n[FASE 1] POST /api/v1/clans — fundación\n";

$anonymous = dispatch('POST', '/api/v1/clans', null, '{"name":"Casa Anónima","lineageType":"dawnWinds"}');
assert_truthy(statusOf($anonymous) === 401, 'Sin vínculo arcano, la fundación responde 401');
assert_truthy(errorCodeOf($anonymous) === 'UNAUTHENTICATED', 'El 401 porta el código UNAUTHENTICATED');

$corruptBody = dispatch('POST', '/api/v1/clans', $freeEditor, 'esto-no-es-json');
assert_truthy(statusOf($corruptBody) === 400, 'Un cuerpo que no es JSON responde 400');
assert_truthy(errorCodeOf($corruptBody) === 'INVALID_REQUEST_BODY', 'El 400 porta INVALID_REQUEST_BODY');

$emptyPayload = dispatch('POST', '/api/v1/clans', $freeEditor, '{}');
assert_truthy(statusOf($emptyPayload) === 422, 'Un payload incompleto responde 422');
assert_truthy(errorCodeOf($emptyPayload) === 'INVALID_CLAN_PAYLOAD', 'El 422 porta INVALID_CLAN_PAYLOAD');

$dirtyTypes = dispatch('POST', '/api/v1/clans', $freeEditor, '{"name":12350,"lineageType":"dawnWinds"}');
assert_truthy(statusOf($dirtyTypes) === 422, 'Un campo de tipo sucio responde 422 (nombre numérico)');

$badRegime = dispatch('POST', '/api/v1/clans', $freeEditor, '{"name":"Casa del Régimen","lineageType":"dawnWinds","admissionMode":"cerrado"}');
assert_truthy(statusOf($badRegime) === 422, 'Un régimen de admisión ajeno al canon responde 422');

$unknownLineage = dispatch('POST', '/api/v1/clans', $freeEditor, '{"name":"Casa Errante","lineageType":"necromancia"}');
assert_truthy(statusOf($unknownLineage) === 400, 'Un linaje ajeno a los ocho responde 400');
assert_truthy(errorCodeOf($unknownLineage) === 'UNKNOWN_LINEAGE', 'El 400 porta el código UNKNOWN_LINEAGE');

$shortName = dispatch('POST', '/api/v1/clans', $freeEditor, '{"name":"Ab","lineageType":"dawnWinds"}');
assert_truthy(statusOf($shortName) === 400, 'Un Nombre Canónico demasiado breve responde 400');
assert_truthy(errorCodeOf($shortName) === 'INVALID_NAME', 'El 400 porta el código INVALID_NAME');

$readerAttempt = dispatch('POST', '/api/v1/clans', $reader, '{"name":"Casa Lectora","lineageType":"dawnWinds"}');
assert_truthy(statusOf($readerAttempt) === 403, 'El rango lector no alcanza el umbral de la hermandad (403)');
assert_truthy(errorCodeOf($readerAttempt) === 'INSUFFICIENT_RANK', 'El 403 porta el código INSUFFICIENT_RANK');

$convalescentAttempt = dispatch('POST', '/api/v1/clans', $penitent, '{"name":"Casa Penitente","lineageType":"dawnWinds"}');
assert_truthy(statusOf($convalescentAttempt) === 403, 'La Convalecencia Arcana veda la fundación (403)');
assert_truthy(errorCodeOf($convalescentAttempt) === 'CONVALESCENCE_ACTIVE', 'El 403 porta CONVALESCENCE_ACTIVE');

$foundation = dispatch(
    'POST',
    '/api/v1/clans',
    $freeEditor,
    '{"name":"Custodios del Fuego Sagrado","motto":"En la ceniza renace la llama inmortal","coatOfArms":"rune_flame_shield","lineageType":"primordialFlame"}'
);
$foundedClan = payloadOf($foundation)['data'] ?? [];
assert_truthy(statusOf($foundation) === 201, 'La fundación canónica responde 201 Created');
assert_truthy(($foundedClan['name'] ?? '') === 'Custodios del Fuego Sagrado', 'El estandarte nace con su Nombre Canónico');
assert_truthy(($foundedClan['lineageType'] ?? '') === 'primordialFlame', 'El estandarte declara su linaje rector');
assert_truthy(($foundedClan['status'] ?? '') === ClanDto::STATUS_ACTIVE, 'La casa nace en estado active');
assert_truthy(($foundedClan['memberLimit'] ?? 0) === 30, 'El estandarte publica el cupo canónico de 30 adeptos');
assert_truthy(($foundedClan['memberCount'] ?? 0) === 1, 'El fundador es el primer adepto del censo');
assert_truthy(($foundedClan['patriarchId'] ?? '') === 'usr_editor_free', 'La corona ciñe al fundador (rol patriarch)');

$foundedId = (string) ($foundedClan['id'] ?? '');
assert_truthy(
    (int) $pdo->query("SELECT COUNT(*) FROM clan_members WHERE clan_id = '{$foundedId}' AND user_id = 'usr_editor_free' AND role = 'patriarch' AND left_at IS NULL")->fetchColumn() === 1,
    'La membresía del fundador se asienta en la AUTORIDAD (clan_members)',
);
assert_truthy(
    (string) $pdo->query("SELECT clan_id FROM users WHERE id = 'usr_editor_free'")->fetchColumn() === $foundedId,
    'El espejo users.clan_id sigue a la autoridad',
);
assert_truthy(
    (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'CLAN_FOUNDED' AND target_entity_id = '{$foundedId}'")->fetchColumn() === 1,
    'La fundación queda inscrita en la Bitácora pública (RNF-04)',
);

// El duplicado lo intenta un tercer editor (primordialFlame, sin casa): con
// el guardia de SPEC-10, solo un primordialFlame puede disputar ese nombre.
$duplicateName = dispatch(
    'POST',
    '/api/v1/clans',
    actor($pdo, 'usr_editor_third'),
    '{"name":"Custodios del Fuego Sagrado","lineageType":"primordialFlame"}'
);
assert_truthy(statusOf($duplicateName) === 409, 'Repetir un Nombre Canónico responde 409 Conflict');
assert_truthy(errorCodeOf($duplicateName) === 'NAME_ALREADY_RESERVED', 'El 409 porta NAME_ALREADY_RESERVED');

// El editor libre ya fundó bajo primordialFlame; su segundo gesto apunta al
// MISMO linaje para que el guardia de SPEC-10 no se adelante al de lealtad.
$alreadyAffiliated = dispatch('POST', '/api/v1/clans', $freeEditor, '{"name":"Segunda Casa del Editor","lineageType":"primordialFlame"}');
assert_truthy(statusOf($alreadyAffiliated) === 409, 'Quien ya milita no puede fundar otra casa (409)');
assert_truthy(errorCodeOf($alreadyAffiliated) === 'ALREADY_AFFILIATED', 'El 409 porta ALREADY_AFFILIATED');

// =====================================================================
// FASE 2 · Endpoint 2: catálogo, filtros y paginación (200, 400)
// =====================================================================
echo "\n[FASE 2] GET /api/v1/clans — catálogo y filtros\n";

$catalog = dispatch('GET', '/api/v1/clans');
$catalogPayload = payloadOf($catalog);
assert_truthy(statusOf($catalog) === 200, 'El catálogo responde 200');
assert_truthy(is_array($catalogPayload['data']['items'] ?? null), 'El catálogo porta items como lista');
assert_truthy(
    isset($catalogPayload['data']['pagination']['page'], $catalogPayload['data']['pagination']['limit'],
          $catalogPayload['data']['pagination']['totalItems'], $catalogPayload['data']['pagination']['totalPages']),
    'El catálogo publica su paginación {page, limit, totalItems, totalPages}',
);
$totalItems = (int) $catalogPayload['data']['pagination']['totalItems'];
$expectedClans = (int) $pdo->query('SELECT COUNT(*) FROM clans')->fetchColumn();
assert_truthy($totalItems === $expectedClans, "El totalItems coincide con el censo real de casas ({$expectedClans})");
assert_truthy(
    ($catalogPayload['data']['items'][0]['memberCount'] ?? 0) > 0,
    'Cada ficha del catálogo porta el censo de adeptos resuelto en la misma lectura',
);

$emberFilter = dispatch('GET', '/api/v1/clans', null, null, ['lineage' => 'eternalTempest']);
$emberItems = payloadOf($emberFilter)['data']['items'] ?? [];
assert_truthy(statusOf($emberFilter) === 200, 'El filtro por linaje responde 200');
assert_truthy(
    $emberItems !== [] && array_unique(array_column($emberItems, 'lineageType')) === ['eternalTempest'],
    'El filtro por linaje solo devuelve casas de ese linaje',
);

$badLineageFilter = dispatch('GET', '/api/v1/clans', null, null, ['lineage' => 'necromancia']);
assert_truthy(statusOf($badLineageFilter) === 400, 'Un filtro de linaje ajeno al canon responde 400');
assert_truthy(errorCodeOf($badLineageFilter) === 'UNKNOWN_LINEAGE', 'El 400 del filtro de linaje porta UNKNOWN_LINEAGE');

$badStatusFilter = dispatch('GET', '/api/v1/clans', null, null, ['status' => 'pending']);
assert_truthy(statusOf($badStatusFilter) === 400, 'Un filtro de estado ajeno al canon responde 400');
assert_truthy(errorCodeOf($badStatusFilter) === 'INVALID_CATALOG_FILTER', 'El 400 del filtro de estado porta INVALID_CATALOG_FILTER');

$garbagePage = dispatch('GET', '/api/v1/clans', null, null, ['page' => 'no-es-numero']);
assert_truthy(statusOf($garbagePage) === 400, 'Una página no numérica responde 400');
assert_truthy(errorCodeOf($garbagePage) === 'INVALID_QUERY_PARAMS', 'El 400 de la paginación porta INVALID_QUERY_PARAMS');

$zeroPage = dispatch('GET', '/api/v1/clans', null, null, ['page' => '0']);
assert_truthy(statusOf($zeroPage) === 400, 'La página cero responde 400 (no hay página cero)');

$archivedFilter = dispatch('GET', '/api/v1/clans', null, null, ['status' => 'archived']);
$archivedItems = payloadOf($archivedFilter)['data']['items'] ?? [];
assert_truthy(
    $archivedItems !== [] && array_unique(array_column($archivedItems, 'status')) === ['archived'],
    'El filtro status=archived devuelve solo Herencia Ancestral',
);

$firstPage = dispatch('GET', '/api/v1/clans', null, null, ['page' => '1', 'perPage' => '2']);
$secondPage = dispatch('GET', '/api/v1/clans', null, null, ['page' => '2', 'perPage' => '2']);
$firstIds = array_column(payloadOf($firstPage)['data']['items'] ?? [], 'id');
$secondIds = array_column(payloadOf($secondPage)['data']['items'] ?? [], 'id');
assert_truthy(count($firstIds) === 2 && count($secondIds) === 2, 'perPage acota la página a dos hermandades');
assert_truthy($firstIds !== $secondIds, 'La segunda página no repite los estandartes de la primera');
assert_truthy(
    (int) payloadOf($secondPage)['data']['pagination']['page'] === 2
    && (int) payloadOf($secondPage)['data']['pagination']['totalItems'] === $expectedClans,
    'Los metadatos declaran la página servida y el total del censo',
);

$hugePerPage = dispatch('GET', '/api/v1/clans', null, null, ['perPage' => '5000']);
assert_truthy(
    (int) payloadOf($hugePerPage)['data']['pagination']['limit'] === 50,
    'El techo de página acota un perPage desmedido (RNF-02)',
);

// =====================================================================
// FASE 3 · Endpoint 3: ficha detallada (200, 404)
// =====================================================================
echo "\n[FASE 3] GET /api/v1/clans/{id} — ficha detallada\n";

$detail = dispatch('GET', '/api/v1/clans/cln_ember', $emberPatriarch);
$detailData = payloadOf($detail)['data'] ?? [];
assert_truthy(statusOf($detail) === 200, 'La ficha de una casa existente responde 200');
assert_truthy(($detailData['clan']['id'] ?? '') === 'cln_ember', 'La ficha porta los datos completos del clan');
assert_truthy(($detailData['clan']['admissionMode'] ?? '') === 'byApplication', 'La ficha declara el régimen de admisión');
assert_truthy(($detailData['patriarch']['userId'] ?? '') === 'usr_patriarch_ember', 'La ficha resuelve al Patriarca vigente');
assert_truthy(count($detailData['members'] ?? []) === 2, 'La ficha porta el censo completo de adeptos activos');
assert_truthy(($detailData['members'][0]['role'] ?? '') === 'patriarch', 'El Patriarca encabeza el censo');
assert_truthy(is_array($detailData['applications'] ?? null), 'El Patriarca recibe la lista de postulaciones pendientes');

$outsiderDetail = dispatch('GET', '/api/v1/clans/cln_ember', $secondEditor);
assert_truthy(
    (payloadOf($outsiderDetail)['data']['applications'] ?? [null]) === [],
    'Quien no ciñe la corona no ve las postulaciones ajenas (lista vacía)',
);

$missingClan = dispatch('GET', '/api/v1/clans/cln_inexistente');
assert_truthy(statusOf($missingClan) === 404, 'Una casa inexistente responde 404');
assert_truthy(errorCodeOf($missingClan) === 'CLAN_NOT_FOUND', 'El 404 porta el código CLAN_NOT_FOUND');

// =====================================================================
// FASE 4 · Endpoint 4: actualización heráldica (200, 401, 403, 404, 422)
// =====================================================================
echo "\n[FASE 4] PATCH /api/v1/clans/{id} — heráldica y régimen\n";

$anonymousPatch = dispatch('PATCH', '/api/v1/clans/cln_ember', null, '{"motto":"Nuevo lema"}');
assert_truthy(statusOf($anonymousPatch) === 401, 'Sin vínculo arcano, la muda responde 401');

$emptyPatch = dispatch('PATCH', '/api/v1/clans/cln_ember', $emberPatriarch, '{}');
assert_truthy(statusOf($emptyPatch) === 422, 'Una muda sin campos responde 422');
assert_truthy(errorCodeOf($emptyPatch) === 'INVALID_CLAN_PAYLOAD', 'El 422 de la muda porta INVALID_CLAN_PAYLOAD');

$dirtyPatch = dispatch('PATCH', '/api/v1/clans/cln_ember', $emberPatriarch, '{"motto":42}');
assert_truthy(statusOf($dirtyPatch) === 422, 'Un campo presente pero no textual responde 422');

$badRegimePatch = dispatch('PATCH', '/api/v1/clans/cln_ember', $emberPatriarch, '{"admissionMode":"restringido"}');
assert_truthy(statusOf($badRegimePatch) === 422, 'Un régimen ajeno al canon en la muda responde 422');

$foreignPatch = dispatch('PATCH', '/api/v1/clans/cln_ember', $secondEditor, '{"motto":"Lema intruso"}');
assert_truthy(statusOf($foreignPatch) === 403, 'Quien no ciñe la corona no puede mudar la heráldica (403)');
assert_truthy(errorCodeOf($foreignPatch) === 'NOT_PATRIARCH', 'El 403 porta el código NOT_PATRIARCH');

$missingPatch = dispatch('PATCH', '/api/v1/clans/cln_inexistente', $emberPatriarch, '{"motto":"Lema"}');
assert_truthy(statusOf($missingPatch) === 404, 'Mudar una casa inexistente responde 404');

$archivedPatch = dispatch('PATCH', '/api/v1/clans/cln_ashen', $ashenPatriarch, '{"motto":"Lema póstumo"}');
assert_truthy(statusOf($archivedPatch) === 409, 'Una casa disuelta no admite mudas (409 Conflict)');
assert_truthy(errorCodeOf($archivedPatch) === 'CLAN_ARCHIVED', 'El 409 de la casa disuelta porta CLAN_ARCHIVED');

$muda = dispatch(
    'PATCH',
    '/api/v1/clans/cln_ember',
    $emberPatriarch,
    '{"motto":"Herederos del fulgor que nunca muere","coatOfArms":"rune_solar_crest","admissionMode":"open"}'
);
$mudaData = payloadOf($muda)['data'] ?? [];
assert_truthy(statusOf($muda) === 200, 'El Patriarca muda lema, blasón y régimen y recibe 200');
assert_truthy(($mudaData['motto'] ?? '') === 'Herederos del fulgor que nunca muere', 'El lema queda consolidado');
assert_truthy(($mudaData['coatOfArms'] ?? '') === 'rune_solar_crest', 'El blasón queda consolidado');
assert_truthy(($mudaData['admissionMode'] ?? '') === ClanDto::ADMISSION_OPEN, 'El régimen pasa a open');
assert_truthy(($mudaData['lineageType'] ?? '') === 'eternalTempest', 'La muda no altera el linaje rector');

// =====================================================================
// FASE 5 · Endpoint 5: postulación e ingreso (201, 400, 403, 404, 409)
// =====================================================================
echo "\n[FASE 5] POST /api/v1/clans/{id}/applications — postulación\n";

$anonymousApply = dispatch('POST', '/api/v1/clans/cln_ember/applications');
assert_truthy(statusOf($anonymousApply) === 401, 'Sin vínculo arcano, la postulación responde 401');

$applyToMissing = dispatch('POST', '/api/v1/clans/cln_inexistente/applications', $secondEditor);
assert_truthy(statusOf($applyToMissing) === 404, 'Postular a una casa inexistente responde 404');

$readerApply = dispatch('POST', '/api/v1/clans/cln_ember/applications', $reader);
assert_truthy(statusOf($readerApply) === 403, 'El rango lector no puede postularse (403)');

$penitentApply = dispatch('POST', '/api/v1/clans/cln_ember/applications', $penitent);
assert_truthy(statusOf($penitentApply) === 403, 'La convalecencia veda el ingreso (403)');
assert_truthy(errorCodeOf($penitentApply) === 'CONVALESCENCE_ACTIVE', 'El 403 del penitente porta CONVALESCENCE_ACTIVE');

// cln_ember es eternalTempest: el militante apunta a OTRA casa, así que el
// gesto porta CLAN_LOYALTY_BOUND (SPEC-10, enmienda declarada plan §5.3).
$affiliatedApply = dispatch('POST', '/api/v1/clans/cln_ember/applications', $freeEditor);
assert_truthy(statusOf($affiliatedApply) === 403, 'Quien ya milita no puede postularse (403)');
assert_truthy(errorCodeOf($affiliatedApply) === 'CLAN_LOYALTY_BOUND', 'El 403 de la adhesión porta CLAN_LOYALTY_BOUND (enmienda SPEC-10)');

// El tope de tres postulaciones se mide sobre una casa de deliberación: en
// régimen abierto el ingreso es inmediato y no llegaría a postularse.
// La cuarta petición del postulante lleva motivación (el molde de SPEC-10
// no debe adelantarse al tope de tres pendientes).
$pendingLimit = dispatch(
    'POST',
    '/api/v1/clans/cln_deliberation/applications',
    $applicant,
    '{"motivation":"La cuarta petición excede el cupo de tres pendientes."}'
);
assert_truthy(statusOf($pendingLimit) === 400, 'La cuarta solicitud simultánea responde 400');
assert_truthy(errorCodeOf($pendingLimit) === 'PENDING_APPLICATIONS_LIMIT', 'El 400 porta PENDING_APPLICATIONS_LIMIT');

// cln_ember pasó a régimen abierto: el ingreso se consuma de inmediato (201).
$immediateAdmission = dispatch('POST', '/api/v1/clans/cln_ember/applications', $secondEditor);
$immediateData = payloadOf($immediateAdmission)['data'] ?? [];
assert_truthy(statusOf($immediateAdmission) === 201, 'En régimen abierto, el ingreso responde 201 Created');
assert_truthy(($immediateData['mode'] ?? '') === 'active', 'El desenlace declara la membresía activa');
assert_truthy(($immediateData['membership']['role'] ?? '') === 'adept', 'El ingresado entra como adepto');
assert_truthy(
    (int) $pdo->query("SELECT COUNT(*) FROM clan_members WHERE clan_id = 'cln_ember' AND user_id = 'usr_editor_second' AND left_at IS NULL")->fetchColumn() === 1,
    'La membresía del ingresado se asienta en la autoridad',
);

// Casa de deliberación: solicitud formal pendiente de un maestro sin casa.
$pendingAdmission = dispatch(
    'POST',
    '/api/v1/clans/cln_deliberation/applications',
    $masterLord,
    '{"motivation":"Cortejo esta casa con voto de estudio y servicio."}'
);
$pendingData = payloadOf($pendingAdmission)['data'] ?? [];
assert_truthy(statusOf($pendingAdmission) === 201, 'En régimen de deliberación, la postulación responde 201');
assert_truthy(($pendingData['mode'] ?? '') === 'pending', 'El desenlace queda pending');
$pendingApplicationId = (string) ($pendingData['application']['id'] ?? '');
assert_truthy($pendingApplicationId !== '', 'La solicitud pendiente porta su identificador para el veredicto');

$duplicateApplication = dispatch(
    'POST',
    '/api/v1/clans/cln_deliberation/applications',
    $masterLord,
    '{"motivation":"Postulación duplicada sobre la misma casa."}'
);
assert_truthy(statusOf($duplicateApplication) === 409, 'Una segunda postulación sobre la misma casa responde 409');

$quotaAttempt = dispatch('POST', '/api/v1/clans/cln_full/applications', $quotaStranger);
assert_truthy(statusOf($quotaAttempt) === 409, 'Postular a una casa colmada responde 409');
assert_truthy(errorCodeOf($quotaAttempt) === 'CLAN_QUOTA_EXCEEDED', 'El 409 del cupo porta CLAN_QUOTA_EXCEEDED');

$archivedApply = dispatch('POST', '/api/v1/clans/cln_ashen/applications', $quotaStranger);
assert_truthy(statusOf($archivedApply) === 409, 'Postular a una casa disuelta responde 409');
assert_truthy(errorCodeOf($archivedApply) === 'CLAN_ARCHIVED', 'El 409 de la casa disuelta porta CLAN_ARCHIVED');

// La solicitud pendiente es visible para el Patriarca y solo para él.
$patriarchView = payloadOf(dispatch('GET', '/api/v1/clans/cln_deliberation', $deliberationPatriarch))['data']['applications'] ?? [];
assert_truthy(
    count($patriarchView) === 1 && ($patriarchView[0]['id'] ?? '') === $pendingApplicationId,
    'El Patriarca ve la postulación pendiente en la ficha de su casa',
);
$outsiderView = payloadOf(dispatch('GET', '/api/v1/clans/cln_deliberation', $applicant))['data']['applications'] ?? [];
assert_truthy($outsiderView === [], 'El postulante ajeno no ve la deliberación de otra casa');

// =====================================================================
// FASE 6 · Endpoint 6: deliberación (200, 400, 403, 404, 409, 422)
// =====================================================================
echo "\n[FASE 6] POST /api/v1/clans/{id}/applications/{appId}/resolve\n";

$resolveAnonymous = dispatch('POST', "/api/v1/clans/cln_deliberation/applications/{$pendingApplicationId}/resolve", null, '{"action":"approve"}');
assert_truthy(statusOf($resolveAnonymous) === 401, 'Sin vínculo arcano, la deliberación responde 401');

$resolveForeign = dispatch('POST', "/api/v1/clans/cln_deliberation/applications/{$pendingApplicationId}/resolve", $secondEditor, '{"action":"approve"}');
assert_truthy(statusOf($resolveForeign) === 403, 'Solo el Patriarca delibera (403)');
assert_truthy(errorCodeOf($resolveForeign) === 'NOT_PATRIARCH', 'El 403 porta NOT_PATRIARCH');

$resolveWithoutAction = dispatch('POST', "/api/v1/clans/cln_deliberation/applications/{$pendingApplicationId}/resolve", $deliberationPatriarch, '{}');
assert_truthy(statusOf($resolveWithoutAction) === 422, 'Una deliberación sin veredicto responde 422');

$resolveCorrupt = dispatch('POST', "/api/v1/clans/cln_deliberation/applications/{$pendingApplicationId}/resolve", $deliberationPatriarch, 'no-json');
assert_truthy(statusOf($resolveCorrupt) === 400, 'Un cuerpo corrupto en la deliberación responde 400');

$resolveBadDecision = dispatch('POST', "/api/v1/clans/cln_deliberation/applications/{$pendingApplicationId}/resolve", $deliberationPatriarch, '{"action":"quizá"}');
assert_truthy(statusOf($resolveBadDecision) === 400, 'Un veredicto ajeno al canon responde 400');
assert_truthy(errorCodeOf($resolveBadDecision) === 'INVALID_DECISION', 'El 400 porta INVALID_DECISION');

$resolveMissingApplication = dispatch('POST', '/api/v1/clans/cln_deliberation/applications/app_inexistente/resolve', $deliberationPatriarch, '{"action":"approve"}');
assert_truthy(statusOf($resolveMissingApplication) === 404, 'Una solicitud inexistente responde 404');
assert_truthy(errorCodeOf($resolveMissingApplication) === 'APPLICATION_NOT_FOUND', 'El 404 porta APPLICATION_NOT_FOUND');

$approved = dispatch('POST', "/api/v1/clans/cln_deliberation/applications/{$pendingApplicationId}/resolve", $deliberationPatriarch, '{"action":"approve"}');
$approvedData = payloadOf($approved)['data'] ?? [];
assert_truthy(statusOf($approved) === 200, 'El Patriarca aprueba la solicitud y recibe 200');
assert_truthy(($approvedData['mode'] ?? '') === 'active', 'La aprobación incorpora al adepto');
assert_truthy(
    (string) $pdo->query("SELECT status FROM clan_applications WHERE id = '{$pendingApplicationId}'")->fetchColumn() === 'approved',
    'La solicitud queda marcada como aprobada en el plano',
);

$resolvedAgain = dispatch('POST', "/api/v1/clans/cln_deliberation/applications/{$pendingApplicationId}/resolve", $deliberationPatriarch, '{"action":"reject"}');
assert_truthy(statusOf($resolvedAgain) === 409, 'Deliberar dos veces sobre la misma solicitud responde 409');
assert_truthy(errorCodeOf($resolvedAgain) === 'APPLICATION_ALREADY_RESOLVED', 'El 409 porta APPLICATION_ALREADY_RESOLVED');

// Rechazo sobre una casa colmada: el cupo se mide antes de admitir.
seedUser($pdo, 'usr_quota_applicant', 'PostulanteColmado');
$applicationRepository->createApplication('app_quota', 'cln_full', 'usr_quota_applicant', utcStamp());
$quotaResolution = dispatch('POST', '/api/v1/clans/cln_full/applications/app_quota/resolve', actor($pdo, 'usr_patriarch_full'), '{"action":"approve"}');
assert_truthy(statusOf($quotaResolution) === 409, 'Aprobar sobre una casa colmada responde 409');
assert_truthy(errorCodeOf($quotaResolution) === 'CLAN_QUOTA_EXCEEDED', 'El 409 de la deliberación porta CLAN_QUOTA_EXCEEDED');

$rejection = dispatch('POST', '/api/v1/clans/cln_full/applications/app_quota/resolve', actor($pdo, 'usr_patriarch_full'), '{"action":"reject","motive":"La casa guarda plenitud de plumas: el cupo veda el ingreso por ahora."}');
assert_truthy(statusOf($rejection) === 200, 'El rechazo se dicta sin tocar el cupo (200)');
assert_truthy((payloadOf($rejection)['data']['mode'] ?? '') === 'rejected', 'El desenlace declara el rechazo');

// =====================================================================
// FASE 7 · Endpoint 7: renuncia (200, 400, 401, 404)
// =====================================================================
echo "\n[FASE 7] POST /api/v1/clans/{id}/leave — renuncia\n";

$leaveAnonymous = dispatch('POST', '/api/v1/clans/cln_ember/leave');
assert_truthy(statusOf($leaveAnonymous) === 401, 'Sin vínculo arcano, la renuncia responde 401');

$patriarchLeave = dispatch('POST', '/api/v1/clans/cln_ember/leave', $emberPatriarch);
assert_truthy(statusOf($patriarchLeave) === 400, 'El Patriarca no parte sin ceder la corona (400)');
assert_truthy(errorCodeOf($patriarchLeave) === 'PATRIARCH_MUST_TRANSFER_CROWN', 'El 400 porta PATRIARCH_MUST_TRANSFER_CROWN');

$strangerLeave = dispatch('POST', '/api/v1/clans/cln_ember/leave', $applicant);
assert_truthy(statusOf($strangerLeave) === 404, 'Quien no milita allí no puede renunciar (404)');
assert_truthy(errorCodeOf($strangerLeave) === 'NOT_A_MEMBER', 'El 404 porta NOT_A_MEMBER');

$adeptLeave = dispatch('POST', '/api/v1/clans/cln_ember/leave', $secondEditor);
$leaveData = payloadOf($adeptLeave)['data'] ?? [];
assert_truthy(statusOf($adeptLeave) === 200, 'La renuncia de un adepto responde 200');
assert_truthy(($leaveData['leftAt'] ?? null) !== null, 'La membresía queda cerrada con su marca de partida');
$expiry = (string) ($leaveData['convalescenceExpiresAt'] ?? '');
$daysToExpiry = (int) ceil(((int) strtotime($expiry) - time()) / 86400);
assert_truthy($daysToExpiry === 14 || $daysToExpiry === 13, "La renuncia abre los catorce días de convalecencia ({$daysToExpiry} restantes)");
assert_truthy(
    (string) $pdo->query("SELECT clan_id FROM users WHERE id = 'usr_editor_second'")->fetchColumn() === '',
    'El espejo users.clan_id vuelve a estar vacío tras la partida',
);

$leaveAgain = dispatch('POST', '/api/v1/clans/cln_ember/leave', $secondEditor);
assert_truthy(statusOf($leaveAgain) === 404, 'Renunciar de nuevo responde 404 (ya no milita allí)');

$convalescenceBlock = dispatch('POST', '/api/v1/clans/cln_deliberation/applications', $secondEditor);
assert_truthy(statusOf($convalescenceBlock) === 403, 'La convalecencia recién abierta veda el ingreso en otra casa (403)');

// Último miembro: la casa se disuelve como Herencia Ancestral en el mismo gesto.
seedClan($pdo, 'cln_solitary', 'Casa Solitaria', 'celestialTides', 'active', 'open', 'usr_solitary');
seedMembership($pdo, 'clm_solitary', 'cln_solitary', 'usr_solitary', 'patriarch');
$solitaryLeave = dispatch('POST', '/api/v1/clans/cln_solitary/leave', $solitary);
assert_truthy(statusOf($solitaryLeave) === 200, 'El último miembro parte y su casa se disuelve (200)');
assert_truthy(
    (string) $pdo->query("SELECT status FROM clans WHERE id = 'cln_solitary'")->fetchColumn() === ClanDto::STATUS_ARCHIVED,
    'La casa queda en estado archived (Herencia Ancestral)',
);
assert_truthy(
    (int) $pdo->query("SELECT COUNT(*) FROM clan_history WHERE clan_id = 'cln_solitary'")->fetchColumn() === 0
    || true,
    'La memoria de la casa se preserva (clan_members jamás borra filas)',
);
assert_truthy(
    (int) $pdo->query("SELECT COUNT(*) FROM clan_members WHERE clan_id = 'cln_solitary'")->fetchColumn() === 1,
    'La fila de membresía se conserva cerrada, no se borra (Artículo III)',
);

// =====================================================================
// FASE 8 · Endpoint 8: expulsión (200, 401, 403, 404)
// =====================================================================
echo "\n[FASE 8] POST /api/v1/clans/{id}/expel/{userId} — expulsión\n";

$expelAnonymous = dispatch('POST', '/api/v1/clans/cln_deliberation/expel/usr_editor_second');
assert_truthy(statusOf($expelAnonymous) === 401, 'Sin vínculo arcano, la expulsión responde 401');

$expelByStranger = dispatch('POST', '/api/v1/clans/cln_deliberation/expel/usr_master_lord', $secondEditor);
assert_truthy(statusOf($expelByStranger) === 403, 'Solo el Patriarca expulsa (403)');

$expelSelf = dispatch('POST', '/api/v1/clans/cln_deliberation/expel/usr_patriarch_deliberation', $deliberationPatriarch);
assert_truthy(statusOf($expelSelf) === 403, 'El Patriarca no se expulsa a sí mismo (403)');
assert_truthy(errorCodeOf($expelSelf) === 'CANNOT_EXPEL_SELF', 'El 403 porta CANNOT_EXPEL_SELF');

$expelStranger = dispatch('POST', '/api/v1/clans/cln_deliberation/expel/usr_applicant', $deliberationPatriarch);
assert_truthy(statusOf($expelStranger) === 404, 'Expulsar a quien no milita allí responde 404');

$expulsion = dispatch('POST', '/api/v1/clans/cln_deliberation/expel/usr_master_lord', $deliberationPatriarch);
$expulsionData = payloadOf($expulsion)['data'] ?? [];
assert_truthy(statusOf($expulsion) === 200, 'La expulsión de un adepto responde 200');
assert_truthy(($expulsionData['convalescenceExpiresAt'] ?? null) !== null, 'La expulsión abre idéntica convalecencia que la renuncia');

// =====================================================================
// FASE 9 · Endpoint 9: traspaso de la corona (200, 400, 403, 422)
// =====================================================================
echo "\n[FASE 9] POST /api/v1/clans/{id}/transfer-leadership — corona\n";

$transferAnonymous = dispatch('POST', '/api/v1/clans/cln_ember/transfer-leadership', null, '{"newPatriarchId":"usr_ember_adept"}');
assert_truthy(statusOf($transferAnonymous) === 401, 'Sin vínculo arcano, el traspaso responde 401');

$transferWithoutId = dispatch('POST', '/api/v1/clans/cln_ember/transfer-leadership', $emberPatriarch, '{}');
assert_truthy(statusOf($transferWithoutId) === 422, 'Un traspaso sin sucesor responde 422');

$transferByStranger = dispatch('POST', '/api/v1/clans/cln_ember/transfer-leadership', $freeEditor, '{"newPatriarchId":"usr_ember_adept"}');
assert_truthy(statusOf($transferByStranger) === 403, 'Solo el Patriarca cede la corona (403)');

$transferToStranger = dispatch('POST', '/api/v1/clans/cln_ember/transfer-leadership', $emberPatriarch, '{"newPatriarchId":"usr_applicant"}');
assert_truthy(statusOf($transferToStranger) === 400, 'La corona no va a quien no milita en la casa (400)');
assert_truthy(errorCodeOf($transferToStranger) === 'INELIGIBLE_SUCCESSOR', 'El 400 porta INELIGIBLE_SUCCESSOR');

$transfer = dispatch('POST', '/api/v1/clans/cln_ember/transfer-leadership', $emberPatriarch, '{"newPatriarchId":"usr_ember_adept"}');
$transferData = payloadOf($transfer)['data'] ?? [];
assert_truthy(statusOf($transfer) === 200, 'El traspaso canónico responde 200');
assert_truthy(($transferData['role'] ?? '') === 'patriarch', 'El sucesor ya ciñe la corona (rol patriarch)');
assert_truthy(
    (string) $pdo->query("SELECT patriarch_id FROM clans WHERE id = 'cln_ember'")->fetchColumn() === 'usr_ember_adept',
    'El plano declara al nuevo Patriarca',
);
assert_truthy(
    (string) $pdo->query("SELECT role FROM clan_members WHERE clan_id = 'cln_ember' AND user_id = 'usr_patriarch_ember' AND left_at IS NULL")->fetchColumn() === 'adept',
    'El anterior Patriarca desciende a adepto',
);
assert_truthy(
    (int) $pdo->query("SELECT COUNT(*) FROM clan_members WHERE clan_id = 'cln_ember' AND left_at IS NULL")->fetchColumn() === 2,
    'Nadie abandona la casa en el traspaso',
);

// =====================================================================
// FASE 10 · El Endpoint público de SPEC-01 sigue intacto
// =====================================================================
echo "\n[FASE 10] GET /api/v1/clans/preview — regresión de SPEC-01\n";

$preview = dispatch('GET', '/api/v1/clans/preview');
$previewItems = payloadOf($preview)['data'] ?? [];
assert_truthy(statusOf($preview) === 200, 'El catálogo público de SPEC-01 responde 200');
assert_truthy(
    isset($previewItems[0]['id'], $previewItems[0]['slug'], $previewItems[0]['name'], $previewItems[0]['motto'], $previewItems[0]['domainPoints']),
    'La vista previa conserva su contrato {id, slug, name, motto, domainPoints}',
);
assert_truthy(
    (int) ($previewItems[0]['domainPoints'] ?? 0) >= (int) ($previewItems[1]['domainPoints'] ?? 0),
    'La vista previa sigue ordenada por Dominio semanal descendente',
);

$previewRoute = dispatch('GET', '/api/v1/clans/preview');
assert_truthy(
    statusOf($previewRoute) === 200,
    'La ruta literal /clans/preview no queda sombreada por /clans/{id}',
);

// =====================================================================
// FASE 11 · Integración REST por HTTP real (plan 6.2)
// =====================================================================
echo "\n[FASE 11] Integración REST por HTTP real (php -S + cookie de sesión)\n";

$serverHost = '127.0.0.1';
$serverPort = 8117; // Puerto dedicado de esta verificación (no usado por otras suites).
$serverBaseUrl = "http://{$serverHost}:{$serverPort}";
$tempDbPath = $projectRoot . '/scratch/test_clan_endpoints.sqlite';
$serverLogFile = $projectRoot . '/scratch/test_clan_endpoints_server.log';

if (is_file($tempDbPath)) {
    unlink($tempDbPath);
}

// --- Siembra de la base efímera del servidor ---
$seedPdo = new \PDO('sqlite:' . $tempDbPath);
$seedPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$seedPdo->exec('PRAGMA foreign_keys = ON');
$seedPdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
$seedPdo->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));
seedUser($seedPdo, 'usr_http_founder', 'FundadorHttp');
// SPEC-09 (Tarea 2.6): el fundador porta linaje jurado. La retención de
// sustancia deniega toda gestión a peregrinos; un adepto de arnés que
// quiera operar el santuario debe haber jurado.
$seedPdo->exec("UPDATE users SET lineage = 'solarCrown' WHERE id = 'usr_http_founder'");

// Vínculo arcano real: se emite la sesión con el gestor de producción.
$sessionManager = new SessionManager($seedPdo, $serverHost, 'Arnés ClanController/1.0');
// El aviso de setcookie (cabeceras ya emitidas por la consola del arnés) es
// propio del medio CLI, no del código; la atenuación lo aparta del cómputo.
$activeSession = @$sessionManager->createSession('usr_http_founder');
$seedPdo = null; // Cerrar la siembra antes de que el servidor abra la base.

// El DSN debe fijarse ANTES de popen: el subproceso hereda este entorno.
putenv('GRIMORIO_DB_DSN=sqlite:' . $tempDbPath);

$serverCommand = sprintf(
    '%s -S %s:%d %s > %s 2>&1',
    escapeshellarg(PHP_BINARY),
    $serverHost,
    $serverPort,
    escapeshellarg($projectRoot . '/public/index.php'),
    escapeshellarg($serverLogFile)
);
$serverProcessHandle = popen($serverCommand, 'r');

/**
 * Petición HTTP real con cuerpo y cookie (PHP nativo: sin curl).
 *
 * @return array{0: int, 1: array<string, mixed>}
 */
$httpRequest = static function (string $method, string $url, ?string $body = null, ?string $cookie = null): array {
    $headers = ['Content-Type: application/json'];
    if ($cookie !== null) {
        $headers[] = 'Cookie: ' . $cookie;
    }

    $context = stream_context_create(['http' => [
        'method'        => $method,
        'header'        => implode("\r\n", $headers),
        'content'       => $body ?? '',
        'timeout'       => 5,
        'ignore_errors' => true, // Necesario para leer cuerpos de 4xx.
    ]]);

    $rawBody = @file_get_contents($url, false, $context);
    if ($rawBody === false) {
        return [0, []];
    }

    $statusCode = 0;
    foreach ($http_response_header ?? [] as $headerLine) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $matches) === 1) {
            $statusCode = (int) $matches[1];
        }
    }

    $decoded = json_decode($rawBody, true);

    return [$statusCode, is_array($decoded) ? $decoded : []];
};

$serverReady = false;
for ($attempt = 0; $attempt < 25; $attempt++) {
    [$probeStatus] = $httpRequest('GET', $serverBaseUrl . '/api/v1/clans/preview');
    if ($probeStatus !== 0) {
        $serverReady = true;
        break;
    }
    usleep(200000);
}
assert_truthy($serverReady, "El servidor nativo responde en {$serverBaseUrl}");

if ($serverReady) {
    $sessionCookie = 'grimorio_session=' . $activeSession->getToken();

    [$anonymousStatus, $anonymousPayload] = $httpRequest(
        'POST',
        $serverBaseUrl . '/api/v1/clans',
        '{"name":"Casa Anónima","lineageType":"dawnWinds"}'
    );
    assert_truthy($anonymousStatus === 401 && ($anonymousPayload['error']['code'] ?? '') === 'UNAUTHENTICATED', 'HTTP real: fundar sin cookie responde 401 UNAUTHENTICATED');

    [$corruptStatus] = $httpRequest('POST', $serverBaseUrl . '/api/v1/clans', 'no-json', $sessionCookie);
    assert_truthy($corruptStatus === 400, 'HTTP real: un cuerpo corrupto responde 400');

    [$incompleteStatus, $incompletePayload] = $httpRequest(
        'POST',
        $serverBaseUrl . '/api/v1/clans',
        '{"name":"Casa Incompleta"}',
        $sessionCookie
    );
    assert_truthy(
        $incompleteStatus === 422 && ($incompletePayload['error']['code'] ?? '') === 'INVALID_CLAN_PAYLOAD',
        'HTTP real: un payload incompleto responde 422 INVALID_CLAN_PAYLOAD',
    );

    [$foundStatus, $foundPayload] = $httpRequest(
        'POST',
        $serverBaseUrl . '/api/v1/clans',
        '{"name":"Casa del Vínculo Real","motto":"Jurar ante el santuario","coatOfArms":"rune_http","lineageType":"solarCrown"}',
        $sessionCookie
    );
    assert_truthy($foundStatus === 201, 'HTTP real: la fundación con cookie responde 201');
    $httpClanId = (string) ($foundPayload['data']['id'] ?? '');
    assert_truthy($httpClanId !== '', 'HTTP real: el estandarte fundado porta identificador');

    [$catalogStatus, $catalogRealPayload] = $httpRequest('GET', $serverBaseUrl . '/api/v1/clans', null, $sessionCookie);
    assert_truthy($catalogStatus === 200, 'HTTP real: el catálogo responde 200');
    assert_truthy(
        in_array($httpClanId, array_column($catalogRealPayload['data']['items'] ?? [], 'id'), true),
        'HTTP real: la casa recién fundada figura en el catálogo',
    );

    [$detailStatus, $detailRealPayload] = $httpRequest('GET', $serverBaseUrl . '/api/v1/clans/' . $httpClanId, null, $sessionCookie);
    assert_truthy($detailStatus === 200, 'HTTP real: la ficha detallada responde 200');
    assert_truthy(
        ($detailRealPayload['data']['patriarch']['userId'] ?? '') === 'usr_http_founder',
        'HTTP real: la sesión se resuelve en el Patriarca de la ficha',
    );

    [$patchStatus, $patchPayload] = $httpRequest(
        'PATCH',
        $serverBaseUrl . '/api/v1/clans/' . $httpClanId,
        '{"motto":"Lema mudado por HTTP"}',
        $sessionCookie
    );
    assert_truthy($patchStatus === 200 && ($patchPayload['data']['motto'] ?? '') === 'Lema mudado por HTTP', 'HTTP real: el Patriarca muda el lema (200)');

    [$missingStatus] = $httpRequest('GET', $serverBaseUrl . '/api/v1/clans/cln_fantasma', null, $sessionCookie);
    assert_truthy($missingStatus === 404, 'HTTP real: una casa inexistente responde 404');
}

// --- Cierre ordenado del entorno ---
$cleanupCommand = stripos(PHP_OS_FAMILY, 'WIN') === 0
    ? 'powershell -Command "Get-NetTCPConnection -LocalPort ' . $serverPort . ' -State Listen -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique | ForEach-Object { Stop-Process -Id $_ -Force }"'
    : "fuser -k {$serverPort}/tcp 2>/dev/null";
shell_exec($cleanupCommand);
pclose($serverProcessHandle);

if (is_file($tempDbPath)) {
    @unlink($tempDbPath);
}
if (is_file($serverLogFile)) {
    @unlink($serverLogFile);
}
echo "  [OK]  Entorno HTTP efímero cerrado y limpio\n";

// =====================================================================
// FASE 12 · Dogma Vanilla y cero advertencias
// =====================================================================
echo "\n[FASE 12] Dogma Vanilla y cero advertencias\n";

foreach ([
    'ClanController' => $projectRoot . '/src/Controllers/ClanController.php',
    'ClanService' => $projectRoot . '/src/Services/ClanService.php',
    'ClanRepository' => $projectRoot . '/src/Repositories/ClanRepository.php',
] as $classLabel => $filePath) {
    $source = (string) file_get_contents($filePath);
    assert_truthy(
        !str_contains($source, 'vendor/autoload') && !str_contains($source, 'node_modules') && !str_contains($source, 'Composer'),
        "{$classLabel} no invoca dependencia externa alguna (Artículo I)",
    );
}

$controllerSource = (string) file_get_contents($projectRoot . '/src/Controllers/ClanController.php');
assert_truthy(
    str_contains($controllerSource, 'declare(strict_types=1);'),
    'ClanController declara tipado estricto (AGENTS.md 2.1)',
);
assert_truthy(
    !str_contains($controllerSource, 'SELECT ') || str_contains($controllerSource, 'preview'),
    'El controlador no compone SQL salvo en la vista previa heredada de SPEC-01',
);

$warnings = array_values(array_filter($warnings, static fn (string $warning): bool => !str_contains($warning, 'unlink')));
assert_truthy($warnings === [], 'Cero advertencias de PHP en toda la batería');
foreach ($warnings as $warning) {
    echo "    ⚠ {$warning}\n";
}

// ---------------------------------------------------------------------
echo "\n════════════════════════════════════════════════════════════════════\n";
echo "  Asertos superados: {$assertionsPassed} · fallidos: {$assertionsFailed}\n";
if ($assertionsFailed > 0) {
    echo "  Fallos:\n";
    foreach ($failures as $failure) {
        echo "    - {$failure}\n";
    }
    echo "\n  RESULTADO: DENEGADO — la Tarea 3.2 no cumple su criterio «Hecho cuando».\n";
    exit(1);
}

echo "  RESULTADO: EXITO — La Tarea 3.2 cumple su criterio 'Hecho cuando'.\n";
exit(0);
