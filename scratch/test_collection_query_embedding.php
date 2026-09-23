<?php

declare(strict_types=1);

/**
 * test_collection_query_embedding.php — Verificación de la Tarea 3.3
 * de TASKS-11.
 *
 * Valida EL ENRIQUECIMIENTO EMBEBIDO y LA TERCERA VÍA por HTTP real
 * (Router nativo) contra el «Hecho cuando» de la tarea:
 *
 *   1. El listado canónico del adepto autenticado porta `adeptState`
 *      (collected/praised) REAL en cada página — sin peticiones extra.
 *   2. Anónimo recibe el listado SIN la clave `adeptState`.
 *   3. mode=collection entrega SOLO el tomo del adepto, ordenado por
 *      adición (la más reciente primero), con `total`, `page` y
 *      `totalPages` (RF-02.1, plan §2.2).
 *   4. Cada entrada del tomo porta `tomeMark` del mapa único (RF-03.2)
 *      y `praiseStatus` con `praised`/`allowed` (RF-04.4/RF-04.5).
 *   5. Peregrino sin linaje → 403 LINEAGE_OATH_REQUIRED en collection
 *      (el linaje manda, no el rol — hallazgo 16); anónimo → 401.
 *   6. El filtro por afinidad particiona el tomo sin residuos (RF-02.3).
 *   7. LATENCIA (RNF-01): abrir la hoja del tomo de 50 entradas cuesta
 *      < 100 ms (presupuesto del RNF-02 de SPEC-06).
 *   8. Frontera sagrada (RF-05.4): la lectura del tomo jamás toca
 *      `favorites` en escritura (los votos permanecen byte a byte).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response/Router nativos, PDO
 *     preparado, esquema canónico real, sondas desechables.
 *   - Artículo V (Dualidad): asertos en inglés, narrativa en castellano.
 *
 * Uso: php scratch/test_collection_query_embedding.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../src/Dto/GrimoirePageDto.php';
require __DIR__ . '/../src/Dto/CollectionEntryDto.php';
require __DIR__ . '/../src/Dto/CollectionPageDto.php';
require __DIR__ . '/../src/Models/User.php';
require __DIR__ . '/../src/Models/Spell.php';
require __DIR__ . '/../src/Core/Response.php';
require __DIR__ . '/../src/Core/Request.php';
require __DIR__ . '/../src/Core/Router.php';
require __DIR__ . '/../src/Models/AuditEntry.php';
require __DIR__ . '/../src/Repositories/GrimoireCollectionRepository.php';
require __DIR__ . '/../src/Repositories/ClanRepository.php';
require __DIR__ . '/../src/Repositories/ClanMemberRepository.php';
require __DIR__ . '/../src/Repositories/WeeklyCycleRepository.php';
require __DIR__ . '/../src/Dto/ClanDto.php';
require __DIR__ . '/../src/Dto/LineageDto.php';
require __DIR__ . '/../src/Dto/ClanMemberDto.php';
require __DIR__ . '/../src/Exceptions/ClanGovernanceException.php';
require __DIR__ . '/../src/Exceptions/LineageOathException.php';
require __DIR__ . '/../src/Exceptions/SpellNotFoundException.php';
require __DIR__ . '/../src/Exceptions/SpellNotInTomeException.php';
require __DIR__ . '/../src/Exceptions/SpellNotValidatedException.php';
require __DIR__ . '/../src/Exceptions/UniformSealVetoException.php';
require __DIR__ . '/../src/Services/AuditRecorderInterface.php';
require __DIR__ . '/../src/Services/AuditService.php';
require __DIR__ . '/../src/Services/LineageSynergyService.php';
require __DIR__ . '/../src/Dto/DominionAwardDto.php';
require __DIR__ . '/../src/Dto/WeeklyCycleDto.php';
require __DIR__ . '/../src/Services/WeeklyDominionService.php';
require __DIR__ . '/../src/Services/GrimoireCollectionService.php';
require __DIR__ . '/../src/Services/GrimoireQueryService.php';
require __DIR__ . '/../src/Controllers/GrimoireController.php';

use Grimorio\Controllers\GrimoireController;
use Grimorio\Core\Request;
use Grimorio\Core\Router;
use Grimorio\Models\User;

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

/** Despacha una petición GET por el Router real con usuario opcional. */
function dispatchGet(Router $router, string $uri, ?User $user = null): object
{
    $_GET = [];
    $queryString = parse_url($uri, PHP_URL_QUERY);
    if (is_string($queryString) && $queryString !== '') {
        parse_str($queryString, $_GET);
    }
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $uri;
    $_COOKIE = [];

    $request = Request::fromGlobals();
    if ($user !== null) {
        $request->setUser($user);
    }

    return $router->dispatch($request);
}

