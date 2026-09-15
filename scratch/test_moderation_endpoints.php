<?php

declare(strict_types=1);

/**
 * test_moderation_endpoints.php — Verificación de la Tarea 3.1 de TASKS-08.
 *
 * Valida contra el criterio «Hecho cuando» de
 * specs/08-moderation-two-step.tasks.md:
 *   «Las rutas responden con los códigos HTTP 200, 400, 403, 409 y devuelven
 *    el catálogo del Atrio con las insignias de advertencia litúrgica.»
 *
 * Estrategia: se despacha por la pila REAL de producción —se carga
 * `public/index.php` y se invoca `buildRouter()`— sobre una base SQLite
 * efímera en el directorio temporal del sistema, jamás dentro del repositorio.
 * Las obras llegan a su estado por los servicios canónicos (elevación, firmas
 * y dictamen), no por escrituras a mano, de modo que el espejo `spells` y la
 * autoridad `spell_reviews` están sincronizados por el propio código.
 *
 * Fases:
 *   [0] Superficie del controlador y registro de las cuatro rutas.
 *   [1] La elevación (RF-01.2): 200, 400, 401, 403, 404 y 409.
 *   [2] La retirada (RF-01.3): 200 con anulación de avales, 409 y 404.
 *   [3] La re-apertura (RF-01.4): 200 con el dictamen a la vista y 400.
 *   [4] El Atrio de Pruebas (RF-05.1, RF-05.3): catálogo público, insignia
 *       de advertencia, indicador 0/3, filtros, paginación y censo.
 *   [5] Auditoría estática, Dogma Vanilla y Dualismo Lingüístico.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response/Router/PDO nativos; cero
 *     librerías y cero dependencias npm.
 *   - Artículo V: identificadores en inglés camelCase; leyendas en castellano.
 *
 * Uso: php scratch/test_moderation_endpoints.php
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
$controllerPath = $projectRoot . '/src/Controllers/ModerationController.php';
$frontControllerPath = $projectRoot . '/public/index.php';

echo "== VERIFICACION TAREA 3.1: Controlador del flujo de moderacion y el Atrio ==\n\n";

// --- FASE 0: Superficie ---
echo "FASE 0: Superficie del controlador y registro de las rutas\n";
assertCondition(is_file($controllerPath), 'Existe src/Controllers/ModerationController.php');
assertCondition(is_file($projectRoot . '/src/Repositories/SpellReviewRepository.php'), 'Existe el repositorio del expediente');

if (!is_file($controllerPath)) {
    echo "\nRESULTADO: FALLO — falta el controlador de la Tarea 3.1 (fase roja del TDD).\n";
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

foreach (['submit', 'withdraw', 'reopen', 'experimental'] as $endpointMethod) {
    assertCondition(
        str_contains($controllerSource, "function {$endpointMethod}("),
        "Expone el metodo canonico {$endpointMethod}()"
    );
}

$frontSource = (string) file_get_contents($frontControllerPath);
foreach ([
    "/api/v1/moderation/spells/{id}/submit",
    "/api/v1/moderation/spells/{id}/withdraw",
    "/api/v1/moderation/spells/{id}/reopen",
    "/api/v1/moderation/experimental",
] as $routePath) {
    assertCondition(str_contains($frontSource, $routePath), "Registra la ruta {$routePath}");
}

// --- Base efímera y pila de producción ---
$databasePath = sys_get_temp_dir() . '/grimorio_moderation_endpoints_' . getmypid() . '.sqlite';
@unlink($databasePath);
putenv('GRIMORIO_DB_DSN=sqlite:' . $databasePath);

require_once $frontControllerPath;
$router = buildRouter();

use Grimorio\Core\Request;
use Grimorio\Database\Connection;
use Grimorio\Models\User;
use Grimorio\Services\MasterDeliberationService;
use Grimorio\Services\ModerationWorkflowService;

$pdo = Connection::getInstance()->getPdo();
$pdo->exec((string) file_get_contents($projectRoot . '/sql/08_moderation_schema.sql'));

$NOW_UTC = '2026-09-15T10:00:00Z';
$NOW = new DateTimeImmutable($NOW_UTC);
$FINGERPRINT = str_repeat('a', 64);

/**
 * Despacha por el router de producción, con usuario y cuerpo inyectables.
 *
 * @param array<string, string>      $headers
 * @param array<string, mixed>|null  $payload
 */
