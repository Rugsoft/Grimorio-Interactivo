<?php

declare(strict_types=1);

/**
 * test_catalog_adept_state.php — Arnés del hallazgo H8 del recorrido
 * manual de SPEC-11 (Tarea 9.2), mitad de backend.
 *
 * «El gesto compartido no vive en las tarjetas de la Biblioteca: /api/v1/spells
 * no porta `adeptState`». Aquí se verifica que el CATÁLOGO de tarjetas
 * (SpellController::index) embebe el estado del adepto linajado:
 *
 *   [1] La obra sellada viaja con `collected: true`; la elogiada, con
 *       `praised: true`.
 *   [2] La obra de la propia casa viva del adepto VEDA el elogio
 *       (`praiseAllowed: false`, RF-04.4) mientras la ajena lo permite.
 *   [3] La obra no validada (experimental) jamás admite elogio (RF-04.5).
 *   [4] El visitante anónimo y el peregrino sin linaje reciben el catálogo
 *       SIN `adeptState`: el estado íntimo no se inventa (RF-05.1 de SPEC-03,
 *       RF-01.1 de SPEC-11).
 *   [5] Guard del Artículo V: ninguna clave snake_case en el sobre.
 *
 * Ejecución: php scratch/test_catalog_adept_state.php  (exit 0 = verde)
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../src/Core/Response.php';
require __DIR__ . '/../src/Core/Request.php';
require __DIR__ . '/../src/Database/Connection.php';
require __DIR__ . '/../src/Models/User.php';
require __DIR__ . '/../src/Models/Spell.php';
require __DIR__ . '/../src/Dto/ClanLegacySpellDto.php';
require __DIR__ . '/../src/Dto/GrimoirePageDto.php';
require __DIR__ . '/../src/Repositories/GrimoireCollectionRepository.php';
require __DIR__ . '/../src/Services/SpellDiscoveryService.php';
require __DIR__ . '/../src/Services/GrimoireQueryService.php';
require __DIR__ . '/../src/Controllers/SpellController.php';

use Grimorio\Controllers\SpellController;
use Grimorio\Core\Request;
use Grimorio\Database\Connection;
use Grimorio\Models\User;
use Grimorio\Services\GrimoireQueryService;
use Grimorio\Services\SpellDiscoveryService;

$assertsPassed = 0;
$assertsFailed = 0;

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

/** Funda un mago contra el esquema canónico. */
function seedWizard(PDO $connection, string $userId, string $alias, string $role, ?string $lineage): void
{
    $connection->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, created_at, updated_at)
         VALUES (:id, :alias, :email, :hash, :role, NULL, :lineage, :stamp, :stamp)'
    )->execute([
        ':id'      => $userId,
        ':alias'   => $alias,
        ':email'   => $alias . '@h8.test',
        ':hash'    => str_repeat('a', 60),
        ':role'    => $role,
        ':lineage' => $lineage,
        ':stamp'   => '2026-09-22T00:00:00Z',
    ]);
}

/** Funda una hermandad con su linaje. */
function seedHouse(PDO $connection, string $clanId, string $lineageType): void
{
    $connection->prepare(
        'INSERT INTO clans (id, slug, name, motto, created_at, coat_of_arms, lineage_type,
                            admission_mode, status, patriarch_id, weekly_points, historical_points,
                            last_activity_at, updated_at)
         VALUES (:id, :id, :id, :motto, :stamp, :arms, :lineageType,
                 :mode, :status, NULL, 0, 0, :stamp, :stamp)'
    )->execute([
        ':id'          => $clanId,
        ':motto'       => 'Lema de laboratorio',
        ':stamp'       => '2026-01-01T00:00:00Z',
        ':arms'        => 'rune_probe',
        ':lineageType' => $lineageType,
        ':mode'        => 'open',
        ':status'      => 'active',
    ]);
}

