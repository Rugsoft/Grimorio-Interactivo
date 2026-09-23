<?php

declare(strict_types=1);

/**
 * test_grimoire_collection_praise.php — Verificación de la Tarea 3.1
 * de TASKS-11.
 *
 * Valida LA PUERTA REST DEL ELOGIO POPULAR
 * (`GrimoireCollectionController::praiseSpell()`, plan §3.3) por HTTP
 * real (Request/Router nativos) contra el «Hecho cuando» de la tarea:
 *
 *   1. Gloria nueva → 200 { praised: true, reason: 'AWARDED' } y el
 *      clan del hechizo recibe 5 PDA + sinergia (+25%) en su marcador
 *      semanal e histórico, con fila en `favorites` y recibo en
 *      `dominion_awards` (RF-04.1, RF-04.2).
 *   2. El segundo elogio del mismo adepto → 200 `ALREADY_PRAISED` SIN
 *      segunda gloria (los PDA no cambian) — el recibo vivo de SPEC-07
 *      garantiza el voto único (RF-04.3).
 *   3. El militante de la casa del hechizo → 200 `OWN_CLAN_FAVORITE`
 *      con `favorites` SIN fila nueva (recibo denegado vivo, jamás
 *      error HTTP — hallazgos 10-11, RF-04.4).
 *   4. El no validado forzado → 409 `PRAISE_SPELL_NOT_VALIDATED` con
 *      la leyenda canónica del Anexo A (RF-04.5, caso límite 8).
 *   5. El cierre de ciclo asigna la gloria al ciclo correcto: el
 *      corte dominical previo drena los contadores y el elogio
 *      posterior acredita en el ciclo NUEVO (caso límite 8).
 *
 * Guardias de frontera: anónimo → 401; peregrino → 403
 * LINEAGE_OATH_REQUIRED (el linaje manda, hallazgo 16); fantasma → 404
 * (el peregrino jamás descubre existencia); y la puerta jamás toca
 * `grimoire_collections` (RF-05.4, los ritos viven aparte).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response/Router nativos,
 *     PDO preparado, sondas desechables.
 *   - Artículo IV (Velo Arcano): reason técnico; leyenda en castellano.
 *   - Artículo V (Dualidad): asertos en inglés, narrativa en castellano.
 *
 * Uso: php scratch/test_grimoire_collection_praise.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../src/Dto/GrimoirePageDto.php';
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
require __DIR__ . '/../src/Services/AuditRecorderInterface.php';
require __DIR__ . '/../src/Services/AuditService.php';
require __DIR__ . '/../src/Services/LineageSynergyService.php';
require __DIR__ . '/../src/Dto/DominionAwardDto.php';
require __DIR__ . '/../src/Dto/WeeklyCycleDto.php';
require __DIR__ . '/../src/Services/WeeklyDominionService.php';
require __DIR__ . '/../src/Services/GrimoireCollectionService.php';
require __DIR__ . '/../src/Dto/CollectionEntryDto.php';
require __DIR__ . '/../src/Dto/CollectionPageDto.php';
require __DIR__ . '/../src/Services/GrimoireQueryService.php';
require __DIR__ . '/../src/Exceptions/LineageOathException.php';
require __DIR__ . '/../src/Exceptions/SpellNotFoundException.php';
require __DIR__ . '/../src/Exceptions/SpellNotInTomeException.php';
require __DIR__ . '/../src/Exceptions/SpellNotValidatedException.php';
require __DIR__ . '/../src/Exceptions/UniformSealVetoException.php';
require __DIR__ . '/../src/Controllers/GrimoireCollectionController.php';

use Grimorio\Controllers\GrimoireCollectionController;
use Grimorio\Core\Request;
use Grimorio\Core\Router;
use Grimorio\Models\User;
use Grimorio\Repositories\GrimoireCollectionRepository;
use Grimorio\Services\GrimoireCollectionService;
use Grimorio\Services\GrimoireQueryService;
use Grimorio\Services\WeeklyDominionService;

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
 * Despacha una petición POST por el Router real con usuario opcional.
 * El cuerpo JSON viaja por rawBody (la vía del Front Controller).
 */
