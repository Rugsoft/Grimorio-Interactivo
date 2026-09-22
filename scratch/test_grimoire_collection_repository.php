<?php

declare(strict_types=1);

/**
 * test_grimoire_collection_repository.php — Verificación de la Tarea 1.2
 * de TASKS-11.
 *
 * Valida `GrimoireCollectionRepository` contra el «Hecho cuando» de la
 * tarea, sobre el esquema canónico REAL (`database/schema.sql`):
 *
 *   1. Las seis consultas responden contra una base sembrada.
 *   2. `add()` dos veces devuelve una sola fila (RF-01.3: el primer
 *      sellado `true`, el segundo `false`, la base con UNA fila).
 *   3. `pageForUser()` respeta filtro, orden y paginación de 50
 *      (RF-02.1, RF-02.3, caso límite 4).
 *
 * Fases:
 *   [0] Superficie: el repositorio existe y declara sus seis métodos.
 *   [1] Base canónica real + siembra de 55 hechizos con dos afinidades.
 *   [2] `add()`: sellado nuevo, idempotencia, muralla por adepto.
 *   [3] `existsForUser()` y `spellIdsForUser()`: lecturas fieles.
 *   [4] `pageForUser()`: orden DESC, filtro por afinidad, paginación.
 *   [5] `countForUser()`: total íntegro y filtrado, con y sin filtro.
 *   [6] `remove()`: retirada propia, ajena imposible, idempotencia.
 *   [7] Regresión de la Tarea 1.1: la muralla física sigue viva.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin librerías externas.
 *   - Artículo V (Dualidad): identificadores en inglés; narrativa y
 *     comentarios en noble castellano.
 *
 * Uso: php scratch/test_grimoire_collection_repository.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

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
 * Siembra un adepto y N hechizos alternando dos afinidades, contra el
 * esquema canónico real (users/clans/magic_schools/spells exigidos).
 */
function seedAdeptWithSpells(PDO $connection, string $suffix, int $spellCount, string $fingerprint): array
{
    $userId = 'usr-' . $suffix;
    $connection->prepare(
        'INSERT INTO users (id, alias, email, password_hash, created_at, updated_at) VALUES (:id, :alias, :email, :hash, :created, :updated)'
    )->execute([
        ':id' => $userId,
        ':alias' => 'Adepto ' . $suffix,
        ':email' => $suffix . '@santuario.test',
        ':hash' => str_repeat('a', 60),
        ':created' => '2026-09-22T00:00:00Z',
        ':updated' => '2026-09-22T00:00:00Z',
    ]);
    $connection->prepare("INSERT OR IGNORE INTO magic_schools (slug, name) VALUES ('evocacion', 'Evocación')")
        ->execute();
    $connection->prepare('INSERT INTO clans (id, slug, name, created_at, lineage_type) VALUES (:id, :slug, :name, :created, :lineageType)')
        ->execute([
            ':id' => 'cln-' . $suffix,
            ':slug' => 'casa-prueba-' . $suffix,
            ':name' => 'Casa de prueba ' . $suffix,
            ':created' => '2026-09-22T00:00:00Z',
            ':lineageType' => 'primordialFlame',
        ]);

    $insertSpell = $connection->prepare(
        'INSERT INTO spells (id, slug, name, author_id, magic_school, clan_id, summary, mana_cost, elemental_affinity, created_at, updated_at, math_fingerprint)
         VALUES (:id, :slug, :name, :author, :school, :clan, :summary, 5, :element, :created, :updated, :fingerprint)'
    );
    for ($index = 0; $index < $spellCount; $index++) {
        // Afinidad alterna: los pares son fuego, los impares agua — el
        // filtro por afinidad tendrá siempre dos mitades limpias.
        $element = ($index % 2 === 0) ? 'fire' : 'water';
        $insertSpell->execute([
            ':id' => "spl-{$suffix}-{$index}",
            ':slug' => "conjuro-{$suffix}-{$index}",
            ':name' => "Conjuro {$index} de {$suffix}",
            ':author' => $userId,
            ':school' => 'evocacion',
            ':clan' => 'cln-' . $suffix,
            ':summary' => 'Resumen de prueba',
            ':element' => $element,
            ':created' => '2026-09-22T00:00:00Z',
            ':updated' => '2026-09-22T00:00:00Z',
            ':fingerprint' => $fingerprint,
        ]);
    }

    return [$userId, $spellCount];
}