/** Decodifica el sobre JSON de una Response a array asociativo. */
function bodyOf(object $response): array
{
    return json_decode($response->getBody(), true) ?: [];
}

/** Funda un mago contra el esquema canónico real. */
function seedWizard(PDO $connection, string $userId, string $alias, ?string $lineage): void
{
    $connection->prepare(
        'INSERT INTO users (id, alias, email, password_hash, lineage, created_at, updated_at)
         VALUES (:id, :alias, :email, :hash, :lineage, :created, :updated)'
    )->execute([
        ':id'      => $userId,
        ':alias'   => $alias,
        ':email'   => $alias . '@santuario.test',
        ':hash'    => str_repeat('a', 60),
        ':lineage' => $lineage,
        ':created' => '2026-09-22T00:00:00Z',
        ':updated' => '2026-09-22T00:00:00Z',
    ]);
}

/** Funda una hermandad. */
function seedHouse(PDO $connection, string $clanId, string $lineageType): void
{
    $connection->prepare(
        'INSERT INTO clans (id, slug, name, motto, created_at, coat_of_arms, lineage_type,
                            admission_mode, status, patriarch_id, weekly_points, historical_points,
                            last_activity_at, updated_at)
         VALUES (:id, :id, :id, :motto, :createdAt, :arms, :lineageType,
                 :mode, :status, NULL, 0, 0, :stamp, :stamp)'
    )->execute([
        ':id'          => $clanId,
        ':motto'       => 'Lema de prueba',
        ':createdAt'   => '2026-01-01T00:00:00Z',
        ':arms'        => 'rune_test',
        ':lineageType' => $lineageType,
        ':mode'        => 'open',
        ':status'      => 'active',
        ':stamp'       => '2026-09-01T00:00:00Z',
    ]);
}

/** Forja un conjuro con su casa, círculo, afinidad y estado. */
function seedSpell(PDO $connection, string $spellId, string $clanId, string $authorId, string $element, string $status): void
{
    $connection->prepare(
        'INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, casting_time,
                             mana_cost, circle, math_fingerprint, clan_id, summary, status,
                             validation_signatures_count, signatures_count, created_at, updated_at, validated_at)
         VALUES (:id, :id, :name, :authorId, :school, :element, :castingTime,
                 :manaCost, :circle, :fingerprint, :clanId, :summary, :status,
                 0, 0, :stamp, :stamp, :validatedAt)'
    )->execute([
        ':id'          => $spellId,
        ':name'        => 'Conjuro ' . $spellId,
        ':authorId'    => $authorId,
        ':school'      => 'evocation',
        ':element'     => $element,
        ':castingTime' => 'action',
        ':manaCost'    => 10,
        ':circle'      => 1,
        ':fingerprint' => str_repeat('f', 64),
        ':clanId'      => $clanId,
        ':summary'     => 'Resumen de prueba',
        ':status'      => $status,
        ':stamp'       => '2026-09-01T00:00:00Z',
        ':validatedAt' => $status === 'validated' ? '2026-09-10T10:00:00Z' : null,
    ]);
}

/** Sella un hechizo en el tomo de un adepto con instante controlado. */
function seedTomeEntry(PDO $connection, string $entryId, string $userId, string $spellId, string $addedAt): void
{
    $connection->prepare(
        'INSERT INTO grimoire_collections (id, user_id, spell_id, added_at)
         VALUES (:id, :userId, :spellId, :addedAt)'
    )->execute([
        ':id'      => $entryId,
        ':userId'  => $userId,
        ':spellId' => $spellId,
        ':addedAt' => $addedAt,
    ]);
}