function dispatchPost(Router $router, string $path, ?User $user, ?string $rawBody): object
{
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = $path;
    $_COOKIE = [];

    $request = new Request('POST', $path, [], [], $rawBody);
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

/** Funda una hermandad con contadores de gloria arbitrarios. */
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

/** Contadores de gloria de una casa. */
function houseGlory(PDO $connection, string $clanId): array
{
    $row = $connection->prepare('SELECT weekly_points, historical_points FROM clans WHERE id = :id');
    $row->execute([':id' => $clanId]);
    $fetched = $row->fetch(PDO::FETCH_ASSOC);

    return [
        'weekly'     => (int) ($fetched['weekly_points'] ?? -1),
        'historical' => (int) ($fetched['historical_points'] ?? -1),
    ];
}

echo "=== La puerta del Elogio Popular — Tarea 3.1 de TASKS-11 ===\n";

// ---------------------------------------------------------------------
// Base canónica real + Router con la ruta de la puerta.
// ---------------------------------------------------------------------
$probePath = __DIR__ . '/__probe_gc_praise.sqlite';
@unlink($probePath);
$connection = new PDO('sqlite:' . $probePath);
$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$connection->exec('PRAGMA foreign_keys = ON');
$connection->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));
$connection->prepare("INSERT OR IGNORE INTO magic_schools (slug, name) VALUES ('evocation', 'Evocación')")->execute();

// Dos casas: la de Llama rige el fuego (sinergia 1.25); la de Mareas, el agua.
seedHouse($connection, 'cln-flame', 'primordialFlame');
seedHouse($connection, 'cln-tides', 'celestialTides');

// Autores (uno por casa), visitante ajeno, militante y peregrino sin linaje.
seedWizard($connection, 'usr-author-flame', 'Autor Llama', 'primordialFlame');
seedWizard($connection, 'usr-author-tides', 'Autor Marea', 'celestialTides');
seedWizard($connection, 'usr-visitor', 'Visitante Agua', 'celestialTides');
seedWizard($connection, 'usr-member-flame', 'Morador Llama', 'primordialFlame');
seedWizard($connection, 'usr-pilgrim', 'Peregrino Sin Linaje', null);

// Militancia viva del morador en la casa de la Llama (guardia de RF-04.4).
seedMembership($connection, 'mem-flame-1', 'cln-flame', 'usr-member-flame');

// La obra validada de la Llama (fuego: sinergia para el visitante de Mareas)
// y la experimental que jamás debe recibir gloria.
seedSpell($connection, 'spl-praise-fire', 'cln-flame', 'usr-author-flame', 'fire', 'validated');
seedSpell($connection, 'spl-praise-experimental', 'cln-flame', 'usr-author-flame', 'fire', 'experimental');

$auditService = new Grimorio\Services\AuditService($connection);
$weeklyDominion = new WeeklyDominionService($connection, $auditService);
$service = new GrimoireCollectionService(
    new GrimoireCollectionRepository($connection),
    $auditService,
    $connection,
);
$controller = new GrimoireCollectionController($service, new GrimoireQueryService($connection), $weeklyDominion, $auditService);

$router = new Router();
$router->addRoute('POST', '/api/v1/grimoire/praise', fn (Request $request): object => $controller->praiseSpell($request));

$visitor = new User('usr-visitor', 'Visitante Agua', 'agua@santuario.test', 'editor', null, 'celestialTides');
$memberFlame = new User('usr-member-flame', 'Morador Llama', 'morador@santuario.test', 'editor', 'cln-flame', 'primordialFlame');
$pilgrim = new User('usr-pilgrim', 'Peregrino Sin Linaje', 'peregrino@santuario.test', 'editor', null, null);

echo "\n[FASE 1] Guardias de frontera: 401, 403 y 404 en su orden de contrato.\n";
$response = dispatchPost($router, '/api/v1/grimoire/praise', null, '{"spellId":"spl-praise-fire"}');
assertCondition($response->getStatusCode() === 401, 'El anónimo recibe 401 UNAUTHENTICATED (guardia de sesión).');
assertCondition((bodyOf($response)['error']['code'] ?? '') === 'UNAUTHENTICATED', 'El sobre del 401 porta el código canónico.');

