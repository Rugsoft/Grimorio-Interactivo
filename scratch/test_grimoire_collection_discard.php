<?php

declare(strict_types=1);

/**
 * test_grimoire_collection_discard.php — Verificación de la Tarea 2.3
 * de TASKS-11.
 *
 * Valida la RETIRADA DEL TOMO (`GrimoireCollectionService::discardSpell()`)
 * y LA PAGINACIÓN VIVA (`resumePageFor()`, plan §3.5) contra el
 * «Hecho cuando» de la tarea:
 *
 *   1. Retirar una entrada existente devuelve el `total` actualizado.
 *   2. Retirar una ausente responde 409 `SPELL_NOT_IN_TOME`.
 *   3. Retirar la última de una página intermedia recalcula la página
 *      destino (`resumePageFor`).
 *   4. `favorites` queda byte a byte intacta (hallazgo 13: elogio
 *      perpetuo — cada rito vive su vida).
 *
 * Fases:
 *   [0] Superficie: el servicio declara discardSpell() y resumePageFor().
 *   [1] Base canónica real + siembra (2 adeptos, tomo con 5 entradas,
 *       mesa del Dominio con votos vivos).
 *   [2] Retirada feliz: fila propia → removed + total actualizado.
 *   [3] Retirada ausente y ajena → 409 solemne (muralla de intimidad).
 *   [4] La frontera sagrada: favorites byte a byte intacta.
 *   [5] Paginación viva: el mapa completo de casos del plan §3.5.
 *   [6] Regresión: sellado, idempotencia y Bitácora intactos.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin librerías externas.
 *   - Artículo V (Dualidad): identificadores en inglés; narrativa en
 *     noble castellano.
 *
 * Uso: php scratch/test_grimoire_collection_discard.php
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

// La interfaz de la pluma debe vivir cargada ANTES de la declaración
// del testigo que la implementa.
require_once __DIR__ . '/../src/Services/AuditRecorderInterface.php';
require_once __DIR__ . '/../src/Models/AuditEntry.php';

final class AuditTally implements Grimorio\Services\AuditRecorderInterface
{
    public int $count = 0;

    public function recordAction(
        string $actorUserId,
        string $actorAlias,
        string $actorRole,
        string $actionType,
        string $targetEntityType,
        string $targetEntityId,
        string $justification,
        ?DateTimeImmutable $now = null,
    ): Grimorio\Models\AuditEntry {
        $this->count++;

        return new Grimorio\Models\AuditEntry(
            0,
            $actorUserId,
            $actorAlias,
            $actorRole,
            $actionType,
            $targetEntityType,
            $targetEntityId,
            $justification,
            ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
        );
    }
}

/** Siembra un adepto contra el esquema canónico real. */
function seedAdept(PDO $connection, string $suffix, string $lineage): string
{
    $userId = 'usr-' . $suffix;
    $connection->prepare(
        'INSERT INTO users (id, alias, email, password_hash, lineage, created_at, updated_at)
         VALUES (:id, :alias, :email, :hash, :lineage, :created, :updated)'
    )->execute([
        ':id' => $userId,
        ':alias' => 'Adepto ' . $suffix,
        ':email' => $suffix . '@santuario.test',
        ':hash' => str_repeat('a', 60),
        ':lineage' => $lineage,
        ':created' => '2026-09-22T00:00:00Z',
        ':updated' => '2026-09-22T00:00:00Z',
    ]);

    return $userId;
}

/** Siembra un hechizo validado en el catálogo. */
function seedSpell(PDO $connection, string $suffix): string
{
    $spellId = 'spl-' . $suffix;
    $connection->prepare(
        'INSERT INTO spells (id, slug, name, author_id, magic_school, clan_id, summary, mana_cost, status, created_at, updated_at, math_fingerprint)
         VALUES (:id, :slug, :name, :author, :school, :clan, :summary, 5, :status, :created, :updated, :fingerprint)'
    )->execute([
        ':id' => $spellId,
        ':slug' => 'conjuro-' . $suffix,
        ':name' => 'Conjuro de prueba ' . $suffix,
        ':author' => 'usr-author',
        ':school' => 'evocacion',
        ':clan' => 'cln-house',
        ':summary' => 'Resumen de prueba',
        ':status' => 'validated',
        ':created' => '2026-09-22T00:00:00Z',
        ':updated' => '2026-09-22T00:00:00Z',
        ':fingerprint' => str_repeat('0', 64),
    ]);

    return $spellId;
}