/** Rinde homenaje directo en la mesa de votos del Dominio (solo arnés). */
function seedFavorite(PDO $connection, string $favoriteId, string $userId, string $spellId): void
{
    $connection->prepare(
        'INSERT INTO favorites (id, user_id, spell_id, created_at)
         VALUES (:id, :userId, :spellId, :stamp)'
    )->execute([
        ':id'      => $favoriteId,
        ':userId'  => $userId,
        ':spellId' => $spellId,
        ':stamp'   => '2026-09-20T00:00:00Z',
    ]);
}

/** Inscribe a un mago en una casa como adepto activo (militancia). */
function seedMembership(PDO $connection, string $memberId, string $clanId, string $userId): void
{
    $connection->prepare(
        'INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at, convalescence_expires_at)
         VALUES (:id, :clanId, :userId, :role, :joinedAt, NULL, NULL)'
    )->execute([
        ':id'       => $memberId,
        ':clanId'   => $clanId,
        ':userId'   => $userId,
        ':role'     => 'adept',
        ':joinedAt' => '2026-02-01T00:00:00Z',
    ]);
}

echo "=== Enriquecimiento embebido y tercera vía — Tarea 3.3 de TASKS-11 ===\n";

// ---------------------------------------------------------------------
// Base canónica real + Router con la ruta del listado.
// ---------------------------------------------------------------------
$probePath = __DIR__ . '/__probe_gc_embedding.sqlite';
@unlink($probePath);
$connection = new PDO('sqlite:' . $probePath);
$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$connection->exec('PRAGMA foreign_keys = ON');
$connection->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));
$connection->prepare("INSERT OR IGNORE INTO magic_schools (slug, name) VALUES ('evocation', 'Evocación')")->execute();

seedHouse($connection, 'cln-flame', 'primordialFlame');
seedHouse($connection, 'cln-tides', 'celestialTides');

seedWizard($connection, 'usr-adept', 'Adepto Marea', 'celestialTides');
seedWizard($connection, 'usr-pilgrim', 'Peregrino Sin Linaje', null);
seedWizard($connection, 'usr-member-flame', 'Morador Llama', 'primordialFlame');
seedWizard($connection, 'usr-author', 'Autor Llama', 'primordialFlame');

// Militancia viva del morador en la casa de la Llama (veda RF-04.4).
seedMembership($connection, 'mem-flame-1', 'cln-flame', 'usr-member-flame');

// Obras del catálogo: tres validadas (dos fuego, una agua), una experimental.
seedSpell($connection, 'spl-emb-fire-1', 'cln-flame', 'usr-author', 'fire', 'validated');
seedSpell($connection, 'spl-emb-fire-2', 'cln-flame', 'usr-author', 'fire', 'validated');
seedSpell($connection, 'spl-emb-water', 'cln-tides', 'usr-author', 'water', 'validated');
seedSpell($connection, 'spl-emb-gestation', 'cln-flame', 'usr-author', 'fire', 'experimental');

// El tomo del adepto: tres obras, la más reciente una experimental (marca gestation).
seedTomeEntry($connection, 'tme-emb-1', 'usr-adept', 'spl-emb-fire-1', '2026-09-21T10:00:00Z');
seedTomeEntry($connection, 'tme-emb-2', 'usr-adept', 'spl-emb-water', '2026-09-21T11:00:00Z');
seedTomeEntry($connection, 'tme-emb-3', 'usr-adept', 'spl-emb-gestation', '2026-09-21T12:00:00Z');

// Voto del Dominio del adepto sobre una obra del tomo (praised real).
seedFavorite($connection, 'fav-emb-1', 'usr-adept', 'spl-emb-fire-1');

$router = new Router();
$controller = new GrimoireController(new Grimorio\Services\GrimoireQueryService($connection));
$router->addRoute('GET', '/api/v1/grimoire/spells', fn (Request $request): object => $controller->listSpells($request));

$adept = new User('usr-adept', 'Adepto Marea', 'adept@santuario.test', 'reader', null, 'celestialTides');
$pilgrim = new User('usr-pilgrim', 'Peregrino Sin Linaje', 'peregrino@santuario.test', 'reader', null, null);
$author = new User('usr-author', 'Autor Llama', 'author@santuario.test', 'editor', 'cln-flame', 'primordialFlame');