$response = dispatchPost($router, '/api/v1/grimoire/praise', $pilgrim, '{"spellId":"spl-praise-fire"}');
assertCondition($response->getStatusCode() === 403, 'El peregrino recibe 403 (el linaje manda, no el rol — hallazgo 16).');
assertCondition(
    (bodyOf($response)['error']['code'] ?? '') === 'LINEAGE_OATH_REQUIRED',
    'El sobre del 403 porta LINEAGE_OATH_REQUIRED.',
);

$response = dispatchPost($router, '/api/v1/grimoire/praise', $visitor, '{"spellId":"spl-ghost"}');
assertCondition($response->getStatusCode() === 404, 'El fantasma recibe 404 SPELL_NOT_FOUND (tras el linaje).');
$response = dispatchPost($router, '/api/v1/grimoire/praise', $pilgrim, '{"spellId":"spl-ghost"}');
assertCondition(
    $response->getStatusCode() === 403 && (bodyOf($response)['error']['code'] ?? '') === 'LINEAGE_OATH_REQUIRED',
    'El peregrino ante un fantasma recibe EL JURAMENTO: nadie descubre existencia (orden de guardias).',
);

echo "\n[FASE 2] Gloria nueva: 5 PDA + sinergia al clan del hechizo (RF-04.1, RF-04.2).\n";
$response = dispatchPost($router, '/api/v1/grimoire/praise', $visitor, '{"spellId":"spl-praise-fire"}');
assertCondition($response->getStatusCode() === 200, 'La gloria nueva responde 200.');
$body = bodyOf($response);
assertCondition(
    ($body['data']['praised'] ?? null) === true && ($body['data']['reason'] ?? '') === 'AWARDED',
    'El sobre porta praised true con reason AWARDED.',
);
assertCondition(
    ($body['data']['awarded']['points'] ?? 0) === 6 && ($body['data']['awarded']['hasSynergy'] ?? false) === true,
    'El recibo traducido publica 5 PDA + sinergia de agua sobre fuego (6) en camelCase.',
);
assertCondition(houseGlory($connection, 'cln-flame') === ['weekly' => 6, 'historical' => 0], 'El clan del hechizo recibe la gloria (6 en el marcador SEMANAL: el pliegue histórico lo ejecuta el corte dominical, RF-04.3 de SPEC-07).');
assertCondition(
    (int) $connection->query("SELECT COUNT(*) FROM favorites WHERE user_id = 'usr-visitor' AND spell_id = 'spl-praise-fire'")->fetchColumn() === 1,
    'El voto único queda inscrito en la mesa del Dominio (favorites).',
);
assertCondition(
    (int) $connection->query("SELECT COUNT(*) FROM dominion_awards WHERE action_type = 'communityFavorite' AND awarded_points = 6")->fetchColumn() === 1,
    'El Dominio acredita su recibo con los 6 PDA y su asiento.',
);

echo "\n[FASE 3] Segundo elogio: ALREADY_PRAISED sin segunda gloria (RF-04.3).\n";
$response = dispatchPost($router, '/api/v1/grimoire/praise', $visitor, '{"spellId":"spl-praise-fire"}');
assertCondition($response->getStatusCode() === 200, 'El segundo elogio responde 200 (estado, no error).');
assertCondition(
    (bodyOf($response)['data']['reason'] ?? '') === 'ALREADY_PRAISED',
    'El sobre porta reason ALREADY_PRAISED.',
);
assertCondition(houseGlory($connection, 'cln-flame') === ['weekly' => 6, 'historical' => 0], 'La gloria del clan NO crece con el eco idempotente (el canon jamás paga dos veces).');
assertCondition(
    (int) $connection->query("SELECT COUNT(*) FROM favorites WHERE user_id = 'usr-visitor'")->fetchColumn() === 1,
    'El voto es único: sigue habiendo UNA fila en favorites.',
);