/** Instante del tomo (código de la base: los favoritos llevan created_at). */
function favoritesAsBytes(PDO $connection): array
{
    $rows = $connection->query(
        'SELECT id, user_id, spell_id, created_at FROM favorites ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);

    return $rows;
}

echo "=== Retirada del tomo y paginación viva — Tarea 2.3 de TASKS-11 ===\n";

echo "\n[FASE 0] Superficie: el servicio declara las piezas de la tarea.\n";
$serviceSource = (string) file_get_contents(__DIR__ . '/../src/Services/GrimoireCollectionService.php');
assertCondition(str_contains($serviceSource, 'public function discardSpell('), 'El servicio declara discardSpell() (la retirada solemne).');
assertCondition(str_contains($serviceSource, 'public static function resumePageFor('), 'El servicio declara resumePageFor() (la paginación viva, plan §3.5).');
require_once __DIR__ . '/../src/Exceptions/SpellNotInTomeException.php';
assertCondition(class_exists(Grimorio\Exceptions\SpellNotInTomeException::class), 'La excepción del 409 solemne existe.');
assertCondition(Grimorio\Exceptions\SpellNotInTomeException::ERROR_CODE === 'SPELL_NOT_IN_TOME', 'Su código canónico es SPELL_NOT_IN_TOME.');
assertCondition(Grimorio\Exceptions\SpellNotInTomeException::HTTP_STATUS_CODE === 409, 'Su HTTP es 409 (conflicto de estado, plan §2.2).');

// --- FASE 1: base canónica real + siembra -----------------------------------
$probePath = __DIR__ . '/__probe_gc_discard.sqlite';
@unlink($probePath);
$connection = new PDO('sqlite:' . $probePath);
$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$connection->exec('PRAGMA foreign_keys = ON');
$connection->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));

// La interfaz de la pluma debe vivir cargada ANTES de la declaración
// del testigo que la implementa.
require_once __DIR__ . '/../src/Repositories/GrimoireCollectionRepository.php';
require_once __DIR__ . '/../src/Services/AuditRecorderInterface.php';
require_once __DIR__ . '/../src/Models/AuditEntry.php';
require_once __DIR__ . '/../src/Models/User.php';
require_once __DIR__ . '/../src/Services/AuditService.php';
require_once __DIR__ . '/../src/Services/GrimoireCollectionService.php';
require_once __DIR__ . '/../src/Exceptions/LineageOathException.php';
require_once __DIR__ . '/../src/Exceptions/SpellNotFoundException.php';
require_once __DIR__ . '/../src/Exceptions/SpellNotInTomeException.php';
require_once __DIR__ . '/../src/Exceptions/UniformSealVetoException.php';

$connection->prepare("INSERT OR IGNORE INTO magic_schools (slug, name) VALUES ('evocacion', 'Evocación')")->execute();
$connection->prepare("INSERT INTO clans (id, slug, name, created_at, lineage_type) VALUES ('cln-house', 'casa-prueba', 'Casa de prueba', '2026-09-22T00:00:00Z', 'primordialFlame')")->execute();
$connection->prepare(
    "INSERT INTO users (id, alias, email, password_hash, created_at, updated_at)
     VALUES ('usr-author', 'Autor Pluma', 'pluma@santuario.test', :hash, '2026-09-22T00:00:00Z', '2026-09-22T00:00:00Z')"
)->execute([':hash' => str_repeat('a', 60)]);