echo "=== GrimoireCollectionRepository — Tarea 1.2 de TASKS-11 ===\n";

echo "\n[FASE 0] Superficie: el repositorio existe y declara sus seis métodos.\n";
$repositorySource = (string) file_get_contents(__DIR__ . '/../src/Repositories/GrimoireCollectionRepository.php');
assertCondition($repositorySource !== '', 'El repositorio src/Repositories/GrimoireCollectionRepository.php existe.');
foreach (['public function add(', 'public function remove(', 'public function pageForUser(', 'public function countForUser(', 'public function existsForUser(', 'public function spellIdsForUser('] as $method) {
    assertCondition(str_contains($repositorySource, $method), "Declara {$method}…).");
}
// Frontera sagrada: la mesa del Dominio jamás aparece en SQL del
// repositorio (solo puede nombrarse en el comentario de cabecera que
// DECLARA la frontera; cero consultas la tocan).
$sqlBody = (string) preg_replace('/^\s*\*.*$/m', '', $repositorySource); // sin comentarios de bloque
$lineComments = (string) preg_replace('/^\s*\/\/.*$/m', '', $sqlBody);   // sin comentarios de línea
assertCondition(!str_contains($lineComments, 'favorites'), 'La frontera sagrada: ninguna consulta del repositorio toca la mesa del Dominio (RF-05.4).');
$preparedCount = substr_count($repositorySource, '->prepare(');
$interpolatedValues = (bool) preg_match('/"\s*\.\s*\$(?!this)/', $repositorySource) || (bool) preg_match('/\$\w+\s*\.\s*"[^"]*SELECT|SELECT[^"]*"\s*\.\s*\$/', $repositorySource);
assertCondition($preparedCount >= 6 && !$interpolatedValues, 'Las seis consultas viajan preparadas; solo el límite de página acotado se ensambla (RNF-02).');

// --- FASE 1: base canónica real + siembra ----------------------------------
$probePath = __DIR__ . '/__probe_gc_repository.sqlite';
@unlink($probePath);
$connection = new PDO('sqlite:' . $probePath);
$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$connection->exec('PRAGMA foreign_keys = ON');
$schemaSource = (string) file_get_contents(__DIR__ . '/../database/schema.sql');
$connection->exec($schemaSource);

// La mesa del tomo nace del DDL canónico (coherencia con la Tarea 1.1).
$collectionTable = $connection->query(
    "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'grimoire_collections'"
)->fetch(PDO::FETCH_ASSOC);
assertCondition(is_array($collectionTable), 'La base canónica porta la mesa del tomo (Tarea 1.1).');

require __DIR__ . '/../src/Repositories/GrimoireCollectionRepository.php';
$repository = new Grimorio\Repositories\GrimoireCollectionRepository($connection);

echo "\n[FASE 1] Siembra: un adepto con 55 hechizos alternando fuego y agua.\n";
[$adeptId, $seededSpells] = seedAdeptWithSpells($connection, 'a', 55, str_repeat('1', 64));
// Un segundo adepto con un solo hechizo, para las murallas de intimidad.
[$otherAdeptId, ] = seedAdeptWithSpells($connection, 'b', 1, str_repeat('2', 64));
assertCondition($seededSpells === 55, 'La siembra dejó 55 hechizos al primer adepto (cruza la página de 50).');
assertCondition($connection->query('SELECT COUNT(*) FROM grimoire_collections')->fetchColumn() == 0, 'El tomo nace vacío: la siembra solo toca el catálogo.');

// --- FASE 2: add() — sellado nuevo, idempotencia, muralla -------------------
echo "\n[FASE 2] add(): el primer sellado es nuevo; el segundo, idempotente (RF-01.3).\n";
$firstSeal = $repository->add($adeptId, 'spl-a-0', '2026-09-22T09:00:00Z');
assertCondition($firstSeal === true, 'El primer sellado de un hechizo devuelve true (fila nueva, digna de 201).');
$secondSeal = $repository->add($adeptId, 'spl-a-0', '2026-09-22T09:05:00Z');
assertCondition($secondSeal === false, 'El re-sellado del mismo hechizo devuelve false («ya está en tu tomo»).');
$rowCount = (int) $connection->query('SELECT COUNT(*) FROM grimoire_collections')->fetchColumn();
assertCondition($rowCount === 1, 'La base guarda UNA sola fila pese a los dos intentos (muralla física).');
$firstInstant = (string) $connection->query('SELECT added_at FROM grimoire_collections')->fetchColumn();
assertCondition($firstInstant === '2026-09-22T09:00:00Z', 'La fila conserva el PRIMER sellado: la idempotencia no muta el instante original.');
// Sellado en lote: los 55 hechizos del adepto (para cruzar la página).
$insertTime = 9;
for ($index = 1; $index < $seededSpells; $index++) {
    $insertTime++;
    $repository->add($adeptId, "spl-a-{$index}", sprintf('2026-09-22T%02d:00:00Z', $insertTime % 24));
}
$crossSeal = $repository->add($otherAdeptId, 'spl-b-0', '2026-09-22T10:00:00Z');
assertCondition($crossSeal === true, 'El segundo adepto sella su propio hechizo sin fricción (la muralla veda el duplicado, no el tomo).');