echo "\n[FASE 4] Militante de la propia casa: 200 con recibo denegado (RF-04.4, hallazgos 10-11).\n";
$response = dispatchPost($router, '/api/v1/grimoire/praise', $memberFlame, '{"spellId":"spl-praise-fire"}');
assertCondition($response->getStatusCode() === 200, 'El militante recibe 200: la denegación es estado, jamás error HTTP.');
assertCondition(
    (bodyOf($response)['data']['praised'] ?? null) === false
    && (bodyOf($response)['data']['reason'] ?? '') === 'OWN_CLAN_FAVORITE',
    'El sobre porta praised false con reason OWN_CLAN_FAVORITE.',
);
assertCondition(
    (int) $connection->query("SELECT COUNT(*) FROM favorites WHERE user_id = 'usr-member-flame'")->fetchColumn() === 0,
    'El recibo denegado NO inserta fila en favorites (SPEC-07 deniega antes de insertar).',
);
assertCondition(houseGlory($connection, 'cln-flame') === ['weekly' => 6, 'historical' => 0], 'El militante tampoco movió la gloria de su propia casa.');

echo "\n[FASE 5] El no validado forzado: 409 solemne con leyenda del Anexo A (RF-04.5).\n";
$response = dispatchPost($router, '/api/v1/grimoire/praise', $visitor, '{"spellId":"spl-praise-experimental"}');
assertCondition($response->getStatusCode() === 409, 'El elogio forzado sobre experimental responde 409.');
$body = bodyOf($response);
assertCondition(
    ($body['error']['code'] ?? '') === 'PRAISE_SPELL_NOT_VALIDATED',
    'El sobre porta el código PRAISE_SPELL_NOT_VALIDATED (el único 409 nuevo).',
);
assertCondition(
    ($body['error']['message'] ?? '') === 'La gloria solo nace de obra sellada por el Tribunal.',
    'La leyenda del 409 es la canónica del Anexo A (leyenda 12).',
);
assertCondition(
    (int) $connection->query("SELECT COUNT(*) FROM favorites WHERE spell_id = 'spl-praise-experimental'")->fetchColumn() === 0,
    'El 409 no deja voto alguno en favorites.',
);

echo "\n[FASE 6] El cierre de ciclo asigna la gloria al ciclo correcto (caso límite 8).\n";
// El corte dominical drena los contadores semanales: la corona es historia.
$weeklyDominion->closeWeeklyCycle(new DateTimeImmutable('2026-09-13T23:59:59Z', new DateTimeZone('UTC')));
$afterClose = houseGlory($connection, 'cln-flame');
assertCondition($afterClose['weekly'] === 0 && $afterClose['historical'] === 6, 'El corte dominical drena los PDA semanales y conserva el histórico.');
// Elogio posterior al corte: acreditación en el ciclo NUEVO, no en el cerrado.
$response = dispatchPost($router, '/api/v1/grimoire/praise', $memberFlame, '{"spellId":"spl-praise-fire"}');
assertCondition(
    ($response->getStatusCode() === 200) && (bodyOf($response)['data']['praised'] ?? null) === false
    && (bodyOf($response)['data']['reason'] ?? '') === 'OWN_CLAN_FAVORITE',
    'Tras el corte, el militante sigue recibiendo su recibo denegado vivo (sin fila nueva).',
);
$response = dispatchPost($router, '/api/v1/grimoire/praise', $visitor, '{"spellId":"spl-praise-fire"}');
assertCondition(
    ($response->getStatusCode() === 200) && (bodyOf($response)['data']['reason'] ?? '') === 'ALREADY_PRAISED',
    'El voto del visitante sobrevive al corte: el segundo elogio sigue siendo eco idempotente.',
);
$gloryAfter = houseGlory($connection, 'cln-flame');
assertCondition($gloryAfter['weekly'] === 0 && $gloryAfter['historical'] === 6, 'Ninguna gloria retroactiva tras el corte (la gloria es asiento histórico — hallazgo 18).');

echo "\n[FASE 7] Frontera sagrada: elogio y colección viven aparte (RF-05.4).\n";
assertCondition(
    (int) $connection->query('SELECT COUNT(*) FROM grimoire_collections')->fetchColumn() === 0,
    'La puerta del elogio no tocó el tomo: cero filas en grimoire_collections.',
);

// Limpieza de sondas desechables.
@unlink($probePath);
@unlink(str_replace('.sqlite', '-wal', $probePath));
@unlink(str_replace('.sqlite', '-shm', $probePath));

echo "\n=== RESULTADO: {$assertsPassed} pasan, {$assertsFailed} fallan ===\n";
exit($assertsFailed === 0 ? 0 : 1);