/** Forja un conjuro validado (o experimental) con su casa y afinidad. */
function seedSpell(PDO $connection, string $spellId, string $clanId, string $authorId, string $element, string $status): void
{
    $connection->prepare(
        'INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, casting_time,
                             mana_cost, circle, math_fingerprint, clan_id, summary, status,
                             validation_signatures_count, signatures_count, is_genesis_sample, created_at, updated_at, validated_at)
         VALUES (:id, :id, :name, :authorId, :school, :element, :castingTime,
                 :manaCost, :circle, :fingerprint, :clanId, :summary, :status,
                 0, 0, 0, :stamp, :stamp, :validatedAt)'
    )->execute([
        ':id'          => $spellId,
        ':name'        => 'Conjuro ' . $spellId,
        ':authorId'    => $authorId,
        ':school'      => 'evocation',
        ':element'     => $element,
        ':castingTime' => 'action',
        ':manaCost'    => 12,
        ':circle'      => 1,
        ':fingerprint' => str_repeat('f', 64),
        ':clanId'      => $clanId,
        ':summary'     => 'Sonda del estado embebido.',
        ':status'      => $status,
        ':stamp'       => '2026-09-01T00:00:00Z',
        ':validatedAt' => $status === 'validated' ? '2026-09-10T10:00:00Z' : null,
    ]);
}

/** Despacha el catálogo con un lector opcional (null = anónimo). */
function catalogItems(SpellController $controller, ?User $reader): array
{
    $request = new Request('GET', '/api/v1/spells', ['includeExperimental' => '1', 'limit' => '50']);
    if ($reader !== null) {
        $request->setUser($reader);
    }
    $payload = json_decode($controller->index($request)->getBody(), true) ?: [];

    return is_array($payload['data']['items'] ?? null) ? $payload['data']['items'] : [];
}

/** Ficha del catálogo por identificador. */
function itemOf(array $items, string $spellId): ?array
{
    foreach ($items as $item) {
        if (($item['id'] ?? null) === $spellId) {
            return $item;
        }
    }

    return null;
}

/** Guard del Artículo V: cero claves snake_case en el sobre. */
function hasSnakeCaseKeys(array $payload): bool
{
    foreach ($payload as $key => $value) {
        if (is_string($key) && str_contains($key, '_')) {
            return true;
        }
        if (is_array($value) && hasSnakeCaseKeys($value)) {
            return true;
        }
    }

    return false;
}

echo "== VERIFICACION H8 (backend): adeptState embebido en el catálogo ==\n\n";

// ---------------------------------------------------------------------
// Base canónica en memoria: semillas + sondas del arnés.
// ---------------------------------------------------------------------
Connection::resetInstance();
putenv('GRIMORIO_DB_DSN=sqlite::memory:');
$pdo = Connection::getInstance()->getPdo();
$pdo->exec('PRAGMA foreign_keys = ON');

seedHouse($pdo, 'cln_tides', 'celestialTides');
seedWizard($pdo, 'usr_h8_adept', 'Adepta Sonda', 'editor', 'celestialTides');
seedWizard($pdo, 'usr_h8_pilgrim', 'Peregrino Sonda', 'reader', null);
seedWizard($pdo, 'usr_h8_author', 'Autor Sonda', 'editor', 'primordialFlame');

// La militancia VIVA del adepto (clan_members) es la autoridad del veto.
$pdo->prepare(
    'INSERT INTO clan_members (id, clan_id, user_id, role, joined_at)
     VALUES (:id, :clanId, :userId, :role, :stamp)'
)->execute([
    ':id'     => 'clm_h8_membership',
    ':clanId' => 'cln_primordial',
    ':userId' => 'usr_h8_adept',
    ':role'   => 'adept',
    ':stamp'  => '2026-08-01T00:00:00Z',
]);

// La casa propia del adepto es `cln_primordial` (membresía viva).
seedSpell($pdo, 'spl_h8_own', 'cln_primordial', 'usr_h8_author', 'fire', 'validated');
seedSpell($pdo, 'spl_h8_foreign', 'cln_tides', 'usr_h8_author', 'water', 'validated');
seedSpell($pdo, 'spl_h8_praised', 'cln_tides', 'usr_h8_author', 'wind', 'validated');
seedSpell($pdo, 'spl_h8_experimental', 'cln_tides', 'usr_h8_author', 'earth', 'experimental');

// El tomo del adepto: la obra ajena ya sellada; el homenaje, rendido.
$pdo->prepare(
    'INSERT INTO grimoire_collections (id, user_id, spell_id, added_at)
     VALUES (:id, :userId, :spellId, :stamp)'
)->execute([
    ':id'      => 'grc_h8_sealed',
    ':userId'  => 'usr_h8_adept',
    ':spellId' => 'spl_h8_foreign',
    ':stamp'   => '2026-09-20T00:00:00Z',
]);
$pdo->prepare(
    'INSERT INTO favorites (id, user_id, spell_id, created_at)
     VALUES (:id, :userId, :spellId, :stamp)'
)->execute([
    ':id'      => 'fav_h8_praise',
    ':userId'  => 'usr_h8_adept',
    ':spellId' => 'spl_h8_praised',
    ':stamp'   => '2026-09-20T00:00:00Z',
]);