function dispatch(string $method, string $uri, ?User $user = null, ?array $payload = null, array $headers = []): object
{
    global $router;

    $path = (string) (parse_url($uri, PHP_URL_PATH) ?: $uri);
    $queryString = (string) (parse_url($uri, PHP_URL_QUERY) ?: '');
    $queryParams = [];
    if ($queryString !== '') {
        parse_str($queryString, $queryParams);
    }

    $rawBody = $payload === null ? null : (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($rawBody !== null) {
        $headers['Content-Type'] = 'application/json';
    }

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
        ':motto'     => 'Lema de prueba del arnes de endpoints.',
        ':createdAt' => '2026-01-01T00:00:00Z',
        ':arms'      => 'rune_' . $clanId,
        ':lineage'   => $lineage,
        ':mode'      => 'open',
        ':status'    => 'active',
    ]);
}

/** Inscribe un conjuro con su forma arcana mínima. */
function forgeSpell(
    PDO $pdo,
    string $spellId,
    string $name,
    string $authorId,
    string $clanId,
    string $affinity,
    string $school,
    string $createdAt,
): void {
    $fingerprint = str_repeat('a', 64);
    $statement = $pdo->prepare(
        'INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, mana_cost, circle,
                             math_fingerprint, clan_id, summary, created_at, updated_at, status, signatures_count,
                             damage, range_type, has_verbal)
         VALUES (:id, :slug, :name, :authorId, :school, :affinity, 100, 1,
                 :fingerprint, :clanId, :summary, :createdAt, :createdAt, \'draft\', 0,
                 20, \'short\', 1)'
    );
    $statement->execute([
        ':id'          => $spellId,
        ':slug'        => $spellId,
        ':name'        => $name,
        ':authorId'    => $authorId,
        ':school'      => $school,
        ':affinity'    => $affinity,
        ':fingerprint' => $fingerprint,
        ':clanId'      => $clanId,
        ':summary'     => 'Obra de prueba del arnes de endpoints de moderacion.',
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

// Semilla del arnés: hermandades, autores, jueces y obras.
forgeClan($pdo, 'cln_llama', 'primordialFlame', 'Hermandad de la Llama');
forgeClan($pdo, 'cln_marea', 'celestialTides', 'Hermandad de la Marea');

forgeUser($pdo, 'usr_autora', 'Autora del Arnes', 'editor', 'cln_llama', $NOW_UTC);
forgeUser($pdo, 'usr_cronista', 'Cronista del Arnes', 'editor', 'cln_marea', $NOW_UTC);
forgeUser($pdo, 'usr_lector', 'Lector del Arnes', 'reader', null, $NOW_UTC);
forgeUser($pdo, 'usr_maestra_uno', 'Maestra Primera', 'master', null, $NOW_UTC);
forgeUser($pdo, 'usr_maestra_dos', 'Maestra Segunda', 'master', null, $NOW_UTC);
forgeUser($pdo, 'usr_maestra_tres', 'Maestra Tercera', 'master', null, $NOW_UTC);

forgeSpell($pdo, 'spl_elegida', 'Ascua Elegida', 'usr_autora', 'cln_llama', 'fire', 'evocation', '2026-09-01T08:00:00Z');
forgeSpell($pdo, 'spl_retirada', 'Ascua Retirada', 'usr_autora', 'cln_llama', 'fire', 'evocation', '2026-09-02T08:00:00Z');
forgeSpell($pdo, 'spl_consagrada', 'Ascua Consagrada', 'usr_autora', 'cln_llama', 'fire', 'evocation', '2026-09-03T08:00:00Z');
forgeSpell($pdo, 'spl_vetada', 'Ascua Vetada', 'usr_autora', 'cln_llama', 'ice', 'abjuration', '2026-09-04T08:00:00Z');
forgeSpell($pdo, 'spl_borrador_propio', 'Ascua En Gestacion', 'usr_autora', 'cln_llama', 'fire', 'evocation', '2026-09-05T08:00:00Z');
forgeSpell($pdo, 'spl_ajena', 'Ascua Ajena', 'usr_cronista', 'cln_marea', 'water', 'conjuration', '2026-09-06T08:00:00Z');
forgeSpell($pdo, 'spl_cupo_uno', 'Ascua del Cupo Primera', 'usr_cronista', 'cln_marea', 'water', 'conjuration', '2026-09-07T08:00:00Z');
forgeSpell($pdo, 'spl_cupo_dos', 'Ascua del Cupo Segunda', 'usr_cronista', 'cln_marea', 'water', 'conjuration', '2026-09-08T08:00:00Z');
forgeSpell($pdo, 'spl_cupo_tres', 'Ascua del Cupo Tercera', 'usr_cronista', 'cln_marea', 'water', 'conjuration', '2026-09-09T08:00:00Z');
forgeSpell($pdo, 'spl_cupo_cuarta', 'Ascua del Cupo Cuarta', 'usr_cronista', 'cln_marea', 'water', 'conjuration', '2026-09-10T08:00:00Z');

$workflow = new ModerationWorkflowService($pdo);
$deliberation = new MasterDeliberationService($pdo);

$autora = loadUser($pdo, 'usr_autora');
$cronista = loadUser($pdo, 'usr_cronista');
$lector = loadUser($pdo, 'usr_lector');

// --- FASE 1: La elevación ---
echo "\nFASE 1: La elevacion a la Torre (RF-01.2, RF-01.5, RNF-04)\n";

$anonymousSubmit = dispatch('POST', '/api/v1/moderation/spells/spl_elegida/submit');
assertCondition(statusOf($anonymousSubmit) === 401, 'Sin vinculo arcano la elevacion responde 401');
assertCondition(errorCodeOf($anonymousSubmit) === 'UNAUTHENTICATED', 'El 401 porta el codigo canonico UNAUTHENTICATED');

$missingSubmit = dispatch('POST', '/api/v1/moderation/spells/spl_fantasma/submit', $autora);
assertCondition(statusOf($missingSubmit) === 404, 'Un conjuro inexistente responde 404');
assertCondition(errorCodeOf($missingSubmit) === 'SPELL_NOT_FOUND', 'El 404 porta el codigo canonico SPELL_NOT_FOUND');

$readerSubmit = dispatch('POST', '/api/v1/moderation/spells/spl_elegida/submit', $lector);
assertCondition(statusOf($readerSubmit) === 403, 'Un lector no eleva obras: 403 (RF-01.2)');
assertCondition(errorCodeOf($readerSubmit) === 'INSUFFICIENT_RANK', 'El 403 declara INSUFFICIENT_RANK');

$elevated = dispatch('POST', '/api/v1/moderation/spells/spl_elegida/submit', $autora);
$elevatedPayload = payloadOf($elevated);
assertCondition(statusOf($elevated) === 200, 'La elevacion legitima responde 200');
assertCondition(
    ($elevatedPayload['data']['review']['status'] ?? '') === 'experimental'
    && (int) ($elevatedPayload['data']['review']['signaturesCount'] ?? -1) === 0,
    'La obra transiciona a experimental con el contador en 0/3'
);
assertCondition(
    (string) ($elevatedPayload['data']['review']['signaturesIndicator'] ?? '') === '0/3',
    'El indicador ceremonial viaja como «0/3» (RF-05.1)'
);
assertCondition(
    strlen((string) ($elevatedPayload['data']['review']['mathFingerprint'] ?? '')) === 64,
    'La huella matematica de 64 caracteres queda sellada por el backend (Art. II)'
);
assertCondition(
    (int) ($elevatedPayload['data']['remainingCapacity'] ?? -1) === 2,
    'El cupo restante del autor baja a dos plazas (RF-01.2)'
);
assertCondition(
    (string) $pdo->query("SELECT status FROM spells WHERE id = 'spl_elegida'")->fetchColumn() === 'experimental',
    'El espejo de `spells` sigue a la autoridad en el mismo gesto'
);

$resubmit = dispatch('POST', '/api/v1/moderation/spells/spl_elegida/submit', $autora);
assertCondition(statusOf($resubmit) === 400, 'Reelevar lo ya elevado responde 400');
assertCondition(errorCodeOf($resubmit) === 'SPELL_NOT_IN_DRAFT', 'El 400 declara SPELL_NOT_IN_DRAFT');

// El cupo: tres obras de un mismo autor y la cuarta rechazada.
$workflow->submitToModeration('spl_cupo_uno', 'usr_cronista', $NOW);
$workflow->submitToModeration('spl_cupo_dos', 'usr_cronista', $NOW);
$workflow->submitToModeration('spl_cupo_tres', 'usr_cronista', $NOW);
$overflow = dispatch('POST', '/api/v1/moderation/spells/spl_cupo_cuarta/submit', $cronista);
assertCondition(statusOf($overflow) === 409, 'La cuarta obra concurrente responde 409 (RNF-04)');
assertCondition(errorCodeOf($overflow) === 'TOWER_CAPACITY_EXCEEDED', 'El 409 declara TOWER_CAPACITY_EXCEEDED');
assertCondition(
    (string) $pdo->query("SELECT status FROM spells WHERE id = 'spl_cupo_cuarta'")->fetchColumn() === 'draft',
    'La cuarta obra permanece intacta en la libreta: el rechazo no deja rastro'
);

// --- FASE 2: La retirada ---
echo "\nFASE 2: La retirada a la libreta privada (RF-01.3)\n";

$workflow->submitToModeration('spl_retirada', 'usr_autora', $NOW);
$deliberation->signSpell('spl_retirada', 'usr_maestra_uno', null, $NOW);
$deliberation->signSpell('spl_retirada', 'usr_maestra_dos', null, $NOW);

$anonymousWithdraw = dispatch('POST', '/api/v1/moderation/spells/spl_retirada/withdraw');
assertCondition(statusOf($anonymousWithdraw) === 401, 'Sin vinculo arcano la retirada responde 401');

$foreignWithdraw = dispatch('POST', '/api/v1/moderation/spells/spl_retirada/withdraw', $cronista);
assertCondition(statusOf($foreignWithdraw) === 404, 'Retirar una obra ajena responde 404 (el dominio no revela existencia)');

$withdrawn = dispatch('POST', '/api/v1/moderation/spells/spl_retirada/withdraw', $autora);
$withdrawnPayload = payloadOf($withdrawn);
assertCondition(statusOf($withdrawn) === 200, 'La retirada legitima responde 200');
assertCondition(
    ($withdrawnPayload['data']['review']['status'] ?? '') === 'draft'
    && (int) ($withdrawnPayload['data']['review']['signaturesCount'] ?? -1) === 0,
    'La obra vuelve a borrador con el contador reiniciado'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_retirada' AND is_revoked = 1 AND revocation_reason = 'author_withdrawn'")->fetchColumn() === 2,
    'Los DOS avales previos caen con el motivo canonico `author_withdrawn` (RF-01.3)'
);

// La obra consagrada no se retira: la consagracion es irrevocable.
$workflow->submitToModeration('spl_consagrada', 'usr_autora', $NOW);
$deliberation->signSpell('spl_consagrada', 'usr_maestra_uno', null, $NOW);
$deliberation->signSpell('spl_consagrada', 'usr_maestra_dos', null, $NOW);
$deliberation->signSpell('spl_consagrada', 'usr_maestra_tres', null, $NOW);
assertCondition(
    (string) $pdo->query("SELECT status FROM spells WHERE id = 'spl_consagrada'")->fetchColumn() === 'validated',
    'Tres avales consagran la obra antes de la retirada'
);

$irrevocable = dispatch('POST', '/api/v1/moderation/spells/spl_consagrada/withdraw', $autora);
assertCondition(statusOf($irrevocable) === 409, 'Retirar una obra consagrada responde 409 (consagracion irrevocable)');
assertCondition(errorCodeOf($irrevocable) === 'SPELL_NOT_UNDER_REVIEW', 'El 409 declara SPELL_NOT_UNDER_REVIEW');

// --- FASE 3: La re-apertura ---
echo "\nFASE 3: La re-apertura de la obra vetada (RF-01.4)\n";

$workflow->submitToModeration('spl_vetada', 'usr_autora', $NOW);
$objectionReason = 'La descripcion lirica presenta anacronismos manifiestos que vulneran el Velo Arcano.';
$deliberation->objectSpell('spl_vetada', 'usr_maestra_uno', $objectionReason, $NOW);

$anonymousReopen = dispatch('POST', '/api/v1/moderation/spells/spl_vetada/reopen');
assertCondition(statusOf($anonymousReopen) === 401, 'Sin vinculo arcano la re-apertura responde 401');

$notRejected = dispatch('POST', '/api/v1/moderation/spells/spl_borrador_propio/reopen', $autora);
assertCondition(statusOf($notRejected) === 400, 'Reabrir un borrador responde 400');
assertCondition(errorCodeOf($notRejected) === 'SPELL_NOT_REJECTED', 'El 400 declara SPELL_NOT_REJECTED');

$reopened = dispatch('POST', '/api/v1/moderation/spells/spl_vetada/reopen', $autora);
$reopenedPayload = payloadOf($reopened);
assertCondition(statusOf($reopened) === 200, 'La re-apertura legitima responde 200');
assertCondition(
    ($reopenedPayload['data']['review']['status'] ?? '') === 'draft',
    'La obra vetada vuelve a borrador con la edicion habilitada (RF-01.4)'
);
assertCondition(
    (string) ($reopenedPayload['data']['lastVerdict']['objectionReason'] ?? '') === $objectionReason,
    'El dictamen anterior viaja INTEGRO a la vista del autor para su subsanacion (RF-06.2)'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM objection_verdicts WHERE spell_id = 'spl_vetada'")->fetchColumn() === 1,
    'La memoria del dictamen jamas se borra: sigue en la base (RNF-01)'
);

// --- FASE 4: El Atrio de Pruebas ---
echo "\nFASE 4: El Atrio de los Arcanos Experimentales (RF-05.1, RF-05.3)\n";

$hall = dispatch('GET', '/api/v1/moderation/experimental');
$hallPayload = payloadOf($hall);
assertCondition(statusOf($hall) === 200, 'El Atrio es de lectura publica: 200 sin vinculo arcano');
assertCondition(
    (string) ($hallPayload['data']['hallWarning']['legend'] ?? '') === 'En Deliberación Arcana — Obra en Fase de Prueba',
    'El Atrio porta la leyenda ceremonial de advertencia de RF-05.1'
);
assertCondition(
    (string) ($hallPayload['data']['hallWarning']['code'] ?? '') === 'UNDER_ARCANE_DELIBERATION'
    && ($hallPayload['data']['hallWarning']['pointsBlocked'] ?? false) === true,
    'La insignia declara su codigo canonico y el bloqueo de PDA (RF-05.3)'
);

$hallSpellIds = array_column($hallPayload['data']['items'] ?? [], 'spellId');
assertCondition(
    in_array('spl_elegida', $hallSpellIds, true)
    && in_array('spl_cupo_uno', $hallSpellIds, true)
    && in_array('spl_cupo_dos', $hallSpellIds, true)
    && in_array('spl_cupo_tres', $hallSpellIds, true),
    'El Atrio exhibe las obras en deliberacion'
);
assertCondition(
    !in_array('spl_borrador_propio', $hallSpellIds, true)
    && !in_array('spl_consagrada', $hallSpellIds, true)
    && !in_array('spl_vetada', $hallSpellIds, true)
    && !in_array('spl_retirada', $hallSpellIds, true),
    'El Atrio jamas exhibe borradores, consagradas, vetadas ni retiradas (RF-05.1)'
);

$firstItem = $hallPayload['data']['items'][0] ?? [];
assertCondition(
    (string) ($firstItem['signaturesIndicator'] ?? '') === '0/3'
    && (int) ($firstItem['signaturesRequired'] ?? 0) === 3,
    'Cada tarjeta porta su medidor de firmas 0/3 (RF-05.1)'
);
assertCondition(
    (string) ($firstItem['authorAlias'] ?? '') !== '' && (string) ($firstItem['spellName'] ?? '') !== '',
    'Cada tarjeta retrata al autor y el nombre de la obra (Art. IV)'
);
assertCondition(
    ($firstItem['hasEthicalConflict'] ?? true) === false,
    'El Atrio publico no calcula el conflicto etico: viaja en falso (Art. III)'
);

$submittedInstants = array_column($hallPayload['data']['items'] ?? [], 'submittedAt');
$sortedInstants = $submittedInstants;
sort($sortedInstants);
assertCondition($submittedInstants === $sortedInstants, 'El Atrio ordena por antiguedad ascendente (RF-05.4)');

$hallCenso = (int) $pdo->query("SELECT COUNT(*) FROM spell_reviews WHERE status = 'experimental'")->fetchColumn();
assertCondition(
    (int) ($hallPayload['data']['pagination']['totalItems'] ?? -1) === $hallCenso,
    'El censo de la paginacion coincide con las obras en deliberacion'
);

$singlePage = payloadOf(dispatch('GET', '/api/v1/moderation/experimental?page=1&perPage=1'));
assertCondition(count($singlePage['data']['items'] ?? []) === 1, 'La paginacion acota la pagina al tamano pedido');
assertCondition(
    (int) ($singlePage['data']['pagination']['totalPages'] ?? 0) === max(1, (int) ceil($hallCenso / 1)),
    'El total de paginas se calcula sobre el censo'
);
$secondPage = payloadOf(dispatch('GET', '/api/v1/moderation/experimental?page=2&perPage=1'));
assertCondition(
    ($secondPage['data']['items'][0]['spellId'] ?? '') !== ($singlePage['data']['items'][0]['spellId'] ?? ''),
    'La segunda pagina no repite la primera obra'
);

$fireHall = payloadOf(dispatch('GET', '/api/v1/moderation/experimental?element=fire'));
$fireIds = array_column($fireHall['data']['items'] ?? [], 'spellId');
assertCondition(
    in_array('spl_elegida', $fireIds, true) && !in_array('spl_cupo_uno', $fireIds, true),
    'El filtro por afinidad elemental acota el Atrio'
);
$schoolHall = payloadOf(dispatch('GET', '/api/v1/moderation/experimental?school=evocation'));
assertCondition(
    in_array('spl_elegida', array_column($schoolHall['data']['items'] ?? [], 'spellId'), true)
    && !in_array('spl_cupo_uno', array_column($schoolHall['data']['items'] ?? [], 'spellId'), true),
    'El filtro por escuela magica acota el Atrio (RF-05.4)'
);
$unknownElement = payloadOf(dispatch('GET', '/api/v1/moderation/experimental?element=plasma'));
assertCondition(
    ($unknownElement['data']['items'] ?? null) === []
    && (int) ($unknownElement['data']['pagination']['totalItems'] ?? -1) === 0,
    'Una afinidad ajena al Codice devuelve una lente vacia, no un error'
);

$badPage = dispatch('GET', '/api/v1/moderation/experimental?page=abc');
assertCondition(statusOf($badPage) === 400, 'Una pagina no entera responde 400');
assertCondition(errorCodeOf($badPage) === 'INVALID_QUERY_PARAMS', 'El 400 declara INVALID_QUERY_PARAMS');
assertCondition(statusOf(dispatch('GET', '/api/v1/moderation/experimental?perPage=0')) === 400, 'Un tamano de pagina cero responde 400');

// --- FASE 5: Auditoría estática y Dogma ---
echo "\nFASE 5: Auditoria estatica, Dogma Vanilla y Dualismo Linguistico\n";

assertCondition(
    !str_contains($controllerSource, 'new PDO')
    && preg_match('/\b(INSERT INTO|UPDATE |DELETE FROM)\b/', $controllerSource) !== 1,
    'El controlador no abre conexiones ni escribe SQL: delega en el servicio (Art. I)'
);
assertCondition(
    str_contains($controllerSource, 'ModerationWorkflowException')
    && str_contains($controllerSource, 'SpellNotFoundException'),
    'Traduce los veredictos del dominio a sobres HTTP canonicos'
);
assertCondition(
    !str_contains($controllerSource, 'clan_members')
    && !str_contains($controllerSource, 'strtotime')
    && !str_contains($controllerSource, '365'),
    'El controlador no reimplementa la ventana de treinta dias ni el cupo (Art. III)'
);
assertCondition(
    str_contains($controllerSource, 'En Deliberación Arcana — Obra en Fase de Prueba'),
    'La leyenda del Atrio se declara una sola vez, en noble castellano (RNF-03)'
);
assertCondition(
    !file_exists($projectRoot . '/package.json')
    && !file_exists($projectRoot . '/composer.json'),
    'El santuario no declara dependencias npm ni Composer (RNF-05)'
);
assertCondition(
    !file_exists($projectRoot . '/scratch/moderation_endpoints.sqlite'),
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
    echo "\nRESULTADO: FALLO — la Tarea 3.1 no cumple su criterio «Hecho cuando».\n";
    exit(1);
}

echo "\nRESULTADO: EXITO — las rutas responden 200/400/401/403/404/409 y el Atrio\n";
echo "porta su insignia de advertencia liturgica (Tarea 3.1 de SPEC-08).\n";
exit(0);