// --- FASE 3: existsForUser() y spellIdsForUser() ----------------------------
echo "\n[FASE 3] Lecturas puntuales y en bloque fieles a la mesa.\n";
assertCondition($repository->existsForUser($adeptId, 'spl-a-0') === true, 'existsForUser() halla un hechizo sellado.');
assertCondition($repository->existsForUser($adeptId, 'spl-a-noexiste') === false, 'existsForUser() no halluca lo jamás sellado.');
// spl-b-0 pertenece al otro adepto: la consulta puntual es por par exacto.
assertCondition($repository->existsForUser($otherAdeptId, 'spl-a-0') === false, 'existsForUser() del tomo ajeno responde ausente (intimidad del tomo).');
$spellIds = $repository->spellIdsForUser($adeptId);
assertCondition(count($spellIds) === 55, 'spellIdsForUser() entrega los 55 identificadores del tomo.');
assertCondition(in_array('spl-a-0', $spellIds, true) && in_array('spl-a-54', $spellIds, true), 'El bloque porta tanto el primer sellado como el último.');

// --- FASE 4: pageForUser() — orden, filtro, paginación ----------------------
echo "\n[FASE 4] pageForUser(): orden DESC, filtro por afinidad y paginación de 50.\n";
$page1 = $repository->pageForUser($adeptId, null, 1);
assertCondition(count($page1) === 50, 'La primera página entrega 50 filas (el límite del caso límite 4).');
// El lote produce EMPATES de instante (13 y 37 → ambos 23:00): el
// desempate por id hace la paginación DETERMINISTA, pero el orden ENTRE
// ids aleatorios no es predecible por diseño — lo que el contrato exige
// es que los dos de instante máximo encabezen la página, sea cual sea el
// orden entre ellos, y que ese orden se REPITA idéntico en re-lecturas.
$topTwo = [$page1[0]['spell_id'], $page1[1]['spell_id']];
sort($topTwo);
assertCondition($topTwo === ['spl-a-14', 'spl-a-38'], 'Los dos sellados de instante máximo encabezan la página (orden total estable, desempate por id, RF-02.1).');
$reread = $repository->pageForUser($adeptId, null, 1);
assertCondition(array_column($reread, 'spell_id') === array_column($page1, 'spell_id'), 'La re-lectura de la página repite el orden IDÉNTICO: paginación determinista ante empates.');
assertCondition($page1[49]['added_at'] <= $page1[0]['added_at'], 'El orden es estrictamente descendente en toda la página.');
$page2 = $repository->pageForUser($adeptId, null, 2);
assertCondition(count($page2) === 5, 'La segunda página entrega las 5 filas restantes (55 = 50 + 5).');
assertCondition($page1[49]['spell_id'] !== $page2[0]['spell_id'], 'Las páginas no se solapan.');
$firePage = $repository->pageForUser($adeptId, 'fire', 1);
$allFire = true;
foreach ($firePage as $row) {
    $spellRow = $connection->prepare('SELECT elemental_affinity FROM spells WHERE id = :id');
    $spellRow->execute([':id' => $row['spell_id']]);
    if ((string) $spellRow->fetchColumn() !== 'fire') {
        $allFire = false;
        break;
    }
}
assertCondition($allFire && count($firePage) === 28, 'El filtro por afinidad entrega solo fuego (28 de los 55 alternados) conservando el orden (RF-02.3).');
$waterPage = $repository->pageForUser($adeptId, 'water', 1);
$allWater = true;
foreach ($waterPage as $row) {
    $spellRow = $connection->prepare('SELECT elemental_affinity FROM spells WHERE id = :id');
    $spellRow->execute([':id' => $row['spell_id']]);
    if ((string) $spellRow->fetchColumn() !== 'water') {
        $allWater = false;
        break;
    }
}
assertCondition($allWater && count($waterPage) === 27, 'El filtro de agua entrega las 27 restantes: las dos afinidades particionan el tomo sin residuos.');
// Página absurda: el candado la conduce a un conjunto vacío sin error.
$voidPage = $repository->pageForUser($adeptId, null, 99999);
assertCondition($voidPage === [], 'Una página fuera de rango responde vacía sin error (candado de página).');