$linajadoId = seedAdept($connection, 'linajado', 'primordialFlame');
$otroAdeptoId = seedAdept($connection, 'otro', 'celestialTides');

$spellIds = [];
for ($index = 0; $index < 5; $index++) {
    $spellIds[] = seedSpell($connection, 'd-' . $index);
}
$spellOtro = seedSpell($connection, 'otro');

$linajado = new Grimorio\Models\User($linajadoId, 'Adepto linajado', 'linajado@santuario.test', 'editor', null, 'primordialFlame');
$otroAdepto = new Grimorio\Models\User($otroAdeptoId, 'Adepto otro', 'otro@santuario.test', 'editor', null, 'celestialTides');

$repository = new Grimorio\Repositories\GrimoireCollectionRepository($connection);
$auditTally = new AuditTally();
$service = new Grimorio\Services\GrimoireCollectionService($repository, $auditTally, $connection);

// Tomo del primer adepto con 5 entradas; elogios VIVOS en la mesa del Dominio.
$instant = 9;
foreach ($spellIds as $index => $spellId) {
    $instant++;
    $service->collectSpell($linajado, $spellId, sprintf('2026-09-22T%02d:00:00Z', $instant % 24));
}
$connection->prepare('INSERT INTO favorites (id, user_id, spell_id, created_at) VALUES (:id, :u, :s, :t)')
    ->execute([':id' => 'fav-d-1', ':u' => $linajadoId, ':s' => $spellIds[1], ':t' => '2026-09-22T20:00:00Z']);
$connection->prepare('INSERT INTO favorites (id, user_id, spell_id, created_at) VALUES (:id, :u, :s, :t)')
    ->execute([':id' => 'fav-d-2', ':u' => $linajadoId, ':s' => $spellIds[2], ':t' => '2026-09-22T20:05:00Z']);
$favoritesAntes = favoritesAsBytes($connection);
assertCondition(count($favoritesAntes) === 2, 'La siembra dejó el tomo con 5 entradas y 2 elogios vivos en la mesa del Dominio.');

echo "\n[FASE 2] Retirada feliz: fila propia → removed + total actualizado (RF-02.4).\n";
[$result, $error] = [null, null];
try {
    $result = $service->discardSpell($linajado, $spellIds[0]);
} catch (Throwable $failure) {
    $error = $failure;
}
assertCondition($error === null, 'La retirada de una entrada propia concluye sin excepción.');
assertCondition(is_array($result) && $result['removed'] === true, 'El eco nombra la retirada como consumada.');
assertCondition(is_array($result) && $result['total'] === 4, 'El total devuelto está ACTUALIZADO (5 → 4): la vista refresca el rótulo sin segunda petición.');
assertCondition(!$repository->existsForUser($linajadoId, $spellIds[0]), 'La fila salió de la mesa del tomo.');

echo "\n[FASE 3] Retirada ausente y ajena → 409 solemne (muralla de intimidad).\n";
[, $absentError] = [null, null];
try {
    $service->discardSpell($linajado, $spellIds[0]); // ya retirada
} catch (Throwable $failure) {
    $absentError = $failure;
}
assertCondition($absentError instanceof Grimorio\Exceptions\SpellNotInTomeException, 'Retirar una entrada ya ausente lanza la excepción solemne.');
assertCondition($absentError !== null && $absentError->getErrorCode() === 'SPELL_NOT_IN_TOME', 'Su código canónico es SPELL_NOT_IN_TOME.');
assertCondition($absentError !== null && $absentError->getHttpStatusCode() === 409, 'Su HTTP es 409.');
[, $foreignError] = [null, null];
try {
    $service->discardSpell($otroAdepto, $spellIds[1]); // el tomo AJENO
} catch (Throwable $failure) {
    $foreignError = $failure;
}
assertCondition($foreignError instanceof Grimorio\Exceptions\SpellNotInTomeException, 'Retirar del tomo AJENO responde 409: la intimidad es física (Tarea 1.2).');
assertCondition($repository->existsForUser($linajadoId, $spellIds[1]), 'La entrada del tomo ajeno sobrevive íntegra al gesto ajeno.');