$controller = new SpellController(
    new SpellDiscoveryService(Connection::getInstance()),
    new GrimoireQueryService($pdo),
);

$reader = new User(
    id: 'usr_h8_adept',
    alias: 'Adepta Sonda',
    email: 'adepta-sonda@h8.test',
    role: 'editor',
    clanId: 'cln_primordial',
    lineage: 'celestialTides',
);
$pilgrim = new User(
    id: 'usr_h8_pilgrim',
    alias: 'Peregrino Sonda',
    email: 'peregrino-sonda@h8.test',
    role: 'reader',
    clanId: null,
    lineage: null,
);

// --- FASE 1: el adepto linajado recibe su estado embebido ---
echo "FASE 1: estado embebido del adepto linajado\n";

$adeptItems = catalogItems($controller, $reader);
assertArcane(count($adeptItems) >= 4, 'El catálogo entrega sus fichas (incluidas las experimentales con la bandera)');

$foreignItem = itemOf($adeptItems, 'spl_h8_foreign');
assertArcane(
    is_array($foreignItem['adeptState'] ?? null),
    'La ficha del catálogo porta `adeptState` (hallazgo H8)'
);
assertArcane(
    ($foreignItem['adeptState']['collected'] ?? null) === true,
    'La obra sellada viaja con `collected: true` (RF-01.3)'
);
assertArcane(
    ($foreignItem['adeptState']['praised'] ?? null) === false,
    'La obra ajena sin voto viaja con `praised: false` (RF-04.3)'
);
assertArcane(
    ($foreignItem['adeptState']['praiseAllowed'] ?? null) === true,
    'La obra ajena y validada admite elogio (`praiseAllowed: true`, RF-04.4)'
);

// --- FASE 2: votos, militancia y estados no validados ---
echo "\nFASE 2: votos, militancia y estados\n";

$praisedItem = itemOf($adeptItems, 'spl_h8_praised');
assertArcane(
    ($praisedItem['adeptState']['praised'] ?? null) === true,
    'La obra ya elogiada viaja con `praised: true` (RF-04.3)'
);
assertArcane(
    ($praisedItem['adeptState']['collected'] ?? null) === false,
    'El tomo personal y los votos son ritos distintos: el elogio no sella (RF-05.4)'
);

$ownItem = itemOf($adeptItems, 'spl_h8_own');
assertArcane(
    ($ownItem['adeptState']['praiseAllowed'] ?? null) === false,
    'La obra de la propia casa viva VEDA el elogio (`praiseAllowed: false`, RF-04.4)'
);

$experimentalItem = itemOf($adeptItems, 'spl_h8_experimental');
assertArcane(
    ($experimentalItem['adeptState']['praiseAllowed'] ?? null) === false,
    'La obra no validada jamás admite elogio (`praiseAllowed: false`, RF-04.5)'
);

// --- FASE 3: anónimo y peregrino ---
echo "\nFASE 3: anónimo y peregrino sin linaje\n";

foreach (['el visitante anónimo' => null, 'el peregrino sin linaje' => $pilgrim] as $who => $whoReader) {
    $items = catalogItems($controller, $whoReader);
    assertArcane($items !== [], "{$who} lee el catálogo público (RF-05.1 de SPEC-03)");
    $anyState = false;
    foreach ($items as $item) {
        if (array_key_exists('adeptState', $item)) {
            $anyState = true;
        }
    }
    assertArcane($anyState === false, "{$who} NO recibe `adeptState`: el estado íntimo no se inventa");
}

// --- FASE 4: contrato camelCase (Artículo V) ---
echo "\nFASE 4: guard del Artículo V\n";

assertArcane(
    hasSnakeCaseKeys(['items' => $adeptItems]) === false,
    'Ninguna clave del sobre del catálogo va en snake_case (guard de los hallazgos 8/19)'
);

// --- Resumen final ---
echo "\n== RESUMEN ==\n";
echo "Asertos superados: {$assertsPassed}\n";
echo "Asertos fallidos:  {$assertsFailed}\n";

if ($assertsFailed === 0) {
    echo "\nRESULTADO: EXITO — El hallazgo H8 (backend) queda cerrado.\n";
    exit(0);
}

echo "\nRESULTADO: DENEGADO — Corregir los asertos marcados con [FALLA].\n";
exit(1);