// --- FASE 5: countForUser() -------------------------------------------------
echo "\n[FASE 5] countForUser(): el total jamás describe un conjunto distinto del exhibido.\n";
assertCondition($repository->countForUser($adeptId) === 55, 'El total íntegro del tomo es 55.');
assertCondition($repository->countForUser($adeptId, 'fire') === 28, 'El total filtrado por fuego es 28 (coherente con la página filtrada).');
assertCondition($repository->countForUser($adeptId, 'water') === 27, 'El total filtrado por agua es 27.');
assertCondition(28 + 27 === $repository->countForUser($adeptId), 'Fuego más agua suma el total íntegro: el filtro particiona, no deforma.');
assertCondition($repository->countForUser($otherAdeptId) === 1, 'El tomo del segundo adepto cuenta lo suyo: los totales son íntimos.');

// --- FASE 6: remove() — retirada propia, ajena imposible ---------------------
echo "\n[FASE 6] remove(): la muralla de intimidad y la retirada limpia (RF-02.4).\n";
$foreignRemove = $repository->remove($otherAdeptId, 'spl-a-0');
assertCondition($foreignRemove === false, 'Retirar del tomo AJENO no encuentra la fila (el WHERE doble lo impide).');
assertCondition($repository->existsForUser($adeptId, 'spl-a-0') === true, 'El hechizo del tomo ajeno sobrevive al gesto ajeno: la intimidad es física.');
$ownRemove = $repository->remove($adeptId, 'spl-a-0');
assertCondition($ownRemove === true, 'El adepto retira lo suyo con éxito.');
$repeatRemove = $repository->remove($adeptId, 'spl-a-0');
assertCondition($repeatRemove === false, 'La retirada repetida responde false sin error (el servicio dictará el 409 solemne).');
assertCondition($repository->countForUser($adeptId) === 54, 'El total del tomo baja a 54 tras la retirada.');

// --- FASE 7: regresión de la Tarea 1.1 ---------------------------------------
echo "\n[FASE 7] Regresión: la muralla física de la Tarea 1.1 sigue viva.\n";
$murallaError = null;
try {
    $connection->prepare('INSERT INTO grimoire_collections (id, user_id, spell_id, added_at) VALUES (:id, :u, :s, :t)')
        ->execute([':id' => 'gc-regression', ':u' => $adeptId, ':s' => 'spl-a-1', ':t' => '2026-09-22T23:00:00Z']);
} catch (PDOException $failure) {
    $murallaError = $failure->getMessage();
}
assertCondition($murallaError !== null && str_contains($murallaError, 'UNIQUE'), 'El INSERT crudo duplicado sigue cayendo ante la UNIQUE de la base.');
// La purga del adepto b arrastra su tomo (RF-05.3). Sus hechizos y su casa
// sobreviven — la cascada corre SOLO hacia el tomo, como manda la Tarea 1.1.
$connection->prepare('DELETE FROM grimoire_collections WHERE user_id = :id')->execute([':id' => $otherAdeptId]);
$connection->prepare('DELETE FROM spells WHERE author_id = :id')->execute([':id' => $otherAdeptId]);
$connection->prepare('DELETE FROM clans WHERE id = :id')->execute([':id' => 'cln-b']);
$connection->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $otherAdeptId]);
$otherAdeptRows = $repository->countForUser($otherAdeptId);
assertCondition($otherAdeptRows === 0, 'La purga del adepto arrastra su tomo por cascada (RF-05.3).');

// --- Limpieza ----------------------------------------------------------------
$connection = null;
@unlink($probePath);

echo "\n=== RESULTADO: {$assertsPassed} asertos en verde, {$assertsFailed} en rojo ===\n";
if ($assertsFailed > 0) {
    exit(1);
}
echo "Tarea 1.2 verificada: el repositorio del Tomo Personal mide y persiste.\n";