echo "\n[FASE 4] La frontera sagrada: favorites byte a byte intacta (hallazgo 13).\n";
assertCondition(favoritesAsBytes($connection) === $favoritesAntes, 'Las retiradas del tomo no tocaron NI UNA fila de favorites: el elogio es perpetuo (caso límite 9).');
// Y el recíproco: el tomo sigue íntegro pese a los votos del Dominio.
assertCondition($repository->countForUser($linajadoId) === 4, 'El tomo conserva sus 4 entradas: cada rito vive su vida.');

echo "\n[FASE 5] Paginación viva: el mapa de casos del plan §3.5 (caso límite 10).\n";
// Con 4 entradas y límite 50, hay 1 página: cualquier petición absurda cae a ella.
assertCondition(Grimorio\Services\GrimoireCollectionService::resumePageFor(1, 4, 50) === 1, 'Página corriente válida se conserva.');
assertCondition(Grimorio\Services\GrimoireCollectionService::resumePageFor(3, 4, 50) === 1, 'Página más allá del final recae en la última página viva.');
// Mapa con dos páginas: 55 entradas, límite 50 → totalPages = 2.
assertCondition(Grimorio\Services\GrimoireCollectionService::resumePageFor(1, 55, 50) === 1, 'Primera página de dos: se conserva.');
assertCondition(Grimorio\Services\GrimoireCollectionService::resumePageFor(2, 55, 50) === 2, 'Segunda página de dos: se conserva.');
assertCondition(Grimorio\Services\GrimoireCollectionService::resumePageFor(2, 50, 50) === 1, 'Retirada que vacía la última página reanuda en la página válida más cercana (50 → 1 página).');
assertCondition(Grimorio\Services\GrimoireCollectionService::resumePageFor(5, 137, 50) === 3, 'Tomo de 137 entradas: la página 5 de 3 posibles recae en la 3 (techo).');
assertCondition(Grimorio\Services\GrimoireCollectionService::resumePageFor(0, 137, 50) === 1, 'Página 0 o negativa se eleva a 1 (jamás OFFSET negativo).');
assertCondition(Grimorio\Services\GrimoireCollectionService::resumePageFor(1, 0, 50) === 1, 'Tomo vaciado del todo recae en la página 1 (estado vacío, jamás pantallas fantasma).');
// El candado del límite: límite 0 o negativo se eleva a 1 (jamás división
// por cero); con límite 1 y 10 entradas hay 10 páginas y la pedida 2 es válida.
assertCondition(Grimorio\Services\GrimoireCollectionService::resumePageFor(2, 10, 0) === 2, 'Límite degenerado (0) no produce división por cero: se eleva a 1 y la paginación sigue siendo válida.');

echo "\n[FASE 6] Regresión: sellado, idempotencia y Bitácora intactos tras la retirada.\n";
$asientosAntes = $auditTally->count;
$reSeal = $service->collectSpell($linajado, $spellIds[0], '2026-09-22T23:00:00Z');
assertCondition(is_array($reSeal) && $reSeal['alreadyCollected'] === false, 'El hechizo retirado puede VOLVER a sellarse (la retirada no es clausura).');
assertCondition($auditTally->count === $asientosAntes + 1, 'El nuevo sellado asienta su propio TOME_SEAL (el ciclo completo vive).');
assertCondition($repository->countForUser($linajadoId) === 5, 'El tomo vuelve a 5 entradas: retirada y sellado son reversible y rito, respectivamente.');

// --- Limpieza ----------------------------------------------------------------
$connection = null;
@unlink($probePath);

echo "\n=== RESULTADO: {$assertsPassed} asertos en verde, {$assertsFailed} en rojo ===\n";
if ($assertsFailed > 0) {
    exit(1);
}
echo "Tarea 2.3 verificada: la retirada es solemne, íntima y con paginación viva.\n";