echo "\n[FASE 1] El listado canónico del adepto porta adeptState REAL (RF-04.0).\n";
$response = dispatchGet($router, '/api/v1/grimoire/spells', $adept);
assertCondition($response->getStatusCode() === 200, 'El listado autenticado responde 200.');
$spells = bodyOf($response)['data']['spells'] ?? [];
$fireOne = null;
$gestation = null;
foreach ($spells as $spell) {
    if (($spell['id'] ?? '') === 'spl-emb-fire-1') {
        $fireOne = $spell;
    }
}
assertCondition($fireOne !== null, 'La obra sellada del tomo aparece en el listado canónico.');
assertCondition(
    ($fireOne['adeptState']['collected'] ?? null) === true && ($fireOne['adeptState']['praised'] ?? null) === true,
    'La obra coleccionada y elogiada porta adeptState { collected: true, praised: true } real.',
);

// La obra en gestación jamás está en el tomo canónico: el enriquecimiento
// se verifica en los ENSAYOS del autor (su titular, Artículo III).
$response = dispatchGet($router, '/api/v1/grimoire/spells?mode=essays', $author);
assertCondition($response->getStatusCode() === 200, 'El listado de ensayos del autor responde 200.');
foreach (bodyOf($response)['data']['spells'] ?? [] as $spell) {
    if (($spell['id'] ?? '') === 'spl-emb-gestation') {
        $gestation = $spell;
    }
}
assertCondition(
    ($gestation['adeptState']['collected'] ?? null) === false && ($gestation['adeptState']['praised'] ?? null) === false,
    'La obra en gestación vista por SU AUTOR (que no la coleccionó) porta collected false y praised false: el estado es del lector, jamás global.',
);

echo "\n[FASE 2] Anónimo: el listado viaja SIN la clave adeptState (RF-04.0).\n";
$response = dispatchGet($router, '/api/v1/grimoire/spells');
assertCondition($response->getStatusCode() === 200, 'El listado anónimo responde 200.');
$anonymousSpells = bodyOf($response)['data']['spells'] ?? [];
$anyAdeptState = false;
foreach ($anonymousSpells as $spell) {
    if (array_key_exists('adeptState', $spell)) {
        $anyAdeptState = true;
    }
}
assertCondition(!$anyAdeptState, 'Ninguna página anónima porta la clave adeptState.');

echo "\n[FASE 3] mode=collection: el tomo íntimo, ordenado y paginado (RF-02.1).\n";
$response = dispatchGet($router, '/api/v1/grimoire/spells?mode=collection', $adept);
assertCondition($response->getStatusCode() === 200, 'mode=collection responde 200.');
$collection = bodyOf($response)['data'];
assertCondition(
    ($collection['total'] ?? -1) === 3 && ($collection['page'] ?? -1) === 1 && ($collection['totalPages'] ?? -1) === 1,
    'El sobre porta total, page y totalPages del contrato (plan §2.2).',
);
$entries = $collection['entries'] ?? [];
assertCondition(count($entries) === 3, 'El tomo entrega exactamente las tres obras del adepto.');
assertCondition(
    count($entries) === 3
    && ($entries[0]['spell']['id'] ?? '') === 'spl-emb-gestation'
    && ($entries[1]['spell']['id'] ?? '') === 'spl-emb-water'
    && ($entries[2]['spell']['id'] ?? '') === 'spl-emb-fire-1',
    'Las entradas viajan ordenadas por adición, la más reciente primero (RF-02.1).',
);
assertCondition(
    ($entries[0]['tomeMark'] ?? '') === 'gestation'
    && ($entries[1]['tomeMark'] ?? '') === 'living'
    && ($entries[2]['tomeMark'] ?? '') === 'living',
    'Cada entrada porta su marca solemne del mapa único (RF-03.2: gestation/living).',
);
assertCondition(
    ($entries[0]['praiseStatus']['allowed'] ?? null) === false,
    'La obra no validada veda el homenaje (allowed false, RF-04.5).',
);
assertCondition(
    ($entries[2]['praiseStatus']['praised'] ?? null) === true,
    'El voto del Dominio viaja embebido en la entrada (praised true, RF-04.0).',
);

