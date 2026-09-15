<?php

declare(strict_types=1);

/**
 * test_moderation_sovereign_endpoints.php — Verificación de la Tarea 3.3 de TASKS-08.
 *
 * Valida contra el criterio «Hecho cuando» de
 * specs/08-moderation-two-step.tasks.md:
 *   «Las rutas verifican el rol `supremeAdmin`, exigen el texto del edicto
 *    imperial y ejecutan la tarea programada de caducidad tras 90 días.»
 *
 * Estrategia: se despacha por la pila REAL de producción —`public/index.php` y
 * `buildRouter()`— sobre una base SQLite efímera en el directorio temporal del
 * sistema. Las obras llegan a su estado por los servicios de la Fase 2; la
 * caducidad se mide con una obra cuya entrada a la Torre se inscribe NOVENTA Y
 * UN DÍAS atrás mediante la propia API del repositorio, de modo que el letargo
 * lo dispara el reloj del santuario y jamás un instante enviado por el cliente.
 *
 * Fases:
 *   [0] Superficie del controlador y registro de las cuatro rutas.
 *   [1] La Firma Soberana (RF-04.1, RF-04.2, RF-04.5).
 *   [2] El rescate de oficio (RF-04.3).
 *   [3] El destierro póstumo y la deducción de gloria (RF-04.4).
 *   [4] El letargo arcano de noventa días (RF-01.6).
 *   [5] Auditoría estática, Dogma Vanilla y Dualismo Lingüístico.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response/Router/PDO nativos.
 *   - Artículo V: identificadores en inglés camelCase; leyendas en castellano.
 *
 * Uso: php scratch/test_moderation_sovereign_endpoints.php
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
$controllerPath = $projectRoot . '/src/Controllers/SovereignAdminController.php';
$frontControllerPath = $projectRoot . '/public/index.php';
const CRON_SECRET = 'sello-del-letargo-2026';

echo "== VERIFICACION TAREA 3.3: Decretos soberanos y letargo arcano ==\n\n";

// --- FASE 0: Superficie ---
echo "FASE 0: Superficie del controlador y registro de las rutas\n";
assertCondition(is_file($controllerPath), 'Existe src/Controllers/SovereignAdminController.php');

if (!is_file($controllerPath)) {
    echo "\nRESULTADO: FALLO — falta el controlador de la Tarea 3.3 (fase roja del TDD).\n";
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

foreach (['validate', 'rescue', 'archive', 'cronCheckExpiry'] as $endpointMethod) {
    assertCondition(
        str_contains($controllerSource, "function {$endpointMethod}("),
        "Expone el metodo canonico {$endpointMethod}()"
    );
}

$frontSource = (string) file_get_contents($frontControllerPath);
foreach ([
    "/api/v1/moderation/sovereign/validate",
    "/api/v1/moderation/sovereign/rescue",
    "/api/v1/moderation/sovereign/archive",
    "/api/v1/moderation/cron-check-expiry",
] as $routePath) {
    assertCondition(str_contains($frontSource, $routePath), "Registra la ruta {$routePath}");
}

// --- Base efímera y pila de producción ---
$databasePath = sys_get_temp_dir() . '/grimorio_sovereign_endpoints_' . getmypid() . '.sqlite';
@unlink($databasePath);
putenv('GRIMORIO_DB_DSN=sqlite:' . $databasePath);
// El sello se declara ANTES de cargar el front controller: el controlador lo
// lee del entorno al construirse (fallo cerrado si falta).
putenv('GRIMORIO_CRON_SECRET=' . CRON_SECRET);

require_once $frontControllerPath;
$router = buildRouter();

use Grimorio\Controllers\SovereignAdminController;
use Grimorio\Core\Request;
use Grimorio\Database\Connection;
use Grimorio\Models\User;
use Grimorio\Repositories\ImperialDecreeRepository;
use Grimorio\Repositories\SpellReviewRepository;
use Grimorio\Services\AuditService;
use Grimorio\Services\MasterDeliberationService;
use Grimorio\Services\ModerationWorkflowService;
use Grimorio\Services\SovereignAdminService;

$pdo = Connection::getInstance()->getPdo();
$pdo->exec((string) file_get_contents($projectRoot . '/sql/08_moderation_schema.sql'));

$CLOCK = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$STAMP = $CLOCK->format('Y-m-d\TH:i:s\Z');
$NINETY_ONE_DAYS_AGO = $CLOCK->modify('-91 days')->format('Y-m-d\TH:i:s\Z');
$FINGERPRINT = str_repeat('a', 64);

/**
 * Despacha por el router de producción, con usuario, cuerpo y cabeceras.
 *
 * @param array<string, mixed>|null $payload
 * @param array<string, string>     $headers
 */
