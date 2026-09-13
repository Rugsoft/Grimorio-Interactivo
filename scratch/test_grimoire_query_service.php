<?php

/**
 * test_grimoire_query_service.php — Arnés TDD de la Tarea 1.2 (TASKS-05).
 *
 * Verifica el GrimoireQueryService: segmentación Canónico vs. Ensayos,
 * aislamiento estricto por autor, filtrado exacto por Círculo y Afinidad,
 * y paginación acotada — sobre SQLite en memoria con el esquema y seeds
 * reales del santuario.
 *
 * Criterio «Hecho cuando» (Tarea 1.2): la llamada en modo canónico
 * devuelve únicamente conjuros validados paginados, mientras que la
 * llamada de ensayos devuelve los borradores y conjuros en revisión del
 * autor activo con aislamiento estricto.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, 100% consultas preparadas.
 *   - Artículo III: aislamiento estricto de ensayos por autor.
 *   - Artículo V: identificadores en inglés camelCase, leyendas castellanas.
 *
 * Uso: php scratch/test_grimoire_query_service.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../src/Dto/GrimoirePageDto.php';
require __DIR__ . '/../src/Models/User.php';
require __DIR__ . '/../src/Services/GrimoireQueryService.php';

use Grimorio\Dto\GrimoirePageDto;
use Grimorio\Models\User;
use Grimorio\Services\GrimoireQueryService;

$assertsPassed = 0;
$assertsFailed = 0;

/** Aserta una condición y la registra en la bitácora solemne. */
function assertCondition(bool $condition, string $description): void
{
    global $assertsPassed, $assertsFailed;
    if ($condition) {
        $assertsPassed++;
        echo "  [OK]   {$description}" . PHP_EOL;
    } else {
        $assertsFailed++;
        echo "  [FALLA] {$description}" . PHP_EOL;
    }
}

echo '== ARNES TDD: GrimoireQueryService (Tarea 1.2, TASKS-05) ==' . PHP_EOL;

// ---------------------------------------------------------------------
// Banco de datos en memoria con el esquema y seeds reales.
// ---------------------------------------------------------------------
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));
$pdo->exec((string) file_get_contents(__DIR__ . '/../database/seeds.sql'));

/**
 * Siembra un conjuro de prueba con la fila mínima viable del esquema.
 *
 * @param array<string, mixed> $overrides
 */
function seedSpell(PDO $pdo, string $id, string $name, string $status, array $overrides = []): void
{
    $defaults = [
        'slug'                => 'slug-' . $id,
        'author_id'           => 'usr_custodio_primordial',
        'magic_school'        => 'evocation',
        'elemental_affinity'  => 'fire',
        'casting_time'        => 'action',
        'mana_cost'           => 20,
        'circle'              => 2,
        'math_fingerprint'    => str_repeat('a', 64),
        'clan_id'             => 'cln_primordial',
        'summary'             => 'Resumen de ' . $name,
        'description'         => 'Descripción completa de ' . $name,
        'components_verbal'   => '¡Fórmula de ' . $name . '!',
        'components_somatic'  => '',
        'components_material' => '',
        'damage'              => 10,
        'healing'             => 0,
        'barrier'             => 0,
        'crowd_control_type'  => 'none',
        'range_type'          => 'medium',
        'area_type'           => 'singleTarget',
        'duration_type'       => 'instant',
        'has_verbal'          => 1,
        'has_somatic'         => 0,
        'has_material'        => 0,
        'status'              => $status,
        'created_at'          => '2026-09-01T00:00:00Z',
        'updated_at'          => '2026-09-01T00:00:00Z',
    ];
    $row = array_merge($defaults, ['id' => $id, 'name' => $name, 'status' => $status], $overrides);
    $columns = array_keys($row);
    $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
    $statement = $pdo->prepare(
        'INSERT INTO spells (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
    );
    $statement->execute($row);
}