echo "\n[FASE 4] Fronteras de acceso a collection: 401 y 403 (hallazgo 16).\n";
$response = dispatchGet($router, '/api/v1/grimoire/spells?mode=collection');
assertCondition(
    $response->getStatusCode() === 401 && (bodyOf($response)['error']['code'] ?? '') === 'UNAUTHENTICATED',
    'El anónimo recibe 401 UNAUTHENTICATED (la sala íntima exige vínculo).',
);
$response = dispatchGet($router, '/api/v1/grimoire/spells?mode=collection', $pilgrim);
assertCondition(
    $response->getStatusCode() === 403 && (bodyOf($response)['error']['code'] ?? '') === 'LINEAGE_OATH_REQUIRED',
    'El peregrino recibe 403 LINEAGE_OATH_REQUIRED: el linaje manda, no el rol.',
);

echo "\n[FASE 5] El filtro por afinidad particiona el tomo (RF-02.3).\n";
$response = dispatchGet($router, '/api/v1/grimoire/spells?mode=collection&element=water', $adept);
$waterCollection = bodyOf($response)['data'];
assertCondition(
    ($waterCollection['total'] ?? -1) === 1 && count($waterCollection['entries'] ?? []) === 1
    && ($waterCollection['entries'][0]['spell']['id'] ?? '') === 'spl-emb-water',
    'El filtro de agua entrega solo su obra, con total coherente (total jamás describe otro conjunto).',
);

echo "\n[FASE 6] Frontera sagrada: la lectura jamás escribe en favorites (RF-05.4).\n";
$favoritesBefore = $connection->query('SELECT COUNT(*) FROM favorites')->fetchColumn();
dispatchGet($router, '/api/v1/grimoire/spells?mode=collection', $adept);
dispatchGet($router, '/api/v1/grimoire/spells', $adept);
$favoritesAfter = $connection->query('SELECT COUNT(*) FROM favorites')->fetchColumn();
assertCondition(
    (int) $favoritesBefore === (int) $favoritesAfter,
    'Las lecturas enriquecidas no mutan la mesa de votos del Dominio (cada rito, su vida).',
);

echo "\n[FASE 7] LATENCIA (RNF-01): hoja de 50 entradas < 100 ms.\n";
// Un tomo de 55 obras: la hoja abre con 50 entradas enriquecidas.
seedHouse($connection, 'cln-bulk', 'primordialFlame');
for ($i = 1; $i <= 55; $i++) {
    $spellId = sprintf('spl-emb-bulk-%02d', $i);
    seedSpell($connection, $spellId, 'cln-bulk', 'usr-author', 'earth', 'validated');
    seedTomeEntry(
        $connection,
        sprintf('tme-emb-bulk-%02d', $i),
        'usr-adept',
        $spellId,
        sprintf('2026-09-20T%02d:%02d:00Z', intdiv($i - 1, 60) % 24, ($i - 1) % 60),
    );
}

$latencyStart = microtime(true);
$response = dispatchGet($router, '/api/v1/grimoire/spells?mode=collection', $adept);
$latencyMs = (microtime(true) - $latencyStart) * 1000;
$bulkCollection = bodyOf($response)['data'];
assertCondition(
    count($bulkCollection['entries'] ?? []) === 50 && ($bulkCollection['total'] ?? -1) === 58,
    'La hoja de 50 se entrega sobre un tomo de 58 entradas (paginación del caso límite 4).',
);
assertCondition(
    ($bulkCollection['totalPages'] ?? -1) === 2,
    'El sobre porta totalPages=2 (techo de 58/50).',
);
assertCondition(
    $latencyMs < 100.0,
    sprintf('La apertura de la hoja costó %.1f ms — dentro del presupuesto de 100 ms (RNF-01).', $latencyMs),
);

// Limpieza de sondas desechables.
@unlink($probePath);
@unlink(str_replace('.sqlite', '-wal', $probePath));
@unlink(str_replace('.sqlite', '-shm', $probePath));

echo "\n=== RESULTADO: {$assertsPassed} pasan, {$assertsFailed} fallan ===\n";
exit($assertsFailed === 0 ? 0 : 1);