function dispatch(string $method, string $uri, ?User $user = null, ?array $payload = null, array $headers = []): object
{
    global $router;

    $path = (string) (parse_url($uri, PHP_URL_PATH) ?: $uri);
    $rawBody = $payload === null ? null : (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($rawBody !== null) {
        $headers['Content-Type'] = 'application/json';
    }

    $request = new Request($method, $path, [], $headers, $rawBody);
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
        ':motto'     => 'Lema de prueba del arnes soberano.',
        ':createdAt' => '2026-01-01T00:00:00Z',
        ':arms'      => 'rune_' . $clanId,
        ':lineage'   => $lineage,
        ':mode'      => 'open',
        ':status'    => 'active',
    ]);
}

/** Inscribe una membresía viva del historial de linaje. */
function forgeMembership(PDO $pdo, string $memberId, string $clanId, string $userId, string $joinedAt): void
{
    $statement = $pdo->prepare(
        'INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at)
         VALUES (:id, :clanId, :userId, :role, :joinedAt, NULL)'
    );
    $statement->execute([
        ':id'       => $memberId,
        ':clanId'   => $clanId,
        ':userId'   => $userId,
        ':role'     => 'adept',
        ':joinedAt' => $joinedAt,
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
        ':summary'     => 'Obra de prueba del arnes del Conclave Supremo.',
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

/** Gloria semanal del marcador de una hermandad. */
function weeklyPointsOf(PDO $pdo, string $clanId): int
{
    $statement = $pdo->prepare('SELECT weekly_points FROM clans WHERE id = :clanId');
    $statement->execute([':clanId' => $clanId]);

    return (int) $statement->fetchColumn();
}

/** Estado canónico de una obra, leído de la AUTORIDAD (`spell_reviews`). */
function reviewStatusOf(PDO $pdo, string $spellId): string
{
    $statement = $pdo->prepare('SELECT status FROM spell_reviews WHERE spell_id = :spellId');
    $statement->execute([':spellId' => $spellId]);

    return (string) $statement->fetchColumn();
}

// --- Semilla ---
forgeClan($pdo, 'cln_llama', 'primordialFlame', 'Hermandad de la Llama');
forgeClan($pdo, 'cln_marea', 'celestialTides', 'Hermandad de la Marea');

$authors = [
    'usr_autora_uno'   => 'Autora Primera',
    'usr_autora_dos'   => 'Autora Segunda',
    'usr_autora_tres'  => 'Autora Tercera',
    'usr_autora_cuatro' => 'Autora Cuarta',
    'usr_autora_cinco' => 'Autora Quinta',
    'usr_autora_seis'  => 'Autora Sexta',
];
foreach ($authors as $authorId => $alias) {
    forgeUser($pdo, $authorId, $alias, 'editor', 'cln_llama', $STAMP);
}

forgeUser($pdo, 'usr_supremo', 'Administradora Suprema', 'supremeAdmin', null, $STAMP);
forgeUser($pdo, 'usr_supremo_llama', 'Supremo de la Llama', 'supremeAdmin', 'cln_llama', $STAMP);
forgeUser($pdo, 'usr_autor_supremo', 'Supremo Autor', 'supremeAdmin', 'cln_marea', $STAMP);
forgeUser($pdo, 'usr_editor', 'Editor del Arnes', 'editor', null, $STAMP);
forgeUser($pdo, 'usr_maestro', 'Maestro del Arnes', 'master', null, $STAMP);
forgeUser($pdo, 'usr_maestro_uno', 'Maestra Primera', 'master', null, $STAMP);
forgeUser($pdo, 'usr_maestro_dos', 'Maestra Segunda', 'master', null, $STAMP);
forgeUser($pdo, 'usr_maestro_tres', 'Maestra Tercera', 'master', null, $STAMP);

forgeMembership($pdo, 'mem_supremo_llama', 'cln_llama', 'usr_supremo_llama', '2026-01-01T00:00:00Z');

forgeSpell($pdo, 'spl_firma_soberana', 'Ascua de la Firma Soberana', 'usr_autora_uno', 'cln_llama', '2026-09-01T08:00:00Z');
forgeSpell($pdo, 'spl_borrador', 'Ascua En Gestacion', 'usr_autora_uno', 'cln_llama', '2026-09-02T08:00:00Z');
forgeSpell($pdo, 'spl_husmeada', 'Ascua Husmeada', 'usr_autora_seis', 'cln_llama', '2026-09-09T08:00:00Z');
forgeSpell($pdo, 'spl_del_supremo', 'Ascua del Propio Supremo', 'usr_autor_supremo', 'cln_marea', '2026-09-03T08:00:00Z');
forgeSpell($pdo, 'spl_vetada', 'Ascua Vetada', 'usr_autora_dos', 'cln_llama', '2026-09-04T08:00:00Z');
forgeSpell($pdo, 'spl_vetada_dos', 'Ascua Vetada Segunda', 'usr_autora_seis', 'cln_llama', '2026-09-05T08:00:00Z');
forgeSpell($pdo, 'spl_archivable', 'Ascua Archivable', 'usr_autora_dos', 'cln_marea', '2026-09-06T08:00:00Z');
forgeSpell($pdo, 'spl_archivable_sin', 'Ascua Archivable Sin Deduccion', 'usr_autora_tres', 'cln_llama', '2026-09-07T08:00:00Z');
forgeSpell($pdo, 'spl_letargo', 'Ascua Letargica', 'usr_autora_cuatro', 'cln_llama', '2026-05-01T08:00:00Z');
forgeSpell($pdo, 'spl_reciente', 'Ascua Reciente', 'usr_autora_cinco', 'cln_llama', '2026-09-08T08:00:00Z');

$workflow = new ModerationWorkflowService($pdo);
$deliberation = new MasterDeliberationService($pdo);
$reviews = new SpellReviewRepository($pdo);

// El borrador privado con expediente: la obra creada despues de la Torre, que
// ya tiene libreta pero no deliberacion (RF-04.1 lo excluye de la facultad).
$reviews->createOrUpdateReview('rev_borrador', 'spl_borrador', 'usr_autora_uno', 'draft', $FINGERPRINT, 'cln_llama', 0, null);

// Las obras llegan a su estado por los servicios canónicos.
$workflow->submitToModeration('spl_firma_soberana', 'usr_autora_uno');
$deliberation->signSpell('spl_firma_soberana', 'usr_maestro_uno');
$deliberation->signSpell('spl_firma_soberana', 'usr_maestro_dos');

$workflow->submitToModeration('spl_del_supremo', 'usr_autor_supremo');

$workflow->submitToModeration('spl_vetada', 'usr_autora_dos');
$deliberation->signSpell('spl_vetada', 'usr_maestro_uno');
$workflow->submitToModeration('spl_vetada_dos', 'usr_autora_seis');
$deliberation->signSpell('spl_vetada_dos', 'usr_maestro_uno');
$objection = 'La descripcion lirica presenta anacronismos manifiestos que vulneran el Velo Arcano.';
$deliberation->objectSpell('spl_vetada', 'usr_maestro_dos', $objection);
$deliberation->objectSpell('spl_vetada_dos', 'usr_maestro_dos', $objection);

$workflow->submitToModeration('spl_archivable', 'usr_autora_dos');
$deliberation->signSpell('spl_archivable', 'usr_maestro_uno');
$deliberation->signSpell('spl_archivable', 'usr_maestro_dos');
$deliberation->signSpell('spl_archivable', 'usr_maestro_tres');

$workflow->submitToModeration('spl_archivable_sin', 'usr_autora_tres');
$deliberation->signSpell('spl_archivable_sin', 'usr_maestro_uno');
$deliberation->signSpell('spl_archivable_sin', 'usr_maestro_dos');
$deliberation->signSpell('spl_archivable_sin', 'usr_maestro_tres');

// La obra letárgica entra a la Torre hace NOVENTA Y UN DÍAS, por la API del
// repositorio: nada se retuerce a mano y el reloj lo pone el santuario.
$reviews->createOrUpdateReview('rev_letargo', 'spl_letargo', 'usr_autora_cuatro', 'experimental', $FINGERPRINT, 'cln_llama', 0, $NINETY_ONE_DAYS_AGO);
$workflow->submitToModeration('spl_reciente', 'usr_autora_cinco');

$supreme = loadUser($pdo, 'usr_supremo');
$supremeLlama = loadUser($pdo, 'usr_supremo_llama');
$supremeAuthor = loadUser($pdo, 'usr_autor_supremo');
$editor = loadUser($pdo, 'usr_editor');
$master = loadUser($pdo, 'usr_maestro');

$edict = 'Se constata la excelencia matematica y liturgica de esta obra: queda consagrada de oficio.';
$edictOfTwenty = str_repeat('e', 20);

// --- FASE 1: La Firma Soberana ---
echo "\nFASE 1: La Firma Soberana (RF-04.1, RF-04.2, RF-04.5)\n";

assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/sovereign/validate', null, ['spellId' => 'spl_firma_soberana', 'imperialDecreeText' => $edict])) === 401, 'Sin vinculo arcano la Firma Soberana responde 401');
assertCondition(
    errorCodeOf(dispatch('POST', '/api/v1/moderation/sovereign/validate', $editor, ['spellId' => 'spl_firma_soberana', 'imperialDecreeText' => $edict])) === 'INSUFFICIENT_SOVEREIGN_RANK',
    'Un editor recibe INSUFFICIENT_SOVEREIGN_RANK (403)'
);
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/sovereign/validate', $editor, ['spellId' => 'spl_firma_soberana', 'imperialDecreeText' => $edict])) === 403, 'El 403 de rango se responde con 403');
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/sovereign/validate', $master, ['spellId' => 'spl_firma_soberana', 'imperialDecreeText' => $edict])) === 403, 'Un Maestro de la Torre tampoco dicta: 403');
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/sovereign/validate', $supreme, ['imperialDecreeText' => $edict])) === 400, 'Un decreto sin objetivo responde 400');
assertCondition(errorCodeOf(dispatch('POST', '/api/v1/moderation/sovereign/validate', $supreme, ['imperialDecreeText' => $edict])) === 'INVALID_REQUEST_BODY', 'El 400 declara INVALID_REQUEST_BODY');
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/sovereign/validate', $supreme, ['spellId' => 'spl_fantasma', 'imperialDecreeText' => $edict])) === 404, 'Una obra sin expediente responde 404');

$briefDecree = dispatch('POST', '/api/v1/moderation/sovereign/validate', $supreme, ['spellId' => 'spl_firma_soberana', 'imperialDecreeText' => str_repeat('e', 19)]);
assertCondition(statusOf($briefDecree) === 422, 'Un edicto de diecinueve caracteres responde 422 (RF-04.5)');
assertCondition(errorCodeOf($briefDecree) === 'IMPERIAL_DECREE_TOO_SHORT', 'El 422 declara IMPERIAL_DECREE_TOO_SHORT');
assertCondition(
    statusOf(dispatch('POST', '/api/v1/moderation/sovereign/validate', $supreme, ['spellId' => 'spl_firma_soberana', 'imperialDecreeText' => str_repeat(' ', 40)])) === 422,
    'Cuarenta espacios no son un edicto: 422'
);
assertCondition(
    statusOf(dispatch('POST', '/api/v1/moderation/sovereign/validate', $supreme, ['spellId' => 'spl_borrador', 'imperialDecreeText' => $edict])) === 400,
    'Un borrador privado queda fuera de la facultad: 400 (Art. II.3)'
);
assertCondition(
    statusOf(dispatch('POST', '/api/v1/moderation/sovereign/validate', $supreme, ['spellId' => 'spl_husmeada', 'imperialDecreeText' => $edict])) === 404,
    'Una obra sin expediente en la Torre responde 404, jamas un 400 de estado'
);
assertCondition(
    errorCodeOf(dispatch('POST', '/api/v1/moderation/sovereign/validate', $supreme, ['spellId' => 'spl_borrador', 'imperialDecreeText' => $edict])) === 'CANNOT_SOVEREIGN_VALIDATE_NON_EXPERIMENTAL',
    'El 400 declara CANNOT_SOVEREIGN_VALIDATE_NON_EXPERIMENTAL'
);

$ownClanVeto = dispatch('POST', '/api/v1/moderation/sovereign/validate', $supremeLlama, ['spellId' => 'spl_firma_soberana', 'imperialDecreeText' => $edict]);
assertCondition(statusOf($ownClanVeto) === 403, 'El Supremo del linaje de la obra queda vetado: 403 (RF-04.2)');
assertCondition(errorCodeOf($ownClanVeto) === 'SOVEREIGN_OWN_CLAN_VETO', 'El 403 declara SOVEREIGN_OWN_CLAN_VETO');
$selfValidation = dispatch('POST', '/api/v1/moderation/sovereign/validate', $supremeAuthor, ['spellId' => 'spl_del_supremo', 'imperialDecreeText' => $edict]);
assertCondition(statusOf($selfValidation) === 403, 'La propia pluma no se consagra de oficio: 403 (RF-03.2)');
assertCondition(errorCodeOf($selfValidation) === 'SELF_VALIDATION_PROHIBITED', 'El 403 declara SELF_VALIDATION_PROHIBITED');
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM sovereign_decrees WHERE spell_id IN ('spl_firma_soberana', 'spl_del_supremo')")->fetchColumn() === 0,
    'Ningun veto deja decreto inscrito: la Bitacora no miente'
);

$weeklyBefore = weeklyPointsOf($pdo, 'cln_llama');
$sovereignValidation = dispatch('POST', '/api/v1/moderation/sovereign/validate', $supreme, ['spellId' => 'spl_firma_soberana', 'imperialDecreeText' => $edictOfTwenty]);
$sovereignPayload = payloadOf($sovereignValidation);
assertCondition(statusOf($sovereignValidation) === 200, 'El edicto de EXACTAMENTE veinte caracteres se admite: 200');
assertCondition(
    (string) ($sovereignPayload['data']['review']['status'] ?? '') === 'validated'
    && (int) ($sovereignPayload['data']['review']['signaturesCount'] ?? -1) === 2,
    'La obra queda consagrada CONSERVANDO sus dos avales reales: el soberano no inventa un tercero'
);
assertCondition(
    (string) ($sovereignPayload['data']['decree']['imperialDecreeText'] ?? '') === $edictOfTwenty
    && (string) ($sovereignPayload['data']['decree']['decreeType'] ?? '') === 'sovereignValidation',
    'El Edicto Imperial viaja INTEGRO con su tipo canonico (RF-04.5)'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM sovereign_decrees WHERE spell_id = 'spl_firma_soberana'")->fetchColumn() === 1,
    'El decreto queda inscrito en el libro de decretos'
);
assertCondition(weeklyPointsOf($pdo, 'cln_llama') > $weeklyBefore, 'La gloria se acredita al linaje ORIGINARIO de la obra (RF-05.3)');
assertCondition(
    reviewStatusOf($pdo, 'spl_firma_soberana') === 'validated'
    && (string) $pdo->query("SELECT status FROM spells WHERE id = 'spl_firma_soberana'")->fetchColumn() === 'validated',
    'La autoridad y el espejo quedan consagrados en el mismo gesto'
);
assertCondition(
    statusOf(dispatch('POST', '/api/v1/moderation/sovereign/validate', $supreme, ['spellId' => 'spl_firma_soberana', 'imperialDecreeText' => $edict])) === 400,
    'Una obra ya consagrada no admite otra Firma Soberana: 400'
);

// --- FASE 2: El rescate ---
echo "\nFASE 2: El rescate de oficio (RF-04.3)\n";

assertCondition(
    errorCodeOf(dispatch('POST', '/api/v1/moderation/sovereign/rescue', $supreme, ['spellId' => 'spl_vetada', 'targetStatus' => 'archived', 'imperialDecreeText' => $edict])) === 'SOVEREIGN_RESCUE_INVALID_TARGET',
    'Un destino ajeno al canon responde 400 SOVEREIGN_RESCUE_INVALID_TARGET'
);
assertCondition(
    errorCodeOf(dispatch('POST', '/api/v1/moderation/sovereign/rescue', $supreme, ['spellId' => 'spl_reciente', 'targetStatus' => 'experimental', 'imperialDecreeText' => $edict])) === 'CANNOT_SOVEREIGN_RESCUE_NON_REJECTED',
    'Una obra en deliberacion no se rescata: 400 CANNOT_SOVEREIGN_RESCUE_NON_REJECTED'
);

// Una firma colada que ninguna version admisible podria sostener.
$pdo->exec(
    "INSERT INTO master_signatures (id, spell_id, master_id, master_clan_id, signed_at, is_revoked)
     VALUES ('sig_colada', 'spl_vetada', 'usr_maestro_tres', NULL, '{$STAMP}', 0)"
);
$pdo->exec("UPDATE spell_reviews SET signatures_count = 1 WHERE spell_id = 'spl_vetada'");
$rejectedAt = (string) $pdo->query("SELECT rejected_at FROM spell_reviews WHERE spell_id = 'spl_vetada'")->fetchColumn();

$rescued = dispatch('POST', '/api/v1/moderation/sovereign/rescue', $supreme, ['spellId' => 'spl_vetada', 'targetStatus' => 'experimental', 'imperialDecreeText' => $edict]);
$rescuedPayload = payloadOf($rescued);
assertCondition(statusOf($rescued) === 200, 'El rescate a la deliberacion responde 200');
assertCondition(
    (string) ($rescuedPayload['data']['review']['status'] ?? '') === 'experimental'
    && (int) ($rescuedPayload['data']['review']['signaturesCount'] ?? -1) === 0,
    'La obra vuelve a deliberar con CERO firmas (RF-04.3)'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM master_signatures WHERE id = 'sig_colada' AND is_revoked = 1 AND revocation_reason = 'review_rejected'")->fetchColumn() === 1,
    'La firma colada cae con su motivo canonico: deliberacion limpia'
);
assertCondition(
    (string) $pdo->query("SELECT rejected_at FROM spell_reviews WHERE spell_id = 'spl_vetada'")->fetchColumn() === $rejectedAt,
    'El expediente conserva la memoria de su veto anterior'
);
assertCondition(
    (string) ($rescuedPayload['data']['decree']['decreeType'] ?? '') === 'rescueToExperimental',
    'El rescate inscribe su decreto con el tipo canonico'
);

$weeklyBeforeRescue = weeklyPointsOf($pdo, 'cln_llama');
$rescueToTome = dispatch('POST', '/api/v1/moderation/sovereign/rescue', $supreme, ['spellId' => 'spl_vetada_dos', 'targetStatus' => 'validated', 'imperialDecreeText' => $edict]);
assertCondition(statusOf($rescueToTome) === 200, 'El rescate directo al Tomo responde 200');
assertCondition(
    (string) (payloadOf($rescueToTome)['data']['review']['status'] ?? '') === 'validated',
    'La obra vetada entra consagrada al Gran Tomo (RF-04.3)'
);
assertCondition(
    weeklyPointsOf($pdo, 'cln_llama') > $weeklyBeforeRescue,
    'El rescate ACREDITA la gloria que el veto habia negado'
);

// --- FASE 3: El destierro póstumo ---
echo "\nFASE 3: El destierro y la deduccion de gloria (RF-04.4)\n";

assertCondition(
    errorCodeOf(dispatch('POST', '/api/v1/moderation/sovereign/archive', $supreme, ['spellId' => 'spl_reciente', 'imperialDecreeText' => $edict])) === 'CANNOT_SOVEREIGN_ARCHIVE_NON_VALIDATED',
    'Una obra no consagrada no se destierra: 400 CANNOT_SOVEREIGN_ARCHIVE_NON_VALIDATED'
);
assertCondition(
    statusOf(dispatch('POST', '/api/v1/moderation/sovereign/archive', $supreme, ['spellId' => 'spl_archivable', 'imperialDecreeText' => $edict, 'deductPoints' => 'true'])) === 400,
    'Una orden de deduccion que no es booleano responde 400, jamas se coacciona en silencio'
);
assertCondition(
    statusOf(dispatch('POST', '/api/v1/moderation/sovereign/archive', $supreme, ['spellId' => 'spl_archivable', 'imperialDecreeText' => str_repeat('e', 19)])) === 422,
    'El destierro tambien exige su Edicto Imperial (RF-04.5)'
);

$weeklyOfLlamaBeforeDeduction = weeklyPointsOf($pdo, 'cln_llama');
$archivedWithoutDeduction = dispatch('POST', '/api/v1/moderation/sovereign/archive', $supreme, ['spellId' => 'spl_archivable_sin', 'imperialDecreeText' => $edict, 'deductPoints' => false]);
$archivedPayload = payloadOf($archivedWithoutDeduction);
assertCondition(statusOf($archivedWithoutDeduction) === 200, 'El destierro responde 200');
assertCondition(
    (string) ($archivedPayload['data']['review']['status'] ?? '') === 'archived'
    && (int) ($archivedPayload['data']['review']['signaturesCount'] ?? -1) === 0,
    'La obra cae del canon y sus avales se anulan'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_archivable_sin' AND is_revoked = 1 AND revocation_reason = 'sovereign_archive'")->fetchColumn() === 3,
    'Los tres avales caen con el motivo canonico `sovereign_archive`'
);
assertCondition(
    weeklyPointsOf($pdo, 'cln_llama') === $weeklyOfLlamaBeforeDeduction
    && (int) ($archivedPayload['data']['gloryDeductionOrdered'] ?? true) === 0,
    'Sin orden de deduccion la gloria permanece intacta'
);

$weeklyOfMareaCredited = weeklyPointsOf($pdo, 'cln_marea');
assertCondition($weeklyOfMareaCredited > 0, 'La obra archivable acredito su gloria al consagrarse');
$archivedWithDeduction = dispatch('POST', '/api/v1/moderation/sovereign/archive', $supreme, ['spellId' => 'spl_archivable', 'imperialDecreeText' => $edict, 'deductPoints' => true]);
assertCondition(statusOf($archivedWithDeduction) === 200, 'El destierro con deduccion responde 200');
assertCondition(
    weeklyPointsOf($pdo, 'cln_marea') === 0,
    'La deduccion drena del marcador semanal EXACTAMENTE la gloria que la obra habia acreditado'
);
assertCondition(
    weeklyPointsOf($pdo, 'cln_marea') >= 0,
    'La casa jamas queda en numeros rojos'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'SOVEREIGN_POINTS_DEDUCTED'")->fetchColumn() >= 1,
    'La Bitacora publica la aritmetica exacta de la deduccion (Art. III.3)'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM sovereign_decrees WHERE spell_id = 'spl_archivable' AND decree_type = 'revokeAndArchive'")->fetchColumn() === 1,
    'El destierro inscribe su decreto con el tipo canonico'
);

// --- FASE 4: El letargo arcano ---
echo "\nFASE 4: La caducidad por letargo (RF-01.6)\n";

assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/cron-check-expiry')) === 401, 'Sin el sello del custodio el letargo responde 401');
assertCondition(
    errorCodeOf(dispatch('POST', '/api/v1/moderation/cron-check-expiry')) === 'CRON_SECRET_REQUIRED',
    'El 401 declara CRON_SECRET_REQUIRED'
);
assertCondition(
    statusOf(dispatch('POST', '/api/v1/moderation/cron-check-expiry', null, null, ['X-Arcane-Cron-Secret' => 'sello-ajeno'])) === 403,
    'Un sello ajeno responde 403'
);
assertCondition(
    errorCodeOf(dispatch('POST', '/api/v1/moderation/cron-check-expiry', null, null, ['X-Arcane-Cron-Secret' => 'sello-ajeno'])) === 'CRON_SECRET_INVALID',
    'El 403 declara CRON_SECRET_INVALID'
);

// Fallo cerrado: un santuario que no declaro sello no invoca el letargo jamas.
$seallessController = new SovereignAdminController(
    new SovereignAdminService($pdo),
    new ModerationWorkflowService($pdo),
    new ImperialDecreeRepository($pdo, new AuditService($pdo)),
    '',
);
$seallessResponse = $seallessController->cronCheckExpiry(
    new Request('POST', '/api/v1/moderation/cron-check-expiry', [], ['X-Arcane-Cron-Secret' => 'cualquiera'])
);
assertCondition(statusOf($seallessResponse) === 403, 'Sin sello declarado en el entorno la ruta se cierra a cal y canto (403)');

// Una firma vieja sobre la obra letargica: el letargo la mide desde la ULTIMA
// resonancia, y un aval de noventa y un dias atras sigue siendo letargo.
$pdo->exec(
    "INSERT INTO master_signatures (id, spell_id, master_id, master_clan_id, signed_at, is_revoked)
     VALUES ('sig_vieja', 'spl_letargo', 'usr_maestro_uno', NULL, '{$NINETY_ONE_DAYS_AGO}', 0)"
);
$pdo->exec("UPDATE spell_reviews SET signatures_count = 1 WHERE spell_id = 'spl_letargo'");

$capacityBefore = (new ModerationWorkflowService($pdo))->remainingCapacity('usr_autora_cuatro');
$cronResponse = dispatch('POST', '/api/v1/moderation/cron-check-expiry', null, null, ['X-Arcane-Cron-Secret' => CRON_SECRET]);
$cronPayload = payloadOf($cronResponse);
assertCondition(statusOf($cronResponse) === 200, 'Con el sello del custodio el barrido responde 200');
assertCondition(
    (int) ($cronPayload['data']['expiredCount'] ?? -1) === 1
    && (string) ($cronPayload['data']['expired'][0]['spellId'] ?? '') === 'spl_letargo',
    'Solo la obra letargica caduca: el barrido no toca lo reciente'
);
assertCondition(
    (string) ($cronPayload['data']['legend'] ?? '') === 'Letargo Arcano por Falta de Resonancia Colegiada'
    && (int) ($cronPayload['data']['staleDays'] ?? 0) === 90,
    'El acta declara la leyenda canonica y el umbral de noventa dias (Art. IV)'
);
assertCondition(
    reviewStatusOf($pdo, 'spl_letargo') === 'rejected'
    && (string) $pdo->query("SELECT status FROM spells WHERE id = 'spl_letargo'")->fetchColumn() === 'rejected',
    'La obra letargica pasa a `rejected` en la autoridad y en el espejo'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM master_signatures WHERE id = 'sig_vieja' AND is_revoked = 1 AND revocation_reason = 'review_expired'")->fetchColumn() === 1,
    'El aval viejo cae con el motivo canonico `review_expired` (RF-01.6)'
);
assertCondition(
    (new ModerationWorkflowService($pdo))->remainingCapacity('usr_autora_cuatro') === $capacityBefore + 1,
    'El letargo LIBERA el cupo del autor en el mismo barrido (RF-01.5)'
);
assertCondition(
    reviewStatusOf($pdo, 'spl_reciente') === 'experimental',
    'La obra con resonancia reciente sigue en deliberacion'
);
$secondSweep = payloadOf(dispatch('POST', '/api/v1/moderation/cron-check-expiry', null, null, ['X-Arcane-Cron-Secret' => CRON_SECRET]));
assertCondition((int) ($secondSweep['data']['expiredCount'] ?? -1) === 0, 'El barrido es idempotente: la segunda pasada no caduca nada');

// --- FASE 5: Auditoría estática y Dogma ---
echo "\nFASE 5: Auditoria estatica, Dogma Vanilla y Dualismo Linguistico\n";

assertCondition(
    !str_contains($controllerSource, 'new PDO')
    && preg_match('/\b(INSERT INTO|UPDATE |DELETE FROM)\b/', $controllerSource) !== 1,
    'El controlador no abre conexiones ni escribe SQL: delega en los servicios (Art. I)'
);
assertCondition(
    str_contains($controllerSource, 'hash_equals')
    && str_contains($controllerSource, "cronSecret === ''"),
    'El sello del cron se compara en tiempo constante y se niega en seco sin sello declarado (RNF-01)'
);
assertCondition(
    !str_contains($controllerSource, 'DateTimeImmutable'),
    'El letargo no lee reloj alguno: lo mide el santuario, jamas el cliente ni este controlador'
);
assertCondition(
    !str_contains($controllerSource, 'clan_members')
    && !str_contains($controllerSource, 'deductOriginGlory'),
    'El veto del linaje propio y la deduccion de gloria los dicta el servicio (Art. III)'
);
assertCondition(
    !file_exists($projectRoot . '/package.json') && !file_exists($projectRoot . '/composer.json'),
    'El santuario no declara dependencias npm ni Composer (RNF-05)'
);
assertCondition(
    !file_exists($projectRoot . '/scratch/sovereign_endpoints.sqlite'),
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
    echo "\nRESULTADO: FALLO — la Tarea 3.3 no cumple su criterio «Hecho cuando».\n";
    exit(1);
}

echo "\nRESULTADO: EXITO — los decretos exigen el rango supremo y su Edicto\n";
echo "Imperial, y el letargo caduca las obras tras noventa dias (Tarea 3.3).\n";
exit(0);