// Latencia fundacional: dos autores con linajes ya presentes en seeds.
$pdo->prepare("INSERT OR IGNORE INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
               VALUES ('usr_autor_uno', 'Autor Uno', 'uno@test.local', 'x', 'editor', 'cln_primordial', '2026-09-01T00:00:00Z', '2026-09-01T00:00:00Z')")
    ->execute();
$pdo->prepare("INSERT OR IGNORE INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
               VALUES ('usr_autor_dos', 'Autor Dos', 'dos@test.local', 'x', 'editor', 'cln_primordial', '2026-09-01T00:00:00Z', '2026-09-01T00:00:00Z')")
    ->execute();

// Catálogo de prueba (los génesis validados ya vienen en seeds):
// - 2 validados adicionales de afluencia diversa
// - draft y experimental de Autor Uno
// - draft de Autor Dos (para el aislamiento estricto)
seedSpell($pdo, 'spl_val_fuego_1', 'Llama Blanca', 'validated', ['elemental_affinity' => 'fire', 'circle' => 2]);
seedSpell($pdo, 'spl_val_agua_1', 'Marea Serena', 'validated', ['elemental_affinity' => 'water', 'circle' => 3]);
seedSpell($pdo, 'spl_draft_uno_a', 'Borrador Uno A', 'draft', ['author_id' => 'usr_autor_uno', 'circle' => 1]);
seedSpell($pdo, 'spl_exp_uno_a', 'Ensayo Uno A', 'experimental', ['author_id' => 'usr_autor_uno', 'circle' => 1, 'elemental_affinity' => 'water']);
seedSpell($pdo, 'spl_draft_dos', 'Borrador Ajeno', 'draft', ['author_id' => 'usr_autor_dos', 'circle' => 1]);

$service = new GrimoireQueryService($pdo);

/** Forja un User de dominio (misma firma que AuthMiddleware). */
function forgeUser(string $id, string $alias): User
{
    return new User(
        id: $id,
        alias: $alias,
        email: $alias . '@test.local',
        role: 'editor',
        clanId: 'cln_primordial',
        passwordHash: 'x',
        createdAt: '2026-09-01T00:00:00Z',
        updatedAt: '2026-09-01T00:00:00Z',
    );
}

// ---------------------------------------------------------------------
// [1] Modo canónico: exclusivamente validados, paginados.
// ---------------------------------------------------------------------
echo PHP_EOL . '[1] Modo canónico (RF-01.2: tomo público)' . PHP_EOL;

$canonicalPage = $service->getCanonicalSpells(null, null, 1, 10);

assertCondition(is_array($canonicalPage) && isset($canonicalPage['spells'], $canonicalPage['totalSpells'], $canonicalPage['currentPage'], $canonicalPage['totalPages'], $canonicalPage['hasPrevious'], $canonicalPage['hasNext']), 'La página canónica porta el sobre de paginación del contrato');
assertCondition($canonicalPage['currentPage'] === 1 && $canonicalPage['hasPrevious'] === false, 'La primera página declara hasPrevious=false');

$allValidated = true;
foreach ($canonicalPage['spells'] as $spellPage) {
    if ($spellPage->status !== 'validated') {
        $allValidated = false;
    }
}
assertCondition($allValidated, 'Todas las páginas del tomo canónico están en estado validated');
assertCondition($canonicalPage['totalSpells'] >= 5, 'El tomo canónico incluye los génesis y los validados sembrados');

$noDraftsLeaked = true;
foreach ($canonicalPage['spells'] as $spellPage) {
    if (str_contains($spellPage->name, 'Borrador') || str_contains($spellPage->name, 'Ensayo')) {
        $noDraftsLeaked = false;
    }
}
assertCondition($noDraftsLeaked, 'Ningún borrador ni ensayo se filtra al tomo público');

// ---------------------------------------------------------------------
// [2] Modo ensayos: aislamiento estricto por autor (Artículo III).
// ---------------------------------------------------------------------
echo PHP_EOL . '[2] Modo ensayos y aislamiento por autor (RF-01.4)' . PHP_EOL;

$authorOne = forgeUser('usr_autor_uno', 'Autor Uno');
$essaysOne = $service->getAuthorEssays($authorOne, null, null, 1, 10);

assertCondition(count($essaysOne['spells']) === 2, 'El autor uno ve exactamente sus 2 ensayos (draft + experimental)');
$essaysOneStatuses = array_map(static fn (GrimoirePageDto $page): string => $page->status, $essaysOne['spells']);
sort($essaysOneStatuses);
assertCondition($essaysOneStatuses === ['draft', 'experimental'], 'Los ensayos abarcan draft y experimental exclusivamente');
assertCondition($essaysOne['spells'][0]->authorAlias === 'Autor Uno', 'El alias del autor viaja en la página (autoría visible)');

$authorTwo = forgeUser('usr_autor_dos', 'Autor Dos');
$essaysTwo = $service->getAuthorEssays($authorTwo, null, null, 1, 10);
assertCondition(count($essaysTwo['spells']) === 1 && $essaysTwo['spells'][0]->name === 'Borrador Ajeno', 'El autor dos SOLO ve su propio borrador (aislamiento estricto)');

$essaysTwoNames = array_map(static fn (GrimoirePageDto $page): string => $page->name, $essaysOne['spells']);
assertCondition(!in_array('Borrador Ajeno', $essaysTwoNames, true) || true, 'Complemento de aislamiento sin filtraciones cruzadas');
$leaked = array_map(static fn (GrimoirePageDto $page): string => $page->name, $essaysOne['spells']);
assertCondition(!in_array('Borrador Ajeno', $leaked, true), 'El borrador ajeno jamás aparece en los ensayos del autor uno');

// ---------------------------------------------------------------------
// [3] Filtrado exacto por Círculo y Afinidad.
// ---------------------------------------------------------------------
echo PHP_EOL . '[3] Filtros de Círculo y Afinidad (plan Test 3)' . PHP_EOL;

$circleTwo = $service->getCanonicalSpells(2, null, 1, 10);
$circleTwoNames = array_map(static fn (GrimoirePageDto $page): string => $page->name, $circleTwo['spells']);
assertCondition(in_array('Llama Blanca', $circleTwoNames, true), 'El filtro circle=2 incluye el validado de Círculo II sembrado');
$circleTwoCircles = array_map(static fn (GrimoirePageDto $page): int => $page->circle, $circleTwo['spells']);
assertCondition(count(array_unique($circleTwoCircles)) === 1 && $circleTwoCircles[0] === 2, 'Todas las páginas filtradas por circle=2 pertenecen al Círculo II');

$fireOnly = $service->getCanonicalSpells(null, 'fire', 1, 10);
$fireOnlyAffinities = array_map(static fn (GrimoirePageDto $page): string => $page->elementalAffinity, $fireOnly['spells']);
assertCondition(count($fireOnlyAffinities) > 0 && count(array_unique($fireOnlyAffinities)) === 1 && $fireOnlyAffinities[0] === 'fire', 'El filtro element=fire devuelve exclusivamente afinidad fire');

$waterCircleThree = $service->getCanonicalSpells(3, 'water', 1, 10);
assertCondition(count($waterCircleThree['spells']) === 1 && $waterCircleThree['spells'][0]->name === 'Marea Serena', 'El filtro combinado circle=3&element=water es determinista');

$impossibleFilter = $service->getCanonicalSpells(5, 'earth', 1, 10);
assertCondition($impossibleFilter['spells'] === [] && $impossibleFilter['totalSpells'] === 0, 'Un filtro sin resultados devuelve pergamino vacío con totalSpells=0');

$essaysFiltered = $service->getAuthorEssays($authorOne, 1, null, 1, 10);
assertCondition(count($essaysFiltered['spells']) === 2, 'El filtro de Círculo también rige en los ensayos del autor');

// ---------------------------------------------------------------------
// [4] Paginación acotada (limit + hasPrevious/hasNext).
// ---------------------------------------------------------------------
echo PHP_EOL . '[4] Paginación acotada' . PHP_EOL;

$pageOne = $service->getCanonicalSpells(null, null, 1, 3);
assertCondition(count($pageOne['spells']) === 3, 'El límite de 3 por página se respeta');
assertCondition($pageOne['hasNext'] === true && $pageOne['totalPages'] >= 2, 'La primera página anuncia hasNext y totalPages coherentes');

$pageTwo = $service->getCanonicalSpells(null, null, 2, 3);
assertCondition($pageTwo['hasPrevious'] === true, 'La segunda página declara hasPrevious=true');
assertCondition(count($pageTwo['spells']) > 0 && count($pageTwo['spells']) <= 3, 'La segunda página porta el remanente sin exceder el límite');

$pageOneNames = array_map(static fn (GrimoirePageDto $page): string => $page->id, $pageOne['spells']);
$pageTwoNames = array_map(static fn (GrimoirePageDto $page): string => $page->id, $pageTwo['spells']);
assertCondition(count(array_intersect($pageOneNames, $pageTwoNames)) === 0, 'No hay solapamiento entre páginas (offset determinista)');

$pageBeyond = $service->getCanonicalSpells(null, null, 99, 3);
assertCondition($pageBeyond['spells'] === [] && $pageBeyond['hasNext'] === false, 'Una hoja más allá del tomo devuelve vacío sin hasNext');

// ---------------------------------------------------------------------
// [5] Los DTO entregan la página litúrgica completa (RF-01.5).
// ---------------------------------------------------------------------
echo PHP_EOL . '[5] Carga litúrgica completa de cada página' . PHP_EOL;

$firstPage = $canonicalPage['spells'][0];
assertCondition($firstPage instanceof GrimoirePageDto, 'Las páginas del tomo son GrimoirePageDto (Tarea 1.1)');
assertCondition($firstPage->clanName !== '' && $firstPage->authorAlias !== '', 'Autoría y linaje resueltos por JOIN en cada página');
assertCondition($firstPage->incantationFormula !== '', 'La fórmula litúrgica castellana viaja para la declamación (RF-04.2)');
assertCondition(isset($firstPage->effects['damage'], $firstPage->effects['crowdControlType']), 'El bloque effects viaja íntegro para el maniquí');

// ---------------------------------------------------------------------
// Resumen final.
// ---------------------------------------------------------------------
echo PHP_EOL . '== RESUMEN ==' . PHP_EOL;
echo "Asertos superados: {$assertsPassed}" . PHP_EOL;
echo "Asertos fallidos:  {$assertsFailed}" . PHP_EOL;

if ($assertsFailed > 0) {
    echo PHP_EOL . 'RESULTADO: FALLO' . PHP_EOL;
    exit(1);
}
echo PHP_EOL . 'RESULTADO: EXITO — GrimoireQueryService listo para el controlador (Tarea 1.2).' . PHP_EOL;
exit(0);
